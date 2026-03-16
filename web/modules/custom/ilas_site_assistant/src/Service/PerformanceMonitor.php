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
   * Canonical monitored endpoint keys.
   */
  public const ENDPOINT_MESSAGE = 'message';
  public const ENDPOINT_TRACK = 'track';
  public const ENDPOINT_SUGGEST = 'suggest';
  public const ENDPOINT_FAQ = 'faq';

  /**
   * Request attribute keys shared with controller/subscriber instrumentation.
   */
  public const ATTRIBUTE_START_TIME = '_ilas_performance_start_time';
  public const ATTRIBUTE_ENDPOINT = '_ilas_performance_endpoint';
  public const ATTRIBUTE_OUTCOME = '_ilas_performance_outcome';
  public const ATTRIBUTE_SUCCESS = '_ilas_performance_success';
  public const ATTRIBUTE_STATUS_CODE = '_ilas_performance_status_code';
  public const ATTRIBUTE_DENIED = '_ilas_performance_denied';
  public const ATTRIBUTE_DEGRADED = '_ilas_performance_degraded';
  public const ATTRIBUTE_SCENARIO = '_ilas_performance_scenario';
  public const ATTRIBUTE_RECORDED = '_ilas_performance_recorded';

  /**
   * Monitored endpoint list.
   */
  public const ENDPOINTS = [
    self::ENDPOINT_MESSAGE,
    self::ENDPOINT_TRACK,
    self::ENDPOINT_SUGGEST,
    self::ENDPOINT_FAQ,
  ];

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
   * Optional SLO definitions for dynamic thresholds.
   *
   * @var \Drupal\ilas_site_assistant\Service\SloDefinitions|null
   */
  protected ?SloDefinitions $sloDefinitions;

  /**
   * Constructs a PerformanceMonitor.
   */
  public function __construct(
    StateInterface $state,
    LoggerChannelInterface $logger,
    ?SloDefinitions $slo_definitions = NULL
  ) {
    $this->state = $state;
    $this->logger = $logger;
    $this->sloDefinitions = $slo_definitions;
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
    $status_code = $success ? 200 : 500;
    $outcome = $success ? 'message.legacy_success' : 'message.legacy_error';
    $this->recordObservedRequest(
      $duration_ms,
      $success,
      self::ENDPOINT_MESSAGE,
      $outcome,
      $status_code,
      FALSE,
      FALSE,
      $scenario,
    );
  }

  /**
   * Records a classified endpoint outcome.
   *
   * @param float $duration_ms
   *   Request duration in milliseconds.
   * @param bool $success
   *   Whether the request succeeded.
   * @param string $endpoint
   *   Canonical endpoint key.
   * @param string $outcome
   *   Endpoint-specific outcome code.
   * @param int $status_code
   *   Final HTTP status code.
   * @param bool $denied
   *   TRUE when access/rate/validation denied the request.
   * @param bool $degraded
   *   TRUE when the endpoint degraded to a fallback path.
   * @param string $scenario
   *   Optional scenario classification.
   */
  public function recordObservedRequest(
    float $duration_ms,
    bool $success,
    string $endpoint,
    string $outcome,
    int $status_code,
    bool $denied = FALSE,
    bool $degraded = FALSE,
    string $scenario = 'unknown',
  ): void {
    $metrics = $this->getMetrics();
    $endpoint = $endpoint !== '' ? $endpoint : self::ENDPOINT_MESSAGE;

    $record = [
      'time' => time(),
      'duration' => $duration_ms,
      'success' => $success,
      'scenario' => $scenario,
      'endpoint' => $endpoint,
      'outcome' => $outcome,
      'status_code' => $status_code,
      'denied' => $denied,
      'degraded' => $degraded,
    ];

    $metrics['all_requests'][] = $record;
    $metrics['all_requests'] = $this->trimWindow($metrics['all_requests']);

    if (!isset($metrics['requests_by_endpoint'][$endpoint]) || !is_array($metrics['requests_by_endpoint'][$endpoint])) {
      $metrics['requests_by_endpoint'][$endpoint] = [];
    }
    $metrics['requests_by_endpoint'][$endpoint][] = $record;
    $metrics['requests_by_endpoint'][$endpoint] = $this->trimWindow($metrics['requests_by_endpoint'][$endpoint]);

    if (!isset($metrics['totals_by_endpoint'][$endpoint]) || !is_array($metrics['totals_by_endpoint'][$endpoint])) {
      $metrics['totals_by_endpoint'][$endpoint] = [
        'total_requests' => 0,
        'total_errors' => 0,
      ];
    }

    $metrics['total_requests_all']++;
    $metrics['totals_by_endpoint'][$endpoint]['total_requests']++;

    if (!$success) {
      $metrics['total_errors_all']++;
      $metrics['totals_by_endpoint'][$endpoint]['total_errors']++;
    }

    if ($endpoint === self::ENDPOINT_MESSAGE) {
      $metrics['requests'][] = $record;
      $metrics['requests'] = $this->trimWindow($metrics['requests']);
      $metrics['total_requests']++;
      if (!$success) {
        $metrics['total_errors']++;
      }
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
      'all_requests' => [],
      'requests_by_endpoint' => [],
      'total_requests' => 0,
      'total_errors' => 0,
      'total_requests_all' => 0,
      'total_errors_all' => 0,
      'totals_by_endpoint' => [],
      'last_alert' => 0,
    ];

    $stored = $this->state->get(self::STATE_KEY, $default);
    $metrics = is_array($stored) ? array_merge($default, $stored) : $default;

    $metrics['requests'] = $this->normalizeRequestList($metrics['requests'] ?? []);
    $metrics['all_requests'] = $this->normalizeRequestList($metrics['all_requests'] ?? []);
    $metrics['requests_by_endpoint'] = is_array($metrics['requests_by_endpoint']) ? $metrics['requests_by_endpoint'] : [];
    $metrics['totals_by_endpoint'] = is_array($metrics['totals_by_endpoint']) ? $metrics['totals_by_endpoint'] : [];

    if ($metrics['all_requests'] === [] && $metrics['requests'] !== []) {
      $metrics['all_requests'] = $metrics['requests'];
    }

    foreach (self::ENDPOINTS as $endpoint) {
      $endpoint_requests = $metrics['requests_by_endpoint'][$endpoint] ?? [];
      if (!is_array($endpoint_requests) || $endpoint_requests === []) {
        $endpoint_requests = ($endpoint === self::ENDPOINT_MESSAGE) ? $metrics['requests'] : [];
      }
      $metrics['requests_by_endpoint'][$endpoint] = $this->normalizeRequestList($endpoint_requests);

      $totals = $metrics['totals_by_endpoint'][$endpoint] ?? [];
      $metrics['totals_by_endpoint'][$endpoint] = [
        'total_requests' => (int) ($totals['total_requests'] ?? 0),
        'total_errors' => (int) ($totals['total_errors'] ?? 0),
      ];
    }

    if ($metrics['totals_by_endpoint'][self::ENDPOINT_MESSAGE]['total_requests'] === 0 && $metrics['total_requests'] > 0) {
      $metrics['totals_by_endpoint'][self::ENDPOINT_MESSAGE]['total_requests'] = (int) $metrics['total_requests'];
    }
    if ($metrics['totals_by_endpoint'][self::ENDPOINT_MESSAGE]['total_errors'] === 0 && $metrics['total_errors'] > 0) {
      $metrics['totals_by_endpoint'][self::ENDPOINT_MESSAGE]['total_errors'] = (int) $metrics['total_errors'];
    }
    if ($metrics['total_requests_all'] === 0) {
      $metrics['total_requests_all'] = array_sum(array_map(
        static fn(array $totals): int => (int) ($totals['total_requests'] ?? 0),
        $metrics['totals_by_endpoint']
      ));
    }
    if ($metrics['total_errors_all'] === 0) {
      $metrics['total_errors_all'] = array_sum(array_map(
        static fn(array $totals): int => (int) ($totals['total_errors'] ?? 0),
        $metrics['totals_by_endpoint']
      ));
    }

    return $metrics;
  }

  /**
   * Calculates summary statistics.
   *
   * @return array
   *   Summary with p50, p95, p99, error_rate, availability, throughput.
   */
  public function getSummary(): array {
    $metrics = $this->getMetrics();
    $summary = $this->buildSummary($metrics['requests']);
    $summary['all_endpoints'] = $this->buildSummary($metrics['all_requests']);
    $summary['by_endpoint'] = [];
    foreach (self::ENDPOINTS as $endpoint) {
      $summary['by_endpoint'][$endpoint] = $this->buildSummary($metrics['requests_by_endpoint'][$endpoint] ?? []);
    }
    $summary['by_outcome'] = $this->buildOutcomeSummary($metrics['all_requests']);

    return $summary;
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

    $summary = $this->buildSummary($metrics['requests']);
    if (($summary['sample_size'] ?? 0) === 0) {
      return;
    }

    $alerted = FALSE;

    if (($summary['p95'] ?? 0) > $this->getLatencyThresholdMs()) {
      $this->logger->warning('Chatbot API latency degraded: P95 = @p95ms (threshold: @threshold ms)', [
        '@p95' => $summary['p95'],
        '@threshold' => $this->getLatencyThresholdMs(),
      ]);
      $alerted = TRUE;
    }

    if ((($summary['error_rate'] ?? 0) / 100) > $this->getErrorRateThresholdRatio()) {
      $this->logger->warning('Chatbot API error rate elevated: @rate% (threshold: @threshold%)', [
        '@rate' => $summary['error_rate'],
        '@threshold' => $this->getErrorRateThresholdRatio() * 100,
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

  /**
   * Returns the active p95 latency threshold in milliseconds.
   */
  private function getLatencyThresholdMs(): int {
    return $this->sloDefinitions
      ? $this->sloDefinitions->getLatencyP95TargetMs()
      : self::THRESHOLD_P95_MS;
  }

  /**
   * Normalizes a request list loaded from state.
   *
   * @param mixed $requests
   *   Request list candidate.
   *
   * @return array
   *   Normalized request records.
   */
  private function normalizeRequestList(mixed $requests): array {
    if (!is_array($requests)) {
      return [];
    }

    return array_values(array_filter($requests, 'is_array'));
  }

  /**
   * Trims a rolling request window.
   *
   * @param array $requests
   *   Request records.
   *
   * @return array
   *   Trimmed request records.
   */
  private function trimWindow(array $requests): array {
    if (count($requests) <= self::WINDOW_SIZE) {
      return $requests;
    }

    return array_slice($requests, -self::WINDOW_SIZE);
  }

  /**
   * Builds a summary for the provided request window.
   *
   * @param array $requests
   *   Request records.
   *
   * @return array
   *   Summary metrics.
   */
  private function buildSummary(array $requests): array {
    $latencyThreshold = $this->getLatencyThresholdMs();
    $errorRateThreshold = $this->getErrorRateThresholdRatio();

    if ($requests === []) {
      return [
        'p50' => 0,
        'p95' => 0,
        'p99' => 0,
        'avg' => 0,
        'error_rate' => 0,
        'availability_pct' => 0,
        'throughput_per_min' => 0,
        'sample_size' => 0,
        'success_count' => 0,
        'error_count' => 0,
        'denied_count' => 0,
        'degraded_count' => 0,
        'status' => 'no_data',
        'status_code_counts' => [],
        'thresholds' => [
          'p95_threshold_ms' => $latencyThreshold,
          'error_rate_threshold' => $errorRateThreshold * 100,
        ],
      ];
    }

    $durations = array_column($requests, 'duration');
    sort($durations);

    $count = count($durations);
    $errors = count(array_filter($requests, static fn(array $request): bool => !($request['success'] ?? FALSE)));
    $denied = count(array_filter($requests, static fn(array $request): bool => (bool) ($request['denied'] ?? FALSE)));
    $degraded = count(array_filter($requests, static fn(array $request): bool => (bool) ($request['degraded'] ?? FALSE)));

    $status_code_counts = [];
    foreach ($requests as $request) {
      $status_code = (string) ($request['status_code'] ?? 0);
      $status_code_counts[$status_code] = (int) ($status_code_counts[$status_code] ?? 0) + 1;
    }
    ksort($status_code_counts);

    $p50 = $durations[(int) floor($count * 0.50)] ?? 0;
    $p95 = $durations[(int) floor($count * 0.95)] ?? 0;
    $p99 = $durations[(int) floor($count * 0.99)] ?? 0;
    $avg = array_sum($durations) / $count;

    $one_minute_ago = time() - 60;
    $recent = array_filter($requests, static fn(array $request): bool => (int) ($request['time'] ?? 0) >= $one_minute_ago);
    $throughput = count($recent);

    $error_rate = $count > 0 ? $errors / $count : 0.0;
    $availability = (1 - $error_rate) * 100;
    $status = 'healthy';
    if ($p95 > $latencyThreshold) {
      $status = 'degraded_latency';
    }
    if ($error_rate > $errorRateThreshold) {
      $status = 'degraded_errors';
    }

    return [
      'p50' => round($p50, 1),
      'p95' => round($p95, 1),
      'p99' => round($p99, 1),
      'avg' => round($avg, 1),
      'error_rate' => round($error_rate * 100, 2),
      'availability_pct' => round($availability, 2),
      'throughput_per_min' => $throughput,
      'sample_size' => $count,
      'success_count' => $count - $errors,
      'error_count' => $errors,
      'denied_count' => $denied,
      'degraded_count' => $degraded,
      'status' => $status,
      'status_code_counts' => $status_code_counts,
      'thresholds' => [
        'p95_threshold_ms' => $latencyThreshold,
        'error_rate_threshold' => $errorRateThreshold * 100,
      ],
    ];
  }

  /**
   * Builds an outcome-count breakdown from the aggregate window.
   *
   * @param array $requests
   *   Aggregate request window.
   *
   * @return array
   *   Outcome keyed counters.
   */
  private function buildOutcomeSummary(array $requests): array {
    $summary = [];

    foreach ($requests as $request) {
      $outcome = (string) ($request['outcome'] ?? 'unknown');
      if (!isset($summary[$outcome])) {
        $summary[$outcome] = [
          'endpoint' => (string) ($request['endpoint'] ?? ''),
          'sample_size' => 0,
          'error_count' => 0,
          'denied_count' => 0,
          'degraded_count' => 0,
          'status_code_counts' => [],
        ];
      }

      $summary[$outcome]['sample_size']++;
      if (!($request['success'] ?? FALSE)) {
        $summary[$outcome]['error_count']++;
      }
      if ($request['denied'] ?? FALSE) {
        $summary[$outcome]['denied_count']++;
      }
      if ($request['degraded'] ?? FALSE) {
        $summary[$outcome]['degraded_count']++;
      }

      $status_code = (string) ($request['status_code'] ?? 0);
      $summary[$outcome]['status_code_counts'][$status_code] = (int) (($summary[$outcome]['status_code_counts'][$status_code] ?? 0) + 1);
      ksort($summary[$outcome]['status_code_counts']);
    }

    ksort($summary);
    return $summary;
  }

  /**
   * Returns the active error rate threshold as a ratio (0-1).
   */
  private function getErrorRateThresholdRatio(): float {
    return $this->sloDefinitions
      ? $this->sloDefinitions->getErrorRateTargetPct() / 100
      : self::THRESHOLD_ERROR_RATE;
  }

}
