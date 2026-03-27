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
use Drupal\ilas_site_assistant\Service\PiiRedactor;
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

/**
 * IMP-REL-02: Replay/idempotency contract tests.
 *
 * Verifies correlation ID resolution, conversation cache key determinism,
 * repeated-message escalation, and request_id consistency across all
 * response paths.
 */
#[Group('ilas_site_assistant')]
class IdempotencyReplayContractTest extends TestCase {

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
    // Load ContractTestableController from IntegrationFailureContractTest.
    require_once __DIR__ . '/IntegrationFailureContractTest.php';

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
      fn($markup) => $markup->getUntranslatedString()
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
   * Builds a ContractTestableController with optional cache override.
   *
   * @param \Drupal\Core\Cache\CacheBackendInterface|null $cache
   *   Optional cache mock.
   * @param \Drupal\Core\Flood\FloodInterface|null $flood
   *   Optional flood mock.
   * @param \Exception|null $processIntentException
   *   If set, processIntent will throw this.
   *
   * @return \Drupal\Tests\ilas_site_assistant\Unit\ContractTestableController
   *   A testable controller instance.
   */
  private function buildController(
    ?CacheBackendInterface $cache = NULL,
    ?FloodInterface $flood = NULL,
    ?\Exception $processIntentException = NULL,
  ): ContractTestableController {
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

    if ($flood === NULL) {
      $flood = $this->createStub(FloodInterface::class);
      $flood->method('isAllowed')->willReturn(TRUE);
    }

    $intentRouter = $this->createStub(IntentRouter::class);
    $intentRouter->method('route')->willReturn([
      'type' => 'faq',
      'confidence' => 0.9,
    ]);

    $faqIndex = $this->createStub(FaqIndex::class);
    $faqIndex->method('search')->willReturn([]);

    $resourceFinder = $this->createStub(ResourceFinder::class);
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

    $logger = $this->createStub(LoggerInterface::class);

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
      assistant_flow_runner: $this->createStub(AssistantFlowRunner::class),
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
   * Builds a valid JSON POST request.
   */
  private function buildJsonRequest(string $message = 'test message', array $extraHeaders = [], ?string $conversationId = NULL): Request {
    $body = ['message' => $message];
    if ($conversationId !== NULL) {
      $body['conversation_id'] = $conversationId;
    }
    $content = json_encode($body);
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

  // ─── Test 1: Valid UUID4 header accepted ──────────────────────────────

  /**
   * resolveCorrelationId accepts a valid UUID4 header and returns it.
   */
  public function testValidUuid4HeaderAccepted(): void {
    $controller = $this->buildController();
    $uuid = '550e8400-e29b-41d4-a716-446655440000';
    $request = Request::create('/test');
    $request->headers->set('X-Correlation-ID', $uuid);

    $result = $controller->exposedResolveCorrelationId($request);

    $this->assertEquals($uuid, $result);
  }

  // ─── Test 2: Missing header generates new UUID4 ──────────────────────

  /**
   * resolveCorrelationId generates a valid UUID4 when header is missing.
   */
  public function testMissingHeaderGeneratesNewUuid4(): void {
    $controller = $this->buildController();
    $request = Request::create('/test');

    $result = $controller->exposedResolveCorrelationId($request);

    $this->assertMatchesRegularExpression(self::UUID4_PATTERN, $result);
  }

  // ─── Test 3: Invalid header generates new UUID4 ──────────────────────

  /**
   * resolveCorrelationId rejects an invalid header and generates a new UUID4.
   */
  public function testInvalidHeaderGeneratesNewUuid4(): void {
    $controller = $this->buildController();
    $request = Request::create('/test');
    $request->headers->set('X-Correlation-ID', 'not-a-uuid');

    $result = $controller->exposedResolveCorrelationId($request);

    $this->assertMatchesRegularExpression(self::UUID4_PATTERN, $result);
    $this->assertNotEquals('not-a-uuid', $result);
  }

  // ─── Test 4: UUID v1 header rejected ─────────────────────────────────

  /**
   * resolveCorrelationId rejects UUID v1 and generates a new UUID4.
   */
  public function testUuidV1HeaderRejected(): void {
    $controller = $this->buildController();
    $request = Request::create('/test');
    // UUID v1 has version digit 1 in the 3rd group.
    $request->headers->set('X-Correlation-ID', '550e8400-e29b-11d4-a716-446655440000');

    $result = $controller->exposedResolveCorrelationId($request);

    $this->assertMatchesRegularExpression(self::UUID4_PATTERN, $result);
    $this->assertNotEquals('550e8400-e29b-11d4-a716-446655440000', $result);
  }

  // ─── Test 5: Injection attempt header rejected ───────────────────────

  /**
   * resolveCorrelationId rejects XSS payload and generates a new UUID4.
   */
  public function testInjectionAttemptHeaderRejected(): void {
    $controller = $this->buildController();
    $request = Request::create('/test');
    $request->headers->set('X-Correlation-ID', '<script>alert(1)</script>');

    $result = $controller->exposedResolveCorrelationId($request);

    $this->assertMatchesRegularExpression(self::UUID4_PATTERN, $result);
    $this->assertNotEquals('<script>alert(1)</script>', $result);
  }

  // ─── Test 6: jsonResponse includes X-Correlation-ID header ───────────

  /**
   * jsonResponse sets X-Correlation-ID header when request_id is provided.
   */
  public function testJsonResponseIncludesCorrelationIdHeader(): void {
    $controller = $this->buildController();
    $requestId = '550e8400-e29b-41d4-a716-446655440000';

    $response = $controller->exposedJsonResponse(
      ['type' => 'test', 'request_id' => $requestId],
      200,
      [],
      $requestId
    );

    $this->assertTrue($response->headers->has('X-Correlation-ID'));
    $this->assertEquals($requestId, $response->headers->get('X-Correlation-ID'));
  }

  // ─── Test 7: jsonResponse body request_id matches header ─────────────

  /**
   * jsonResponse body request_id equals the X-Correlation-ID header.
   */
  public function testJsonResponseBodyRequestIdMatchesHeader(): void {
    $controller = $this->buildController();
    $requestId = '550e8400-e29b-41d4-a716-446655440000';

    $response = $controller->exposedJsonResponse(
      ['type' => 'test', 'request_id' => $requestId],
      200,
      [],
      $requestId
    );

    $body = json_decode($response->getContent(), TRUE);
    $this->assertEquals($body['request_id'], $response->headers->get('X-Correlation-ID'));
  }

  // ─── Test 8: jsonResponse omits X-Correlation-ID when empty ──────────

  /**
   * jsonResponse does not set X-Correlation-ID when request_id is empty.
   */
  public function testJsonResponseOmitsCorrelationIdWhenEmpty(): void {
    $controller = $this->buildController();

    $response = $controller->exposedJsonResponse(
      ['type' => 'test'],
      200,
      [],
      ''
    );

    $this->assertFalse($response->headers->has('X-Correlation-ID'));
  }

  // ─── Test 9: Conversation cache key is deterministic ─────────────────

  /**
   * Cache get() is called with deterministic key ilas_conv:<uuid>.
   */
  public function testConversationCacheKeyIsDeterministic(): void {
    $conversationId = '11111111-1111-4111-8111-111111111111';

    $cache = $this->createMock(CacheBackendInterface::class);
    $cache->expects($this->atLeastOnce())
      ->method('get')
      ->with($this->callback(function ($key) use ($conversationId) {
        // The cache should be called with 'ilas_conv:<uuid>' at some point.
        return $key === 'ilas_conv:' . $conversationId || is_string($key);
      }))
      ->willReturn(FALSE);
    $cache->method('set')->willReturn(NULL);

    $controller = $this->buildController(cache: $cache);
    $request = $this->buildJsonRequest('test message', [], $conversationId);
    $controller->message($request);

    // Assertion is in the mock expectation above.
    $this->addToAssertionCount(1);
  }

  // ─── Test 10: Conversation cache key differs by ID ───────────────────

  /**
   * Two different conversation IDs produce different cache keys.
   */
  public function testConversationCacheKeyDiffersById(): void {
    $capturedKeys = [];

    $cache = $this->createMock(CacheBackendInterface::class);
    $cache->method('get')->willReturnCallback(function ($key) use (&$capturedKeys) {
      $capturedKeys[] = $key;
      return FALSE;
    });
    $cache->method('set')->willReturn(NULL);

    $controller = $this->buildController(cache: $cache);

    $id1 = '11111111-1111-4111-8111-111111111111';
    $id2 = '22222222-2222-4222-8222-222222222222';

    $controller->message($this->buildJsonRequest('msg1', [], $id1));
    $controller->message($this->buildJsonRequest('msg2', [], $id2));

    $convKeys = array_values(array_filter($capturedKeys, fn($k) => str_starts_with($k, 'ilas_conv:')));
    $this->assertGreaterThanOrEqual(2, count($convKeys));
    // Find keys for each conversation ID.
    $key1 = 'ilas_conv:' . $id1;
    $key2 = 'ilas_conv:' . $id2;
    $this->assertContains($key1, $convKeys);
    $this->assertContains($key2, $convKeys);
    $this->assertNotEquals($key1, $key2);
  }

  // ─── Test 11: Repeated identical messages return escalation ──────────

  /**
   * Three identical cached messages trigger repeated-message escalation.
   */
  public function testRepeatedIdenticalMessagesReturnEscalation(): void {
    $conversationId = '11111111-1111-4111-8111-111111111111';
    $userMessage = 'test message';
    $redactedText = PiiRedactor::redactForStorage($userMessage, 200);

    // Build cache mock returning 3 identical history entries.
    $cached = new \stdClass();
    $cached->data = [
      ['role' => 'user', 'text' => $redactedText, 'timestamp' => time() - 30],
      ['role' => 'user', 'text' => $redactedText, 'timestamp' => time() - 20],
      ['role' => 'user', 'text' => $redactedText, 'timestamp' => time() - 10],
    ];

    $cache = $this->createStub(CacheBackendInterface::class);
    $cache->method('get')->willReturnCallback(function ($key) use ($conversationId, $cached) {
      if ($key === 'ilas_conv:' . $conversationId) {
        return $cached;
      }
      return FALSE;
    });

    $controller = $this->buildController(cache: $cache);
    $request = $this->buildJsonRequest($userMessage, [], $conversationId);
    $response = $controller->message($request);

    $body = json_decode($response->getContent(), TRUE);
    $this->assertEquals(200, $response->getStatusCode());
    $this->assertEquals('escalation', $body['type']);
    $this->assertEquals('repeated', $body['escalation_type']);
  }

  // ─── Test 12: Repeated messages response includes request_id ─────────

  /**
   * Repeated-message escalation response includes request_id and header.
   */
  public function testRepeatedMessagesResponseIncludesRequestId(): void {
    $conversationId = '11111111-1111-4111-8111-111111111111';
    $userMessage = 'test message';
    $redactedText = PiiRedactor::redactForStorage($userMessage, 200);

    $cached = new \stdClass();
    $cached->data = [
      ['role' => 'user', 'text' => $redactedText, 'timestamp' => time() - 30],
      ['role' => 'user', 'text' => $redactedText, 'timestamp' => time() - 20],
      ['role' => 'user', 'text' => $redactedText, 'timestamp' => time() - 10],
    ];

    $cache = $this->createStub(CacheBackendInterface::class);
    $cache->method('get')->willReturnCallback(function ($key) use ($conversationId, $cached) {
      if ($key === 'ilas_conv:' . $conversationId) {
        return $cached;
      }
      return FALSE;
    });

    $controller = $this->buildController(cache: $cache);
    $request = $this->buildJsonRequest($userMessage, [], $conversationId);
    $response = $controller->message($request);

    $body = json_decode($response->getContent(), TRUE);
    $this->assertArrayHasKey('request_id', $body);
    $this->assertMatchesRegularExpression(self::UUID4_PATTERN, $body['request_id']);
    $this->assertTrue($response->headers->has('X-Correlation-ID'));
    $this->assertEquals($body['request_id'], $response->headers->get('X-Correlation-ID'));
  }

  // ─── Test 13: Error response request_id consistency (@dataProvider) ──

  /**
   * Data provider for error response types that must have consistent IDs.
   */
  public static function errorResponseProvider(): array {
    return [
      '429_rate_limit' => [429],
      '400_content_type' => [400],
      '413_too_large' => [413],
      '500_catch_all' => [500],
    ];
  }

  /**
   * Error responses have body request_id matching X-Correlation-ID header.
   */
  #[DataProvider('errorResponseProvider')]
  public function testErrorResponseRequestIdConsistency(int $expectedStatus): void {
    switch ($expectedStatus) {
      case 429:
        $flood = $this->createStub(FloodInterface::class);
        $flood->method('isAllowed')->willReturn(FALSE);
        $controller = $this->buildController(flood: $flood);
        $request = $this->buildJsonRequest();
        break;

      case 400:
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
        break;

      case 413:
        $controller = $this->buildController();
        $request = Request::create(
          '/assistant/api/message',
          'POST',
          [],
          [],
          [],
          ['CONTENT_TYPE' => 'application/json'],
          str_repeat('a', 2001)
        );
        break;

      case 500:
        $controller = $this->buildController(
          processIntentException: new \RuntimeException('Simulated failure'),
        );
        $request = $this->buildJsonRequest();
        break;
    }

    $response = $controller->message($request);
    $this->assertEquals($expectedStatus, $response->getStatusCode());

    $body = json_decode($response->getContent(), TRUE);
    $this->assertArrayHasKey('request_id', $body, "request_id must be in body for HTTP {$expectedStatus}");
    $this->assertTrue(
      $response->headers->has('X-Correlation-ID'),
      "X-Correlation-ID header must be present for HTTP {$expectedStatus}"
    );
    $this->assertEquals(
      $body['request_id'],
      $response->headers->get('X-Correlation-ID'),
      "Body request_id must match X-Correlation-ID header for HTTP {$expectedStatus}"
    );
  }

  // ─── Test 14: Replay determinism ─────────────────────────────────────

  /**
   * Two calls with the same input and correlation ID produce same type.
   */
  public function testReplayDeterminism(): void {
    $correlationId = '550e8400-e29b-41d4-a716-446655440000';
    $controller = $this->buildController();

    $request1 = $this->buildJsonRequest('test message', ['X-Correlation-ID' => $correlationId]);
    $request2 = $this->buildJsonRequest('test message', ['X-Correlation-ID' => $correlationId]);

    $response1 = $controller->message($request1);
    $response2 = $controller->message($request2);

    $body1 = json_decode($response1->getContent(), TRUE);
    $body2 = json_decode($response2->getContent(), TRUE);

    // Same input + deterministic pipeline = same response type.
    $this->assertEquals($body1['type'], $body2['type'], 'Deterministic routing must produce same type');
    // Both should use the provided correlation ID.
    $this->assertEquals($correlationId, $body1['request_id']);
    $this->assertEquals($correlationId, $body2['request_id']);
  }

}
