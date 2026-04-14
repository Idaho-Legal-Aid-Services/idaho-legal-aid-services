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
   * Maximum allowed retention for analytics data (days).
   */
  const MAX_RETENTION_DAYS = 730;

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
      $this->database->merge('ilas_site_assistant_stats')
        ->keys([
          'event_type' => $event_type,
          'event_value' => $event_value,
          'date' => $date,
        ])
        ->fields([
          'event_type' => $event_type,
          'event_value' => $event_value,
          'date' => $date,
          'count' => 1,
        ])
        ->expression('count', 'count + 1')
        ->execute();
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
   * Logs privacy-safe ambiguity analytics for a disambiguation response.
   *
   * @param array $intent
   *   The disambiguation intent payload.
   * @param string $message
   *   The raw user message. Only minimized metadata is retained.
   */
  public function logDisambiguation(array $intent, string $message): void {
    $stableFamily = (string) ($intent['family'] ?? $intent['reason'] ?? 'unknown');
    $pairKey = (string) ($intent['pair_key'] ?? $this->buildPairKeyFromCompetingIntents($intent['competing_intents'] ?? []));

    $triggerPayload = [
      'kind' => $pairKey !== '' ? 'pair' : 'family',
      'name' => $pairKey !== '' ? $pairKey : $stableFamily,
    ];
    $this->log('disambiguation_trigger', json_encode($triggerPayload));

    $metadata = ObservabilityPayloadMinimizer::buildTextMetadataWithLanguage($message);
    $bucketPayload = [
      'family' => $stableFamily,
      'lang' => $metadata['language_hint'],
      'len' => $metadata['length_bucket'],
      'pair' => $pairKey !== '' ? $pairKey : 'none',
    ];
    $this->log('ambiguity_bucket', json_encode($bucketPayload));
  }

  /**
   * Logs a "no answer" query for content gap analysis.
   *
   * @param string $query
   *   The user's query that had no results.
   * @param array $context
   *   Optional governance context for canonical gap-item creation.
   *
   * @return int|null
   *   Canonical assistant gap-item ID when governance storage is active.
   */
  public function logNoAnswer(string $query, array $context = []): ?int {
    $gap_item_id = NULL;
    if (\Drupal::hasService('ilas_site_assistant_governance.gap_item_manager')) {
      try {
        $gap_item_id = \Drupal::service('ilas_site_assistant_governance.gap_item_manager')
          ->recordNoAnswer($query, $context);
      }
      catch (\Throwable $e) {
        $this->logger->error('Governance no-answer logging failed: @class @error_signature', [
          '@class' => get_class($e),
          '@error_signature' => ObservabilityPayloadMinimizer::exceptionSignature($e),
        ]);
      }
    }

    $config = $this->configFactory->get('ilas_site_assistant.settings');

    if (!$config->get('enable_logging')) {
      return $gap_item_id;
    }

    $metadata = ObservabilityPayloadMinimizer::buildTextMetadataWithLanguage($query);

    // Skip empty normalized queries.
    if ($metadata['length_bucket'] === ObservabilityPayloadMinimizer::LENGTH_BUCKET_EMPTY) {
      return $gap_item_id;
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

    return $gap_item_id;
  }

  /**
   * Cleans up old analytics data based on retention settings.
   *
   * Uses batched deletes (500 rows per iteration, max 100 iterations)
   * to avoid locking tables during cron.
   */
  public function cleanupOldData() {
    $config = $this->configFactory->get('ilas_site_assistant.settings');
    $retention_days = min(
      (int) ($config->get('log_retention_days') ?? 730),
      self::MAX_RETENTION_DAYS,
    );

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

  /**
   * Gets aggregated totals for an event type over the given window.
   *
   * @param string $event_type
   *   The event type to query.
   * @param int $days
   *   Number of days to look back.
   * @param int $limit
   *   Maximum rows to return.
   *
   * @return array
   *   Array of totals keyed by event_value.
   */
  public function getEventTotals(string $event_type, int $days = 30, int $limit = 10): array {
    $start_date = date('Y-m-d', strtotime("-{$days} days"));

    $query = $this->database->select('ilas_site_assistant_stats', 's')
      ->fields('s', ['event_value'])
      ->condition('event_type', $event_type)
      ->condition('date', $start_date, '>=')
      ->orderBy('total', 'DESC')
      ->range(0, max(1, $limit));
    $query->addExpression('SUM(count)', 'total');
    $query->groupBy('event_value');

    return $query->execute()->fetchAll();
  }

  /**
   * Builds a stable pair key from competing intents.
   *
   * @param array $competing_intents
   *   Competing intent rows.
   *
   * @return string
   *   Sorted pair key or empty string.
   */
  protected function buildPairKeyFromCompetingIntents(array $competing_intents): string {
    if (count($competing_intents) < 2) {
      return '';
    }

    $intentOne = (string) ($competing_intents[0]['intent'] ?? '');
    $intentTwo = (string) ($competing_intents[1]['intent'] ?? '');
    if ($intentOne === '' || $intentTwo === '') {
      return '';
    }

    $pair = [$intentOne, $intentTwo];
    sort($pair);
    return implode(':', $pair);
  }

}
