<?php

declare(strict_types=1);

namespace Drupal\ilas_site_assistant\Service;

use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\State\StateInterface;

/**
 * Monitors Langfuse export queue depth and health.
 *
 * Tracks queue depth relative to SLO-defined thresholds and records
 * drain counts for throughput monitoring.
 */
class QueueHealthMonitor {

  /**
   * Supported queue/export outcomes tracked in state.
   */
  const OUTCOME_KEYS = [
    'drop_max_depth',
    'drop_enqueue_failure',
    'discard_invalid_shape',
    'discard_missing_enqueued_at',
    'discard_stale',
    'discard_disabled',
    'discard_missing_credentials',
    'discard_non_retryable_http',
    'send_success',
    'send_partial_207',
    'retryable_suspend',
  ];

  /**
   * Outcome policy metadata exposed to operator surfaces.
   */
  const OUTCOME_POLICIES = [
    'drop_max_depth' => [
      'classification' => 'alertable_loss',
      'severity' => 'warning',
      'requires_error_count' => FALSE,
    ],
    'drop_enqueue_failure' => [
      'classification' => 'alertable_loss',
      'severity' => 'warning',
      'requires_error_count' => FALSE,
    ],
    'discard_invalid_shape' => [
      'classification' => 'alertable_loss',
      'severity' => 'warning',
      'requires_error_count' => FALSE,
    ],
    'discard_missing_enqueued_at' => [
      'classification' => 'alertable_loss',
      'severity' => 'warning',
      'requires_error_count' => FALSE,
    ],
    'discard_stale' => [
      'classification' => 'alertable_loss',
      'severity' => 'warning',
      'requires_error_count' => FALSE,
    ],
    'discard_disabled' => [
      'classification' => 'informational_loss',
      'severity' => 'info',
      'requires_error_count' => FALSE,
    ],
    'discard_missing_credentials' => [
      'classification' => 'alertable_loss',
      'severity' => 'warning',
      'requires_error_count' => FALSE,
    ],
    'discard_non_retryable_http' => [
      'classification' => 'alertable_loss',
      'severity' => 'warning',
      'requires_error_count' => FALSE,
    ],
    'send_success' => [
      'classification' => 'success',
      'severity' => 'info',
      'requires_error_count' => FALSE,
    ],
    'send_partial_207' => [
      'classification' => 'alertable_loss',
      'severity' => 'warning',
      'requires_error_count' => TRUE,
    ],
    'retryable_suspend' => [
      'classification' => 'retry_only',
      'severity' => 'info',
      'requires_error_count' => FALSE,
    ],
  ];

  /**
   * State key for total items drained.
   */
  const STATE_TOTAL_DRAINED = 'ilas_site_assistant.queue_total_drained';

  /**
   * State key for oldest enqueue timestamp in the queue.
   */
  const STATE_OLDEST_ENQUEUED_AT = 'ilas_site_assistant.queue_oldest_enqueued_at';

  /**
   * State key prefix for export outcome counters.
   */
  const STATE_OUTCOME_COUNTER_PREFIX = 'ilas_site_assistant.queue_export_outcome.';

  /**
   * State key for last export outcome metadata.
   */
  const STATE_LAST_OUTCOME = 'ilas_site_assistant.queue_export_last_outcome';

  /**
   * State key prefix for export outcome aggregate totals.
   */
  const STATE_OUTCOME_TOTAL_PREFIX = 'ilas_site_assistant.queue_export_total.';

  /**
   * The Drupal queue name for Langfuse export.
   */
  const QUEUE_NAME = 'ilas_langfuse_export';

  /**
   * Backlog warning threshold as a fraction of max depth.
   */
  const BACKLOG_THRESHOLD_RATIO = 0.8;

  /**
   * The queue factory.
   *
   * @var \Drupal\Core\Queue\QueueFactory
   */
  protected QueueFactory $queueFactory;

