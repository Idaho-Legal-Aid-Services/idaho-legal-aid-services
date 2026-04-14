<?php

declare(strict_types=1);

namespace Drupal\ilas_site_assistant_governance\Entity;

use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\ContentEntityDeleteForm;
use Drupal\Core\Entity\EntityChangedTrait;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\EntityViewBuilder;
use Drupal\Core\Entity\Form\ContentEntityForm;
use Drupal\Core\Entity\Form\RevisionDeleteForm;
use Drupal\Core\Entity\Form\RevisionRevertForm;
use Drupal\Core\Entity\RevisionableContentEntityBase;
use Drupal\Core\Entity\Routing\AdminHtmlRouteProvider;
use Drupal\Core\Entity\Routing\RevisionHtmlRouteProvider;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\ilas_site_assistant\Service\ObservabilityPayloadMinimizer;
use Drupal\ilas_site_assistant\Service\PiiRedactor;
use Drupal\ilas_site_assistant_governance\AssistantGapItemAccessControlHandler;
use Drupal\ilas_site_assistant_governance\AssistantGapItemListBuilder;
use Drupal\ilas_site_assistant_governance\AssistantGapItemStorageSchema;
use Drupal\ilas_site_assistant_governance\Form\AssistantGapItemForm;
use Drupal\user\EntityOwnerInterface;
use Drupal\user\EntityOwnerTrait;
use Drupal\views\EntityViewsData;

/**
 * Canonical no-answer review entity for assistant governance.
 */
#[ContentEntityType(
  id: 'assistant_gap_item',
  label: new TranslatableMarkup('Assistant gap item'),
  label_collection: new TranslatableMarkup('Assistant gap items'),
  label_singular: new TranslatableMarkup('assistant gap item'),
  label_plural: new TranslatableMarkup('assistant gap items'),
  handlers: [
    'storage_schema' => AssistantGapItemStorageSchema::class,
    'view_builder' => EntityViewBuilder::class,
    'list_builder' => AssistantGapItemListBuilder::class,
    'access' => AssistantGapItemAccessControlHandler::class,
    'form' => [
      'default' => AssistantGapItemForm::class,
      'edit' => AssistantGapItemForm::class,
      'delete' => ContentEntityDeleteForm::class,
      'revision-delete' => RevisionDeleteForm::class,
      'revision-revert' => RevisionRevertForm::class,
    ],
    'views_data' => EntityViewsData::class,
    'route_provider' => [
      'html' => AdminHtmlRouteProvider::class,
      'revision' => RevisionHtmlRouteProvider::class,
    ],
  ],
  entity_keys: [
    'id' => 'id',
    'uuid' => 'uuid',
    'revision' => 'revision_id',
    'label' => 'label',
    'owner' => 'uid',
  ],
  links: [
    'canonical' => '/admin/reports/ilas-assistant/gaps/{assistant_gap_item}',
    'edit-form' => '/admin/reports/ilas-assistant/gaps/{assistant_gap_item}/edit',
    'delete-form' => '/admin/reports/ilas-assistant/gaps/{assistant_gap_item}/delete',
    'collection' => '/admin/reports/ilas-assistant/gaps/list',
    'revision' => '/admin/reports/ilas-assistant/gaps/{assistant_gap_item}/revision/{assistant_gap_item_revision}/view',
    'revision-delete-form' => '/admin/reports/ilas-assistant/gaps/{assistant_gap_item}/revision/{assistant_gap_item_revision}/delete',
    'revision-revert-form' => '/admin/reports/ilas-assistant/gaps/{assistant_gap_item}/revision/{assistant_gap_item_revision}/revert',
    'version-history' => '/admin/reports/ilas-assistant/gaps/{assistant_gap_item}/revisions',
  ],
  admin_permission: 'administer assistant gap items',
  collection_permission: 'view assistant gap items',
  base_table: 'assistant_gap_item',
  revision_table: 'assistant_gap_item_revision',
  show_revision_ui: TRUE,
  revision_metadata_keys: [
    'revision_user' => 'revision_user',
    'revision_created' => 'revision_created',
    'revision_log_message' => 'revision_log_message',
  ],
)]
class AssistantGapItem extends RevisionableContentEntityBase implements EntityOwnerInterface {

