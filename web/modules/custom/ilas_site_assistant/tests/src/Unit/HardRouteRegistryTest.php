<?php

namespace Drupal\Tests\ilas_site_assistant\Unit;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

// Load the required classes directly (no Drupal bootstrap needed).
require_once __DIR__ . '/../../../src/Service/ResponseBuilder.php';
require_once __DIR__ . '/../../../src/Service/HardRouteRegistry.php';
require_once __DIR__ . '/../Support/CanonicalUrlFixtures.php';

use Drupal\ilas_site_assistant\Service\HardRouteRegistry;
use Drupal\ilas_site_assistant\Service\ResponseBuilder;
use Drupal\Tests\ilas_site_assistant\Support\CanonicalUrlFixtures;

/**
 * Regression tests for the HardRouteRegistry.
 *
 * These tests ensure that hard-route intents ALWAYS emit their canonical URL.
 * If any of these tests fail, the action URL accuracy in eval will drop.
 */
#[Group('ilas_site_assistant')]
class HardRouteRegistryTest extends TestCase {

  /**
   * The registry under test.
   *
   * @var \Drupal\ilas_site_assistant\Service\HardRouteRegistry
   */
  protected $registry;

  /**
   * The response builder.
   *
   * @var \Drupal\ilas_site_assistant\Service\ResponseBuilder
   */
  protected $builder;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->registry = new HardRouteRegistry(CanonicalUrlFixtures::defaults());
    $this->builder = new ResponseBuilder(CanonicalUrlFixtures::defaults());
  }

  /**
   * Tests that navigation intents are correctly identified as hard-routes.
   */
  #[DataProvider('hardRouteIntentProvider')]
  public function testIntentIsHardRoute(string $intent_type): void {
    $this->assertTrue(
      $this->registry->isHardRoute($intent_type),
      "Intent '$intent_type' should be identified as a hard-route"
    );
  }

  /**
   * Data provider for hard-route intents.
   */
  public static function hardRouteIntentProvider(): array {
    return [
      'apply' => ['apply'],
      'apply_for_help' => ['apply_for_help'],
      'hotline' => ['hotline'],
      'legal_advice_line' => ['legal_advice_line'],
      'offices' => ['offices'],
      'offices_contact' => ['offices_contact'],
      'donate' => ['donate'],
      'donations' => ['donations'],
      'feedback' => ['feedback'],
      'feedback_complaints' => ['feedback_complaints'],
      'forms' => ['forms'],
      'forms_finder' => ['forms_finder'],
      'guides' => ['guides'],
      'guides_finder' => ['guides_finder'],
      'faq' => ['faq'],
      'services' => ['services'],
      'services_overview' => ['services_overview'],
      'eligibility' => ['eligibility'],
      'risk_detector' => ['risk_detector'],
      'senior_risk_detector' => ['senior_risk_detector'],
      'resources' => ['resources'],
      'high_risk' => ['high_risk'],
      'high_risk_dv' => ['high_risk_dv'],
      'high_risk_eviction' => ['high_risk_eviction'],
      'high_risk_scam' => ['high_risk_scam'],
      'high_risk_deadline' => ['high_risk_deadline'],
      'high_risk_utility' => ['high_risk_utility'],
      'out_of_scope' => ['out_of_scope'],
    ];
  }

  /**
   * Tests that soft-route intents are correctly identified.
   */
  #[DataProvider('softRouteIntentProvider')]
  public function testIntentIsSoftRoute(string $intent_type): void {
    $this->assertTrue(
      $this->registry->isSoftRoute($intent_type),
      "Intent '$intent_type' should be identified as a soft-route"
    );
  }

  /**
   * Data provider for soft-route intents.
   */
  public static function softRouteIntentProvider(): array {
    return [
      'navigation' => ['navigation'],
      'topic' => ['topic'],
      'service_area' => ['service_area'],
      'disambiguation' => ['disambiguation'],
      'clarify' => ['clarify'],
      'greeting' => ['greeting'],
      'unknown' => ['unknown'],
      'fallback' => ['fallback'],
    ];
  }

  /**
   * Tests canonical URL resolution for each hard-route intent.
   *
   * This is the CRITICAL regression test. If any URL changes unexpectedly,
   * it will cause "intent right, URL wrong" failures in eval.
   */
  #[DataProvider('canonicalUrlProvider')]
  public function testCanonicalUrlResolution(string $intent_type, string $expected_url, array $intent_extra = []): void {
    $url = $this->registry->getCanonicalUrl($intent_type, $intent_extra);

    $this->assertNotNull($url, "Intent '$intent_type' must have a canonical URL");
    $this->assertEquals(
      $expected_url,
      $url,
      "Intent '$intent_type' must resolve to '$expected_url', got '$url'"
    );
  }

  /**
   * Data provider for canonical URL resolution.
   */
  public static function canonicalUrlProvider(): array {
    return [
      // Navigation intents.
      'apply' => ['apply', '/apply-for-help'],
      'apply_for_help' => ['apply_for_help', '/apply-for-help'],
      'hotline' => ['hotline', '/Legal-Advice-Line'],
      'legal_advice_line' => ['legal_advice_line', '/Legal-Advice-Line'],
      'offices' => ['offices', '/contact/offices'],
      'offices_contact' => ['offices_contact', '/contact/offices'],
      'donate' => ['donate', '/donate'],
      'donations' => ['donations', '/donate'],
      'feedback' => ['feedback', '/get-involved/feedback'],
      'feedback_complaints' => ['feedback_complaints', '/get-involved/feedback'],
      'forms' => ['forms', '/forms'],
      'forms_finder' => ['forms_finder', '/forms'],
      'guides' => ['guides', '/guides'],
      'guides_finder' => ['guides_finder', '/guides'],
      'faq' => ['faq', '/faq'],
      'services' => ['services', '/services'],
      'services_overview' => ['services_overview', '/services'],
      'eligibility' => ['eligibility', '/apply-for-help'],
      'risk_detector' => ['risk_detector', '/resources/legal-risk-detector'],
      'senior_risk_detector' => ['senior_risk_detector', '/resources/legal-risk-detector'],
      'resources' => ['resources', '/what-we-do/resources'],

      // Service area intents.
      'topic_housing' => ['topic_housing', '/legal-help/housing'],
      'topic_family' => ['topic_family', '/legal-help/family'],
      'topic_seniors' => ['topic_seniors', '/legal-help/seniors'],
      'topic_health' => ['topic_health', '/legal-help/health'],
      'topic_consumer' => ['topic_consumer', '/legal-help/consumer'],
      'topic_civil_rights' => ['topic_civil_rights', '/legal-help/civil-rights'],

      // High-risk intents.
      'high_risk' => ['high_risk', '/apply-for-help'],
      'high_risk_dv' => ['high_risk_dv', '/apply-for-help'],
      'high_risk_eviction' => ['high_risk_eviction', '/apply-for-help'],
      'high_risk_scam' => ['high_risk_scam', '/apply-for-help'],
      'high_risk_utility' => ['high_risk_utility', '/apply-for-help'],
      'high_risk_deadline' => ['high_risk_deadline', '/Legal-Advice-Line'],

      // Out of scope.
      'out_of_scope' => ['out_of_scope', '/services'],
    ];
  }

  /**
   * Tests that URL enforcement works correctly.
   *
   * Simulates the scenario where processIntent() sets a wrong URL,
   * and verifies that enforceCanonicalUrl() corrects it.
   */
  public function testEnforceCanonicalUrlCorrectsDrift(): void {
    // Simulate a response where the URL drifted (wrong URL set).
    $drifted_response = [
      'type' => 'navigation',
      'response_mode' => 'navigate',
      'primary_action' => [
        'label' => 'Wrong Label',
        'url' => '/wrong-url',
      ],
      'url' => '/wrong-url',
    ];

    // Test enforcement for apply intent.
    $intent = ['type' => 'apply'];
    $enforced = $this->registry->enforceCanonicalUrl($drifted_response, $intent);

    $this->assertEquals(
      '/apply-for-help',
      $enforced['primary_action']['url'],
      'enforceCanonicalUrl() must correct drifted URL to canonical'
    );
    $this->assertEquals(
      '/apply-for-help',
      $enforced['url'],
      'enforceCanonicalUrl() must also update legacy url field'
    );
    $this->assertTrue($enforced['_hard_route_enforced'] ?? FALSE);
  }

  /**
   * Tests that URL enforcement preserves correct URLs.
   */
  public function testEnforceCanonicalUrlPreservesCorrectUrl(): void {
    // Response with correct URL.
    $correct_response = [
      'type' => 'navigation',
      'response_mode' => 'navigate',
      'primary_action' => [
        'label' => 'Apply for Help',
        'url' => '/apply-for-help',
      ],
      'url' => '/apply-for-help',
    ];

    $intent = ['type' => 'apply'];
    $enforced = $this->registry->enforceCanonicalUrl($correct_response, $intent);

    $this->assertEquals(
      '/apply-for-help',
      $enforced['primary_action']['url'],
      'enforceCanonicalUrl() must preserve correct URL'
    );
  }

  /**
   * Tests that URL enforcement skips soft-route intents.
   */
  public function testEnforceCanonicalUrlSkipsSoftRoutes(): void {
    // Response for navigation intent (soft-route) with custom URL.
    $response = [
      'type' => 'navigation',
      'response_mode' => 'navigate',
      'primary_action' => [
        'label' => 'Custom Page',
        'url' => '/custom-navigation-target',
      ],
      'url' => '/custom-navigation-target',
    ];

    $intent = ['type' => 'navigation'];
    $enforced = $this->registry->enforceCanonicalUrl($response, $intent);

    $this->assertEquals(
      '/custom-navigation-target',
      $enforced['primary_action']['url'],
      'enforceCanonicalUrl() must not override soft-route URLs'
    );
    $this->assertFalse($enforced['_hard_route_enforced'] ?? FALSE);
  }

  /**
   * Tests URL validation for hard-route intents.
   */
  public function testValidateCanonicalUrlDetectsDrift(): void {
    // Response with wrong URL.
    $response = [
      'primary_action' => ['url' => '/wrong-url'],
    ];

    $validation = $this->registry->validateCanonicalUrl($response, ['type' => 'apply']);

    $this->assertFalse($validation['valid']);
    $this->assertTrue($validation['is_hard_route']);
    $this->assertEquals('/apply-for-help', $validation['expected']);
    $this->assertEquals('/wrong-url', $validation['actual']);
    $this->assertStringContainsString('drift', strtolower($validation['message']));
  }

  /**
   * Tests URL validation for correct URLs.
   */
  public function testValidateCanonicalUrlPassesCorrect(): void {
    $response = [
      'primary_action' => ['url' => '/apply-for-help'],
    ];

    $validation = $this->registry->validateCanonicalUrl($response, ['type' => 'apply']);

    $this->assertTrue($validation['valid']);
    $this->assertTrue($validation['is_hard_route']);
    $this->assertEquals('/apply-for-help', $validation['expected']);
  }

  /**
   * Tests that high_risk_deadline correctly maps to hotline.
   *
   * This is a specific regression test because deadline should go to
   * hotline, not apply (unlike other high-risk intents).
   */
  public function testHighRiskDeadlineMapsToHotline(): void {
    $url = $this->registry->getCanonicalUrl('high_risk_deadline');

    $this->assertStringContainsStringIgnoringCase(
      'legal-advice-line',
      $url,
      'high_risk_deadline must map to hotline/Legal-Advice-Line'
    );

    // Also verify enforcement.
    $response = [
      'type' => 'escalation',
      'primary_action' => ['url' => '/apply-for-help'], // Wrong!
    ];
    $enforced = $this->registry->enforceCanonicalUrl($response, ['type' => 'high_risk_deadline']);

    $this->assertStringContainsStringIgnoringCase(
      'legal-advice-line',
      $enforced['primary_action']['url'],
      'high_risk_deadline enforcement must set hotline URL'
    );
  }

  /**
   * Tests integration with ResponseBuilder.
   *
   * Verifies that buildFromIntent + enforceHardRouteUrl always produces
   * the correct canonical URL for hard-route intents.
   */
  #[DataProvider('hardRouteIntegrationProvider')]
  public function testResponseBuilderEnforcementIntegration(string $intent_type, string $expected_url): void {
    // Build response.
    $intent = ['type' => $intent_type];
    $response = $this->builder->buildFromIntent($intent);

    // Enforce.
    $enforced = $this->builder->enforceHardRouteUrl($response, $intent);

    // Normalize for comparison (ignore case for paths like Legal-Advice-Line).
    $actual = strtolower($enforced['primary_action']['url'] ?? '');
    $expected = strtolower($expected_url);

    $this->assertEquals(
      $expected,
      $actual,
      "ResponseBuilder + enforceHardRouteUrl for '$intent_type' must produce '$expected_url'"
    );
  }

  /**
   * Data provider for integration tests.
   */
  public static function hardRouteIntegrationProvider(): array {
    return [
      'apply_for_help' => ['apply_for_help', '/apply-for-help'],
      'legal_advice_line' => ['legal_advice_line', '/legal-advice-line'],
      'offices_contact' => ['offices_contact', '/contact/offices'],
      'donations' => ['donations', '/donate'],
      'forms_finder' => ['forms_finder', '/forms'],
      'guides_finder' => ['guides_finder', '/guides'],
      'faq' => ['faq', '/faq'],
      'services_overview' => ['services_overview', '/services'],
    ];
  }

  /**
   * Tests that the hard-route map is comprehensive.
   *
   * Ensures all expected intents are registered.
   */
  public function testHardRouteMapCompleteness(): void {
    $map = HardRouteRegistry::getHardRouteMap();

    // Core navigation intents must be present.
    $required = [
      'apply', 'apply_for_help',
      'hotline', 'legal_advice_line',
      'offices', 'offices_contact',
      'donate', 'donations',
      'feedback', 'feedback_complaints',
      'forms', 'forms_finder',
      'guides', 'guides_finder',
      'faq',
      'services', 'services_overview',
      'eligibility',
      'risk_detector', 'senior_risk_detector',
      'resources',
    ];

    foreach ($required as $intent) {
      $this->assertArrayHasKey(
        $intent,
        $map,
        "Hard-route map must include '$intent'"
      );
    }
  }

  /**
   * Tests that canonical labels are provided for all hard-route intents.
   */
  public function testCanonicalLabelsExist(): void {
    $hard_routes = [
      'apply', 'hotline', 'offices', 'donate', 'feedback',
      'forms', 'guides', 'faq', 'services', 'eligibility',
      'risk_detector', 'resources', 'high_risk', 'out_of_scope',
    ];

    foreach ($hard_routes as $intent) {
      $label = $this->registry->getCanonicalLabel($intent);
      $this->assertNotNull(
        $label,
        "Hard-route intent '$intent' must have a canonical label"
      );
      $this->assertNotEmpty(
        $label,
        "Canonical label for '$intent' must not be empty"
      );
    }
  }

  /**
   * Tests getAllCanonicalUrls returns non-empty map.
   */
  public function testGetAllCanonicalUrls(): void {
    $urls = $this->registry->getAllCanonicalUrls();

    $this->assertNotEmpty($urls);
    $this->assertArrayHasKey('apply', $urls);
    $this->assertEquals('/apply-for-help', $urls['apply']);
  }

  /**
   * Tests override intent enforcement overrides soft-route intents.
   */
  #[DataProvider('overrideIntentProvider')]
  public function testOverrideIntentOverridesSoftRouteIntent(
    string $intent_type,
    ?array $override_intent,
    string $expected_url
  ): void {
    $intent = ['type' => $intent_type, 'area' => 'housing'];
    $response = [
      'type' => 'navigation',
      'primary_action' => ['url' => '/legal-help/housing'],
    ];

    $enforced = $this->registry->enforceCanonicalUrlWithOverrideIntent(
      $response,
      $intent,
      $override_intent
    );

    $this->assertStringContainsStringIgnoringCase(
      strtolower($expected_url),
      strtolower($enforced['primary_action']['url']),
      "Override intent for '$intent_type' should produce '$expected_url'"
    );
  }

  /**
   * Data provider for override-intent tests.
   */
  public static function overrideIntentProvider(): array {
    return [
      'service_area_with_eviction_override' => ['service_area', ['type' => 'high_risk', 'risk_category' => 'high_risk_eviction'], '/apply-for-help'],
      'service_area_with_dv_override' => ['service_area', ['type' => 'high_risk', 'risk_category' => 'high_risk_dv'], '/apply-for-help'],
      'service_area_with_deadline_override' => ['service_area', ['type' => 'high_risk', 'risk_category' => 'high_risk_deadline'], '/Legal-Advice-Line'],
      'service_area_with_scam_override' => ['service_area', ['type' => 'high_risk', 'risk_category' => 'high_risk_scam'], '/apply-for-help'],
      'topic_with_eviction_override' => ['topic', ['type' => 'high_risk', 'risk_category' => 'high_risk_eviction'], '/apply-for-help'],
    ];
  }

  /**
   * Tests that override enforcement does not override when no override is present.
   */
  public function testOverrideIntentEnforcementWithoutOverrideKeepsOriginal(): void {
    $intent = ['type' => 'service_area', 'area' => 'housing'];
    $original_url = '/legal-help/housing';
    $response = [
      'type' => 'navigation',
      'primary_action' => ['url' => $original_url],
    ];

    $enforced = $this->registry->enforceCanonicalUrlWithOverrideIntent(
      $response,
      $intent,
      NULL
    );

    $this->assertEquals($original_url, $enforced['primary_action']['url']);
    $this->assertFalse($enforced['_hard_route_enforced_by_override_intent'] ?? FALSE);
  }

  /**
   * Tests that override-intent enforcement works with ResponseBuilder.
   */
  public function testResponseBuilderOverrideIntentEnforcement(): void {
    $intent = ['type' => 'service_area', 'area' => 'housing'];
    $response = $this->builder->buildFromIntent($intent, 'test');

    $enforced = $this->builder->enforceHardRouteUrlWithOverrideIntent(
      $response,
      $intent,
      ['type' => 'high_risk', 'risk_category' => 'high_risk_eviction']
    );

    $this->assertEquals('/apply-for-help', $enforced['primary_action']['url']);
    $this->assertTrue($enforced['_hard_route_enforced_by_override_intent'] ?? FALSE);
  }

  /**
   * Tests getCanonicalAction convenience method.
   */
  public function testGetCanonicalAction(): void {
    $action = $this->registry->getCanonicalAction('apply');

    $this->assertIsArray($action);
    $this->assertEquals('/apply-for-help', $action['url']);
    $this->assertNotEmpty($action['label']);
  }

  /**
   * Tests getCanonicalAction returns NULL for soft-route.
   */
  public function testGetCanonicalActionReturnsNullForSoftRoute(): void {
    $action = $this->registry->getCanonicalAction('navigation');
    $this->assertNull($action);
  }

}
