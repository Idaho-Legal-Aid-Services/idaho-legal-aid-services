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
   * @return array<string, mixed>|null
   *   The report, or NULL when nothing ran.
   */
  public function runIfDue(): ?array {
    $config = $this->configFactory->get('ilas_site_assistant.settings');
    if (!$config->get('vendor_contract_check.enabled')) {
      return NULL;
    }

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
   * @return array{ok: bool, checked_at: int, vendors: array<string, array{status: string, reason: string}>}
   *   The report. 'ok' is FALSE when any vendor failed.
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

    $failed = 0;
    foreach ($vendors as $vendor => $result) {
      if ($result['status'] === VendorContractCheckInterface::FAIL) {
        $failed++;
        // One log line per vendor, with the vendor in the template, so each
        // failing vendor is its own Sentry issue rather than one shared one.
        $this->logger->error('Vendor contract check failed: ' . $vendor . ' (@reason). The site sends requests this provider no longer accepts, or the provider is unreachable.', [
          '@reason' => $result['reason'],
        ]);
      }
    }

    $report = [
      'ok' => $failed === 0,
      'checked_at' => $this->time->getCurrentTime(),
      'vendors' => $vendors,
    ];
    $this->state->set(self::STATE_LAST_REPORT, $report);

    if ($failed === 0) {
      $this->logger->info('Vendor contract check passed: @summary', [
        '@summary' => implode(', ', array_map(
          static fn(string $vendor, array $result): string => $vendor . '=' . $result['status'],
          array_keys($vendors),
          $vendors,
        )),
      ]);
    }

    return $report;
  }

}