  use EntityChangedTrait;
  use EntityOwnerTrait;

  public const STATE_NEW = 'new';
  public const STATE_NEEDS_REVIEW = 'needs_review';
  public const STATE_REVIEWED = 'reviewed';
  public const STATE_RESOLVED = 'resolved';
  public const STATE_ARCHIVED = 'archived';

  public const FLAG_POTENTIAL_FAQ_CANDIDATE = 'potential_faq_candidate';
  public const FLAG_POSSIBLE_TAXONOMY_GAP = 'possible_taxonomy_gap';
  public const FLAG_NEEDS_CONTENT_UPDATE = 'needs_content_update';
  public const FLAG_ESCALATE_TO_EDITOR = 'escalate_to_editor';
  public const FLAG_DUPLICATE_ISSUE = 'duplicate_issue';
  public const FLAG_POTENTIAL_SEARCH_TUNING = 'potential_search_tuning';
  public const FLAG_POTENTIAL_CONTENT_GAP = 'potential_content_gap';
  public const FLAG_POLICY_REVIEW = 'policy_review';
  public const FLAG_HIGH_VOLUME = 'high_volume';

  public const RESOLUTION_FAQ_CREATED = 'faq_created';
  public const RESOLUTION_CONTENT_UPDATED = 'content_updated';
  public const RESOLUTION_SEARCH_TUNED = 'search_tuned';
  public const RESOLUTION_EXPECTED_OOS = 'expected_oos';
  public const RESOLUTION_DUPLICATE = 'duplicate';
  public const RESOLUTION_FALSE_POSITIVE = 'false_positive';
  public const RESOLUTION_OTHER = 'other';

  /**
   * Returns allowed review states.
   */
  public static function stateOptions(): array {
    return [
      self::STATE_NEW => (string) t('New'),
      self::STATE_NEEDS_REVIEW => (string) t('Needs review'),
      self::STATE_REVIEWED => (string) t('Reviewed'),
      self::STATE_RESOLVED => (string) t('Resolved'),
      self::STATE_ARCHIVED => (string) t('Archived'),
    ];
  }

  /**
   * Returns allowed topic assignment sources.
   */
  public static function topicAssignmentSourceOptions(): array {
    return [
      'selection' => (string) t('Selection'),
      'router' => (string) t('Router'),
      'retrieval' => (string) t('Retrieval'),
      'reviewer' => (string) t('Reviewer'),
      'legacy_none' => (string) t('Legacy none'),
      'unknown' => (string) t('Unknown'),
    ];
  }

  /**
   * Returns allowed resolution codes.
   */
  public static function resolutionCodeOptions(): array {
    return [
      self::RESOLUTION_FAQ_CREATED => (string) t('FAQ created'),
      self::RESOLUTION_CONTENT_UPDATED => (string) t('Content updated'),
      self::RESOLUTION_SEARCH_TUNED => (string) t('Search tuned'),
      self::RESOLUTION_EXPECTED_OOS => (string) t('Expected out of scope'),
      self::RESOLUTION_DUPLICATE => (string) t('Duplicate'),
      self::RESOLUTION_FALSE_POSITIVE => (string) t('False positive'),
      self::RESOLUTION_OTHER => (string) t('Other'),
    ];
  }

  /**
   * Returns allowed secondary flags.
   */
  public static function secondaryFlagOptions(): array {
    return [
      self::FLAG_POTENTIAL_FAQ_CANDIDATE => (string) t('Potential FAQ candidate'),
      self::FLAG_POSSIBLE_TAXONOMY_GAP => (string) t('Possible taxonomy gap'),
      self::FLAG_NEEDS_CONTENT_UPDATE => (string) t('Needs content update'),
      self::FLAG_ESCALATE_TO_EDITOR => (string) t('Escalate to editor'),
      self::FLAG_DUPLICATE_ISSUE => (string) t('Duplicate issue'),
      self::FLAG_POTENTIAL_SEARCH_TUNING => (string) t('Potential search tuning'),
      self::FLAG_POTENTIAL_CONTENT_GAP => (string) t('Potential content gap'),
      self::FLAG_POLICY_REVIEW => (string) t('Policy review'),
      self::FLAG_HIGH_VOLUME => (string) t('High volume'),
    ];
  }

