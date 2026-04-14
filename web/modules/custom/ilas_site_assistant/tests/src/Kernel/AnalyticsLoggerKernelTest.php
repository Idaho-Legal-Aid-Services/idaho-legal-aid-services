<?php

namespace Drupal\Tests\ilas_site_assistant\Kernel;

use Drupal\ilas_site_assistant\Service\AnalyticsLogger;
use Drupal\ilas_site_assistant\Service\ObservabilityPayloadMinimizer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\LoggerInterface;

/**
 * Kernel tests for AnalyticsLogger service.
 *
 * Tests the count-upsert pattern, metadata-only no-answer storage, and
 * normalization of analytics event values against a real database.
 *
 */
#[CoversClass(AnalyticsLogger::class)]
#[Group('ilas_site_assistant')]
class AnalyticsLoggerKernelTest extends AssistantKernelTestBase {

  /**
   * Tests that log() creates a new row for a new event.
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
   */
  public function testLogDisabledByConfig(): void {
    $logger = $this->createAnalyticsLogger(['enable_logging' => FALSE]);
    $logger->log('chat_open', '');

    $count = $this->countTableRows('ilas_site_assistant_stats');
    $this->assertEquals(0, $count);
  }

  /**
   * Tests that logNoAnswer deduplicates via query hash.
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
   * Tests that disambiguation analytics only retain safe family metadata.
   */
  public function testLogDisambiguationStoresSafeFamilyMetadata(): void {
    $logger = $this->createAnalyticsLogger();
    $logger->logDisambiguation(
      [
        'type' => 'disambiguation',
        'family' => 'generic_help',
      ],
      'Please help me with this'
    );

    $trigger = $this->database->select('ilas_site_assistant_stats', 's')
      ->fields('s', ['event_value'])
      ->condition('event_type', 'disambiguation_trigger')
      ->execute()
      ->fetchField();
    $bucket = $this->database->select('ilas_site_assistant_stats', 's')
      ->fields('s', ['event_value'])
      ->condition('event_type', 'ambiguity_bucket')
      ->execute()
      ->fetchField();

    $this->assertSame('kind=family,name=generic_help', $trigger);
    $this->assertSame('family=generic_help,lang=en,len=1-24,pair=none', $bucket);
  }

  /**
   * Tests that pair-based disambiguation analytics retain only pair metadata.
   */
  public function testLogDisambiguationUsesStablePairMetadata(): void {
    $logger = $this->createAnalyticsLogger();
    $logger->logDisambiguation(
      [
        'type' => 'disambiguation',
        'family' => 'confusable_pair',
        'competing_intents' => [
          ['intent' => 'services_overview', 'confidence' => 0.61],
          ['intent' => 'apply_for_help', 'confidence' => 0.60],
        ],
      ],
      'What do you offer?'
    );

    $trigger = $this->database->select('ilas_site_assistant_stats', 's')
      ->fields('s', ['event_value'])
      ->condition('event_type', 'disambiguation_trigger')
      ->execute()
      ->fetchField();
    $bucket = $this->database->select('ilas_site_assistant_stats', 's')
      ->fields('s', ['event_value'])
      ->condition('event_type', 'ambiguity_bucket')
      ->execute()
      ->fetchField();

    $this->assertSame('kind=pair,name=apply_for_help:services_overview', $trigger);
    $this->assertSame('family=confusable_pair,lang=en,len=1-24,pair=apply_for_help:services_overview', $bucket);
  }

