<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\ilas_site_assistant\EventSubscriber\SentryOptionsSubscriber;
use Drupal\ilas_site_assistant\Service\LangfuseTracer;
use Drupal\ilas_site_assistant\Service\PiiRedactor;
use Drupal\ilas_site_assistant\Service\QueueHealthMonitor;
use Drupal\ilas_site_assistant\Service\SloDefinitions;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * Acceptance tests proving IMP-OBS-01 backlog criteria.
 *
 * Validates Sentry and Langfuse story completion:
 * - Sentry: synthetic events get environment tags, PII scrubbed, runbook doc-lock
 * - Langfuse: full lifecycle, queue health, install config policy-cap
 */
#[Group('ilas_site_assistant')]
class ImpObs01AcceptanceTest extends TestCase {

  private const MODULE_PATH = 'web/modules/custom/ilas_site_assistant';

  /**
   * Returns the repository root.
   */
  private static function repoRoot(): string {
    return dirname(__DIR__, 7);
  }

  /**
   * Reads a file from repo root after existence checks.
   */
  private static function readFile(string $relativePath): string {
    $path = self::repoRoot() . '/' . ltrim($relativePath, '/');
    self::assertFileExists($path, "Expected file does not exist: {$relativePath}");

    $contents = file_get_contents($path);
    self::assertIsString($contents, "Failed reading file: {$relativePath}");
    return $contents;
  }

  /**
   * Skips the test if Sentry SDK is not installed.
   */
  protected function requireSentry(): void {
    if (!class_exists('\Sentry\Event')) {
      $this->markTestSkipped('Sentry SDK not installed.');
    }
  }

  /**
   * Builds a LangfuseTracer with test credentials enabled.
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

  // ─── Sentry Story acceptance ────────────────────────────────────────

  /**
   * Sentry AC-1: Synthetic event gets environment tags and PII scrubbed.
   */
  public function testSentryEventGetsEnvironmentTagsAndPiiScrubbed(): void {
    $this->requireSentry();

    $callback = SentryOptionsSubscriber::beforeSendCallback();
    $sentryEvent = \Sentry\Event::createEvent();
    $sentryEvent->setMessage('User email john@example.com called from 208-555-1234');
    $sentryEvent->setTags([
      'pantheon_env' => 'test',
      'php_sapi' => 'cli',
      'runtime_context' => 'web',
    ]);

    $result = $callback($sentryEvent, NULL);
    $this->assertNotNull($result);

    // Verify tags are preserved.
    $tags = $result->getTags();
    $this->assertSame('test', $tags['pantheon_env']);
    $this->assertSame('cli', $tags['php_sapi']);
    // runtime_context is set by resolveRuntimeContext() which is authoritative
    // — it overwrites any pre-existing tag value. In PHPUnit (PHP_SAPI=cli),
    // the resolved value depends on argv, so assert presence, not value.
    $this->assertArrayHasKey('runtime_context', $tags);
    $this->assertNotEmpty($tags['runtime_context']);

    // Verify PII is scrubbed from message.
    $message = $result->getMessage();
    $this->assertStringNotContainsString('john@example.com', $message);
    $this->assertStringNotContainsString('208-555-1234', $message);
  }

