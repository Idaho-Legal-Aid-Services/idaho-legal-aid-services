<?php

namespace Drupal\Tests\ilas_site_assistant\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\ilas_site_assistant\Service\LangfuseTracer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests LangfuseTracer batch event types and trace lifecycle.
 */
#[CoversClass(LangfuseTracer::class)]
#[Group('ilas_site_assistant')]
class LangfuseTracerTest extends TestCase {

  /**
   * Builds a LangfuseTracer with Langfuse enabled and test credentials.
   *
   * @return \Drupal\ilas_site_assistant\Service\LangfuseTracer
   *   A configured tracer instance.
   */
  private function buildTracer(): LangfuseTracer {
    $config = $this->createStub(ImmutableConfig::class);
    $config->method('get')
      ->willReturnCallback(fn($key) => match ($key) {
        'langfuse.enabled' => TRUE,
        'langfuse.public_key' => 'pk-test-123',
        'langfuse.secret_key' => 'sk-test-456',
        'langfuse.sample_rate' => 1.0,
        default => NULL,
      });

    $configFactory = $this->createStub(ConfigFactoryInterface::class);
    $configFactory->method('get')->willReturn($config);

    $logger = $this->createStub(LoggerInterface::class);

    return new LangfuseTracer($configFactory, $logger);
  }

  /**
   * Builds a LangfuseTracer with Langfuse disabled.
   *
   * @return \Drupal\ilas_site_assistant\Service\LangfuseTracer
   *   A tracer that will not emit events.
   */
  private function buildDisabledTracer(): LangfuseTracer {
    $config = $this->createStub(ImmutableConfig::class);
    $config->method('get')
      ->willReturnCallback(fn($key) => match ($key) {
        'langfuse.enabled' => FALSE,
        default => NULL,
      });

    $configFactory = $this->createStub(ConfigFactoryInterface::class);
    $configFactory->method('get')->willReturn($config);

    $logger = $this->createStub(LoggerInterface::class);

    return new LangfuseTracer($configFactory, $logger);
  }

  /**
   * Extracts the 'type' field from each batch event in the payload.
   *
   * @param array $payload
   *   The trace payload from getTracePayload().
   *
   * @return string[]
   *   Ordered list of event types.
   */
  private function extractEventTypes(array $payload): array {
    return array_map(fn($event) => $event['type'], $payload['batch']);
  }

  /**
   * Tests that a full trace lifecycle emits the correct event type sequence.
   */
  public function testFullLifecycleEventTypeSequence(): void {
    $tracer = $this->buildTracer();

    $tracer->startTrace('trace-001', 'assistant.message', ['env' => 'test'], 'hash=abc len=1-24 redact=none');
    $tracer->startSpan('safety.classify');
    $tracer->endSpan(['is_safe' => TRUE]);
    $tracer->startSpan('retrieval');
    $tracer->startGeneration('llm.summarize', 'gemini-1.5-flash', ['temperature' => 0.3], [
      'input_hash' => 'abc',
      'input_length_bucket' => '1-24',
      'input_redaction_profile' => 'none',
    ]);
    $tracer->endGeneration('intent=faq', ['input' => 10, 'output' => 20, 'total' => 30]);
    $tracer->endSpan(['results' => 3]);
    $tracer->endTrace('type=faq reason=none hash=def len=1-24', ['total_ms' => 450]);

    $payload = $tracer->getTracePayload();

    $this->assertNotNull($payload, 'Payload should not be null after full lifecycle');

    $types = $this->extractEventTypes($payload);

    $expected = [
      'trace-create',
      'span-create',
      'generation-create',
      'span-create',
    ];

    $this->assertSame($expected, $types);
    $this->assertNotContains('trace-update', $types);
  }

