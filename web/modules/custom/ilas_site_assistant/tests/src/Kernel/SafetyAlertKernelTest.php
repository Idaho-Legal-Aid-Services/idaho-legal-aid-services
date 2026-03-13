<?php

namespace Drupal\Tests\ilas_site_assistant\Kernel;

use Drupal\ilas_site_assistant\Service\SafetyAlertService;
use Drupal\ilas_site_assistant\Service\SafetyViolationTracker;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Kernel tests for SafetyAlertService.
 *
 * Tests threshold detection, cooldown logic, and email dispatch
 * against a real database with mocked mail/state services.
 *
 */
#[CoversClass(SafetyAlertService::class)]
#[Group('ilas_site_assistant')]
class SafetyAlertKernelTest extends AssistantKernelTestBase {

  /**
   * Tests that checkThresholds does nothing when alerting is disabled.
   */
  public function testCheckThresholdsSkipsWhenDisabled(): void {
    $mailManager = $this->createMock('Drupal\Core\Mail\MailManagerInterface');
    $mailManager->expects($this->never())->method('mail');

    $service = $this->createSafetyAlertService([
      'safety_alerting.enabled' => FALSE,
    ], $mailManager);

    $service->checkThresholds();
  }

  /**
   * Tests that checkThresholds does nothing without recipients.
   */
  public function testCheckThresholdsSkipsWithoutRecipients(): void {
    $mailManager = $this->createMock('Drupal\Core\Mail\MailManagerInterface');
    $mailManager->expects($this->never())->method('mail');

    $service = $this->createSafetyAlertService([
      'safety_alerting.enabled' => TRUE,
      'safety_alerting.recipients' => '',
    ], $mailManager);

    $service->checkThresholds();
  }

  /**
   * Tests that checkThresholds sends email when threshold is exceeded.
   */
  public function testCheckThresholdsSendsWhenExceeded(): void {
    $today = date('Y-m-d');

    // Insert violations that exceed the threshold (threshold=5 for this test).
    $this->insertStatsRow('safety_violation', 'crisis_suicide', 3, $today);
    $this->insertStatsRow('safety_violation', 'emergency_dv', 3, $today);

    $mailManager = $this->createMock('Drupal\Core\Mail\MailManagerInterface');
    $mailManager->expects($this->once())
      ->method('mail')
      ->with(
        'ilas_site_assistant',
        'safety_alert',
        'admin@example.com',
        $this->anything(),
        $this->anything()
      );

    $service = $this->createSafetyAlertService([
      'safety_alerting.enabled' => TRUE,
      'safety_alerting.threshold' => 5,
      'safety_alerting.window_hours' => 1,
      'safety_alerting.cooldown_minutes' => 60,
      'safety_alerting.recipients' => 'admin@example.com',
    ], $mailManager);

    $service->checkThresholds();
  }

  /**
   * Tests that checkThresholds does not send when below threshold.
   */
  public function testCheckThresholdsDoesNotSendBelowThreshold(): void {
    $today = date('Y-m-d');

    // Insert violations below the threshold.
    $this->insertStatsRow('safety_violation', 'crisis_suicide', 2, $today);

    $mailManager = $this->createMock('Drupal\Core\Mail\MailManagerInterface');
    $mailManager->expects($this->never())->method('mail');

    $service = $this->createSafetyAlertService([
      'safety_alerting.enabled' => TRUE,
      'safety_alerting.threshold' => 5,
      'safety_alerting.window_hours' => 1,
      'safety_alerting.cooldown_minutes' => 60,
      'safety_alerting.recipients' => 'admin@example.com',
    ], $mailManager);

    $service->checkThresholds();
  }

  /**
   * Tests that checkThresholds respects cooldown period.
   */
  public function testCheckThresholdsRespectsCooldown(): void {
    $now = 1700000000;
    $today = date('Y-m-d', $now);

    // Insert violations exceeding threshold.
    $this->insertStatsRow('safety_violation', 'crisis_suicide', 10, $today);

    // Set last alert time within cooldown window.
    $state = $this->createMock('Drupal\Core\State\StateInterface');
    $state->method('get')
      ->with(SafetyAlertService::STATE_LAST_ALERT, 0)
      ->willReturn($now - 1800); // 30 minutes ago, cooldown is 60.

    $mailManager = $this->createMock('Drupal\Core\Mail\MailManagerInterface');
    $mailManager->expects($this->never())->method('mail');

    $service = $this->createSafetyAlertService([
      'safety_alerting.enabled' => TRUE,
      'safety_alerting.threshold' => 5,
      'safety_alerting.window_hours' => 1,
      'safety_alerting.cooldown_minutes' => 60,
      'safety_alerting.recipients' => 'admin@example.com',
    ], $mailManager, $state, $now);

    $service->checkThresholds();
  }

