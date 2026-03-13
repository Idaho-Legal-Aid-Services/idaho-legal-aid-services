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
   *
   * Regression test for OBS-1: endTrace() previously emitted 'trace-create'
   * instead of 'trace-update', causing duplicate traces in Langfuse.
   */
  public function testFullLifecycleEventTypeSequence(): void {
    $tracer = $this->buildTracer();

    $tracer->startTrace('trace-001', 'assistant.message', ['env' => 'test']);
    $tracer->startSpan('safety.classify');
    $tracer->endSpan('safe');
    $tracer->startSpan('retrieval');
    $tracer->startGeneration('llm.summarize', 'gemini-1.5-flash', ['temperature' => 0.3], 'prompt text');
    $tracer->endGeneration('summary response', ['input' => 10, 'output' => 20, 'total' => 30]);
    $tracer->endSpan(['results' => 3]);
    $tracer->endTrace('final output', ['total_ms' => 450]);

    $payload = $tracer->getTracePayload();

    $this->assertNotNull($payload, 'Payload should not be null after full lifecycle');

    $types = $this->extractEventTypes($payload);

    $expected = [
      'trace-create',       // startTrace
      'span-create',        // endSpan (safety.classify)
      'generation-create',  // endGeneration (llm.summarize)
      'span-create',        // endSpan (retrieval)
      'trace-update',       // endTrace — NOT trace-create
    ];

    $this->assertSame($expected, $types,
      'Event type sequence must be: trace-create, spans, generations, then trace-update');
  }

  /**
   * Tests that endTrace emits 'trace-update', not 'trace-create'.
   *
   * Minimal regression test for OBS-1.
   */
  public function testEndTraceEmitsTraceUpdate(): void {
    $tracer = $this->buildTracer();

    $tracer->startTrace('trace-002', 'assistant.message');
    $tracer->endTrace('done');

    $payload = $tracer->getTracePayload();
    $types = $this->extractEventTypes($payload);

    $this->assertSame(['trace-create', 'trace-update'], $types,
      'Minimal lifecycle must emit exactly trace-create then trace-update');
  }

  /**
   * Tests that trace-update body carries the trace ID, not a new UUID.
   *
   * The trace-update event must reference the same trace ID so Langfuse
   * merges it with the original trace rather than creating a new one.
   */
  public function testTraceUpdateBodyUsesOriginalTraceId(): void {
    $tracer = $this->buildTracer();

    $tracer->startTrace('trace-003', 'assistant.message');
    $tracer->endTrace('output', ['key' => 'value']);

    $payload = $tracer->getTracePayload();
    $batch = $payload['batch'];

    // First event: trace-create.
    $createEvent = $batch[0];
    $this->assertSame('trace-create', $createEvent['type']);
    $this->assertSame('trace-003', $createEvent['body']['id']);

    // Second event: trace-update.
    $updateEvent = $batch[1];
    $this->assertSame('trace-update', $updateEvent['type']);
    $this->assertSame('trace-003', $updateEvent['body']['id'],
      'trace-update body.id must match the original trace ID');
  }

  /**
   * Tests that endTrace closes dangling spans automatically.
   */
  public function testEndTraceClosesDanglingSpans(): void {
    $tracer = $this->buildTracer();

    $tracer->startTrace('trace-004', 'test');
    $tracer->startSpan('unclosed.span');
    // Do NOT call endSpan — endTrace should close it.
    $tracer->endTrace();

    $payload = $tracer->getTracePayload();
    $types = $this->extractEventTypes($payload);

    $this->assertSame(['trace-create', 'span-create', 'trace-update'], $types,
      'endTrace must auto-close dangling spans before emitting trace-update');
  }

  /**
   * Tests that endTrace closes a dangling generation automatically.
   */
  public function testEndTraceClosesDanglingGeneration(): void {
    $tracer = $this->buildTracer();

    $tracer->startTrace('trace-005', 'test');
    $tracer->startGeneration('llm.call', 'gemini-1.5-flash');
    // Do NOT call endGeneration — endTrace should close it.
    $tracer->endTrace();

    $payload = $tracer->getTracePayload();
    $types = $this->extractEventTypes($payload);

    $this->assertSame(['trace-create', 'generation-create', 'trace-update'], $types,
      'endTrace must auto-close dangling generation before emitting trace-update');
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

    $this->assertNull($tracer->getTracePayload(),
      'Disabled tracer must return NULL payload');
    $this->assertFalse($tracer->isActive(),
      'Disabled tracer must not be active');
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
    $this->assertSame(2, $payload['metadata']['batch_size']);
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

    $this->assertSame(['trace-create', 'event-create', 'trace-update'], $types);
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

    // batch[0] = trace-create
    // batch[1] = inner span-create (closed first due to stack)
    // batch[2] = outer span-create
    // batch[3] = trace-update
    $innerSpan = $batch[1];
    $outerSpan = $batch[2];

    $this->assertSame('span-create', $innerSpan['type']);
    $this->assertSame('span-create', $outerSpan['type']);

    // Inner span's parent should be the outer span's ID.
    $this->assertArrayHasKey('parentObservationId', $innerSpan['body']);
    // Outer span should have no parent observation (directly on trace).
    $this->assertArrayNotHasKey('parentObservationId', $outerSpan['body'],
      'Top-level span should not have a parentObservationId');
  }

}
