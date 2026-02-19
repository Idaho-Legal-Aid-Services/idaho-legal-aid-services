<?php

namespace Drupal\ilas_site_assistant\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Component\Datetime\TimeInterface;

/**
 * Opt-in conversation logger with PII redaction for QA/debugging.
 *
 * Default: OFF. When enabled, stores redacted message pairs with a short
 * retention TTL. Access is gated by the 'view ilas site assistant conversations'
 * permission.
 */
class ConversationLogger {

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
   * Constructs a ConversationLogger object.
   */
  public function __construct(
    Connection $database,
    ConfigFactoryInterface $config_factory,
    TimeInterface $time
  ) {
    $this->database = $database;
    $this->configFactory = $config_factory;
    $this->time = $time;
  }

  /**
   * Checks if conversation logging is enabled.
   *
   * @return bool
   *   TRUE if conversation logging is enabled.
   */
  public function isEnabled(): bool {
    return (bool) $this->configFactory
      ->get('ilas_site_assistant.settings')
      ->get('conversation_logging.enabled');
  }

  /**
   * Logs a user–assistant exchange.
   *
   * @param string $conversationId
   *   UUID grouping messages in one conversation.
   * @param string $userMessage
   *   The raw user message (will be redacted before storage).
   * @param string $assistantMessage
   *   The assistant response text.
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

    // PII redaction is always-on — no config gate.
    $redactedUser = PiiRedactor::redactForStorage($userMessage, 500);
    $assistantMessage = mb_substr(strip_tags($assistantMessage), 0, 1000);

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
        'redacted_message' => $redactedUser,
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
        'redacted_message' => $assistantMessage,
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
      \Drupal::logger('ilas_site_assistant')
        ->error('Conversation logging failed: @message', [
          '@message' => $e->getMessage(),
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

    $config = $this->configFactory->get('ilas_site_assistant.settings');
    $retention_hours = (int) ($config->get('conversation_logging.retention_hours') ?? 72);
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
        \Drupal::logger('ilas_site_assistant')
          ->info('Cleaned up @count expired conversation log entries.', [
            '@count' => $total_deleted,
          ]);
      }
    }
    catch (\Exception $e) {
      \Drupal::logger('ilas_site_assistant')
        ->error('Conversation cleanup failed: @message', [
          '@message' => $e->getMessage(),
        ]);
    }
  }

}
