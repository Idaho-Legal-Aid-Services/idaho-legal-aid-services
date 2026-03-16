<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Guards Phase 3 Sprint 6 Week 2 closure artifacts (`P3-SBD-02`).
 */
#[Group('ilas_site_assistant')]
final class PhaseThreeSprintSixWeekTwoGateTest extends TestCase {

  use DiagramAQualityGateAssertionsTrait;

  /**
   * Returns repository root.
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
   * Roadmap must include the dated Sprint 6 Week 2 closure disposition.
   */
  public function testRoadmapContainsSprintSixWeekTwoDisposition(): void {
    $roadmap = self::readFile('docs/aila/roadmap.md');

    $this->assertStringContainsString('### Phase 3 Sprint 6 Week 2 disposition (2026-03-06)', $roadmap);
    $this->assertStringContainsString('"Sprint 6 Week 2: performance/cost guardrails and governance signoff."', $roadmap);
    $this->assertStringContainsString('phase3-sprint6-week2-performance-cost-governance-signoff.txt', $roadmap);
    $this->assertStringContainsString('PhaseThreeSprintSixWeekTwoGateTest.php', $roadmap);
    $this->assertStringContainsString('P3-OBJ-02', $roadmap);
    $this->assertStringContainsString('P3-OBJ-03', $roadmap);
    $this->assertStringContainsString('P3-EXT-02', $roadmap);
    $this->assertStringContainsString('P3-EXT-03', $roadmap);
    $this->assertStringContainsString('CLAIM-157', $roadmap);
    $this->assertStringContainsString('no net-new assistant channels or third-party model expansion', $roadmap);
    $this->assertStringContainsString('no platform-wide refactor of unrelated Drupal subsystems', $roadmap);
  }

  /**
   * Current-state must include the dated P3-SBD-02 sprint closure addendum.
   */
  public function testCurrentStateContainsSprintSixWeekTwoAddendum(): void {
    $currentState = self::readFile('docs/aila/current-state.md');

    $this->assertStringContainsString(
      '### Phase 3 Sprint 6 Week 2 Performance/Cost Guardrails + Governance Signoff Disposition (2026-03-06)',
      $currentState
    );
    $this->assertStringContainsString('`P3-SBD-02`', $currentState);
    $this->assertStringContainsString('`P3-OBJ-02`', $currentState);
    $this->assertStringContainsString('`P3-OBJ-03`', $currentState);
    $this->assertStringContainsString('`P3-EXT-02`', $currentState);
    $this->assertStringContainsString('`P3-EXT-03`', $currentState);
    $this->assertStringContainsString('`VC-UNIT`', $currentState);
    $this->assertStringContainsString('`VC-QUALITY-GATE`', $currentState);
    $this->assertStringContainsString('phase3-sprint6-week2-performance-cost-governance-signoff.txt', $currentState);
    $this->assertStringContainsString('[^CLAIM-157]', $currentState);
  }

  /**
   * Runbook must include reproducible P3-SBD-02 verification steps.
   */
  public function testRunbookContainsSprintSixWeekTwoVerificationBundle(): void {
    $runbook = self::readFile('docs/aila/runbook.md');

    $this->assertStringContainsString(
      '### Phase 3 Sprint 6 Week 2 performance/cost guardrails + governance signoff verification (`P3-SBD-02`)',
      $runbook
    );
    $this->assertStringContainsString('# VC-UNIT', $runbook);
    $this->assertStringContainsString('# VC-QUALITY-GATE', $runbook);
    $this->assertStringContainsString('PhaseThreeSprintSixWeekTwoGateTest.php', $runbook);
    $this->assertStringContainsString('phase3-sprint6-week2-performance-cost-governance-signoff.txt', $runbook);
    $this->assertStringContainsString('PhaseThreeObjectiveTwoGateTest', $runbook);
    $this->assertStringContainsString('PhaseThreeObjectiveThreeGateTest', $runbook);
    $this->assertStringContainsString('PhaseThreeExitCriteriaTwoGateTest', $runbook);
    $this->assertStringContainsString('PhaseThreeExitCriteriaThreeGateTest', $runbook);
    $this->assertStringContainsString('[^CLAIM-157]', $runbook);
  }

