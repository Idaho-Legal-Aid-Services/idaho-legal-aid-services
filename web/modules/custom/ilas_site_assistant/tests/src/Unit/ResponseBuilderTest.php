<?php

namespace Drupal\Tests\ilas_site_assistant\Unit;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

// Load the ResponseBuilder directly (no Drupal bootstrap needed).
require_once __DIR__ . '/../../../src/Service/ResponseBuilder.php';
require_once __DIR__ . '/../Support/CanonicalUrlFixtures.php';

use Drupal\ilas_site_assistant\Service\ResponseBuilder;
use Drupal\Tests\ilas_site_assistant\Support\CanonicalUrlFixtures;

/**
 * Regression tests for the shared ResponseBuilder.
 *
 * These tests ensure that every navigable intent produces a response
 * with the correct canonical URL in primary_action. If any of these
 * fail, the API eval pass rate will drop.
 */
#[Group('ilas_site_assistant')]
class ResponseBuilderTest extends TestCase {

  /**
   * The response builder under test.
   *
   * @var \Drupal\ilas_site_assistant\Service\ResponseBuilder
   */
  protected $builder;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->builder = new ResponseBuilder(CanonicalUrlFixtures::defaults());
  }

  /**
   * Tests that apply_for_help intent returns apply_cta with LegalServer URL.
   */
  public function testApplyForHelpReturnsApplyUrl(): void {
    $response = $this->builder->buildFromIntent(['type' => 'apply_for_help']);
    $expected = CanonicalUrlFixtures::defaults()['online_application'];

    $this->assertNotNull($response['primary_action'], 'apply_for_help must have primary_action');
    $this->assertSame($expected, $response['primary_action']['url'],
      'apply_for_help primary action must point to the configured online intake URL');
    $this->assertEquals('navigate', $response['response_mode']);
    $this->assertEquals('apply_cta', $response['type']);
  }

  /**
   * Tests the legacy 'apply' intent also works.
   */
  public function testApplyLegacyReturnsApplyUrl(): void {
    $response = $this->builder->buildFromIntent(['type' => 'apply']);
    $this->assertSame(CanonicalUrlFixtures::defaults()['online_application'], $response['primary_action']['url']);
  }

  /**
   * Tests that offices_contact returns /contact/offices action.
   */
  public function testOfficesContactReturnsOfficesUrl(): void {
    $response = $this->builder->buildFromIntent(['type' => 'offices_contact']);

    $this->assertNotNull($response['primary_action']);
    $this->assertEquals('/contact/offices', $response['primary_action']['url'],
      'offices_contact intent must return /contact/offices as primary action URL');
    $this->assertEquals('navigate', $response['response_mode']);
  }

  /**
   * Tests the legacy 'offices' intent also works.
   */
  public function testOfficesLegacyReturnsOfficesUrl(): void {
    $response = $this->builder->buildFromIntent(['type' => 'offices']);

    $this->assertEquals('/contact/offices', $response['primary_action']['url']);
  }

  /**
   * Tests that legal_advice_line returns hotline URL.
   */
  public function testLegalAdviceLineReturnsHotlineUrl(): void {
    $response = $this->builder->buildFromIntent(['type' => 'legal_advice_line']);

    $this->assertNotNull($response['primary_action']);
    $this->assertStringContainsStringIgnoringCase('legal-advice-line',
      $response['primary_action']['url'],
      'legal_advice_line must return Legal-Advice-Line URL');
  }

  /**
   * Tests that donations returns /donate URL.
   */
  public function testDonationsReturnsDonateUrl(): void {
    $response = $this->builder->buildFromIntent(['type' => 'donations']);

    $this->assertNotNull($response['primary_action']);
    $this->assertEquals('/donate', $response['primary_action']['url']);
  }

  /**
   * Tests that forms_finder returns /forms URL.
   */
  public function testFormsFinderReturnsFormsUrl(): void {
    $response = $this->builder->buildFromIntent(['type' => 'forms_finder']);

    $this->assertNotNull($response['primary_action']);
    $this->assertEquals('/forms', $response['primary_action']['url']);
  }

  /**
   * Tests that guides_finder returns /guides URL.
   */
  public function testGuidesFinderReturnsGuidesUrl(): void {
    $response = $this->builder->buildFromIntent(['type' => 'guides_finder']);

    $this->assertNotNull($response['primary_action']);
    $this->assertEquals('/guides', $response['primary_action']['url']);
  }

  /**
   * Tests that services_overview returns /apply-for-help as primary action.
   *
   * The golden dataset expects services_overview → /apply-for-help because
   * the main CTA should drive users to apply, with /services as secondary.
   */
  public function testServicesOverviewReturnsApplyUrl(): void {
    $response = $this->builder->buildFromIntent(['type' => 'services_overview']);

    $this->assertNotNull($response['primary_action']);
    $this->assertEquals('/apply-for-help', $response['primary_action']['url'],
      'services_overview primary action must be /apply-for-help per golden dataset');

    // /services should be in secondary_actions.
    $secondary_urls = array_map(fn($a) => $a['url'], $response['secondary_actions']);
    $this->assertContains('/services', $secondary_urls,
      '/services must be in secondary_actions for services_overview');
  }

  /**
   * Tests that feedback returns /get-involved/feedback URL.
   */
  public function testFeedbackReturnsFeedbackUrl(): void {
    $response = $this->builder->buildFromIntent(['type' => 'feedback']);

    $this->assertNotNull($response['primary_action']);
    $this->assertEquals('/get-involved/feedback', $response['primary_action']['url']);
  }

  /**
   * Tests that senior_risk_detector returns correct URL.
   */
  public function testRiskDetectorReturnsRiskUrl(): void {
    $response = $this->builder->buildFromIntent(['type' => 'risk_detector']);

    $this->assertNotNull($response['primary_action']);
    $this->assertEquals('/resources/legal-risk-detector', $response['primary_action']['url']);
  }

  /**
   * Tests that FAQ intent returns /faq URL.
   */
  public function testFaqReturnsFaqUrl(): void {
    $response = $this->builder->buildFromIntent(['type' => 'faq']);

    $this->assertNotNull($response['primary_action']);
    $this->assertEquals('/faq', $response['primary_action']['url']);
  }

  /**
   * Tests that high_risk_dv returns /apply-for-help as primary action.
   */
  public function testHighRiskDvReturnsApplyUrl(): void {
    $response = $this->builder->buildFromIntent([
      'type' => 'high_risk',
      'risk_category' => 'high_risk_dv',
    ]);

    $this->assertNotNull($response['primary_action']);
    $this->assertEquals('/apply-for-help', $response['primary_action']['url'],
      'high_risk_dv must include /apply-for-help as primary action');
    $this->assertEquals('escalation', $response['type']);
    $this->assertStringContainsString('911', $response['answer_text'],
      'DV response must mention 911');
  }

  /**
   * Tests that high_risk_deadline returns hotline URL.
   */
  public function testHighRiskDeadlineReturnsHotlineUrl(): void {
    $response = $this->builder->buildFromIntent([
      'type' => 'high_risk',
      'risk_category' => 'high_risk_deadline',
    ]);

    $this->assertNotNull($response['primary_action']);
    $this->assertStringContainsStringIgnoringCase('legal-advice-line',
      $response['primary_action']['url'],
      'high_risk_deadline must include Legal-Advice-Line as primary action');
  }

  /**
   * Tests that high_risk_eviction returns /apply-for-help.
   */
  public function testHighRiskEvictionReturnsApplyUrl(): void {
    $response = $this->builder->buildFromIntent([
      'type' => 'high_risk',
      'risk_category' => 'high_risk_eviction',
    ]);

    $this->assertNotNull($response['primary_action']);
    $this->assertEquals('/apply-for-help', $response['primary_action']['url']);
  }

  /**
   * Tests that high_risk_scam returns /apply-for-help.
   */
  public function testHighRiskScamReturnsApplyUrl(): void {
    $response = $this->builder->buildFromIntent([
      'type' => 'high_risk',
      'risk_category' => 'high_risk_scam',
    ]);

    $this->assertNotNull($response['primary_action']);
    $this->assertEquals('/apply-for-help', $response['primary_action']['url']);
  }

  /**
   * Tests that every response has the canonical contract fields.
   */
  #[\PHPUnit\Framework\Attributes\DataProvider('intentProvider')]
  public function testResponseContract(string $intent_type, array $extra = []): void {
    $intent = array_merge(['type' => $intent_type], $extra);
    $response = $this->builder->buildFromIntent($intent);

    // Every response must have these fields.
    $this->assertArrayHasKey('intent_selected', $response);
    $this->assertArrayHasKey('intent_confidence', $response);
    $this->assertArrayHasKey('response_mode', $response);
    $this->assertArrayHasKey('primary_action', $response);
    $this->assertArrayHasKey('secondary_actions', $response);
    $this->assertArrayHasKey('answer_text', $response);
    $this->assertArrayHasKey('reason_code', $response);
    $this->assertArrayHasKey('type', $response);

    // response_mode must be valid.
    $valid_modes = ['navigate', 'topic', 'answer', 'clarify', 'fallback'];
    $this->assertContains($response['response_mode'], $valid_modes,
      "Intent '$intent_type' has invalid response_mode: {$response['response_mode']}");

    // For navigate/topic modes, primary_action must have url.
    if (in_array($response['response_mode'], ['navigate', 'topic'])) {
      $this->assertNotNull($response['primary_action'],
        "Intent '$intent_type' in {$response['response_mode']} mode must have primary_action");
      $this->assertNotEmpty($response['primary_action']['url'] ?? '',
        "Intent '$intent_type' primary_action must have URL");
    }
  }

  /**
   * Data provider for testResponseContract.
   */
  public static function intentProvider(): array {
    return [
      'apply_for_help' => ['apply_for_help'],
      'apply' => ['apply'],
      'legal_advice_line' => ['legal_advice_line'],
      'hotline' => ['hotline'],
      'offices_contact' => ['offices_contact'],
      'offices' => ['offices'],
      'donations' => ['donations'],
      'donate' => ['donate'],
      'forms_finder' => ['forms_finder'],
      'forms' => ['forms'],
      'guides_finder' => ['guides_finder'],
      'guides' => ['guides'],
      'services_overview' => ['services_overview'],
      'services' => ['services'],
      'feedback' => ['feedback'],
      'risk_detector' => ['risk_detector'],
      'faq' => ['faq'],
      'greeting' => ['greeting'],
      'eligibility' => ['eligibility'],
      'clarify' => ['clarify'],
      'unknown' => ['unknown'],
      'high_risk_dv' => ['high_risk', ['risk_category' => 'high_risk_dv']],
      'high_risk_eviction' => ['high_risk', ['risk_category' => 'high_risk_eviction']],
      'high_risk_scam' => ['high_risk', ['risk_category' => 'high_risk_scam']],
      'high_risk_deadline' => ['high_risk', ['risk_category' => 'high_risk_deadline']],
      'out_of_scope' => ['out_of_scope'],
      'resources' => ['resources'],
    ];
  }

  /**
   * Tests that intent aliases map correctly.
   */
  public function testIntentAliases(): void {
    $aliases = ResponseBuilder::getIntentAliases();

    $this->assertEquals('apply', $aliases['apply_for_help']);
    $this->assertEquals('hotline', $aliases['legal_advice_line']);
    $this->assertEquals('offices', $aliases['offices_contact']);
    $this->assertEquals('donate', $aliases['donations']);
    $this->assertEquals('forms', $aliases['forms_finder']);
    $this->assertEquals('guides', $aliases['guides_finder']);
    $this->assertEquals('services', $aliases['services_overview']);
  }

  /**
   * Tests resolveIntentUrl returns correct URLs for all navigable intents.
   */
  public function testResolveIntentUrl(): void {
    $this->assertEquals('/apply-for-help', $this->builder->resolveIntentUrl('apply_for_help'));
    $this->assertEquals('/apply-for-help', $this->builder->resolveIntentUrl('apply'));
    $this->assertStringContainsStringIgnoringCase('legal-advice-line',
      $this->builder->resolveIntentUrl('legal_advice_line'));
    $this->assertEquals('/contact/offices', $this->builder->resolveIntentUrl('offices_contact'));
    $this->assertEquals('/donate', $this->builder->resolveIntentUrl('donations'));
    $this->assertEquals('/forms', $this->builder->resolveIntentUrl('forms_finder'));
    $this->assertEquals('/guides', $this->builder->resolveIntentUrl('guides_finder'));
    $this->assertEquals('/services', $this->builder->resolveIntentUrl('services_overview'));
    $this->assertEquals('/get-involved/feedback', $this->builder->resolveIntentUrl('feedback'));
    $this->assertEquals('/resources/legal-risk-detector', $this->builder->resolveIntentUrl('risk_detector'));
    $this->assertNull($this->builder->resolveIntentUrl('nonexistent_intent'));
  }

  /**
   * Tests that extractPrimaryActionUrl works correctly.
   */
  public function testExtractPrimaryActionUrl(): void {
    $response = $this->builder->buildFromIntent(['type' => 'apply_for_help']);
    $this->assertSame(CanonicalUrlFixtures::defaults()['online_application'], ResponseBuilder::extractPrimaryActionUrl($response));

    $response = $this->builder->buildFromIntent(['type' => 'offices_contact']);
    $this->assertEquals('/contact/offices', ResponseBuilder::extractPrimaryActionUrl($response));
  }

}
