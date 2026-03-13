<?php

namespace Drupal\Tests\ilas_site_assistant\Unit;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Lock\NullLockBackend;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\State\StateInterface;
use Drupal\ilas_site_assistant\Service\CostControlPolicy;
use Drupal\ilas_site_assistant\Service\LlmAdmissionCoordinator;
use Drupal\ilas_site_assistant\Service\LlmCircuitBreaker;
use Drupal\ilas_site_assistant\Service\LlmEnhancer;
use Drupal\ilas_site_assistant\Service\LlmRateLimiter;
use Drupal\ilas_site_assistant\Service\PolicyFilter;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests LLM cost/latency hardening: sanitization, caching, retry, config flags.
 */
#[Group('ilas_site_assistant')]
class LlmEnhancerHardeningTest extends TestCase {

  /**
   * Shared capture/control object for the testable subclass.
   *
   * @var \stdClass
   */
  private \stdClass $control;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->control = new \stdClass();
    $this->control->capturedPrompt = NULL;
    $this->control->apiCallCount = 0;
    $this->control->apiResponse = 'LLM test response';
    $this->control->apiException = NULL;
    $this->control->apiExceptionSequence = [];
    $this->control->sleepDelayHistory = [];
    $this->control->currentTime = 1700000000;
    $this->control->authHeaders = [];
    $this->control->vertexServiceAccountJson = '';
    $this->control->serviceAccountTokenSequence = [];
    $this->control->metadataTokenSequence = [];
    $this->control->serviceAccountTokenFetchCount = 0;
    $this->control->metadataTokenFetchCount = 0;
  }

  /**
   * Tests that the full system prompt reaches the API (not truncated to 100 chars).
   */
  public function testSanitizationPreservesSystemPrompt(): void {
    $enhancer = $this->buildEnhancer();

    // Call classifyIntent which composes a prompt from system prompt + user query.
    $enhancer->classifyIntent('what forms do you have', 'unknown');

    // The captured prompt must be longer than 100 chars (system prompt alone is ~1000+ chars).
    $this->assertNotNull($this->control->capturedPrompt, 'Prompt should be captured');
    $this->assertGreaterThan(100, strlen($this->control->capturedPrompt),
      'Full prompt must not be truncated to 100 chars');
    // It should contain system prompt text.
    $this->assertStringContainsString('Classify the user\'s intent', $this->control->capturedPrompt,
      'System prompt text must be present in captured prompt');
  }

  /**
   * Tests that a long user query is NOT truncated before reaching the LLM.
   *
   * Regression: sanitizeForStorage() truncates to 100 chars, which is wrong
   * for LLM prompt building. sanitizeForLlmPrompt() preserves full context.
   */
  public function testLongQueryPreservedInPrompt(): void {
    // Build a 300-char query — well beyond the 100-char storage limit.
    $longQuery = 'I need help understanding my rights as a tenant because my landlord has been '
      . 'refusing to make repairs to my apartment for several months now and I am wondering what '
      . 'steps I can take to address this situation and whether ILAS has any guides or resources '
      . 'that could help me understand the process';

    $this->assertGreaterThan(200, strlen($longQuery), 'Test query must be >200 chars');

    $enhancer = $this->buildEnhancer();
    $enhancer->classifyIntent($longQuery, 'unknown');

    $this->assertNotNull($this->control->capturedPrompt);
    // The full query text must appear in the prompt, not truncated to 100 chars.
    $this->assertStringContainsString('understand the process', $this->control->capturedPrompt,
      'End of long query must be present in the prompt (not truncated)');
    $this->assertStringContainsString('rights as a tenant', $this->control->capturedPrompt,
      'Beginning of long query must be present in the prompt');
  }

  /**
   * Tests that a cache hit skips the API call entirely.
   */
  public function testCacheHitSkipsApiCall(): void {
    $cacheObj = new \stdClass();
    $cacheObj->data = 'cached response';

    $cache = $this->createMock(CacheBackendInterface::class);
    $cache->method('get')->willReturn($cacheObj);

    $enhancer = $this->buildEnhancer(cache: $cache);
    $enhancer->classifyIntent('test query', 'unknown');

    $this->assertEquals(0, $this->control->apiCallCount,
      'API should not be called when cache returns a hit');
  }

  /**
   * Tests that a cache miss stores the result with correct TTL and tags.
   */
  public function testCacheMissStoresResult(): void {
    $cache = $this->createMock(CacheBackendInterface::class);
    $cache->method('get')->willReturn(FALSE);
    $cache->expects($this->once())
      ->method('set')
      ->with(
        $this->stringStartsWith('llm:'),
        'LLM test response',
        $this->greaterThan(time()),
        ['ilas_site_assistant:llm']
      );

    $enhancer = $this->buildEnhancer(cache: $cache);
    $enhancer->classifyIntent('test query', 'unknown');

    $this->assertEquals(1, $this->control->apiCallCount, 'API should be called once on cache miss');
  }

  /**
   * Tests that POLICY_VERSION is included in the cache key.
   *
   * Two enhancers with different POLICY_VERSIONs should produce different
   * cache keys for the same query, enabling manual cache invalidation.
   */
  public function testPolicyVersionInCacheKey(): void {
    $capturedKeys = [];

    $cache = $this->createMock(CacheBackendInterface::class);
    $cache->method('get')->willReturn(FALSE);
    $cache->method('set')
      ->willReturnCallback(function ($key) use (&$capturedKeys) {
        $capturedKeys[] = $key;
      });

    // Build enhancer with default POLICY_VERSION ('1.0').
    $enhancer = $this->buildEnhancer(cache: $cache);
    $enhancer->classifyIntent('test query for cache key', 'unknown');

    $this->assertCount(1, $capturedKeys, 'Should have captured one cache key');
    $keyV1 = $capturedKeys[0];

    // The cache key must start with 'llm:' prefix.
    $this->assertStringStartsWith('llm:', $keyV1);

    // Build enhancer with overridden POLICY_VERSION ('2.0').
    $capturedKeys = [];
    $this->control->apiCallCount = 0;

    $enhancerV2 = new PolicyVersionTestableEnhancer(
      $this->buildConfigFactory(),
      $this->createStub(ClientInterface::class),
      $this->buildLoggerFactory(),
      $this->buildPolicyFilter(),
      $cache,
      $this->control,
      '2.0'
    );
    $enhancerV2->classifyIntent('test query for cache key', 'unknown');

    $this->assertCount(1, $capturedKeys, 'Should have captured one cache key for v2');
    $keyV2 = $capturedKeys[0];

    // Keys must differ when policy version changes.
    $this->assertNotEquals($keyV1, $keyV2,
      'Cache keys must differ when POLICY_VERSION changes');
  }

  /**
   * Tests that high temperature (greetings at 0.7) bypasses cache entirely.
   */
  public function testHighTemperatureSkipsCache(): void {
    $cache = $this->createMock(CacheBackendInterface::class);
    // Cache should never be checked or written.
    $cache->expects($this->never())->method('get');
    $cache->expects($this->never())->method('set');

    $enhancer = $this->buildEnhancer(cache: $cache, configOverrides: [
      'llm.enhance_greetings' => TRUE,
    ]);
    $enhancer->generateGreeting('Hello there!');

    $this->assertEquals(1, $this->control->apiCallCount,
      'API should be called for high-temperature greeting');
  }

  /**
   * Tests retry on HTTP 429 then success on second attempt.
   */
  public function testRetryOn429(): void {
    // First call throws 429, second succeeds.
    $this->control->apiExceptionSequence = [
      new RequestException(
        'Rate limited',
        new Request('POST', 'https://example.com'),
        new Response(429)
      ),
    ];
    $this->control->apiResponse = 'success after retry';

    $enhancer = $this->buildEnhancer(useRealMakeApiRequest: TRUE);

    // Use reflection to call makeApiRequest directly.
    $ref = new \ReflectionMethod($enhancer, 'makeApiRequest');
    $ref->setAccessible(TRUE);

    $result = $ref->invoke($enhancer, 'https://example.com/api', [
      'contents' => [['parts' => [['text' => 'test']]]],
    ]);

    $this->assertEquals('success after retry', $result);
    // httpClient should have been called twice (1 failure + 1 success).
    $this->assertEquals(2, $this->control->apiCallCount);
  }

  /**
   * Tests retry on HTTP 503 (service unavailable).
   */
  public function testRetryOn503(): void {
    $this->control->apiExceptionSequence = [
      new RequestException(
        'Service unavailable',
        new Request('POST', 'https://example.com'),
        new Response(503)
      ),
    ];
    $this->control->apiResponse = 'recovered';

    $enhancer = $this->buildEnhancer(useRealMakeApiRequest: TRUE);

    $ref = new \ReflectionMethod($enhancer, 'makeApiRequest');
    $ref->setAccessible(TRUE);

    $result = $ref->invoke($enhancer, 'https://example.com/api', [
      'contents' => [['parts' => [['text' => 'test']]]],
    ]);

    $this->assertEquals('recovered', $result);
    $this->assertEquals(2, $this->control->apiCallCount);
  }

  /**
   * Tests retry on HTTP 500 (server error).
   */
  public function testRetryOn500(): void {
    $this->control->apiExceptionSequence = [
      new RequestException(
        'Internal server error',
        new Request('POST', 'https://example.com'),
        new Response(500)
      ),
    ];
    $this->control->apiResponse = 'recovered after 500';

    $enhancer = $this->buildEnhancer(useRealMakeApiRequest: TRUE);

    $ref = new \ReflectionMethod($enhancer, 'makeApiRequest');
    $ref->setAccessible(TRUE);

    $result = $ref->invoke($enhancer, 'https://example.com/api', [
      'contents' => [['parts' => [['text' => 'test']]]],
    ]);

    $this->assertEquals('recovered after 500', $result);
    $this->assertEquals(2, $this->control->apiCallCount);
  }

  /**
   * Tests that HTTP 400 does not retry and throws immediately.
   */
  public function testNoRetryOn400(): void {
    $this->control->apiExceptionSequence = [
      new RequestException(
        'Bad request',
        new Request('POST', 'https://example.com'),
        new Response(400)
      ),
    ];

    $enhancer = $this->buildEnhancer(useRealMakeApiRequest: TRUE);

    $ref = new \ReflectionMethod($enhancer, 'makeApiRequest');
    $ref->setAccessible(TRUE);

    $this->expectException(\Exception::class);
    $this->expectExceptionMessage('API request failed');

    $ref->invoke($enhancer, 'https://example.com/api', [
      'contents' => [['parts' => [['text' => 'test']]]],
    ]);

    // Should only have been called once (no retry on 400).
    $this->assertEquals(1, $this->control->apiCallCount);
  }

  /**
   * Tests transport timeout/no-status failures do not retry.
   */
  public function testNoRetryOnTransportTimeout(): void {
    $this->control->apiExceptionSequence = [
      new RequestException(
        'cURL error 28: Operation timed out',
        new Request('POST', 'https://example.com')
      ),
    ];

    $enhancer = $this->buildEnhancer(useRealMakeApiRequest: TRUE);

    $ref = new \ReflectionMethod($enhancer, 'makeApiRequest');
    $ref->setAccessible(TRUE);

    $this->expectException(\Exception::class);
    $this->expectExceptionMessage('API request failed');

    $ref->invoke($enhancer, 'https://example.com/api', [
      'contents' => [['parts' => [['text' => 'test']]]],
    ]);

    // No HTTP status means no retry path is eligible.
    $this->assertEquals(1, $this->control->apiCallCount);
  }

  /**
   * Tests that retries are bounded by max_retries config.
   */
  public function testRetriesBoundedByConfig(): void {
    // Set max_retries to 1, send 2 failures — second should not retry.
    $this->control->apiExceptionSequence = [
      new RequestException(
        'Rate limited',
        new Request('POST', 'https://example.com'),
        new Response(429)
      ),
      new RequestException(
        'Rate limited again',
        new Request('POST', 'https://example.com'),
        new Response(429)
      ),
    ];

    $enhancer = $this->buildEnhancer(
      useRealMakeApiRequest: TRUE,
      configOverrides: ['llm.max_retries' => 1],
    );

    $ref = new \ReflectionMethod($enhancer, 'makeApiRequest');
    $ref->setAccessible(TRUE);

    $this->expectException(\Exception::class);

    $ref->invoke($enhancer, 'https://example.com/api', [
      'contents' => [['parts' => [['text' => 'test']]]],
    ]);

    // First attempt + 1 retry = 2 calls total, then gives up.
    $this->assertEquals(2, $this->control->apiCallCount);
  }

  /**
   * Tests the default retry budget only allows one retry.
   */
  public function testDefaultRetryBudgetAllowsSingleRetryOnly(): void {
    $this->control->apiExceptionSequence = [
      new RequestException(
        'Service unavailable',
        new Request('POST', 'https://example.com'),
        new Response(503)
      ),
      new RequestException(
        'Service unavailable again',
        new Request('POST', 'https://example.com'),
        new Response(503)
      ),
    ];

    $enhancer = $this->buildEnhancer(
      useRealMakeApiRequest: TRUE,
      captureRetryDelay: TRUE,
    );

    $ref = new \ReflectionMethod($enhancer, 'makeApiRequest');
    $ref->setAccessible(TRUE);

    $this->expectException(\Exception::class);
    $ref->invoke($enhancer, 'https://example.com/api', [
      'contents' => [['parts' => [['text' => 'test']]]],
    ]);

    $this->assertEquals(2, $this->control->apiCallCount);
    $this->assertCount(1, $this->control->sleepDelayHistory);
  }

  /**
   * Tests retry delays are capped for synchronous worker safety.
   */
  public function testRetryDelayIsCappedAt250Milliseconds(): void {
    $this->control->apiExceptionSequence = [
      new RequestException(
        'Rate limited',
        new Request('POST', 'https://example.com'),
        new Response(429)
      ),
    ];
    $this->control->apiResponse = 'bounded retry success';

    $enhancer = $this->buildEnhancer(
      useRealMakeApiRequest: TRUE,
      captureRetryDelay: TRUE,
    );

    $ref = new \ReflectionMethod($enhancer, 'makeApiRequest');
    $ref->setAccessible(TRUE);

    $result = $ref->invoke($enhancer, 'https://example.com/api', [
      'contents' => [['parts' => [['text' => 'test']]]],
    ]);

    $this->assertSame('bounded retry success', $result);
    $this->assertCount(1, $this->control->sleepDelayHistory);
    $this->assertLessThanOrEqual(250, $this->control->sleepDelayHistory[0]);
  }

  /**
   * Tests Vertex tokens are reused across enhancer instances until expiry.
   */
  public function testVertexAccessTokenCachedAcrossInstancesUsingServiceAccount(): void {
    $cache = $this->buildSharedCacheBackend();
    $this->control->vertexServiceAccountJson = '{"private_key":"runtime-key","client_email":"bot@example.com"}';
    $this->control->serviceAccountTokenSequence = [
      [
        'access_token' => 'shared-service-account-token',
        'expires_at' => $this->control->currentTime + 3600,
        'cache_ttl' => 3500,
      ],
    ];

    $first = $this->buildVertexEnhancer(cache: $cache);
    $second = $this->buildVertexEnhancer(cache: $cache);

    $ref = new \ReflectionMethod($first, 'callVertexAi');
    $ref->setAccessible(TRUE);

    $firstResult = $ref->invoke($first, 'Prompt one', ['max_tokens' => 20]);
    $secondResult = $ref->invoke($second, 'Prompt two', ['max_tokens' => 20]);

    $this->assertSame('LLM test response', $firstResult);
    $this->assertSame('LLM test response', $secondResult);
    $this->assertSame(1, $this->control->serviceAccountTokenFetchCount);
    $this->assertSame(0, $this->control->metadataTokenFetchCount);
    $this->assertSame(
      ['Bearer shared-service-account-token', 'Bearer shared-service-account-token'],
      $this->control->authHeaders
    );
  }

  /**
   * Tests cached Vertex tokens refresh after their buffered cache window expires.
   */
  public function testVertexAccessTokenRefreshesAfterBufferedExpiryUsingMetadataServer(): void {
    $cache = $this->buildSharedCacheBackend();
    $this->control->metadataTokenSequence = [
      [
        'access_token' => 'metadata-token-one',
        'expires_at' => $this->control->currentTime + 200,
        'cache_ttl' => 100,
      ],
      [
        'access_token' => 'metadata-token-two',
        'expires_at' => $this->control->currentTime + 4000,
        'cache_ttl' => 3500,
      ],
    ];

    $enhancer = $this->buildVertexEnhancer(cache: $cache);
    $ref = new \ReflectionMethod($enhancer, 'callVertexAi');
    $ref->setAccessible(TRUE);

    $firstResult = $ref->invoke($enhancer, 'Prompt one', ['max_tokens' => 20]);
    $this->control->currentTime += 101;
    $secondResult = $ref->invoke($enhancer, 'Prompt two', ['max_tokens' => 20]);

    $this->assertSame('LLM test response', $firstResult);
    $this->assertSame('LLM test response', $secondResult);
    $this->assertSame(2, $this->control->metadataTokenFetchCount);
    $this->assertSame(0, $this->control->serviceAccountTokenFetchCount);
    $this->assertSame(
      ['Bearer metadata-token-one', 'Bearer metadata-token-two'],
      $this->control->authHeaders
    );
  }

  /**
   * Tests that FAQ response enhancement is fully disabled.
   */
  public function testFaqResponseEnhancementIsDisabled(): void {
    $enhancer = $this->buildEnhancer();

    $response = [
      'type' => 'faq',
      'results' => [['question' => 'Q?', 'answer' => 'A.']],
    ];

    $result = $enhancer->enhanceResponse($response, 'test question');

    $this->assertSame(0, $this->control->apiCallCount, 'FAQ response path must remain deterministic');
    $this->assertSame($response, $result, 'FAQ responses should pass through unchanged');
  }

  /**
   * Tests that resource response enhancement is fully disabled.
   */
  public function testResourceResponseEnhancementIsDisabled(): void {
    $enhancer = $this->buildEnhancer();

    $response = [
      'type' => 'resources',
      'results' => [['title' => 'Resource 1', 'type' => 'guide']],
    ];

    $result = $enhancer->enhanceResponse($response, 'test question');

    $this->assertSame(0, $this->control->apiCallCount, 'Resource response path must remain deterministic');
    $this->assertSame($response, $result, 'Resource responses should pass through unchanged');
  }

  /**
   * Tests that escalation/error responses are never sent to the LLM.
   */
  public function testEscalationResponseSkipsLlm(): void {
    $enhancer = $this->buildEnhancer();

    $response = [
      'type' => 'escalation',
      'message' => 'Please call our hotline.',
    ];

    $result = $enhancer->enhanceResponse($response, 'test');

    $this->assertSame(0, $this->control->apiCallCount, 'API should not be called for escalation responses');
    $this->assertSame($response, $result);
  }

  /**
   * Tests classifyIntent returns deterministic fallback when LLM is disabled.
   */
  public function testClassifyIntentFallbackWhenDisabled(): void {
    $enhancer = $this->buildEnhancer(configOverrides: [
      'llm.enabled' => FALSE,
    ]);

    $result = $enhancer->classifyIntent('some query', 'unknown');

    $this->assertEquals('unknown', $result, 'Should return original intent when LLM disabled');
    $this->assertEquals(0, $this->control->apiCallCount);
  }

  /**
   * Tests unavailable dependency (missing API key) keeps classifyIntent deterministic.
   */
  public function testClassifyIntentFallbackWhenApiKeyMissing(): void {
    $enhancer = $this->buildEnhancer(configOverrides: [
      'llm.enabled' => TRUE,
      'llm.provider' => 'gemini_api',
      'llm.api_key' => '',
    ]);

    $result = $enhancer->classifyIntent('some query', 'unknown');

    $this->assertEquals('unknown', $result);
    $this->assertEquals(0, $this->control->apiCallCount);
  }

  /**
   * Tests classifyIntent skips LLM when intent is already known.
   */
  public function testClassifyIntentSkipsLlmForKnownIntent(): void {
    $enhancer = $this->buildEnhancer();

    $result = $enhancer->classifyIntent('test', 'housing');

    $this->assertEquals('housing', $result, 'Should return existing intent without calling LLM');
    $this->assertEquals(0, $this->control->apiCallCount);
  }

  /**
   * Tests Spanish intent classification prompts add a language instruction.
   */
  public function testSpanishClassifyIntentPromptUsesSpanishInstruction(): void {
    $this->control->apiResponse = 'apply';
    $enhancer = $this->buildEnhancer();

    $result = $enhancer->classifyIntent('Como aplico para ayuda legal', 'unknown');

    $this->assertSame('apply', $result);
    $this->assertNotNull($this->control->capturedPrompt);
    $this->assertStringContainsString(
      'The user wrote in Spanish',
      $this->control->capturedPrompt
    );
    $this->assertStringContainsString(
      'respond with ONLY one canonical English category name',
      $this->control->capturedPrompt
    );
  }

  /**
   * Tests mixed-language intent classification prompts preserve English labels.
   */
  public function testMixedLanguageClassifyIntentPromptUsesMixedInstruction(): void {
    $this->control->apiResponse = 'offices';
    $enhancer = $this->buildEnhancer();

    $result = $enhancer->classifyIntent('Where is the oficina in Boise', 'unknown');

    $this->assertSame('offices', $result);
    $this->assertNotNull($this->control->capturedPrompt);
    $this->assertStringContainsString(
      'The user mixed English and Spanish',
      $this->control->capturedPrompt
    );
    $this->assertStringContainsString(
      'canonical English category name',
      $this->control->capturedPrompt
    );
  }

  /**
   * Tests Spanish labels are rejected so classifyIntent stays canonical.
   */
  public function testSpanishClassifyIntentRejectsNonCanonicalLabel(): void {
    $this->control->apiResponse = 'aplicar';
    $enhancer = $this->buildEnhancer();

    $result = $enhancer->classifyIntent('Como aplico para ayuda legal', 'unknown');

    $this->assertSame('unknown', $result);
    $this->assertSame(1, $this->control->apiCallCount);
  }

  /**
   * Tests that an open circuit breaker skips the API call.
   */
  public function testCircuitBreakerOpenSkipsApiCall(): void {
    $circuitBreaker = $this->createMock(LlmCircuitBreaker::class);
    $circuitBreaker->method('isAvailable')->willReturn(FALSE);
    $circuitBreaker->expects($this->never())->method('recordSuccess');

    $enhancer = $this->buildEnhancer(circuitBreaker: $circuitBreaker);
    $result = $enhancer->classifyIntent('what forms do you have', 'unknown');

    $this->assertSame('unknown', $result);
    $this->assertSame(0, $this->control->apiCallCount,
      'API should not be called when circuit breaker is open');
  }

  /**
   * Tests that a NULL circuit breaker is handled gracefully (backward compat).
   */
  public function testCircuitBreakerNullSkipped(): void {
    // Build enhancer without circuit breaker (default NULL).
    $enhancer = $this->buildEnhancer();

    $result = $enhancer->classifyIntent('what forms do you have', 'unknown');

    $this->assertEquals(1, $this->control->apiCallCount,
      'API should be called normally when circuit breaker is NULL');
  }

  /**
   * Tests that an exhausted rate limiter skips the API call.
   */
  public function testRateLimiterExhaustedSkipsApiCall(): void {
    $rateLimiter = $this->createMock(LlmRateLimiter::class);
    $rateLimiter->method('isAllowed')->willReturn(FALSE);
    $rateLimiter->expects($this->never())->method('recordCall');

    $enhancer = $this->buildEnhancer(rateLimiter: $rateLimiter);
    $result = $enhancer->classifyIntent('what forms do you have', 'unknown');

    $this->assertSame('unknown', $result);
    $this->assertSame(0, $this->control->apiCallCount,
      'API should not be called when rate limiter is exhausted');
  }

  /**
   * Tests that a NULL rate limiter is handled gracefully (backward compat).
   */
  public function testRateLimiterNullSkipped(): void {
    // Build enhancer without rate limiter (default NULL).
    $enhancer = $this->buildEnhancer();

    $result = $enhancer->classifyIntent('what forms do you have', 'unknown');

    $this->assertEquals(1, $this->control->apiCallCount,
      'API should be called normally when rate limiter is NULL');
  }

  /**
   * Tests that beginRequest denial blocks the API call.
   */
  public function testCostControlBeginRequestBlocksApiCall(): void {
    $policy = $this->createMock(CostControlPolicy::class);
    $policy->expects($this->once())
      ->method('recordCacheMiss');
    $policy->expects($this->once())
      ->method('beginRequest')
      ->willReturn(['allowed' => FALSE, 'reason' => 'circuit_breaker_open']);
    $policy->expects($this->never())
      ->method('recordCall');

    $enhancer = $this->buildEnhancer(costControlPolicy: $policy);
    $result = $enhancer->classifyIntent('what forms do you have', 'unknown');

    $this->assertSame('unknown', $result);
    $this->assertSame(0, $this->control->apiCallCount,
      'API should not be called when beginRequest denies admission');
  }

  /**
   * Tests that beginRequest replaces legacy post-call cost accounting.
   */
  public function testCostControlBeginRequestReplacesLegacyRecordCall(): void {
    $policy = $this->createMock(CostControlPolicy::class);
    $policy->expects($this->once())
      ->method('recordCacheMiss');
    $policy->expects($this->once())
      ->method('beginRequest')
      ->willReturn(['allowed' => TRUE, 'reason' => 'allowed']);
    $policy->expects($this->never())
      ->method('recordCall');

    $enhancer = $this->buildEnhancer(costControlPolicy: $policy);
    $this->control->apiResponse = 'faq';
    $result = $enhancer->classifyIntent('what forms do you have', 'unknown');

    $this->assertSame('faq', $result);
    $this->assertSame(1, $this->control->apiCallCount,
      'API should be called once when beginRequest allows admission');
  }

  /**
   * Tests that classifyIntent passes the trusted budget identity to cost control.
   */
  public function testCostControlBeginRequestReceivesBudgetIdentity(): void {
    $policy = $this->createMock(CostControlPolicy::class);
    $policy->expects($this->once())
      ->method('recordCacheMiss');
    $policy->expects($this->never())
      ->method('recordCacheHit');
    $policy->expects($this->once())
      ->method('beginRequest')
      ->with('198.51.100.10')
      ->willReturn(['allowed' => TRUE, 'reason' => 'allowed']);

    $enhancer = $this->buildEnhancer(costControlPolicy: $policy);
    $this->control->apiResponse = 'offices';

    $result = $enhancer->classifyIntent('Where is the Boise office?', 'unknown', '198.51.100.10');

    $this->assertSame('offices', $result);
    $this->assertSame(1, $this->control->apiCallCount);
  }

  /**
   * Tests cache-effectiveness acceptance with normalized intent fingerprints.
   */
  public function testIntentClassificationCacheEffectivenessAcceptanceProof(): void {
    $cache = $this->buildSharedCacheBackend();
    $policy = $this->buildRealCostControlPolicy([
      'cost_control.cache_hit_rate_target' => 0.30,
      'cost_control.per_ip_hourly_call_limit' => 10,
      'cost_control.per_ip_window_seconds' => 3600,
      'cost_control.daily_call_limit' => 0,
      'cost_control.monthly_call_limit' => 0,
      'llm.global_rate_limit.max_per_hour' => 0,
    ]);
    $enhancer = $this->buildEnhancer(cache: $cache, costControlPolicy: $policy);
    $this->control->apiResponse = 'offices';

    $queries = [
      'Boise office location',
      'location office Boise',
      'Office location in Boise',
      'Boise office location please',
      'boise office, location',
      'office location boise',
      'Boise office location?',
      'Office, location, Boise',
      'BOISE office location',
      'office in Boise location',
    ];

    foreach ($queries as $query) {
      $this->assertSame('offices', $enhancer->classifyIntent($query, 'unknown', '198.51.100.10'));
    }

    $summary = $policy->getSummary();
    $cacheHitRate = (float) ($summary['cache_hit_rate'] ?? 0.0);
    $callReductionRate = 1 - ($this->control->apiCallCount / count($queries));

    $this->assertCount(10, $queries, 'Acceptance proof requires a corpus of at least 10 requests.');
    $this->assertSame(10, $summary['cache_requests']);
    $this->assertSame(9, $summary['cache_hits']);
    $this->assertSame(1, $summary['cache_misses']);
    $this->assertGreaterThanOrEqual($summary['cache_hit_rate_target'], $cacheHitRate);
    $this->assertGreaterThanOrEqual($summary['cache_hit_rate_target'], $callReductionRate);
    $this->assertSame(1, $this->control->apiCallCount, 'Normalized intent cache should collapse the corpus to one external call.');
  }

  /**
   * Tests repeated enhanceResponse calls preserve deterministic response class.
   */
  public function testEnhanceResponsePreservesResponseClassDeterministically(): void {
    $enhancer = $this->buildEnhancer();

    $response = [
      'type' => 'faq',
      'results' => [['question' => 'Q?', 'answer' => 'A.']],
    ];

    $first = $enhancer->enhanceResponse($response, 'test question');
    $second = $enhancer->enhanceResponse($response, 'test question');

    $this->assertEquals('faq', $first['type']);
    $this->assertEquals('faq', $second['type']);
    $this->assertSame(0, $this->control->apiCallCount);
    $this->assertSame($response, $first);
    $this->assertSame($response, $second);
  }

  /**
   * Tests live environment hard-disables LLM even when config is enabled.
   */
  public function testLiveEnvironmentHardDisablesLlmDespiteEnabledConfig(): void {
    $originalPantheon = getenv('PANTHEON_ENVIRONMENT');
    $hadPantheonInEnv = array_key_exists('PANTHEON_ENVIRONMENT', $_ENV);
    $originalPantheonEnv = $_ENV['PANTHEON_ENVIRONMENT'] ?? NULL;

    try {
      putenv('PANTHEON_ENVIRONMENT=live');
      $_ENV['PANTHEON_ENVIRONMENT'] = 'live';

      $enhancer = $this->buildEnhancer(configOverrides: [
        'llm.enabled' => TRUE,
        'llm.api_key' => 'test-api-key',
      ]);
      $result = $enhancer->classifyIntent('what forms do you have', 'unknown');

      $this->assertFalse($enhancer->isEnabled(), 'Live environment must force LLM disabled');
      $this->assertSame('unknown', $result, 'Live guard must preserve deterministic classification fallback');
      $this->assertSame(0, $this->control->apiCallCount, 'Live guard must prevent LLM API calls');
    }
    finally {
      if ($originalPantheon === FALSE) {
        putenv('PANTHEON_ENVIRONMENT');
      }
      else {
        putenv("PANTHEON_ENVIRONMENT={$originalPantheon}");
      }

      if ($hadPantheonInEnv) {
        $_ENV['PANTHEON_ENVIRONMENT'] = $originalPantheonEnv;
      }
      else {
        unset($_ENV['PANTHEON_ENVIRONMENT']);
      }
    }
  }

  /**
   * Builds the config factory with given overrides.
   */
  private function buildConfigFactory(array $overrides = []): ConfigFactoryInterface {
    $configValues = [
      'llm.enabled' => TRUE,
      'llm.provider' => 'gemini_api',
      'llm.api_key' => 'test-api-key',
      'llm.model' => 'gemini-1.5-flash',
      'llm.max_tokens' => 150,
      'llm.temperature' => 0.3,
      'llm.enhance_greetings' => FALSE,
      'llm.fallback_on_error' => TRUE,
      'llm.safety_threshold' => 'BLOCK_MEDIUM_AND_ABOVE',
      'llm.cache_ttl' => 3600,
      'llm.max_retries' => 1,
      'llm.global_rate_limit.max_per_hour' => 500,
      'llm.global_rate_limit.window_seconds' => 3600,
      'llm.circuit_breaker.failure_threshold' => 3,
      'llm.circuit_breaker.failure_window_seconds' => 60,
      'llm.circuit_breaker.cooldown_seconds' => 300,
      'cost_control.daily_call_limit' => 5000,
      'cost_control.monthly_call_limit' => 100000,
      'cost_control.per_ip_hourly_call_limit' => 10,
      'cost_control.per_ip_window_seconds' => 3600,
      'cost_control.sample_rate' => 1.0,
      'cost_control.cache_hit_rate_target' => 0.30,
      'cost_control.cache_stats_window_seconds' => 86400,
      'cost_control.alert_cooldown_minutes' => 60,
    ];

    foreach ($overrides as $key => $value) {
      $configValues[$key] = $value;
    }

    $config = $this->createStub(ImmutableConfig::class);
    $config->method('get')
      ->willReturnCallback(fn($key) => $configValues[$key] ?? NULL);

    $configFactory = $this->createStub(ConfigFactoryInterface::class);
    $configFactory->method('get')->willReturn($config);

    return $configFactory;
  }

  /**
   * Builds the logger factory stub.
   */
  private function buildLoggerFactory(): LoggerChannelFactoryInterface {
    $logger = $this->createStub(LoggerInterface::class);
    $loggerFactory = $this->createStub(LoggerChannelFactoryInterface::class);
    $loggerFactory->method('get')->willReturn($logger);
    return $loggerFactory;
  }

  /**
   * Builds the PolicyFilter stub.
   */
  private function buildPolicyFilter(): PolicyFilter {
    $policyFilter = $this->createStub(PolicyFilter::class);
    $policyFilter->method('sanitizeForStorage')
      ->willReturnCallback(fn($q) => $q);
    $policyFilter->method('sanitizeForLlmPrompt')
      ->willReturnCallback(fn($q) => $q);
    return $policyFilter;
  }

  /**
   * Builds a testable LlmEnhancer with mocked dependencies.
   *
   * @param \Drupal\Core\Cache\CacheBackendInterface|null $cache
   *   Optional cache mock. NULL = no cache backend.
   * @param array $configOverrides
   *   Config values to override from defaults.
   * @param bool $useRealMakeApiRequest
   *   If TRUE, uses the real makeApiRequest with a mock HTTP client
   *   (for testing retry logic). If FALSE, overrides makeApiRequest entirely.
   *
   * @return \Drupal\ilas_site_assistant\Service\LlmEnhancer
   *   The testable enhancer.
   */
  private function buildEnhancer(
    ?CacheBackendInterface $cache = NULL,
    array $configOverrides = [],
    bool $useRealMakeApiRequest = FALSE,
    bool $captureRetryDelay = FALSE,
    ?LlmCircuitBreaker $circuitBreaker = NULL,
    ?LlmRateLimiter $rateLimiter = NULL,
    ?CostControlPolicy $costControlPolicy = NULL,
  ): LlmEnhancer {
    $configFactory = $this->buildConfigFactory($configOverrides);
    $loggerFactory = $this->buildLoggerFactory();
    $policyFilter = $this->buildPolicyFilter();

    if ($useRealMakeApiRequest) {
      if ($captureRetryDelay) {
        return new CapturingRetryTestableEnhancer(
          $configFactory,
          $this->buildMockHttpClient(),
          $loggerFactory,
          $policyFilter,
          $cache,
          $this->control,
          $circuitBreaker,
          $rateLimiter,
          $costControlPolicy,
        );
      }

      return new RetryTestableEnhancer(
        $configFactory,
        $this->buildMockHttpClient(),
        $loggerFactory,
        $policyFilter,
        $cache,
        $this->control,
        $circuitBreaker,
        $rateLimiter,
        $costControlPolicy,
      );
    }

    return new HardeningTestableEnhancer(
      $configFactory,
      $this->createStub(ClientInterface::class),
      $loggerFactory,
      $policyFilter,
      $cache,
      $this->control,
      $circuitBreaker,
      $rateLimiter,
      $costControlPolicy,
    );
  }

  /**
   * Builds a Vertex transport double with shared cache support.
   */
  private function buildVertexEnhancer(
    ?CacheBackendInterface $cache = NULL,
    array $configOverrides = [],
  ): LlmEnhancer {
    $configFactory = $this->buildConfigFactory([
      'llm.provider' => 'vertex_ai',
      'llm.project_id' => 'test-project',
      ...$configOverrides,
    ]);

    return new VertexTransportTestableEnhancer(
      $configFactory,
      $this->createStub(ClientInterface::class),
      $this->buildLoggerFactory(),
      $this->buildPolicyFilter(),
      $cache,
      $this->control,
    );
  }

  /**
   * Builds a mock HTTP client that replays apiExceptionSequence then succeeds.
   */
  private function buildMockHttpClient(): ClientInterface {
    $control = $this->control;
    $client = $this->createMock(ClientInterface::class);
    $client->method('request')
      ->willReturnCallback(function () use ($control) {
        $control->apiCallCount++;
        if (!empty($control->apiExceptionSequence)) {
          throw array_shift($control->apiExceptionSequence);
        }
        $body = json_encode([
          'candidates' => [
            [
              'content' => [
                'parts' => [
                  ['text' => $control->apiResponse],
                ],
              ],
            ],
          ],
        ]);
        $response = new Response(200, [], $body);
        return $response;
      });

    return $client;
  }

  /**
   * Builds a shared in-memory cache backend driven by the test clock.
   */
  private function buildSharedCacheBackend(): CacheBackendInterface {
    $entries = [];
    $control = $this->control;

    $cache = $this->createMock(CacheBackendInterface::class);
    $cache->method('get')
      ->willReturnCallback(function (string $cid) use (&$entries, $control) {
        if (!isset($entries[$cid])) {
          return FALSE;
        }

        $entry = $entries[$cid];
        if ($entry['expire'] !== Cache::PERMANENT && $entry['expire'] <= $control->currentTime) {
          unset($entries[$cid]);
          return FALSE;
        }

        $cacheItem = new \stdClass();
        $cacheItem->data = $entry['data'];
        return $cacheItem;
      });
    $cache->method('set')
      ->willReturnCallback(function (string $cid, mixed $data, int $expire = Cache::PERMANENT, array $tags = []) use (&$entries) {
        $entries[$cid] = [
          'data' => $data,
          'expire' => $expire,
          'tags' => $tags,
        ];
      });

    return $cache;
  }

  /**
   * Builds a real cost-control policy backed by an in-memory state store.
   */
  private function buildRealCostControlPolicy(array $configOverrides = []): CostControlPolicy {
    $stateStore = [];
    $state = $this->createMock(StateInterface::class);
    $state->method('get')
      ->willReturnCallback(function (string $key, $default = NULL) use (&$stateStore) {
        return $stateStore[$key] ?? $default;
      });
    $state->method('set')
      ->willReturnCallback(function (string $key, $value) use (&$stateStore): void {
        $stateStore[$key] = $value;
      });

    $configFactory = $this->buildConfigFactory($configOverrides);
    $logger = $this->createStub(LoggerInterface::class);
    $circuitBreaker = $this->createStub(LlmCircuitBreaker::class);
    $rateLimiter = $this->createStub(LlmRateLimiter::class);
    $coordinator = new LlmAdmissionCoordinator($state, $configFactory, $logger, new NullLockBackend());

    return new CostControlPolicy($state, $configFactory, $logger, $circuitBreaker, $rateLimiter, $coordinator);
  }

}

