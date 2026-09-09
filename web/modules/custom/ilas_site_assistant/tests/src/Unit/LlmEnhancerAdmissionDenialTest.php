<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\ilas_site_assistant\Service\CostControlPolicy;
use Drupal\ilas_site_assistant\Service\EnvironmentDetector;
use Drupal\ilas_site_assistant\Service\LlmCircuitBreaker;
use Drupal\ilas_site_assistant\Service\LlmEnhancer;
use Drupal\ilas_site_assistant\Service\LlmEvalTrafficPolicy;
use Drupal\ilas_site_assistant\Service\LlmRateLimiter;
use Drupal\ilas_site_assistant\Service\PolicyFilter;
use Drupal\ilas_site_assistant\Service\RequestTimeLlmTransportInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Locks the boundary between local admission denials and breaker failures.
 *
 * Regression coverage for the dev circuit-breaker loop (Sentry PHP-9P,
 * PHP-8N, PHP-8P, PHP-9N): a per-IP budget denial was thrown as a plain
 * RuntimeException inside classifyIntent()'s try block, caught by the
 * generic catch, logged at ERROR and recorded as a circuit-breaker failure.
 * Three denials from one client within 60 s opened the site-wide breaker.
 *
 * This is the only test that constructs LlmEnhancer with BOTH a cost-control
 * policy and a circuit breaker; the bug lived in that intersection.
 */
#[Group('ilas_site_assistant')]
final class LlmEnhancerAdmissionDenialTest extends TestCase {

  /**
   * A per-IP budget denial must never reach the breaker or the ERROR log.
   */
  public function testBudgetDenialReturnsCurrentIntentWithoutBreakerFailure(): void {
    $logger = $this->buildLogger();
    $logger->expects($this->never())->method('error');
    $logger->expects($this->never())->method('warning');
    $logger->expects($this->once())
      ->method('notice')
      ->with(
        $this->stringContains('Request-time LLM budget exceeded'),
        $this->callback(static fn(array $context): bool => ($context['@reason'] ?? NULL) === 'per_ip_budget_exceeded'),
      );

    $breaker = $this->createMock(LlmCircuitBreaker::class);
    $breaker->method('isAvailable')->willReturn(TRUE);
    $breaker->expects($this->never())->method('recordFailure');
    $breaker->expects($this->never())->method('recordSuccess');

    $costControl = $this->createMock(CostControlPolicy::class);
    $costControl->method('beginRequest')
      ->willReturn(['allowed' => FALSE, 'reason' => 'per_ip_budget_exceeded']);
    $costControl->expects($this->never())->method('recordCacheMiss');
    $costControl->expects($this->never())->method('recordCall');

    $transport = new AdmissionDenialStaticTransport();
    $enhancer = $this->buildEnhancer($logger, $breaker, $costControl, $transport);

    $result = $enhancer->classifyIntent('help with eviction', 'unknown', '203.0.113.7');

    $this->assertSame('unknown', $result);
    $this->assertSame(0, $transport->calls, 'Denied admission must not reach the transport.');
    $meta = $enhancer->getLastRequestMeta() ?? [];
    $this->assertSame('budget_per_ip_budget_exceeded', $meta['fallback_reason'] ?? NULL);
    $this->assertSame('per_ip_budget_exceeded', $meta['admission_reason'] ?? NULL);
    $this->assertFalse($meta['success'] ?? TRUE);
    $this->assertFalse($meta['transport_attempted'] ?? TRUE);
    $this->assertArrayNotHasKey('error_class', $meta);
    $this->assertArrayNotHasKey('error_signature', $meta);
  }

  /**
   * Every coordinator denial reason is an admission decision, not a failure.
   */
  #[DataProvider('admissionReasons')]
  public function testEveryAdmissionReasonBypassesBreaker(string $reason): void {
    $logger = $this->buildLogger();
    $logger->expects($this->never())->method('error');
    $logger->expects($this->once())->method('notice');

    $breaker = $this->createMock(LlmCircuitBreaker::class);
    $breaker->method('isAvailable')->willReturn(TRUE);
    $breaker->expects($this->never())->method('recordFailure');

    $costControl = $this->createMock(CostControlPolicy::class);
    $costControl->method('beginRequest')->willReturn(['allowed' => FALSE, 'reason' => $reason]);

    $enhancer = $this->buildEnhancer($logger, $breaker, $costControl, new AdmissionDenialStaticTransport());
    $this->assertSame('unknown', $enhancer->classifyIntent('what forms do i need', 'unknown', '203.0.113.7'));
    $this->assertSame('budget_' . $reason, $enhancer->getLastRequestMeta()['fallback_reason'] ?? NULL);
  }

