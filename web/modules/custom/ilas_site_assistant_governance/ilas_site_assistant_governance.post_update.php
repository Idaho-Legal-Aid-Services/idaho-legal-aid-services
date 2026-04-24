<?php

declare(strict_types=1);

use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Statement\FetchAs;
use Drupal\ilas_site_assistant_governance\Entity\AssistantGapItem;
use Drupal\ilas_site_assistant_governance\Service\GapItemIdentityBuilder;
use Drupal\ilas_site_assistant_governance\Service\GovernanceConversationLogger;

/**
 * Backfills canonical gap items from legacy no-answer rows.
 */
function ilas_site_assistant_governance_post_update_backfill_gap_items(array &$sandbox): string {
  $database = \Drupal::database();
  if (!$database->schema()->tableExists('ilas_site_assistant_no_answer')) {
    return (string) t('Legacy no-answer table is unavailable; nothing to backfill.');
  }

  if (!isset($sandbox['max'])) {
    $sandbox['max'] = (int) $database->select('ilas_site_assistant_no_answer', 'n')
      ->countQuery()
      ->execute()
      ->fetchField();
    $sandbox['progress'] = 0;
    $sandbox['last_id'] = 0;
  }

  if ((int) $sandbox['max'] === 0) {
    $sandbox['#finished'] = 1;
    return (string) t('No legacy no-answer rows required backfill.');
  }

  $rows = $database->select('ilas_site_assistant_no_answer', 'n')
    ->fields('n')
    ->condition('id', (int) $sandbox['last_id'], '>')
    ->orderBy('id', 'ASC')
    ->range(0, 50)
    ->execute()
    ->fetchAllAssoc('id');

  /** @var \Drupal\Core\Entity\ContentEntityStorageInterface $storage */
  $storage = \Drupal::entityTypeManager()->getStorage('assistant_gap_item');
  /** @var \Drupal\ilas_site_assistant_governance\Service\GapItemIdentityBuilder $identity_builder */
  $identity_builder = \Drupal::service('ilas_site_assistant_governance.gap_item_identity_builder');

  foreach ($rows as $id => $row) {
    $identity = $identity_builder->buildFromLegacyNoAnswerRow($row->query_hash, $row->language_hint);
    $existing = $storage->loadByProperties(['cluster_hash' => $identity['cluster_hash']]);
    if (!$existing) {
      $entity = $storage->create([
        'cluster_hash' => $identity['cluster_hash'],
        'query_hash' => $row->query_hash,
        'language_hint' => $row->language_hint,
        'query_length_bucket' => $row->length_bucket,
        'redaction_profile' => $row->redaction_profile,
        'identity_context_key' => $identity['identity_context_key'],
        'identity_source' => $identity['identity_source'],
        'identity_selection_key' => $identity['identity_selection_key'],
        'identity_intent' => $identity['identity_intent'],
        'identity_topic_tid' => $identity['identity_topic_tid'],
        'identity_service_area_tid' => $identity['identity_service_area_tid'],
        'review_state' => AssistantGapItem::STATE_NEW,
        'topic_assignment_source' => 'legacy_none',
        'first_seen' => (int) $row->first_seen,
        'last_seen' => (int) $row->last_seen,
        'occurrence_count_total' => (int) $row->count,
        'occurrence_count_unresolved' => (int) $row->count,
      ]);
      $entity->setRevisionLogMessage('Backfilled from legacy no-answer aggregate row.');
      $entity->setNewRevision(TRUE);
      $entity->save();
    }

    $sandbox['last_id'] = (int) $id;
    $sandbox['progress']++;
  }

  $sandbox['#finished'] = min(1, $sandbox['progress'] / $sandbox['max']);
  return (string) t('Backfilled @count legacy no-answer rows into assistant gap items.', ['@count' => $sandbox['progress']]);
}

/**
 * Backfills canonical conversation-session headers from legacy metadata logs.
 */
