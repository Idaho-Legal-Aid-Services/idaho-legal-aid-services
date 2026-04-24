<?php

declare(strict_types=1);

namespace Drupal\ilas_site_assistant_governance\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\ilas_site_assistant\Service\ObservabilityPayloadMinimizer;
use Drupal\ilas_site_assistant\Service\PiiRedactor;
use Drupal\ilas_site_assistant\Service\TopicResolver;
use Drupal\ilas_site_assistant_governance\Entity\AssistantGapItem;
use Psr\Log\LoggerInterface;

/**
 * Canonical writer for no-answer review queue items and hits.
 */
class GapItemManager {

  /**
   * Constructs the manager.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected Connection $database,
    protected TimeInterface $time,
    protected ConfigFactoryInterface $configFactory,
    protected LoggerInterface $logger,
    protected TopicResolver $topicResolver,
    protected GapItemIdentityBuilder $identityBuilder,
  ) {}

  /**
   * Records a no-answer occurrence and returns the canonical gap-item ID.
   */
  public function recordNoAnswer(string $query, array $context = []): ?int {
    try {
      $metadata = ObservabilityPayloadMinimizer::buildTextMetadataWithLanguage($query);
      if ($metadata['length_bucket'] === ObservabilityPayloadMinimizer::LENGTH_BUCKET_EMPTY) {
        return NULL;
      }

      $now = $this->time->getRequestTime();
      $topic_context = $this->deriveTopicContext($query, $context);
      $identity = $this->identityBuilder->buildFromRuntimeContext(
        $metadata['text_hash'],
        $metadata['language_hint'],
        $context,
        $topic_context,
      );

      /** @var \Drupal\Core\Entity\ContentEntityStorageInterface $storage */
      $storage = $this->entityTypeManager->getStorage('assistant_gap_item');
      $existing = $storage->loadByProperties(['cluster_hash' => $identity['cluster_hash']]);
      /** @var \Drupal\ilas_site_assistant_governance\Entity\AssistantGapItem|null $entity */
      $entity = $existing ? reset($existing) : NULL;

      if (!$entity instanceof AssistantGapItem) {
        $entity = $storage->create([
          'cluster_hash' => $identity['cluster_hash'],
          'query_hash' => $metadata['text_hash'],
          'exemplar_redacted_query' => PiiRedactor::redactForStorage($query, 2000),
          'language_hint' => $metadata['language_hint'],
          'query_length_bucket' => $metadata['length_bucket'],
          'redaction_profile' => $metadata['redaction_profile'],
          'identity_context_key' => $identity['identity_context_key'],
          'identity_source' => $identity['identity_source'],
          'identity_selection_key' => $identity['identity_selection_key'],
          'identity_intent' => $identity['identity_intent'],
          'identity_topic_tid' => $identity['identity_topic_tid'],
          'identity_service_area_tid' => $identity['identity_service_area_tid'],
          'review_state' => AssistantGapItem::STATE_NEW,
          'first_seen' => $now,
          'last_seen' => $now,
          'occurrence_count_total' => 1,
          'occurrence_count_unresolved' => 1,
          'first_conversation_id' => $this->truncate($context['conversation_id'] ?? '', 36),
          'latest_conversation_id' => $this->truncate($context['conversation_id'] ?? '', 36),
          'latest_request_id' => $this->truncate($context['request_id'] ?? '', 36),
          'topic_assignment_source' => $topic_context['assignment_source'],
          'topic_assignment_confidence' => $topic_context['confidence'],
        ]);
        $entity->setRevisionLogMessage('Created automatically from unanswered assistant query.');
        $entity->setNewRevision(TRUE);
      }
      else {
        $entity->set('last_seen', $now);
        $entity->set('occurrence_count_total', (int) ($entity->get('occurrence_count_total')->value ?? 0) + 1);
        $entity->set('occurrence_count_unresolved', (int) ($entity->get('occurrence_count_unresolved')->value ?? 0) + 1);
        $entity->set('latest_conversation_id', $this->truncate($context['conversation_id'] ?? '', 36) ?: NULL);
        $entity->set('latest_request_id', $this->truncate($context['request_id'] ?? '', 36) ?: NULL);

        if (in_array($entity->getReviewState(), [AssistantGapItem::STATE_RESOLVED, AssistantGapItem::STATE_ARCHIVED], TRUE)) {
          $entity->applyTransition(AssistantGapItem::STATE_NEEDS_REVIEW, 0);
          $entity->setRevisionLogMessage('Automatically reopened after a new unanswered occurrence.');
          $entity->setNewRevision(TRUE);
        }
      }

      if ($entity->get('primary_topic_tid')->isEmpty() && $topic_context['topic_id'] !== NULL) {
        $entity->set('primary_topic_tid', $topic_context['topic_id']);
      }
      if ($entity->get('primary_service_area_tid')->isEmpty() && $topic_context['service_area_id'] !== NULL) {
        $entity->set('primary_service_area_tid', $topic_context['service_area_id']);
      }

      $current_assignment_source = (string) ($entity->get('topic_assignment_source')->value ?? 'unknown');
      if ($entity->get('primary_topic_tid')->isEmpty()) {
        $entity->set('topic_assignment_confidence', NULL);
      }
      elseif ($entity->get('topic_assignment_confidence')->isEmpty() && $topic_context['confidence'] !== NULL) {
        $entity->set('topic_assignment_confidence', $topic_context['confidence']);
      }

      if (
        $current_assignment_source !== 'reviewer'
        && in_array($current_assignment_source, ['unknown', 'legacy_none', ''], TRUE)
        && $topic_context['assignment_source'] !== 'unknown'
      ) {
          $entity->set('topic_assignment_source', $topic_context['assignment_source']);
      }

      if ($entity->isNew()) {
        $entity->set('topic_assignment_confidence', $topic_context['confidence']);
      }

      $entity->save();
      $gap_item_id = (int) $entity->id();

      $this->database->insert('ilas_site_assistant_gap_hit')
        ->fields([
          'gap_item_id' => $gap_item_id,
          'conversation_id' => $this->nullableString($context['conversation_id'] ?? '', 36),
          'request_id' => $this->nullableString($context['request_id'] ?? '', 36),
          'occurred_at' => $now,
          'is_unresolved' => 1,
          'query_hash' => $metadata['text_hash'],
          'language_hint' => $metadata['language_hint'],
          'observed_topic_tid' => $topic_context['topic_id'],
          'observed_service_area_tid' => $topic_context['service_area_id'],
          'assignment_source' => $topic_context['assignment_source'],
          'intent' => $this->nullableString($context['intent_type'] ?? ($context['intent']['type'] ?? ''), 64),
          'active_selection_key' => $this->nullableString($context['active_selection_key'] ?? '', 64),
        ])
        ->execute();

      if ($this->shouldWriteAnalyticsRollups()) {
        if ($topic_context['topic_id'] !== NULL) {
          $this->rollupStat('no_answer_topic', 'tid:' . $topic_context['topic_id']);
        }
        if ($topic_context['service_area_id'] !== NULL) {
          $this->rollupStat('no_answer_service_area', 'tid:' . $topic_context['service_area_id']);
        }
      }

      return $gap_item_id;
    }
    catch (\Throwable $e) {
      $this->logger->error('Gap item recording failed: @class @error_signature', [
        '@class' => get_class($e),
        '@error_signature' => ObservabilityPayloadMinimizer::exceptionSignature($e),
      ]);
      return NULL;
    }
  }

