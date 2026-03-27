<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Flood\FloodInterface;
use Drupal\ilas_site_assistant\Controller\AssistantApiController;
use Drupal\ilas_site_assistant\EventSubscriber\AssistantApiResponseMonitorSubscriber;
use Drupal\ilas_site_assistant\Exception\RetrievalDependencyUnavailableException;
use Drupal\ilas_site_assistant\Service\AnalyticsLogger;
use Drupal\ilas_site_assistant\Service\AssistantFlowRunner;
use Drupal\ilas_site_assistant\Service\AssistantReadEndpointGuard;
use Drupal\ilas_site_assistant\Service\FallbackGate;
use Drupal\ilas_site_assistant\Service\FaqIndex;
use Drupal\ilas_site_assistant\Service\IntentRouter;
use Drupal\ilas_site_assistant\Service\LlmEnhancer;
use Drupal\ilas_site_assistant\Service\PerformanceMonitor;
use Drupal\ilas_site_assistant\Service\PolicyFilter;
use Drupal\ilas_site_assistant\Service\ResourceFinder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Covers read-endpoint controller contracts for ordinary, throttled, and
 * degraded behavior.
 */
#[Group('ilas_site_assistant')]
final class AssistantApiReadEndpointContractTest extends TestCase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    require_once __DIR__ . '/controller_test_bootstrap.php';
  }

  /**
   * Short suggest queries bypass the read guard and return the cheap empty set.
   */
  public function testSuggestShortQueriesBypassReadGuard(): void {
    $flood = $this->createMock(FloodInterface::class);
    $flood->expects($this->never())->method('isAllowed');
    $flood->expects($this->never())->method('register');

    $controller = $this->buildController(
      readEndpointGuard: $this->buildReadGuard(
        ['suggest' => ['rate_limit_per_minute' => 1, 'rate_limit_per_hour' => 10]],
        $flood,
      ),
    );

    $response = $controller->suggest(Request::create('/assistant/api/suggest?q=h', 'GET'));

    $this->assertSame(200, $response->getStatusCode());
    $this->assertSame(['suggestions' => []], json_decode($response->getContent(), TRUE));
  }

  /**
   * Ordinary suggest queries still return 200 with suggestions.
   */
  public function testSuggestNormalQueryReturnsSuggestions(): void {
    $intentRouter = $this->createStub(IntentRouter::class);
    $intentRouter->method('suggestTopics')->willReturn([
      ['name' => 'Housing', 'id' => '75'],
    ]);

    $faqIndex = $this->createStub(FaqIndex::class);
    $faqIndex->method('search')->willReturn([
      ['question' => 'What do I do about eviction?', 'id' => 'faq_45'],
    ]);

    $controller = $this->buildController(
      intentRouter: $intentRouter,
      faqIndex: $faqIndex,
      readEndpointGuard: $this->buildReadGuard(),
    );

    $response = $controller->suggest(Request::create('/assistant/api/suggest?q=housing&type=all', 'GET'));

    $this->assertSame(200, $response->getStatusCode());
    $body = json_decode($response->getContent(), TRUE);
    $this->assertSame(['suggestions'], array_keys($body));
    $this->assertCount(2, $body['suggestions']);
    $this->assertSame('topic', $body['suggestions'][0]['type']);
    $this->assertSame('faq', $body['suggestions'][1]['type']);
    foreach ($body['suggestions'] as $suggestion) {
      $keys = array_keys($suggestion);
      sort($keys);
      $this->assertSame(['id', 'label', 'type'], $keys);
    }
  }

  /**
   * Suggest throttling returns 429 with a stable response shape.
   */
  public function testSuggestThrottleReturns429WithRequestId(): void {
    $controller = $this->buildController(
      readEndpointGuard: $this->buildReadGuard(
        ['suggest' => ['rate_limit_per_minute' => 1, 'rate_limit_per_hour' => 10]],
        $this->denyingFlood(FALSE),
      ),
    );

    $response = $controller->suggest(Request::create('/assistant/api/suggest?q=housing&type=all', 'GET'));

    $this->assertSame(429, $response->getStatusCode());
    $this->assertSame('60', $response->headers->get('Retry-After'));
    $body = json_decode($response->getContent(), TRUE);
    $this->assertSame([], $body['suggestions']);
    $this->assertSame('rate_limit', $body['type']);
    $this->assertSame($body['request_id'], $response->headers->get('X-Correlation-ID'));
  }

  /**
   * FAQ throttling returns stable bodies for each read mode.
   */
  #[DataProvider('faqThrottleProvider')]
  public function testFaqThrottleReturnsEndpointShapedBody(
    string $path,
    array $expectedBody,
  ): void {
    $controller = $this->buildController(
      readEndpointGuard: $this->buildReadGuard(
        ['faq' => ['rate_limit_per_minute' => 1, 'rate_limit_per_hour' => 10]],
        $this->denyingFlood(FALSE),
      ),
    );

    $response = $controller->faq(Request::create($path, 'GET'));

    $this->assertSame(429, $response->getStatusCode());
    $this->assertSame('60', $response->headers->get('Retry-After'));
    $body = json_decode($response->getContent(), TRUE);
    foreach ($expectedBody as $key => $value) {
      $this->assertSame($value, $body[$key]);
    }
    $this->assertSame('rate_limit', $body['type']);
    $this->assertSame($body['request_id'], $response->headers->get('X-Correlation-ID'));
  }

  /**
   * Provider for FAQ throttle-body contracts.
   */
  public static function faqThrottleProvider(): array {
    return [
      'id mode' => [
        '/assistant/api/faq?id=faq_12',
        ['faq' => NULL],
      ],
      'query mode' => [
        '/assistant/api/faq?q=eviction',
        ['results' => [], 'count' => 0],
      ],
      'category mode' => [
        '/assistant/api/faq',
        ['categories' => []],
      ],
    ];
  }

  /**
   * Suggest exceptions still degrade to the deterministic empty suggestion set.
   */
  public function testSuggestExceptionFallsBackToEmptySuggestions(): void {
    $intentRouter = $this->createMock(IntentRouter::class);
    $intentRouter->expects($this->once())
      ->method('suggestTopics')
      ->willThrowException(new \RuntimeException('Simulated topic failure'));

    $controller = $this->buildController(
      intentRouter: $intentRouter,
      readEndpointGuard: $this->buildReadGuard(),
    );

    $response = $controller->suggest(Request::create('/assistant/api/suggest?q=housing&type=topics', 'GET'));

    $this->assertSame(200, $response->getStatusCode());
    $this->assertSame(['suggestions' => []], json_decode($response->getContent(), TRUE));
  }

  /**
   * FAQ dependency loss suppresses only FAQ suggestions.
   */
  public function testSuggestFaqDependencyUnavailableSuppressesOnlyFaqSuggestions(): void {
    $intentRouter = $this->createStub(IntentRouter::class);
    $intentRouter->method('suggestTopics')->willReturn([
      ['name' => 'Housing', 'id' => '75'],
    ]);

    $faqIndex = $this->createMock(FaqIndex::class);
    $faqIndex->expects($this->once())
      ->method('search')
      ->willThrowException(new RetrievalDependencyUnavailableException('faq', 'faq_retrieval_unavailable'));

    $controller = $this->buildController(
      intentRouter: $intentRouter,
      faqIndex: $faqIndex,
      readEndpointGuard: $this->buildReadGuard(),
    );

    $response = $controller->suggest(Request::create('/assistant/api/suggest?q=housing&type=all', 'GET'));

    $this->assertSame(200, $response->getStatusCode());
    $body = json_decode($response->getContent(), TRUE);
    $this->assertSame(['suggestions'], array_keys($body));
    $this->assertCount(1, $body['suggestions']);
    $this->assertSame('topic', $body['suggestions'][0]['type']);
    $keys = array_keys($body['suggestions'][0]);
    sort($keys);
    $this->assertSame(['id', 'label', 'type'], $keys);
    $this->assertArrayNotHasKey('reason_code', $body);
    $this->assertArrayNotHasKey('request_id', $body);
  }

  /**
   * Suggest throttles record exactly one denied monitor outcome.
   */
  public function testSuggestThrottleRecordsDeniedOutcomeOnce(): void {
    $monitor = $this->createMock(PerformanceMonitor::class);
    $monitor->expects($this->once())
      ->method('recordObservedRequest')
      ->with(
        $this->greaterThanOrEqual(0.0),
        FALSE,
        PerformanceMonitor::ENDPOINT_SUGGEST,
        'suggest.rate_limit',
        429,
        TRUE,
        FALSE,
        'unknown',
      );

    $controller = $this->buildController(
      readEndpointGuard: $this->buildReadGuard(
        ['suggest' => ['rate_limit_per_minute' => 1, 'rate_limit_per_hour' => 10]],
        $this->denyingFlood(FALSE),
      ),
    );
    $subscriber = new AssistantApiResponseMonitorSubscriber($monitor);
    $request = Request::create('/assistant/api/suggest?q=housing&type=all', 'GET');

    $response = $controller->suggest($request);
    $this->dispatchMonitoredResponse($subscriber, $request, $response);

    $this->assertSame(429, $response->getStatusCode());
  }

  /**
   * Suggest degraded fallbacks record exactly one failed monitor outcome.
   */
  public function testSuggestDegradedFallbackRecordsFailedOutcomeOnce(): void {
    $intentRouter = $this->createMock(IntentRouter::class);
    $intentRouter->expects($this->once())
      ->method('suggestTopics')
      ->willThrowException(new \RuntimeException('Simulated topic failure'));

    $monitor = $this->createMock(PerformanceMonitor::class);
    $monitor->expects($this->once())
      ->method('recordObservedRequest')
      ->with(
        $this->greaterThanOrEqual(0.0),
        FALSE,
        PerformanceMonitor::ENDPOINT_SUGGEST,
        'suggest.degraded_empty',
        200,
        FALSE,
        TRUE,
        'unknown',
      );

    $controller = $this->buildController(
      intentRouter: $intentRouter,
      readEndpointGuard: $this->buildReadGuard(),
    );
    $subscriber = new AssistantApiResponseMonitorSubscriber($monitor);
    $request = Request::create('/assistant/api/suggest?q=housing&type=topics', 'GET');

    $response = $controller->suggest($request);
    $this->dispatchMonitoredResponse($subscriber, $request, $response);

    $this->assertSame(200, $response->getStatusCode());
  }

  /**
   * FAQ ID exceptions still degrade to the existing 404 not-found response.
   */
  public function testFaqIdExceptionFallsBackToNotFound(): void {
    $faqIndex = $this->createMock(FaqIndex::class);
    $faqIndex->expects($this->once())
      ->method('getById')
      ->willThrowException(new \RuntimeException('Simulated FAQ lookup failure'));

    $controller = $this->buildController(
      faqIndex: $faqIndex,
      readEndpointGuard: $this->buildReadGuard(),
    );

    $response = $controller->faq(Request::create('/assistant/api/faq?id=faq_55', 'GET'));

    $this->assertSame(404, $response->getStatusCode());
    $this->assertSame(['error' => 'FAQ not found'], json_decode($response->getContent(), TRUE));
  }

  /**
   * FAQ dependency loss returns a 503 unavailable body for ID lookups.
   */
  public function testFaqIdDependencyUnavailableReturns503(): void {
    $faqIndex = $this->createMock(FaqIndex::class);
    $faqIndex->expects($this->once())
      ->method('getById')
      ->willThrowException(new RetrievalDependencyUnavailableException('faq', 'faq_retrieval_unavailable'));

    $controller = $this->buildController(
      faqIndex: $faqIndex,
      readEndpointGuard: $this->buildReadGuard(),
    );

    $response = $controller->faq(Request::create('/assistant/api/faq?id=faq_55', 'GET'));

    $this->assertSame(503, $response->getStatusCode());
    $body = json_decode($response->getContent(), TRUE);
    $this->assertSame([
      'error' => 'FAQ retrieval is temporarily unavailable.',
      'type' => 'unavailable',
      'error_code' => 'faq_retrieval_unavailable',
      'request_id' => $body['request_id'],
      'faq' => NULL,
    ], $body);
  }

  /**
   * FAQ query exceptions still degrade to empty search results.
   */
  public function testFaqQueryExceptionFallsBackToEmptyResults(): void {
    $faqIndex = $this->createMock(FaqIndex::class);
    $faqIndex->expects($this->once())
      ->method('search')
      ->willThrowException(new \RuntimeException('Simulated FAQ search failure'));

    $controller = $this->buildController(
      faqIndex: $faqIndex,
      readEndpointGuard: $this->buildReadGuard(),
    );

    $response = $controller->faq(Request::create('/assistant/api/faq?q=eviction', 'GET'));

    $this->assertSame(200, $response->getStatusCode());
    $this->assertSame([
      'results' => [],
      'count' => 0,
    ], json_decode($response->getContent(), TRUE));
  }

  /**
   * FAQ dependency loss returns a 503 unavailable body for searches.
   */
  public function testFaqQueryDependencyUnavailableReturns503(): void {
    $faqIndex = $this->createMock(FaqIndex::class);
    $faqIndex->expects($this->once())
      ->method('search')
      ->willThrowException(new RetrievalDependencyUnavailableException('faq', 'faq_retrieval_unavailable'));

    $controller = $this->buildController(
      faqIndex: $faqIndex,
      readEndpointGuard: $this->buildReadGuard(),
    );

    $response = $controller->faq(Request::create('/assistant/api/faq?q=eviction', 'GET'));

    $this->assertSame(503, $response->getStatusCode());
    $body = json_decode($response->getContent(), TRUE);
    $this->assertSame([], $body['results']);
    $this->assertSame(0, $body['count']);
    $this->assertSame('unavailable', $body['type']);
    $this->assertSame('faq_retrieval_unavailable', $body['error_code']);
  }

  /**
   * FAQ category exceptions still degrade to the empty category list.
   */
  public function testFaqCategoryExceptionFallsBackToEmptyCategories(): void {
    $faqIndex = $this->createMock(FaqIndex::class);
    $faqIndex->expects($this->once())
      ->method('getCategories')
      ->willThrowException(new \RuntimeException('Simulated category failure'));

    $controller = $this->buildController(
      faqIndex: $faqIndex,
      readEndpointGuard: $this->buildReadGuard(),
    );

    $response = $controller->faq(Request::create('/assistant/api/faq', 'GET'));

    $this->assertSame(200, $response->getStatusCode());
    $this->assertSame(['categories' => []], json_decode($response->getContent(), TRUE));
  }

  /**
   * FAQ dependency loss returns a 503 unavailable body for categories.
   */
  public function testFaqCategoriesDependencyUnavailableReturns503(): void {
    $faqIndex = $this->createMock(FaqIndex::class);
    $faqIndex->expects($this->once())
      ->method('getCategories')
      ->willThrowException(new RetrievalDependencyUnavailableException('faq', 'faq_retrieval_unavailable'));

    $controller = $this->buildController(
      faqIndex: $faqIndex,
      readEndpointGuard: $this->buildReadGuard(),
    );

    $response = $controller->faq(Request::create('/assistant/api/faq', 'GET'));

    $this->assertSame(503, $response->getStatusCode());
    $body = json_decode($response->getContent(), TRUE);
    $this->assertSame([], $body['categories']);
    $this->assertSame('unavailable', $body['type']);
    $this->assertSame('faq_retrieval_unavailable', $body['error_code']);
  }

  /**
   * FAQ throttles record exactly one denied monitor outcome.
   */
  public function testFaqThrottleRecordsDeniedOutcomeOnce(): void {
    $monitor = $this->createMock(PerformanceMonitor::class);
    $monitor->expects($this->once())
      ->method('recordObservedRequest')
      ->with(
        $this->greaterThanOrEqual(0.0),
        FALSE,
        PerformanceMonitor::ENDPOINT_FAQ,
        'faq.rate_limit',
        429,
        TRUE,
        FALSE,
        'unknown',
      );

    $controller = $this->buildController(
      readEndpointGuard: $this->buildReadGuard(
        ['faq' => ['rate_limit_per_minute' => 1, 'rate_limit_per_hour' => 10]],
        $this->denyingFlood(FALSE),
      ),
    );
    $subscriber = new AssistantApiResponseMonitorSubscriber($monitor);
    $request = Request::create('/assistant/api/faq?q=eviction', 'GET');

    $response = $controller->faq($request);
    $this->dispatchMonitoredResponse($subscriber, $request, $response);

    $this->assertSame(429, $response->getStatusCode());
  }

  /**
   * FAQ degraded fallbacks record exactly one failed monitor outcome.
   */
  public function testFaqDegradedFallbackRecordsFailedOutcomeOnce(): void {
    $faqIndex = $this->createMock(FaqIndex::class);
    $faqIndex->expects($this->once())
      ->method('search')
      ->willThrowException(new \RuntimeException('Simulated FAQ search failure'));

    $monitor = $this->createMock(PerformanceMonitor::class);
    $monitor->expects($this->once())
      ->method('recordObservedRequest')
      ->with(
        $this->greaterThanOrEqual(0.0),
        FALSE,
        PerformanceMonitor::ENDPOINT_FAQ,
        'faq.degraded_empty_results',
        200,
        FALSE,
        TRUE,
        'unknown',
      );

    $controller = $this->buildController(
      faqIndex: $faqIndex,
      readEndpointGuard: $this->buildReadGuard(),
    );
    $subscriber = new AssistantApiResponseMonitorSubscriber($monitor);
    $request = Request::create('/assistant/api/faq?q=eviction', 'GET');

    $response = $controller->faq($request);
    $this->dispatchMonitoredResponse($subscriber, $request, $response);

    $this->assertSame(200, $response->getStatusCode());
  }

  /**
   * Cached read responses are backfilled when controller instrumentation is bypassed.
   */
  public function testCachedSuggestResponseIsBackfilledWhenControllerBypassed(): void {
    $monitor = $this->createMock(PerformanceMonitor::class);
    $monitor->expects($this->once())
      ->method('recordObservedRequest')
      ->with(
        $this->greaterThanOrEqual(0.0),
        TRUE,
        PerformanceMonitor::ENDPOINT_SUGGEST,
        'suggest.success',
        200,
        FALSE,
        FALSE,
        'unknown',
      );

    $subscriber = new AssistantApiResponseMonitorSubscriber($monitor);
    $request = Request::create('/assistant/api/suggest?q=housing&type=all', 'GET');
    $response = new CacheableJsonResponse([
      'suggestions' => [
        ['type' => 'topic', 'label' => 'Housing', 'id' => '75'],
      ],
    ], 200);
    $response->setMaxAge(300);

    $this->dispatchMonitoredResponse($subscriber, $request, $response);
  }

  /**
   * Builds a controller with stubs sufficient for read-endpoint testing.
   */
  private function buildController(
    ?IntentRouter $intentRouter = NULL,
    ?FaqIndex $faqIndex = NULL,
    ?AssistantReadEndpointGuard $readEndpointGuard = NULL,
    ?LoggerInterface $logger = NULL,
  ): AssistantApiController {
    $config = $this->createStub(ImmutableConfig::class);
    $config->method('get')->willReturnCallback(static function (string $key) {
      return match ($key) {
        'enable_faq' => TRUE,
        'read_endpoint_rate_limits' => [
          'suggest' => ['rate_limit_per_minute' => 120, 'rate_limit_per_hour' => 1200],
          'faq' => ['rate_limit_per_minute' => 60, 'rate_limit_per_hour' => 600],
        ],
        default => NULL,
      };
    });

    $configFactory = $this->createStub(ConfigFactoryInterface::class);
    $configFactory->method('get')->willReturn($config);

    $intentRouter ??= $this->createStub(IntentRouter::class);
    $faqIndex ??= $this->createStub(FaqIndex::class);
    $resourceFinder = $this->createStub(ResourceFinder::class);
    $policyFilter = $this->createStub(PolicyFilter::class);
    $analyticsLogger = $this->createStub(AnalyticsLogger::class);
    $llmEnhancer = $this->createStub(LlmEnhancer::class);
    $fallbackGate = $this->createStub(FallbackGate::class);
    $flood = $this->createStub(FloodInterface::class);
    $cache = $this->createStub(CacheBackendInterface::class);
    $logger ??= $this->createStub(LoggerInterface::class);

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
      selection_registry: new \Drupal\ilas_site_assistant\Service\SelectionRegistry(new \Drupal\ilas_site_assistant\Service\TopIntentsPack()),
      selection_state_store: new \Drupal\ilas_site_assistant\Service\SelectionStateStore($cache),
      read_endpoint_guard: $readEndpointGuard,
    );
  }

  /**
   * Builds a real read guard backed by stubbed config and flood behavior.
   */
  private function buildReadGuard(
    ?array $limits = NULL,
    ?FloodInterface $flood = NULL,
    ?LoggerInterface $logger = NULL,
  ): AssistantReadEndpointGuard {
    $config = $this->createStub(ImmutableConfig::class);
    $config->method('get')->willReturnCallback(static function (string $key) use ($limits) {
      return $key === 'read_endpoint_rate_limits' ? $limits : NULL;
    });

    $configFactory = $this->createStub(ConfigFactoryInterface::class);
    $configFactory->method('get')->willReturn($config);

    if ($flood === NULL) {
      $flood = $this->createStub(FloodInterface::class);
      $flood->method('isAllowed')->willReturn(TRUE);
    }
    $logger ??= $this->createStub(LoggerInterface::class);

    return new AssistantReadEndpointGuard(
      $configFactory,
      $flood,
      new \Drupal\ilas_site_assistant\Service\RequestTrustInspector(),
      $logger,
    );
  }

  /**
   * Returns a flood mock that denies on minute or hour checks.
   */
  private function denyingFlood(bool $denyOnHour): FloodInterface {
    $calls = 0;
    $flood = $this->createMock(FloodInterface::class);
    $flood->expects($denyOnHour ? $this->exactly(2) : $this->once())
      ->method('isAllowed')
      ->willReturnCallback(function () use (&$calls, $denyOnHour): bool {
        $calls++;
        if ($denyOnHour) {
          return $calls === 1;
        }
        return FALSE;
      });
    $flood->expects($this->never())->method('register');
    return $flood;
  }

  /**
   * FAQ search responses contain only the public field allowlist.
   */
  public function testFaqSearchResponseContainsOnlyPublicFields(): void {
    $faqIndex = $this->createStub(FaqIndex::class);
    $faqIndex->method('search')->willReturn([$this->fullFaqResult()]);

    $controller = $this->buildController(
      faqIndex: $faqIndex,
      readEndpointGuard: $this->buildReadGuard(),
    );

    $response = $controller->faq(Request::create('/assistant/api/faq?q=eviction', 'GET'));

    $this->assertSame(200, $response->getStatusCode());
    $body = json_decode($response->getContent(), TRUE);
    $this->assertSame(1, $body['count']);
    $result = $body['results'][0];
    $keys = array_keys($result);
    sort($keys);
    $this->assertSame(['answer', 'id', 'question', 'url'], $keys);
    foreach (self::DENIED_FAQ_FIELDS as $field) {
      $this->assertArrayNotHasKey($field, $result, "Internal field '{$field}' leaked into search response");
    }
  }

  /**
   * FAQ ID lookup responses contain only the public field allowlist.
   */
  public function testFaqIdResponseContainsOnlyPublicFields(): void {
    $faqIndex = $this->createStub(FaqIndex::class);
    $faqIndex->method('getById')->willReturn($this->fullFaqResult());

    $controller = $this->buildController(
      faqIndex: $faqIndex,
      readEndpointGuard: $this->buildReadGuard(),
    );

    $response = $controller->faq(Request::create('/assistant/api/faq?id=faq_123', 'GET'));

    $this->assertSame(200, $response->getStatusCode());
    $body = json_decode($response->getContent(), TRUE);
    $faq = $body['faq'];
    $keys = array_keys($faq);
    sort($keys);
    $this->assertSame(['answer', 'id', 'question', 'url'], $keys);
    foreach (self::DENIED_FAQ_FIELDS as $field) {
      $this->assertArrayNotHasKey($field, $faq, "Field '{$field}' should not appear in ID lookup response");
    }
  }

  /**
   * FAQ category browse responses contain only the public field allowlist.
   */
  public function testFaqCategoriesResponseContainsOnlyPublicFields(): void {
    $faqIndex = $this->createStub(FaqIndex::class);
    $faqIndex->method('getCategories')->willReturn([
      [
        'name' => 'Housing',
        'count' => '3',
        'internal' => 'hidden',
      ],
    ]);

    $controller = $this->buildController(
      faqIndex: $faqIndex,
      readEndpointGuard: $this->buildReadGuard(),
    );

    $response = $controller->faq(Request::create('/assistant/api/faq', 'GET'));

    $this->assertSame(200, $response->getStatusCode());
    $body = json_decode($response->getContent(), TRUE);
    $this->assertCount(1, $body['categories']);
    $category = $body['categories'][0];
    $keys = array_keys($category);
    sort($keys);
    $this->assertSame(['count', 'name'], $keys);
    $this->assertSame('Housing', $category['name']);
    $this->assertSame(3, $category['count']);
    $this->assertArrayNotHasKey('internal', $category);
  }

  /**
   * Each denied internal field is individually stripped from search results.
   */
  #[DataProvider('deniedFaqFieldProvider')]
  public function testFaqSearchResponseDeniesInternalFields(string $field, mixed $value): void {
    $result = [
      'id' => 'faq_123',
      'question' => 'Test?',
      'answer' => 'Yes.',
      'url' => '/test',
      $field => $value,
    ];

    $faqIndex = $this->createStub(FaqIndex::class);
    $faqIndex->method('search')->willReturn([$result]);

    $controller = $this->buildController(
      faqIndex: $faqIndex,
      readEndpointGuard: $this->buildReadGuard(),
    );

    $response = $controller->faq(Request::create('/assistant/api/faq?q=test', 'GET'));

    $body = json_decode($response->getContent(), TRUE);
    $this->assertArrayNotHasKey(
      $field,
      $body['results'][0],
      "Internal field '{$field}' must not appear in public FAQ search response",
    );
  }

  /**
   * Provider for denied FAQ fields with representative values.
   */
  public static function deniedFaqFieldProvider(): array {
    return [
      'paragraph_id' => ['paragraph_id', 42],
      'type' => ['type', 'faq_item'],
      'full_answer' => ['full_answer', 'Full answer text'],
      'title' => ['title', 'FAQ Title'],
      'body' => ['body', 'Body text'],
      'anchor' => ['anchor', 'section-anchor'],
      'category' => ['category', 'Housing'],
      'parent_url' => ['parent_url', '/legal-topics/housing'],
      'source_url' => ['source_url', '/node/123'],
      'score' => ['score', 0.95],
      'source' => ['source', 'lexical'],
      'updated_at' => ['updated_at', '2026-01-15'],
      'answer_snippet' => ['answer_snippet', 'Snippet...'],
      'vector_score' => ['vector_score', 0.87],
      'source_class' => ['source_class', 'authoritative'],
      'provenance' => ['provenance', ['origin' => 'manual', 'verified' => TRUE]],
      'freshness' => ['freshness', ['score' => 0.9, 'last_checked' => '2026-01-01']],
      'governance_flags' => ['governance_flags', ['needs_review']],
    ];
  }

  /**
   * Denied field names for negative assertions.
   */
  private const DENIED_FAQ_FIELDS = [
    'paragraph_id', 'type', 'full_answer', 'title', 'body', 'anchor',
    'category', 'parent_url', 'source_url', 'score', 'source',
    'updated_at', 'answer_snippet',
    'vector_score', 'source_class', 'provenance', 'freshness', 'governance_flags',
  ];

  /**
   * Returns a FAQ result with all known fields populated.
   */
  private function fullFaqResult(): array {
    return [
      'id' => 'faq_123',
      'paragraph_id' => 42,
      'type' => 'faq_item',
      'question' => 'What do I do about eviction?',
      'answer' => 'Contact legal aid immediately.',
      'full_answer' => 'Contact legal aid immediately for assistance.',
      'title' => 'Eviction Help',
      'body' => 'Contact legal aid immediately.',
      'anchor' => 'eviction-help',
      'category' => 'Housing',
      'parent_url' => '/legal-topics/housing',
      'url' => '/legal-topics/housing/eviction',
      'source_url' => '/node/55',
      'updated_at' => '2026-01-15T10:00:00+00:00',
      'source' => 'vector',
      'source_class' => 'authoritative',
      'score' => 0.95,
      'answer_snippet' => 'Contact legal aid...',
      'vector_score' => 0.87,
      'provenance' => [
        'origin' => 'manual',
        'author' => 'staff',
        'verified' => TRUE,
        'verified_at' => '2026-01-10',
        'source_node' => 55,
        'paragraph_bundle' => 'faq_item',
        'content_type' => 'legal_topic',
      ],
      'freshness' => [
        'score' => 0.9,
        'last_checked' => '2026-01-01',
        'days_since_update' => 14,
        'freshness_class' => 'current',
      ],
      'governance_flags' => ['needs_review'],
    ];
  }

  /**
   * Runs the response-monitor subscriber around a controller response.
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