function ilas_site_assistant_governance_post_update_backfill_conversation_sessions(array &$sandbox): string {
  $database = \Drupal::database();
  if (
    !$database->schema()->tableExists('ilas_site_assistant_conversations')
    || !$database->schema()->tableExists('ilas_site_assistant_conversation_session')
  ) {
    return (string) t('Legacy or governance conversation tables are unavailable; nothing to backfill.');
  }

  if (!isset($sandbox['max'])) {
    $sandbox['max'] = (int) $database->select('ilas_site_assistant_conversations', 'c')
      ->addExpression('COUNT(DISTINCT c.conversation_id)', 'total')
      ->execute()
      ->fetchField();
    $sandbox['progress'] = 0;
    $sandbox['offset'] = 0;
  }

  if ((int) $sandbox['max'] === 0) {
    $sandbox['#finished'] = 1;
    return (string) t('No legacy conversation headers required backfill.');
  }

  $conversation_ids = $database->select('ilas_site_assistant_conversations', 'c')
    ->fields('c', ['conversation_id'])
    ->groupBy('conversation_id')
    ->orderBy('conversation_id', 'ASC')
    ->range((int) $sandbox['offset'], 50)
    ->execute()
    ->fetchCol();

  foreach ($conversation_ids as $conversation_id) {
    $exists = $database->select('ilas_site_assistant_conversation_session', 's')
      ->fields('s', ['conversation_id'])
      ->condition('conversation_id', $conversation_id)
      ->execute()
      ->fetchField();

    if ($exists !== FALSE) {
      $sandbox['progress']++;
      continue;
    }

    $summary = $database->select('ilas_site_assistant_conversations', 'c')
      ->condition('conversation_id', $conversation_id);
    $summary->addExpression('MIN(c.created)', 'first_message_at');
    $summary->addExpression('MAX(c.created)', 'last_message_at');
    $summary->addExpression('COUNT(c.id)', 'turn_count');
    $summary = $summary->execute()->fetchAssoc();

    $latest = $database->select('ilas_site_assistant_conversations', 'c')
      ->fields('c', ['intent', 'response_type', 'request_id'])
      ->condition('conversation_id', $conversation_id)
      ->orderBy('created', 'DESC')
      ->orderBy('id', 'DESC')
      ->range(0, 1)
      ->execute()
      ->fetchAssoc() ?: [];

    $last_message_at = (int) ($summary['last_message_at'] ?? 0);
    $turn_count = (int) ($summary['turn_count'] ?? 0);

    $database->insert('ilas_site_assistant_conversation_session')
      ->fields([
        'conversation_id' => $conversation_id,
        'first_message_at' => (int) ($summary['first_message_at'] ?? 0),
        'last_message_at' => $last_message_at,
        'turn_count' => $turn_count,
        'exchange_count' => (int) ceil($turn_count / 2),
        'language_hint' => 'unknown',
        'last_intent' => $latest['intent'] ?? NULL,
        'last_response_type' => $latest['response_type'] ?? NULL,
        'first_request_id' => NULL,
        'last_request_id' => $latest['request_id'] ?? NULL,
        'has_no_answer' => 0,
        'is_held' => 0,
        'purge_after' => $last_message_at + (90 * 86400),
      ])
      ->execute();

    $sandbox['progress']++;
  }

  $sandbox['offset'] += count($conversation_ids);
  $sandbox['#finished'] = min(1, $sandbox['progress'] / $sandbox['max']);
  return (string) t('Backfilled @count legacy conversation sessions.', ['@count' => $sandbox['progress']]);
}

/**
 * Clears stale topic-confidence values from gap items without a topic.
 */
function ilas_site_assistant_governance_post_update_clear_stale_gap_confidence(array &$sandbox): string {
  /** @var \Drupal\Core\Entity\ContentEntityStorageInterface $storage */
  $storage = \Drupal::entityTypeManager()->getStorage('assistant_gap_item');

  if (!isset($sandbox['ids'])) {
    $sandbox['ids'] = array_values($storage->getQuery()
      ->accessCheck(FALSE)
      ->execute());
    $sandbox['progress'] = 0;
    $sandbox['max'] = count($sandbox['ids']);
  }

  if ((int) $sandbox['max'] === 0) {
    $sandbox['#finished'] = 1;
    return (string) t('No assistant gap items required confidence cleanup.');
  }

  $batch_ids = array_slice($sandbox['ids'], (int) $sandbox['progress'], 25);
  /** @var \Drupal\ilas_site_assistant_governance\Entity\AssistantGapItem[] $entities */
  $entities = $storage->loadMultiple($batch_ids);

  foreach ($entities as $entity) {
    if ($entity->get('primary_topic_tid')->isEmpty() && !$entity->get('topic_assignment_confidence')->isEmpty()) {
      $entity->set('topic_assignment_confidence', NULL);
      $entity->setNewRevision(TRUE);
      $entity->setRevisionLogMessage('Cleared stale topic confidence from a gap item without a topic.');
      $entity->save();
    }
    $sandbox['progress']++;
  }

  $sandbox['#finished'] = min(1, $sandbox['progress'] / $sandbox['max']);
  return (string) t('Processed @count assistant gap items for confidence cleanup.', ['@count' => $sandbox['progress']]);
}

