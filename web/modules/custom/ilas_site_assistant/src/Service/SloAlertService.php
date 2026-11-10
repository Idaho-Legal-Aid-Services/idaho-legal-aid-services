<?php

declare(strict_types=1);

namespace Drupal\ilas_site_assistant\Service;

use Drupal\Core\State\StateInterface;
use Psr\Log\LoggerInterface;

/**
 * Monitors all SLO dimensions and logs warnings on violations.
 *
 * Alerts on state change: once when a dimension starts breaching, a daily
 * reminder while it stays breached, and a notice when it recovers. A new
 * breach within 15 minutes of the previous alert is held back.
 * Designed to be called from cron after cron health recording.
 *
 * Alerts are structured Drupal logger warnings. Sentry/Raven integration
 * picks these up automatically; external alert rules are configured in the
 * Sentry dashboard.
 */
class SloAlertService {

  /**
   * Cooldown period between repeated alerts of the same type (seconds).
   */
  const COOLDOWN_SECONDS = 900;

  /**
   * Interval between reminders while a dimension stays breached (seconds).
   */
  const REMINDER_SECONDS = 86400;

  /**
   * State key prefix for alert cooldowns.
   */
  const STATE_PREFIX = 'ilas_site_assistant.slo_alert_last_';

  /**
   * State key prefix for the per-dimension "currently breaching" flag.
   */
  const STATE_BREACH_PREFIX = 'ilas_site_assistant.slo_alert_breaching_';

  /**
   * State key prefix for per-outcome queue-loss alert totals.
   */
  const STATE_QUEUE_LOSS_TOTAL_PREFIX = 'ilas_site_assistant.slo_alert_queue_loss_total_';

  /**
   * The SLO definitions service.
   *
   * @var \Drupal\ilas_site_assistant\Service\SloDefinitions
   */
  protected SloDefinitions $sloDefinitions;

  /**
   * The performance monitor service.
   *
   * @var \Drupal\ilas_site_assistant\Service\PerformanceMonitor|null
   */
  protected ?PerformanceMonitor $performanceMonitor;

  /**
   * The cron health tracker service.
   *
   * @var \Drupal\ilas_site_assistant\Service\CronHealthTracker|null
   */
  protected ?CronHealthTracker $cronHealthTracker;

  /**
   * The queue health monitor service.
   *
   * @var \Drupal\ilas_site_assistant\Service\QueueHealthMonitor|null
   */
  protected ?QueueHealthMonitor $queueHealthMonitor;

  /**
   * The logger.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected LoggerInterface $logger;

  /**
   * The state service.
   *
   * @var \Drupal\Core\State\StateInterface
   */
  protected StateInterface $state;

  /**
   * Constructs an SloAlertService.
   */
  public function __construct(
    SloDefinitions $slo_definitions,
    LoggerInterface $logger,
    StateInterface $state,
    ?PerformanceMonitor $performance_monitor = NULL,
    ?CronHealthTracker $cron_health_tracker = NULL,
    ?QueueHealthMonitor $queue_health_monitor = NULL,
  ) {
    $this->sloDefinitions = $slo_definitions;
    $this->logger = $logger;
    $this->state = $state;
    $this->performanceMonitor = $performance_monitor;
    $this->cronHealthTracker = $cron_health_tracker;
    $this->queueHealthMonitor = $queue_health_monitor;
  }

  /**
   * Checks all SLO dimensions and logs warnings on violations.
   */
  public function checkAll(): void {
    $this->checkAvailabilitySlo();
    $this->checkLatencySlo();
    $this->checkErrorRateSlo();
    $this->checkCronSlo();
    $this->checkQueueSlo();
  }

