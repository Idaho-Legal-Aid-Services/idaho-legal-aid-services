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
use Drupal\ilas_site_assistant\Service\GapReviewDecider;
use Drupal\ilas_site_assistant\Service\IntentRouter;
use Drupal\ilas_site_assistant\Service\LlmEnhancer;
use Drupal\ilas_site_assistant\Service\PolicyFilter;
use Drupal\ilas_site_assistant\Service\ResourceFinder;
use Drupal\ilas_site_assistant\Service\ResponseBuilder;
use Drupal\ilas_site_assistant\Service\SelectionRegistry;
use Drupal\ilas_site_assistant\Service\SelectionStateStore;
use Drupal\ilas_site_assistant\Service\SourceGovernanceService;
use Drupal\ilas_site_assistant\Service\TopIntentsPack;
use Drupal\Tests\ilas_site_assistant\Support\CanonicalUrlFixtures;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * PHARD-03 grounding refusal vs. curated pack answers.
 *
 * A Top Intents pack answer describes its own canonical page, so it must
 * survive empty retrieval (empty index, outage, low vector scores) by citing
 * that page. Every other answerable type with nothing to cite is still
 * refused, and the refusal must be visible to the gap-review trigger.
 */
#[Group('ilas_site_assistant')]
final class GroundingRefusalPackCitationTest extends TestCase {

  /**
   * The zero-results gate decision for a high-confidence retrieval intent.
   */
  private const NO_RESULTS_GATE = [
    'confidence' => 0.49,
    'reason_code' => FallbackGate::REASON_NO_RESULTS,
  ];

  /**
   * Builds a controller with stubs; the analytics logger may be a mock.
   */
  private function buildController(?AnalyticsLogger $analyticsLogger = NULL, ?LoggerInterface $logger = NULL): AssistantApiController {
    $config = $this->createStub(ImmutableConfig::class);
    $config->method('get')->willReturn(NULL);
    $governanceConfigFactory = $this->createStub(ConfigFactoryInterface::class);
    $governanceConfigFactory->method('get')->willReturn($config);
    $sourceGovernance = new SourceGovernanceService(
      $governanceConfigFactory,
      $this->createStub(StateInterface::class),
      $this->createStub(LoggerInterface::class),
    );

    $cache = $this->createStub(CacheBackendInterface::class);
    $controller = new AssistantApiController(
      $this->createStub(ConfigFactoryInterface::class),
      $this->createStub(IntentRouter::class),
      $this->createStub(FaqIndex::class),
      $this->createStub(ResourceFinder::class),
      $this->createStub(PolicyFilter::class),
      $analyticsLogger ?? $this->createStub(AnalyticsLogger::class),
      $this->createStub(LlmEnhancer::class),
      $this->createStub(FallbackGate::class),
      $this->createStub(FloodInterface::class),
      $cache,
      $logger ?? $this->createStub(LoggerInterface::class),
      assistant_flow_runner: $this->createStub(AssistantFlowRunner::class),
      selection_registry: new SelectionRegistry(new TopIntentsPack()),
      selection_state_store: new SelectionStateStore($cache),
      source_governance: $sourceGovernance,
    );

    $translation = $this->createStub(TranslationInterface::class);
    $translation->method('translateString')
      ->willReturnCallback(static fn($markup) => (string) $markup->getUntranslatedString());
    $controller->setStringTranslation($translation);

    return $controller;
  }

  /**
   * Invokes a private controller method.
   */
  private function invoke(AssistantApiController $controller, string $method, array $args): array {
    $reflection = new \ReflectionMethod(AssistantApiController::class, $method);
    $reflection->setAccessible(TRUE);
    $result = $reflection->invokeArgs($controller, $args);
    $this->assertIsArray($result);
    return $result;
  }

  /**
   * Builds the response skeleton the controller derives from the contract.
   */
  private function buildPackResponse(string $intent_type): array {
    $builder = new ResponseBuilder(CanonicalUrlFixtures::defaults(), new TopIntentsPack());
    $contract = $builder->buildFromIntent(['type' => $intent_type], 'tenant rights');
    return [
      'type' => $contract['type'],
      'message' => $contract['answer_text'],
      'response_mode' => $contract['response_mode'],
      'primary_action' => $contract['primary_action'],
      'reason_code' => $contract['reason_code'],
    ];
  }

