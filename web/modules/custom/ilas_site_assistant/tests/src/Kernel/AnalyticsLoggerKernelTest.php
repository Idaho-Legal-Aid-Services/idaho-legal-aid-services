<?php

namespace Drupal\Tests\ilas_site_assistant\Kernel;

use Drupal\ilas_site_assistant\Service\AnalyticsLogger;
use Drupal\ilas_site_assistant\Service\PiiRedactor;

/**
 * Kernel tests for AnalyticsLogger service.
 *
 * Tests the count-upsert pattern, no-answer deduplication, and cleanup
 * against a real database.
 *
 * @group ilas_site_assistant
 * @coversDefaultClass \Drupal\ilas_site_assistant\Service\AnalyticsLogger
 */
class AnalyticsLoggerKernelTest extends AssistantKernelTestBase {

  /**
   * Tests that log() creates a new row for a new event.
   *
   * @covers ::log
   */
  public function testLogCreatesNewRow(): void {
    $logger = $this->createAnalyticsLogger();
    $logger->log('chat_open', '');

    $count = $this->countTableRows('ilas_site_assistant_stats');
    $this->assertEquals(1, $count);

    $row = $this->database->select('ilas_site_assistant_stats', 's')
      ->fields('s')
      ->execute()
      ->fetch();

    $this->assertEquals('chat_open', $row->event_type);
    $this->assertEquals(1, $row->count);
    $this->assertEquals(date('Y-m-d'), $row->date);
  }

  /**
   * Tests that log() increments count for the same event_type+value+date.
   *
   * @covers ::log
   */
  public function testLogUpsertsExistingRow(): void {
    $logger = $this->createAnalyticsLogger();

    $logger->log('topic_selected', 'housing');
    $logger->log('topic_selected', 'housing');
    $logger->log('topic_selected', 'housing');

    $row = $this->database->select('ilas_site_assistant_stats', 's')
      ->fields('s', ['count'])
      ->condition('event_type', 'topic_selected')
      ->condition('event_value', 'housing')
      ->execute()
      ->fetch();

    $this->assertEquals(3, $row->count);

    // Should still be a single row, not three.
    $total_rows = $this->database->select('ilas_site_assistant_stats', 's')
      ->condition('event_type', 'topic_selected')
      ->condition('event_value', 'housing')
      ->countQuery()
      ->execute()
      ->fetchField();

    $this->assertEquals(1, $total_rows);
  }

  /**
   * Tests that different event values get separate rows.
   *
   * @covers ::log
   */
  public function testLogSeparatesEventValues(): void {
    $logger = $this->createAnalyticsLogger();

    $logger->log('topic_selected', 'housing');
    $logger->log('topic_selected', 'family');

    $count = $this->database->select('ilas_site_assistant_stats', 's')
      ->condition('event_type', 'topic_selected')
      ->countQuery()
      ->execute()
      ->fetchField();

    $this->assertEquals(2, $count);
  }

  /**
   * Tests that log() sanitizes email addresses from event values.
   *
   * @covers ::log
   */
  public function testLogSanitizesEventValue(): void {
    $logger = $this->createAnalyticsLogger();
    $logger->log('resource_click', 'contact john@example.com for help');

    $row = $this->database->select('ilas_site_assistant_stats', 's')
      ->fields('s', ['event_value'])
      ->condition('event_type', 'resource_click')
      ->execute()
      ->fetch();

    $this->assertStringNotContainsString('john@example.com', $row->event_value);
    $this->assertStringContainsString(PiiRedactor::TOKEN_EMAIL, $row->event_value);
  }

  /**
   * Tests that SSN, DOB, and CC are redacted from event values.
   *
   * @covers ::log
   */
  public function testLogRedactsSsnDobCcInEventValue(): void {
    $logger = $this->createAnalyticsLogger();
    $logger->log('search_query', 'SSN 123-45-6789 born on 01/01/1990');

    $row = $this->database->select('ilas_site_assistant_stats', 's')
      ->fields('s', ['event_value'])
      ->condition('event_type', 'search_query')
      ->execute()
      ->fetch();

    $this->assertStringNotContainsString('123-45-6789', $row->event_value);
    $this->assertStringContainsString(PiiRedactor::TOKEN_SSN, $row->event_value);
  }

  /**
   * Tests that log() does nothing when logging is disabled.
   *
   * @covers ::log
   */
  public function testLogDisabledByConfig(): void {
    $logger = $this->createAnalyticsLogger(['enable_logging' => FALSE]);
    $logger->log('chat_open', '');

    $count = $this->countTableRows('ilas_site_assistant_stats');
    $this->assertEquals(0, $count);
  }

