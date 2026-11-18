<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\ilas_site_assistant\Service\LangfuseOtlpPayloadBuilder;
use Drupal\ilas_site_assistant\Service\LangfuseTracer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests conversion of tracer batches to OTLP spans for Langfuse.
 *
 * The id derivations asserted here were verified against Langfuse Cloud on
 * 2026-11-18: a hyphen-stripped UUID is accepted as the trace id, and an event
 * sent later with the derived root span id as its parent attaches to the
 * existing trace.
 */
#[CoversClass(LangfuseOtlpPayloadBuilder::class)]
#[Group('ilas_site_assistant')]
final class LangfuseOtlpPayloadBuilderTest extends TestCase {

  private const TRACE_UUID = '06999e10-1f0b-4be8-b574-71abd2864da4';

  /**
   * Returns the spans of a built body.
   */
  private function spans(array $body): array {
    return $body['resourceSpans'][0]['scopeSpans'][0]['spans'] ?? [];
  }

  /**
   * Flattens a span's OTLP attribute list to key => scalar/list.
   */
  private function attributes(array $span): array {
    $flat = [];
    foreach ($span['attributes'] as $attribute) {
      $value = $attribute['value'];
      $flat[$attribute['key']] = isset($value['arrayValue'])
        ? array_column($value['arrayValue']['values'], 'stringValue')
        : reset($value);
    }
    return $flat;
  }

  /**
   * A representative batch: trace, nested span, generation and event.
   */
  private function batch(): array {
    return [
      [
        'type' => 'trace-create',
        'body' => [
          'id' => self::TRACE_UUID,
          'timestamp' => '2026-11-18T17:00:00.000000Z',
          'name' => 'assistant.message',
          'sessionId' => 'session-hash',
          'tags' => ['assistant', 'aila', 'message'],
          'release' => 'live_190',
          'version' => 'abc1234',
          'input' => 'hash=abc len=25-99',
          'output' => 'type=faq',
          'metadata' => ['environment' => 'pantheon-live', 'intent' => 'faq', 'input_fields' => ['a' => 1]],
        ],
      ],
      [
        'type' => 'span-create',
        'body' => [
          'id' => '11111111-2222-4333-8444-555555555555',
          'traceId' => self::TRACE_UUID,
          'name' => 'retrieval',
          'startTime' => '2026-11-18T17:00:00.100000Z',
          'endTime' => '2026-11-18T17:00:00.900000Z',
          'metadata' => ['results' => 3, 'cached' => FALSE],
          'output' => 'results=3',
        ],
      ],
      [
        'type' => 'generation-create',
        'body' => [
          'id' => 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee',
          'traceId' => self::TRACE_UUID,
          'parentObservationId' => '11111111-2222-4333-8444-555555555555',
          'name' => 'llm.intent_classification',
          'model' => 'command-a',
          'modelParameters' => ['temperature' => 0.3, 'provider' => 'cohere'],
          'usage' => ['input' => 10, 'output' => 20, 'total' => 30],
          'startTime' => '2026-11-18T17:00:00.200000Z',
          'endTime' => '2026-11-18T17:00:01.500000Z',
        ],
      ],
      [
        'type' => 'event-create',
        'body' => [
          'id' => '99999999-8888-4777-8666-555555555555',
          'traceId' => self::TRACE_UUID,
          'parentObservationId' => '11111111-2222-4333-8444-555555555555',
          'name' => 'vector.backoff',
          'level' => 'WARNING',
          'startTime' => '2026-11-18T17:00:00.300000Z',
        ],
      ],
    ];
  }

  /**
   * The trace becomes a root span carrying the trace input and output.
   */
  public function testTraceBecomesRootSpan(): void {
    $spans = $this->spans(LangfuseOtlpPayloadBuilder::build($this->batch()));

    $this->assertCount(4, $spans);
    $root = $spans[0];
    $this->assertSame('06999e101f0b4be8b57471abd2864da4', $root['traceId']);
    $this->assertSame(LangfuseOtlpPayloadBuilder::rootSpanId(self::TRACE_UUID), $root['spanId']);
    $this->assertArrayNotHasKey('parentSpanId', $root);
    $this->assertSame('assistant.message', $root['name']);
    $this->assertSame('1795021200000000000', $root['startTimeUnixNano']);
    // The root span ends with the latest observation.
    $this->assertSame('1795021201500000000', $root['endTimeUnixNano']);

    $attributes = $this->attributes($root);
    $this->assertSame('hash=abc len=25-99', $attributes['langfuse.observation.input']);
    $this->assertSame('type=faq', $attributes['langfuse.observation.output']);
  }

