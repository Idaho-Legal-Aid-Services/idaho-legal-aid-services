<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\State\StateInterface;
use Drupal\ilas_site_assistant\Service\LangfuseContractCheck;
use Drupal\ilas_site_assistant\Service\LangfuseTraceLookupService;
use Drupal\ilas_site_assistant\Service\ProviderHealthContractCheck;
use Drupal\ilas_site_assistant\Service\VendorContractCheckInterface;
use Drupal\ilas_site_assistant\Service\VendorContractDeferredCheckInterface;
use Drupal\ilas_site_assistant\Service\VendorContractMonitor;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests the scheduled vendor contract checks.
 */
#[Group('ilas_site_assistant')]
final class VendorContractMonitorTest extends TestCase {

  private const NOW = 1795000000;

  /**
   * The current time reported to the monitor.
   */
  private int $now = self::NOW;

  /**
   * State values written during a test.
   *
   * @var array<string, mixed>
   */
  private array $stateStore = [];

  /**
   * Builds a monitor over fixed check results.
   *
   * @param array<int, array<string, array{status: string, reason: string}>|\Throwable> $results
   *   One entry per check: its result, or an exception for it to throw.
   */
  private function buildMonitor(LoggerInterface $logger, array $results, array $config = []): VendorContractMonitor {
    $values = $config + [
      'vendor_contract_check.enabled' => TRUE,
      'vendor_contract_check.interval_hours' => 168,
    ];
    $immutable = $this->createStub(ImmutableConfig::class);
    $immutable->method('get')->willReturnCallback(fn($key) => $values[$key] ?? NULL);
    $configFactory = $this->createStub(ConfigFactoryInterface::class);
    $configFactory->method('get')->willReturn($immutable);

    $state = $this->createStub(StateInterface::class);
    $state->method('get')->willReturnCallback(fn($key, $default = NULL) => $this->stateStore[$key] ?? $default);
    $state->method('set')->willReturnCallback(function ($key, $value): void {
      $this->stateStore[$key] = $value;
    });

    $time = $this->createStub(TimeInterface::class);
    $time->method('getCurrentTime')->willReturnCallback(fn(): int => $this->now);

    $checks = [];
    foreach ($results as $result) {
      $check = $this->createMock(VendorContractCheckInterface::class);
      if ($result instanceof \Throwable) {
        $check->method('run')->willThrowException($result);
      }
      else {
        $check->method('run')->willReturn($result);
      }
      $checks[] = $check;
    }

    return new VendorContractMonitor($configFactory, $state, $time, $logger, $checks);
  }

  /**
   * All vendors passing or skipped is a clean run with no error logged.
   */
  public function testPassingRunLogsNoError(): void {
    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->never())->method('error');
    $logger->expects($this->once())->method('info');

    $report = $this->buildMonitor($logger, [
      [
        'cohere' => ['status' => 'skipped', 'reason' => 'disabled'],
        'voyage' => ['status' => 'pass', 'reason' => 'rerank_applied'],
      ],
      ['langfuse' => ['status' => 'pass', 'reason' => 'stored_and_read_back']],
    ])->run();

