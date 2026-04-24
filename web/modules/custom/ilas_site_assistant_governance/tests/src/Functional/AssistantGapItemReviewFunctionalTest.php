<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant_governance\Functional;

use Drupal\Tests\BrowserTestBase;
use Drupal\ilas_site_assistant\Service\PiiRedactor;
use Drupal\ilas_site_assistant_governance\Entity\AssistantGapItem;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use PHPUnit\Framework\Attributes\Group;

/**
 * Functional coverage for the gap-item reviewer workflow.
 */
#[Group('ilas_site_assistant_governance')]
final class AssistantGapItemReviewFunctionalTest extends BrowserTestBase {

  /**
   * Runtime functional coverage should not be blocked by config-schema drift.
   *
   * @var bool
   */
  protected $strictConfigSchema = FALSE;

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'ilas_site_assistant_action_compat',
    'eca',
    'system',
    'user',
    'field',
    'filter',
    'text',
    'node',
    'taxonomy',
    'token',
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
   * Reviewer user.
   *
   * @var \Drupal\user\UserInterface
   */
  protected $reviewerUser;

  /**
   * The gap item under review.
   */
  protected AssistantGapItem $gapItem;

  /**
   * Initial topic term used to simulate an unknown legacy assignment.
   */
  protected Term $initialTopicTerm;

  /**
   * Reviewer-selected topic term.
   */
  protected Term $reviewTopicTerm;

  /**
   * Initial service-area term used to simulate an unknown legacy assignment.
   */
  protected Term $initialServiceAreaTerm;

  /**
   * Reviewer-selected service-area term.
   */
  protected Term $reviewServiceAreaTerm;

  /**
   * The raw query that must not appear in reviewer UI.
   */
  protected string $rawQuery;

  /**
   * A unique non-sensitive excerpt that appears in redacted storage.
   */
  protected string $safeExcerpt = 'How do I contest a three-day notice?';

  /**
   * A raw email address that must not appear in reviewer UI.
   */
  protected string $rawEmail = 'jane.tenant@example.com';

  /**
   * A raw phone number that must not appear in reviewer UI.
   */
  protected string $rawPhone = '208-555-0199';

  /**
   * A raw name that must not appear in reviewer UI.
   */
  protected string $rawName = 'Jane Tenant';

  /**
   * The stored redacted exemplar query.
   */
  protected string $redactedQuery;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->initialTopicTerm = $this->createTerm('topics', 'Legacy topic guess');
    $this->reviewTopicTerm = $this->createTerm('topics', 'Housing workflow topic');
    $this->initialServiceAreaTerm = $this->createTerm('service_areas', 'Legacy service area guess');
    $this->reviewServiceAreaTerm = $this->createTerm('service_areas', 'Housing workflow service area');

    $this->reviewerUser = $this->drupalCreateUser([
      'access administration pages',
      'view assistant gap items',
      'edit assistant gap items',
      'transition assistant gap items to needs_review',
      'transition assistant gap items to reviewed',
      'transition assistant gap items to resolved',
      'transition assistant gap items to archived',
      'reopen assistant gap items',
      'flag assistant gap items',
      'view assistant governance conversations',
    ]);

