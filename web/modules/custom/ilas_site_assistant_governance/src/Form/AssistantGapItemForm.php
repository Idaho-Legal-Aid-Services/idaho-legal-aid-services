<?php

declare(strict_types=1);

namespace Drupal\ilas_site_assistant_governance\Form;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\ilas_site_assistant_governance\Entity\AssistantGapItem;
use Drupal\ilas_site_assistant_governance\Service\GapReviewRules;
use Drupal\ilas_site_assistant_governance\Service\LegalHoldLogger;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Reviewer-first edit form for assistant gap items.
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
      '#markup' => '<p>' . $this->t('Update the status, topic assignment, flags, and reviewer notes for this redacted gap item. Dismissing a gap marks it resolved as a false positive and removes it from open-work queues.') . '</p>',
      '#weight' => -100,
    ];

    if (isset($form['review_state'])) {
      $form['review_state']['#title'] = $this->t('Status');
      $form['review_state']['#description'] = $this->t('Use the quick action buttons below for the most common reviewer outcomes.');
    }
    if (isset($form['assigned_uid'])) {
      $form['assigned_uid']['#title'] = $this->t('Assigned reviewer');
    }
    if (isset($form['primary_topic_tid'])) {
      $form['primary_topic_tid']['#title'] = $this->t('Assigned topic');
      $form['primary_topic_tid']['#description'] = $this->t('Choose a topic when the current assignment is missing or unclear.');
    }
    if (isset($form['primary_service_area_tid'])) {
      $form['primary_service_area_tid']['#title'] = $this->t('Assigned service area');
    }
    if (isset($form['resolution_code'])) {
      $form['resolution_code']['#title'] = $this->t('Resolution outcome');
    }
    if (isset($form['resolution_reference'])) {
      $form['resolution_reference']['#title'] = $this->t('Resolution reference');
      $form['resolution_reference']['#description'] = $this->t('Optional for general resolutions. Use this for FAQ, content, or search-tuning follow-up identifiers when available.');
    }
    if (isset($form['resolution_notes'])) {
      $form['resolution_notes']['#title'] = $this->t('Reviewer notes');
      $form['resolution_notes']['#description'] = $this->t('Notes are redacted before storage.');
    }
    if (isset($form['secondary_flags'])) {
      $form['secondary_flags']['#title'] = $this->t('Flags');
      $form['secondary_flags']['#description'] = $this->t('Use flags for FAQ candidates, content updates, and other follow-up work.');
    }
    if (isset($form['is_held'])) {
      $form['is_held']['#title'] = $this->t('Legal hold');
    }
    if (isset($form['hold_reason_summary'])) {
      $form['hold_reason_summary']['#title'] = $this->t('Hold reason summary');
    }

    $form['assignment'] = [
      '#type' => 'details',
      '#title' => $this->t('Assignment'),
      '#open' => TRUE,
      '#weight' => 0,
    ];
    $this->moveElement($form, 'review_state', 'assignment');
    $this->moveElement($form, 'assigned_uid', 'assignment');
    $this->moveElement($form, 'primary_topic_tid', 'assignment');
    $this->moveElement($form, 'primary_service_area_tid', 'assignment');

    $form['disposition'] = [
      '#type' => 'details',
      '#title' => $this->t('Disposition'),
      '#open' => TRUE,
      '#weight' => 5,
    ];
    $this->moveElement($form, 'resolution_code', 'disposition');
    $this->moveElement($form, 'resolution_reference', 'disposition');
    $this->moveElement($form, 'resolution_notes', 'disposition');
    $this->moveElement($form, 'secondary_flags', 'disposition');

    $form['legal_hold'] = [
      '#type' => 'details',
      '#title' => $this->t('Legal hold'),
      '#open' => FALSE,
      '#weight' => 10,
    ];
    $this->moveElement($form, 'is_held', 'legal_hold');
    $this->moveElement($form, 'hold_reason_summary', 'legal_hold');

    if (isset($form['advanced'])) {
      $form['advanced']['#weight'] = 95;
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  protected function actions(array $form, FormStateInterface $form_state): array {
    $actions = parent::actions($form, $form_state);
    /** @var \Drupal\ilas_site_assistant_governance\Entity\AssistantGapItem $entity */
    $entity = $this->entity;

    $actions['submit']['#value'] = $this->t('Save review');
    $actions['submit']['#review_action'] = 'save_review';

    if ($this->canTriggerTransition(AssistantGapItem::STATE_REVIEWED)) {
      $actions['mark_reviewed'] = [
        '#type' => 'submit',
        '#value' => $this->t('Mark reviewed'),
        '#weight' => 10,
        '#review_action' => 'mark_reviewed',
        '#submit' => ['::submitForm', '::save'],
      ];
    }

    if ($this->canTriggerTransition(AssistantGapItem::STATE_RESOLVED)) {
      $actions['mark_resolved'] = [
        '#type' => 'submit',
        '#value' => $this->t('Mark resolved'),
        '#weight' => 15,
        '#review_action' => 'mark_resolved',
        '#submit' => ['::submitForm', '::save'],
      ];
      $actions['dismiss_gap'] = [
        '#type' => 'submit',
        '#value' => $this->t('Dismiss gap'),
        '#weight' => 20,
        '#review_action' => 'dismiss_gap',
        '#submit' => ['::submitForm', '::save'],
      ];
    }

    if (
      in_array($entity->getReviewState(), [AssistantGapItem::STATE_RESOLVED, AssistantGapItem::STATE_ARCHIVED], TRUE)
      && $this->canTriggerTransition(AssistantGapItem::STATE_NEEDS_REVIEW)
    ) {
      $actions['reopen'] = [
        '#type' => 'submit',
        '#value' => $this->t('Reopen'),
        '#weight' => 25,
        '#review_action' => 'reopen',
        '#submit' => ['::submitForm', '::save'],
      ];
    }

    if ($this->getRouteMatch()->getRouteName() === 'entity.assistant_gap_item.canonical') {
      unset($actions['delete']);
    }

    return $actions;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $entity = parent::validateForm($form, $form_state);
    assert($entity instanceof AssistantGapItem);

    $original = $entity->getOriginal();
    $action = $this->getReviewAction($form_state);
    $from = $original instanceof AssistantGapItem ? $original->getReviewState() : AssistantGapItem::STATE_NEW;
    $to = $this->determineTargetState($entity, $action);

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

    foreach (GapReviewRules::validateDisposition(
      $to,
      $this->determineResolutionCode($entity, $action, $to),
      $this->normalizeResolutionReference($entity, $action),
      $this->normalizeResolutionNotes($entity, $action),
    ) as $field_name => $message) {
      $form_state->setErrorByName($field_name, $this->t($message));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state): int {
    /** @var \Drupal\ilas_site_assistant_governance\Entity\AssistantGapItem $entity */
    $entity = $this->entity;
    $original = $entity->getOriginal();
    $action = $this->getReviewAction($form_state);
    $target_state = $this->determineTargetState($entity, $action);
    $original_state = $original instanceof AssistantGapItem ? $original->getReviewState() : $entity->getReviewState();
    $acting_uid = (int) $this->currentUser()->id();
    $was_held = $original instanceof AssistantGapItem ? !empty($original->get('is_held')->value) : FALSE;
    $is_held = !empty($entity->get('is_held')->value);

    if ($acting_uid > 0 && $entity->get('assigned_uid')->isEmpty()) {
      $entity->set('assigned_uid', $acting_uid);
    }

    $this->stampReviewerTopicAssignment($entity, $original instanceof AssistantGapItem ? $original : NULL);

    $resolution_code = $this->determineResolutionCode($entity, $action, $target_state);
    if ($resolution_code !== NULL) {
      $entity->set('resolution_code', $resolution_code);
    }

    $entity->set('review_state', $original_state);
    $entity->applyTransition($target_state, $acting_uid);

    $entity->setNewRevision(TRUE);
    $entity->setRevisionUserId($acting_uid);
    if ($entity->getRevisionLogMessage() === '') {
      $entity->setRevisionLogMessage($this->defaultRevisionLogMessage($action, $target_state));
    }

    $status = parent::save($form, $form_state);

    if ($was_held !== $is_held) {
      if ($is_held) {
        $this->legalHoldLogger->recordHold(
          'gap_item',
          (string) $entity->id(),
          (string) ($entity->get('hold_reason_summary')->value ?? ''),
          NULL,
          $acting_uid,
        );
      }
      else {
        $this->legalHoldLogger->recordRelease(
          'gap_item',
          (string) $entity->id(),
          $acting_uid,
        );
      }
    }

    $this->messenger()->addStatus($this->statusMessageForAction($action, $entity));
    $form_state->setRedirect(
      $entity->isClosed() ? 'view.assistant_gap_items.page_queue' : 'entity.assistant_gap_item.canonical',
      $entity->isClosed() ? [] : ['assistant_gap_item' => $entity->id()],
    );

    return $status;
  }

  /**
   * Moves a built form element under a details wrapper.
   */
  protected function moveElement(array &$form, string $key, string $destination): void {
    if (!isset($form[$key])) {
      return;
    }

    $form[$destination][$key] = $form[$key];
    unset($form[$key]);
  }

  /**
   * Returns the active reviewer action.
   */
  protected function getReviewAction(FormStateInterface $form_state): string {
    return (string) ($form_state->getTriggeringElement()['#review_action'] ?? 'save_review');
  }

  /**
   * Returns TRUE when the current reviewer can trigger a target state.
   */
  protected function canTriggerTransition(string $target_state): bool {
    return AssistantGapItem::canTransition($this->entity->getReviewState(), $target_state, $this->currentUser());
  }

  /**
   * Computes the effective target state for the active action.
   */
  protected function determineTargetState(AssistantGapItem $entity, string $action): string {
    return match ($action) {
      'mark_reviewed' => AssistantGapItem::STATE_REVIEWED,
      'mark_resolved', 'dismiss_gap' => AssistantGapItem::STATE_RESOLVED,
      'reopen' => AssistantGapItem::STATE_NEEDS_REVIEW,
      default => $entity->getReviewState(),
    };
  }

  /**
   * Computes the effective resolution code for the active action.
   */
  protected function determineResolutionCode(AssistantGapItem $entity, string $action, string $target_state): ?string {
    if ($action === 'reopen' || $target_state === AssistantGapItem::STATE_REVIEWED || $target_state === AssistantGapItem::STATE_NEEDS_REVIEW) {
      return NULL;
    }

    if ($action === 'dismiss_gap') {
      return AssistantGapItem::RESOLUTION_FALSE_POSITIVE;
    }

    $resolution_code = trim((string) ($entity->get('resolution_code')->value ?? ''));
    return GapReviewRules::normalizeCloseResolutionCode($target_state, $resolution_code);
  }

  /**
   * Normalizes resolution reference for validation.
   */
  protected function normalizeResolutionReference(AssistantGapItem $entity, string $action): ?string {
    if ($action === 'reopen') {
      return NULL;
    }

    return $entity->get('resolution_reference')->value ?? NULL;
  }

  /**
   * Normalizes reviewer notes for validation.
   */
  protected function normalizeResolutionNotes(AssistantGapItem $entity, string $action): ?string {
    if ($action === 'reopen') {
      return NULL;
    }

    return $entity->get('resolution_notes')->value ?? NULL;
  }

  /**
   * Stamps reviewer topic assignment when unknown legacy data is corrected.
   */
  protected function stampReviewerTopicAssignment(AssistantGapItem $entity, ?AssistantGapItem $original): void {
    $original_topic_id = $original instanceof AssistantGapItem ? (int) ($original->get('primary_topic_tid')->target_id ?? 0) : 0;
    $original_service_area_id = $original instanceof AssistantGapItem ? (int) ($original->get('primary_service_area_tid')->target_id ?? 0) : 0;
    $topic_id = (int) ($entity->get('primary_topic_tid')->target_id ?? 0);
    $service_area_id = (int) ($entity->get('primary_service_area_tid')->target_id ?? 0);
    $assignment_source = (string) ($entity->get('topic_assignment_source')->value ?? 'unknown');

    if (!in_array($assignment_source, ['unknown', 'legacy_none'], TRUE)) {
      return;
    }

    if ($topic_id <= 0 && $service_area_id <= 0) {
      return;
    }

    if (
      $original_topic_id !== $topic_id
      || $original_service_area_id !== $service_area_id
      || !$entity->get('topic_assignment_confidence')->isEmpty()
      || !($original instanceof AssistantGapItem)
    ) {
      $entity->set('topic_assignment_confidence', NULL);
    }

    $entity->set('topic_assignment_source', 'reviewer');
  }

  /**
   * Returns a default revision log message for the triggered action.
   */
  protected function defaultRevisionLogMessage(string $action, string $target_state): string {
    return match ($action) {
      'mark_reviewed' => 'Reviewer marked the gap item as reviewed.',
      'mark_resolved' => 'Reviewer marked the gap item as resolved.',
      'dismiss_gap' => 'Reviewer dismissed the gap item as not a real gap.',
      'reopen' => 'Reviewer reopened the gap item for follow-up.',
      default => match ($target_state) {
        AssistantGapItem::STATE_RESOLVED => 'Reviewer saved the gap item as resolved.',
        AssistantGapItem::STATE_ARCHIVED => 'Reviewer archived the gap item.',
        default => 'Assistant gap item updated by staff review.',
      },
    };
  }

  /**
   * Returns a user-facing status message for the action.
   */
  protected function statusMessageForAction(string $action, AssistantGapItem $entity): string {
    return match ($action) {
      'mark_reviewed' => (string) $this->t('Marked %label as reviewed.', ['%label' => $entity->label()]),
      'mark_resolved' => (string) $this->t('Marked %label as resolved.', ['%label' => $entity->label()]),
      'dismiss_gap' => (string) $this->t('Dismissed %label as not a real gap.', ['%label' => $entity->label()]),
      'reopen' => (string) $this->t('Reopened %label for follow-up.', ['%label' => $entity->label()]),
      default => (string) $this->t('Saved assistant gap item %label.', ['%label' => $entity->label()]),
    };
  }

}