/**
 * Repairs assistant gap-item identity boundaries from per-hit evidence.
 */
function ilas_site_assistant_governance_post_update_repair_gap_item_identity(array &$sandbox): string {
  $database = \Drupal::database();
  $schema = $database->schema();
  if (
    !$schema->tableExists('assistant_gap_item')
    || !$schema->tableExists('ilas_site_assistant_gap_hit')
  ) {
    return (string) t('Assistant gap-item storage is unavailable; nothing to repair.');
  }

  /** @var \Drupal\Core\Entity\ContentEntityStorageInterface $storage */
  $storage = \Drupal::entityTypeManager()->getStorage('assistant_gap_item');
  /** @var \Drupal\ilas_site_assistant_governance\Service\GapItemIdentityBuilder $identity_builder */
  $identity_builder = \Drupal::service('ilas_site_assistant_governance.gap_item_identity_builder');

  if (!isset($sandbox['ids'])) {
    $sandbox['ids'] = array_values($storage->getQuery()
      ->accessCheck(FALSE)
      ->sort('id', 'ASC')
      ->execute());
    $sandbox['progress'] = 0;
    $sandbox['max'] = count($sandbox['ids']);
  }

  if ((int) $sandbox['max'] === 0) {
    $sandbox['#finished'] = 1;
    return (string) t('No assistant gap items required identity repair.');
  }

  $batch_ids = array_slice($sandbox['ids'], (int) $sandbox['progress'], 10);
  foreach ($batch_ids as $entity_id) {
    /** @var \Drupal\ilas_site_assistant_governance\Entity\AssistantGapItem|null $entity */
    $entity = $storage->load($entity_id);
    if ($entity instanceof AssistantGapItem) {
      ilas_site_assistant_governance_repair_gap_item_identity_entity($entity, $storage, $database, $identity_builder);
    }
    $sandbox['progress']++;
  }

  $sandbox['#finished'] = min(1, $sandbox['progress'] / $sandbox['max']);
  return (string) t('Processed @count of @total assistant gap items for identity repair.', [
    '@count' => (int) $sandbox['progress'],
    '@total' => (int) $sandbox['max'],
  ]);
}

/**
 * Repairs one gap item by regrouping its hit evidence under new identity rules.
 *
 * @param \Drupal\ilas_site_assistant_governance\Entity\AssistantGapItem $entity
 *   The original gap item.
 * @param \Drupal\Core\Entity\ContentEntityStorageInterface $storage
 *   The assistant gap-item storage.
 * @param \Drupal\Core\Database\Connection $database
 *   The database connection.
 * @param \Drupal\ilas_site_assistant_governance\Service\GapItemIdentityBuilder $identity_builder
 *   The shared immutable identity builder.
 */
