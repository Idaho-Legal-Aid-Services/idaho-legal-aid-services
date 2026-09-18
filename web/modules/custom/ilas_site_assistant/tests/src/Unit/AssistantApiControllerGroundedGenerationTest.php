<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\ilas_site_assistant\Controller\AssistantApiController;
use Drupal\ilas_site_assistant\Service\AssistantFlowRunner;
use Drupal\ilas_site_assistant\Service\FallbackGate;
use Drupal\ilas_site_assistant\Service\FaqIndex;
use Drupal\ilas_site_assistant\Service\GroundedAnswerGenerator;
use Drupal\ilas_site_assistant\Service\IntentRouter;
use Drupal\ilas_site_assistant\Service\LlmEnhancer;
use Drupal\ilas_site_assistant\Service\PolicyFilter;
use Drupal\ilas_site_assistant\Service\PreRoutingDecisionEngine;
use Drupal\ilas_site_assistant\Service\ResourceFinder;
use Drupal\ilas_site_assistant\Service\ResponseGrounder;
use Drupal\ilas_site_assistant\Service\SelectionRegistry;
use Drupal\ilas_site_assistant\Service\SelectionStateStore;
use Drupal\ilas_site_assistant\Service\SourceGovernanceService;
use Drupal\ilas_site_assistant\Service\TopIntentsPack;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpFoundation\Request;

/**
 * Controller-path contract tests for grounded answer generation (TOVR-18).
 */
#[Group('ilas_site_assistant')]
final class AssistantApiControllerGroundedGenerationTest extends TestCase {

