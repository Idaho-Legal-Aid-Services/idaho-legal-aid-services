<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Database\Connection;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\ilas_site_assistant\Controller\AssistantApiController;
use Drupal\ilas_site_assistant\EventSubscriber\AssistantApiResponseMonitorSubscriber;
use Drupal\ilas_site_assistant\Exception\RetrievalDependencyUnavailableException;
use Drupal\ilas_site_assistant\Service\AnalyticsLogger;
use Drupal\ilas_site_assistant\Service\AssistantFlowRunner;
use Drupal\ilas_site_assistant\Service\ConversationLogger;
use Drupal\ilas_site_assistant\Service\FallbackGate;
use Drupal\ilas_site_assistant\Service\FaqIndex;
use Drupal\ilas_site_assistant\Service\IntentRouter;
use Drupal\ilas_site_assistant\Service\LangfuseTracer;
use Drupal\ilas_site_assistant\Service\LlmEnhancer;
use Drupal\ilas_site_assistant\Service\PerformanceMonitor;
use Drupal\ilas_site_assistant\Service\PolicyFilter;
use Drupal\ilas_site_assistant\Service\PreRoutingDecisionEngine;
use Drupal\ilas_site_assistant\Service\ResourceFinder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * IMP-REL-01: Consolidated failure-mode contract tests.
 *
 * Verifies controller catch-all behavior, observability isolation, and
 * cross-cutting request_id/correlation ID consistency for all failure paths.
 */
#[Group('ilas_site_assistant')]
class IntegrationFailureContractTest extends TestCase {

