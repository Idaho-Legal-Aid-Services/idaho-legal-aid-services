<?php

declare(strict_types=1);

namespace Drupal\ilas_site_assistant_governance\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\ilas_site_assistant\Service\ObservabilityPayloadMinimizer;
use Drupal\ilas_site_assistant\Service\PiiRedactor;
use Psr\Log\LoggerInterface;

/**
 * Canonical redacted conversation logger.
 */
class GovernanceConversationLogger {

  public const RETENTION_DAYS = 90;
  public const REDACTION_VERSION = 'v1';

  public const FEEDBACK_HELPFUL = 'helpful';
  public const FEEDBACK_NOT_HELPFUL = 'not_helpful';

  /**
   * Constructs the logger.
   */
  public function __construct(
    protected Connection $database,
    protected TimeInterface $time,
    protected LoggerInterface $logger,
    protected ?GapItemManager $gapItemManager = NULL,
  ) {}

  /**
   * Correlates thumbs-up/down feedback with the answered turn.
   *
   * Updates the assistant turn matching BOTH the request ID and the
   * conversation ID (so a spoofed request ID from another conversation is
   * ignored), records the value once (first vote wins), and for not-helpful
   * feedback creates or increments a negative_feedback gap item from the
   * paired user turn's already-redacted text. No raw user text is read.
   *
   * @return array|null
   *   ['feedback_value' => ..., 'gap_item_id' => int|null,
   *    'user_turn' => array|null] when a turn was updated, NULL when the
   *   request/conversation pair is unknown or feedback was already recorded.
   */
  public function recordFeedback(string $requestId, string $conversationId, string $value): ?array {
    $value = $value === self::FEEDBACK_HELPFUL ? self::FEEDBACK_HELPFUL : self::FEEDBACK_NOT_HELPFUL;
    $requestId = mb_substr(trim($requestId), 0, 36);
    $conversationId = mb_substr(trim($conversationId), 0, 36);
    if (!preg_match('/^[a-f0-9\-]{36}$/i', $requestId) || !preg_match('/^[a-f0-9\-]{36}$/i', $conversationId)) {
      return NULL;
    }

    $schema = $this->database->schema();
    if (
      !$schema->tableExists('ilas_site_assistant_conversation_turn')
      || !$schema->fieldExists('ilas_site_assistant_conversation_turn', 'feedback_value')
    ) {
      return NULL;
    }

    try {
      $now = $this->time->getRequestTime();
      $updated = $this->database->update('ilas_site_assistant_conversation_turn')
        ->fields(['feedback_value' => $value, 'feedback_at' => $now])
        ->condition('conversation_id', $conversationId)
        ->condition('request_id', $requestId)
        ->condition('direction', 'assistant')
        ->isNull('feedback_value')
        ->execute();
      if ((int) $updated === 0) {
        return NULL;
      }

      $result = ['feedback_value' => $value, 'gap_item_id' => NULL, 'user_turn' => NULL];
      if ($value !== self::FEEDBACK_NOT_HELPFUL) {
        return $result;
      }

      if ($schema->fieldExists('ilas_site_assistant_conversation_session', 'has_negative_feedback')) {
        $this->database->update('ilas_site_assistant_conversation_session')
          ->fields(['has_negative_feedback' => 1])
          ->condition('conversation_id', $conversationId)
          ->execute();
      }

      $user_turn = $this->database->select('ilas_site_assistant_conversation_turn', 't')
        ->fields('t', [
          'id',
          'turn_sequence',
          'message_redacted',
          'message_hash',
          'message_length_bucket',
          'redaction_profile',
          'language_hint',
          'intent',
          'gap_item_id',
        ])
        ->condition('conversation_id', $conversationId)
        ->condition('request_id', $requestId)
        ->condition('direction', 'user')
        ->orderBy('turn_sequence', 'ASC')
        ->range(0, 1)
        ->execute()
        ->fetchAssoc() ?: NULL;
      $result['user_turn'] = $user_turn;

      if ($user_turn === NULL || $this->gapItemManager === NULL) {
        return $result;
      }

      $gap_item_id = $this->recordTurnGapItem($user_turn, $conversationId, $requestId, 'negative_feedback');
      $result['gap_item_id'] = $gap_item_id;
      return $result;
    }
    catch (\Throwable $e) {
      $this->logger->error('Governance feedback correlation failed: @class @error_signature', [
        '@class' => get_class($e),
        '@error_signature' => ObservabilityPayloadMinimizer::exceptionSignature($e),
      ]);
      return NULL;
    }
  }

