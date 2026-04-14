<?php

namespace Drupal\Tests\ilas_site_assistant\Functional;

use Drupal\Tests\BrowserTestBase;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Cookie\CookieJarInterface;

/**
 * Multi-turn controller-path functional tests.
 *
 * Tests clarify-loop escalation, history-based intent fallback, and
 * session-fingerprint ownership through the real HTTP stack with LLM disabled.
 *
 * Routing assumptions:
 * - "asdfghjkl" produces no intent match, yielding a clarify response.
 * - "I need help with housing" routes to a housing topic intent.
 * - "tell me more about that" triggers TURN_FOLLOW_UP (anaphoric) and returns
 *   unknown from IntentRouter, activating HistoryIntentResolver.
 *
 * @group ilas_site_assistant
 */
class AssistantMultiTurnFunctionalTest extends BrowserTestBase {

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
    'node',
    'taxonomy',
    'user',
    'views',
    'search_api',
    'search_api_db',
    'paragraphs',
    'ilas_site_assistant',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Pin LLM to disabled so FallbackGate returns DECISION_CLARIFY for
    // unknown intents — gives deterministic clarify without a real backend.
    // Disable FAQ retrieval because search_api indexes are not populated
    // in BrowserTestBase (no content nodes, missing paragraph field
    // definitions). Disable analytics logging to avoid DB writes to the
    // stats table for cleaner test isolation.
    \Drupal::configFactory()->getEditable('ilas_site_assistant.settings')
      ->set('llm.enabled', FALSE)
      ->set('enable_faq', FALSE)
      ->set('enable_resources', FALSE)
      ->set('enable_logging', FALSE)
      ->save();
  }

  /**
   * Three identical clarify turns trigger loop-break; counter resets after.
   *
   * Controller path: applyClarifyLoopGuard() at line ~4851.
   * CLARIFY_LOOP_THRESHOLD = 3.
   * The guard hashes the normalized *response* message (not user input).
   * After loop-break fires, clarify_count resets to 0.
   */
  public function testClarifyLoopEscalation(): void {
    $this->clearMessageFloodEvents();
    [$cookies, $token] = $this->getAnonymousSessionCookiesAndToken();
    $convId = \Drupal::service('uuid')->generate();

    // Turn 1: nonsense → clarify, not loop-break.
    $r1 = $this->sendAnonymousMessage($cookies, $token, 'asdfghjkl', $convId);
    $this->assertSame('clarify', $r1['response_mode'] ?? '', 'Turn 1 must be a clarify response');
    $this->assertNotEquals('clarify_loop_break', $r1['type'], 'Turn 1 must not be a loop break');

    // Turn 2: same nonsense → still below threshold.
    $r2 = $this->sendAnonymousMessage($cookies, $token, 'asdfghjkl', $convId);
    $this->assertNotEquals('clarify_loop_break', $r2['type'], 'Turn 2 must not be a loop break');

    // Turn 3: threshold reached → loop-break.
    $r3 = $this->sendAnonymousMessage($cookies, $token, 'asdfghjkl', $convId);
    $this->assertSame('clarify_loop_break', $r3['type'], 'Turn 3 must trigger clarify_loop_break (threshold = 3)');
    $this->assertSame('clarify_loop_break', $r3['reason_code'] ?? '', 'reason_code must be clarify_loop_break');
    $this->assertArrayHasKey('topic_suggestions', $r3, 'Loop-break must include topic_suggestions');
    $this->assertArrayHasKey('actions', $r3, 'Loop-break must include escalation actions');

    // Turn 4: counter was reset → NOT loop-break.
    $r4 = $this->sendAnonymousMessage($cookies, $token, 'asdfghjkl', $convId);
    $this->assertNotEquals('clarify_loop_break', $r4['type'], 'Turn 4 must not be a loop break (counter resets after break)');
  }

  /**
   * Follow-up message resolves intent from conversation history.
   *
   * Turn 1 routes to housing topic. Turn 2 ("tell me more about that") is
   * classified as TURN_FOLLOW_UP, IntentRouter returns unknown, and
   * HistoryIntentResolver carries the housing context forward.
   *
   * Proof: without history, "tell me more about that" always produces a
   * clarify when LLM is disabled and IntentRouter returns unknown.
   */
  public function testHistoryIntentFallback(): void {
    $this->clearMessageFloodEvents();
    [$cookies, $token] = $this->getAnonymousSessionCookiesAndToken();
    $convId = \Drupal::service('uuid')->generate();

    // Turn 1: housing topic — should NOT be a clarify.
    $r1 = $this->sendAnonymousMessage($cookies, $token, 'I need help with housing', $convId);
    $this->assertNotSame('clarify', $r1['response_mode'] ?? '', 'Turn 1 must not be a generic clarify — housing should route to a topic');

    // Turn 2: anaphoric follow-up — should resolve from history, not clarify.
    $r2 = $this->sendAnonymousMessage($cookies, $token, 'tell me more about that', $convId);
    $this->assertNotEquals('clarify_loop_break', $r2['type'] ?? '', 'Turn 2 must not be a loop break');

    // The critical assertion: if history fallback worked, this is NOT a
    // bare clarify. Without history, "tell me more about that" always
    // produces clarify when LLM is disabled and IntentRouter returns unknown.
    $isClarify = ($r2['response_mode'] ?? '') === 'clarify'
      && in_array($r2['type'] ?? '', ['clarify', 'disambiguation', 'fallback'], TRUE);
    $this->assertFalse($isClarify, 'Turn 2 must not be a bare clarify — history fallback should have resolved the intent');
  }

  /**
   * Session fingerprint prevents cross-session history leakage.
   *
   * Session A writes history + fingerprint to conversation cache.
   * Session B reuses the same conversation_id but has a different session ID,
   * producing a different SHA-256 fingerprint. The controller detects the
   * mismatch and does NOT load history, so Session B gets no context carry.
   *
   * Proof structure: Session A sends "I need help with housing" (Turn 1),
   * then Session A sends "tell me more about that" (Turn 2) — which resolves
   * from history. Session B then sends "tell me more about that" (Turn 3) on
   * the SAME conversation_id. If the fingerprint guard works, Session B sees
   * no history and gets a different response than Session A's Turn 2.
   *
   * Mechanism: TurnClassifier::detectFollowUp() returns FALSE immediately when
   * $server_history is empty (TurnClassifier.php line 196). Blocking history
   * via the fingerprint guard therefore forces TURN_NEW classification, which
   * causes the controller to omit 'turn_type' from the response (line 2781).
   * Differential proof: Session A Turn 2 MUST have turn_type=FOLLOW_UP (proving
   * history was loaded), Session B Turn 3 must NOT have turn_type (proving
   * history was blocked). Both halves must hold for the assertion to be
   * non-vacuous.
   */
  public function testSessionFingerprintOwnership(): void {
    $this->clearMessageFloodEvents();
    $convId = \Drupal::service('uuid')->generate();

    // Session A: establish housing context and confirm follow-up works.
    [$cookiesA, $tokenA] = $this->getAnonymousSessionCookiesAndToken();
    $r1 = $this->sendAnonymousMessage($cookiesA, $tokenA, 'I need help with housing', $convId);
    $this->assertArrayHasKey('type', $r1, 'Session A Turn 1 must return a valid response');

    $r2 = $this->sendAnonymousMessage($cookiesA, $tokenA, 'tell me more about that', $convId);
    // Positive anchor: Session A Turn 2 must be TURN_FOLLOW_UP, proving history
    // WAS loaded. Without this assertion the Session B proof below is vacuous —
    // if history never loaded for Session A either, both sessions would lack
    // turn_type and the fingerprint guard would appear to "work" by accident.
    $this->assertSame(
      'FOLLOW_UP',
      $r2['turn_type'] ?? '',
      'Session A Turn 2 must be classified TURN_FOLLOW_UP (requires non-empty history)'
    );
    $r2Mode = $r2['response_mode'] ?? '';

    // Session B: separate anonymous session.
    [$cookiesB, $tokenB] = $this->getAnonymousSessionCookiesAndToken();

    // Verify the sessions are distinct.
    $sessionCookieA = $this->findDrupalSessionCookie($cookiesA);
    $sessionCookieB = $this->findDrupalSessionCookie($cookiesB);
    $this->assertNotNull($sessionCookieA, 'Session A must have a Drupal session cookie');
    $this->assertNotNull($sessionCookieB, 'Session B must have a Drupal session cookie');
    $this->assertNotEquals(
      $sessionCookieA['Value'],
      $sessionCookieB['Value'],
      'Sessions A and B must have different session IDs'
    );

    // Session B sends the SAME follow-up on the SAME conversation_id.
    // Fingerprint mismatch → no history → different routing path.
    $r3 = $this->sendAnonymousMessage($cookiesB, $tokenB, 'tell me more about that', $convId);
    $r3Mode = $r3['response_mode'] ?? '';

    // The fingerprint guard is proven if Session B's response_mode or type
    // differs from Session A's Turn 2 — demonstrating that Session B did
    // NOT receive Session A's conversation history.
    $sessionBMatchesA = ($r3Mode === $r2Mode) && ($r3['type'] === $r2['type']);
    // If Session A got a history-resolved topic response and Session B also
    // got the exact same response, history leaked. But if they differ, or
    // Session B got a clarify/different routing, the guard worked.
    // Additional check: Session B's turn_type should NOT be 'FOLLOW_UP'
    // (which requires server_history), proving fingerprint blocked history.
    $this->assertArrayNotHasKey('turn_type', $r3,
      'Session B must not have turn_type set (FOLLOW_UP requires history, which fingerprint guard should block)');
  }

  // -----------------------------------------------------------------------
  // Helpers: duplicated from AssistantApiFunctionalTest (trait extraction
  // deferred until 5+ multi-turn methods are stable).
  // -----------------------------------------------------------------------

  /**
   * Sends a message and asserts HTTP 200, returns decoded JSON.
   */
  protected function sendAnonymousMessage(CookieJarInterface $cookies, string $token, string $message, ?string $conversationId = NULL): array {
    $payload = ['message' => $message];
    if ($conversationId !== NULL) {
      $payload['conversation_id'] = $conversationId;
    }
    $response = $this->postJsonAnonymous('/assistant/api/message', $payload, $token, $cookies);
    $this->assertEquals(200, $response->getStatusCode(),
      'Message request must return 200; got ' . $response->getStatusCode() . ': ' . $response->getBody());
    $data = json_decode($response->getBody(), TRUE);
    $this->assertIsArray($data, 'Response body must be valid JSON');
    return $data;
  }

  /**
   * Sends a JSON POST as anonymous with optional CSRF and cookies.
   */
  protected function postJsonAnonymous(string $path, array $data, ?string $csrfToken = NULL, ?CookieJarInterface $cookies = NULL) {
    $url = $this->buildUrl($path);
    $headers = [
      'Content-Type' => 'application/json',
    ];
    if ($csrfToken !== NULL) {
      $headers['X-CSRF-Token'] = $csrfToken;
    }

    $options = [
      'http_errors' => FALSE,
      'headers' => $headers,
      'body' => json_encode($data),
    ];
    if ($cookies !== NULL) {
      $options['cookies'] = $cookies;
    }

    return $this->getHttpClient()->post($url, $options);
  }

  /**
   * Returns anonymous session cookies and a bound CSRF token.
   */
  protected function getAnonymousSessionCookiesAndToken(): array {
    $cookies = new CookieJar();
    $this->primeAnonymousSession($cookies);
    $this->assertNotEmpty($cookies->toArray(), 'Anonymous session priming must issue a session cookie');

    return [
      $cookies,
      $this->getSessionToken($cookies),
    ];
  }

  /**
   * Gets a CSRF session token from the bootstrap endpoint.
   */
  protected function getSessionToken(?CookieJarInterface $cookies = NULL): string {
    $response = $this->requestBootstrap($cookies);
    return (string) $response->getBody();
  }

  /**
   * Primes an anonymous cookie jar via session bootstrap.
   */
  protected function primeAnonymousSession(CookieJarInterface $cookies): void {
    $response = $this->requestBootstrap($cookies);
    $this->assertEquals(200, $response->getStatusCode(),
      '/assistant/api/session/bootstrap must be accessible to establish anonymous session');
  }

  /**
   * Issues a bootstrap GET request with optional cookie jar.
   */
  protected function requestBootstrap(?CookieJarInterface $cookies = NULL) {
    $options = [
      'http_errors' => FALSE,
    ];
    if ($cookies !== NULL) {
      $options['cookies'] = $cookies;
    }

    return $this->getHttpClient()->get($this->buildUrl('/assistant/api/session/bootstrap'), $options);
  }

  /**
   * Finds the Drupal session cookie in a cookie jar.
   */
  protected function findDrupalSessionCookie(CookieJarInterface $cookies): ?array {
    foreach ($cookies->toArray() as $cookie) {
      $name = (string) ($cookie['Name'] ?? '');
      if (str_starts_with($name, 'SESS') || str_starts_with($name, 'SSESS')) {
        return $cookie;
      }
    }

    return NULL;
  }

  /**
   * Clears message-endpoint flood events to prevent rate-limit interference.
   */
  protected function clearMessageFloodEvents(): void {
    $database = \Drupal::database();
    if (!$database->schema()->tableExists('flood')) {
      return;
    }

    $database->delete('flood')
      ->condition('event', [
        'ilas_assistant_min',
        'ilas_assistant_hr',
      ], 'IN')
      ->execute();
  }

}