    $this->assertTrue($report['ok']);
    $this->assertSame(['cohere', 'voyage', 'langfuse'], array_keys($report['vendors']));
    $this->assertSame($report, $this->stateStore[VendorContractMonitor::STATE_LAST_REPORT]);
  }

  /**
   * Each failing vendor is logged as its own error, naming the vendor.
   */
  public function testFailingVendorsAreLoggedSeparately(): void {
    $messages = [];
    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->exactly(2))
      ->method('error')
      ->willReturnCallback(function (string $message, array $context) use (&$messages): void {
        $messages[] = [$message, $context['@reason']];
      });
    $logger->expects($this->never())->method('info');

    $report = $this->buildMonitor($logger, [
      [
        'cohere' => ['status' => 'fail', 'reason' => 'http_400'],
        'voyage' => ['status' => 'pass', 'reason' => 'rerank_applied'],
      ],
      ['langfuse' => ['status' => 'fail', 'reason' => 'export_http_410']],
    ])->run();

    $this->assertFalse($report['ok']);
    // The vendor is part of the message template so Sentry groups by vendor.
    $this->assertStringContainsString('Vendor contract check failed: cohere ', $messages[0][0]);
    $this->assertSame('http_400', $messages[0][1]);
    $this->assertStringContainsString('Vendor contract check failed: langfuse ', $messages[1][0]);
    $this->assertSame('export_http_410', $messages[1][1]);
  }

  /**
   * A check that throws is a failure of that check, not of the whole run.
   */
  public function testThrowingCheckIsRecordedAsFailure(): void {
    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->once())->method('error');

    $report = $this->buildMonitor($logger, [
      new \RuntimeException('boom'),
      ['langfuse' => ['status' => 'pass', 'reason' => 'stored_and_read_back']],
    ])->run();

    $this->assertFalse($report['ok']);
    $this->assertSame('pass', $report['vendors']['langfuse']['status']);
    $this->assertCount(2, $report['vendors']);
  }

  /**
   * The scheduled entry point respects the enabled flag and the interval.
   */
  public function testRunIfDueHonoursEnabledFlagAndInterval(): void {
    $logger = $this->createStub(LoggerInterface::class);
    $passing = [['voyage' => ['status' => 'pass', 'reason' => 'rerank_applied']]];

    $this->assertNull($this->buildMonitor($logger, $passing, ['vendor_contract_check.enabled' => FALSE])->runIfDue());
    $this->assertSame([], $this->stateStore);

    $monitor = $this->buildMonitor($logger, $passing);
    $this->assertIsArray($monitor->runIfDue());
    $this->assertSame(self::NOW, $this->stateStore[VendorContractMonitor::STATE_LAST_RUN]);
    // Second call inside the interval does nothing.
    $this->assertNull($monitor->runIfDue());

    // A run older than the interval is due again.
    $this->stateStore[VendorContractMonitor::STATE_LAST_RUN] = self::NOW - (169 * 3600);
    $this->assertIsArray($monitor->runIfDue());
  }

  /**
   * A disabled provider is skipped; an enabled one that fails is a failure.
   */
  public function testProviderReportMapping(): void {
    $mapped = ProviderHealthContractCheck::mapReport([
      'cohere' => ['ok' => FALSE, 'enabled' => FALSE, 'reason' => 'not_attempted'],
      'voyage' => ['ok' => FALSE, 'enabled' => TRUE, 'fallback_reason' => 'http 400 <b>'],
      'pinecone' => [
        'ok' => FALSE,
        'indexes' => [
          ['key' => 'faq', 'reachable' => TRUE, 'probe_status' => 'healthy'],
          ['key' => 'resources', 'reachable' => FALSE, 'probe_status' => 'failed'],
        ],
      ],
    ]);

    $this->assertSame(['status' => 'skipped', 'reason' => 'disabled'], $mapped['cohere']);
    $this->assertSame('fail', $mapped['voyage']['status']);
    $this->assertSame('http_400__b_', $mapped['voyage']['reason']);
    $this->assertSame(['status' => 'fail', 'reason' => 'resources:failed'], $mapped['pinecone']);

    $healthy = ProviderHealthContractCheck::mapReport([
      'cohere' => ['ok' => TRUE, 'enabled' => TRUE],
      'voyage' => ['ok' => TRUE, 'enabled' => TRUE],
      'pinecone' => ['ok' => TRUE, 'indexes' => [['key' => 'faq', 'reachable' => TRUE, 'probe_status' => 'healthy']]],
    ]);
    $this->assertSame(['pass', 'pass', 'pass'], array_column($healthy, 'status'));

    $inactive = ProviderHealthContractCheck::mapReport([
      'pinecone' => ['ok' => FALSE, 'indexes' => [['key' => 'faq', 'reachable' => FALSE, 'probe_status' => 'skipped']]],
    ]);
    $this->assertSame('skipped', $inactive['pinecone']['status']);
    // An exception while probing is a failure even with no index rows.
    $errored = ProviderHealthContractCheck::mapReport(['pinecone' => ['ok' => FALSE, 'indexes' => [], 'error' => 'x']]);
    $this->assertSame('fail', $errored['pinecone']['status']);
  }

  /**
   * Builds the Langfuse check over a mocked client and lookup service.
   */
  private function buildLangfuseCheck(ClientInterface $httpClient, LangfuseTraceLookupService $lookup, bool $enabled = TRUE): LangfuseContractCheck {
    $values = [
      'langfuse.enabled' => $enabled,
      'langfuse.public_key' => 'pk-test',
      'langfuse.secret_key' => 'sk-test',
      'langfuse.environment' => 'pantheon-live',
      'langfuse.timeout' => 5.0,
    ];
    $immutable = $this->createStub(ImmutableConfig::class);
    $immutable->method('get')->willReturnCallback(fn($key) => $values[$key] ?? NULL);
    $configFactory = $this->createStub(ConfigFactoryInterface::class);
    $configFactory->method('get')->willReturn($immutable);

    return new LangfuseContractCheck($configFactory, $httpClient, $lookup);
  }

  /**
   * Langfuse passes only when the trace it accepted can be read back.
   */
  public function testLangfuseCheckWritesThenReadsBack(): void {
    $sentTraceId = NULL;
    $httpClient = $this->createMock(ClientInterface::class);
    $httpClient->expects($this->once())
      ->method('request')
      ->with(
        'POST',
        'https://us.cloud.langfuse.com/api/public/otel/v1/traces',
        $this->callback(function (array $options) use (&$sentTraceId): bool {
          $spans = $options['json']['resourceSpans'][0]['scopeSpans'][0]['spans'];
          $sentTraceId = $spans[0]['traceId'];
          return $options['headers']['x-langfuse-ingestion-version'] === '4' && count($spans) === 3;
        }),
      )
      ->willReturn(new Response(200, [], '{}'));

    $lookup = $this->createMock(LangfuseTraceLookupService::class);
    $lookup->expects($this->once())
      ->method('lookupTrace')
      ->with($this->callback(function (string $id) use (&$sentTraceId): bool {
        // The trace read back is the one that was just written.
        return str_replace('-', '', $id) === $sentTraceId;
      }))
      ->willReturn(['found' => TRUE]);

    $result = $this->buildLangfuseCheck($httpClient, $lookup)->run()['langfuse'];

    $this->assertSame('pass', $result['status']);
    $this->assertSame('stored_and_read_back', $result['reason']);
    // The trace id is recorded so an alert can be checked against Langfuse.
    $this->assertSame($sentTraceId, str_replace('-', '', $result['context']['trace_id']));
  }

  /**
   * The failure modes seen in November 2026 each get a distinct reason.
   */
  public function testLangfuseCheckFailureReasons(): void {
    $lookup = $this->createStub(LangfuseTraceLookupService::class);

    $gone = $this->createStub(ClientInterface::class);
    $gone->method('request')->willThrowException(new ClientException(
      'gone',
      new Request('POST', 'https://us.cloud.langfuse.com/api/public/ingestion'),
      new Response(410),
    ));
    $this->assertSame('export_http_410', $this->buildLangfuseCheck($gone, $lookup)->run()['langfuse']['reason']);

    $accepting = $this->createStub(ClientInterface::class);
    $accepting->method('request')->willReturn(new Response(200, [], '{}'));

    $readGone = $this->createStub(LangfuseTraceLookupService::class);
    $readGone->method('lookupTrace')->willThrowException(new \RuntimeException('Langfuse trace lookup failed with HTTP 410: gone'));
    $this->assertSame('read_http_410', $this->buildLangfuseCheck($accepting, $readGone)->run()['langfuse']['reason']);
  }

  /**
   * An accepted trace that is not readable yet is pending, not failed.
   *
   * Langfuse stores asynchronously. On the first scheduled run on live the
   * trace was not readable within the ten seconds the check waited, an error
   * was raised (Sentry PHP-C9), and the trace was in Langfuse all along.
   */
  public function testLangfuseCheckIsPendingWhenTheTraceIsNotReadableYet(): void {
    $accepting = $this->createStub(ClientInterface::class);
    $accepting->method('request')->willReturn(new Response(200, [], '{}'));
    $lookedUp = [];
    $lookup = $this->createStub(LangfuseTraceLookupService::class);
    $lookup->method('lookupTrace')->willReturnCallback(function (string $id) use (&$lookedUp): array {
      $lookedUp[] = $id;
      // Not there on the first read; there when asked again later.
      return ['found' => count($lookedUp) > 1];
    });
    $check = $this->buildLangfuseCheck($accepting, $lookup);

    $result = $check->run()['langfuse'];
    $this->assertSame('pending', $result['status']);
    $this->assertSame('awaiting_read_back', $result['reason']);
    $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $result['context']['trace_id']);

    $this->assertSame(['langfuse'], $check->deferredVendors());
    $confirmed = $check->confirm($result['context']);
    $this->assertSame(['status' => 'pass', 'reason' => 'stored_and_read_back'], $confirmed);
    // The confirmation looks for the same trace the run wrote.
    $this->assertSame([$result['context']['trace_id'], $result['context']['trace_id']], $lookedUp);

    // The read API failing during confirmation is a failure, not a wait.
    $gone = $this->createStub(LangfuseTraceLookupService::class);
    $gone->method('lookupTrace')->willThrowException(new \RuntimeException('Langfuse trace lookup failed with HTTP 410: gone'));
    $this->assertSame('read_http_410', $this->buildLangfuseCheck($accepting, $gone)->confirm($result['context'])['reason']);
    $this->assertSame('fail', $this->buildLangfuseCheck($accepting, $gone)->confirm([])['status']);
  }

  /**
   * Builds a monitor whose single check leaves 'langfuse' pending.
   *
   * @param array<int, array{status: string, reason: string}> $confirmations
   *   What successive confirm() calls return.
   */
  private function buildMonitorWithPendingCheck(LoggerInterface $logger, array $confirmations, array $config = []): VendorContractMonitor {
    $values = $config + [
      'vendor_contract_check.enabled' => TRUE,
      'vendor_contract_check.interval_hours' => 168,
    ];
    $immutable = $this->createStub(ImmutableConfig::class);
    $immutable->method('get')->willReturnCallback(fn($key) => $values[$key] ?? NULL);
    $configFactory = $this->createStub(ConfigFactoryInterface::class);
    $configFactory->method('get')->willReturn($immutable);

    $state = $this->createStub(StateInterface::class);
    $state->method('get')->willReturnCallback(fn($key, $default = NULL) => $this->stateStore[$key] ?? $default);
    $state->method('set')->willReturnCallback(function ($key, $value): void {
      $this->stateStore[$key] = $value;
    });
    $time = $this->createStub(TimeInterface::class);
    $time->method('getCurrentTime')->willReturnCallback(fn(): int => $this->now);

    $check = $this->createStub(VendorContractDeferredCheckInterface::class);
    $check->method('run')->willReturn([
      'langfuse' => ['status' => 'pending', 'reason' => 'awaiting_read_back', 'context' => ['trace_id' => 'trace-1']],
    ]);
    $check->method('deferredVendors')->willReturn(['langfuse']);
    $check->method('confirm')->willReturnOnConsecutiveCalls(...$confirmations);

    return new VendorContractMonitor($configFactory, $state, $time, $logger, [$check]);
  }

  /**
   * A pending vendor is not an error, and is confirmed on a later cron run.
   */
  public function testPendingResultIsConfirmedOnALaterRunWithoutAnError(): void {
    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->never())->method('error');
    $pending = ['status' => 'pending', 'reason' => 'awaiting_read_back'];
    $monitor = $this->buildMonitorWithPendingCheck($logger, [$pending, ['status' => 'pass', 'reason' => 'stored_and_read_back']]);

    $report = $monitor->runIfDue();
    $this->assertTrue($report['ok']);
    $this->assertSame('pending', $report['vendors']['langfuse']['status']);
    $this->assertTrue($monitor->hasPending());
    $this->assertSame('trace-1', $this->stateStore[VendorContractMonitor::STATE_PENDING]['langfuse']['context']['trace_id']);

    // Ten minutes later, inside the grace period and still not visible:
    // nothing is decided and nothing is logged as an error.
    $this->now += 600;
    $this->assertNull($monitor->runIfDue());
    $this->assertTrue($monitor->hasPending());

    // The next hourly cron run finds it. No new weekly run is due.
    $this->now += 3000;
    $this->assertNull($monitor->runIfDue());
    $this->assertFalse($monitor->hasPending());
    $this->assertSame('pass', $monitor->lastReport()['vendors']['langfuse']['status']);
    $this->assertSame('trace-1', $monitor->lastReport()['vendors']['langfuse']['context']['trace_id']);
    $this->assertTrue($monitor->lastReport()['ok']);
  }

  /**
   * A probe still missing after the grace period is a real failure.
   */
  public function testPendingResultBecomesAFailureAfterTheGracePeriod(): void {
    $errors = [];
    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->once())
      ->method('error')
      ->willReturnCallback(function (string $message, array $context) use (&$errors): void {
        $errors[] = [$message, $context['@reason']];
      });
    $pending = ['status' => 'pending', 'reason' => 'awaiting_read_back'];
    $monitor = $this->buildMonitorWithPendingCheck($logger, [$pending, $pending]);

    $monitor->runIfDue();
    $this->now += VendorContractMonitor::PENDING_GRACE_SECONDS - 1;
    $monitor->runIfDue();
    $this->assertSame([], $errors);

    $this->now += 3600;
    $monitor->runIfDue();
    $this->assertStringContainsString('Vendor contract check failed: langfuse ', $errors[0][0]);
    $this->assertSame('accepted_but_not_stored', $errors[0][1]);
    $this->assertFalse($monitor->hasPending());
    $this->assertFalse($monitor->lastReport()['ok']);
    $this->assertSame('fail', $monitor->lastReport()['vendors']['langfuse']['status']);

    // Decided once: later cron runs do not report it again.
    $this->now += 3600;
    $monitor->runIfDue();
    $this->assertCount(1, $errors);
  }

  /**
   * An operator can ask for a final answer without waiting out the grace.
   */
  public function testFinalConfirmationFailsWhatIsStillPending(): void {
    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->once())->method('error');
    $monitor = $this->buildMonitorWithPendingCheck($logger, [['status' => 'pending', 'reason' => 'awaiting_read_back']]);

    $monitor->run();
    $outcomes = $monitor->confirmPending(TRUE);

    $this->assertSame('accepted_but_not_stored', $outcomes['langfuse']['reason']);
    $this->assertFalse($monitor->hasPending());
  }

  /**
   * A runtime without Langfuse sends nothing and is skipped.
   */
  public function testLangfuseCheckSkippedWhenDisabled(): void {
    $httpClient = $this->createMock(ClientInterface::class);
    $httpClient->expects($this->never())->method('request');

    $result = $this->buildLangfuseCheck($httpClient, $this->createStub(LangfuseTraceLookupService::class), FALSE)->run();

    $this->assertSame(['status' => 'skipped', 'reason' => 'disabled'], $result['langfuse']);
  }

}
