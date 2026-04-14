<?php

declare(strict_types=1);

namespace Drupal\ilas_site_assistant_governance;

use Drupal\Core\Entity\ContentEntityTypeInterface;
use Drupal\Core\Entity\Sql\SqlContentEntityStorageSchema;

/**
 * Adds custom indexes to the assistant gap item storage schema.
 */
class AssistantGapItemStorageSchema extends SqlContentEntityStorageSchema {

  /**
   * {@inheritdoc}
   */
  protected function getEntitySchema(ContentEntityTypeInterface $entity_type, $reset = FALSE): array {
    $schema = parent::getEntitySchema($entity_type, $reset);

    if ($base_table = $this->storage->getBaseTable()) {
      $schema[$base_table]['unique keys'] += [
        'assistant_gap_item__cluster_hash' => ['cluster_hash'],
      ];
      $schema[$base_table]['indexes'] += [
        'assistant_gap_item__query_hash' => ['query_hash'],
        'assistant_gap_item__state_last_seen' => ['review_state', 'last_seen'],
        'assistant_gap_item__state_assigned_changed' => ['review_state', 'assigned_uid', 'changed'],
        'assistant_gap_item__topic_last_seen' => ['primary_topic_tid', 'last_seen'],
        'assistant_gap_item__service_area_last_seen' => ['primary_service_area_tid', 'last_seen'],
        'assistant_gap_item__assigned_changed' => ['assigned_uid', 'changed'],
        'assistant_gap_item__purge_hold' => ['purge_after', 'is_held'],
      ];
    }

    return $schema;
  }

}
