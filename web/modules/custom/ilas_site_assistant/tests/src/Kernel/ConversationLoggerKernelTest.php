<?php

namespace Drupal\Tests\ilas_site_assistant\Kernel;

use Drupal\ilas_site_assistant\Service\ConversationLogger;
use Drupal\ilas_site_assistant\Service\ObservabilityPayloadMinimizer;
use Psr\Log\LoggerInterface;

/**
 * Kernel tests for ConversationLogger service.
 *
 * Tests real database writes, metadata-only message persistence, request_id
 * storage, and cleanup behavior with actual SQL.
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

    $this->assertEquals('user', $rows[0]->direction);
    $this->assertEquals('apply_for_help', $rows[0]->intent);
    $this->assertNull($rows[0]->response_type);
    $this->assertNotSame('', $rows[0]->message_hash);

    $this->assertEquals('assistant', $rows[1]->direction);
    $this->assertEquals('apply_for_help', $rows[1]->intent);
    $this->assertEquals('navigation', $rows[1]->response_type);
    $this->assertNotSame('', $rows[1]->message_hash);
  }

  /**
   * Tests that only metadata is stored for user and assistant messages.
   *
   * @covers ::logExchange
   */
  public function testLogExchangeStoresMetadataOnly(): void {
    $logger = $this->createConversationLogger();
    $conv_id = '12345678-1234-4123-8123-123456789abc';
    $userMessage = 'My email is john@example.com and my phone is 208-555-1234';
    $assistantMessage = '<p>I can help you find resources.</p>';

    $logger->logExchange(
      $conv_id,
      $userMessage,
      $assistantMessage,
      'faq',
      'faq'
    );

    $userExpected = ObservabilityPayloadMinimizer::buildTextMetadata($userMessage);
    $assistantExpected = ObservabilityPayloadMinimizer::buildTextMetadata('I can help you find resources.');

    $userRow = $this->database->select('ilas_site_assistant_conversations', 'c')
      ->fields('c', ['message_hash', 'message_length_bucket', 'redaction_profile'])
      ->condition('conversation_id', $conv_id)
      ->condition('direction', 'user')
      ->execute()
      ->fetch();

    $assistantRow = $this->database->select('ilas_site_assistant_conversations', 'c')
      ->fields('c', ['message_hash', 'message_length_bucket', 'redaction_profile'])
      ->condition('conversation_id', $conv_id)
      ->condition('direction', 'assistant')
      ->execute()
      ->fetch();

    $this->assertFalse($this->database->schema()->fieldExists('ilas_site_assistant_conversations', 'redacted_message'));

    $this->assertSame($userExpected['text_hash'], $userRow->message_hash);
    $this->assertSame($userExpected['length_bucket'], $userRow->message_length_bucket);
    $this->assertSame($userExpected['redaction_profile'], $userRow->redaction_profile);

    $this->assertSame($assistantExpected['text_hash'], $assistantRow->message_hash);
    $this->assertSame($assistantExpected['length_bucket'], $assistantRow->message_length_bucket);
    $this->assertSame($assistantExpected['redaction_profile'], $assistantRow->redaction_profile);
  }

  /**
   * Tests Spanish/contextual PII redaction in stored metadata.
   *
   * @covers ::logExchange
   */
  public function testLogExchangeStoresSpanishContextualMetadata(): void {
    $logger = $this->createConversationLogger();
    $conv_id = '12345678-1234-4123-8123-123456789abc';
    $userMessage = 'Mi nombre es Juan Garcia y vivo en 123 Main Street Boise ID 83702. Mi licencia es AB123456C.';

    $logger->logExchange(
      $conv_id,
      $userMessage,
      'Puedo ayudarle a encontrar recursos.',
      'faq',
      'faq'
    );

    $expected = ObservabilityPayloadMinimizer::buildTextMetadata($userMessage);
    $userRow = $this->database->select('ilas_site_assistant_conversations', 'c')
      ->fields('c', ['message_hash', 'message_length_bucket', 'redaction_profile'])
      ->condition('conversation_id', $conv_id)
      ->condition('direction', 'user')
      ->execute()
      ->fetch();

    $this->assertSame($expected['text_hash'], $userRow->message_hash);
    $this->assertSame($expected['length_bucket'], $userRow->message_length_bucket);
    $this->assertSame($expected['redaction_profile'], $userRow->redaction_profile);
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

    $this->database->insert('ilas_site_assistant_conversations')
      ->fields([
        'conversation_id' => '11111111-1111-4111-8111-111111111111',
        'direction' => 'user',
        'message_hash' => hash('sha256', 'old message'),
        'message_length_bucket' => ObservabilityPayloadMinimizer::LENGTH_BUCKET_SHORT,
        'redaction_profile' => ObservabilityPayloadMinimizer::PROFILE_NONE,
        'intent' => 'faq',
        'created' => $old_timestamp,
      ])
      ->execute();

    $this->database->insert('ilas_site_assistant_conversations')
      ->fields([
        'conversation_id' => '22222222-2222-4222-8222-222222222222',
        'direction' => 'user',
        'message_hash' => hash('sha256', 'recent message'),
        'message_length_bucket' => ObservabilityPayloadMinimizer::LENGTH_BUCKET_SHORT,
        'redaction_profile' => ObservabilityPayloadMinimizer::PROFILE_NONE,
        'intent' => 'faq',
        'created' => $now - 3600,
      ])
      ->execute();

    $logger = $this->createConversationLogger([], $now);
    $logger->cleanup();

    $remaining = $this->countTableRows('ilas_site_assistant_conversations');
    $this->assertEquals(1, $remaining);

    $row = $this->database->select('ilas_site_assistant_conversations', 'c')
      ->fields('c', ['conversation_id'])
      ->execute()
      ->fetchField();
    $this->assertEquals('22222222-2222-4222-8222-222222222222', $row);
  }

  /**
   * Tests that cleanup logs the deleted-row count through the injected logger.
   *
   * @covers ::cleanup
   */
  public function testCleanupLogsDeletedRowCount(): void {
    $now = 1700000000;
    $retention_hours = 72;
    $old_timestamp = $now - ($retention_hours * 3600) - 1;

    $this->database->insert('ilas_site_assistant_conversations')
      ->fields([
        'conversation_id' => '33333333-3333-4333-8333-333333333333',
        'direction' => 'user',
        'message_hash' => hash('sha256', 'old message'),
        'message_length_bucket' => ObservabilityPayloadMinimizer::LENGTH_BUCKET_SHORT,
        'redaction_profile' => ObservabilityPayloadMinimizer::PROFILE_NONE,
        'intent' => 'faq',
        'created' => $old_timestamp,
      ])
      ->execute();

    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->once())
      ->method('info')
      ->with('Cleaned up @count expired conversation log entries.', [
        '@count' => 1,
      ]);

    $service = $this->createConversationLogger([], $now, $logger);
    $service->cleanup();

    $this->assertEquals(0, $this->countTableRows('ilas_site_assistant_conversations'));
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

    for ($i = 0; $i < 10; $i++) {
      $this->database->insert('ilas_site_assistant_conversations')
        ->fields([
          'conversation_id' => sprintf('aaaaaaaa-aaaa-4aaa-8aaa-%012d', $i),
          'direction' => 'user',
          'message_hash' => hash('sha256', 'old message ' . $i),
          'message_length_bucket' => ObservabilityPayloadMinimizer::LENGTH_BUCKET_SHORT,
          'redaction_profile' => ObservabilityPayloadMinimizer::PROFILE_NONE,
          'intent' => 'faq',
          'created' => $old_timestamp - $i,
        ])
        ->execute();
    }

    for ($i = 0; $i < 2; $i++) {
      $this->database->insert('ilas_site_assistant_conversations')
        ->fields([
          'conversation_id' => sprintf('bbbbbbbb-bbbb-4bbb-8bbb-%012d', $i),
          'direction' => 'user',
          'message_hash' => hash('sha256', 'recent message ' . $i),
          'message_length_bucket' => ObservabilityPayloadMinimizer::LENGTH_BUCKET_SHORT,
          'redaction_profile' => ObservabilityPayloadMinimizer::PROFILE_NONE,
          'intent' => 'faq',
          'created' => $now - 3600,
        ])
        ->execute();
    }

    $logger = $this->createConversationLogger([], $now);
    $logger->cleanup();

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

    $this->database->insert('ilas_site_assistant_conversations')
      ->fields([
        'conversation_id' => '33333333-3333-4333-8333-333333333333',
        'direction' => 'user',
        'message_hash' => hash('sha256', 'recent message'),
        'message_length_bucket' => ObservabilityPayloadMinimizer::LENGTH_BUCKET_SHORT,
        'redaction_profile' => ObservabilityPayloadMinimizer::PROFILE_NONE,
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
   * Tests that long messages collapse into bucketed metadata.
   *
   * @covers ::logExchange
   */
  public function testLogExchangeBucketsLongMessages(): void {
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
      ->fields('c', ['message_length_bucket'])
      ->condition('conversation_id', $conv_id)
      ->condition('direction', 'user')
      ->execute()
      ->fetchField();

    $assistant_row = $this->database->select('ilas_site_assistant_conversations', 'c')
      ->fields('c', ['message_length_bucket'])
      ->condition('conversation_id', $conv_id)
      ->condition('direction', 'assistant')
      ->execute()
      ->fetchField();

    $this->assertSame(ObservabilityPayloadMinimizer::LENGTH_BUCKET_LONG, $user_row);
    $this->assertSame(ObservabilityPayloadMinimizer::LENGTH_BUCKET_LONG, $assistant_row);
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
  protected function createConversationLogger(array $config_overrides = [], int $timestamp = 1700000000, ?LoggerInterface $logger = NULL): ConversationLogger {
    $configFactory = $this->createMockConfigFactory($config_overrides);
    $time = $this->createMockTime($timestamp);
    $logger ??= $this->createStub(LoggerInterface::class);

    return new ConversationLogger(
      $this->database,
      $configFactory,
      $time,
      $logger
    );
  }

}
