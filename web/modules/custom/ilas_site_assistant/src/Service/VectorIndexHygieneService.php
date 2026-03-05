<?php

declare(strict_types=1);

namespace Drupal\ilas_site_assistant\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\State\StateInterface;
use Drupal\search_api\IndexInterface;
use Drupal\search_api\ServerInterface;
use Drupal\search_api\Tracker\TrackerInterface;
use Psr\Log\LoggerInterface;

/**
 * Enforces vector-index hygiene policy and refresh monitoring.
 */
final class VectorIndexHygieneService {

  /**
   * State key storing vector-index hygiene snapshot.
   */
  private const SNAPSHOT_STATE_KEY = 'ilas_site_assistant.vector_index_hygiene.snapshot';

  /**
   * State key storing degraded-alert cooldown timestamp.
   */
  private const ALERT_STATE_KEY = 'ilas_site_assistant.vector_index_hygiene.last_alert';

  /**
   * Config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected ConfigFactoryInterface $configFactory;

  /**
   * State service.
   *
   * @var \Drupal\Core\State\StateInterface
   */
  protected StateInterface $state;

  /**
   * Entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * Module logger.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected LoggerInterface $logger;

  /**
   * Constructs a vector-index hygiene service.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    StateInterface $state,
    EntityTypeManagerInterface $entity_type_manager,
    LoggerInterface $logger,
  ) {
    $this->configFactory = $config_factory;
    $this->state = $state;
    $this->entityTypeManager = $entity_type_manager;
    $this->logger = $logger;
  }

  /**
   * Runs scheduled incremental refresh for managed vector indexes.
   */
  public function runScheduledRefresh(): void {
    $policy = $this->getPolicy();
    if (empty($policy['enabled'])) {
      return;
    }

    $now = time();
    $snapshot = $this->state->get(self::SNAPSHOT_STATE_KEY);
    if (!is_array($snapshot)) {
      $snapshot = $this->newSnapshot($policy, $now);
    }

    $snapshot['recorded_at'] = $now;
    $snapshot['policy_version'] = (string) ($policy['policy_version'] ?? 'p2_del_03_v1');
    $snapshot['refresh_mode'] = (string) ($policy['refresh_mode'] ?? 'incremental');

    foreach (($policy['managed_indexes'] ?? []) as $index_key => $index_policy) {
      if (!is_string($index_key) || !is_array($index_policy)) {
        continue;
      }
      $snapshot['indexes'][$index_key] = $this->refreshIndexSnapshot(
        $index_key,
        $index_policy,
        is_array($snapshot['indexes'][$index_key] ?? NULL) ? $snapshot['indexes'][$index_key] : [],
        $policy,
        $now
      );
    }

    $snapshot = $this->applyDerivedSnapshotFields($snapshot, $policy, $now);
    $this->state->set(self::SNAPSHOT_STATE_KEY, $snapshot);
    $this->emitDegradedAlertIfNeeded($snapshot, $policy, $now);
  }

  /**
   * Returns the current vector-index hygiene snapshot.
   *
   * @return array
   *   Snapshot metadata.
   */
  public function getSnapshot(): array {
    $policy = $this->getPolicy();
    $snapshot = $this->state->get(self::SNAPSHOT_STATE_KEY);
    $now = time();

    if (!is_array($snapshot)) {
      $snapshot = $this->newSnapshot($policy, $now);
    }

    $snapshot = $this->applyDerivedSnapshotFields($snapshot, $policy, $now);
    return $snapshot;
  }

