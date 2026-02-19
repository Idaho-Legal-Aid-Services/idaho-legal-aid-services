<?php

namespace Drupal\Tests\ilas_site_assistant\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\ilas_site_assistant\Plugin\QueueWorker\LangfuseExportWorker;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests LangfuseExportWorker age-based discard and normal processing.
 *
 * @coversDefaultClass \Drupal\ilas_site_assistant\Plugin\QueueWorker\LangfuseExportWorker
 */
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
   * Tests that a recent item is processed normally (HTTP call made).
   *
   * @covers ::processItem
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
   * Tests that a stale item is discarded without making an HTTP call.
   *
   * @covers ::processItem
   */
  public function testStaleItemDiscarded(): void {
    $mocks = $this->buildWorker(maxAge: 3600);

    $mocks['httpClient']->expects($this->never())->method('request');

    $mocks['logger']->expects($this->once())
      ->method('notice')
      ->with($this->stringContains('aged'), $this->anything());

    $mocks['worker']->processItem($this->buildPayload(time() - 7200));
  }

  /**
   * Tests that an item without enqueued_at is discarded as pre-upgrade.
   *
   * @covers ::processItem
   */
  public function testMissingEnqueuedAtDiscarded(): void {
    $mocks = $this->buildWorker();

    $mocks['httpClient']->expects($this->never())->method('request');

    $mocks['logger']->expects($this->once())
      ->method('notice')
      ->with($this->stringContains('pre-upgrade'), $this->anything());

    $mocks['worker']->processItem($this->buildPayload());
  }

  /**
   * Tests that a custom max age from config is respected.
   *
   * @covers ::processItem
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
   *
   * @covers ::processItem
   */
  public function testItemJustWithinMaxAgeProcessed(): void {
    $mocks = $this->buildWorker(maxAge: 3600);

    $mocks['httpClient']->expects($this->once())
      ->method('request')
      ->willReturn(new Response(200));

    $mocks['worker']->processItem($this->buildPayload(time() - 3599));
  }

  /**
   * Tests that stale check runs before credential check.
   *
   * If an item is stale AND credentials are empty, it should be discarded
   * by the age check (notice says "aged"), not by the credential check.
   *
   * @covers ::processItem
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

}