  /**
   * Sentry AC-2: All 9 PII types redacted across message/exception/extra.
   */
  public function testAllNinePiiTypesRedactedAcrossAllSentryFields(): void {
    $this->requireSentry();

    $allPii = implode(' | ', [
      'email john@example.com',
      'phone 208-555-1234',
      'ssn 123-45-6789',
      'cc 4111111111111111',
      'born on 01/15/1990',
      'date 2025-03-15',
      '123 Main Street',
      'name John Smith',
      'CV-24-0001',
    ]);

    $rawValues = [
      'john@example.com', '208-555-1234', '123-45-6789',
      '4111111111111111', '01/15/1990', '2025-03-15',
      '123 Main Street', 'John Smith', 'CV-24-0001',
    ];

    $callback = SentryOptionsSubscriber::beforeSendCallback();

    // Test message redaction.
    $event = \Sentry\Event::createEvent();
    $event->setMessage($allPii);
    $result = $callback($event, NULL);
    $this->assertNotNull($result);
    foreach ($rawValues as $raw) {
      $this->assertStringNotContainsString($raw, $result->getMessage());
    }

    // Test exception redaction.
    $event2 = \Sentry\Event::createEvent();
    $exception = new \RuntimeException($allPii);
    $exceptionBag = new \Sentry\ExceptionDataBag($exception);
    $event2->setExceptions([$exceptionBag]);
    $result2 = $callback($event2, NULL);
    $this->assertNotNull($result2);
    foreach ($rawValues as $raw) {
      $this->assertStringNotContainsString($raw, $result2->getExceptions()[0]->getValue());
    }

    // Test extra context redaction.
    $event3 = \Sentry\Event::createEvent();
    $event3->setExtra(['context' => $allPii]);
    $result3 = $callback($event3, NULL);
    $this->assertNotNull($result3);
    foreach ($rawValues as $raw) {
      $this->assertStringNotContainsString($raw, $result3->getExtra()['context']);
    }
  }

  /**
   * Sentry AC-3: Runtime gates doc contains raven_client_key=present verification.
   */
  public function testRuntimeGatesDocumentsRavenClientKeyPresent(): void {
    $gates = self::readFile('docs/aila/runtime/phase1-observability-gates.txt');

    $this->assertStringContainsString(
      'raven_client_key=present',
      $gates,
      'Runtime gates must document raven_client_key=present verification',
    );

    // Runbook references the verification command pattern.
    $runbook = self::readFile('docs/aila/runbook.md');
    $this->assertStringContainsString(
      'raven_client_key',
      $runbook,
      'Runbook must reference raven_client_key verification',
    );
  }

  // ─── Langfuse Story acceptance ──────────────────────────────────────

  /**
   * Langfuse AC-4: Full lifecycle produces all expected emitted event types.
   */
  public function testLangfuseFullLifecycleProducesAllEventTypes(): void {
    $tracer = $this->buildTracer();

    $tracer->startTrace('req-001', 'assistant.message', [], 'preview="Need help with [REDACTED-EMAIL]" hash=abc len=25-99 redact=email');
    $tracer->startSpan('safety.classify');
    $tracer->endSpan(['is_safe' => TRUE]);
    $tracer->startSpan('intent.route');
    $tracer->startGeneration('llm.enhance', 'gemini-1.5-flash', ['temperature' => 0.3]);
    $tracer->endGeneration('intent=faq', ['input' => 10, 'output' => 20, 'total' => 30]);
    $tracer->endSpan(['intent' => 'faq']);
    $tracer->addEvent('request.complete', ['response_type' => 'faq']);
    $tracer->endTrace('type=faq reason=none preview="Please call [REDACTED-PHONE]." hash=def len=25-99 redact=phone', ['duration_ms' => 150, 'success' => TRUE]);

    $payload = $tracer->getTracePayload();
    $this->assertNotNull($payload);

    $eventTypes = array_map(fn($e) => $e['type'], $payload['batch']);

    $requiredTypes = [
      'trace-create',
      'span-create',
      'generation-create',
      'event-create',
    ];

    foreach ($requiredTypes as $type) {
      $this->assertContains($type, $eventTypes, "Missing Langfuse event type: {$type}");
    }
  }

