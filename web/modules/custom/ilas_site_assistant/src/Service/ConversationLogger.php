<?php

namespace Drupal\ilas_site_assistant\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Component\Datetime\TimeInterface;
use Psr\Log\LoggerInterface;

/**
 * Opt-in conversation logger with metadata-only persistence for QA/debugging.
 *
 * Default: OFF. When enabled, stores hashed/minimized message metadata with a
 * short retention TTL. Access is gated by the
 * 'view ilas site assistant conversations' permission.
 */
class ConversationLogger {

  /**
   * Maximum allowed retention for conversation logs (30 days in hours).
   */
  const MAX_RETENTION_HOURS = 720;

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The time service.
   *
   * @var \Drupal\Component\Datetime\TimeInterface
   */
  protected $time;

  /**
   * The module logger.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected LoggerInterface $logger;

  /**
   * Constructs a ConversationLogger object.
   */
  public function __construct(
    Connection $database,
    ConfigFactoryInterface $config_factory,
    TimeInterface $time,
    LoggerInterface $logger
  ) {
    $this->database = $database;
    $this->configFactory = $config_factory;
    $this->time = $time;
    $this->logger = $logger;
  }

  /**
   * Checks if conversation logging is enabled.
   *
   * @return bool
   *   TRUE if conversation logging is enabled.
   */
  public function isEnabled(): bool {
    return $this->resolveConfig()['enabled'];
  }

  /**
   * Returns TRUE when user notice must be displayed (logging is active).
   *
   * @return bool
   *   TRUE if logging is enabled and user notice is required.
   */
  public function isUserNoticeRequired(): bool {
    $config = $this->resolveConfig();
    return $config['enabled'] && $config['show_user_notice'];
  }

  /**
   * Returns resolved conversation_logging config with privacy invariants.
   *
   * When logging is enabled, PII redaction and user notice are forced on.
   * Retention hours are always capped at MAX_RETENTION_HOURS.
   *
   * @return array{enabled: bool, retention_hours: int, redact_pii: bool, show_user_notice: bool}
   */
  private function resolveConfig(): array {
    $config = $this->configFactory->get('ilas_site_assistant.settings');

    $enabled = (bool) $config->get('conversation_logging.enabled');
    $retention_hours = (int) ($config->get('conversation_logging.retention_hours') ?? 72);
    $redact_pii = (bool) ($config->get('conversation_logging.redact_pii') ?? TRUE);
    $show_user_notice = (bool) ($config->get('conversation_logging.show_user_notice') ?? TRUE);

    if ($enabled) {
      $redact_pii = TRUE;
      $show_user_notice = TRUE;
    }

    $retention_hours = min($retention_hours, self::MAX_RETENTION_HOURS);

    return [
      'enabled' => $enabled,
      'retention_hours' => $retention_hours,
      'redact_pii' => $redact_pii,
      'show_user_notice' => $show_user_notice,
    ];
  }

  /**
   * Logs a user–assistant exchange.
   *
   * @param string $conversationId
   *   UUID grouping messages in one conversation.
   * @param string $userMessage
   *   The raw user message (minimized before storage).
   * @param string $assistantMessage
   *   The assistant response text (minimized before storage).
   * @param string $intent
   *   The detected intent type.
   * @param string $responseType
   *   The response type (faq, navigation, escalation, etc.).
   * @param string $requestId
   *   Per-request correlation UUID for tracing across logs.
   */
  public function logExchange(
    string $conversationId,
    string $userMessage,
    string $assistantMessage,
    string $intent,
    string $responseType,
    string $requestId = ''
  ): void {
    if (!$this->isEnabled()) {
      return;
    }

    // Verify the table exists (handles case before update hook runs).
    if (!$this->database->schema()->tableExists('ilas_site_assistant_conversations')) {
      return;
    }

    $now = $this->time->getRequestTime();

    $userMetadata = ObservabilityPayloadMinimizer::buildTextMetadata($userMessage);
    $assistantMetadata = ObservabilityPayloadMinimizer::buildTextMetadata(strip_tags($assistantMessage));

    // Sanitize request_id to valid UUID or NULL.
    $storedRequestId = NULL;
    if ($requestId !== '' && preg_match('/^[a-f0-9\-]{36}$/i', $requestId)) {
      $storedRequestId = $requestId;
    }

    try {
      // Log user message.
      $fields = [
        'conversation_id' => mb_substr($conversationId, 0, 36),
        'direction' => 'user',
        'message_hash' => $userMetadata['text_hash'],
        'message_length_bucket' => $userMetadata['length_bucket'],
        'redaction_profile' => $userMetadata['redaction_profile'],
        'intent' => $intent,
        'response_type' => NULL,
        'created' => $now,
      ];
      if ($storedRequestId !== NULL) {
        $fields['request_id'] = $storedRequestId;
      }
      $this->database->insert('ilas_site_assistant_conversations')
        ->fields($fields)
        ->execute();

      // Log assistant response.
      $fields = [
        'conversation_id' => mb_substr($conversationId, 0, 36),
        'direction' => 'assistant',
        'message_hash' => $assistantMetadata['text_hash'],
        'message_length_bucket' => $assistantMetadata['length_bucket'],
        'redaction_profile' => $assistantMetadata['redaction_profile'],
        'intent' => $intent,
        'response_type' => $responseType,
        'created' => $now,
      ];
      if ($storedRequestId !== NULL) {
        $fields['request_id'] = $storedRequestId;
      }
      $this->database->insert('ilas_site_assistant_conversations')
        ->fields($fields)
        ->execute();
    }
    catch (\Exception $e) {
      $this->logger
        ->error('Conversation logging failed: @class @error_signature', [
          '@class' => get_class($e),
          '@error_signature' => ObservabilityPayloadMinimizer::exceptionSignature($e),
        ]);
    }
  }

  /**
   * Cleans up expired conversation logs based on retention settings.
   *
   * Uses batched deletes (500 rows per iteration, max 100 iterations)
   * to avoid locking the table during cron.
   */
  public function cleanup(): void {
    if (!$this->database->schema()->tableExists('ilas_site_assistant_conversations')) {
      return;
    }

    $resolved = $this->resolveConfig();
    $retention_hours = $resolved['retention_hours'];
    $cutoff = $this->time->getRequestTime() - ($retention_hours * 3600);

    $batch_size = 500;
    $max_iterations = 100;
    $total_deleted = 0;

    try {
      for ($i = 0; $i < $max_iterations; $i++) {
        // Select IDs to delete in this batch.
        $ids = $this->database->select('ilas_site_assistant_conversations', 'c')
          ->fields('c', ['id'])
          ->condition('created', $cutoff, '<')
          ->range(0, $batch_size)
          ->execute()
          ->fetchCol();

        if (empty($ids)) {
          break;
        }

        $deleted = $this->database->delete('ilas_site_assistant_conversations')
          ->condition('id', $ids, 'IN')
          ->execute();

        $total_deleted += $deleted;

        // If we got fewer than batch_size, we're done.
        if (count($ids) < $batch_size) {
          break;
        }
      }

      if ($total_deleted > 0) {
        $this->logger
          ->info('Cleaned up @count expired conversation log entries.', [
            '@count' => $total_deleted,
          ]);
      }
    }
    catch (\Exception $e) {
      $this->logger
        ->error('Conversation cleanup failed: @class @error_signature', [
          '@class' => get_class($e),
          '@error_signature' => ObservabilityPayloadMinimizer::exceptionSignature($e),
        ]);
    }
  }

}