  /**
   * Tests that the finalized trace-create carries visible trace I/O.
   */
  public function testTraceCreateCarriesInputOutputAndMetadata(): void {
    $tracer = $this->buildTracer();

    $tracer->startTrace('trace-002', 'assistant.message', [
      'env' => 'test',
      'input_hash' => str_repeat('a', 64),
      'input_length_bucket' => '1-24',
      'input_redaction_profile' => 'none',
    ], 'hash=aaaaaaaaaaaa len=1-24 redact=none');
    $tracer->endTrace('type=faq reason=none hash=bbbbbbbbbbbb len=1-24', [
      'output_hash' => str_repeat('b', 64),
      'output_length_bucket' => '1-24',
      'output_redaction_profile' => 'none',
      'response_type' => 'faq',
      'reason_code' => NULL,
    ]);

    $payload = $tracer->getTracePayload();
    $this->assertNotNull($payload);

    $traceCreate = $payload['batch'][0];
    $this->assertSame('trace-create', $traceCreate['type']);
    $this->assertSame('trace-002', $traceCreate['body']['id']);
    $this->assertSame('assistant.message', $traceCreate['body']['name']);
    $this->assertSame('hash=aaaaaaaaaaaa len=1-24 redact=none', $traceCreate['body']['input']);
    $this->assertSame('type=faq reason=none hash=bbbbbbbbbbbb len=1-24', $traceCreate['body']['output']);
    $this->assertSame('test', $traceCreate['body']['metadata']['env']);
    $this->assertSame('faq', $traceCreate['body']['metadata']['response_type']);
    $this->assertArrayHasKey('timestamp', $traceCreate['body']);
  }

  /**
   * Tests that array inputs and outputs move to metadata-only fields.
   */
  public function testArrayInputsAndOutputsMoveToMetadataWhileUiGetsSummary(): void {
    $tracer = $this->buildTracer();

    $tracer->startTrace('trace-003', 'assistant.message');
    $tracer->startSpan('gate.evaluate', ['decision_kind' => 'fallback'], [
      'input_hash' => 'abc',
      'input_length_bucket' => '1-24',
      'input_redaction_profile' => 'none',
    ]);
    $tracer->endSpan([
      'decision' => 'answer',
      'confidence' => 0.8,
      'reason_code' => NULL,
    ]);
    $tracer->endTrace([
      'type' => 'faq',
      'reason_code' => NULL,
    ], ['success' => TRUE]);

    $payload = $tracer->getTracePayload();
    $this->assertNotNull($payload);

    $span = $payload['batch'][1];
    $this->assertSame('confidence=0.8,decision=answer,reason_code=none', $span['body']['output']);
    $this->assertSame(
      'input_hash=abc,input_length_bucket=1-24,input_redaction_profile=none',
      $span['body']['input']
    );
    $this->assertSame('answer', $span['body']['metadata']['output_fields']['decision']);
    $this->assertSame('abc', $span['body']['metadata']['input_fields']['input_hash']);

    $trace = $payload['batch'][0];
    $this->assertSame('reason_code=none,type=faq', $trace['body']['output']);
    $this->assertSame('faq', $trace['body']['metadata']['output_fields']['type']);
  }

  /**
   * Tests that endTrace closes dangling spans automatically.
   */
  public function testEndTraceClosesDanglingSpans(): void {
    $tracer = $this->buildTracer();

    $tracer->startTrace('trace-004', 'test');
    $tracer->startSpan('unclosed.span');
    $tracer->endTrace();

    $payload = $tracer->getTracePayload();
    $types = $this->extractEventTypes($payload);

    $this->assertSame(['trace-create', 'span-create'], $types);
  }

  /**
   * Tests that endTrace closes a dangling generation automatically.
   */
  public function testEndTraceClosesDanglingGeneration(): void {
    $tracer = $this->buildTracer();

    $tracer->startTrace('trace-005', 'test');
    $tracer->startGeneration('llm.call', 'gemini-1.5-flash');
    $tracer->endTrace();

    $payload = $tracer->getTracePayload();
    $types = $this->extractEventTypes($payload);

    $this->assertSame(['trace-create', 'generation-create'], $types);
  }

  /**
   * Tests that a disabled tracer produces no payload.
   */
  public function testDisabledTracerProducesNoPayload(): void {
    $tracer = $this->buildDisabledTracer();

    $tracer->startTrace('trace-006', 'test');
    $tracer->startSpan('span');
    $tracer->endSpan();
    $tracer->endTrace();

    $this->assertNull($tracer->getTracePayload());
    $this->assertFalse($tracer->isActive());
  }

