<?php

namespace Drupal\Tests\ilas_site_assistant\Kernel;

use Drupal\ilas_site_assistant\Service\AnalyticsLogger;
use Drupal\ilas_site_assistant\Service\ObservabilityPayloadMinimizer;
use Psr\Log\LoggerInterface;

/**
 * Kernel tests for AnalyticsLogger service.
 *
 * Tests the count-upsert pattern, metadata-only no-answer storage, and
 * normalization of analytics event values against a real database.
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
    $this->assertSame('', $row->event_value);
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

    $logger->log('topic_selected', '42');
    $logger->log('topic_selected', '42');
    $logger->log('topic_selected', '42');

    $row = $this->database->select('ilas_site_assistant_stats', 's')
      ->fields('s', ['count'])
      ->condition('event_type', 'topic_selected')
      ->condition('event_value', '42')
      ->execute()
      ->fetch();

    $this->assertEquals(3, $row->count);

    $total_rows = $this->database->select('ilas_site_assistant_stats', 's')
      ->condition('event_type', 'topic_selected')
      ->condition('event_value', '42')
      ->countQuery()
      ->execute()
      ->fetchField();

    $this->assertEquals(1, $total_rows);
  }

  /**
   * Tests that different normalized event values get separate rows.
   *
   * @covers ::log
   */
  public function testLogSeparatesEventValues(): void {
    $logger = $this->createAnalyticsLogger();

    $logger->log('topic_selected', '42');
    $logger->log('topic_selected', '99');

    $count = $this->database->select('ilas_site_assistant_stats', 's')
      ->condition('event_type', 'topic_selected')
      ->countQuery()
      ->execute()
      ->fetchField();

    $this->assertEquals(2, $count);
  }

  /**
   * Tests that click analytics store only the URL path.
   *
   * @covers ::log
   */
  public function testLogNormalizesClickEventValueToPath(): void {
    $logger = $this->createAnalyticsLogger();
    $logger->log('resource_click', 'https://www.example.org/contact/offices/boise?foo=bar#section');

    $row = $this->database->select('ilas_site_assistant_stats', 's')
      ->fields('s', ['event_value'])
      ->condition('event_type', 'resource_click')
      ->execute()
      ->fetch();

    $this->assertSame('/contact/offices/boise', $row->event_value);
  }

  /**
   * Tests that unexpected free-text values are blanked instead of persisted.
   *
   * @covers ::log
   */
  public function testLogDropsUnexpectedFreeTextEventValue(): void {
    $logger = $this->createAnalyticsLogger();
    $logger->log('search_query', 'Mi nombre es Juan Garcia y necesito ayuda con 123 Main Street');

    $row = $this->database->select('ilas_site_assistant_stats', 's')
      ->fields('s', ['event_value'])
      ->condition('event_type', 'search_query')
      ->execute()
      ->fetch();

    $this->assertSame('', $row->event_value);
  }

  /**
   * Tests that clarify-loop analytics hash the conversation identifier.
   *
   * @covers ::log
   */
  public function testLogHashesClarifyLoopBreakIdentifier(): void {
    $logger = $this->createAnalyticsLogger();
    $conversationId = '12345678-1234-4123-8123-123456789abc';

    $logger->log('clarify_loop_break', $conversationId);

    $storedValue = $this->database->select('ilas_site_assistant_stats', 's')
      ->fields('s', ['event_value'])
      ->condition('event_type', 'clarify_loop_break')
      ->execute()
      ->fetchField();

    $this->assertSame(
      ObservabilityPayloadMinimizer::hashIdentifier($conversationId),
      $storedValue
    );
  }

  /**
   * Tests that A/B assignment analytics serialize to stable tokens only.
   *
   * @covers ::log
   */
  public function testLogNormalizesAbAssignments(): void {
    $logger = $this->createAnalyticsLogger();
    $logger->log('ab_assignment', '{"tone":"friendly","cta":"apply_first"}');

    $storedValue = $this->database->select('ilas_site_assistant_stats', 's')
      ->fields('s', ['event_value'])
      ->condition('event_type', 'ab_assignment')
      ->execute()
      ->fetchField();

    $this->assertSame('cta=apply_first,tone=friendly', $storedValue);
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

    $this->assertEquals(1, $count);

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
   * Tests that no-answer storage keeps metadata only and no text column.
   *
   * @covers ::logNoAnswer
   */
  public function testLogNoAnswerStoresMetadataOnly(): void {
    $logger = $this->createAnalyticsLogger();
    $query = 'Mi nombre es Juan Garcia y vivo en 123 Main Street Boise ID 83702';
    $expected = ObservabilityPayloadMinimizer::buildTextMetadataWithLanguage($query);

    $logger->logNoAnswer($query);

    $row = $this->database->select('ilas_site_assistant_no_answer', 'n')
      ->fields('n', ['query_hash', 'language_hint', 'length_bucket', 'redaction_profile'])
      ->execute()
      ->fetch();

    $this->assertFalse($this->database->schema()->fieldExists('ilas_site_assistant_no_answer', 'sanitized_query'));
    $this->assertSame($expected['text_hash'], $row->query_hash);
    $this->assertSame($expected['language_hint'], $row->language_hint);
    $this->assertSame($expected['length_bucket'], $row->length_bucket);
    $this->assertSame($expected['redaction_profile'], $row->redaction_profile);
  }

  /**
   * Tests that getStats returns aggregated data for a given event type.
   *
   * @covers ::getStats
   */
  public function testGetStatsReturnsAggregatedData(): void {
    $today = date('Y-m-d');
    $this->insertStatsRow('chat_open', '', 5, $today);
    $this->insertStatsRow('topic_selected', '42', 3, $today);
    $this->insertStatsRow('topic_selected', '99', 2, $today);

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

    $this->database->insert('ilas_site_assistant_no_answer')
      ->fields([
        'query_hash' => hash('sha256', 'old query'),
        'language_hint' => 'en',
        'length_bucket' => ObservabilityPayloadMinimizer::LENGTH_BUCKET_SHORT,
        'redaction_profile' => ObservabilityPayloadMinimizer::PROFILE_NONE,
        'count' => 1,
        'first_seen' => strtotime('-100 days'),
        'last_seen' => strtotime('-100 days'),
      ])
      ->execute();

    $logger = $this->createAnalyticsLogger(['log_retention_days' => 90]);
    $logger->cleanupOldData();

    $stats_count = $this->countTableRows('ilas_site_assistant_stats');
    $this->assertEquals(1, $stats_count);

    $no_answer_count = $this->countTableRows('ilas_site_assistant_no_answer');
    $this->assertEquals(0, $no_answer_count);
  }

  /**
   * Tests that batched cleanup deletes all expired rows across batches.
   *
   * @covers ::cleanupOldData
   */
  public function testBatchedCleanupDeletesAllExpiredRows(): void {
    $old_date = date('Y-m-d', strtotime('-100 days'));
    for ($i = 0; $i < 10; $i++) {
      $this->insertStatsRow('test_event', 'value_' . $i, 1, $old_date);
    }

    $recent_date = date('Y-m-d', strtotime('-10 days'));
    $this->insertStatsRow('test_event', 'recent_a', 1, $recent_date);
    $this->insertStatsRow('test_event', 'recent_b', 1, $recent_date);

    $logger = $this->createAnalyticsLogger(['log_retention_days' => 90]);
    $logger->cleanupOldData();

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
  protected function createAnalyticsLogger(array $config_overrides = [], int $timestamp = 1700000000, ?LoggerInterface $logger = NULL): AnalyticsLogger {
    $configFactory = $this->createMockConfigFactory($config_overrides);
    $time = $this->createMockTime($timestamp);
    $logger ??= $this->createStub(LoggerInterface::class);

    return new AnalyticsLogger(
      $this->database,
      $configFactory,
      $time,
      $logger
    );
  }

}