  private const TEMPLATE = 'Here is what our website says about applying for help.';
  private const GENERATED = 'You can apply for help online or by calling the Legal Advice Line, and intake staff will ask about your income and legal problem.';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    require_once __DIR__ . '/controller_test_bootstrap.php';
    $container = new ContainerBuilder();
    $container->set('logger.factory', new class {

      /**
       * Returns a null logger for any channel.
       */
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
   * A used generation replaces the message and exposes trimmed citations.
   */
  public function testUsedGenerationReplacesMessageAndReportsDiagnostics(): void {
    $generator = $this->buildGenerator([
      'attempted' => TRUE,
      'used' => TRUE,
      'reason' => 'generated',
      'message' => self::GENERATED,
      'citations' => [
        ['result_index' => 0, 'document_id' => 'doc_0', 'url' => '/faq/apply#how', 'title' => 'How do I apply?', 'start' => 0, 'end' => 10],
      ],
      'usage' => ['input' => 900, 'output' => 40, 'total' => 940],
    ]);
    [$controller, $analytics] = $this->buildController($generator);
    $controller->processIntentResponse = $this->buildResponse(self::TEMPLATE);

    $response = $controller->message($this->buildRequest());
    $body = json_decode((string) $response->getContent(), TRUE);

    $this->assertSame(200, $response->getStatusCode());
    $this->assertSame(self::GENERATED, $body['message']);
    $this->assertTrue($body['generation']['used']);
    $this->assertSame('cohere', $body['generation']['provider']);
    $this->assertSame([
      ['result_index' => 0, 'url' => '/faq/apply#how', 'title' => 'How do I apply?'],
    ], $body['generation']['citations'], 'public citations carry only result_index/url/title');
    $this->assertArrayNotHasKey('_message_template', $body);
    $this->assertSame('grounded_answer', $body['meta']['generation']['operation']);
    $this->assertTrue($body['meta']['generation']['used']);
    $this->assertSame('generated', $body['meta']['generation']['reason']);
    $this->assertTrue($body['meta']['generation']['operations']['grounded_answer']['used']);
    $this->assertSame(1, $body['meta']['generation']['operations']['grounded_answer']['citation_count']);
    $this->assertFalse($body['meta']['generation']['operations']['intent_classification']['used']);
    $this->assertNotEmpty($body['sources'] ?? [], 'grounding sources still derive from results');
    $this->assertNotContains('post_gen_generation_reverted', $analytics->eventTypes());
    $this->assertSame(1, $generator->generateCalls);
    $this->assertSame('help me apply', $generator->lastUserMessage);
  }

  /**
   * A generation fallback keeps the template byte-for-byte.
   */
  public function testFallbackKeepsTemplateAndReportsReason(): void {
    $generator = $this->buildGenerator([
      'attempted' => TRUE,
      'used' => FALSE,
      'reason' => 'no_citations',
      'message' => NULL,
      'citations' => [],
      'usage' => [],
    ]);
    [$controller] = $this->buildController($generator);
    $controller->processIntentResponse = $this->buildResponse(self::TEMPLATE);

    $body = json_decode((string) $controller->message($this->buildRequest())->getContent(), TRUE);

    $this->assertSame(self::TEMPLATE, $body['message']);
    $this->assertSame(['used' => FALSE, 'reason' => 'no_citations'], $body['generation']);
    $this->assertSame('grounded_answer', $body['meta']['generation']['operation']);
    $this->assertFalse($body['meta']['generation']['used']);
    $this->assertSame('no_citations', $body['meta']['generation']['reason']);
    $this->assertFalse($body['meta']['generation']['operations']['grounded_answer']['used']);
  }

  /**
   * Legal-advice phrasing in generated text reverts to the template.
   */
  public function testLegalAdviceInGeneratedTextRevertsToTemplate(): void {
    $generator = $this->buildGenerator([
      'attempted' => TRUE,
      'used' => TRUE,
      'reason' => 'generated',
      'message' => 'You should file a motion with the court right away.',
      'citations' => [
        ['result_index' => 0, 'document_id' => 'doc_0', 'url' => '/faq/apply', 'title' => 'Apply', 'start' => 0, 'end' => 3],
      ],
      'usage' => [],
    ]);
    [$controller, $analytics] = $this->buildController($generator);
    $controller->processIntentResponse = $this->buildResponse(self::TEMPLATE);

    $body = json_decode((string) $controller->message($this->buildRequest())->getContent(), TRUE);

    $this->assertSame(self::TEMPLATE, $body['message'], 'the deterministic template is restored, not the generic fallback');
    $this->assertSame(['used' => FALSE, 'reason' => 'post_generation_safety'], $body['generation']);
    $this->assertContains('post_gen_safety_legal_advice_scan', $analytics->eventTypes());
    $this->assertContains('post_gen_generation_reverted', $analytics->eventTypes());
    $this->assertFalse($body['meta']['generation']['used']);
    $this->assertSame('post_generation_safety', $body['meta']['generation']['reason']);
    $this->assertArrayNotHasKey('_message_template', $body);
  }

  /**
   * Without a generator the response has no generation block.
   */
  public function testMissingGeneratorLeavesResponseUnchanged(): void {
    [$controller] = $this->buildController(NULL);
    $controller->processIntentResponse = $this->buildResponse(self::TEMPLATE);

    $body = json_decode((string) $controller->message($this->buildRequest())->getContent(), TRUE);

    $this->assertSame(self::TEMPLATE, $body['message']);
    $this->assertArrayNotHasKey('generation', $body);
    $this->assertNull($body['meta']['generation']['operation']);
    $this->assertFalse($body['meta']['generation']['used']);
    $this->assertArrayNotHasKey('grounded_answer', $body['meta']['generation']['operations']);
  }

  /**
   * A disabled or non-applicable generator is never asked to generate.
   */
  public function testDisabledOrNotApplicableGeneratorIsNotCalled(): void {
    $generator = $this->buildGenerator(['attempted' => TRUE, 'used' => TRUE, 'message' => 'x', 'reason' => 'generated', 'citations' => [], 'usage' => []], enabled: FALSE);
    [$controller] = $this->buildController($generator);
    $controller->processIntentResponse = $this->buildResponse(self::TEMPLATE);
    $body = json_decode((string) $controller->message($this->buildRequest())->getContent(), TRUE);
    $this->assertSame(self::TEMPLATE, $body['message']);
    $this->assertArrayNotHasKey('generation', $body);
    $this->assertSame(0, $generator->generateCalls);

    $generator = $this->buildGenerator(['attempted' => TRUE, 'used' => TRUE, 'message' => 'x', 'reason' => 'generated', 'citations' => [], 'usage' => []], applies: FALSE);
    [$controller] = $this->buildController($generator);
    $controller->processIntentResponse = $this->buildResponse(self::TEMPLATE);
    $body = json_decode((string) $controller->message($this->buildRequest())->getContent(), TRUE);
    $this->assertSame(self::TEMPLATE, $body['message']);
    $this->assertSame(0, $generator->generateCalls);
  }

  /**
   * Builds a scripted generator double.
   */
  private function buildGenerator(array $result, bool $enabled = TRUE, bool $applies = TRUE): RecordingGroundedAnswerGenerator {
    return new RecordingGroundedAnswerGenerator($result + ['operation' => 'grounded_answer', 'provider' => 'cohere', 'model' => 'command-a-03-2025', 'documents_count' => 1, 'cache_hit' => FALSE, 'latency_ms' => 12.5, 'language' => 'en'], $enabled, $applies);
  }

  /**
   * Builds the controller double with the real message() path.
   *
   * @return array{0: GroundedGenerationTestableController, 1: RecordingAnalyticsLogger}
   *   Controller and analytics recorder.
   */
  private function buildController(?GroundedAnswerGenerator $generator): array {
    $configFactory = $this->buildConfigFactory();

    $flood = $this->createStub(FloodInterface::class);
    $flood->method('isAllowed')->willReturn(TRUE);

    $intentRouter = $this->createStub(IntentRouter::class);
    $intentRouter->method('route')->willReturn(['type' => 'faq', 'confidence' => 0.95]);

    $faqIndex = $this->createStub(FaqIndex::class);
    $faqIndex->method('search')->willReturn([]);
    $resourceFinder = $this->createStub(ResourceFinder::class);

    $policyFilter = $this->createStub(PolicyFilter::class);
    $policyFilter->method('check')->willReturn(['passed' => TRUE, 'violation' => FALSE]);

    $analyticsLogger = new RecordingAnalyticsLogger();
    $state = $this->createStub(StateInterface::class);
    $state->method('get')->willReturnCallback(static fn(string $key, $default = NULL) => $default);
    $sourceGovernance = new SourceGovernanceService($configFactory, $state, new NullLogger());

    $fallbackGate = $this->createStub(FallbackGate::class);
    $fallbackGate->method('evaluate')->willReturn(['decision' => 'allow', 'reason_code' => 'test', 'confidence' => 1.0]);

    $cache = $this->createStub(CacheBackendInterface::class);
    $cache->method('get')->willReturn(FALSE);

    $controller = new GroundedGenerationTestableController(
      config_factory: $configFactory,
      intent_router: $intentRouter,
      faq_index: $faqIndex,
      resource_finder: $resourceFinder,
      policy_filter: $policyFilter,
      analytics_logger: $analyticsLogger,
      llm_enhancer: new GroundedGenerationTestableLlmEnhancer(),
      fallback_gate: $fallbackGate,
      flood: $flood,
      conversation_cache: $cache,
      logger: new NullLogger(),
      assistant_flow_runner: $this->createStub(AssistantFlowRunner::class),
      selection_registry: new SelectionRegistry(new TopIntentsPack()),
      selection_state_store: new SelectionStateStore($cache),
      response_grounder: new ResponseGrounder($sourceGovernance),
      source_governance: $sourceGovernance,
      pre_routing_decision_engine: new PreRoutingDecisionEngine($policyFilter),
      grounded_answer_generator: $generator,
    );

    return [$controller, $analyticsLogger];
  }

  /**
   * Builds a deterministic JSON request.
   */
  private function buildRequest(): Request {
    return Request::create(
      '/assistant/api/message',
      'POST',
      [],
      [],
      [],
      ['CONTENT_TYPE' => 'application/json'],
      json_encode(['message' => 'help me apply']),
    );
  }

  /**
   * Builds a deterministic processIntent response shape.
   */
  private function buildResponse(string $message): array {
    return [
      'type' => 'faq',
      'message' => $message,
      'response_mode' => 'answer',
      'primary_action' => [],
      'secondary_actions' => [],
      'reason_code' => 'test',
      'results' => [
        [
          'question' => 'How do I apply?',
          'answer' => 'You can apply for help online or by calling the Legal Advice Line.',
          'url' => '/faq/apply',
          'freshness' => ['status' => 'fresh'],
        ],
      ],
    ];
  }

  /**
   * Builds the config factory used by the controller and services.
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
        'llm.generation.temperature' => 0.2,
        'llm.generation.max_tokens' => 220,
        'llm.generation.citation_mode' => 'FAST',
      ];
      return $values[$key] ?? NULL;
    });
    $configFactory = $this->createStub(ConfigFactoryInterface::class);
    $configFactory->method('get')->willReturn($configStub);
    return $configFactory;
  }

  /**
   * Pass-through translator.
   */
  private function translationStub(): TranslationInterface {
    return new class implements TranslationInterface {

      /**
       * {@inheritdoc}
       */
      public function translate($string, array $args = [], array $options = []) {
        return strtr($string, $args);
      }

      /**
       * {@inheritdoc}
       */
      public function translateString(TranslatableMarkup $translated_string) {
        return strtr($translated_string->getUntranslatedString(), $translated_string->getArguments());
      }

      /**
       * {@inheritdoc}
       */
      public function formatPlural($count, $singular, $plural, array $args = [], array $options = []) {
        return strtr($count == 1 ? $singular : $plural, $args);
      }

    };
  }

}

/**
 * Scripted generator double recording calls.
 */
final class RecordingGroundedAnswerGenerator extends GroundedAnswerGenerator {

  /**
   * Number of generate() calls.
   */
  public int $generateCalls = 0;

  /**
   * Last user message passed to generate().
   */
  public ?string $lastUserMessage = NULL;

  /**
   * Constructs the scripted generator.
   *
   * @param array<string, mixed> $result
   *   Scripted generate() result.
   * @param bool $enabled
   *   isEnabled() return value.
   * @param bool $appliesResult
   *   applies() return value.
   */
  public function __construct(
    private readonly array $result,
    private readonly bool $enabled,
    private readonly bool $appliesResult,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function isEnabled(): bool {
    return $this->enabled;
  }

  /**
   * {@inheritdoc}
   */
  public function applies(array $response): bool {
    return $this->appliesResult;
  }

  /**
   * {@inheritdoc}
   */
  public function getProviderId(): string {
    return 'cohere';
  }

  /**
   * {@inheritdoc}
   */
  public function getModelId(): string {
    return 'command-a-03-2025';
  }

  /**
   * {@inheritdoc}
   */
  public function generate(string $userMessage, array $response, array $options = []): array {
    $this->generateCalls++;
    $this->lastUserMessage = $userMessage;
    return $this->result;
  }

}

/**
 * Enhancer double that never classifies.
 */
final class GroundedGenerationTestableLlmEnhancer extends LlmEnhancer {

  public function __construct() {}

}

/**
 * Controller double exposing the real message() path.
 */
final class GroundedGenerationTestableController extends AssistantApiController {

  /**
   * The deterministic response returned by processIntent().
   *
   * @var array<string, mixed>
   */
  public array $processIntentResponse = [];

  /**
   * {@inheritdoc}
   */
  protected function processIntent(array $intent, string $message, array $context, string $request_id = '', array $server_history = [], array $conversation_context_summary = []) {
    return $this->processIntentResponse;
  }

  /**
   * {@inheritdoc}
   */
  protected function getEscalationActions() {
    return [];
  }

}
