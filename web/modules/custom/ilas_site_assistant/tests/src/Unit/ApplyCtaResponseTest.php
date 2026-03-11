<?php

namespace Drupal\Tests\ilas_site_assistant\Unit;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

// Load the services directly (no Drupal bootstrap needed).
require_once __DIR__ . '/../../../src/Service/ResponseBuilder.php';
require_once __DIR__ . '/../../../src/Service/HardRouteRegistry.php';
require_once __DIR__ . '/../Support/CanonicalUrlFixtures.php';

use Drupal\ilas_site_assistant\Service\ResponseBuilder;
use Drupal\Tests\ilas_site_assistant\Support\CanonicalUrlFixtures;

/**
 * Regression tests for the Apply CTA deterministic response.
 *
 * Asserts that:
 * - Clicking Apply always returns a structured CTA response (no FAQ dump).
 * - The response includes all three methods + correct links.
 * - The online application URL comes from config (LegalServer intake).
 * - Typed utterances like "apply", "apply for help" also produce CTA.
 */
#[Group('ilas_site_assistant')]
class ApplyCtaResponseTest extends TestCase {

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
   * Tests that apply_for_help intent returns apply_cta type.
   */
  public function testApplyForHelpReturnsApplyCtaType(): void {
    $response = $this->builder->buildFromIntent(['type' => 'apply_for_help']);

    $this->assertEquals('apply_cta', $response['type'],
      'apply_for_help intent must return type=apply_cta, not navigation or faq');
  }

  /**
   * Tests that legacy 'apply' alias also returns apply_cta type.
   */
  public function testApplyLegacyReturnsApplyCtaType(): void {
    $response = $this->builder->buildFromIntent(['type' => 'apply']);

    $this->assertEquals('apply_cta', $response['type']);
  }

  /**
   * Tests that the primary action URL is the online application.
   */
  public function testPrimaryActionIsOnlineApplication(): void {
    $response = $this->builder->buildFromIntent(['type' => 'apply_for_help']);

    $this->assertNotNull($response['primary_action'], 'Must have primary_action');
    $this->assertSame(CanonicalUrlFixtures::defaults()['online_application'], $response['primary_action']['url'],
      'Primary action URL must point to the configured online intake URL');
    $this->assertEquals('Start online application', $response['primary_action']['label']);
  }

  /**
   * Tests that secondary actions include phone and office links.
   */
  public function testSecondaryActionsIncludePhoneAndOffice(): void {
    $response = $this->builder->buildFromIntent(['type' => 'apply_for_help']);

    $this->assertNotEmpty($response['secondary_actions'], 'Must have secondary_actions');
    $this->assertCount(2, $response['secondary_actions']);

    $labels = array_column($response['secondary_actions'], 'label');
    $this->assertContains('Call (208) 746-7541', $labels);
    $this->assertContains('Find an office', $labels);

    $urls = array_column($response['secondary_actions'], 'url');
    $this->assertContains('tel:208-746-7541', $urls);
    $this->assertContains('/contact/offices', $urls);
  }

  /**
   * Tests that the answer text is the CTA-first intro copy.
   */
  public function testAnswerTextIsCtaIntro(): void {
    $response = $this->builder->buildFromIntent(['type' => 'apply_for_help']);

    $this->assertStringContainsString('three ways to apply', $response['answer_text']);
  }

  /**
   * Tests that the response mode is navigate (not answer/faq).
   */
  public function testResponseModeIsNavigate(): void {
    $response = $this->builder->buildFromIntent(['type' => 'apply_for_help']);

    $this->assertEquals('navigate', $response['response_mode']);
  }

  /**
   * Tests that the reason code is correct.
   */
  public function testReasonCode(): void {
    $response = $this->builder->buildFromIntent(['type' => 'apply_for_help']);

    $this->assertEquals('direct_navigation_apply', $response['reason_code']);
  }

  /**
   * Tests that the online_application URL is in default canonical URLs.
   */
  public function testCanonicalUrlFixtureIncludesOnlineApplication(): void {
    $urls = CanonicalUrlFixtures::defaults();

    $this->assertArrayHasKey('online_application', $urls,
      'Canonical URLs must include online_application key');
    $this->assertStringContainsString('intake', $urls['online_application']);
  }

  /**
   * Tests that apply response type is never 'faq'.
   *
   * Regression: Clicking Apply must return CTA, never FAQ dump.
   */
  public function testApplyNeverReturnsFaqType(): void {
    $response = $this->builder->buildFromIntent(['type' => 'apply_for_help']);
    $this->assertNotEquals('faq', $response['type'],
      'apply_for_help must never return type=faq');

    $response = $this->builder->buildFromIntent(['type' => 'apply']);
    $this->assertNotEquals('faq', $response['type'],
      'apply (legacy) must never return type=faq');
  }

  /**
   * Tests that apply response includes all three canonical URLs.
   *
   * Regression: The CTA must include online app, phone, and office links.
   */
  public function testApplyResponseIncludesAllThreeLinks(): void {
    $response = $this->builder->buildFromIntent(['type' => 'apply_for_help']);

    // Primary action must be the configured online application URL.
    $this->assertSame(CanonicalUrlFixtures::defaults()['online_application'], $response['primary_action']['url'],
      'Primary action must link to the configured online intake URL');

    // Secondary actions must include phone and office.
    $secondary_urls = array_column($response['secondary_actions'], 'url');
    $this->assertContains('tel:208-746-7541', $secondary_urls,
      'Secondary actions must include phone number');
    $this->assertContains('/contact/offices', $secondary_urls,
      'Secondary actions must include offices link');
  }

  /**
   * Tests that eligibility intent does NOT return apply_cta type.
   *
   * Regression: "Am I eligible?" and "What documents do I need?" should
   * route to eligibility/FAQ, not to the Apply CTA.
   */
  public function testEligibilityIsNotApplyCta(): void {
    $response = $this->builder->buildFromIntent(['type' => 'eligibility']);

    $this->assertNotEquals('apply_cta', $response['type'],
      'eligibility intent must not return type=apply_cta');
    $this->assertEquals('eligibility', $response['type']);
  }

}