function ilas_site_assistant_governance_repair_gap_item_identity_entity(
  AssistantGapItem $entity,
  $storage,
  Connection $database,
  GapItemIdentityBuilder $identity_builder,
): void {
  $hits = $database->select('ilas_site_assistant_gap_hit', 'h')
    ->fields('h')
    ->condition('gap_item_id', (int) $entity->id())
    ->orderBy('occurred_at', 'ASC')
    ->orderBy('id', 'ASC')
    ->execute()
    ->fetchAll(FetchAs::Associative);

  if ($hits === []) {
    $fallback_source = (string) ($entity->get('topic_assignment_source')->value ?? 'unknown') === 'legacy_none'
      ? 'legacy'
      : 'route';
    $identity = $identity_builder->buildUnknownIdentity(
      (string) ($entity->get('query_hash')->value ?? ''),
      (string) ($entity->get('language_hint')->value ?? 'unknown'),
      $fallback_source,
    );
    ilas_site_assistant_governance_apply_identity_to_gap_item($entity, $identity);
    $entity->setNewRevision(TRUE);
    $entity->setRevisionLogMessage('Repaired immutable identity for a no-evidence assistant gap item.');
    $entity->save();
    return;
  }

  $groups = ilas_site_assistant_governance_group_gap_hits_by_identity($hits, $identity_builder);
  if (count($groups) === 1) {
    $group = reset($groups);
    assert(is_array($group));
    $summary = ilas_site_assistant_governance_summarize_gap_hit_group($group['hits']);
    ilas_site_assistant_governance_apply_identity_to_gap_item($entity, $group['identity']);
    ilas_site_assistant_governance_apply_hit_summary_to_gap_item($entity, $summary);
    ilas_site_assistant_governance_fill_blank_topic_context($entity, $group['identity']);
    $entity->setNewRevision(TRUE);
    $entity->setRevisionLogMessage('Repaired immutable identity boundaries from gap-hit evidence.');
    $entity->save();

    $conversation_ids = ilas_site_assistant_governance_rewrite_turn_links_for_hits($database, $group['hits'], (int) $entity->id());
    ilas_site_assistant_governance_refresh_conversation_sessions($database, $conversation_ids);
    return;
  }

  $ordered_group_keys = array_keys($groups);
  usort($ordered_group_keys, static function (string $left, string $right) use ($groups): int {
    $left_group = $groups[$left];
    $right_group = $groups[$right];
    $count_compare = count($right_group['hit_ids']) <=> count($left_group['hit_ids']);
    if ($count_compare !== 0) {
      return $count_compare;
    }

    return (($right_group['last_occurred_at'] ?? 0) <=> ($left_group['last_occurred_at'] ?? 0));
  });

  $all_conversation_ids = [];
  foreach ($ordered_group_keys as $index => $group_key) {
    $group = $groups[$group_key];
    $summary = ilas_site_assistant_governance_summarize_gap_hit_group($group['hits']);
    $target_entity = $index === 0 ? $entity : $storage->create(
      ilas_site_assistant_governance_build_split_gap_item_values($entity, $group['identity'], $summary, $group['hits'])
    );

    ilas_site_assistant_governance_apply_identity_to_gap_item($target_entity, $group['identity']);
    ilas_site_assistant_governance_apply_hit_summary_to_gap_item($target_entity, $summary);
    ilas_site_assistant_governance_requeue_split_gap_item($target_entity, $group['identity'], $group['hits']);
    $target_entity->setNewRevision(TRUE);
    $target_entity->setRevisionLogMessage('Split a contaminated assistant gap item into an immutable context boundary.');
    $target_entity->save();

    $target_id = (int) $target_entity->id();
    $database->update('ilas_site_assistant_gap_hit')
      ->fields([
        'gap_item_id' => $target_id,
        'is_unresolved' => 1,
      ])
      ->condition('id', $group['hit_ids'], 'IN')
      ->execute();

    $all_conversation_ids = array_merge(
      $all_conversation_ids,
      ilas_site_assistant_governance_rewrite_turn_links_for_hits($database, $group['hits'], $target_id),
    );
  }

  ilas_site_assistant_governance_refresh_conversation_sessions($database, $all_conversation_ids);
}

/**
 * Groups gap-hit rows by their corrected immutable identity.
 *
 * @param array<int, array<string, mixed>> $hits
 *   Hit rows keyed numerically.
 * @param \Drupal\ilas_site_assistant_governance\Service\GapItemIdentityBuilder $identity_builder
 *   The identity builder.
 *
 * @return array<string, array<string, mixed>>
 *   Group data keyed by corrected cluster hash.
 */
function ilas_site_assistant_governance_group_gap_hits_by_identity(array $hits, GapItemIdentityBuilder $identity_builder): array {
  $groups = [];

  foreach ($hits as $hit) {
    $identity = $identity_builder->buildFromHitRecord($hit);
    $group_key = $identity['cluster_hash'];
    if (!isset($groups[$group_key])) {
      $groups[$group_key] = [
        'identity' => $identity,
        'hits' => [],
        'hit_ids' => [],
        'last_occurred_at' => 0,
      ];
    }

    $groups[$group_key]['hits'][] = $hit;
    $groups[$group_key]['hit_ids'][] = (int) $hit['id'];
    $groups[$group_key]['last_occurred_at'] = max(
      (int) $groups[$group_key]['last_occurred_at'],
      (int) ($hit['occurred_at'] ?? 0),
    );
  }

  return $groups;
}

