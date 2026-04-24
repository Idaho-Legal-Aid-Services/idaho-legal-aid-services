<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant_governance\Kernel;

use Drupal\Core\Session\AccountInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\ilas_site_assistant\Service\ObservabilityPayloadMinimizer;
use Drupal\ilas_site_assistant_governance\Controller\GapReviewWorkflowController;
use Drupal\ilas_site_assistant_governance\Entity\AssistantGapItem;
use Drupal\taxonomy\Entity\Term;
use Drupal\views\Views;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Kernel coverage for the operational gap-review workflow.
 */
#[Group('ilas_site_assistant_governance')]
#[RunTestsInSeparateProcesses]
final class AssistantGapItemWorkflowKernelTest extends KernelTestBase {

  /**
   * Runtime workflow coverage should not be blocked by legacy config-schema drift.
   *
   * The governance module still carries unrelated exported Views schema gaps
   * outside the queue workflow exercised here.
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
   * The actionable tabs render open counts while All keeps lifetime counts.
   */
  public function testQueueTabsUseOpenAndLifetimeCountSemantics(): void {
    $this->setCurrentAccount([
      'view assistant gap items',
    ]);

    $new_item = $this->createGapItem('queue-new', AssistantGapItem::STATE_NEW, 2, [
      'occurrence_count_total' => 5,
    ]);
    $needs_review_item = $this->createGapItem('queue-needs-review', AssistantGapItem::STATE_NEEDS_REVIEW, 1, [
      'occurrence_count_total' => 4,
    ]);
    $reviewed_item = $this->createGapItem('queue-reviewed', AssistantGapItem::STATE_REVIEWED, 1, [
      'occurrence_count_total' => 6,
    ]);
    $resolved_item = $this->createGapItem('queue-resolved', AssistantGapItem::STATE_RESOLVED, 0, [
      'occurrence_count_total' => 7,
    ]);

    $view = Views::getView('assistant_gap_items');
    self::assertNotNull($view);

    $view->setDisplay('page_queue');
    $view->execute();
    $page_queue_ids = array_map(static fn($row): int => (int) $row->_entity->id(), $view->result);
    $this->assertSame('Open occurrences', $view->field['occurrence_count_unresolved']->options['label']);
    $this->assertArrayNotHasKey('occurrence_count_total', $view->field);
    $new_queue_row = $this->findViewResultRow($view->result, (int) $new_item->id());
    $this->assertSame('2', trim((string) $view->field['occurrence_count_unresolved']->render($new_queue_row)));

    $this->assertContains((int) $new_item->id(), $page_queue_ids);
    $this->assertContains((int) $needs_review_item->id(), $page_queue_ids);
    $this->assertContains((int) $reviewed_item->id(), $page_queue_ids);
    $this->assertNotContains((int) $resolved_item->id(), $page_queue_ids);

    $new_view = Views::getView('assistant_gap_items');
    self::assertNotNull($new_view);
    $new_view->setDisplay('page_new');
    $new_view->execute();
    $page_new_ids = array_map(static fn($row): int => (int) $row->_entity->id(), $new_view->result);
    $this->assertSame('Open occurrences', $new_view->field['occurrence_count_unresolved']->options['label']);
    $this->assertArrayNotHasKey('occurrence_count_total', $new_view->field);
    $this->assertContains((int) $new_item->id(), $page_new_ids);
    $new_tab_row = $this->findViewResultRow($new_view->result, (int) $new_item->id());
    $this->assertSame('2', trim((string) $new_view->field['occurrence_count_unresolved']->render($new_tab_row)));

    $needs_review_view = Views::getView('assistant_gap_items');
    self::assertNotNull($needs_review_view);
    $needs_review_view->setDisplay('page_needs_review');
    $needs_review_view->execute();
    $page_needs_review_ids = array_map(static fn($row): int => (int) $row->_entity->id(), $needs_review_view->result);
    $this->assertSame('Open occurrences', $needs_review_view->field['occurrence_count_unresolved']->options['label']);
    $this->assertArrayNotHasKey('occurrence_count_total', $needs_review_view->field);
    $this->assertContains((int) $needs_review_item->id(), $page_needs_review_ids);
    $needs_review_tab_row = $this->findViewResultRow($needs_review_view->result, (int) $needs_review_item->id());
    $this->assertSame('1', trim((string) $needs_review_view->field['occurrence_count_unresolved']->render($needs_review_tab_row)));

    $all_view = Views::getView('assistant_gap_items');
    self::assertNotNull($all_view);
    $all_view->setDisplay('page_all');
    $all_view->execute();
    $page_all_ids = array_map(static fn($row): int => (int) $row->_entity->id(), $all_view->result);
    $this->assertSame('Lifetime occurrences', $all_view->field['occurrence_count_total']->options['label']);
    $this->assertArrayNotHasKey('occurrence_count_unresolved', $all_view->field);
    $new_all_row = $this->findViewResultRow($all_view->result, (int) $new_item->id());
    $this->assertSame('5', trim((string) $all_view->field['occurrence_count_total']->render($new_all_row)));
    $this->assertContains((int) $new_item->id(), $page_all_ids);
    $this->assertContains((int) $resolved_item->id(), $page_all_ids);
  }

