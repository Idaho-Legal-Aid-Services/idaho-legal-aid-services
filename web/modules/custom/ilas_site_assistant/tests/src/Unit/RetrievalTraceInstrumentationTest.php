<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\ilas_site_assistant\EventSubscriber\SentryOptionsSubscriber;
use Drupal\ilas_site_assistant\Service\LangfuseTracer;
use Drupal\ilas_site_assistant\Service\RetrievalTraceTags;
use Drupal\ilas_site_assistant\Service\SentryStageSpan;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Sentry\SentrySdk;
use Sentry\State\Hub;
use Sentry\Tracing\SamplingContext;
use Sentry\Tracing\Transaction;
use Sentry\Tracing\TransactionContext;

/**
 * Covers the request-shape tags, stage spans and message-endpoint sampler.
 */
#[Group('ilas_site_assistant')]
final class RetrievalTraceInstrumentationTest extends TestCase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    SentrySdk::setCurrentHub(new Hub());
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    SentrySdk::setCurrentHub(new Hub());
    parent::tearDown();
  }

  /**
   * Builds one retrieval telemetry row.
   */
  private static function operation(string $service, string $status, bool $attempted, string $hash, ?string $reason = NULL): array {
    return [
      'service' => $service,
      'vector_status' => $status,
      'vector_attempted' => $attempted,
      'query_hash' => $hash,
      'degraded_reason' => $reason,
    ];
  }

  /**
   * Request shapes and the tags they must produce.
   */
  public static function requestShapes(): array {
    return [
      'no retrieval' => [
        [],
        ['vector_searches' => '0', 'faq_resource_query_identical' => 'na', 'vector_fallback' => 'no', 'vector_backoff' => 'none', 'vector_backoff_activations' => '0'],
      ],
      'faq only' => [
        [self::operation('faq', 'healthy', TRUE, 'aaa')],
        ['vector_searches' => '1', 'faq_resource_query_identical' => 'na', 'vector_fallback' => 'no', 'vector_backoff' => 'none', 'vector_backoff_activations' => '0'],
      ],
      'faq and resource, same text' => [
        [self::operation('faq', 'healthy', TRUE, 'aaa'), self::operation('resource', 'healthy_empty', TRUE, 'aaa')],
        ['vector_searches' => '2', 'faq_resource_query_identical' => 'yes', 'vector_fallback' => 'no', 'vector_backoff' => 'none', 'vector_backoff_activations' => '0'],
      ],
      'faq and resource, topic hint added' => [
        [self::operation('faq', 'healthy', TRUE, 'aaa'), self::operation('resource', 'healthy', TRUE, 'bbb')],
        ['vector_searches' => '2', 'faq_resource_query_identical' => 'no', 'vector_fallback' => 'no', 'vector_backoff' => 'none', 'vector_backoff_activations' => '0'],
      ],
      'cache hit is not a vector search' => [
        [self::operation('faq', 'cached', FALSE, 'aaa'), self::operation('resource', 'healthy', TRUE, 'aaa')],
        ['vector_searches' => '1', 'faq_resource_query_identical' => 'na', 'vector_fallback' => 'no', 'vector_backoff' => 'none', 'vector_backoff_activations' => '0'],
      ],
      'faq vector failed, lexical used' => [
        [self::operation('faq', 'degraded', TRUE, 'aaa', 'unexpected'), self::operation('resource', 'healthy', TRUE, 'aaa')],
        ['vector_searches' => '2', 'faq_resource_query_identical' => 'yes', 'vector_fallback' => 'yes', 'vector_backoff' => 'none', 'vector_backoff_activations' => '1', 'vector_fallback_reason' => 'unexpected'],
      ],
      'both services in backoff' => [
        [self::operation('faq', 'backoff', FALSE, 'aaa', 'backoff_active'), self::operation('resource', 'backoff', FALSE, 'aaa', 'backoff_active')],
        ['vector_searches' => '0', 'faq_resource_query_identical' => 'na', 'vector_fallback' => 'no', 'vector_backoff' => 'both', 'vector_backoff_activations' => '0'],
      ],
      'resource in backoff only' => [
        [self::operation('faq', 'healthy', TRUE, 'aaa'), self::operation('resource', 'backoff', FALSE, 'aaa', 'backoff_active')],
        ['vector_searches' => '1', 'faq_resource_query_identical' => 'na', 'vector_fallback' => 'no', 'vector_backoff' => 'resource', 'vector_backoff_activations' => '0'],
      ],
    ];
  }

  /**
   * Tags describe what the request did, in counts and yes/no values.
   */
  #[DataProvider('requestShapes')]
  public function testTagsDescribeTheRequestShape(array $operations, array $expected): void {
    $tags = RetrievalTraceTags::fromOperations($operations);

    ksort($tags);
    ksort($expected);
    $this->assertSame($expected, $tags);
  }

  /**
   * Every tag is approved, or the Sentry scrubber would drop it.
   */
  public function testEveryTagIsApprovedForSentry(): void {
    $this->assertSame([], array_diff(RetrievalTraceTags::TAGS, SentryOptionsSubscriber::APPROVED_TAGS));
  }

  /**
   * Tag values never carry the query hash, let alone text.
   */
  public function testTagValuesCarryNoQueryMaterial(): void {
    $tags = RetrievalTraceTags::fromOperations([
      self::operation('faq', 'healthy', TRUE, 'f00dfeed'),
      self::operation('resource', 'degraded', TRUE, 'deadbeef', 'latency_budget_exceeded'),
    ]);

    $this->assertStringNotContainsString('f00dfeed', json_encode($tags));
    $this->assertStringNotContainsString('deadbeef', json_encode($tags));
  }

  /**
   * Without a sampled transaction a stage span is a pass-through.
   */
  public function testStageSpanIsANoOpWithoutATransaction(): void {
    $this->assertNull(SentryStageSpan::start('faq.lexical_query'));
    SentryStageSpan::finish(NULL);
    $this->assertSame(42, SentryStageSpan::run('faq.lexical_query', static fn(): int => 42));
  }

  /**
   * Stage spans nest under the transaction and restore the current span.
   */
  public function testStageSpansAreRecordedAndNested(): void {
    $transaction = $this->startSampledTransaction();

    $result = SentryStageSpan::run('retrieval.early', static function (): string {
      return SentryStageSpan::run('faq.vector_query', static fn(): string => 'done');
    });

    $this->assertSame('done', $result);
    $this->assertSame($transaction, SentrySdk::getCurrentHub()->getSpan(), 'the transaction is current again');

    $spans = $this->stageSpans($transaction);
    $this->assertSame(['retrieval.early', 'faq.vector_query'], array_keys($spans));
    $this->assertSame($transaction->getSpanId(), $spans['retrieval.early']->getParentSpanId());
    $this->assertSame($spans['retrieval.early']->getSpanId(), $spans['faq.vector_query']->getParentSpanId());
    $this->assertNotNull($spans['faq.vector_query']->getEndTimestamp());
  }

  /**
   * A stage that throws still closes its span.
   */
  public function testStageSpanClosesWhenTheStageThrows(): void {
    $transaction = $this->startSampledTransaction();

    try {
      SentryStageSpan::run('faq.lexical_query', static function (): void {
        throw new \RuntimeException('index unavailable');
      });
      $this->fail('the exception must propagate');
    }
    catch (\RuntimeException) {
      // Expected.
    }

    $this->assertSame($transaction, SentrySdk::getCurrentHub()->getSpan());
    $this->assertNotNull($this->stageSpans($transaction)['faq.lexical_query']->getEndTimestamp());
  }

  /**
   * Controller stages reach Sentry even when Langfuse export is off.
   */
  public function testLangfuseTracerStagesAreMirroredWhenLangfuseIsDisabled(): void {
    $config = $this->createStub(ImmutableConfig::class);
    $config->method('get')->willReturn(NULL);
    $configFactory = $this->createStub(ConfigFactoryInterface::class);
    $configFactory->method('get')->willReturn($config);
    $tracer = new LangfuseTracer($configFactory, $this->createStub(LoggerInterface::class));

    $transaction = $this->startSampledTransaction();

    $tracer->startSpan('analytics.persist', ['intent_type' => 'faq'], 'what is a power of attorney');
    $tracer->endSpan(['event_count' => 1]);
    // An unmatched endSpan() must not disturb the transaction.
    $tracer->endSpan();

    $this->assertFalse($tracer->isActive());
    $spans = $this->stageSpans($transaction);
    $this->assertSame(['analytics.persist'], array_keys($spans));
    $this->assertSame([], $spans['analytics.persist']->getData(), 'metadata and input stay out of Sentry');
    $this->assertSame($transaction, SentrySdk::getCurrentHub()->getSpan());
  }

  /**
   * Transaction names and the sample rate each must get.
   */
  public static function sampledTransactions(): array {
    return [
      'message endpoint, unsampled parent' => ['POST https://idaholegalaid.org/assistant/api/message', FALSE, 1.0],
      'message endpoint, no parent' => ['POST https://idaholegalaid.org/assistant/api/message', NULL, 1.0],
      'message endpoint, language prefix' => ['POST https://idaholegalaid.org/es/assistant/api/message', NULL, 1.0],
      'message endpoint, route name' => ['POST /assistant/api/message', NULL, 1.0],
      'track endpoint keeps the default' => ['POST https://idaholegalaid.org/assistant/api/track', NULL, 0.1],
      'GET on the message path keeps the default' => ['GET https://idaholegalaid.org/assistant/api/message', NULL, 0.1],
      'look-alike path keeps the default' => ['POST https://idaholegalaid.org/assistant/api/message-export', NULL, 0.1],
      'page view follows a sampled parent' => ['GET https://idaholegalaid.org/forms', TRUE, 1.0],
      'page view follows an unsampled parent' => ['GET https://idaholegalaid.org/forms', FALSE, 0.0],
      'page view without a parent uses the rate' => ['GET https://idaholegalaid.org/forms', NULL, 0.1],
    ];
  }

  /**
   * Every message request is traced; other requests sample as before.
   */
  #[DataProvider('sampledTransactions')]
  public function testSamplerTracesEveryMessageRequest(string $name, ?bool $parent_sampled, float $expected): void {
    $context = TransactionContext::make()->setName($name);
    $sampling = SamplingContext::getDefault($context)->setParentSampled($parent_sampled);

    $this->assertSame($expected, (SentryOptionsSubscriber::tracesSamplerCallback(0.1))($sampling));
  }

  /**
   * The sampler is only installed where tracing is already configured.
   */
  public function testSamplerIsNotInstalledWhenTracingIsOff(): void {
    $subscriber = new SentryOptionsSubscriber();

    $off = (object) ['options' => []];
    $subscriber->onOptionsAlter($off);
    $this->assertArrayNotHasKey('traces_sampler', $off->options);

    $on = (object) ['options' => ['traces_sample_rate' => 0.1]];
    $subscriber->onOptionsAlter($on);
    $this->assertIsCallable($on->options['traces_sampler']);
  }

  /**
   * Puts a sampled transaction on the current hub.
   */
  private function startSampledTransaction(): Transaction {
    $transaction = new Transaction(
      TransactionContext::make()->setName('POST /assistant/api/message')->setSampled(TRUE)
    );
    $transaction->initSpanRecorder();
    SentrySdk::getCurrentHub()->setSpan($transaction);

    return $transaction;
  }

  /**
   * Returns the recorded stage spans keyed by stage name, in start order.
   *
   * @return array<string, \Sentry\Tracing\Span>
   *   Stage spans.
   */
  private function stageSpans(Transaction $transaction): array {
    $spans = [];
    foreach ($transaction->getSpanRecorder()->getSpans() as $span) {
      if ($span->getOp() === SentryStageSpan::OP) {
        $spans[(string) $span->getDescription()] = $span;
      }
    }

    return $spans;
  }

}
