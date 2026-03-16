<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Guards Phase 3 Exit criterion #2 closure artifacts (`P3-EXT-02`).
 */
#[Group('ilas_site_assistant_docs')]
final class PhaseThreeExitCriteriaTwoGateTest extends TestCase {

  use DiagramAQualityGateAssertionsTrait;

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
   * Roadmap must include dated closure disposition for Phase 3 Exit #2.
   */
  public function testRoadmapContainsPhaseThreeExitTwoDisposition(): void {
    $roadmap = self::readFile('docs/aila/roadmap.md');

    $this->assertStringContainsString('### Phase 3 Exit #2 disposition (2026-03-06)', $roadmap);
    $this->assertStringContainsString(
      'Exit criterion #2 is closed as implemented: cost/performance controls are documented, monitored, and accepted by product/platform owners.',
      $roadmap
    );
    $this->assertStringContainsString('Pantheon read-only verification passed on 2026-03-13 across `dev`/`test`/`live`', $roadmap);
    $this->assertStringContainsString('phase3-exit2-cost-performance-owner-acceptance.txt', $roadmap);
    $this->assertStringContainsString('PhaseThreeExitCriteriaTwoGateTest.php', $roadmap);
    $this->assertStringContainsString('CLAIM-154', $roadmap);
    $this->assertStringContainsString('no net-new assistant channels or third-party model expansion', $roadmap);
    $this->assertStringContainsString('no platform-wide refactor of unrelated Drupal subsystems', $roadmap);
    $this->assertStringContainsString('B-04', $roadmap);
  }

  /**
   * Current-state must include dated P3-EXT-02 owner-acceptance addendum.
   */
  public function testCurrentStateContainsPhaseThreeExitTwoAddendum(): void {
    $currentState = self::readFile('docs/aila/current-state.md');

    $this->assertStringContainsString(
      '### Phase 3 Exit #2 Cost/Performance Controls + Product/Platform Owner Acceptance Disposition (2026-03-06)',
      $currentState
    );
    $this->assertStringContainsString('`P3-EXT-02`', $currentState);
    $this->assertStringContainsString('`VC-PURE`', $currentState);
    $this->assertStringContainsString('`VC-QUALITY-GATE`', $currentState);
    $this->assertStringContainsString('`VC-PANTHEON-READONLY`', $currentState);
    $this->assertStringContainsString('owner-acceptance-product-role=accepted', $currentState);
    $this->assertStringContainsString('owner-acceptance-platform-role=accepted', $currentState);
    $this->assertStringContainsString('metrics.cost_control', $currentState);
    $this->assertStringContainsString('Pantheon read-only', $currentState);
    $this->assertStringContainsString('2026-03-13 across `dev`/`test`/`live`', $currentState);
    $this->assertStringContainsString('phase3-exit2-cost-performance-owner-acceptance.txt', $currentState);
    $this->assertStringContainsString('[^CLAIM-154]', $currentState);
  }

  /**
   * Runbook section 3 must include reproducible P3-EXT-02 verification steps.
   */
  public function testRunbookContainsPhaseThreeExitTwoVerificationBundle(): void {
    $runbook = self::readFile('docs/aila/runbook.md');

    $this->assertStringContainsString(
      '### Phase 3 exit #2 cost/performance controls documented + monitored + owner acceptance verification (`P3-EXT-02`)',
      $runbook
    );
    $this->assertStringContainsString('# VC-PURE', $runbook);
    $this->assertStringContainsString('# VC-QUALITY-GATE', $runbook);
    $this->assertStringContainsString('# VC-PANTHEON-READONLY', $runbook);
    $this->assertStringContainsString('/assistant/api/health', $runbook);
    $this->assertStringContainsString('/assistant/api/metrics', $runbook);
    $this->assertStringContainsString('metrics.cost_control', $runbook);
    $this->assertStringContainsString('thresholds.cost_control', $runbook);
    $this->assertStringContainsString('PhaseThreeExitCriteriaTwoGateTest.php', $runbook);
    $this->assertStringContainsString('phase3-exit2-cost-performance-owner-acceptance.txt', $runbook);
    $this->assertStringContainsString('March 13, 2026 hosted verification showed', $runbook);
    $this->assertStringContainsString('If `VC-PANTHEON-READONLY` later shows the deployed config missing', $runbook);
    $this->assertStringContainsString('`per_ip_hourly_call_limit` or `per_ip_window_seconds`', $runbook);
    $this->assertStringContainsString('[^CLAIM-154]', $runbook);
  }

