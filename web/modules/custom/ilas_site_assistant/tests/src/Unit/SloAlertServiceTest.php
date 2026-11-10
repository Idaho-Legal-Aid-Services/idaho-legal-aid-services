<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use Drupal\Core\State\StateInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Queue\QueueInterface;
use Drupal\ilas_site_assistant\Service\CronHealthTracker;
use Drupal\ilas_site_assistant\Service\PerformanceMonitor;
use Drupal\ilas_site_assistant\Service\QueueHealthMonitor;
use Drupal\ilas_site_assistant\Service\SloAlertService;
use Drupal\ilas_site_assistant\Service\SloDefinitions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for SloAlertService.
 */
#[CoversClass(SloAlertService::class)]
#[Group('ilas_site_assistant')]
class SloAlertServiceTest extends TestCase {

  /**
   * In-memory state store.
   *
   * @var array
   */
  private array $stateStore = [];

  /**
   * Builds a mock StateInterface backed by the in-memory store.
   */
  private function buildState(): StateInterface {
    $state = $this->createMock(StateInterface::class);

    $state->method('get')
      ->willReturnCallback(function (string $key, $default = NULL) {
        return $this->stateStore[$key] ?? $default;
      });

    $state->method('set')
      ->willReturnCallback(function (string $key, $value) {
        $this->stateStore[$key] = $value;
      });

    $state->method('setMultiple')
      ->willReturnCallback(function (array $data) {
        foreach ($data as $key => $value) {
          $this->stateStore[$key] = $value;
        }
      });

    return $state;
  }

  /**
   * Builds a SloDefinitions with default thresholds.
   */
  private function buildSlo(): SloDefinitions {
    $config = $this->createStub(ImmutableConfig::class);
    $config->method('get')->willReturn(NULL);

    $configFactory = $this->createStub(ConfigFactoryInterface::class);
    $configFactory->method('get')->willReturn($config);

    return new SloDefinitions($configFactory);
  }

  /**
   * Builds a mock PerformanceMonitor with given summary values.
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
   * Builds a PerformanceMonitor whose whole window and SLO sample differ.
   *
   * The whole window always looks broken (P95 9000 ms, 50% errors), so a
   * check that passes proves the service judged the `slo` sample.
   *
   * @param \Closure $slo
   *   Returns the current [p95, error_rate, sample_size] of the SLO sample.
   */
  private function buildSplitPerformanceMonitor(\Closure $slo): PerformanceMonitor {
    $monitor = $this->createMock(PerformanceMonitor::class);
    $monitor->method('getSummary')
      ->willReturnCallback(static function () use ($slo): array {
        [$p95, $errorRate, $sampleSize] = $slo();
        return [
          'p95' => 9000.0,
          'error_rate' => 50.0,
          'sample_size' => 1000,
          'slo' => [
            'p95' => $p95,
            'error_rate' => $errorRate,
            'sample_size' => $sampleSize,
            'sufficient_sample' => $sampleSize >= PerformanceMonitor::MIN_SLO_SAMPLE_SIZE,
          ],
        ];
      });

    return $monitor;
  }

  /**
   * Builds a CronHealthTracker that will report the given status.
   */
  private function buildCronTracker(string $cronStatus): CronHealthTracker {
    $state = $this->buildState();
    $tracker = $this->createMock(CronHealthTracker::class);

    $tracker->method('getHealthStatus')
      ->willReturn([
        'status' => $cronStatus,
        'age' => $cronStatus === 'healthy' ? 100 : 10000,
        'last_run' => time() - 100,
        'duration_ms' => 50.0,
        'consecutive_failures' => $cronStatus === 'failing' ? 3 : 0,
      ]);

    return $tracker;
  }

  /**
   * Builds a QueueHealthMonitor that will report the given status.
   */
  private function buildQueueMonitor(string $queueStatus, array $actionableLosses = []): QueueHealthMonitor {
    $monitor = $this->createMock(QueueHealthMonitor::class);

    $monitor->method('getQueueHealthStatus')
      ->willReturn([
        'status' => $queueStatus,
        'depth' => $queueStatus === 'backlogged' ? 9000 : 100,
        'max_depth' => 10000,
        'utilization_pct' => $queueStatus === 'backlogged' ? 90.0 : 1.0,
      ]);
    $monitor->method('getActionableLossOutcomes')
      ->willReturn($actionableLosses);

    return $monitor;
  }

  /**
   * Returns a matcher callback for @slo_dimension warning context.
   */
  private function hasSloDimension(string $dimension): \Closure {
    return static function ($context) use ($dimension): bool {
      return is_array($context) && ($context['@slo_dimension'] ?? NULL) === $dimension;
    };
  }

  /**
   * Tests that checkLatencySlo fires a warning when P95 exceeds target.
   */
  public function testLatencySloViolationFiresWarning(): void {
    $slo = $this->buildSlo();
    $state = $this->buildState();
    // P95 of 3000ms exceeds default target of 2000ms.
    $perfMonitor = $this->buildPerformanceMonitor(3000, 1.0);

    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->once())
      ->method('warning')
      ->with(
        $this->stringContains('P95 latency'),
        $this->callback($this->hasSloDimension('latency'))
      );

