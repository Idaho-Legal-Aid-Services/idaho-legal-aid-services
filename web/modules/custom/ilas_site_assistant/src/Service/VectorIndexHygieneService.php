<?php

declare(strict_types=1);

namespace Drupal\ilas_site_assistant\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\State\StateInterface;
use Drupal\search_api\IndexInterface;
use Drupal\search_api\Item\ItemInterface;
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
   * Retrieval configuration resolver.
   *
   * @var \Drupal\ilas_site_assistant\Service\RetrievalConfigurationService
   */
  protected RetrievalConfigurationService $retrievalConfiguration;

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
    RetrievalConfigurationService $retrieval_configuration,
    LoggerInterface $logger,
  ) {
    $this->configFactory = $config_factory;
    $this->state = $state;
    $this->entityTypeManager = $entity_type_manager;
    $this->retrievalConfiguration = $retrieval_configuration;
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

    $vector_search_enabled = $this->retrievalConfiguration->isVectorSearchEnabled();

    $now = time();
    $snapshot = $this->state->get(self::SNAPSHOT_STATE_KEY);
    if (!is_array($snapshot)) {
      $snapshot = $this->newSnapshot($policy, $now);
    }

    $snapshot['recorded_at'] = $now;
    $snapshot['policy_version'] = (string) ($policy['policy_version'] ?? 'p2_del_03_v1');
    $snapshot['refresh_mode'] = (string) ($policy['refresh_mode'] ?? 'incremental');
    $snapshot['vector_search_enabled'] = $vector_search_enabled;

    foreach (($policy['managed_indexes'] ?? []) as $index_key => $index_policy) {
      if (!is_string($index_key) || !is_array($index_policy)) {
        continue;
      }
      $snapshot['indexes'][$index_key] = $this->refreshIndexSnapshot(
        $index_key,
        $index_policy,
        is_array($snapshot['indexes'][$index_key] ?? NULL) ? $snapshot['indexes'][$index_key] : [],
        $policy,
        $now,
        $vector_search_enabled,
        FALSE,
        $vector_search_enabled,
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

    $snapshot['vector_search_enabled'] = $this->retrievalConfiguration->isVectorSearchEnabled();
    $snapshot = $this->applyDerivedSnapshotFields($snapshot, $policy, $now);
    return $snapshot;
  }

  /**
   * Refreshes live snapshot data without running incremental indexing.
   *
   * @param bool $force_probe
   *   TRUE to force queryability probes during this refresh.
   * @param string|null $only_index_key
   *   Optional managed index key to refresh.
   *
   * @return array
   *   Refreshed snapshot payload.
   */
  public function refreshSnapshot(bool $force_probe = FALSE, ?string $only_index_key = NULL): array {
    $policy = $this->getPolicy();
    $snapshot = $this->state->get(self::SNAPSHOT_STATE_KEY);
    $now = time();
    $vector_search_enabled = $this->retrievalConfiguration->isVectorSearchEnabled();

    if (!is_array($snapshot)) {
      $snapshot = $this->newSnapshot($policy, $now);
    }

    $snapshot['recorded_at'] = $now;
    $snapshot['policy_version'] = (string) ($policy['policy_version'] ?? 'p2_del_03_v1');
    $snapshot['refresh_mode'] = (string) ($policy['refresh_mode'] ?? 'incremental');
    $snapshot['vector_search_enabled'] = $vector_search_enabled;

    foreach (($policy['managed_indexes'] ?? []) as $index_key => $index_policy) {
      if (!is_string($index_key) || !is_array($index_policy)) {
        continue;
      }
      if ($only_index_key !== NULL && $only_index_key !== $index_key) {
        continue;
      }

      $snapshot['indexes'][$index_key] = $this->refreshIndexSnapshot(
        $index_key,
        $index_policy,
        is_array($snapshot['indexes'][$index_key] ?? NULL) ? $snapshot['indexes'][$index_key] : [],
        $policy,
        $now,
        FALSE,
        $force_probe,
        $vector_search_enabled,
      );
    }

    $snapshot = $this->applyDerivedSnapshotFields($snapshot, $policy, $now);
    $this->state->set(self::SNAPSHOT_STATE_KEY, $snapshot);
    return $snapshot;
  }

  /**
   * Returns the configured Search API index ID for a managed vector key.
   *
   * @param string $index_key
   *   Managed index key.
   *
   * @return string|null
   *   Search API index machine name when configured.
   */
  public function getManagedIndexId(string $index_key): ?string {
    $policy = $this->getPolicy();
    if (!isset($policy['managed_indexes'][$index_key]) || !is_array($policy['managed_indexes'][$index_key])) {
      return NULL;
    }

    return $this->resolveManagedIndexId($index_key);
  }

  /**
   * Runs a paced resume or full rebuild backfill for one managed index.
   *
   * @param string $index_key
   *   Managed index key.
   * @param int $batch_size
   *   Maximum Search API items per indexing call.
   * @param int $max_batches
   *   Maximum indexing calls in this run.
   * @param int $sleep_seconds
   *   Pause between batches when another batch will run.
   * @param bool $until_complete
   *   TRUE to continue until complete or another stop reason is hit.
   * @param bool $clear_first
   *   TRUE to explicitly clear the index before indexing.
   *
   * @return array
   *   Backfill status report.
   */
  public function backfillIndex(
    string $index_key,
    int $batch_size = 5,
    int $max_batches = 1,
    int $sleep_seconds = 0,
    bool $until_complete = FALSE,
    bool $clear_first = FALSE,
  ): array {
    $policy = $this->getPolicy();
    $managed_policy = $policy['managed_indexes'][$index_key] ?? NULL;
    if (!is_array($managed_policy)) {
      throw new \InvalidArgumentException(sprintf('Unknown managed vector index key: %s', $index_key));
    }

    $index_id = $this->resolveManagedIndexId($index_key);
    if (!is_string($index_id) || $index_id === '') {
      throw new \RuntimeException(sprintf('Missing Search API index ID for managed vector index %s.', $index_key));
    }

    $index = $this->loadIndex($index_id);
    if (!$index instanceof IndexInterface) {
      throw new \RuntimeException(sprintf('Search API index %s could not be loaded.', $index_id));
    }

    $batch_size = max(1, $batch_size);
    $max_batches = max(1, $max_batches);
    $sleep_seconds = max(0, $sleep_seconds);
    $mode = $clear_first ? 'rebuild' : 'resume';
    $processed_this_run = 0;
    $last_error = NULL;
    $stop_reason = 'not_started';
    $now = time();

    $progress = $this->captureIndexProgress($index);
    if (!$index->status()) {
      $stop_reason = 'index_disabled';
    }
    elseif (($progress['tracker_available'] ?? FALSE) !== TRUE) {
      $stop_reason = 'tracker_unavailable';
    }
    else {
      try {
        if ($clear_first) {
          $index->clear();
          $progress = $this->captureIndexProgress($index);
        }

        if (($progress['remaining_items'] ?? 0) <= 0) {
          $stop_reason = 'already_complete';
        }
        else {
          $batch_cap = $until_complete && $max_batches === 1 ? PHP_INT_MAX : $max_batches;
          $batch_counter = 0;

          while ($batch_counter < $batch_cap) {
            $batch_counter++;
            $processed = (int) $index->indexItems($batch_size);
            $processed_this_run += max(0, $processed);
            $progress = $this->captureIndexProgress($index);

            if (($progress['remaining_items'] ?? 0) <= 0) {
              $stop_reason = 'complete';
              break;
            }
            if ($processed <= 0) {
              $stop_reason = 'no_items_processed';
              break;
            }

            if (!$until_complete && $batch_counter >= $max_batches) {
              $stop_reason = 'batch_limit_reached';
              break;
            }
            if ($until_complete && $batch_counter >= $batch_cap) {
              $stop_reason = 'batch_cap_reached';
              break;
            }

            if ($sleep_seconds > 0) {
              sleep($sleep_seconds);
            }
          }
        }
      }
      catch (\Throwable $e) {
        $last_error = get_class($e) . ': ' . $e->getMessage();
        $stop_reason = $clear_first ? 'rebuild_failed' : 'backfill_failed';
      }
    }

    $this->persistIndexOperationState($index_key, $managed_policy, [
      'last_backfill_at' => $now,
      'last_stop_reason' => $stop_reason,
      'last_error' => $last_error,
    ]);

    $snapshot = $this->refreshSnapshot(FALSE, $index_key);
    $index_snapshot = is_array($snapshot['indexes'][$index_key] ?? NULL)
      ? $snapshot['indexes'][$index_key]
      : $this->newIndexSnapshot($index_key, $managed_policy);

    return [
      'index_key' => $index_key,
      'index_id' => $index_id,
      'mode' => $mode,
      'batch_size' => $batch_size,
      'max_batches' => $max_batches,
      'sleep_seconds' => $sleep_seconds,
      'until_complete' => $until_complete,
      'clear_first' => $clear_first,
      'processed_this_run' => $processed_this_run,
      'stop_reason' => $stop_reason,
      'last_error' => $last_error,
      'total_items' => (int) ($index_snapshot['total_items'] ?? 0),
      'indexed_items' => (int) ($index_snapshot['indexed_items'] ?? 0),
      'remaining_items' => (int) ($index_snapshot['remaining_items'] ?? 0),
      'percent_complete' => $this->calculatePercentComplete(
        (int) ($index_snapshot['indexed_items'] ?? 0),
        (int) ($index_snapshot['total_items'] ?? 0),
      ),
      'indexing_status' => (string) ($index_snapshot['indexing_status'] ?? 'unknown'),
      'hygiene_status' => (string) ($index_snapshot['status'] ?? 'unknown'),
      'metadata_status' => (string) ($index_snapshot['metadata_status'] ?? 'unknown'),
      'probe_status' => (string) ($index_snapshot['probe_status'] ?? 'unknown'),
      'last_probe_at' => $index_snapshot['last_probe_at'] ?? NULL,
      'probe_error' => $index_snapshot['probe_error'] ?? NULL,
      'last_stop_reason' => $index_snapshot['last_stop_reason'] ?? $stop_reason,
    ];
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
    bool $allow_indexing = TRUE,
    bool $force_probe = FALSE,
    bool $vector_search_enabled = TRUE,
  ): array {
    $index_id = $this->resolveManagedIndexId($index_key);
    $index_snapshot = array_replace(
      $this->newIndexSnapshot($index_key, $index_policy),
      $existing
    );
    $index_snapshot['feature_active'] = $vector_search_enabled;
    $index_snapshot['inactive_reason'] = $vector_search_enabled ? NULL : 'vector_search_disabled';
    $index_snapshot['last_run_at'] = $now;
    $index_snapshot['last_error'] = NULL;
    $index_snapshot['duration_ms'] = 0.0;
    $index_snapshot['items_processed_last_run'] = 0;

    if ($index_id === '') {
      if (!$vector_search_enabled) {
        $this->applyVectorSearchInactiveState($index_snapshot, $now);
        return $index_snapshot;
      }
      $index_snapshot['status'] = 'unknown';
      $index_snapshot['metadata_status'] = 'unknown';
      $index_snapshot['indexing_status'] = 'unknown';
      $index_snapshot['last_error'] = 'missing_index_id';
      return $index_snapshot;
    }

    $start = microtime(TRUE);
    $index = $this->loadIndex($index_id);
    if (!$index) {
      if (!$vector_search_enabled) {
        $index_snapshot['exists'] = FALSE;
        $index_snapshot['enabled'] = FALSE;
        $this->applyVectorSearchInactiveState($index_snapshot, $now);
        $index_snapshot['duration_ms'] = round((microtime(TRUE) - $start) * 1000, 2);
        return $index_snapshot;
      }
      $index_snapshot['status'] = 'degraded';
      $index_snapshot['metadata_status'] = 'unknown';
      $index_snapshot['indexing_status'] = 'unknown';
      $index_snapshot['exists'] = FALSE;
      $index_snapshot['enabled'] = FALSE;
      $index_snapshot['last_error'] = 'index_not_found';
      $index_snapshot['probe_error'] = 'index_not_found';
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
    $index_snapshot['indexing_status'] = $this->deriveIndexingStatus($index_snapshot);
    $this->applyRefreshScheduleFields($index_snapshot, $policy, $now);

    if (!$vector_search_enabled) {
      $this->applyVectorSearchInactiveState($index_snapshot, $now);
      $index_snapshot['duration_ms'] = round((microtime(TRUE) - $start) * 1000, 2);
      return $index_snapshot;
    }

    if ($allow_indexing && $index_snapshot['due'] && $index_snapshot['enabled'] && ($policy['refresh_mode'] ?? 'incremental') === 'incremental') {
      try {
        $batch = max(1, (int) ($policy['max_items_per_run'] ?? 5));
        $processed = (int) $index->indexItems($batch);
        $index_snapshot['items_processed_last_run'] = $processed;
        $index_snapshot['last_refresh_at'] = $now;

        // Refresh tracker counters after indexing.
        $this->captureTrackerMetrics($index, $index_snapshot);
        $index_snapshot['indexing_status'] = $this->deriveIndexingStatus($index_snapshot);
      }
      catch (\Throwable $e) {
        $index_snapshot['last_error'] = get_class($e) . ': ' . $e->getMessage();
      }
    }

    $probe_due = $this->shouldRunQueryabilityProbe($index_snapshot, $policy, $force_probe);
    if ($probe_due) {
      if ($index_snapshot['enabled']) {
        $this->executeQueryabilityProbes($index, $index_key, $index_policy, $index_snapshot, $now);
      }
      else {
        $this->applySkippedProbeState($index_snapshot, $now, 'index_disabled');
      }
    }

    $index_snapshot['duration_ms'] = round((microtime(TRUE) - $start) * 1000, 2);

    $has_error = is_string($index_snapshot['last_error']) && $index_snapshot['last_error'] !== '';
    $metadata_degraded = $index_snapshot['metadata_status'] === 'drift';
    $probe_degraded = in_array((string) ($index_snapshot['probe_status'] ?? 'unknown'), ['failed', 'mixed'], TRUE);
    $enabled = (bool) ($index_snapshot['enabled'] ?? FALSE);
    if (!$enabled || $has_error || $metadata_degraded || !empty($index_snapshot['overdue']) || $probe_degraded) {
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

    if (($snapshot['feature_active'] ?? TRUE) === FALSE) {
      $snapshot['refresh_interval_seconds'] = $interval_seconds;
      $snapshot['overdue_grace_seconds'] = $grace_seconds;
      $snapshot['next_refresh_due_at'] = NULL;
      $snapshot['due'] = FALSE;
      $snapshot['overdue'] = FALSE;
      $snapshot['seconds_until_due'] = 0;
      return;
    }

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
      'probe_interval_hours' => 24,
      'overdue_grace_minutes' => 45,
      'max_items_per_run' => 5,
      'alert_cooldown_minutes' => 60,
      'managed_indexes' => [
        'faq_vector' => [
          'owner_role' => 'Content Operations Lead',
          'expected_server_id' => 'pinecone_vector_faq',
          'expected_metric' => 'cosine_similarity',
          'expected_dimensions' => 3072,
          'queryability_probes' => [
            [
              'label' => 'faq_custody_canary',
              'query' => 'custody',
              'langcode' => 'en',
              'top_k' => 1,
              'min_results' => 1,
            ],
          ],
        ],
        'resource_vector' => [
          'owner_role' => 'Content Operations Lead',
          'expected_server_id' => 'pinecone_vector_resources',
          'expected_metric' => 'cosine_similarity',
          'expected_dimensions' => 3072,
          'queryability_probes' => [
            [
              'label' => 'resource_eviction_canary',
              'query' => 'eviction',
              'langcode' => 'en',
              'top_k' => 1,
              'min_results' => 1,
            ],
          ],
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
      'vector_search_enabled' => $this->retrievalConfiguration->isVectorSearchEnabled(),
      'status' => 'unknown',
      'indexes' => $indexes,
      'totals' => [
        'managed_indexes' => count($indexes),
        'healthy_indexes' => 0,
        'degraded_indexes' => 0,
        'compliant_indexes' => 0,
        'drift_indexes' => 0,
        'queryable_indexes' => 0,
        'probe_failed_indexes' => 0,
        'probe_passed_count' => 0,
        'probe_failed_count' => 0,
        'due_indexes' => 0,
        'overdue_indexes' => 0,
        'refreshed_indexes' => 0,
        'remaining_items' => 0,
      ],
      'thresholds' => [
        'refresh_interval_hours' => max(1, (int) ($policy['refresh_interval_hours'] ?? 24)),
        'probe_interval_hours' => max(1, (int) ($policy['probe_interval_hours'] ?? 24)),
        'overdue_grace_minutes' => max(0, (int) ($policy['overdue_grace_minutes'] ?? 45)),
        'max_items_per_run' => max(1, (int) ($policy['max_items_per_run'] ?? 5)),
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
      'index_id' => $this->resolveManagedIndexId($index_key) ?? '',
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
      'indexing_status' => 'unknown',
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
      'last_probe_at' => NULL,
      'probe_status' => 'unknown',
      'probe_error' => NULL,
      'probe_passed_count' => 0,
      'probe_failed_count' => 0,
      'probe_evidence' => [],
      'feature_active' => TRUE,
      'inactive_reason' => NULL,
      'last_backfill_at' => NULL,
      'last_stop_reason' => NULL,
      'duration_ms' => 0.0,
      'items_processed_last_run' => 0,
      'last_error' => NULL,
    ];
  }

  /**
   * Resolves the configured Search API index ID for a managed vector key.
   */
  protected function resolveManagedIndexId(string $index_key): ?string {
    return match ($index_key) {
      'faq_vector' => $this->retrievalConfiguration->getFaqVectorIndexId(),
      'resource_vector' => $this->retrievalConfiguration->getResourceVectorIndexId(),
      default => NULL,
    };
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
    $vector_search_enabled = $this->retrievalConfiguration->isVectorSearchEnabled();
    $snapshot['vector_search_enabled'] = $vector_search_enabled;
    $totals = [
      'managed_indexes' => 0,
      'healthy_indexes' => 0,
      'degraded_indexes' => 0,
      'compliant_indexes' => 0,
      'drift_indexes' => 0,
      'queryable_indexes' => 0,
      'probe_failed_indexes' => 0,
      'probe_passed_count' => 0,
      'probe_failed_count' => 0,
      'due_indexes' => 0,
      'overdue_indexes' => 0,
      'refreshed_indexes' => 0,
      'remaining_items' => 0,
    ];

    foreach (($snapshot['indexes'] ?? []) as $index_key => $index_snapshot) {
      if (!is_array($index_snapshot)) {
        continue;
      }
      if (!$vector_search_enabled) {
        $this->applyVectorSearchInactiveState($index_snapshot);
      }
      $this->applyRefreshScheduleFields($index_snapshot, $policy, $now);
      $index_snapshot['indexing_status'] = $this->deriveIndexingStatus($index_snapshot);
      $snapshot['indexes'][$index_key] = $index_snapshot;

      $totals['managed_indexes']++;
      $totals['remaining_items'] += max(0, (int) ($index_snapshot['remaining_items'] ?? 0));
      if (($index_snapshot['status'] ?? 'unknown') === 'skipped') {
        continue;
      }
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
      if (($index_snapshot['probe_status'] ?? 'unknown') === 'healthy') {
        $totals['queryable_indexes']++;
      }
      if (in_array((string) ($index_snapshot['probe_status'] ?? 'unknown'), ['failed', 'mixed'], TRUE)) {
        $totals['probe_failed_indexes']++;
      }
      $totals['probe_passed_count'] += max(0, (int) ($index_snapshot['probe_passed_count'] ?? 0));
      $totals['probe_failed_count'] += max(0, (int) ($index_snapshot['probe_failed_count'] ?? 0));
      if (!empty($index_snapshot['due'])) {
        $totals['due_indexes']++;
      }
      if (!empty($index_snapshot['overdue'])) {
        $totals['overdue_indexes']++;
      }
      if (($index_snapshot['items_processed_last_run'] ?? 0) > 0) {
        $totals['refreshed_indexes']++;
      }
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
      'probe_interval_hours' => max(1, (int) ($policy['probe_interval_hours'] ?? 24)),
      'overdue_grace_minutes' => max(0, (int) ($policy['overdue_grace_minutes'] ?? 45)),
      'max_items_per_run' => max(1, (int) ($policy['max_items_per_run'] ?? 5)),
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
    $this->logger->notice(
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

  /**
   * Derives a simple indexing progress state from tracker counters.
   */
  protected function deriveIndexingStatus(array $snapshot): string {
    if (($snapshot['feature_active'] ?? TRUE) === FALSE) {
      return 'skipped';
    }

    if (empty($snapshot['tracker_available'])) {
      return 'unknown';
    }

    $total = max(0, (int) ($snapshot['total_items'] ?? 0));
    $indexed = max(0, (int) ($snapshot['indexed_items'] ?? 0));
    $remaining = max(0, (int) ($snapshot['remaining_items'] ?? 0));

    if ($total <= 0) {
      return 'empty';
    }
    if ($remaining <= 0) {
      return 'complete';
    }
    if ($indexed <= 0) {
      return 'pending';
    }

    return 'partial';
  }

  /**
   * Determines whether the semantic queryability probe should run now.
   */
  protected function shouldRunQueryabilityProbe(array $snapshot, array $policy, bool $force_probe): bool {
    if ($force_probe) {
      return TRUE;
    }

    $last_probe_at = isset($snapshot['last_probe_at']) ? (int) $snapshot['last_probe_at'] : 0;
    if ($last_probe_at <= 0) {
      return TRUE;
    }

    $interval_seconds = max(1, (int) ($policy['probe_interval_hours'] ?? 24)) * 3600;
    return ($last_probe_at + $interval_seconds) <= time();
  }

  /**
   * Executes configured semantic queryability probes for one index.
   */
  protected function executeQueryabilityProbes(
    IndexInterface $index,
    string $index_key,
    array $index_policy,
    array &$snapshot,
    int $now,
  ): void {
    $probes = $index_policy['queryability_probes'] ?? [];
    if (!is_array($probes) || $probes === []) {
      $snapshot['last_probe_at'] = $now;
      $snapshot['probe_status'] = 'unknown';
      $snapshot['probe_error'] = 'probe_unconfigured';
      $snapshot['probe_passed_count'] = 0;
      $snapshot['probe_failed_count'] = 0;
      $snapshot['probe_evidence'] = [];
      return;
    }

    $passed = 0;
    $failed = 0;
    $errors = [];
    $evidence = [];

    foreach ($probes as $delta => $probe) {
      if (!is_array($probe)) {
        continue;
      }

      $label = trim((string) ($probe['label'] ?? ('probe_' . ($delta + 1))));
      $query = trim((string) ($probe['query'] ?? ''));
      $langcode = trim((string) ($probe['langcode'] ?? ''));
      $top_k = max(1, min(5, (int) ($probe['top_k'] ?? 1)));
      $min_results = max(1, (int) ($probe['min_results'] ?? 1));

      if ($query === '') {
        $failed++;
        $errors[] = $label . ': missing_query';
        $evidence[] = [
          'label' => $label,
          'passed' => FALSE,
          'result_count' => 0,
          'score_present' => FALSE,
          'duration_ms' => 0.0,
          'error' => 'missing_query',
        ];
        continue;
      }

      $probe_start = microtime(TRUE);
      try {
        $search_query = $index->query();
        $search_query->keys($query);
        $search_query->range(0, $top_k);
        if ($langcode !== '') {
          $search_query->addCondition('search_api_language', $langcode);
        }

        $results = $search_query->execute();
        $result_items = $results->getResultItems();
        $result_count = is_array($result_items) ? count($result_items) : 0;
        $top_hit = $result_items !== [] ? reset($result_items) : NULL;
        $score_present = $top_hit instanceof ItemInterface
          ? $top_hit->getScore() !== NULL
          : FALSE;
        $duration_ms = round((microtime(TRUE) - $probe_start) * 1000, 2);
        $probe_passed = $result_count >= $min_results;

        if ($probe_passed) {
          $passed++;
        }
        else {
          $failed++;
          $errors[] = $label . ': insufficient_results';
        }

        $evidence[] = [
          'label' => $label,
          'passed' => $probe_passed,
          'result_count' => $result_count,
          'score_present' => $score_present,
          'duration_ms' => $duration_ms,
        ];
      }
      catch (\Throwable $e) {
        $failed++;
        $duration_ms = round((microtime(TRUE) - $probe_start) * 1000, 2);
        $error = get_class($e) . ': ' . $e->getMessage();
        $errors[] = $label . ': ' . $error;
        $evidence[] = [
          'label' => $label,
          'passed' => FALSE,
          'result_count' => 0,
          'score_present' => FALSE,
          'duration_ms' => $duration_ms,
          'error' => $error,
        ];
        $this->logger->warning(
          'Vector queryability probe failed for {index_key}/{label}: {error}',
          [
            'index_key' => $index_key,
            'label' => $label,
            'error' => $error,
          ]
        );
      }
    }

    $snapshot['last_probe_at'] = $now;
    $snapshot['probe_passed_count'] = $passed;
    $snapshot['probe_failed_count'] = $failed;
    $snapshot['probe_evidence'] = $evidence;
    $snapshot['probe_error'] = $errors !== [] ? implode('; ', array_slice($errors, 0, 3)) : NULL;
    $snapshot['probe_status'] = match (TRUE) {
      $failed === 0 && $passed > 0 => 'healthy',
      $passed > 0 && $failed > 0 => 'mixed',
      $failed > 0 => 'failed',
      default => 'unknown',
    };
  }

  /**
   * Applies a skipped probe state when the index cannot be queried safely.
   */
  protected function applySkippedProbeState(array &$snapshot, int $now, string $reason): void {
    $snapshot['last_probe_at'] = $now;
    $snapshot['probe_status'] = 'skipped';
    $snapshot['probe_error'] = $reason;
    $snapshot['probe_passed_count'] = 0;
    $snapshot['probe_failed_count'] = 0;
    $snapshot['probe_evidence'] = [];
  }

  /**
   * Marks a managed vector index as intentionally inactive while vector search is off.
   */
  protected function applyVectorSearchInactiveState(array &$snapshot, ?int $now = NULL): void {
    $snapshot['feature_active'] = FALSE;
    $snapshot['inactive_reason'] = 'vector_search_disabled';
    $snapshot['status'] = 'skipped';
    $snapshot['indexing_status'] = 'skipped';
    $snapshot['items_processed_last_run'] = 0;
    $snapshot['last_error'] = NULL;

    if ($now !== NULL) {
      $this->applySkippedProbeState($snapshot, $now, 'vector_search_disabled');
    }
    else {
      $snapshot['probe_status'] = 'skipped';
      $snapshot['probe_error'] = 'vector_search_disabled';
      $snapshot['probe_passed_count'] = 0;
      $snapshot['probe_failed_count'] = 0;
      $snapshot['probe_evidence'] = [];
    }

    if (empty($snapshot['last_stop_reason'])) {
      $snapshot['last_stop_reason'] = 'vector_search_disabled';
    }
  }

  /**
   * Captures current tracker progress in an isolated array.
   */
  protected function captureIndexProgress(IndexInterface $index): array {
    $snapshot = [
      'tracker_available' => FALSE,
      'total_items' => 0,
      'indexed_items' => 0,
      'remaining_items' => 0,
      'indexing_status' => 'unknown',
    ];
    $this->captureTrackerMetrics($index, $snapshot);
    $snapshot['indexing_status'] = $this->deriveIndexingStatus($snapshot);
    return $snapshot;
  }

  /**
   * Persists operator-visible state for a managed index.
   */
  protected function persistIndexOperationState(string $index_key, array $index_policy, array $values): void {
    $policy = $this->getPolicy();
    $now = time();
    $snapshot = $this->state->get(self::SNAPSHOT_STATE_KEY);
    if (!is_array($snapshot)) {
      $snapshot = $this->newSnapshot($policy, $now);
    }

    $existing = is_array($snapshot['indexes'][$index_key] ?? NULL) ? $snapshot['indexes'][$index_key] : [];
    $snapshot['indexes'][$index_key] = array_replace(
      $this->newIndexSnapshot($index_key, $index_policy),
      $existing,
      $values,
    );
    $snapshot = $this->applyDerivedSnapshotFields($snapshot, $policy, $now);
    $this->state->set(self::SNAPSHOT_STATE_KEY, $snapshot);
  }

  /**
   * Calculates a rounded percent complete from indexed and total counts.
   */
  protected function calculatePercentComplete(int $indexed_items, int $total_items): float {
    if ($total_items <= 0) {
      return 0.0;
    }

    return round(min(100, max(0, ($indexed_items / $total_items) * 100)), 2);
  }

}