  /**
   * Checks availability against SLO target.
   */
  public function checkAvailabilitySlo(): void {
    if ($this->performanceMonitor === NULL) {
      return;
    }

    $summary = $this->getSloSample();
    if ($summary === NULL) {
      return;
    }
    $errorRate = (float) ($summary['error_rate'] ?? 0);
    $availability = max(0.0, round(100.0 - $errorRate, 2));
    $target = $this->sloDefinitions->getAvailabilityTargetPct();

    if ($availability >= $target) {
      $this->recordRecovery('availability');
      return;
    }

    if ($this->shouldAlert('availability')) {
      $this->logger->warning('SLO violation: availability @availability% below target @target%', [
        '@availability' => $availability,
        '@target' => $target,
        '@slo_dimension' => 'availability',
      ]);
      $this->recordAlert('availability');
    }
  }

  /**
   * Checks P95 latency against SLO target.
   */
  public function checkLatencySlo(): void {
    if ($this->performanceMonitor === NULL) {
      return;
    }

    $summary = $this->getSloSample();
    if ($summary === NULL) {
      return;
    }
    $p95 = $summary['p95'] ?? 0;
    $target = $this->sloDefinitions->getLatencyP95TargetMs();

    if ($p95 <= $target) {
      $this->recordRecovery('latency');
      return;
    }

    if ($this->shouldAlert('latency')) {
      $this->logger->warning('SLO violation: P95 latency @p95ms exceeds target @target ms', [
        '@p95' => $p95,
        '@target' => $target,
        '@slo_dimension' => 'latency',
      ]);
      $this->recordAlert('latency');
    }
  }

  /**
   * Checks error rate against SLO target.
   */
  public function checkErrorRateSlo(): void {
    if ($this->performanceMonitor === NULL) {
      return;
    }

    $summary = $this->getSloSample();
    if ($summary === NULL) {
      return;
    }
    $errorRate = $summary['error_rate'] ?? 0;
    $target = $this->sloDefinitions->getErrorRateTargetPct();

    if ($errorRate <= $target) {
      $this->recordRecovery('error_rate');
      return;
    }

    if ($this->shouldAlert('error_rate')) {
      $this->logger->warning('SLO violation: error rate @rate% exceeds target @target%', [
        '@rate' => $errorRate,
        '@target' => $target,
        '@slo_dimension' => 'error_rate',
      ]);
      $this->recordAlert('error_rate');
    }
  }

  /**
   * Checks cron health against SLO targets.
   */
  public function checkCronSlo(): void {
    if ($this->cronHealthTracker === NULL) {
      return;
    }

    $status = $this->cronHealthTracker->getHealthStatus($this->sloDefinitions);

    if ($status['status'] === 'healthy') {
      $this->recordRecovery('cron');
      return;
    }

    if ($this->shouldAlert('cron')) {
      $this->logger->warning('SLO violation: cron health is @status (age: @age s, failures: @failures)', [
        '@status' => $status['status'],
        '@age' => $status['age'] ?? 'never',
        '@failures' => $status['consecutive_failures'],
        '@slo_dimension' => 'cron',
      ]);
      $this->recordAlert('cron');
    }
  }

  /**
   * Checks queue health against SLO targets.
   */
  public function checkQueueSlo(): void {
    if ($this->queueHealthMonitor === NULL) {
      return;
    }

    $status = $this->queueHealthMonitor->getQueueHealthStatus($this->sloDefinitions);

    if ($status['status'] === 'healthy') {
      $this->recordRecovery('queue');
    }
    elseif ($this->shouldAlert('queue')) {
      $this->logger->warning('SLO violation: queue is @status (depth: @depth, utilization: @pct%, oldest_age: @age s, max_age: @max_age s)', [
        '@status' => $status['status'],
        '@depth' => $status['depth'],
        '@pct' => $status['utilization_pct'],
        '@age' => $status['oldest_item_age_seconds'] ?? 'unknown',
        '@max_age' => $status['max_age_seconds'] ?? 'unknown',
        '@slo_dimension' => 'queue',
      ]);
      $this->recordAlert('queue');
    }

    $this->checkQueueLossAlerts();
  }

