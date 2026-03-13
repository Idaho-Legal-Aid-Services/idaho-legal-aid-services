<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Lock\NullLockBackend;
use Drupal\Core\State\StateInterface;
use Drupal\ilas_site_assistant\Service\CostControlPolicy;
use Drupal\ilas_site_assistant\Service\LlmAdmissionCoordinator;
use Drupal\ilas_site_assistant\Service\LlmCircuitBreaker;
use Drupal\ilas_site_assistant\Service\LlmRateLimiter;
use Drupal\ilas_site_assistant\Service\PerformanceMonitor;
use Drupal\ilas_site_assistant\Service\SloAlertService;
use Drupal\ilas_site_assistant\Service\SloDefinitions;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\LoggerInterface;

/**
 * Behavioral proof for cross-phase dependency row #6 (`XDP-06`).
 */
#[Group('ilas_site_assistant')]
final class CrossPhaseDependencyRowSixBehaviorTest extends BehavioralDependencyGateTestBase {

  /**
   * In-memory state store for policy and SLO checks.
   *
   * @var array<string, mixed>
   */
  private array $stateStore = [];

  /**
   * Cost policy must fail closed when operational guardrails trip.
   */
  public function testCostPolicyBehaviorBlocksWhenBudgetOrRateGuardrailsTrip(): void {
    $healthyPolicy = $this->buildPolicy();
    $this->assertTrue($healthyPolicy->isRequestAllowed()['allowed']);

    $budgetBlockedPolicy = $this->buildPolicy([
      'cost_control.daily_call_limit' => 1,
    ]);
    $budgetBlockedPolicy->recordCall();
    $this->assertSame(
      ['allowed' => FALSE, 'reason' => 'daily_budget_exhausted'],
      $budgetBlockedPolicy->isRequestAllowed(),
    );

    $rateBlockedPolicy = $this->buildPolicy([], TRUE, FALSE);
    $this->assertSame(
      ['allowed' => FALSE, 'reason' => 'rate_limit_exceeded'],
      $rateBlockedPolicy->isRequestAllowed(),
    );
  }

  /**
   * Cost policy must enforce granular per-IP budgets in addition to global caps.
   */
  public function testCostPolicyBehaviorBlocksWhenPerIpBudgetTrips(): void {
    $policy = $this->buildPolicy([
      'cost_control.daily_call_limit' => 0,
      'cost_control.monthly_call_limit' => 0,
      'cost_control.per_ip_hourly_call_limit' => 1,
      'cost_control.per_ip_window_seconds' => 3600,
      'llm.global_rate_limit.max_per_hour' => 0,
    ]);

    $this->assertSame(['allowed' => TRUE, 'reason' => 'allowed'], $policy->beginRequest('198.51.100.10'));
    $this->assertSame(
      ['allowed' => FALSE, 'reason' => 'per_ip_budget_exceeded'],
      $policy->beginRequest('198.51.100.10'),
    );
    $this->assertSame(['allowed' => TRUE, 'reason' => 'allowed'], $policy->beginRequest('198.51.100.11'));
  }

  /**
   * SLO monitoring must emit a violation when latency budget is breached.
   */
  public function testSloMonitoringBehaviorBlocksWhenLatencyBudgetIsBreached(): void {
    $healthyLogger = $this->createMock(LoggerInterface::class);
    $healthyLogger->expects($this->never())->method('warning');

    $healthyService = new SloAlertService(
      $this->buildSloDefinitions(),
      $healthyLogger,
      $this->buildState(),
      $this->buildPerformanceMonitor(500.0, 0.1),
    );
    $healthyService->checkLatencySlo();

    $violatingLogger = $this->createMock(LoggerInterface::class);
    $violatingLogger->expects($this->once())
      ->method('warning')
      ->with($this->stringContains('P95 latency'), $this->anything());

    $violatingService = new SloAlertService(
      $this->buildSloDefinitions(),
      $violatingLogger,
      $this->buildState(),
      $this->buildPerformanceMonitor(3000.0, 0.1),
    );
    $violatingService->checkLatencySlo();
  }

  /**
   * Row #6 closure must remain blocked until config and guardrails are healthy.
   */
  public function testXdp06DependencyClosureBlocksWhenCostGuardrailPrerequisitesFail(): void {
    $install = self::parseYamlFile('web/modules/custom/ilas_site_assistant/config/install/ilas_site_assistant.settings.yml');
    $active = self::parseYamlFile('config/ilas_site_assistant.settings.yml');

    $closed = $this->evaluateDependencyClosure(array_merge(
      $this->evaluateCostConfig($install, $active),
      $this->evaluateCostPolicyPrerequisite($this->buildPolicy()),
      $this->evaluateSloPrerequisite(500.0, 0.1),
    ));

    $this->assertSame('closed', $closed['status']);
    $this->assertSame(0, $closed['unresolved_count']);

    $blocked = $this->evaluateDependencyClosure(array_merge(
      $this->evaluateCostConfig($install, array_diff_key($active, ['cost_control' => TRUE])),
      $this->evaluateCostPolicyPrerequisite($this->buildPolicy(['cost_control.daily_call_limit' => 1], TRUE, FALSE)),
      $this->evaluateSloPrerequisite(3000.0, 0.1),
    ));

    $this->assertSame('blocked', $blocked['status']);
    $this->assertGreaterThan(0, $blocked['unresolved_count']);
  }

  /**
   * Builds an in-memory Drupal state store.
   */
  private function buildState(): StateInterface {
    $state = $this->createMock(StateInterface::class);
    $state->method('get')
      ->willReturnCallback(fn(string $key, $default = NULL) => $this->stateStore[$key] ?? $default);
    $state->method('set')
      ->willReturnCallback(function (string $key, $value): void {
        $this->stateStore[$key] = $value;
      });

    return $state;
  }