  /**
   * Tests that event totals aggregate across dates by event value.
   */
  public function testGetEventTotalsReturnsSummedRows(): void {
    $this->insertStatsRow('ambiguity_bucket', 'family=generic_help,lang=en,len=1-24,pair=none', 2, date('Y-m-d', strtotime('-1 day')));
    $this->insertStatsRow('ambiguity_bucket', 'family=generic_help,lang=en,len=1-24,pair=none', 3, date('Y-m-d'));
    $this->insertStatsRow('ambiguity_bucket', 'family=contact_method,lang=en,len=1-24,pair=none', 1, date('Y-m-d'));

    $logger = $this->createAnalyticsLogger();
    $totals = $logger->getEventTotals('ambiguity_bucket', 30, 10);

    $this->assertCount(2, $totals);
    $this->assertSame('family=generic_help,lang=en,len=1-24,pair=none', $totals[0]->event_value);
    $this->assertSame('5', (string) $totals[0]->total);
  }

  /**
   * Tests that cleanupOldData removes rows older than retention.
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
   * Tests that feedback_helpful events normalize the response type token.
   */
  public function testLogFeedbackHelpfulNormalizesResponseType(): void {
    $logger = $this->createAnalyticsLogger();
    $logger->log('feedback_helpful', 'faq');

    $row = $this->database->select('ilas_site_assistant_stats', 's')
      ->fields('s', ['event_value'])
      ->condition('event_type', 'feedback_helpful')
      ->execute()
      ->fetch();

    $this->assertSame('faq', $row->event_value);
  }

  /**
   * Tests that feedback_not_helpful events normalize the response type token.
   */
  public function testLogFeedbackNotHelpfulNormalizesResponseType(): void {
    $logger = $this->createAnalyticsLogger();
    $logger->log('feedback_not_helpful', 'resources');

    $row = $this->database->select('ilas_site_assistant_stats', 's')
      ->fields('s', ['event_value'])
      ->condition('event_type', 'feedback_not_helpful')
      ->execute()
      ->fetch();

    $this->assertSame('resources', $row->event_value);
  }

  /**
   * Tests that generic_answer events normalize the fallback level.
   */
  public function testLogGenericAnswerNormalizesFallbackLevel(): void {
    $logger = $this->createAnalyticsLogger();
    $logger->log('generic_answer', '3');

    $row = $this->database->select('ilas_site_assistant_stats', 's')
      ->fields('s', ['event_value'])
      ->condition('event_type', 'generic_answer')
      ->execute()
      ->fetch();

    $this->assertSame('3', $row->event_value);
  }

  /**
   * Tests that feedback events with free text are blanked.
   */
  public function testLogFeedbackRejectsUserText(): void {
    $logger = $this->createAnalyticsLogger();
    $logger->log('feedback_helpful', 'This answer helped me find eviction info at 123 Main St');

    $row = $this->database->select('ilas_site_assistant_stats', 's')
      ->fields('s', ['event_value'])
      ->condition('event_type', 'feedback_helpful')
      ->execute()
      ->fetch();

    $this->assertSame('', $row->event_value);
  }

  /**
   * Tests that batched cleanup deletes all expired rows across batches.
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
   * Tests cleanup respects MAX_RETENTION_DAYS cap even with high config value.
   */
  public function testCleanupRespectsMaxRetentionDaysCap(): void {
    // Set retention to 9999 days (far exceeds 730 cap).
    // A row at 800 days old is within 9999 but beyond 730 cap.
    $beyond_cap_date = date('Y-m-d', strtotime('-800 days'));
    // A row at 100 days old is within both 9999 and 730.
    $within_cap_date = date('Y-m-d', strtotime('-100 days'));

    $this->insertStatsRow('test_event', 'old', 1, $beyond_cap_date);
    $this->insertStatsRow('test_event', 'recent', 1, $within_cap_date);

    $logger = $this->createAnalyticsLogger(['log_retention_days' => 9999]);
    $logger->cleanupOldData();

    $remaining = $this->countTableRows('ilas_site_assistant_stats');
    $this->assertEquals(1, $remaining, 'Cleanup must enforce MAX_RETENTION_DAYS cap even when config exceeds it.');

    $row = $this->database->select('ilas_site_assistant_stats', 's')
      ->fields('s', ['event_value'])
      ->execute()
      ->fetch();
    $this->assertSame('recent', $row->event_value);
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
