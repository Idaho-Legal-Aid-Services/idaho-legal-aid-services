<?php

namespace Drupal\Tests\ilas_site_assistant\Functional;

use Drupal\Core\Site\Settings;
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
    'token',
    'paragraphs',
    'ilas_site_assistant',
    'ilas_site_assistant_governance',
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
  protected function tearDown(): void {
    new Settings([]);
    putenv('ILAS_ASSISTANT_DIAGNOSTICS_TOKEN');
    unset($_ENV['ILAS_ASSISTANT_DIAGNOSTICS_TOKEN']);
    parent::tearDown();
  }

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
      'view assistant governance conversations',
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
   * Tests that missing-browser-proof track requests are denied.
   */
  public function testTrackEndpointWithoutBrowserProofReturnsTrackProofMissing(): void {
    $response = $this->postTrack([
      'event_type' => 'chat_open',
      'event_value' => '',
    ], [
      'Content-Type' => 'application/json',
    ]);

    $this->assertEquals(403, $response->getStatusCode(),
      '/track should deny POST when Origin and Referer are both missing');
    $data = json_decode($response->getBody(), TRUE);
    $this->assertIsArray($data);
    $this->assertSame('Forbidden', $data['error'] ?? NULL);
    $this->assertSame('track_proof_missing', $data['error_code'] ?? NULL);
    $this->assertArrayHasKey('request_id', $data);
  }

  /**
   * Tests that track endpoint rejects cross-origin Origin headers.
   */
  public function testTrackEndpointRejectsCrossOriginOriginHeader(): void {
    $response = $this->postTrack([
      'event_type' => 'chat_open',
      'event_value' => '',
    ], [
      'Content-Type' => 'application/json',
      'Origin' => 'https://evil.example',
    ]);

    $this->assertEquals(403, $response->getStatusCode(), 'Cross-origin Origin must be denied');
    $data = json_decode($response->getBody(), TRUE);
    $this->assertIsArray($data);
    $this->assertSame('Forbidden', $data['error'] ?? NULL);
    $this->assertSame('track_origin_mismatch', $data['error_code'] ?? NULL);
    $this->assertArrayHasKey('request_id', $data);
  }

  /**
   * Tests that track endpoint allows same-origin Origin headers.
   */
  public function testTrackEndpointAllowsSameOriginOriginHeader(): void {
    $response = $this->postTrack([
      'event_type' => 'chat_open',
      'event_value' => '',
    ], $this->validTrackHeaders());

    $this->assertEquals(200, $response->getStatusCode(), 'Same-origin Origin must be allowed');
    $data = json_decode($response->getBody(), TRUE);
    $this->assertIsArray($data);
    $this->assertTrue($data['ok'] ?? FALSE);
  }

  /**
   * Tests that track endpoint allows same-origin Referer when Origin is absent.
   */
  public function testTrackEndpointAllowsSameOriginRefererHeader(): void {
    $response = $this->postTrack([
      'event_type' => 'chat_open',
      'event_value' => '',
    ], [
      'Content-Type' => 'application/json',
      'Referer' => $this->siteOrigin() . '/assistant',
    ]);

    $this->assertEquals(200, $response->getStatusCode(), 'Same-origin Referer must be allowed');
    $data = json_decode($response->getBody(), TRUE);
    $this->assertIsArray($data);
    $this->assertTrue($data['ok'] ?? FALSE);
  }

  /**
   * Tests that track endpoint rejects cross-origin Referer when Origin is absent.
   */
  public function testTrackEndpointRejectsCrossOriginRefererHeader(): void {
    $response = $this->postTrack([
      'event_type' => 'chat_open',
      'event_value' => '',
    ], [
      'Content-Type' => 'application/json',
      'Referer' => 'https://evil.example/assistant',
    ]);

    $this->assertEquals(403, $response->getStatusCode(), 'Cross-origin Referer must be denied');
    $data = json_decode($response->getBody(), TRUE);
    $this->assertIsArray($data);
    $this->assertSame('Forbidden', $data['error'] ?? NULL);
    $this->assertSame('track_origin_mismatch', $data['error_code'] ?? NULL);
    $this->assertArrayHasKey('request_id', $data);
  }

  /**
   * Tests that missing-browser-proof requests can recover with bootstrap token.
   */
  public function testTrackEndpointAllowsBootstrapTokenWhenBrowserHeadersMissing(): void {
    [$cookies, $token] = $this->getAnonymousSessionCookiesAndToken();

    $response = $this->postJsonAnonymous('/assistant/api/track', [
      'event_type' => 'chat_open',
      'event_value' => '',
    ], $token, $cookies);

    $this->assertEquals(200, $response->getStatusCode(), 'Missing browser proof may recover with bootstrap token');
    $data = json_decode($response->getBody(), TRUE);
    $this->assertIsArray($data);
    $this->assertTrue($data['ok'] ?? FALSE);
  }

  /**
   * Tests that invalid bootstrap token fallback is denied.
   */
  public function testTrackEndpointRejectsInvalidBootstrapTokenWhenBrowserHeadersMissing(): void {
    [$cookies] = $this->getAnonymousSessionCookiesAndToken();

    $response = $this->postJsonAnonymous('/assistant/api/track', [
      'event_type' => 'chat_open',
      'event_value' => '',
    ], 'deliberately-invalid-token', $cookies);

    $this->assertEquals(403, $response->getStatusCode(), 'Invalid bootstrap token must be denied');
    $data = json_decode($response->getBody(), TRUE);
    $this->assertIsArray($data);
    $this->assertSame('Forbidden', $data['error'] ?? NULL);
    $this->assertSame('track_proof_invalid', $data['error_code'] ?? NULL);
    $this->assertArrayHasKey('request_id', $data);
  }

  /**
   * Tests end-to-end /track recovery after missing browser proof.
   */
  public function testTrackEndpointRecoveryWithFreshBootstrapToken(): void {
    $response = $this->postJsonAnonymous('/assistant/api/track', [
      'event_type' => 'chat_open',
      'event_value' => '',
    ]);
    $this->assertEquals(403, $response->getStatusCode());
    $data = json_decode($response->getBody(), TRUE);
    $this->assertIsArray($data);
    $this->assertSame('track_proof_missing', $data['error_code'] ?? NULL);

    [$cookies, $token] = $this->getAnonymousSessionCookiesAndToken();
    $response = $this->postJsonAnonymous('/assistant/api/track', [
      'event_type' => 'chat_open',
      'event_value' => '',
    ], $token, $cookies);
    $this->assertEquals(200, $response->getStatusCode(), 'Track recovery with bootstrap token should succeed');
  }

  /**
   * Tests that the track endpoint accepts valid events.
   */
  public function testTrackEndpointAcceptsValidEvent(): void {
    $this->drupalLogin($this->regularUser);

    $response = $this->postTrack([
      'event_type' => 'chat_open',
      'event_value' => '',
    ], $this->validTrackHeaders());

    $this->assertEquals(200, $response->getStatusCode());

    $data = json_decode($response->getBody(), TRUE);
    $this->assertTrue($data['ok']);
  }

  /**
   * Tests that the track endpoint accepts ui_troubleshooting events.
   */
  public function testTrackEndpointAcceptsUiTroubleshootingEvent(): void {
    $response = $this->postTrack([
      'event_type' => 'ui_troubleshooting',
      'event_value' => 'displayed',
    ], $this->validTrackHeaders());

    $this->assertEquals(200, $response->getStatusCode());
    $data = json_decode($response->getBody(), TRUE);
    $this->assertIsArray($data);
    $this->assertTrue($data['ok'] ?? FALSE);
  }

  /**
   * Tests that the track endpoint accepts ui_fallback_used events.
   */
  public function testTrackEndpointAcceptsUiFallbackUsedEvent(): void {
    $response = $this->postTrack([
      'event_type' => 'ui_fallback_used',
      'event_value' => 'forms_inventory',
    ], $this->validTrackHeaders());

    $this->assertEquals(200, $response->getStatusCode());
    $data = json_decode($response->getBody(), TRUE);
    $this->assertIsArray($data);
    $this->assertTrue($data['ok'] ?? FALSE);
  }

  /**
   * Tests that the track endpoint rejects missing event_type.
   */
  public function testTrackEndpointRejectsMissingEventType(): void {
    $response = $this->postTrack([
      'event_value' => 'some_value',
    ], $this->validTrackHeaders());

    $this->assertEquals(400, $response->getStatusCode());
  }

  /**
   * Tests that the suggest endpoint is accessible to anonymous users.
   */
  public function testSuggestEndpointAccessible(): void {
    $response = $this->getJson('/assistant/api/suggest?q=housing&type=all');

    $this->assertEquals(200, $response->getStatusCode());
    $this->assertReadJsonHeaders($response);

    $data = json_decode($response->getBody(), TRUE);
    $this->assertSame(['suggestions'], array_keys($data));
    foreach ($data['suggestions'] as $suggestion) {
      $this->assertSuggestionPublicFields($suggestion);
    }
  }

  /**
   * Tests that the FAQ endpoint is accessible.
   */
  public function testFaqEndpointAccessible(): void {
    $response = $this->getJson('/assistant/api/faq?q=eviction');

    $this->assertEquals(200, $response->getStatusCode());
    $this->assertReadJsonHeaders($response);

    $data = json_decode($response->getBody(), TRUE);
    $this->assertSame(['results', 'count'], array_keys($data));
    foreach ($data['results'] as $result) {
      $this->assertFaqResultPublicFields($result);
    }
  }

  /**
   * Tests that FAQ category browse remains anonymously accessible.
   */
  public function testFaqCategoriesEndpointAccessible(): void {
    $response = $this->getJson('/assistant/api/faq');

    $this->assertEquals(200, $response->getStatusCode());
    $this->assertReadJsonHeaders($response);

    $data = json_decode($response->getBody(), TRUE);
    $this->assertSame(['categories'], array_keys($data));
    foreach ($data['categories'] as $category) {
      $this->assertFaqCategoryPublicFields($category);
    }
  }

  /**
   * Repeated suggest requests are bounded by explicit read-endpoint throttling.
   */
  public function testSuggestEndpointRateLimitAppliesToRepeatedGetRequests(): void {
    $this->setReadEndpointThresholds(1, 10, 60, 600);
    $this->clearReadEndpointFloodEvents();

    $allowed = $this->getJson('/assistant/api/suggest?q=housing&type=all');
    $this->assertEquals(200, $allowed->getStatusCode(), 'Initial suggest request must succeed');

    // Vary the query to avoid Drupal serving a cached 200 before the controller
    // and flood guard can execute.
    $limited = $this->getJson('/assistant/api/suggest?q=tenant&type=all');

    $this->assertEquals(429, $limited->getStatusCode(), 'Second suggest request must be rate limited with a 1/min threshold');
    $this->assertReadJsonHeaders($limited, TRUE);
    $this->assertSame('60', $limited->getHeader('Retry-After')[0] ?? NULL);
    $body = json_decode($limited->getBody(), TRUE);
    $this->assertSame([], $body['suggestions'] ?? NULL);
    $this->assertSame('rate_limit', $body['type'] ?? NULL);
    $this->assertArrayHasKey('request_id', $body);
    $this->assertSame($body['request_id'], $limited->getHeader('X-Correlation-ID')[0] ?? NULL);
  }

  /**
   * Repeated FAQ requests are bounded by explicit read-endpoint throttling.
   */
  public function testFaqEndpointRateLimitAppliesToRepeatedGetRequests(): void {
    $this->setReadEndpointThresholds(120, 1200, 1, 10);
    $this->clearReadEndpointFloodEvents();

    $allowed = $this->getJson('/assistant/api/faq?q=eviction');
    $this->assertEquals(200, $allowed->getStatusCode(), 'Initial FAQ request must succeed');

    // Vary the query to avoid Drupal serving a cached 200 before the controller
    // and flood guard can execute.
    $limited = $this->getJson('/assistant/api/faq?q=tenant');

    $this->assertEquals(429, $limited->getStatusCode(), 'Second FAQ request must be rate limited with a 1/min threshold');
    $this->assertReadJsonHeaders($limited, TRUE);
    $this->assertSame('60', $limited->getHeader('Retry-After')[0] ?? NULL);
    $body = json_decode($limited->getBody(), TRUE);
    $this->assertSame([], $body['results'] ?? NULL);
    $this->assertSame(0, $body['count'] ?? NULL);
    $this->assertSame('rate_limit', $body['type'] ?? NULL);
    $this->assertArrayHasKey('request_id', $body);
    $this->assertSame($body['request_id'], $limited->getHeader('X-Correlation-ID')[0] ?? NULL);
  }

  /**
   * Tests that anonymous callers cannot read health without machine auth.
   */
  public function testHealthEndpointAnonymousWithoutTokenReturnsAccessDenied(): void {
    $response = $this->getJson('/assistant/api/health');

    $this->assertEquals(403, $response->getStatusCode());
    $data = json_decode($response->getBody(), TRUE);
    $this->assertSame(TRUE, $data['error'] ?? NULL);
    $this->assertSame('access_denied', $data['error_code'] ?? NULL);
    $this->assertSame('Access denied', $data['message'] ?? NULL);
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
   * Tests that the health endpoint is accessible with a valid machine header.
   */
  public function testHealthEndpointAccessibleWithValidMachineHeader(): void {
    $this->configureDiagnosticsToken('functional-health-token');

    $response = $this->getJson('/assistant/api/health', NULL, [
      'X-ILAS-Observability-Key' => 'functional-health-token',
    ]);

    $this->assertContains(
      $response->getStatusCode(),
      [200, 503],
      'Machine-auth health endpoint should be reachable and return healthy/degraded status'
    );

    $data = json_decode($response->getBody(), TRUE);
    $this->assertArrayHasKey('status', $data);
    $this->assertArrayHasKey('timestamp', $data);
    $this->assertArrayHasKey('checks', $data);
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
    $this->assertArrayHasKey('checks', $data);
    $this->assertArrayHasKey('proxy_trust', $data['checks']);
  }

  /**
   * Tests that anonymous callers cannot read metrics without machine auth.
   */
  public function testMetricsEndpointAnonymousWithoutTokenReturnsAccessDenied(): void {
    $response = $this->getJson('/assistant/api/metrics');

    $this->assertEquals(403, $response->getStatusCode());
    $data = json_decode($response->getBody(), TRUE);
    $this->assertSame(TRUE, $data['error'] ?? NULL);
    $this->assertSame('access_denied', $data['error_code'] ?? NULL);
    $this->assertSame('Access denied', $data['message'] ?? NULL);
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
   * Tests that the metrics endpoint is accessible with a valid machine header.
   */
  public function testMetricsEndpointAccessibleWithValidMachineHeader(): void {
    $this->configureDiagnosticsToken('functional-metrics-token');

    $response = $this->getJson('/assistant/api/metrics', NULL, [
      'X-ILAS-Observability-Key' => 'functional-metrics-token',
    ]);

    $this->assertEquals(200, $response->getStatusCode());

    $data = json_decode($response->getBody(), TRUE);
    $this->assertArrayHasKey('timestamp', $data);
    $this->assertArrayHasKey('metrics', $data);
    $this->assertArrayHasKey('proxy_trust', $data);
    $this->assertArrayHasKey('thresholds', $data);
    $this->assertArrayHasKey('cron', $data);
    $this->assertArrayHasKey('queue', $data);
  }

  /**
   * Tests that the metrics endpoint is accessible to admin users.
   */
  public function testMetricsEndpointAccessibleToAdmin(): void {
    $this->drupalLogin($this->adminUser);
    $cookies = $this->getSessionCookies();

    $url = $this->buildUrl('/assistant/api/metrics');
    $response = $this->getHttpClient()->get($url, [
      'http_errors' => FALSE,
      'cookies' => $cookies,
    ]);

    $this->assertEquals(200, $response->getStatusCode());

    $data = json_decode($response->getBody(), TRUE);
    $this->assertArrayHasKey('timestamp', $data);
    $this->assertArrayHasKey('metrics', $data);
    $this->assertArrayHasKey('proxy_trust', $data);
    $this->assertArrayHasKey('thresholds', $data);
    $this->assertArrayHasKey('cron', $data);
    $this->assertArrayHasKey('queue', $data);
    $this->assertArrayHasKey('session_bootstrap', $data['metrics']);
    $this->assertArrayHasKey('new_session_requests', $data['metrics']['session_bootstrap']);
    $this->assertArrayHasKey('rate_limited_requests', $data['metrics']['session_bootstrap']);
    $this->assertArrayHasKey('session_bootstrap', $data['thresholds']);
    $this->assertArrayHasKey('rate_limit_per_minute', $data['thresholds']['session_bootstrap']);
    $this->assertArrayHasKey('rate_limit_per_hour', $data['thresholds']['session_bootstrap']);
    $this->assertArrayHasKey('observation_window_hours', $data['thresholds']['session_bootstrap']);
  }

  /**
   * Tests that the admin report dashboard requires proper permission.
   */
  public function testAdminReportDashboardPermissionCheck(): void {
    $this->drupalLogin($this->regularUser);

    $this->drupalGet('/admin/reports/ilas-assistant');
    $this->assertSession()->statusCodeEquals(403);
  }

  /**
   * Tests that admin users can access the admin report dashboard.
   */
  public function testAdminReportDashboardAccessibleToAdmin(): void {
    $this->drupalLogin($this->adminUser);

    $this->drupalGet('/admin/reports/ilas-assistant');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Summary Statistics');
    $this->assertSession()->pageTextContains('Top Topics Selected');
    $this->assertSession()->pageTextContains('Top Resource Links Clicked');
    $this->assertSession()->pageTextNotContains('Content Gaps' . ' (No-Answer Queries)');
  }

  /**
   * Tests that the conversation list requires proper permission.
   */
  public function testConversationListPermissionCheck(): void {
    $this->drupalLogin($this->regularUser);

    $url = $this->buildUrl('/admin/reports/ilas-assistant/governance/conversations');
    $response = $this->getHttpClient()->get($url, [
      'http_errors' => FALSE,
    ]);

    $this->assertEquals(403, $response->getStatusCode());
  }

  /**
   * Tests that admin users can access the conversation list.
   */
  public function testConversationListAccessibleToAdmin(): void {
    $conversation_id = '12345678-1234-4123-8123-123456789abc';
    $this->insertGovernanceConversation($conversation_id);
    $this->drupalLogin($this->adminUser);

    $this->drupalGet('/admin/reports/ilas-assistant/governance/conversations');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Conversation Logs');
    $this->assertSession()->pageTextContains($conversation_id);
  }

  /**
   * Tests that the canonical empty state is truthful when no rows exist.
   */
  public function testConversationListShowsTruthfulEmptyState(): void {
    $this->drupalLogin($this->adminUser);

    $this->drupalGet('/admin/reports/ilas-assistant/governance/conversations');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('No canonical conversation logs are available yet.');
    $this->assertSession()->pageTextNotContains('No conversations found matching the current filters.');
  }

  /**
   * Tests that the conversation detail requires UUID format.
   */
  public function testConversationDetailRequiresUuidFormat(): void {
    $this->drupalLogin($this->adminUser);

    // Invalid UUID format should return 404 (route pattern doesn't match).
    $this->drupalGet('/admin/reports/ilas-assistant/governance/conversations/not-a-uuid');
    $this->assertSession()->statusCodeEquals(404);

    // Valid UUID format should return 200 (even if empty).
    $this->drupalGet('/admin/reports/ilas-assistant/governance/conversations/12345678-1234-4123-8123-123456789abc');
    $this->assertSession()->statusCodeEquals(200);
  }

  /**
   * Tests that canonical conversation detail renders redacted turns.
   */
  public function testConversationDetailRendersCanonicalDetail(): void {
    $conversation_id = 'abcdef12-3456-4789-8abc-def012345678';
    $this->insertGovernanceConversation($conversation_id, 'Necesito ayuda con vivienda');
    $this->drupalLogin($this->adminUser);

    $this->drupalGet("/admin/reports/ilas-assistant/governance/conversations/{$conversation_id}");
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains($conversation_id);
    $this->assertSession()->pageTextContains('Necesito ayuda con vivienda');
  }

  /**
   * Tests that the assistant page remains accessible when the widget is off.
   */
  public function testAssistantPageAccessibleWhenWidgetDisabled(): void {
    $this->drupalLogin($this->regularUser);
    $this->setAssistantSurfaceToggles(FALSE, TRUE);

    $this->drupalGet('/assistant');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->responseContains('"pageMode":true');
    $this->assertSession()->responseContains('"ilasSiteAssistant"');
  }

  /**
   * Tests that the assistant page returns 403 when the page is disabled.
   */
  public function testAssistantPageForbiddenWhenPageDisabled(): void {
    $this->drupalLogin($this->regularUser);
    $this->setAssistantSurfaceToggles(TRUE, FALSE);

    $this->drupalGet('/assistant');
    $this->assertSession()->statusCodeEquals(403);
    $this->assertSession()->responseNotContains('"pageMode":true');
  }

  /**
   * Tests that disabling the widget removes global widget settings.
   */
  public function testGlobalWidgetSettingsRemovedWhenWidgetDisabled(): void {
    $this->drupalLogin($this->regularUser);
    $this->setAssistantSurfaceToggles(FALSE, TRUE);

    $this->drupalGet('/user');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->responseNotContains('"ilasSiteAssistant"');
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
    $response = $this->postTrack([
      'event_type' => 'chat_open',
    ], [
      'Origin' => $this->siteOrigin(),
    ]);
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
    $response = $this->postTrack([
      'event_type' => 'chat_open',
      'event_value' => '',
    ], $this->validTrackHeaders());

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
    $response = $this->postTrack([
      'event_type' => 'chat_open',
      'event_value' => '',
    ], $this->validTrackHeaders());

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
   * Tests anonymous POST to /track without browser proof is denied.
   */
  public function testAnonymousTrackEndpointWithoutBrowserProofReturnsTrackProofMissing(): void {
    $response = $this->postJsonAnonymous('/assistant/api/track', [
      'event_type' => 'chat_open',
      'event_value' => '',
    ]);

    $this->assertEquals(403, $response->getStatusCode(), 'Anonymous POST to /track should fail without browser proof');
    $data = json_decode($response->getBody(), TRUE);
    $this->assertIsArray($data);
    $this->assertSame('track_proof_missing', $data['error_code'] ?? NULL);
  }

  /**
   * Tests that /track flood limit still applies on allowed requests.
   */
  public function testTrackEndpointFloodLimitAppliesToAllowedRequests(): void {
    $warmup = $this->postTrack([
      'event_type' => 'chat_open',
      'event_value' => 'warmup',
    ], $this->validTrackHeaders());
    $this->assertEquals(200, $warmup->getStatusCode(), 'Warmup track request must succeed');

    $identifier = \Drupal::database()->select('flood', 'f')
      ->fields('f', ['identifier'])
      ->condition('event', 'ilas_assistant_track')
      ->range(0, 1)
      ->execute()
      ->fetchField();
    $this->assertNotEmpty($identifier, 'Track flood identifier must be discoverable after warmup request');

    \Drupal::database()->delete('flood')
      ->condition('event', 'ilas_assistant_track')
      ->condition('identifier', $identifier)
      ->execute();

    for ($i = 0; $i < 60; $i++) {
      $response = $this->postTrack([
        'event_type' => 'chat_open',
        'event_value' => 'burst-' . $i,
      ], $this->validTrackHeaders());
      $this->assertEquals(200, $response->getStatusCode(), 'Initial allowed track requests must succeed');
    }

    $response = $this->postTrack([
      'event_type' => 'chat_open',
      'event_value' => 'burst-limit',
    ], $this->validTrackHeaders());

    $this->assertEquals(429, $response->getStatusCode(), '61st allowed track request must be rate limited');
    $this->assertSame('60', $response->getHeader('Retry-After')[0] ?? NULL);
  }

  /**
   * Anonymous POST without CSRF token returns 403 with csrf_missing error code.
   *
   * IMP-SEC-02 error code contract: missing token → csrf_missing.
   */
  public function testAnonymousMessageWithoutToken_Returns403WithCsrfMissing(): void {
    $response = $this->postJsonAnonymous('/assistant/api/message', [
      'message' => 'Hello',
    ]);

    $this->assertEquals(403, $response->getStatusCode());
    $data = json_decode($response->getBody(), TRUE);
    $this->assertNotNull($data, 'Response body must be valid JSON');
    $this->assertTrue($data['error']);
    $this->assertEquals('csrf_missing', $data['error_code']);
    $this->assertNotEmpty($data['message']);
    $this->assertEquals('application/json', $response->getHeader('Content-Type')[0] ?? '');
    $this->assertStringContainsString('no-store', $response->getHeader('Cache-Control')[0] ?? '');
    $this->assertEquals('nosniff', $response->getHeader('X-Content-Type-Options')[0] ?? '');
  }

  /**
   * Anonymous POST with invalid CSRF token (with session) returns csrf_invalid.
   *
   * IMP-SEC-02 error code contract: invalid token + active session → csrf_invalid.
   */
  public function testAnonymousMessageWithInvalidToken_Returns403WithErrorCode(): void {
    // Prime a session first so hasPreviousSession() is true during validation.
    [$cookies] = $this->getAnonymousSessionCookiesAndToken();

    $response = $this->postJsonAnonymous('/assistant/api/message', [
      'message' => 'Hello',
    ], 'deliberately-invalid-token', $cookies);

    $this->assertEquals(403, $response->getStatusCode());
    $data = json_decode($response->getBody(), TRUE);
    $this->assertNotNull($data, 'Response body must be valid JSON');
    $this->assertTrue($data['error']);
    $this->assertEquals('csrf_invalid', $data['error_code']);
    $this->assertNotEmpty($data['message']);
  }

  /**
   * POST with token from unmatched session returns csrf_expired.
   *
   * IMP-SEC-02 error code contract: token present + no session → csrf_expired.
   */
  public function testAnonymousMessageWithExpiredSession_Returns403WithCsrfExpired(): void {
    // Send invalid token WITHOUT any session cookies — simulates expired session.
    $response = $this->postJsonAnonymous('/assistant/api/message', [
      'message' => 'Hello',
    ], 'token-from-expired-session');

    $this->assertEquals(403, $response->getStatusCode());
    $data = json_decode($response->getBody(), TRUE);
    $this->assertNotNull($data, 'Response body must be valid JSON');
    $this->assertTrue($data['error']);
    $this->assertEquals('csrf_expired', $data['error_code']);
    $this->assertNotEmpty($data['message']);
  }

  /**
   * Recovery path: 403 → fetch fresh token → 200.
   *
   * IMP-SEC-02 acceptance criteria: validates end-to-end recovery path.
   */
  public function testAnonymousMessageRecovery_FreshTokenAfter403(): void {
    // Step 1: POST without token → 403.
    $response = $this->postJsonAnonymous('/assistant/api/message', [
      'message' => 'Hello',
    ]);
    $this->assertEquals(403, $response->getStatusCode());

    // Step 2: Fetch fresh session + token.
    [$cookies, $token] = $this->getAnonymousSessionCookiesAndToken();

    // Step 3: POST with valid token → 200.
    $response = $this->postJsonAnonymous('/assistant/api/message', [
      'message' => 'Hello',
    ], $token, $cookies);
    $this->assertEquals(200, $response->getStatusCode(), 'Recovery with fresh token should succeed');
  }

  /**
   * Assistant bootstrap endpoint returns token and sets anonymous session.
   */
  public function testAnonymousSessionBootstrapEndpointReturnsTokenAndSetsCookie(): void {
    $cookies = new CookieJar();
    $response = $this->requestBootstrap($cookies);

    $this->assertEquals(200, $response->getStatusCode());
    $this->assertNotEmpty(trim((string) $response->getBody()), 'Bootstrap endpoint must return a CSRF token');
    $this->assertNotEmpty($cookies->toArray(), 'Bootstrap endpoint must issue a session cookie');
    $this->assertStringContainsString('text/plain', $response->getHeader('Content-Type')[0] ?? '');
    $this->assertStringContainsString('no-store', $response->getHeader('Cache-Control')[0] ?? '');
    $this->assertStringContainsString('private', $response->getHeader('Cache-Control')[0] ?? '');
    $this->assertEquals('nosniff', $response->getHeader('X-Content-Type-Options')[0] ?? '');
  }

  /**
   * Assistant bootstrap reuses an established anonymous session without churn.
   */
  public function testAnonymousSessionBootstrapReuseDoesNotRotateCookie(): void {
    $cookies = new CookieJar();

    $first = $this->requestBootstrap($cookies);
    $this->assertEquals(200, $first->getStatusCode());
    $this->assertNotEmpty($first->getHeader('Set-Cookie'), 'Initial bootstrap must mint a session cookie');

    $initial_cookie = $this->findDrupalSessionCookie($cookies);
    $this->assertNotNull($initial_cookie, 'Initial bootstrap must populate the cookie jar with a Drupal session cookie');

    $second = $this->requestBootstrap($cookies);
    $this->assertEquals(200, $second->getStatusCode());
    $this->assertSame([], $second->getHeader('Set-Cookie'), 'Bootstrap reuse must not rotate the anonymous session cookie');

    $reused_cookie = $this->findDrupalSessionCookie($cookies);
    $this->assertNotNull($reused_cookie, 'Reused bootstrap must preserve the existing Drupal session cookie');
    $this->assertSame($initial_cookie['Value'], $reused_cookie['Value'], 'Bootstrap reuse must preserve the same session identifier');
  }

  /**
   * Bootstrap rate limiting applies only to new anonymous sessions.
   */
  public function testAnonymousSessionBootstrapRateLimitBoundsNewSessionsButAllowsReuse(): void {
    $this->setSessionBootstrapThresholds(1, 1);
    $this->clearBootstrapFloodEvents();

    $established_cookies = new CookieJar();
    $warmup = $this->requestBootstrap($established_cookies);
    $this->assertEquals(200, $warmup->getStatusCode(), 'Initial bootstrap must succeed before the limit is reached');
    $this->assertNotNull($this->findDrupalSessionCookie($established_cookies), 'Initial bootstrap must create a Drupal session cookie');

    $cold_cookies = new CookieJar();
    $limited = $this->requestBootstrap($cold_cookies);
    $this->assertEquals(429, $limited->getStatusCode(), 'A second cold bootstrap request must be rate limited when the new-session budget is exhausted');
    $this->assertSame('60', $limited->getHeader('Retry-After')[0] ?? NULL);
    $this->assertStringContainsString('text/plain', $limited->getHeader('Content-Type')[0] ?? '');
    $this->assertStringContainsString('no-store', $limited->getHeader('Cache-Control')[0] ?? '');
    $this->assertStringContainsString('private', $limited->getHeader('Cache-Control')[0] ?? '');
    $this->assertEquals('nosniff', $limited->getHeader('X-Content-Type-Options')[0] ?? '');
    $this->assertSame([], $cold_cookies->toArray(), 'Rate-limited bootstrap requests must not mint a new anonymous session cookie');

    $reused = $this->requestBootstrap($established_cookies);
    $this->assertEquals(200, $reused->getStatusCode(), 'Bootstrap reuse with an existing anonymous session must stay allowed after cold-session exhaustion');
    $this->assertSame([], $reused->getHeader('Set-Cookie'), 'Reused bootstrap after exhaustion must not rotate the session cookie');
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
   * Sends a JSON POST request to /track with explicit headers.
   */
  protected function postTrack(array $data, array $headers, ?CookieJarInterface $cookies = NULL) {
    $options = [
      'http_errors' => FALSE,
      'headers' => $headers,
      'body' => json_encode($data),
    ];
    if ($cookies !== NULL) {
      $options['cookies'] = $cookies;
    }

    return $this->getHttpClient()->post($this->buildUrl('/assistant/api/track'), $options);
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
    $response = $this->requestBootstrap($cookies);
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
   * Primes an anonymous cookie jar via assistant session bootstrap endpoint.
   *
   * The assistant page no longer embeds CSRF tokens (to allow page caching).
   * The widget fetches /assistant/api/session/bootstrap lazily before first
   * POST.
   */
  protected function primeAnonymousSession(CookieJarInterface $cookies): void {
    $response = $this->requestBootstrap($cookies);
    $this->assertEquals(200, $response->getStatusCode(),
      '/assistant/api/session/bootstrap must be accessible to establish anonymous session');
  }

  /**
   * Issues a bootstrap request with an optional cookie jar.
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
   * Sends a GET request with optional cookies and headers.
   */
  protected function getJson(string $path, ?CookieJarInterface $cookies = NULL, array $headers = []) {
    $query = '';
    if (str_contains($path, '?')) {
      [$path, $query] = explode('?', $path, 2);
      $query = '?' . $query;
    }

    $options = [
      'http_errors' => FALSE,
    ];
    if ($cookies !== NULL) {
      $options['cookies'] = $cookies;
    }
    if ($headers !== []) {
      $options['headers'] = $headers;
    }

    return $this->getHttpClient()->get($this->buildUrl($path) . $query, $options);
  }

  /**
   * Asserts the common anonymous JSON read headers.
   */
  protected function assertReadJsonHeaders($response, bool $expect_correlation = FALSE): void {
    $this->assertStringContainsString('application/json', $response->getHeader('Content-Type')[0] ?? '');
    $cache_control = $response->getHeader('Cache-Control')[0] ?? '';
    $this->assertStringContainsString('private', $cache_control);
    $this->assertTrue(
      str_contains($cache_control, 'no-store') || str_contains($cache_control, 'no-cache'),
      'Anonymous JSON reads must send an explicit private no-cache/no-store directive',
    );
    $this->assertSame('nosniff', $response->getHeader('X-Content-Type-Options')[0] ?? '');

    if ($expect_correlation) {
      $this->assertNotEmpty($response->getHeader('X-Correlation-ID')[0] ?? NULL);
    }
  }

  /**
   * Asserts the public suggest item contract.
   */
  protected function assertSuggestionPublicFields(array $suggestion): void {
    $keys = array_keys($suggestion);
    sort($keys);
    $this->assertSame(['id', 'label', 'type'], $keys);
  }

  /**
   * Asserts the public FAQ result contract.
   */
  protected function assertFaqResultPublicFields(array $result): void {
    $keys = array_keys($result);
    sort($keys);
    $this->assertSame(['answer', 'id', 'question', 'url'], $keys);
  }

  /**
   * Asserts the public FAQ category contract.
   */
  protected function assertFaqCategoryPublicFields(array $category): void {
    $keys = array_keys($category);
    sort($keys);
    $this->assertSame(['count', 'name'], $keys);
  }

  /**
   * Writes a runtime diagnostics token into the generated test-site settings.
   */
  protected function configureDiagnosticsToken(string $token): void {
    $this->writeSettings([
      'settings' => [
        'ilas_assistant_diagnostics_token' => (object) [
          'value' => $token,
          'required' => TRUE,
        ],
      ],
    ]);

    \Drupal::service('kernel')->invalidateContainer();
    Settings::initialize(DRUPAL_ROOT, $this->siteDirectory, $this->classLoader);
  }

  /**
   * Returns the Drupal session cookie stored in a cookie jar, if present.
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
   * Applies widget/page surface toggles for the active test site.
   */
  protected function setAssistantSurfaceToggles(bool $enableGlobalWidget, bool $enableAssistantPage): void {
    \Drupal::configFactory()->getEditable('ilas_site_assistant.settings')
      ->set('enable_global_widget', $enableGlobalWidget)
      ->set('enable_assistant_page', $enableAssistantPage)
      ->save();
  }

  /**
   * Applies bootstrap rate-limit thresholds for the active test site.
   */
  protected function setSessionBootstrapThresholds(int $perMinute, int $perHour, int $windowHours = 24): void {
    \Drupal::configFactory()->getEditable('ilas_site_assistant.settings')
      ->set('session_bootstrap', [
        'rate_limit_per_minute' => $perMinute,
        'rate_limit_per_hour' => $perHour,
        'observation_window_hours' => $windowHours,
      ])
      ->save();
  }

  /**
   * Applies read-endpoint thresholds for the active test site.
   */
  protected function setReadEndpointThresholds(int $suggestPerMinute, int $suggestPerHour, int $faqPerMinute, int $faqPerHour): void {
    \Drupal::configFactory()->getEditable('ilas_site_assistant.settings')
      ->set('read_endpoint_rate_limits', [
        'suggest' => [
          'rate_limit_per_minute' => $suggestPerMinute,
          'rate_limit_per_hour' => $suggestPerHour,
        ],
        'faq' => [
          'rate_limit_per_minute' => $faqPerMinute,
          'rate_limit_per_hour' => $faqPerHour,
        ],
      ])
      ->save();
  }

  /**
   * Clears bootstrap flood rows to keep assertions deterministic.
   */
  protected function clearBootstrapFloodEvents(): void {
    $database = \Drupal::database();
    if (!$database->schema()->tableExists('flood')) {
      return;
    }

    $database->delete('flood')
      ->condition('event', [
        'ilas_assistant_session_bootstrap_min',
        'ilas_assistant_session_bootstrap_hour',
      ], 'IN')
      ->execute();
  }

  /**
   * Clears read-endpoint flood rows to keep assertions deterministic.
   */
  protected function clearReadEndpointFloodEvents(): void {
    $database = \Drupal::database();
    if (!$database->schema()->tableExists('flood')) {
      return;
    }

    $database->delete('flood')
      ->condition('event', [
        'ilas_assistant_suggest_min',
        'ilas_assistant_suggest_hour',
        'ilas_assistant_faq_min',
        'ilas_assistant_faq_hour',
      ], 'IN')
      ->execute();
  }

  /**
   * Returns the site origin for same-origin header assertions.
   */
  protected function siteOrigin(): string {
    $parts = parse_url($this->buildUrl('/assistant'));
    $scheme = $parts['scheme'] ?? 'http';
    $host = $parts['host'] ?? 'localhost';
    $port = isset($parts['port']) ? ':' . $parts['port'] : '';
    return "{$scheme}://{$host}{$port}";
  }

  /**
   * Returns the standard allow-path headers for /track requests.
   */
  protected function validTrackHeaders(): array {
    return [
      'Content-Type' => 'application/json',
      'Origin' => $this->siteOrigin(),
    ];
  }

  /**
   * Inserts a canonical governance conversation for admin-route assertions.
   */
  protected function insertGovernanceConversation(string $conversationId, string $userMessage = 'Redacted housing question', int $timestamp = 1700000000): void {
    $database = \Drupal::database();

    $database->insert('ilas_site_assistant_conversation_session')
      ->fields([
        'conversation_id' => $conversationId,
        'first_message_at' => $timestamp,
        'last_message_at' => $timestamp,
        'turn_count' => 2,
        'exchange_count' => 1,
        'language_hint' => 'en',
        'last_intent' => 'housing',
        'last_response_type' => 'resources',
        'first_request_id' => '11111111-1111-4111-8111-111111111111',
        'last_request_id' => '11111111-1111-4111-8111-111111111111',
        'has_no_answer' => 0,
        'is_held' => 0,
        'purge_after' => $timestamp + 86400,
      ])
      ->execute();

    $database->insert('ilas_site_assistant_conversation_turn')
      ->fields([
        'conversation_id' => $conversationId,
        'turn_sequence' => 1,
        'request_id' => '11111111-1111-4111-8111-111111111111',
        'direction' => 'user',
        'message_redacted' => $userMessage,
        'message_hash' => hash('sha256', $userMessage),
        'message_length_bucket' => 'medium',
        'redaction_profile' => 'none',
        'redaction_version' => 'v1',
        'language_hint' => 'en',
        'intent' => 'housing',
        'response_type' => NULL,
        'is_no_answer' => 0,
        'created' => $timestamp,
      ])
      ->execute();

    $assistantMessage = 'Here are housing resources that may help.';
    $database->insert('ilas_site_assistant_conversation_turn')
      ->fields([
        'conversation_id' => $conversationId,
        'turn_sequence' => 2,
        'request_id' => '11111111-1111-4111-8111-111111111111',
        'direction' => 'assistant',
        'message_redacted' => $assistantMessage,
        'message_hash' => hash('sha256', $assistantMessage),
        'message_length_bucket' => 'medium',
        'redaction_profile' => 'none',
        'redaction_version' => 'v1',
        'language_hint' => 'en',
        'intent' => 'housing',
        'response_type' => 'resources',
        'is_no_answer' => 0,
        'created' => $timestamp,
      ])
      ->execute();
  }

}
