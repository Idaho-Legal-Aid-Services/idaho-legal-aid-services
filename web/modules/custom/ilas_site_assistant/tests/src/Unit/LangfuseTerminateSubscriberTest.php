<?php

namespace Drupal\Tests\ilas_site_assistant\Unit;

use Drupal;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Queue\QueueInterface;
use Drupal\ilas_site_assistant\EventSubscriber\LangfuseTerminateSubscriber;
use Drupal\ilas_site_assistant\Service\LangfuseTracer;
use Drupal\ilas_site_assistant\Service\QueueHealthMonitor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests LangfuseTerminateSubscriber queue depth cap and enqueued_at stamping.
 */
#[CoversClass(LangfuseTerminateSubscriber::class)]
#[Group('ilas_site_assistant')]
class LangfuseTerminateSubscriberTest extends TestCase {

  /**
   * Builds a subscriber with configurable mocks.
   *
   * @param bool $tracerActive
   *   Whether the tracer reports as active.
   * @param array|null $payload
   *   The payload returned by getTracePayload(), or NULL.
   * @param int $queueDepth
   *   Current number of items in the queue.
   * @param int $maxDepth
   *   Configured max queue depth.
   * @param \Throwable|null $tracerException
   *   Optional exception for getTracePayload() to throw.
   * @param \Throwable|null $queueCreateException
   *   Optional exception for queue createItem() to throw.
   *
   * @return array
   *   Keyed array with 'subscriber', 'queue', 'logger' mocks.
   */
  private function buildSubscriber(
    bool $tracerActive = TRUE,
    ?array $payload = NULL,
    int $queueDepth = 0,
    int $maxDepth = 10000,
    ?\Throwable $tracerException = NULL,
    ?\Throwable $queueCreateException = NULL,
  ): array {
    $tracer = $this->createMock(LangfuseTracer::class);
    $tracer->method('isActive')->willReturn($tracerActive);

    if ($tracerException !== NULL) {
      $tracer->method('getTracePayload')->willThrowException($tracerException);
    }
    else {
      $tracer->method('getTracePayload')->willReturn($payload);
    }

    $queue = $this->createMock(QueueInterface::class);
    $queue->method('numberOfItems')->willReturn($queueDepth);
    if ($queueCreateException !== NULL) {
      $queue->method('createItem')->willThrowException($queueCreateException);
    }

    $queueFactory = $this->createMock(QueueFactory::class);
    $queueFactory->method('get')->with('ilas_langfuse_export')->willReturn($queue);

    $config = $this->createStub(ImmutableConfig::class);
    $config->method('get')->willReturnCallback(fn($key) => match ($key) {
      'langfuse.max_queue_depth' => $maxDepth,
      default => NULL,
    });

    $configFactory = $this->createStub(ConfigFactoryInterface::class);
    $configFactory->method('get')
      ->with('ilas_site_assistant.settings')
      ->willReturn($config);

    $logger = $this->createMock(LoggerInterface::class);

    $subscriber = new LangfuseTerminateSubscriber(
      $tracer,
      $queueFactory,
      $configFactory,
      $logger,
    );

    return [
      'subscriber' => $subscriber,
      'queue' => $queue,
      'logger' => $logger,
      'tracer' => $tracer,
    ];
  }

  /**
   * Tests that a normal enqueue succeeds and stamps enqueued_at.
   */
  public function testNormalEnqueueSucceeds(): void {
    $payload = [
      'batch' => [['type' => 'trace-create', 'body' => []]],
      'metadata' => ['batch_size' => 1],
    ];

    $mocks = $this->buildSubscriber(
      tracerActive: TRUE,
      payload: $payload,
      queueDepth: 0,
    );

    $before = time();

    $mocks['queue']->expects($this->once())
      ->method('createItem')
      ->with($this->callback(function ($item) use ($before) {
        // Must have enqueued_at stamped.
        $this->assertArrayHasKey('enqueued_at', $item);
        $this->assertGreaterThanOrEqual($before, $item['enqueued_at']);
        $this->assertLessThanOrEqual(time(), $item['enqueued_at']);
        // Original payload keys preserved.
        $this->assertArrayHasKey('batch', $item);
        $this->assertArrayHasKey('metadata', $item);
        return TRUE;
      }));

    $mocks['subscriber']->onTerminate();
  }

