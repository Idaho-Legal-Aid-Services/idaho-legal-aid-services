<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\ilas_site_assistant\Controller\AssistantApiController;
use Drupal\ilas_site_assistant\Service\AnalyticsLogger;
use Drupal\ilas_site_assistant\Service\AssistantFlowRunner;
use Drupal\ilas_site_assistant\Service\FallbackGate;
use Drupal\ilas_site_assistant\Service\FaqIndex;
use Drupal\ilas_site_assistant\Service\IntentRouter;
use Drupal\ilas_site_assistant\Service\LlmEnhancer;
use Drupal\ilas_site_assistant\Service\PolicyFilter;
use Drupal\ilas_site_assistant\Service\PreRoutingDecisionEngine;
use Drupal\ilas_site_assistant\Service\ResourceFinder;
use Drupal\ilas_site_assistant\Service\ResponseGrounder;
use Drupal\ilas_site_assistant\Service\SourceGovernanceService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpFoundation\Request;

/**
 * Controller-path contract tests for post-generation safety enforcement.
 */
#[Group('ilas_site_assistant')]
final class PostGenerationEnforcementTest extends TestCase {

  /**
   * Safe fallback text used by the real controller path.
   */
  private const SAFE_FALLBACK = 'I found some information that may help. For guidance specific to your situation, please contact our Legal Advice Line or apply for help.';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    require_once __DIR__ . '/controller_test_bootstrap.php';

    $container = new ContainerBuilder();
    $container->set('logger.factory', new class {

      public function get(string $channel): NullLogger {
        return new NullLogger();
      }

    });
    $container->set('string_translation', $this->translationStub());
    $container->set('config.factory', $this->buildConfigFactory());

