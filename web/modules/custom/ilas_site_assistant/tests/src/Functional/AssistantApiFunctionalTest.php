<?php

namespace Drupal\Tests\ilas_site_assistant\Functional;

use Drupal\Tests\BrowserTestBase;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Cookie\CookieJarInterface;

/**
 * Functional tests for Site Assistant API endpoints.
 *
 * Tests HTTP-layer concerns that Kernel tests cannot cover:
 * - CSRF token validation on POST routes
 * - Route-level permission gating
 * - JSON content-type enforcement
 * - Input validation (bad body, too-large payload, invalid JSON)
 * - Proper HTTP status codes and response headers
 *
 * @group ilas_site_assistant
 */
class AssistantApiFunctionalTest extends BrowserTestBase {

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
   * A user with admin permissions.
   *
   * @var \Drupal\user\UserInterface
   */
  protected $adminUser;

  /**
   * A regular authenticated user.
   *
   * @var \Drupal\user\UserInterface
   */
  protected $regularUser;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Create users with different permission levels.
    $this->adminUser = $this->drupalCreateUser([
      'access content',
      'administer ilas site assistant',
      'view ilas site assistant reports',
      'view ilas site assistant conversations',
    ]);

    $this->regularUser = $this->drupalCreateUser([
      'access content',
    ]);
  }

  /**
   * Tests that the message endpoint rejects requests without CSRF token.
   */
  public function testMessageEndpointRequiresCsrfToken(): void {
    $this->drupalLogin($this->regularUser);

    $response = $this->postJson('/assistant/api/message', [
      'message' => 'Hello',
    ], FALSE);

    // Without CSRF token, Drupal returns 403.
    $this->assertEquals(403, $response->getStatusCode());
  }

  /**
   * Tests that the message endpoint rejects invalid CSRF tokens.
   */
  public function testMessageEndpointRejectsInvalidCsrfToken(): void {
    $this->drupalLogin($this->regularUser);

    $response = $this->postJson('/assistant/api/message', [
      'message' => 'Hello',
    ], TRUE, 'invalid-token');

    $this->assertEquals(403, $response->getStatusCode());
  }

  /**
   * Tests that the message endpoint accepts valid requests with CSRF token.
   */
  public function testMessageEndpointWithCsrfToken(): void {
    $this->drupalLogin($this->regularUser);

    $response = $this->postJson('/assistant/api/message', [
      'message' => 'Hello',
    ], TRUE);

    $status = $response->getStatusCode();
    // Should be 200 (success) — not 403 (no CSRF) or 400 (bad request).
    $this->assertEquals(200, $status);

    $data = json_decode($response->getBody(), TRUE);
    $this->assertNotEmpty($data);
    $this->assertArrayHasKey('type', $data);
    $this->assertArrayHasKey('message', $data);
  }

  /**
   * Tests that the message endpoint rejects invalid content type.
   */
  public function testMessageEndpointRejectsInvalidContentType(): void {
    $this->drupalLogin($this->regularUser);

    $cookies = $this->getSessionCookies();
    $session_token = $this->getSessionToken($cookies);
    $url = $this->buildUrl('/assistant/api/message');

    $options = [
      'http_errors' => FALSE,
      'headers' => [
        'Content-Type' => 'text/plain',
        'X-CSRF-Token' => $session_token,
      ],
      'cookies' => $cookies,
      'body' => 'Hello',
    ];

    $response = $this->getHttpClient()->post($url, $options);
    $this->assertEquals(400, $response->getStatusCode());
  }

  /**
   * Tests that the message endpoint rejects oversized payloads.
   */
  public function testMessageEndpointRejectsOversizedPayload(): void {
    $this->drupalLogin($this->regularUser);

    // 2001 bytes exceeds the 2000 byte limit.
    $response = $this->postJson('/assistant/api/message', [
      'message' => str_repeat('a', 2001),
    ], TRUE);

    $this->assertEquals(413, $response->getStatusCode());
  }

  /**
   * Tests that the message endpoint rejects invalid JSON.
   */
  public function testMessageEndpointRejectsInvalidJson(): void {
    $this->drupalLogin($this->regularUser);

    $cookies = $this->getSessionCookies();
    $session_token = $this->getSessionToken($cookies);
    $url = $this->buildUrl('/assistant/api/message');

    $options = [
      'http_errors' => FALSE,
      'headers' => [
        'Content-Type' => 'application/json',
        'X-CSRF-Token' => $session_token,
      ],
      'cookies' => $cookies,
      'body' => '{invalid json',
    ];

    $response = $this->getHttpClient()->post($url, $options);
    $this->assertEquals(400, $response->getStatusCode());
  }

  /**
   * Tests that the message endpoint rejects empty message.
   */
  public function testMessageEndpointRejectsEmptyMessage(): void {
    $this->drupalLogin($this->regularUser);

    $response = $this->postJson('/assistant/api/message', [
      'message' => '',
    ], TRUE);

    $this->assertEquals(400, $response->getStatusCode());
  }

  /**
   * Tests that the track endpoint works without CSRF token.
   *
   * CSRF was removed from /track to avoid session creation on telemetry.
   * Origin/Referer validation + rate limiting protect the endpoint instead.
   */
  public function testTrackEndpointWithoutCsrf(): void {
    $response = $this->getHttpClient()->post($this->buildUrl('/assistant/api/track'), [
      'http_errors' => FALSE,
      'headers' => [
        'Content-Type' => 'application/json',
      ],
      'body' => json_encode([
        'event_type' => 'chat_open',
        'event_value' => '',
      ]),
    ]);
    $this->assertEquals(200, $response->getStatusCode(),
      '/track should accept POST without CSRF token');
  }

  /**
   * Tests that the track endpoint accepts valid events.
   */
  public function testTrackEndpointAcceptsValidEvent(): void {
    $this->drupalLogin($this->regularUser);

    $response = $this->postJson('/assistant/api/track', [
      'event_type' => 'chat_open',
      'event_value' => '',
    ], FALSE);

    $this->assertEquals(200, $response->getStatusCode());

    $data = json_decode($response->getBody(), TRUE);
    $this->assertTrue($data['ok']);
  }

  /**
   * Tests that the track endpoint rejects missing event_type.
   */
  public function testTrackEndpointRejectsMissingEventType(): void {
    $response = $this->getHttpClient()->post($this->buildUrl('/assistant/api/track'), [
      'http_errors' => FALSE,
      'headers' => [
        'Content-Type' => 'application/json',
      ],
      'body' => json_encode([
        'event_value' => 'some_value',
      ]),
    ]);

    $this->assertEquals(400, $response->getStatusCode());
  }

  /**
   * Tests that the suggest endpoint is accessible to anonymous users.
   */
  public function testSuggestEndpointAccessible(): void {
    // Use the authenticated session cookie jar for deterministic access context.
    $this->drupalLogin($this->regularUser);
    $cookies = $this->getSessionCookies();

    $url = $this->buildUrl('/assistant/api/suggest');
    $response = $this->getHttpClient()->get($url . '?q=housing', [
      'http_errors' => FALSE,
      'cookies' => $cookies,
    ]);

    $this->assertEquals(200, $response->getStatusCode());

    $data = json_decode($response->getBody(), TRUE);
    $this->assertArrayHasKey('suggestions', $data);
  }

  /**
   * Tests that the FAQ endpoint is accessible.
   */
  public function testFaqEndpointAccessible(): void {
    $this->drupalLogin($this->regularUser);
    $cookies = $this->getSessionCookies();

    $url = $this->buildUrl('/assistant/api/faq');
    $response = $this->getHttpClient()->get($url, [
      'http_errors' => FALSE,
      'cookies' => $cookies,
    ]);

    $this->assertEquals(200, $response->getStatusCode());

    $data = json_decode($response->getBody(), TRUE);
    $this->assertNotEmpty($data);
  }

  /**
   * Tests that the health endpoint requires proper permission.
   */
  public function testHealthEndpointPermissionCheck(): void {
    // Regular user should be denied.
    $this->drupalLogin($this->regularUser);

    $url = $this->buildUrl('/assistant/api/health');
    $response = $this->getHttpClient()->get($url, [
      'http_errors' => FALSE,
    ]);

    $this->assertEquals(403, $response->getStatusCode());
  }

  /**
   * Tests that the health endpoint is accessible to admin users.
   */
  public function testHealthEndpointAccessibleToAdmin(): void {
    $this->drupalLogin($this->adminUser);
    $cookies = $this->getSessionCookies();

    $url = $this->buildUrl('/assistant/api/health');
    $response = $this->getHttpClient()->get($url, [
      'http_errors' => FALSE,
      'cookies' => $cookies,
    ]);

    $this->assertContains(
      $response->getStatusCode(),
      [200, 503],
      'Admin health endpoint should be reachable and return healthy/degraded status'
    );

    $data = json_decode($response->getBody(), TRUE);
    $this->assertArrayHasKey('status', $data);
    $this->assertArrayHasKey('timestamp', $data);
  }

  /**
   * Tests that the metrics endpoint requires proper permission.
   */
  public function testMetricsEndpointPermissionCheck(): void {
    $this->drupalLogin($this->regularUser);

    $url = $this->buildUrl('/assistant/api/metrics');
    $response = $this->getHttpClient()->get($url, [
      'http_errors' => FALSE,
    ]);

    $this->assertEquals(403, $response->getStatusCode());
  }

  /**
   * Tests that the conversation list requires proper permission.
   */
  public function testConversationListPermissionCheck(): void {
    $this->drupalLogin($this->regularUser);

    $url = $this->buildUrl('/admin/reports/ilas-assistant/conversations');
    $response = $this->getHttpClient()->get($url, [
      'http_errors' => FALSE,
    ]);

    $this->assertEquals(403, $response->getStatusCode());
  }

  /**
   * Tests that admin users can access the conversation list.
   */
  public function testConversationListAccessibleToAdmin(): void {
    $this->drupalLogin($this->adminUser);

    $this->drupalGet('/admin/reports/ilas-assistant/conversations');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Conversation');
  }

  /**
   * Tests that the conversation detail requires UUID format.
   */
  public function testConversationDetailRequiresUuidFormat(): void {
    $this->drupalLogin($this->adminUser);

    // Invalid UUID format should return 404 (route pattern doesn't match).
    $this->drupalGet('/admin/reports/ilas-assistant/conversations/not-a-uuid');
    $this->assertSession()->statusCodeEquals(404);

    // Valid UUID format should return 200 (even if empty).
    $this->drupalGet('/admin/reports/ilas-assistant/conversations/12345678-1234-4123-8123-123456789abc');
    $this->assertSession()->statusCodeEquals(200);
  }

  /**
   * Tests that the assistant page is accessible.
   */
  public function testAssistantPageAccessible(): void {
    $this->drupalLogin($this->regularUser);

    $this->drupalGet('/assistant');
    $this->assertSession()->statusCodeEquals(200);
  }

  /**
   * Tests that responses include security headers.
   */
  public function testSecurityHeaders(): void {
    $this->drupalLogin($this->regularUser);

    $response = $this->postJson('/assistant/api/message', [
      'message' => 'Hello',
    ], TRUE);

    $this->assertEquals('nosniff', $response->getHeader('X-Content-Type-Options')[0] ?? '');
  }

  /**
   * Tests message endpoint with a conversation_id for multi-turn.
   */
  public function testMessageEndpointWithConversationId(): void {
    $this->drupalLogin($this->regularUser);

    $conv_id = '12345678-1234-4123-8123-123456789abc';

    $response = $this->postJson('/assistant/api/message', [
      'message' => 'Hello',
      'conversation_id' => $conv_id,
    ], TRUE);

    $this->assertEquals(200, $response->getStatusCode());

    $data = json_decode($response->getBody(), TRUE);
    $this->assertArrayHasKey('type', $data);
  }

  /**
   * Tests that POST /message with no Content-Type returns 400, not 500.
   *
   * Covers Fix A (F-04): Content-Type null → TypeError on PHP 8.1+.
   */
  public function testMessageEndpointRejectsNullContentType(): void {
    $this->drupalLogin($this->regularUser);

    $cookies = $this->getSessionCookies();
    $session_token = $this->getSessionToken($cookies);
    $url = $this->buildUrl('/assistant/api/message');

    // POST without Content-Type header at all.
    $options = [
      'http_errors' => FALSE,
      'headers' => [
        'X-CSRF-Token' => $session_token,
      ],
      'cookies' => $cookies,
      'body' => '{"message":"Hello"}',
    ];

    $response = $this->getHttpClient()->post($url, $options);
    $this->assertEquals(400, $response->getStatusCode(), 'Missing Content-Type should return 400, not 500');
  }

  /**
   * Tests that POST /track with no Content-Type returns 400, not 500.
   *
   * Covers Fix A (F-04): Content-Type null → TypeError on PHP 8.1+.
   */
  public function testTrackEndpointRejectsNullContentType(): void {
    $url = $this->buildUrl('/assistant/api/track');

    $options = [
      'http_errors' => FALSE,
      'body' => '{"event_type":"chat_open"}',
    ];

    $response = $this->getHttpClient()->post($url, $options);
    $this->assertEquals(400, $response->getStatusCode(), 'Missing Content-Type should return 400, not 500');
  }

  /**
   * Tests that message endpoint includes Cache-Control: no-store.
   *
   * Covers Fix B (C-06).
   */
  public function testMessageEndpointIncludesCacheControlNoStore(): void {
    $this->drupalLogin($this->regularUser);

    $response = $this->postJson('/assistant/api/message', [
      'message' => 'Hello',
    ], TRUE);

    $cache_control = $response->getHeader('Cache-Control')[0] ?? '';
    $this->assertStringContainsString('no-store', $cache_control, 'POST /message must include Cache-Control: no-store');
  }

  /**
   * Tests that track endpoint includes Cache-Control: no-store.
   *
   * Covers Fix B (C-06).
   */
  public function testTrackEndpointIncludesCacheControlNoStore(): void {
    $response = $this->getHttpClient()->post($this->buildUrl('/assistant/api/track'), [
      'http_errors' => FALSE,
      'headers' => [
        'Content-Type' => 'application/json',
      ],
      'body' => json_encode([
        'event_type' => 'chat_open',
        'event_value' => '',
      ]),
    ]);

    $cache_control = $response->getHeader('Cache-Control')[0] ?? '';
    $this->assertStringContainsString('no-store', $cache_control, 'POST /track must include Cache-Control: no-store');
  }

  /**
   * Tests that a successful message response includes request_id.
   *
   * Covers Fix D (F-28/C-07).
   */
  public function testMessageResponseIncludesRequestId(): void {
    $this->drupalLogin($this->regularUser);

    $response = $this->postJson('/assistant/api/message', [
      'message' => 'Hello',
    ], TRUE);

    $this->assertEquals(200, $response->getStatusCode());

    $data = json_decode($response->getBody(), TRUE);
    $this->assertArrayHasKey('request_id', $data, 'Response must include request_id');
    // Validate UUID v4 format.
    $this->assertMatchesRegularExpression(
      '/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/i',
      $data['request_id'],
      'request_id must be a valid UUID v4'
    );
  }

  /**
   * Tests that a track response includes request_id.
   *
   * Covers Fix D (F-28/C-07).
   */
  public function testTrackResponseIncludesRequestId(): void {
    $response = $this->getHttpClient()->post($this->buildUrl('/assistant/api/track'), [
      'http_errors' => FALSE,
      'headers' => [
        'Content-Type' => 'application/json',
      ],
      'body' => json_encode([
        'event_type' => 'chat_open',
        'event_value' => '',
      ]),
    ]);

    $this->assertEquals(200, $response->getStatusCode());

    $data = json_decode($response->getBody(), TRUE);
    $this->assertArrayHasKey('request_id', $data, 'Track response must include request_id');
    $this->assertMatchesRegularExpression(
      '/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/i',
      $data['request_id']
    );
  }

  /**
   * Tests that a 400 error response still includes request_id.
   *
   * Covers Fix D (F-28/C-07).
   */
  public function testMessageErrorResponseIncludesRequestId(): void {
    $this->drupalLogin($this->regularUser);

    // Send invalid content type to trigger 400.
    $cookies = $this->getSessionCookies();
    $session_token = $this->getSessionToken($cookies);
    $url = $this->buildUrl('/assistant/api/message');

    $options = [
      'http_errors' => FALSE,
      'headers' => [
        'Content-Type' => 'text/plain',
        'X-CSRF-Token' => $session_token,
      ],
      'cookies' => $cookies,
      'body' => 'Hello',
    ];

    $response = $this->getHttpClient()->post($url, $options);
    $this->assertEquals(400, $response->getStatusCode());

    $data = json_decode($response->getBody(), TRUE);
    $this->assertArrayHasKey('request_id', $data, 'Error responses must include request_id');
    $this->assertMatchesRegularExpression(
      '/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/i',
      $data['request_id']
    );
  }

  /**
   * Tests anonymous POST to /message without CSRF token returns 403.
   *
   * IMP-SEC-01 CSRF Auth Matrix row:
   *   Anonymous | No session/token | No token | 403 (forbidden)
   */
  public function testAnonymousMessageEndpointRequiresCsrfToken(): void {
    $response = $this->postJsonAnonymous('/assistant/api/message', [
      'message' => 'Hello',
    ]);

    $this->assertEquals(403, $response->getStatusCode(), 'Anonymous POST without CSRF token should return 403');
  }

  /**
   * Tests anonymous POST to /message with invalid CSRF token returns 403.
   *
   * IMP-SEC-01 CSRF Auth Matrix row:
   *   Anonymous | No session/token | Invalid token | 403 (forbidden)
   */
  public function testAnonymousMessageEndpointRejectsInvalidCsrfToken(): void {
    $response = $this->postJsonAnonymous('/assistant/api/message', [
      'message' => 'Hello',
    ], 'invalid-token');

    $this->assertEquals(403, $response->getStatusCode(), 'Anonymous POST with invalid CSRF token should return 403');
  }

  /**
   * Tests anonymous POST to /message with valid CSRF token returns 200.
   *
   * IMP-SEC-01 CSRF Auth Matrix row:
   *   Anonymous | Session cookie | Valid token | 200 (allowed)
   */
  public function testAnonymousMessageEndpointAllowsValidCsrfToken(): void {
    [$cookies, $token] = $this->getAnonymousSessionCookiesAndToken();

    $response = $this->postJsonAnonymous('/assistant/api/message', [
      'message' => 'Hello',
    ], $token, $cookies);

    $this->assertEquals(200, $response->getStatusCode(), 'Anonymous POST with valid CSRF token should return 200');
  }

  /**
   * Tests anonymous POST to /track succeeds without CSRF token.
   *
   * CSRF was removed from /track to avoid unnecessary session creation.
   * Origin/Referer validation + rate limiting protect the endpoint instead.
   */
  public function testAnonymousTrackEndpointWithoutCsrf(): void {
    $response = $this->postJsonAnonymous('/assistant/api/track', [
      'event_type' => 'chat_open',
      'event_value' => '',
    ]);

    $this->assertEquals(200, $response->getStatusCode(), 'Anonymous POST to /track should succeed without CSRF token');
    $data = json_decode($response->getBody(), TRUE);
    $this->assertTrue($data['ok']);
  }

  /**
   * Sends a JSON POST request as anonymous.
   *
   * @param string $path
   *   The path to POST to.
   * @param array $data
   *   The data to send as JSON.
   * @param string|null $csrfToken
   *   Optional CSRF token header value.
   * @param \GuzzleHttp\Cookie\CookieJarInterface|null $cookies
   *   Optional cookie jar to bind token + POST to the same session.
   *
   * @return \Psr\Http\Message\ResponseInterface
   *   The response.
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
   * Sends a JSON POST request to the given path.
   *
   * @param string $path
   *   The path to POST to.
   * @param array $data
   *   The data to send as JSON.
   * @param bool $include_csrf
   *   Whether to include a CSRF token.
   * @param string|null $csrf_token
   *   Optional CSRF token override.
   *
   * @return \Psr\Http\Message\ResponseInterface
   *   The response.
   */
  protected function postJson(string $path, array $data, bool $include_csrf = TRUE, ?string $csrf_token = NULL) {
    $url = $this->buildUrl($path);
    $cookies = $this->getSessionCookies();

    $headers = [
      'Content-Type' => 'application/json',
    ];

    if ($include_csrf) {
      $headers['X-CSRF-Token'] = $csrf_token ?? $this->getSessionToken($cookies);
    }

    return $this->getHttpClient()->post($url, [
      'http_errors' => FALSE,
      'headers' => $headers,
      'cookies' => $cookies,
      'body' => json_encode($data),
    ]);
  }

  /**
   * Gets a CSRF session token from Drupal.
   *
   * @return string
   *   The session token.
   */
  protected function getSessionToken(?CookieJarInterface $cookies = NULL): string {
    $url = $this->buildUrl('/session/token');
    $options = [
      'http_errors' => FALSE,
    ];
    if ($cookies !== NULL) {
      $options['cookies'] = $cookies;
    }
    $response = $this->getHttpClient()->get($url, $options);
    return (string) $response->getBody();
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
   * Primes an anonymous cookie jar by fetching a session token.
   *
   * The assistant page no longer embeds CSRF tokens (to allow page caching).
   * Instead, the widget fetches /session/token lazily before the first POST.
   * This helper establishes the session cookie for anonymous CSRF tests.
   */
  protected function primeAnonymousSession(CookieJarInterface $cookies): void {
    $response = $this->getHttpClient()->get($this->buildUrl('/session/token'), [
      'http_errors' => FALSE,
      'cookies' => $cookies,
    ]);
    $this->assertEquals(200, $response->getStatusCode(),
      '/session/token must be accessible to establish anonymous session');
  }

}
