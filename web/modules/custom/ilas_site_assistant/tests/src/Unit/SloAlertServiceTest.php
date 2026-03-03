<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Queue\QueueInterface;
use Drupal\ilas_site_assistant\Service\CronHealthTracker;
use Drupal\ilas_site_assistant\Service\PerformanceMonitor;
use Drupal\ilas_site_assistant\Service\QueueHealthMonitor;
use Drupal\ilas_site_assistant\Service\SloAlertService;
use Drupal\ilas_site_assistant\Service\SloDefinitions;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for SloAlertService.
 *
 * @group ilas_site_assistant
 * @coversDefaultClass \Drupal\ilas_site_assistant\Service\SloAlertService
 */
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
  private function buildState(): \Drupal\Core\State\StateInterface {
    $state = $this->createMock(\Drupal\Core\State\StateInterface::class);

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
  private function buildQueueMonitor(string $queueStatus): QueueHealthMonitor {
    $monitor = $this->createMock(QueueHealthMonitor::class);

    $monitor->method('getQueueHealthStatus')
      ->willReturn([
        'status' => $queueStatus,
        'depth' => $queueStatus === 'backlogged' ? 9000 : 100,
        'max_depth' => 10000,
        'utilization_pct' => $queueStatus === 'backlogged' ? 90.0 : 1.0,
      ]);

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
   *
   * @covers ::checkLatencySlo
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
   *
   * @covers ::checkAvailabilitySlo
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
   *
   * @covers ::checkAvailabilitySlo
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
   *
   * @covers ::checkLatencySlo
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
   *
   * @covers ::checkLatencySlo
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
   * Tests that checkErrorRateSlo fires a warning when error rate exceeds target.
   *
   * @covers ::checkErrorRateSlo
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
   *
   * @covers ::checkCronSlo
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
   *
   * @covers ::checkCronSlo
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
   *
   * @covers ::checkQueueSlo
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
   * Tests that checkAll delegates to all individual checks.
   *
   * @covers ::checkAll
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
   *
   * @covers ::checkAll
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