  /**
   * Tests that checkThresholds sends to multiple recipients.
   */
  public function testCheckThresholdsSendsToMultipleRecipients(): void {
    $today = date('Y-m-d');
    $this->insertStatsRow('safety_violation', 'crisis_suicide', 10, $today);

    $mailManager = $this->createMock('Drupal\Core\Mail\MailManagerInterface');
    $mailManager->expects($this->exactly(2))
      ->method('mail');

    $service = $this->createSafetyAlertService([
      'safety_alerting.enabled' => TRUE,
      'safety_alerting.threshold' => 5,
      'safety_alerting.window_hours' => 1,
      'safety_alerting.cooldown_minutes' => 60,
      'safety_alerting.recipients' => 'admin@example.com, ops@example.com',
    ], $mailManager);

    $service->checkThresholds();
  }

  /**
   * Tests that invalid email addresses are skipped.
   */
  public function testCheckThresholdsSkipsInvalidEmails(): void {
    $today = date('Y-m-d');
    $this->insertStatsRow('safety_violation', 'crisis_suicide', 10, $today);

    $mailManager = $this->createMock('Drupal\Core\Mail\MailManagerInterface');
    // Only 1 valid email out of 2 recipients.
    $mailManager->expects($this->once())->method('mail');

    $service = $this->createSafetyAlertService([
      'safety_alerting.enabled' => TRUE,
      'safety_alerting.threshold' => 5,
      'safety_alerting.window_hours' => 1,
      'safety_alerting.cooldown_minutes' => 60,
      'safety_alerting.recipients' => 'admin@example.com, not-an-email',
    ], $mailManager);

    $service->checkThresholds();
  }

  /**
   * Tests that state is updated after sending an alert.
   */
  public function testCheckThresholdsUpdatesState(): void {
    $now = 1700000000;
    $today = date('Y-m-d', $now);
    $this->insertStatsRow('safety_violation', 'crisis_suicide', 10, $today);

    $state = $this->createMock('Drupal\Core\State\StateInterface');
    $state->method('get')
      ->with(SafetyAlertService::STATE_LAST_ALERT, 0)
      ->willReturn(0);
    $state->expects($this->once())
      ->method('set')
      ->with(SafetyAlertService::STATE_LAST_ALERT, $now);

    $mailManager = $this->createMock('Drupal\Core\Mail\MailManagerInterface');

    $service = $this->createSafetyAlertService([
      'safety_alerting.enabled' => TRUE,
      'safety_alerting.threshold' => 5,
      'safety_alerting.window_hours' => 1,
      'safety_alerting.cooldown_minutes' => 60,
      'safety_alerting.recipients' => 'admin@example.com',
    ], $mailManager, $state, $now);

    $service->checkThresholds();
  }

  /**
   * Tests that only safety_violation event types are counted.
   */
  public function testCountsOnlySafetyViolations(): void {
    $today = date('Y-m-d');

    // Mix of event types — only safety_violation should count.
    $this->insertStatsRow('safety_violation', 'crisis_suicide', 3, $today);
    $this->insertStatsRow('chat_open', '', 100, $today);
    $this->insertStatsRow('topic_selected', 'housing', 50, $today);

    $mailManager = $this->createMock('Drupal\Core\Mail\MailManagerInterface');
    // 3 < 5, so no email should be sent despite other high counts.
    $mailManager->expects($this->never())->method('mail');

    $service = $this->createSafetyAlertService([
      'safety_alerting.enabled' => TRUE,
      'safety_alerting.threshold' => 5,
      'safety_alerting.window_hours' => 1,
      'safety_alerting.cooldown_minutes' => 60,
      'safety_alerting.recipients' => 'admin@example.com',
    ], $mailManager);

    $service->checkThresholds();
  }

