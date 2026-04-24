<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant_governance\Kernel;

use Drupal\Core\Database\Statement\FetchAs;
use Drupal\KernelTests\KernelTestBase;
use Drupal\ilas_site_assistant_governance\Entity\AssistantGapItem;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Kernel coverage for repairing contaminated gap-item identity boundaries.
 */
#[Group('ilas_site_assistant_governance')]
#[RunTestsInSeparateProcesses]
final class AssistantGapItemIdentityMigrationKernelTest extends KernelTestBase {

  /**
   * Runtime workflow coverage should not be blocked by unrelated config drift.
   *
   * @var bool
   */
  protected $strictConfigSchema = FALSE;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'filter',
    'text',
    'node',
    'taxonomy',
    'views',
    'options',
    'search_api',
    'search_api_db',
    'entity_reference_revisions',
    'paragraphs',
    'ilas_site_assistant',
    'ilas_site_assistant_governance',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('taxonomy_term');
    $this->installEntitySchema('paragraph');
    $this->installEntitySchema('search_api_task');
    $this->installEntitySchema('assistant_gap_item');
    $this->installConfig(['search_api', 'search_api_db', 'ilas_site_assistant', 'ilas_site_assistant_governance']);
    $this->installSchema('ilas_site_assistant_governance', [
      'ilas_site_assistant_conversation_session',
      'ilas_site_assistant_conversation_turn',
      'ilas_site_assistant_gap_hit',
      'ilas_site_assistant_legal_hold',
    ]);
  }

  /**
   * Mixed hit context splits one contaminated item into new immutable records.
   */
  public function testPostUpdateRepairsContaminatedIdentityAndRequeuesSplits(): void {
    $reviewer = User::create([
      'name' => 'reviewer',
    ]);
    $reviewer->save();

    $storage = $this->container->get('entity_type.manager')->getStorage('assistant_gap_item');
    /** @var \Drupal\ilas_site_assistant_governance\Entity\AssistantGapItem $entity */
    $entity = $storage->create([
      'uid' => (int) $reviewer->id(),
      'cluster_hash' => hash('sha256', 'query-hash|en'),
      'query_hash' => 'query-hash',
      'exemplar_redacted_query' => 'help me find the right paperwork',
      'language_hint' => 'en',
      'query_length_bucket' => '1-24',
      'redaction_profile' => 'none',
      'identity_context_key' => 'legacy:unknown',
      'identity_source' => 'legacy',
      'identity_selection_key' => NULL,
      'identity_intent' => 'unknown',
      'identity_topic_tid' => NULL,
      'identity_service_area_tid' => NULL,
      'review_state' => AssistantGapItem::STATE_REVIEWED,
      'assigned_uid' => (int) $reviewer->id(),
      'reviewed_at' => 1710000100,
      'reviewed_uid' => (int) $reviewer->id(),
      'topic_assignment_source' => 'reviewer',
      'first_seen' => 1710000001,
      'last_seen' => 1710000003,
      'occurrence_count_total' => 3,
      'occurrence_count_unresolved' => 3,
      'first_conversation_id' => 'conversation-1',
      'latest_conversation_id' => 'conversation-3',
      'latest_request_id' => 'request-3',
      'is_held' => 1,
      'held_at' => 1710000200,
      'held_by_uid' => (int) $reviewer->id(),
      'hold_reason_summary' => 'Keep evidence for audit.',
    ]);
    $entity->save();

    $database = $this->container->get('database');
    $this->seedHitAndConversation($database, (int) $entity->id(), 'conversation-1', 'request-1', 1710000001, 'forms', 'forms_family');
    $this->seedHitAndConversation($database, (int) $entity->id(), 'conversation-2', 'request-2', 1710000002, 'forms', 'forms_family');
    $this->seedHitAndConversation($database, (int) $entity->id(), 'conversation-3', 'request-3', 1710000003, 'guides', 'guides_family');

    require_once DRUPAL_ROOT . '/modules/custom/ilas_site_assistant_governance/ilas_site_assistant_governance.post_update.php';
    $sandbox = [];
    do {
      \ilas_site_assistant_governance_post_update_repair_gap_item_identity($sandbox);
    } while (($sandbox['#finished'] ?? 0) < 1);

    /** @var \Drupal\ilas_site_assistant_governance\Entity\AssistantGapItem[] $items */
    $items = $storage->loadMultiple();
    $this->assertCount(2, $items);

    $items_by_context = [];
    foreach ($items as $item) {
      $items_by_context[(string) $item->get('identity_context_key')->value] = $item;
    }

    $this->assertArrayHasKey('selection:forms_family', $items_by_context);
    $this->assertArrayHasKey('selection:guides_family', $items_by_context);

    $forms_item = $items_by_context['selection:forms_family'];
    $guides_item = $items_by_context['selection:guides_family'];

    foreach ([$forms_item, $guides_item] as $item) {
      $this->assertSame(AssistantGapItem::STATE_NEW, $item->getReviewState());
      $this->assertTrue($item->get('assigned_uid')->isEmpty());
      $this->assertTrue($item->get('reviewed_at')->isEmpty());
      $this->assertSame('selection', (string) $item->get('topic_assignment_source')->value);
      $this->assertSame('1', (string) $item->get('is_held')->value);
      $this->assertSame('Keep evidence for audit.', (string) $item->get('hold_reason_summary')->value);
    }

    $this->assertSame('2', (string) $forms_item->get('occurrence_count_total')->value);
    $this->assertSame('2', (string) $forms_item->get('occurrence_count_unresolved')->value);
    $this->assertSame('1', (string) $guides_item->get('occurrence_count_total')->value);
    $this->assertSame('1', (string) $guides_item->get('occurrence_count_unresolved')->value);

    $hits = $database->select('ilas_site_assistant_gap_hit', 'h')
      ->fields('h', ['request_id', 'active_selection_key', 'gap_item_id', 'is_unresolved'])
      ->orderBy('request_id', 'ASC')
      ->execute()
      ->fetchAllAssoc('request_id', FetchAs::Associative);

    $this->assertSame((int) $forms_item->id(), (int) $hits['request-1']['gap_item_id']);
    $this->assertSame((int) $forms_item->id(), (int) $hits['request-2']['gap_item_id']);
    $this->assertSame((int) $guides_item->id(), (int) $hits['request-3']['gap_item_id']);
    $this->assertSame('1', (string) $hits['request-1']['is_unresolved']);
    $this->assertSame('1', (string) $hits['request-3']['is_unresolved']);

    $turns = $database->select('ilas_site_assistant_conversation_turn', 't')
      ->fields('t', ['conversation_id', 'request_id', 'direction', 'gap_item_id', 'is_no_answer'])
      ->orderBy('conversation_id', 'ASC')
      ->orderBy('direction', 'ASC')
      ->execute()
      ->fetchAll(FetchAs::Associative);

    foreach ($turns as $turn) {
      $expected_gap_item_id = in_array($turn['request_id'], ['request-1', 'request-2'], TRUE) ? (int) $forms_item->id() : (int) $guides_item->id();
      $this->assertSame($expected_gap_item_id, (int) $turn['gap_item_id']);
      $this->assertSame('1', (string) $turn['is_no_answer']);
    }

    $sessions = $database->select('ilas_site_assistant_conversation_session', 's')
      ->fields('s', ['conversation_id', 'latest_gap_item_id', 'has_no_answer', 'has_unresolved_gap'])
      ->orderBy('conversation_id', 'ASC')
      ->execute()
      ->fetchAllAssoc('conversation_id', FetchAs::Associative);

    $this->assertSame((int) $forms_item->id(), (int) $sessions['conversation-1']['latest_gap_item_id']);
    $this->assertSame((int) $forms_item->id(), (int) $sessions['conversation-2']['latest_gap_item_id']);
    $this->assertSame((int) $guides_item->id(), (int) $sessions['conversation-3']['latest_gap_item_id']);
    $this->assertSame('1', (string) $sessions['conversation-1']['has_no_answer']);
    $this->assertSame('1', (string) $sessions['conversation-1']['has_unresolved_gap']);
    $this->assertSame('1', (string) $sessions['conversation-2']['has_unresolved_gap']);
    $this->assertSame('1', (string) $sessions['conversation-3']['has_no_answer']);
    $this->assertSame('1', (string) $sessions['conversation-3']['has_unresolved_gap']);
  }

  /**
   * Seeds one hit row and its linked conversation/session records.
   */
  private function seedHitAndConversation(
    $database,
    int $gap_item_id,
    string $conversation_id,
    string $request_id,
    int $occurred_at,
    string $intent,
    string $selection_key,
  ): void {
    $database->insert('ilas_site_assistant_gap_hit')
      ->fields([
        'gap_item_id' => $gap_item_id,
        'conversation_id' => $conversation_id,
        'request_id' => $request_id,
        'occurred_at' => $occurred_at,
        'is_unresolved' => 1,
        'query_hash' => 'query-hash',
        'language_hint' => 'en',
        'assignment_source' => 'selection',
        'intent' => $intent,
        'active_selection_key' => $selection_key,
      ])
      ->execute();

    foreach (['assistant', 'user'] as $offset => $direction) {
      $database->insert('ilas_site_assistant_conversation_turn')
        ->fields([
          'conversation_id' => $conversation_id,
          'turn_sequence' => $offset + 1,
          'request_id' => $request_id,
          'direction' => $direction,
          'message_redacted' => $direction . ' message',
          'message_hash' => hash('sha256', $direction . ':' . $request_id),
          'message_length_bucket' => '1-24',
          'redaction_profile' => 'none',
          'redaction_version' => 'v1',
          'language_hint' => 'en',
          'intent' => $intent,
          'response_type' => $direction === 'assistant' ? 'fallback' : NULL,
          'is_no_answer' => 1,
          'gap_item_id' => $gap_item_id,
          'created' => $occurred_at,
        ])
        ->execute();
    }

    $database->insert('ilas_site_assistant_conversation_session')
      ->fields([
        'conversation_id' => $conversation_id,
        'first_message_at' => $occurred_at,
        'last_message_at' => $occurred_at,
        'turn_count' => 2,
        'exchange_count' => 1,
        'language_hint' => 'en',
        'last_intent' => $intent,
        'last_response_type' => 'fallback',
        'first_request_id' => $request_id,
        'last_request_id' => $request_id,
        'has_no_answer' => 1,
        'has_unresolved_gap' => 1,
        'latest_gap_item_id' => $gap_item_id,
        'is_held' => 0,
        'purge_after' => $occurred_at + 86400,
      ])
      ->execute();
  }

}
