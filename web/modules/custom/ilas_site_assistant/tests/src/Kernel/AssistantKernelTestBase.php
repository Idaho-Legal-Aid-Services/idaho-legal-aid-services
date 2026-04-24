<?php

namespace Drupal\Tests\ilas_site_assistant\Kernel;

use Drupal\KernelTests\KernelTestBase;

/**
 * Base class for ILAS Site Assistant Kernel tests.
 *
 * Provides the module's database schema without installing the full module
 * (which has heavy dependencies: node, taxonomy, search_api, paragraphs).
 * Services are instantiated directly with real database + mocked dependencies.
 */
abstract class AssistantKernelTestBase extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system'];

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->database = $this->container->get('database');
    $this->createModuleTables();
  }

  /**
   * Creates the ilas_site_assistant module tables.
   *
   * Mirrors hook_schema() + relevant update hooks so tests
   * exercise the exact same schema as production.
   */
  protected function createModuleTables(): void {
    $schema = $this->database->schema();

    // Stats table (aggregated, no PII).
    $schema->createTable('ilas_site_assistant_stats', [
      'fields' => [
        'id' => [
          'type' => 'serial',
          'unsigned' => TRUE,
          'not null' => TRUE,
        ],
        'event_type' => [
          'type' => 'varchar',
          'length' => 64,
          'not null' => TRUE,
        ],
        'event_value' => [
          'type' => 'varchar',
          'length' => 255,
          'not null' => FALSE,
        ],
        'count' => [
          'type' => 'int',
          'unsigned' => TRUE,
          'not null' => TRUE,
          'default' => 1,
        ],
        'date' => [
          'type' => 'varchar',
          'length' => 10,
          'not null' => TRUE,
        ],
      ],
      'primary key' => ['id'],
      'indexes' => [
        'date' => ['date'],
        'event_date' => ['event_type', 'date'],
        'event_value' => ['event_type', 'event_value'],
      ],
      'unique keys' => [
        'event_date_value' => ['event_type', 'event_value', 'date'],
      ],
    ]);

    // No-answer table.
    $schema->createTable('ilas_site_assistant_no_answer', [
      'fields' => [
        'id' => [
          'type' => 'serial',
          'unsigned' => TRUE,
          'not null' => TRUE,
        ],
        'query_hash' => [
          'type' => 'varchar',
          'length' => 64,
          'not null' => TRUE,
        ],
        'language_hint' => [
          'type' => 'varchar',
          'length' => 16,
          'not null' => TRUE,
          'default' => 'unknown',
        ],
        'length_bucket' => [
          'type' => 'varchar',
          'length' => 16,
          'not null' => TRUE,
          'default' => 'empty',
        ],
        'redaction_profile' => [
          'type' => 'varchar',
          'length' => 255,
          'not null' => TRUE,
          'default' => 'none',
        ],
        'count' => [
          'type' => 'int',
          'unsigned' => TRUE,
          'not null' => TRUE,
          'default' => 1,
        ],
        'first_seen' => [
          'type' => 'int',
          'unsigned' => TRUE,
          'not null' => TRUE,
        ],
        'last_seen' => [
          'type' => 'int',
          'unsigned' => TRUE,
          'not null' => TRUE,
        ],
      ],
      'primary key' => ['id'],
      'unique keys' => [
        'query_hash' => ['query_hash'],
      ],
      'indexes' => [
        'count' => ['count'],
        'last_seen' => ['last_seen'],
      ],
    ]);

    // Conversations table (with request_id from update_10002).
    $schema->createTable('ilas_site_assistant_conversations', [
      'fields' => [
        'id' => [
          'type' => 'serial',
          'unsigned' => TRUE,
          'not null' => TRUE,
        ],
        'conversation_id' => [
          'type' => 'varchar',
          'length' => 36,
          'not null' => TRUE,
        ],
        'direction' => [
          'type' => 'varchar',
          'length' => 10,
          'not null' => TRUE,
        ],
        'message_hash' => [
          'type' => 'varchar',
          'length' => 64,
          'not null' => TRUE,
          'default' => '',
        ],
        'message_length_bucket' => [
          'type' => 'varchar',
          'length' => 16,
          'not null' => TRUE,
          'default' => 'empty',
        ],
        'redaction_profile' => [
          'type' => 'varchar',
          'length' => 255,
          'not null' => TRUE,
          'default' => 'none',
        ],
        'intent' => [
          'type' => 'varchar',
          'length' => 64,
          'not null' => FALSE,
        ],
        'response_type' => [
          'type' => 'varchar',
          'length' => 32,
          'not null' => FALSE,
        ],
        'created' => [
          'type' => 'int',
          'unsigned' => TRUE,
          'not null' => TRUE,
        ],
        'request_id' => [
          'type' => 'varchar',
          'length' => 36,
          'not null' => FALSE,
          'default' => NULL,
        ],
      ],
      'primary key' => ['id'],
      'indexes' => [
        'conversation' => ['conversation_id'],
        'created' => ['created'],
        'intent_created' => ['intent', 'created'],
        'request_id' => ['request_id'],
      ],
    ]);

    // Canonical governance conversation session table.
    $schema->createTable('ilas_site_assistant_conversation_session', [
      'fields' => [
        'conversation_id' => [
          'type' => 'varchar',
          'length' => 36,
          'not null' => TRUE,
        ],
        'first_message_at' => [
          'type' => 'int',
          'unsigned' => TRUE,
          'not null' => TRUE,
        ],
        'last_message_at' => [
          'type' => 'int',
          'unsigned' => TRUE,
          'not null' => TRUE,
        ],
        'turn_count' => [
          'type' => 'int',
          'unsigned' => TRUE,
          'not null' => TRUE,
          'default' => 0,
        ],
        'exchange_count' => [
          'type' => 'int',
          'unsigned' => TRUE,
          'not null' => TRUE,
          'default' => 0,
        ],
        'language_hint' => [
          'type' => 'varchar',
          'length' => 16,
          'not null' => TRUE,
          'default' => 'unknown',
        ],
        'last_intent' => [
          'type' => 'varchar',
          'length' => 64,
          'not null' => FALSE,
        ],
        'last_response_type' => [
          'type' => 'varchar',
          'length' => 32,
          'not null' => FALSE,
        ],
        'first_request_id' => [
          'type' => 'varchar',
          'length' => 36,
          'not null' => FALSE,
        ],
        'last_request_id' => [
          'type' => 'varchar',
          'length' => 36,
          'not null' => FALSE,
        ],
        'has_no_answer' => [
          'type' => 'int',
          'size' => 'tiny',
          'not null' => TRUE,
          'default' => 0,
        ],
        'has_unresolved_gap' => [
          'type' => 'int',
          'size' => 'tiny',
          'not null' => TRUE,
          'default' => 0,
        ],
        'latest_gap_item_id' => [
          'type' => 'int',
          'unsigned' => TRUE,
          'not null' => FALSE,
        ],
        'is_held' => [
          'type' => 'int',
          'size' => 'tiny',
          'not null' => TRUE,
          'default' => 0,
        ],
        'held_at' => [
          'type' => 'int',
          'unsigned' => TRUE,
          'not null' => FALSE,
        ],
        'held_by_uid' => [
          'type' => 'int',
          'unsigned' => TRUE,
          'not null' => FALSE,
        ],
        'hold_reason_summary' => [
          'type' => 'varchar',
          'length' => 255,
          'not null' => FALSE,
        ],
        'purge_after' => [
          'type' => 'int',
          'unsigned' => TRUE,
          'not null' => TRUE,
        ],
      ],
      'primary key' => ['conversation_id'],
      'indexes' => [
        'purge_hold' => ['purge_after', 'is_held'],
        'last_message' => ['last_message_at'],
      ],
    ]);

    // Canonical governance conversation turn table.
    $schema->createTable('ilas_site_assistant_conversation_turn', [
      'fields' => [
        'id' => [
          'type' => 'serial',
          'unsigned' => TRUE,
          'not null' => TRUE,
        ],
        'conversation_id' => [
          'type' => 'varchar',
          'length' => 36,
          'not null' => TRUE,
        ],
        'turn_sequence' => [
          'type' => 'int',
          'unsigned' => TRUE,
          'not null' => TRUE,
        ],
        'request_id' => [
          'type' => 'varchar',
          'length' => 36,
          'not null' => FALSE,
        ],
        'direction' => [
          'type' => 'varchar',
          'length' => 10,
          'not null' => TRUE,
        ],
        'message_redacted' => [
          'type' => 'text',
          'size' => 'big',
          'not null' => TRUE,
        ],
        'message_hash' => [
          'type' => 'varchar',
          'length' => 64,
          'not null' => TRUE,
        ],
        'message_length_bucket' => [
          'type' => 'varchar',
          'length' => 16,
          'not null' => TRUE,
        ],
        'redaction_profile' => [
          'type' => 'varchar',
          'length' => 255,
          'not null' => TRUE,
        ],
        'redaction_version' => [
          'type' => 'varchar',
          'length' => 32,
          'not null' => TRUE,
          'default' => 'v1',
        ],
        'language_hint' => [
          'type' => 'varchar',
          'length' => 16,
          'not null' => TRUE,
          'default' => 'unknown',
        ],
        'intent' => [
          'type' => 'varchar',
          'length' => 64,
          'not null' => FALSE,
        ],
        'response_type' => [
          'type' => 'varchar',
          'length' => 32,
          'not null' => FALSE,
        ],
        'is_no_answer' => [
          'type' => 'int',
          'size' => 'tiny',
          'not null' => TRUE,
          'default' => 0,
        ],
        'gap_item_id' => [
          'type' => 'int',
          'unsigned' => TRUE,
          'not null' => FALSE,
        ],
        'created' => [
          'type' => 'int',
          'unsigned' => TRUE,
          'not null' => TRUE,
        ],
      ],
      'primary key' => ['id'],
      'unique keys' => [
        'conversation_sequence' => ['conversation_id', 'turn_sequence'],
      ],
      'indexes' => [
        'conversation_created' => ['conversation_id', 'created'],
        'request_id' => ['request_id'],
      ],
    ]);

    // Durable assistant conversation state table.
    $schema->createTable('ilas_site_assistant_conversation_state', [
      'fields' => [
        'conversation_id' => [
          'type' => 'varchar',
          'length' => 36,
          'not null' => TRUE,
        ],
        'session_fingerprint' => [
          'type' => 'varchar',
          'length' => 64,
          'not null' => TRUE,
          'default' => '',
        ],
        'pending_flow_type' => [
          'type' => 'varchar',
          'length' => 32,
          'not null' => TRUE,
          'default' => '',
        ],
        'pending_flow_origin_intent' => [
          'type' => 'varchar',
          'length' => 64,
          'not null' => TRUE,
          'default' => '',
        ],
        'pending_flow_remaining_turns' => [
          'type' => 'int',
          'unsigned' => TRUE,
          'not null' => TRUE,
          'default' => 0,
        ],
        'pending_flow_created' => [
          'type' => 'int',
          'unsigned' => TRUE,
          'not null' => TRUE,
          'default' => 0,
        ],
        'updated' => [
          'type' => 'int',
          'unsigned' => TRUE,
          'not null' => TRUE,
          'default' => 0,
        ],
        'expires' => [
          'type' => 'int',
          'unsigned' => TRUE,
          'not null' => TRUE,
          'default' => 0,
        ],
      ],
      'primary key' => ['conversation_id'],
      'indexes' => [
        'expires' => ['expires'],
      ],
    ]);
  }

  /**
   * Creates a mock config factory with the given settings.
   *
   * @param array $overrides
   *   Config values to override. Nested keys use dot notation.
   *
   * @return \Drupal\Core\Config\ConfigFactoryInterface
   *   A mock config factory.
   */
  protected function createMockConfigFactory(array $overrides = []) {
    $defaults = [
      'enable_logging' => TRUE,
      'log_retention_days' => 90,
      'conversation_logging.enabled' => TRUE,
      'conversation_logging.retention_hours' => 72,
      'conversation_logging.redact_pii' => TRUE,
      'conversation_logging.show_user_notice' => TRUE,
      'safety_alerting.enabled' => FALSE,
      'safety_alerting.threshold' => 20,
      'safety_alerting.window_hours' => 1,
      'safety_alerting.cooldown_minutes' => 60,
      'safety_alerting.recipients' => '',
      'ab_testing.enabled' => FALSE,
      'ab_testing.experiments' => [],
    ];

    $values = array_merge($defaults, $overrides);

    $config = $this->createMock('Drupal\Core\Config\ImmutableConfig');
    $config->method('get')
      ->willReturnCallback(function ($key) use ($values) {
        return $values[$key] ?? NULL;
      });

    $configFactory = $this->createMock('Drupal\Core\Config\ConfigFactoryInterface');
    $configFactory->method('get')
      ->with('ilas_site_assistant.settings')
      ->willReturn($config);

    return $configFactory;
  }

  /**
   * Creates a mock time service returning a fixed timestamp.
   *
   * @param int $timestamp
   *   The timestamp to return.
   *
   * @return \Drupal\Component\Datetime\TimeInterface
   *   A mock time service.
   */
  protected function createMockTime(int $timestamp) {
    $time = $this->createMock('Drupal\Component\Datetime\TimeInterface');
    $time->method('getRequestTime')->willReturn($timestamp);
    $time->method('getCurrentTime')->willReturn($timestamp);
    return $time;
  }

  /**
   * Creates a mock PolicyFilter that sanitizes predictably.
   *
   * @return \Drupal\ilas_site_assistant\Service\PolicyFilter
   *   A mock PolicyFilter.
   */
  protected function createMockPolicyFilter() {
    $configFactory = $this->createMockConfigFactory();
    return new \Drupal\ilas_site_assistant\Service\PolicyFilter($configFactory);
  }

  /**
   * Inserts a stats row directly for test setup.
   *
   * @param string $event_type
   *   The event type.
   * @param string $event_value
   *   The event value.
   * @param int $count
   *   The count.
   * @param string $date
   *   The date in Y-m-d format.
   */
  protected function insertStatsRow(string $event_type, string $event_value, int $count, string $date): void {
    $this->database->insert('ilas_site_assistant_stats')
      ->fields([
        'event_type' => $event_type,
        'event_value' => $event_value,
        'count' => $count,
        'date' => $date,
      ])
      ->execute();
  }

  /**
   * Counts rows in a table.
   *
   * @param string $table
   *   The table name.
   *
   * @return int
   *   The row count.
   */
  protected function countTableRows(string $table): int {
    return (int) $this->database->select($table)
      ->countQuery()
      ->execute()
      ->fetchField();
  }

}
