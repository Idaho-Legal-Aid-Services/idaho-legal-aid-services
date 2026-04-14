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

  /**
   * Constructs the logger.
   */
  public function __construct(
    protected Connection $database,
    protected TimeInterface $time,
    protected LoggerInterface $logger,
  ) {}

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

}
