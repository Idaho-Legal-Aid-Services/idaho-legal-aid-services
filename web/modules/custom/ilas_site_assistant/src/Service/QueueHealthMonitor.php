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
   * State key for total items drained.
   */
  const STATE_TOTAL_DRAINED = 'ilas_site_assistant.queue_total_drained';

  /**
   * State key for oldest enqueue timestamp in the queue.
   */
  const STATE_OLDEST_ENQUEUED_AT = 'ilas_site_assistant.queue_oldest_enqueued_at';

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
   * Returns oldest known enqueue timestamp or NULL if unknown.
   */
  public function getOldestEnqueuedAt(): ?int {
    $value = $this->state->get(self::STATE_OLDEST_ENQUEUED_AT);
    if ($value === NULL || (int) $value <= 0) {
      return NULL;
    }
    return (int) $value;
  }

}
