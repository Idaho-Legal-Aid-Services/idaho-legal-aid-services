<?php

namespace Drupal\ilas_site_assistant\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Site\Settings;
use Drupal\Core\State\StateInterface;
use Psr\Log\LoggerInterface;

/**
 * Cost-control policy service for LLM API budget enforcement.
 *
 * Enforces daily/monthly budget caps, LLM sampling policy, cache-hit
 * monitoring, cost estimation, and a consolidated kill-switch evaluator.
 *
 * State is stored in Drupal's State API (key_value table), which works
 * on all Pantheon tiers without Redis.
 */
class CostControlPolicy {

  const STATE_KEY_DAILY = 'ilas_site_assistant.cost_control.daily';
  const STATE_KEY_MONTHLY = 'ilas_site_assistant.cost_control.monthly';
  const STATE_KEY_PER_IP = 'ilas_site_assistant.cost_control.per_ip';
  const STATE_KEY_CACHE_STATS = 'ilas_site_assistant.cost_control.cache_stats';
  const STATE_KEY_KILL_SWITCH = 'ilas_site_assistant.cost_control.kill_switch';

  protected StateInterface $state;
  protected ConfigFactoryInterface $configFactory;
  protected LoggerInterface $logger;
  protected LlmCircuitBreaker $circuitBreaker;
  protected LlmRateLimiter $rateLimiter;
  protected LlmAdmissionCoordinator $admissionCoordinator;

  /**
   * Timestamp of last alert, to enforce cooldown.
   *
   * @var int
   */
  protected int $lastAlertTime = 0;

  /**
   * Constructs a CostControlPolicy.
   */
  public function __construct(
    StateInterface $state,
    ConfigFactoryInterface $config_factory,
    LoggerInterface $logger,
    LlmCircuitBreaker $circuit_breaker,
    LlmRateLimiter $rate_limiter,
    LlmAdmissionCoordinator $admission_coordinator,
  ) {
    $this->state = $state;
    $this->configFactory = $config_factory;
    $this->logger = $logger;
    $this->circuitBreaker = $circuit_breaker;
    $this->rateLimiter = $rate_limiter;
    $this->admissionCoordinator = $admission_coordinator;
  }

  /**
   * Checks whether an LLM request is allowed under all policy gates.
   *
   * Evaluation order: kill-switch, circuit breaker, rate limit,
   * daily budget, monthly budget, sampling gate.
   *
   * @return array
   *   Array with 'allowed' (bool) and 'reason' (string).
   */
  public function isRequestAllowed(?string $budgetIdentity = NULL): array {
    // 1. Manual kill switch.
    if ($this->state->get(self::STATE_KEY_KILL_SWITCH, FALSE)) {
      return ['allowed' => FALSE, 'reason' => 'manual_kill_switch'];
    }

    // 2. Circuit breaker.
    if (!$this->circuitBreaker->isAvailable()) {
      return ['allowed' => FALSE, 'reason' => 'circuit_breaker_open'];
    }

    // 3. Rate limit.
    if (!$this->rateLimiter->isAllowed()) {
      return ['allowed' => FALSE, 'reason' => 'rate_limit_exceeded'];
    }

    // 4. Daily budget.
    if ($this->isDailyBudgetExhausted()) {
      return ['allowed' => FALSE, 'reason' => 'daily_budget_exhausted'];
    }

    // 5. Monthly budget.
    if ($this->isMonthlyBudgetExhausted()) {
      return ['allowed' => FALSE, 'reason' => 'monthly_budget_exhausted'];
    }

    // 6. Per-IP budget.
    if ($this->isPerIpBudgetExhausted($budgetIdentity)) {
      return ['allowed' => FALSE, 'reason' => 'per_ip_budget_exceeded'];
    }

    // 7. Sampling gate.
    if (!$this->passesSamplingGate()) {
      return ['allowed' => FALSE, 'reason' => 'sampling_gate_rejected'];
    }

    return ['allowed' => TRUE, 'reason' => 'allowed'];
  }

