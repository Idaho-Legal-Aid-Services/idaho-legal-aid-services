<?php

namespace Drupal\Tests\ilas_site_assistant\Unit;

use Drupal;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\ilas_site_assistant\Plugin\QueueWorker\LangfuseExportWorker;
use Drupal\ilas_site_assistant\Service\QueueHealthMonitor;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Request;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Drupal\Core\Queue\SuspendQueueException;

/**
 * Tests LangfuseExportWorker age-based discard and normal processing.
 */
#[CoversClass(LangfuseExportWorker::class)]
#[Group('ilas_site_assistant')]
class LangfuseExportWorkerTest extends TestCase {

  /**
   * Builds a LangfuseExportWorker with configurable mocks.
   *
   * @param int $maxAge
   *   Configured max item age in seconds.
   * @param bool $langfuseEnabled
   *   Whether Langfuse is enabled in config.
   *
   * @return array
   *   Keyed array with 'worker', 'httpClient', 'logger' mocks.
   */
  private function buildWorker(int $maxAge = 3600, bool $langfuseEnabled = TRUE): array {
    $config = $this->createStub(ImmutableConfig::class);
    $config->method('get')->willReturnCallback(fn($key) => match ($key) {
      'langfuse.max_item_age_seconds' => $maxAge,
      'langfuse.enabled' => $langfuseEnabled,
      'langfuse.host' => 'https://us.cloud.langfuse.com',
      'langfuse.public_key' => 'pk-test-123',
      'langfuse.secret_key' => 'sk-test-456',
      'langfuse.timeout' => 5.0,
      default => NULL,
    });

    $configFactory = $this->createStub(ConfigFactoryInterface::class);
    $configFactory->method('get')
      ->with('ilas_site_assistant.settings')
      ->willReturn($config);

    $httpClient = $this->createMock(ClientInterface::class);
    $logger = $this->createMock(LoggerInterface::class);

    $worker = new LangfuseExportWorker(
      [],
      'ilas_langfuse_export',
      ['cron' => ['time' => 30]],
      $configFactory,
      $httpClient,
      $logger,
    );

    return [
      'worker' => $worker,
      'httpClient' => $httpClient,
      'logger' => $logger,
    ];
  }

  /**
   * Builds a valid payload with the given enqueued_at timestamp.
   *
   * @param int|null $enqueuedAt
   *   The enqueued_at timestamp, or NULL to omit it.
   *
   * @return array
   *   A queue item payload.
   */
  private function buildPayload(?int $enqueuedAt = NULL): array {
    $payload = [
      'batch' => [
        ['type' => 'trace-create', 'body' => ['id' => 'trace-001']],
      ],
      'metadata' => ['batch_size' => 1, 'sdk_name' => 'ilas-langfuse-tracer'],
    ];
    if ($enqueuedAt !== NULL) {
      $payload['enqueued_at'] = $enqueuedAt;
    }
    return $payload;
  }

  /**
   * Runs a callback with a mocked queue-health monitor in the Drupal container.
   */
  private function withQueueHealthMonitor(QueueHealthMonitor $monitor, callable $callback): void {
    $container = new ContainerBuilder();
    $container->set('ilas_site_assistant.queue_health_monitor', $monitor);
    Drupal::setContainer($container);

    try {
      $callback();
    }
    finally {
      Drupal::setContainer(new ContainerBuilder());
    }
  }

  /**
   * Tests that a recent item is processed normally (HTTP call made).
   */
  public function testRecentItemProcessedNormally(): void {
    $mocks = $this->buildWorker();

    $mocks['httpClient']->expects($this->once())
      ->method('request')
      ->with('POST', $this->anything(), $this->anything())
      ->willReturn(new Response(200));

    $mocks['logger']->expects($this->once())
      ->method('info')
      ->with($this->stringContains('sent'), $this->anything());

    $mocks['worker']->processItem($this->buildPayload(time() - 60));
  }

