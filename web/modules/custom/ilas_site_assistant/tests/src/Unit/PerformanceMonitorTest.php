<?php

namespace Drupal\Tests\ilas_site_assistant\Unit;

use Drupal\ilas_site_assistant\Service\PerformanceMonitor;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests for PerformanceMonitor: single state write, no race, cooldown preserved.
 *
 * @group ilas_site_assistant
 * @coversDefaultClass \Drupal\ilas_site_assistant\Service\PerformanceMonitor
 */
#[Group('ilas_site_assistant')]
class PerformanceMonitorTest extends TestCase {

  /**
   * Tests that recordRequest only writes to state once.
   *
   * Previously checkThresholds() also called state->set(), causing a
   * double-write race where the last_alert cooldown could be lost.
   *
   * @covers ::recordRequest
   */
  public function testSingleStateWrite(): void {
    $state = $this->createMock('Drupal\Core\State\StateInterface');
    $logger = $this->createMock('Drupal\Core\Logger\LoggerChannelInterface');

    // State should be read once (getMetrics) and written once (recordRequest).
    $state->expects($this->once())
      ->method('get')
      ->with(PerformanceMonitor::STATE_KEY, $this->anything())
      ->willReturn([
        'requests' => [],
        'total_requests' => 0,
        'total_errors' => 0,
        'last_alert' => 0,
      ]);

    $state->expects($this->once())
      ->method('set')
      ->with(PerformanceMonitor::STATE_KEY, $this->anything());

    $monitor = new PerformanceMonitor($state, $logger);
    $monitor->recordRequest(100.0, TRUE, 'retrieval');
  }

  /**
   * Tests that cooldown is preserved through the single state write.
   *
   * When a threshold is exceeded, checkThresholds() sets last_alert on
   * the metrics array by reference. The single state->set() in
   * recordRequest() persists it.
   *
   * @covers ::recordRequest
   */
  public function testCooldownPreservedInSingleWrite(): void {
    $state = $this->createMock('Drupal\Core\State\StateInterface');
    $logger = $this->createMock('Drupal\Core\Logger\LoggerChannelInterface');

    // Build a metrics array with high-latency requests to trigger threshold.
    $requests = [];
    for ($i = 0; $i < 20; $i++) {
      $requests[] = [
        'time' => time() - 10,
        'duration' => 3000.0, // Above 2000ms P95 threshold.
        'success' => TRUE,
        'scenario' => 'retrieval',
      ];
    }

    $state->method('get')
      ->willReturn([
        'requests' => $requests,
        'total_requests' => 20,
        'total_errors' => 0,
        'last_alert' => 0, // No recent alert — threshold will fire.
      ]);

    // The logger should receive a warning about degraded latency.
    $logger->expects($this->atLeastOnce())
      ->method('warning');

    // The state should be written exactly once, and the written value
    // should include an updated last_alert.
    $state->expects($this->once())
      ->method('set')
      ->with(
        PerformanceMonitor::STATE_KEY,
        $this->callback(function ($metrics) {
          // last_alert should be set to a recent timestamp.
          return $metrics['last_alert'] > 0
            && $metrics['last_alert'] >= time() - 5;
        })
      );

    $monitor = new PerformanceMonitor($state, $logger);
    $monitor->recordRequest(3000.0, TRUE, 'retrieval');
  }

  /**
   * Tests that cooldown suppresses repeated alerts.
   *
   * @covers ::recordRequest
   */
  public function testCooldownSuppressesAlerts(): void {
    $state = $this->createMock('Drupal\Core\State\StateInterface');
    $logger = $this->createMock('Drupal\Core\Logger\LoggerChannelInterface');

    // Build metrics with recent alert (within 5-minute cooldown).
    $requests = [];
    for ($i = 0; $i < 20; $i++) {
      $requests[] = [
        'time' => time() - 10,
        'duration' => 3000.0,
        'success' => TRUE,
        'scenario' => 'retrieval',
      ];
    }

    $state->method('get')
      ->willReturn([
        'requests' => $requests,
        'total_requests' => 20,
        'total_errors' => 0,
        'last_alert' => time() - 60, // Alert 60 seconds ago (within 300s cooldown).
      ]);

    // No warning should be logged due to cooldown.
    $logger->expects($this->never())->method('warning');

    $state->expects($this->once())->method('set');

    $monitor = new PerformanceMonitor($state, $logger);
    $monitor->recordRequest(3000.0, TRUE, 'retrieval');
  }