  /**
   * A pack answer with zero retrieval results cites its own page.
   */
  public function testPackAnswerSurvivesEmptyRetrievalByCitingItsCanonicalPage(): void {
    $analyticsLogger = $this->createMock(AnalyticsLogger::class);
    $analyticsLogger->expects($this->never())->method('log');
    $controller = $this->buildController($analyticsLogger);

    $response = $this->buildPackResponse('topic_housing_landlord_tenant');
    $this->assertSame('topic', $response['type']);
    $this->assertSame('intent_pack_topic_housing_landlord_tenant', $response['reason_code']);

    $after = $this->invoke($controller, 'applyGroundingRefusal', [$response, self::NO_RESULTS_GATE, 'tenant rights', 'req-1']);
    $this->assertSame('topic', $after['type'], 'A curated pack answer must not be refused for lack of retrieval results.');
    $this->assertSame($response['message'], $after['message']);

    $contract = $this->invoke($controller, 'assembleContractFields', [$after, self::NO_RESULTS_GATE, 'normal']);
    $this->assertCount(1, $contract['citations']);
    $this->assertSame('/resources/landlord-and-tenant', $contract['citations'][0]['url']);
    $this->assertSame('curated_pack', $contract['citations'][0]['source']);
    $this->assertSame('Landlord and Tenant Resources', $contract['citations'][0]['title']);
    $this->assertNull(GapReviewDecider::resolveTriggerCode($after, ['type' => 'topic_housing_landlord_tenant']));
  }

  /**
   * Retrieval evidence always wins over the pack's own page.
   */
  public function testPackCitationIsOnlyAFallback(): void {
    $controller = $this->buildController();
    $response = $this->buildPackResponse('topic_housing_landlord_tenant') + [
      'results' => [['title' => 'Eviction FAQ', 'url' => '/faq#eviction', 'source_class' => 'faq_lexical']],
    ];

    $contract = $this->invoke($controller, 'assembleContractFields', [$response, ['confidence' => 0.8], 'normal']);
    $this->assertCount(1, $contract['citations']);
    $this->assertSame('/faq#eviction', $contract['citations'][0]['url']);
  }

  /**
   * A pack entry whose URL fails citation policy gets no exemption.
   */
  public function testPackAnswerWithUnusableUrlIsStillRefused(): void {
    $controller = $this->buildController();
    $response = $this->buildPackResponse('topic_housing_landlord_tenant');
    $response['primary_action']['url'] = 'https://attacker.example.com/phish';

    $after = $this->invoke($controller, 'applyGroundingRefusal', [$response, self::NO_RESULTS_GATE, 'tenant rights', 'req-2']);
    $this->assertSame('clarify_no_grounding', $after['type']);
  }

  /**
   * Non-pack answerable types are refused, and the refusal is reviewable.
   */
  public function testNonPackTopicWithoutEvidenceIsRefusedAndReachesGapReview(): void {
    $analyticsLogger = $this->createMock(AnalyticsLogger::class);
    $analyticsLogger->expects($this->once())->method('log')->with('grounding_refusal', 'req-3');
    $controller = $this->buildController($analyticsLogger);

    $response = [
      'type' => 'topic',
      'message' => 'Here is our housing page.',
      'primary_action' => ['label' => 'Housing', 'url' => '/legal-help/housing'],
      'reason_code' => 'topic_match',
      'generation' => ['used' => TRUE],
    ];

    $after = $this->invoke($controller, 'applyGroundingRefusal', [$response, self::NO_RESULTS_GATE, 'rent help', 'req-3']);
    $this->assertSame('clarify_no_grounding', $after['type']);
    $this->assertStringContainsString("wasn't able to find specific information", $after['message']);
    $this->assertSame(['used' => FALSE, 'reason' => 'grounding_refusal'], $after['generation']);
    $this->assertSame(
      GapReviewDecider::TRIGGER_CLARIFY_NO_GROUNDING,
      GapReviewDecider::resolveTriggerCode($after, ['type' => 'topic_housing']),
      'The refusal must be decided before the gap trigger is resolved.',
    );

    // Contract assembly recomputes confidence from the gate decision; the
    // refusal's own contract values must win and the internal flag must go.
    $contract = $this->invoke($controller, 'assembleContractFields', [$after, self::NO_RESULTS_GATE, 'normal']);
    $this->assertSame(0.0, $contract['confidence']);
    $this->assertSame('answerable_type_no_citations_low_confidence', $contract['decision_reason']);
    $this->assertArrayNotHasKey('_grounding_refusal_reason', $contract);

    // The post-assembly backstop is a no-op for an already-refused response.
    $again = $this->invoke($controller, 'applyGroundingRefusal', [$contract, self::NO_RESULTS_GATE, 'rent help', 'req-3', FALSE, $contract]);
    $this->assertSame($contract, $again);
  }

