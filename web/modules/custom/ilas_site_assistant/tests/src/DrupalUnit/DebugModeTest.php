<?php

namespace Drupal\Tests\ilas_site_assistant\DrupalUnit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\ilas_site_assistant\Service\InputNormalizer;
use Drupal\ilas_site_assistant\Service\OutOfScopeClassifier;
use Drupal\ilas_site_assistant\Service\PolicyFilter;
use Drupal\ilas_site_assistant\Service\PreRoutingDecisionEngine;
use Drupal\ilas_site_assistant\Service\SafetyClassifier;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Unit tests for DEBUG mode functionality.
 *
 * Tests the debug metadata structure and PII redaction.
 */
#[Group('ilas_site_assistant')]
class DebugModeTest extends UnitTestCase {

  /**
   * Tests keyword extraction for debug output.
   */
  #[DataProvider('keywordExtractionProvider')]
  public function testKeywordExtraction(string $input, array $expectedKeywords): void {
    $keywords = $this->extractKeywords($input);

    // All expected keywords should be present.
    foreach ($expectedKeywords as $expected) {
      $this->assertContains($expected, $keywords, "Keyword '$expected' should be extracted");
    }

    // No stop words should be present.
    $stopWords = ['the', 'and', 'for', 'you', 'are'];
    foreach ($stopWords as $stopWord) {
      $this->assertNotContains($stopWord, $keywords, "Stop word '$stopWord' should not be extracted");
    }
  }

  /**
   * Data provider for keyword extraction tests.
   */
  public static function keywordExtractionProvider(): array {
    return [
      'simple question' => [
        'how do i apply for legal help',
        ['apply', 'legal', 'help'],
      ],
      'housing query' => [
        'i need help with my eviction notice',
        ['need', 'help', 'eviction', 'notice'],
      ],
      'typos included' => [
        'i need a lawer for my divorc',
        ['need', 'lawer', 'divorc'],
      ],
    ];
  }

  /**
   * Tests urgency-signal detection from the decision engine.
   */
  #[DataProvider('safetyFlagProvider')]
  public function testSafetyFlagDetection(string $message, array $expectedFlags): void {
    $flags = $this->evaluateUrgencySignals($message);

    if ($expectedFlags === []) {
      $this->assertSame([], $flags);
    }

    foreach ($expectedFlags as $expected) {
      $this->assertContains($expected, $flags, "Flag '$expected' should be detected");
    }
  }

  /**
   * Data provider for safety flag tests.
   */
  public static function safetyFlagProvider(): array {
    return [
      'dv indicator' => [
        'my husband is abusing me',
        ['dv_indicator'],
      ],
      'eviction imminent' => [
        'the sheriff is coming tomorrow to evict me',
        ['eviction_imminent'],
      ],
      'identity theft' => [
        'someone committed identity theft',
        ['identity_theft'],
      ],
      'deadline pressure' => [
        'i have a court date tomorrow',
        ['deadline_pressure'],
      ],
      'criminal matter' => [
        'i was arrested for DUI',
        ['criminal_matter'],
      ],
      'crisis' => [
        'this is an emergency',
        ['crisis_emergency'],
      ],
      'clean message' => [
        'how do i apply for help',
        [],
      ],
    ];
  }

  /**
   * Tests intent confidence calculation.
   */
  #[DataProvider('confidenceProvider')]
  public function testIntentConfidence(array $intent, string $message, float $minConfidence, float $maxConfidence): void {
    $confidence = $this->calculateIntentConfidence($intent, $message);

    $this->assertGreaterThanOrEqual($minConfidence, $confidence);
    $this->assertLessThanOrEqual($maxConfidence, $confidence);
  }

  /**
   * Data provider for confidence tests.
   */
  public static function confidenceProvider(): array {
    return [
      'unknown intent' => [
        ['type' => 'unknown'],
        'asdfghjkl',
        0.1, 0.3,
      ],
      'llm classified' => [
        ['type' => 'apply', 'source' => 'llm'],
        'help me please',
        0.5, 0.7,
      ],
      'short message rule-based' => [
        ['type' => 'apply'],
        'apply',
        0.6, 0.8,
      ],
      'long message rule-based' => [
        ['type' => 'apply'],
        'how do i apply for legal assistance from idaho legal aid services',
        0.9, 1.0,
      ],
    ];
  }

