<?php

declare(strict_types=1);

use Drupal\ilas_site_assistant_governance\Entity\AssistantGapItem;

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

  foreach ($rows as $id => $row) {
    $cluster_hash = hash('sha256', $row->query_hash . '|' . $row->language_hint);
    $existing = $storage->loadByProperties(['cluster_hash' => $cluster_hash]);
    if (!$existing) {
      $entity = $storage->create([
        'cluster_hash' => $cluster_hash,
        'query_hash' => $row->query_hash,
        'language_hint' => $row->language_hint,
        'query_length_bucket' => $row->length_bucket,
        'redaction_profile' => $row->redaction_profile,
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
