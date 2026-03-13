<?php

namespace Drupal\ilas_site_assistant\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\State\StateInterface;
use Psr\Log\LoggerInterface;

/**
 * Coordinates atomic LLM admission and state transitions.
 */
class LlmAdmissionCoordinator {

  /**
   * Shared lock for LLM guard state mutations.
   */
  const CONTROL_LOCK = 'ilas_site_assistant.llm_control_state';

  /**
   * Dedicated in-flight probe lock for half-open breaker requests.
   */
  const PROBE_LOCK = 'ilas_site_assistant.llm_circuit_probe';

  /**
   * Lock lifetime in seconds.
   */
  const LOCK_TTL = 5.0;

  /**
   * Maximum blocking wait for non-admission mutations.
   */
  const BLOCKING_WAIT_SECONDS = 1.0;

  protected StateInterface $state;
  protected ConfigFactoryInterface $configFactory;
  protected LoggerInterface $logger;
  protected LockBackendInterface $lock;

  /**
   * Constructs an LLM admission coordinator.
   */
  public function __construct(
    StateInterface $state,
    ConfigFactoryInterface $config_factory,
    LoggerInterface $logger,
    LockBackendInterface $lock,
  ) {
    $this->state = $state;
    $this->configFactory = $config_factory;
    $this->logger = $logger;
    $this->lock = $lock;
  }

  /**
   * Atomically evaluates and reserves request admission state.
   *
   * @param string|null $budgetIdentity
   *   The trusted identity string used for per-IP budgeting.
   *
   * @return array
   *   Array with 'allowed' (bool) and 'reason' (string).
   */
  public function beginRequest(?string $budgetIdentity = NULL): array {
    return $this->evaluateRequest($budgetIdentity, TRUE);
  }

  /**
   * Evaluates request admission without reserving capacity.
   *
   * @param string|null $budgetIdentity
   *   The trusted identity string used for per-IP budgeting.
   *
   * @return array
   *   Array with 'allowed' (bool) and 'reason' (string).
   */
  public function previewRequest(?string $budgetIdentity = NULL): array {
    return $this->evaluateRequest($budgetIdentity, FALSE);
  }