  /**
   * Tests final action determination.
   */
  #[DataProvider('finalActionProvider')]
  public function testFinalActionDetermination(string $responseType, string $expectedAction): void {
    $action = $this->determineFinalAction($responseType);
    $this->assertEquals($expectedAction, $action);
  }

  /**
   * Data provider for final action tests.
   */
  public static function finalActionProvider(): array {
    return [
      'faq' => ['faq', 'answer'],
      'resources' => ['resources', 'answer'],
      'navigation' => ['navigation', 'hard_route'],
      'escalation' => ['escalation', 'hard_route'],
      'fallback' => ['fallback', 'clarify'],
      'greeting' => ['greeting', 'answer'],
    ];
  }

  /**
   * Tests reason code generation.
   */
  #[DataProvider('reasonCodeProvider')]
  public function testReasonCodeGeneration(array $intent, array $response, string $expectedCode): void {
    $code = $this->determineReasonCode($intent, $response);
    $this->assertEquals($expectedCode, $code);
  }

  /**
   * Data provider for reason code tests.
   */
  public static function reasonCodeProvider(): array {
    return [
      'faq match' => [
        ['type' => 'faq'],
        ['type' => 'faq', 'results' => [['id' => 1]]],
        'faq_match_found',
      ],
      'resource match' => [
        ['type' => 'forms'],
        ['type' => 'resources', 'results' => [['id' => 1]]],
        'resource_match_found',
      ],
      'navigation' => [
        ['type' => 'apply'],
        ['type' => 'navigation'],
        'direct_navigation_apply',
      ],
      'fallback' => [
        ['type' => 'unknown'],
        ['type' => 'fallback'],
        'no_match_fallback',
      ],
    ];
  }

  /**
   * Tests retrieval metadata extraction.
   *
   * Ensures no sensitive content is included, only IDs/URLs.
   */
  public function testRetrievalMetaExtraction(): void {
    $results = [
      [
        'id' => 'faq_123',
        'url' => '/faq#test-question',
        'question' => 'What is the answer?',
        'answer' => 'This is sensitive content that should not be included.',
        'score' => 0.95,
      ],
      [
        'id' => 'faq_456',
        'url' => '/faq#another-question',
        'question' => 'Another question?',
        'answer' => 'More content here.',
        'score' => 0.8,
      ],
    ];

    $meta = $this->extractRetrievalMeta($results);

    // Should have 2 results.
    $this->assertCount(2, $meta);

    // Check first result.
    $this->assertEquals(1, $meta[0]['rank']);
    $this->assertEquals('faq_123', $meta[0]['id']);
    $this->assertEquals('/faq#test-question', $meta[0]['url']);
    $this->assertEquals(0.95, $meta[0]['score']);

    // Should NOT contain question or answer text.
    $this->assertArrayNotHasKey('question', $meta[0]);
    $this->assertArrayNotHasKey('answer', $meta[0]);
  }

  /**
   * Tests debug metadata structure.
   */
  public function testDebugMetadataStructure(): void {
    $debugMeta = [
      'timestamp' => date('c'),
      'intent_selected' => 'apply',
      'intent_confidence' => 0.85,
      'intent_source' => 'rule_based',
      'extracted_keywords' => ['apply', 'help', 'legal'],
      'retrieval_results' => [],
      'final_action' => 'hard_route',
      'reason_code' => 'direct_navigation_apply',
      'safety_flags' => [],
      'pre_routing_decision' => ['decision_type' => 'continue'],
      'policy_check' => ['passed' => TRUE, 'violation_type' => NULL],
      'llm_used' => FALSE,
      'processing_stages' => ['input_sanitized', 'policy_checked', 'intent_routed'],
    ];

    // Verify all required keys are present.
    $requiredKeys = [
      'timestamp',
      'intent_selected',
      'intent_confidence',
      'intent_source',
      'extracted_keywords',
      'retrieval_results',
      'final_action',
      'reason_code',
      'safety_flags',
      'pre_routing_decision',
      'policy_check',
      'llm_used',
      'processing_stages',
    ];

    foreach ($requiredKeys as $key) {
      $this->assertArrayHasKey($key, $debugMeta, "Debug metadata missing key: $key");
    }

    // Verify no raw user text is stored.
    $jsonString = json_encode($debugMeta);
    $this->assertStringNotContainsString('how do i apply', $jsonString);
    $this->assertStringNotContainsString('john@example.com', $jsonString);
  }