  /**
   * Tests that enqueue is dropped when queue is exactly at max depth.
   */
  public function testEnqueueDroppedWhenQueueAtMax(): void {
    $payload = [
      'batch' => [['type' => 'trace-create', 'body' => []]],
      'metadata' => [],
    ];

    $mocks = $this->buildSubscriber(
      tracerActive: TRUE,
      payload: $payload,
      queueDepth: 10000,
      maxDepth: 10000,
    );

    $mocks['queue']->expects($this->never())->method('createItem');
    $mocks['logger']->expects($this->once())
      ->method('warning')
      ->with(
        $this->stringContains('dropping trace batch'),
        $this->anything(),
      );

    $mocks['subscriber']->onTerminate();
  }

  /**
   * Tests that enqueue is dropped when queue exceeds max depth.
   */
  public function testEnqueueDroppedWhenQueueAboveMax(): void {
    $payload = [
      'batch' => [['type' => 'trace-create', 'body' => []]],
      'metadata' => [],
    ];

    $mocks = $this->buildSubscriber(
      tracerActive: TRUE,
      payload: $payload,
      queueDepth: 15000,
      maxDepth: 10000,
    );

    $mocks['queue']->expects($this->never())->method('createItem');
    $mocks['logger']->expects($this->once())->method('warning');

    $mocks['subscriber']->onTerminate();
  }

  /**
   * Tests that a custom max depth from config is respected.
   */
  public function testCustomMaxDepthFromConfig(): void {
    $payload = [
      'batch' => [['type' => 'trace-create', 'body' => []]],
      'metadata' => [],
    ];

    $mocks = $this->buildSubscriber(
      tracerActive: TRUE,
      payload: $payload,
      queueDepth: 50,
      maxDepth: 50,
    );

    $mocks['queue']->expects($this->never())->method('createItem');
    $mocks['logger']->expects($this->once())->method('warning');

    $mocks['subscriber']->onTerminate();
  }

  /**
   * Tests that an inactive tracer skips entirely — no queue interaction.
   */
  public function testInactiveTracerSkipsEntirely(): void {
    $mocks = $this->buildSubscriber(
      tracerActive: FALSE,
    );

    $mocks['queue']->expects($this->never())->method('numberOfItems');
    $mocks['queue']->expects($this->never())->method('createItem');

    $mocks['subscriber']->onTerminate();
  }

  /**
   * Tests that a null payload skips enqueue.
   */
  public function testNullPayloadSkipsEnqueue(): void {
    $mocks = $this->buildSubscriber(
      tracerActive: TRUE,
      payload: NULL,
    );

    $mocks['queue']->expects($this->never())->method('createItem');

    $mocks['subscriber']->onTerminate();
  }

  /**
   * Tests that exceptions are logged, not propagated.
   */
  public function testExceptionLoggedNotPropagated(): void {
    $mocks = $this->buildSubscriber(
      tracerActive: TRUE,
      tracerException: new \RuntimeException('Tracer blew up'),
    );

    $mocks['logger']->expects($this->once())
      ->method('warning')
      ->with(
        $this->stringContains('enqueue failed'),
        $this->anything(),
      );

    // Must not throw.
    $mocks['subscriber']->onTerminate();
  }