  /**
   * Tests that a 207 response is treated as partial success, not generic 2xx.
   */
  public function testPartialSuccessResponseLoggedAsNotice(): void {
    $mocks = $this->buildWorker();

    $mocks['httpClient']->expects($this->once())
      ->method('request')
      ->willReturn(new Response(207, [], json_encode([
        'successes' => [['id' => 'ok-1']],
        'errors' => [['id' => 'err-1']],
      ], JSON_THROW_ON_ERROR)));

    $mocks['logger']->expects($this->once())
      ->method('notice')
      ->with($this->stringContains('partial success'), $this->anything());
    $mocks['logger']->expects($this->never())
      ->method('info');

    $monitor = $this->createMock(QueueHealthMonitor::class);
    $monitor->expects($this->once())
      ->method('recordOutcome')
      ->with(
        'send_partial_207',
        $this->callback(function (array $metadata): bool {
          return ($metadata['http_status'] ?? NULL) === 207
            && ($metadata['event_count'] ?? NULL) === 1
            && ($metadata['success_count'] ?? NULL) === 1
            && ($metadata['error_count'] ?? NULL) === 1;
        }),
      );

    $this->withQueueHealthMonitor($monitor, function () use ($mocks): void {
      $mocks['worker']->processItem($this->buildPayload(time() - 60));
    });
  }

  /**
   * Tests that invalid queue item shapes are discarded before any HTTP call.
   */
  public function testInvalidQueueItemDiscarded(): void {
    $mocks = $this->buildWorker();

    $mocks['httpClient']->expects($this->never())->method('request');

    $mocks['logger']->expects($this->once())
      ->method('warning')
      ->with($this->stringContains('invalid queue item'));

    $monitor = $this->createMock(QueueHealthMonitor::class);
    $monitor->expects($this->once())
      ->method('recordOutcome')
      ->with(
        'discard_invalid_shape',
        $this->callback(function (array $metadata): bool {
          return ($metadata['event_count'] ?? NULL) === 0;
        }),
      );

    $this->withQueueHealthMonitor($monitor, function () use ($mocks): void {
      $mocks['worker']->processItem([
        'payload' => $this->buildPayload(time() - 60),
        'enqueued_at' => time(),
      ]);
    });
  }

  /**
   * Tests that a stale item is discarded without making an HTTP call.
   */
  public function testStaleItemDiscarded(): void {
    $mocks = $this->buildWorker(maxAge: 3600);

    $mocks['httpClient']->expects($this->never())->method('request');

    $mocks['logger']->expects($this->once())
      ->method('notice')
      ->with($this->stringContains('aged'), $this->anything());

    $monitor = $this->createMock(QueueHealthMonitor::class);
    $monitor->expects($this->once())
      ->method('recordOutcome')
      ->with(
        'discard_stale',
        $this->callback(function (array $metadata): bool {
          return ($metadata['event_count'] ?? NULL) === 1
            && ($metadata['max_age_seconds'] ?? NULL) === 3600
            && (int) ($metadata['item_age_seconds'] ?? 0) >= 7200;
        }),
      );

    $this->withQueueHealthMonitor($monitor, function () use ($mocks): void {
      $mocks['worker']->processItem($this->buildPayload(time() - 7200));
    });
  }

  /**
   * Tests that an item without enqueued_at is discarded as pre-upgrade.
   */
  public function testMissingEnqueuedAtDiscarded(): void {
    $mocks = $this->buildWorker();

    $mocks['httpClient']->expects($this->never())->method('request');

    $mocks['logger']->expects($this->once())
      ->method('notice')
      ->with($this->stringContains('pre-upgrade'), $this->anything());

    $monitor = $this->createMock(QueueHealthMonitor::class);
    $monitor->expects($this->once())
      ->method('recordOutcome')
      ->with(
        'discard_missing_enqueued_at',
        $this->callback(function (array $metadata): bool {
          return ($metadata['event_count'] ?? NULL) === 1;
        }),
      );

    $this->withQueueHealthMonitor($monitor, function () use ($mocks): void {
      $mocks['worker']->processItem($this->buildPayload());
    });
  }