  /**
   * Refreshes snapshot state for one managed index.
   *
   * @param string $index_key
   *   Managed index key.
   * @param array $index_policy
   *   Managed index policy.
   * @param array $existing
   *   Existing index snapshot.
   * @param array $policy
   *   Global policy.
   * @param int $now
   *   Epoch seconds.
   *
   * @return array
   *   Updated index snapshot.
   */
  protected function refreshIndexSnapshot(
    string $index_key,
    array $index_policy,
    array $existing,
    array $policy,
    int $now,
  ): array {
    $index_id = (string) ($index_policy['index_id'] ?? '');
    $index_snapshot = array_replace(
      $this->newIndexSnapshot($index_key, $index_policy),
      $existing
    );
    $index_snapshot['last_run_at'] = $now;
    $index_snapshot['last_error'] = NULL;
    $index_snapshot['duration_ms'] = 0.0;
    $index_snapshot['items_processed_last_run'] = 0;

    if ($index_id === '') {
      $index_snapshot['status'] = 'unknown';
      $index_snapshot['metadata_status'] = 'unknown';
      $index_snapshot['last_error'] = 'missing_index_id';
      return $index_snapshot;
    }

    $start = microtime(TRUE);
    $index = $this->loadIndex($index_id);
    if (!$index) {
      $index_snapshot['status'] = 'degraded';
      $index_snapshot['metadata_status'] = 'unknown';
      $index_snapshot['exists'] = FALSE;
      $index_snapshot['enabled'] = FALSE;
      $index_snapshot['last_error'] = 'index_not_found';
      $index_snapshot['duration_ms'] = round((microtime(TRUE) - $start) * 1000, 2);
      return $index_snapshot;
    }

    $index_snapshot['exists'] = TRUE;
    $index_snapshot['enabled'] = (bool) $index->status();

    $metadata = $this->evaluateMetadata($index, $index_policy);
    $index_snapshot['metadata_status'] = $metadata['metadata_status'];
    $index_snapshot['drift_fields'] = $metadata['drift_fields'];
    $index_snapshot['observed'] = $metadata['observed'];

    $this->captureTrackerMetrics($index, $index_snapshot);
    $this->applyRefreshScheduleFields($index_snapshot, $policy, $now);

    if ($index_snapshot['due'] && $index_snapshot['enabled'] && ($policy['refresh_mode'] ?? 'incremental') === 'incremental') {
      try {
        $batch = max(1, (int) ($policy['max_items_per_run'] ?? 60));
        $processed = (int) $index->indexItems($batch);
        $index_snapshot['items_processed_last_run'] = $processed;
        $index_snapshot['last_refresh_at'] = $now;

        // Refresh tracker counters after indexing.
        $this->captureTrackerMetrics($index, $index_snapshot);
      }
      catch (\Throwable $e) {
        $index_snapshot['last_error'] = get_class($e) . ': ' . $e->getMessage();
      }
    }

    $index_snapshot['duration_ms'] = round((microtime(TRUE) - $start) * 1000, 2);

    $has_error = is_string($index_snapshot['last_error']) && $index_snapshot['last_error'] !== '';
    $metadata_degraded = $index_snapshot['metadata_status'] === 'drift';
    $enabled = (bool) ($index_snapshot['enabled'] ?? FALSE);
    if (!$enabled || $has_error || $metadata_degraded || !empty($index_snapshot['overdue'])) {
      $index_snapshot['status'] = 'degraded';
    }
    else {
      $index_snapshot['status'] = 'healthy';
    }

    return $index_snapshot;
  }

  /**
   * Loads a Search API index by ID.
   *
   * @param string $index_id
   *   Index machine name.
   *
   * @return \Drupal\search_api\IndexInterface|null
   *   Loaded index or NULL.
   */
  protected function loadIndex(string $index_id): ?IndexInterface {
    $storage = $this->entityTypeManager->getStorage('search_api_index');
    $entity = $storage->load($index_id);
    return $entity instanceof IndexInterface ? $entity : NULL;
  }

  /**
   * Evaluates metadata compliance for one index.
   *
   * @param \Drupal\search_api\IndexInterface $index
   *   Search API index.
   * @param array $index_policy
   *   Managed index policy.
   *
   * @return array
   *   Metadata status payload.
   */
  protected function evaluateMetadata(IndexInterface $index, array $index_policy): array {
    $drift_fields = [];
    $observed = [
      'server_id' => $index->getServerId(),
      'metric' => NULL,
      'dimensions' => NULL,
    ];

    $expected_server = (string) ($index_policy['expected_server_id'] ?? '');
    if ($expected_server !== '' && $observed['server_id'] !== $expected_server) {
      $drift_fields[] = 'server_id';
    }

    $server = $index->getServerInstanceIfAvailable();
    if ($server instanceof ServerInterface) {
      $backend = $server->getBackendConfig();
      $observed['metric'] = $backend['database_settings']['metric'] ?? NULL;
      $observed['dimensions'] = isset($backend['embeddings_engine_configuration']['dimensions'])
        ? (int) $backend['embeddings_engine_configuration']['dimensions']
        : NULL;
    }
    else {
      $drift_fields[] = 'server_instance';
    }

    $expected_metric = (string) ($index_policy['expected_metric'] ?? '');
    if ($expected_metric !== '' && $observed['metric'] !== $expected_metric) {
      $drift_fields[] = 'metric';
    }

    $expected_dimensions = isset($index_policy['expected_dimensions'])
      ? (int) $index_policy['expected_dimensions']
      : NULL;
    if ($expected_dimensions !== NULL && $observed['dimensions'] !== $expected_dimensions) {
      $drift_fields[] = 'dimensions';
    }

    if ($drift_fields) {
      return [
        'metadata_status' => 'drift',
        'drift_fields' => array_values(array_unique($drift_fields)),
        'observed' => $observed,
      ];
    }

    return [
      'metadata_status' => 'compliant',
      'drift_fields' => [],
      'observed' => $observed,
    ];
  }