/**
 * Test double that overrides makeApiRequest for most tests.
 */
class HardeningTestableEnhancer extends LlmEnhancer {

  protected \stdClass $control;

  public function __construct(
    $config_factory,
    $http_client,
    $logger_factory,
    $policy_filter,
    $cache,
    \stdClass $control,
    $circuit_breaker = NULL,
    $rate_limiter = NULL,
    $cost_control_policy = NULL,
  ) {
    parent::__construct($config_factory, $http_client, $logger_factory, $policy_filter, $cache, $circuit_breaker, $rate_limiter, $cost_control_policy);
    $this->control = $control;
  }

  protected function makeApiRequest(string $url, array $payload, array $headers = []): string {
    $this->control->apiCallCount++;
    $this->control->capturedPrompt = $payload['contents'][0]['parts'][0]['text'] ?? NULL;

    if ($this->control->apiException) {
      throw $this->control->apiException;
    }

    return $this->control->apiResponse;
  }

}

/**
 * Test double that uses real makeApiRequest (for retry tests) but with mock HTTP client.
 */
class RetryTestableEnhancer extends LlmEnhancer {

  protected \stdClass $control;

  public function __construct(
    $config_factory,
    $http_client,
    $logger_factory,
    $policy_filter,
    $cache,
    \stdClass $control,
    $circuit_breaker = NULL,
    $rate_limiter = NULL,
    $cost_control_policy = NULL,
  ) {
    parent::__construct($config_factory, $http_client, $logger_factory, $policy_filter, $cache, $circuit_breaker, $rate_limiter, $cost_control_policy);
    $this->control = $control;
  }

