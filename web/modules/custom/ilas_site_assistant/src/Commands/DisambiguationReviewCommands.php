<?php

declare(strict_types=1);

namespace Drupal\ilas_site_assistant\Commands;

use Drupal\ilas_site_assistant\Service\AnalyticsLogger;
use Drush\Commands\DrushCommands;

/**
 * Read-only Drush commands for ambiguity/disambiguation review.
 */
class DisambiguationReviewCommands extends DrushCommands {

  /**
   * The analytics logger.
   */
  protected AnalyticsLogger $analyticsLogger;

  /**
   * Constructs a DisambiguationReviewCommands.
   */
  public function __construct(AnalyticsLogger $analytics_logger) {
    parent::__construct();
    $this->analyticsLogger = $analytics_logger;
  }

  /**
   * Summarize privacy-safe ambiguity aggregates for disambiguation tuning.
   *
   * @param array $options
   *   Command options.
   *
   * @command ilas:disambiguation-review
   * @aliases disambiguation-review
   * @option days Rolling lookback window in days.
   * @option limit Maximum rows per section.
   * @usage ilas:disambiguation-review
   *   Print the last 30 days of ambiguity aggregates.
   */
  public function disambiguationReview(array $options = ['days' => 30, 'limit' => 10]): int {
    $days = max(1, (int) ($options['days'] ?? 30));
    $limit = max(1, (int) ($options['limit'] ?? 10));
    $report = $this->buildReviewReport($days, $limit);

    $this->logger()->notice(sprintf('Disambiguation review window: last %d days', $report['days']));
    $this->emitSection('Top families', $report['families'], 'family');
    $this->emitSection('Top pairs', $report['pairs'], 'pair');
    $this->emitSection('Top ambiguity buckets', $report['buckets'], 'bucket');

    return 0;
  }

  /**
   * Builds a structured review report for tests and command output.
   */
  public function buildReviewReport(int $days = 30, int $limit = 10): array {
    $triggerRows = $this->analyticsLogger->getEventTotals('disambiguation_trigger', $days, max(1, $limit * 4));
    $bucketRows = $this->analyticsLogger->getEventTotals('ambiguity_bucket', $days, $limit);

    $families = [];
    $pairs = [];

    foreach ($triggerRows as $row) {
      $meta = $this->parseKeyValueSummary((string) ($row->event_value ?? ''));
      $total = (int) ($row->total ?? 0);
      if (($meta['kind'] ?? '') === 'pair') {
        $pairs[] = [
          'pair' => (string) ($meta['name'] ?? 'unknown'),
          'count' => $total,
        ];
        continue;
      }

      $families[] = [
        'family' => (string) ($meta['name'] ?? 'unknown'),
        'count' => $total,
      ];
    }

    $families = array_slice($families, 0, $limit);
    $pairs = array_slice($pairs, 0, $limit);

    $buckets = [];
    foreach ($bucketRows as $row) {
      $buckets[] = [
        'bucket' => (string) ($row->event_value ?? ''),
        'count' => (int) ($row->total ?? 0),
      ];
    }

    return [
      'days' => $days,
      'families' => $families,
      'pairs' => $pairs,
      'buckets' => $buckets,
    ];
  }

  /**
   * Parses stable key=value analytics summaries.
   */
  protected function parseKeyValueSummary(string $value): array {
    $parsed = [];
    foreach (explode(',', $value) as $segment) {
      $parts = explode('=', $segment, 2);
      if (count($parts) !== 2) {
        continue;
      }
      $key = trim($parts[0]);
      $parsed[$key] = trim($parts[1]);
    }
    return $parsed;
  }

  /**
   * Emits a simple section to the Drush logger.
   */
  protected function emitSection(string $title, array $rows, string $key): void {
    $this->logger()->notice($title . ':');
    if ($rows === []) {
      $this->logger()->notice('  none');
      return;
    }

    foreach ($rows as $row) {
      $label = (string) ($row[$key] ?? 'unknown');
      $count = (int) ($row['count'] ?? 0);
      $this->logger()->notice(sprintf('  %s (%d)', $label, $count));
    }
  }

}
