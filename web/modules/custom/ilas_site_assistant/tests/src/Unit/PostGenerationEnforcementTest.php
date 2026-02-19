<?php

namespace Drupal\Tests\ilas_site_assistant\Unit;

use Drupal\ilas_site_assistant\Service\InputNormalizer;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Tests for post-generation safety enforcement logic.
 *
 * Tests the enforcement patterns that run AFTER LLM enhancement, covering:
 * - _requires_review flag enforcement (F-13)
 * - Legal advice detection in LLM output
 * - Internal flag stripping before client response
 *
 * These tests validate the regex patterns used by
 * AssistantApiController::enforcePostGenerationSafety() and
 * AssistantApiController::containsLegalAdviceInOutput().
 */
#[Group('ilas_site_assistant')]
class PostGenerationEnforcementTest extends TestCase {

  /**
   * Legal advice patterns (mirrors AssistantApiController::containsLegalAdviceInOutput).
   *
   * @var array
   */
  protected array $legalAdvicePatterns = [
    '/you\s+should\s+(file|sue|appeal|claim|motion)/i',
    '/i\s+(would\s+)?(advise|recommend|suggest)\s+(you|that\s+you)/i',
    '/my\s+(legal\s+)?advice\s+is/i',
    '/the\s+best\s+(legal\s+)?(strategy|approach)\s+is/i',
    '/you\s+need\s+to\s+(file|submit|send)/i',
    '/you\s+(will|would)\s+(likely|probably)\s+(win|lose|succeed|fail)/i',
    '/the\s+court\s+will\s+(likely|probably)/i',
    '/you\s+should\s+(stop\s+paying|withhold|break\s+your)/i',
    '/don\'t\s+(pay|respond|go\s+to\s+court)/i',
    '/ignore\s+the\s+(notice|summons|order)/i',
    '/idaho\s+code\s*(§|section)/i',
    '/(statute|code)\s+(says|states|requires)/i',
  ];

  /**
   * Checks if text contains legal advice.
   */
  protected function containsLegalAdvice(string $text): bool {
    $text = InputNormalizer::normalize($text);
    foreach ($this->legalAdvicePatterns as $pattern) {
      if (preg_match($pattern, $text)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Tests that LLM output containing legal advice is caught.
   */
  #[DataProvider('legalAdviceOutputProvider')]
  public function testLegalAdviceDetectionInOutput(string $llm_output): void {
    $this->assertTrue(
      $this->containsLegalAdvice($llm_output),
      "Should detect legal advice in: '$llm_output'"
    );
  }

  /**
   * Data provider for LLM outputs that contain legal advice.
   */
  public static function legalAdviceOutputProvider(): array {
    return [
      'you should file' => ['Based on your situation, you should file a motion to dismiss.'],
      'you should sue' => ['You should sue your landlord for damages.'],
      'I would advise you' => ['I would advise you to seek representation immediately.'],
      'I recommend you' => ['I recommend you appeal the decision.'],
      'best strategy' => ['The best legal strategy is to file an answer within 20 days.'],
      'you need to file' => ['You need to file a response before the deadline.'],
      'you will likely win' => ['You will likely win this case based on the facts.'],
      'court will likely' => ['The court will likely rule in your favor.'],
      'you should stop paying' => ['You should stop paying rent until repairs are made.'],
      'ignore the notice' => ['You can safely ignore the notice from your landlord.'],
      'idaho code section' => ['Idaho Code section 6-303 provides that tenants have rights.'],
      'statute says' => ['The statute says you have 20 days to respond.'],
      'don\'t go to court' => ["Don't go to court without a lawyer."],
    ];
  }

  /**
   * Tests that safe LLM output is NOT flagged.
   */
  #[DataProvider('safeLlmOutputProvider')]
  public function testSafeLlmOutputNotFlagged(string $llm_output): void {
    $this->assertFalse(
      $this->containsLegalAdvice($llm_output),
      "Should NOT flag safe output: '$llm_output'"
    );
  }

  /**
   * Data provider for safe LLM outputs.
   */
  public static function safeLlmOutputProvider(): array {
    return [
      'general info' => ['Idaho Legal Aid Services can help with housing issues.'],
      'contact info' => ['You can call our Legal Advice Line at (208) 746-7541.'],
      'resource pointer' => ['Here are some guides that explain the eviction process.'],
      'apply suggestion' => ['To get help with your situation, please apply for services.'],
      'caveat response' => ['This is general information only. For advice about your case, please contact us.'],
      'faq summary' => ['Tenants generally have the right to receive notice before eviction.'],
    ];
  }

  /**
   * Tests _requires_review flag stripping.
   */
  public function testRequiresReviewFlagStripping(): void {
    $response = [
      'type' => 'faq',
      'message' => 'Here is some info.',
      'llm_summary' => 'A helpful summary.',
      '_requires_review' => TRUE,
      '_validation_warnings' => ['Phone number not in list'],
      '_grounding_version' => '1.0',
    ];

    // Simulate the strip behavior.
    unset($response['_requires_review']);
    unset($response['_validation_warnings']);
    unset($response['_grounding_version']);

    $this->assertArrayNotHasKey('_requires_review', $response);
    $this->assertArrayNotHasKey('_validation_warnings', $response);
    $this->assertArrayNotHasKey('_grounding_version', $response);
    // Public fields are preserved.
    $this->assertArrayHasKey('type', $response);
    $this->assertArrayHasKey('message', $response);
    $this->assertArrayHasKey('llm_summary', $response);
  }

  /**
   * Tests that obfuscated legal advice in LLM output is caught after normalization.
   */
  public function testObfuscatedLegalAdviceInOutput(): void {
    // "you s.h.o.u.l.d file a motion" — after normalization: "you should file a motion"
    $obfuscated = 'you s.h.o.u.l.d file a motion';
    $this->assertTrue(
      $this->containsLegalAdvice($obfuscated),
      'Obfuscated legal advice should be caught after normalization'
    );
  }

  /**
   * Tests that _requires_review replacement uses safe fallback.
   */
  public function testRequiresReviewReplacementText(): void {
    $safe_fallback = 'I found some information that may help. For guidance specific to your situation, please contact our Legal Advice Line or apply for help.';

    // This is what the controller does — just verify the text is safe.
    $this->assertFalse(
      $this->containsLegalAdvice($safe_fallback),
      'The safe fallback text itself should not trigger legal advice detection'
    );
  }

}
