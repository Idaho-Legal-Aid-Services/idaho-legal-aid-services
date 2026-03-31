<?php

namespace Drupal\Tests\ilas_site_assistant\Kernel;

use Drupal\ilas_site_assistant\Service\ConversationStateStore;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Kernel coverage for durable assistant conversation-state storage.
 */
#[CoversClass(ConversationStateStore::class)]
#[Group('ilas_site_assistant')]
class ConversationStateStoreKernelTest extends AssistantKernelTestBase {

  /**
   * Durable state rows round-trip through the store.
   */
  public function testSaveLoadAndClearOfficeFollowupState(): void {
    $store = $this->createConversationStateStore(1700000000);
    $conversation_id = '11111111-1111-4111-8111-111111111111';

    $store->saveOfficeFollowupState($conversation_id, [
      'origin_intent' => 'apply',
      'remaining_turns' => 2,
      'created_at' => 1699999900,
    ], 'session-a', 1800);

    $loaded = $store->loadOfficeFollowupState($conversation_id, 'session-a');

    $this->assertSame([
      'type' => ConversationStateStore::FLOW_TYPE_OFFICE_LOCATION,
      'origin_intent' => 'apply',
      'remaining_turns' => 2,
      'created_at' => 1699999900,
    ], $loaded);

    $row = $this->database->select('ilas_site_assistant_conversation_state', 's')
      ->fields('s')
      ->condition('conversation_id', $conversation_id)
      ->execute()
      ->fetchAssoc();
    $this->assertSame('session-a', $row['session_fingerprint'] ?? NULL);
    $this->assertSame('apply', $row['pending_flow_origin_intent'] ?? NULL);
    $this->assertSame('1700001700', (string) ($row['expires'] ?? ''));

    $store->clear($conversation_id);
    $this->assertNull($store->loadOfficeFollowupState($conversation_id, 'session-a'));
    $this->assertSame(0, $this->countTableRows('ilas_site_assistant_conversation_state'));
  }

  /**
   * Session mismatch clears stale state immediately.
   */
  public function testSessionMismatchClearsStoredState(): void {
    $store = $this->createConversationStateStore(1700000000);
    $conversation_id = '22222222-2222-4222-8222-222222222222';

    $store->saveOfficeFollowupState($conversation_id, [
      'origin_intent' => 'apply',
      'remaining_turns' => 2,
      'created_at' => 1700000000,
    ], 'session-a', 1800);

    $this->assertNull($store->loadOfficeFollowupState($conversation_id, 'session-b'));
    $this->assertSame(0, $this->countTableRows('ilas_site_assistant_conversation_state'));
  }

  /**
   * Expired rows are treated as invalid and removed on read.
   */
  public function testExpiredStateIsPurgedOnLoad(): void {
    $store = $this->createConversationStateStore(1700000100);
    $conversation_id = '33333333-3333-4333-8333-333333333333';

    $this->database->insert('ilas_site_assistant_conversation_state')
      ->fields([
        'conversation_id' => $conversation_id,
        'session_fingerprint' => 'session-a',
        'pending_flow_type' => ConversationStateStore::FLOW_TYPE_OFFICE_LOCATION,
        'pending_flow_origin_intent' => 'apply',
        'pending_flow_remaining_turns' => 1,
        'pending_flow_created' => 1700000000,
        'updated' => 1700000000,
        'expires' => 1700000050,
      ])
      ->execute();

    $this->assertNull($store->loadOfficeFollowupState($conversation_id, 'session-a'));
    $this->assertSame(0, $this->countTableRows('ilas_site_assistant_conversation_state'));
  }

  /**
   * Cleanup removes only expired rows and preserves active ones.
   */
  public function testCleanupExpiredRemovesOnlyExpiredRows(): void {
    $store = $this->createConversationStateStore(1700000100);

    $this->database->insert('ilas_site_assistant_conversation_state')
      ->fields([
        'conversation_id' => '44444444-4444-4444-8444-444444444444',
        'session_fingerprint' => 'session-a',
        'pending_flow_type' => ConversationStateStore::FLOW_TYPE_OFFICE_LOCATION,
        'pending_flow_origin_intent' => 'apply',
        'pending_flow_remaining_turns' => 1,
        'pending_flow_created' => 1700000000,
        'updated' => 1700000000,
        'expires' => 1700000050,
      ])
      ->execute();

    $this->database->insert('ilas_site_assistant_conversation_state')
      ->fields([
        'conversation_id' => '55555555-5555-4555-8555-555555555555',
        'session_fingerprint' => 'session-b',
        'pending_flow_type' => ConversationStateStore::FLOW_TYPE_OFFICE_LOCATION,
        'pending_flow_origin_intent' => 'apply',
        'pending_flow_remaining_turns' => 2,
        'pending_flow_created' => 1700000000,
        'updated' => 1700000000,
        'expires' => 1700001900,
      ])
      ->execute();

    $deleted = $store->cleanupExpired(10, 2);

    $this->assertSame(1, $deleted);
    $this->assertSame(1, $this->countTableRows('ilas_site_assistant_conversation_state'));
    $this->assertSame(
      '55555555-5555-4555-8555-555555555555',
      $this->database->select('ilas_site_assistant_conversation_state', 's')
        ->fields('s', ['conversation_id'])
        ->execute()
        ->fetchField()
    );
  }

  /**
   * Builds a ConversationStateStore with a fixed notion of time.
   */
  private function createConversationStateStore(int $timestamp): ConversationStateStore {
    return new ConversationStateStore(
      $this->database,
      $this->createMockTime($timestamp),
    );
  }

}