  // Uses real makeApiRequest — the mock HTTP client handles sequencing.

}

/**
 * Retry test double that captures backoff delays without sleeping.
 */
class CapturingRetryTestableEnhancer extends RetryTestableEnhancer {

  protected function sleepMilliseconds(int $delayMs): void {
    $this->control->sleepDelayHistory[] = $delayMs;
  }

}

/**
 * Vertex transport double with controllable token sources and clock.
 */
class VertexTransportTestableEnhancer extends LlmEnhancer {

  protected \stdClass $control;

  public function __construct(
    $config_factory,
    $http_client,
    $logger_factory,
    $policy_filter,
    $cache,
    \stdClass $control,
  ) {
    parent::__construct($config_factory, $http_client, $logger_factory, $policy_filter, $cache, NULL, NULL, NULL);
    $this->control = $control;
  }

  protected function getVertexServiceAccountJson(): string {
    return $this->control->vertexServiceAccountJson;
  }

  protected function getTokenFromServiceAccount(string $json): array {
    $this->control->serviceAccountTokenFetchCount++;
    return array_shift($this->control->serviceAccountTokenSequence);
  }

  protected function getTokenFromMetadataServer(): array {
    $this->control->metadataTokenFetchCount++;
    return array_shift($this->control->metadataTokenSequence);
  }

  protected function getCurrentTime(): int {
    return $this->control->currentTime;
  }

  protected function makeApiRequest(string $url, array $payload, array $headers = []): string {
    $this->control->apiCallCount++;
    $this->control->authHeaders[] = $headers['Authorization'] ?? NULL;
    $this->control->capturedPrompt = $payload['contents'][0]['parts'][0]['text'] ?? NULL;

    return $this->control->apiResponse;
  }

}

/**
 * Test double with overridable POLICY_VERSION for cache key testing.
 */
class PolicyVersionTestableEnhancer extends LlmEnhancer {

  private \stdClass $control;
  private string $policyVersion;

  public function __construct(
    $config_factory,
    $http_client,
    $logger_factory,
    $policy_filter,
    $cache,
    \stdClass $control,
    string $policyVersion = '1.0',
  ) {
    parent::__construct($config_factory, $http_client, $logger_factory, $policy_filter, $cache, NULL);
    $this->control = $control;
    $this->policyVersion = $policyVersion;
  }

  protected function getPolicyVersion(): string {
    return $this->policyVersion;
  }

  protected function makeApiRequest(string $url, array $payload, array $headers = []): string {
    $this->control->apiCallCount++;
    return $this->control->apiResponse;
  }

}