    \Drupal::setContainer($container);
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    \Drupal::unsetContainer();
    parent::tearDown();
  }

  /**
   * Unsafe grounded messages must be replaced on the final controller path.
   */
  public function testRequiresReviewReplacesFinalMessageAndStripsLegacyLlmFields(): void {
    [$controller, $analytics] = $this->buildController();
    $controller->processIntentResponse = $this->buildResponse(
      'You should file a complaint with the court.',
      'faq'
    );
    $controller->processIntentResponse['llm_summary'] = 'This summary looks harmless but must be replaced.';
    $controller->processIntentResponse['llm_enhanced'] = TRUE;

    $response = $controller->message($this->buildRequest());
    $body = json_decode($response->getContent(), TRUE);

    $this->assertSame(200, $response->getStatusCode());
    $this->assertSame(self::SAFE_FALLBACK, $body['message'] ?? NULL);
    $this->assertArrayNotHasKey('llm_summary', $body);
    $this->assertArrayNotHasKey('llm_enhanced', $body);
    $this->assertSame('faq', $body['type'] ?? NULL);
    $this->assertContains('post_gen_safety_review_flag', $analytics->eventTypes());
    $this->assertInternalFieldsAreHidden($body);
  }

  /**
   * Legacy LLM-only fields must be stripped before serialization.
   */
  public function testLegacyLlmFieldsAreStrippedOnControllerPath(): void {
    [$controller, $analytics] = $this->buildController();
    $controller->processIntentResponse = $this->buildResponse(
      'Idaho Legal Aid Services may have housing resources that can help.',
      'faq'
    );
    $controller->processIntentResponse['llm_summary'] = 'You should file a motion to dismiss right away.';
    $controller->processIntentResponse['llm_enhanced'] = TRUE;

    $response = $controller->message($this->buildRequest());
    $body = json_decode($response->getContent(), TRUE);

    $this->assertSame(200, $response->getStatusCode());
    $this->assertSame('Idaho Legal Aid Services may have housing resources that can help.', $body['message'] ?? NULL);
    $this->assertArrayNotHasKey('llm_summary', $body);
    $this->assertArrayNotHasKey('llm_enhanced', $body);
    $this->assertNotContains('post_gen_safety_review_flag', $analytics->eventTypes());
    $this->assertInternalFieldsAreHidden($body);
  }

  /**
   * Safe public responses must pass through unchanged.
   */
  public function testSafeResponsePassesThroughUnchanged(): void {
    [$controller, $analytics] = $this->buildController();
    $controller->processIntentResponse = $this->buildResponse(
      'Idaho Legal Aid Services provides general information about housing issues.',
      'faq'
    );
    $controller->processIntentResponse['llm_summary'] = 'Here are some housing resources that may help.';
    $controller->processIntentResponse['llm_enhanced'] = TRUE;

    $response = $controller->message($this->buildRequest());
    $body = json_decode($response->getContent(), TRUE);

    $this->assertSame(200, $response->getStatusCode());
    $this->assertSame('Idaho Legal Aid Services provides general information about housing issues.', $body['message'] ?? NULL);
    $this->assertArrayNotHasKey('llm_summary', $body);
    $this->assertArrayNotHasKey('llm_enhanced', $body);
    $this->assertNotContains('post_gen_safety_review_flag', $analytics->eventTypes());
    $this->assertInternalFieldsAreHidden($body);
  }

  /**
   * Builds a controller with a real ResponseGrounder and stubbed LLM.
   *
   * @return array{0: PostGenerationTestableController, 1: RecordingAnalyticsLogger}
   *   The controller and analytics recorder.
   */
  private function buildController(): array {
    $configFactory = $this->buildConfigFactory();

    $flood = $this->createStub(FloodInterface::class);
    $flood->method('isAllowed')->willReturn(TRUE);

    $intentRouter = $this->createStub(IntentRouter::class);
    $intentRouter->method('route')->willReturn([
      'type' => 'faq',
      'confidence' => 0.95,
    ]);

    $faqIndex = $this->createStub(FaqIndex::class);
    $faqIndex->method('search')->willReturn([]);
    $resourceFinder = $this->createStub(ResourceFinder::class);

    $policyFilter = $this->createStub(PolicyFilter::class);
    $policyFilter->method('check')->willReturn([
      'passed' => TRUE,
      'violation' => FALSE,
    ]);

    $analyticsLogger = new RecordingAnalyticsLogger();
    $llmEnhancer = new PostGenerationTestableLlmEnhancer();
    $state = $this->createStub(StateInterface::class);
    $state->method('get')->willReturnCallback(static function (string $key, $default = NULL) {
      return $default;
    });
    $sourceGovernance = new SourceGovernanceService($configFactory, $state, new NullLogger());

    $fallbackGate = $this->createStub(FallbackGate::class);
    $fallbackGate->method('evaluate')->willReturn([
      'decision' => 'allow',
      'reason_code' => 'test',
      'confidence' => 1.0,
    ]);

    $cache = $this->createStub(CacheBackendInterface::class);
    $cache->method('get')->willReturn(FALSE);

    $controller = new PostGenerationTestableController(
      config_factory: $configFactory,
      intent_router: $intentRouter,
      faq_index: $faqIndex,
      resource_finder: $resourceFinder,
      policy_filter: $policyFilter,
      analytics_logger: $analyticsLogger,
      llm_enhancer: $llmEnhancer,
      fallback_gate: $fallbackGate,
      flood: $flood,
      conversation_cache: $cache,
      logger: new NullLogger(),
      assistant_flow_runner: $this->createStub(AssistantFlowRunner::class),
      selection_registry: new \Drupal\ilas_site_assistant\Service\SelectionRegistry(new \Drupal\ilas_site_assistant\Service\TopIntentsPack()),
      selection_state_store: new \Drupal\ilas_site_assistant\Service\SelectionStateStore($cache),
      response_grounder: new ResponseGrounder($sourceGovernance),
      source_governance: $sourceGovernance,
      pre_routing_decision_engine: new PreRoutingDecisionEngine($policyFilter),
    );

    return [$controller, $analyticsLogger];
  }

  /**
   * Builds a deterministic JSON request for the controller path.
   */
  private function buildRequest(): Request {
    return Request::create(
      '/assistant/api/message',
      'POST',
      [],
      [],
      [],
      ['CONTENT_TYPE' => 'application/json'],
      json_encode(['message' => 'help me'])
    );
  }

  /**
   * Builds a deterministic processIntent response shape.
   */
  private function buildResponse(string $message, string $type): array {
    return [
      'type' => $type,
      'message' => $message,
      'response_mode' => 'answer',
      'primary_action' => [],
      'secondary_actions' => [],
      'reason_code' => 'test',
      'results' => [
        [
          'question' => 'Housing help',
          'answer' => 'Read the guide for more information.',
          'url' => '/faq/housing-help',
          'freshness' => ['status' => 'fresh'],
        ],
      ],
    ];
  }

  // -----------------------------------------------------------------------
  // AFRP-18: Independent post-generation legal-advice scan
  // -----------------------------------------------------------------------

  /**
   * Legal advice caught by independent scan when grounding is skipped.
   *
   * Proves GAP G2 is closed: responses without results skip
   * ResponseGrounder, so _requires_review is never set. The independent
   * PostGenerationLegalAdviceDetector scan must still catch legal advice.
   */
  public function testLegalAdviceCaughtByIndependentScanWhenGrounderSkipped(): void {
    [$controller, $analytics] = $this->buildController();
    $controller->processIntentResponse = [
      'type' => 'faq',
      'message' => 'It would be advisable to file a motion to dismiss.',
      'response_mode' => 'answer',
      'primary_action' => [],
      'secondary_actions' => [],
      'reason_code' => 'test',
      'results' => [],
    ];

    $response = $controller->message($this->buildRequest());
    $body = json_decode($response->getContent(), TRUE);

    $this->assertSame(200, $response->getStatusCode());
    $this->assertSame(self::SAFE_FALLBACK, $body['message'] ?? NULL);
    $this->assertContains('post_gen_safety_legal_advice_scan', $analytics->eventTypes());
    $this->assertNotContains('post_gen_safety_review_flag', $analytics->eventTypes());
    $this->assertInternalFieldsAreHidden($body);
  }

  /**
   * Statute citation patterns caught by independent scan.
   *
   * Proves GAP G3 is closed: ResponseGrounder's 4 patterns miss statute
   * citations, but PostGenerationLegalAdviceDetector catches them.
   */
  public function testStatuteCitationCaughtByIndependentScan(): void {
    [$controller, $analytics] = $this->buildController();
    $controller->processIntentResponse = $this->buildResponse(
      'Under Idaho Code section 6-321, you have the right to withhold rent.',
      'faq'
    );

    $response = $controller->message($this->buildRequest());
    $body = json_decode($response->getContent(), TRUE);

    $this->assertSame(200, $response->getStatusCode());
    $this->assertSame(self::SAFE_FALLBACK, $body['message'] ?? NULL);
    $this->assertContains('post_gen_safety_legal_advice_scan', $analytics->eventTypes());
    $this->assertInternalFieldsAreHidden($body);
  }

  /**
   * Outcome prediction patterns caught by independent scan.
   */
  public function testOutcomePredictionCaughtByIndependentScan(): void {
    [$controller, $analytics] = $this->buildController();
    $controller->processIntentResponse = $this->buildResponse(
      'Your chances of winning this case are quite good.',
      'faq'
    );

    $response = $controller->message($this->buildRequest());
    $body = json_decode($response->getContent(), TRUE);

    $this->assertSame(200, $response->getStatusCode());
    $this->assertSame(self::SAFE_FALLBACK, $body['message'] ?? NULL);
    $this->assertContains('post_gen_safety_legal_advice_scan', $analytics->eventTypes());
    $this->assertInternalFieldsAreHidden($body);
  }

  /**
   * Qualification assertion patterns caught by independent scan.
   */
  public function testQualificationAssertionCaughtByIndependentScan(): void {
    [$controller, $analytics] = $this->buildController();
    $controller->processIntentResponse = $this->buildResponse(
      'You definitely qualify for legal aid based on your income.',
      'faq'
    );

    $response = $controller->message($this->buildRequest());
    $body = json_decode($response->getContent(), TRUE);

    $this->assertSame(200, $response->getStatusCode());
    $this->assertSame(self::SAFE_FALLBACK, $body['message'] ?? NULL);
    $this->assertContains('post_gen_safety_legal_advice_scan', $analytics->eventTypes());
    $this->assertInternalFieldsAreHidden($body);
  }

  /**
   * Action prescription patterns caught by independent scan.
   */
  public function testActionPrescriptionCaughtByIndependentScan(): void {
    [$controller, $analytics] = $this->buildController();
    $controller->processIntentResponse = $this->buildResponse(
      'I would advise you to file for divorce immediately.',
      'faq'
    );

    $response = $controller->message($this->buildRequest());
    $body = json_decode($response->getContent(), TRUE);

    $this->assertSame(200, $response->getStatusCode());
    $this->assertSame(self::SAFE_FALLBACK, $body['message'] ?? NULL);
    $this->assertContains('post_gen_safety_legal_advice_scan', $analytics->eventTypes());
    $this->assertInternalFieldsAreHidden($body);
  }

  /**
   * Safe messages must pass through the independent scan unchanged.
   */
  public function testSafeMessagePassesThroughIndependentScan(): void {
    [$controller, $analytics] = $this->buildController();
    $controller->processIntentResponse = $this->buildResponse(
      'Idaho Legal Aid Services provides free legal help to eligible Idahoans. Contact our office for more information.',
      'faq'
    );

    $response = $controller->message($this->buildRequest());
    $body = json_decode($response->getContent(), TRUE);

    $this->assertSame(200, $response->getStatusCode());
    $this->assertSame(
      'Idaho Legal Aid Services provides free legal help to eligible Idahoans. Contact our office for more information.',
      $body['message'] ?? NULL
    );
    $this->assertNotContains('post_gen_safety_legal_advice_scan', $analytics->eventTypes());
    $this->assertNotContains('post_gen_safety_review_flag', $analytics->eventTypes());
    $this->assertInternalFieldsAreHidden($body);
  }

  /**
   * Obfuscated legal advice is caught via InputNormalizer integration.
   *
   * Uses 4+ letter words for obfuscation since InputNormalizer's threshold
   * requires 4+ single letters separated by punctuation to trigger
   * normalization (preserving legitimate abbreviations like "U.S.").
   */
  public function testObfuscatedLegalAdviceCaughtByIndependentScan(): void {
    [$controller, $analytics] = $this->buildController();
    $controller->processIntentResponse = $this->buildResponse(
      'I w.o.u.l.d a.d.v.i.s.e you to file a motion.',
      'faq'
    );

    $response = $controller->message($this->buildRequest());
    $body = json_decode($response->getContent(), TRUE);

    $this->assertSame(200, $response->getStatusCode());
    $this->assertSame(self::SAFE_FALLBACK, $body['message'] ?? NULL);
    $this->assertContains('post_gen_safety_legal_advice_scan', $analytics->eventTypes());
    $this->assertInternalFieldsAreHidden($body);
  }

  /**
   * Review flag takes precedence; independent scan does not double-fire.
   *
   * When both ResponseGrounder's _requires_review flag AND the independent
   * PostGenerationLegalAdviceDetector would match, Check 1 fires first and
   * Check 1b is skipped (guarded by !$meta['message_replaced']).
   */
  public function testReviewFlagTakesPrecedenceOverIndependentScan(): void {
    [$controller, $analytics] = $this->buildController();
    // This message matches ResponseGrounder's 4 patterns AND PGLAD's 41.
    $controller->processIntentResponse = $this->buildResponse(
      'You should file a complaint with the court.',
      'faq'
    );

    $response = $controller->message($this->buildRequest());
    $body = json_decode($response->getContent(), TRUE);

    $this->assertSame(200, $response->getStatusCode());
    $this->assertSame(self::SAFE_FALLBACK, $body['message'] ?? NULL);
    $this->assertContains('post_gen_safety_review_flag', $analytics->eventTypes());
    $this->assertNotContains('post_gen_safety_legal_advice_scan', $analytics->eventTypes());
    $this->assertInternalFieldsAreHidden($body);
  }

  // -----------------------------------------------------------------------
  // PHARD-03: Weak grounding flag stripping
  // -----------------------------------------------------------------------

  /**
   * Tests that PHARD-03 internal flags are stripped from client response.
   */
  public function testWeakGroundingFlagStripping(): void {
    [$controller, $analytics] = $this->buildController();
    $controller->processIntentResponse = $this->buildResponse(
      'Some safe information about housing.',
      'faq'
    );
    $controller->processIntentResponse['llm_summary'] = 'Summary text.';
    $controller->processIntentResponse['llm_enhanced'] = TRUE;
    $controller->processIntentResponse['_grounding_weak'] = TRUE;
    $controller->processIntentResponse['_grounding_weak_reason'] = 'citation_required_type_without_citations';
    $controller->processIntentResponse['_all_citations_stale'] = TRUE;
    $controller->processIntentResponse['_stale_citation_count'] = 2;

    $response = $controller->message($this->buildRequest());
    $body = json_decode($response->getContent(), TRUE);

    $this->assertSame(200, $response->getStatusCode());
    $this->assertArrayNotHasKey('_grounding_weak', $body);
    $this->assertArrayNotHasKey('_grounding_weak_reason', $body);
    $this->assertArrayNotHasKey('_all_citations_stale', $body);
    $this->assertArrayNotHasKey('_stale_citation_count', $body);
    $this->assertInternalFieldsAreHidden($body);
  }

  /**
   * Tests that weak grounding strips legacy LLM fields on the controller path.
   */
  public function testWeakGroundingStripsLegacyLlmFields(): void {
    [$controller, $analytics] = $this->buildController();
    $controller->processIntentResponse = $this->buildResponse(
      'Some safe information.',
      'faq'
    );
    $controller->processIntentResponse['llm_summary'] = 'Original LLM summary with claims.';
    $controller->processIntentResponse['llm_enhanced'] = TRUE;
    $controller->processIntentResponse['_grounding_weak'] = TRUE;
    $controller->processIntentResponse['_grounding_weak_reason'] = 'citation_required_type_without_citations';

    $response = $controller->message($this->buildRequest());
    $body = json_decode($response->getContent(), TRUE);

    $this->assertSame(200, $response->getStatusCode());
    $this->assertSame('Some safe information.', $body['message'] ?? NULL);
    $this->assertArrayNotHasKey('llm_summary', $body);
    $this->assertArrayNotHasKey('llm_enhanced', $body);
    $this->assertContains('post_gen_safety_weak_grounding', $analytics->eventTypes());
    $this->assertInternalFieldsAreHidden($body);
  }

  // -----------------------------------------------------------------------
  // AFRP-10: Governance enforcement behavioral proofs
  // -----------------------------------------------------------------------

  /**
   * Proves _all_citations_stale adds freshness_caveat to client response.
   *
   * This is the behavioral proof that the all_citations_stale signal is
   * SOFT-enforced, not merely advisory.
   */
  public function testAllCitationsStaleAddsFreshnessCaveatToResponse(): void {
    [$controller, $analytics] = $this->buildController();
    $controller->processIntentResponse = $this->buildResponse(
      'Here is information about housing assistance.',
      'faq'
    );
    $controller->processIntentResponse['_all_citations_stale'] = TRUE;
    $controller->processIntentResponse['_stale_citation_count'] = 2;

    $response = $controller->message($this->buildRequest());
    $body = json_decode($response->getContent(), TRUE);

    $this->assertSame(200, $response->getStatusCode());
    $this->assertArrayHasKey('freshness_caveat', $body, 'Stale citations must produce a freshness_caveat in the response body.');
    $this->assertNotEmpty($body['freshness_caveat']);
    $this->assertContains('post_gen_stale_citations', $analytics->eventTypes(), 'Stale citations must fire the post_gen_stale_citations analytics event.');
    // Original message must NOT be replaced — only caveat added.
    $this->assertSame('Here is information about housing assistance.', $body['message'] ?? NULL);
    $this->assertInternalFieldsAreHidden($body);
  }

  /**
   * Proves governance metadata is present in normal responses.
   */
  public function testGovernanceMetadataInNormalResponse(): void {
    [$controller, $analytics] = $this->buildController();
    $controller->processIntentResponse = $this->buildResponse(
      'Idaho Legal Aid provides housing help.',
      'faq'
    );

    $response = $controller->message($this->buildRequest());
    $body = json_decode($response->getContent(), TRUE);

    $this->assertSame(200, $response->getStatusCode());
    $this->assertArrayHasKey('governance', $body, 'Every normal response must include governance metadata.');
    $this->assertArrayHasKey('enforcement_mode', $body['governance']);
    $this->assertArrayHasKey('policy_version', $body['governance']);
    $this->assertArrayHasKey('status', $body['governance']);
    $this->assertSame('advisory', $body['governance']['enforcement_mode']);
  }

  /**
   * Proves freshness_caveat is NOT added when citations are not stale.
   */
  public function testFreshCitationsDoNotProduceFreshnessCaveat(): void {
    [$controller, $analytics] = $this->buildController();
    $controller->processIntentResponse = $this->buildResponse(
      'Here is current housing information.',
      'faq'
    );

    $response = $controller->message($this->buildRequest());
    $body = json_decode($response->getContent(), TRUE);

    $this->assertSame(200, $response->getStatusCode());
    $this->assertArrayNotHasKey('freshness_caveat', $body, 'Fresh citations must NOT produce a freshness_caveat.');
    $this->assertNotContains('post_gen_stale_citations', $analytics->eventTypes());
  }

  /**
   * Ensures internal post-generation flags are never serialized to clients.
   */
  private function assertInternalFieldsAreHidden(array $body): void {
    $this->assertArrayNotHasKey('_requires_review', $body);
    $this->assertArrayNotHasKey('_validation_warnings', $body);
    $this->assertArrayNotHasKey('_grounding_version', $body);
    $this->assertArrayNotHasKey('_grounding_weak', $body);
    $this->assertArrayNotHasKey('_grounding_weak_reason', $body);
    $this->assertArrayNotHasKey('_all_citations_stale', $body);
    $this->assertArrayNotHasKey('_stale_citation_count', $body);
    $this->assertArrayNotHasKey('llm_summary', $body);
    $this->assertArrayNotHasKey('llm_enhanced', $body);
    $this->assertArrayNotHasKey('review_flag_triggered', $body);
    $this->assertArrayNotHasKey('message_replaced', $body);
    $this->assertArrayNotHasKey('weak_grounding_detected', $body);
    $this->assertArrayNotHasKey('stale_citations_caveat_added', $body);
    $this->assertArrayNotHasKey('llm_artifacts_stripped', $body);
    $this->assertArrayNotHasKey('legal_advice_scan_triggered', $body);
    $this->assertArrayNotHasKey('_freshness_enforcement', $body);
    $this->assertArrayNotHasKey('_freshness_confidence_cap', $body);
  }

  /**
   * Builds the config factory used by the controller under test.
   */
  private function buildConfigFactory(): ConfigFactoryInterface {
    $configStub = $this->createStub(ImmutableConfig::class);
    $configStub->method('get')->willReturnCallback(static function (string $key) {
      $values = [
        'rate_limit_per_minute' => 15,
        'rate_limit_per_hour' => 120,
        'enable_faq' => TRUE,
        'enable_logging' => FALSE,
        'langfuse.environment' => 'test',
        'langfuse.enabled' => FALSE,
      ];

      return $values[$key] ?? NULL;
    });

    $configFactory = $this->createStub(ConfigFactoryInterface::class);
    $configFactory->method('get')->willReturn($configStub);

    return $configFactory;
  }

  /**
   * Builds a translation stub that behaves like a pass-through translator.
   */
  private function translationStub(): TranslationInterface {
    return new class implements TranslationInterface {

      public function translate($string, array $args = [], array $options = []) {
        return strtr($string, $args);
      }

      public function translateString(\Drupal\Core\StringTranslation\TranslatableMarkup $translated_string) {
        return strtr($translated_string->getUntranslatedString(), $translated_string->getArguments());
      }

      public function formatPlural($count, $singular, $plural, array $args = [], array $options = []) {
        return strtr($count == 1 ? $singular : $plural, $args);
      }

    };
  }

}

