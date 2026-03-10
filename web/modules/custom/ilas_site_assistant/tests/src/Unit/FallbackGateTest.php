<?php

namespace Drupal\Tests\ilas_site_assistant\Unit;

use Drupal\ilas_site_assistant\Service\FallbackGate;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests for FallbackGate service.
 */
#[Group('ilas_site_assistant')]
class FallbackGateTest extends TestCase {

  /**
   * The fallback gate service.
   *
   * @var \Drupal\ilas_site_assistant\Service\FallbackGate
   */
  protected $fallbackGate;

  /**
   * Mock config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $configFactory;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Stub the config factory (no call-count expectations needed).
    $config = $this->createStub(ImmutableConfig::class);
    $config->method('get')
      ->willReturnCallback(function ($key) {
        $values = [
          'llm.enabled' => TRUE,
          'fallback_gate.thresholds' => [],
        ];
        return $values[$key] ?? NULL;
      });

    $this->configFactory = $this->createStub(ConfigFactoryInterface::class);
    $this->configFactory->method('get')
      ->willReturn($config);

    $this->fallbackGate = new FallbackGate($this->configFactory);
  }

  /**
   * Tests high confidence intent detection.
   */
  public function testHighConfidenceIntent(): void {
    $intent = [
      'type' => 'apply',
      'extraction' => [
        'keywords' => ['apply', 'help', 'legal'],
        'phrases_found' => ['apply for help'],
        'synonyms_applied' => [],
      ],
    ];
    $message = 'How do I apply for legal help?';

    $decision = $this->fallbackGate->evaluate($intent, [], [], ['message' => $message]);

    $this->assertEquals(FallbackGate::DECISION_ANSWER, $decision['decision']);
    $this->assertEquals(FallbackGate::REASON_HIGH_CONF_INTENT, $decision['reason_code']);
    $this->assertGreaterThanOrEqual(0.70, $decision['confidence']);
  }

  /**
   * Tests unknown intent triggers fallback.
   */
  public function testUnknownIntentFallback(): void {
    $intent = [
      'type' => 'unknown',
      'extraction' => [
        'keywords' => ['random', 'stuff'],
        'phrases_found' => [],
        'synonyms_applied' => [],
      ],
    ];
    $message = 'This is some random query that makes no sense';

    $decision = $this->fallbackGate->evaluate($intent, [], [], ['message' => $message]);

    $this->assertEquals(FallbackGate::DECISION_FALLBACK_LLM, $decision['decision']);
    $this->assertEquals(FallbackGate::REASON_LOW_INTENT_CONF, $decision['reason_code']);
    $this->assertLessThan(0.50, $decision['confidence']);
  }

  /**
   * Tests live environment treats LLM as disabled for fallback decisions.
   */
  public function testLiveEnvironmentDisablesLlmFallbackDecision(): void {
    $originalPantheon = getenv('PANTHEON_ENVIRONMENT');
    $hadPantheonInEnv = array_key_exists('PANTHEON_ENVIRONMENT', $_ENV);
    $originalPantheonEnv = $_ENV['PANTHEON_ENVIRONMENT'] ?? NULL;

    try {
      putenv('PANTHEON_ENVIRONMENT=live');
      $_ENV['PANTHEON_ENVIRONMENT'] = 'live';

      $intent = [
        'type' => 'unknown',
        'extraction' => [
          'keywords' => ['random', 'stuff'],
          'phrases_found' => [],
          'synonyms_applied' => [],
        ],
      ];
      $message = 'This is some random query that makes no sense';

      $decision = $this->fallbackGate->evaluate($intent, [], [], ['message' => $message]);

      $this->assertEquals(FallbackGate::DECISION_CLARIFY, $decision['decision']);
      $this->assertEquals(FallbackGate::REASON_LLM_DISABLED, $decision['reason_code']);
    }
    finally {
      if ($originalPantheon === FALSE) {
        putenv('PANTHEON_ENVIRONMENT');
      }
      else {
        putenv("PANTHEON_ENVIRONMENT={$originalPantheon}");
      }

      if ($hadPantheonInEnv) {
        $_ENV['PANTHEON_ENVIRONMENT'] = $originalPantheonEnv;
      }
      else {
        unset($_ENV['PANTHEON_ENVIRONMENT']);
      }
    }
  }

