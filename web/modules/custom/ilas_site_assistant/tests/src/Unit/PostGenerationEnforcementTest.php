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
use Drupal\ilas_site_assistant\Service\FallbackGate;
use Drupal\ilas_site_assistant\Service\FaqIndex;
use Drupal\ilas_site_assistant\Service\IntentRouter;
use Drupal\ilas_site_assistant\Service\LlmEnhancer;
use Drupal\ilas_site_assistant\Service\PolicyFilter;
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
      response_grounder: new ResponseGrounder($sourceGovernance),
      source_governance: $sourceGovernance,
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
        ],
      ],
    ];
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
  public function logNoAnswer(string $query) {}

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

  /**
   * {@inheritdoc}
   */
  public function enhanceResponse(array $response, string $userQuery): array {
    unset($userQuery);
    return $response;
  }

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