  /**
   * Evidence index must include CLAIM-154 and P3-EXT-02 addenda under 077/084.
   */
  public function testEvidenceIndexContainsPhaseThreeExitTwoClaimAndAddenda(): void {
    $evidenceIndex = self::readFile('docs/aila/evidence-index.md');

    $this->assertStringContainsString('### CLAIM-077', $evidenceIndex);
    $this->assertStringContainsString('### CLAIM-084', $evidenceIndex);
    $this->assertStringContainsString('Addendum (2026-03-06): Phase 3 Exit #2 (`P3-EXT-02`)', $evidenceIndex);
    $this->assertStringContainsString(
      '## Phase 3 Exit #2 Cost/Performance Controls Documented + Monitored + Product/Platform Owner Accepted (`P3-EXT-02`)',
      $evidenceIndex
    );
    $this->assertStringContainsString('### CLAIM-154', $evidenceIndex);
    $this->assertStringContainsString('PhaseThreeExitCriteriaTwoGateTest.php', $evidenceIndex);
    $this->assertStringContainsString('Pantheon read-only verification passed on 2026-03-13', $evidenceIndex);
  }

  /**
   * Runtime artifact must contain required closure and owner-acceptance markers.
   */
  public function testRuntimeArtifactContainsPhaseThreeExitTwoProofMarkers(): void {
    $artifact = self::readFile('docs/aila/runtime/phase3-exit2-cost-performance-owner-acceptance.txt');

    $this->assertStringContainsString('# Phase 3 Exit #2 Runtime Evidence (P3-EXT-02)', $artifact);
    $this->assertStringContainsString('`VC-PURE`', $artifact);
    $this->assertStringContainsString('`VC-PANTHEON-READONLY`', $artifact);
    $this->assertStringContainsString('p3-ext-02-status=closed', $artifact);
    $this->assertStringContainsString('p3-ext-02-claim-077=present', $artifact);
    $this->assertStringContainsString('p3-ext-02-claim-084=present', $artifact);
    $this->assertStringContainsString('p3-ext-02-monitoring=verified', $artifact);
    $this->assertStringContainsString('owner-acceptance-product-role=accepted', $artifact);
    $this->assertStringContainsString('owner-acceptance-platform-role=accepted', $artifact);
    $this->assertStringContainsString('owner-acceptance-date=2026-03-06', $artifact);
    $this->assertStringContainsString('metrics-cost-control=present', $artifact);
    $this->assertStringContainsString('thresholds-cost-control=present', $artifact);
    $this->assertStringContainsString('env.dev.per_ip_hourly_call_limit=10', $artifact);
    $this->assertStringContainsString('env.test.per_ip_hourly_call_limit=10', $artifact);
    $this->assertStringContainsString('env.live.per_ip_hourly_call_limit=10', $artifact);
    $this->assertStringContainsString('deployed_metrics_cost_control=present', $artifact);
    $this->assertStringContainsString('cost-proof-status=pass', $artifact);
    $this->assertStringContainsString('vc-pantheon-readonly-status=pass', $artifact);
    $this->assertStringContainsString('b04-status=open', $artifact);
  }

  /**
   * Backlog and risk linkage must include P3-EXT-02 owner-acceptance continuity.
   */
  public function testBacklogAndRiskRegisterContainPhaseThreeExitTwoLinkage(): void {
    $backlog = self::readFile('docs/aila/backlog.md');
    $riskRegister = self::readFile('docs/aila/risk-register.md');

    $this->assertStringContainsString('Active mitigation (IMP-COST-01 / P3-OBJ-02, 2026-03-05)', $backlog);
    $this->assertStringContainsString('P3-EXT-02', $backlog);
    $this->assertStringContainsString('phase3-exit2-cost-performance-owner-acceptance.txt', $backlog);
    $this->assertStringContainsString('Pantheon read-only verification passed on 2026-03-13', $backlog);

    $this->assertStringContainsString('| R-PERF-01 |', $riskRegister);
    $this->assertStringContainsString('P3-EXT-02', $riskRegister);
    $this->assertStringContainsString('owner-acceptance-product-role', $riskRegister);
    $this->assertStringContainsString('owner-acceptance-platform-role', $riskRegister);
    $this->assertStringContainsString('vc-pantheon-readonly-status', $riskRegister);
    $this->assertStringContainsString('Pantheon read-only verification passed on 2026-03-13', $riskRegister);
  }

  /**
   * Diagram A continuity anchors must remain present for exit context.
   */
  public function testSystemMapRetainsDiagramAAnchors(): void {
    $systemMap = self::readFile('docs/aila/system-map.mmd');

    $this->assertCurrentDiagramAQualityGateAnchors($systemMap, requireObservability: TRUE);
  }

}
