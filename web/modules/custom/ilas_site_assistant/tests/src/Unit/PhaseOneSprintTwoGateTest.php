<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Guards Phase 1 Sprint 2 closure artifacts (`P1-SBD-01`).
 */
#[Group('ilas_site_assistant')]
final class PhaseOneSprintTwoGateTest extends TestCase {

  /**
   * Returns the repository root path.
   */
  private static function repoRoot(): string {
    // __DIR__ = <repo>/web/modules/custom/ilas_site_assistant/tests/src/Unit
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
   * Roadmap must include a dated Sprint 2 closure disposition.
   */
  public function testRoadmapContainsSprintTwoDisposition(): void {
    $roadmap = self::readFile('docs/aila/roadmap.md');

    $this->assertStringContainsString('### Phase 1 Sprint 2 disposition (2026-03-03)', $roadmap);
    $this->assertStringContainsString('Sprint 2: Sentry/Langfuse bootstrap, log schema normalization, initial SLO drafts.', $roadmap);
    $this->assertStringContainsString('No live LLM rollout', $roadmap);
    $this->assertStringContainsString('No full redesign of retrieval architecture', $roadmap);
    $this->assertStringContainsString('CLAIM-129', $roadmap);
  }

  /**
   * Current state must include Sprint 2 closure addendum and residual risk.
   */
  public function testCurrentStateContainsSprintTwoClosureAddendum(): void {
    $currentState = self::readFile('docs/aila/current-state.md');

    $this->assertStringContainsString('### Phase 1 Sprint 2 Closure Addendum (2026-03-03)', $currentState);
    $this->assertStringContainsString('TelemetrySchema::toLogContext()', $currentState);
    $this->assertStringContainsString('intent`, `safety_class`, `fallback_path`, `request_id`, `env`', $currentState);
    $this->assertStringContainsString('SLO draft thresholds remain exposed via `/assistant/api/health` and `/assistant/api/metrics`', $currentState);
    $this->assertStringContainsString('B-04 (cron/queue throughput under load) remains unresolved', $currentState);
    $this->assertStringContainsString('[^CLAIM-129]', $currentState);
  }

  /**
   * Runbook must include Sprint 2 verification command bundle.
   */
  public function testRunbookContainsSprintTwoVerificationCommands(): void {
    $runbook = self::readFile('docs/aila/runbook.md');

    $this->assertStringContainsString('### Phase 1 Sprint 2 verification (`P1-SBD-01`)', $runbook);
    $this->assertStringContainsString('TelemetrySchemaContractTest.php', $runbook);
    $this->assertStringContainsString('PhaseOneSprintTwoGateTest.php', $runbook);
    $this->assertStringContainsString('VC-UNIT', $runbook);
    $this->assertStringContainsString('VC-QUALITY-GATE', $runbook);
  }

  /**
   * Evidence index must include CLAIM-129 with doc/code/test links.
   */
  public function testEvidenceIndexContainsClaim129(): void {
    $evidenceIndex = self::readFile('docs/aila/evidence-index.md');

    $this->assertStringContainsString('### CLAIM-129', $evidenceIndex);
    $this->assertStringContainsString('Phase 1 Sprint 2 (`P1-SBD-01`) is closed', $evidenceIndex);
    $this->assertStringContainsString('TelemetrySchema.php', $evidenceIndex);
    $this->assertStringContainsString('AssistantApiController.php', $evidenceIndex);
    $this->assertStringContainsString('TelemetrySchemaContractTest.php', $evidenceIndex);
    $this->assertStringContainsString('PhaseOneSprintTwoGateTest.php', $evidenceIndex);
  }

  /**
   * Backlog and system map must reflect Sprint 2 closure language.
   */
  public function testBacklogAndSystemMapContainSprintTwoClosureMarkers(): void {
    $backlog = self::readFile('docs/aila/backlog.md');
    $systemMap = self::readFile('docs/aila/system-map.mmd');

    $this->assertStringContainsString('**Done (IMP-SLO-01, 2026-03-03).**', $backlog);
    $this->assertStringContainsString('CLAIM-129', $backlog);
    $this->assertStringContainsString('Normalized telemetry log schema', $systemMap);
  }

  /**
   * Source contract: controller must use log-context helper on critical logs.
   */
  public function testControllerUsesLogContextHelperOnCriticalLogs(): void {
    $controller = self::readFile('web/modules/custom/ilas_site_assistant/src/Controller/AssistantApiController.php');
    $schema = self::readFile('web/modules/custom/ilas_site_assistant/src/Service/TelemetrySchema.php');

    $this->assertGreaterThanOrEqual(5, substr_count($controller, 'TelemetrySchema::toLogContext('));
    $this->assertStringContainsString('public static function toLogContext(array $telemetry, array $extra = []): array', $schema);
    $this->assertStringContainsString("'@request_id' =>", $schema);
    $this->assertStringContainsString("'@intent' =>", $schema);
    $this->assertStringContainsString("'@safety' =>", $schema);
    $this->assertStringContainsString("'@gate' =>", $schema);
  }

}