  /**
   * The eight denial reasons emitted by LlmAdmissionCoordinator.
   */
  public static function admissionReasons(): array {
    return [
      'lock timeout' => ['concurrency_lock_timeout'],
      'kill switch' => ['manual_kill_switch'],
      'breaker open' => ['circuit_breaker_open'],
      'global rate limit' => ['rate_limit_exceeded'],
      'daily budget' => ['daily_budget_exhausted'],
      'monthly budget' => ['monthly_budget_exhausted'],
      'per-ip budget' => ['per_ip_budget_exceeded'],
      'sampling gate' => ['sampling_gate_rejected'],
    ];
  }

  /**
   * A genuine transport failure still records exactly one breaker failure.
   *
   * ConnectException carries no HTTP status, which reproduces the production
   * "(HTTP none)" title for real network failures.
   */
  public function testTransportFailureRecordsBreakerFailureOnceAndLogsError(): void {
    $logger = $this->buildLogger();
    $logger->expects($this->once())
      ->method('error')
      ->with($this->stringContains('Request-time LLM intent classification failed'), $this->anything());
    $logger->expects($this->never())->method('notice');

    $breaker = $this->createMock(LlmCircuitBreaker::class);
    $breaker->method('isAvailable')->willReturn(TRUE);
    $breaker->expects($this->once())->method('recordFailure');

    $costControl = $this->createMock(CostControlPolicy::class);
    $costControl->method('beginRequest')->willReturn(['allowed' => TRUE, 'reason' => 'allowed']);

    $enhancer = $this->buildSequencedEnhancer($logger, $breaker, $costControl, [
      new ConnectException('cURL error 28', new Request('POST', 'https://api.cohere.com/v2/chat')),
    ]);

    $this->assertSame('unknown', $enhancer->classifyIntent('help with eviction', 'unknown', '203.0.113.7'));
    $meta = $enhancer->getLastRequestMeta() ?? [];
    $this->assertSame('exception', $meta['fallback_reason'] ?? NULL);
    $this->assertSame(ConnectException::class, $meta['error_class'] ?? NULL);
    $this->assertTrue($meta['transport_attempted'] ?? FALSE);
  }

  /**
   * Non-retryable HTTP errors and malformed payloads also count as failures.
   */
  #[DataProvider('transportFailures')]
  public function testOtherTransportFailuresRecordBreakerFailure(mixed $dispatchResult): void {
    $logger = $this->buildLogger();
    $logger->expects($this->once())->method('error');

    $breaker = $this->createMock(LlmCircuitBreaker::class);
    $breaker->method('isAvailable')->willReturn(TRUE);
    $breaker->expects($this->once())->method('recordFailure');

    $costControl = $this->createMock(CostControlPolicy::class);
    $costControl->method('beginRequest')->willReturn(['allowed' => TRUE, 'reason' => 'allowed']);

    $enhancer = $this->buildSequencedEnhancer($logger, $breaker, $costControl, [$dispatchResult]);
    $this->assertSame('unknown', $enhancer->classifyIntent('help with eviction', 'unknown', '203.0.113.7'));
    $this->assertSame('exception', $enhancer->getLastRequestMeta()['fallback_reason'] ?? NULL);
  }

  /**
   * Transport outcomes that must feed the breaker.
   */
  public static function transportFailures(): array {
    return [
      'http 500' => [
        new RequestException(
          'transport failure',
          new Request('POST', 'https://api.cohere.com/v2/chat'),
          new Response(500),
        ),
      ],
      'non-array payload' => [['payload' => 'not-an-array']],
    ];
  }

  /**
   * A coordinator-side circuit_breaker_open denial is not double-logged.
   *
   * Models the cooldown-elapsed race where isAvailable() reports TRUE but the
   * coordinator refuses the half-open probe slot.
   */
  public function testCircuitBreakerOpenAdmissionDenialIsNotDoubleLoggedOrRecorded(): void {
    $logger = $this->buildLogger();
    $logger->expects($this->never())->method('warning');
    $logger->expects($this->never())->method('error');
    $logger->expects($this->once())->method('notice');

    $breaker = $this->createMock(LlmCircuitBreaker::class);
    $breaker->expects($this->once())->method('isAvailable')->willReturn(TRUE);
    $breaker->expects($this->never())->method('recordFailure');

    $costControl = $this->createMock(CostControlPolicy::class);
    $costControl->method('beginRequest')->willReturn(['allowed' => FALSE, 'reason' => 'circuit_breaker_open']);

    $enhancer = $this->buildEnhancer($logger, $breaker, $costControl, new AdmissionDenialStaticTransport());
    $this->assertSame('unknown', $enhancer->classifyIntent('help with eviction', 'unknown', '203.0.113.7'));
    $this->assertSame('budget_circuit_breaker_open', $enhancer->getLastRequestMeta()['fallback_reason'] ?? NULL);
  }