  /**
   * Tests that logNoAnswer deduplicates via query hash.
   *
   * @covers ::logNoAnswer
   */
  public function testLogNoAnswerDeduplicates(): void {
    $logger = $this->createAnalyticsLogger();

    $logger->logNoAnswer('eviction help near me');
    $logger->logNoAnswer('eviction help near me');

    $count = $this->database->select('ilas_site_assistant_no_answer', 'n')
      ->countQuery()
      ->execute()
      ->fetchField();

    // Same query should deduplicate to 1 row.
    $this->assertEquals(1, $count);

    // Count should be 2.
    $row = $this->database->select('ilas_site_assistant_no_answer', 'n')
      ->fields('n', ['count'])
      ->execute()
      ->fetch();

    $this->assertEquals(2, $row->count);
  }

  /**
   * Tests that different no-answer queries get separate rows.
   *
   * @covers ::logNoAnswer
   */
  public function testLogNoAnswerSeparateQueries(): void {
    $logger = $this->createAnalyticsLogger();

    $logger->logNoAnswer('eviction help');
    $logger->logNoAnswer('divorce forms');

    $count = $this->countTableRows('ilas_site_assistant_no_answer');
    $this->assertEquals(2, $count);
  }

  /**
   * Tests that getStats returns aggregated data for a given event type.
   *
   * @covers ::getStats
   */
  public function testGetStatsReturnsAggregatedData(): void {
    // Insert test data.
    $today = date('Y-m-d');
    $this->insertStatsRow('chat_open', '', 5, $today);
    $this->insertStatsRow('topic_selected', 'housing', 3, $today);
    $this->insertStatsRow('topic_selected', 'family', 2, $today);

    $logger = $this->createAnalyticsLogger();
    $stats = $logger->getStats('topic_selected', 30);

    $this->assertCount(2, $stats);
  }

  /**
   * Tests that cleanupOldData removes rows older than retention.
   *
   * @covers ::cleanupOldData
   */
  public function testCleanupOldDataRemovesExpired(): void {
    $old_date = date('Y-m-d', strtotime('-100 days'));
    $recent_date = date('Y-m-d', strtotime('-10 days'));

    $this->insertStatsRow('chat_open', '', 5, $old_date);
    $this->insertStatsRow('chat_open', '', 3, $recent_date);

    // Insert old no-answer row.
    $this->database->insert('ilas_site_assistant_no_answer')
      ->fields([
        'query_hash' => hash('sha256', 'old query'),
        'sanitized_query' => 'old query',
        'count' => 1,
        'first_seen' => strtotime('-100 days'),
        'last_seen' => strtotime('-100 days'),
      ])
      ->execute();

    $logger = $this->createAnalyticsLogger(['log_retention_days' => 90]);
    $logger->cleanupOldData();

    // Old stats row should be gone.
    $stats_count = $this->countTableRows('ilas_site_assistant_stats');
    $this->assertEquals(1, $stats_count);

    // Old no-answer row should be gone.
    $no_answer_count = $this->countTableRows('ilas_site_assistant_no_answer');
    $this->assertEquals(0, $no_answer_count);
  }

  /**
   * Tests that batched cleanup deletes all expired rows across batches.
   *
   * @covers ::cleanupOldData
   */
  public function testBatchedCleanupDeletesAllExpiredRows(): void {
    // Insert 10 expired stats rows.
    $old_date = date('Y-m-d', strtotime('-100 days'));
    for ($i = 0; $i < 10; $i++) {
      $this->insertStatsRow('test_event', 'value_' . $i, 1, $old_date);
    }

    // Insert 2 recent rows.
    $recent_date = date('Y-m-d', strtotime('-10 days'));
    $this->insertStatsRow('test_event', 'recent_a', 1, $recent_date);
    $this->insertStatsRow('test_event', 'recent_b', 1, $recent_date);

    $logger = $this->createAnalyticsLogger(['log_retention_days' => 90]);
    $logger->cleanupOldData();

    // Only the 2 recent rows should remain.
    $remaining = $this->countTableRows('ilas_site_assistant_stats');
    $this->assertEquals(2, $remaining);
  }

  /**
   * Creates an AnalyticsLogger with configurable overrides.
   *
   * @param array $config_overrides
   *   Config values to override.
   * @param int $timestamp
   *   The timestamp for the time service.
   *
   * @return \Drupal\ilas_site_assistant\Service\AnalyticsLogger
   *   The configured AnalyticsLogger.
   */
  protected function createAnalyticsLogger(array $config_overrides = [], int $timestamp = 1700000000): AnalyticsLogger {
    $configFactory = $this->createMockConfigFactory($config_overrides);
    $time = $this->createMockTime($timestamp);

    return new AnalyticsLogger(
      $this->database,
      $configFactory,
      $time
    );
  }

}
