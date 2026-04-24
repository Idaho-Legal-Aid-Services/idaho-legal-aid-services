<?php

namespace Drupal\ilas_site_assistant\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Component\Datetime\TimeInterface;
use Psr\Log\LoggerInterface;

/**
 * Cron-driven safety spike alerting service.
 *
 * Checks the ilas_site_assistant_stats table for safety_violation events
 * that exceed a configurable threshold within a time window. Sends email
 * alerts to configured recipients with no PII in the message body.
 */
class SafetyAlertService {

  /**
   * State key for tracking the last alert timestamp.
   */
  const STATE_LAST_ALERT = 'ilas_site_assistant.last_safety_alert';

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * The mail manager.
   *
   * @var \Drupal\Core\Mail\MailManagerInterface
   */
  protected $mailManager;

  /**
   * The state service.
   *
   * @var \Drupal\Core\State\StateInterface
   */
  protected $state;

  /**
   * The time service.
   *
   * @var \Drupal\Component\Datetime\TimeInterface
   */
  protected $time;

  /**
   * The logger.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * The violation tracker for sub-day windowed counting.
   *
   * @var \Drupal\ilas_site_assistant\Service\SafetyViolationTracker|null
   */
  protected $violationTracker;

  /**
   * Constructs a SafetyAlertService object.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    Connection $database,
    MailManagerInterface $mail_manager,
    StateInterface $state,
    TimeInterface $time,
    LoggerInterface $logger,
    SafetyViolationTracker $violation_tracker = NULL
  ) {
    $this->configFactory = $config_factory;
    $this->database = $database;
    $this->mailManager = $mail_manager;
    $this->state = $state;
    $this->time = $time;
    $this->logger = $logger;
    $this->violationTracker = $violation_tracker;
  }

  /**
   * Checks safety violation thresholds and sends alerts if exceeded.
   *
   * Called from hook_cron(). Respects cooldown period between alerts.
   */
  public function checkThresholds(): void {
    $config = $this->configFactory->get('ilas_site_assistant.settings');

    if (!$config->get('safety_alerting.enabled')) {
      return;
    }

    $threshold = (int) ($config->get('safety_alerting.threshold') ?? 20);
    $window_hours = (int) ($config->get('safety_alerting.window_hours') ?? 1);
    $cooldown_minutes = (int) ($config->get('safety_alerting.cooldown_minutes') ?? 60);
    $recipients = trim($config->get('safety_alerting.recipients') ?? '');

    if (empty($recipients)) {
      return;
    }

    // Check cooldown: don't send if we alerted recently.
    $now = $this->time->getRequestTime();
    $last_alert = (int) $this->state->get(self::STATE_LAST_ALERT, 0);
    if ($last_alert > 0 && ($now - $last_alert) < ($cooldown_minutes * 60)) {
      return;
    }

    // Count safety violations within the window.
    // Prefer the violation tracker (accurate sub-day counting) over
    // the stats table (date-bucketed, day-level granularity only).
    $window_start_date = date('Y-m-d', $now - ($window_hours * 3600));
    $today = date('Y-m-d', $now);

    try {
      if ($this->violationTracker) {
        $cutoff = $now - ($window_hours * 3600);
        $count = $this->violationTracker->countSince($cutoff);
      }
      else {
        $count = $this->countSafetyViolations($window_start_date, $today);
      }
    }
    catch (\Exception $e) {
      $this->logger->error('Safety alert check failed: @class @error_signature', [
        '@class' => get_class($e),
        '@error_signature' => ObservabilityPayloadMinimizer::exceptionSignature($e),
      ]);
      return;
    }

    if ($count < $threshold) {
      return;
    }

    // Get top reason codes for the alert body (daily granularity acceptable).
    $top_reasons = $this->getTopReasonCodes($window_start_date, $today, 5);

    // Send the alert.
    $this->sendAlert($recipients, $count, $window_hours, $top_reasons);

    // Record that we sent an alert.
    $this->state->set(self::STATE_LAST_ALERT, $now);

    $this->logger->warning('Safety spike alert sent: @count violations in @hours hour(s). Recipients: @recipients', [
      '@count' => $count,
      '@hours' => $window_hours,
      '@recipients' => $recipients,
    ]);
  }

