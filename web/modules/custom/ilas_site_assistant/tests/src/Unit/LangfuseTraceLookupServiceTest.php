<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\ilas_site_assistant\Service\LangfuseTraceLookupService;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for sanitized Langfuse trace lookup.
 */
#[Group('ilas_site_assistant')]
class LangfuseTraceLookupServiceTest extends TestCase {

  /**
   * Builds the lookup service with configurable runtime values.
   */
  private function buildService(ClientInterface $httpClient, array $configValues = []): LangfuseTraceLookupService {
    $values = $configValues + [
      'langfuse.enabled' => TRUE,
      'langfuse.public_key' => 'pk-test-123',
      'langfuse.secret_key' => 'sk-test-456',
      'langfuse.host' => 'https://us.cloud.langfuse.com',
      'langfuse.timeout' => 5.0,
    ];

    $config = $this->createStub(ImmutableConfig::class);
    $config->method('get')->willReturnCallback(fn($key) => $values[$key] ?? NULL);

    $configFactory = $this->createStub(ConfigFactoryInterface::class);
    $configFactory->method('get')
      ->with('ilas_site_assistant.settings')
      ->willReturn($config);

    return new LangfuseTraceLookupService($configFactory, $httpClient);
  }

  /**
   * Tests success against the current top-level trace response shape.
   */
  public function testLookupReturnsSanitizedTraceOnSuccess(): void {
    $httpClient = $this->createMock(ClientInterface::class);
    $httpClient->expects($this->once())
      ->method('request')
      ->with(
        'GET',
        'https://us.cloud.langfuse.com/api/public/traces/trace-001',
        $this->callback(function (array $options): bool {
          return ($options['timeout'] ?? NULL) === 5.0
            && ($options['connect_timeout'] ?? NULL) === 5.0
            && ($options['auth'] ?? NULL) === ['pk-test-123', 'sk-test-456'];
        }),
      )
      ->willReturn(new Response(200, [], json_encode([
        'id' => 'trace-001',
        'name' => 'assistant.message',
        'timestamp' => '2026-03-18T00:00:00.000Z',
        'createdAt' => '2026-03-18T00:00:01.000Z',
        'updatedAt' => '2026-03-18T00:00:02.000Z',
        'environment' => 'local',
        'input' => 'hash=abc len=1-24 redact=none',
        'output' => 'type=navigation reason=navigation_page_match hash=def len=1-24',
        'metadata' => [
          'response_type' => 'navigation',
          'reason_code' => 'navigation_page_match',
          'vector_status' => 'enabled_not_needed',
          'vector_attempted' => FALSE,
        ],
        'observations' => [
          ['id' => 'obs-1'],
          ['id' => 'obs-2'],
        ],
      ], JSON_THROW_ON_ERROR)));

    $service = $this->buildService($httpClient);
    $result = $service->lookupTrace('trace-001', 1, 0);

    $this->assertTrue($result['found']);
    $this->assertSame(200, $result['http_status']);
    $this->assertSame('assistant.message', $result['trace']['name']);
    $this->assertSame(2, $result['trace']['observation_count']);
    $this->assertSame('hash=abc len=1-24 redact=none', $result['trace']['input']);
    $this->assertTrue($result['trace']['request_path_fields']['vector_status']['present']);
    $this->assertSame('enabled_not_needed', $result['trace']['request_path_fields']['vector_status']['value']);
    $this->assertContains('response_type', $result['trace']['metadata_keys']);
  }

  /**
   * Tests 404 lookup retries can recover on a later success.
   */
  public function testLookupRetriesEventualNotFoundUntilSuccess(): void {
    $httpClient = $this->createMock(ClientInterface::class);
    $request = new Request('GET', 'https://us.cloud.langfuse.com/api/public/traces/trace-002');
    $notFound = new ClientException('not found', $request, new Response(404, [], '{"message":"not found"}'));

    $attempt = 0;
    $httpClient->expects($this->exactly(2))
      ->method('request')
      ->willReturnCallback(function () use (&$attempt, $notFound) {
        $attempt++;
        if ($attempt === 1) {
          throw $notFound;
        }

        return new Response(200, [], json_encode([
          'id' => 'trace-002',
          'name' => 'assistant.message',
          'metadata' => [],
          'observations' => [],
        ], JSON_THROW_ON_ERROR));
      });

    $service = $this->buildService($httpClient);
    $result = $service->lookupTrace('trace-002', 2, 0);

    $this->assertTrue($result['found']);
    $this->assertSame(2, $result['attempts']);
  }

