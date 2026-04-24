<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Guards P1-EXT-01 artifacts for Phase 1 Exit criterion #1.
 */
#[Group('ilas_site_assistant')]
final class PhaseOneExitCriteriaOneGateTest extends TestCase {

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
   * Roadmap must contain dated closure for Phase 1 Exit criterion #1.
   */
  public function testRoadmapContainsPhaseOneExitOneDisposition(): void {
    $roadmap = self::readFile('docs/aila/roadmap.md');

    $this->assertStringContainsString(
      'Critical alerts and dashboards operate in non-live and are tested.',
      $roadmap,
    );
    $this->assertStringContainsString('### Phase 1 Exit #1 disposition (2026-03-03)', $roadmap);
    $this->assertStringContainsString('/assistant/api/health', $roadmap);
    $this->assertStringContainsString('/assistant/api/metrics', $roadmap);
    $this->assertStringContainsString('/admin/reports/ilas-assistant', $roadmap);
    $this->assertStringContainsString('phase1-exit1-alerts-dashboards.txt', $roadmap);
  }

  /**
   * Current-state addendum must capture non-live verification + residual B-04.
   */
  public function testCurrentStateContainsExitOneNonLiveAddendum(): void {
    $currentState = self::readFile('docs/aila/current-state.md');

    $this->assertStringContainsString(
      '### Phase 1 Exit #1 Non-Live Alert + Dashboard Verification (2026-03-03)',
      $currentState,
    );
    $this->assertStringContainsString(
      'Critical alert and dashboard surfaces were verified in local and Pantheon non-live (dev/test) environments.',
      $currentState,
    );
    $this->assertStringContainsString('B-04 (cron/queue throughput under load) remains unresolved', $currentState);
    $this->assertStringContainsString('[^CLAIM-127]', $currentState);
  }

  /**
   * Runbook section 3 must include non-live verification commands.
   */
  public function testRunbookContainsExitOneVerificationCommands(): void {
    $runbook = self::readFile('docs/aila/runbook.md');

    $this->assertStringContainsString('### Phase 1 Exit #1 non-live alerts + dashboards verification', $runbook);
    $this->assertStringContainsString('AssistantApiController::health()', $runbook);
    $this->assertStringContainsString('AssistantApiController::metrics()', $runbook);
    $this->assertStringContainsString('AssistantReportController::report()', $runbook);
    $this->assertStringContainsString('for ENV in dev test; do', $runbook);
    $this->assertStringContainsString('docs/aila/runtime/phase1-exit1-alerts-dashboards.txt', $runbook);
  }

  /**
   * Diagram A must explicitly include dashboard and alert observability surfaces.
   */
  public function testSystemMapIncludesDashboardAndAlertSurfaces(): void {
    $systemMap = self::readFile('docs/aila/system-map.mmd');

    $this->assertStringContainsString('Dashboard APIs', $systemMap);
    $this->assertStringContainsString('/assistant/api/health', $systemMap);
    $this->assertStringContainsString('/assistant/api/metrics', $systemMap);
    $this->assertStringContainsString('/admin/reports/ilas-assistant', $systemMap);
    $this->assertStringContainsString('Critical alerts', $systemMap);
    $this->assertStringContainsString('SLO violation watchdog warnings', $systemMap);
  }

  /**
   * Evidence index must include CLAIM-127 with test/runtime/doc references.
   */
  public function testEvidenceIndexContainsClaim127(): void {
    $evidenceIndex = self::readFile('docs/aila/evidence-index.md');

    $this->assertStringContainsString('### CLAIM-127', $evidenceIndex);
    $this->assertStringContainsString('Phase 1 Exit #1 (P1-EXT-01) is closed', $evidenceIndex);
    $this->assertStringContainsString('Cron hook records run health before `SloAlertService::checkAll()`', $evidenceIndex);
    $this->assertStringContainsString('CronHookSloAlertOrderingTest.php', $evidenceIndex);
    $this->assertStringContainsString('AssistantApiFunctionalTest.php', $evidenceIndex);
    $this->assertStringContainsString('phase1-exit1-alerts-dashboards.txt', $evidenceIndex);
  }

  /**
   * Runtime artifact must exist with local and non-live Pantheon proof lines.
   */
  public function testRuntimeArtifactContainsExitOneProofLines(): void {
    $artifact = self::readFile('docs/aila/runtime/phase1-exit1-alerts-dashboards.txt');

    $this->assertStringContainsString('## Local (DDEV) non-live verification', $artifact);
    $this->assertStringContainsString('health_keys=status,timestamp,checks', $artifact);
    $this->assertStringContainsString('metrics_keys=timestamp,metrics,thresholds,cron,queue', $artifact);
    $this->assertStringContainsString('report_sections=summary,topics,destinations,quality', $artifact);
    $this->assertStringContainsString('@slo_dimension', $artifact);
    $this->assertStringContainsString('## Pantheon dev non-live verification', $artifact);
    $this->assertStringContainsString('## Pantheon test non-live verification', $artifact);
  }

}