  /**
   * Atomically evaluates and reserves request admission state.
   *
   * @return array
   *   Array with 'allowed' (bool) and 'reason' (string).
   */
  public function beginRequest(?string $budgetIdentity = NULL): array {
    return $this->admissionCoordinator->beginRequest($budgetIdentity);
  }

  /**
   * Records a successful LLM API call against budget counters.
   *
   * @param array|null $tokenUsage
   *   Optional token usage array with 'input' and 'output' keys.
   */
  public function recordCall(?array $tokenUsage = NULL): void {
    $this->admissionCoordinator->recordCostControlCall();
  }

  /**
   * Records a cache hit for cache-hit-rate monitoring.
   */
  public function recordCacheHit(): void {
    $this->admissionCoordinator->recordCacheStat('hits');
  }

  /**
   * Records a cache miss for cache-hit-rate monitoring.
   */
  public function recordCacheMiss(): void {
    $this->admissionCoordinator->recordCacheStat('misses');
  }

  /**
   * Checks if the daily call budget is exhausted.
   *
   * @return bool
   *   TRUE if daily limit reached.
   */
  public function isDailyBudgetExhausted(): bool {
    $limit = $this->getConfig('daily_call_limit');
    if ($limit === 0) {
      return FALSE;
    }

    $data = $this->getDailyData();
    return $data['count'] >= $limit;
  }

  /**
   * Checks if the monthly call budget is exhausted.
   *
   * @return bool
   *   TRUE if monthly limit reached.
   */
  public function isMonthlyBudgetExhausted(): bool {
    $limit = $this->getConfig('monthly_call_limit');
    if ($limit === 0) {
      return FALSE;
    }

    $data = $this->getMonthlyData();
    return $data['count'] >= $limit;
  }

  /**
   * Checks if the per-IP call budget is exhausted for the supplied identity.
   *
   * @param string|null $budgetIdentity
   *   The trusted client-identity string used for budgeting.
   *
   * @return bool
   *   TRUE if the per-IP limit is reached.
   */
  public function isPerIpBudgetExhausted(?string $budgetIdentity): bool {
    $normalizedIdentity = static::normalizeBudgetIdentity($budgetIdentity);
    $limit = $this->getConfig('per_ip_hourly_call_limit');

    if ($normalizedIdentity === NULL || $limit === 0) {
      return FALSE;
    }

    $identityHash = static::hashBudgetIdentity($normalizedIdentity);
    $data = $this->getPerIpBudgetState(time());
    $bucket = $data[$identityHash] ?? ['count' => 0, 'window_start' => time()];

    return (int) $bucket['count'] >= $limit;
  }

  /**
   * Checks if the current request passes the sampling gate.
   *
   * @return bool
   *   TRUE if request passes sampling.
   */
  public function passesSamplingGate(): bool {
    $rate = $this->getConfigFloat('sample_rate');
    if ($rate >= 1.0) {
      return TRUE;
    }
    if ($rate <= 0.0) {
      return FALSE;
    }
    return (mt_rand() / mt_getrandmax()) < $rate;
  }

  /**
   * Calculates the current cache hit rate.
   *
   * @return float|null
   *   Hit rate between 0.0 and 1.0, or NULL if no data.
   */
  public function getCacheHitRate(): ?float {
    $stats = $this->getCacheStats();
    $total = $stats['hits'] + $stats['misses'];
    if ($total === 0) {
      return NULL;
    }
    return $stats['hits'] / $total;
  }

  /**
   * Checks if the cache hit rate is above the configured target.
   *
   * @return bool
   *   TRUE if healthy (at or above target), FALSE if degraded.
   */
  public function isCacheHitRateHealthy(): bool {
    $rate = $this->getCacheHitRate();
    if ($rate === NULL) {
      return TRUE;
    }
    $target = $this->getConfigFloat('cache_hit_rate_target');
    if ($rate < $target) {
      $this->logger->warning('Cache hit rate degraded: @rate (target: @target).', [
        '@rate' => round($rate, 3),
        '@target' => $target,
      ]);
      return FALSE;
    }
    return TRUE;
  }