  /**
   * Tests fallback to the recent trace list when detail lookup still 404s.
   */
  public function testLookupFallsBackToRecentTraceList(): void {
    $httpClient = $this->createMock(ClientInterface::class);
    $detailRequest = new Request('GET', 'https://us.cloud.langfuse.com/api/public/traces/trace-002b');
    $notFound = new ClientException('not found', $detailRequest, new Response(404, [], '{"message":"not found"}'));

    $httpClient->expects($this->exactly(3))
      ->method('request')
      ->willReturnCallback(function (string $method, string $url, array $options) use ($notFound) {
        if ($url === 'https://us.cloud.langfuse.com/api/public/traces/trace-002b') {
          throw $notFound;
        }

        $this->assertSame('https://us.cloud.langfuse.com/api/public/traces', $url);
        $this->assertSame(['limit' => 25], $options['query'] ?? []);

        return new Response(200, [], json_encode([
          'data' => [
            [
              'id' => 'trace-002b',
              'name' => 'assistant.message',
              'input' => 'hash=abc len=1-24 redact=none',
              'output' => 'type=navigation reason=navigation_page_match hash=def len=1-24',
              'metadata' => [
                'response_type' => 'navigation',
              ],
              'observations' => ['obs-1'],
            ],
          ],
        ], JSON_THROW_ON_ERROR));
      });

    $service = $this->buildService($httpClient);
    $result = $service->lookupTrace('trace-002b', 2, 0);

    $this->assertTrue($result['found']);
    $this->assertSame('/api/public/traces?limit=25', $result['api_path']);
    $this->assertSame(1, $result['trace']['observation_count']);
    $this->assertSame('navigation', $result['trace']['request_path_fields']['response_type']['value']);
  }

  /**
   * Tests timeout fallback can still succeed through the recent trace list.
   */
  public function testLookupFallsBackToRecentTraceListAfterTimeout(): void {
    $httpClient = $this->createMock(ClientInterface::class);
    $timeout = new ConnectException(
      'timeout',
      new Request('GET', 'https://us.cloud.langfuse.com/api/public/traces/trace-002c'),
    );

    $httpClient->expects($this->exactly(2))
      ->method('request')
      ->willReturnCallback(function (string $method, string $url) use ($timeout) {
        if ($url === 'https://us.cloud.langfuse.com/api/public/traces/trace-002c') {
          throw $timeout;
        }

        return new Response(200, [], json_encode([
          'data' => [
            [
              'id' => 'trace-002c',
              'name' => 'assistant.message',
              'metadata' => [],
              'observations' => [],
            ],
          ],
        ], JSON_THROW_ON_ERROR));
      });

    $service = $this->buildService($httpClient);
    $result = $service->lookupTrace('trace-002c', 2, 0);

    $this->assertTrue($result['found']);
    $this->assertSame('/api/public/traces?limit=25', $result['api_path']);
  }

  /**
   * Tests repeated 404 responses return a not-found result instead of throwing.
   */
  public function testLookupReturnsNotFoundAfterExhaustingRetries(): void {
    $httpClient = $this->createMock(ClientInterface::class);
    $request = new Request('GET', 'https://us.cloud.langfuse.com/api/public/traces/trace-003');
    $notFound = new ClientException('not found', $request, new Response(404, [], '{"message":"not found"}'));

    $httpClient->expects($this->exactly(3))
      ->method('request')
      ->willReturnCallback(function (string $method, string $url) use ($notFound) {
        if ($url === 'https://us.cloud.langfuse.com/api/public/traces/trace-003') {
          throw $notFound;
        }

        return new Response(200, [], json_encode([
          'data' => [],
        ], JSON_THROW_ON_ERROR));
      });

    $service = $this->buildService($httpClient);
    $result = $service->lookupTrace('trace-003', 2, 0);

    $this->assertFalse($result['found']);
    $this->assertSame(404, $result['http_status']);
    $this->assertSame(2, $result['attempts']);
  }

  /**
   * Tests transport failures surface as runtime exceptions.
   */
  public function testLookupThrowsOnTransportFailure(): void {
    $httpClient = $this->createMock(ClientInterface::class);
    $httpClient->expects($this->exactly(2))
      ->method('request')
      ->willReturnCallback(function (string $method, string $url) {
        throw new ConnectException(
          'timeout',
          new Request($method, $url),
        );
      });

    $service = $this->buildService($httpClient);

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('Langfuse trace list fallback failed: timeout');
    $service->lookupTrace('trace-004', 1, 0);
  }
}
