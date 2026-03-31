<?php

namespace Drupal\Tests\ilas_site_assistant\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Lock\NullLockBackend;
use Drupal\Core\State\StateInterface;
use Drupal\ilas_site_assistant\Service\CostControlPolicy;
use Drupal\ilas_site_assistant\Service\LlmAdmissionCoordinator;
use Drupal\ilas_site_assistant\Service\LlmCircuitBreaker;
use Drupal\ilas_site_assistant\Service\LlmRateLimiter;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for CostControlPolicy.
 */
#[Group('ilas_site_assistant')]
class CostControlPolicyTest extends TestCase {

  /**
   * Stored state values keyed by state key.
   *
   * @var array
   */
  private array $storedState = [];

  /**
   * Captured log messages.
   *
   * @var array
   */
  private array $logMessages = [];

  // ---------------------------------------------------------------
  // AC-1: Budget thresholds enforced in non-live simulation.
  // ---------------------------------------------------------------

  /**
   * Tests daily budget blocks when exhausted.
   */
  public function testDailyBudgetBlocksWhenExhausted(): void {
    $policy = $this->buildPolicy(configOverrides: [
      'cost_control.daily_call_limit' => 5,
    ]);

    for ($i = 0; $i < 5; $i++) {
      $policy->recordCall();
    }

    $this->assertTrue($policy->isDailyBudgetExhausted());
    $result = $policy->isRequestAllowed();
    $this->assertFalse($result['allowed']);
    $this->assertEquals('daily_budget_exhausted', $result['reason']);
  }

  /**
   * Tests monthly budget blocks when exhausted.
   */
  public function testMonthlyBudgetBlocksWhenExhausted(): void {
    $policy = $this->buildPolicy(configOverrides: [
      'cost_control.monthly_call_limit' => 3,
    ]);

    for ($i = 0; $i < 3; $i++) {
      $policy->recordCall();
    }

    $this->assertTrue($policy->isMonthlyBudgetExhausted());
    $result = $policy->isRequestAllowed();
    $this->assertFalse($result['allowed']);
    $this->assertEquals('monthly_budget_exhausted', $result['reason']);
  }

  /**
   * Tests daily budget resets on a new day.
   */
  public function testDailyBudgetResetsOnNewDay(): void {
    $policy = $this->buildPolicy(configOverrides: [
      'cost_control.daily_call_limit' => 2,
    ]);

    $policy->recordCall();
    $policy->recordCall();
    $this->assertTrue($policy->isDailyBudgetExhausted());

    // Backdate the stored daily state to yesterday.
    $this->storedState[CostControlPolicy::STATE_KEY_DAILY] = [
      'count' => 2,
      'date' => date('Y-m-d', strtotime('-1 day')),
    ];

    $this->assertFalse($policy->isDailyBudgetExhausted());
    $result = $policy->isRequestAllowed();
    $this->assertTrue($result['allowed']);
  }

  /**
   * Tests monthly budget resets on a new month.
   */
  public function testMonthlyBudgetResetsOnNewMonth(): void {
    $policy = $this->buildPolicy(configOverrides: [
      'cost_control.monthly_call_limit' => 2,
    ]);

    $policy->recordCall();
    $policy->recordCall();
    $this->assertTrue($policy->isMonthlyBudgetExhausted());

    // Backdate to previous month.
    $this->storedState[CostControlPolicy::STATE_KEY_MONTHLY] = [
      'count' => 2,
      'month' => date('Y-m', strtotime('first day of last month')),
    ];

    $this->assertFalse($policy->isMonthlyBudgetExhausted());
  }

  /**
   * Tests zero limit disables budget check.
   */
  public function testZeroLimitDisablesBudgetCheck(): void {
    $policy = $this->buildPolicy(configOverrides: [
      'cost_control.daily_call_limit' => 0,
      'cost_control.monthly_call_limit' => 0,
    ]);

    for ($i = 0; $i < 100; $i++) {
      $policy->recordCall();
    }

    $this->assertFalse($policy->isDailyBudgetExhausted());
    $this->assertFalse($policy->isMonthlyBudgetExhausted());
    $result = $policy->isRequestAllowed();
    $this->assertTrue($result['allowed']);
  }

