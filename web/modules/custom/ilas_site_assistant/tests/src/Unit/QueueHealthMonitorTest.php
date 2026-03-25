<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Queue\QueueInterface;
use Drupal\ilas_site_assistant\Service\QueueHealthMonitor;
use Drupal\ilas_site_assistant\Service\SloDefinitions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for QueueHealthMonitor.
 */
#[CoversClass(QueueHealthMonitor::class)]
#[Group('ilas_site_assistant')]
class QueueHealthMonitorTest extends TestCase {

  /**
   * In-memory state store.
   *
   * @var array
   */
  private array $stateStore = [];

  /**
   * Builds a mock StateInterface.
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

    $state->method('delete')
      ->willReturnCallback(function (string $key) {
        unset($this->stateStore[$key]);
      });

    return $state;
  }

  /**
   * Builds a mock QueueFactory returning a queue with given depth.
   */
  private function buildQueueFactory(int $depth): QueueFactory {
    $queue = $this->createMock(QueueInterface::class);
    $queue->method('numberOfItems')->willReturn($depth);

    $factory = $this->createMock(QueueFactory::class);
    $factory->method('get')
      ->with(QueueHealthMonitor::QUEUE_NAME)
      ->willReturn($queue);

    return $factory;
  }

  /**
   * Builds a SloDefinitions with given overrides.
   */
  private function buildSlo(array $overrides = []): SloDefinitions {
    $config = $this->createStub(ImmutableConfig::class);
    $config->method('get')
      ->willReturnCallback(function ($key) use ($overrides) {
        if (str_starts_with($key, 'slo.')) {
          $sloKey = substr($key, 4);
          return $overrides[$sloKey] ?? NULL;
        }
        return NULL;
      });

    $configFactory = $this->createStub(ConfigFactoryInterface::class);
    $configFactory->method('get')->willReturn($config);

    return new SloDefinitions($configFactory);
  }

  /**
   * Tests healthy status when queue depth is low.
   */
  public function testHealthyWhenDepthBelowThreshold(): void {
    $factory = $this->buildQueueFactory(100);
    $state = $this->buildState();
    $monitor = new QueueHealthMonitor($factory, $state);
    $slo = $this->buildSlo(['queue_max_depth' => 10000]);

    $status = $monitor->getQueueHealthStatus($slo);

    $this->assertSame('healthy', $status['status']);
    $this->assertSame(100, $status['depth']);
    $this->assertSame(10000, $status['max_depth']);
    $this->assertSame(1.0, $status['utilization_pct']);
  }

  /**
   * Tests backlogged status when depth exceeds 80% threshold.
   */
  public function testBackloggedWhenDepthExceedsThreshold(): void {
    // 8001 out of 10000 is 80.01% — exceeds 80% threshold.
    $factory = $this->buildQueueFactory(8001);
    $state = $this->buildState();
    $monitor = new QueueHealthMonitor($factory, $state);
    $slo = $this->buildSlo(['queue_max_depth' => 10000]);

    $status = $monitor->getQueueHealthStatus($slo);

    $this->assertSame('backlogged', $status['status']);
    $this->assertSame(8001, $status['depth']);
    $this->assertSame(80.01, $status['utilization_pct']);
  }

  /**
   * Tests healthy at exactly 80% threshold (boundary).
   */
  public function testHealthyAtExactThreshold(): void {
    // 8000 out of 10000 is exactly 80% — not exceeding.
    $factory = $this->buildQueueFactory(8000);
    $state = $this->buildState();
    $monitor = new QueueHealthMonitor($factory, $state);
    $slo = $this->buildSlo(['queue_max_depth' => 10000]);

    $status = $monitor->getQueueHealthStatus($slo);

    $this->assertSame('healthy', $status['status']);
  }

  /**
   * Tests that recordDrain increments the total count.
   */
  public function testRecordDrainIncrements(): void {
    $factory = $this->buildQueueFactory(0);
    $state = $this->buildState();
    $monitor = new QueueHealthMonitor($factory, $state);

    $this->assertSame(0, $monitor->getTotalDrained());

    $monitor->recordDrain(1);
    $this->assertSame(1, $monitor->getTotalDrained());

    $monitor->recordDrain(5);
    $this->assertSame(6, $monitor->getTotalDrained());
  }

