<?php

declare(strict_types=1);

namespace Drupal\ilas_site_assistant_governance\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\ilas_site_assistant\Service\ObservabilityPayloadMinimizer;
use Drupal\ilas_site_assistant\Service\PiiRedactor;
use Psr\Log\LoggerInterface;

/**
 * Records legal-hold history for assistant governance records.
 */
class LegalHoldLogger {

  /**
   * Constructs the logger.
   */
  public function __construct(
    protected Connection $database,
    protected TimeInterface $time,
    protected AccountProxyInterface $currentUser,
    protected LoggerInterface $logger,
  ) {}

  /**
   * Records a new hold event.
   */
  public function recordHold(string $target_type, string $target_id, ?string $reason, ?string $notes = NULL, ?int $uid = NULL): void {
    try {
      $this->database->insert('ilas_site_assistant_legal_hold')
        ->fields([
          'target_type' => mb_substr($target_type, 0, 32),
          'target_id' => mb_substr($target_id, 0, 64),
          'hold_reason_redacted' => PiiRedactor::redactForStorage((string) $reason, 255),
          'hold_notes_redacted' => $notes !== NULL ? PiiRedactor::redactForStorage($notes, 2000) : NULL,
          'held_at' => $this->time->getRequestTime(),
          'held_by_uid' => $uid ?? (int) $this->currentUser->id(),
        ])
        ->execute();
    }
    catch (\Throwable $e) {
      $this->logger->error('Legal hold logging failed: @class @error_signature', [
        '@class' => get_class($e),
        '@error_signature' => ObservabilityPayloadMinimizer::exceptionSignature($e),
      ]);
    }
  }

  /**
   * Records a hold release event.
   */
  public function recordRelease(string $target_type, string $target_id, ?int $uid = NULL): void {
    try {
      $hold_id = $this->database->select('ilas_site_assistant_legal_hold', 'h')
        ->fields('h', ['id'])
        ->condition('target_type', $target_type)
        ->condition('target_id', $target_id)
        ->isNull('released_at')
        ->orderBy('id', 'DESC')
        ->range(0, 1)
        ->execute()
        ->fetchField();

      if ($hold_id === FALSE) {
        return;
      }

      $this->database->update('ilas_site_assistant_legal_hold')
        ->fields([
          'released_at' => $this->time->getRequestTime(),
          'released_by_uid' => $uid ?? (int) $this->currentUser->id(),
        ])
        ->condition('id', (int) $hold_id)
        ->execute();
    }
    catch (\Throwable $e) {
      $this->logger->error('Legal hold release logging failed: @class @error_signature', [
        '@class' => get_class($e),
        '@error_signature' => ObservabilityPayloadMinimizer::exceptionSignature($e),
      ]);
    }
  }

}