  /**
   * Starting review assigns the reviewer and moves the item into review.
   */
  public function testStartReviewOperationAssignsAndTransitions(): void {
    $this->setCurrentAccount([
      'edit assistant gap items',
      'transition assistant gap items to needs_review',
    ]);

    $entity = $this->createGapItem('start-review', AssistantGapItem::STATE_NEW, 1);
    $controller = GapReviewWorkflowController::create($this->container);
    $response = $controller->startReview($entity);

    /** @var \Drupal\ilas_site_assistant_governance\Entity\AssistantGapItem $reloaded */
    $reloaded = $this->container->get('entity_type.manager')->getStorage('assistant_gap_item')->load($entity->id());
    self::assertNotNull($reloaded);

    $this->assertSame(302, $response->getStatusCode());
    $this->assertStringContainsString('/admin/reports/ilas-assistant/gaps/' . $entity->id(), $response->getTargetUrl());
    $this->assertSame(AssistantGapItem::STATE_NEEDS_REVIEW, $reloaded->getReviewState());
    $this->assertSame('2', (string) ($reloaded->get('assigned_uid')->target_id ?? ''));

    $operations = $this->container->get('entity_type.manager')->getListBuilder('assistant_gap_item')->getOperations($reloaded);
    $this->assertArrayNotHasKey('start_review', $operations);
  }

  /**
   * A new occurrence automatically reopens resolved items.
   */
  public function testRecordNoAnswerReopensResolvedItem(): void {
    $query = 'eviction help after lockout';
    $metadata = ObservabilityPayloadMinimizer::buildTextMetadataWithLanguage($query);
    $query_hash = $metadata['text_hash'];
    $cluster_hash = $this->identityForContext($query_hash, $metadata['language_hint'], [])['cluster_hash'];

    $resolved = $this->createGapItem('reopen-me', AssistantGapItem::STATE_RESOLVED, 0, [
      'cluster_hash' => $cluster_hash,
      'query_hash' => $query_hash,
      'language_hint' => $metadata['language_hint'],
      'occurrence_count_total' => 1,
      'occurrence_count_unresolved' => 0,
    ]);

    $this->container->get('ilas_site_assistant_governance.gap_item_manager')->recordNoAnswer($query, []);

    /** @var \Drupal\ilas_site_assistant_governance\Entity\AssistantGapItem $reloaded */
    $reloaded = $this->container->get('entity_type.manager')->getStorage('assistant_gap_item')->load($resolved->id());
    self::assertNotNull($reloaded);

    $this->assertSame(AssistantGapItem::STATE_NEEDS_REVIEW, $reloaded->getReviewState());
    $this->assertSame('2', (string) $reloaded->get('occurrence_count_total')->value);
    $this->assertSame('1', (string) $reloaded->get('occurrence_count_unresolved')->value);
    $this->assertSame(
      '1',
      (string) $this->container->get('database')->select('ilas_site_assistant_gap_hit', 'h')
        ->fields('h', ['is_unresolved'])
        ->condition('gap_item_id', (int) $resolved->id())
        ->orderBy('id', 'DESC')
        ->range(0, 1)
        ->execute()
        ->fetchField()
    );
  }

