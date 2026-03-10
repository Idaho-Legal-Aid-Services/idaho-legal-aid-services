<?php

namespace Drupal\Tests\ilas_site_assistant\Kernel;

use Drupal\ilas_site_assistant\Service\ConversationLogger;
use Drupal\ilas_site_assistant\Service\PiiRedactor;

/**
 * Kernel tests for ConversationLogger service.
 *
 * Tests real database writes, PII redaction, request_id storage,
 * and cleanup behavior with actual SQL.
 *
 * @group ilas_site_assistant
 * @coversDefaultClass \Drupal\ilas_site_assistant\Service\ConversationLogger
 */
class ConversationLoggerKernelTest extends AssistantKernelTestBase {

  /**
   * Tests that logExchange writes both user and assistant rows.
   *
   * @covers ::logExchange
   */
  public function testLogExchangeWritesBothRows(): void {
    $logger = $this->createConversationLogger();
    $conv_id = '12345678-1234-4123-8123-123456789abc';

    $logger->logExchange(
      $conv_id,
      'How do I apply for help?',
      'You can apply online or call our hotline.',
      'apply_for_help',
      'navigation'
    );

    $rows = $this->database->select('ilas_site_assistant_conversations', 'c')
      ->fields('c')
      ->condition('conversation_id', $conv_id)
      ->orderBy('id')
      ->execute()
      ->fetchAll();

    $this->assertCount(2, $rows);

    // First row: user direction.
    $this->assertEquals('user', $rows[0]->direction);
    $this->assertEquals('apply_for_help', $rows[0]->intent);
    $this->assertNull($rows[0]->response_type);

    // Second row: assistant direction.
    $this->assertEquals('assistant', $rows[1]->direction);
    $this->assertEquals('apply_for_help', $rows[1]->intent);
    $this->assertEquals('navigation', $rows[1]->response_type);
  }

  /**
   * Tests that PII is redacted from stored messages using unified tokens.
   *
   * @covers ::logExchange
   */
  public function testLogExchangeRedactsPii(): void {
    $logger = $this->createConversationLogger();
    $conv_id = '12345678-1234-4123-8123-123456789abc';

    $logger->logExchange(
      $conv_id,
      'My email is john@example.com and my phone is 208-555-1234',
      'I can help you find resources.',
      'faq',
      'faq'
    );

    $user_row = $this->database->select('ilas_site_assistant_conversations', 'c')
      ->fields('c', ['redacted_message'])
      ->condition('conversation_id', $conv_id)
      ->condition('direction', 'user')
      ->execute()
      ->fetchField();

    $this->assertStringNotContainsString('john@example.com', $user_row);
    $this->assertStringNotContainsString('208-555-1234', $user_row);
    // Verify unified token format.
    $this->assertStringContainsString(PiiRedactor::TOKEN_EMAIL, $user_row);
    $this->assertStringContainsString(PiiRedactor::TOKEN_PHONE, $user_row);
  }

  /**
   * Tests that PII is always redacted regardless of config setting.
   *
   * @covers ::logExchange
   */
  public function testPiiAlwaysRedactedRegardlessOfConfig(): void {
    // Explicitly set redact_pii to FALSE — redaction should still happen.
    $logger = $this->createConversationLogger([
      'conversation_logging.redact_pii' => FALSE,
    ]);
    $conv_id = '12345678-1234-4123-8123-123456789abc';

    $logger->logExchange(
      $conv_id,
      'My SSN is 123-45-6789',
      'I cannot collect that.',
      'pii',
      'escalation'
    );

    $user_row = $this->database->select('ilas_site_assistant_conversations', 'c')
      ->fields('c', ['redacted_message'])
      ->condition('conversation_id', $conv_id)
      ->condition('direction', 'user')
      ->execute()
      ->fetchField();

    $this->assertStringNotContainsString('123-45-6789', $user_row);
    $this->assertStringContainsString(PiiRedactor::TOKEN_SSN, $user_row);
  }

  /**
   * Tests Spanish/contextual PII redaction in stored conversation rows.
   *
   * @covers ::logExchange
   */
  public function testLogExchangeRedactsSpanishContextualPii(): void {
    $logger = $this->createConversationLogger();
    $conv_id = '12345678-1234-4123-8123-123456789abc';

    $logger->logExchange(
      $conv_id,
      'Mi nombre es Juan García y vivo en 123 Main Street Boise ID 83702. Mi licencia es AB123456C.',
      'Puedo ayudarle a encontrar recursos.',
      'faq',
      'faq'
    );

    $user_row = $this->database->select('ilas_site_assistant_conversations', 'c')
      ->fields('c', ['redacted_message'])
      ->condition('conversation_id', $conv_id)
      ->condition('direction', 'user')
      ->execute()
      ->fetchField();

    $this->assertStringNotContainsString('Juan García', $user_row);
    $this->assertStringNotContainsString('123 Main Street Boise ID 83702', $user_row);
    $this->assertStringNotContainsString('AB123456C', $user_row);
    $this->assertStringContainsString(PiiRedactor::TOKEN_NAME, $user_row);
    $this->assertStringContainsString(PiiRedactor::TOKEN_ADDRESS, $user_row);
    $this->assertStringContainsString(PiiRedactor::TOKEN_CASE, $user_row);
  }