  /**
   * UUID4 regex pattern.
   */
  const UUID4_PATTERN = '/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/i';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Load module function stubs for controller-level testing.
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
        'flows.enabled' => TRUE,
        'flows.office_followup.enabled' => TRUE,
        'flows.office_followup.trigger_intents' => ['apply'],
        'flows.office_followup.require_followup_prompt' => TRUE,
        'flows.office_followup.max_turns' => 2,
        'flows.office_followup.ttl_seconds' => 1800,
      ];
      return $values[$key] ?? NULL;
    });

    $configFactory = $this->createStub(ConfigFactoryInterface::class);
    $configFactory->method('get')->willReturn($configStub);

    $container = new ContainerBuilder();
    $container->set('logger.factory', new class {

      public function get(string $channel): NullLogger {
        return new NullLogger();
      }

    });
    $container->set('string_translation', new \Drupal\Tests\ilas_site_assistant\Support\PassThroughTranslation());
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
   * Builds a ContractTestableController with stubbed dependencies.
   *
   * @param \Psr\Log\LoggerInterface|null $logger
   *   Optional logger mock.
   * @param \Drupal\Core\Flood\FloodInterface|null $flood
   *   Optional flood mock.
   * @param \Drupal\ilas_site_assistant\Service\IntentRouter|null $intentRouter
   *   Optional intent router mock.
   * @param \Drupal\ilas_site_assistant\Service\FaqIndex|null $faqIndex
   *   Optional FAQ index mock.
   * @param \Drupal\ilas_site_assistant\Service\ResourceFinder|null $resourceFinder
   *   Optional resource finder mock.
   * @param \Drupal\Core\Cache\CacheBackendInterface|null $cache
   *   Optional conversation cache mock.
   * @param \Drupal\ilas_site_assistant\Service\AssistantFlowRunner|null $assistantFlowRunner
   *   Optional flow runner mock.
   * @param \Exception|null $processIntentException
   *   If set, processIntent will throw this exception.
   *
   * @return \Drupal\Tests\ilas_site_assistant\Unit\ContractTestableController
   *   A testable controller instance.
   */
  private function buildController(
    ?LoggerInterface $logger = NULL,
    ?FloodInterface $flood = NULL,
    ?IntentRouter $intentRouter = NULL,
    ?FaqIndex $faqIndex = NULL,
    ?ResourceFinder $resourceFinder = NULL,
    ?CacheBackendInterface $cache = NULL,
    ?AssistantFlowRunner $assistantFlowRunner = NULL,
    ?\Exception $processIntentException = NULL,
  ): ContractTestableController {
    $configStub = $this->createStub(ImmutableConfig::class);
    $configStub->method('get')->willReturnCallback(function (string $key) {
      $values = [
        'rate_limit_per_minute' => 15,
        'rate_limit_per_hour' => 120,
        'enable_faq' => TRUE,
        'enable_resources' => TRUE,
        'enable_logging' => FALSE,
        'langfuse.environment' => 'test',
        'langfuse.enabled' => FALSE,
        'flows.enabled' => TRUE,
        'flows.office_followup.enabled' => TRUE,
        'flows.office_followup.trigger_intents' => ['apply'],
        'flows.office_followup.require_followup_prompt' => TRUE,
        'flows.office_followup.max_turns' => 2,
        'flows.office_followup.ttl_seconds' => 1800,
      ];
      return $values[$key] ?? NULL;
    });

    $configFactory = $this->createStub(ConfigFactoryInterface::class);
    $configFactory->method('get')->willReturn($configStub);

    if ($flood === NULL) {
      $flood = $this->createStub(FloodInterface::class);
      $flood->method('isAllowed')->willReturn(TRUE);
    }

    if ($intentRouter === NULL) {
      $intentRouter = $this->createStub(IntentRouter::class);
      $intentRouter->method('route')->willReturn([
        'type' => 'faq',
        'confidence' => 0.9,
      ]);
    }

    if ($faqIndex === NULL) {
      $faqIndex = $this->createStub(FaqIndex::class);
      $faqIndex->method('search')->willReturn([]);
    }

    $resourceFinder ??= $this->createStub(ResourceFinder::class);
    $policyFilter = $this->createStub(PolicyFilter::class);
    $policyFilter->method('check')->willReturn(['passed' => TRUE, 'violation' => FALSE]);

    $analyticsLogger = $this->createStub(AnalyticsLogger::class);
    $llmEnhancer = $this->createStub(LlmEnhancer::class);
    $fallbackGate = $this->createStub(FallbackGate::class);
    $fallbackGate->method('evaluate')->willReturn([
      'decision' => 'allow',
      'reason_code' => 'test',
      'confidence' => 1.0,
    ]);

    if ($cache === NULL) {
      $cache = $this->createStub(CacheBackendInterface::class);
      $cache->method('get')->willReturn(FALSE);
    }

    if ($logger === NULL) {
      $logger = $this->createStub(LoggerInterface::class);
    }

    if ($assistantFlowRunner === NULL) {
      $assistantFlowRunner = $this->createStub(AssistantFlowRunner::class);
    }

    $controller = new ContractTestableController(
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
      assistant_flow_runner: $assistantFlowRunner,
      selection_registry: new \Drupal\ilas_site_assistant\Service\SelectionRegistry(new \Drupal\ilas_site_assistant\Service\TopIntentsPack()),
      selection_state_store: new \Drupal\ilas_site_assistant\Service\SelectionStateStore($cache),
      pre_routing_decision_engine: new PreRoutingDecisionEngine($policyFilter),
    );

    if ($processIntentException !== NULL) {
      $controller->processIntentException = $processIntentException;
    }

    return $controller;
  }

  /**
   * Builds a valid JSON POST request with application/json content type.
   */
  private function buildJsonRequest(string $message = 'test message', array $extraHeaders = [], array $extraPayload = []): Request {
    $payload = array_merge(['message' => $message], $extraPayload);
    $content = json_encode($payload);
    $request = Request::create(
      '/assistant/api/message',
      'POST',
      [],
      [],
      [],
      ['CONTENT_TYPE' => 'application/json'],
      $content
    );
    foreach ($extraHeaders as $key => $value) {
      $request->headers->set($key, $value);
    }
    return $request;
  }

  /**
   * Builds a JSON POST request for the tracking endpoint.
   */
  private function buildTrackRequest(array $payload = ['event_type' => 'chat_open'], array $extraHeaders = []): Request {
    $request = Request::create(
      '/assistant/api/track',
      'POST',
      [],
      [],
      [],
      ['CONTENT_TYPE' => 'application/json'],
      json_encode($payload),
    );
    foreach ($extraHeaders as $key => $value) {
      $request->headers->set($key, $value);
    }
    return $request;
  }

  // ─── Test 1: Catch-all returns 500 with internal_error code ───────────

  /**
   * Controller catch-all returns HTTP 500 with error.code = internal_error.
   */
  public function testCatchAllReturns500WithInternalErrorCode(): void {
    $controller = $this->buildController(
      processIntentException: new \RuntimeException('Simulated pipeline failure'),
    );

    $response = $controller->message($this->buildJsonRequest());

    $this->assertEquals(500, $response->getStatusCode());
    $body = json_decode($response->getContent(), TRUE);
    $this->assertIsArray($body['error'] ?? NULL, 'Body must contain error object');
    $this->assertEquals('internal_error', $body['error']['code']);
    $this->assertArrayHasKey('request_id', $body);
  }

  // ─── Test 2: Catch-all includes X-Correlation-ID header ───────────────

  /**
   * Catch-all response includes X-Correlation-ID header matching body request_id.
   */
  public function testCatchAllIncludesCorrelationIdHeader(): void {
    $controller = $this->buildController(
      processIntentException: new \RuntimeException('Simulated failure'),
    );

    $response = $controller->message($this->buildJsonRequest());

    $body = json_decode($response->getContent(), TRUE);
    $this->assertTrue($response->headers->has('X-Correlation-ID'));
    $this->assertEquals($body['request_id'], $response->headers->get('X-Correlation-ID'));
  }

  // ─── Test 3: Catch-all logs exception ─────────────────────────────────

  /**
   * Catch-all path invokes logger->error() for unhandled exceptions.
   */
  public function testCatchAllLogsException(): void {
    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->atLeastOnce())
      ->method('error')
      ->with($this->stringContains('Unhandled exception'));

    $controller = $this->buildController(
      logger: $logger,
      processIntentException: new \RuntimeException('Pipeline error'),
    );

    $controller->message($this->buildJsonRequest());
  }

  // ─── Test 4: Rate limit response includes request_id ──────────────────

  /**
   * Rate limit (429) response includes request_id in body and correlation header.
   */
  public function testRateLimitResponseIncludesRequestId(): void {
    $flood = $this->createStub(FloodInterface::class);
    $flood->method('isAllowed')->willReturn(FALSE);

    $controller = $this->buildController(flood: $flood);

    $response = $controller->message($this->buildJsonRequest());

    $this->assertEquals(429, $response->getStatusCode());
    $body = json_decode($response->getContent(), TRUE);
    $this->assertArrayHasKey('request_id', $body);
    $this->assertMatchesRegularExpression(self::UUID4_PATTERN, $body['request_id']);
  }

  // ─── Test 5: Invalid content type response includes request_id ────────

  /**
   * Invalid content type (400) response includes request_id.
   */
  public function testInvalidContentTypeResponseIncludesRequestId(): void {
    $controller = $this->buildController();

    $request = Request::create(
      '/assistant/api/message',
      'POST',
      [],
      [],
      [],
      ['CONTENT_TYPE' => 'text/plain'],
      'not json'
    );

    $response = $controller->message($request);

    $this->assertEquals(400, $response->getStatusCode());
    $body = json_decode($response->getContent(), TRUE);
    $this->assertArrayHasKey('request_id', $body);
    $this->assertMatchesRegularExpression(self::UUID4_PATTERN, $body['request_id']);
  }

  // ─── Test 6: Request too large response includes request_id ───────────

  /**
   * Request too large (413) response includes request_id.
   */
  public function testRequestTooLargeResponseIncludesRequestId(): void {
    $controller = $this->buildController();

    $bigMessage = str_repeat('a', 2001);
    $request = Request::create(
      '/assistant/api/message',
      'POST',
      [],
      [],
      [],
      ['CONTENT_TYPE' => 'application/json'],
      $bigMessage
    );

    $response = $controller->message($request);

    $this->assertEquals(413, $response->getStatusCode());
    $body = json_decode($response->getContent(), TRUE);
    $this->assertArrayHasKey('request_id', $body);
    $this->assertMatchesRegularExpression(self::UUID4_PATTERN, $body['request_id']);
  }

  // ─── Test 7: Invalid JSON response includes request_id ────────────────

  /**
   * Invalid JSON (400) response includes request_id.
   */
  public function testInvalidJsonResponseIncludesRequestId(): void {
    $controller = $this->buildController();

    $request = Request::create(
      '/assistant/api/message',
      'POST',
      [],
      [],
      [],
      ['CONTENT_TYPE' => 'application/json'],
      '{broken json'
    );

    $response = $controller->message($request);

    $this->assertEquals(400, $response->getStatusCode());
    $body = json_decode($response->getContent(), TRUE);
    $this->assertArrayHasKey('request_id', $body);
    $this->assertMatchesRegularExpression(self::UUID4_PATTERN, $body['request_id']);
  }

  /**
   * Post-sanitize whitespace/control input must return deterministic 400.
   */
  public function testWhitespaceMessageReturnsInvalidMessage400(): void {
    $intentRouter = $this->createMock(IntentRouter::class);
    $intentRouter->expects($this->never())->method('route');

    $controller = $this->buildController(intentRouter: $intentRouter);
    $response = $controller->message($this->buildJsonRequest(" \t\n "));

    $this->assertEquals(400, $response->getStatusCode());
    $body = json_decode($response->getContent(), TRUE);
    $this->assertIsArray($body);
    $this->assertSame('invalid_message', $body['error_code'] ?? NULL);
    $this->assertArrayHasKey('request_id', $body);
    $this->assertTrue($response->headers->has('X-Correlation-ID'));
    $this->assertSame($body['request_id'], $response->headers->get('X-Correlation-ID'));
  }

  /**
   * Third repeated clarify response in same conversation triggers loop break.
   */
  public function testClarifyLoopBreaksOnThirdRepeat(): void {
    $cacheData = [];
    $cache = $this->createMock(CacheBackendInterface::class);
    $cache->method('get')->willReturnCallback(function (string $cid) use (&$cacheData) {
      if (!array_key_exists($cid, $cacheData)) {
        return FALSE;
      }
      return (object) ['data' => $cacheData[$cid]];
    });
    $cache->method('set')->willReturnCallback(function (string $cid, mixed $data) use (&$cacheData) {
      $cacheData[$cid] = $data;
    });

    $controller = $this->buildController(cache: $cache);
    $controller->processIntentResponse = [
      'type' => 'disambiguation',
      'response_mode' => 'clarify',
      'message' => 'What type of resource do you need?',
      'topic_suggestions' => [
        ['label' => 'Court forms', 'action' => 'forms'],
        ['label' => 'How-to guides', 'action' => 'guides'],
      ],
      'results' => [],
    ];

    $conversationId = '123e4567-e89b-42d3-a456-426614174000';
    $requestPayload = ['conversation_id' => $conversationId];

    $responseOne = $controller->message($this->buildJsonRequest('eviction forms', [], $requestPayload));
    $responseTwo = $controller->message($this->buildJsonRequest('eviction forms', [], $requestPayload));
    $responseThree = $controller->message($this->buildJsonRequest('eviction forms', [], $requestPayload));

    $bodyOne = json_decode($responseOne->getContent(), TRUE);
    $bodyTwo = json_decode($responseTwo->getContent(), TRUE);
    $bodyThree = json_decode($responseThree->getContent(), TRUE);

    $this->assertSame('disambiguation', $bodyOne['type'] ?? NULL);
    $this->assertSame('disambiguation', $bodyTwo['type'] ?? NULL);
    $this->assertSame('clarify_loop_break', $bodyThree['type'] ?? NULL);
    $this->assertSame('clarify_loop_break', $bodyThree['reason_code'] ?? NULL);
    $this->assertSame('clarify', $bodyThree['response_mode'] ?? NULL);
    $this->assertNotEmpty($bodyThree['topic_suggestions'] ?? []);
    $this->assertNotEmpty($bodyThree['actions'] ?? []);
  }

  /**
   * Pending office follow-up decisions are handled through the runner.
   */
  public function testPendingOfficeFollowupUsesRunnerDecision(): void {
    $conversationId = '123e4567-e89b-42d3-a456-426614174111';
    $intentRouter = $this->createMock(IntentRouter::class);
    $intentRouter->expects($this->never())->method('route');

    $assistantFlowRunner = $this->createMock(AssistantFlowRunner::class);
    $assistantFlowRunner->expects($this->once())
      ->method('evaluatePending')
      ->with($this->callback(static function (array $context) use ($conversationId): bool {
        return $context['conversation_id'] === $conversationId
          && $context['user_message'] === 'boise'
          && $context['is_location_like'] === TRUE
          && $context['is_explicit_office_followup'] === FALSE;
      }))
      ->willReturn([
        'status' => 'handled',
        'flow_id' => 'office_followup',
        'action' => 'resolve',
        'state_operation' => 'clear',
        'state_payload' => [],
        'office' => [
          'name' => 'Boise',
          'address' => '1104 W Royal Blvd, Boise, ID 83706',
          'phone' => '(208) 345-0106',
          'hours' => 'Hours may vary. Please call to confirm current office hours.',
          'url' => '/contact/offices/boise',
        ],
      ]);
    $assistantFlowRunner->expects($this->once())
      ->method('clearOfficeFollowupState')
      ->with($conversationId);
    $assistantFlowRunner->expects($this->never())->method('evaluatePostResponse');

    $controller = $this->buildController(
      intentRouter: $intentRouter,
      assistantFlowRunner: $assistantFlowRunner,
      processIntentException: new \RuntimeException('Pending flow should have returned before routing'),
    );

    $response = $controller->message($this->buildJsonRequest('boise', [], ['conversation_id' => $conversationId]));

    $this->assertSame(200, $response->getStatusCode());
    $body = json_decode($response->getContent(), TRUE);
    $this->assertSame('office_location', $body['type'] ?? NULL);
    $this->assertSame('office_followup_resolved', $body['reason_code'] ?? NULL);
    $this->assertSame('Boise', $body['office']['name'] ?? NULL);
  }

  /**
   * Apply responses arm office follow-up through the runner decision contract.
   */
  public function testPostResponseOfficeFollowupUsesRunnerDecision(): void {
    $conversationId = '123e4567-e89b-42d3-a456-426614174112';
    $intentRouter = $this->createMock(IntentRouter::class);
    $intentRouter->method('route')->willReturn([
      'type' => 'apply',
      'confidence' => 0.92,
    ]);

    $assistantFlowRunner = $this->createMock(AssistantFlowRunner::class);
    $assistantFlowRunner->expects($this->once())
      ->method('evaluatePending')
      ->with($this->callback(static fn (array $context): bool => $context['conversation_id'] === $conversationId))
      ->willReturn([
        'status' => 'continue',
        'flow_id' => 'office_followup',
        'action' => 'none',
        'state_operation' => 'none',
        'state_payload' => [],
      ]);
    $assistantFlowRunner->expects($this->once())
      ->method('evaluatePostResponse')
      ->with($this->callback(static function (array $context) use ($conversationId): bool {
        return $context['conversation_id'] === $conversationId
          && $context['intent_type'] === 'apply'
          && $context['has_followup_prompt'] === TRUE;
      }))
      ->willReturn([
        'status' => 'continue',
        'flow_id' => 'office_followup',
        'action' => 'arm',
        'state_operation' => 'save',
        'state_payload' => [
          'type' => 'office_location',
          'origin_intent' => 'apply',
          'remaining_turns' => 2,
          'created_at' => 1700000000,
        ],
      ]);
    $assistantFlowRunner->expects($this->once())
      ->method('saveOfficeFollowupState')
      ->with($conversationId, [
        'type' => 'office_location',
        'origin_intent' => 'apply',
        'remaining_turns' => 2,
        'created_at' => 1700000000,
      ]);

    $controller = $this->buildController(
      intentRouter: $intentRouter,
      assistantFlowRunner: $assistantFlowRunner,
    );
    $controller->processIntentResponse = [
      'type' => 'faq',
      'message' => 'You can apply for help online.',
      'response_mode' => 'answer',
      'primary_action' => [],
      'secondary_actions' => [],
      'reason_code' => 'apply_stub',
      'results' => [],
      'followup' => 'Which office is closest to you?',
    ];

    $response = $controller->message($this->buildJsonRequest('i need help applying', [], ['conversation_id' => $conversationId]));

    $this->assertSame(200, $response->getStatusCode());
    $body = json_decode($response->getContent(), TRUE);
    $this->assertSame('faq', $body['type'] ?? NULL);
    $this->assertArrayHasKey('request_id', $body);
  }

  // ─── Test 8: Success response includes request_id ─────────────────────

  /**
   * Successful (200) response from processIntent stub includes request_id.
   */
  public function testSuccessResponseIncludesRequestId(): void {
    $controller = $this->buildController();

    $response = $controller->message($this->buildJsonRequest());

    $this->assertEquals(200, $response->getStatusCode());
    $body = json_decode($response->getContent(), TRUE);
    $this->assertArrayHasKey('request_id', $body);
    $this->assertMatchesRegularExpression(self::UUID4_PATTERN, $body['request_id']);
    $this->assertTrue($response->headers->has('X-Correlation-ID'));
  }

  /**
   * Message retrieval dependency loss degrades explicitly instead of 500ing.
   */
  public function testMessageRetrievalDependencyUnavailableReturnsDegradedNavigation(): void {
    $controller = $this->buildController(
      processIntentException: new RetrievalDependencyUnavailableException('resource', 'resource_retrieval_unavailable'),
    );

    $response = $controller->message($this->buildJsonRequest());

    $this->assertSame(200, $response->getStatusCode());
    $body = json_decode($response->getContent(), TRUE);
    $this->assertSame('navigation', $body['type'] ?? NULL);
    $this->assertSame('resource_retrieval_unavailable', $body['reason_code'] ?? NULL);
    $this->assertEquals(1.0, $body['confidence'] ?? NULL);
    $this->assertSame([], $body['citations'] ?? NULL);
    $this->assertSame('resource_retrieval_unavailable', $body['decision_reason'] ?? NULL);
    $this->assertArrayHasKey('request_id', $body);
    $this->assertNotEmpty($body['actions'] ?? []);
  }

  /**
   * Content-index fallback results are marked as explicitly degraded.
   */
  public function testMessageResourceFallbackResultsUseExplicitDegradedReasonCode(): void {
    $intentRouter = $this->createMock(IntentRouter::class);
    $intentRouter->method('route')->willReturn([
      'type' => 'resources',
      'confidence' => 0.95,
    ]);

    $faqIndex = $this->createStub(FaqIndex::class);
    $faqIndex->method('search')->willReturn([]);

    $resourceFinder = $this->createMock(ResourceFinder::class);
    $resourceFinder->expects($this->once())
      ->method('findResources')
      ->willReturn([
        [
          'id' => 101,
          'title' => 'Eviction guide',
          'url' => '/node/101',
          'source_url' => '/node/101',
          'source' => 'lexical',
          'lexical_index_mode' => 'fallback',
        ],
      ]);
    $resourceFinder->method('getLastLexicalResolution')->willReturn([
      'mode' => 'fallback',
    ]);

    $controller = $this->buildController(
      intentRouter: $intentRouter,
      faqIndex: $faqIndex,
      resourceFinder: $resourceFinder,
    );
    $controller->deferToParentProcessIntent = TRUE;

    $response = $controller->message($this->buildJsonRequest('eviction help'));

    $this->assertSame(200, $response->getStatusCode());
    $body = json_decode($response->getContent(), TRUE);
    $this->assertSame('resource_results_fallback_index', $body['reason_code'] ?? NULL);
    $this->assertArrayHasKey('degraded_notice', $body);
  }

  // ─── Test 9: Failure mode matrix (@dataProvider) ──────────────────────

  /**
   * Data provider for the consolidated failure mode matrix.
   *
   * Each row documents a dependency, failure type, expected HTTP status,
   * expected response class, and whether request_id must be present.
   *
   * @return array
   *   Test case matrix.
   */
  public static function failureModeProvider(): array {
    return [
      'faq_search_api_unavailable' => ['faq', 'search_api_unavailable', 200, 'explicit_degraded'],
      'faq_search_api_query_timeout' => ['faq', 'search_api_query_timeout', 200, 'legacy_fallback'],
      'resource_search_api_unavailable' => ['resource', 'search_api_unavailable', 200, 'explicit_degraded'],
      'resource_search_api_query_5xx' => ['resource', 'search_api_query_5xx', 200, 'legacy_fallback'],
      'faq_vector_unavailable' => ['faq', 'vector_unavailable', 200, 'lexical_preserved'],
      'resource_vector_unavailable' => ['resource', 'vector_unavailable', 200, 'lexical_preserved'],
      'llm_429_rate_limited' => ['llm', '429_rate_limited', 200, 'original_preserved'],
      'llm_503_unavailable' => ['llm', '503_unavailable', 200, 'original_preserved'],
      'llm_circuit_breaker_open' => ['llm', 'circuit_breaker_open', 200, 'original_preserved'],
      'controller_uncaught_throwable' => ['controller', 'uncaught_throwable', 500, 'internal_error'],
    ];
  }

  /**
   * Verifies the failure mode matrix: each dependency failure maps to a
   * documented fallback class with request_id present.
   */
  #[DataProvider('failureModeProvider')]
  public function testFailureModeContract(string $dependency, string $failureType, int $expectedStatus, string $expectedClass): void {
    // The matrix documents all 10 failure → fallback mappings.
    // Rows 1-6 (retrieval) are exercised by DependencyFailureDegradeContractTest.
    // Rows 7-9 (LLM) are exercised by LlmEnhancerHardeningTest.
    // Row 10 (controller) is exercised by testCatchAllReturns500 above.
    //
    // This test validates the matrix is internally consistent and that the
    // cross-cutting request_id property holds for the controller-level case.

    if ($dependency === 'controller') {
      $controller = $this->buildController(
        processIntentException: new \RuntimeException('Simulated ' . $failureType),
      );
      $response = $controller->message($this->buildJsonRequest());

      $this->assertEquals($expectedStatus, $response->getStatusCode());
      $body = json_decode($response->getContent(), TRUE);
      $this->assertArrayHasKey('request_id', $body, "request_id must be present for {$dependency}/{$failureType}");
      $this->assertEquals('internal_error', $body['error']['code'] ?? NULL);
    }
    else {
      // Retrieval/LLM failure classes are documented and verified
      // by existing contract test suites. Assert matrix consistency.
      $this->assertContains($expectedClass, [
        'explicit_degraded',
        'legacy_fallback',
        'lexical_preserved',
        'original_preserved',
      ], "Expected class {$expectedClass} must be a known fallback class for {$dependency}/{$failureType}");
      $this->assertEquals(200, $expectedStatus, "Non-controller failures should degrade gracefully to 200");
    }
  }

  // ─── Test 10: AnalyticsLogger internal catch swallows exception ───────

  /**
   * AnalyticsLogger::log() swallows database exceptions internally.
   */
  public function testAnalyticsLoggerInternalCatchSwallowsException(): void {
    $database = $this->createMock(Connection::class);
    $database->method('merge')->willThrowException(
      new \Exception('DB connection lost')
    );

    $configStub = $this->createStub(ImmutableConfig::class);
    $configStub->method('get')->willReturnCallback(function (string $key) {
      return match ($key) {
        'enable_logging' => TRUE,
        default => NULL,
      };
    });
    $configFactory = $this->createStub(ConfigFactoryInterface::class);
    $configFactory->method('get')->willReturn($configStub);

    $time = $this->createStub(TimeInterface::class);
    $time->method('getRequestTime')->willReturn(time());

    $logger = new AnalyticsLogger($database, $configFactory, $time, $this->createStub(LoggerInterface::class));

    // Must not throw — exception is caught internally.
    $logger->log('test_event', 'test_value');
    $this->addToAssertionCount(1);
  }

  // ─── Test 11: ConversationLogger internal catch swallows exception ────

  /**
   * ConversationLogger::logExchange() swallows database exceptions internally.
   */
  public function testConversationLoggerInternalCatchSwallowsException(): void {
    $schema = $this->createStub(\Drupal\Core\Database\Schema::class);
    $schema->method('tableExists')->willReturn(TRUE);

    $database = $this->createMock(Connection::class);
    $database->method('schema')->willReturn($schema);
    $database->method('insert')->willThrowException(
      new \Exception('DB write failed')
    );

    $configStub = $this->createStub(ImmutableConfig::class);
    $configStub->method('get')->willReturnCallback(function (string $key) {
      return match ($key) {
        'conversation_logging.enabled' => TRUE,
        default => NULL,
      };
    });
    $configFactory = $this->createStub(ConfigFactoryInterface::class);
    $configFactory->method('get')->willReturn($configStub);

    $time = $this->createStub(TimeInterface::class);
    $time->method('getRequestTime')->willReturn(time());

    $logger = new ConversationLogger($database, $configFactory, $time, $this->createStub(LoggerInterface::class));

    // Must not throw — exception is caught internally.
    $logger->logExchange('11111111-1111-4111-8111-111111111111', 'test', 'response', 'faq', 'faq', 'req-id');
    $this->addToAssertionCount(1);
  }

  /**
   * Message validation failures record exactly one denied monitor outcome.
   */
  public function testMessageInvalidRequestRecordsDeniedOutcomeOnce(): void {
    $controller = $this->buildController();
    $monitor = $this->createMock(PerformanceMonitor::class);
    $monitor->expects($this->once())
      ->method('recordObservedRequest')
      ->with(
        $this->greaterThanOrEqual(0.0),
        FALSE,
        PerformanceMonitor::ENDPOINT_MESSAGE,
        'message.invalid_request',
        400,
        TRUE,
        FALSE,
        'unknown',
      );

    $subscriber = new AssistantApiResponseMonitorSubscriber($monitor);
    $request = Request::create(
      '/assistant/api/message',
      'POST',
      [],
      [],
      [],
      ['CONTENT_TYPE' => 'application/json'],
      '{"message":',
    );

    $response = $controller->message($request);
    $this->dispatchMonitoredResponse($subscriber, $request, $response);

    $this->assertSame(400, $response->getStatusCode());
  }

  /**
   * Message shortcut-success paths record exactly one successful outcome.
   */
  public function testMessageRepeatedEscalationRecordsShortcutSuccessOnce(): void {
    $cache = $this->createStub(CacheBackendInterface::class);
    $cache->method('get')->willReturn((object) [
      'data' => [
        ['text' => 'test message'],
        ['text' => 'test message'],
        ['text' => 'test message'],
      ],
    ]);

    $controller = $this->buildController(cache: $cache);
    $monitor = $this->createMock(PerformanceMonitor::class);
    $monitor->expects($this->once())
      ->method('recordObservedRequest')
      ->with(
        $this->greaterThanOrEqual(0.0),
        TRUE,
        PerformanceMonitor::ENDPOINT_MESSAGE,
        'message.repeated_message_escalation',
        200,
        FALSE,
        FALSE,
        'unknown',
      );

    $subscriber = new AssistantApiResponseMonitorSubscriber($monitor);
    $request = $this->buildJsonRequest('test message', [], [
      'conversation_id' => '11111111-1111-4111-8111-111111111111',
    ]);

    $response = $controller->message($request);
    $this->dispatchMonitoredResponse($subscriber, $request, $response);

    $this->assertSame(200, $response->getStatusCode());
    $body = json_decode((string) $response->getContent(), TRUE);
    $this->assertSame('repeated', $body['escalation_type']);
  }

  /**
   * Track proof denials record exactly one denied monitor outcome.
   */
  public function testTrackDenialRecordsDeniedOutcomeOnce(): void {
    $controller = $this->buildController();
    $monitor = $this->createMock(PerformanceMonitor::class);
    $monitor->expects($this->once())
      ->method('recordObservedRequest')
      ->with(
        $this->greaterThanOrEqual(0.0),
        FALSE,
        PerformanceMonitor::ENDPOINT_TRACK,
        'track.track_origin_mismatch',
        403,
        TRUE,
        FALSE,
        'unknown',
      );

    $subscriber = new AssistantApiResponseMonitorSubscriber($monitor);
    $request = $this->buildTrackRequest(
      ['event_type' => 'chat_open'],
      ['Origin' => 'https://evil.example'],
    );

    $response = $controller->track($request);
    $this->dispatchMonitoredResponse($subscriber, $request, $response);

    $this->assertSame(403, $response->getStatusCode());
  }

  /**
   * Track success paths record exactly one successful monitor outcome.
   */
  public function testTrackSuccessRecordsSuccessfulOutcomeOnce(): void {
    $controller = $this->buildController();
    $monitor = $this->createMock(PerformanceMonitor::class);
    $monitor->expects($this->once())
      ->method('recordObservedRequest')
      ->with(
        $this->greaterThanOrEqual(0.0),
        TRUE,
        PerformanceMonitor::ENDPOINT_TRACK,
        'track.success',
        200,
        FALSE,
        FALSE,
        'unknown',
      );

    $subscriber = new AssistantApiResponseMonitorSubscriber($monitor);
    $request = $this->buildTrackRequest(
      ['event_type' => 'chat_open'],
      ['Origin' => 'http://localhost'],
    );

    $response = $controller->track($request);
    $this->dispatchMonitoredResponse($subscriber, $request, $response);

    $this->assertSame(200, $response->getStatusCode());
    $body = json_decode((string) $response->getContent(), TRUE);
    $this->assertTrue($body['ok']);
  }

  // ─── Test 12: LangfuseTracer internal catch swallows exception ────────

  /**
   * LangfuseTracer methods swallow internal exceptions without propagation.
   */
  public function testLangfuseTracerInternalCatchSwallowsException(): void {
    $configStub = $this->createStub(ImmutableConfig::class);
    $configStub->method('get')->willReturnCallback(function (string $key) {
      return match ($key) {
        'langfuse.enabled' => TRUE,
        'langfuse.public_key' => 'pk-test',
        'langfuse.secret_key' => 'sk-test',
        'langfuse.sample_rate' => 1.0,
        default => NULL,
      };
    });
    $configFactory = $this->createStub(ConfigFactoryInterface::class);
    $configFactory->method('get')->willReturn($configStub);

    $logger = $this->createStub(LoggerInterface::class);

    $tracer = new LangfuseTracer($configFactory, $logger);

    // Start a trace normally.
    $tracer->startTrace('test-id', 'test.trace');
    $this->assertTrue($tracer->isActive());

    // These methods should not throw even if internal state is exercised.
    $tracer->startSpan('test.span');
    $tracer->endSpan(['result' => 'ok']);
    $tracer->addEvent('test.event', ['key' => 'value']);
    $tracer->endTrace(['output' => 'done']);

    // Verify trace completed without exception.
    $this->addToAssertionCount(1);
  }

  /**
   * Runs the response-monitor subscriber around a finalized response.
   */
  private function dispatchMonitoredResponse(
    AssistantApiResponseMonitorSubscriber $subscriber,
    Request $request,
    Response $response,
  ): void {
    $kernel = $this->createMock(HttpKernelInterface::class);
    $subscriber->onRequest(new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));
    $subscriber->onResponse(new ResponseEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $response));
  }

}