  /**
   * Tests that tracker-based counting is used when available.
   *
   * The tracker provides sub-day (timestamp-level) counting, overriding
   * the date-bucketed stats table counting.
   */
  public function testTrackerBasedCountingUsed(): void {
    $now = 1700000000;

    // Insert stats rows that would exceed threshold via the old path.
    $today = date('Y-m-d', $now);
    $this->insertStatsRow('safety_violation', 'crisis_suicide', 10, $today);

    // Create a tracker that reports fewer violations (below threshold).
    $trackerState = $this->createMock('Drupal\Core\State\StateInterface');
    $trackerState->method('get')
      ->willReturn([]); // Empty = 0 violations via tracker.
    $trackerState->method('set');
    $tracker = new SafetyViolationTracker($trackerState);

    $mailManager = $this->createMock('Drupal\Core\Mail\MailManagerInterface');
    // If tracker is used, count is 0, so no email should be sent.
    $mailManager->expects($this->never())->method('mail');

    $service = $this->createSafetyAlertService([
      'safety_alerting.enabled' => TRUE,
      'safety_alerting.threshold' => 5,
      'safety_alerting.window_hours' => 1,
      'safety_alerting.cooldown_minutes' => 60,
      'safety_alerting.recipients' => 'admin@example.com',
    ], $mailManager, NULL, $now, $tracker);

    $service->checkThresholds();
  }

  /**
   * Tests that tracker violations exceeding threshold trigger alert.
   */
  public function testTrackerViolationsExceedThreshold(): void {
    $now = 1700000000;

    // Tracker has 10 recent timestamps.
    $timestamps = [];
    for ($i = 0; $i < 10; $i++) {
      $timestamps[] = $now - ($i * 60); // One per minute, all within 1 hour.
    }

    $trackerState = $this->createMock('Drupal\Core\State\StateInterface');
    $trackerState->method('get')
      ->willReturn($timestamps);
    $trackerState->method('set');
    $tracker = new SafetyViolationTracker($trackerState);

    // Insert stats rows for top_reasons (daily granularity is fine).
    $today = date('Y-m-d', $now);
    $this->insertStatsRow('safety_violation', 'crisis_suicide', 7, $today);
    $this->insertStatsRow('safety_violation', 'emergency_dv', 3, $today);

    $mailManager = $this->createMock('Drupal\Core\Mail\MailManagerInterface');
    $mailManager->expects($this->once())->method('mail');

    $service = $this->createSafetyAlertService([
      'safety_alerting.enabled' => TRUE,
      'safety_alerting.threshold' => 5,
      'safety_alerting.window_hours' => 1,
      'safety_alerting.cooldown_minutes' => 60,
      'safety_alerting.recipients' => 'admin@example.com',
    ], $mailManager, NULL, $now, $tracker);

    $service->checkThresholds();
  }

  /**
   * Creates a SafetyAlertService with configurable overrides.
   *
   * @param array $config_overrides
   *   Config values to override.
   * @param \Drupal\Core\Mail\MailManagerInterface|null $mailManager
   *   Mail manager mock.
   * @param \Drupal\Core\State\StateInterface|null $state
   *   State service mock.
   * @param int $timestamp
   *   The timestamp for the time service.
   * @param \Drupal\ilas_site_assistant\Service\SafetyViolationTracker|null $tracker
   *   Optional violation tracker.
   *
   * @return \Drupal\ilas_site_assistant\Service\SafetyAlertService
   *   The configured SafetyAlertService.
   */
  protected function createSafetyAlertService(
    array $config_overrides = [],
    $mailManager = NULL,
    $state = NULL,
    int $timestamp = 0,
    SafetyViolationTracker $tracker = NULL
  ): SafetyAlertService {
    if ($timestamp === 0) {
      $timestamp = time();
    }

    $configFactory = $this->createMockConfigFactory($config_overrides);
    $time = $this->createMockTime($timestamp);

    if ($mailManager === NULL) {
      $mailManager = $this->createMock('Drupal\Core\Mail\MailManagerInterface');
    }

    if ($state === NULL) {
      $state = $this->createMock('Drupal\Core\State\StateInterface');
      $state->method('get')->willReturn(0);
    }

    $logger = $this->createMock('Psr\Log\LoggerInterface');

    return new SafetyAlertService(
      $configFactory,
      $this->database,
      $mailManager,
      $state,
      $time,
      $logger,
      $tracker
    );
  }

}
