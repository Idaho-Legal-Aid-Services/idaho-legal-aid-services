<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\ilas_site_assistant\Exception\LlmAdmissionDeniedException;
use Drupal\ilas_site_assistant\Service\CostControlPolicy;
use Drupal\ilas_site_assistant\Service\LlmCircuitBreaker;
use Drupal\ilas_site_assistant\Service\LlmEvalTrafficPolicy;
use Drupal\ilas_site_assistant\Service\LlmRequestPipeline;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Unit coverage for the shared admission/cache/retry/breaker pipeline.
 */
#[Group('ilas_site_assistant')]
final class LlmRequestPipelineTest extends TestCase {

  /**
   * Admission denial throws the typed exception and never dispatches.
   */
  public function testAdmissionDenialThrowsWithoutDispatchOrBreakerFailure(): void {
    $policy = $this->createMock(CostControlPolicy::class);
    $policy->expects($this->once())
      ->method('beginRequest')
      ->with('global', [
        LlmEvalTrafficPolicy::OPTION_PER_IP_EXEMPT => FALSE,
        CostControlPolicy::OPTION_OPERATION => 'grounded_answer',
      ])
      ->willReturn(['allowed' => FALSE, 'reason' => 'per_ip_budget_exceeded']);
    $policy->expects($this->never())->method('recordUsage');
    $breaker = $this->createMock(LlmCircuitBreaker::class);
    $breaker->expects($this->never())->method('recordFailure');
    $breaker->expects($this->never())->method('recordSuccess');

    $pipeline = new LlmRequestPipeline($this->buildConfigFactory(), NULL, $breaker, $policy);
    $dispatched = FALSE;

    try {
      $pipeline->execute('grounded_answer', 'cid', function () use (&$dispatched): array {
        $dispatched = TRUE;
        return ['payload' => []];
      });
      $this->fail('Expected admission denial.');
    }
    catch (LlmAdmissionDeniedException $e) {
      $this->assertSame('per_ip_budget_exceeded', $e->getReason());
      $this->assertSame('budget_per_ip_budget_exceeded', $e->getFallbackReason());
    }

    $this->assertFalse($dispatched);
    $meta = $pipeline->getLastMeta();
    $this->assertFalse($meta['transport_attempted']);
    $this->assertFalse($meta['success']);
    $this->assertSame('per_ip_budget_exceeded', $meta['admission_reason']);
    $this->assertSame('budget_per_ip_budget_exceeded', $meta['fallback_reason']);
  }

  /**
   * A cache hit returns the cached payload and skips the transport.
   */
  public function testCacheHitSkipsTransportAndRecordsHit(): void {
    $policy = $this->createMock(CostControlPolicy::class);
    $policy->method('beginRequest')->willReturn(['allowed' => TRUE, 'reason' => 'allowed']);
    $policy->expects($this->once())->method('recordCacheHit');
    $policy->expects($this->never())->method('recordCacheMiss');
    $policy->expects($this->never())->method('recordUsage');

    $cache = $this->createMock(CacheBackendInterface::class);
    $cache->method('get')->with('cid')->willReturn((object) ['data' => ['text' => 'cached answer']]);
    $cache->expects($this->never())->method('set');

    $pipeline = new LlmRequestPipeline($this->buildConfigFactory(), $cache, NULL, $policy);
    $result = $pipeline->execute('grounded_answer', 'cid', function (): array {
      $this->fail('Transport must not be called on a cache hit.');
    });

    $this->assertSame(['text' => 'cached answer'], $result['payload']);
    $this->assertTrue($result['meta']['cache_hit']);
    $this->assertFalse($result['meta']['transport_attempted']);
    $this->assertTrue($result['meta']['success']);
  }

