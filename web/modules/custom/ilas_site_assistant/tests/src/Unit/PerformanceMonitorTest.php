<?php

namespace Drupal\Tests\ilas_site_assistant\Unit;

use Drupal\ilas_site_assistant\Service\PerformanceMonitor;
use Drupal\ilas_site_assistant\Service\SloDefinitions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Tests for PerformanceMonitor: single state write, no race, cooldown preserved.
 */
#[CoversClass(PerformanceMonitor::class)]
#[Group('ilas_site_assistant')]
class PerformanceMonitorTest extends TestCase {

  /**
   * Tests that recordRequest only writes to state once.
   *
   * Previously checkThresholds() also called state->set(), causing a
   * double-write race where the last_alert cooldown could be lost.
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
    $this->assertSame(100.0, $summary['availability_pct']);
    $this->assertGreaterThan(0, $summary['p50']);
    $this->assertGreaterThan($summary['p50'], $summary['p95']);
    $this->assertGreaterThanOrEqual($summary['p95'], $summary['p99']);
  }

  /**
   * Tests that custom SLO thresholds are used in summary threshold output.
   */
  public function testGetSummaryUsesCustomSloThresholds(): void {
    $state = $this->createMock('Drupal\Core\State\StateInterface');
    $logger = $this->createMock('Drupal\Core\Logger\LoggerChannelInterface');
    $slo = $this->createMock(SloDefinitions::class);
    $slo->method('getLatencyP95TargetMs')->willReturn(1500);
    $slo->method('getErrorRateTargetPct')->willReturn(2.0);

    $requests = [];
    for ($i = 0; $i < 99; $i++) {
      $requests[] = [
        'time' => time(),
        'duration' => 1200.0,
        'success' => TRUE,
        'scenario' => 'retrieval',
      ];
    }
    $requests[] = [
      'time' => time(),
      'duration' => 2000.0,
      'success' => TRUE,
      'scenario' => 'retrieval',
    ];

    $state->method('get')
      ->willReturn([
        'requests' => $requests,
        'total_requests' => 100,
        'total_errors' => 0,
        'last_alert' => 0,
      ]);

    $monitor = new PerformanceMonitor($state, $logger, $slo);
    $summary = $monitor->getSummary();

    $this->assertSame(1500, $summary['thresholds']['p95_threshold_ms']);
    $this->assertSame(2.0, $summary['thresholds']['error_rate_threshold']);
  }

  /**
   * Tests that classified endpoint outcomes are stored with enriched metadata.
   */
  public function testRecordObservedRequestStoresEndpointClassification(): void {
    $state = $this->createMock('Drupal\Core\State\StateInterface');
    $logger = $this->createStub('Drupal\Core\Logger\LoggerChannelInterface');

    $state->method('get')
      ->willReturn([
        'requests' => [],
        'all_requests' => [],
        'requests_by_endpoint' => [],
        'total_requests' => 0,
        'total_errors' => 0,
        'total_requests_all' => 0,
        'total_errors_all' => 0,
        'totals_by_endpoint' => [],
        'last_alert' => 0,
      ]);

    $state->expects($this->once())
      ->method('set')
      ->with(
        PerformanceMonitor::STATE_KEY,
        $this->callback(function (array $metrics): bool {
          $this->assertSame(1, $metrics['total_requests_all']);
          $this->assertSame(1, $metrics['total_errors_all']);
          $this->assertSame(0, $metrics['total_requests']);
          $this->assertSame(0, $metrics['total_errors']);
          $this->assertSame(1, $metrics['totals_by_endpoint'][PerformanceMonitor::ENDPOINT_TRACK]['total_requests']);
          $this->assertSame(1, $metrics['totals_by_endpoint'][PerformanceMonitor::ENDPOINT_TRACK]['total_errors']);
          $this->assertCount(1, $metrics['all_requests']);
          $this->assertCount(1, $metrics['requests_by_endpoint'][PerformanceMonitor::ENDPOINT_TRACK]);
          $this->assertSame([], $metrics['requests']);

          $record = $metrics['all_requests'][0];
          $this->assertSame(125.5, $record['duration']);
          $this->assertFalse($record['success']);
          $this->assertSame('unknown', $record['scenario']);
          $this->assertSame(PerformanceMonitor::ENDPOINT_TRACK, $record['endpoint']);
          $this->assertSame('track.rate_limit', $record['outcome']);
          $this->assertSame(429, $record['status_code']);
          $this->assertTrue($record['denied']);
          $this->assertFalse($record['degraded']);

          return TRUE;
        })
      );

    $monitor = new PerformanceMonitor($state, $logger);
    $monitor->recordObservedRequest(
      125.5,
      FALSE,
      PerformanceMonitor::ENDPOINT_TRACK,
      'track.rate_limit',
      429,
      TRUE,
      FALSE,
      'unknown',
    );
  }