  /**
   * The open-breaker skip warns once per open window, then notices.
   */
  public function testCircuitOpenSkipWarnsOncePerOpenWindowThenNotices(): void {
    $store = [];
    $cache = $this->buildCache($store);

    $logger = $this->buildLogger();
    $logger->expects($this->once())
      ->method('warning')
      ->with($this->identicalTo('Skipping request-time LLM classification because the circuit breaker is open.'), $this->anything());
    $logger->expects($this->exactly(2))->method('notice');
    $logger->expects($this->never())->method('error');

    $breaker = $this->createMock(LlmCircuitBreaker::class);
    $breaker->method('isAvailable')->willReturn(FALSE);
    $breaker->method('getState')->willReturn([
      'state' => 'open',
      'opened_at' => 1_700_000_000,
      'consecutive_failures' => 3,
      'last_failure_time' => 1_700_000_000,
    ]);

    $costControl = $this->createMock(CostControlPolicy::class);
    $costControl->expects($this->never())->method('beginRequest');

    $enhancer = $this->buildEnhancer($logger, $breaker, $costControl, new AdmissionDenialStaticTransport(), $cache);
    for ($i = 0; $i < 3; $i++) {
      $this->assertSame('unknown', $enhancer->classifyIntent('help with eviction', 'unknown', '203.0.113.7'));
      $this->assertSame('circuit_open', $enhancer->getLastRequestMeta()['fallback_reason'] ?? NULL);
    }

    // A fresh open window (new opened_at) warns again, even sharing the cache.
    $secondLogger = $this->buildLogger();
    $secondLogger->expects($this->once())->method('warning');
    $secondBreaker = $this->createMock(LlmCircuitBreaker::class);
    $secondBreaker->method('isAvailable')->willReturn(FALSE);
    $secondBreaker->method('getState')->willReturn([
      'state' => 'open',
      'opened_at' => 1_700_000_600,
      'consecutive_failures' => 3,
      'last_failure_time' => 1_700_000_600,
    ]);
    $second = $this->buildEnhancer(
      $secondLogger,
      $secondBreaker,
      $costControl,
      new AdmissionDenialStaticTransport(),
      $this->buildCache($store),
    );
    $this->assertSame('unknown', $second->classifyIntent('help with eviction', 'unknown', '203.0.113.7'));
  }

  /**
   * Without a cache backend every open-breaker skip warns (legacy behaviour).
   */
  public function testCircuitOpenSkipWarnsEveryTimeWithoutCache(): void {
    $logger = $this->buildLogger();
    $logger->expects($this->exactly(2))->method('warning');
    $logger->expects($this->never())->method('notice');

    $breaker = $this->createMock(LlmCircuitBreaker::class);
    $breaker->method('isAvailable')->willReturn(FALSE);

    $enhancer = $this->buildEnhancer($logger, $breaker, NULL, new AdmissionDenialStaticTransport(), NULL);
    $enhancer->classifyIntent('help with eviction', 'unknown', '203.0.113.7');
    $enhancer->classifyIntent('help with eviction', 'unknown', '203.0.113.7');
  }

  /**
   * The diagnostics probe reports the admission reason and never records failures.
   */
  public function testProbeConnectivityReportsAdmissionReasonWithoutBreakerFailure(): void {
    $breaker = $this->createMock(LlmCircuitBreaker::class);
    $breaker->method('isAvailable')->willReturn(TRUE);
    $breaker->expects($this->never())->method('recordFailure');

    $costControl = $this->createMock(CostControlPolicy::class);
    $costControl->method('beginRequest')->willReturn(['allowed' => FALSE, 'reason' => 'daily_budget_exhausted']);

    $enhancer = $this->buildEnhancer($this->buildLogger(), $breaker, $costControl, new AdmissionDenialStaticTransport());
    $probe = $enhancer->probeConnectivity('unit');

    $this->assertFalse($probe['success']);
    $this->assertSame('budget_daily_budget_exhausted', $probe['fallback_reason']);
    $this->assertSame('daily_budget_exhausted', $probe['admission_reason']);
    $this->assertArrayNotHasKey('error_class', $probe);
  }

