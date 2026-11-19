<?php

declare(strict_types=1);

namespace Drupal\ilas_site_assistant\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\State\StateInterface;
use Psr\Log\LoggerInterface;

/**
 * Checks on a schedule that outside APIs still accept what this site sends.
 *
 * The site talks to Langfuse, Cohere, Voyage and Pinecone through its own
 * clients. Each has changed its API under us at least once, and each time the
 * first sign was a production failure: ordinary traffic is the only thing
 * that exercised those calls, and some paths (the Langfuse read API, grounded
 * generation) see no traffic at all. This runs one minimal real request per
 * vendor once a week from cron and logs an error for any that fails, which
 * reaches Sentry.
 *
 * Enabled by settings.php on live only, where the provider keys are. The
 * ilas:vendor-contract-check command runs the same checks anywhere on demand.
 */
final class VendorContractMonitor {

  public const STATE_LAST_RUN = 'ilas_site_assistant.vendor_contract.last_run';
  public const STATE_LAST_REPORT = 'ilas_site_assistant.vendor_contract.last_report';
  public const STATE_PENDING = 'ilas_site_assistant.vendor_contract.pending';

  /**
   * How long an accepted request may stay unconfirmed before it is a failure.
   *
   * Langfuse documents up to ten minutes of delay on its slower ingestion
   * path. Cron runs hourly, so a pending probe is normally confirmed on the
   * next run; thirty minutes only guards against calling it too early.
   */
  public const PENDING_GRACE_SECONDS = 1800;

