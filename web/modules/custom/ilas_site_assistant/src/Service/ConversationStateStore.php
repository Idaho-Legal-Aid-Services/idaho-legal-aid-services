<?php

namespace Drupal\ilas_site_assistant\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;

/**
 * Persists the durable subset of assistant conversation state.
 *
 * This initial slice stores only pending office follow-up flow state. The
 * table is intentionally narrow so future durable additions can stay additive.
 */
class ConversationStateStore {

  /**
   * The durable assistant conversation-state table.
   */
  private const TABLE = 'ilas_site_assistant_conversation_state';

  /**
   * The only durable flow type stored in this slice.
   */
  public const FLOW_TYPE_OFFICE_LOCATION = 'office_location';

  /**
   * Constructs a ConversationStateStore.
   */
  public function __construct(
    private readonly Connection $database,
    private readonly TimeInterface $time,
  ) {}

  /**
   * Loads pending office follow-up state for a conversation.
   */
  public function loadOfficeFollowupState(string $conversation_id, string $session_fingerprint = ''): ?array {
    if ($conversation_id === '' || !$this->database->schema()->tableExists(self::TABLE)) {
      return NULL;
    }

    $row = $this->database->select(self::TABLE, 's')
      ->fields('s', [
        'session_fingerprint',
        'pending_flow_type',
        'pending_flow_origin_intent',
        'pending_flow_remaining_turns',
        'pending_flow_created',
        'expires',
      ])
      ->condition('conversation_id', mb_substr($conversation_id, 0, 36))
      ->execute()
      ->fetchAssoc();

    if (!is_array($row) || $row === []) {
      return NULL;
    }

    $stored_fingerprint = (string) ($row['session_fingerprint'] ?? '');
    if ($stored_fingerprint !== '' && $session_fingerprint !== '' && !hash_equals($stored_fingerprint, $session_fingerprint)) {
      $this->clear($conversation_id);
      return NULL;
    }

    if (($row['pending_flow_type'] ?? '') !== self::FLOW_TYPE_OFFICE_LOCATION) {
      return NULL;
    }

    $created_at = (int) ($row['pending_flow_created'] ?? 0);
    $remaining_turns = (int) ($row['pending_flow_remaining_turns'] ?? 0);
    $expires = (int) ($row['expires'] ?? 0);
    if ($created_at <= 0 || $remaining_turns <= 0 || $expires <= $this->time->getCurrentTime()) {
      $this->clear($conversation_id);
      return NULL;
    }

    return [
      'type' => self::FLOW_TYPE_OFFICE_LOCATION,
      'origin_intent' => (string) ($row['pending_flow_origin_intent'] ?? 'apply'),
      'remaining_turns' => $remaining_turns,
      'created_at' => $created_at,
    ];
  }

  /**
   * Persists office follow-up state for a conversation.
   */
  public function saveOfficeFollowupState(string $conversation_id, array $state, string $session_fingerprint, int $ttl_seconds): void {
    if ($conversation_id === '' || !$this->database->schema()->tableExists(self::TABLE)) {
      return;
    }

    $now = $this->time->getCurrentTime();
    $created_at = max(1, (int) ($state['created_at'] ?? $now));
    $remaining_turns = max(0, (int) ($state['remaining_turns'] ?? 0));
    $ttl_seconds = max(1, $ttl_seconds);

    $this->database->merge(self::TABLE)
      ->keys([
        'conversation_id' => mb_substr($conversation_id, 0, 36),
      ])
      ->fields([
        'session_fingerprint' => mb_substr($session_fingerprint, 0, 64),
        'pending_flow_type' => self::FLOW_TYPE_OFFICE_LOCATION,
        'pending_flow_origin_intent' => mb_substr((string) ($state['origin_intent'] ?? 'apply'), 0, 64),
        'pending_flow_remaining_turns' => $remaining_turns,
        'pending_flow_created' => $created_at,
        'updated' => $now,
        'expires' => $created_at + $ttl_seconds,
      ])
      ->execute();
  }

  /**
   * Clears durable state for a conversation.
   */
  public function clear(string $conversation_id): void {
    if ($conversation_id === '' || !$this->database->schema()->tableExists(self::TABLE)) {
      return;
    }

    $this->database->delete(self::TABLE)
      ->condition('conversation_id', mb_substr($conversation_id, 0, 36))
      ->execute();
  }

  /**
   * Deletes expired durable state rows in bounded batches.
   */
  public function cleanupExpired(int $limit = 500, int $max_iterations = 100): int {
    if (!$this->database->schema()->tableExists(self::TABLE)) {
      return 0;
    }

    $limit = max(1, $limit);
    $max_iterations = max(1, $max_iterations);
    $cutoff = $this->time->getCurrentTime();
    $deleted_total = 0;

    for ($i = 0; $i < $max_iterations; $i++) {
      $conversation_ids = $this->database->select(self::TABLE, 's')
        ->fields('s', ['conversation_id'])
        ->condition('expires', $cutoff, '<=')
        ->range(0, $limit)
        ->execute()
        ->fetchCol();

      if ($conversation_ids === []) {
        break;
      }

      $deleted_total += $this->database->delete(self::TABLE)
        ->condition('conversation_id', $conversation_ids, 'IN')
        ->execute();

      if (count($conversation_ids) < $limit) {
        break;
      }
    }

    return $deleted_total;
  }

}