  /**
   * Recording a new no-answer creates one canonical gap item and open hit row.
   */
  public function testRecordNoAnswerCreatesCanonicalArtifacts(): void {
    $gap_item_id = $this->container->get('ilas_site_assistant_governance.gap_item_manager')->recordNoAnswer(
      'need help with an unresolved generic topic',
      [
        'conversation_id' => '12345678-1234-4123-8123-123456789abc',
        'request_id' => '12345678-1234-4123-8123-123456789abd',
      ],
    );

    $this->assertIsInt($gap_item_id);
    $this->assertGreaterThan(0, $gap_item_id);

    /** @var \Drupal\ilas_site_assistant_governance\Entity\AssistantGapItem $entity */
    $entity = $this->container->get('entity_type.manager')->getStorage('assistant_gap_item')->load($gap_item_id);
    self::assertNotNull($entity);

    $this->assertSame('1', (string) $entity->get('occurrence_count_total')->value);
    $this->assertSame('1', (string) $entity->get('occurrence_count_unresolved')->value);
    $this->assertSame('route:unknown', (string) $entity->get('identity_context_key')->value);
    $this->assertSame('route', (string) $entity->get('identity_source')->value);

    $hit = $this->container->get('database')->select('ilas_site_assistant_gap_hit', 'h')
      ->fields('h', ['is_unresolved', 'conversation_id', 'request_id'])
      ->condition('gap_item_id', $gap_item_id)
      ->range(0, 1)
      ->execute()
      ->fetchAssoc();

    $this->assertIsArray($hit);
    $this->assertSame('1', (string) $hit['is_unresolved']);
    $this->assertSame('12345678-1234-4123-8123-123456789abc', (string) $hit['conversation_id']);
    $this->assertSame('12345678-1234-4123-8123-123456789abd', (string) $hit['request_id']);
  }

  /**
   * The same text must split into different items across selection branches.
   */
  public function testRecordNoAnswerSplitsBySelectionContext(): void {
    $manager = $this->container->get('ilas_site_assistant_governance.gap_item_manager');
    $query = 'I need the right paperwork';

    $forms_gap_item_id = $manager->recordNoAnswer($query, [
      'intent_type' => 'forms',
      'active_selection_key' => 'forms_family',
      'assignment_source' => 'selection',
    ]);
    $guides_gap_item_id = $manager->recordNoAnswer($query, [
      'intent_type' => 'guides',
      'active_selection_key' => 'guides_family',
      'assignment_source' => 'selection',
    ]);

    $this->assertIsInt($forms_gap_item_id);
    $this->assertIsInt($guides_gap_item_id);
    $this->assertNotSame($forms_gap_item_id, $guides_gap_item_id);

    /** @var \Drupal\ilas_site_assistant_governance\Entity\AssistantGapItem $forms_gap_item */
    $forms_gap_item = $this->container->get('entity_type.manager')->getStorage('assistant_gap_item')->load($forms_gap_item_id);
    /** @var \Drupal\ilas_site_assistant_governance\Entity\AssistantGapItem $guides_gap_item */
    $guides_gap_item = $this->container->get('entity_type.manager')->getStorage('assistant_gap_item')->load($guides_gap_item_id);

    $this->assertSame('selection:forms_family', (string) $forms_gap_item->get('identity_context_key')->value);
    $this->assertSame('selection:guides_family', (string) $guides_gap_item->get('identity_context_key')->value);
  }