  /**
   * Pushes a stored user turn into the gap review queue.
   *
   * Used by the transcript "Flag for review" action; clusters with runtime
   * hits for the same message via the stored text hash.
   *
   * @return int|null
   *   The gap item ID, or NULL when the turn is unknown or already linked.
   */
  public function flagUserTurn(string $conversationId, int $turnSequence): ?int {
    $conversationId = mb_substr(trim($conversationId), 0, 36);
    if ($conversationId === '' || $this->gapItemManager === NULL) {
      return NULL;
    }

    $user_turn = $this->database->select('ilas_site_assistant_conversation_turn', 't')
      ->fields('t', [
        'id',
        'turn_sequence',
        'request_id',
        'direction',
        'message_redacted',
        'message_hash',
        'message_length_bucket',
        'redaction_profile',
        'language_hint',
        'intent',
        'gap_item_id',
      ])
      ->condition('conversation_id', $conversationId)
      ->condition('turn_sequence', $turnSequence)
      ->execute()
      ->fetchAssoc() ?: NULL;

    if ($user_turn === NULL || ($user_turn['direction'] ?? '') !== 'user') {
      return NULL;
    }
    if (!empty($user_turn['gap_item_id'])) {
      return (int) $user_turn['gap_item_id'];
    }

    return $this->recordTurnGapItem($user_turn, $conversationId, (string) ($user_turn['request_id'] ?? ''), 'reviewer_flagged');
  }

  /**
   * Records a gap item from a stored (already redacted) user turn row.
   */
  protected function recordTurnGapItem(array $user_turn, string $conversationId, string $requestId, string $trigger_code): ?int {
    $gap_item_id = $this->gapItemManager->recordNoAnswer((string) ($user_turn['message_redacted'] ?? ''), [
      'trigger_code' => $trigger_code,
      'conversation_id' => $conversationId,
      'request_id' => $requestId,
      'intent_type' => (string) ($user_turn['intent'] ?? 'unknown'),
      'text_metadata' => [
        'text_hash' => (string) ($user_turn['message_hash'] ?? ''),
        'language_hint' => (string) ($user_turn['language_hint'] ?? 'unknown'),
        'length_bucket' => (string) ($user_turn['message_length_bucket'] ?? ''),
        'redaction_profile' => (string) ($user_turn['redaction_profile'] ?? 'none'),
      ],
    ]);
    if ($gap_item_id === NULL) {
      return NULL;
    }

    // Link the item to the turn and to the session, like a runtime hit.
    $this->database->update('ilas_site_assistant_conversation_turn')
      ->fields(['gap_item_id' => $gap_item_id])
      ->condition('id', (int) $user_turn['id'])
      ->isNull('gap_item_id')
      ->execute();
    $session_fields = ['latest_gap_item_id' => $gap_item_id];
    if ($this->database->schema()->fieldExists('ilas_site_assistant_conversation_session', 'has_unresolved_gap')) {
      $session_fields['has_unresolved_gap'] = 1;
    }
    $this->database->update('ilas_site_assistant_conversation_session')
      ->fields($session_fields)
      ->condition('conversation_id', $conversationId)
      ->execute();

    return $gap_item_id;
  }

