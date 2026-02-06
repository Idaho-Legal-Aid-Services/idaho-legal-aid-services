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
   * The policy filter service.
   *
   * @var \Drupal\ilas_site_assistant\Service\PolicyFilter
   */
  protected $policyFilter;

  /**
   * Constructs a ConversationLogger object.
   */
  public function __construct(
    Connection $database,
    ConfigFactoryInterface $config_factory,
    TimeInterface $time,
    PolicyFilter $policy_filter
  ) {
    $this->database = $database;
    $this->configFactory = $config_factory;
    $this->time = $time;
    $this->policyFilter = $policy_filter;
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
   */
  public function logExchange(
    string $conversationId,
    string $userMessage,
    string $assistantMessage,
    string $intent,
    string $responseType
  ): void {
    if (!$this->isEnabled()) {
      return;
    }

    // Verify the table exists (handles case before update hook runs).
    if (!$this->database->schema()->tableExists('ilas_site_assistant_conversations')) {
      return;
    }

    $config = $this->configFactory->get('ilas_site_assistant.settings');
    $now = $this->time->getRequestTime();

    // Redact PII from user message.
    $redactedUser = $config->get('conversation_logging.redact_pii') !== FALSE
      ? $this->redactPii($userMessage)
      : $userMessage;

    // Truncate to prevent storage of very long messages.
    $redactedUser = mb_substr($redactedUser, 0, 500);
    $assistantMessage = mb_substr(strip_tags($assistantMessage), 0, 1000);

    try {
      // Log user message.
      $this->database->insert('ilas_site_assistant_conversations')
        ->fields([
          'conversation_id' => mb_substr($conversationId, 0, 36),
          'direction' => 'user',
          'redacted_message' => $redactedUser,
          'intent' => $intent,
          'response_type' => NULL,
          'created' => $now,
        ])
        ->execute();

      // Log assistant response.
      $this->database->insert('ilas_site_assistant_conversations')
        ->fields([
          'conversation_id' => mb_substr($conversationId, 0, 36),
          'direction' => 'assistant',
          'redacted_message' => $assistantMessage,
          'intent' => $intent,
          'response_type' => $responseType,
          'created' => $now,
        ])
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
   * Redacts obvious PII patterns from text.
   *
   * @param string $text
   *   The text to redact.
   *
   * @return string
   *   Redacted text with PII replaced by tokens.
   */
  protected function redactPii(string $text): string {
    // Use existing PolicyFilter sanitization first.
    $text = $this->policyFilter->sanitizeForStorage($text);

    // Email addresses.
    $text = preg_replace(
      '/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/',
      '[EMAIL]', $text
    );

    // SSN patterns (###-##-####).
    $text = preg_replace(
      '/\b\d{3}-\d{2}-\d{4}\b/',
      '[SSN]', $text
    );

    // Phone numbers.
    $text = preg_replace(
      '/\b(\d{3}[-.\s]?\d{3}[-.\s]?\d{4}|\(\d{3}\)\s*\d{3}[-.\s]?\d{4})\b/',
      '[PHONE]', $text
    );

    // Date patterns (MM/DD/YYYY or similar).
    $text = preg_replace(
      '/\b\d{1,2}[\/\-]\d{1,2}[\/\-]\d{2,4}\b/',
      '[DATE]', $text
    );

    // Street addresses (simple heuristic: number + words + street suffix).
    $text = preg_replace(
      '/\b\d{1,5}\s+[\w\s]{1,40}\b(street|st|avenue|ave|road|rd|drive|dr|lane|ln|court|ct|boulevard|blvd|way|place|pl)\b/i',
      '[ADDRESS]', $text
    );

    // Idaho court case numbers (CV-XX-XXXXX, DR-XX-XXXXX, etc.).
    $text = preg_replace(
      '/\b(CV|DR|CR|JV|MH|SP)-\d{2,4}-\d{2,8}\b/i',
      '[CASE_NUM]', $text
    );

    return $text;
  }

  /**
   * Cleans up expired conversation logs based on retention settings.
   */
  public function cleanup(): void {
    if (!$this->database->schema()->tableExists('ilas_site_assistant_conversations')) {
      return;
    }

    $config = $this->configFactory->get('ilas_site_assistant.settings');
    $retention_hours = (int) ($config->get('conversation_logging.retention_hours') ?? 72);
    $cutoff = $this->time->getRequestTime() - ($retention_hours * 3600);

    try {
      $deleted = $this->database->delete('ilas_site_assistant_conversations')
        ->condition('created', $cutoff, '<')
        ->execute();

      if ($deleted > 0) {
        \Drupal::logger('ilas_site_assistant')
          ->info('Cleaned up @count expired conversation log entries.', [
            '@count' => $deleted,
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
