<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Queue\QueueInterface;
use Drupal\Core\State\StateInterface;
use Drupal\ilas_site_assistant\Service\LangfusePayloadContract;
use Drupal\ilas_site_assistant\Service\LangfuseTracer;
use Drupal\ilas_site_assistant\Service\QueueHealthMonitor;
use Drupal\ilas_site_assistant\Service\SloAlertService;
use Drupal\ilas_site_assistant\Service\SloDefinitions;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * Acceptance tests proving PHARD-02 backlog criteria.
 *
 * Validates Langfuse operationalization proof infrastructure:
 * - Evidence artifact with required sections
 * - Payload contract locked by constants + full lifecycle trace
 * - No PII in batch event bodies
 * - Sampling policy documented
 * - Queue SLO alert route
 * - Drush probe command registration
 *
 */
#[Group('ilas_site_assistant')]
class Phard02LangfuseLiveAcceptanceTest extends TestCase {

  private const MODULE_PATH = 'web/modules/custom/ilas_site_assistant';
  private const EVIDENCE_PATH = 'docs/aila/runtime/phard-02-langfuse-operationalization.txt';

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

  // ─── Evidence artifact assertions ─────────────────────────────────

  /**
   * Evidence artifact exists at expected path with all required sections.
   */
  public function testEvidenceArtifactExistsWithRequiredSections(): void {
    $content = self::readFile(self::EVIDENCE_PATH);

    $requiredSections = [
      'Pre-Edit State',
      'Synthetic Probe Evidence',
      'Approved Payload Shape',
      'Redaction Verification',
      'Queue Health Evidence',
      'Sampling Policy',
      'Alert Routing Evidence',
      'Review Cadence and Ownership',
      'Residual Risks',
      'Closure Determination',
    ];

    foreach ($requiredSections as $section) {
      $this->assertStringContainsString(
        $section,
        $content,
        "Evidence artifact must contain section: {$section}",
      );
    }
  }

  /**
   * Evidence artifact documents review cadence.
   */
  public function testEvidenceArtifactDocumentsReviewCadence(): void {
    $content = self::readFile(self::EVIDENCE_PATH);

    $this->assertMatchesRegularExpression(
      '/[Ww]eekly/',
      $content,
      'Evidence artifact must document weekly review cadence',
    );
  }

  /**
   * Evidence artifact documents named owner role.
   */
  public function testEvidenceArtifactDocumentsNamedOwnerRole(): void {
    $content = self::readFile(self::EVIDENCE_PATH);

    $this->assertStringContainsString(
      'SRE/Platform Engineer',
      $content,
      'Evidence artifact must document named owner role',
    );
  }

  // ─── Payload contract assertions ──────────────────────────────────

  /**
   * Full lifecycle trace produces all approved emitted event types.
   */
  public function testFullLifecycleTraceProducesAllApprovedEventTypes(): void {
    $tracer = $this->buildTracer();

    $tracer->startTrace('req-phard02', 'assistant.message', [], 'hash=abc len=1-24 redact=none');
    $tracer->startSpan('safety.classify');
    $tracer->endSpan(['is_safe' => TRUE]);
    $tracer->startSpan('intent.route');
    $tracer->startGeneration('llm.enhance', 'gemini-1.5-flash', ['temperature' => 0.3]);
    $tracer->endGeneration('intent=faq', ['input' => 10, 'output' => 20, 'total' => 30]);
    $tracer->endSpan(['intent' => 'faq']);
    $tracer->addEvent('request.complete', ['response_type' => 'faq']);
    $tracer->endTrace('type=faq reason=none hash=def len=1-24', ['duration_ms' => 150]);

    $payload = $tracer->getTracePayload();
    $this->assertNotNull($payload);

    $eventTypes = array_map(fn($e) => $e['type'], $payload['batch']);

    foreach (LangfusePayloadContract::APPROVED_EVENT_TYPES as $type) {
      $this->assertContains(
        $type,
        $eventTypes,
        "Full lifecycle must produce approved event type: {$type}",
      );
    }
  }

