<?php

declare(strict_types=1);

namespace Drupal\ilas_site_assistant_governance\Form;

use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\Core\Url;
use Drupal\ilas_site_assistant_governance\Entity\AssistantGapItem;
use Drupal\ilas_site_assistant_governance\Plugin\Action\AssistantGapItemDeferredCloseActionBase;
use Drupal\ilas_site_assistant_governance\Service\GapReviewRules;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Collects required disposition data before bulk close/archive transitions.
 */
final class AssistantGapItemBulkDispositionForm extends ConfirmFormBase {

  /**
   * Constructs the form.
   */
  public function __construct(
    protected PrivateTempStoreFactory $tempStoreFactory,
    protected EntityStorageInterface $gapItemStorage,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('tempstore.private'),
      $container->get('entity_type.manager')->getStorage('assistant_gap_item'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'assistant_gap_item_bulk_disposition_form';
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion(): string {
    return (string) $this->t(
      'Apply a disposition and move the selected gap items to %state?',
      ['%state' => $this->targetStateLabel()]
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription(): string {
    return (string) $this->t('No gap items are updated until you submit this form.');
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText(): string {
    return (string) $this->t('Apply disposition');
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl(): Url {
    return Url::fromRoute('view.assistant_gap_items.page_queue');
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $items = $this->loadSelectedGapItems();
    if ($items === []) {
      $this->messenger()->addWarning($this->t('No selected gap items are waiting for bulk disposition.'));
      return $this->redirect('view.assistant_gap_items.page_queue');
    }

    $form['intro'] = [
      '#type' => 'markup',
      '#markup' => '<p>' . $this->t('This bulk action uses the same close-out disposition requirements as the manual review form.') . '</p>',
    ];

    $rows = [];
    foreach ($items as $item) {
      $rows[] = [
        $item->label(),
        AssistantGapItem::stateOptions()[$item->getReviewState()] ?? $item->getReviewState(),
        (string) ($item->get('occurrence_count_unresolved')->value ?? '0'),
      ];
    }

    $form['selected_items'] = [
      '#type' => 'table',
      '#header' => [
        $this->t('Gap item'),
        $this->t('Current status'),
        $this->t('Open occurrences'),
      ],
      '#rows' => $rows,
    ];

    $form['disposition'] = [
      '#type' => 'details',
      '#title' => $this->t('Disposition'),
      '#open' => TRUE,
    ];
    $form['disposition']['resolution_code'] = [
      '#type' => 'select',
      '#title' => $this->t('Resolution outcome'),
      '#options' => AssistantGapItem::resolutionCodeOptions(),
      '#empty_option' => $this->t('- Default to Other -'),
      '#default_value' => (string) $form_state->getValue('resolution_code', ''),
      '#description' => $this->t('Blank values are saved as Other, matching the manual review form.'),
    ];
    $form['disposition']['resolution_reference'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Resolution reference'),
      '#default_value' => (string) $form_state->getValue('resolution_reference', ''),
      '#maxlength' => 255,
      '#description' => $this->t('Required for FAQ, content, and search-tuning dispositions.'),
    ];
    $form['disposition']['resolution_notes'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Reviewer notes'),
      '#default_value' => (string) $form_state->getValue('resolution_notes', ''),
      '#rows' => 4,
      '#description' => $this->t('Required for suppression-style dispositions such as false positive or test traffic. Notes are redacted before storage.'),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $items = $this->loadSelectedGapItems();
    if ($items === []) {
      $form_state->setErrorByName('', $this->t('No selected gap items are waiting for bulk disposition.'));
      return;
    }

    $target_state = $this->targetState();
    foreach ($items as $item) {
      if (!AssistantGapItem::canTransition($item->getReviewState(), $target_state, $this->currentUser())) {
        $form_state->setErrorByName('', $this->t('One or more selected gap items can no longer be moved to %state. Return to the queue and try again.', [
          '%state' => $this->targetStateLabel(),
        ]));
        break;
      }
    }

    $resolution_code = GapReviewRules::normalizeCloseResolutionCode(
      $target_state,
      $form_state->getValue('resolution_code'),
    );
    foreach (GapReviewRules::validateDisposition(
      $target_state,
      $resolution_code,
      $form_state->getValue('resolution_reference'),
      $form_state->getValue('resolution_notes'),
    ) as $field_name => $message) {
      $form_state->setErrorByName($field_name, $this->t($message));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $items = $this->loadSelectedGapItems();
    $target_state = $this->targetState();
    $acting_uid = (int) $this->currentUser()->id();
    $resolution_code = GapReviewRules::normalizeCloseResolutionCode(
      $target_state,
      $form_state->getValue('resolution_code'),
    );
    $resolution_reference = trim((string) $form_state->getValue('resolution_reference'));
    $resolution_notes = trim((string) $form_state->getValue('resolution_notes'));
    $count = 0;

    foreach ($items as $item) {
      if (!AssistantGapItem::canTransition($item->getReviewState(), $target_state, $this->currentUser())) {
        continue;
      }

      if ($acting_uid > 0 && $item->get('assigned_uid')->isEmpty()) {
        $item->set('assigned_uid', $acting_uid);
      }

      $item->set('resolution_code', $resolution_code);
      $item->set('resolution_reference', $resolution_reference !== '' ? $resolution_reference : NULL);
      $item->set('resolution_notes', $resolution_notes !== '' ? $resolution_notes : NULL);
      $item->applyTransition($target_state, $acting_uid);
      $item->setNewRevision(TRUE);
      $item->setRevisionUserId($acting_uid);
      $item->setRevisionLogMessage($this->revisionLogMessage());
      $item->save();
      $count++;
    }

    $this->clearSelection();

    if ($count > 0) {
      $this->messenger()->addStatus($this->formatPlural(
        $count,
        'Moved 1 gap item to %state.',
        'Moved @count gap items to %state.',
        ['%state' => $this->targetStateLabel()]
      ));
    }
    else {
      $this->messenger()->addWarning($this->t('No selected gap items were eligible for that bulk transition.'));
    }

    $form_state->setRedirect('view.assistant_gap_items.page_queue');
  }

  /**
   * {@inheritdoc}
   */
  public function cancelForm(array &$form, FormStateInterface $form_state): void {
    $this->clearSelection();
    parent::cancelForm($form, $form_state);
  }

  /**
   * Returns the route-specific target state.
   */
  protected function targetState(): string {
    return match ($this->getRouteMatch()->getRouteName()) {
      'ilas_site_assistant_governance.gap_bulk_archive_confirm' => AssistantGapItem::STATE_ARCHIVED,
      default => AssistantGapItem::STATE_RESOLVED,
    };
  }

  /**
   * Returns the route-specific action plugin id.
   */
  protected function actionPluginId(): string {
    return match ($this->getRouteMatch()->getRouteName()) {
      'ilas_site_assistant_governance.gap_bulk_archive_confirm' => 'assistant_gap_item_to_archived_action',
      default => 'assistant_gap_item_to_resolved_action',
    };
  }

  /**
   * Returns the route-specific target state label.
   */
  protected function targetStateLabel(): string {
    return AssistantGapItem::stateOptions()[$this->targetState()] ?? $this->targetState();
  }

  /**
   * Returns the route-specific revision log message.
   */
  protected function revisionLogMessage(): string {
    return match ($this->targetState()) {
      AssistantGapItem::STATE_ARCHIVED => 'Bulk action moved gap item to archived.',
      default => 'Bulk action moved gap item to resolved.',
    };
  }

  /**
   * Loads the gap items selected for the current action.
   *
   * @return \Drupal\ilas_site_assistant_governance\Entity\AssistantGapItem[]
   *   Loaded gap items keyed numerically in stored order.
   */
  protected function loadSelectedGapItems(): array {
    $ids = $this->tempStoreFactory
      ->get(AssistantGapItemDeferredCloseActionBase::TEMPSTORE_COLLECTION)
      ->get($this->actionPluginId());

    if (!is_array($ids) || $ids === []) {
      return [];
    }

    $ids = array_values(array_unique(array_map('intval', $ids)));
    $loaded = $this->gapItemStorage->loadMultiple($ids);
    $items = [];
    foreach ($ids as $id) {
      if (isset($loaded[$id]) && $loaded[$id] instanceof AssistantGapItem) {
        $items[] = $loaded[$id];
      }
    }

    return $items;
  }

  /**
   * Clears the stored bulk selection for the current action.
   */
  protected function clearSelection(): void {
    $this->tempStoreFactory
      ->get(AssistantGapItemDeferredCloseActionBase::TEMPSTORE_COLLECTION)
      ->delete($this->actionPluginId());
  }

}