  /**
   * Captures tracker queue/backlog counters.
   *
   * @param \Drupal\search_api\IndexInterface $index
   *   Search API index.
   * @param array $snapshot
   *   Index snapshot (by reference).
   */
  protected function captureTrackerMetrics(IndexInterface $index, array &$snapshot): void {
    $tracker = $index->getTrackerInstanceIfAvailable();
    if (!$tracker instanceof TrackerInterface) {
      $snapshot['tracker_available'] = FALSE;
      $snapshot['total_items'] = 0;
      $snapshot['indexed_items'] = 0;
      $snapshot['remaining_items'] = 0;
      return;
    }

    $snapshot['tracker_available'] = TRUE;
    $snapshot['total_items'] = (int) $tracker->getTotalItemsCount();
    $snapshot['indexed_items'] = (int) $tracker->getIndexedItemsCount();
    $snapshot['remaining_items'] = (int) $tracker->getRemainingItemsCount();
  }

  /**
   * Applies due/overdue schedule fields for an index snapshot.
   *
   * @param array $snapshot
   *   Index snapshot (by reference).
   * @param array $policy
   *   Global policy.
   * @param int $now
   *   Epoch seconds.
   */
  protected function applyRefreshScheduleFields(array &$snapshot, array $policy, int $now): void {
    $interval_seconds = max(1, (int) ($policy['refresh_interval_hours'] ?? 24)) * 3600;
    $grace_seconds = max(0, (int) ($policy['overdue_grace_minutes'] ?? 45)) * 60;
    $last_refresh_at = isset($snapshot['last_refresh_at']) ? (int) $snapshot['last_refresh_at'] : 0;

    $snapshot['refresh_interval_seconds'] = $interval_seconds;
    $snapshot['overdue_grace_seconds'] = $grace_seconds;
    $snapshot['next_refresh_due_at'] = $last_refresh_at > 0 ? $last_refresh_at + $interval_seconds : $now;
    $snapshot['due'] = $last_refresh_at <= 0 || $snapshot['next_refresh_due_at'] <= $now;
    $snapshot['overdue'] = $last_refresh_at > 0
      && ($last_refresh_at + $interval_seconds + $grace_seconds) <= $now;
    $snapshot['seconds_until_due'] = max(0, (int) $snapshot['next_refresh_due_at'] - $now);
  }

  /**
   * Returns default vector-index hygiene policy.
   */
  protected function defaultPolicy(): array {
    return [
      'enabled' => TRUE,
      'policy_version' => 'p2_del_03_v1',
      'refresh_mode' => 'incremental',
      'refresh_interval_hours' => 24,
      'overdue_grace_minutes' => 45,
      'max_items_per_run' => 60,
      'alert_cooldown_minutes' => 60,
      'managed_indexes' => [
        'faq_vector' => [
          'index_id' => 'faq_accordion_vector',
          'owner_role' => 'Content Operations Lead',
          'expected_server_id' => 'pinecone_vector',
          'expected_metric' => 'cosine_similarity',
          'expected_dimensions' => 3072,
        ],
        'resource_vector' => [
          'index_id' => 'assistant_resources_vector',
          'owner_role' => 'Content Operations Lead',
          'expected_server_id' => 'pinecone_vector',
          'expected_metric' => 'cosine_similarity',
          'expected_dimensions' => 3072,
        ],
      ],
    ];
  }

  /**
   * Returns merged policy values from config/defaults.
   */
  protected function getPolicy(): array {
    $policy = $this->defaultPolicy();
    $configured = $this->configFactory
      ->get('ilas_site_assistant.settings')
      ->get('vector_index_hygiene');

    if (is_array($configured)) {
      $policy = array_replace_recursive($policy, $configured);
    }

    return $policy;
  }