  /**
   * The state service.
   *
   * @var \Drupal\Core\State\StateInterface
   */
  protected StateInterface $state;

  /**
   * Constructs a QueueHealthMonitor.
   */
  public function __construct(QueueFactory $queue_factory, StateInterface $state) {
    $this->queueFactory = $queue_factory;
    $this->state = $state;
  }

  /**
   * Returns the current queue health status.
   *
   * @param \Drupal\ilas_site_assistant\Service\SloDefinitions $slo
   *   SLO definitions for threshold values.
   *
   * @return array
   *   Associative array with keys:
   *   - status: 'healthy', 'backlogged', 'stale', or 'backlogged_stale'
   *   - depth: current queue item count
   *   - max_depth: SLO max depth threshold
   *   - utilization_pct: depth as percentage of max_depth
   *   - oldest_enqueued_at: oldest known enqueue unix timestamp (or NULL)
   *   - oldest_item_age_seconds: computed age of oldest item (or NULL)
   *   - max_age_seconds: SLO queue-age threshold
   */
  public function getQueueHealthStatus(SloDefinitions $slo): array {
    $queue = $this->queueFactory->get(self::QUEUE_NAME);
    $depth = $queue->numberOfItems();
    $maxDepth = $slo->getQueueMaxDepth();
    $maxAgeSeconds = $slo->getQueueMaxAgeSeconds();

    $utilizationPct = $maxDepth > 0 ? round(($depth / $maxDepth) * 100, 2) : 0;
    $threshold = $maxDepth * self::BACKLOG_THRESHOLD_RATIO;
    $oldestEnqueuedAt = $this->getOldestEnqueuedAt();

    // Self-heal: if the queue is empty, any residual oldest_enqueued_at
    // is stale state from a prior drain cycle — clear it.
    if ($depth <= 0 && $oldestEnqueuedAt !== NULL) {
      $this->state->delete(self::STATE_OLDEST_ENQUEUED_AT);
      $oldestEnqueuedAt = NULL;
    }

    $oldestItemAge = $oldestEnqueuedAt !== NULL ? max(0, time() - $oldestEnqueuedAt) : NULL;

    $isBacklogged = $depth > $threshold;
    $isStale = $oldestItemAge !== NULL && $oldestItemAge > $maxAgeSeconds;

    $status = 'healthy';
    if ($isBacklogged && $isStale) {
      $status = 'backlogged_stale';
    }
    elseif ($isBacklogged) {
      $status = 'backlogged';
    }
    elseif ($isStale) {
      $status = 'stale';
    }

    return [
      'status' => $status,
      'depth' => $depth,
      'max_depth' => $maxDepth,
      'utilization_pct' => $utilizationPct,
      'oldest_enqueued_at' => $oldestEnqueuedAt,
      'oldest_item_age_seconds' => $oldestItemAge,
      'max_age_seconds' => $maxAgeSeconds,
    ];
  }

  /**
   * Records enqueue metadata for queue age SLO monitoring.
   *
   * @param int $enqueuedAt
   *   Item enqueue unix timestamp.
   * @param int $depthBeforeEnqueue
   *   Queue depth before the item was enqueued.
   */
  public function recordEnqueue(int $enqueuedAt, int $depthBeforeEnqueue = 0): void {
    $currentOldest = $this->getOldestEnqueuedAt();

    // Initialize oldest timestamp when queue was empty or state was missing.
    if ($depthBeforeEnqueue <= 0 || $currentOldest === NULL) {
      $this->state->set(self::STATE_OLDEST_ENQUEUED_AT, $enqueuedAt);
    }
  }

