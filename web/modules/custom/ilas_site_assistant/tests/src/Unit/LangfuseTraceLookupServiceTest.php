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

  private const TRACE_UUID = '06999e10-1f0b-4be8-b574-71abd2864da4';

  private const TRACE_HEX = '06999e101f0b4be8b57471abd2864da4';

  private const URL = 'https://us.cloud.langfuse.com/api/public/v2/observations';

  /**
   * Observation rows as GET /api/public/v2/observations returns them.
   *
   * Shape recorded from Langfuse Cloud on 2026-11-18 for an OTLP trace sent
   * by LangfuseOtlpPayloadBuilder.
   */
  private function observationRows(): array {
    return [
      [
        'id' => '3fd7d162c5614978',
        'traceId' => self::TRACE_HEX,
        'type' => 'EVENT',
        'name' => 'feedback.received',
        'startTime' => '2026-03-18T00:00:05.000Z',
        'parentObservationId' => '5181590e55d80a76',
        'isRootObservation' => FALSE,
        'environment' => 'local',
        'sessionId' => 'session-001',
      ],
      [
        'id' => '661395f2118f4749',
        'traceId' => self::TRACE_HEX,
        'type' => 'SPAN',
        'name' => 'retrieval',
        'startTime' => '2026-03-18T00:00:01.000Z',
        'parentObservationId' => '5181590e55d80a76',
        'isRootObservation' => FALSE,
        'environment' => 'local',
        'sessionId' => 'session-001',
      ],
      [
        'id' => '5181590e55d80a76',
        'traceId' => self::TRACE_HEX,
        'type' => 'SPAN',
        'name' => 'assistant.message',
        'startTime' => '2026-03-18T00:00:00.000Z',
        'parentObservationId' => NULL,
        'isRootObservation' => TRUE,
        'environment' => 'local',
        'sessionId' => 'session-001',
        'input' => 'preview="My email is [REDACTED-EMAIL]" hash=abc len=25-99 redact=email',
        'output' => 'type=navigation reason=navigation_page_match',
        'metadata' => [
          'response_type' => 'navigation',
          'reason_code' => 'navigation_page_match',
          'input_preview_redacted' => 'My email is [REDACTED-EMAIL]',
          'vector_status' => 'enabled_not_needed',
          'vector_attempted' => FALSE,
          'scope.name' => 'ilas-langfuse-tracer',
          'resourceAttributes.service.name' => 'ilas-site-assistant',
          'attributes.langfuse.trace.name' => 'assistant.message',
          'attributes.langfuse.trace.tags' => ['assistant', 'aila', 'message'],
        ],
      ],
    ];
  }

  /**
   * Tests a trace is rebuilt from its v2 observation rows.
   */
  public function testLookupReturnsSanitizedTraceOnSuccess(): void {
    $httpClient = $this->createMock(ClientInterface::class);
    $httpClient->expects($this->once())
      ->method('request')
      ->with(
        'GET',
        self::URL,
        $this->callback(function (array $options): bool {
          $this->assertSame(5.0, $options['timeout']);
          $this->assertSame(['pk-test-123', 'sk-test-456'], $options['auth']);
          // The request UUID is sent as the hyphen-free Langfuse trace id.
          $this->assertSame(self::TRACE_HEX, $options['query']['traceId']);
          $this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $options['query']['fromStartTime']);
          $this->assertGreaterThan($options['query']['fromStartTime'], $options['query']['toStartTime']);
          $this->assertStringContainsString('metadata', $options['query']['fields']);
          return TRUE;
        }),
      )
      ->willReturn(new Response(200, [], json_encode(['data' => $this->observationRows(), 'meta' => []], JSON_THROW_ON_ERROR)));

    $result = $this->buildService($httpClient)->lookupTrace(self::TRACE_UUID, 1, 0);

    $this->assertTrue($result['found']);
    $this->assertSame(200, $result['http_status']);
    $this->assertSame(self::TRACE_UUID, $result['trace_id']);
    $this->assertSame(self::TRACE_HEX, $result['langfuse_trace_id']);
    $this->assertSame('/api/public/v2/observations?traceId={trace_id}', $result['api_path']);
    $this->assertSame('assistant.message', $result['trace']['name']);
    $this->assertSame('2026-03-18T00:00:00.000Z', $result['trace']['timestamp']);
    $this->assertSame('local', $result['trace']['environment']);
    $this->assertTrue($result['trace']['root_observation_found']);
    // The root span stands in for the trace and is not counted.
    $this->assertSame(2, $result['trace']['observation_count']);
    $this->assertSame('session-001', $result['trace']['session_id']);
    $this->assertSame(['assistant', 'aila', 'message'], $result['trace']['tags']);
    $this->assertSame('preview="My email is [REDACTED-EMAIL]" hash=abc len=25-99 redact=email', $result['trace']['input']);
    $this->assertTrue($result['trace']['request_path_fields']['vector_status']['present']);
    $this->assertSame('enabled_not_needed', $result['trace']['request_path_fields']['vector_status']['value']);
    $this->assertSame('My email is [REDACTED-EMAIL]', $result['trace']['request_path_fields']['input_preview_redacted']['value']);
    $this->assertContains('response_type', $result['trace']['metadata_keys']);
    // OTLP transport keys are not reported as trace metadata.
    $this->assertNotContains('scope.name', $result['trace']['metadata_keys']);
    $this->assertNotContains('attributes.langfuse.trace.tags', $result['trace']['metadata_keys']);
  }

  /**
   * Tests an empty result is retried until the trace has been ingested.
   */
  public function testLookupRetriesUntilObservationsAppear(): void {
    $httpClient = $this->createMock(ClientInterface::class);
    $httpClient->expects($this->exactly(2))
      ->method('request')
      ->willReturnOnConsecutiveCalls(
        new Response(200, [], '{"data":[],"meta":{}}'),
        new Response(200, [], json_encode(['data' => $this->observationRows()], JSON_THROW_ON_ERROR)),
      );

    $result = $this->buildService($httpClient)->lookupTrace(self::TRACE_HEX, 3, 0);

    $this->assertTrue($result['found']);
    $this->assertSame(2, $result['attempts']);
  }

  /**
   * Tests a trace that never appears is reported as not found.
   */
  public function testLookupReturnsNotFoundAfterExhaustingRetries(): void {
    $httpClient = $this->createMock(ClientInterface::class);
    $httpClient->expects($this->exactly(2))
      ->method('request')
      ->willReturn(new Response(200, [], '{"data":[],"meta":{}}'));

    $result = $this->buildService($httpClient)->lookupTrace(self::TRACE_UUID, 2, 0);

    $this->assertFalse($result['found']);
    $this->assertSame(2, $result['attempts']);
    $this->assertArrayNotHasKey('trace', $result);
  }

  /**
   * Tests rows without a root span are still described by the first one.
   */
  public function testLookupWithoutRootObservationUsesEarliestRow(): void {
    $rows = array_slice($this->observationRows(), 0, 2);
    $httpClient = $this->createStub(ClientInterface::class);
    $httpClient->method('request')
      ->willReturn(new Response(200, [], json_encode(['data' => $rows], JSON_THROW_ON_ERROR)));

    $result = $this->buildService($httpClient)->lookupTrace(self::TRACE_UUID, 1, 0);

    $this->assertTrue($result['found']);
    $this->assertFalse($result['trace']['root_observation_found']);
    $this->assertSame('retrieval', $result['trace']['name']);
    $this->assertSame(2, $result['trace']['observation_count']);
  }

  /**
   * Tests an HTTP error surfaces with its status instead of being retried.
   *
   * The removed v3 read endpoints answer 410; a retry loop there would only
   * hide the cause.
   */
  public function testLookupThrowsOnHttpError(): void {
    $httpClient = $this->createMock(ClientInterface::class);
    $httpClient->expects($this->once())
      ->method('request')
      ->willThrowException(new ClientException(
        'gone',
        new Request('GET', self::URL),
        new Response(410, [], '{"error":"LANGFUSE_V3_API_REMOVED"}'),
      ));

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('Langfuse trace lookup failed with HTTP 410');
    $this->buildService($httpClient)->lookupTrace(self::TRACE_UUID, 3, 0);
  }

  /**
   * Tests transport failures surface as runtime exceptions.
   */
  public function testLookupThrowsOnTransportFailure(): void {
    $httpClient = $this->createMock(ClientInterface::class);
    $httpClient->expects($this->once())
      ->method('request')
      ->willThrowException(new ConnectException('timeout', new Request('GET', self::URL)));

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('Langfuse trace lookup failed with HTTP 0: timeout');
    $this->buildService($httpClient)->lookupTrace(self::TRACE_UUID, 1, 0);
  }

  /**
   * Tests an unexpected body is an error, not a silent "not found".
   */
  public function testLookupThrowsOnUnexpectedShape(): void {
    $httpClient = $this->createStub(ClientInterface::class);
    $httpClient->method('request')->willReturn(new Response(200, [], '{"message":"nope"}'));

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('unexpected response shape');
    $this->buildService($httpClient)->lookupTrace(self::TRACE_UUID, 1, 0);
  }

}