  /**
   * The eval per-IP exemption flag is forwarded to cost control verbatim.
   */
  public function testClassifyIntentForwardsPerIpExemptOption(): void {
    $breaker = $this->createMock(LlmCircuitBreaker::class);
    $breaker->method('isAvailable')->willReturn(TRUE);

    $costControl = $this->createMock(CostControlPolicy::class);
    $costControl->expects($this->exactly(2))
      ->method('beginRequest')
      ->willReturnCallback(function (?string $identity, array $options = []): array {
        static $call = 0;
        $call++;
        $expected = $call === 1;
        $this->assertSame($expected, $options[LlmEvalTrafficPolicy::OPTION_PER_IP_EXEMPT] ?? NULL);
        return ['allowed' => TRUE, 'reason' => 'allowed'];
      });

    $enhancer = $this->buildEnhancer($this->buildLogger(), $breaker, $costControl, new AdmissionDenialStaticTransport());
    $enhancer->classifyIntent('help with eviction', 'unknown', '203.0.113.7', [LlmEvalTrafficPolicy::OPTION_PER_IP_EXEMPT => TRUE]);
    $this->assertTrue($enhancer->getLastRequestMeta()['per_ip_budget_exempt'] ?? NULL);
    $enhancer->classifyIntent('help with eviction', 'unknown', '203.0.113.7');
    $this->assertFalse($enhancer->getLastRequestMeta()['per_ip_budget_exempt'] ?? NULL);
  }

  /**
   * Builds an enhancer with a static transport.
   */
  private function buildEnhancer(
    LoggerInterface $logger,
    ?LlmCircuitBreaker $breaker,
    ?CostControlPolicy $costControl,
    RequestTimeLlmTransportInterface $transport,
    ?CacheBackendInterface $cache = NULL,
  ): LlmEnhancer {
    $configFactory = $this->buildConfigFactory();
    return new LlmEnhancer(
      $configFactory,
      $this->createStub(ClientInterface::class),
      $this->buildLoggerFactory($logger),
      new PolicyFilter($configFactory),
      $cache,
      $breaker,
      NULL,
      $costControl,
      NULL,
      $transport,
    );
  }

  /**
   * Builds an enhancer whose dispatch results are scripted.
   *
   * @param \Psr\Log\LoggerInterface $logger
   *   Logger mock the test sets expectations on.
   * @param \Drupal\ilas_site_assistant\Service\LlmCircuitBreaker|null $breaker
   *   Circuit breaker mock.
   * @param \Drupal\ilas_site_assistant\Service\CostControlPolicy|null $costControl
   *   Cost-control policy mock.
   * @param array<int, array<string, mixed>|\Throwable> $sequence
   *   Dispatch outcomes in order.
   */
  private function buildSequencedEnhancer(
    LoggerInterface $logger,
    ?LlmCircuitBreaker $breaker,
    ?CostControlPolicy $costControl,
    array $sequence,
  ): LlmEnhancer {
    $configFactory = $this->buildConfigFactory();
    return new AdmissionDenialSequencedEnhancer(
      $configFactory,
      $this->createStub(ClientInterface::class),
      $this->buildLoggerFactory($logger),
      new PolicyFilter($configFactory),
      NULL,
      $breaker,
      NULL,
      $costControl,
      NULL,
      new AdmissionDenialStaticTransport(),
      $sequence,
    );
  }

  /**
   * Builds the LLM config stub (cache and retries disabled).
   */
  private function buildConfigFactory(): ConfigFactoryInterface {
    $values = [
      'llm.enabled' => TRUE,
      'llm.max_tokens' => 150,
      'llm.temperature' => 0.3,
      'llm.fallback_on_error' => TRUE,
      'llm.safety_threshold' => 'BLOCK_MEDIUM_AND_ABOVE',
      'llm.cache_ttl' => 0,
      'llm.max_retries' => 0,
      'llm.circuit_breaker.cooldown_seconds' => 300,
    ];
    $config = $this->createStub(ImmutableConfig::class);
    $config->method('get')
      ->willReturnCallback(static fn(string $key): mixed => $values[$key] ?? NULL);
    $factory = $this->createStub(ConfigFactoryInterface::class);
    $factory->method('get')
      ->with('ilas_site_assistant.settings')
      ->willReturn($config);
    return $factory;
  }