  /**
   * Success records usage only, caches, and marks breaker success.
   */
  public function testSuccessRecordsUsageOnlyAndCachesPayload(): void {
    $policy = $this->createMock(CostControlPolicy::class);
    $policy->method('beginRequest')->willReturn(['allowed' => TRUE, 'reason' => 'allowed']);
    $policy->expects($this->once())->method('recordCacheMiss');
    $policy->expects($this->once())
      ->method('recordUsage')
      ->with(['input' => 10, 'output' => 5, 'total' => 15], 'intent_classification');
    $policy->expects($this->never())->method('recordCall');

    $breaker = $this->createMock(LlmCircuitBreaker::class);
    $breaker->expects($this->once())->method('recordSuccess');
    $breaker->expects($this->never())->method('recordFailure');

    $cache = $this->createMock(CacheBackendInterface::class);
    $cache->method('get')->willReturn(FALSE);
    $cache->expects($this->once())
      ->method('set')
      ->with('cid', ['intent' => 'faq'], $this->greaterThan(time()));

    $pipeline = new LlmRequestPipeline($this->buildConfigFactory(), $cache, $breaker, $policy);
    $result = $pipeline->execute('intent_classification', 'cid', static fn(): array => [
      'payload' => ['intent' => 'faq'],
      'usage' => ['input' => 10, 'output' => 5, 'total' => 15],
    ], [LlmRequestPipeline::OPTION_USER_IDENTIFIER => '203.0.113.9']);

    $this->assertSame(['intent' => 'faq'], $result['payload']);
    $this->assertTrue($result['meta']['success']);
    $this->assertTrue($result['meta']['transport_attempted']);
    $this->assertSame(1, $result['meta']['attempt_number']);
    $this->assertSame(['input' => 10, 'output' => 5, 'total' => 15], $result['meta']['usage']);
  }

  /**
   * Retryable HTTP statuses are retried up to max_retries with backoff.
   */
  public function testRetryableStatusIsRetriedThenThrown(): void {
    $delays = [];
    $pipeline = new LlmRequestPipeline(
      $this->buildConfigFactory(['llm.max_retries' => 1]),
      NULL,
      NULL,
      NULL,
      static function (int $ms) use (&$delays): void {
        $delays[] = $ms;
      },
    );

    $attempts = 0;
    $exception = new RequestException('rate limited', new Request('POST', 'x'), new Response(429));
    try {
      $pipeline->execute('grounded_answer', 'cid', static function () use (&$attempts, $exception): array {
        $attempts++;
        throw $exception;
      });
      $this->fail('Expected RequestException.');
    }
    catch (RequestException $e) {
      $this->assertSame(429, $e->getResponse()?->getStatusCode());
    }

    $this->assertSame(2, $attempts, 'one retry after the initial attempt');
    $this->assertSame([50], $delays);
    $meta = $pipeline->getLastMeta();
    $this->assertSame(429, $meta['http_status']);
    $this->assertFalse($meta['success']);
    $this->assertSame(2, $meta['attempt_number']);
  }

  /**
   * Non-retryable statuses and max_retries=0 throw immediately.
   */
  public function testNonRetryableStatusAndZeroRetriesThrowImmediately(): void {
    $pipeline = new LlmRequestPipeline($this->buildConfigFactory(['llm.max_retries' => 3]));
    $attempts = 0;
    try {
      $pipeline->execute('grounded_answer', 'cid', static function () use (&$attempts): array {
        $attempts++;
        throw new RequestException('bad request', new Request('POST', 'x'), new Response(400));
      });
      $this->fail('Expected RequestException.');
    }
    catch (RequestException) {
    }
    $this->assertSame(1, $attempts);

    $attempts = 0;
    try {
      $pipeline->execute('grounded_answer', 'cid', static function () use (&$attempts): array {
        $attempts++;
        throw new ConnectException('timeout', new Request('POST', 'x'));
      }, [LlmRequestPipeline::OPTION_MAX_RETRIES => 0]);
      $this->fail('Expected ConnectException.');
    }
    catch (ConnectException) {
    }
    $this->assertSame(1, $attempts, 'per-call max_retries=0 overrides config');
  }

  /**
   * Budget keys hash identifiers and fall back to the global bucket.
   */
  public function testBudgetKeyDerivation(): void {
    $this->assertSame('global', LlmRequestPipeline::buildBudgetKey(NULL));
    $this->assertSame('global', LlmRequestPipeline::buildBudgetKey('  '));
    $this->assertSame(hash('sha256', '203.0.113.9'), LlmRequestPipeline::buildBudgetKey('203.0.113.9'));
    $this->assertTrue(LlmRequestPipeline::isRetryableStatusCode(503));
    $this->assertFalse(LlmRequestPipeline::isRetryableStatusCode(401));
    $this->assertSame(250, LlmRequestPipeline::backoffDelayMs(9));
  }

  /**
   * Builds a config factory with llm.* defaults and overrides.
   */
  private function buildConfigFactory(array $overrides = []): ConfigFactoryInterface {
    $values = $overrides + [
      'llm.cache_ttl' => 3600,
      'llm.max_retries' => 1,
    ];
    $config = $this->createStub(ImmutableConfig::class);
    $config->method('get')->willReturnCallback(static fn(string $key): mixed => $values[$key] ?? NULL);
    $factory = $this->createStub(ConfigFactoryInterface::class);
    $factory->method('get')->willReturn($config);
    return $factory;
  }

}