  /**
   * Builds a new default snapshot.
   *
   * @param array $policy
   *   Effective policy.
   * @param int $now
   *   Epoch seconds.
   *
   * @return array
   *   Snapshot payload.
   */
  protected function newSnapshot(array $policy, int $now): array {
    $indexes = [];
    foreach (($policy['managed_indexes'] ?? []) as $index_key => $index_policy) {
      if (is_string($index_key) && is_array($index_policy)) {
        $indexes[$index_key] = $this->newIndexSnapshot($index_key, $index_policy);
      }
    }

    return [
      'policy_version' => (string) ($policy['policy_version'] ?? 'p2_del_03_v1'),
      'recorded_at' => $now,
      'refresh_mode' => (string) ($policy['refresh_mode'] ?? 'incremental'),
      'status' => 'unknown',
      'indexes' => $indexes,
      'totals' => [
        'managed_indexes' => count($indexes),
        'healthy_indexes' => 0,
        'degraded_indexes' => 0,
        'compliant_indexes' => 0,
        'drift_indexes' => 0,
        'due_indexes' => 0,
        'overdue_indexes' => 0,
        'refreshed_indexes' => 0,
        'remaining_items' => 0,
      ],
      'thresholds' => [
        'refresh_interval_hours' => max(1, (int) ($policy['refresh_interval_hours'] ?? 24)),
        'overdue_grace_minutes' => max(0, (int) ($policy['overdue_grace_minutes'] ?? 45)),
        'max_items_per_run' => max(1, (int) ($policy['max_items_per_run'] ?? 60)),
        'alert_cooldown_minutes' => max(1, (int) ($policy['alert_cooldown_minutes'] ?? 60)),
      ],
      'last_alert_at' => NULL,
      'next_alert_eligible_at' => NULL,
      'cooldown_seconds_remaining' => 0,
    ];
  }

  /**
   * Builds a new default index snapshot.
   *
   * @param string $index_key
   *   Managed index key.
   * @param array $index_policy
   *   Managed index policy.
   *
   * @return array
   *   Index snapshot.
   */
  protected function newIndexSnapshot(string $index_key, array $index_policy): array {
    return [
      'index_key' => $index_key,
      'index_id' => (string) ($index_policy['index_id'] ?? ''),
      'owner_role' => (string) ($index_policy['owner_role'] ?? 'Content Operations Lead'),
      'status' => 'unknown',
      'exists' => FALSE,
      'enabled' => FALSE,
      'metadata_status' => 'unknown',
      'drift_fields' => [],
      'expected' => [
        'server_id' => (string) ($index_policy['expected_server_id'] ?? ''),
        'metric' => (string) ($index_policy['expected_metric'] ?? ''),
        'dimensions' => isset($index_policy['expected_dimensions']) ? (int) $index_policy['expected_dimensions'] : NULL,
      ],
      'observed' => [
        'server_id' => NULL,
        'metric' => NULL,
        'dimensions' => NULL,
      ],
      'tracker_available' => FALSE,
      'total_items' => 0,
      'indexed_items' => 0,
      'remaining_items' => 0,
      'due' => TRUE,
      'overdue' => FALSE,
      'seconds_until_due' => 0,
      'next_refresh_due_at' => NULL,
      'refresh_interval_seconds' => 0,
      'overdue_grace_seconds' => 0,
      'last_run_at' => NULL,
      'last_refresh_at' => NULL,
      'duration_ms' => 0.0,
      'items_processed_last_run' => 0,
      'last_error' => NULL,
    ];
  }