  /**
   * Tests sampling gate blocks at zero rate.
   */
  public function testSamplingGateBlocksAtZeroRate(): void {
    $policy = $this->buildPolicy(configOverrides: [
      'cost_control.sample_rate' => 0.0,
    ]);

    for ($i = 0; $i < 20; $i++) {
      $this->assertFalse($policy->passesSamplingGate());
    }
  }

  /**
   * Tests sampling gate passes at full rate.
   */
  public function testSamplingGatePassesAtFullRate(): void {
    $policy = $this->buildPolicy(configOverrides: [
      'cost_control.sample_rate' => 1.0,
    ]);

    for ($i = 0; $i < 20; $i++) {
      $this->assertTrue($policy->passesSamplingGate());
    }
  }

  /**
   * Tests budget threshold logs at 80% of daily limit.
   */
  public function testBudgetThresholdLogsAt80Percent(): void {
    $policy = $this->buildPolicy(configOverrides: [
      'cost_control.daily_call_limit' => 5,
    ]);

    // 80% of 5 = ceil(4.0) = 4.
    for ($i = 0; $i < 4; $i++) {
      $policy->recordCall();
    }

    $this->assertLogContains('notice', '80%');
  }

  /**
   * Tests budget threshold logs at 100% of daily limit.
   */
  public function testBudgetThresholdLogsAt100Percent(): void {
    $policy = $this->buildPolicy(configOverrides: [
      'cost_control.daily_call_limit' => 3,
    ]);

    for ($i = 0; $i < 3; $i++) {
      $policy->recordCall();
    }

    $this->assertLogContains('warning', 'exhausted');
  }

  // ---------------------------------------------------------------
  // AC-2: Cost-per-request dashboard operational.
  // ---------------------------------------------------------------

  /**
   * Tests cost estimation calculation.
   */
  public function testEstimateCostCalculation(): void {
    $policy = $this->buildPolicy(configOverrides: [
      'cost_control.pricing.input_per_1m_tokens' => 0.075,
      'cost_control.pricing.output_per_1m_tokens' => 0.30,
    ]);

    $cost = $policy->estimateCost(['input' => 1_000_000, 'output' => 100_000]);
    // 1M input * 0.075/1M = 0.075; 100K output * 0.30/1M = 0.03.
    $this->assertEqualsWithDelta(0.105, $cost, 0.0001);
  }

  /**
   * Tests getSummary contains all required fields.
   */
  public function testGetSummaryContainsAllFields(): void {
    $policy = $this->buildPolicy();
    $summary = $policy->getSummary();

    $requiredKeys = [
      'daily_calls',
      'daily_limit',
      'monthly_calls',
      'monthly_limit',
      'cache_hits',
      'cache_misses',
      'cache_requests',
      'cache_hit_rate',
      'cache_hit_rate_target',
      'kill_switch_active',
      'sample_rate',
      'per_ip_hourly_call_limit',
      'per_ip_window_seconds',
    ];
    foreach ($requiredKeys as $key) {
      $this->assertArrayHasKey($key, $summary, "Summary missing key: $key");
    }
  }

  /**
   * Tests cache hit rate calculation.
   */
  public function testCacheHitRateCalculation(): void {
    $policy = $this->buildPolicy();

    for ($i = 0; $i < 7; $i++) {
      $policy->recordCacheHit();
    }
    for ($i = 0; $i < 3; $i++) {
      $policy->recordCacheMiss();
    }

    $this->assertEqualsWithDelta(0.7, $policy->getCacheHitRate(), 0.001);
  }

