<?php

declare(strict_types=1);

namespace Drupal\ilas_site_assistant_governance\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\State\StateInterface;
use Psr\Log\LoggerInterface;

/**
 * Writes the reviewed-gap promptfoo candidate export on a weekly cadence.
 *
 * The candidate YAML lands in the private file system so reviewers can
 * download it from the gap queue without a drush session. It is never
 * written into promptfoo-evals/tests: promotion stays a human step.
 */
final class CandidateExportScheduler {

  public const STATE_KEY = 'ilas_site_assistant_governance.candidate_export.last_run';
  public const INTERVAL_SECONDS = 7 * 86400;
  public const DIRECTORY = 'private://ilas-assistant-governance';
  public const FILENAME = 'reviewed-gaps.candidate.yaml';

  /**
   * Constructs the scheduler.
   */
  public function __construct(
    private readonly ReviewedGapPromptfooCandidateExporter $exporter,
    private readonly StateInterface $state,
    private readonly FileSystemInterface $fileSystem,
    private readonly TimeInterface $time,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Returns the candidate file URI.
   */
  public function getUri(): string {
    return self::DIRECTORY . '/' . self::FILENAME;
  }

  /**
   * Returns the real path of the candidate file when it exists.
   */
  public function getRealPath(): ?string {
    $path = $this->fileSystem->realpath($this->getUri());
    return is_string($path) && is_file($path) ? $path : NULL;
  }

  /**
   * Returns the last run summary, if any.
   */
  public function getLastRun(): ?array {
    $last = $this->state->get(self::STATE_KEY);
    return is_array($last) ? $last : NULL;
  }

  /**
   * Returns TRUE when a week has passed since the last successful run.
   */
  public function isDue(): bool {
    $last = $this->getLastRun();
    $ran_at = (int) ($last['ran_at'] ?? 0);
    return $ran_at <= 0 || ($this->time->getRequestTime() - $ran_at) >= self::INTERVAL_SECONDS;
  }

  /**
   * Runs the export when due (or when forced) and records the outcome.
   *
   * @return array|null
   *   Run summary (ran_at, candidates, considered, uri, written, error) or
   *   NULL when the run was skipped because it was not due.
   */
  public function runIfDue(bool $force = FALSE): ?array {
    if (!$force && !$this->isDue()) {
      return NULL;
    }

    $now = $this->time->getRequestTime();
    $summary = [
      'ran_at' => $now,
      'candidates' => 0,
      'considered' => 0,
      'uri' => $this->getUri(),
      'written' => FALSE,
      'error' => NULL,
      'forced' => $force,
    ];

    try {
      if ($this->fileSystem->realpath('private://') === FALSE) {
        throw new \RuntimeException('The private file system is not configured; set file_private_path.');
      }
      $directory = self::DIRECTORY;
      if (!$this->fileSystem->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS)) {
        throw new \RuntimeException('Could not prepare ' . self::DIRECTORY . '.');
      }

      $export = $this->exporter->buildExport([]);
      $summary['candidates'] = (int) ($export['stats']['exported'] ?? 0);
      $summary['considered'] = (int) ($export['stats']['considered'] ?? 0);
      $summary['skipped'] = $export['stats']['skipped'] ?? [];

      $this->fileSystem->saveData($this->exporter->renderCandidateYaml($export), $this->getUri(), FileExists::Replace);
      $summary['written'] = TRUE;
      $this->logger->info('Weekly reviewed-gap promptfoo export wrote @count candidate case(s) to @uri.', [
        '@count' => $summary['candidates'],
        '@uri' => $this->getUri(),
      ]);
    }
    catch (\Throwable $e) {
      $summary['error'] = $e->getMessage();
      $this->logger->error('Weekly reviewed-gap promptfoo export failed: @message', ['@message' => $e->getMessage()]);
    }

    $this->state->set(self::STATE_KEY, $summary);
    return $summary;
  }

}
