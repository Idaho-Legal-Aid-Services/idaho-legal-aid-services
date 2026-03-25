<?php

namespace Drupal\employment_application\Commands;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\File\FileSystemInterface;
use Drupal\file\Entity\File;
use Drush\Commands\DrushCommands;

/**
 * Drush commands for Employment Application data management.
 */
class EmploymentApplicationCommands extends DrushCommands {

  /**
   * The database connection.
   */
  protected Connection $database;

  /**
   * The file system service.
   */
  protected FileSystemInterface $fileSystem;

  /**
   * The config factory.
   */
  protected ConfigFactoryInterface $configFactory;

  /**
   * Constructs EmploymentApplicationCommands.
   */
  public function __construct(
    Connection $database,
    FileSystemInterface $fileSystem,
    ConfigFactoryInterface $configFactory,
  ) {
    parent::__construct();
    $this->database = $database;
    $this->fileSystem = $fileSystem;
    $this->configFactory = $configFactory;
  }

  /**
   * Purge employment applications older than the retention period.
   *
   * @param array $options
   *   Command options.
   *
   * @option days
   *   Override the configured retention_days. Applications older than this
   *   many days will be deleted.
   * @option dry-run
   *   Show what would be deleted without actually deleting.
   *
   * @command employment-application:purge
   * @aliases eapurge
   * @usage drush employment-application:purge
   *   Purge applications using configured retention_days (default 365).
   * @usage drush employment-application:purge --days=180
   *   Purge applications older than 180 days.
   * @usage drush employment-application:purge --dry-run
   *   Show how many applications would be purged.
   */
  public function purge(array $options = ['days' => NULL, 'dry-run' => FALSE]): void {
    $retentionDays = $options['days'];
    if ($retentionDays === NULL) {
      $retentionDays = (int) $this->configFactory
        ->get('employment_application.settings')
        ->get('retention_days');
    }
    $retentionDays = (int) $retentionDays;

    if ($retentionDays <= 0) {
      $this->logger()->warning('Retention days is 0 or unset — nothing to purge. Use --days=N to override.');
      return;
    }

    $cutoff = \Drupal::time()->getRequestTime() - ($retentionDays * 86400);
    $cutoffDate = date('Y-m-d', $cutoff);

    // Count affected records.
    $count = (int) $this->database->select('employment_applications', 'ea')
      ->condition('submitted', $cutoff, '<')
      ->countQuery()
      ->execute()
      ->fetchField();

    if ($count === 0) {
      $this->logger()->success("No applications older than {$retentionDays} days (before {$cutoffDate}).");
      return;
    }

    if ($options['dry-run']) {
      $this->logger()->notice("[DRY RUN] Would purge {$count} application(s) submitted before {$cutoffDate}.");
      return;
    }

    if (!$this->io()->confirm("Permanently delete {$count} application(s) submitted before {$cutoffDate}?")) {
      $this->logger()->notice('Aborted.');
      return;
    }

    // Fetch and delete records with files.
    $results = $this->database->select('employment_applications', 'ea')
      ->fields('ea', ['id', 'application_id', 'file_data'])
      ->condition('submitted', $cutoff, '<')
      ->execute();

    $deleted = 0;
    $filesDeleted = 0;
    $logger = \Drupal::logger('employment_application');

    foreach ($results as $row) {
      // Collect file URIs before DB changes.
      $physicalFiles = [];
      $fileData = json_decode($row->file_data, TRUE);

      // Transaction: DB operations first (reversible via rollback).
      $transaction = $this->database->startTransaction();
      try {
        if (is_array($fileData)) {
          foreach ($fileData as $fieldFiles) {
            if (!is_array($fieldFiles)) {
              continue;
            }
            foreach ($fieldFiles as $fileRef) {
              $fid = $fileRef['fid'] ?? NULL;
              if ($fid) {
                $file = File::load($fid);
                if ($file) {
                  $physicalFiles[] = $file->getFileUri();
                  $file->delete();
                }
              }
            }
          }
        }

        $this->database->delete('employment_applications')
          ->condition('id', $row->id)
          ->execute();

        // Commit.
        unset($transaction);
      }
      catch (\Throwable $e) {
        $transaction->rollBack();
        $this->logger()->error("Failed to purge application {$row->application_id}: {$e->getMessage()}");
        continue;
      }

      // Outside transaction: best-effort secure deletion of physical files.
      foreach ($physicalFiles as $uri) {
        _employment_application_secure_delete_file($this->fileSystem, $uri, $logger);
      }

      $filesDeleted += count($physicalFiles);
      $deleted++;
    }

    $this->logger()->success("Purged {$deleted} application(s) and {$filesDeleted} file(s) older than {$retentionDays} days.");
  }

}