  /**
   * Constructs the monitor.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   * @param \Drupal\Core\State\StateInterface $state
   *   The state store.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   * @param \Psr\Log\LoggerInterface $logger
   *   The module logger.
   * @param iterable<\Drupal\ilas_site_assistant\Service\VendorContractCheckInterface> $checks
   *   The vendor checks to run.
   */
  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly StateInterface $state,
    private readonly TimeInterface $time,
    private readonly LoggerInterface $logger,
    private readonly iterable $checks,
  ) {}

  /**
   * Runs the checks when enabled and the interval has elapsed.
   *
   * Called on every cron run. Results left pending by an earlier run are
   * confirmed first, whether or not a new run is due.
   *
   * @return array<string, mixed>|null
   *   The report, or NULL when no new run was started.
   */
  public function runIfDue(): ?array {
    $config = $this->configFactory->get('ilas_site_assistant.settings');
    if (!$config->get('vendor_contract_check.enabled')) {
      return NULL;
    }

    $this->confirmPending();

    $intervalSeconds = max(1, (int) ($config->get('vendor_contract_check.interval_hours') ?? 168)) * 3600;
    $now = $this->time->getCurrentTime();
    if (($now - (int) $this->state->get(self::STATE_LAST_RUN, 0)) < $intervalSeconds) {
      return NULL;
    }

    // Recorded before the run so a check that hangs or fatals cannot make
    // every following cron run repeat it.
    $this->state->set(self::STATE_LAST_RUN, $now);

    return $this->run();
  }

  /**
   * Runs every check now and logs the outcome.
   *
   * @return array{ok: bool, checked_at: int, vendors: array<string, array<string, mixed>>}
   *   The report. 'ok' is FALSE when any vendor failed; a pending vendor is
   *   not a failure.
   */
  public function run(): array {
    $vendors = [];
    foreach ($this->checks as $check) {
      try {
        $vendors += $check->run();
      }
      catch (\Throwable $e) {
        $vendors[(new \ReflectionClass($check))->getShortName()] = [
          'status' => VendorContractCheckInterface::FAIL,
          'reason' => 'exception:' . (new \ReflectionClass($e))->getShortName(),
        ];
      }
    }

    $now = $this->time->getCurrentTime();
    $pending = [];
    $failed = 0;
    foreach ($vendors as $vendor => $result) {
      if ($result['status'] === VendorContractCheckInterface::FAIL) {
        $failed++;
        $this->logFailure((string) $vendor, (string) $result['reason']);
      }
      elseif ($result['status'] === VendorContractCheckInterface::PENDING) {
        $pending[$vendor] = [
          'context' => is_array($result['context'] ?? NULL) ? $result['context'] : [],
          'written_at' => $now,
        ];
      }
    }
    // A new run replaces whatever an earlier run left unconfirmed.
    $this->state->set(self::STATE_PENDING, $pending);

    $report = [
      'ok' => $failed === 0,
      'checked_at' => $now,
      'vendors' => $vendors,
    ];
    $this->state->set(self::STATE_LAST_REPORT, $report);

    if ($failed === 0) {
      $this->logger->info('Vendor contract check passed: @summary', [
        '@summary' => $this->summarize($vendors),
      ]);
    }

    return $report;
  }

  /**
   * Confirms results that an earlier run left pending.
   *
   * @param bool $final
   *   TRUE to treat anything still unconfirmed as a failure now, regardless
   *   of the grace period.
   *
   * @return array<string, array{status: string, reason: string}>
   *   The outcome per vendor that was pending. Empty when nothing was.
   */
  public function confirmPending(bool $final = FALSE): array {
    $pending = $this->state->get(self::STATE_PENDING, []);
    if (!is_array($pending) || $pending === []) {
      return [];
    }

    $now = $this->time->getCurrentTime();
    $outcomes = [];
    foreach ($pending as $vendor => $entry) {
      $vendor = (string) $vendor;
      $context = is_array($entry['context'] ?? NULL) ? $entry['context'] : [];
      $check = $this->deferredCheckFor($vendor);
      try {
        $result = $check !== NULL
          ? $check->confirm($context)
          : ['status' => VendorContractCheckInterface::FAIL, 'reason' => 'no_check_to_confirm'];
      }
      catch (\Throwable $e) {
        $result = ['status' => VendorContractCheckInterface::FAIL, 'reason' => 'exception:' . (new \ReflectionClass($e))->getShortName()];
      }

      $age = $now - (int) ($entry['written_at'] ?? 0);
      if ($result['status'] === VendorContractCheckInterface::PENDING && ($final || $age >= self::PENDING_GRACE_SECONDS)) {
        // Accepted, and still not there well after it should be.
        $result = ['status' => VendorContractCheckInterface::FAIL, 'reason' => 'accepted_but_not_stored'];
      }

      $outcomes[$vendor] = $result;
      if ($result['status'] === VendorContractCheckInterface::PENDING) {
        continue;
      }

      unset($pending[$vendor]);
      if ($result['status'] === VendorContractCheckInterface::FAIL) {
        $this->logFailure($vendor, (string) $result['reason']);
      }
      else {
        $this->logger->info('Vendor contract check confirmed: ' . $vendor . ' (@reason, @age s after the request).', [
          '@reason' => $result['reason'],
          '@age' => $age,
        ]);
      }
      $this->recordConfirmedOutcome($vendor, $result);
    }

    $this->state->set(self::STATE_PENDING, $pending);

    return $outcomes;
  }

  /**
   * Returns TRUE when an earlier run left results unconfirmed.
   */
  public function hasPending(): bool {
    $pending = $this->state->get(self::STATE_PENDING, []);

    return is_array($pending) && $pending !== [];
  }

  /**
   * Returns the stored report of the last run, with confirmations applied.
   *
   * @return array<string, mixed>
   *   The report, or an empty array before the first run.
   */
  public function lastReport(): array {
    $report = $this->state->get(self::STATE_LAST_REPORT, []);

    return is_array($report) ? $report : [];
  }

  /**
   * Finds the check that can confirm a vendor's pending result.
   */
  private function deferredCheckFor(string $vendor): ?VendorContractDeferredCheckInterface {
    foreach ($this->checks as $check) {
      if ($check instanceof VendorContractDeferredCheckInterface && in_array($vendor, $check->deferredVendors(), TRUE)) {
        return $check;
      }
    }
    return NULL;
  }

  /**
   * Writes a confirmed outcome into the stored report.
   *
   * @param string $vendor
   *   The vendor name.
   * @param array{status: string, reason: string} $result
   *   The confirmed result.
   */
  private function recordConfirmedOutcome(string $vendor, array $result): void {
    $report = $this->lastReport();
    if (!isset($report['vendors'][$vendor])) {
      return;
    }

    $context = $report['vendors'][$vendor]['context'] ?? NULL;
    $report['vendors'][$vendor] = $result + (is_array($context) ? ['context' => $context] : []);
    $report['ok'] = !in_array(
      VendorContractCheckInterface::FAIL,
      array_column($report['vendors'], 'status'),
      TRUE,
    );
    $this->state->set(self::STATE_LAST_REPORT, $report);
  }

  /**
   * Logs one vendor's failure.
   *
   * The vendor is part of the message template, so each failing vendor is
   * its own Sentry issue rather than one shared one.
   */
  private function logFailure(string $vendor, string $reason): void {
    $this->logger->error('Vendor contract check failed: ' . $vendor . ' (@reason). The provider rejected a request this site sends, could not be reached, or accepted it without storing it.', [
      '@reason' => $reason,
    ]);
  }

  /**
   * Formats per-vendor statuses for the log.
   *
   * @param array<string, array<string, mixed>> $vendors
   *   Results keyed by vendor.
   */
  private function summarize(array $vendors): string {
    return implode(', ', array_map(
      static fn(string $vendor, array $result): string => $vendor . '=' . $result['status'],
      array_keys($vendors),
      $vendors,
    ));
  }

}
