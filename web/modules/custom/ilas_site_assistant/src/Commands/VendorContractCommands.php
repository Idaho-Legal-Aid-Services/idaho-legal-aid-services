<?php

declare(strict_types=1);

namespace Drupal\ilas_site_assistant\Commands;

use Drupal\ilas_site_assistant\Service\VendorContractMonitor;
use Drush\Commands\DrushCommands;

/**
 * Drush command for the vendor contract checks.
 */
final class VendorContractCommands extends DrushCommands {

  public function __construct(
    private readonly VendorContractMonitor $monitor,
  ) {
    parent::__construct();
  }

  /**
   * Check that Langfuse, Cohere, Voyage and Pinecone accept our requests.
   *
   * Sends one minimal real request to each provider that is enabled in this
   * runtime (Langfuse: one synthetic trace, written and read back). Runs
   * regardless of the weekly schedule and of vendor_contract_check.enabled.
   * Exits non-zero if any provider fails; a disabled provider is "skipped".
   *
   * @command ilas:vendor-contract-check
   * @aliases vendor-contract-check
   * @usage ilas:vendor-contract-check
   *   Print the per-vendor result as JSON.
   */
  public function vendorContractCheck(): int {
    $report = $this->monitor->run();
    $this->output()->writeln(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    return $report['ok'] ? self::EXIT_SUCCESS : self::EXIT_FAILURE;
  }

}