  /**
   * Returns the request sample the performance SLOs are judged against.
   *
   * @return array|null
   *   The time-bounded, denial-free SLO summary, or NULL when it holds too
   *   few requests to judge. A NULL sample leaves the breach state untouched.
   */
  protected function getSloSample(): ?array {
    $summary = $this->performanceMonitor->getSummary();
    $sample = is_array($summary['slo'] ?? NULL) ? $summary['slo'] : $summary;

    if (!($sample['sufficient_sample'] ?? TRUE)) {
      return NULL;
    }

    return $sample;
  }

  /**
   * Decides whether a breaching dimension should alert now.
   *
   * @param string $type
   *   The alert type key (availability, latency, error_rate, cron, queue).
   *
   * @return bool
   *   TRUE for a new breach once the cooldown has elapsed, or for an ongoing
   *   breach once the reminder interval has elapsed.
   */
  protected function shouldAlert(string $type): bool {
    if ($this->state->get(self::STATE_BREACH_PREFIX . $type, FALSE)) {
      $lastAlert = (int) $this->state->get(self::STATE_PREFIX . $type, 0);
      return (time() - $lastAlert) >= self::REMINDER_SECONDS;
    }

    return $this->cooldownElapsed($type);
  }

  /**
   * Clears a dimension's breach flag and logs the recovery once.
   *
   * @param string $type
   *   The alert type key.
   */
  protected function recordRecovery(string $type): void {
    if (!$this->state->get(self::STATE_BREACH_PREFIX . $type, FALSE)) {
      return;
    }

    $this->state->set(self::STATE_BREACH_PREFIX . $type, FALSE);
    $this->logger->notice('SLO recovered: @slo_dimension is back within target', [
      '@slo_dimension' => $type,
    ]);
  }

  /**
   * Checks if the cooldown period has elapsed for a given alert type.
   *
   * @param string $type
   *   The alert type key (availability, latency, error_rate, cron, queue).
   *
   * @return bool
   *   TRUE if the cooldown has elapsed and the alert can fire.
   */
  protected function cooldownElapsed(string $type): bool {
    $lastAlert = (int) $this->state->get(self::STATE_PREFIX . $type, 0);
    return (time() - $lastAlert) >= self::COOLDOWN_SECONDS;
  }

  /**
   * Records the current time as the last alert for a given type.
   *
   * @param string $type
   *   The alert type key.
   */
  protected function recordAlert(string $type): void {
    $this->state->set(self::STATE_PREFIX . $type, time());
    $this->state->set(self::STATE_BREACH_PREFIX . $type, TRUE);
  }

  /**
   * Emits per-outcome alerts for newly observed alertable queue loss.
   */
  protected function checkQueueLossAlerts(): void {
    if ($this->queueHealthMonitor === NULL) {
      return;
    }

    $lossOutcomes = $this->queueHealthMonitor->getActionableLossOutcomes();
    foreach ($lossOutcomes as $outcome => $summary) {
      $currentQueueItems = (int) ($summary['queue_items'] ?? 0);
      $lastAlertedQueueItems = (int) $this->state->get(self::STATE_QUEUE_LOSS_TOTAL_PREFIX . $outcome, 0);

      if ($currentQueueItems <= $lastAlertedQueueItems) {
        continue;
      }

      $alertType = 'queue_loss_' . $outcome;
      if (!$this->cooldownElapsed($alertType)) {
        continue;
      }

      $this->logger->warning('SLO violation: queue loss outcome @outcome detected (@new new batches, @total total batches, @events lost events)', [
        '@outcome' => $outcome,
        '@new' => $currentQueueItems - $lastAlertedQueueItems,
        '@total' => $currentQueueItems,
        '@events' => (int) ($summary['event_count'] ?? 0),
        '@slo_dimension' => 'queue_loss',
      ]);

      $this->state->set(self::STATE_QUEUE_LOSS_TOTAL_PREFIX . $outcome, $currentQueueItems);
      $this->recordAlert($alertType);
    }
  }

}
