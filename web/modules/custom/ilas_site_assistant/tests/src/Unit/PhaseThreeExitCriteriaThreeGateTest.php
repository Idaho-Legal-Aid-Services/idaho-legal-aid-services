<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Guards Phase 3 Exit criterion #3 closure artifacts (`P3-EXT-03`).
 */
#[Group('ilas_site_assistant')]
final class PhaseThreeExitCriteriaThreeGateTest extends TestCase {

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
   * Roadmap must include dated closure disposition for Phase 3 Exit #3.
   */
  public function testRoadmapContainsPhaseThreeExitThreeDisposition(): void {
    $roadmap = self::readFile('docs/aila/roadmap.md');

    $this->assertStringContainsString('### Phase 3 Exit #3 disposition (2026-03-06)', $roadmap);
    $this->assertStringContainsString(
      'Exit criterion #3 is closed as implemented: final release packet includes known-unknown disposition and residual risk signoff.',
      $roadmap
    );
    $this->assertStringContainsString('phase3-exit3-release-packet-known-unknown-risk-signoff.txt', $roadmap);
    $this->assertStringContainsString('PhaseThreeExitCriteriaThreeGateTest.php', $roadmap);
    $this->assertStringContainsString('CLAIM-155', $roadmap);
    $this->assertStringContainsString('no net-new assistant channels or third-party model expansion', $roadmap);
    $this->assertStringContainsString('no platform-wide refactor of unrelated Drupal subsystems', $roadmap);
    $this->assertStringContainsString('B-04', $roadmap);
  }

  /**
   * Current-state must include dated P3-EXT-03 known-unknown/risk-signoff addendum.
   */
  public function testCurrentStateContainsPhaseThreeExitThreeAddendum(): void {
    $currentState = self::readFile('docs/aila/current-state.md');

    $this->assertStringContainsString(
      '### Phase 3 Exit #3 Final Release Packet Known-Unknown Disposition + Residual Risk Signoff Disposition (2026-03-06)',
      $currentState
    );
    $this->assertStringContainsString('`P3-EXT-03`', $currentState);
    $this->assertStringContainsString('`VC-RUNBOOK-LOCAL`', $currentState);
    $this->assertStringContainsString('`VC-RUNBOOK-PANTHEON`', $currentState);
    $this->assertStringContainsString('Promptfoo CI', $currentState);
    $this->assertStringContainsString('ownership remains resolved', $currentState);
    $this->assertStringContainsString('long-run cron/queue load observation remains', $currentState);
    $this->assertStringContainsString('residual boundary `B-04`', $currentState);
    $this->assertStringContainsString('phase3-exit3-release-packet-known-unknown-risk-signoff.txt', $currentState);
    $this->assertStringContainsString('[^CLAIM-155]', $currentState);
  }

  /**
   * Runbook section 4 must include reproducible P3-EXT-03 verification steps.
   */
  public function testRunbookContainsPhaseThreeExitThreeVerificationBundle(): void {
    $runbook = self::readFile('docs/aila/runbook.md');

    $this->assertStringContainsString(
      '### Phase 3 exit #3 final release packet known-unknown disposition + residual risk signoff verification (`P3-EXT-03`)',
      $runbook
    );
    $this->assertStringContainsString('# VC-RUNBOOK-LOCAL', $runbook);
    $this->assertStringContainsString('# VC-RUNBOOK-PANTHEON', $runbook);
    $this->assertStringContainsString('Known-unknown and residual-risk continuity checks', $runbook);
    $this->assertStringContainsString('Promptfoo CI ownership', $runbook);
    $this->assertStringContainsString('R-REL-02', $runbook);
    $this->assertStringContainsString('PhaseThreeExitCriteriaThreeGateTest.php', $runbook);
    $this->assertStringContainsString('phase3-exit3-release-packet-known-unknown-risk-signoff.txt', $runbook);
    $this->assertStringContainsString('If `VC-RUNBOOK-PANTHEON` fails', $runbook);
    $this->assertStringContainsString('[^CLAIM-155]', $runbook);
  }