  /**
   * Returns the transition graph.
   */
  public static function transitionMap(): array {
    return [
      self::STATE_NEW => [self::STATE_NEEDS_REVIEW],
      self::STATE_NEEDS_REVIEW => [self::STATE_REVIEWED],
      self::STATE_REVIEWED => [self::STATE_RESOLVED],
      self::STATE_RESOLVED => [self::STATE_ARCHIVED, self::STATE_NEEDS_REVIEW],
      self::STATE_ARCHIVED => [self::STATE_NEEDS_REVIEW],
    ];
  }

  /**
   * Returns TRUE when the current user may perform the transition.
   */
  public static function canTransition(string $from, string $to, AccountInterface $account): bool {
    if ($account->hasPermission('administer assistant gap items')) {
      return TRUE;
    }

    $allowed = self::transitionMap()[$from] ?? [];
    if (!in_array($to, $allowed, TRUE)) {
      return FALSE;
    }

    return match ($to) {
      self::STATE_NEEDS_REVIEW => $account->hasPermission('transition assistant gap items to needs_review') || $account->hasPermission('reopen assistant gap items'),
      self::STATE_REVIEWED => $account->hasPermission('transition assistant gap items to reviewed'),
      self::STATE_RESOLVED => $account->hasPermission('transition assistant gap items to resolved'),
      self::STATE_ARCHIVED => $account->hasPermission('transition assistant gap items to archived'),
      default => FALSE,
    };
  }

  /**
   * {@inheritdoc}
   */
  public static function preCreate(EntityStorageInterface $storage, array &$values): void {
    parent::preCreate($storage, $values);
    $values['uid'] = $values['uid'] ?? \Drupal::currentUser()->id();
    $values['review_state'] = $values['review_state'] ?? self::STATE_NEW;
  }

  /**
   * Returns the current review state.
   */
  public function getReviewState(): string {
    return (string) ($this->get('review_state')->value ?? self::STATE_NEW);
  }

  /**
   * Applies state side effects before save.
   */
  public function applyTransition(string $new_state, int $acting_uid = 0): self {
    $current_state = $this->getReviewState();
    if ($current_state === $new_state) {
      return $this;
    }

    $now = \Drupal::time()->getRequestTime();
    $this->set('review_state', $new_state);

    if (in_array($new_state, [self::STATE_REVIEWED, self::STATE_RESOLVED, self::STATE_ARCHIVED], TRUE)) {
      $this->set('reviewed_at', $now);
      if ($acting_uid > 0) {
        $this->set('reviewed_uid', $acting_uid);
      }
    }

    if ($new_state === self::STATE_RESOLVED || $new_state === self::STATE_ARCHIVED) {
      $this->set('resolved_at', $now);
      if ($acting_uid > 0) {
        $this->set('resolved_uid', $acting_uid);
      }
      $this->set('occurrence_count_unresolved', 0);
      $this->set('purge_after', $now + (30 * 86400));
    }

    if ($new_state === self::STATE_NEEDS_REVIEW && in_array($current_state, [self::STATE_RESOLVED, self::STATE_ARCHIVED], TRUE)) {
      $this->set('resolved_at', NULL);
      $this->set('resolved_uid', NULL);
      $this->set('purge_after', NULL);
      $this->set('resolution_code', NULL);
      $this->set('resolution_reference', NULL);
      $this->set('resolution_notes', NULL);
    }

    return $this;
  }

  /**
   * Adds a secondary flag if it is not already present.
   */
  public function addSecondaryFlag(string $flag): self {
    $flag = trim($flag);
    if ($flag === '' || !isset(self::secondaryFlagOptions()[$flag])) {
      return $this;
    }

    $values = array_column($this->get('secondary_flags')->getValue(), 'value');
    if (!in_array($flag, $values, TRUE)) {
      $values[] = $flag;
      $this->set('secondary_flags', $values);
    }

    return $this;
  }