  /**
   * Tests that a valid request_id is stored in both rows.
   *
   * @covers ::logExchange
   */
  public function testLogExchangeStoresValidRequestId(): void {
    $logger = $this->createConversationLogger();
    $conv_id = '12345678-1234-4123-8123-123456789abc';
    $request_id = 'abcdef01-2345-4678-9abc-def012345678';

    $logger->logExchange(
      $conv_id,
      'Hello',
      'Welcome!',
      'greeting',
      'greeting',
      $request_id
    );

    $rows = $this->database->select('ilas_site_assistant_conversations', 'c')
      ->fields('c', ['request_id'])
      ->condition('conversation_id', $conv_id)
      ->execute()
      ->fetchAll();

    $this->assertCount(2, $rows);
    $this->assertEquals($request_id, $rows[0]->request_id);
    $this->assertEquals($request_id, $rows[1]->request_id);
  }

  /**
   * Tests that an invalid request_id is not stored.
   *
   * @covers ::logExchange
   */
  public function testLogExchangeRejectsInvalidRequestId(): void {
    $logger = $this->createConversationLogger();
    $conv_id = '12345678-1234-4123-8123-123456789abc';

    $logger->logExchange(
      $conv_id,
      'Hello',
      'Welcome!',
      'greeting',
      'greeting',
      'not-a-valid-uuid'
    );

    $rows = $this->database->select('ilas_site_assistant_conversations', 'c')
      ->fields('c', ['request_id'])
      ->condition('conversation_id', $conv_id)
      ->execute()
      ->fetchAll();

    $this->assertCount(2, $rows);
    $this->assertNull($rows[0]->request_id);
    $this->assertNull($rows[1]->request_id);
  }

  /**
   * Tests that an empty request_id results in NULL storage.
   *
   * @covers ::logExchange
   */
  public function testLogExchangeEmptyRequestIdStoresNull(): void {
    $logger = $this->createConversationLogger();
    $conv_id = '12345678-1234-4123-8123-123456789abc';

    $logger->logExchange(
      $conv_id,
      'Hello',
      'Welcome!',
      'greeting',
      'greeting'
    );

    $row = $this->database->select('ilas_site_assistant_conversations', 'c')
      ->fields('c', ['request_id'])
      ->condition('conversation_id', $conv_id)
      ->condition('direction', 'user')
      ->execute()
      ->fetchField();

    $this->assertNull($row);
  }

  /**
   * Tests that logExchange does nothing when logging is disabled.
   *
   * @covers ::logExchange
   */
  public function testLogExchangeDisabledByConfig(): void {
    $logger = $this->createConversationLogger([
      'conversation_logging.enabled' => FALSE,
    ]);

    $logger->logExchange(
      '12345678-1234-4123-8123-123456789abc',
      'Hello',
      'Welcome!',
      'greeting',
      'greeting'
    );

    $count = $this->countTableRows('ilas_site_assistant_conversations');
    $this->assertEquals(0, $count);
  }

  /**
   * Tests that cleanup removes expired rows.
   *
   * @covers ::cleanup
   */
  public function testCleanupRemovesExpiredRows(): void {
    $now = 1700000000;
    $retention_hours = 72;
    $old_timestamp = $now - ($retention_hours * 3600) - 1;

    // Insert an old row and a recent row.
    $this->database->insert('ilas_site_assistant_conversations')
      ->fields([
        'conversation_id' => '11111111-1111-4111-8111-111111111111',
        'direction' => 'user',
        'redacted_message' => 'old message',
        'intent' => 'faq',
        'created' => $old_timestamp,
      ])
      ->execute();

    $this->database->insert('ilas_site_assistant_conversations')
      ->fields([
        'conversation_id' => '22222222-2222-4222-8222-222222222222',
        'direction' => 'user',
        'redacted_message' => 'recent message',
        'intent' => 'faq',
        'created' => $now - 3600,
      ])
      ->execute();

    $logger = $this->createConversationLogger([], $now);
    $logger->cleanup();

    $remaining = $this->countTableRows('ilas_site_assistant_conversations');
    $this->assertEquals(1, $remaining);

    // Verify the recent row survived.
    $row = $this->database->select('ilas_site_assistant_conversations', 'c')
      ->fields('c', ['conversation_id'])
      ->execute()
      ->fetchField();
    $this->assertEquals('22222222-2222-4222-8222-222222222222', $row);
  }