  /**
   * Determines topic/service-area context for a no-answer occurrence.
   */
  protected function deriveTopicContext(string $query, array $context): array {
    $assignment_source = (string) ($context['assignment_source'] ?? 'unknown');
    $topic_id = $this->normalizeInt($context['topic_id'] ?? NULL);
    $service_area_id = $this->normalizeInt($context['service_area_id'] ?? NULL);
    $selection_topic_candidates = array_values(array_filter([
      trim((string) ($context['selection_query_label'] ?? '')),
      trim((string) ($context['selection_label'] ?? '')),
    ]));

    if ($assignment_source === 'unknown' && ($selection_topic_candidates !== [] || trim((string) ($context['active_selection_key'] ?? '')) !== '')) {
      $assignment_source = 'selection';
    }

    if ($topic_id === NULL) {
      $topic_label_candidates = [
        (string) ($context['topic_label'] ?? ''),
        (string) ($context['selection_label'] ?? ''),
      ];
      $topic_label_candidates = array_merge($selection_topic_candidates, $topic_label_candidates);

      foreach ($topic_label_candidates as $candidate) {
        if ($candidate === '') {
          continue;
        }
        $topic = $this->topicResolver->resolveFromText($candidate);
        if (is_array($topic) && !empty($topic['id'])) {
          $topic_id = (int) $topic['id'];
          $assignment_source = $assignment_source !== 'unknown' ? $assignment_source : 'selection';
          if ($service_area_id === NULL && !empty($topic['service_areas'][0]['id'])) {
            $service_area_id = (int) $topic['service_areas'][0]['id'];
          }
          break;
        }
      }
    }

    if ($service_area_id === NULL && !empty($context['selection_service_area'])) {
      $service_area_id = $this->resolveServiceAreaIdByName((string) $context['selection_service_area']);
    }

    if ($service_area_id === NULL && !empty($context['service_area_label'])) {
      $service_area_id = $this->resolveServiceAreaIdByName((string) $context['service_area_label']);
    }

    if ($service_area_id === NULL && $topic_id !== NULL) {
      $topic_info = $this->topicResolver->getTopicInfo($topic_id);
      if (is_array($topic_info) && !empty($topic_info['service_areas'][0]['id'])) {
        $service_area_id = (int) $topic_info['service_areas'][0]['id'];
      }
    }

    $confidence = $this->normalizeInt($context['topic_confidence'] ?? NULL);
    if ($topic_id === NULL) {
      $confidence = NULL;
    }
    elseif ($confidence === NULL) {
      $confidence = 80;
    }

    return [
      'topic_id' => $topic_id,
      'service_area_id' => $service_area_id,
      'assignment_source' => in_array($assignment_source, array_keys(AssistantGapItem::topicAssignmentSourceOptions()), TRUE) ? $assignment_source : 'unknown',
      'confidence' => $confidence,
    ];
  }

