<?php

declare(strict_types=1);

namespace Drupal\ilas_site_assistant_governance\Commands;

use Drupal\ilas_site_assistant_governance\Service\ReviewedGapPromptfooCandidateExporter;
use Drush\Commands\DrushCommands;

/**
 * Drush commands for exporting reviewed gap candidates to Promptfoo.
 */
final class ReviewedGapPromptfooExportCommands extends DrushCommands {

  private const DEFAULT_RELATIVE_OUTPUT = 'promptfoo-evals/output/reviewed-gaps.candidate.yaml';

  /**
   * Constructs the command handler.
   */
  public function __construct(
    private readonly ReviewedGapPromptfooCandidateExporter $exporter,
  ) {
    parent::__construct();
  }

  /**
   * Export reviewed assistant gap candidates as untrusted Promptfoo YAML.
   *
   * @param array $options
   *   Command options.
   *
   * @command ilas:export-reviewed-gaps-to-promptfoo
   * @aliases export-reviewed-gaps-to-promptfoo
   * @option days Rolling lookback window in days. Use 0 for all time.
   * @option limit Maximum candidate cases to include.
   * @option states Comma-separated review states to export.
   * @option output Relative or absolute output path for --write.
   * @option write Write candidate YAML. Defaults to dry-run only.
   * @option include-held Include legal-hold records. Defaults to false.
   * @option include-archived Include archived records. Defaults to false.
   * @usage ilas:export-reviewed-gaps-to-promptfoo
   *   Dry-run the reviewed gap export and print a summary.
   * @usage ilas:export-reviewed-gaps-to-promptfoo --write
   *   Write promptfoo-evals/output/reviewed-gaps.candidate.yaml.
   */
  public function exportReviewedGapsToPromptfoo(
    array $options = [
      'days' => ReviewedGapPromptfooCandidateExporter::DEFAULT_DAYS,
      'limit' => ReviewedGapPromptfooCandidateExporter::DEFAULT_LIMIT,
      'states' => 'reviewed,resolved',
      'output' => self::DEFAULT_RELATIVE_OUTPUT,
      'write' => FALSE,
      'include-held' => FALSE,
      'include-archived' => FALSE,
    ],
  ): int {
    $write = $this->toBool($options['write'] ?? FALSE);
    $output_path = $this->resolveOutputPath((string) ($options['output'] ?? self::DEFAULT_RELATIVE_OUTPUT));

    $export = $this->exporter->buildExport([
      'days' => $options['days'] ?? ReviewedGapPromptfooCandidateExporter::DEFAULT_DAYS,
      'limit' => $options['limit'] ?? ReviewedGapPromptfooCandidateExporter::DEFAULT_LIMIT,
      'states' => $options['states'] ?? 'reviewed,resolved',
      'include_held' => $options['include-held'] ?? FALSE,
      'include_archived' => $options['include-archived'] ?? FALSE,
    ]);

    if (!$write) {
      $summary = $this->summaryPayload($export, $output_path, TRUE);
      print json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
      return 0;
    }

    if ($this->isTrustedTestsPath($output_path)) {
      fwrite(STDERR, "Refusing to write reviewed-gap candidates directly into promptfoo-evals/tests. Write to promptfoo-evals/output first, then promote cases by human review.\n");
      return 1;
    }

    $directory = dirname($output_path);
    if (!is_dir($directory) && !mkdir($directory, 0775, TRUE) && !is_dir($directory)) {
      fwrite(STDERR, sprintf("Failed to create output directory: %s\n", $directory));
      return 1;
    }

    $yaml = $this->exporter->renderCandidateYaml($export);

    if (file_put_contents($output_path, $yaml) === FALSE) {
      fwrite(STDERR, sprintf("Failed to write candidate YAML: %s\n", $output_path));
      return 1;
    }

    $summary = $this->summaryPayload($export, $output_path, FALSE);
    print json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    return 0;
  }

  /**
   * Builds a compact command summary.
   */
  private function summaryPayload(array $export, string $output_path, bool $dry_run): array {
    return [
      'dry_run' => $dry_run,
      'output_path' => $output_path,
      'generated_at' => $export['generated_at'] ?? NULL,
      'source' => $export['source'] ?? NULL,
      'options' => $export['options'] ?? [],
      'stats' => $export['stats'] ?? [],
      'candidate_preview' => array_slice($export['candidates'] ?? [], 0, 3),
      'human_review_required' => TRUE,
      'promotion_target' => 'promptfoo-evals/tests after privacy and overfit review',
    ];
  }

  /**
   * Resolves a relative output path against the repository root.
   */
  private function resolveOutputPath(string $output): string {
    $output = trim($output) !== '' ? trim($output) : self::DEFAULT_RELATIVE_OUTPUT;
    if (str_starts_with($output, '/')) {
      return $output;
    }

    $repo_root = defined('DRUPAL_ROOT') ? dirname(DRUPAL_ROOT) : getcwd();
    return rtrim((string) $repo_root, '/') . '/' . ltrim($output, '/');
  }

  /**
   * Returns TRUE when a path points at trusted Promptfoo test fixtures.
   */
  private function isTrustedTestsPath(string $path): bool {
    $normalized = str_replace('\\', '/', $path);
    return str_contains($normalized, '/promptfoo-evals/tests/')
      || str_ends_with($normalized, '/promptfoo-evals/tests');
  }

  /**
   * Converts Drush-style option values to booleans.
   */
  private function toBool(mixed $value): bool {
    if (is_bool($value)) {
      return $value;
    }
    if (is_int($value)) {
      return $value !== 0;
    }
    if (is_string($value)) {
      return !in_array(mb_strtolower(trim($value)), ['', '0', 'false', 'no', 'off'], TRUE);
    }
    return !empty($value);
  }

}