  /**
   * Records that items have been drained from the queue.
   *
   * @param int $count
   *   Number of items drained.
   */
  public function recordDrain(int $count): void {
    $current = (int) $this->state->get(self::STATE_TOTAL_DRAINED, 0);
    $this->state->set(self::STATE_TOTAL_DRAINED, $current + $count);

    if ($count <= 0) {
      return;
    }

    // Reset oldest timestamp when the queue is fully drained.
    // Use <= 1 because recordDrain() is called from processItem() while the
    // current item is still claimed (counted by numberOfItems()). If only 1
    // item remains, it is the one being processed and will be deleted next.
    $queue = $this->queueFactory->get(self::QUEUE_NAME);
    if ($queue->numberOfItems() <= 1) {
      $this->state->delete(self::STATE_OLDEST_ENQUEUED_AT);
    }
  }

  /**
   * Returns the total items drained since last reset.
   */
  public function getTotalDrained(): int {
    return (int) $this->state->get(self::STATE_TOTAL_DRAINED, 0);
  }

  /**
   * Records a queue/export outcome and updates the last-outcome snapshot.
   *
   * @param string $outcome
   *   Outcome key from self::OUTCOME_KEYS.
   * @param array<string, mixed> $metadata
   *   Scalar-safe metadata for the last outcome snapshot.
   */
  public function recordOutcome(string $outcome, array $metadata = []): void {
    if (!in_array($outcome, self::OUTCOME_KEYS, TRUE)) {
      return;
    }

    $stateKey = self::STATE_OUTCOME_COUNTER_PREFIX . $outcome;
    $current = (int) $this->state->get($stateKey, 0);
    $this->state->set($stateKey, $current + 1);

    $totals = $this->getStoredOutcomeTotals($outcome);
    $normalized = $this->normalizeOutcomeMetadata($outcome, $metadata);

    foreach ($totals as $key => $value) {
      $totals[$key] = $value + $normalized[$key];
    }

    $this->state->set(self::STATE_OUTCOME_TOTAL_PREFIX . $outcome, $totals);

    $policy = self::OUTCOME_POLICIES[$outcome];
    $currentCounter = $current + 1;

    $lastOutcome = [
      'outcome' => $outcome,
      'recorded_at' => time(),
      'classification' => $policy['classification'],
      'severity' => $policy['severity'],
      'actionable' => $this->isOutcomeActionable($outcome, $totals, $currentCounter),
    ];
    foreach ($metadata as $key => $value) {
      if (!is_string($key) || $key === '') {
        continue;
      }
      if (is_scalar($value) || $value === NULL) {
        $lastOutcome[$key] = $value;
      }
    }

    $this->state->set(self::STATE_LAST_OUTCOME, $lastOutcome);
  }

  /**
   * Returns tracked export outcome counters.
   *
   * @return array<string, int>
   *   Counts keyed by outcome name.
   */
  public function getOutcomeCounters(): array {
    $counters = [];
    foreach (self::OUTCOME_KEYS as $outcome) {
      $counters[$outcome] = (int) $this->state->get(self::STATE_OUTCOME_COUNTER_PREFIX . $outcome, 0);
    }

    return $counters;
  }

  /**
   * Returns tracked export outcome aggregate totals.
   *
   * @return array<string, array<string, int>>
   *   Totals keyed by outcome name.
   */
  public function getOutcomeTotals(): array {
    $totals = [];
    foreach (self::OUTCOME_KEYS as $outcome) {
      $totals[$outcome] = $this->getStoredOutcomeTotals($outcome);
    }

    return $totals;
  }

  /**
   * Returns outcome policies with operator-facing metadata.
   *
   * @return array<string, array<string, mixed>>
   *   Policy metadata keyed by outcome.
   */
  public function getOutcomePolicies(): array {
    $policies = [];
    foreach (self::OUTCOME_KEYS as $outcome) {
      $policy = self::OUTCOME_POLICIES[$outcome] ?? [];
      $policies[$outcome] = [
        'classification' => $policy['classification'] ?? 'informational_loss',
        'severity' => $policy['severity'] ?? 'info',
        'requires_error_count' => (bool) ($policy['requires_error_count'] ?? FALSE),
      ];
    }

    return $policies;
  }

