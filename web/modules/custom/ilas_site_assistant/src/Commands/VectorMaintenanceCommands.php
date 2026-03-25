<?php

declare(strict_types=1);

namespace Drupal\ilas_site_assistant\Commands;

use Drupal\ilas_site_assistant\Service\VectorIndexHygieneService;
use Drush\Commands\DrushCommands;

/**
 * Drush commands for vector index status, resume, and rebuild workflows.
 */
final class VectorMaintenanceCommands extends DrushCommands {

  /**
   * Constructs a VectorMaintenanceCommands handler.
   */
  public function __construct(
    protected VectorIndexHygieneService $vectorIndexHygiene,
  ) {
    parent::__construct();
  }

  /**
   * Print JSON status for one managed vector index or all managed indexes.
   *
   * @param string|null $index_key
   *   Optional managed index key (`faq_vector` or `resource_vector`).
   * @param array $options
   *   Command options.
   *
   * @command ilas:vector-status
   * @aliases vector-status
   * @option probe-now Force a live semantic queryability probe before printing status.
   * @usage ilas:vector-status
   *   Print all managed vector-index status as JSON.
   * @usage ilas:vector-status faq_vector --probe-now
   *   Print FAQ vector status after forcing a live probe.
   */
  public function vectorStatus(?string $index_key = NULL, array $options = ['probe-now' => FALSE]): int {
    if ($index_key !== NULL && $this->vectorIndexHygiene->getManagedIndexId($index_key) === NULL) {
      fwrite(STDERR, sprintf("Unknown managed vector index key: %s\n", $index_key));
      return 1;
    }

    try {
      $snapshot = $this->vectorIndexHygiene->refreshSnapshot((bool) ($options['probe-now'] ?? FALSE), $index_key);
    }
    catch (\Throwable $throwable) {
      fwrite(STDERR, sprintf("Failed to refresh vector status: %s\n", $throwable->getMessage()));
      return 1;
    }

    if ($index_key !== NULL) {
      $index_snapshot = $snapshot['indexes'][$index_key] ?? [];
      $payload = [
        'index_key' => $index_key,
        'overall_status' => $snapshot['status'] ?? 'unknown',
        'thresholds' => $snapshot['thresholds'] ?? [],
      ] + $this->formatIndexStatus($index_snapshot);
    }
    else {
      $indexes = [];
      foreach (($snapshot['indexes'] ?? []) as $managed_key => $index_snapshot) {
        if (!is_array($index_snapshot)) {
          continue;
        }
        $indexes[$managed_key] = $this->formatIndexStatus($index_snapshot);
      }

      $payload = [
        'recorded_at' => $snapshot['recorded_at'] ?? NULL,
        'status' => $snapshot['status'] ?? 'unknown',
        'totals' => $snapshot['totals'] ?? [],
        'thresholds' => $snapshot['thresholds'] ?? [],
        'indexes' => $indexes,
      ];
    }

    print json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    return 0;
  }

  /**
   * Resume or explicitly rebuild a managed vector index with paced batching.
   *
   * @param string $index_key
   *   Managed index key (`faq_vector` or `resource_vector`).
   * @param array $options
   *   Command options.
   *
   * @command ilas:vector-backfill
   * @aliases vector-backfill
   * @option batch-size Search API items per indexing call (default: 5).
   * @option max-batches Maximum indexing calls in this run (default: 1).
   * @option sleep-seconds Pause between batches when another batch will run.
   * @option until-complete Continue until tracker remaining reaches zero or another stop reason is hit.
   * @option clear-first Explicitly clear the selected Search API index before indexing.
   * @usage ilas:vector-backfill faq_vector
   *   Resume the FAQ vector index with one conservative batch.
   * @usage ilas:vector-backfill resource_vector --until-complete --sleep-seconds=60
   *   Pace a longer resource backfill until completion.
   * @usage ilas:vector-backfill faq_vector --clear-first --until-complete
   *   Explicitly rebuild the FAQ vector index from empty.
   */
  public function vectorBackfill(
    string $index_key,
    array $options = [
      'batch-size' => 5,
      'max-batches' => 1,
      'sleep-seconds' => 0,
      'until-complete' => FALSE,
      'clear-first' => FALSE,
    ],
  ): int {
    if ($this->vectorIndexHygiene->getManagedIndexId($index_key) === NULL) {
      fwrite(STDERR, sprintf("Unknown managed vector index key: %s\n", $index_key));
      return 1;
    }

    try {
      $report = $this->vectorIndexHygiene->backfillIndex(
        $index_key,
        max(1, (int) ($options['batch-size'] ?? 5)),
        max(1, (int) ($options['max-batches'] ?? 1)),
        max(0, (int) ($options['sleep-seconds'] ?? 0)),
        (bool) ($options['until-complete'] ?? FALSE),
        (bool) ($options['clear-first'] ?? FALSE),
      );
    }
    catch (\Throwable $throwable) {
      fwrite(STDERR, sprintf("Vector backfill failed: %s\n", $throwable->getMessage()));
      return 1;
    }

    print json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    return 0;
  }

  /**
   * Formats one index snapshot for operator-oriented JSON output.
   *
   * @param array $index_snapshot
   *   Managed index snapshot.
   *
   * @return array<string, mixed>
   *   Operator-friendly status payload.
   */
  protected function formatIndexStatus(array $index_snapshot): array {
    $total_items = (int) ($index_snapshot['total_items'] ?? 0);
    $indexed_items = (int) ($index_snapshot['indexed_items'] ?? 0);

    return [
      'index_id' => $index_snapshot['index_id'] ?? '',
      'hygiene_status' => $index_snapshot['status'] ?? 'unknown',
      'metadata_status' => $index_snapshot['metadata_status'] ?? 'unknown',
      'indexing_status' => $index_snapshot['indexing_status'] ?? 'unknown',
      'probe_status' => $index_snapshot['probe_status'] ?? 'unknown',
      'last_probe_at' => $index_snapshot['last_probe_at'] ?? NULL,
      'probe_error' => $index_snapshot['probe_error'] ?? NULL,
      'probe_passed_count' => (int) ($index_snapshot['probe_passed_count'] ?? 0),
      'probe_failed_count' => (int) ($index_snapshot['probe_failed_count'] ?? 0),
      'probe_evidence' => $index_snapshot['probe_evidence'] ?? [],
      'total_items' => $total_items,
      'indexed_items' => $indexed_items,
      'remaining_items' => (int) ($index_snapshot['remaining_items'] ?? 0),
      'percent_complete' => $total_items > 0
        ? round(min(100, max(0, ($indexed_items / $total_items) * 100)), 2)
        : 0.0,
      'last_stop_reason' => $index_snapshot['last_stop_reason'] ?? NULL,
      'last_error' => $index_snapshot['last_error'] ?? NULL,
    ];
  }

}
