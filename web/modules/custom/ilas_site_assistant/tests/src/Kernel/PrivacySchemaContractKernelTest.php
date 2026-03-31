<?php

namespace Drupal\Tests\ilas_site_assistant\Kernel;

use Drupal\ilas_site_assistant\Service\ConversationLogger;
use Drupal\ilas_site_assistant\Service\ObservabilityPayloadMinimizer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\LoggerInterface;

/**
 * Kernel tests enforcing the privacy schema contract.
 *
 * Guards database column allowlists, retention cap enforcement, and
 * runtime privacy invariants with real database operations.
 *
 */
#[CoversClass(ConversationLogger::class)]
#[Group('ilas_site_assistant')]
class PrivacySchemaContractKernelTest extends AssistantKernelTestBase {

  /**
   * Approved columns for the conversations table.
   */
  private const CONVERSATIONS_ALLOWLIST = [
    'id',
    'conversation_id',
    'direction',
    'message_hash',
    'message_length_bucket',
    'redaction_profile',
    'intent',
    'response_type',
    'created',
    'request_id',
  ];

  /**
   * Approved columns for the stats table.
   */
  private const STATS_ALLOWLIST = [
    'id',
    'event_type',
    'event_value',
    'count',
    'date',
  ];

  /**
   * Approved columns for the no-answer table.
   */
  private const NO_ANSWER_ALLOWLIST = [
    'id',
    'query_hash',
    'language_hint',
    'length_bucket',
    'redaction_profile',
    'count',
    'first_seen',
    'last_seen',
  ];

  /**
   * Approved columns for the durable conversation-state table.
   */
  private const CONVERSATION_STATE_ALLOWLIST = [
    'conversation_id',
    'session_fingerprint',
    'pending_flow_type',
    'pending_flow_origin_intent',
    'pending_flow_remaining_turns',
    'pending_flow_created',
    'updated',
    'expires',
  ];

  /**
   * Tests conversations table columns match the approved allowlist.
   */
  public function testConversationsTableColumnsMatchAllowlist(): void {
    $schema = $this->database->schema();
    $table = 'ilas_site_assistant_conversations';

    foreach (self::CONVERSATIONS_ALLOWLIST as $column) {
      $this->assertTrue(
        $schema->fieldExists($table, $column),
        "Approved column '{$column}' must exist in {$table}.",
      );
    }
  }

  /**
   * Tests stats table columns match the approved allowlist.
   */
  public function testStatsTableColumnsMatchAllowlist(): void {
    $schema = $this->database->schema();
    $table = 'ilas_site_assistant_stats';

    foreach (self::STATS_ALLOWLIST as $column) {
      $this->assertTrue(
        $schema->fieldExists($table, $column),
        "Approved column '{$column}' must exist in {$table}.",
      );
    }
  }

  /**
   * Tests no-answer table columns match the approved allowlist.
   */
  public function testNoAnswerTableColumnsMatchAllowlist(): void {
    $schema = $this->database->schema();
    $table = 'ilas_site_assistant_no_answer';

    foreach (self::NO_ANSWER_ALLOWLIST as $column) {
      $this->assertTrue(
        $schema->fieldExists($table, $column),
        "Approved column '{$column}' must exist in {$table}.",
      );
    }
  }

  /**
   * Tests conversation-state table columns match the approved allowlist.
   */
  public function testConversationStateTableColumnsMatchAllowlist(): void {
    $schema = $this->database->schema();
    $table = 'ilas_site_assistant_conversation_state';

    foreach (self::CONVERSATION_STATE_ALLOWLIST as $column) {
      $this->assertTrue(
        $schema->fieldExists($table, $column),
        "Approved column '{$column}' must exist in {$table}.",
      );
    }
  }

  /**
   * Tests conversations table has no text storage columns (RAUD-11 guard).
   */
  public function testConversationsTableHasNoTextColumns(): void {
    $schema = $this->database->schema();
    $table = 'ilas_site_assistant_conversations';
    $forbidden = ['redacted_message', 'message', 'raw_message', 'user_message', 'assistant_message'];

    foreach ($forbidden as $column) {
      $this->assertFalse(
        $schema->fieldExists($table, $column),
        "Forbidden text column '{$column}' must NOT exist in {$table}.",
      );
    }
  }

  /**
   * Tests no-answer table has no text storage columns (RAUD-11 guard).
   */
  public function testNoAnswerTableHasNoTextColumns(): void {
    $schema = $this->database->schema();
    $table = 'ilas_site_assistant_no_answer';
    $forbidden = ['sanitized_query', 'query', 'raw_query', 'user_query'];

    foreach ($forbidden as $column) {
      $this->assertFalse(
        $schema->fieldExists($table, $column),
        "Forbidden text column '{$column}' must NOT exist in {$table}.",
      );
    }
  }