  /**
   * Tests that batched cleanup deletes all expired rows across batches.
   *
   * @covers ::cleanup
   */
  public function testBatchedCleanupDeletesAllExpiredRows(): void {
    $now = 1700000000;
    $retention_hours = 72;
    $old_timestamp = $now - ($retention_hours * 3600) - 1;

    // Insert 10 expired rows.
    for ($i = 0; $i < 10; $i++) {
      $this->database->insert('ilas_site_assistant_conversations')
        ->fields([
          'conversation_id' => sprintf('aaaaaaaa-aaaa-4aaa-8aaa-%012d', $i),
          'direction' => 'user',
          'redacted_message' => 'old message ' . $i,
          'intent' => 'faq',
          'created' => $old_timestamp - $i,
        ])
        ->execute();
    }

    // Insert 2 recent rows.
    for ($i = 0; $i < 2; $i++) {
      $this->database->insert('ilas_site_assistant_conversations')
        ->fields([
          'conversation_id' => sprintf('bbbbbbbb-bbbb-4bbb-8bbb-%012d', $i),
          'direction' => 'user',
          'redacted_message' => 'recent message ' . $i,
          'intent' => 'faq',
          'created' => $now - 3600,
        ])
        ->execute();
    }

    $logger = $this->createConversationLogger([], $now);
    $logger->cleanup();

    // Only the 2 recent rows should remain.
    $remaining = $this->countTableRows('ilas_site_assistant_conversations');
    $this->assertEquals(2, $remaining);
  }

  /**
   * Tests that cleanup preserves all rows within retention window.
   *
   * @covers ::cleanup
   */
  public function testCleanupPreservesRecentRows(): void {
    $now = 1700000000;

    // Insert a row within the retention window.
    $this->database->insert('ilas_site_assistant_conversations')
      ->fields([
        'conversation_id' => '33333333-3333-4333-8333-333333333333',
        'direction' => 'user',
        'redacted_message' => 'recent message',
        'intent' => 'faq',
        'created' => $now - 3600,
      ])
      ->execute();

    $logger = $this->createConversationLogger([], $now);
    $logger->cleanup();

    $count = $this->countTableRows('ilas_site_assistant_conversations');
    $this->assertEquals(1, $count);
  }

  /**
   * Tests that long messages are truncated.
   *
   * @covers ::logExchange
   */
  public function testLogExchangeTruncatesLongMessages(): void {
    $logger = $this->createConversationLogger();
    $conv_id = '12345678-1234-4123-8123-123456789abc';
    $long_message = str_repeat('a', 1000);

    $logger->logExchange(
      $conv_id,
      $long_message,
      str_repeat('b', 2000),
      'faq',
      'faq'
    );

    $user_row = $this->database->select('ilas_site_assistant_conversations', 'c')
      ->fields('c', ['redacted_message'])
      ->condition('conversation_id', $conv_id)
      ->condition('direction', 'user')
      ->execute()
      ->fetchField();

    // User messages are truncated to 500 chars.
    $this->assertLessThanOrEqual(500, mb_strlen($user_row));

    $assistant_row = $this->database->select('ilas_site_assistant_conversations', 'c')
      ->fields('c', ['redacted_message'])
      ->condition('conversation_id', $conv_id)
      ->condition('direction', 'assistant')
      ->execute()
      ->fetchField();

    // Assistant messages are truncated to 1000 chars.
    $this->assertLessThanOrEqual(1000, mb_strlen($assistant_row));
  }

  /**
   * Tests that multiple exchanges for the same conversation are grouped.
   *
   * @covers ::logExchange
   */
  public function testMultipleExchangesSameConversation(): void {
    $logger = $this->createConversationLogger();
    $conv_id = '12345678-1234-4123-8123-123456789abc';

    $logger->logExchange($conv_id, 'Hello', 'Hi!', 'greeting', 'greeting');
    $logger->logExchange($conv_id, 'Forms?', 'Here are forms.', 'forms', 'resources');

    $count = $this->database->select('ilas_site_assistant_conversations', 'c')
      ->condition('conversation_id', $conv_id)
      ->countQuery()
      ->execute()
      ->fetchField();

    // 2 exchanges = 4 rows (user + assistant each).
    $this->assertEquals(4, $count);
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

    return new ConversationLogger(
      $this->database,
      $configFactory,
      $time
    );
  }

}
