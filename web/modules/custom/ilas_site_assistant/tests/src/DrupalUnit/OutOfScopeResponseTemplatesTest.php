<?php

namespace Drupal\Tests\ilas_site_assistant\DrupalUnit;

use Drupal\ilas_site_assistant\Service\OutOfScopeClassifier;
use Drupal\ilas_site_assistant\Service\OutOfScopeResponseTemplates;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Unit tests for OutOfScopeResponseTemplates service.
 *
 * Verifies that out-of-scope responses:
 * - Return type 'escalation' (not 'out_of_scope')
 * - Include appropriate limitation language
 * - Do not hard-route to /services as primary action
 * - Include external referrals where appropriate
 *
 */
#[CoversClass(OutOfScopeResponseTemplates::class)]
#[Group('ilas_site_assistant')]
class OutOfScopeResponseTemplatesTest extends UnitTestCase {

  /**
   * The response templates service.
   *
   * @var \Drupal\ilas_site_assistant\Service\OutOfScopeResponseTemplates
   */
  protected $templates;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->templates = new OutOfScopeResponseTemplates();
    $this->templates->setStringTranslation($this->getStringTranslationStub());
  }

  // =========================================================================
  // CRIMINAL DEFENSE RESPONSE TESTS
  // =========================================================================

  /**
   * Tests criminal defense response returns escalation type.
   */
  public function testCriminalDefenseResponseType(): void {
    $classification = [
      'category' => OutOfScopeClassifier::CATEGORY_CRIMINAL_DEFENSE,
      'reason_code' => 'oos_criminal_arrested',
      'suggestions' => ['Public defender', 'Idaho State Bar'],
    ];

    $response = $this->templates->getResponse($classification);

    // CRITICAL: Must return 'escalation' type, not 'out_of_scope'.
    $this->assertEquals('escalation', $response['type']);
    $this->assertEquals('out_of_scope', $response['response_mode']);
    $this->assertEquals('criminal_defense', $response['escalation_type']);
  }

  /**
   * Tests criminal defense response includes limitation language.
   */
  public function testCriminalDefenseResponseMessage(): void {
    $classification = [
      'category' => OutOfScopeClassifier::CATEGORY_CRIMINAL_DEFENSE,
      'reason_code' => 'oos_criminal_dui',
      'suggestions' => [],
    ];

    $response = $this->templates->getResponse($classification);

    // Must explain ILAS handles civil only.
    $this->assertStringContainsString('civil', strtolower($response['message']));
    // Must mention alternative (public defender).
    $this->assertStringContainsString('public defender', strtolower($response['message']));
    // Must include disclaimer.
    $this->assertNotEmpty($response['disclaimer']);
  }

  /**
   * Tests criminal defense response includes external referral links.
   */
  public function testCriminalDefenseResponseLinks(): void {
    $classification = [
      'category' => OutOfScopeClassifier::CATEGORY_CRIMINAL_DEFENSE,
      'reason_code' => 'oos_criminal_probation',
      'suggestions' => [],
    ];

    $response = $this->templates->getResponse($classification);

    // Must include Idaho State Bar referral.
    $has_bar_referral = FALSE;
    foreach ($response['links'] as $link) {
      if (strpos($link['url'], 'isb.idaho.gov') !== FALSE) {
        $has_bar_referral = TRUE;
        break;
      }
    }
    $this->assertTrue($has_bar_referral, 'Must include Idaho State Bar referral link');
  }

  /**
   * Tests DUI response (specific criminal case).
   */
  public function testDuiResponse(): void {
    $classification = [
      'category' => OutOfScopeClassifier::CATEGORY_CRIMINAL_DEFENSE,
      'reason_code' => 'oos_criminal_dui',
      'suggestions' => [],
    ];

    $response = $this->templates->getResponse($classification);

    $this->assertEquals('escalation', $response['type']);
    $this->assertTrue($response['can_still_help']);
  }

  /**
   * Tests expungement response (edge case - relates to criminal record).
   */
  public function testExpungementResponse(): void {
    $classification = [
      'category' => OutOfScopeClassifier::CATEGORY_CRIMINAL_DEFENSE,
      'reason_code' => 'oos_criminal_expungement',
      'suggestions' => [],
    ];

    $response = $this->templates->getResponse($classification);

    $this->assertEquals('escalation', $response['type']);
    // Expungement is OOS but user might have other civil issues.
    $this->assertTrue($response['can_still_help']);
  }

  // =========================================================================
  // IMMIGRATION RESPONSE TESTS
  // =========================================================================

  /**
   * Tests immigration response returns escalation type.
   */
  public function testImmigrationResponseType(): void {
    $classification = [
      'category' => OutOfScopeClassifier::CATEGORY_IMMIGRATION,
      'reason_code' => 'oos_immigration_visa',
      'suggestions' => [],
    ];

    $response = $this->templates->getResponse($classification);

    $this->assertEquals('escalation', $response['type']);
    $this->assertEquals('out_of_scope', $response['response_mode']);
    $this->assertEquals('immigration', $response['escalation_type']);
  }

  /**
   * Tests immigration response includes specialized referrals.
   */
  public function testImmigrationResponseLinks(): void {
    $classification = [
      'category' => OutOfScopeClassifier::CATEGORY_IMMIGRATION,
      'reason_code' => 'oos_immigration_green_card',
      'suggestions' => [],
    ];

    $response = $this->templates->getResponse($classification);

    // Must include immigration-specific resources.
    $link_urls = array_map(fn($l) => $l['url'], $response['links']);
    $has_immigration_resource = FALSE;
    foreach ($link_urls as $url) {
      if (strpos($url, 'ccidaho.org') !== FALSE || strpos($url, 'icha.idaho.gov') !== FALSE) {
        $has_immigration_resource = TRUE;
        break;
      }
    }
    $this->assertTrue($has_immigration_resource, 'Must include immigration-specific resource');
  }

  /**
   * Tests asylum response (edge case).
   */
  public function testAsylumResponse(): void {
    $classification = [
      'category' => OutOfScopeClassifier::CATEGORY_IMMIGRATION,
      'reason_code' => 'oos_immigration_asylum',
      'suggestions' => [],
    ];

    $response = $this->templates->getResponse($classification);

    $this->assertEquals('escalation', $response['type']);
    $this->assertTrue($response['can_still_help']);
    // Should not route to /services as primary action.
    $primary_url = $response['links'][0]['url'] ?? '';
    $this->assertStringNotContainsString('/services', $primary_url);
  }

  // =========================================================================
  // BUSINESS/COMMERCIAL RESPONSE TESTS
  // =========================================================================

  /**
   * Tests business response returns escalation type.
   */
  public function testBusinessResponseType(): void {
    $classification = [
      'category' => OutOfScopeClassifier::CATEGORY_BUSINESS_COMMERCIAL,
      'reason_code' => 'oos_business_start',
      'suggestions' => [],
    ];

    $response = $this->templates->getResponse($classification);

    $this->assertEquals('escalation', $response['type']);
    $this->assertEquals('business_commercial', $response['escalation_type']);
  }

  /**
   * Tests startup LLC response (edge case).
   */
  public function testStartupLlcResponse(): void {
    $classification = [
      'category' => OutOfScopeClassifier::CATEGORY_BUSINESS_COMMERCIAL,
      'reason_code' => 'oos_business_form',
      'suggestions' => [],
    ];

    $response = $this->templates->getResponse($classification);

    $this->assertEquals('escalation', $response['type']);
    // Must mention SBDC as resource.
    $this->assertStringContainsString('small business development center', strtolower($response['message']));
    // User might have personal legal issues.
    $this->assertTrue($response['can_still_help']);
  }

  /**
   * Tests patent response (edge case - IP).
   */
  public function testPatentResponse(): void {
    $classification = [
      'category' => OutOfScopeClassifier::CATEGORY_BUSINESS_COMMERCIAL,
      'reason_code' => 'oos_ip_patent',
      'suggestions' => [],
    ];

    $response = $this->templates->getResponse($classification);

    $this->assertEquals('escalation', $response['type']);
    // Should include bar referral for patent attorneys.
    $link_urls = array_map(fn($l) => $l['url'], $response['links']);
    $has_bar = FALSE;
    foreach ($link_urls as $url) {
      if (strpos($url, 'isb.idaho.gov') !== FALSE) {
        $has_bar = TRUE;
        break;
      }
    }
    $this->assertTrue($has_bar, 'Patent response should include bar referral');
  }

  // =========================================================================
  // NON-IDAHO RESPONSE TESTS
  // =========================================================================

  /**
   * Tests non-Idaho response returns escalation type.
   */
  public function testNonIdahoResponseType(): void {
    $classification = [
      'category' => OutOfScopeClassifier::CATEGORY_NON_IDAHO,
      'reason_code' => 'oos_location_western',
      'suggestions' => [],
    ];

    $response = $this->templates->getResponse($classification);

    $this->assertEquals('escalation', $response['type']);
    $this->assertEquals('non_idaho', $response['escalation_type']);
    // Non-Idaho users can't be helped.
    $this->assertFalse($response['can_still_help']);
  }

  /**
   * Tests non-Idaho response includes LawHelp.org.
   */
  public function testNonIdahoResponseLinks(): void {
    $classification = [
      'category' => OutOfScopeClassifier::CATEGORY_NON_IDAHO,
      'reason_code' => 'oos_location_other',
      'suggestions' => [],
    ];

    $response = $this->templates->getResponse($classification);

    // Must include LawHelp.org for finding legal aid.
    $link_urls = array_map(fn($l) => $l['url'], $response['links']);
    $has_lawhelp = FALSE;
    foreach ($link_urls as $url) {
      if (strpos($url, 'lawhelp.org') !== FALSE) {
        $has_lawhelp = TRUE;
        break;
      }
    }
    $this->assertTrue($has_lawhelp, 'Non-Idaho response must include LawHelp.org');
  }

  // =========================================================================
  // EMERGENCY SERVICES RESPONSE TESTS
  // =========================================================================

  /**
   * Tests emergency services response returns escalation type.
   */
  public function testEmergencyServicesResponseType(): void {
    $classification = [
      'category' => OutOfScopeClassifier::CATEGORY_EMERGENCY_SERVICES,
      'reason_code' => 'oos_emergency_police',
      'suggestions' => [],
    ];

    $response = $this->templates->getResponse($classification);

    $this->assertEquals('escalation', $response['type']);
    $this->assertEquals('emergency_services', $response['escalation_type']);
  }

  /**
   * Tests emergency services response includes 911.
   */
  public function testEmergencyServicesResponseLinks(): void {
    $classification = [
      'category' => OutOfScopeClassifier::CATEGORY_EMERGENCY_SERVICES,
      'reason_code' => 'oos_emergency_ambulance',
      'suggestions' => [],
    ];

    $response = $this->templates->getResponse($classification);

    // Must include 911 link.
    $link_urls = array_map(fn($l) => $l['url'], $response['links']);
    $has_911 = FALSE;
    foreach ($link_urls as $url) {
      if (strpos($url, 'tel:911') !== FALSE) {
        $has_911 = TRUE;
        break;
      }
    }
    $this->assertTrue($has_911, 'Emergency response must include 911');
  }

  // =========================================================================
  // FEDERAL MATTERS RESPONSE TESTS
  // =========================================================================

  /**
   * Tests federal matters response returns escalation type.
   */
  public function testFederalMattersResponseType(): void {
    $classification = [
      'category' => OutOfScopeClassifier::CATEGORY_FEDERAL_MATTERS,
      'reason_code' => 'oos_federal_bankruptcy',
      'suggestions' => [],
    ];

    $response = $this->templates->getResponse($classification);

    $this->assertEquals('escalation', $response['type']);
    $this->assertEquals('federal_matters', $response['escalation_type']);
  }

  /**
   * Tests bankruptcy-specific response.
   */
  public function testBankruptcyResponse(): void {
    $classification = [
      'category' => OutOfScopeClassifier::CATEGORY_FEDERAL_MATTERS,
      'reason_code' => 'oos_federal_bankruptcy',
      'suggestions' => [],
    ];

    $response = $this->templates->getResponse($classification);

    // Message should be customized for bankruptcy.
    $this->assertStringContainsString('bankruptcy', strtolower($response['message']));
  }

  // =========================================================================
  // HIGH-VALUE CIVIL RESPONSE TESTS
  // =========================================================================

  /**
   * Tests high-value civil response returns escalation type.
   */
  public function testHighValueCivilResponseType(): void {
    $classification = [
      'category' => OutOfScopeClassifier::CATEGORY_HIGH_VALUE_CIVIL,
      'reason_code' => 'oos_civil_personal_injury',
      'suggestions' => [],
    ];

    $response = $this->templates->getResponse($classification);

    $this->assertEquals('escalation', $response['type']);
    $this->assertEquals('high_value_civil', $response['escalation_type']);
  }

  /**
   * Tests personal injury response mentions contingency fees.
   */
  public function testPersonalInjuryResponseMessage(): void {
    $classification = [
      'category' => OutOfScopeClassifier::CATEGORY_HIGH_VALUE_CIVIL,
      'reason_code' => 'oos_civil_auto_accident_sue',
      'suggestions' => [],
    ];

    $response = $this->templates->getResponse($classification);

    // Should mention contingency arrangement.
    $this->assertStringContainsString('contingency', strtolower($response['message']));
    // User might have other civil issues.
    $this->assertTrue($response['can_still_help']);
  }

  // =========================================================================
  // GOLDEN DATASET EDGE CASE TESTS
  // =========================================================================

  /**
   * Tests all OOS eval golden dataset utterances get escalation type.
   *
   * These are the specific utterances from the golden dataset that should
   * return "Explain limitation" responses.
   */
  #[DataProvider('goldenDatasetOosProvider')]
  public function testGoldenDatasetOosResponses(string $reason_code, string $category): void {
    $classification = [
      'category' => $category,
      'reason_code' => $reason_code,
      'suggestions' => [],
    ];

    $response = $this->templates->getResponse($classification);

    // ALL OOS responses must return type 'escalation'.
    $this->assertEquals('escalation', $response['type'], "Response for $reason_code must be type 'escalation'");
  }

  /**
   * Data provider for golden dataset OOS cases.
   */
  public static function goldenDatasetOosProvider(): array {
    return [
      'criminal_defense_lawyer' => ['oos_criminal_representation', OutOfScopeClassifier::CATEGORY_CRIMINAL_DEFENSE],
      'dui' => ['oos_criminal_dui', OutOfScopeClassifier::CATEGORY_CRIMINAL_DEFENSE],
      'arrested' => ['oos_criminal_arrested', OutOfScopeClassifier::CATEGORY_CRIMINAL_DEFENSE],
      'oregon' => ['oos_location_western', OutOfScopeClassifier::CATEGORY_NON_IDAHO],
      'washington' => ['oos_location_western', OutOfScopeClassifier::CATEGORY_NON_IDAHO],
      'immigration_lawyer' => ['oos_immigration_general', OutOfScopeClassifier::CATEGORY_IMMIGRATION],
      'green_card' => ['oos_immigration_green_card_apply', OutOfScopeClassifier::CATEGORY_IMMIGRATION],
      'emergency_breaking_in' => ['oos_emergency_intrusion', OutOfScopeClassifier::CATEGORY_EMERGENCY_SERVICES],
      'call_911' => ['oos_emergency_police', OutOfScopeClassifier::CATEGORY_EMERGENCY_SERVICES],
      'sue_million_dollars' => ['oos_civil_large_monetary', OutOfScopeClassifier::CATEGORY_HIGH_VALUE_CIVIL],
      'start_business' => ['oos_business_start', OutOfScopeClassifier::CATEGORY_BUSINESS_COMMERCIAL],
      'patent' => ['oos_ip_patent', OutOfScopeClassifier::CATEGORY_BUSINESS_COMMERCIAL],
    ];
  }

  // =========================================================================
  // BRIEF EXPLANATION TESTS
  // =========================================================================

  /**
   * Tests brief explanations are provided for all categories.
   */
  public function testBriefExplanations(): void {
    $categories = [
      OutOfScopeClassifier::CATEGORY_CRIMINAL_DEFENSE,
      OutOfScopeClassifier::CATEGORY_IMMIGRATION,
      OutOfScopeClassifier::CATEGORY_NON_IDAHO,
      OutOfScopeClassifier::CATEGORY_EMERGENCY_SERVICES,
      OutOfScopeClassifier::CATEGORY_BUSINESS_COMMERCIAL,
      OutOfScopeClassifier::CATEGORY_FEDERAL_MATTERS,
      OutOfScopeClassifier::CATEGORY_HIGH_VALUE_CIVIL,
    ];

    foreach ($categories as $category) {
      $explanation = $this->templates->getBriefExplanation($category);
      $this->assertNotEmpty($explanation, "Brief explanation missing for $category");
    }
  }

  /**
   * Tests Spanish brief explanations are provided.
   */
  public function testSpanishBriefExplanations(): void {
    $explanation = $this->templates->getBriefExplanationSpanish(OutOfScopeClassifier::CATEGORY_CRIMINAL_DEFENSE);
    $this->assertNotEmpty($explanation);
    // Should be in Spanish.
    $this->assertStringContainsString('ILAS', $explanation);
  }

}