  /**
   * Applies current-state legal hold fields.
   */
  public function setHoldState(bool $is_held, ?string $reason = NULL, int $acting_uid = 0): self {
    $now = \Drupal::time()->getRequestTime();
    $this->set('is_held', $is_held ? 1 : 0);

    if ($is_held) {
      $this->set('held_at', $now);
      $this->set('held_by_uid', $acting_uid > 0 ? $acting_uid : NULL);
      $this->set('hold_reason_summary', $reason !== NULL ? PiiRedactor::redactForStorage($reason, 255) : NULL);
    }
    else {
      $this->set('held_at', NULL);
      $this->set('held_by_uid', NULL);
      $this->set('hold_reason_summary', NULL);
    }

    return $this;
  }

  /**
   * Refreshes the safe generated label.
   */
  public function refreshDerivedLabel(): self {
    $cluster_hash = (string) ($this->get('cluster_hash')->value ?? '');
    $lang = (string) ($this->get('language_hint')->value ?? 'unknown');
    $topic_token = 'unknown';
    $topic = $this->get('primary_topic_tid')->entity;
    if ($topic) {
      $topic_token = self::normalizeTopicToken((string) $topic->label());
    }

    $prefix = $cluster_hash !== '' ? ObservabilityPayloadMinimizer::hashPrefix($cluster_hash, 12) : 'pending';
    $this->set('label', sprintf('gap:%s:%s:%s', $prefix, $lang !== '' ? $lang : 'unknown', $topic_token));

    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function preSave(EntityStorageInterface $storage): void {
    parent::preSave($storage);

    if (!$this->get('resolution_notes')->isEmpty()) {
      $this->set('resolution_notes', PiiRedactor::redactForStorage((string) $this->get('resolution_notes')->value, 2000));
    }
    if (!$this->get('hold_reason_summary')->isEmpty()) {
      $this->set('hold_reason_summary', PiiRedactor::redactForStorage((string) $this->get('hold_reason_summary')->value, 255));
    }

    $this->refreshDerivedLabel();
  }

  /**
   * Normalizes a taxonomy label into a safe token for generated labels.
   */
  protected static function normalizeTopicToken(string $value): string {
    $normalized = mb_strtolower(trim($value));
    $normalized = preg_replace('/[^a-z0-9]+/', '_', $normalized);
    $normalized = trim((string) $normalized, '_');
    return $normalized !== '' ? $normalized : 'unknown';
  }

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);
    $fields += static::ownerBaseFieldDefinitions($entity_type);

    $fields['label'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Label'))
      ->setDescription(t('Generated safe label for the gap item.'))
      ->setSetting('max_length', 128)
      ->setRevisionable(TRUE)
      ->setDisplayOptions('view', [
        'label' => 'hidden',
        'type' => 'string',
        'weight' => -20,
      ]);

    $fields['cluster_hash'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Cluster hash'))
      ->setSetting('max_length', 64)
      ->setRequired(TRUE)
      ->setRevisionable(FALSE)
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'string',
        'weight' => -18,
      ]);