  /**
   * Evaluates the consolidated kill-switch condition.
   *
   * @return array
   *   Array with 'killed' (bool) and 'reasons' (string[]).
   */
  public function evaluateKillSwitch(): array {
    $reasons = [];

    if ($this->state->get(self::STATE_KEY_KILL_SWITCH, FALSE)) {
      $reasons[] = 'manual_kill_switch';
    }

    if (!$this->circuitBreaker->isAvailable()) {
      $reasons[] = 'circuit_breaker_open';
    }

    if ($this->isDailyBudgetExhausted()) {
      $reasons[] = 'daily_budget_exhausted';
    }

    if ($this->isMonthlyBudgetExhausted()) {
      $reasons[] = 'monthly_budget_exhausted';
    }

    return [
      'killed' => !empty($reasons),
      'reasons' => $reasons,
    ];
  }

  /**
   * Estimates the cost of a single LLM call based on token usage.
   *
   * @param array $tokenUsage
   *   Array with 'input' and 'output' token counts.
   *
   * @return float
   *   Estimated cost in USD.
   */
  public function estimateCost(array $tokenUsage): float {
    $inputTokens = (int) ($tokenUsage['input'] ?? 0);
    $outputTokens = (int) ($tokenUsage['output'] ?? 0);

    $config = $this->configFactory->get('ilas_site_assistant.settings');
    $inputPrice = (float) ($config->get('cost_control.pricing.input_per_1m_tokens') ?? 0.075);
    $outputPrice = (float) ($config->get('cost_control.pricing.output_per_1m_tokens') ?? 0.30);

    return ($inputTokens / 1_000_000) * $inputPrice + ($outputTokens / 1_000_000) * $outputPrice;
  }

  /**
   * Returns a dashboard-ready summary of all cost-control state.
   *
   * @return array
   *   Snapshot with counters, rates, limits, and kill-switch status.
   */
  public function getSummary(): array {
    $daily = $this->getDailyData();
    $monthly = $this->getMonthlyData();
    $cacheStats = $this->getCacheStats();
    $cacheRequests = $cacheStats['hits'] + $cacheStats['misses'];

    return [
      'daily_calls' => $daily['count'],
      'daily_limit' => $this->getConfig('daily_call_limit'),
      'monthly_calls' => $monthly['count'],
      'monthly_limit' => $this->getConfig('monthly_call_limit'),
      'cache_hits' => $cacheStats['hits'],
      'cache_misses' => $cacheStats['misses'],
      'cache_requests' => $cacheRequests,
      'cache_hit_rate' => $this->getCacheHitRate(),
      'cache_hit_rate_target' => $this->getConfigFloat('cache_hit_rate_target'),
      'kill_switch_active' => (bool) $this->state->get(self::STATE_KEY_KILL_SWITCH, FALSE),
      'sample_rate' => $this->getConfigFloat('sample_rate'),
      'per_ip_hourly_call_limit' => $this->getConfig('per_ip_hourly_call_limit'),
      'per_ip_window_seconds' => $this->getConfig('per_ip_window_seconds'),
    ];
  }

  /**
   * Activates the manual kill switch.
   */
  public function activateKillSwitch(): void {
    $this->state->set(self::STATE_KEY_KILL_SWITCH, TRUE);
    $this->logger->warning('Cost control: manual kill switch ACTIVATED.');
  }

  /**
   * Deactivates the manual kill switch.
   */
  public function deactivateKillSwitch(): void {
    $this->state->set(self::STATE_KEY_KILL_SWITCH, FALSE);
    $this->logger->info('Cost control: manual kill switch deactivated.');
  }

  /**
   * Resets all cost-control state (for testing/recovery).
   */
  public function reset(): void {
    $this->admissionCoordinator->resetCostControl();
    $this->state->set(self::STATE_KEY_KILL_SWITCH, FALSE);
    $this->lastAlertTime = 0;
  }

