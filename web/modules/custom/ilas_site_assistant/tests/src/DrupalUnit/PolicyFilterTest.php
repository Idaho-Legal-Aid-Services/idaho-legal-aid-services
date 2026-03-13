<?php

namespace Drupal\Tests\ilas_site_assistant\DrupalUnit;

use Drupal\Tests\UnitTestCase;
use Drupal\ilas_site_assistant\Service\PolicyFilter;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Unit tests for PolicyFilter service.
 *
 */
#[CoversClass(PolicyFilter::class)]
#[Group('ilas_site_assistant')]
class PolicyFilterTest extends UnitTestCase {

  /**
   * The policy filter service.
   *
   * @var \Drupal\ilas_site_assistant\Service\PolicyFilter
   */
  protected $policyFilter;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Create mock config.
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturn(NULL);

    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')
      ->with('ilas_site_assistant.settings')
      ->willReturn($config);

    $this->policyFilter = new PolicyFilter($configFactory);
    $this->policyFilter->setStringTranslation($this->getStringTranslationStub());
  }

  /**
   * Tests emergency detection for domestic violence.
   */
  #[DataProvider('emergencyDvProvider')]
  public function testEmergencyDvDetection(string $message, bool $expectViolation): void {
    $result = $this->policyFilter->check($message);

    $this->assertEquals($expectViolation, $result['violation']);
    if ($expectViolation) {
      $this->assertEquals('emergency', $result['type']);
      $this->assertEquals('immediate', $result['escalation_level']);
    }
  }

  /**
   * Data provider for DV emergency tests.
   */
  public static function emergencyDvProvider(): array {
    return [
      'explicit dv' => ['my husband is hitting me', TRUE],
      'domestic violence' => ['i need help with domestic violence', TRUE],
      'afraid for life' => ['im afraid for my life', TRUE],
      'threatened to kill' => ['he threatened to kill me', TRUE],
      'stalking' => ['someone is stalking me', TRUE],
      'normal question' => ['how do i apply for help', FALSE],
      'housing question' => ['i need help with my lease', FALSE],
    ];
  }

  /**
   * Tests suicide/crisis detection.
   */
  public function testCrisisDetection(): void {
    $result = $this->policyFilter->check('i want to kill myself');

    $this->assertTrue($result['violation']);
    $this->assertEquals('emergency', $result['type']);
    $this->assertStringContainsString('988', $result['response']);
  }

  /**
   * Tests PII detection.
   */
  #[DataProvider('piiProvider')]
  public function testPiiDetection(string $message, bool $expectViolation): void {
    $result = $this->policyFilter->check($message);

    $this->assertEquals($expectViolation, $result['violation']);
    if ($expectViolation) {
      $this->assertEquals('pii', $result['type']);
    }
  }

  /**
   * Data provider for PII tests.
   */
  public static function piiProvider(): array {
    return [
      'email address' => ['my email is john@example.com', TRUE],
      'phone number' => ['call me at 208-555-1234', TRUE],
      'ssn' => ['my ssn is 123-45-6789', TRUE],
      'case number' => ['case number CV-2024-12345', TRUE],
      'no pii' => ['i need help with eviction', FALSE],
    ];
  }

  /**
   * Tests criminal matter detection.
   */
  #[DataProvider('criminalProvider')]
  public function testCriminalMatterDetection(string $message, bool $expectViolation): void {
    $result = $this->policyFilter->check($message);

    $this->assertEquals($expectViolation, $result['violation']);
    if ($expectViolation) {
      $this->assertEquals('criminal', $result['type']);
    }
  }

  /**
   * Data provider for criminal matter tests.
   */
  public static function criminalProvider(): array {
    return [
      'arrested' => ['i was arrested last night', TRUE],
      'dui' => ['can you help with my DUI', FALSE],
      'felony' => ['i have a felony charge', TRUE],
      'public defender' => ['i need a public defender', TRUE],
      'civil eviction' => ['i got an eviction notice', FALSE],
      'divorce' => ['i want to file for divorce', FALSE],
    ];
  }

  /**
   * Tests legal advice request detection.
   */
  #[DataProvider('legalAdviceProvider')]
  public function testLegalAdviceDetection(string $message, bool $expectViolation): void {
    $result = $this->policyFilter->check($message);

    $this->assertEquals($expectViolation, $result['violation']);
    if ($expectViolation) {
      $this->assertEquals('legal_advice', $result['type']);
    }
  }

  /**
   * Data provider for legal advice tests.
   */
  public static function legalAdviceProvider(): array {
    return [
      'should i' => ['should i sue my landlord', TRUE],
      'will i win' => ['will i win my case', TRUE],
      'is it legal' => ['is it legal to break my lease', TRUE],
      'predict outcome' => ['what will happen in court', TRUE],
      'general question' => ['how do i apply for help', FALSE],
      'find forms' => ['where can i find eviction forms', FALSE],
    ];
  }

  /**
   * Tests code-owned fallback legal-advice keywords removed from config export.
   */
  #[DataProvider('codeOwnedLegalAdviceKeywordProvider')]
  public function testCodeOwnedLegalAdviceKeywordFallback(string $message): void {
    $result = $this->policyFilter->check($message);

    $this->assertTrue($result['violation']);
    $this->assertEquals('legal_advice', $result['type']);
  }

  /**
   * Data provider for code-owned fallback legal-advice keywords.
   */
  public static function codeOwnedLegalAdviceKeywordProvider(): array {
    return [
      'law says substring' => ['tell me what the law says about eviction'],
      'my rights substring' => ['what are my rights as a tenant'],
    ];
  }

  /**
   * Tests code-owned fallback PII indicators removed from config export.
   */
  #[DataProvider('codeOwnedPiiIndicatorProvider')]
  public function testCodeOwnedPiiIndicatorFallback(string $message): void {
    $result = $this->policyFilter->check($message);

    $this->assertTrue($result['violation']);
    $this->assertEquals('pii', $result['type']);
  }

  /**
   * Data provider for code-owned fallback PII indicators.
   */
  public static function codeOwnedPiiIndicatorProvider(): array {
    return [
      'lowercase name' => ['my name is john'],
      'address without house number' => ['my address is elm street'],
    ];
  }

  /**
   * Tests document drafting detection.
   */
  public function testDocumentDraftingDetection(): void {
    $message = 'can you fill out this form for me';
    $result = $this->policyFilter->check($message);

    $this->assertTrue($result['violation']);
    $this->assertEquals('document_drafting', $result['type']);
  }

  /**
   * Tests frustration detection.
   */
  public function testFrustrationDetection(): void {
    $message = 'you people are useless';
    $result = $this->policyFilter->check($message);

    $this->assertTrue($result['violation']);
    $this->assertEquals('frustration', $result['type']);
  }

  /**
   * Tests PII sanitization.
   */
  public function testPiiSanitization(): void {
    $input = 'my email is john@example.com and phone is 208-555-1234';
    $sanitized = $this->policyFilter->sanitizeForStorage($input);

    $this->assertStringContainsString('[REDACTED-EMAIL]', $sanitized);
    $this->assertStringContainsString('[REDACTED-PHONE]', $sanitized);
    $this->assertStringNotContainsString('john@example.com', $sanitized);
    $this->assertStringNotContainsString('208-555-1234', $sanitized);
  }

  /**
   * Tests that clean messages pass through.
   */
  public function testCleanMessagePasses(): void {
    $cleanMessages = [
      'How do I apply for legal help?',
      'What are your office hours?',
      'I need help with my eviction',
      'Where can I find divorce forms',
      'Tell me about your services',
    ];

    foreach ($cleanMessages as $message) {
      $result = $this->policyFilter->check($message);
      $this->assertFalse($result['violation'], "Message should pass: $message");
    }
  }

  /**
   * Tests isEmergency helper method.
   */
  public function testIsEmergencyHelper(): void {
    $this->assertTrue($this->policyFilter->isEmergency('the sheriff is coming tomorrow'));
    $this->assertFalse($this->policyFilter->isEmergency('how do i apply'));
  }

  /**
   * Tests isCriminalMatter helper method.
   */
  public function testIsCriminalMatterHelper(): void {
    $this->assertTrue($this->policyFilter->isCriminalMatter('i was arrested'));
    $this->assertFalse($this->policyFilter->isCriminalMatter('i need divorce help'));
  }

}