  /**
   * Evaluates admission rules and optionally reserves capacity.
   *
   * @param string|null $budgetIdentity
   *   The trusted identity string used for per-IP budgeting.
   * @param bool $reserve
   *   TRUE to reserve capacity, FALSE for a read-only preview.
   *
   * @return array
   *   Array with 'allowed' (bool) and 'reason' (string).
   */
  protected function evaluateRequest(?string $budgetIdentity, bool $reserve): array {
    if (!$this->lock->acquire(self::CONTROL_LOCK, self::LOCK_TTL)) {
      return ['allowed' => FALSE, 'reason' => 'concurrency_lock_timeout'];
    }

    $probeAcquired = FALSE;
    try {
      if ($this->state->get(CostControlPolicy::STATE_KEY_KILL_SWITCH, FALSE)) {
        return ['allowed' => FALSE, 'reason' => 'manual_kill_switch'];
      }

      $now = time();
      $breaker = $this->getCircuitBreakerData();
      $limiter = $this->prepareRateLimiterWindow($this->getRateLimiterData(), $now);
      $daily = $this->getDailyBudgetData($now);
      $monthly = $this->getMonthlyBudgetData($now);
      $normalizedIdentity = CostControlPolicy::normalizeBudgetIdentity($budgetIdentity);
      $identityHash = $normalizedIdentity !== NULL
        ? CostControlPolicy::hashBudgetIdentity($normalizedIdentity)
        : NULL;
      $perIpBudgets = $this->getPerIpBudgetState($now);
      $perIpBudget = $identityHash !== NULL
        ? ($perIpBudgets[$identityHash] ?? ['count' => 0, 'window_start' => $now])
        : NULL;

      $probeRequired = FALSE;
      $transitionToHalfOpen = FALSE;
      $cooldown = $this->getCircuitBreakerConfig('cooldown_seconds');

      if ($breaker['state'] === 'open') {
        if (($now - $breaker['opened_at']) < $cooldown) {
          return ['allowed' => FALSE, 'reason' => 'circuit_breaker_open'];
        }
        if ($reserve) {
          $probeRequired = TRUE;
          $transitionToHalfOpen = TRUE;
        }
      }
      elseif ($breaker['state'] === 'half_open' && $reserve) {
        $probeRequired = TRUE;
      }

      if (!$this->rateLimiterHasCapacity($limiter)) {
        return ['allowed' => FALSE, 'reason' => 'rate_limit_exceeded'];
      }
      if ($this->isBudgetExhausted($daily, 'daily_call_limit')) {
        return ['allowed' => FALSE, 'reason' => 'daily_budget_exhausted'];
      }
      if ($this->isBudgetExhausted($monthly, 'monthly_call_limit')) {
        return ['allowed' => FALSE, 'reason' => 'monthly_budget_exhausted'];
      }
      if (!$this->perIpBudgetHasCapacity($perIpBudget)) {
        return ['allowed' => FALSE, 'reason' => 'per_ip_budget_exceeded'];
      }
      if (!$this->passesSamplingGate()) {
        return ['allowed' => FALSE, 'reason' => 'sampling_gate_rejected'];
      }

      if (!$reserve) {
        return ['allowed' => TRUE, 'reason' => 'allowed'];
      }

      if ($probeRequired && !$this->lock->acquire(self::PROBE_LOCK, self::LOCK_TTL)) {
        return ['allowed' => FALSE, 'reason' => 'circuit_breaker_open'];
      }
      $probeAcquired = $probeRequired;

      if ($transitionToHalfOpen) {
        $breaker['state'] = 'half_open';
        $this->logger->notice('LLM circuit breaker transitioning from open to half_open after @cooldown s cooldown.', [
          '@cooldown' => $cooldown,
        ]);
        $this->state->set(LlmCircuitBreaker::STATE_KEY, $breaker);
      }

      if ($this->getRateLimiterConfig('max_per_hour') > 0) {
        $limiter['count']++;
        $this->state->set(LlmRateLimiter::STATE_KEY, $limiter);
        $this->logRateLimiterThresholds($limiter);
      }

      $daily['count']++;
      $this->state->set(CostControlPolicy::STATE_KEY_DAILY, $daily);
      $this->logDailyBudgetThresholds($daily);

      $monthly['count']++;
      $this->state->set(CostControlPolicy::STATE_KEY_MONTHLY, $monthly);

      if ($identityHash !== NULL && $this->getCostControlConfig('per_ip_hourly_call_limit') > 0) {
        $perIpBudget['count']++;
        $perIpBudget['window_start'] = (int) ($perIpBudget['window_start'] ?? $now);
        $perIpBudgets[$identityHash] = $perIpBudget;
        $this->state->set(CostControlPolicy::STATE_KEY_PER_IP, $perIpBudgets);
        $this->logPerIpBudgetThresholds($perIpBudget, $identityHash);
      }

      return ['allowed' => TRUE, 'reason' => 'allowed'];
    }
    finally {
      $this->lock->release(self::CONTROL_LOCK);
      if (!$probeAcquired) {
        $this->lock->release(self::PROBE_LOCK);
      }
    }
  }

  /**
   * Atomically reserves one rate-limiter slot when capacity remains.
   */
  public function tryAcquireRateLimitAllowance(): bool {
    if ($this->getRateLimiterConfig('max_per_hour') === 0) {
      return TRUE;
    }
    if (!$this->lock->acquire(self::CONTROL_LOCK, self::LOCK_TTL)) {
      return FALSE;
    }

    try {
      $data = $this->prepareRateLimiterWindow($this->getRateLimiterData(), time());
      if (!$this->rateLimiterHasCapacity($data)) {
        return FALSE;
      }
      $data['count']++;
      $this->state->set(LlmRateLimiter::STATE_KEY, $data);
      $this->logRateLimiterThresholds($data);
      return TRUE;
    }
    finally {
      $this->lock->release(self::CONTROL_LOCK);
    }
  }