    $this->seedGapItemWithEvidence();
  }

  /**
   * Tests the reviewer workflow from queue item to dismissal.
   */
  public function testReviewerWorkflowUsesCanonicalWorkspaceAndDismissesGap(): void {
    $this->drupalLogin($this->reviewerUser);

    $queue_path = '/admin/reports/ilas-assistant/gaps';
    $detail_path = '/admin/reports/ilas-assistant/gaps/' . $this->gapItem->id();

    $this->drupalGet($queue_path);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains($this->safeExcerpt);
    $this->assertSession()->pageTextContains('Open occurrences');
    $this->assertSession()->pageTextNotContains('Lifetime occurrences');
    $this->assertSession()->elementExists('xpath', "//tr[td[contains(normalize-space(.), '{$this->safeExcerpt}')] and td[normalize-space()='3']]");
    $this->assertSession()->pageTextContains('New');
    $this->assertSession()->pageTextContains('Assign topic');

    $this->drupalGet($queue_path . '/all');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains($this->safeExcerpt);
    $this->assertSession()->pageTextContains('Lifetime occurrences');
    $this->assertSession()->elementExists('xpath', "//tr[td[contains(normalize-space(.), '{$this->safeExcerpt}')] and td[normalize-space()='5']]");

    $this->drupalGet($detail_path);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Review this assistant gap using redacted evidence only.');
    $this->assertSession()->pageTextContains($this->safeExcerpt);
    $this->assertSession()->pageTextContains('Recent gap evidence');
    $this->assertSession()->pageTextContains('Latest redacted conversation');
    $this->assertSession()->pageTextContains(PiiRedactor::TOKEN_EMAIL);
    $this->assertSession()->pageTextContains(PiiRedactor::TOKEN_PHONE);
    $this->assertSession()->pageTextContains('Open full redacted conversation');
    $this->assertSession()->elementExists('xpath', "//tr[td[normalize-space()='Open occurrences'] and td[normalize-space()='3']]");
    $this->assertSession()->elementExists('xpath', "//tr[td[normalize-space()='Lifetime occurrences'] and td[normalize-space()='5']]");
    $this->assertSession()->pageTextNotContains($this->rawEmail);
    $this->assertSession()->pageTextNotContains($this->rawPhone);
    $this->assertSession()->pageTextNotContains($this->rawName);

    $this->submitForm([
      'primary_topic_tid[0][target_id]' => $this->reviewTopicTerm->label(),
      'primary_service_area_tid[0][target_id]' => $this->reviewServiceAreaTerm->label(),
      'secondary_flags[potential_faq_candidate]' => TRUE,
      'secondary_flags[needs_content_update]' => TRUE,
      'resolution_notes[0][value]' => 'Topic assignment corrected from reviewer-safe evidence.',
    ], 'Save review');

    $reloaded = $this->reloadGapItem();
    $this->assertSame('reviewer', (string) $reloaded->get('topic_assignment_source')->value);
    $this->assertTrue($reloaded->get('topic_assignment_confidence')->isEmpty());
    $this->assertSame((string) $this->reviewerUser->id(), (string) $reloaded->get('assigned_uid')->target_id);
    $this->assertContains(
      AssistantGapItem::FLAG_POTENTIAL_FAQ_CANDIDATE,
      array_column($reloaded->get('secondary_flags')->getValue(), 'value')
    );
    $this->assertContains(
      AssistantGapItem::FLAG_NEEDS_CONTENT_UPDATE,
      array_column($reloaded->get('secondary_flags')->getValue(), 'value')
    );

    $this->submitForm([
      'resolution_notes[0][value]' => 'Reviewed and ready for a final disposition.',
    ], 'Mark reviewed');
    $this->assertSame(AssistantGapItem::STATE_REVIEWED, $this->reloadGapItem()->getReviewState());

    $this->drupalGet($queue_path);
    $this->assertSession()->pageTextContains($this->safeExcerpt);
    $this->assertSession()->pageTextContains('Reviewed');
    $this->assertSession()->pageTextContains('Resolve or dismiss');

    $this->drupalGet($detail_path);
    $this->submitForm([
      'resolution_notes[0][value]' => 'False positive after comparing the redacted turns.',
    ], 'Dismiss gap');

    $reloaded = $this->reloadGapItem();
    $this->assertSame(AssistantGapItem::STATE_RESOLVED, $reloaded->getReviewState());
    $this->assertSame(AssistantGapItem::RESOLUTION_FALSE_POSITIVE, (string) $reloaded->get('resolution_code')->value);
    $this->assertSame('0', (string) $reloaded->get('occurrence_count_unresolved')->value);

    $session = \Drupal::database()->select('ilas_site_assistant_conversation_session', 's')
      ->fields('s', ['has_no_answer', 'has_unresolved_gap'])
      ->condition('conversation_id', (string) $reloaded->get('latest_conversation_id')->value)
      ->execute()
      ->fetchAssoc();

    $this->assertIsArray($session);
    $this->assertSame('1', (string) $session['has_no_answer']);
    $this->assertSame('0', (string) $session['has_unresolved_gap']);

    $this->drupalGet($queue_path);
    $this->assertSession()->pageTextNotContains($this->safeExcerpt);
    $this->assertSession()->pageTextNotContains('Resolve or dismiss');
  }

  /**
   * Tests bulk resolve defers state changes until disposition submit.
   */
  public function testBulkResolveUsesDispositionFormAndDefaultsBlankCodeToOther(): void {
    $this->drupalLogin($this->reviewerUser);

    $this->startBulkDispositionFlow('assistant_gap_item_to_resolved_action');
    $this->assertSession()->addressEquals('admin/reports/ilas-assistant/gaps/bulk/resolve');
    $this->assertSession()->pageTextContains('No gap items are updated until you submit this form.');
    $this->assertSame(AssistantGapItem::STATE_NEW, $this->reloadGapItem()->getReviewState());

    $this->submitForm([], 'Apply disposition');

    $reloaded = $this->reloadGapItem();
    $this->assertSame(AssistantGapItem::STATE_RESOLVED, $reloaded->getReviewState());
    $this->assertSame(AssistantGapItem::RESOLUTION_OTHER, (string) $reloaded->get('resolution_code')->value);
    $this->assertTrue($reloaded->get('resolution_reference')->isEmpty());
    $this->assertTrue($reloaded->get('resolution_notes')->isEmpty());
    $this->assertSame((int) $this->reviewerUser->id(), (int) $reloaded->getRevisionUserId());
    $this->assertSame('Bulk action moved gap item to resolved.', (string) $reloaded->getRevisionLogMessage());
    $this->assertSame((string) $this->reviewerUser->id(), (string) $reloaded->get('assigned_uid')->target_id);
  }

  /**
   * Tests bulk resolve requires a reference for content dispositions.
   */
  public function testBulkResolveValidationRequiresReference(): void {
    $this->drupalLogin($this->reviewerUser);

    $this->startBulkDispositionFlow('assistant_gap_item_to_resolved_action');
    $this->assertSame(AssistantGapItem::STATE_NEW, $this->reloadGapItem()->getReviewState());

    $this->submitForm([
      'resolution_code' => AssistantGapItem::RESOLUTION_FAQ_CREATED,
      'resolution_notes' => 'Created a reviewer-approved FAQ entry.',
    ], 'Apply disposition');

    $this->assertSession()->pageTextContains('This disposition requires a reference to the FAQ, content change, or search tuning work.');
    $this->assertSame(AssistantGapItem::STATE_NEW, $this->reloadGapItem()->getReviewState());
  }

  /**
   * Tests bulk archive requires suppression notes before saving.
   */
  public function testBulkArchiveValidationRequiresSuppressionNotes(): void {
    $this->gapItem->applyTransition(AssistantGapItem::STATE_REVIEWED, (int) $this->reviewerUser->id());
    $this->gapItem->save();

    $this->drupalLogin($this->reviewerUser);
    $this->startBulkDispositionFlow('assistant_gap_item_to_archived_action');
    $this->assertSession()->addressEquals('admin/reports/ilas-assistant/gaps/bulk/archive');
    $this->assertSame(AssistantGapItem::STATE_REVIEWED, $this->reloadGapItem()->getReviewState());

    $this->submitForm([
      'resolution_code' => AssistantGapItem::RESOLUTION_FALSE_POSITIVE,
      'resolution_notes' => 'too short',
    ], 'Apply disposition');

    $this->assertSession()->pageTextContains('Add a short note explaining why this item is being suppressed.');
    $this->assertSame(AssistantGapItem::STATE_REVIEWED, $this->reloadGapItem()->getReviewState());

    $this->submitForm([
      'resolution_code' => AssistantGapItem::RESOLUTION_FALSE_POSITIVE,
      'resolution_notes' => 'False positive confirmed after comparing the redacted evidence.',
    ], 'Apply disposition');

    $reloaded = $this->reloadGapItem();
    $this->assertSame(AssistantGapItem::STATE_ARCHIVED, $reloaded->getReviewState());
    $this->assertSame(AssistantGapItem::RESOLUTION_FALSE_POSITIVE, (string) $reloaded->get('resolution_code')->value);
    $this->assertSame('False positive confirmed after comparing the redacted evidence.', (string) $reloaded->get('resolution_notes')->value);
    $this->assertSame((int) $this->reviewerUser->id(), (int) $reloaded->getRevisionUserId());
    $this->assertSame('Bulk action moved gap item to archived.', (string) $reloaded->getRevisionLogMessage());
    $this->assertSame((string) $this->reviewerUser->id(), (string) $reloaded->get('assigned_uid')->target_id);
  }

  /**
   * Creates a taxonomy term in the requested vocabulary.
   */
  protected function createTerm(string $vocabulary_id, string $name): Term {
    $this->ensureVocabulary($vocabulary_id);

    $term = Term::create([
      'vid' => $vocabulary_id,
      'name' => $name,
    ]);
    $term->save();

    return $term;
  }

  /**
   * Ensures the requested vocabulary exists.
   */
  protected function ensureVocabulary(string $vocabulary_id): void {
    if (Vocabulary::load($vocabulary_id)) {
      return;
    }

    Vocabulary::create([
      'vid' => $vocabulary_id,
      'name' => ucwords(str_replace('_', ' ', $vocabulary_id)),
    ])->save();
  }

  /**
   * Seeds a gap item plus redacted evidence rows.
   */
  protected function seedGapItemWithEvidence(): void {
    $timestamp = 1710000000;
    $conversation_id = \Drupal::service('uuid')->generate();
    $request_id = \Drupal::service('uuid')->generate();
    $this->rawQuery = sprintf(
      'My name is %s, email %s, phone %s. %s',
      $this->rawName,
      $this->rawEmail,
      $this->rawPhone,
      $this->safeExcerpt,
    );
    $this->redactedQuery = PiiRedactor::redactForStorage($this->rawQuery, 2000);
    $query_hash = hash('sha256', $this->redactedQuery);
    $cluster_hash = hash('sha256', $query_hash . '|en');

    $this->gapItem = AssistantGapItem::create([
      'cluster_hash' => $cluster_hash,
      'query_hash' => $query_hash,
      'exemplar_redacted_query' => $this->redactedQuery,
      'language_hint' => 'en',
      'query_length_bucket' => '25-120',
      'redaction_profile' => 'functional-test',
      'review_state' => AssistantGapItem::STATE_NEW,
      'primary_topic_tid' => $this->initialTopicTerm->id(),
      'primary_service_area_tid' => $this->initialServiceAreaTerm->id(),
      'topic_assignment_source' => 'unknown',
      'topic_assignment_confidence' => 87,
      'first_seen' => $timestamp,
      'last_seen' => $timestamp,
      'occurrence_count_total' => 5,
      'occurrence_count_unresolved' => 3,
      'first_conversation_id' => $conversation_id,
      'latest_conversation_id' => $conversation_id,
      'latest_request_id' => $request_id,
    ]);
    $this->gapItem->save();

    $database = \Drupal::database();

    $database->insert('ilas_site_assistant_conversation_session')
      ->fields([
        'conversation_id' => $conversation_id,
        'first_message_at' => $timestamp,
        'last_message_at' => $timestamp + 120,
        'turn_count' => 2,
        'exchange_count' => 1,
        'language_hint' => 'en',
        'last_intent' => 'housing_help',
        'last_response_type' => 'no_answer',
        'first_request_id' => $request_id,
        'last_request_id' => $request_id,
        'has_no_answer' => 1,
        'has_unresolved_gap' => 1,
        'latest_gap_item_id' => (int) $this->gapItem->id(),
        'is_held' => 0,
        'purge_after' => $timestamp + 86400,
      ])
      ->execute();

    $user_turn = PiiRedactor::redactForStorage($this->rawQuery, 4000);
    $assistant_turn = PiiRedactor::redactForStorage(
      'I could not confidently answer that request, but I kept the conversation redacted for review.',
      4000,
    );

    $database->insert('ilas_site_assistant_conversation_turn')
      ->fields([
        'conversation_id' => $conversation_id,
        'turn_sequence' => 1,
        'request_id' => $request_id,
        'direction' => 'user',
        'message_redacted' => $user_turn,
        'message_hash' => hash('sha256', $user_turn),
        'message_length_bucket' => '25-120',
        'redaction_profile' => 'functional-test',
        'redaction_version' => 'v1',
        'language_hint' => 'en',
        'intent' => 'housing_help',
        'created' => $timestamp,
      ])
      ->execute();

    $database->insert('ilas_site_assistant_conversation_turn')
      ->fields([
        'conversation_id' => $conversation_id,
        'turn_sequence' => 2,
        'request_id' => $request_id,
        'direction' => 'assistant',
        'message_redacted' => $assistant_turn,
        'message_hash' => hash('sha256', $assistant_turn),
        'message_length_bucket' => '25-120',
        'redaction_profile' => 'functional-test',
        'redaction_version' => 'v1',
        'language_hint' => 'en',
        'intent' => 'housing_help',
        'response_type' => 'no_answer',
        'is_no_answer' => 1,
        'gap_item_id' => (int) $this->gapItem->id(),
        'created' => $timestamp + 60,
      ])
      ->execute();

    $database->insert('ilas_site_assistant_gap_hit')
      ->fields([
        'gap_item_id' => (int) $this->gapItem->id(),
        'conversation_id' => $conversation_id,
        'request_id' => $request_id,
        'occurred_at' => $timestamp + 60,
        'query_hash' => $query_hash,
        'language_hint' => 'en',
        'observed_topic_tid' => $this->initialTopicTerm->id(),
        'observed_service_area_tid' => $this->initialServiceAreaTerm->id(),
        'assignment_source' => 'unknown',
        'intent' => 'housing_help',
        'active_selection_key' => 'faq:none',
      ])
      ->execute();
  }

  /**
   * Reloads the gap item from storage.
   */
  protected function reloadGapItem(): AssistantGapItem {
    /** @var \Drupal\ilas_site_assistant_governance\Entity\AssistantGapItem $entity */
    $entity = \Drupal::entityTypeManager()->getStorage('assistant_gap_item')->load($this->gapItem->id());
    $this->assertNotNull($entity);
    $this->gapItem = $entity;
    return $entity;
  }

  /**
   * Starts a tempstore-backed bulk disposition flow for the current gap item.
   */
  protected function startBulkDispositionFlow(string $action_id): void {
    $this->container->get('current_user')->setAccount($this->reviewerUser);
    $action = \Drupal::entityTypeManager()->getStorage('action')->load($action_id);
    $this->assertNotNull($action);
    $action->execute([$this->gapItem]);

    $this->drupalGet(match ($action_id) {
      'assistant_gap_item_to_archived_action' => '/admin/reports/ilas-assistant/gaps/bulk/archive',
      default => '/admin/reports/ilas-assistant/gaps/bulk/resolve',
    });
  }

}
