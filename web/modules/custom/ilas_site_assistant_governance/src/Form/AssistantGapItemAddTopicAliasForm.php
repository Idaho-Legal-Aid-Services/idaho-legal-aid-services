<?php

declare(strict_types=1);

namespace Drupal\ilas_site_assistant_governance\Form;

use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Drupal\ilas_site_assistant\Service\LexiconOverrides;
use Drupal\ilas_site_assistant_governance\Entity\AssistantGapItem;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Turns a gap item's exemplar query into an admin topic-alias override.
 *
 * This is the "fix it now" path for a reviewer: the phrase the user typed
 * starts routing to the chosen pack answer immediately, and the item can be
 * resolved as search-tuned in the same step.
 */
final class AssistantGapItemAddTopicAliasForm extends ConfirmFormBase {

  /**
   * The gap item being tuned.
   */
  protected ?AssistantGapItem $gapItem = NULL;

  /**
   * Constructs the form.
   */
  public function __construct(
    protected ?LexiconOverrides $lexiconOverrides,
    protected AccountProxyInterface $currentAccount,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->has('ilas_site_assistant.lexicon_overrides') ? $container->get('ilas_site_assistant.lexicon_overrides') : NULL,
      $container->get('current_user'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'assistant_gap_item_add_topic_alias_form';
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion(): string {
    return (string) $this->t('Route this wording to a topic answer?');
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription(): string {
    return (string) $this->t('The phrase is saved as a lexicon override (Site Assistant settings › Lexicon overrides) and takes effect on the next request. Promote it into config/routing/topic_map.yml with a fixture during the weekly review.');
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText(): string {
    return (string) $this->t('Add topic alias');
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl(): Url {
    return $this->gapItem !== NULL
      ? Url::fromRoute('entity.assistant_gap_item.canonical', ['assistant_gap_item' => $this->gapItem->id()])
      : Url::fromRoute('view.assistant_gap_items.page_queue');
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?AssistantGapItem $assistant_gap_item = NULL): array {
    $this->gapItem = $assistant_gap_item;
    if ($assistant_gap_item === NULL || $this->lexiconOverrides === NULL) {
      $this->messenger()->addError($this->t('Lexicon overrides are not available.'));
      return $this->redirect('view.assistant_gap_items.page_queue');
    }

    $options = $this->lexiconOverrides->topicIntentOptions();
    if ($options === []) {
      $this->messenger()->addError($this->t('No Top Intents pack entries are available to target.'));
      return $this->redirect('entity.assistant_gap_item.canonical', ['assistant_gap_item' => $assistant_gap_item->id()]);
    }

    $exemplar = mb_strtolower(trim((string) ($assistant_gap_item->get('exemplar_redacted_query')->value ?? '')));
    $exemplar = trim((string) preg_replace('/[^\p{L}\p{N}\s\'-]+/u', ' ', $exemplar));
    $exemplar = trim((string) preg_replace('/\s+/u', ' ', $exemplar));

    $form = parent::buildForm($form, $form_state);
    $form['exemplar'] = [
      '#type' => 'item',
      '#title' => $this->t('Redacted exemplar'),
      '#markup' => (string) ($assistant_gap_item->get('exemplar_redacted_query')->value ?? ''),
    ];
    $form['phrase'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Phrase to recognise'),
      '#description' => $this->t('Plain words, up to @max characters. Shorten it to the wording that should trigger the answer (e.g. "entering a residence").', ['@max' => LexiconOverrides::MAX_PHRASE_LENGTH]),
      '#default_value' => mb_substr($exemplar, 0, LexiconOverrides::MAX_PHRASE_LENGTH),
      '#required' => TRUE,
      '#maxlength' => LexiconOverrides::MAX_PHRASE_LENGTH,
    ];
    $form['topic_intent'] = [
      '#type' => 'select',
      '#title' => $this->t('Answer with'),
      '#options' => $options,
      '#required' => TRUE,
    ];
    $form['resolve'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Resolve this gap item as "Search tuned"'),
      '#default_value' => TRUE,
      '#access' => AssistantGapItem::canTransition($assistant_gap_item->getReviewState(), AssistantGapItem::STATE_RESOLVED, $this->currentAccount),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $parsed = LexiconOverrides::parseAliasLines($form_state->getValue('phrase') . ' ' . LexiconOverrides::SEPARATOR . ' ' . $form_state->getValue('topic_intent'));
    $errors = array_values($parsed['errors']);
    if ($this->lexiconOverrides !== NULL) {
      $errors = array_merge($errors, $this->lexiconOverrides->validateAliases($parsed['entries']));
    }
    if ($errors !== []) {
      $form_state->setErrorByName('phrase', implode(' ', $errors));
      return;
    }
    $form_state->set('alias_entry', $parsed['entries'][0] ?? NULL);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $entry = $form_state->get('alias_entry');
    if ($this->gapItem === NULL || $this->lexiconOverrides === NULL || !is_array($entry)) {
      return;
    }

    $added = $this->lexiconOverrides->addTopicAlias($entry['phrase'], $entry['topic_intent']);
    $this->messenger()->addStatus($added
      ? $this->t('"@phrase" now routes to @intent.', ['@phrase' => $entry['phrase'], '@intent' => $entry['topic_intent']])
      : $this->t('"@phrase" was already a topic alias.', ['@phrase' => $entry['phrase']]));

    $acting_uid = (int) $this->currentAccount->id();
    if ((bool) $form_state->getValue('resolve')
      && AssistantGapItem::canTransition($this->gapItem->getReviewState(), AssistantGapItem::STATE_RESOLVED, $this->currentAccount)) {
      $this->gapItem->set('resolution_code', AssistantGapItem::RESOLUTION_SEARCH_TUNED);
      $this->gapItem->set('resolution_reference', 'lexicon_overrides:' . $entry['phrase']);
      $this->gapItem->applyTransition(AssistantGapItem::STATE_RESOLVED, $acting_uid);
      $this->gapItem->setNewRevision(TRUE);
      if ($acting_uid > 0) {
        $this->gapItem->setRevisionUserId($acting_uid);
      }
      $this->gapItem->setRevisionLogMessage(sprintf('Resolved as search tuned via lexicon override "%s" -> %s.', $entry['phrase'], $entry['topic_intent']));
      $this->gapItem->save();
      $this->messenger()->addStatus($this->t('Gap item resolved as search tuned.'));
    }

    $form_state->setRedirectUrl(Url::fromRoute('entity.assistant_gap_item.canonical', ['assistant_gap_item' => $this->gapItem->id()]));
  }

}