  /**
   * Observations keep their nesting; top-level ones hang off the root span.
   */
  public function testObservationParentage(): void {
    $spans = $this->spans(LangfuseOtlpPayloadBuilder::build($this->batch()));
    $root = LangfuseOtlpPayloadBuilder::rootSpanId(self::TRACE_UUID);

    $this->assertSame('1111111122224333', $spans[1]['spanId']);
    $this->assertSame($root, $spans[1]['parentSpanId']);
    $this->assertSame('1111111122224333', $spans[2]['parentSpanId']);
    $this->assertSame('1111111122224333', $spans[3]['parentSpanId']);
    foreach ($spans as $span) {
      $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $span['traceId']);
      $this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $span['spanId']);
    }
  }

  /**
   * Each event type maps to its Langfuse observation type and attributes.
   */
  public function testObservationTypesAndAttributes(): void {
    $spans = $this->spans(LangfuseOtlpPayloadBuilder::build($this->batch()));

    $span = $this->attributes($spans[1]);
    $this->assertSame('span', $span['langfuse.observation.type']);
    $this->assertSame('3', $span['langfuse.observation.metadata.results']);
    $this->assertFalse($span['langfuse.observation.metadata.cached']);
    $this->assertSame('results=3', $span['langfuse.observation.output']);

    $generation = $this->attributes($spans[2]);
    $this->assertSame('generation', $generation['langfuse.observation.type']);
    $this->assertSame('command-a', $generation['langfuse.observation.model.name']);
    $this->assertSame(['temperature' => 0.3, 'provider' => 'cohere'], json_decode($generation['langfuse.observation.model.parameters'], TRUE));
    $this->assertSame(['input' => 10, 'output' => 20, 'total' => 30], json_decode($generation['langfuse.observation.usage_details'], TRUE));

    $event = $this->attributes($spans[3]);
    $this->assertSame('event', $event['langfuse.observation.type']);
    $this->assertSame('WARNING', $event['langfuse.observation.level']);
    $this->assertSame($spans[3]['startTimeUnixNano'], $spans[3]['endTimeUnixNano']);
    $this->assertSame('1795021200300000000', $spans[3]['startTimeUnixNano']);
  }

  /**
   * Trace-level attributes are repeated on every span of the trace.
   */
  public function testTraceAttributesPropagateToEverySpan(): void {
    $spans = $this->spans(LangfuseOtlpPayloadBuilder::build($this->batch()));

    foreach ($spans as $span) {
      $attributes = $this->attributes($span);
      $this->assertSame('assistant.message', $attributes['langfuse.trace.name']);
      $this->assertSame('session-hash', $attributes['langfuse.session.id']);
      $this->assertSame(['assistant', 'aila', 'message'], $attributes['langfuse.trace.tags']);
      $this->assertSame('live_190', $attributes['langfuse.release']);
      $this->assertSame('abc1234', $attributes['langfuse.version']);
      $this->assertSame('pantheon-live', $attributes['langfuse.environment']);
      $this->assertSame('faq', $attributes['langfuse.trace.metadata.intent']);
      $this->assertSame('{"a":1}', $attributes['langfuse.trace.metadata.input_fields']);
      $this->assertArrayNotHasKey('langfuse.user.id', $attributes);
    }
  }

  /**
   * An explicit environment wins over the trace metadata.
   */
  public function testExplicitEnvironmentWins(): void {
    $spans = $this->spans(LangfuseOtlpPayloadBuilder::build($this->batch(), 'pantheon-test'));

    $this->assertSame('pantheon-test', $this->attributes($spans[0])['langfuse.environment']);
  }

  /**
   * A feedback event for an earlier trace attaches to that trace's root span.
   */
  public function testStandaloneEventAttachesToExistingTrace(): void {
    $body = LangfuseOtlpPayloadBuilder::build([
      [
        'type' => 'event-create',
        'body' => [
          'id' => '12345678-1234-4234-8234-123456789012',
          'traceId' => self::TRACE_UUID,
          'name' => 'feedback.received',
          'level' => 'DEFAULT',
          'metadata' => ['feedback_value' => 'helpful'],
          'startTime' => '2026-11-18T17:05:00.000000Z',
        ],
      ],
    ], 'pantheon-live');

    $spans = $this->spans($body);
    $this->assertCount(1, $spans);
    $this->assertSame('06999e101f0b4be8b57471abd2864da4', $spans[0]['traceId']);
    $this->assertSame(LangfuseOtlpPayloadBuilder::rootSpanId(self::TRACE_UUID), $spans[0]['parentSpanId']);

    $attributes = $this->attributes($spans[0]);
    $this->assertSame('helpful', $attributes['langfuse.observation.metadata.feedback_value']);
    $this->assertSame('pantheon-live', $attributes['langfuse.environment']);
    // No trace-create in the batch, so nothing may rename the existing trace.
    $this->assertArrayNotHasKey('langfuse.trace.name', $attributes);
  }

  /**
   * Ids that are not UUIDs still yield valid, stable OTLP ids.
   */
  public function testNonUuidIdsAreHashedDeterministically(): void {
    $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', LangfuseOtlpPayloadBuilder::traceId('trace-001'));
    $this->assertSame(LangfuseOtlpPayloadBuilder::traceId('trace-001'), LangfuseOtlpPayloadBuilder::traceId('trace-001'));
    $this->assertNotSame(LangfuseOtlpPayloadBuilder::traceId('trace-001'), LangfuseOtlpPayloadBuilder::traceId('trace-002'));
    $this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', LangfuseOtlpPayloadBuilder::spanId('span one'));
    // All-zero ids are invalid in OTLP.
    $this->assertNotSame(str_repeat('0', 32), LangfuseOtlpPayloadBuilder::traceId('00000000-0000-0000-0000-000000000000'));
    $this->assertNotSame(str_repeat('0', 16), LangfuseOtlpPayloadBuilder::spanId('00000000-0000-0000-0000-000000000000'));
  }

  /**
   * Batches with nothing exportable produce an empty body.
   */
  public function testUnexportableBatchesYieldNoSpans(): void {
    foreach ([[], ['junk'], [['type' => 'score-create', 'body' => ['id' => 'x']]], [['type' => 'span-create']]] as $batch) {
      $body = LangfuseOtlpPayloadBuilder::build($batch);
      $this->assertSame(0, LangfuseOtlpPayloadBuilder::spanCount($body));
    }
  }

  /**
   * Timestamps convert to unix nanoseconds without float rounding.
   */
  public function testTimestampConversion(): void {
    $this->assertSame('1795021200123456000', LangfuseOtlpPayloadBuilder::toUnixNano('2026-11-18T17:00:00.123456Z'));
    $this->assertNull(LangfuseOtlpPayloadBuilder::toUnixNano('not a date'));
    $this->assertNull(LangfuseOtlpPayloadBuilder::toUnixNano(NULL));
  }

  /**
   * A real tracer run converts into one connected trace.
   */
  public function testRealTracerPayloadConverts(): void {
    $config = $this->createStub(ImmutableConfig::class);
    $config->method('get')->willReturnCallback(fn($key) => match ($key) {
      'langfuse.enabled' => TRUE,
      'langfuse.public_key' => 'pk-test-123',
      'langfuse.secret_key' => 'sk-test-456',
      'langfuse.sample_rate' => 1.0,
      default => NULL,
    });
    $configFactory = $this->createStub(ConfigFactoryInterface::class);
    $configFactory->method('get')->willReturn($config);
    $tracer = new LangfuseTracer($configFactory, $this->createStub(LoggerInterface::class));

    $tracer->startTrace(self::TRACE_UUID, 'assistant.message', ['environment' => 'local'], 'in', 'session-hash', ['assistant']);
    $tracer->startSpan('retrieval');
    $tracer->startGeneration('llm.classify', 'command-a', ['temperature' => 0.3], ['input_hash' => 'abc']);
    $tracer->endGeneration('intent=faq', ['input' => 1, 'output' => 2, 'total' => 3]);
    $tracer->addEvent('cache.miss');
    $tracer->endSpan(['results' => 3]);
    $tracer->endTrace('out');

    $payload = $tracer->getTracePayload();
    $body = LangfuseOtlpPayloadBuilder::build($payload['batch']);
    $spans = $this->spans($body);

    $this->assertSame(count($payload['batch']), LangfuseOtlpPayloadBuilder::spanCount($body));
    $this->assertCount(1, array_unique(array_column($spans, 'traceId')));
    $this->assertCount(count($spans), array_unique(array_column($spans, 'spanId')));

    // Every non-root span has a parent that is in the batch.
    $ids = array_column($spans, 'spanId');
    foreach (array_slice($spans, 1) as $span) {
      $this->assertContains($span['parentSpanId'], $ids);
      $this->assertLessThanOrEqual($span['endTimeUnixNano'], $span['startTimeUnixNano']);
    }
    $this->assertSame('local', $this->attributes($spans[0])['langfuse.environment']);
    $this->assertNotFalse(json_encode($body, JSON_THROW_ON_ERROR));
  }

}