  /**
   * Evidence index must include CLAIM-122 addendum and terminal CLAIM-155 section.
   */
  public function testEvidenceIndexContainsPhaseThreeExitThreeClaimAndAddendum(): void {
    $evidenceIndex = self::readFile('docs/aila/evidence-index.md');

    $this->assertStringContainsString('### CLAIM-122', $evidenceIndex);
    $this->assertStringContainsString('Addendum (2026-03-06): Phase 3 Exit #3 (`P3-EXT-03`)', $evidenceIndex);
    $this->assertStringContainsString(
      '## Phase 3 Exit #3 Final Release Packet Includes Known-Unknown Disposition + Residual Risk Signoff (`P3-EXT-03`)',
      $evidenceIndex
    );
    $this->assertStringContainsString('### CLAIM-155', $evidenceIndex);
    $this->assertStringContainsString('PhaseThreeExitCriteriaThreeGateTest.php', $evidenceIndex);
  }

  /**
   * Runtime artifact must contain required closure and signoff markers.
   */
  public function testRuntimeArtifactContainsPhaseThreeExitThreeProofMarkers(): void {
    $artifact = self::readFile('docs/aila/runtime/phase3-exit3-release-packet-known-unknown-risk-signoff.txt');

    $this->assertStringContainsString('# Phase 3 Exit #3 Runtime Evidence (P3-EXT-03)', $artifact);
    $this->assertStringContainsString('`VC-RUNBOOK-LOCAL`', $artifact);
    $this->assertStringContainsString('`VC-RUNBOOK-PANTHEON`', $artifact);
    $this->assertStringContainsString('p3-ext-03-status=closed', $artifact);
    $this->assertStringContainsString('p3-ext-03-claim-122=present', $artifact);
    $this->assertStringContainsString('p3-ext-03-claim-155=present', $artifact);
    $this->assertStringContainsString('known-unknown.promptfoo-ci-ownership=resolved', $artifact);
    $this->assertStringContainsString('known-unknown.cron-queue-load-observation=open', $artifact);
    $this->assertStringContainsString('residual-risk-id=B-04', $artifact);
    $this->assertStringContainsString('residual-risk-signoff-product-role=accepted', $artifact);
    $this->assertStringContainsString('residual-risk-signoff-platform-role=accepted', $artifact);
    $this->assertStringContainsString('residual-risk-signoff-date=2026-03-06', $artifact);
    $this->assertStringContainsString('residual-risk-disposition=accepted-open', $artifact);
    $this->assertStringContainsString('b04-status=open', $artifact);
    $this->assertStringContainsString('no-net-new-assistant-channels=true', $artifact);
    $this->assertStringContainsString('no-third-party-model-expansion=true', $artifact);
    $this->assertStringContainsString('no-unrelated-drupal-platform-refactor=true', $artifact);
  }

  /**
   * Risk register must include explicit P3-EXT-03 signoff continuity on R-REL-02.
   */
  public function testRiskRegisterContainsPhaseThreeExitThreeLinkage(): void {
    $riskRegister = self::readFile('docs/aila/risk-register.md');

    $this->assertStringContainsString('| R-REL-02 |', $riskRegister);
    $this->assertStringContainsString('P3-EXT-03', $riskRegister);
    $this->assertStringContainsString('phase3-exit3-release-packet-known-unknown-risk-signoff.txt', $riskRegister);
    $this->assertStringContainsString('residual-risk-signoff-product-role', $riskRegister);
    $this->assertStringContainsString('residual-risk-signoff-platform-role', $riskRegister);
  }

  /**
   * Diagram A continuity anchors must remain present for exit context.
   */
  public function testSystemMapRetainsDiagramAAnchors(): void {
    $systemMap = self::readFile('docs/aila/system-map.mmd');

    $this->assertCurrentDiagramAQualityGateAnchors($systemMap);
  }

}
