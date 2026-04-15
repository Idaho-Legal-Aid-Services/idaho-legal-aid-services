<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Site\Settings;
use Drupal\ilas_site_assistant\Service\CostControlPolicy;
use Drupal\ilas_site_assistant\Service\LlmEnhancer;
use Drupal\ilas_site_assistant\Service\PolicyFilter;
use Drupal\ilas_site_assistant\Service\RequestTimeLlmTransportInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Cohere-first hardening coverage for bounded request-time classification.
 */
#[Group('ilas_site_assistant')]
final class LlmEnhancerHardeningTest extends TestCase {

  protected function tearDown(): void {
    new Settings([]);
    parent::tearDown();
  }

  public function testIsEnabledRequiresRuntimeToggleAndConfiguredTransport(): void {
    $disabled = $this->buildEnhancer(
      ['llm.enabled' => FALSE],
      new StaticRequestTimeTransport(TRUE, ['payload' => ['intent' => 'faq']]),
    );
    $this->assertFalse($disabled->isEnabled());

    $missingSecret = $this->buildEnhancer(
      ['llm.enabled' => TRUE],
      new StaticRequestTimeTransport(FALSE, ['payload' => ['intent' => 'faq']]),
    );
    $this->assertFalse($missingSecret->isEnabled());

    $enabled = $this->buildEnhancer(
      ['llm.enabled' => TRUE],
      new StaticRequestTimeTransport(TRUE, ['payload' => ['intent' => 'faq']]),
    );
    $this->assertTrue($enabled->isEnabled());
  }

  public function testClassifyIntentReturnsCurrentIntentWhenDisabled(): void {
    $transport = new StaticRequestTimeTransport(FALSE, ['payload' => ['intent' => 'faq']]);
    $enhancer = $this->buildEnhancer(['llm.enabled' => TRUE], $transport);

    $this->assertSame('unknown', $enhancer->classifyIntent('eviction help'));
    $this->assertSame(0, $transport->calls);
  }

  public function testClassifyIntentUsesStructuredTransportAndReturnsCanonicalIntent(): void {
    $transport = new StaticRequestTimeTransport(TRUE, ['payload' => ['intent' => 'faq']]);
    $enhancer = $this->buildEnhancer(['llm.enabled' => TRUE], $transport);

    $intent = $enhancer->classifyIntent('Do you have an FAQ about eviction notices?');

    $this->assertSame('faq', $intent);
    $this->assertSame(1, $transport->calls);
    $this->assertSame('cohere', $enhancer->getProviderId());
    $this->assertSame('command-a-03-2025', $enhancer->getModelId());
    $this->assertStringContainsString('Return exactly one canonical intent label', (string) ($transport->messages[0]['content'] ?? ''));
  }

  public function testClassifyIntentRejectsNonCanonicalIntent(): void {
    $transport = new StaticRequestTimeTransport(TRUE, ['payload' => ['intent' => 'housing']]);
    $enhancer = $this->buildEnhancer(['llm.enabled' => TRUE], $transport);

    $this->assertSame('unknown', $enhancer->classifyIntent('I need legal help with my apartment.'));
  }

  public function testCacheMissStoresResultAndCacheHitSkipsTransport(): void {
    $store = [];
    $cache = $this->createMock(CacheBackendInterface::class);
    $cache->method('get')
      ->willReturnCallback(static function (string $cid) use (&$store): mixed {
        return array_key_exists($cid, $store) ? (object) ['data' => $store[$cid]] : FALSE;
      });
    $cache->method('set')
      ->willReturnCallback(static function (string $cid, mixed $data) use (&$store): void {
        $store[$cid] = $data;
      });

    $costControl = $this->createMock(CostControlPolicy::class);
    $costControl->expects($this->exactly(2))
      ->method('beginRequest')
      ->willReturn(['allowed' => TRUE, 'reason' => 'allowed']);
    $costControl->expects($this->once())->method('recordCacheMiss');
    $costControl->expects($this->once())->method('recordCacheHit');
    $costControl->expects($this->once())->method('recordCall');

    $transport = new StaticRequestTimeTransport(TRUE, ['payload' => ['intent' => 'guides'], 'usage' => ['input' => 9, 'output' => 2, 'total' => 11]]);
    $enhancer = $this->buildEnhancer(
      ['llm.enabled' => TRUE, 'llm.cache_ttl' => 3600],
      $transport,
      $cache,
      $costControl,
    );

    $first = $enhancer->classifyIntent('Show me your self-help guides.');
    $second = $enhancer->classifyIntent('Show me your self-help guides.');

    $this->assertSame('guides', $first);
    $this->assertSame('guides', $second);
    $this->assertSame(1, $transport->calls);
    $this->assertSame(['input' => 9, 'output' => 2, 'total' => 11], $enhancer->getLastUsage());
    $this->assertNotEmpty($store);
  }

  public function testRetryableStatusRetriesWithinConfiguredBudget(): void {
    $enhancer = new SequencedRetryEnhancer(
      $this->buildConfigFactory([
        'llm.enabled' => TRUE,
        'llm.max_retries' => 1,
      ]),
      $this->createStub(ClientInterface::class),
      $this->buildLoggerFactory(),
      new PolicyFilter($this->buildConfigFactory([])),
      NULL,
      NULL,
      NULL,
      NULL,
      NULL,
      new StaticRequestTimeTransport(TRUE, ['payload' => ['intent' => 'faq']]),
      [
        $this->buildRequestException(429),
        ['payload' => ['intent' => 'faq'], 'usage' => ['input' => 3, 'output' => 1, 'total' => 4]],
      ],
    );

    $intent = $enhancer->classifyIntent('faq');

    $this->assertSame('faq', $intent);
    $this->assertCount(1, $enhancer->delays);
    $this->assertLessThanOrEqual(250, $enhancer->delays[0]);
  }

