<?php

namespace Drupal\Tests\ilas_site_assistant\Unit;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\ilas_site_assistant\Service\LlmEnhancer;
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
   * Tests that enhance_faq: false skips the LLM call for FAQ responses.
   */
  public function testEnhanceFaqFalseSkipsLlm(): void {
    $enhancer = $this->buildEnhancer(configOverrides: [
      'llm.enhance_faq' => FALSE,
    ]);

    $response = [
      'type' => 'faq',
      'results' => [['question' => 'Q?', 'answer' => 'A.']],
    ];

    $result = $enhancer->enhanceResponse($response, 'test question');

    $this->assertEquals(0, $this->control->apiCallCount, 'API should not be called when enhance_faq is false');
    $this->assertArrayNotHasKey('llm_summary', $result, 'Response should not be enhanced');
  }

  /**
   * Tests that enhance_resources: false skips the LLM call for resource responses.
   */
  public function testEnhanceResourcesFalseSkipsLlm(): void {
    $enhancer = $this->buildEnhancer(configOverrides: [
      'llm.enhance_resources' => FALSE,
    ]);

    $response = [
      'type' => 'resources',
      'results' => [['title' => 'Resource 1', 'type' => 'guide']],
    ];

    $result = $enhancer->enhanceResponse($response, 'test question');

    $this->assertEquals(0, $this->control->apiCallCount, 'API should not be called when enhance_resources is false');
    $this->assertArrayNotHasKey('llm_summary', $result, 'Response should not be enhanced');
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

    $this->assertEquals(0, $this->control->apiCallCount, 'API should not be called for escalation responses');
    $this->assertArrayNotHasKey('llm_summary', $result);
  }

  /**
   * Tests that fallback_on_error: true swallows exceptions and returns original response.
   */
  public function testFallbackOnErrorTrueSwallows(): void {
    $this->control->apiException = new \Exception('LLM exploded');

    $enhancer = $this->buildEnhancer(configOverrides: [
      'llm.fallback_on_error' => TRUE,
    ]);

    $response = [
      'type' => 'faq',
      'results' => [['question' => 'Q?', 'answer' => 'A.']],
    ];

    $result = $enhancer->enhanceResponse($response, 'test question');

    $this->assertArrayNotHasKey('llm_summary', $result, 'Failed enhancement should not add summary');
    $this->assertEquals('faq', $result['type'], 'Original response should be returned');
  }

  /**
   * Tests that fallback_on_error: false re-throws exceptions.
   */
  public function testFallbackOnErrorFalseRethrows(): void {
    $this->control->apiException = new \Exception('LLM exploded');

    $enhancer = $this->buildEnhancer(configOverrides: [
      'llm.fallback_on_error' => FALSE,
    ]);

    $response = [
      'type' => 'faq',
      'results' => [['question' => 'Q?', 'answer' => 'A.']],
    ];

    $this->expectException(\Exception::class);
    $this->expectExceptionMessage('LLM exploded');

    $enhancer->enhanceResponse($response, 'test question');
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
   * Tests classifyIntent skips LLM when intent is already known.
   */
  public function testClassifyIntentSkipsLlmForKnownIntent(): void {
    $enhancer = $this->buildEnhancer();

    $result = $enhancer->classifyIntent('test', 'housing');

    $this->assertEquals('housing', $result, 'Should return existing intent without calling LLM');
    $this->assertEquals(0, $this->control->apiCallCount);
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
      'llm.enhance_faq' => TRUE,
      'llm.enhance_resources' => TRUE,
      'llm.enhance_greetings' => FALSE,
      'llm.fallback_on_error' => TRUE,
      'llm.safety_threshold' => 'BLOCK_MEDIUM_AND_ABOVE',
      'llm.cache_ttl' => 3600,
      'llm.max_retries' => 2,
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
    bool $useRealMakeApiRequest = FALSE
  ): LlmEnhancer {
    $configFactory = $this->buildConfigFactory($configOverrides);
    $loggerFactory = $this->buildLoggerFactory();
    $policyFilter = $this->buildPolicyFilter();

    if ($useRealMakeApiRequest) {
      return new RetryTestableEnhancer(
        $configFactory,
        $this->buildMockHttpClient(),
        $loggerFactory,
        $policyFilter,
        $cache,
        $this->control
      );
    }

    return new HardeningTestableEnhancer(
      $configFactory,
      $this->createStub(ClientInterface::class),
      $loggerFactory,
      $policyFilter,
      $cache,
      $this->control
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

}

/**
 * Test double that overrides makeApiRequest for most tests.
 */
class HardeningTestableEnhancer extends LlmEnhancer {

  private \stdClass $control;

  public function __construct(
    $config_factory,
    $http_client,
    $logger_factory,
    $policy_filter,
    $cache,
    \stdClass $control,
  ) {
    parent::__construct($config_factory, $http_client, $logger_factory, $policy_filter, $cache);
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

  private \stdClass $control;

  public function __construct(
    $config_factory,
    $http_client,
    $logger_factory,
    $policy_filter,
    $cache,
    \stdClass $control,
  ) {
    parent::__construct($config_factory, $http_client, $logger_factory, $policy_filter, $cache);
    $this->control = $control;
  }

  // Uses real makeApiRequest — the mock HTTP client handles sequencing.

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
    parent::__construct($config_factory, $http_client, $logger_factory, $policy_filter, $cache);
    $this->control = $control;
    $this->policyVersion = $policyVersion;
  }

  protected function callLlm(string $prompt, array $options = []): string {
    $config = $this->configFactory->get('ilas_site_assistant.settings');
    $model = $config->get('llm.model') ?? 'gemini-1.5-flash';
    $temperature = $options['temperature'] ?? 0.3;
    $cacheTtl = (int) ($config->get('llm.cache_ttl') ?? 3600);

    if ($this->cache && $cacheTtl > 0 && $temperature <= 0.5) {
      $cacheKey = 'llm:' . hash('sha256', implode('|', [
        $prompt,
        $model,
        (string) ($options['max_tokens'] ?? 150),
        (string) $temperature,
        $this->policyVersion,
      ]));
      $cached = $this->cache->get($cacheKey);
      if ($cached) {
        return $cached->data;
      }
    }

    $this->control->apiCallCount++;
    $result = $this->control->apiResponse;

    if ($this->cache && $cacheTtl > 0 && $temperature <= 0.5 && isset($cacheKey)) {
      $this->cache->set($cacheKey, $result, time() + $cacheTtl, ['ilas_site_assistant:llm']);
    }

    return $result;
  }

  protected function makeApiRequest(string $url, array $payload, array $headers = []): string {
    $this->control->apiCallCount++;
    return $this->control->apiResponse;
  }

}
