<?php

namespace Drupal\Tests\ilas_site_assistant\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\ilas_site_assistant\Service\LlmEnhancer;
use Drupal\ilas_site_assistant\Service\PolicyFilter;
use GuzzleHttp\ClientInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests that the Gemini API key is sent via header, not URL query string.
 *
 * Covers Fix C (F-14): API key in URL leaks via access logs.
 */
#[Group('ilas_site_assistant')]
class LlmEnhancerApiKeyTest extends TestCase {

  /**
   * Tests that callGeminiApi sends the API key via header, not in the URL.
   */
  public function testApiKeyNotInUrl(): void {
    // Shared capture object to collect the URL and headers from makeApiRequest.
    $capture = new \stdClass();
    $capture->url = NULL;
    $capture->headers = [];

    $enhancer = new TestableGeminiLlmEnhancer(
      $this->createMockConfigFactory(),
      $this->createStub(ClientInterface::class),
      $this->createMockLoggerFactory(),
      $this->createStub(PolicyFilter::class),
      $capture
    );

    // Invoke the protected method via reflection.
    $ref = new \ReflectionMethod($enhancer, 'callGeminiApi');
    $ref->setAccessible(TRUE);
    $ref->invoke($enhancer, 'Test prompt', ['max_tokens' => 10]);

    // Assert API key is NOT in the URL.
    $this->assertNotNull($capture->url, 'URL should have been captured');
    $this->assertStringNotContainsString('key=', $capture->url, 'API key must NOT appear in the URL query string');

    // Assert API key IS in the header.
    $this->assertArrayHasKey('x-goog-api-key', $capture->headers, 'API key must be sent via x-goog-api-key header');
    $this->assertEquals('test-api-key-12345', $capture->headers['x-goog-api-key'], 'Header must contain the configured API key');
  }

  /**
   * Creates a mock config factory that returns a test API key.
   *
   * @return \Drupal\Core\Config\ConfigFactoryInterface
   *   The mock config factory.
   */
  private function createMockConfigFactory(): ConfigFactoryInterface {
    $config = $this->createStub(ImmutableConfig::class);
    $config->method('get')
      ->willReturnMap([
        ['llm.enabled', TRUE],
        ['llm.provider', 'gemini_api'],
        ['llm.api_key', 'test-api-key-12345'],
        ['llm.model', 'gemini-1.5-flash'],
        ['llm.safety_threshold', 'BLOCK_MEDIUM_AND_ABOVE'],
      ]);

    $factory = $this->createStub(ConfigFactoryInterface::class);
    $factory->method('get')
      ->willReturn($config);

    return $factory;
  }

  /**
   * Creates a mock logger channel factory.
   *
   * @return \Drupal\Core\Logger\LoggerChannelFactoryInterface
   *   The mock logger factory.
   */
  private function createMockLoggerFactory(): LoggerChannelFactoryInterface {
    $logger = $this->createStub(LoggerInterface::class);
    $factory = $this->createStub(LoggerChannelFactoryInterface::class);
    $factory->method('get')->willReturn($logger);
    return $factory;
  }

}

/**
 * Test double for LlmEnhancer that captures makeApiRequest args.
 */
class TestableGeminiLlmEnhancer extends LlmEnhancer {

  /**
   * Capture object for URL and headers.
   *
   * @var \stdClass
   */
  private \stdClass $capture;

  /**
   * Constructs the test double.
   */
  public function __construct(
    $config_factory,
    $http_client,
    $logger_factory,
    $policy_filter,
    \stdClass $capture,
  ) {
    parent::__construct($config_factory, $http_client, $logger_factory, $policy_filter);
    $this->capture = $capture;
  }

  /**
   * {@inheritdoc}
   */
  protected function makeApiRequest(string $url, array $payload, array $headers = []): string {
    $this->capture->url = $url;
    $this->capture->headers = $headers;
    return 'test response';
  }

}