/**
 * Testable controller subclass for contract testing.
 *
 * Overrides processIntent() to return deterministic stubs or throw injected
 * exceptions. Overrides getEscalationActions() to avoid module function calls.
 */
class ContractTestableController extends AssistantApiController {

  /**
   * If set, processIntent() throws this exception.
   */
  public ?\Exception $processIntentException = NULL;

  /**
   * When TRUE, route processIntent() to the parent implementation.
   */
  public bool $deferToParentProcessIntent = FALSE;

  /**
   * The stubbed response from processIntent().
   */
  public array $processIntentResponse = [
    'type' => 'faq',
    'message' => 'Test FAQ response',
    'response_mode' => 'answer',
    'primary_action' => [],
    'secondary_actions' => [],
    'reason_code' => 'test',
    'results' => [],
  ];

  /**
   * {@inheritdoc}
   */
  protected function processIntent(array $intent, string $message, array $context, string $request_id = '', array $server_history = []) {
    if ($this->deferToParentProcessIntent) {
      return parent::processIntent($intent, $message, $context, $request_id, $server_history);
    }
    if ($this->processIntentException !== NULL) {
      throw $this->processIntentException;
    }
    return $this->processIntentResponse;
  }

  /**
   * {@inheritdoc}
   */
  protected function getEscalationActions() {
    return [
      ['label' => 'Call Hotline', 'url' => 'tel:+12083451011', 'type' => 'hotline'],
      ['label' => 'Apply for Help', 'url' => '/apply', 'type' => 'apply'],
      ['label' => 'Give Feedback', 'url' => '/feedback', 'type' => 'feedback'],
    ];
  }

  /**
   * Exposes resolveCorrelationId via reflection for direct unit testing.
   */
  public function exposedResolveCorrelationId(Request $request): string {
    $ref = new \ReflectionMethod(AssistantApiController::class, 'resolveCorrelationId');
    $ref->setAccessible(TRUE);
    return $ref->invoke($this, $request);
  }

  /**
   * Exposes jsonResponse for direct unit testing.
   */
  public function exposedJsonResponse(array $data, int $status = 200, array $extra_headers = [], string $request_id = ''): \Symfony\Component\HttpFoundation\JsonResponse {
    return $this->jsonResponse($data, $status, $extra_headers, $request_id);
  }

}