  /**
   * Tests very short messages trigger clarification.
   */
  public function testShortMessageClarification(): void {
    $intent = [
      'type' => 'unknown',
      'extraction' => ['keywords' => [], 'phrases_found' => [], 'synonyms_applied' => []],
    ];
    $message = 'hi';

    $decision = $this->fallbackGate->evaluate($intent, [], [], ['message' => $message]);

    $this->assertEquals(FallbackGate::DECISION_CLARIFY, $decision['decision']);
    $this->assertEquals(FallbackGate::REASON_LOW_INTENT_CONF, $decision['reason_code']);
  }

  /**
   * Tests authoritative override intent triggers hard route.
   */
  public function testRoutingOverrideIntentHardRoute(): void {
    $intent = [
      'type' => 'apply',
      'extraction' => ['keywords' => ['help'], 'phrases_found' => [], 'synonyms_applied' => []],
    ];
    $override_intent = [
      'type' => 'high_risk',
      'risk_category' => 'high_risk_dv',
      'confidence' => 1.0,
      'source' => 'pre_routing_decision_engine',
    ];

    $decision = $this->fallbackGate->evaluate($intent, [], $override_intent, ['message' => 'I need help']);

    $this->assertEquals(FallbackGate::DECISION_HARD_ROUTE, $decision['decision']);
    $this->assertEquals(FallbackGate::REASON_SAFETY_URGENT, $decision['reason_code']);
    $this->assertEquals(1.0, $decision['confidence']);
    $this->assertSame('high_risk_dv', $decision['details']['override_risk_category'] ?? NULL);
  }

  /**
   * Tests high risk intent triggers hard route.
   */
  public function testHighRiskHardRoute(): void {
    $intent = [
      'type' => 'high_risk',
      'risk_category' => 'high_risk_dv',
      'extraction' => ['keywords' => [], 'phrases_found' => [], 'synonyms_applied' => []],
    ];

    $decision = $this->fallbackGate->evaluate($intent, [], [], ['message' => 'my husband hit me']);

    $this->assertEquals(FallbackGate::DECISION_HARD_ROUTE, $decision['decision']);
    $this->assertEquals(FallbackGate::REASON_SAFETY_URGENT, $decision['reason_code']);
  }

  /**
   * Tests out of scope intent.
   */
  public function testOutOfScopeHardRoute(): void {
    $intent = [
      'type' => 'out_of_scope',
      'extraction' => ['keywords' => ['criminal'], 'phrases_found' => [], 'synonyms_applied' => []],
    ];

    $decision = $this->fallbackGate->evaluate($intent, [], [], ['message' => 'I need a criminal lawyer']);

    $this->assertEquals(FallbackGate::DECISION_HARD_ROUTE, $decision['decision']);
    $this->assertEquals(FallbackGate::REASON_OUT_OF_SCOPE, $decision['reason_code']);
  }

  /**
   * Tests greeting always answers.
   */
  public function testGreetingAlwaysAnswers(): void {
    $intent = [
      'type' => 'greeting',
      'extraction' => ['keywords' => [], 'phrases_found' => [], 'synonyms_applied' => []],
    ];

    $decision = $this->fallbackGate->evaluate($intent, [], [], ['message' => 'hello']);

    $this->assertEquals(FallbackGate::DECISION_ANSWER, $decision['decision']);
    $this->assertEquals(FallbackGate::REASON_GREETING, $decision['reason_code']);
    $this->assertEquals(1.0, $decision['confidence']);
  }

