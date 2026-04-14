<?php

declare(strict_types=1);

namespace Drupal\ilas_site_assistant_governance\Form;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\ilas_site_assistant_governance\Entity\AssistantGapItem;
use Drupal\ilas_site_assistant_governance\Service\LegalHoldLogger;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Edit form for assistant gap items.
 */
class AssistantGapItemForm extends ContentEntityForm {

  /**
   * Constructs the form.
   */
  public function __construct(
    EntityRepositoryInterface $entity_repository,
    EntityTypeBundleInfoInterface $entity_type_bundle_info,
    TimeInterface $time,
    protected LegalHoldLogger $legalHoldLogger,
  ) {
    parent::__construct($entity_repository, $entity_type_bundle_info, $time);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity.repository'),
      $container->get('entity_type.bundle.info'),
      $container->get('datetime.time'),
      $container->get('ilas_site_assistant_governance.legal_hold_logger'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state): array {
    $form = parent::form($form, $form_state);

    $form['help'] = [
      '#type' => 'markup',
      '#markup' => '<p>' . $this->t('Use this form to classify, assign, resolve, and hold assistant gap items. All notes are redacted before storage.') . '</p>',
      '#weight' => -100,
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);

    /** @var \Drupal\ilas_site_assistant_governance\Entity\AssistantGapItem $entity */
    $entity = $this->entity;
    $original = $entity->getOriginal();

    $from = $original instanceof AssistantGapItem ? $original->getReviewState() : AssistantGapItem::STATE_NEW;
    $to = $entity->getReviewState();
    if ($from !== $to && !AssistantGapItem::canTransition($from, $to, $this->currentUser())) {
      $form_state->setErrorByName('review_state', $this->t('You do not have permission to change the review state from %from to %to.', [
        '%from' => $from,
        '%to' => $to,
      ]));
    }

    $was_held = $original instanceof AssistantGapItem ? !empty($original->get('is_held')->value) : FALSE;
    $is_held = !empty($entity->get('is_held')->value);
    if ($was_held !== $is_held) {
      $permission = $is_held ? 'place legal hold on assistant records' : 'release legal hold on assistant records';
      if (!$this->currentUser()->hasPermission($permission) && !$this->currentUser()->hasPermission('administer assistant gap items')) {
        $form_state->setErrorByName('is_held', $this->t('You do not have permission to change legal hold status.'));
      }
      if ($is_held && $entity->get('hold_reason_summary')->isEmpty()) {
        $form_state->setErrorByName('hold_reason_summary', $this->t('A hold reason summary is required when placing a legal hold.'));
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state): int {
    /** @var \Drupal\ilas_site_assistant_governance\Entity\AssistantGapItem $entity */
    $entity = $this->entity;
    $original = $entity->getOriginal();
    $was_held = $original instanceof AssistantGapItem ? !empty($original->get('is_held')->value) : FALSE;
    $is_held = !empty($entity->get('is_held')->value);

    $entity->applyTransition($entity->getReviewState(), (int) $this->currentUser()->id());
    $entity->setNewRevision(TRUE);
    $entity->setRevisionUserId((int) $this->currentUser()->id());
    if ($entity->getRevisionLogMessage() === '') {
      $entity->setRevisionLogMessage('Assistant gap item updated by staff review.');
    }

    $status = parent::save($form, $form_state);

    if ($was_held !== $is_held) {
      if ($is_held) {
        $this->legalHoldLogger->recordHold(
          'gap_item',
          (string) $entity->id(),
          (string) ($entity->get('hold_reason_summary')->value ?? ''),
          NULL,
          (int) $this->currentUser()->id(),
        );
      }
      else {
        $this->legalHoldLogger->recordRelease(
          'gap_item',
          (string) $entity->id(),
          (int) $this->currentUser()->id(),
        );
      }
    }

    $this->messenger()->addStatus($this->t('Saved assistant gap item %label.', ['%label' => $entity->label()]));
    $form_state->setRedirectUrl($entity->toUrl('canonical'));

    return $status;
  }

}