  /**
   * Atomically acquires circuit-breaker admission.
   */
  public function tryAcquireCircuitAdmission(): bool {
    if (!$this->lock->acquire(self::CONTROL_LOCK, self::LOCK_TTL)) {
      return FALSE;
    }

    $probeAcquired = FALSE;
    try {
      $data = $this->getCircuitBreakerData();
      if ($data['state'] === 'closed') {
        return TRUE;
      }

      $now = time();
      $cooldown = $this->getCircuitBreakerConfig('cooldown_seconds');
      $transitionToHalfOpen = FALSE;

      if ($data['state'] === 'open') {
        if (($now - $data['opened_at']) < $cooldown) {
          return FALSE;
        }
        $transitionToHalfOpen = TRUE;
      }

      if (!$this->lock->acquire(self::PROBE_LOCK, self::LOCK_TTL)) {
        return FALSE;
      }
      $probeAcquired = TRUE;

      if ($transitionToHalfOpen) {
        $data['state'] = 'half_open';
        $this->state->set(LlmCircuitBreaker::STATE_KEY, $data);
        $this->logger->notice('LLM circuit breaker transitioning from open to half_open after @cooldown s cooldown.', [
          '@cooldown' => $cooldown,
        ]);
      }

      return TRUE;
    }
    finally {
      $this->lock->release(self::CONTROL_LOCK);
      if (!$probeAcquired) {
        $this->lock->release(self::PROBE_LOCK);
      }
    }
  }

  /**
   * Atomically records a compatibility-path rate-limiter increment.
   */
  public function recordRateLimiterCall(): void {
    if ($this->getRateLimiterConfig('max_per_hour') === 0) {
      return;
    }
    $this->withBlockingControlLock(function (): void {
      $data = $this->prepareRateLimiterWindow($this->getRateLimiterData(), time());
      $data['count']++;
      $this->state->set(LlmRateLimiter::STATE_KEY, $data);
      $this->logRateLimiterThresholds($data);
    });
  }

  /**
   * Atomically records a compatibility-path cost-control increment.
   */
  public function recordCostControlCall(): void {
    $this->withBlockingControlLock(function (): void {
      $now = time();
      $daily = $this->getDailyBudgetData($now);
      $daily['count']++;
      $this->state->set(CostControlPolicy::STATE_KEY_DAILY, $daily);
      $this->logDailyBudgetThresholds($daily);

      $monthly = $this->getMonthlyBudgetData($now);
      $monthly['count']++;
      $this->state->set(CostControlPolicy::STATE_KEY_MONTHLY, $monthly);
    });
  }

  /**
   * Atomically records a cache hit or miss.
   */
  public function recordCacheStat(string $stat): void {
    if (!in_array($stat, ['hits', 'misses'], TRUE)) {
      return;
    }

    $this->withBlockingControlLock(function () use ($stat): void {
      $data = $this->getCacheStatsData(time());
      $data[$stat]++;
      $this->state->set(CostControlPolicy::STATE_KEY_CACHE_STATS, $data);
    });
  }

  /**
   * Atomically records a successful breaker outcome.
   */
  public function recordCircuitSuccess(): void {
    $releaseProbe = FALSE;

    $this->withBlockingControlLock(function () use (&$releaseProbe): void {
      $data = $this->getCircuitBreakerData();
      if ($data['state'] === 'half_open') {
        $this->state->set(LlmCircuitBreaker::STATE_KEY, $this->defaultCircuitBreakerState());
        $this->logger->info('LLM circuit breaker closing after successful half-open probe.');
        $releaseProbe = TRUE;
        return;
      }

      if ($data['state'] === 'closed' && $data['consecutive_failures'] > 0) {
        $data['consecutive_failures'] = 0;
        $data['last_failure_time'] = 0;
        $this->state->set(LlmCircuitBreaker::STATE_KEY, $data);
      }
    });

    if ($releaseProbe) {
      $this->lock->release(self::PROBE_LOCK);
    }
  }

  /**
   * Atomically records a failed breaker outcome.
   */
  public function recordCircuitFailure(): void {
    $releaseProbe = FALSE;

    $this->withBlockingControlLock(function () use (&$releaseProbe): void {
      $data = $this->getCircuitBreakerData();
      $now = time();
      $threshold = $this->getCircuitBreakerConfig('failure_threshold');
      $window = $this->getCircuitBreakerConfig('failure_window_seconds');

      if ($data['state'] === 'half_open') {
        $data['state'] = 'open';
        $data['opened_at'] = $now;
        $data['consecutive_failures'] = $threshold;
        $data['last_failure_time'] = $now;
        $this->state->set(LlmCircuitBreaker::STATE_KEY, $data);
        $this->logger->warning('LLM circuit breaker reopened after failed half-open probe.');
        $releaseProbe = TRUE;
        return;
      }

      if ($data['state'] === 'open') {
        return;
      }

      if ($data['last_failure_time'] > 0 && ($now - $data['last_failure_time']) > $window) {
        $data['consecutive_failures'] = 0;
      }

      $data['consecutive_failures']++;
      $data['last_failure_time'] = $now;

      if ($data['consecutive_failures'] >= $threshold) {
        $data['state'] = 'open';
        $data['opened_at'] = $now;
        $this->logger->warning('LLM circuit breaker opened after @count consecutive failures within @window s.', [
          '@count' => $data['consecutive_failures'],
          '@window' => $window,
        ]);
      }

      $this->state->set(LlmCircuitBreaker::STATE_KEY, $data);
    });

    if ($releaseProbe) {
      $this->lock->release(self::PROBE_LOCK);
    }
  }