  /**
   * Tests enqueue metadata recording for queue age monitoring.
   */
  public function testEnqueueMetadataRecordedWhenMonitorAvailable(): void {
    $payload = [
      'batch' => [['type' => 'trace-create', 'body' => []]],
      'metadata' => ['batch_size' => 1],
    ];

    $mocks = $this->buildSubscriber(
      tracerActive: TRUE,
      payload: $payload,
      queueDepth: 4,
    );

    $monitor = $this->createMock(QueueHealthMonitor::class);
    $monitor->expects($this->once())
      ->method('recordEnqueue')
      ->with(
        $this->greaterThan(0),
        4,
      );

    $container = new ContainerBuilder();
    $container->set('ilas_site_assistant.queue_health_monitor', $monitor);
    Drupal::setContainer($container);

    try {
      $mocks['subscriber']->onTerminate();
    }
    finally {
      Drupal::setContainer(new ContainerBuilder());
    }
  }

  /**
   * Tests max-depth drops record an explicit outcome when monitor is available.
   */
  public function testDropMaxDepthRecordsOutcomeWhenMonitorAvailable(): void {
    $payload = [
      'batch' => [['type' => 'trace-create', 'body' => []]],
      'metadata' => ['batch_size' => 1],
    ];

    $mocks = $this->buildSubscriber(
      tracerActive: TRUE,
      payload: $payload,
      queueDepth: 10,
      maxDepth: 10,
    );

    $monitor = $this->createMock(QueueHealthMonitor::class);
    $monitor->expects($this->once())
      ->method('recordOutcome')
      ->with(
        'drop_max_depth',
        $this->callback(function (array $metadata): bool {
          return ($metadata['queue_depth'] ?? NULL) === 10
            && ($metadata['max_depth'] ?? NULL) === 10
            && ($metadata['event_count'] ?? NULL) === 1;
        }),
      );

    $container = new ContainerBuilder();
    $container->set('ilas_site_assistant.queue_health_monitor', $monitor);
    Drupal::setContainer($container);

    try {
      $mocks['subscriber']->onTerminate();
    }
    finally {
      Drupal::setContainer(new ContainerBuilder());
    }
  }

  /**
   * Tests response-phase flush enqueues once and terminate does not duplicate it.
   */
  public function testResponseFlushPreventsTerminateDuplicateEnqueue(): void {
    $payload = [
      'batch' => [['type' => 'trace-create', 'body' => []]],
      'metadata' => ['batch_size' => 1],
    ];

    $mocks = $this->buildSubscriber(
      tracerActive: TRUE,
      payload: $payload,
      queueDepth: 0,
    );

    $mocks['queue']->expects($this->once())
      ->method('createItem')
      ->with($this->callback(function (array $item): bool {
        return array_key_exists('batch', $item)
          && array_key_exists('metadata', $item)
          && array_key_exists('enqueued_at', $item);
      }));

    $mocks['subscriber']->onResponse();
    $mocks['subscriber']->onTerminate();
  }

  /**
   * Tests terminate-time enqueue failures become explicit queue-loss outcomes.
   */
  public function testTerminateEnqueueFailureRecordsExplicitOutcome(): void {
    $payload = [
      'batch' => [['type' => 'trace-create', 'body' => []]],
      'metadata' => ['batch_size' => 1],
    ];

    $mocks = $this->buildSubscriber(
      tracerActive: TRUE,
      payload: $payload,
      queueDepth: 0,
      queueCreateException: new \RuntimeException('Queue backend failed'),
    );

    $monitor = $this->createMock(QueueHealthMonitor::class);
    $monitor->expects($this->once())
      ->method('recordOutcome')
      ->with(
        'drop_enqueue_failure',
        $this->callback(function (array $metadata): bool {
          return ($metadata['event_count'] ?? NULL) === 1
            && ($metadata['flush_stage'] ?? NULL) === 'terminate';
        }),
      );

    $container = new ContainerBuilder();
    $container->set('ilas_site_assistant.queue_health_monitor', $monitor);
    Drupal::setContainer($container);

    try {
      $mocks['subscriber']->onTerminate();
    }
    finally {
      Drupal::setContainer(new ContainerBuilder());
    }
  }

}