/**
 * Analytics recorder for controller contract tests.
 */
final class RecordingAnalyticsLogger extends AnalyticsLogger {

  /**
   * Recorded analytics events.
   *
   * @var array<int, array{type: string, value: string}>
   */
  public array $events = [];

  /**
   * {@inheritdoc}
   */
  public function __construct() {}

  /**
   * {@inheritdoc}
   */
  public function log(string $event_type, string $event_value = '') {
    $this->events[] = [
      'type' => $event_type,
      'value' => $event_value,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function logNoAnswer(string $query, array $context = []): ?int {
    return NULL;
  }

  /**
   * Returns the recorded event types.
   *
   * @return string[]
   *   Event type list.
   */
  public function eventTypes(): array {
    return array_column($this->events, 'type');
  }

}

/**
 * LLM enhancer test double that preserves deterministic responses.
 */
final class PostGenerationTestableLlmEnhancer extends LlmEnhancer {

  public function __construct() {}

}

/**
 * Controller test double exposing the real message() response path.
 */
final class PostGenerationTestableController extends AssistantApiController {

  /**
   * The deterministic response returned by processIntent().
   *
   * @var array<string, mixed>
   */
  public array $processIntentResponse = [];

  /**
   * {@inheritdoc}
   */
  protected function processIntent(array $intent, string $message, array $context, string $request_id = '', array $server_history = []) {
    return $this->processIntentResponse;
  }

  /**
   * {@inheritdoc}
   */
  protected function getEscalationActions() {
    return [];
  }

}
