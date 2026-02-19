<?php

namespace Drupal\ilas_site_assistant\Service;

use Drupal\Core\State\StateInterface;

/**
 * Ring-buffer timestamp tracker for sub-day safety violation counting.
 *
 * Stores violation timestamps in Drupal state to enable accurate
 * sub-day windowed counting (e.g. "violations in the last hour").
 * The date-bucketed stats table only supports day-level granularity;
 * this tracker fills the gap for real-time alerting.
 */
class SafetyViolationTracker {

  /**
   * State key for the violation timestamp ring buffer.
   */
  const STATE_KEY = 'ilas_site_assistant.safety_violation_timestamps';

  /**
   * Maximum number of timestamps to retain in the ring buffer.
   */
  const MAX_ENTRIES = 500;

  /**
   * The state service.
   *
   * @var \Drupal\Core\State\StateInterface
   */
  protected $state;

  /**
   * Constructs a SafetyViolationTracker.
   *
   * @param \Drupal\Core\State\StateInterface $state
   *   The state service.
   */
  public function __construct(StateInterface $state) {
    $this->state = $state;
  }

  /**
   * Records a safety violation at the given timestamp.
   *
   * @param int $timestamp
   *   Unix timestamp of the violation.
   */
  public function record(int $timestamp): void {
    $timestamps = $this->getTimestamps();
    $timestamps[] = $timestamp;

    // Trim to max entries, keeping the most recent.
    if (count($timestamps) > self::MAX_ENTRIES) {
      $timestamps = array_slice($timestamps, -self::MAX_ENTRIES);
    }

    $this->state->set(self::STATE_KEY, $timestamps);
  }

  /**
   * Counts violations since the given cutoff timestamp.
   *
   * @param int $cutoff_timestamp
   *   Unix timestamp; only violations at or after this time are counted.
   *
   * @return int
   *   Number of violations since the cutoff.
   */
  public function countSince(int $cutoff_timestamp): int {
    $timestamps = $this->getTimestamps();
    $count = 0;
    foreach ($timestamps as $ts) {
      if ($ts >= $cutoff_timestamp) {
        $count++;
      }
    }
    return $count;
  }

  /**
   * Removes timestamps older than the cutoff.
   *
   * Intended to be called from cron to prevent unbounded growth.
   *
   * @param int $cutoff_timestamp
   *   Unix timestamp; entries before this are removed.
   */
  public function prune(int $cutoff_timestamp): void {
    $timestamps = $this->getTimestamps();
    $timestamps = array_values(array_filter($timestamps, fn($ts) => $ts >= $cutoff_timestamp));
    $this->state->set(self::STATE_KEY, $timestamps);
  }

  /**
   * Resets the tracker (for testing).
   */
  public function reset(): void {
    $this->state->delete(self::STATE_KEY);
  }

  /**
   * Gets the current timestamp ring buffer.
   *
   * @return int[]
   *   Array of Unix timestamps.
   */
  protected function getTimestamps(): array {
    return $this->state->get(self::STATE_KEY, []);
  }

}