  /**
   * Tests cache hit rate healthy above target.
   */
  public function testCacheHitRateHealthyAboveTarget(): void {
    $policy = $this->buildPolicy(configOverrides: [
      'cost_control.cache_hit_rate_target' => 0.3,
    ]);

    // 5 hits, 5 misses = 0.5 > 0.3 target.
    for ($i = 0; $i < 5; $i++) {
      $policy->recordCacheHit();
    }
    for ($i = 0; $i < 5; $i++) {
      $policy->recordCacheMiss();
    }

    $this->assertTrue($policy->isCacheHitRateHealthy());
  }

  /**
   * Tests cache hit rate degraded below target logs warning.
   */
  public function testCacheHitRateDegradedBelowTarget(): void {
    $policy = $this->buildPolicy(configOverrides: [
      'cost_control.cache_hit_rate_target' => 0.3,
    ]);

    // 2 hits, 8 misses = 0.2 < 0.3 target.
    for ($i = 0; $i < 2; $i++) {
      $policy->recordCacheHit();
    }
    for ($i = 0; $i < 8; $i++) {
      $policy->recordCacheMiss();
    }

    $this->assertFalse($policy->isCacheHitRateHealthy());
    $this->assertLogContains('warning', 'degraded');
  }

  // ---------------------------------------------------------------
  // AC-3: Kill-switch tabletop drill.
  // ---------------------------------------------------------------

  /**
   * Tests manual kill switch blocks requests.
   */
  public function testManualKillSwitchBlocksRequests(): void {
    $policy = $this->buildPolicy();

    $policy->activateKillSwitch();
    $result = $policy->isRequestAllowed();
    $this->assertFalse($result['allowed']);
    $this->assertEquals('manual_kill_switch', $result['reason']);
  }

  /**
   * Tests kill switch deactivation allows requests.
   */
  public function testKillSwitchDeactivationAllowsRequests(): void {
    $policy = $this->buildPolicy();

    $policy->activateKillSwitch();
    $this->assertFalse($policy->isRequestAllowed()['allowed']);

    $policy->deactivateKillSwitch();
    $this->assertTrue($policy->isRequestAllowed()['allowed']);
  }

  /**
   * Tests evaluateKillSwitch aggregates multiple reasons.
   */
  public function testEvaluateKillSwitchAggregatesReasons(): void {
    $policy = $this->buildPolicy(
      configOverrides: ['cost_control.daily_call_limit' => 1],
      circuitBreakerAvailable: FALSE,
    );

    $policy->activateKillSwitch();
    $policy->recordCall();

    $result = $policy->evaluateKillSwitch();
    $this->assertTrue($result['killed']);
    $this->assertContains('manual_kill_switch', $result['reasons']);
    $this->assertContains('circuit_breaker_open', $result['reasons']);
    $this->assertContains('daily_budget_exhausted', $result['reasons']);
  }

  /**
   * Tests isRequestAllowed checks full chain ordering.
   */
  public function testIsRequestAllowedChecksFullChain(): void {
    // Kill switch should be checked first, even with other failures.
    $policy = $this->buildPolicy(
      configOverrides: ['cost_control.daily_call_limit' => 1],
      circuitBreakerAvailable: FALSE,
      rateLimiterAllowed: FALSE,
    );

    $policy->activateKillSwitch();
    $policy->recordCall();

    $result = $policy->isRequestAllowed();
    $this->assertFalse($result['allowed']);
    // Kill switch is checked first in the chain.
    $this->assertEquals('manual_kill_switch', $result['reason']);
  }

  /**
   * Tests recordCall increments both daily and monthly counters.
   */
  public function testRecordCallIncrementsBothCounters(): void {
    $policy = $this->buildPolicy();

    $policy->recordCall();

    $summary = $policy->getSummary();
    $this->assertEquals(1, $summary['daily_calls']);
    $this->assertEquals(1, $summary['monthly_calls']);
  }