  /**
   * No batch event body contains PII-like patterns.
   */
  public function testNoBatchEventBodyContainsPii(): void {
    $tracer = $this->buildTracer();

    $tracer->startTrace('req-phard02-pii', 'assistant.message', [], 'hash=abc len=1-24 redact=none');
    $tracer->startSpan('safety.classify');
    $tracer->endSpan(['result' => 'safe']);
    $tracer->startGeneration('llm.enhance', 'gemini-1.5-flash');
    $tracer->endGeneration('intent=faq');
    $tracer->addEvent('request.complete');
    $tracer->endTrace('type=faq reason=none hash=def len=1-24');

    $payload = $tracer->getTracePayload();
    $this->assertNotNull($payload);

    $piiPatterns = [
      'email' => '/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/',
      'phone' => '/\b\d{3}[-.]?\d{3}[-.]?\d{4}\b/',
      'ssn' => '/\b\d{3}-\d{2}-\d{4}\b/',
      'cc' => '/\b(?:4\d{12}(?:\d{3})?|5[1-5]\d{14})\b/',
    ];

    foreach ($payload['batch'] as $event) {
      $bodyJson = json_encode($event['body']);
      foreach ($piiPatterns as $label => $pattern) {
        $this->assertDoesNotMatchRegularExpression(
          $pattern,
          $bodyJson,
          "Batch event body must not contain {$label} PII: type={$event['type']}",
        );
      }
    }
  }

  // ─── Sampling policy assertions ───────────────────────────────────

  /**
   * Sampling policy documented in runtime gates artifact.
   */
  public function testSamplingPolicyDocumentedInRuntimeGates(): void {
    $gatesFile = self::readFile('docs/aila/runtime/phase1-observability-gates.txt');

    $this->assertStringContainsString(
      'langfuse_sample_rate=0.1',
      $gatesFile,
      'Runtime gates must document live sample rate of 0.1',
    );
  }

  /**
   * Install config sample_rate is 1.0.
   */
  public function testInstallConfigSampleRateIsOne(): void {
    $installPath = self::repoRoot() . '/' . self::MODULE_PATH
      . '/config/install/ilas_site_assistant.settings.yml';
    $this->assertFileExists($installPath);
    $install = Yaml::parseFile($installPath);

    $this->assertSame(
      1.0,
      (float) $install['langfuse']['sample_rate'],
      'Install config langfuse.sample_rate must be 1.0',
    );
  }

  // ─── Queue SLO alert assertions ───────────────────────────────────

  /**
   * SloAlertService::checkQueueSlo() fires warning on unhealthy queue.
   */
  public function testSloAlertServiceFiresWarningOnUnhealthyQueue(): void {
    $slo = $this->createStub(SloDefinitions::class);
    $slo->method('getQueueMaxDepth')->willReturn(100);
    $slo->method('getQueueMaxAgeSeconds')->willReturn(3600);

    // Backlogged queue: 90 items (90% utilization, above 80% threshold).
    $queue = $this->createStub(QueueInterface::class);
    $queue->method('numberOfItems')->willReturn(90);

    $queueFactory = $this->createStub(QueueFactory::class);
    $queueFactory->method('get')->willReturn($queue);

    $monitor = new QueueHealthMonitor($queueFactory, $this->createStub(StateInterface::class));

    // Verify the monitor reports backlogged status.
    $status = $monitor->getQueueHealthStatus($slo);
    $this->assertSame('backlogged', $status['status']);

    // Verify SloAlertService would fire a warning.
    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->once())
      ->method('warning')
      ->with($this->stringContains('SLO violation: queue is'));

    $state = $this->createStub(StateInterface::class);
    $state->method('get')->willReturn(NULL);

    $alertService = new SloAlertService(
      $slo,
      $logger,
      $state,
      NULL,
      NULL,
      $monitor,
    );

    $alertService->checkQueueSlo();
  }

  // ─── Drush service registration ───────────────────────────────────

  /**
   * Drush services registers langfuse probe command.
   */
  public function testDrushServicesRegistersLangfuseProbeCommand(): void {
    $servicesPath = self::repoRoot() . '/' . self::MODULE_PATH . '/drush.services.yml';
    $this->assertFileExists($servicesPath);

    $services = Yaml::parseFile($servicesPath);
    $this->assertArrayHasKey('services', $services);
    $this->assertArrayHasKey(
      'ilas_site_assistant.langfuse_probe_commands',
      $services['services'],
      'drush.services.yml must register langfuse_probe_commands',
    );

    $tags = $services['services']['ilas_site_assistant.langfuse_probe_commands']['tags'] ?? [];
    $drushTag = array_filter($tags, fn($t) => ($t['name'] ?? '') === 'drush.command');
    $this->assertNotEmpty($drushTag, 'Service must be tagged as drush.command');
  }

}