  /**
   * Evidence index must include CLAIM-157 sprint closure section.
   */
  public function testEvidenceIndexContainsClaim157SprintClosureSection(): void {
    $evidenceIndex = self::readFile('docs/aila/evidence-index.md');

    $this->assertStringContainsString(
      '## Phase 3 Sprint 6 Week 2 Performance/Cost Guardrails + Governance Signoff Closure (`P3-SBD-02`)',
      $evidenceIndex
    );
    $this->assertStringContainsString('### CLAIM-157', $evidenceIndex);
    $this->assertStringContainsString('PhaseThreeSprintSixWeekTwoGateTest.php', $evidenceIndex);
    $this->assertStringContainsString('phase3-sprint6-week2-performance-cost-governance-signoff.txt', $evidenceIndex);
    $this->assertStringContainsString('P3-OBJ-02', $evidenceIndex);
    $this->assertStringContainsString('P3-OBJ-03', $evidenceIndex);
    $this->assertStringContainsString('P3-EXT-02', $evidenceIndex);
    $this->assertStringContainsString('P3-EXT-03', $evidenceIndex);
  }

  /**
   * Runtime artifact must contain required closure markers.
   */
  public function testRuntimeArtifactContainsSprintSixWeekTwoProofMarkers(): void {
    $artifact = self::readFile('docs/aila/runtime/phase3-sprint6-week2-performance-cost-governance-signoff.txt');

    $this->assertStringContainsString('# Phase 3 Sprint 6 Week 2 Runtime Evidence (P3-SBD-02)', $artifact);
    $this->assertStringContainsString('### VC-UNIT', $artifact);
    $this->assertStringContainsString('### VC-QUALITY-GATE', $artifact);
    $this->assertStringContainsString('p3-sbd-02-status=closed', $artifact);
    $this->assertStringContainsString('p3-sbd-02-claim-147=present', $artifact);
    $this->assertStringContainsString('p3-sbd-02-claim-148=present', $artifact);
    $this->assertStringContainsString('p3-sbd-02-claim-154=present', $artifact);
    $this->assertStringContainsString('p3-sbd-02-claim-155=present', $artifact);
    $this->assertStringContainsString('p3-sbd-02-claim-157=present', $artifact);
    $this->assertStringContainsString('p3-sbd-02-objective-link-primary=P3-OBJ-02', $artifact);
    $this->assertStringContainsString('p3-sbd-02-objective-link-secondary=P3-OBJ-03', $artifact);
    $this->assertStringContainsString('p3-sbd-02-exit-link-primary=P3-EXT-02', $artifact);
    $this->assertStringContainsString('p3-sbd-02-exit-link-secondary=P3-EXT-03', $artifact);
    $this->assertStringContainsString('no-net-new-assistant-channels=true', $artifact);
    $this->assertStringContainsString('no-third-party-model-expansion=true', $artifact);
    $this->assertStringContainsString('no-unrelated-drupal-platform-refactor=true', $artifact);
    $this->assertStringContainsString('b04-status=open', $artifact);
  }

  /**
   * Backlog/risk continuity anchors must remain present for cost/governance linkage.
   */
  public function testBacklogAndRiskRegisterContainCostGovernanceContinuityAnchors(): void {
    $backlog = self::readFile('docs/aila/backlog.md');
    $riskRegister = self::readFile('docs/aila/risk-register.md');

    $this->assertStringContainsString('IMP-COST-01 / P3-OBJ-02, 2026-03-05', $backlog);
    $this->assertStringContainsString('IMP-GOV-01 / P3-OBJ-03, 2026-03-05', $backlog);
    $this->assertStringContainsString('Retention/Access Attestation / P3-OBJ-03, 2026-03-05', $backlog);

    $this->assertStringContainsString('| R-PERF-01 |', $riskRegister);
    $this->assertStringContainsString('phase3-exit2-cost-performance-owner-acceptance.txt', $riskRegister);
    $this->assertStringContainsString('| R-GOV-01 |', $riskRegister);
    $this->assertStringContainsString('phase3-obj3-release-readiness-governance-attestation.txt', $riskRegister);
    $this->assertStringContainsString('| R-REL-02 |', $riskRegister);
    $this->assertStringContainsString('phase3-exit3-release-packet-known-unknown-risk-signoff.txt', $riskRegister);
  }

  /**
   * Diagram A continuity anchors must remain present for sprint closure context.
   */
  public function testSystemMapRetainsDiagramAAnchors(): void {
    $systemMap = self::readFile('docs/aila/system-map.mmd');

    $this->assertCurrentDiagramAQualityGateAnchors($systemMap);
  }

}
