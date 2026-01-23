<?php

namespace Drupal\ilas_site_assistant\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Component\Datetime\TimeInterface;

/**
 * Service for logging non-PII analytics data.
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
   * The policy filter service.
   *
   * @var \Drupal\ilas_site_assistant\Service\PolicyFilter|null
   */
  protected $policyFilter;

  /**
   * Constructs an AnalyticsLogger object.
   */
  public function __construct(
    Connection $database,
    ConfigFactoryInterface $config_factory,
    TimeInterface $time
  ) {
    $this->database = $database;
    $this->configFactory = $config_factory;
    $this->time = $time;
  }

  /**
   * Sets the policy filter service (for lazy loading to avoid circular dependency).
   *
   * @param \Drupal\ilas_site_assistant\Service\PolicyFilter $policy_filter
   *   The policy filter service.
   */
  public function setPolicyFilter(PolicyFilter $policy_filter) {
    $this->policyFilter = $policy_filter;
  }

  /**
   * Gets the policy filter service.
   *
   * @return \Drupal\ilas_site_assistant\Service\PolicyFilter
   *   The policy filter service.
   */
  protected function getPolicyFilter() {
    if (!$this->policyFilter) {
      $this->policyFilter = \Drupal::service('ilas_site_assistant.policy_filter');
    }
    return $this->policyFilter;
  }

  /**
   * Logs an event.
   *
   * @param string $event_type
   *   The event type (chat_open, topic_selected, resource_click, etc.).
   * @param string $event_value
   *   The event value (topic name, URL path, etc.) - must be non-PII.
   */
  public function log(string $event_type, string $event_value = '') {
    $config = $this->configFactory->get('ilas_site_assistant.settings');

    if (!$config->get('enable_logging')) {
      return;
    }

    // Sanitize event value to ensure no PII.
    $event_value = $this->sanitizeEventValue($event_value);

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
      \Drupal::logger('ilas_site_assistant')->error('Analytics logging failed: @message', [
        '@message' => $e->getMessage(),
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

    // Sanitize the query to remove any PII.
    $sanitized = $this->getPolicyFilter()->sanitizeForStorage($query);

    // Skip if sanitized query is too short or empty.
    if (strlen($sanitized) < 3) {
      return;
    }

    // Create a hash for deduplication.
    $hash = hash('sha256', $sanitized);
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
            'sanitized_query' => $sanitized,
            'count' => 1,
            'first_seen' => $now,
            'last_seen' => $now,
          ])
          ->execute();
      }
    }
    catch (\Exception $e) {
      \Drupal::logger('ilas_site_assistant')->error('No-answer logging failed: @message', [
        '@message' => $e->getMessage(),
      ]);
    }

    // Also log as a regular event for counting.
    $this->log('no_answer', '');
  }

  /**
   * Sanitizes an event value to ensure it contains no PII.
   *
   * @param string $value
   *   The value to sanitize.
   *
   * @return string
   *   Sanitized value.
   */
  protected function sanitizeEventValue(string $value) {
    // Truncate to prevent overly long values.
    $value = mb_substr($value, 0, 255);

    // Remove potential email addresses.
    $value = preg_replace('/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/', '', $value);

    // Remove potential phone numbers.
    $value = preg_replace('/\b(\d{3}[-.\s]?\d{3}[-.\s]?\d{4}|\(\d{3}\)\s*\d{3}[-.\s]?\d{4})\b/', '', $value);

    // Normalize whitespace.
    $value = preg_replace('/\s+/', ' ', trim($value));

    return $value;
  }

  /**
   * Cleans up old analytics data based on retention settings.
   */
  public function cleanupOldData() {
    $config = $this->configFactory->get('ilas_site_assistant.settings');
    $retention_days = $config->get('log_retention_days') ?? 90;

    $cutoff_date = date('Y-m-d', strtotime("-{$retention_days} days"));

    try {
      // Clean up stats table.
      $this->database->delete('ilas_site_assistant_stats')
        ->condition('date', $cutoff_date, '<')
        ->execute();

      // Clean up no-answer table.
      $cutoff_timestamp = strtotime("-{$retention_days} days");
      $this->database->delete('ilas_site_assistant_no_answer')
        ->condition('last_seen', $cutoff_timestamp, '<')
        ->execute();
    }
    catch (\Exception $e) {
      \Drupal::logger('ilas_site_assistant')->error('Analytics cleanup failed: @message', [
        '@message' => $e->getMessage(),
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