  /**
   * Spanish input gets the Spanish refusal copy.
   */
  public function testRefusalCopyFollowsSpanishInput(): void {
    $controller = $this->buildController();
    $response = ['type' => 'faq', 'message' => 'x', 'reason_code' => 'faq_match'];

    $after = $this->invoke($controller, 'applyGroundingRefusal', [$response, self::NO_RESULTS_GATE, 'necesito ayuda con mi desalojo', 'req-4']);
    $this->assertStringContainsString('No encontré información específica', $after['message']);
  }

  /**
   * Eval traffic must not inflate the grounding_refusal counter.
   */
  public function testEvalTrafficDoesNotCountRefusals(): void {
    $analyticsLogger = $this->createMock(AnalyticsLogger::class);
    $analyticsLogger->expects($this->never())->method('log');
    $controller = $this->buildController($analyticsLogger);

    $after = $this->invoke($controller, 'applyGroundingRefusal', [
      ['type' => 'resources', 'message' => 'x'],
      self::NO_RESULTS_GATE,
      'forms',
      'req-5',
      TRUE,
    ]);
    $this->assertSame('clarify_no_grounding', $after['type']);
  }

  /**
   * A retrieval failure is labelled as such, not as a content gap.
   */
  public function testRefusalDuringRetrievalFailureIsLabelledDegraded(): void {
    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->once())->method('warning')
      ->with($this->stringContains('retrieval was degraded'));
    $controller = $this->buildController(NULL, $logger);

    $response = [
      'type' => 'topic',
      'message' => 'x',
      'reason_code' => 'topic_match',
      '_retrieval_degraded' => 'RuntimeException',
    ];
    $after = $this->invoke($controller, 'applyGroundingRefusal', [$response, self::NO_RESULTS_GATE, 'rent help', 'req-6']);
    $contract = $this->invoke($controller, 'assembleContractFields', [$after, self::NO_RESULTS_GATE, 'normal']);
    $this->assertSame('answerable_type_no_citations_retrieval_degraded', $contract['decision_reason']);
  }

  /**
   * Pipeline bookkeeping keys never leave the single response exit point.
   *
   * Per-path unset lists kept missing keys (the safety-escalation path skips
   * them entirely), so jsonResponse() drops every "_" key except _debug.
   */
  public function testInternalBookkeepingKeysAreDroppedAtTheExitPoint(): void {
    $controller = $this->buildController();
    $method = new \ReflectionMethod(AssistantApiController::class, 'jsonResponse');
    $method->setAccessible(TRUE);

    $json = $method->invoke($controller, [
      'type' => 'escalation',
      'message' => 'Call 911.',
      'meta' => ['_nested' => 'nested keys are data, not bookkeeping'],
      '_grounded' => TRUE,
      '_citation_added' => TRUE,
      '_hard_route_enforced' => TRUE,
      '_all_citations_stale' => FALSE,
      '_retrieval_degraded' => 'lexical_search_failed',
      '_debug' => ['intent_selected' => 'high_risk'],
    ], 200, [], 'req-9');

    $body = json_decode((string) $json->getContent(), TRUE);
    $this->assertSame(['type', 'message', 'meta', '_debug'], array_keys($body));
    $this->assertSame('Call 911.', $body['message']);
    $this->assertSame('req-9', $json->headers->get('X-Correlation-ID'));
  }

  /**
   * Types that never required citations, and confident answers, pass through.
   */
  public function testNonAnswerableAndConfidentResponsesAreUntouched(): void {
    $controller = $this->buildController();

    $navigation = ['type' => 'navigation', 'message' => 'Apply here.'];
    $this->assertSame($navigation, $this->invoke($controller, 'applyGroundingRefusal', [$navigation, self::NO_RESULTS_GATE, 'apply', 'req-7']));

    $eligibility = ['type' => 'eligibility', 'message' => 'Eligibility info.'];
    $this->assertSame($eligibility, $this->invoke($controller, 'applyGroundingRefusal', [$eligibility, ['confidence' => 0.85], 'am i eligible', 'req-8']));
  }

}