  /**
   * Atomically resets the rate limiter state.
   */
  public function resetRateLimiter(): void {
    $this->withBlockingControlLock(function (): void {
      $this->state->set(LlmRateLimiter::STATE_KEY, [
        'count' => 0,
        'window_start' => time(),
      ]);
    });
  }

  /**
   * Atomically resets the circuit breaker state.
   */
  public function resetCircuitBreaker(): void {
    $this->withBlockingControlLock(function (): void {
      $this->state->set(LlmCircuitBreaker::STATE_KEY, $this->defaultCircuitBreakerState());
      $this->lock->release(self::PROBE_LOCK);
    });
  }

  /**
   * Atomically resets cost-control counters and cache stats.
   */
  public function resetCostControl(): void {
    $this->withBlockingControlLock(function (): void {
      $this->state->set(CostControlPolicy::STATE_KEY_DAILY, NULL);
      $this->state->set(CostControlPolicy::STATE_KEY_MONTHLY, NULL);
      $this->state->set(CostControlPolicy::STATE_KEY_PER_IP, NULL);
      $this->state->set(CostControlPolicy::STATE_KEY_CACHE_STATS, NULL);
    });
  }

  /**
   * Executes a mutation while waiting briefly for the control lock.
   */
  protected function withBlockingControlLock(callable $callback): void {
    $deadline = microtime(TRUE) + self::BLOCKING_WAIT_SECONDS;
    do {
      if ($this->lock->acquire(self::CONTROL_LOCK, self::LOCK_TTL)) {
        try {
          $callback();
        }
        finally {
          $this->lock->release(self::CONTROL_LOCK);
        }
        return;
      }
      usleep(25000);
    } while (microtime(TRUE) < $deadline);

    $this->logger->warning('LLM control-state lock acquisition timed out during post-admission mutation.');
  }

  /**
   * Returns normalized rate-limiter state.
   */
  protected function getRateLimiterData(): array {
    $data = $this->state->get(LlmRateLimiter::STATE_KEY);
    if (!is_array($data) || !isset($data['count'])) {
      return [
        'count' => 0,
        'window_start' => time(),
      ];
    }
    return [
      'count' => (int) $data['count'],
      'window_start' => (int) ($data['window_start'] ?? time()),
    ];
  }

  /**
   * Returns rate-limiter state with expired windows reset.
   */
  protected function prepareRateLimiterWindow(array $data, int $now): array {
    $windowSeconds = $this->getRateLimiterConfig('window_seconds');
    if (($now - $data['window_start']) >= $windowSeconds) {
      return [
        'count' => 0,
        'window_start' => $now,
      ];
    }
    return $data;
  }

  /**
   * Returns TRUE when the limiter can admit one more request.
   */
  protected function rateLimiterHasCapacity(array $data): bool {
    $maxPerHour = $this->getRateLimiterConfig('max_per_hour');
    return $maxPerHour === 0 || $data['count'] < $maxPerHour;
  }

  /**
   * Logs rate-limiter thresholds using the post-increment state.
   */
  protected function logRateLimiterThresholds(array $data): void {
    $maxPerHour = $this->getRateLimiterConfig('max_per_hour');
    if ($maxPerHour === 0) {
      return;
    }

    $threshold80 = (int) ceil($maxPerHour * 0.8);
    if ($data['count'] === $threshold80) {
      $this->logger->notice('LLM global rate limit at 80% (@count/@max) within current window.', [
        '@count' => $data['count'],
        '@max' => $maxPerHour,
      ]);
    }

    if ($data['count'] >= $maxPerHour) {
      $this->logger->warning('LLM global rate limit reached (@count/@max). Subsequent calls will be skipped until window resets.', [
        '@count' => $data['count'],
        '@max' => $maxPerHour,
      ]);
    }
  }

