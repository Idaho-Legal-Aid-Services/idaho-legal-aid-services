<?php

namespace Drupal\Tests\ilas_site_assistant\Unit;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

// Load the required classes directly (no Drupal bootstrap needed).
require_once __DIR__ . '/../../../src/Service/ResponseBuilder.php';
require_once __DIR__ . '/../../../src/Service/HardRouteRegistry.php';

use Drupal\ilas_site_assistant\Service\HardRouteRegistry;
use Drupal\ilas_site_assistant\Service\ResponseBuilder;

/**
 * Tests hard-route URL enforcement to prevent URL drift.
 *
 * These tests ensure that when a hard-route intent is selected,
 * the canonical URL is ALWAYS emitted, even after response enrichment.
 */
#[Group('ilas_site_assistant')]
class HardRouteUrlEnforcementTest extends TestCase {

  /**
   * The canonical URLs.
   *
   * @var array
   */
  protected $canonicalUrls;

  /**
   * The HardRouteRegistry.
   *
   * @var \Drupal\ilas_site_assistant\Service\HardRouteRegistry
   */
  protected $registry;

  /**
   * The ResponseBuilder.
   *
   * @var \Drupal\ilas_site_assistant\Service\ResponseBuilder
   */
  protected $builder;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->canonicalUrls = [
      'apply' => '/apply-for-help',
      'hotline' => '/Legal-Advice-Line',
      'offices' => '/contact/offices',
      'donate' => '/donate',
      'feedback' => '/get-involved/feedback',
      'resources' => '/what-we-do/resources',
      'forms' => '/forms',
      'guides' => '/guides',
      'senior_risk_detector' => '/resources/legal-risk-detector',
      'faq' => '/faq',
      'services' => '/services',
      'service_areas' => [
        'housing' => '/legal-help/housing',
        'family' => '/legal-help/family',
        'seniors' => '/legal-help/seniors',
        'health' => '/legal-help/health',
        'consumer' => '/legal-help/consumer',
        'civil_rights' => '/legal-help/civil-rights',
      ],
    ];