    $fields['query_hash'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Query hash'))
      ->setSetting('max_length', 64)
      ->setRequired(TRUE)
      ->setRevisionable(FALSE)
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'string',
        'weight' => -17,
      ]);

    $fields['exemplar_redacted_query'] = BaseFieldDefinition::create('string_long')
      ->setLabel(t('Exemplar redacted query'))
      ->setRevisionable(FALSE)
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'basic_string',
        'weight' => -16,
      ]);

    $fields['language_hint'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Language hint'))
      ->setSetting('max_length', 16)
      ->setRequired(TRUE)
      ->setDefaultValue('unknown')
      ->setRevisionable(FALSE)
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'string',
        'weight' => -15,
      ]);

    $fields['query_length_bucket'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Query length bucket'))
      ->setSetting('max_length', 16)
      ->setRequired(TRUE)
      ->setDefaultValue('empty')
      ->setRevisionable(FALSE)
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'string',
        'weight' => -14,
      ]);

    $fields['redaction_profile'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Redaction profile'))
      ->setSetting('max_length', 255)
      ->setRequired(TRUE)
      ->setDefaultValue('none')
      ->setRevisionable(FALSE)
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'string',
        'weight' => -13,
      ]);

    $fields['review_state'] = BaseFieldDefinition::create('list_string')
      ->setLabel(t('Review state'))
      ->setRequired(TRUE)
      ->setDefaultValue(self::STATE_NEW)
      ->setRevisionable(TRUE)
      ->setSetting('allowed_values', self::stateOptions())
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'list_default',
        'weight' => -12,
      ])
      ->setDisplayOptions('form', [
        'type' => 'options_select',
        'weight' => -12,
      ]);

    $fields['primary_topic_tid'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Primary topic'))
      ->setSetting('target_type', 'taxonomy_term')
      ->setSetting('handler', 'default:taxonomy_term')
      ->setSetting('handler_settings', ['target_bundles' => ['topics' => 'topics']])
      ->setRevisionable(TRUE)
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'entity_reference_label',
        'weight' => -11,
      ])
      ->setDisplayOptions('form', [
        'type' => 'entity_reference_autocomplete',
        'weight' => -11,
        'settings' => [
          'match_operator' => 'CONTAINS',
          'size' => 60,
          'placeholder' => '',
        ],
      ]);

    $fields['primary_service_area_tid'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Primary service area'))
      ->setSetting('target_type', 'taxonomy_term')
      ->setSetting('handler', 'default:taxonomy_term')
      ->setSetting('handler_settings', ['target_bundles' => ['service_areas' => 'service_areas']])
      ->setRevisionable(TRUE)
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'entity_reference_label',
        'weight' => -10,
      ])
      ->setDisplayOptions('form', [
        'type' => 'entity_reference_autocomplete',
        'weight' => -10,
        'settings' => [
          'match_operator' => 'CONTAINS',
          'size' => 60,
          'placeholder' => '',
        ],
      ]);

    $fields['topic_assignment_source'] = BaseFieldDefinition::create('list_string')
      ->setLabel(t('Topic assignment source'))
      ->setRequired(TRUE)
      ->setDefaultValue('unknown')
      ->setRevisionable(TRUE)
      ->setSetting('allowed_values', self::topicAssignmentSourceOptions())
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'list_default',
        'weight' => -9,
      ]);

    $fields['topic_assignment_confidence'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('Topic assignment confidence'))
      ->setRevisionable(TRUE)
      ->setSetting('unsigned', TRUE)
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'number_integer',
        'weight' => -8,
      ]);

    $fields['first_seen'] = BaseFieldDefinition::create('timestamp')
      ->setLabel(t('First seen'))
      ->setRevisionable(FALSE)
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'timestamp',
        'weight' => -7,
        'settings' => ['date_format' => 'short'],
      ]);

    $fields['last_seen'] = BaseFieldDefinition::create('timestamp')
      ->setLabel(t('Last seen'))
      ->setRevisionable(FALSE)
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'timestamp',
        'weight' => -6,
        'settings' => ['date_format' => 'short'],
      ]);

    $fields['occurrence_count_total'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('Total occurrences'))
      ->setRequired(TRUE)
      ->setDefaultValue(0)
      ->setSetting('unsigned', TRUE)
      ->setRevisionable(FALSE)
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'number_integer',
        'weight' => -5,
      ]);

    $fields['occurrence_count_unresolved'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('Unresolved occurrences'))
      ->setRequired(TRUE)
      ->setDefaultValue(0)
      ->setSetting('unsigned', TRUE)
      ->setRevisionable(FALSE)
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'number_integer',
        'weight' => -4,
      ]);

    $fields['first_conversation_id'] = BaseFieldDefinition::create('string')
      ->setLabel(t('First conversation ID'))
      ->setSetting('max_length', 36)
      ->setRevisionable(FALSE);

    $fields['latest_conversation_id'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Latest conversation ID'))
      ->setSetting('max_length', 36)
      ->setRevisionable(FALSE);

    $fields['latest_request_id'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Latest request ID'))
      ->setSetting('max_length', 36)
      ->setRevisionable(FALSE);

    $fields['assigned_uid'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Assigned reviewer'))
      ->setSetting('target_type', 'user')
      ->setSetting('handler', 'default')
      ->setRevisionable(TRUE)
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'entity_reference_label',
        'weight' => 1,
      ])
      ->setDisplayOptions('form', [
        'type' => 'entity_reference_autocomplete',
        'weight' => 1,
        'settings' => [
          'match_operator' => 'CONTAINS',
          'size' => 60,
          'placeholder' => '',
        ],
      ]);

    $fields['reviewed_at'] = BaseFieldDefinition::create('timestamp')
      ->setLabel(t('Reviewed at'))
      ->setRevisionable(TRUE)
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'timestamp',
        'weight' => 2,
        'settings' => ['date_format' => 'short'],
      ]);

    $fields['reviewed_uid'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Reviewed by'))
      ->setSetting('target_type', 'user')
      ->setSetting('handler', 'default')
      ->setRevisionable(TRUE)
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'entity_reference_label',
        'weight' => 3,
      ]);

    $fields['resolved_at'] = BaseFieldDefinition::create('timestamp')
      ->setLabel(t('Resolved at'))
      ->setRevisionable(TRUE)
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'timestamp',
        'weight' => 4,
        'settings' => ['date_format' => 'short'],
      ]);

    $fields['resolved_uid'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Resolved by'))
      ->setSetting('target_type', 'user')
      ->setSetting('handler', 'default')
      ->setRevisionable(TRUE)
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'entity_reference_label',
        'weight' => 5,
      ]);

    $fields['resolution_code'] = BaseFieldDefinition::create('list_string')
      ->setLabel(t('Resolution code'))
      ->setSetting('allowed_values', self::resolutionCodeOptions())
      ->setRevisionable(TRUE)
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'list_default',
        'weight' => 6,
      ])
      ->setDisplayOptions('form', [
        'type' => 'options_select',
        'weight' => 6,
      ]);

    $fields['resolution_reference'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Resolution reference'))
      ->setSetting('max_length', 255)
      ->setRevisionable(TRUE)
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'string',
        'weight' => 7,
      ])
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => 7,
      ]);

    $fields['resolution_notes'] = BaseFieldDefinition::create('string_long')
      ->setLabel(t('Resolution notes'))
      ->setRevisionable(TRUE)
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'basic_string',
        'weight' => 8,
      ])
      ->setDisplayOptions('form', [
        'type' => 'string_textarea',
        'weight' => 8,
      ]);

    $fields['secondary_flags'] = BaseFieldDefinition::create('list_string')
      ->setLabel(t('Secondary flags'))
      ->setSetting('allowed_values', self::secondaryFlagOptions())
      ->setCardinality(BaseFieldDefinition::CARDINALITY_UNLIMITED)
      ->setRevisionable(TRUE)
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'list_default',
        'weight' => 9,
      ])
      ->setDisplayOptions('form', [
        'type' => 'options_buttons',
        'weight' => 9,
      ]);

    $fields['is_held'] = BaseFieldDefinition::create('boolean')
      ->setLabel(t('Legal hold'))
      ->setDefaultValue(FALSE)
      ->setRevisionable(TRUE)
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'boolean',
        'weight' => 10,
        'settings' => [
          'format' => 'default',
          'format_custom_true' => 'Yes',
          'format_custom_false' => 'No',
        ],
      ])
      ->setDisplayOptions('form', [
        'type' => 'boolean_checkbox',
        'weight' => 10,
      ]);

    $fields['held_at'] = BaseFieldDefinition::create('timestamp')
      ->setLabel(t('Held at'))
      ->setRevisionable(TRUE)
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'timestamp',
        'weight' => 11,
        'settings' => ['date_format' => 'short'],
      ]);

    $fields['held_by_uid'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Held by'))
      ->setSetting('target_type', 'user')
      ->setSetting('handler', 'default')
      ->setRevisionable(TRUE)
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'entity_reference_label',
        'weight' => 12,
      ]);

    $fields['hold_reason_summary'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Hold reason summary'))
      ->setSetting('max_length', 255)
      ->setRevisionable(TRUE)
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'string',
        'weight' => 13,
      ])
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => 13,
      ]);

    $fields['purge_after'] = BaseFieldDefinition::create('timestamp')
      ->setLabel(t('Purge after'))
      ->setRevisionable(FALSE)
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'timestamp',
        'weight' => 14,
        'settings' => ['date_format' => 'short'],
      ]);

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(t('Created'))
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'timestamp',
        'weight' => 15,
        'settings' => ['date_format' => 'short'],
      ]);

    $fields['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel(t('Changed'))
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'timestamp',
        'weight' => 16,
        'settings' => ['date_format' => 'short'],
      ]);

    $fields['revision_log_message']
      ->setDisplayOptions('form', [
        'type' => 'string_textarea',
        'weight' => 50,
      ]);

    return $fields;
  }

}