  /**
   * Writes a canonical session row plus two redacted turn rows.
   */
  public function logExchange(
    string $conversationId,
    string $userMessage,
    string $assistantMessage,
    string $intent,
    string $responseType,
    string $requestId = '',
    array $context = [],
  ): void {
    $schema = $this->database->schema();
    if (!$schema->tableExists('ilas_site_assistant_conversation_session') || !$schema->tableExists('ilas_site_assistant_conversation_turn')) {
      return;
    }

    $conversationId = mb_substr($conversationId, 0, 36);
    if ($conversationId === '') {
      return;
    }

    $now = $this->time->getRequestTime();
    $stored_request_id = preg_match('/^[a-f0-9\-]{36}$/i', $requestId) ? $requestId : NULL;
    $user_redacted = PiiRedactor::redactForStorage($userMessage, 4000);
    $assistant_redacted = PiiRedactor::redactForStorage(strip_tags($assistantMessage), 4000);
    $user_metadata = ObservabilityPayloadMinimizer::buildTextMetadataWithLanguage($userMessage);
    $assistant_metadata = ObservabilityPayloadMinimizer::buildTextMetadataWithLanguage(strip_tags($assistantMessage));
    $supports_unresolved_gap = $schema->fieldExists('ilas_site_assistant_conversation_session', 'has_unresolved_gap');

    try {
      $session = $this->database->select('ilas_site_assistant_conversation_session', 's')
        ->fields('s')
        ->condition('conversation_id', $conversationId)
        ->execute()
        ->fetchAssoc() ?: NULL;

      $next_turn_sequence = (int) ($session['turn_count'] ?? 0) + 1;

      $this->database->insert('ilas_site_assistant_conversation_turn')
        ->fields([
          'conversation_id' => $conversationId,
          'turn_sequence' => $next_turn_sequence,
          'request_id' => $stored_request_id,
          'direction' => 'user',
          'message_redacted' => $user_redacted,
          'message_hash' => $user_metadata['text_hash'],
          'message_length_bucket' => $user_metadata['length_bucket'],
          'redaction_profile' => $user_metadata['redaction_profile'],
          'redaction_version' => self::REDACTION_VERSION,
          'language_hint' => $user_metadata['language_hint'],
          'intent' => mb_substr($intent, 0, 64),
          'response_type' => NULL,
          'is_no_answer' => !empty($context['is_no_answer']) ? 1 : 0,
          'gap_item_id' => !empty($context['gap_item_id']) ? (int) $context['gap_item_id'] : NULL,
          'created' => $now,
        ])
        ->execute();

      $this->database->insert('ilas_site_assistant_conversation_turn')
        ->fields([
          'conversation_id' => $conversationId,
          'turn_sequence' => $next_turn_sequence + 1,
          'request_id' => $stored_request_id,
          'direction' => 'assistant',
          'message_redacted' => $assistant_redacted,
          'message_hash' => $assistant_metadata['text_hash'],
          'message_length_bucket' => $assistant_metadata['length_bucket'],
          'redaction_profile' => $assistant_metadata['redaction_profile'],
          'redaction_version' => self::REDACTION_VERSION,
          'language_hint' => $assistant_metadata['language_hint'],
          'intent' => mb_substr($intent, 0, 64),
          'response_type' => mb_substr($responseType, 0, 32),
          'is_no_answer' => !empty($context['is_no_answer']) ? 1 : 0,
          'gap_item_id' => !empty($context['gap_item_id']) ? (int) $context['gap_item_id'] : NULL,
          'created' => $now,
        ])
        ->execute();

      $session_fields = [
        'last_message_at' => $now,
        'turn_count' => $next_turn_sequence + 1,
        'exchange_count' => (int) ($session['exchange_count'] ?? 0) + 1,
        'language_hint' => $user_metadata['language_hint'],
        'last_intent' => mb_substr($intent, 0, 64),
        'last_response_type' => mb_substr($responseType, 0, 32),
        'last_request_id' => $stored_request_id,
        'has_no_answer' => !empty($context['is_no_answer']) || !empty($session['has_no_answer']) ? 1 : 0,
        'latest_gap_item_id' => !empty($context['gap_item_id']) ? (int) $context['gap_item_id'] : ($session['latest_gap_item_id'] ?? NULL),
        'purge_after' => $now + (self::RETENTION_DAYS * 86400),
      ];
      if ($supports_unresolved_gap) {
        // Any linked gap item (no-match, clarify, loop, grounding refusal)
        // means a reviewer may still need to follow up on this conversation.
        $has_open_gap = !empty($context['is_no_answer']) || !empty($context['gap_item_id']);
        $session_fields['has_unresolved_gap'] = $has_open_gap || !empty($session['has_unresolved_gap']) ? 1 : 0;
      }

      if ($session) {
        $this->database->update('ilas_site_assistant_conversation_session')
          ->fields($session_fields)
          ->condition('conversation_id', $conversationId)
          ->execute();
      }
      else {
        $this->database->insert('ilas_site_assistant_conversation_session')
          ->fields($session_fields + [
            'conversation_id' => $conversationId,
            'first_message_at' => $now,
            'first_request_id' => $stored_request_id,
            'is_held' => 0,
          ])
          ->execute();
      }
    }
    catch (\Throwable $e) {
      $this->logger->error('Governance conversation logging failed: @class @error_signature', [
        '@class' => get_class($e),
        '@error_signature' => ObservabilityPayloadMinimizer::exceptionSignature($e),
      ]);
    }
  }

  /**
   * Refreshes current follow-up flags for the given conversations.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param string[] $conversation_ids
   *   Conversation IDs to refresh.
   */
  public static function refreshUnresolvedGapFlags(Connection $database, array $conversation_ids): void {
    $conversation_ids = array_values(array_unique(array_filter(array_map(
      static fn (mixed $conversation_id): string => trim((string) $conversation_id),
      $conversation_ids,
    ))));
    if ($conversation_ids === []) {
      return;
    }

    $schema = $database->schema();
    if (
      !$schema->tableExists('ilas_site_assistant_conversation_session')
      || !$schema->fieldExists('ilas_site_assistant_conversation_session', 'has_unresolved_gap')
      || !$schema->tableExists('ilas_site_assistant_gap_hit')
      || !$schema->fieldExists('ilas_site_assistant_gap_hit', 'is_unresolved')
    ) {
      return;
    }

    $database->update('ilas_site_assistant_conversation_session')
      ->fields(['has_unresolved_gap' => 0])
      ->condition('conversation_id', $conversation_ids, 'IN')
      ->execute();

    $active_conversation_ids = $database->select('ilas_site_assistant_gap_hit', 'h')
      ->fields('h', ['conversation_id'])
      ->condition('conversation_id', $conversation_ids, 'IN')
      ->condition('is_unresolved', 1)
      ->isNotNull('conversation_id')
      ->distinct()
      ->execute()
      ->fetchCol();

    if ($active_conversation_ids === []) {
      return;
    }

    $database->update('ilas_site_assistant_conversation_session')
      ->fields(['has_unresolved_gap' => 1])
      ->condition('conversation_id', $active_conversation_ids, 'IN')
      ->execute();
  }

}