/**
 * Summarizes one hit group for canonical gap-item counters and timestamps.
 *
 * @param array<int, array<string, mixed>> $hits
 *   Ordered hit rows.
 *
 * @return array<string, mixed>
 *   Summary values.
 */
function ilas_site_assistant_governance_summarize_gap_hit_group(array $hits): array {
  $first = reset($hits) ?: [];
  $last = end($hits) ?: [];
  $occurrence_count_total = count($hits);
  $occurrence_count_unresolved = 0;

  foreach ($hits as $hit) {
    if (!empty($hit['is_unresolved'])) {
      $occurrence_count_unresolved++;
    }
  }

  return [
    'first_seen' => (int) ($first['occurred_at'] ?? 0),
    'last_seen' => (int) ($last['occurred_at'] ?? 0),
    'occurrence_count_total' => $occurrence_count_total,
    'occurrence_count_unresolved' => $occurrence_count_unresolved,
    'first_conversation_id' => ilas_site_assistant_governance_truncate_nullable_string($first['conversation_id'] ?? NULL, 36),
    'latest_conversation_id' => ilas_site_assistant_governance_truncate_nullable_string($last['conversation_id'] ?? NULL, 36),
    'latest_request_id' => ilas_site_assistant_governance_truncate_nullable_string($last['request_id'] ?? NULL, 36),
  ];
}

/**
 * Applies immutable identity values to a gap item entity.
 *
 * @param \Drupal\ilas_site_assistant_governance\Entity\AssistantGapItem $entity
 *   The target gap item.
 * @param array<string, mixed> $identity
 *   Immutable identity values.
 */
function ilas_site_assistant_governance_apply_identity_to_gap_item(AssistantGapItem $entity, array $identity): void {
  $entity->set('cluster_hash', $identity['cluster_hash']);
  $entity->set('identity_context_key', $identity['identity_context_key']);
  $entity->set('identity_source', $identity['identity_source']);
  $entity->set('identity_selection_key', $identity['identity_selection_key']);
  $entity->set('identity_intent', $identity['identity_intent']);
  $entity->set('identity_topic_tid', $identity['identity_topic_tid']);
  $entity->set('identity_service_area_tid', $identity['identity_service_area_tid']);
}

/**
 * Applies per-hit summary values to a gap item entity.
 *
 * @param \Drupal\ilas_site_assistant_governance\Entity\AssistantGapItem $entity
 *   The target gap item.
 * @param array<string, mixed> $summary
 *   Summary values.
 */
function ilas_site_assistant_governance_apply_hit_summary_to_gap_item(AssistantGapItem $entity, array $summary): void {
  $entity->set('first_seen', $summary['first_seen']);
  $entity->set('last_seen', $summary['last_seen']);
  $entity->set('occurrence_count_total', $summary['occurrence_count_total']);
  $entity->set('occurrence_count_unresolved', $summary['occurrence_count_unresolved']);
  $entity->set('first_conversation_id', $summary['first_conversation_id']);
  $entity->set('latest_conversation_id', $summary['latest_conversation_id']);
  $entity->set('latest_request_id', $summary['latest_request_id']);
}

/**
 * Seeds mutable topic context only when it is still blank.
 *
 * @param \Drupal\ilas_site_assistant_governance\Entity\AssistantGapItem $entity
 *   The target gap item.
 * @param array<string, mixed> $identity
 *   Immutable identity values.
 */
function ilas_site_assistant_governance_fill_blank_topic_context(AssistantGapItem $entity, array $identity): void {
  if ($entity->get('primary_topic_tid')->isEmpty() && !empty($identity['identity_topic_tid'])) {
    $entity->set('primary_topic_tid', (int) $identity['identity_topic_tid']);
  }
  if ($entity->get('primary_service_area_tid')->isEmpty() && !empty($identity['identity_service_area_tid'])) {
    $entity->set('primary_service_area_tid', (int) $identity['identity_service_area_tid']);
  }
}

/**
 * Builds create-values for a split child gap item.
 *
 * @param \Drupal\ilas_site_assistant_governance\Entity\AssistantGapItem $original
 *   The original contaminated gap item.
 * @param array<string, mixed> $identity
 *   Immutable identity values.
 * @param array<string, mixed> $summary
 *   Hit-summary values.
 * @param array<int, array<string, mixed>> $hits
 *   Grouped hit rows.
 *
 * @return array<string, mixed>
 *   Entity create values.
 */