  /**
   * Applies derived aggregate, status, and cooldown fields.
   *
   * @param array $snapshot
   *   Snapshot payload.
   * @param array $policy
   *   Effective policy.
   * @param int $now
   *   Epoch seconds.
   *
   * @return array
   *   Snapshot payload with derived fields.
   */
  protected function applyDerivedSnapshotFields(array $snapshot, array $policy, int $now): array {
    $totals = [
      'managed_indexes' => 0,
      'healthy_indexes' => 0,
      'degraded_indexes' => 0,
      'compliant_indexes' => 0,
      'drift_indexes' => 0,
      'due_indexes' => 0,
      'overdue_indexes' => 0,
      'refreshed_indexes' => 0,
      'remaining_items' => 0,
    ];

    foreach (($snapshot['indexes'] ?? []) as $index_key => $index_snapshot) {
      if (!is_array($index_snapshot)) {
        continue;
      }
      $this->applyRefreshScheduleFields($index_snapshot, $policy, $now);
      $snapshot['indexes'][$index_key] = $index_snapshot;

      $totals['managed_indexes']++;
      if (($index_snapshot['status'] ?? 'unknown') === 'healthy') {
        $totals['healthy_indexes']++;
      }
      if (($index_snapshot['status'] ?? 'unknown') === 'degraded') {
        $totals['degraded_indexes']++;
      }
      if (($index_snapshot['metadata_status'] ?? 'unknown') === 'compliant') {
        $totals['compliant_indexes']++;
      }
      if (($index_snapshot['metadata_status'] ?? 'unknown') === 'drift') {
        $totals['drift_indexes']++;
      }
      if (!empty($index_snapshot['due'])) {
        $totals['due_indexes']++;
      }
      if (!empty($index_snapshot['overdue'])) {
        $totals['overdue_indexes']++;
      }
      if (($index_snapshot['items_processed_last_run'] ?? 0) > 0) {
        $totals['refreshed_indexes']++;
      }
      $totals['remaining_items'] += max(0, (int) ($index_snapshot['remaining_items'] ?? 0));
    }

    $snapshot['totals'] = $totals;
    if ($totals['managed_indexes'] <= 0) {
      $snapshot['status'] = 'unknown';
    }
    elseif ($totals['degraded_indexes'] > 0 || $totals['drift_indexes'] > 0 || $totals['overdue_indexes'] > 0) {
      $snapshot['status'] = 'degraded';
    }
    else {
      $snapshot['status'] = 'healthy';
    }

    $cooldown_seconds = max(1, (int) ($policy['alert_cooldown_minutes'] ?? 60)) * 60;
    $last_alert = (int) $this->state->get(self::ALERT_STATE_KEY, 0);
    $next_alert_eligible_at = $last_alert > 0 ? $last_alert + $cooldown_seconds : 0;

    $snapshot['thresholds'] = [
      'refresh_interval_hours' => max(1, (int) ($policy['refresh_interval_hours'] ?? 24)),
      'overdue_grace_minutes' => max(0, (int) ($policy['overdue_grace_minutes'] ?? 45)),
      'max_items_per_run' => max(1, (int) ($policy['max_items_per_run'] ?? 60)),
      'alert_cooldown_minutes' => max(1, (int) ($policy['alert_cooldown_minutes'] ?? 60)),
    ];
    $snapshot['last_alert_at'] = $last_alert > 0 ? $last_alert : NULL;
    $snapshot['next_alert_eligible_at'] = $next_alert_eligible_at > 0 ? $next_alert_eligible_at : NULL;
    $snapshot['cooldown_seconds_remaining'] = max(0, $next_alert_eligible_at - $now);

    return $snapshot;
  }

  /**
   * Emits degraded alerts with cooldown protection.
   *
   * @param array $snapshot
   *   Snapshot payload.
   * @param array $policy
   *   Effective policy.
   * @param int $now
   *   Epoch seconds.
   */
  protected function emitDegradedAlertIfNeeded(array $snapshot, array $policy, int $now): void {
    if (($snapshot['status'] ?? 'unknown') !== 'degraded') {
      return;
    }

    $cooldown_seconds = max(1, (int) ($policy['alert_cooldown_minutes'] ?? 60)) * 60;
    $last_alert = (int) $this->state->get(self::ALERT_STATE_KEY, 0);
    if ($last_alert > 0 && ($now - $last_alert) < $cooldown_seconds) {
      return;
    }

    $totals = is_array($snapshot['totals'] ?? NULL) ? $snapshot['totals'] : [];
    $this->logger->warning(
      'Vector index hygiene degraded: managed=@managed healthy=@healthy degraded=@degraded drift=@drift overdue=@overdue remaining=@remaining.',
      [
        '@managed' => (int) ($totals['managed_indexes'] ?? 0),
        '@healthy' => (int) ($totals['healthy_indexes'] ?? 0),
        '@degraded' => (int) ($totals['degraded_indexes'] ?? 0),
        '@drift' => (int) ($totals['drift_indexes'] ?? 0),
        '@overdue' => (int) ($totals['overdue_indexes'] ?? 0),
        '@remaining' => (int) ($totals['remaining_items'] ?? 0),
      ]
    );
    $this->state->set(self::ALERT_STATE_KEY, $now);
  }

}