  public function testNonRetryableStatusStopsImmediately(): void {
    $enhancer = new SequencedRetryEnhancer(
      $this->buildConfigFactory([
        'llm.enabled' => TRUE,
        'llm.max_retries' => 2,
      ]),
      $this->createStub(ClientInterface::class),
      $this->buildLoggerFactory(),
      new PolicyFilter($this->buildConfigFactory([])),
      NULL,
      NULL,
      NULL,
      NULL,
      NULL,
      new StaticRequestTimeTransport(TRUE, ['payload' => ['intent' => 'faq']]),
      [
        $this->buildRequestException(400),
      ],
    );

    $this->assertSame('unknown', $enhancer->classifyIntent('faq'));
    $this->assertSame([], $enhancer->delays);
    $this->assertSame(1, $enhancer->dispatchCount);
  }

  public function testGenerateGreetingRemainsRetired(): void {
    $enhancer = $this->buildEnhancer(['llm.enabled' => TRUE], new StaticRequestTimeTransport(TRUE, ['payload' => ['intent' => 'faq']]));
    $this->assertNull($enhancer->generateGreeting('hello'));
  }

  private function buildEnhancer(
    array $overrides,
    RequestTimeLlmTransportInterface $transport,
    ?CacheBackendInterface $cache = NULL,
    ?CostControlPolicy $costControlPolicy = NULL,
  ): LlmEnhancer {
    $configFactory = $this->buildConfigFactory($overrides);

    return new LlmEnhancer(
      $configFactory,
      $this->createStub(ClientInterface::class),
      $this->buildLoggerFactory(),
      new PolicyFilter($configFactory),
      $cache,
      NULL,
      NULL,
      $costControlPolicy,
      NULL,
      $transport,
    );
  }

  private function buildConfigFactory(array $overrides): ConfigFactoryInterface {
    $defaults = [
      'llm.enabled' => FALSE,
      'llm.max_tokens' => 150,
      'llm.temperature' => 0.3,
      'llm.fallback_on_error' => TRUE,
      'llm.safety_threshold' => 'BLOCK_MEDIUM_AND_ABOVE',
      'llm.cache_ttl' => 3600,
      'llm.max_retries' => 1,
    ];
    $values = $overrides + $defaults;

    $config = $this->createStub(ImmutableConfig::class);
    $config->method('get')
      ->willReturnCallback(static fn(string $key): mixed => $values[$key] ?? NULL);

    $factory = $this->createStub(ConfigFactoryInterface::class);
    $factory->method('get')
      ->with('ilas_site_assistant.settings')
      ->willReturn($config);

    return $factory;
  }

  private function buildLoggerFactory(): LoggerChannelFactoryInterface {
    $logger = $this->createStub(LoggerInterface::class);
    $factory = $this->createStub(LoggerChannelFactoryInterface::class);
    $factory->method('get')->willReturn($logger);
    return $factory;
  }

  private function buildRequestException(int $statusCode): RequestException {
    return new RequestException(
      'transport failure',
      new Request('POST', 'https://api.cohere.com/v2/chat'),
      new Response($statusCode),
    );
  }

}

final class StaticRequestTimeTransport implements RequestTimeLlmTransportInterface {

  public int $calls = 0;

  /**
   * @var array<int, array<string, mixed>>
   */
  public array $messages = [];

  /**
   * @param array<string, mixed> $result
   *   Structured payload returned by the transport.
   */
  public function __construct(
    private readonly bool $configured,
    private readonly array $result,
  ) {}

  public function getProviderId(): string {
    return 'cohere';
  }

  public function getModelId(): string {
    return 'command-a-03-2025';
  }

  public function isConfigured(): bool {
    return $this->configured;
  }

  public function completeStructuredJson(array $messages, array $schema, array $options = []): array {
    $this->calls++;
    $this->messages = $messages;
    return $this->result;
  }

}

final class SequencedRetryEnhancer extends LlmEnhancer {

  /**
   * @var array<int, array<string, mixed>|\Throwable>
   */
  private array $sequence;

  /**
   * @var int[]
   */
  public array $delays = [];

  public int $dispatchCount = 0;

  /**
   * @param array<int, array<string, mixed>|\Throwable> $sequence
   *   Dispatch sequence.
   */
  public function __construct(
    ConfigFactoryInterface $configFactory,
    ClientInterface $httpClient,
    LoggerChannelFactoryInterface $loggerFactory,
    ?PolicyFilter $policyFilter,
    ?CacheBackendInterface $cache,
    ?\Drupal\ilas_site_assistant\Service\LlmCircuitBreaker $circuitBreaker,
    ?\Drupal\ilas_site_assistant\Service\LlmRateLimiter $rateLimiter,
    ?CostControlPolicy $costControlPolicy,
    ?\Drupal\ilas_site_assistant\Service\EnvironmentDetector $environmentDetector,
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

  protected function dispatchStructuredJsonRequest(array $messages, array $schema, array $options = []): array {
    $this->dispatchCount++;
    $next = array_shift($this->sequence);
    if ($next instanceof \Throwable) {
      throw $next;
    }

    return is_array($next) ? $next : ['payload' => ['intent' => 'unknown']];
  }

  protected function sleepMilliseconds(int $delayMs): void {
    $this->delays[] = $delayMs;
  }

}