  /**
   * Extracts keywords from text (simulating controller method).
   */
  protected function extractKeywords(string $text): array {
    $text = strtolower($text);
    $text = preg_replace('/[^\w\s]/', ' ', $text);
    $words = preg_split('/\s+/', $text);

    $stopWords = [
      'a', 'an', 'the', 'and', 'or', 'of', 'to', 'in', 'for', 'on', 'with',
      'is', 'are', 'i', 'you', 'we', 'they', 'my', 'your', 'me', 'can',
      'do', 'does', 'did', 'have', 'has', 'am', 'be', 'been', 'what',
      'how', 'where', 'when', 'why', 'this', 'that', 'it',
    ];

    $keywords = array_filter($words, function ($word) use ($stopWords) {
      return strlen($word) >= 3 && !in_array($word, $stopWords);
    });

    return array_values(array_unique($keywords));
  }

  /**
   * Evaluates urgency signals using the authoritative decision engine.
   */
  protected function evaluateUrgencySignals(string $message): array {
    $config = $this->createStub(ImmutableConfig::class);
    $config->method('get')->willReturn([]);

    $configFactory = $this->createStub(ConfigFactoryInterface::class);
    $configFactory->method('get')->willReturn($config);

    $policyFilter = new PolicyFilter($configFactory);
    $policyFilter->setStringTranslation($this->getStringTranslationStub());

    $engine = new PreRoutingDecisionEngine(
      $policyFilter,
      new SafetyClassifier($configFactory),
      new OutOfScopeClassifier($configFactory),
    );

    $decision = $engine->evaluate(InputNormalizer::normalize($message));
    return $decision['urgency_signals'] ?? [];
  }

  /**
   * Calculates intent confidence (simulating controller method).
   */
  protected function calculateIntentConfidence(array $intent, string $message): float {
    if ($intent['type'] === 'unknown') {
      return 0.2;
    }
    if (isset($intent['source']) && $intent['source'] === 'llm') {
      return 0.6;
    }
    $wordCount = str_word_count($message);
    if ($wordCount < 3) {
      return 0.7;
    }
    elseif ($wordCount < 8) {
      return 0.85;
    }
    return 0.95;
  }

  /**
   * Determines final action (simulating controller method).
   */
  protected function determineFinalAction(string $responseType): string {
    $answerTypes = ['faq', 'resources', 'topic', 'eligibility', 'services_overview'];
    $hardRouteTypes = ['navigation', 'escalation'];

    if (in_array($responseType, $answerTypes)) {
      return 'answer';
    }
    if (in_array($responseType, $hardRouteTypes)) {
      return 'hard_route';
    }
    if ($responseType === 'fallback') {
      return 'clarify';
    }
    if ($responseType === 'greeting') {
      return 'answer';
    }
    return 'answer';
  }

  /**
   * Determines reason code (simulating controller method).
   */
  protected function determineReasonCode(array $intent, array $response): string {
    $type = $response['type'] ?? 'unknown';
    $intentType = $intent['type'] ?? 'unknown';

    if ($type === 'faq' && !empty($response['results'])) {
      return 'faq_match_found';
    }
    if ($type === 'resources' && !empty($response['results'])) {
      return 'resource_match_found';
    }
    if ($type === 'navigation') {
      return 'direct_navigation_' . $intentType;
    }
    if ($type === 'fallback') {
      return 'no_match_fallback';
    }
    if ($type === 'escalation') {
      return 'policy_escalation';
    }
    return 'intent_' . $intentType;
  }

  /**
   * Extracts retrieval metadata (simulating controller method).
   */
  protected function extractRetrievalMeta(array $results): array {
    $meta = [];
    foreach (array_slice($results, 0, 10) as $i => $result) {
      $item = [
        'rank' => $i + 1,
        'id' => $result['id'] ?? $result['paragraph_id'] ?? NULL,
        'url' => $result['url'] ?? $result['source_url'] ?? NULL,
        'type' => $result['type'] ?? 'unknown',
      ];
      if (isset($result['score'])) {
        $item['score'] = $result['score'];
      }
      $meta[] = $item;
    }
    return $meta;
  }

}
