<?php

declare(strict_types=1);

namespace Drupal\ilas_site_assistant\Commands;

use Drupal\ilas_site_assistant\Service\LangfuseTraceLookupService;
use Drush\Commands\DrushCommands;

/**
 * Drush command for sanitized Langfuse trace lookup.
 */
class LangfuseLookupCommands extends DrushCommands {

  /**
   * Constructs a Langfuse lookup command handler.
   */
  public function __construct(
    protected LangfuseTraceLookupService $traceLookupService,
  ) {
    parent::__construct();
  }

  /**
   * Lookup a Langfuse trace by ID and print sanitized proof data as JSON.
   *
   * @param string $trace_id
   *   Trace ID to fetch from Langfuse.
   * @param array $options
   *   Command options.
   *
   * @command ilas:langfuse-lookup
   * @aliases langfuse-lookup
   * @option attempts Number of lookup attempts before returning not found.
   * @option delay-ms Delay between lookup retries in milliseconds.
   * @usage ilas:langfuse-lookup 1234-5678
   *   Print a sanitized Langfuse trace proof snapshot as JSON.
   */
  public function langfuseLookup(string $trace_id, array $options = ['attempts' => 30, 'delay-ms' => 2000]): int {
    $attempts = max(1, (int) ($options['attempts'] ?? 30));
    $delayMs = max(0, (int) ($options['delay-ms'] ?? 2000));

    try {
      $result = $this->traceLookupService->lookupTrace($trace_id, $attempts, $delayMs);
    }
    catch (\Throwable $throwable) {
      fwrite(STDERR, sprintf("Langfuse trace lookup failed: %s\n", $throwable->getMessage()));
      return 1;
    }

    print json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    return !empty($result['found']) ? 0 : 1;
  }

}