  /**
   * Tests that beginRequest spends daily/monthly budget on admission.
   */
  public function testBeginRequestReservesBudgetOnAdmission(): void {
    $policy = $this->buildPolicy(configOverrides: [
      'cost_control.daily_call_limit' => 1,
      'cost_control.monthly_call_limit' => 1,
      'llm.global_rate_limit.max_per_hour' => 0,
    ]);

    $this->assertSame(['allowed' => TRUE, 'reason' => 'allowed'], $policy->beginRequest());
    $this->assertSame(1, $policy->getSummary()['daily_calls']);
    $this->assertSame(1, $policy->getSummary()['monthly_calls']);

    $result = $policy->beginRequest();
    $this->assertFalse($result['allowed']);
    $this->assertSame('daily_budget_exhausted', $result['reason']);
  }

  /**
   * Tests that beginRequest spends the rate-limit slot on admission.
   */
  public function testBeginRequestReservesRateLimitOnAdmission(): void {
    $policy = $this->buildPolicy(configOverrides: [
      'llm.global_rate_limit.max_per_hour' => 1,
    ]);

    $this->assertTrue($policy->beginRequest()['allowed']);

    $result = $policy->beginRequest();
    $this->assertFalse($result['allowed']);
    $this->assertSame('rate_limit_exceeded', $result['reason']);
    $this->assertSame(1, $this->storedState[LlmRateLimiter::STATE_KEY]['count']);
  }

  /**
   * Tests per-IP budget blocks after the configured identity limit is reached.
   */
  public function testPerIpBudgetBlocksWhenIdentityLimitReached(): void {
    $policy = $this->buildPolicy(configOverrides: [
      'cost_control.per_ip_hourly_call_limit' => 1,
      'cost_control.per_ip_window_seconds' => 3600,
      'llm.global_rate_limit.max_per_hour' => 0,
    ]);

    $identity = '198.51.100.10';
    $this->assertSame(['allowed' => TRUE, 'reason' => 'allowed'], $policy->beginRequest($identity));

    $result = $policy->beginRequest($identity);
    $this->assertFalse($result['allowed']);
    $this->assertSame('per_ip_budget_exceeded', $result['reason']);

    $stored = $this->storedState[CostControlPolicy::STATE_KEY_PER_IP] ?? [];
    $hashedIdentity = CostControlPolicy::hashBudgetIdentity($identity);
    $this->assertArrayHasKey($hashedIdentity, $stored);
    $this->assertArrayNotHasKey($identity, $stored, 'Raw identity must not be persisted.');
  }

  /**
   * Tests per-IP budgets isolate separate identities.
   */
  public function testPerIpBudgetIsScopedPerIdentity(): void {
    $policy = $this->buildPolicy(configOverrides: [
      'cost_control.per_ip_hourly_call_limit' => 1,
      'llm.global_rate_limit.max_per_hour' => 0,
    ]);

    $this->assertTrue($policy->beginRequest('198.51.100.10')['allowed']);
    $this->assertTrue($policy->beginRequest('198.51.100.11')['allowed']);
  }

  /**
   * Tests zero per-IP limit disables granular identity enforcement.
   */
  public function testPerIpBudgetCanBeDisabled(): void {
    $policy = $this->buildPolicy(configOverrides: [
      'cost_control.per_ip_hourly_call_limit' => 0,
      'llm.global_rate_limit.max_per_hour' => 0,
    ]);

    $this->assertTrue($policy->beginRequest('198.51.100.10')['allowed']);
    $this->assertTrue($policy->beginRequest('198.51.100.10')['allowed']);
    $this->assertArrayNotHasKey(CostControlPolicy::STATE_KEY_PER_IP, $this->storedState);
  }

  /**
   * Tests reset clears all state.
   */
  public function testResetClearsAllState(): void {
    $policy = $this->buildPolicy(configOverrides: [
      'llm.global_rate_limit.max_per_hour' => 0,
    ]);

    $policy->recordCall();
    $policy->recordCall();
    $policy->recordCacheHit();
    $policy->beginRequest('198.51.100.10');
    $policy->activateKillSwitch();

    $policy->reset();

    $summary = $policy->getSummary();
    $this->assertEquals(0, $summary['daily_calls']);
    $this->assertEquals(0, $summary['monthly_calls']);
    $this->assertNull($summary['cache_hit_rate']);
    $this->assertFalse($summary['kill_switch_active']);
    $this->assertArrayHasKey(CostControlPolicy::STATE_KEY_PER_IP, $this->storedState);
    $this->assertNull($this->storedState[CostControlPolicy::STATE_KEY_PER_IP]);
  }