  /**
   * Tests that error rate threshold triggers alert.
   *
   * @covers ::recordRequest
   */
  public function testErrorRateThresholdTriggersAlert(): void {
    $state = $this->createMock('Drupal\Core\State\StateInterface');
    $logger = $this->createMock('Drupal\Core\Logger\LoggerChannelInterface');

    // Build metrics with high error rate (>5%).
    $requests = [];
    for ($i = 0; $i < 18; $i++) {
      $requests[] = [
        'time' => time() - 10,
        'duration' => 100.0,
        'success' => TRUE,
        'scenario' => 'short',
      ];
    }
    // Add errors (2/20 = 10% > 5% threshold).
    for ($i = 0; $i < 2; $i++) {
      $requests[] = [
        'time' => time() - 10,
        'duration' => 100.0,
        'success' => FALSE,
        'scenario' => 'error',
      ];
    }

    $state->method('get')
      ->willReturn([
        'requests' => $requests,
        'total_requests' => 20,
        'total_errors' => 2,
        'last_alert' => 0,
      ]);

    // Should warn about error rate.
    $logger->expects($this->atLeastOnce())
      ->method('warning')
      ->with($this->stringContains('error rate'), $this->anything());

    $state->expects($this->once())->method('set');

    $monitor = new PerformanceMonitor($state, $logger);
    $monitor->recordRequest(100.0, FALSE, 'error');
  }

  /**
   * Tests that getSummary computes correct percentiles.
   *
   * @covers ::getSummary
   */
  public function testGetSummaryPercentiles(): void {
    $state = $this->createMock('Drupal\Core\State\StateInterface');
    $logger = $this->createMock('Drupal\Core\Logger\LoggerChannelInterface');

    $requests = [];
    for ($i = 1; $i <= 100; $i++) {
      $requests[] = [
        'time' => time(),
        'duration' => (float) $i * 10,
        'success' => TRUE,
        'scenario' => 'retrieval',
      ];
    }

    $state->method('get')
      ->willReturn([
        'requests' => $requests,
        'total_requests' => 100,
        'total_errors' => 0,
        'last_alert' => 0,
      ]);

    $monitor = new PerformanceMonitor($state, $logger);
    $summary = $monitor->getSummary();

    $this->assertEquals(100, $summary['sample_size']);
    $this->assertEquals('healthy', $summary['status']);
    $this->assertGreaterThan(0, $summary['p50']);
    $this->assertGreaterThan($summary['p50'], $summary['p95']);
    $this->assertGreaterThanOrEqual($summary['p95'], $summary['p99']);
  }

  /**
   * Tests that getSummary returns no_data for empty metrics.
   *
   * @covers ::getSummary
   */
  public function testGetSummaryEmpty(): void {
    $state = $this->createMock('Drupal\Core\State\StateInterface');
    $logger = $this->createMock('Drupal\Core\Logger\LoggerChannelInterface');

    $state->method('get')
      ->willReturn([
        'requests' => [],
        'total_requests' => 0,
        'total_errors' => 0,
        'last_alert' => 0,
      ]);

    $monitor = new PerformanceMonitor($state, $logger);
    $summary = $monitor->getSummary();

    $this->assertEquals('no_data', $summary['status']);
    $this->assertEquals(0, $summary['sample_size']);
  }

  /**
   * Tests that request_id parameter is accepted.
   *
   * @covers ::recordRequest
   */
  public function testRecordRequestAcceptsRequestId(): void {
    $state = $this->createMock('Drupal\Core\State\StateInterface');
    $logger = $this->createMock('Drupal\Core\Logger\LoggerChannelInterface');

    $state->method('get')
      ->willReturn([
        'requests' => [],
        'total_requests' => 0,
        'total_errors' => 0,
        'last_alert' => 0,
      ]);

    $state->expects($this->once())->method('set');

    $monitor = new PerformanceMonitor($state, $logger);
    // Should not throw.
    $monitor->recordRequest(50.0, TRUE, 'short', 'test-request-id-123');
  }

}