  /**
   * Tests that additive summaries keep /message SLOs separate from all traffic.
   */
  public function testGetSummaryIncludesAggregateEndpointAndOutcomeBreakdowns(): void {
    $state = $this->createMock('Drupal\Core\State\StateInterface');
    $logger = $this->createMock('Drupal\Core\Logger\LoggerChannelInterface');

    $now = time();
    $message_success = [
      'time' => $now,
      'duration' => 100.0,
      'success' => TRUE,
      'scenario' => 'retrieval',
      'endpoint' => PerformanceMonitor::ENDPOINT_MESSAGE,
      'outcome' => 'message.success',
      'status_code' => 200,
      'denied' => FALSE,
      'degraded' => FALSE,
    ];
    $message_invalid = [
      'time' => $now,
      'duration' => 250.0,
      'success' => FALSE,
      'scenario' => 'unknown',
      'endpoint' => PerformanceMonitor::ENDPOINT_MESSAGE,
      'outcome' => 'message.invalid_request',
      'status_code' => 400,
      'denied' => TRUE,
      'degraded' => FALSE,
    ];
    $suggest_degraded = [
      'time' => $now,
      'duration' => 75.0,
      'success' => FALSE,
      'scenario' => 'unknown',
      'endpoint' => PerformanceMonitor::ENDPOINT_SUGGEST,
      'outcome' => 'suggest.degraded_empty',
      'status_code' => 200,
      'denied' => FALSE,
      'degraded' => TRUE,
    ];
    $faq_denied = [
      'time' => $now,
      'duration' => 50.0,
      'success' => FALSE,
      'scenario' => 'unknown',
      'endpoint' => PerformanceMonitor::ENDPOINT_FAQ,
      'outcome' => 'faq.rate_limit',
      'status_code' => 429,
      'denied' => TRUE,
      'degraded' => FALSE,
    ];

    $state->method('get')
      ->willReturn([
        'requests' => [$message_success, $message_invalid],
        'all_requests' => [$message_success, $message_invalid, $suggest_degraded, $faq_denied],
        'requests_by_endpoint' => [
          PerformanceMonitor::ENDPOINT_MESSAGE => [$message_success, $message_invalid],
          PerformanceMonitor::ENDPOINT_SUGGEST => [$suggest_degraded],
          PerformanceMonitor::ENDPOINT_FAQ => [$faq_denied],
        ],
        'total_requests' => 2,
        'total_errors' => 1,
        'total_requests_all' => 4,
        'total_errors_all' => 3,
        'totals_by_endpoint' => [
          PerformanceMonitor::ENDPOINT_MESSAGE => ['total_requests' => 2, 'total_errors' => 1],
          PerformanceMonitor::ENDPOINT_SUGGEST => ['total_requests' => 1, 'total_errors' => 1],
          PerformanceMonitor::ENDPOINT_FAQ => ['total_requests' => 1, 'total_errors' => 1],
        ],
        'last_alert' => 0,
      ]);

    $monitor = new PerformanceMonitor($state, $logger);
    $summary = $monitor->getSummary();

    $this->assertSame(2, $summary['sample_size']);
    $this->assertSame(1, $summary['error_count']);
    $this->assertSame(1, $summary['denied_count']);
    $this->assertSame(0, $summary['degraded_count']);

    $this->assertSame(4, $summary['all_endpoints']['sample_size']);
    $this->assertSame(3, $summary['all_endpoints']['error_count']);
    $this->assertSame(2, $summary['all_endpoints']['denied_count']);
    $this->assertSame(1, $summary['all_endpoints']['degraded_count']);
    $this->assertSame(['200' => 2, '400' => 1, '429' => 1], $summary['all_endpoints']['status_code_counts']);

    $this->assertSame(2, $summary['by_endpoint'][PerformanceMonitor::ENDPOINT_MESSAGE]['sample_size']);
    $this->assertSame(1, $summary['by_endpoint'][PerformanceMonitor::ENDPOINT_MESSAGE]['error_count']);
    $this->assertSame(1, $summary['by_endpoint'][PerformanceMonitor::ENDPOINT_SUGGEST]['degraded_count']);
    $this->assertSame(1, $summary['by_endpoint'][PerformanceMonitor::ENDPOINT_FAQ]['denied_count']);
    $this->assertSame(0, $summary['by_endpoint'][PerformanceMonitor::ENDPOINT_TRACK]['sample_size']);

    $this->assertSame('faq', $summary['by_outcome']['faq.rate_limit']['endpoint']);
    $this->assertSame(1, $summary['by_outcome']['faq.rate_limit']['denied_count']);
    $this->assertSame(1, $summary['by_outcome']['message.invalid_request']['error_count']);
    $this->assertSame(1, $summary['by_outcome']['suggest.degraded_empty']['degraded_count']);
  }

  /**
   * Tests that high-volume read traffic cannot evict the /message SLO window.
   */
  public function testPerEndpointWindowsPreserveMessageSamplesUnderReadTraffic(): void {
    $stored = NULL;
    $state = $this->createMock('Drupal\Core\State\StateInterface');
    $logger = $this->createStub('Drupal\Core\Logger\LoggerChannelInterface');

    $state->method('get')
      ->willReturnCallback(function (string $key, array $default) use (&$stored): array {
        return $stored ?? $default;
      });
    $state->method('set')
      ->willReturnCallback(function (string $key, array $value) use (&$stored): void {
        $stored = $value;
      });

    $monitor = new PerformanceMonitor($state, $logger);
    $monitor->recordObservedRequest(
      40.0,
      TRUE,
      PerformanceMonitor::ENDPOINT_MESSAGE,
      'message.success',
      200,
      FALSE,
      FALSE,
      'retrieval',
    );

    for ($i = 0; $i < PerformanceMonitor::WINDOW_SIZE + 5; $i++) {
      $monitor->recordObservedRequest(
        20.0,
        TRUE,
        PerformanceMonitor::ENDPOINT_SUGGEST,
        'suggest.success',
        200,
        FALSE,
        FALSE,
        'unknown',
      );
    }

    $summary = $monitor->getSummary();

    $this->assertSame(1, $summary['sample_size']);
    $this->assertSame(1, $summary['by_endpoint'][PerformanceMonitor::ENDPOINT_MESSAGE]['sample_size']);
    $this->assertSame(PerformanceMonitor::WINDOW_SIZE, $summary['by_endpoint'][PerformanceMonitor::ENDPOINT_SUGGEST]['sample_size']);
    $this->assertSame(PerformanceMonitor::WINDOW_SIZE, $summary['all_endpoints']['sample_size']);
  }

  /**
   * Tests that getSummary returns no_data for empty metrics.
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