  /**
   * Returns the last recorded export outcome snapshot.
   *
   * @return array<string, mixed>|null
   *   Last outcome metadata, or NULL if no outcome has been recorded.
   */
  public function getLastOutcome(): ?array {
    $value = $this->state->get(self::STATE_LAST_OUTCOME);
    return is_array($value) ? $value : NULL;
  }

  /**
   * Returns the export outcome summary exposed to status commands.
   *
   * @return array<string, mixed>
   *   Export outcome summary.
   */
  public function getExportOutcomeSummary(): array {
    $counters = $this->getOutcomeCounters();
    $totals = $this->getOutcomeTotals();
    $policies = $this->getOutcomePolicies();

    $alertableLossTotals = $this->defaultSummaryTotals();
    $informationalLossTotals = $this->defaultSummaryTotals();

    foreach (self::OUTCOME_KEYS as $outcome) {
      $policy = $policies[$outcome];
      $totals[$outcome]['actionable'] = $this->isOutcomeActionable($outcome, $totals[$outcome], $counters[$outcome]);
      $policies[$outcome]['actionable'] = $totals[$outcome]['actionable'];

      if ($policy['classification'] === 'alertable_loss' && $totals[$outcome]['actionable']) {
        $alertableLossTotals['occurrences'] += $counters[$outcome];
        $alertableLossTotals['queue_items'] += $totals[$outcome]['lost_queue_items'];
        $alertableLossTotals['event_count'] += $totals[$outcome]['lost_event_count'];
      }
      elseif ($policy['classification'] === 'informational_loss') {
        $informationalLossTotals['occurrences'] += $counters[$outcome];
        $informationalLossTotals['queue_items'] += $totals[$outcome]['lost_queue_items'];
        $informationalLossTotals['event_count'] += $totals[$outcome]['lost_event_count'];
      }
    }

    return [
      'counters' => $counters,
      'totals' => $totals,
      'last_outcome' => $this->getLastOutcome(),
      'action_required' => $alertableLossTotals['occurrences'] > 0,
      'policies' => $policies,
      'alertable_loss_totals' => $alertableLossTotals,
      'informational_loss_totals' => $informationalLossTotals,
    ];
  }

  /**
   * Returns only alertable queue-loss outcomes for SLO alerting.
   *
   * @return array<string, array<string, int|string>>
   *   Alertable outcomes keyed by outcome.
   */
  public function getActionableLossOutcomes(): array {
    $counters = $this->getOutcomeCounters();
    $totals = $this->getOutcomeTotals();
    $policies = $this->getOutcomePolicies();
    $actionable = [];

    foreach (self::OUTCOME_KEYS as $outcome) {
      if (($policies[$outcome]['classification'] ?? NULL) !== 'alertable_loss') {
        continue;
      }
      if (!$this->isOutcomeActionable($outcome, $totals[$outcome], $counters[$outcome])) {
        continue;
      }

      $actionable[$outcome] = [
        'occurrences' => $counters[$outcome],
        'queue_items' => $totals[$outcome]['lost_queue_items'],
        'event_count' => $totals[$outcome]['lost_event_count'],
        'severity' => (string) ($policies[$outcome]['severity'] ?? 'warning'),
      ];
    }

    return $actionable;
  }

  /**
   * Returns oldest known enqueue timestamp or NULL if unknown.
   */
  public function getOldestEnqueuedAt(): ?int {
    $value = $this->state->get(self::STATE_OLDEST_ENQUEUED_AT);
    if ($value === NULL || (int) $value <= 0) {
      return NULL;
    }
    return (int) $value;
  }

  /**
   * Returns stored totals for one outcome with defaults applied.
   *
   * @param string $outcome
   *   Outcome key.
   *
   * @return array<string, int>
   *   Aggregate totals.
   */
  private function getStoredOutcomeTotals(string $outcome): array {
    $stored = $this->state->get(self::STATE_OUTCOME_TOTAL_PREFIX . $outcome, []);
    $stored = is_array($stored) ? $stored : [];

    return array_merge($this->defaultOutcomeTotals(), array_intersect_key($stored, $this->defaultOutcomeTotals()));
  }

