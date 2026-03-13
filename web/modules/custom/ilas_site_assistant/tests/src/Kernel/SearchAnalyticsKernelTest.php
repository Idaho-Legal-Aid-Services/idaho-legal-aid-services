<?php

namespace Drupal\Tests\ilas_site_assistant\Kernel;

use Drupal\ilas_site_assistant\Service\AnalyticsLogger;
use Drupal\ilas_site_assistant\Service\ObservabilityPayloadMinimizer;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\LoggerInterface;

/**
 * Kernel tests for PHARD-05 search quality analytics events.
 *
 * Tests the generic_answer, feedback_helpful, and feedback_not_helpful
 * event types added for the search quality analytics dashboard.
 *
 */
#[Group('ilas_site_assistant')]
class SearchAnalyticsKernelTest extends AssistantKernelTestBase {

  /**
   * Tests that generic_answer events store the fallback level as value.
   */
  public function testGenericAnswerEventStoresFallbackLevel(): void {
    $logger = $this->createAnalyticsLogger();

    $logger->log('generic_answer', '2');

    $row = $this->database->select('ilas_site_assistant_stats', 's')
      ->fields('s', ['event_type', 'event_value', 'count'])
      ->condition('event_type', 'generic_answer')
      ->execute()
      ->fetch();

    $this->assertNotFalse($row);
    $this->assertSame('generic_answer', $row->event_type);
    $this->assertSame('2', $row->event_value);
    $this->assertEquals(1, $row->count);
  }

  /**
   * Tests that feedback_helpful events normalize the response type token.
   */
  public function testFeedbackHelpfulNormalizesToResponseType(): void {
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
  public function testFeedbackNotHelpfulNormalizesToResponseType(): void {
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
   * Tests that feedback events upsert per day per response type.
   */
  public function testFeedbackUpsertPerDayPerResponseType(): void {
    $logger = $this->createAnalyticsLogger();

    $logger->log('feedback_helpful', 'faq');
    $logger->log('feedback_helpful', 'faq');
    $logger->log('feedback_helpful', 'resources');

    // faq should have count=2, resources count=1.
    $faq_row = $this->database->select('ilas_site_assistant_stats', 's')
      ->fields('s', ['count'])
      ->condition('event_type', 'feedback_helpful')
      ->condition('event_value', 'faq')
      ->execute()
      ->fetch();

    $this->assertEquals(2, $faq_row->count);

    $resources_row = $this->database->select('ilas_site_assistant_stats', 's')
      ->fields('s', ['count'])
      ->condition('event_type', 'feedback_helpful')
      ->condition('event_value', 'resources')
      ->execute()
      ->fetch();

    $this->assertEquals(1, $resources_row->count);
  }

  /**
   * Tests that feedback events with free text are blanked.
   */
  public function testFeedbackRejectsFreeTextValue(): void {
    $logger = $this->createAnalyticsLogger();

    $logger->log('feedback_helpful', 'This response was really great and helped me find housing info');

    $row = $this->database->select('ilas_site_assistant_stats', 's')
      ->fields('s', ['event_value'])
      ->condition('event_type', 'feedback_helpful')
      ->execute()
      ->fetch();

    // Free text should be blanked by normalizeApprovedTokenValue.
    $this->assertSame('', $row->event_value);
  }

  /**
   * Tests that feedback events are cleaned up by retention.
   */
  public function testFeedbackCleanedUpByRetention(): void {
    $old_date = date('Y-m-d', strtotime('-100 days'));
    $recent_date = date('Y-m-d', strtotime('-10 days'));

    $this->insertStatsRow('feedback_helpful', 'faq', 5, $old_date);
    $this->insertStatsRow('feedback_not_helpful', 'resources', 3, $old_date);
    $this->insertStatsRow('feedback_helpful', 'faq', 2, $recent_date);

    $logger = $this->createAnalyticsLogger(['log_retention_days' => 90]);
    $logger->cleanupOldData();

    $remaining = $this->countTableRows('ilas_site_assistant_stats');
    $this->assertEquals(1, $remaining);
  }

  /**
   * Creates an AnalyticsLogger with configurable overrides.
   */
  protected function createAnalyticsLogger(array $config_overrides = [], int $timestamp = 1700000000): AnalyticsLogger {
    $configFactory = $this->createMockConfigFactory($config_overrides);
    $time = $this->createMockTime($timestamp);
    $logger = $this->createStub(LoggerInterface::class);

    return new AnalyticsLogger(
      $this->database,
      $configFactory,
      $time,
      $logger
    );
  }

}