function ilas_site_assistant_governance_build_split_gap_item_values(
  AssistantGapItem $original,
  array $identity,
  array $summary,
  array $hits,
): array {
  return [
    'uid' => (int) ($original->getOwnerId() ?? 0),
    'created' => (int) ($original->get('created')->value ?? 0),
    'cluster_hash' => $identity['cluster_hash'],
    'query_hash' => (string) ($original->get('query_hash')->value ?? ''),
    'exemplar_redacted_query' => (string) ($original->get('exemplar_redacted_query')->value ?? ''),
    'language_hint' => (string) ($original->get('language_hint')->value ?? 'unknown'),
    'query_length_bucket' => (string) ($original->get('query_length_bucket')->value ?? 'empty'),
    'redaction_profile' => (string) ($original->get('redaction_profile')->value ?? 'none'),
    'identity_context_key' => $identity['identity_context_key'],
    'identity_source' => $identity['identity_source'],
    'identity_selection_key' => $identity['identity_selection_key'],
    'identity_intent' => $identity['identity_intent'],
    'identity_topic_tid' => $identity['identity_topic_tid'],
    'identity_service_area_tid' => $identity['identity_service_area_tid'],
    'review_state' => AssistantGapItem::STATE_NEW,
    'first_seen' => $summary['first_seen'],
    'last_seen' => $summary['last_seen'],
    'occurrence_count_total' => $summary['occurrence_count_total'],
    'occurrence_count_unresolved' => count($hits),
    'first_conversation_id' => $summary['first_conversation_id'],
    'latest_conversation_id' => $summary['latest_conversation_id'],
    'latest_request_id' => $summary['latest_request_id'],
    'is_held' => !empty($original->get('is_held')->value) ? 1 : 0,
    'held_at' => $original->get('held_at')->value,
    'held_by_uid' => $original->get('held_by_uid')->target_id,
    'hold_reason_summary' => $original->get('hold_reason_summary')->value,
  ];
}

/**
 * Resets a split gap item into fresh open reviewer work.
 *
 * @param \Drupal\ilas_site_assistant_governance\Entity\AssistantGapItem $entity
 *   The gap item to reset.
 * @param array<string, mixed> $identity
 *   Immutable identity values.
 * @param array<int, array<string, mixed>> $hits
 *   The grouped hit rows.
 */
function ilas_site_assistant_governance_requeue_split_gap_item(AssistantGapItem $entity, array $identity, array $hits): void {
  $entity->set('review_state', AssistantGapItem::STATE_NEW);
  $entity->set('assigned_uid', NULL);
  $entity->set('reviewed_at', NULL);
  $entity->set('reviewed_uid', NULL);
  $entity->set('resolved_at', NULL);
  $entity->set('resolved_uid', NULL);
  $entity->set('resolution_code', NULL);
  $entity->set('resolution_reference', NULL);
  $entity->set('resolution_notes', NULL);
  $entity->set('secondary_flags', []);
  $entity->set('purge_after', NULL);
  $entity->set('topic_assignment_confidence', NULL);
  $entity->set('primary_topic_tid', $identity['identity_topic_tid']);
  $entity->set('primary_service_area_tid', $identity['identity_service_area_tid']);
  $entity->set('topic_assignment_source', ilas_site_assistant_governance_choose_group_assignment_source($hits, $identity));
  $entity->set('occurrence_count_unresolved', count($hits));

  if (empty($entity->get('is_held')->value)) {
    $entity->set('held_at', NULL);
    $entity->set('held_by_uid', NULL);
    $entity->set('hold_reason_summary', NULL);
  }
}

/**
 * Chooses the best non-reviewer assignment source from grouped hit context.
 *
 * @param array<int, array<string, mixed>> $hits
 *   Grouped hit rows.
 * @param array<string, mixed> $identity
 *   Immutable identity values.
 */
function ilas_site_assistant_governance_choose_group_assignment_source(array $hits, array $identity): string {
  foreach ($hits as $hit) {
    $source = trim((string) ($hit['assignment_source'] ?? ''));
    if ($source !== '' && $source !== 'reviewer' && isset(AssistantGapItem::topicAssignmentSourceOptions()[$source])) {
      return $source;
    }
  }

  return match ($identity['identity_source']) {
    'selection' => 'selection',
    'legacy' => 'legacy_none',
    default => (!empty($identity['identity_topic_tid']) || !empty($identity['identity_service_area_tid'])) ? 'router' : 'unknown',
  };
}