  // ---------------------------------------------------------------
  // Test helpers.
  // ---------------------------------------------------------------

  /**
   * Builds a CostControlPolicy with mocked dependencies.
   */
  private function buildPolicy(
    array $configOverrides = [],
    bool $circuitBreakerAvailable = TRUE,
    bool $rateLimiterAllowed = TRUE,
  ): CostControlPolicy {
    $this->storedState = [];
    $this->logMessages = [];

    $state = $this->createMock(StateInterface::class);
    $state->method('get')
      ->willReturnCallback(fn($key, $default = NULL) => $this->storedState[$key] ?? $default);
    $state->method('set')
      ->willReturnCallback(function ($key, $value) {
        $this->storedState[$key] = $value;
      });

    $configValues = [
      'cost_control.daily_call_limit' => 5000,
      'cost_control.monthly_call_limit' => 100000,
      'cost_control.per_ip_hourly_call_limit' => 10,
      'cost_control.per_ip_window_seconds' => 3600,
      'cost_control.sample_rate' => 1.0,
      'cost_control.cache_hit_rate_target' => 0.30,
      'cost_control.cache_stats_window_seconds' => 86400,
      'cost_control.manual_kill_switch' => FALSE,
      'cost_control.pricing.input_per_1m_tokens' => 0.075,
      'cost_control.pricing.output_per_1m_tokens' => 0.30,
      'cost_control.alert_cooldown_minutes' => 60,
      'llm.global_rate_limit.max_per_hour' => 500,
      'llm.global_rate_limit.window_seconds' => 3600,
      'llm.circuit_breaker.failure_threshold' => 3,
      'llm.circuit_breaker.failure_window_seconds' => 60,
      'llm.circuit_breaker.cooldown_seconds' => 300,
    ];
    foreach ($configOverrides as $key => $value) {
      $configValues[$key] = $value;
    }

    $config = $this->createStub(ImmutableConfig::class);
    $config->method('get')
      ->willReturnCallback(fn($key) => $configValues[$key] ?? NULL);

    $configFactory = $this->createStub(ConfigFactoryInterface::class);
    $configFactory->method('get')->willReturn($config);

    $logger = $this->createMock(LoggerInterface::class);
    foreach (['warning', 'notice', 'info'] as $level) {
      $logger->method($level)
        ->willReturnCallback(function ($message) use ($level) {
          $this->logMessages[] = ['level' => $level, 'message' => $message];
        });
    }

    $circuitBreaker = $this->createMock(LlmCircuitBreaker::class);
    $circuitBreaker->method('isAvailable')->willReturn($circuitBreakerAvailable);

    $rateLimiter = $this->createMock(LlmRateLimiter::class);
    $rateLimiter->method('isAllowed')->willReturn($rateLimiterAllowed);

    $coordinator = new LlmAdmissionCoordinator($state, $configFactory, $logger, new NullLockBackend());

    return new CostControlPolicy($state, $configFactory, $logger, $circuitBreaker, $rateLimiter, $coordinator);
  }

  /**
   * Asserts that a log message at the given level contains the expected text.
   */
  private function assertLogContains(string $level, string $needle): void {
    foreach ($this->logMessages as $log) {
      if ($log['level'] === $level && stripos($log['message'], $needle) !== FALSE) {
        $this->addToAssertionCount(1);
        return;
      }
    }
    $logged = array_map(fn($l) => "[{$l['level']}] {$l['message']}", $this->logMessages);
    $this->fail("Expected a '$level' log containing '$needle'. Logged: " . implode('; ', $logged));
  }

}
