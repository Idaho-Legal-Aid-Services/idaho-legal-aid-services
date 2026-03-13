<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\ilas_site_assistant\Service\CronHealthTracker;
use Drupal\ilas_site_assistant\Service\SloDefinitions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for CronHealthTracker.
 */
#[CoversClass(CronHealthTracker::class)]
#[Group('ilas_site_assistant')]
class CronHealthTrackerTest extends TestCase {

  /**
   * In-memory state store for testing.
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
   * Tests that recordRun stores all state keys.
   */
  public function testRecordRunStoresState(): void {
    $state = $this->buildState();
    $tracker = new CronHealthTracker($state);

    $tracker->recordRun(150.5, TRUE);

    $this->assertNotNull($this->stateStore[CronHealthTracker::STATE_LAST_RUN]);
    $this->assertSame(150.5, $this->stateStore[CronHealthTracker::STATE_LAST_DURATION_MS]);
    $this->assertTrue($this->stateStore[CronHealthTracker::STATE_LAST_SUCCESS]);
    $this->assertSame(0, $this->stateStore[CronHealthTracker::STATE_CONSECUTIVE_FAILURES]);
  }

  /**
   * Tests that a failed run increments consecutive failures.
   */
  public function testFailedRunIncrementsFailures(): void {
    $state = $this->buildState();
    $tracker = new CronHealthTracker($state);

    $tracker->recordRun(100.0, FALSE);
    $this->assertSame(1, $this->stateStore[CronHealthTracker::STATE_CONSECUTIVE_FAILURES]);

    $tracker->recordRun(100.0, FALSE);
    $this->assertSame(2, $this->stateStore[CronHealthTracker::STATE_CONSECUTIVE_FAILURES]);
  }

  /**
   * Tests that success resets consecutive failures.
   */
  public function testSuccessResetsFailures(): void {
    $state = $this->buildState();
    $tracker = new CronHealthTracker($state);

    $tracker->recordRun(100.0, FALSE);
    $tracker->recordRun(100.0, FALSE);
    $this->assertSame(2, $this->stateStore[CronHealthTracker::STATE_CONSECUTIVE_FAILURES]);

    $tracker->recordRun(100.0, TRUE);
    $this->assertSame(0, $this->stateStore[CronHealthTracker::STATE_CONSECUTIVE_FAILURES]);
  }

  /**
   * Tests healthy status when cron ran recently and succeeded.
   */
  public function testHealthyStatus(): void {
    $state = $this->buildState();
    $tracker = new CronHealthTracker($state);
    $slo = $this->buildSlo();

    $tracker->recordRun(100.0, TRUE);
    $status = $tracker->getHealthStatus($slo);

    $this->assertSame('healthy', $status['status']);
    $this->assertSame(0, $status['consecutive_failures']);
    $this->assertNotNull($status['last_run']);
    $this->assertSame(100.0, $status['duration_ms']);
  }

  /**
   * Tests stale status when cron has never run.
   */
  public function testStaleStatusNeverRun(): void {
    $state = $this->buildState();
    $tracker = new CronHealthTracker($state);
    $slo = $this->buildSlo();

    $status = $tracker->getHealthStatus($slo);

    $this->assertSame('stale', $status['status']);
    $this->assertNull($status['age']);
    $this->assertNull($status['last_run']);
    $this->assertNull($status['duration_ms']);
  }

  /**
   * Tests stale status when cron ran too long ago.
   */
  public function testStaleStatusOldRun(): void {
    $state = $this->buildState();
    $tracker = new CronHealthTracker($state);
    $slo = $this->buildSlo();

    // Simulate a run 3 hours ago (exceeds default 7200s max age).
    $this->stateStore[CronHealthTracker::STATE_LAST_RUN] = time() - 10800;
    $this->stateStore[CronHealthTracker::STATE_LAST_DURATION_MS] = 100.0;
    $this->stateStore[CronHealthTracker::STATE_LAST_SUCCESS] = TRUE;
    $this->stateStore[CronHealthTracker::STATE_CONSECUTIVE_FAILURES] = 0;

    $status = $tracker->getHealthStatus($slo);

    $this->assertSame('stale', $status['status']);
  }

  /**
   * Tests failing status when there are consecutive failures.
   */
  public function testFailingStatus(): void {
    $state = $this->buildState();
    $tracker = new CronHealthTracker($state);
    $slo = $this->buildSlo();

    $tracker->recordRun(100.0, FALSE);
    $status = $tracker->getHealthStatus($slo);

    $this->assertSame('failing', $status['status']);
    $this->assertSame(1, $status['consecutive_failures']);
  }

}
