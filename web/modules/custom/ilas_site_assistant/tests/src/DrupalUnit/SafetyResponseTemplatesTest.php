<?php

namespace Drupal\Tests\ilas_site_assistant\DrupalUnit;

use Drupal\ilas_site_assistant\Service\SafetyClassifier;
use Drupal\ilas_site_assistant\Service\SafetyResponseTemplates;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Unit tests for SafetyResponseTemplates service.
 *
 * Verifies that safety responses:
 * - Return type 'escalation' for OOS cases (criminal, immigration, external)
 * - Include appropriate limitation language
 * - Do not hard-route to /services as primary action for OOS
 * - Include external referrals where appropriate
 *
 */
#[CoversClass(SafetyResponseTemplates::class)]
#[Group('ilas_site_assistant')]
class SafetyResponseTemplatesTest extends UnitTestCase {

  /**
   * The response templates service.
   *
   * @var \Drupal\ilas_site_assistant\Service\SafetyResponseTemplates
   */
  protected $templates;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->templates = new SafetyResponseTemplates();
    $this->templates->setStringTranslation($this->getStringTranslationStub());
  }

  // =========================================================================
  // CRIMINAL (SAFETY) RESPONSE TESTS
  // =========================================================================

  /**
   * Tests criminal (safety) response returns escalation type.
   */
  public function testCriminalResponseType(): void {
    $classification = [
      'class' => SafetyClassifier::CLASS_CRIMINAL,
      'reason_code' => 'out_of_scope_criminal_arrest',
    ];

    $response = $this->templates->getResponse($classification);

    // CRITICAL: Must return 'escalation' type, not 'out_of_scope'.
    $this->assertEquals('escalation', $response['type']);
    $this->assertEquals('out_of_scope', $response['response_mode']);
    $this->assertEquals('criminal', $response['escalation_type']);
  }

  /**
   * Tests criminal response includes proper limitation message.
   */
  public function testCriminalResponseMessage(): void {
    $classification = [
      'class' => SafetyClassifier::CLASS_CRIMINAL,
      'reason_code' => 'out_of_scope_criminal_dui',
    ];

    $response = $this->templates->getResponse($classification);

    // Must explain civil only.
    $this->assertStringContainsString('civil', strtolower($response['message']));
    // Must mention public defender.
    $this->assertStringContainsString('public defender', strtolower($response['message']));
    // Must include bar referral info.
    $this->assertStringContainsString('idaho state bar', strtolower($response['message']));
  }

  /**
   * Tests criminal response includes proper links.
   */
  public function testCriminalResponseLinks(): void {
    $classification = [
      'class' => SafetyClassifier::CLASS_CRIMINAL,
      'reason_code' => 'out_of_scope_criminal_charge',
    ];

    $response = $this->templates->getResponse($classification);

    // Must include bar referral link.
    $has_bar_link = FALSE;
    foreach ($response['links'] as $link) {
      if (strpos($link['url'], 'isb.idaho.gov') !== FALSE) {
        $has_bar_link = TRUE;
        break;
      }
    }
    $this->assertTrue($has_bar_link, 'Criminal response must include Idaho State Bar link');
  }

  /**
   * Tests criminal response allows helping with civil issues.
   */
  public function testCriminalResponseCanStillHelp(): void {
    $classification = [
      'class' => SafetyClassifier::CLASS_CRIMINAL,
      'reason_code' => 'out_of_scope_criminal_representation',
    ];

    $response = $this->templates->getResponse($classification);

    $this->assertTrue($response['can_still_help']);
  }

  // =========================================================================
  // IMMIGRATION (SAFETY) RESPONSE TESTS
  // =========================================================================

  /**
   * Tests immigration (safety) response returns escalation type.
   */
  public function testImmigrationResponseType(): void {
    $classification = [
      'class' => SafetyClassifier::CLASS_IMMIGRATION,
      'reason_code' => 'out_of_scope_immigration',
    ];

    $response = $this->templates->getResponse($classification);

    // CRITICAL: Must return 'escalation' type, not 'out_of_scope'.
    $this->assertEquals('escalation', $response['type']);
    $this->assertEquals('out_of_scope', $response['response_mode']);
    $this->assertEquals('immigration', $response['escalation_type']);
  }

  /**
   * Tests immigration response includes proper resources.
   */
  public function testImmigrationResponseLinks(): void {
    $classification = [
      'class' => SafetyClassifier::CLASS_IMMIGRATION,
      'reason_code' => 'out_of_scope_immigration_visa',
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
    $this->assertTrue($has_immigration_resource, 'Immigration response must include immigration-specific resources');
  }

  /**
   * Tests immigration response allows helping with civil issues.
   */
  public function testImmigrationResponseCanStillHelp(): void {
    $classification = [
      'class' => SafetyClassifier::CLASS_IMMIGRATION,
      'reason_code' => 'out_of_scope_immigration_asylum',
    ];

    $response = $this->templates->getResponse($classification);

    $this->assertTrue($response['can_still_help']);
  }

  // =========================================================================
  // EXTERNAL (SAFETY) RESPONSE TESTS
  // =========================================================================

  /**
   * Tests external (safety) response returns escalation type.
   */
  public function testExternalResponseType(): void {
    $classification = [
      'class' => SafetyClassifier::CLASS_EXTERNAL,
      'reason_code' => 'external_gov_website',
    ];

    $response = $this->templates->getResponse($classification);

    // CRITICAL: Must return 'escalation' type, not 'out_of_scope'.
    $this->assertEquals('escalation', $response['type']);
    $this->assertEquals('out_of_scope', $response['response_mode']);
    $this->assertEquals('external', $response['escalation_type']);
  }

  // =========================================================================
  // SAFETY ESCALATION RESPONSE TESTS (Non-OOS)
  // =========================================================================

  /**
   * Tests crisis response returns escalation type.
   */
  public function testCrisisResponseType(): void {
    $classification = [
      'class' => SafetyClassifier::CLASS_CRISIS,
      'reason_code' => 'crisis_suicide',
    ];

    $response = $this->templates->getResponse($classification);

    $this->assertEquals('escalation', $response['type']);
    $this->assertEquals('critical', $response['escalation_level']);
  }

  /**
   * Tests DV emergency response returns escalation type.
   */
  public function testDvEmergencyResponseType(): void {
    $classification = [
      'class' => SafetyClassifier::CLASS_DV_EMERGENCY,
      'reason_code' => 'emergency_dv',
    ];

    $response = $this->templates->getResponse($classification);

    $this->assertEquals('escalation', $response['type']);
    $this->assertEquals('immediate', $response['escalation_level']);
  }

  /**
   * Tests eviction emergency response returns escalation type.
   */
  public function testEvictionEmergencyResponseType(): void {
    $classification = [
      'class' => SafetyClassifier::CLASS_EVICTION_EMERGENCY,
      'reason_code' => 'emergency_lockout',
    ];

    $response = $this->templates->getResponse($classification);

    $this->assertEquals('escalation', $response['type']);
  }

  /**
   * Tests child safety response returns escalation type.
   */
  public function testChildSafetyResponseType(): void {
    $classification = [
      'class' => SafetyClassifier::CLASS_CHILD_SAFETY,
      'reason_code' => 'emergency_child_abuse',
    ];

    $response = $this->templates->getResponse($classification);

    $this->assertEquals('escalation', $response['type']);
    $this->assertEquals('immediate', $response['escalation_level']);
  }

  /**
   * Tests scam response returns escalation type.
   */
  public function testScamResponseType(): void {
    $classification = [
      'class' => SafetyClassifier::CLASS_SCAM_ACTIVE,
      'reason_code' => 'emergency_scam',
    ];

    $response = $this->templates->getResponse($classification);

    $this->assertEquals('escalation', $response['type']);
  }

  /**
   * Tests legal advice response returns escalation type.
   */
  public function testLegalAdviceResponseType(): void {
    $classification = [
      'class' => SafetyClassifier::CLASS_LEGAL_ADVICE,
      'reason_code' => 'legal_advice_should',
    ];

    $response = $this->templates->getResponse($classification);

    $this->assertEquals('escalation', $response['type']);
  }

  /**
   * Tests document drafting response returns escalation type.
   */
  public function testDocumentDraftingResponseType(): void {
    $classification = [
      'class' => SafetyClassifier::CLASS_DOCUMENT_DRAFTING,
      'reason_code' => 'document_drafting_create',
    ];

    $response = $this->templates->getResponse($classification);

    $this->assertEquals('escalation', $response['type']);
  }

  /**
   * Tests frustration response returns escalation type.
   */
  public function testFrustrationResponseType(): void {
    $classification = [
      'class' => SafetyClassifier::CLASS_FRUSTRATION,
      'reason_code' => 'frustration_unhelpful',
    ];

    $response = $this->templates->getResponse($classification);

    $this->assertEquals('escalation', $response['type']);
  }

  // =========================================================================
  // SAFE RESPONSE TESTS
  // =========================================================================

  /**
   * Tests safe response returns safe type.
   */
  public function testSafeResponseType(): void {
    $classification = [
      'class' => SafetyClassifier::CLASS_SAFE,
      'reason_code' => 'safe_no_concerns',
    ];

    $response = $this->templates->getResponse($classification);

    $this->assertEquals('safe', $response['type']);
    $this->assertEquals('none', $response['escalation_level']);
    $this->assertNull($response['message']);
  }

  // =========================================================================
  // SPECIAL CASE TESTS
  // =========================================================================

  /**
   * Tests wrongdoing response returns refusal type.
   */
  public function testWrongdoingResponseType(): void {
    $classification = [
      'class' => SafetyClassifier::CLASS_WRONGDOING,
      'reason_code' => 'wrongdoing_threat',
    ];

    $response = $this->templates->getResponse($classification);

    $this->assertEquals('refusal', $response['type']);
  }

  /**
   * Tests PII response returns privacy type.
   */
  public function testPiiResponseType(): void {
    $classification = [
      'class' => SafetyClassifier::CLASS_PII,
      'reason_code' => 'pii_ssn',
    ];

    $response = $this->templates->getResponse($classification);

    $this->assertEquals('privacy', $response['type']);
  }

}