  /**
   * Returns normalized circuit-breaker state.
   */
  protected function getCircuitBreakerData(): array {
    $data = $this->state->get(LlmCircuitBreaker::STATE_KEY);
    if (!is_array($data) || !isset($data['state'])) {
      return $this->defaultCircuitBreakerState();
    }
    return [
      'state' => (string) $data['state'],
      'consecutive_failures' => (int) ($data['consecutive_failures'] ?? 0),
      'last_failure_time' => (int) ($data['last_failure_time'] ?? 0),
      'opened_at' => (int) ($data['opened_at'] ?? 0),
    ];
  }

  /**
   * Returns the default closed circuit-breaker state.
   */
  protected function defaultCircuitBreakerState(): array {
    return [
      'state' => 'closed',
      'consecutive_failures' => 0,
      'last_failure_time' => 0,
      'opened_at' => 0,
    ];
  }

  /**
   * Returns normalized daily budget state.
   */
  protected function getDailyBudgetData(int $now): array {
    $data = $this->state->get(CostControlPolicy::STATE_KEY_DAILY);
    $today = date('Y-m-d', $now);
    if (!is_array($data) || !isset($data['count']) || ($data['date'] ?? '') !== $today) {
      return ['count' => 0, 'date' => $today];
    }
    return [
      'count' => (int) $data['count'],
      'date' => (string) $data['date'],
    ];
  }

  /**
   * Returns normalized monthly budget state.
   */
  protected function getMonthlyBudgetData(int $now): array {
    $data = $this->state->get(CostControlPolicy::STATE_KEY_MONTHLY);
    $month = date('Y-m', $now);
    if (!is_array($data) || !isset($data['count']) || ($data['month'] ?? '') !== $month) {
      return ['count' => 0, 'month' => $month];
    }
    return [
      'count' => (int) $data['count'],
      'month' => (string) $data['month'],
    ];
  }

  /**
   * Returns normalized per-IP budget state with expired windows pruned.
   *
   * @return array<string, array{count:int, window_start:int}>
   *   Active hashed per-IP budget buckets.
   */
  protected function getPerIpBudgetState(int $now): array {
    $data = $this->state->get(CostControlPolicy::STATE_KEY_PER_IP);
    $windowSeconds = $this->getCostControlConfig('per_ip_window_seconds');
    if (!is_array($data)) {
      return [];
    }

    $normalized = [];
    foreach ($data as $identityHash => $bucket) {
      if (!is_string($identityHash) || !is_array($bucket) || !isset($bucket['count'])) {
        continue;
      }

      $windowStart = (int) ($bucket['window_start'] ?? 0);
      if ($windowSeconds > 0 && ($now - $windowStart) >= $windowSeconds) {
        continue;
      }

      $normalized[$identityHash] = [
        'count' => (int) $bucket['count'],
        'window_start' => $windowStart > 0 ? $windowStart : $now,
      ];
    }

    return $normalized;
  }

  /**
   * Returns TRUE when a budget is exhausted.
   */
  protected function isBudgetExhausted(array $data, string $configKey): bool {
    $limit = $this->getCostControlConfig($configKey);
    return $limit > 0 && $data['count'] >= $limit;
  }

  /**
   * Returns TRUE when the per-IP budget can admit one more request.
   *
   * @param array<string, int>|null $data
   *   The current per-IP bucket or NULL when no identity was supplied.
   */
  protected function perIpBudgetHasCapacity(?array $data): bool {
    $limit = $this->getCostControlConfig('per_ip_hourly_call_limit');
    if ($data === NULL || $limit === 0) {
      return TRUE;
    }

    return (int) ($data['count'] ?? 0) < $limit;
  }

  /**
   * Logs daily-budget thresholds using the post-increment state.
   */
  protected function logDailyBudgetThresholds(array $data): void {
    $limit = $this->getCostControlConfig('daily_call_limit');
    if ($limit === 0) {
      return;
    }

    $threshold80 = (int) ceil($limit * 0.8);
    if ($data['count'] === $threshold80) {
      $this->logger->notice('Daily LLM budget at 80% (@count/@max).', [
        '@count' => $data['count'],
        '@max' => $limit,
      ]);
    }

    if ($data['count'] === $limit) {
      $this->logger->warning('Daily LLM budget exhausted (@count/@max).', [
        '@count' => $data['count'],
        '@max' => $limit,
      ]);
    }
  }

