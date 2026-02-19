<?php

namespace Drupal\ilas_site_assistant\Service;

use Drupal\Core\State\StateInterface;
use Drupal\Core\Logger\LoggerChannelInterface;

/**
 * Tracks chatbot API performance metrics for monitoring.
 *
 * Stores rolling metrics in Drupal state for lightweight monitoring.
 * Use with an external alerting system or expose via /admin endpoint.
 */
class PerformanceMonitor {

  /**
   * State key for metrics storage.
   */
  const STATE_KEY = 'ilas_site_assistant.performance_metrics';

  /**
   * Rolling window size (number of requests to track).
   */
  const WINDOW_SIZE = 1000;

  /**
   * Alert thresholds.
   */
  const THRESHOLD_P95_MS = 2000;
  const THRESHOLD_ERROR_RATE = 0.05;

  /**
   * The state service.
   *
   * @var \Drupal\Core\State\StateInterface
   */
  protected $state;

  /**
   * The logger.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected $logger;

  /**
   * Constructs a PerformanceMonitor.
   */
  public function __construct(StateInterface $state, LoggerChannelInterface $logger) {
    $this->state = $state;
    $this->logger = $logger;
  }

  /**
   * Records a request's performance.
   *
   * @param float $duration_ms
   *   Request duration in milliseconds.
   * @param bool $success
   *   Whether the request succeeded.
   * @param string $scenario
   *   The query type (short, navigation, retrieval).
   * @param string $request_id
   *   Optional per-request correlation ID for log tracing.
   */
  public function recordRequest(float $duration_ms, bool $success, string $scenario = 'unknown', string $request_id = ''): void {
    $metrics = $this->getMetrics();

    // Add to rolling window.
    $metrics['requests'][] = [
      'time' => time(),
      'duration' => $duration_ms,
      'success' => $success,
      'scenario' => $scenario,
    ];

    // Trim to window size.
    if (count($metrics['requests']) > self::WINDOW_SIZE) {
      $metrics['requests'] = array_slice($metrics['requests'], -self::WINDOW_SIZE);
    }

    // Update counters.
    $metrics['total_requests']++;
    if (!$success) {
      $metrics['total_errors']++;
    }

    // Check thresholds and log warnings.
    $this->checkThresholds($metrics);

    $this->state->set(self::STATE_KEY, $metrics);
  }

  /**
   * Gets current metrics.
   *
   * @return array
   *   The metrics array.
   */
  public function getMetrics(): array {
    $default = [
      'requests' => [],
      'total_requests' => 0,
      'total_errors' => 0,
      'last_alert' => 0,
    ];

    return $this->state->get(self::STATE_KEY, $default);
  }

  /**
   * Calculates summary statistics.
   *
   * @return array
   *   Summary with p50, p95, p99, error_rate, throughput.
   */
  public function getSummary(): array {
    $metrics = $this->getMetrics();
    $requests = $metrics['requests'];

    if (empty($requests)) {
      return [
        'p50' => 0,
        'p95' => 0,
        'p99' => 0,
        'avg' => 0,
        'error_rate' => 0,
        'throughput_per_min' => 0,
        'sample_size' => 0,
        'status' => 'no_data',
      ];
    }

    // Extract durations and sort.
    $durations = array_column($requests, 'duration');
    sort($durations);

    $count = count($durations);
    $errors = count(array_filter($requests, fn($r) => !$r['success']));

    // Calculate percentiles.
    $p50 = $durations[(int) floor($count * 0.50)] ?? 0;
    $p95 = $durations[(int) floor($count * 0.95)] ?? 0;
    $p99 = $durations[(int) floor($count * 0.99)] ?? 0;
    $avg = array_sum($durations) / $count;

    // Calculate throughput (requests in last minute).
    $one_minute_ago = time() - 60;
    $recent = array_filter($requests, fn($r) => $r['time'] >= $one_minute_ago);
    $throughput = count($recent);

    // Determine status.
    $error_rate = $count > 0 ? $errors / $count : 0;
    $status = 'healthy';
    if ($p95 > self::THRESHOLD_P95_MS) {
      $status = 'degraded_latency';
    }
    if ($error_rate > self::THRESHOLD_ERROR_RATE) {
      $status = 'degraded_errors';
    }

    return [
      'p50' => round($p50, 1),
      'p95' => round($p95, 1),
      'p99' => round($p99, 1),
      'avg' => round($avg, 1),
      'error_rate' => round($error_rate * 100, 2),
      'throughput_per_min' => $throughput,
      'sample_size' => $count,
      'status' => $status,
      'thresholds' => [
        'p95_threshold_ms' => self::THRESHOLD_P95_MS,
        'error_rate_threshold' => self::THRESHOLD_ERROR_RATE * 100,
      ],
    ];
  }

  /**
   * Checks thresholds and logs warnings.
   *
   * Uses the in-memory $metrics array (which includes the current request)
   * instead of re-reading state. Mutates $metrics['last_alert'] by reference
   * so the single state->set() in recordRequest() persists the cooldown.
   */
  protected function checkThresholds(array &$metrics): void {
    // Only alert once per 5 minutes to avoid log spam.
    if (time() - $metrics['last_alert'] < 300) {
      return;
    }

    $requests = $metrics['requests'];
    if (empty($requests)) {
      return;
    }

    // In-memory summary calculation (avoids stale state re-read).
    $durations = array_column($requests, 'duration');
    sort($durations);
    $count = count($durations);
    $errors = count(array_filter($requests, fn($r) => !$r['success']));
    $p95 = $durations[(int) floor($count * 0.95)] ?? 0;
    $error_rate = $count > 0 ? $errors / $count : 0;

    $alerted = FALSE;

    if ($p95 > self::THRESHOLD_P95_MS) {
      $this->logger->warning('Chatbot API latency degraded: P95 = @p95ms (threshold: @threshold ms)', [
        '@p95' => round($p95, 1),
        '@threshold' => self::THRESHOLD_P95_MS,
      ]);
      $alerted = TRUE;
    }

    if ($error_rate > self::THRESHOLD_ERROR_RATE) {
      $this->logger->warning('Chatbot API error rate elevated: @rate% (threshold: @threshold%)', [
        '@rate' => round($error_rate * 100, 2),
        '@threshold' => self::THRESHOLD_ERROR_RATE * 100,
      ]);
      $alerted = TRUE;
    }

    if ($alerted) {
      $metrics['last_alert'] = time();
    }
  }

  /**
   * Resets metrics (for testing or maintenance).
   */
  public function reset(): void {
    $this->state->delete(self::STATE_KEY);
  }

}
