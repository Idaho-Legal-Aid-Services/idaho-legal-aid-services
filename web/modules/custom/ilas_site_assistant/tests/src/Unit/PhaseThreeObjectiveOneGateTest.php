<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Guards Phase 3 Objective #1 closure artifacts (`P3-OBJ-01`).
 */
#[Group('ilas_site_assistant')]
final class PhaseThreeObjectiveOneGateTest extends TestCase {

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
   * Roadmap must include dated closure disposition for Phase 3 Objective #1.
   */
  public function testRoadmapContainsPhaseThreeObjectiveOneDisposition(): void {
    $roadmap = self::readFile('docs/aila/roadmap.md');

    $this->assertStringContainsString('## Phase 3 (Sprint 6): UX polish + performance/cost optimization', $roadmap);
    $this->assertStringContainsString('### Phase 3 Objective #1 disposition (2026-03-05)', $roadmap);
    $this->assertStringContainsString('Objective #1 is closed as implemented: accessibility and mobile UX hardening acceptance gates are delivered', $roadmap);
    $this->assertStringContainsString('phase3-obj1-ux-a11y-mobile-acceptance.txt', $roadmap);
    $this->assertStringContainsString('PhaseThreeObjectiveOneGateTest.php', $roadmap);
    $this->assertStringContainsString('CLAIM-149', $roadmap);
    $this->assertStringContainsString('No net-new assistant channels or third-party model expansion beyond audited providers.', $roadmap);
    $this->assertStringContainsString('No platform-wide refactor of unrelated Drupal subsystems.', $roadmap);
  }

  /**
   * Current-state must include the dated P3-OBJ-01 accessibility addendum.
   */
  public function testCurrentStateContainsObjectiveOneAccessibilityAddendum(): void {
    $currentState = self::readFile('docs/aila/current-state.md');

    $this->assertStringContainsString(
      '### Phase 3 Objective #1 Accessibility + Mobile UX Acceptance Disposition (2026-03-05)',
      $currentState
    );
    $this->assertStringContainsString('`P3-OBJ-01`', $currentState);
    $this->assertStringContainsString('`CLAIM-025`', $currentState);
    $this->assertStringContainsString('`CLAIM-032`', $currentState);
    $this->assertStringContainsString('phase3-obj1-ux-a11y-mobile-acceptance.txt', $currentState);
    $this->assertStringContainsString('[^CLAIM-149]', $currentState);
  }

  /**
   * Runbook section 2 must include reproducible P3-OBJ-01 verification steps.
   */
  public function testRunbookContainsObjectiveOneVerificationBundle(): void {
    $runbook = self::readFile('docs/aila/runbook.md');

    $this->assertStringContainsString(
      '### Phase 3 objective #1 accessibility + mobile UX acceptance verification (`P3-OBJ-01`)',
      $runbook
    );
    $this->assertStringContainsString('# VC-UNIT', $runbook);
    $this->assertStringContainsString('# VC-DRUPAL-UNIT', $runbook);
    $this->assertStringContainsString('AccessibilityMobileUxAcceptanceGateTest.php', $runbook);
    $this->assertStringContainsString('RecoveryUxContractTest.php', $runbook);
    $this->assertStringContainsString('docs/aila/backlog.md', $runbook);
    $this->assertStringContainsString('docs/aila/risk-register.md', $runbook);
    $this->assertStringContainsString('PhaseThreeObjectiveOneGateTest.php', $runbook);
    $this->assertStringContainsString('phase3-obj1-ux-a11y-mobile-acceptance.txt', $runbook);
    $this->assertStringContainsString('[^CLAIM-149]', $runbook);
  }

  /**
   * Evidence index must include CLAIM-149 and P3 addenda under 025/032.
   */
  public function testEvidenceIndexContainsClaim149AndObjectiveAddenda(): void {
    $evidenceIndex = self::readFile('docs/aila/evidence-index.md');

    $this->assertStringContainsString('### CLAIM-025', $evidenceIndex);
    $this->assertStringContainsString('Addendum (2026-03-05): Phase 3 Objective #1 (`P3-OBJ-01`)', $evidenceIndex);
    $this->assertStringContainsString('### CLAIM-032', $evidenceIndex);
    $this->assertStringContainsString(
      '## Phase 3 Objective #1 Accessibility + Mobile UX Acceptance Closure (`P3-OBJ-01`)',
      $evidenceIndex
    );
    $this->assertStringContainsString('### CLAIM-149', $evidenceIndex);
    $this->assertStringContainsString('PhaseThreeObjectiveOneGateTest.php', $evidenceIndex);
  }

  /**
   * Runtime artifact must contain required closure markers.
   */
  public function testRuntimeArtifactContainsObjectiveOneProofMarkers(): void {
    $artifact = self::readFile('docs/aila/runtime/phase3-obj1-ux-a11y-mobile-acceptance.txt');

    $this->assertStringContainsString('# Phase 3 Objective #1 Runtime Evidence (P3-OBJ-01)', $artifact);
    $this->assertStringContainsString('### VC-UNIT', $artifact);
    $this->assertStringContainsString('### VC-DRUPAL-UNIT', $artifact);
    $this->assertStringContainsString('p3-obj-01-status=closed', $artifact);
    $this->assertStringContainsString('a11y-anchor-claim-025=present', $artifact);
    $this->assertStringContainsString('a11y-anchor-claim-032=present', $artifact);
    $this->assertStringContainsString('a11y-anchor-acceptance-gate-test=present', $artifact);
    $this->assertStringContainsString('a11y-anchor-recovery-ux-contract-test=present', $artifact);
    $this->assertStringContainsString('a11y-anchor-widget-hardening-js-test=present', $artifact);
    $this->assertStringContainsString('mobile-anchor-claim-026=present', $artifact);
    $this->assertStringContainsString('mobile-anchor-claim-031=present', $artifact);
    $this->assertStringContainsString('mobile-anchor-timeout-offline-contracts=present', $artifact);
  }

  /**
   * Backlog and risk linkage must move to active mitigation posture.
   */
  public function testBacklogAndRiskRegisterMoveToActiveMitigation(): void {
    $backlog = self::readFile('docs/aila/backlog.md');
    $riskRegister = self::readFile('docs/aila/risk-register.md');

    $this->assertStringContainsString('Done (IMP-UX-01, 2026-03-05)', $backlog);
    $this->assertStringContainsString('| R-UX-01 |', $riskRegister);
    $this->assertStringContainsString('| R-UX-02 |', $riskRegister);
    $this->assertStringContainsString('phase3-obj1-ux-a11y-mobile-acceptance.txt', $riskRegister);
    $this->assertStringContainsString('| Active mitigation |', $riskRegister);
  }

  /**
   * Diagram A anchors must remain present for objective context.
   */
  public function testSystemMapRetainsDiagramAAnchors(): void {
    $systemMap = self::readFile('docs/aila/system-map.mmd');

    $this->assertStringContainsString('flowchart LR', $systemMap);
    $this->assertStringContainsString('Drupal 11 / ilas_site_assistant', $systemMap);
    $this->assertStringContainsString('External Integrations', $systemMap);
    $this->assertStringContainsString('CI[External CI runner', $systemMap);
    $this->assertStringContainsString('PF[Promptfoo harness]', $systemMap);
  }

}
