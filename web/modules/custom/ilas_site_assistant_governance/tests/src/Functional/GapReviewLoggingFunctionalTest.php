<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant_governance\Functional;

use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\ilas_site_assistant\Traits\RetrievalIndexFieldScaffoldingTrait;
use GuzzleHttp\Cookie\CookieJarInterface;
use PHPUnit\Framework\Attributes\Group;

/**
 * End-to-end coverage for gap-review logging through the assistant API.
 */
#[Group('ilas_site_assistant_governance')]
final class GapReviewLoggingFunctionalTest extends BrowserTestBase {

  use RetrievalIndexFieldScaffoldingTrait;

  /**
   * Governance functional coverage should not be blocked by config-schema drift.
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
    'file',
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
   * Regular authenticated user for API calls.
   *
   * @var \Drupal\user\UserInterface
   */
  protected $regularUser;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Materialize the lexical retrieval indexes so the message pipeline runs
    // in its production shape (index present, empty) instead of the
    // fail-closed retrieval-unavailable path, which reclassifies every
    // message as a "navigation" response.
    $this->scaffoldRetrievalIndexFields();
    \Drupal::service('config.installer')->installOptionalConfig();

    $this->regularUser = $this->drupalCreateUser([
      'access content',
    ]);
  }

  /**
   * Clarify-style fallback traffic must not create gap-review artifacts.
   */
  public function testClarifyFallbackDoesNotCreateGapReviewArtifacts(): void {
    $this->drupalLogin($this->regularUser);
    $conversation_id = '12345678-1234-4123-8123-123456789abc';

    $response = $this->postJson('/assistant/api/message', [
      'message' => 'zxqvplm',
      'conversation_id' => $conversation_id,
    ]);

    $this->assertSame(200, $response->getStatusCode());
    $data = json_decode((string) $response->getBody(), TRUE);
    $this->assertIsArray($data);
    $this->assertSame('fallback', $data['type'] ?? NULL);
    $this->assertSame('clarification_needed', $data['reason_code'] ?? NULL);

    $database = \Drupal::database();
    $this->assertSame(
      '0',
      (string) $database->select('assistant_gap_item', 'g')
        ->countQuery()
        ->execute()
        ->fetchField()
    );
    $this->assertSame(
      '0',
      (string) $database->select('ilas_site_assistant_gap_hit', 'h')
        ->countQuery()
        ->execute()
        ->fetchField()
    );

    $session = $database->select('ilas_site_assistant_conversation_session', 's')
      ->fields('s', ['has_no_answer', 'has_unresolved_gap', 'latest_gap_item_id'])
      ->condition('conversation_id', $conversation_id)
      ->execute()
      ->fetchAssoc();

    $this->assertIsArray($session);
    $this->assertSame('0', (string) $session['has_no_answer']);
    $this->assertSame('0', (string) $session['has_unresolved_gap']);
    $this->assertNull($session['latest_gap_item_id']);

    $flagged_turns = $database->select('ilas_site_assistant_conversation_turn', 't')
      ->condition('conversation_id', $conversation_id)
      ->condition('is_no_answer', 1)
      ->countQuery()
      ->execute()
      ->fetchField();

    $this->assertSame('0', (string) $flagged_turns);
  }

  /**
   * A substantive generic clarify creates a clarify-triggered gap item.
   *
   * Regression for conversation 7c10da94, whose three clarify/navigation
   * turns left no trace in the review queue.
   */
  public function testSubstantiveGenericClarifyCreatesClarifyTriggeredGapItem(): void {
    $this->drupalLogin($this->regularUser);
    $conversation_id = '12345678-1234-4123-8123-123456789abd';

    $response = $this->postJson('/assistant/api/message', [
      'message' => 'qwertyuiop asdfghjkl',
      'conversation_id' => $conversation_id,
    ]);

    $this->assertSame(200, $response->getStatusCode());
    $data = json_decode((string) $response->getBody(), TRUE);
    $this->assertSame('fallback', $data['type'] ?? NULL);
    $this->assertSame('clarification_needed', $data['reason_code'] ?? NULL);

    $database = \Drupal::database();
    $items = $database->select('assistant_gap_item', 'g')
      ->fields('g', ['id', 'trigger_code', 'latest_trigger_code', 'occurrence_count_unresolved'])
      ->execute()
      ->fetchAll(\PDO::FETCH_ASSOC);
    $this->assertCount(1, $items);
    $this->assertSame('clarify_unknown_intent', $items[0]['trigger_code']);
    $this->assertSame('clarify_unknown_intent', $items[0]['latest_trigger_code']);

    $hit = $database->select('ilas_site_assistant_gap_hit', 'h')
      ->fields('h', ['trigger_code', 'conversation_id', 'is_unresolved'])
      ->execute()
      ->fetchAssoc();
    $this->assertSame('clarify_unknown_intent', $hit['trigger_code']);
    $this->assertSame($conversation_id, $hit['conversation_id']);

    $session = $database->select('ilas_site_assistant_conversation_session', 's')
      ->fields('s', ['has_no_answer', 'has_unresolved_gap', 'latest_gap_item_id'])
      ->condition('conversation_id', $conversation_id)
      ->execute()
      ->fetchAssoc();
    // Not a dead end, but the conversation does need follow-up.
    $this->assertSame('0', (string) $session['has_no_answer']);
    $this->assertSame('1', (string) $session['has_unresolved_gap']);
    $this->assertSame((string) $items[0]['id'], (string) $session['latest_gap_item_id']);

    $turn = $database->select('ilas_site_assistant_conversation_turn', 't')
      ->fields('t', ['is_no_answer', 'gap_item_id'])
      ->condition('conversation_id', $conversation_id)
      ->condition('direction', 'user')
      ->execute()
      ->fetchAssoc();
    $this->assertSame('0', (string) $turn['is_no_answer']);
    $this->assertSame((string) $items[0]['id'], (string) $turn['gap_item_id']);
  }

  /**
   * A thumbs-down through /assistant/api/track becomes a negative_feedback item.
   */
  public function testNotHelpfulFeedbackCreatesNegativeFeedbackGapItem(): void {
    $this->drupalLogin($this->regularUser);
    $conversation_id = '12345678-1234-4123-8123-123456789abf';

    $response = $this->postJson('/assistant/api/message', [
      'message' => 'What are my tenant rights in Idaho?',
      'conversation_id' => $conversation_id,
    ]);
    $this->assertSame(200, $response->getStatusCode());
    $data = json_decode((string) $response->getBody(), TRUE);
    $request_id = (string) ($data['request_id'] ?? '');
    $this->assertMatchesRegularExpression('/^[a-f0-9\-]{36}$/i', $request_id, 'Message responses carry the request id the widget echoes back with feedback');

    $database = \Drupal::database();
    $this->assertSame('0', (string) $database->select('assistant_gap_item', 'g')->countQuery()->execute()->fetchField(), 'An answered question creates no gap item by itself');

    $track = $this->postJson('/assistant/api/track', [
      'event_type' => 'feedback_not_helpful',
      'event_value' => 'topic',
      'conversation_id' => $conversation_id,
      'response_request_id' => $request_id,
      'response_type' => 'topic',
    ]);
    $this->assertSame(200, $track->getStatusCode(), (string) $track->getBody());

    $items = $database->select('assistant_gap_item', 'g')
      ->fields('g', ['id', 'trigger_code', 'exemplar_redacted_query'])
      ->execute()
      ->fetchAll(\PDO::FETCH_ASSOC);
    $this->assertCount(1, $items);
    $this->assertSame('negative_feedback', $items[0]['trigger_code']);
    $this->assertSame('What are my tenant rights in Idaho?', $items[0]['exemplar_redacted_query']);

    $turns = $database->select('ilas_site_assistant_conversation_turn', 't')
      ->fields('t', ['direction', 'feedback_value', 'gap_item_id'])
      ->condition('conversation_id', $conversation_id)
      ->orderBy('turn_sequence')
      ->execute()
      ->fetchAll(\PDO::FETCH_ASSOC);
    $this->assertSame('user', $turns[0]['direction']);
    $this->assertSame((string) $items[0]['id'], (string) $turns[0]['gap_item_id']);
    $this->assertSame('not_helpful', $turns[1]['feedback_value']);

    $session = $database->select('ilas_site_assistant_conversation_session', 's')
      ->fields('s', ['has_negative_feedback', 'has_unresolved_gap'])
      ->condition('conversation_id', $conversation_id)
      ->execute()
      ->fetchAssoc();
    $this->assertSame('1', (string) $session['has_negative_feedback']);
    $this->assertSame('1', (string) $session['has_unresolved_gap']);

    // A second vote on the same answer changes nothing.
    $this->postJson('/assistant/api/track', [
      'event_type' => 'feedback_not_helpful',
      'conversation_id' => $conversation_id,
      'response_request_id' => $request_id,
      'response_type' => 'topic',
    ]);
    $this->assertSame('1', (string) $database->select('assistant_gap_item', 'g')->countQuery()->execute()->fetchField());
  }

  /**
   * Reviewers can push a transcript turn into the queue from the detail page.
   */
  public function testReviewerCanFlagTranscriptTurn(): void {
    $this->drupalLogin($this->regularUser);
    $conversation_id = '12345678-1234-4123-8123-123456789ac0';
    $this->postJson('/assistant/api/message', [
      'message' => 'What are my tenant rights in Idaho?',
      'conversation_id' => $conversation_id,
    ]);

    $reviewer = $this->drupalCreateUser([
      'view assistant governance conversations',
      'view assistant gap items',
      'flag assistant conversation turns',
    ]);
    $this->drupalLogin($reviewer);
    $this->drupalGet('/admin/reports/ilas-assistant/governance/conversations/' . $conversation_id);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Feedback');
    $this->clickLink('Flag for review');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('was added to the gap review queue');

    $database = \Drupal::database();
    $items = $database->select('assistant_gap_item', 'g')
      ->fields('g', ['id', 'trigger_code'])
      ->execute()
      ->fetchAll(\PDO::FETCH_ASSOC);
    $this->assertCount(1, $items);
    $this->assertSame('reviewer_flagged', $items[0]['trigger_code']);

    // The flagged turn now links to the item and the link is gone.
    $this->drupalGet('/admin/reports/ilas-assistant/governance/conversations/' . $conversation_id);
    $this->assertSession()->linkNotExists('Flag for review');
    $this->assertSession()->linkExists((string) $items[0]['id']);

    // The Eval export tab is reachable for reviewers; run-now needs edit.
    $this->drupalGet('/admin/reports/ilas-assistant/gaps/export');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Never');
    $this->assertSession()->linkNotExists('Run export now');
  }

  /**
   * A reviewer can turn a miss into a topic alias that routes immediately.
   */
  public function testReviewerCanAddTopicAliasFromGapItem(): void {
    $this->drupalLogin($this->regularUser);
    $conversation_id = '12345678-1234-4123-8123-123456789ac1';
    $phrase = 'qwertyuiop asdfghjkl';

    $this->postJson('/assistant/api/message', ['message' => $phrase, 'conversation_id' => $conversation_id]);
    $database = \Drupal::database();
    $item_id = (int) $database->select('assistant_gap_item', 'g')->fields('g', ['id'])->execute()->fetchField();
    $this->assertGreaterThan(0, $item_id, 'The generic clarify created a gap item');

    $reviewer = $this->drupalCreateUser([
      'view assistant gap items',
      'edit assistant gap items',
      'transition assistant gap items to resolved',
    ]);
    $this->drupalLogin($reviewer);
    $this->drupalGet('/admin/reports/ilas-assistant/gaps/' . $item_id);
    $this->assertSession()->statusCodeEquals(200);
    $this->clickLink('Add exemplar as topic alias');
    $this->assertSession()->fieldValueEquals('phrase', $phrase);
    $this->submitForm([
      'phrase' => $phrase,
      'topic_intent' => 'topic_housing_landlord_tenant',
      'resolve' => TRUE,
    ], 'Add topic alias');
    $this->assertSession()->pageTextContains('now routes to topic_housing_landlord_tenant');
    $this->assertSession()->pageTextContains('resolved as search tuned');

    $aliases = \Drupal::config('ilas_site_assistant.settings')->get('lexicon_overrides.topic_aliases');
    $this->assertSame([['phrase' => $phrase, 'topic_intent' => 'topic_housing_landlord_tenant']], $aliases);

    $item = $database->select('assistant_gap_item', 'g')
      ->fields('g', ['review_state', 'resolution_code', 'resolution_reference'])
      ->condition('id', $item_id)
      ->execute()
      ->fetchAssoc();
    $this->assertSame('resolved', $item['review_state']);
    $this->assertSame('search_tuned', $item['resolution_code']);
    $this->assertSame('lexicon_overrides:' . $phrase, $item['resolution_reference']);

    // The same wording now routes to the topic answer instead of the generic
    // clarify. (The test site has no indexed content, so the grounding
    // policy may still mark the citation-less answer as clarify_no_grounding;
    // the routing evidence is the reason code and the canonical link.)
    $this->drupalLogin($this->regularUser);
    $response = $this->postJson('/assistant/api/message', ['message' => $phrase, 'conversation_id' => '12345678-1234-4123-8123-123456789ac2']);
    $data = json_decode((string) $response->getBody(), TRUE);
    $this->assertSame('intent_pack_topic_housing_landlord_tenant', $data['reason_code'] ?? NULL, json_encode($data));
    $this->assertSame('/resources/landlord-and-tenant', $data['url'] ?? NULL);
    $this->assertNotSame('clarification_needed', $data['reason_code'] ?? NULL);
    $this->assertSame('1', (string) $database->select('assistant_gap_item', 'g')->countQuery()->execute()->fetchField(), 'No new gap item for the now-understood wording');
  }

  /**
   * Understood topic clarifiers (disambiguation menus) stay out of the queue.
   */
  public function testTopicDisambiguationDoesNotCreateGapReviewArtifacts(): void {
    $this->drupalLogin($this->regularUser);

    $response = $this->postJson('/assistant/api/message', [
      'message' => 'housing',
      'conversation_id' => '12345678-1234-4123-8123-123456789abe',
    ]);

    $this->assertSame(200, $response->getStatusCode());
    $data = json_decode((string) $response->getBody(), TRUE);
    $this->assertNotEmpty($data['options'] ?? $data['topic_suggestions'] ?? [], 'Bare area word should return a disambiguation menu');

    $this->assertSame(
      '0',
      (string) \Drupal::database()->select('assistant_gap_item', 'g')->countQuery()->execute()->fetchField()
    );
  }

  /**
   * Deterministic apply CTA traffic must not create gap-review artifacts.
   */
  public function testApplyQuickActionDoesNotCreateGapReviewArtifacts(): void {
    $this->drupalLogin($this->regularUser);
    $conversation_id = '12345678-1234-4123-8123-123456789abd';

    $response = $this->postJson('/assistant/api/message', [
      'message' => 'apply',
      'conversation_id' => $conversation_id,
      'context' => [
        'quickAction' => 'apply',
      ],
    ]);

    $this->assertSame(200, $response->getStatusCode());
    $data = json_decode((string) $response->getBody(), TRUE);
    $this->assertIsArray($data);
    $this->assertSame('apply_cta', $data['type'] ?? NULL);

    $database = \Drupal::database();
    $this->assertSame(
      '0',
      (string) $database->select('assistant_gap_item', 'g')
        ->countQuery()
        ->execute()
        ->fetchField()
    );
    $this->assertSame(
      '0',
      (string) $database->select('ilas_site_assistant_gap_hit', 'h')
        ->countQuery()
        ->execute()
        ->fetchField()
    );

    $session = $database->select('ilas_site_assistant_conversation_session', 's')
      ->fields('s', ['has_no_answer', 'has_unresolved_gap', 'latest_gap_item_id'])
      ->condition('conversation_id', $conversation_id)
      ->execute()
      ->fetchAssoc();

    $this->assertIsArray($session);
    $this->assertSame('0', (string) $session['has_no_answer']);
    $this->assertSame('0', (string) $session['has_unresolved_gap']);
    $this->assertNull($session['latest_gap_item_id']);

    $flagged_turns = $database->select('ilas_site_assistant_conversation_turn', 't')
      ->condition('conversation_id', $conversation_id)
      ->condition('is_no_answer', 1)
      ->countQuery()
      ->execute()
      ->fetchField();

    $this->assertSame('0', (string) $flagged_turns);
  }

  /**
   * Sends a JSON POST request to the given path.
   *
   * @return \Psr\Http\Message\ResponseInterface
   *   The HTTP response.
   */
  protected function postJson(string $path, array $data) {
    $url = $this->buildUrl($path);
    $cookies = $this->getSessionCookies();

    return $this->getHttpClient()->post($url, [
      'http_errors' => FALSE,
      'headers' => [
        'Content-Type' => 'application/json',
        'X-CSRF-Token' => $this->getSessionToken($cookies),
      ],
      'cookies' => $cookies,
      'body' => json_encode($data),
    ]);
  }

  /**
   * Gets a CSRF token bound to the current browser session.
   */
  protected function getSessionToken(?CookieJarInterface $cookies = NULL): string {
    $response = $this->requestBootstrap($cookies);
    return (string) $response->getBody();
  }

  /**
   * Issues a session bootstrap request with an optional cookie jar.
   *
   * @return \Psr\Http\Message\ResponseInterface
   *   The bootstrap response.
   */
  protected function requestBootstrap(?CookieJarInterface $cookies = NULL) {
    $options = ['http_errors' => FALSE];
    if ($cookies !== NULL) {
      $options['cookies'] = $cookies;
    }

    return $this->getHttpClient()->get($this->buildUrl('/assistant/api/session/bootstrap'), $options);
  }

}