  /**
   * Logs per-IP threshold crossings using the post-increment state.
   */
  protected function logPerIpBudgetThresholds(array $data, string $identityHash): void {
    $limit = $this->getCostControlConfig('per_ip_hourly_call_limit');
    if ($limit === 0) {
      return;
    }

    $threshold80 = (int) ceil($limit * 0.8);
    $identityLabel = substr($identityHash, 0, 12);
    if ($data['count'] === $threshold80) {
      $this->logger->notice('Per-IP LLM budget at 80% for identity @identity (@count/@max).', [
        '@identity' => $identityLabel,
        '@count' => $data['count'],
        '@max' => $limit,
      ]);
    }

    if ($data['count'] === $limit) {
      $this->logger->warning('Per-IP LLM budget exhausted for identity @identity (@count/@max).', [
        '@identity' => $identityLabel,
        '@count' => $data['count'],
        '@max' => $limit,
      ]);
    }
  }

  /**
   * Returns normalized cache-stat state.
   */
  protected function getCacheStatsData(int $now): array {
    $data = $this->state->get(CostControlPolicy::STATE_KEY_CACHE_STATS);
    $windowSeconds = $this->getCostControlConfig('cache_stats_window_seconds');

    if (!is_array($data) || !isset($data['hits'])) {
      return ['hits' => 0, 'misses' => 0, 'window_start' => $now];
    }

    if ($windowSeconds > 0 && ($now - (int) ($data['window_start'] ?? 0)) >= $windowSeconds) {
      return ['hits' => 0, 'misses' => 0, 'window_start' => $now];
    }

    return [
      'hits' => (int) ($data['hits'] ?? 0),
      'misses' => (int) ($data['misses'] ?? 0),
      'window_start' => (int) ($data['window_start'] ?? $now),
    ];
  }

  /**
   * Returns TRUE when the sampling gate passes.
   */
  protected function passesSamplingGate(): bool {
    $rate = $this->getCostControlFloatConfig('sample_rate');
    if ($rate >= 1.0) {
      return TRUE;
    }
    if ($rate <= 0.0) {
      return FALSE;
    }
    return (mt_rand() / mt_getrandmax()) < $rate;
  }

  /**
   * Gets an integer rate-limiter config value.
   */
  protected function getRateLimiterConfig(string $key): int {
    $defaults = [
      'max_per_hour' => 500,
      'window_seconds' => 3600,
    ];
    $config = $this->configFactory->get('ilas_site_assistant.settings');
    $value = $config->get('llm.global_rate_limit.' . $key);
    return (int) ($value ?? $defaults[$key] ?? 0);
  }

  /**
   * Gets an integer circuit-breaker config value.
   */
  protected function getCircuitBreakerConfig(string $key): int {
    $defaults = [
      'failure_threshold' => 3,
      'failure_window_seconds' => 60,
      'cooldown_seconds' => 300,
    ];
    $config = $this->configFactory->get('ilas_site_assistant.settings');
    $value = $config->get('llm.circuit_breaker.' . $key);
    return (int) ($value ?? $defaults[$key] ?? 0);
  }

  /**
   * Gets an integer cost-control config value.
   */
  protected function getCostControlConfig(string $key): int {
    $defaults = [
      'daily_call_limit' => 5000,
      'monthly_call_limit' => 100000,
      'per_ip_hourly_call_limit' => 10,
      'per_ip_window_seconds' => 3600,
      'cache_stats_window_seconds' => 86400,
      'alert_cooldown_minutes' => 60,
    ];
    $config = $this->configFactory->get('ilas_site_assistant.settings');
    $value = $config->get('cost_control.' . $key);
    return (int) ($value ?? $defaults[$key] ?? 0);
  }

  /**
   * Gets a float cost-control config value.
   */
  protected function getCostControlFloatConfig(string $key): float {
    $defaults = [
      'sample_rate' => 1.0,
      'cache_hit_rate_target' => 0.30,
    ];
    $config = $this->configFactory->get('ilas_site_assistant.settings');
    $value = $config->get('cost_control.' . $key);
    return (float) ($value ?? $defaults[$key] ?? 0.0);
  }

}