  /**
   * Counts safety_violation events within a date range.
   *
   * @param string $start_date
   *   Start date in Y-m-d format.
   * @param string $end_date
   *   End date in Y-m-d format.
   *
   * @return int
   *   Total violation count.
   */
  protected function countSafetyViolations(string $start_date, string $end_date): int {
    $query = $this->database->select('ilas_site_assistant_stats', 's')
      ->condition('event_type', 'safety_violation')
      ->condition('date', $start_date, '>=')
      ->condition('date', $end_date, '<=');
    $query->addExpression('SUM(s.count)', 'total');

    $result = $query->execute()->fetchField();
    return (int) ($result ?? 0);
  }

  /**
   * Gets the top reason codes for safety violations in a date range.
   *
   * @param string $start_date
   *   Start date in Y-m-d format.
   * @param string $end_date
   *   End date in Y-m-d format.
   * @param int $limit
   *   Maximum number of reason codes to return.
   *
   * @return array
   *   Array of ['reason_code' => string, 'count' => int].
   */
  protected function getTopReasonCodes(string $start_date, string $end_date, int $limit): array {
    $query = $this->database->select('ilas_site_assistant_stats', 's')
      ->condition('event_type', 'safety_violation')
      ->condition('date', $start_date, '>=')
      ->condition('date', $end_date, '<=');
    $query->addField('s', 'event_value', 'reason_code');
    $query->addExpression('SUM(s.count)', 'total');
    $query->groupBy('s.event_value');
    $query->orderBy('total', 'DESC');
    $query->range(0, $limit);

    $results = [];
    foreach ($query->execute() as $row) {
      $results[] = [
        'reason_code' => $row->reason_code,
        'count' => (int) $row->total,
      ];
    }
    return $results;
  }

  /**
   * Sends the safety spike alert email.
   *
   * @param string $recipients
   *   Comma-separated email addresses.
   * @param int $count
   *   Total violation count.
   * @param int $window_hours
   *   The lookback window in hours.
   * @param array $top_reasons
   *   Array of top reason codes with counts.
   */
  protected function sendAlert(string $recipients, int $count, int $window_hours, array $top_reasons): void {
    $reason_lines = [];
    foreach ($top_reasons as $reason) {
      $reason_lines[] = '  - ' . $reason['reason_code'] . ' (' . $reason['count'] . ')';
    }

    $params = [
      'subject' => '[ILAS Assistant] Safety spike alert',
      'body' => implode("\n", [
        'The ILAS Site Assistant has detected a spike in safety-classified messages.',
        '',
        'Violations detected: ' . $count,
        'Time window: last ' . $window_hours . ' hour(s)',
        'Date: ' . date('Y-m-d H:i T'),
        '',
        'Top reason codes:',
        implode("\n", $reason_lines),
        '',
        'Review the full report at: /admin/reports/ilas-assistant',
        'Review conversation logs at: /admin/reports/ilas-assistant/governance/conversations',
        '',
        'This alert contains no personally identifiable information (PII).',
        'This is an automated message from the ILAS Site Assistant module.',
      ]),
    ];

    $addresses = array_map('trim', explode(',', $recipients));
    foreach ($addresses as $address) {
      if (!filter_var($address, FILTER_VALIDATE_EMAIL)) {
        $this->logger->warning('Safety alert: skipping invalid email address @address', [
          '@address' => $address,
        ]);
        continue;
      }

      $this->mailManager->mail(
        'ilas_site_assistant',
        'safety_alert',
        $address,
        'en',
        $params
      );
    }
  }

}