  /**
   * Gets daily counter data with lazy date reset.
   *
   * @return array
   *   Array with 'count' (int) and 'date' (string Y-m-d).
   */
  protected function getDailyData(): array {
    $data = $this->state->get(self::STATE_KEY_DAILY);
    $today = date('Y-m-d');

    if (!is_array($data) || !isset($data['count']) || ($data['date'] ?? '') !== $today) {
      return ['count' => 0, 'date' => $today];
    }
    return $data;
  }

  /**
   * Gets monthly counter data with lazy month reset.
   *
   * @return array
   *   Array with 'count' (int) and 'month' (string Y-m).
   */
  protected function getMonthlyData(): array {
    $data = $this->state->get(self::STATE_KEY_MONTHLY);
    $currentMonth = date('Y-m');

    if (!is_array($data) || !isset($data['count']) || ($data['month'] ?? '') !== $currentMonth) {
      return ['count' => 0, 'month' => $currentMonth];
    }
    return $data;
  }

  /**
   * Returns normalized per-IP budget state with expired windows pruned.
   *
   * @return array<string, array{count:int, window_start:int}>
   *   Active hashed per-IP budget buckets.
   */
  protected function getPerIpBudgetState(int $now): array {
    $data = $this->state->get(self::STATE_KEY_PER_IP);
    $windowSeconds = $this->getConfig('per_ip_window_seconds');
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
   * Increments the daily call counter with threshold logging.
   */
  protected function incrementDailyCounter(): void {
    $data = $this->getDailyData();
    $data['count']++;
    $this->state->set(self::STATE_KEY_DAILY, $data);

    $limit = $this->getConfig('daily_call_limit');
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

    if ($data['count'] >= $limit && $data['count'] === $limit) {
      $this->logger->warning('Daily LLM budget exhausted (@count/@max).', [
        '@count' => $data['count'],
        '@max' => $limit,
      ]);
    }
  }

  /**
   * Increments the monthly call counter.
   */
  protected function incrementMonthlyCounter(): void {
    $data = $this->getMonthlyData();
    $data['count']++;
    $this->state->set(self::STATE_KEY_MONTHLY, $data);
  }

  /**
   * Gets cache stats with lazy window reset.
   *
   * @return array
   *   Array with 'hits', 'misses', 'window_start'.
   */
  protected function getCacheStats(): array {
    $data = $this->state->get(self::STATE_KEY_CACHE_STATS);
    $windowSeconds = $this->getConfig('cache_stats_window_seconds');

    if (!is_array($data) || !isset($data['hits'])) {
      return ['hits' => 0, 'misses' => 0, 'window_start' => time()];
    }

    if ($windowSeconds > 0 && (time() - $data['window_start']) >= $windowSeconds) {
      return ['hits' => 0, 'misses' => 0, 'window_start' => time()];
    }

    return $data;
  }

  /**
   * Persists cache stats.
   */
  protected function setCacheStats(array $data): void {
    $this->state->set(self::STATE_KEY_CACHE_STATS, $data);
  }

  /**
   * Gets an integer config value.
   */
  protected function getConfig(string $key): int {
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
   * Gets a float config value.
   */
  protected function getConfigFloat(string $key): float {
    $defaults = [
      'sample_rate' => 1.0,
      'cache_hit_rate_target' => 0.30,
    ];
    $config = $this->configFactory->get('ilas_site_assistant.settings');
    $value = $config->get('cost_control.' . $key);
    return (float) ($value ?? $defaults[$key] ?? 0.0);
  }

  /**
   * Normalizes a request identity for budgeting.
   */
  public static function normalizeBudgetIdentity(?string $budgetIdentity): ?string {
    $budgetIdentity = trim((string) $budgetIdentity);
    return $budgetIdentity === '' ? NULL : $budgetIdentity;
  }

  /**
   * Returns the HMAC-hashed state key suffix for a budget identity.
   */
  public static function hashBudgetIdentity(string $budgetIdentity): string {
    try {
      $hashSalt = (string) Settings::get('hash_salt', '');
    }
    catch (\Throwable) {
      $hashSalt = '';
    }
    if ($hashSalt === '') {
      $hashSalt = __CLASS__;
    }

    return hash_hmac('sha256', $budgetIdentity, $hashSalt);
  }

}