  /**
   * Builds a logger mock that tests can set expectations on.
   */
  private function buildLogger(): LoggerInterface&MockObject {
    return $this->createMock(LoggerInterface::class);
  }

  /**
   * Wraps a logger in a channel factory stub.
   */
  private function buildLoggerFactory(LoggerInterface $logger): LoggerChannelFactoryInterface {
    $factory = $this->createStub(LoggerChannelFactoryInterface::class);
    $factory->method('get')->willReturn($logger);
    return $factory;
  }

  /**
   * Builds an in-memory cache backed by the supplied store.
   */
  private function buildCache(array &$store): CacheBackendInterface {
    $cache = $this->createMock(CacheBackendInterface::class);
    $cache->method('get')
      ->willReturnCallback(static function (string $cid) use (&$store): mixed {
        return array_key_exists($cid, $store) ? (object) ['data' => $store[$cid]] : FALSE;
      });
    $cache->method('set')
      ->willReturnCallback(static function (string $cid, mixed $data) use (&$store): void {
        $store[$cid] = $data;
      });
    return $cache;
  }

}

/**
 * Static transport that counts calls and returns a benign payload.
 */
final class AdmissionDenialStaticTransport implements RequestTimeLlmTransportInterface {

  /**
   * Number of times completeStructuredJson() was invoked.
   */
  public int $calls = 0;

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
  public function isConfigured(): bool {
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function completeStructuredJson(array $messages, array $schema, array $options = []): array {
    $this->calls++;
    return ['payload' => ['intent' => 'faq']];
  }

}

/**
 * Enhancer whose transport dispatch is scripted per call.
 */
final class AdmissionDenialSequencedEnhancer extends LlmEnhancer {

  /**
   * Scripted dispatch outcomes, consumed in order.
   *
   * @var array<int, array<string, mixed>|\Throwable>
   */
  private array $sequence;

  /**
   * Constructs the scripted enhancer.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   Config factory.
   * @param \GuzzleHttp\ClientInterface $httpClient
   *   HTTP client stub.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerFactory
   *   Logger channel factory.
   * @param \Drupal\ilas_site_assistant\Service\PolicyFilter|null $policyFilter
   *   Policy filter.
   * @param \Drupal\Core\Cache\CacheBackendInterface|null $cache
   *   Cache backend.
   * @param \Drupal\ilas_site_assistant\Service\LlmCircuitBreaker|null $circuitBreaker
   *   Circuit breaker.
   * @param \Drupal\ilas_site_assistant\Service\LlmRateLimiter|null $rateLimiter
   *   Rate limiter.
   * @param \Drupal\ilas_site_assistant\Service\CostControlPolicy|null $costControlPolicy
   *   Cost-control policy.
   * @param \Drupal\ilas_site_assistant\Service\EnvironmentDetector|null $environmentDetector
   *   Environment detector.
   * @param \Drupal\ilas_site_assistant\Service\RequestTimeLlmTransportInterface|null $transport
   *   Transport.
   * @param array<int, array<string, mixed>|\Throwable> $sequence
   *   Dispatch sequence.
   */
  public function __construct(
    ConfigFactoryInterface $configFactory,
    ClientInterface $httpClient,
    LoggerChannelFactoryInterface $loggerFactory,
    ?PolicyFilter $policyFilter,
    ?CacheBackendInterface $cache,
    ?LlmCircuitBreaker $circuitBreaker,
    ?LlmRateLimiter $rateLimiter,
    ?CostControlPolicy $costControlPolicy,
    ?EnvironmentDetector $environmentDetector,
    ?RequestTimeLlmTransportInterface $transport,
    array $sequence,
  ) {
    parent::__construct(
      $configFactory,
      $httpClient,
      $loggerFactory,
      $policyFilter,
      $cache,
      $circuitBreaker,
      $rateLimiter,
      $costControlPolicy,
      $environmentDetector,
      $transport,
    );
    $this->sequence = $sequence;
  }

  /**
   * {@inheritdoc}
   */
  protected function dispatchStructuredJsonRequest(array $messages, array $schema, array $options = []): array {
    $next = array_shift($this->sequence);
    if ($next instanceof \Throwable) {
      throw $next;
    }
    return is_array($next) ? $next : ['payload' => ['intent' => 'unknown']];
  }

  /**
   * {@inheritdoc}
   */
  protected function sleepMilliseconds(int $delayMs): void {
  }

}