    $alert = new SloAlertService($slo, $logger, $state, $perfMonitor);
    $alert->checkLatencySlo();
  }

  /**
   * Tests that checkAvailabilitySlo fires when availability is below target.
   */
  public function testAvailabilitySloViolationFiresWarning(): void {
    $slo = $this->buildSlo();
    $state = $this->buildState();
    // Error rate 1% => 99% availability, below default 99.5 target.
    $perfMonitor = $this->buildPerformanceMonitor(500, 1.0);

    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->once())
      ->method('warning')
      ->with(
        $this->stringContains('availability'),
        $this->callback($this->hasSloDimension('availability'))
      );

    $alert = new SloAlertService($slo, $logger, $state, $perfMonitor);
    $alert->checkAvailabilitySlo();
  }

  /**
   * Tests that checkAvailabilitySlo does not fire when target is met.
   */
  public function testAvailabilitySloHealthyNoWarning(): void {
    $slo = $this->buildSlo();
    $state = $this->buildState();
    // Error rate 0.1% => 99.9% availability (healthy).
    $perfMonitor = $this->buildPerformanceMonitor(500, 0.1);

    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->never())->method('warning');

    $alert = new SloAlertService($slo, $logger, $state, $perfMonitor);
    $alert->checkAvailabilitySlo();
  }

  /**
   * Tests that checkLatencySlo does NOT fire when within target.
   */
  public function testLatencySloHealthyNoWarning(): void {
    $slo = $this->buildSlo();
    $state = $this->buildState();
    // P95 of 500ms is well within default 2000ms target.
    $perfMonitor = $this->buildPerformanceMonitor(500, 1.0);

    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->never())->method('warning');

    $alert = new SloAlertService($slo, $logger, $state, $perfMonitor);
    $alert->checkLatencySlo();
  }

  /**
   * Tests that cooldown suppresses duplicate alerts.
   */
  public function testCooldownSuppressesDuplicateAlert(): void {
    $slo = $this->buildSlo();
    $state = $this->buildState();
    $perfMonitor = $this->buildPerformanceMonitor(3000, 1.0);

    $logger = $this->createMock(LoggerInterface::class);
    // Should fire only once despite two calls.
    $logger->expects($this->once())->method('warning');

    $alert = new SloAlertService($slo, $logger, $state, $perfMonitor);
    $alert->checkLatencySlo();
    // Second call should be suppressed by cooldown.
    $alert->checkLatencySlo();
  }

  /**
   * Tests that the performance SLOs are judged on the SLO sample.
   */
  public function testPerformanceSlosUseSloSampleNotWholeWindow(): void {
    $perfMonitor = $this->buildSplitPerformanceMonitor(static fn(): array => [900.0, 0.0, 80]);

    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->never())->method('warning');

    $alert = new SloAlertService($this->buildSlo(), $logger, $this->buildState(), $perfMonitor);
    $alert->checkLatencySlo();
    $alert->checkAvailabilitySlo();
    $alert->checkErrorRateSlo();
  }

  /**
   * Tests that a thin SLO sample neither alerts nor clears a breach.
   */
  public function testInsufficientSloSampleDoesNotAlertOrRecover(): void {
    $sample = [3000.0, 0.0, 80];
    $perfMonitor = $this->buildSplitPerformanceMonitor(static function () use (&$sample): array {
      return $sample;
    });

    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->once())->method('warning');
    $logger->expects($this->never())->method('notice');

    $alert = new SloAlertService($this->buildSlo(), $logger, $this->buildState(), $perfMonitor);
    $alert->checkLatencySlo();

    // A quiet week: three fast requests are not evidence of recovery.
    $sample = [500.0, 0.0, 3];
    $alert->checkLatencySlo();
    $this->assertTrue($this->stateStore[SloAlertService::STATE_BREACH_PREFIX . 'latency']);

    // And three slow ones are not evidence of a breach.
    $this->stateStore = [];
    $sample = [9000.0, 0.0, 3];
    $alert->checkLatencySlo();
    $this->assertSame([], $this->stateStore);
  }

  /**
   * Tests that a sustained breach is re-reported daily, not on every check.
   */
  public function testSustainedBreachRemindsOncePerDay(): void {
    $state = $this->buildState();
    $perfMonitor = $this->buildPerformanceMonitor(3000, 0.0);

    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->exactly(2))->method('warning');

    $alert = new SloAlertService($this->buildSlo(), $logger, $state, $perfMonitor);
    $alert->checkLatencySlo();

    // An hour later (next cron): past the cooldown, still the same breach.
    $this->stateStore[SloAlertService::STATE_PREFIX . 'latency'] = time() - 3600;
    $alert->checkLatencySlo();

    // A day later: one reminder.
    $this->stateStore[SloAlertService::STATE_PREFIX . 'latency'] = time() - SloAlertService::REMINDER_SECONDS;
    $alert->checkLatencySlo();
  }

  /**
   * Tests that recovery is logged once and a later breach alerts again.
   */
  public function testRecoveryIsLoggedOnceAndRearmsTheAlert(): void {
    $sample = [3000.0, 0.0, 80];
    $perfMonitor = $this->buildSplitPerformanceMonitor(static function () use (&$sample): array {
      return $sample;
    });

    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->exactly(2))
      ->method('warning')
      ->with($this->stringContains('P95 latency'), $this->anything());
    $logger->expects($this->once())
      ->method('notice')
      ->with(
        $this->stringContains('SLO recovered'),
        $this->callback($this->hasSloDimension('latency'))
      );

    $alert = new SloAlertService($this->buildSlo(), $logger, $this->buildState(), $perfMonitor);
    $alert->checkLatencySlo();

    $sample = [1200.0, 0.0, 80];
    $alert->checkLatencySlo();
    $alert->checkLatencySlo();

    // Breaches again after the cooldown has passed.
    $this->stateStore[SloAlertService::STATE_PREFIX . 'latency'] = time() - SloAlertService::COOLDOWN_SECONDS;
    $sample = [3000.0, 0.0, 80];
    $alert->checkLatencySlo();
  }

  /**
   * Tests that checkErrorRateSlo fires a warning when error rate exceeds target.
   */
  public function testErrorRateSloViolation(): void {
    $slo = $this->buildSlo();
    $state = $this->buildState();
    // Error rate 10% exceeds default 5% target.
    $perfMonitor = $this->buildPerformanceMonitor(500, 10.0);

    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->once())
      ->method('warning')
      ->with(
        $this->stringContains('error rate'),
        $this->callback($this->hasSloDimension('error_rate'))
      );

    $alert = new SloAlertService($slo, $logger, $state, $perfMonitor);
    $alert->checkErrorRateSlo();
  }

  /**
   * Tests that checkCronSlo fires a warning when cron is stale.
   */
  public function testCronSloViolation(): void {
    $slo = $this->buildSlo();
    $state = $this->buildState();
    $cronTracker = $this->buildCronTracker('stale');

    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->once())
      ->method('warning')
      ->with(
        $this->stringContains('cron health'),
        $this->callback($this->hasSloDimension('cron'))
      );

    $alert = new SloAlertService($slo, $logger, $state, NULL, $cronTracker);
    $alert->checkCronSlo();
  }

  /**
   * Tests that checkCronSlo does not fire when cron is healthy.
   */
  public function testCronSloHealthyNoWarning(): void {
    $slo = $this->buildSlo();
    $state = $this->buildState();
    $cronTracker = $this->buildCronTracker('healthy');

    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->never())->method('warning');

    $alert = new SloAlertService($slo, $logger, $state, NULL, $cronTracker);
    $alert->checkCronSlo();
  }

  /**
   * Tests that checkQueueSlo fires a warning when queue is backlogged.
   */
  public function testQueueSloViolation(): void {
    $slo = $this->buildSlo();
    $state = $this->buildState();
    $queueMonitor = $this->buildQueueMonitor('backlogged');

    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->once())
      ->method('warning')
      ->with(
        $this->stringContains('queue is'),
        $this->callback($this->hasSloDimension('queue'))
      );

    $alert = new SloAlertService($slo, $logger, $state, NULL, NULL, $queueMonitor);
    $alert->checkQueueSlo();
  }

  /**
   * Tests that checkQueueSlo emits queue-loss warnings for actionable loss.
   */
  public function testQueueLossViolation(): void {
    $slo = $this->buildSlo();
    $state = $this->buildState();
    $queueMonitor = $this->buildQueueMonitor('healthy', [
      'discard_stale' => [
        'occurrences' => 1,
        'queue_items' => 1,
        'event_count' => 3,
        'severity' => 'warning',
      ],
    ]);

    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->once())
      ->method('warning')
      ->with(
        $this->stringContains('queue loss outcome @outcome'),
        $this->callback(function ($context): bool {
          return is_array($context)
            && ($context['@slo_dimension'] ?? NULL) === 'queue_loss'
            && ($context['@outcome'] ?? NULL) === 'discard_stale';
        })
      );

    $alert = new SloAlertService($slo, $logger, $state, NULL, NULL, $queueMonitor);
    $alert->checkQueueSlo();
  }

  /**
   * Tests that checkAll delegates to all individual checks.
   */
  public function testCheckAllDelegation(): void {
    $slo = $this->buildSlo();
    $state = $this->buildState();
    // All healthy — no warnings should fire.
    $perfMonitor = $this->buildPerformanceMonitor(500, 0.1);
    $cronTracker = $this->buildCronTracker('healthy');
    $queueMonitor = $this->buildQueueMonitor('healthy');

    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->never())->method('warning');

    $alert = new SloAlertService($slo, $logger, $state, $perfMonitor, $cronTracker, $queueMonitor);
    $alert->checkAll();
  }

  /**
   * Tests that no alerts fire without optional services.
   */
  public function testNoAlertsWithoutServices(): void {
    $slo = $this->buildSlo();
    $state = $this->buildState();

    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->never())->method('warning');

    // All optional services are NULL.
    $alert = new SloAlertService($slo, $logger, $state);
    $alert->checkAll();
  }

}