  /**
   * Later automatic hits must not overwrite an existing canonical context.
   */
  public function testRecordNoAnswerDoesNotOverwriteExistingCanonicalContext(): void {
    $service_area_a = Term::create([
      'vid' => 'service_areas',
      'name' => 'Family',
    ]);
    $service_area_a->save();

    $service_area_b = Term::create([
      'vid' => 'service_areas',
      'name' => 'Housing',
    ]);
    $service_area_b->save();

    $topic_a = Term::create([
      'vid' => 'topics',
      'name' => 'Family forms',
    ]);
    $topic_a->save();

    $topic_b = Term::create([
      'vid' => 'topics',
      'name' => 'Housing forms',
    ]);
    $topic_b->save();

    $manager = $this->container->get('ilas_site_assistant_governance.gap_item_manager');
    $query = 'I need the right paperwork';
    $metadata = ObservabilityPayloadMinimizer::buildTextMetadataWithLanguage($query);
    $identity = $this->identityForContext($metadata['text_hash'], $metadata['language_hint'], [
      'intent_type' => 'forms',
      'active_selection_key' => 'forms_family',
      'assignment_source' => 'selection',
      'topic_id' => (int) $topic_a->id(),
      'service_area_id' => (int) $service_area_a->id(),
    ]);
    $entity = $this->createGapItem('protected-context', AssistantGapItem::STATE_NEW, 1, [
      'cluster_hash' => $identity['cluster_hash'],
      'query_hash' => $metadata['text_hash'],
      'language_hint' => $metadata['language_hint'],
      'identity_context_key' => $identity['identity_context_key'],
      'identity_source' => $identity['identity_source'],
      'identity_selection_key' => $identity['identity_selection_key'],
      'identity_intent' => $identity['identity_intent'],
      'identity_topic_tid' => $identity['identity_topic_tid'],
      'identity_service_area_tid' => $identity['identity_service_area_tid'],
      'primary_topic_tid' => (int) $topic_a->id(),
      'primary_service_area_tid' => (int) $service_area_a->id(),
      'topic_assignment_source' => 'selection',
      'occurrence_count_total' => 1,
      'occurrence_count_unresolved' => 1,
    ]);

    $repeat_gap_item_id = $manager->recordNoAnswer($query, [
      'intent_type' => 'forms',
      'active_selection_key' => 'forms_family',
      'assignment_source' => 'router',
      'topic_id' => (int) $topic_b->id(),
      'service_area_id' => (int) $service_area_b->id(),
    ]);

    $this->assertSame((int) $entity->id(), $repeat_gap_item_id);

    /** @var \Drupal\ilas_site_assistant_governance\Entity\AssistantGapItem $entity */
    $entity = $this->container->get('entity_type.manager')->getStorage('assistant_gap_item')->load($repeat_gap_item_id);
    self::assertNotNull($entity);

    $this->assertSame((string) $topic_a->id(), (string) $entity->get('primary_topic_tid')->target_id);
    $this->assertSame((string) $service_area_a->id(), (string) $entity->get('primary_service_area_tid')->target_id);
    $this->assertSame('selection', (string) $entity->get('topic_assignment_source')->value);
    $this->assertSame('2', (string) $entity->get('occurrence_count_total')->value);
    $this->assertSame('selection:forms_family', (string) $entity->get('identity_context_key')->value);
  }

  /**
   * Resolving an item clears unresolved flags on its existing hit evidence.
   */
  public function testResolvingItemClosesOpenHitRows(): void {
    $entity = $this->createGapItem('resolve-hits', AssistantGapItem::STATE_NEEDS_REVIEW, 2);
    $database = $this->container->get('database');
    $timestamp = 1710000100;
    $conversation_id = '12345678-1234-4123-8123-123456789abc';

    $database->insert('ilas_site_assistant_conversation_session')
      ->fields([
        'conversation_id' => $conversation_id,
        'first_message_at' => $timestamp,
        'last_message_at' => $timestamp + 2,
        'turn_count' => 2,
        'exchange_count' => 1,
        'language_hint' => 'en',
        'last_intent' => 'housing_help',
        'last_response_type' => 'no_answer',
        'has_no_answer' => 1,
        'has_unresolved_gap' => 1,
        'latest_gap_item_id' => (int) $entity->id(),
        'is_held' => 0,
        'purge_after' => $timestamp + 86400,
      ])
      ->execute();

    foreach ([1, 2] as $offset) {
      $database->insert('ilas_site_assistant_gap_hit')
        ->fields([
          'gap_item_id' => (int) $entity->id(),
          'conversation_id' => $conversation_id,
          'occurred_at' => $timestamp + $offset,
          'is_unresolved' => 1,
          'query_hash' => hash('sha256', 'resolve-hits:' . $offset),
          'language_hint' => 'en',
          'assignment_source' => 'unknown',
        ])
        ->execute();
    }

    $entity->applyTransition(AssistantGapItem::STATE_RESOLVED, 2);
    $entity->save();

    $flags = $database->select('ilas_site_assistant_gap_hit', 'h')
      ->fields('h', ['is_unresolved'])
      ->condition('gap_item_id', (int) $entity->id())
      ->execute()
      ->fetchCol();

    $this->assertSame(['0', '0'], array_values($flags));

    $session = $database->select('ilas_site_assistant_conversation_session', 's')
      ->fields('s', ['has_no_answer', 'has_unresolved_gap'])
      ->condition('conversation_id', $conversation_id)
      ->execute()
      ->fetchAssoc();

    $this->assertIsArray($session);
    $this->assertSame('1', (string) $session['has_no_answer']);
    $this->assertSame('0', (string) $session['has_unresolved_gap']);
  }

