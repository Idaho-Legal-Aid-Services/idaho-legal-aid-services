<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\State\StateInterface;
use Drupal\ilas_site_assistant\Controller\AssistantApiController;
use Drupal\ilas_site_assistant\Service\AnalyticsLogger;
use Drupal\ilas_site_assistant\Service\AssistantFlowRunner;
use Drupal\ilas_site_assistant\Service\FallbackGate;
use Drupal\ilas_site_assistant\Service\FaqIndex;
use Drupal\ilas_site_assistant\Service\IntentRouter;
use Drupal\ilas_site_assistant\Service\LlmEnhancer;
use Drupal\ilas_site_assistant\Service\PolicyFilter;
use Drupal\ilas_site_assistant\Service\ResourceFinder;
use Drupal\ilas_site_assistant\Service\SourceGovernanceService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Contract tests for response contract normalization helpers.
 */
#[Group('ilas_site_assistant')]
final class ResponseContractNormalizationTest extends TestCase {

  /**
   * Builds a source-governance service with default citation policy.
   */
  private function buildSourceGovernanceService(): SourceGovernanceService {
    $config = $this->createStub(ImmutableConfig::class);
    $config->method('get')
      ->willReturnCallback(static fn(string $key) => $key === 'source_governance' ? NULL : NULL);

    $configFactory = $this->createStub(ConfigFactoryInterface::class);
    $configFactory->method('get')
      ->with('ilas_site_assistant.settings')
      ->willReturn($config);

    $state = $this->createStub(StateInterface::class);
    $logger = $this->createStub(LoggerInterface::class);

    return new SourceGovernanceService($configFactory, $state, $logger);
  }

  /**
   * Builds a controller with dependency stubs for private method testing.
   */
  private function buildController(): AssistantApiController {
    $configFactory = $this->createStub(ConfigFactoryInterface::class);
    $intentRouter = $this->createStub(IntentRouter::class);
    $faqIndex = $this->createStub(FaqIndex::class);
    $resourceFinder = $this->createStub(ResourceFinder::class);
    $policyFilter = $this->createStub(PolicyFilter::class);
    $analyticsLogger = $this->createStub(AnalyticsLogger::class);
    $llmEnhancer = $this->createStub(LlmEnhancer::class);
    $fallbackGate = $this->createStub(FallbackGate::class);
    $flood = $this->createStub(FloodInterface::class);
    $cache = $this->createStub(CacheBackendInterface::class);
    $logger = $this->createStub(LoggerInterface::class);
    $sourceGovernance = $this->buildSourceGovernanceService();

    return new AssistantApiController(
      $configFactory,
      $intentRouter,
      $faqIndex,
      $resourceFinder,
      $policyFilter,
      $analyticsLogger,
      $llmEnhancer,
      $fallbackGate,
      $flood,
      $cache,
      $logger,
      assistant_flow_runner: $this->createStub(AssistantFlowRunner::class),
      source_governance: $sourceGovernance,
    );
  }

  /**
   * Invokes assembleContractFields() via reflection.
   */
  private function invokeAssembleContractFields(
    AssistantApiController $controller,
    array $response,
    ?array $gateDecision,
    string $pathType,
  ): array {
    $method = new \ReflectionMethod(AssistantApiController::class, 'assembleContractFields');
    $method->setAccessible(TRUE);

    $result = $method->invoke($controller, $response, $gateDecision, $pathType);
    $this->assertIsArray($result);
    return $result;
  }

  /**
   * Confidence is clamped to [0, 1] with deterministic fallback by path type.
   */
  public function testConfidenceNormalizationAndFallback(): void {
    $controller = $this->buildController();

    $high = $this->invokeAssembleContractFields($controller, [], [
      'confidence' => 1.7,
      'reason_code' => FallbackGate::REASON_BORDERLINE_CONF,
    ], 'normal');
    $this->assertSame(1.0, $high['confidence']);

    $low = $this->invokeAssembleContractFields($controller, [], [
      'confidence' => -0.2,
      'reason_code' => FallbackGate::REASON_LOW_RETRIEVAL_SCORE,
    ], 'normal');
    $this->assertSame(0.0, $low['confidence']);

    $safetyFallback = $this->invokeAssembleContractFields($controller, [], [
      'confidence' => NAN,
      'reason_code' => FallbackGate::REASON_SAFETY_URGENT,
    ], 'safety');
    $this->assertSame(1.0, $safetyFallback['confidence']);

    $normalFallback = $this->invokeAssembleContractFields($controller, [], NULL, 'normal');
    $this->assertSame(0.0, $normalFallback['confidence']);
  }

  /**
   * Citations prefer sources and derive safely from results when missing.
   */
  public function testCitationsNormalizationPrefersSourcesThenDerivesFromResults(): void {
    $controller = $this->buildController();

    $responseWithSources = [
      'sources' => [
        ['title' => 'A', 'url' => '/a'],
        ['title' => 'Bad', 'url' => 'javascript:alert(1)'],
      ],
      'results' => [
        ['title' => 'Result title', 'url' => '/result'],
      ],
      'reason_code' => FallbackGate::REASON_HIGH_CONF_RETRIEVAL,
    ];

    $preferred = $this->invokeAssembleContractFields($controller, $responseWithSources, [
      'confidence' => 0.8,
      'reason_code' => FallbackGate::REASON_HIGH_CONF_RETRIEVAL,
    ], 'normal');
    $this->assertSame([['title' => 'A', 'url' => '/a']], $preferred['citations']);

    $responseDerived = [
      'results' => [
        [
          'title' => 'Housing FAQ',
          'url' => '/housing',
          'source_class' => 'faq_lexical',
        ],
        [
          'title' => 'Poisoned FAQ',
          'url' => 'https://attacker.example.com/phish',
          'source_class' => 'faq_lexical',
        ],
      ],
      'reason_code' => FallbackGate::REASON_BORDERLINE_CONF,
    ];
    $derived = $this->invokeAssembleContractFields($controller, $responseDerived, [
      'confidence' => 0.55,
      'reason_code' => FallbackGate::REASON_BORDERLINE_CONF,
    ], 'normal');
    $this->assertCount(1, $derived['citations']);
    $this->assertSame('Housing FAQ', $derived['citations'][0]['title']);
    $this->assertSame('/housing', $derived['citations'][0]['url']);
    $this->assertSame('faq_lexical', $derived['citations'][0]['source']);
  }

  /**
   * Decision reason maps known reason codes and falls back by path.
   */
  public function testDecisionReasonNormalizationUsesReasonMapAndPathFallback(): void {
    $controller = $this->buildController();

    $mapped = $this->invokeAssembleContractFields($controller, [
      'reason_code' => FallbackGate::REASON_LOW_RETRIEVAL_SCORE,
    ], ['confidence' => 0.25], 'normal');
    $this->assertSame(
      'Retrieval results not confident enough',
      $mapped['decision_reason']
    );

    $unknownCode = $this->invokeAssembleContractFields($controller, [
      'reason_code' => 'UNKNOWN_REASON_CODE',
    ], ['confidence' => 0.4], 'normal');
    $this->assertSame('UNKNOWN_REASON_CODE', $unknownCode['decision_reason']);

    $pathFallback = $this->invokeAssembleContractFields($controller, [], NULL, 'oos');
    $this->assertSame(
      'Request classified as outside service scope',
      $pathFallback['decision_reason']
    );
  }

}
