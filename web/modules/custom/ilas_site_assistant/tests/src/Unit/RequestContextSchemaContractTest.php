<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Flood\FloodInterface;
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
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpFoundation\Request;

/**
 * Contracts the public /message request context schema.
 */
#[Group('ilas_site_assistant')]
final class RequestContextSchemaContractTest extends TestCase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    require_once __DIR__ . '/controller_test_bootstrap.php';

    $configStub = $this->createStub(ImmutableConfig::class);
    $configStub->method('get')->willReturnCallback(function (string $key) {
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

    $translationStub = $this->createStub(TranslationInterface::class);
    $translationStub->method('translateString')->willReturnCallback(
      static fn($markup) => $markup->getUntranslatedString()
    );

    $container = new ContainerBuilder();
    $container->set('logger.factory', new class {

      public function get(string $channel): NullLogger {
        return new NullLogger();
      }

    });
    $container->set('string_translation', $translationStub);
    $container->set('config.factory', $configFactory);

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
   * Unsupported request-context keys are stripped before downstream use.
   */
  public function testUnknownContextKeysAreStrippedBeforeDownstreamUse(): void {
    $observed = [];
    $controller = $this->buildController($observed);

    $response = $controller->message($this->buildJsonRequest([
      'message' => 'Housing help',
      'context' => [
        'history' => [['role' => 'user', 'text' => 'hello']],
        'recovery_retry' => TRUE,
        'injectedKey' => 'x',
      ],
    ]));

    $this->assertSame(200, $response->getStatusCode());
    $this->assertSame(1, $observed['route_calls']);
    $this->assertSame([[]], $observed['route_contexts']);
    $this->assertSame([], $controller->lastProcessIntentContext);
  }

  /**
   * Allowed quickAction survives normalization and bypasses router dispatch.
   */
  public function testAllowedQuickActionIsPreservedForProcessing(): void {
    $observed = [];
    $controller = $this->buildController($observed);

    $response = $controller->message($this->buildJsonRequest([
      'message' => 'Show me FAQs',
      'context' => [
        'quickAction' => 'faq',
        'injectedKey' => 'x',
      ],
    ]));

    $this->assertSame(200, $response->getStatusCode());
    $this->assertSame(0, $observed['route_calls']);
    $this->assertSame(['quickAction' => 'faq'], $controller->lastProcessIntentContext);
  }

  /**
   * Invalid quickAction values are dropped before routing and processing.
   */
  public function testInvalidQuickActionIsDroppedBeforeDownstreamUse(): void {
    $observed = [];
    $controller = $this->buildController($observed);

    $response = $controller->message($this->buildJsonRequest([
      'message' => 'Find housing and eviction forms',
      'context' => [
        'quickAction' => 'forms_housing',
      ],
    ]));

    $this->assertSame(200, $response->getStatusCode());
    $this->assertSame(1, $observed['route_calls']);
    $this->assertSame([[]], $observed['route_contexts']);
    $this->assertSame([], $controller->lastProcessIntentContext);
  }

  /**
   * Malformed scalar contexts are rejected with a 400 request error.
   */
  public function testScalarContextReturns400(): void {
    $observed = [];
    $controller = $this->buildController($observed);

    $response = $controller->message($this->buildJsonRequest([
      'message' => 'Housing help',
      'context' => 'invalid-context',
    ]));

    $body = json_decode($response->getContent(), TRUE);

    $this->assertSame(400, $response->getStatusCode());
    $this->assertSame('invalid_context', $body['error_code'] ?? NULL);
    $this->assertArrayHasKey('request_id', $body);
  }

  /**
   * Builds a controller with routing hooks for context assertions.
   */
  private function buildController(array &$observed): RequestContextSchemaTestableController {
    $observed = [
      'route_calls' => 0,
      'route_contexts' => [],
    ];

    $configStub = $this->createStub(ImmutableConfig::class);
    $configStub->method('get')->willReturnCallback(function (string $key) {
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

    $intentRouter = $this->createMock(IntentRouter::class);
    $intentRouter->method('route')->willReturnCallback(
      function (string $message, array $context) use (&$observed): array {
        $observed['route_calls']++;
        $observed['route_contexts'][] = $context;

        return [
          'type' => 'faq',
          'confidence' => 0.9,
        ];
      }
    );

    $faqIndex = $this->createStub(FaqIndex::class);
    $faqIndex->method('search')->willReturn([]);

    $resourceFinder = $this->createStub(ResourceFinder::class);

    $policyFilter = $this->createStub(PolicyFilter::class);
    $policyFilter->method('check')->willReturn([
      'passed' => TRUE,
      'violation' => FALSE,
    ]);

    $analyticsLogger = $this->createStub(AnalyticsLogger::class);

    $llmEnhancer = $this->createStub(LlmEnhancer::class);
    $fallbackGate = $this->createStub(FallbackGate::class);
    $fallbackGate->method('evaluate')->willReturn([
      'decision' => 'allow',
      'reason_code' => 'test',
      'confidence' => 1.0,
    ]);

    $flood = $this->createStub(FloodInterface::class);
    $flood->method('isAllowed')->willReturn(TRUE);

    $cache = $this->createStub(CacheBackendInterface::class);
    $cache->method('get')->willReturn(FALSE);

    $logger = $this->createStub(LoggerInterface::class);

    return new RequestContextSchemaTestableController(
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
      pre_routing_decision_engine: new PreRoutingDecisionEngine($policyFilter),
    );
  }

  /**
   * Builds a valid JSON message request.
   */
  private function buildJsonRequest(array $payload): Request {
    return Request::create(
      '/assistant/api/message',
      'POST',
      [],
      [],
      [],
      ['CONTENT_TYPE' => 'application/json'],
      json_encode($payload)
    );
  }

}

/**
 * Minimal test controller that captures normalized processIntent context.
 */
final class RequestContextSchemaTestableController extends AssistantApiController {

  /**
   * The last normalized context seen by processIntent().
   *
   * @var array<string, mixed>|null
   */
  public ?array $lastProcessIntentContext = NULL;

  /**
   * {@inheritdoc}
   */
  protected function processIntent(array $intent, string $message, array $context, string $request_id = '', array $server_history = []) {
    $this->lastProcessIntentContext = $context;

    return [
      'type' => 'faq',
      'message' => 'Test FAQ response',
      'response_mode' => 'answer',
      'primary_action' => [],
      'secondary_actions' => [],
      'reason_code' => 'test',
      'results' => [],
    ];
  }

  /**
   * {@inheritdoc}
   */
  protected function getEscalationActions() {
    return [
      ['label' => 'Call Hotline', 'url' => '/hotline', 'type' => 'hotline'],
      ['label' => 'Apply for Help', 'url' => '/apply', 'type' => 'apply'],
      ['label' => 'Give Feedback', 'url' => '/feedback', 'type' => 'feedback'],
    ];
  }

}