  /**
   * Tests that a custom max age from config is respected.
   */
  public function testCustomMaxAgeFromConfig(): void {
    $mocks = $this->buildWorker(maxAge: 120);

    $mocks['httpClient']->expects($this->never())->method('request');

    $mocks['logger']->expects($this->once())
      ->method('notice')
      ->with($this->stringContains('aged'), $this->anything());

    $mocks['worker']->processItem($this->buildPayload(time() - 150));
  }

  /**
   * Tests that an item just within max age is processed.
   */
  public function testItemJustWithinMaxAgeProcessed(): void {
    $mocks = $this->buildWorker(maxAge: 3600);

    $mocks['httpClient']->expects($this->once())
      ->method('request')
      ->willReturn(new Response(200));

    $monitor = $this->createMock(QueueHealthMonitor::class);
    $monitor->expects($this->once())
      ->method('recordOutcome')
      ->with(
        'send_success',
        $this->callback(function (array $metadata): bool {
          return ($metadata['http_status'] ?? NULL) === 200
            && ($metadata['event_count'] ?? NULL) === 1;
        }),
      );

    $this->withQueueHealthMonitor($monitor, function () use ($mocks): void {
      $mocks['worker']->processItem($this->buildPayload(time() - 3599));
    });
  }

  /**
   * Tests that stale check runs before credential check.
   *
   * If an item is stale AND credentials are empty, it should be discarded
   * by the age check (notice says "aged"), not by the credential check.
   */
  public function testStaleCheckRunsBeforeCredentialCheck(): void {
    // Build a worker with empty credentials.
    $config = $this->createStub(ImmutableConfig::class);
    $config->method('get')->willReturnCallback(fn($key) => match ($key) {
      'langfuse.max_item_age_seconds' => 3600,
      'langfuse.enabled' => TRUE,
      'langfuse.host' => 'https://us.cloud.langfuse.com',
      'langfuse.public_key' => '',
      'langfuse.secret_key' => '',
      'langfuse.timeout' => 5.0,
      default => NULL,
    });

    $configFactory = $this->createStub(ConfigFactoryInterface::class);
    $configFactory->method('get')
      ->with('ilas_site_assistant.settings')
      ->willReturn($config);

    $httpClient = $this->createMock(ClientInterface::class);
    $httpClient->expects($this->never())->method('request');

    $logger = $this->createMock(LoggerInterface::class);

    // Expect a notice about age, not a warning about credentials.
    $logger->expects($this->once())
      ->method('notice')
      ->with($this->stringContains('aged'), $this->anything());
    $logger->expects($this->never())->method('warning');

    $worker = new LangfuseExportWorker(
      [],
      'ilas_langfuse_export',
      ['cron' => ['time' => 30]],
      $configFactory,
      $httpClient,
      $logger,
    );

    $worker->processItem($this->buildPayload(time() - 7200));
  }

  /**
   * Tests disabled Langfuse items are explicitly accounted for.
   */
  public function testDisabledItemRecordsDiscardDisabled(): void {
    $mocks = $this->buildWorker(langfuseEnabled: FALSE);

    $mocks['httpClient']->expects($this->never())->method('request');
    $mocks['logger']->expects($this->once())
      ->method('notice')
      ->with($this->stringContains('tracing disabled'), $this->anything());

    $monitor = $this->createMock(QueueHealthMonitor::class);
    $monitor->expects($this->once())
      ->method('recordOutcome')
      ->with(
        'discard_disabled',
        $this->callback(function (array $metadata): bool {
          return ($metadata['event_count'] ?? NULL) === 1;
        }),
      );

    $this->withQueueHealthMonitor($monitor, function () use ($mocks): void {
      $mocks['worker']->processItem($this->buildPayload(time() - 60));
    });
  }