  /**
   * Langfuse AC-5: QueueHealthMonitor reports correct health status.
   */
  public function testQueueHealthMonitorReportsCorrectStatus(): void {
    // Build SloDefinitions stub with max_depth = 100.
    $slo = $this->createStub(SloDefinitions::class);
    $slo->method('getQueueMaxDepth')->willReturn(100);

    // Build a queue stub reporting 50 items (50% utilization, below 80%).
    $healthyQueue = $this->createStub(\Drupal\Core\Queue\QueueInterface::class);
    $healthyQueue->method('numberOfItems')->willReturn(50);

    $healthyQueueFactory = $this->createStub(\Drupal\Core\Queue\QueueFactory::class);
    $healthyQueueFactory->method('get')->willReturn($healthyQueue);

    $state = $this->createStub(\Drupal\Core\State\StateInterface::class);

    $monitor = new QueueHealthMonitor($healthyQueueFactory, $state);
    $status = $monitor->getQueueHealthStatus($slo);
    $this->assertSame('healthy', $status['status']);

    // Build a queue stub reporting 90 items (90% utilization, above 80%).
    $backloggedQueue = $this->createStub(\Drupal\Core\Queue\QueueInterface::class);
    $backloggedQueue->method('numberOfItems')->willReturn(90);

    $backloggedQueueFactory = $this->createStub(\Drupal\Core\Queue\QueueFactory::class);
    $backloggedQueueFactory->method('get')->willReturn($backloggedQueue);

    $backlogMonitor = new QueueHealthMonitor($backloggedQueueFactory, $state);
    $backlogStatus = $backlogMonitor->getQueueHealthStatus($slo);
    $this->assertSame('backlogged', $backlogStatus['status']);
  }

  /**
   * Langfuse AC-6: Install config and runtime gates both document sample_rate=1.0.
   */
  public function testInstallSampleRateAndRuntimeGatesAlignment(): void {
    // Verify install config default sample_rate.
    $installPath = self::repoRoot() . '/' . self::MODULE_PATH
      . '/config/install/ilas_site_assistant.settings.yml';
    $this->assertFileExists($installPath);
    $install = Yaml::parseFile($installPath);

    $this->assertSame(
      1.0,
      (float) $install['langfuse']['sample_rate'],
      'Install config langfuse.sample_rate must be 1.0',
    );

    // Verify runtime gates file documents live at 1.0.
    $gatesFile = self::readFile('docs/aila/runtime/phase1-observability-gates.txt');
    $this->assertStringContainsString(
      'langfuse_sample_rate=1',
      $gatesFile,
      'Runtime gates must document live sample rate of 1.0',
    );
    $this->assertStringNotContainsString(
      'langfuse_sample_rate=0.1',
      $gatesFile,
      'Runtime gates must no longer document a 0.1 Langfuse sample rate',
    );
  }

  /**
   * RAUD-27 doc lock: denied/degraded monitor coverage is documented end to end.
   */
  public function testRaud27ObservabilityDocsLockDeniedAndDegradedMonitoring(): void {
    $runbook = self::readFile('docs/aila/runbook.md');
    $this->assertStringContainsString(
      '### RAUD-27 performance monitor coverage verification',
      $runbook,
    );
    $this->assertStringContainsString('all_endpoints', $runbook);
    $this->assertStringContainsString('by_endpoint', $runbook);
    $this->assertStringContainsString('by_outcome', $runbook);
    $this->assertStringContainsString('denied', $runbook);
    $this->assertStringContainsString('degraded', $runbook);

    $currentState = self::readFile('docs/aila/current-state.md');
    $this->assertStringContainsString('all_endpoints', $currentState);
    $this->assertStringContainsString('by_endpoint', $currentState);
    $this->assertStringContainsString('by_outcome', $currentState);

    $evidenceIndex = self::readFile('docs/aila/evidence-index.md');
    $this->assertStringContainsString('RAUD-27', $evidenceIndex);
    $this->assertStringContainsString('AssistantApiResponseMonitorSubscriber.php', $evidenceIndex);

    $artifact = self::readFile('docs/aila/runtime/raud-27-performance-monitor-coverage.txt');
    $this->assertStringContainsString('Prior status', $artifact);
    $this->assertStringContainsString('Post-change status', $artifact);
    $this->assertStringContainsString('Verification level tag', $artifact);
    $this->assertStringContainsString('VC-UNIT', $artifact);
    $this->assertStringContainsString('VC-PURE', $artifact);
    $this->assertStringContainsString('VC-QUALITY-GATE', $artifact);
  }

}
