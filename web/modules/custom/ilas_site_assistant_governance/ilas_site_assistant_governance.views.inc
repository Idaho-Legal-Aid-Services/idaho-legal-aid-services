<?php

/**
 * @file
 * Views data for assistant governance tables.
 */

/**
 * Implements hook_views_data().
 */
function ilas_site_assistant_governance_views_data(): array {
  $data = [];

  $data['ilas_site_assistant_conversation_session']['table']['group'] = t('Assistant governance conversations');
  $data['ilas_site_assistant_conversation_session']['table']['base'] = [
    'field' => 'conversation_id',
    'title' => t('Assistant governance conversations'),
    'help' => t('Canonical PII-redacted assistant conversation sessions.'),
  ];

  $data['ilas_site_assistant_conversation_session']['conversation_id'] = [
    'title' => t('Conversation ID'),
    'help' => t('Canonical conversation identifier.'),
    'field' => ['id' => 'standard'],
    'filter' => ['id' => 'string'],
    'sort' => ['id' => 'standard'],
  ];

  foreach ([
    'language_hint' => t('Language hint'),
    'last_intent' => t('Last intent'),
    'last_response_type' => t('Last response type'),
    'first_request_id' => t('First request ID'),
    'last_request_id' => t('Last request ID'),
    'hold_reason_summary' => t('Hold reason summary'),
  ] as $field_name => $title) {
    $data['ilas_site_assistant_conversation_session'][$field_name] = [
      'title' => $title,
      'field' => ['id' => 'standard'],
      'filter' => ['id' => 'string'],
      'sort' => ['id' => 'standard'],
    ];
  }

  foreach ([
    'turn_count' => t('Turn count'),
    'exchange_count' => t('Exchange count'),
    'latest_gap_item_id' => t('Latest gap item ID'),
  ] as $field_name => $title) {
    $data['ilas_site_assistant_conversation_session'][$field_name] = [
      'title' => $title,
      'field' => ['id' => 'numeric'],
      'filter' => ['id' => 'numeric'],
      'sort' => ['id' => 'standard'],
    ];
  }

  foreach ([
    'first_message_at' => t('First message at'),
    'last_message_at' => t('Last message at'),
    'held_at' => t('Held at'),
    'purge_after' => t('Purge after'),
  ] as $field_name => $title) {
    $data['ilas_site_assistant_conversation_session'][$field_name] = [
      'title' => $title,
      'field' => ['id' => 'date'],
      'filter' => ['id' => 'date'],
      'sort' => ['id' => 'standard'],
    ];
  }

  foreach ([
    'has_no_answer' => t('Has no answer'),
    'is_held' => t('Legal hold'),
  ] as $field_name => $title) {
    $data['ilas_site_assistant_conversation_session'][$field_name] = [
      'title' => $title,
      'field' => ['id' => 'boolean'],
      'filter' => ['id' => 'boolean'],
      'sort' => ['id' => 'standard'],
    ];
  }

  $data['ilas_site_assistant_conversation_session']['held_by_uid'] = [
    'title' => t('Held by user ID'),
    'field' => ['id' => 'numeric'],
    'filter' => ['id' => 'numeric'],
    'sort' => ['id' => 'standard'],
    'relationship' => [
      'title' => t('Held by user'),
      'help' => t('Relates a held conversation to the user who placed the hold.'),
      'id' => 'standard',
      'base' => 'users_field_data',
      'base field' => 'uid',
      'field' => 'held_by_uid',
    ],
  ];

  $data['ilas_site_assistant_conversation_session']['latest_gap_item_id']['relationship'] = [
    'title' => t('Latest gap item'),
    'help' => t('Relates a conversation to its latest assistant gap item.'),
    'id' => 'standard',
    'base' => 'assistant_gap_item',
    'base field' => 'id',
    'field' => 'latest_gap_item_id',
  ];

  return $data;
}