  /**
   * Tests that the payload includes correct metadata.
   */
  public function testPayloadMetadata(): void {
    $tracer = $this->buildTracer();

    $tracer->startTrace('trace-007', 'test');
    $tracer->endTrace();

    $payload = $tracer->getTracePayload();

    $this->assertArrayHasKey('metadata', $payload);
    $this->assertSame(1, $payload['metadata']['batch_size']);
    $this->assertSame('ilas-langfuse-tracer', $payload['metadata']['sdk_name']);
  }

  /**
   * Tests that addEvent produces event-create type.
   */
  public function testAddEventType(): void {
    $tracer = $this->buildTracer();

    $tracer->startTrace('trace-008', 'test');
    $tracer->addEvent('request.complete', ['status' => 200]);
    $tracer->endTrace();

    $payload = $tracer->getTracePayload();
    $types = $this->extractEventTypes($payload);

    $this->assertSame(['trace-create', 'event-create'], $types);
  }

  /**
   * Tests that nested spans get correct parent observation IDs.
   */
  public function testNestedSpanParenting(): void {
    $tracer = $this->buildTracer();

    $tracer->startTrace('trace-009', 'test');
    $tracer->startSpan('outer');
    $tracer->startSpan('inner');
    $tracer->endSpan('inner-output');
    $tracer->endSpan('outer-output');
    $tracer->endTrace();

    $payload = $tracer->getTracePayload();
    $batch = $payload['batch'];

    $innerSpan = $batch[1];
    $outerSpan = $batch[2];

    $this->assertSame('span-create', $innerSpan['type']);
    $this->assertSame('span-create', $outerSpan['type']);
    $this->assertArrayHasKey('parentObservationId', $innerSpan['body']);
    $this->assertArrayNotHasKey('parentObservationId', $outerSpan['body']);
  }

  /**
   * Tests that isEnabled() logs reason when config is disabled.
   */
  public function testIsEnabledLogsReasonWhenConfigDisabled(): void {
    $config = $this->createStub(ImmutableConfig::class);
    $config->method('get')
      ->willReturnCallback(fn($key) => match ($key) {
        'langfuse.enabled' => FALSE,
        default => NULL,
      });

    $configFactory = $this->createStub(ConfigFactoryInterface::class);
    $configFactory->method('get')->willReturn($config);

    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->once())
      ->method('info')
      ->with($this->stringContains('config_disabled'));

    $tracer = new LangfuseTracer($configFactory, $logger);
    $this->assertFalse($tracer->isEnabled());
  }

  /**
   * Tests that isEnabled() logs reason when credentials are absent.
   */
  public function testIsEnabledLogsReasonWhenCredentialsAbsent(): void {
    $config = $this->createStub(ImmutableConfig::class);
    $config->method('get')
      ->willReturnCallback(fn($key) => match ($key) {
        'langfuse.enabled' => TRUE,
        'langfuse.public_key' => '',
        'langfuse.secret_key' => '',
        default => NULL,
      });

    $configFactory = $this->createStub(ConfigFactoryInterface::class);
    $configFactory->method('get')->willReturn($config);

    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->once())
      ->method('info')
      ->with($this->stringContains('credentials_absent'));

    $tracer = new LangfuseTracer($configFactory, $logger);
    $this->assertFalse($tracer->isEnabled());
  }

  /**
   * Tests that isEnabled() logs active state when tracing is enabled.
   */
  public function testIsEnabledLogsActiveState(): void {
    $config = $this->createStub(ImmutableConfig::class);
    $config->method('get')
      ->willReturnCallback(fn($key) => match ($key) {
        'langfuse.enabled' => TRUE,
        'langfuse.public_key' => 'pk-test-123',
        'langfuse.secret_key' => 'sk-test-456',
        'langfuse.sample_rate' => 1.0,
        default => NULL,
      });

    $configFactory = $this->createStub(ConfigFactoryInterface::class);
    $configFactory->method('get')->willReturn($config);

    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->once())
      ->method('debug')
      ->with(
        $this->stringContains('tracing active'),
        $this->callback(fn($context) => isset($context['@rate']) && $context['@rate'] === 1.0),
      );

    $tracer = new LangfuseTracer($configFactory, $logger);
    $this->assertTrue($tracer->isEnabled());
  }

}
