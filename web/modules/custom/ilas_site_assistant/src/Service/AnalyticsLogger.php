<?php

namespace Drupal\ilas_site_assistant\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Component\Datetime\TimeInterface;
use Psr\Log\LoggerInterface;

/**
 * Service for logging minimized analytics data.
 */
class AnalyticsLogger {

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The time service.
   *
   * @var \Drupal\Component\Datetime\TimeInterface
   */
  protected $time;

  /**
   * The module logger.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected LoggerInterface $logger;

  /**
   * Constructs an AnalyticsLogger object.
   */
  public function __construct(
    Connection $database,
    ConfigFactoryInterface $config_factory,
    TimeInterface $time,
    LoggerInterface $logger
  ) {
    $this->database = $database;
    $this->configFactory = $config_factory;
    $this->time = $time;
    $this->logger = $logger;
  }

  /**
   * Logs an event.
   *
   * @param string $event_type
   *   The event type (chat_open, topic_selected, resource_click, etc.).
   * @param string $event_value
   *   The event value (path, ID, reason code, etc.).
   */
  public function log(string $event_type, string $event_value = '') {
    $config = $this->configFactory->get('ilas_site_assistant.settings');

    if (!$config->get('enable_logging')) {
      return;
    }

    $event_value = ObservabilityPayloadMinimizer::normalizeAnalyticsValue($event_type, $event_value);

    // Get today's date.
    $date = date('Y-m-d');

    try {
      // Try to update existing record.
      $updated = $this->database->update('ilas_site_assistant_stats')
        ->expression('count', 'count + 1')
        ->condition('event_type', $event_type)
        ->condition('event_value', $event_value)
        ->condition('date', $date)
        ->execute();

      // If no record was updated, insert new one.
      if ($updated === 0) {
        $this->database->insert('ilas_site_assistant_stats')
          ->fields([
            'event_type' => $event_type,
            'event_value' => $event_value,
            'count' => 1,
            'date' => $date,
          ])
          ->execute();
      }
    }
    catch (\Exception $e) {
      // Log error but don't break the user experience.
      $this->logger->error('Analytics logging failed: @class @error_signature', [
        '@class' => get_class($e),
        '@error_signature' => ObservabilityPayloadMinimizer::exceptionSignature($e),
      ]);
    }
  }

  /**
   * Logs a "no answer" query for content gap analysis.
   *
   * @param string $query
   *   The user's query that had no results.
   */
  public function logNoAnswer(string $query) {
    $config = $this->configFactory->get('ilas_site_assistant.settings');

    if (!$config->get('enable_logging')) {
      return;
    }

    $metadata = ObservabilityPayloadMinimizer::buildTextMetadataWithLanguage($query);

    // Skip empty normalized queries.
    if ($metadata['length_bucket'] === ObservabilityPayloadMinimizer::LENGTH_BUCKET_EMPTY) {
      return;
    }

    $hash = $metadata['text_hash'];
    $now = $this->time->getRequestTime();

    try {
      // Try to update existing record.
      $updated = $this->database->update('ilas_site_assistant_no_answer')
        ->expression('count', 'count + 1')
        ->fields(['last_seen' => $now])
        ->condition('query_hash', $hash)
        ->execute();

      // If no record was updated, insert new one.
      if ($updated === 0) {
        $this->database->insert('ilas_site_assistant_no_answer')
          ->fields([
            'query_hash' => $hash,
            'language_hint' => $metadata['language_hint'],
            'length_bucket' => $metadata['length_bucket'],
            'redaction_profile' => $metadata['redaction_profile'],
            'count' => 1,
            'first_seen' => $now,
            'last_seen' => $now,
          ])
          ->execute();
      }
    }
    catch (\Exception $e) {
      $this->logger->error('No-answer logging failed: @class @error_signature', [
        '@class' => get_class($e),
        '@error_signature' => ObservabilityPayloadMinimizer::exceptionSignature($e),
      ]);
    }

    // Also log as a regular event for counting.
    $this->log('no_answer', '');
  }

  /**
   * Cleans up old analytics data based on retention settings.
   *
   * Uses batched deletes (500 rows per iteration, max 100 iterations)
   * to avoid locking tables during cron.
   */
  public function cleanupOldData() {
    $config = $this->configFactory->get('ilas_site_assistant.settings');
    $retention_days = $config->get('log_retention_days') ?? 90;

    $cutoff_date = date('Y-m-d', strtotime("-{$retention_days} days"));
    $cutoff_timestamp = strtotime("-{$retention_days} days");

    $batch_size = 500;
    $max_iterations = 100;

    try {
      // Batched cleanup for stats table.
      for ($i = 0; $i < $max_iterations; $i++) {
        $ids = $this->database->select('ilas_site_assistant_stats', 's')
          ->fields('s', ['id'])
          ->condition('date', $cutoff_date, '<')
          ->range(0, $batch_size)
          ->execute()
          ->fetchCol();

        if (empty($ids)) {
          break;
        }

        $this->database->delete('ilas_site_assistant_stats')
          ->condition('id', $ids, 'IN')
          ->execute();

        if (count($ids) < $batch_size) {
          break;
        }
      }

      // Batched cleanup for no-answer table.
      for ($i = 0; $i < $max_iterations; $i++) {
        $ids = $this->database->select('ilas_site_assistant_no_answer', 'n')
          ->fields('n', ['id'])
          ->condition('last_seen', $cutoff_timestamp, '<')
          ->range(0, $batch_size)
          ->execute()
          ->fetchCol();

        if (empty($ids)) {
          break;
        }

        $this->database->delete('ilas_site_assistant_no_answer')
          ->condition('id', $ids, 'IN')
          ->execute();

        if (count($ids) < $batch_size) {
          break;
        }
      }
    }
    catch (\Exception $e) {
      $this->logger->error('Analytics cleanup failed: @class @error_signature', [
        '@class' => get_class($e),
        '@error_signature' => ObservabilityPayloadMinimizer::exceptionSignature($e),
      ]);
    }
  }

  /**
   * Gets aggregated statistics for reporting.
   *
   * @param string $event_type
   *   The event type to query.
   * @param int $days
   *   Number of days to look back.
   *
   * @return array
   *   Array of statistics.
   */
  public function getStats(string $event_type, int $days = 30) {
    $start_date = date('Y-m-d', strtotime("-{$days} days"));

    $query = $this->database->select('ilas_site_assistant_stats', 's')
      ->fields('s', ['event_value', 'date'])
      ->condition('event_type', $event_type)
      ->condition('date', $start_date, '>=')
      ->orderBy('date', 'DESC');
    $query->addExpression('SUM(count)', 'total');
    $query->groupBy('event_value');
    $query->groupBy('date');

    return $query->execute()->fetchAll();
  }

}