  /**
   * Tests missing credentials are explicitly accounted for.
   */
  public function testMissingCredentialsRecordsDiscardOutcome(): void {
    $config = $this->createStub(ImmutableConfig::class);
    $config->method('get')->willReturnCallback(fn($key) => match ($key) {
      'langfuse.max_item_age_seconds' => 3600,
      'langfuse.enabled' => TRUE,
      'langfuse.host' => 'https://us.cloud.langfuse.com',
      'langfuse.public_key' => '',
      'langfuse.secret_key' => '',
      'langfuse.timeout' => 5.0,
      default => NULL,
    });

    $configFactory = $this->createStub(ConfigFactoryInterface::class);
    $configFactory->method('get')
      ->with('ilas_site_assistant.settings')
      ->willReturn($config);

    $httpClient = $this->createMock(ClientInterface::class);
    $httpClient->expects($this->never())->method('request');

    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->once())
      ->method('warning')
      ->with($this->stringContains('credentials not configured'));

    $worker = new LangfuseExportWorker(
      [],
      'ilas_langfuse_export',
      ['cron' => ['time' => 30]],
      $configFactory,
      $httpClient,
      $logger,
    );

    $monitor = $this->createMock(QueueHealthMonitor::class);
    $monitor->expects($this->once())
      ->method('recordOutcome')
      ->with(
        'discard_missing_credentials',
        $this->callback(function (array $metadata): bool {
          return ($metadata['event_count'] ?? NULL) === 1;
        }),
      );

    $this->withQueueHealthMonitor($monitor, function () use ($worker): void {
      $worker->processItem($this->buildPayload(time() - 60));
    });
  }

  /**
   * Tests non-retryable HTTP errors are accounted for and discarded.
   */
  public function testNonRetryableHttpErrorRecordsDiscardOutcome(): void {
    $mocks = $this->buildWorker();

    $request = new Request('POST', 'https://us.cloud.langfuse.com/api/public/ingestion');
    $response = new Response(400, [], '{"message":"bad request"}');
    $exception = new ClientException('bad request', $request, $response);

    $mocks['httpClient']->expects($this->once())
      ->method('request')
      ->willThrowException($exception);

    $mocks['logger']->expects($this->once())
      ->method('error')
      ->with($this->stringContains('non-retryable error'), $this->anything());

    $monitor = $this->createMock(QueueHealthMonitor::class);
    $monitor->expects($this->once())
      ->method('recordOutcome')
      ->with(
        'discard_non_retryable_http',
        $this->callback(function (array $metadata): bool {
          return ($metadata['http_status'] ?? NULL) === 400
            && ($metadata['event_count'] ?? NULL) === 1;
        }),
      );

    $this->withQueueHealthMonitor($monitor, function () use ($mocks): void {
      $mocks['worker']->processItem($this->buildPayload(time() - 60));
    });
  }

  /**
   * Tests retryable transport failures are accounted for before suspension.
   */
  public function testRetryableTransportFailureRecordsSuspendOutcome(): void {
    $mocks = $this->buildWorker();

    $request = new Request('POST', 'https://us.cloud.langfuse.com/api/public/ingestion');
    $exception = new ConnectException('timeout', $request);

    $mocks['httpClient']->expects($this->once())
      ->method('request')
      ->willThrowException($exception);

    $mocks['logger']->expects($this->once())
      ->method('error')
      ->with($this->stringContains('retryable error'), $this->anything());

    $monitor = $this->createMock(QueueHealthMonitor::class);
    $monitor->expects($this->once())
      ->method('recordOutcome')
      ->with(
        'retryable_suspend',
        $this->callback(function (array $metadata): bool {
          return ($metadata['http_status'] ?? NULL) === 0
            && ($metadata['event_count'] ?? NULL) === 1;
        }),
      );

    $this->withQueueHealthMonitor($monitor, function () use ($mocks): void {
      $this->expectException(SuspendQueueException::class);
      $mocks['worker']->processItem($this->buildPayload(time() - 60));
    });
  }

}