  /**
   * Tests high retrieval confidence with unknown intent.
   */
  public function testHighRetrievalConfidenceAnswers(): void {
    $intent = [
      'type' => 'unknown',
      'extraction' => ['keywords' => [], 'phrases_found' => [], 'synonyms_applied' => []],
    ];
    $retrieval_results = [
      ['id' => 1, 'score' => 0.85, 'title' => 'FAQ about eviction'],
      ['id' => 2, 'score' => 0.60, 'title' => 'Eviction guide'],
    ];

    $decision = $this->fallbackGate->evaluate($intent, $retrieval_results, [], ['message' => 'what happens if i get evicted']);

    $this->assertEquals(FallbackGate::DECISION_ANSWER, $decision['decision']);
    $this->assertEquals(FallbackGate::REASON_HIGH_CONF_RETRIEVAL, $decision['reason_code']);
  }

  /**
   * Tests no-results retrieval path caps high-intent confidence at 0.49.
   */
  public function testNoResultsHighIntentConfidenceIsCapped(): void {
    $intent = [
      'type' => 'faq',
      'extraction' => [
        'keywords' => ['tenant', 'rights'],
        'phrases_found' => ['tenant rights'],
        'synonyms_applied' => [],
      ],
    ];

    $decision = $this->fallbackGate->evaluate($intent, [], [], [
      'message' => 'What are Idaho tenant rights for eviction notices?',
    ]);

    $this->assertEquals(FallbackGate::DECISION_ANSWER, $decision['decision']);
    $this->assertEquals(FallbackGate::REASON_NO_RESULTS, $decision['reason_code']);
    $this->assertLessThanOrEqual(0.49, $decision['confidence']);
    $this->assertSame(TRUE, $decision['details']['no_results_confidence_capped'] ?? NULL);
    $this->assertArrayHasKey('no_results_uncapped_confidence', $decision['details']);
  }

  /**
   * Tests policy violation triggers hard route.
   */
  public function testPolicyViolationHardRoute(): void {
    $intent = [
      'type' => 'faq',
      'extraction' => ['keywords' => [], 'phrases_found' => [], 'synonyms_applied' => []],
    ];

    $decision = $this->fallbackGate->evaluate($intent, [], [], [
      'message' => 'what should i do',
      'policy_violation' => TRUE,
    ]);

    $this->assertEquals(FallbackGate::DECISION_HARD_ROUTE, $decision['decision']);
    $this->assertEquals(FallbackGate::REASON_POLICY_VIOLATION, $decision['reason_code']);
  }

  /**
   * Tests intent confidence calculation.
   */
  public function testIntentConfidenceCalculation(): void {
    // Unknown intent.
    $confidence = $this->fallbackGate->calculateIntentConfidence(
      ['type' => 'unknown'],
      'test message'
    );
    $this->assertLessThan(0.30, $confidence);

    // LLM-classified intent.
    $confidence = $this->fallbackGate->calculateIntentConfidence(
      ['type' => 'apply', 'source' => 'llm'],
      'test message'
    );
    $this->assertGreaterThan(0.40, $confidence);
    $this->assertLessThan(0.70, $confidence);

    // Rule-based with good keywords.
    $confidence = $this->fallbackGate->calculateIntentConfidence(
      [
        'type' => 'apply',
        'extraction' => [
          'keywords' => ['apply', 'help'],
          'phrases_found' => ['apply for help'],
          'synonyms_applied' => [],
        ],
      ],
      'How do I apply for legal help?'
    );
    $this->assertGreaterThan(0.80, $confidence);
  }

  /**
   * Tests retrieval confidence calculation.
   */
  public function testRetrievalConfidenceCalculation(): void {
    $thresholds = $this->fallbackGate->getThresholds();

    // Empty results.
    $confidence = $this->fallbackGate->calculateRetrievalConfidence([], $thresholds);
    $this->assertEquals(0.0, $confidence);

    // High score result.
    $results = [['score' => 0.90]];
    $confidence = $this->fallbackGate->calculateRetrievalConfidence($results, $thresholds);
    $this->assertEquals(0.90, $confidence);

    // Multiple results without scores (estimate).
    $results = [
      ['id' => 1, 'title' => 'Result 1'],
      ['id' => 2, 'title' => 'Result 2'],
      ['id' => 3, 'title' => 'Result 3'],
    ];
    $confidence = $this->fallbackGate->calculateRetrievalConfidence($results, $thresholds);
    $this->assertEquals(0.70, $confidence);
  }