  /**
   * Resolving one gap must not clear current follow-up when another remains.
   */
  public function testResolvingOneItemKeepsConversationFollowUpWhenAnotherGapRemains(): void {
    $database = $this->container->get('database');
    $timestamp = 1710000200;
    $conversation_id = '12345678-1234-4123-8123-123456789abd';
    $first = $this->createGapItem('conversation-open-one', AssistantGapItem::STATE_NEEDS_REVIEW, 1);
    $second = $this->createGapItem('conversation-open-two', AssistantGapItem::STATE_NEEDS_REVIEW, 1);

    $database->insert('ilas_site_assistant_conversation_session')
      ->fields([
        'conversation_id' => $conversation_id,
        'first_message_at' => $timestamp,
        'last_message_at' => $timestamp + 2,
        'turn_count' => 4,
        'exchange_count' => 2,
        'language_hint' => 'en',
        'last_intent' => 'housing_help',
        'last_response_type' => 'no_answer',
        'has_no_answer' => 1,
        'has_unresolved_gap' => 1,
        'latest_gap_item_id' => (int) $second->id(),
        'is_held' => 0,
        'purge_after' => $timestamp + 86400,
      ])
      ->execute();

    foreach ([
      [(int) $first->id(), $timestamp + 1],
      [(int) $second->id(), $timestamp + 2],
    ] as [$gap_item_id, $occurred_at]) {
      $database->insert('ilas_site_assistant_gap_hit')
        ->fields([
          'gap_item_id' => $gap_item_id,
          'conversation_id' => $conversation_id,
          'occurred_at' => $occurred_at,
          'is_unresolved' => 1,
          'query_hash' => hash('sha256', 'conversation-open:' . $gap_item_id),
          'language_hint' => 'en',
          'assignment_source' => 'unknown',
        ])
        ->execute();
    }

    $first->applyTransition(AssistantGapItem::STATE_RESOLVED, 2);
    $first->save();

    $session = $database->select('ilas_site_assistant_conversation_session', 's')
      ->fields('s', ['has_unresolved_gap'])
      ->condition('conversation_id', $conversation_id)
      ->execute()
      ->fetchAssoc();

    $this->assertIsArray($session);
    $this->assertSame('1', (string) $session['has_unresolved_gap']);
  }

  /**
   * Creates a gap item with the minimum required fields.
   */
  private function createGapItem(string $seed, string $state, int $unresolved, array $overrides = []): AssistantGapItem {
    $storage = $this->container->get('entity_type.manager')->getStorage('assistant_gap_item');
    $timestamp = 1710000000;
    $values = $overrides + [
      'cluster_hash' => hash('sha256', $seed . ':cluster'),
      'query_hash' => hash('sha256', $seed . ':query'),
      'language_hint' => 'en',
      'query_length_bucket' => '1-24',
      'redaction_profile' => 'none',
      'review_state' => $state,
      'first_seen' => $timestamp,
      'last_seen' => $timestamp,
      'occurrence_count_total' => max(1, $unresolved),
      'occurrence_count_unresolved' => $unresolved,
    ];

    /** @var \Drupal\ilas_site_assistant_governance\Entity\AssistantGapItem $entity */
    $entity = $storage->create($values);
    $entity->save();
    return $entity;
  }

  /**
   * Finds a Views result row by gap-item entity ID.
   *
   * @param object[] $rows
   *   Views result rows.
   */
  private function findViewResultRow(array $rows, int $entity_id): object {
    foreach ($rows as $row) {
      if ((int) $row->_entity->id() === $entity_id) {
        return $row;
      }
    }

    $this->fail(sprintf('Expected to find assistant gap item %d in the view result.', $entity_id));
  }

  /**
   * Builds the corrected immutable identity for a test query and context.
   */
  private function identityForContext(string $query_hash, string $language_hint, array $context): array {
    $topic_context = [
      'topic_id' => $context['topic_id'] ?? NULL,
      'service_area_id' => $context['service_area_id'] ?? NULL,
    ];

    return $this->container->get('ilas_site_assistant_governance.gap_item_identity_builder')
      ->buildFromRuntimeContext($query_hash, $language_hint, $context, $topic_context);
  }

  /**
   * Sets a lightweight current account with the requested permissions.
   *
   * @param string[] $permissions
   *   Allowed permissions.
   */
  private function setCurrentAccount(array $permissions): void {
    $account = $this->createMock(AccountInterface::class);
    $account->method('id')->willReturn('2');
    $account->method('hasPermission')->willReturnCallback(static fn(string $permission): bool => in_array($permission, $permissions, TRUE));
    $this->container->get('current_user')->setAccount($account);
  }

}