    $this->registry = new HardRouteRegistry($this->canonicalUrls);
    $this->builder = new ResponseBuilder($this->canonicalUrls);
  }

  /**
   * Tests that apply intent always returns canonical apply URL.
   */
  #[DataProvider('applyIntentProvider')]
  public function testApplyIntentAlwaysReturnsCanonicalUrl(string $intent_type): void {
    $intent = ['type' => $intent_type];
    $response = [
      'type' => 'navigation',
      'primary_action' => ['label' => 'Wrong', 'url' => '/wrong-url'],
      'url' => '/wrong-url',
    ];

    $enforced = $this->registry->enforceCanonicalUrl($response, $intent);

    $this->assertEquals('/apply-for-help', $enforced['primary_action']['url']);
    $this->assertEquals('/apply-for-help', $enforced['url']);
    $this->assertTrue($enforced['_hard_route_enforced'] ?? FALSE);
  }

  /**
   * Data provider for apply intent variants.
   */
  public static function applyIntentProvider(): array {
    return [
      ['apply'],
      ['apply_for_help'],
      ['eligibility'],
    ];
  }

  /**
   * Tests that hotline intent always returns canonical hotline URL.
   */
  #[DataProvider('hotlineIntentProvider')]
  public function testHotlineIntentAlwaysReturnsCanonicalUrl(string $intent_type): void {
    $intent = ['type' => $intent_type];
    $response = [
      'type' => 'navigation',
      'primary_action' => ['label' => 'Wrong', 'url' => '/wrong-url'],
      'url' => '/wrong-url',
    ];

    $enforced = $this->registry->enforceCanonicalUrl($response, $intent);

    $this->assertEquals('/Legal-Advice-Line', $enforced['primary_action']['url']);
    $this->assertEquals('/Legal-Advice-Line', $enforced['url']);
  }

  /**
   * Data provider for hotline intent variants.
   */
  public static function hotlineIntentProvider(): array {
    return [
      ['hotline'],
      ['legal_advice_line'],
    ];
  }

  /**
   * Tests that high-risk intents return correct canonical URLs.
   */
  #[DataProvider('highRiskIntentProvider')]
  public function testHighRiskIntentsReturnCorrectUrls(string $intent_type, string $expected_url): void {
    $intent = ['type' => $intent_type];
    $response = [
      'type' => 'navigation',
      'primary_action' => ['label' => 'Wrong', 'url' => '/wrong-url'],
    ];

    $enforced = $this->registry->enforceCanonicalUrl($response, $intent);

    $this->assertEquals($expected_url, $enforced['primary_action']['url']);
  }

  /**
   * Data provider for high-risk intents.
   */
  public static function highRiskIntentProvider(): array {
    return [
      ['high_risk', '/apply-for-help'],
      ['high_risk_dv', '/apply-for-help'],
      ['high_risk_eviction', '/apply-for-help'],
      ['high_risk_scam', '/apply-for-help'],
      ['high_risk_utility', '/apply-for-help'],
      ['high_risk_deadline', '/Legal-Advice-Line'],
    ];
  }

  /**
   * Tests that soft-route intents are not enforced.
   */
  #[DataProvider('softRouteIntentProvider')]
  public function testSoftRouteIntentsNotEnforced(string $intent_type): void {
    $intent = ['type' => $intent_type];
    $original_url = '/some-dynamic-url';
    $response = [
      'type' => 'navigation',
      'primary_action' => ['label' => 'Dynamic', 'url' => $original_url],
    ];

    $enforced = $this->registry->enforceCanonicalUrl($response, $intent);

    // URL should remain unchanged for soft-route intents.
    $this->assertEquals($original_url, $enforced['primary_action']['url']);
    $this->assertFalse($enforced['_hard_route_enforced'] ?? FALSE);
  }

  /**
   * Data provider for soft-route intents.
   */
  public static function softRouteIntentProvider(): array {
    return [
      ['navigation'],
      ['topic'],
      ['service_area'],
      ['disambiguation'],
      ['clarify'],
      ['greeting'],
      ['unknown'],
      ['fallback'],
    ];
  }

  /**
   * Tests override intent handling for high-risk intents.
   */
  #[DataProvider('overrideIntentProvider')]
  public function testOverrideIntentValidation(string $intentType, ?array $overrideIntent, string $expectedUrl): void {
    $intent = ['type' => 'service_area', 'area' => 'housing'];
    $response = [
      'type' => 'navigation',
      'primary_action' => ['label' => 'Housing Help', 'url' => $expectedUrl],
    ];

    $result = $this->builder->validateHardRouteUrlWithOverrideIntent($response, $intent, $overrideIntent);
    $this->assertTrue($result['valid']);
  }

  /**
   * Tests that override intents override soft-route intents.
   *
   * This is the critical test for preventing URL drift when intent is
   * misclassified as service_area but override intent indicates high-risk.
   */
  #[DataProvider('overrideIntentProvider')]
  public function testOverrideIntentSoftRouteIntents(
    string $intent_type,
    ?array $override_intent,
    string $expected_url
  ): void {
    $intent = ['type' => $intent_type, 'area' => 'housing'];
    $original_url = '/legal-help/housing';
    $response = [
      'type' => 'navigation',
      'primary_action' => ['label' => 'Housing Help', 'url' => $original_url],
    ];

    $enforced = $this->registry->enforceCanonicalUrlWithOverrideIntent(
      $response,
      $intent,
      $override_intent
    );

    $this->assertEquals($expected_url, $enforced['primary_action']['url']);

    if ($expected_url !== $original_url) {
      $this->assertTrue($enforced['_hard_route_enforced_by_override_intent'] ?? FALSE);
    }
  }

  /**
   * Data provider for override intents.
   */
  public static function overrideIntentProvider(): array {
    return [
      ['service_area', ['type' => 'high_risk', 'risk_category' => 'high_risk_eviction'], '/apply-for-help'],
      ['service_area', ['type' => 'high_risk', 'risk_category' => 'high_risk_dv'], '/apply-for-help'],
      ['service_area', ['type' => 'high_risk', 'risk_category' => 'high_risk_deadline'], '/Legal-Advice-Line'],
      ['service_area', ['type' => 'high_risk', 'risk_category' => 'high_risk_scam'], '/apply-for-help'],
      ['service_area', NULL, '/legal-help/housing'],
      ['service_area', ['type' => 'unknown'], '/legal-help/housing'],
    ];
  }

  /**
   * Tests that ResponseBuilder's enforceHardRouteUrl works correctly.
   */
  public function testResponseBuilderEnforcement(): void {
    $intent = ['type' => 'apply'];
    $response = $this->builder->buildFromIntent($intent, 'test message');

    // Simulate enrichment that changes the URL.
    $response['primary_action']['url'] = '/wrong-url';

    $enforced = $this->builder->enforceHardRouteUrl($response, $intent);

    $this->assertEquals('/apply-for-help', $enforced['primary_action']['url']);
  }

  /**
   * Tests that ResponseBuilder's override-intent-aware enforcement works.
   */
  public function testResponseBuilderOverrideIntentEnforcement(): void {
    $intent = ['type' => 'service_area', 'area' => 'housing'];
    $response = $this->builder->buildFromIntent($intent, 'test message');

    // Response has housing URL.
    $this->assertStringContainsString('housing', $response['primary_action']['url'] ?? '');

    // Enforce with eviction override intent.
    $enforced = $this->builder->enforceHardRouteUrlWithOverrideIntent(
      $response,
      $intent,
      ['type' => 'high_risk', 'risk_category' => 'high_risk_eviction']
    );

    // Should now have apply URL.
    $this->assertEquals('/apply-for-help', $enforced['primary_action']['url']);
  }

  /**
   * Tests validation correctly identifies URL drift.
   */
  public function testValidationDetectsUrlDrift(): void {
    $intent = ['type' => 'apply'];
    $response = [
      'primary_action' => ['url' => '/wrong-url'],
    ];

    $result = $this->registry->validateCanonicalUrl($response, $intent);

    $this->assertFalse($result['valid']);
    $this->assertEquals('/apply-for-help', $result['expected']);
    $this->assertEquals('/wrong-url', $result['actual']);
    $this->assertStringContainsString('URL drift', $result['message']);
  }

  /**
   * Tests validation passes for correct canonical URL.
   */
  public function testValidationPassesForCorrectUrl(): void {
    $intent = ['type' => 'apply'];
    $response = [
      'primary_action' => ['url' => '/apply-for-help'],
    ];

    $result = $this->registry->validateCanonicalUrl($response, $intent);

    $this->assertTrue($result['valid']);
    $this->assertEquals('/apply-for-help', $result['expected']);
    $this->assertEquals('/apply-for-help', $result['actual']);
  }

  /**
   * Tests that service area intents with specific area return correct URL.
   */
  public function testServiceAreaIntentsWithArea(): void {
    $intent = ['type' => 'service_area', 'area' => 'housing'];
    $url = $this->registry->getCanonicalUrl('service_area', $intent);

    $this->assertEquals('/legal-help/housing', $url);
  }

  /**
   * Tests that topic_* intents return correct service area URLs.
   */
  #[DataProvider('topicIntentProvider')]
  public function testTopicIntentsReturnServiceAreaUrls(string $intent_type, string $expected_url): void {
    $url = $this->registry->getCanonicalUrl($intent_type);
    $this->assertEquals($expected_url, $url);
  }

  /**
   * Data provider for topic intents.
   */
  public static function topicIntentProvider(): array {
    return [
      ['topic_housing', '/legal-help/housing'],
      ['topic_family', '/legal-help/family'],
      ['topic_seniors', '/legal-help/seniors'],
      ['topic_health', '/legal-help/health'],
      ['topic_consumer', '/legal-help/consumer'],
      ['topic_civil_rights', '/legal-help/civil-rights'],
    ];
  }

  /**
   * Tests that all hard-route intents have valid canonical URLs.
   */
  public function testAllHardRouteIntentsHaveValidUrls(): void {
    $hard_routes = HardRouteRegistry::getHardRouteMap();

    foreach ($hard_routes as $intent_type => $url_key) {
      $url = $this->registry->getCanonicalUrl($intent_type);
      $this->assertNotNull($url, "Hard-route intent '$intent_type' should have a canonical URL");
      $this->assertNotEmpty($url, "Hard-route intent '$intent_type' should have a non-empty URL");
    }
  }

  /**
   * Tests that enforcement preserves response structure.
   */
  public function testEnforcementPreservesResponseStructure(): void {
    $intent = ['type' => 'apply'];
    $response = [
      'type' => 'navigation',
      'message' => 'Test message',
      'primary_action' => ['label' => 'Apply', 'url' => '/wrong'],
      'secondary_actions' => [['label' => 'FAQ', 'url' => '/faq']],
      'custom_field' => 'preserved',
    ];

    $enforced = $this->registry->enforceCanonicalUrl($response, $intent);

    // URL should be enforced.
    $this->assertEquals('/apply-for-help', $enforced['primary_action']['url']);

    // Other fields should be preserved.
    $this->assertEquals('navigation', $enforced['type']);
    $this->assertEquals('Test message', $enforced['message']);
    $this->assertEquals('preserved', $enforced['custom_field']);
    $this->assertCount(1, $enforced['secondary_actions']);
  }

  /**
   * Tests the canonical action convenience method.
   */
  public function testGetCanonicalAction(): void {
    $action = $this->registry->getCanonicalAction('apply');

    $this->assertIsArray($action);
    $this->assertEquals('/apply-for-help', $action['url']);
    $this->assertEquals('Apply for Help', $action['label']);
  }

  /**
   * Tests that getCanonicalAction returns NULL for soft-route intents.
   */
  public function testGetCanonicalActionReturnsNullForSoftRoute(): void {
    $action = $this->registry->getCanonicalAction('navigation');
    $this->assertNull($action);
  }

}