/**
 * Rewrites conversation-turn gap-item references for grouped hits.
 *
 * @param \Drupal\Core\Database\Connection $database
 *   The database connection.
 * @param array<int, array<string, mixed>> $hits
 *   Grouped hit rows.
 * @param int $gap_item_id
 *   The target gap-item ID.
 *
 * @return string[]
 *   Distinct affected conversation IDs.
 */
function ilas_site_assistant_governance_rewrite_turn_links_for_hits(Connection $database, array $hits, int $gap_item_id): array {
  $conversation_ids = [];
  $schema = $database->schema();
  if (!$schema->tableExists('ilas_site_assistant_conversation_turn')) {
    return $conversation_ids;
  }

  foreach ($hits as $hit) {
    $conversation_id = ilas_site_assistant_governance_truncate_nullable_string($hit['conversation_id'] ?? NULL, 36);
    $request_id = ilas_site_assistant_governance_truncate_nullable_string($hit['request_id'] ?? NULL, 36);
    if ($conversation_id !== NULL) {
      $conversation_ids[] = $conversation_id;
    }

    $updated = 0;
    if ($request_id !== NULL) {
      $update = $database->update('ilas_site_assistant_conversation_turn')
        ->fields([
          'gap_item_id' => $gap_item_id,
          'is_no_answer' => 1,
        ])
        ->condition('request_id', $request_id);

      if ($conversation_id !== NULL) {
        $update->condition('conversation_id', $conversation_id);
      }

      $updated = (int) $update->execute();
    }

    if ($updated === 0 && $conversation_id !== NULL && !empty($hit['occurred_at'])) {
      $database->update('ilas_site_assistant_conversation_turn')
        ->fields([
          'gap_item_id' => $gap_item_id,
          'is_no_answer' => 1,
        ])
        ->condition('conversation_id', $conversation_id)
        ->condition('created', (int) $hit['occurred_at'])
        ->execute();
    }
  }

  return array_values(array_unique(array_filter($conversation_ids)));
}

/**
 * Recomputes session latest-gap-item pointers for affected conversations.
 *
 * @param \Drupal\Core\Database\Connection $database
 *   The database connection.
 * @param string[] $conversation_ids
 *   Conversation IDs to refresh.
 */
function ilas_site_assistant_governance_refresh_conversation_sessions(Connection $database, array $conversation_ids): void {
  $conversation_ids = array_values(array_unique(array_filter($conversation_ids)));
  if ($conversation_ids === []) {
    return;
  }

  $schema = $database->schema();
  if (
    !$schema->tableExists('ilas_site_assistant_conversation_turn')
    || !$schema->tableExists('ilas_site_assistant_conversation_session')
  ) {
    return;
  }

  foreach ($conversation_ids as $conversation_id) {
    $has_no_answer = (bool) $database->select('ilas_site_assistant_conversation_turn', 't')
      ->condition('conversation_id', $conversation_id)
      ->condition('is_no_answer', 1)
      ->countQuery()
      ->execute()
      ->fetchField();

    $latest_gap_item_id = $database->select('ilas_site_assistant_conversation_turn', 't')
      ->fields('t', ['gap_item_id'])
      ->condition('conversation_id', $conversation_id)
      ->condition('is_no_answer', 1)
      ->isNotNull('gap_item_id')
      ->orderBy('created', 'DESC')
      ->orderBy('id', 'DESC')
      ->range(0, 1)
      ->execute()
      ->fetchField();

    $database->update('ilas_site_assistant_conversation_session')
      ->fields([
        'has_no_answer' => $has_no_answer ? 1 : 0,
        'latest_gap_item_id' => $latest_gap_item_id !== FALSE ? (int) $latest_gap_item_id : NULL,
      ])
      ->condition('conversation_id', $conversation_id)
      ->execute();
  }

  GovernanceConversationLogger::refreshUnresolvedGapFlags($database, $conversation_ids);
}

/**
 * Truncates a value and returns NULL when it is empty.
 */
function ilas_site_assistant_governance_truncate_nullable_string(mixed $value, int $length): ?string {
  $normalized = mb_substr(trim((string) $value), 0, $length);
  return $normalized !== '' ? $normalized : NULL;
}
