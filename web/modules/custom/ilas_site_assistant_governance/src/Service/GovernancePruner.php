<?php

declare(strict_types=1);

namespace Drupal\ilas_site_assistant_governance\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\State\StateInterface;
use Psr\Log\LoggerInterface;

/**
 * Chunked pruner for governance storage.
 */
class GovernancePruner {

  /**
   * Constructs the pruner.
   */
  public function __construct(
    protected Connection $database,
    protected TimeInterface $time,
    protected StateInterface $state,
    protected LoggerInterface $logger,
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Runs chunked pruning across governance tables.
   */
  public function prune(int $batch_size = 250, int $max_iterations = 10): void {
    $now = $this->time->getRequestTime();
    $deleted_sessions = 0;
    $deleted_gap_items = 0;

    try {
      for ($i = 0; $i < $max_iterations; $i++) {
        $conversation_ids = $this->database->select('ilas_site_assistant_conversation_session', 's')
          ->fields('s', ['conversation_id'])
          ->condition('purge_after', $now, '<=')
          ->condition('is_held', 0)
          ->range(0, $batch_size)
          ->execute()
          ->fetchCol();

        if ($conversation_ids === []) {
          break;
        }

        $this->database->delete('ilas_site_assistant_conversation_turn')
          ->condition('conversation_id', $conversation_ids, 'IN')
          ->execute();
        $deleted_sessions += $this->database->delete('ilas_site_assistant_conversation_session')
          ->condition('conversation_id', $conversation_ids, 'IN')
          ->execute();
      }

      /** @var \Drupal\Core\Entity\ContentEntityStorageInterface $storage */
      $storage = $this->entityTypeManager->getStorage('assistant_gap_item');
      for ($i = 0; $i < $max_iterations; $i++) {
        $gap_item_ids = $this->database->select('assistant_gap_item', 'g')
          ->fields('g', ['id'])
          ->condition('purge_after', $now, '<=')
          ->condition('is_held', 0)
          ->range(0, $batch_size)
          ->execute()
          ->fetchCol();

        if ($gap_item_ids === []) {
          break;
        }

        $this->database->delete('ilas_site_assistant_gap_hit')
          ->condition('gap_item_id', $gap_item_ids, 'IN')
          ->execute();

        $entities = $storage->loadMultiple(array_map('intval', $gap_item_ids));
        $deleted_gap_items += count($entities);
        $storage->delete($entities);
      }

      $this->state->set('ilas_site_assistant_governance.pruner.last_run', [
        'timestamp' => $now,
        'deleted_sessions' => $deleted_sessions,
        'deleted_gap_items' => $deleted_gap_items,
      ]);
    }
    catch (\Throwable $e) {
      $this->logger->error('Governance prune failed: @class @message', [
        '@class' => get_class($e),
        '@message' => $e->getMessage(),
      ]);
    }
  }

}