  /**
   * Resolves a service area term ID by human label or configured key.
   */
  protected function resolveServiceAreaIdByName(string $service_area_label): ?int {
    $normalized = mb_strtolower(trim($service_area_label));
    if ($normalized === '') {
      return NULL;
    }

    foreach ($this->topicResolver->getServiceAreas() as $service_area) {
      $name = mb_strtolower(trim((string) ($service_area['name'] ?? '')));
      $key = mb_strtolower(trim((string) ($service_area['key'] ?? '')));
      if ($normalized === $name || $normalized === $key) {
        return (int) $service_area['id'];
      }
    }

    return NULL;
  }

  /**
   * Writes a single aggregated analytics rollup row.
   */
  protected function rollupStat(string $event_type, string $event_value): void {
    if (!$this->database->schema()->tableExists('ilas_site_assistant_stats')) {
      return;
    }

    $date = date('Y-m-d', $this->time->getRequestTime());

    $this->database->merge('ilas_site_assistant_stats')
      ->keys([
        'event_type' => $event_type,
        'event_value' => $event_value,
        'date' => $date,
      ])
      ->fields([
        'event_type' => $event_type,
        'event_value' => $event_value,
        'date' => $date,
        'count' => 1,
      ])
      ->expression('count', 'count + 1')
      ->execute();
  }

  /**
   * Returns TRUE when analytics rollups should be written.
   */
  protected function shouldWriteAnalyticsRollups(): bool {
    return (bool) $this->configFactory->get('ilas_site_assistant.settings')->get('enable_logging');
  }

  /**
   * Returns an integer or NULL.
   */
  protected function normalizeInt(mixed $value): ?int {
    if ($value === NULL || $value === '') {
      return NULL;
    }

    $int = (int) $value;
    return $int > 0 ? $int : NULL;
  }

  /**
   * Truncates a scalar string value.
   */
  protected function truncate(mixed $value, int $length): string {
    return mb_substr(trim((string) $value), 0, $length);
  }

  /**
   * Returns a nullable truncated string.
   */
  protected function nullableString(mixed $value, int $length): ?string {
    $value = $this->truncate($value, $length);
    return $value !== '' ? $value : NULL;
  }

}