  /**
   * Tests export outcomes track counters and last-outcome metadata.
   */
  public function testRecordOutcomeTracksCountersAndLastOutcome(): void {
    $factory = $this->buildQueueFactory(0);
    $state = $this->buildState();
    $monitor = new QueueHealthMonitor($factory, $state);

    $monitor->recordOutcome('send_partial_207', [
      'http_status' => 207,
      'event_count' => 3,
      'success_count' => 3,
      'error_count' => 0,
    ]);
    $monitor->recordOutcome('send_partial_207', [
      'http_status' => 207,
      'event_count' => 9,
      'success_count' => 8,
      'error_count' => 1,
    ]);

    $counters = $monitor->getOutcomeCounters();
    $this->assertSame(2, $counters['send_partial_207']);
    $this->assertSame(0, $counters['drop_max_depth']);

    $totals = $monitor->getOutcomeTotals();
    $this->assertSame(12, $totals['send_partial_207']['event_count']);
    $this->assertSame(11, $totals['send_partial_207']['success_count']);
    $this->assertSame(1, $totals['send_partial_207']['error_count']);
    $this->assertSame(1, $totals['send_partial_207']['lost_event_count']);

    $summary = $monitor->getExportOutcomeSummary();
    $this->assertTrue($summary['action_required']);
    $this->assertSame('alertable_loss', $summary['policies']['send_partial_207']['classification']);
    $this->assertSame(2, $summary['alertable_loss_totals']['occurrences']);
    $this->assertSame(1, $summary['alertable_loss_totals']['event_count']);

    $lastOutcome = $monitor->getLastOutcome();
    $this->assertNotNull($lastOutcome);
    $this->assertSame('send_partial_207', $lastOutcome['outcome']);
    $this->assertSame(207, $lastOutcome['http_status']);
    $this->assertSame(9, $lastOutcome['event_count']);
    $this->assertSame(1, $lastOutcome['error_count']);
    $this->assertTrue($lastOutcome['actionable']);
    $this->assertArrayHasKey('recorded_at', $lastOutcome);
  }

  /**
   * Tests unknown outcomes are ignored.
   */
  public function testUnknownOutcomeIgnored(): void {
    $factory = $this->buildQueueFactory(0);
    $state = $this->buildState();
    $monitor = new QueueHealthMonitor($factory, $state);

    $monitor->recordOutcome('unknown_outcome', ['http_status' => 200]);

    foreach ($monitor->getOutcomeCounters() as $count) {
      $this->assertSame(0, $count);
    }
    $this->assertNull($monitor->getLastOutcome());
  }

  /**
   * Tests informational loss remains visible without triggering action_required.
   */
  public function testInformationalLossIsTrackedWithoutActionRequired(): void {
    $factory = $this->buildQueueFactory(0);
    $state = $this->buildState();
    $monitor = new QueueHealthMonitor($factory, $state);

    $monitor->recordOutcome('discard_disabled', [
      'event_count' => 4,
    ]);

    $summary = $monitor->getExportOutcomeSummary();
    $this->assertFalse($summary['action_required']);
    $this->assertSame(1, $summary['informational_loss_totals']['occurrences']);
    $this->assertSame(4, $summary['informational_loss_totals']['event_count']);
    $this->assertSame(0, $summary['alertable_loss_totals']['occurrences']);
  }

  /**
   * Tests alertable enqueue failures are exposed through the actionable map.
   */
  public function testDropEnqueueFailureIsActionable(): void {
    $factory = $this->buildQueueFactory(0);
    $state = $this->buildState();
    $monitor = new QueueHealthMonitor($factory, $state);

    $monitor->recordOutcome('drop_enqueue_failure', [
      'event_count' => 2,
      'flush_stage' => 'terminate',
    ]);

    $actionable = $monitor->getActionableLossOutcomes();
    $this->assertArrayHasKey('drop_enqueue_failure', $actionable);
    $this->assertSame(1, $actionable['drop_enqueue_failure']['occurrences']);
    $this->assertSame(2, $actionable['drop_enqueue_failure']['event_count']);
  }