  /**
   * Tests durable conversation-state table has no raw transcript text columns.
   */
  public function testConversationStateTableHasNoTranscriptTextColumns(): void {
    $schema = $this->database->schema();
    $table = 'ilas_site_assistant_conversation_state';
    $forbidden = [
      'history_json',
      'messages_json',
      'message',
      'user_message',
      'assistant_message',
      'raw_message',
      'raw_user_message',
      'assistant_text',
      'transcript',
    ];

    foreach ($forbidden as $column) {
      $this->assertFalse(
        $schema->fieldExists($table, $column),
        "Forbidden transcript text column '{$column}' must NOT exist in {$table}.",
      );
    }
  }

  /**
   * Tests cleanup respects MAX_RETENTION_HOURS cap even with high config value.
   */
  public function testCleanupRespectsMaxRetentionCap(): void {
    $now = 1700000000;
    // Set retention to 9999 hours (far exceeds 720 cap).
    // A row at 800 hours old is within 9999 but beyond 720 cap.
    $beyond_cap_timestamp = $now - (800 * 3600);
    // A row at 100 hours old is within both 9999 and 720.
    $within_cap_timestamp = $now - (100 * 3600);

    $this->database->insert('ilas_site_assistant_conversations')
      ->fields([
        'conversation_id' => '11111111-1111-4111-8111-111111111111',
        'direction' => 'user',
        'message_hash' => hash('sha256', 'beyond cap'),
        'message_length_bucket' => ObservabilityPayloadMinimizer::LENGTH_BUCKET_SHORT,
        'redaction_profile' => ObservabilityPayloadMinimizer::PROFILE_NONE,
        'intent' => 'faq',
        'created' => $beyond_cap_timestamp,
      ])
      ->execute();

    $this->database->insert('ilas_site_assistant_conversations')
      ->fields([
        'conversation_id' => '22222222-2222-4222-8222-222222222222',
        'direction' => 'user',
        'message_hash' => hash('sha256', 'within cap'),
        'message_length_bucket' => ObservabilityPayloadMinimizer::LENGTH_BUCKET_SHORT,
        'redaction_profile' => ObservabilityPayloadMinimizer::PROFILE_NONE,
        'intent' => 'faq',
        'created' => $within_cap_timestamp,
      ])
      ->execute();

    $logger = $this->createConversationLogger([
      'conversation_logging.retention_hours' => 9999,
    ], $now);
    $logger->cleanup();

    $remaining = $this->countTableRows('ilas_site_assistant_conversations');
    $this->assertEquals(1, $remaining, 'Cleanup must enforce MAX_RETENTION_HOURS cap even when config exceeds it.');

    $row = $this->database->select('ilas_site_assistant_conversations', 'c')
      ->fields('c', ['conversation_id'])
      ->execute()
      ->fetchField();
    $this->assertEquals('22222222-2222-4222-8222-222222222222', $row);
  }

  /**
   * Tests isUserNoticeRequired returns true when logging is enabled.
   */
  public function testIsUserNoticeRequiredWhenLoggingEnabled(): void {
    $logger = $this->createConversationLogger([
      'conversation_logging.enabled' => TRUE,
      'conversation_logging.show_user_notice' => TRUE,
    ]);

    $this->assertTrue($logger->isUserNoticeRequired());
  }

  /**
   * Tests isUserNoticeRequired returns false when logging is disabled.
   */
  public function testIsUserNoticeRequiredWhenLoggingDisabled(): void {
    $logger = $this->createConversationLogger([
      'conversation_logging.enabled' => FALSE,
      'conversation_logging.show_user_notice' => TRUE,
    ]);

    $this->assertFalse($logger->isUserNoticeRequired());
  }

  /**
   * Tests privacy invariant: notice is forced on when logging is enabled.
   *
   * Even if config has show_user_notice=false, resolveConfig() forces it true
   * when logging is enabled.
   */
  public function testPrivacyInvariantForcesNoticeWhenLoggingEnabled(): void {
    $logger = $this->createConversationLogger([
      'conversation_logging.enabled' => TRUE,
      'conversation_logging.show_user_notice' => FALSE,
    ]);

    $this->assertTrue(
      $logger->isUserNoticeRequired(),
      'User notice must be forced on when conversation logging is enabled.',
    );
  }

  /**
   * Creates a ConversationLogger with configurable overrides.
   *
   * @param array $config_overrides
   *   Config values to override.
   * @param int $timestamp
   *   The timestamp for the time service.
   *
   * @return \Drupal\ilas_site_assistant\Service\ConversationLogger
   *   The configured ConversationLogger.
   */
  protected function createConversationLogger(array $config_overrides = [], int $timestamp = 1700000000): ConversationLogger {
    $configFactory = $this->createMockConfigFactory($config_overrides);
    $time = $this->createMockTime($timestamp);
    $logger = $this->createStub(LoggerInterface::class);

    return new ConversationLogger(
      $this->database,
      $configFactory,
      $time,
      $logger
    );
  }

}
