<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Guards Phase 3 Objective #3 closure artifacts (`P3-OBJ-03`).
 */
#[Group('ilas_site_assistant')]
final class PhaseThreeObjectiveThreeGateTest extends TestCase {

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
   * Roadmap must include dated closure disposition for Phase 3 Objective #3.
   */
  public function testRoadmapContainsPhaseThreeObjectiveThreeDisposition(): void {
    $roadmap = self::readFile('docs/aila/roadmap.md');

    $this->assertStringContainsString('## Phase 3 (Sprint 6): UX polish + performance/cost optimization', $roadmap);
    $this->assertStringContainsString('### Phase 3 Objective #3 disposition (2026-03-05)', $roadmap);
    $this->assertStringContainsString('Objective #3 is closed as implemented: release readiness package and governance attestation are delivered', $roadmap);
    $this->assertStringContainsString('phase3-obj3-release-readiness-governance-attestation.txt', $roadmap);
    $this->assertStringContainsString('PhaseThreeObjectiveThreeGateTest.php', $roadmap);
    $this->assertStringContainsString('CLAIM-148', $roadmap);
    $this->assertStringContainsString('No net-new assistant channels or third-party model expansion beyond audited providers.', $roadmap);
    $this->assertStringContainsString('No platform-wide refactor of unrelated Drupal subsystems.', $roadmap);
  }

  /**
   * Current-state must include the dated P3-OBJ-03 release-readiness addendum.
   */
  public function testCurrentStateContainsObjectiveThreeOperationalAddendum(): void {
    $currentState = self::readFile('docs/aila/current-state.md');

    $this->assertStringContainsString(
      '### Phase 3 Objective #3 Release Readiness + Governance Attestation Disposition (2026-03-05)',
      $currentState
    );
    $this->assertStringContainsString('`P3-OBJ-03`', $currentState);
    $this->assertStringContainsString('`CLAIM-108`', $currentState);
    $this->assertStringContainsString('`CLAIM-115`', $currentState);
    $this->assertStringContainsString('phase3-obj3-release-readiness-governance-attestation.txt', $currentState);
    $this->assertStringContainsString('[^CLAIM-148]', $currentState);
  }

  /**
   * Runbook section 4 must include reproducible P3-OBJ-03 verification steps.
   */
  public function testRunbookContainsObjectiveThreeVerificationBundle(): void {
    $runbook = self::readFile('docs/aila/runbook.md');

    $this->assertStringContainsString(
      '### Phase 3 objective #3 release readiness package + governance attestation verification (`P3-OBJ-03`)',
      $runbook
    );
    $this->assertStringContainsString('# VC-UNIT', $runbook);
    $this->assertStringContainsString('# VC-DRUPAL-UNIT', $runbook);
    $this->assertStringContainsString('local-preflight.txt', $runbook);
    $this->assertStringContainsString('pantheon-dev.txt', $runbook);
    $this->assertStringContainsString('pantheon-test.txt', $runbook);
    $this->assertStringContainsString('pantheon-live.txt', $runbook);
    $this->assertStringContainsString('docs/aila/backlog.md', $runbook);
    $this->assertStringContainsString('docs/aila/risk-register.md', $runbook);
    $this->assertStringContainsString('PhaseThreeObjectiveThreeGateTest.php', $runbook);
    $this->assertStringContainsString('phase3-obj3-release-readiness-governance-attestation.txt', $runbook);
    $this->assertStringContainsString('[^CLAIM-148]', $runbook);
  }

  /**
   * Evidence index must include CLAIM-148 and P3 addenda under 108/115.
   */
  public function testEvidenceIndexContainsClaim148AndObjectiveAddenda(): void {
    $evidenceIndex = self::readFile('docs/aila/evidence-index.md');

    $this->assertStringContainsString('### CLAIM-108', $evidenceIndex);
    $this->assertStringContainsString('Addendum (2026-03-05): Phase 3 Objective #3 (`P3-OBJ-03`)', $evidenceIndex);
    $this->assertStringContainsString('### CLAIM-115', $evidenceIndex);
    $this->assertStringContainsString(
      '## Phase 3 Objective #3 Release Readiness Package + Governance Attestation Closure (`P3-OBJ-03`)',
      $evidenceIndex
    );
    $this->assertStringContainsString('### CLAIM-148', $evidenceIndex);
    $this->assertStringContainsString('PhaseThreeObjectiveThreeGateTest.php', $evidenceIndex);
  }

  /**
   * Runtime artifact must contain required closure markers.
   */
  public function testRuntimeArtifactContainsObjectiveThreeProofMarkers(): void {
    $artifact = self::readFile('docs/aila/runtime/phase3-obj3-release-readiness-governance-attestation.txt');

    $this->assertStringContainsString('# Phase 3 Objective #3 Runtime Evidence (P3-OBJ-03)', $artifact);
    $this->assertStringContainsString('### VC-UNIT', $artifact);
    $this->assertStringContainsString('### VC-DRUPAL-UNIT', $artifact);
    $this->assertStringContainsString('p3-obj-03-status=closed', $artifact);
    $this->assertStringContainsString('release-readiness-anchor-claim-108=present', $artifact);
    $this->assertStringContainsString('release-readiness-anchor-claim-115=present', $artifact);
    $this->assertStringContainsString('release-readiness-anchor-local-preflight=present', $artifact);
    $this->assertStringContainsString('release-readiness-anchor-pantheon-dev=present', $artifact);
    $this->assertStringContainsString('release-readiness-anchor-pantheon-test=present', $artifact);
    $this->assertStringContainsString('release-readiness-anchor-pantheon-live=present', $artifact);
    $this->assertStringContainsString('governance-attestation-anchor-backlog-imp-gov-01=present', $artifact);
    $this->assertStringContainsString('governance-attestation-anchor-backlog-retention-attestation=present', $artifact);
    $this->assertStringContainsString('governance-attestation-anchor-risk-r-gov-01=present', $artifact);
  }

  /**
   * Backlog and risk linkage must move to active mitigation posture.
   */
  public function testBacklogAndRiskRegisterMoveToActiveMitigation(): void {
    $backlog = self::readFile('docs/aila/backlog.md');
    $riskRegister = self::readFile('docs/aila/risk-register.md');

    $this->assertStringContainsString('Active mitigation (IMP-GOV-01 / P3-OBJ-03, 2026-03-05)', $backlog);
    $this->assertStringContainsString('Active mitigation (Retention/Access Attestation / P3-OBJ-03, 2026-03-05)', $backlog);
    $this->assertStringContainsString('| R-GOV-01 |', $riskRegister);
    $this->assertStringContainsString('phase3-obj3-release-readiness-governance-attestation.txt', $riskRegister);
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