  /**
   * Builds a cost policy with configurable circuit/rate guard state.
   */
  private function buildPolicy(
    array $configOverrides = [],
    bool $circuitBreakerAvailable = TRUE,
    bool $rateLimiterAllowed = TRUE,
  ): CostControlPolicy {
    $this->stateStore = [];
    $state = $this->buildState();

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
    ];
    foreach ($configOverrides as $key => $value) {
      $configValues[$key] = $value;
    }

    $config = $this->createStub(ImmutableConfig::class);
    $config->method('get')
      ->willReturnCallback(fn(string $key) => $configValues[$key] ?? NULL);

    $configFactory = $this->createStub(ConfigFactoryInterface::class);
    $configFactory->method('get')->willReturn($config);

    $logger = $this->createMock(LoggerInterface::class);
    $circuitBreaker = $this->createMock(LlmCircuitBreaker::class);
    $circuitBreaker->method('isAvailable')->willReturn($circuitBreakerAvailable);

    $rateLimiter = $this->createMock(LlmRateLimiter::class);
    $rateLimiter->method('isAllowed')->willReturn($rateLimiterAllowed);

    $coordinator = new LlmAdmissionCoordinator($state, $configFactory, $logger, new NullLockBackend());

    return new CostControlPolicy($state, $configFactory, $logger, $circuitBreaker, $rateLimiter, $coordinator);
  }

  /**
   * Builds default SLO definitions.
   */
  private function buildSloDefinitions(): SloDefinitions {
    $config = $this->createStub(ImmutableConfig::class);
    $config->method('get')->willReturn(NULL);

    $configFactory = $this->createStub(ConfigFactoryInterface::class);
    $configFactory->method('get')->willReturn($config);

    return new SloDefinitions($configFactory);
  }

  /**
   * Builds a performance monitor stub with specific summary values.
   */
  private function buildPerformanceMonitor(float $p95, float $errorRate): PerformanceMonitor {
    $monitor = $this->createMock(PerformanceMonitor::class);
    $monitor->method('getSummary')
      ->willReturn([
        'p50' => 100,
        'p95' => $p95,
        'p99' => $p95 * 1.5,
        'avg' => 80,
        'error_rate' => $errorRate,
        'availability_pct' => max(0, 100 - $errorRate),
        'throughput_per_min' => 10,
        'sample_size' => 100,
        'status' => 'healthy',
      ]);

    return $monitor;
  }

  /**
   * Evaluates required cost-control config presence.
   *
   * @return string[]
   *   The unresolved prerequisite failures.
   */
  private function evaluateCostConfig(array $install, array $active): array {
    $failures = [];
    foreach (['cost_control'] as $requiredTopLevelKey) {
      if (!array_key_exists($requiredTopLevelKey, $install)) {
        $failures[] = "install_missing:{$requiredTopLevelKey}";
      }
      if (!array_key_exists($requiredTopLevelKey, $active)) {
        $failures[] = "active_missing:{$requiredTopLevelKey}";
      }
    }

    $requiredCostKeys = [
      'daily_call_limit',
      'monthly_call_limit',
      'per_ip_hourly_call_limit',
      'per_ip_window_seconds',
      'sample_rate',
      'cache_hit_rate_target',
      'cache_stats_window_seconds',
      'manual_kill_switch',
      'pricing',
      'alert_cooldown_minutes',
    ];
    foreach ($requiredCostKeys as $key) {
      if (!array_key_exists($key, $install['cost_control'] ?? [])) {
        $failures[] = "install_missing:cost_control.{$key}";
      }
      if (!array_key_exists($key, $active['cost_control'] ?? [])) {
        $failures[] = "active_missing:cost_control.{$key}";
      }
    }

    sort($failures);
    return $failures;
  }

  /**
   * Evaluates cost-policy behavior for unresolved prerequisite failures.
   *
   * @return string[]
   *   The unresolved prerequisite failures.
   */
  private function evaluateCostPolicyPrerequisite(CostControlPolicy $policy): array {
    $decision = $policy->isRequestAllowed();
    if ($decision['allowed']) {
      return [];
    }

    return ['cost_policy_blocked:' . $decision['reason']];
  }

  /**
   * Evaluates SLO behavior for unresolved prerequisite failures.
   *
   * @return string[]
   *   The unresolved prerequisite failures.
   */
  private function evaluateSloPrerequisite(float $p95, float $errorRate): array {
    $latencyTarget = $this->buildSloDefinitions()->getLatencyP95TargetMs();
    $errorRateTarget = $this->buildSloDefinitions()->getErrorRateTargetPct();
    $failures = [];

    if ($p95 > $latencyTarget) {
      $failures[] = 'slo_violation:latency';
    }
    if ($errorRate > $errorRateTarget) {
      $failures[] = 'slo_violation:error_rate';
    }

    sort($failures);
    return $failures;
  }

  /**
   * Computes row closure status from unresolved prerequisite failures.
   *
   * @param string[] $failures
   *   The unresolved prerequisite failures.
   *
   * @return array{status: string, unresolved_count: int, unresolved: string[]}
   *   Row closure state.
   */
  private function evaluateDependencyClosure(array $failures): array {
    $normalized = array_values(array_unique(array_filter($failures)));
    sort($normalized);

    return [
      'status' => $normalized === [] ? 'closed' : 'blocked',
      'unresolved_count' => count($normalized),
      'unresolved' => $normalized,
    ];
  }

}