  /**
   * Normalizes one outcome record into aggregate counters.
   *
   * @param string $outcome
   *   Outcome key.
   * @param array<string, mixed> $metadata
   *   Outcome metadata.
   *
   * @return array<string, int>
   *   Aggregate totals to add.
   */
  private function normalizeOutcomeMetadata(string $outcome, array $metadata): array {
    $queueItems = max(0, (int) ($metadata['queue_items'] ?? 1));
    $eventCount = max(0, (int) ($metadata['event_count'] ?? 0));
    $successCount = array_key_exists('success_count', $metadata)
      ? max(0, (int) $metadata['success_count'])
      : ($outcome === 'send_success' ? $eventCount : 0);
    $errorCount = array_key_exists('error_count', $metadata)
      ? max(0, (int) $metadata['error_count'])
      : ($outcome === 'send_partial_207' ? max(0, $eventCount - $successCount) : 0);

    $classification = self::OUTCOME_POLICIES[$outcome]['classification'] ?? 'informational_loss';
    $lostQueueItems = 0;
    $lostEventCount = 0;

    if ($outcome === 'send_partial_207') {
      $lostQueueItems = $errorCount > 0 ? $queueItems : 0;
      $lostEventCount = $errorCount;
    }
    elseif ($classification === 'alertable_loss' || $classification === 'informational_loss') {
      $lostQueueItems = $queueItems;
      $lostEventCount = $eventCount;
    }

    return [
      'queue_items' => $queueItems,
      'event_count' => $eventCount,
      'success_count' => $successCount,
      'error_count' => $errorCount,
      'lost_queue_items' => $lostQueueItems,
      'lost_event_count' => $lostEventCount,
    ];
  }

  /**
   * Returns the default aggregate total structure.
   *
   * @return array<string, int>
   *   Default totals.
   */
  private function defaultOutcomeTotals(): array {
    return [
      'queue_items' => 0,
      'event_count' => 0,
      'success_count' => 0,
      'error_count' => 0,
      'lost_queue_items' => 0,
      'lost_event_count' => 0,
    ];
  }

  /**
   * Returns the default summary total structure.
   *
   * @return array<string, int>
   *   Default summary totals.
   */
  private function defaultSummaryTotals(): array {
    return [
      'occurrences' => 0,
      'queue_items' => 0,
      'event_count' => 0,
    ];
  }

  /**
   * Determines whether an outcome currently requires operator action.
   *
   * @param string $outcome
   *   Outcome key.
   * @param array<string, int> $totals
   *   Aggregate totals for the outcome.
   *
   * @return bool
   *   TRUE when the outcome is alertable.
   */
  private function isOutcomeActionable(string $outcome, array $totals, int $counter = 0): bool {
    $policy = self::OUTCOME_POLICIES[$outcome] ?? [];
    if (($policy['classification'] ?? NULL) !== 'alertable_loss') {
      return FALSE;
    }
    if (!empty($policy['requires_error_count'])) {
      return ($totals['error_count'] ?? 0) > 0
        || ($counter > 0 && $this->isLegacyCounterOnlyTotals($totals));
    }

    return ($totals['lost_queue_items'] ?? 0) > 0
      || ($totals['queue_items'] ?? 0) > 0
      || ($counter > 0 && $this->isLegacyCounterOnlyTotals($totals));
  }

  /**
   * Determines whether totals are empty because they predate AFRP-17 storage.
   *
   * @param array<string, int> $totals
   *   Aggregate totals for the outcome.
   *
   * @return bool
   *   TRUE when every aggregate total is zero.
   */
  private function isLegacyCounterOnlyTotals(array $totals): bool {
    foreach ($this->defaultOutcomeTotals() as $key => $_value) {
      if (($totals[$key] ?? 0) > 0) {
        return FALSE;
      }
    }

    return TRUE;
  }

}