  /**
   * Tests multi-intent detection triggers appropriate response.
   */
  public function testMultiIntentDetection(): void {
    $intent = [
      'type' => 'forms',
      'extraction' => ['keywords' => ['forms', 'guides'], 'phrases_found' => [], 'synonyms_applied' => []],
    ];
    // Message with conjunction suggesting multiple intents.
    $message = 'I need divorce forms and also guides about custody and I want to apply for help';

    $decision = $this->fallbackGate->evaluate($intent, [], [], ['message' => $message]);

    // Should still answer since we detected an intent, but may suggest LLM enhancement.
    $this->assertContains($decision['decision'], [
      FallbackGate::DECISION_ANSWER,
      FallbackGate::DECISION_FALLBACK_LLM,
    ]);
  }

  /**
   * Tests gate metrics calculation.
   */
  public function testGateMetricsCalculation(): void {
    $decisions = [
      ['decision' => FallbackGate::DECISION_ANSWER, 'reason_code' => FallbackGate::REASON_HIGH_CONF_INTENT, 'confidence' => 0.90],
      ['decision' => FallbackGate::DECISION_ANSWER, 'reason_code' => FallbackGate::REASON_HIGH_CONF_RETRIEVAL, 'confidence' => 0.85],
      ['decision' => FallbackGate::DECISION_FALLBACK_LLM, 'reason_code' => FallbackGate::REASON_LOW_INTENT_CONF, 'confidence' => 0.30],
      ['decision' => FallbackGate::DECISION_CLARIFY, 'reason_code' => FallbackGate::REASON_LOW_INTENT_CONF, 'confidence' => 0.20],
      ['decision' => FallbackGate::DECISION_HARD_ROUTE, 'reason_code' => FallbackGate::REASON_SAFETY_URGENT, 'confidence' => 1.00],
    ];

    $metrics = FallbackGate::calculateGateMetrics($decisions);

    $this->assertEquals(5, $metrics['total']);
    $this->assertEquals(0.40, $metrics['answer_rate']); // 2/5
    $this->assertEquals(0.20, $metrics['clarify_rate']); // 1/5
    $this->assertEquals(0.20, $metrics['fallback_rate']); // 1/5
    $this->assertEquals(0.20, $metrics['hard_route_rate']); // 1/5
    $this->assertEqualsWithDelta(0.65, $metrics['avg_confidence'], 0.01);
    $this->assertArrayHasKey(FallbackGate::REASON_HIGH_CONF_INTENT, $metrics['by_reason_code']);
    $this->assertEquals(1, $metrics['by_reason_code'][FallbackGate::REASON_HIGH_CONF_INTENT]);
  }

  /**
   * Tests all reason codes have descriptions.
   */
  public function testReasonCodeDescriptions(): void {
    $descriptions = FallbackGate::getReasonCodeDescriptions();

    // Check all constants have descriptions.
    $constants = [
      FallbackGate::REASON_HIGH_CONF_INTENT,
      FallbackGate::REASON_HIGH_CONF_RETRIEVAL,
      FallbackGate::REASON_LOW_INTENT_CONF,
      FallbackGate::REASON_LOW_RETRIEVAL_SCORE,
      FallbackGate::REASON_AMBIGUOUS_MULTI_INTENT,
      FallbackGate::REASON_SAFETY_URGENT,
      FallbackGate::REASON_OUT_OF_SCOPE,
      FallbackGate::REASON_POLICY_VIOLATION,
      FallbackGate::REASON_NO_RESULTS,
      FallbackGate::REASON_LARGE_SCORE_GAP,
      FallbackGate::REASON_BORDERLINE_CONF,
      FallbackGate::REASON_GREETING,
      FallbackGate::REASON_LLM_DISABLED,
    ];

    foreach ($constants as $code) {
      $this->assertArrayHasKey($code, $descriptions, "Missing description for $code");
      $this->assertNotEmpty($descriptions[$code], "Empty description for $code");
    }
  }

}
