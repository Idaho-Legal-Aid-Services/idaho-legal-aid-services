<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant_governance\Functional;

use Drupal\Tests\BrowserTestBase;
use GuzzleHttp\Cookie\CookieJarInterface;
use PHPUnit\Framework\Attributes\Group;

/**
 * End-to-end coverage for gap-review logging through the assistant API.
 */
#[Group('ilas_site_assistant_governance')]
final class GapReviewLoggingFunctionalTest extends BrowserTestBase {

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
