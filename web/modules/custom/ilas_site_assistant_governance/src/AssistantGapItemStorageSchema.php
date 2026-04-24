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
      if (isset($schema[$base_table]['fields']['cluster_hash'])) {
        $schema[$base_table]['unique keys'] += [
          'assistant_gap_item__cluster_hash' => ['cluster_hash'],
        ];
      }

      $indexes = [
        'assistant_gap_item__query_hash' => ['query_hash'],
        'assistant_gap_item__identity_context_key' => ['identity_context_key'],
        'assistant_gap_item__identity_selection_key' => ['identity_selection_key'],
        'assistant_gap_item__identity_intent' => ['identity_intent'],
        'assistant_gap_item__state_last_seen' => ['review_state', 'last_seen'],
        'assistant_gap_item__state_assigned_changed' => ['review_state', 'assigned_uid', 'changed'],
        'assistant_gap_item__topic_last_seen' => ['primary_topic_tid', 'last_seen'],
        'assistant_gap_item__service_area_last_seen' => ['primary_service_area_tid', 'last_seen'],
        'assistant_gap_item__assigned_changed' => ['assigned_uid', 'changed'],
        'assistant_gap_item__purge_hold' => ['purge_after', 'is_held'],
      ];

      foreach ($indexes as $index_name => $columns) {
        if ($this->schemaHasColumns($schema[$base_table], $columns)) {
          $schema[$base_table]['indexes'] += [
            $index_name => $columns,
          ];
        }
      }
    }

    return $schema;
  }

  /**
   * Checks whether all index columns exist in the current table schema.
   *
   * Entity definition updates install new base fields incrementally. During
   * that process the storage schema may be built before every future column is
   * present, so custom indexes must only reference columns in the active schema.
   *
   * @param array<string, mixed> $table_schema
   *   The table schema definition.
   * @param string[] $columns
   *   The index columns.
   */
  protected function schemaHasColumns(array $table_schema, array $columns): bool {
    foreach ($columns as $column) {
      if (!isset($table_schema['fields'][$column])) {
        return FALSE;
      }
    }

    return TRUE;
  }

}