  /**
   * Tests stale status when oldest queue item age exceeds SLO max age.
   */
  public function testStaleWhenOldestAgeExceedsThreshold(): void {
    $factory = $this->buildQueueFactory(100);
    $state = $this->buildState();
    $monitor = new QueueHealthMonitor($factory, $state);
    $monitor->recordEnqueue(time() - 4000, 0);
    $slo = $this->buildSlo([
      'queue_max_depth' => 10000,
      'queue_max_age_seconds' => 3600,
    ]);

    $status = $monitor->getQueueHealthStatus($slo);

    $this->assertSame('stale', $status['status']);
    $this->assertNotNull($status['oldest_item_age_seconds']);
    $this->assertGreaterThan(3600, $status['oldest_item_age_seconds']);
    $this->assertSame(3600, $status['max_age_seconds']);
  }

  /**
   * Tests combined backlogged + stale status.
   */
  public function testBackloggedStaleStatus(): void {
    $factory = $this->buildQueueFactory(9000);
    $state = $this->buildState();
    $monitor = new QueueHealthMonitor($factory, $state);
    $monitor->recordEnqueue(time() - 5000, 0);
    $slo = $this->buildSlo([
      'queue_max_depth' => 10000,
      'queue_max_age_seconds' => 3600,
    ]);

    $status = $monitor->getQueueHealthStatus($slo);

    $this->assertSame('backlogged_stale', $status['status']);
  }

  /**
   * Tests enqueue tracking initializes oldest timestamp only when queue empty.
   */
  public function testRecordEnqueueTracksOldestTimestamp(): void {
    $factory = $this->buildQueueFactory(1);
    $state = $this->buildState();
    $monitor = new QueueHealthMonitor($factory, $state);

    $first = time() - 120;
    $second = time() - 30;

    $monitor->recordEnqueue($first, 0);
    $monitor->recordEnqueue($second, 2);

    $this->assertSame($first, $monitor->getOldestEnqueuedAt());
  }

  /**
   * Tests recordDrain clears oldest timestamp when one item remains in-flight.
   *
   * When called from processItem(), the current item is still claimed and
   * counted by numberOfItems(). If only 1 item remains, it is the one being
   * processed and the queue will be empty after deleteItem().
   */
  public function testRecordDrainClearsStateWhenLastItemInFlight(): void {
    // numberOfItems() returns 1 — the item currently being processed.
    $factory = $this->buildQueueFactory(1);
    $state = $this->buildState();
    $monitor = new QueueHealthMonitor($factory, $state);

    $monitor->recordEnqueue(time() - 500, 0);
    $this->assertNotNull($monitor->getOldestEnqueuedAt());

    $monitor->recordDrain(1);
    $this->assertNull($monitor->getOldestEnqueuedAt());
  }

  /**
   * Tests getQueueHealthStatus self-heals stale state when queue is empty.
   *
   * If oldest_enqueued_at is set but the queue is empty, the state is residual
   * from a prior drain cycle and should be cleared automatically.
   */
  public function testHealthStatusSelfHealsWhenQueueEmpty(): void {
    $factory = $this->buildQueueFactory(0);
    $state = $this->buildState();
    $monitor = new QueueHealthMonitor($factory, $state);

    // Simulate residual state: oldest_enqueued_at set but queue is empty.
    $monitor->recordEnqueue(time() - 70000, 0);
    $this->assertNotNull($monitor->getOldestEnqueuedAt());

    $slo = $this->buildSlo([
      'queue_max_depth' => 10000,
      'queue_max_age_seconds' => 3600,
    ]);

    $status = $monitor->getQueueHealthStatus($slo);

    $this->assertSame('healthy', $status['status']);
    $this->assertSame(0, $status['depth']);
    $this->assertNull($status['oldest_item_age_seconds']);
    $this->assertNull($monitor->getOldestEnqueuedAt());
  }

}
