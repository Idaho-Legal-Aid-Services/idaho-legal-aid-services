<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Guards Phase 3 Sprint 6 Week 1 closure artifacts (`P3-SBD-01`).
 */
#[Group('ilas_site_assistant')]
final class PhaseThreeSprintSixWeekOneGateTest extends TestCase {

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
   * Roadmap must include the dated Sprint 6 Week 1 closure disposition.
   */
  public function testRoadmapContainsSprintSixWeekOneDisposition(): void {
    $roadmap = self::readFile('docs/aila/roadmap.md');

    $this->assertStringContainsString('### Phase 3 Sprint 6 Week 1 disposition (2026-03-06)', $roadmap);
    $this->assertStringContainsString('"Sprint 6 Week 1: UX/a11y and mobile hardening."', $roadmap);
    $this->assertStringContainsString('phase3-sprint6-week1-ux-a11y-mobile-hardening.txt', $roadmap);
    $this->assertStringContainsString('PhaseThreeSprintSixWeekOneGateTest.php', $roadmap);
    $this->assertStringContainsString('CLAIM-156', $roadmap);
    $this->assertStringContainsString('no net-new assistant channels or third-party model expansion', $roadmap);
    $this->assertStringContainsString('no platform-wide refactor of unrelated Drupal subsystems', $roadmap);
  }

  /**
   * Current-state must include the dated P3-SBD-01 sprint closure addendum.
   */
  public function testCurrentStateContainsSprintSixWeekOneAddendum(): void {
    $currentState = self::readFile('docs/aila/current-state.md');

    $this->assertStringContainsString(
      '### Phase 3 Sprint 6 Week 1 UX/a11y + Mobile Hardening Disposition (2026-03-06)',
      $currentState
    );
    $this->assertStringContainsString('`P3-SBD-01`', $currentState);
    $this->assertStringContainsString('`P3-OBJ-01`', $currentState);
    $this->assertStringContainsString('`P3-EXT-01`', $currentState);
    $this->assertStringContainsString('`VC-UNIT`', $currentState);
    $this->assertStringContainsString('`VC-QUALITY-GATE`', $currentState);
    $this->assertStringContainsString('phase3-sprint6-week1-ux-a11y-mobile-hardening.txt', $currentState);
    $this->assertStringContainsString('[^CLAIM-156]', $currentState);
  }

  /**
   * Runbook must include reproducible P3-SBD-01 verification steps.
   */
  public function testRunbookContainsSprintSixWeekOneVerificationBundle(): void {
    $runbook = self::readFile('docs/aila/runbook.md');

    $this->assertStringContainsString(
      '### Phase 3 Sprint 6 Week 1 UX/a11y + mobile hardening verification (`P3-SBD-01`)',
      $runbook
    );
    $this->assertStringContainsString('# VC-UNIT', $runbook);
    $this->assertStringContainsString('# VC-QUALITY-GATE', $runbook);
    $this->assertStringContainsString('PhaseThreeSprintSixWeekOneGateTest.php', $runbook);
    $this->assertStringContainsString('phase3-sprint6-week1-ux-a11y-mobile-hardening.txt', $runbook);
    $this->assertStringContainsString('AccessibilityMobileUxAcceptanceGateTest', $runbook);
    $this->assertStringContainsString('RecoveryUxContractTest', $runbook);
    $this->assertStringContainsString('assistant-widget-hardening', $runbook);
    $this->assertStringContainsString('[^CLAIM-156]', $runbook);
  }

  /**
   * Evidence index must include CLAIM-156 sprint closure section.
   */
  public function testEvidenceIndexContainsClaim156SprintClosureSection(): void {
    $evidenceIndex = self::readFile('docs/aila/evidence-index.md');

    $this->assertStringContainsString(
      '## Phase 3 Sprint 6 Week 1 UX/a11y + Mobile Hardening Closure (`P3-SBD-01`)',
      $evidenceIndex
    );
    $this->assertStringContainsString('### CLAIM-156', $evidenceIndex);
    $this->assertStringContainsString('PhaseThreeSprintSixWeekOneGateTest.php', $evidenceIndex);
    $this->assertStringContainsString('phase3-sprint6-week1-ux-a11y-mobile-hardening.txt', $evidenceIndex);
  }

  /**
   * Runtime artifact must contain required closure markers.
   */
  public function testRuntimeArtifactContainsSprintSixWeekOneProofMarkers(): void {
    $artifact = self::readFile('docs/aila/runtime/phase3-sprint6-week1-ux-a11y-mobile-hardening.txt');

    $this->assertStringContainsString('# Phase 3 Sprint 6 Week 1 Runtime Evidence (P3-SBD-01)', $artifact);
    $this->assertStringContainsString('### VC-UNIT', $artifact);
    $this->assertStringContainsString('### VC-QUALITY-GATE', $artifact);
    $this->assertStringContainsString('p3-sbd-01-status=closed', $artifact);
    $this->assertStringContainsString('p3-sbd-01-claim-149=present', $artifact);
    $this->assertStringContainsString('p3-sbd-01-claim-153=present', $artifact);
    $this->assertStringContainsString('p3-sbd-01-claim-156=present', $artifact);
    $this->assertStringContainsString('p3-sbd-01-objective-link=P3-OBJ-01', $artifact);
    $this->assertStringContainsString('p3-sbd-01-exit-link=P3-EXT-01', $artifact);
    $this->assertStringContainsString('p3-sbd-01-a11y-gate-test=present', $artifact);
    $this->assertStringContainsString('p3-sbd-01-recovery-ux-contract-test=present', $artifact);
    $this->assertStringContainsString('p3-sbd-01-widget-hardening-js-test=present', $artifact);
    $this->assertStringContainsString('no-net-new-assistant-channels=true', $artifact);
    $this->assertStringContainsString('no-third-party-model-expansion=true', $artifact);
    $this->assertStringContainsString('no-unrelated-drupal-platform-refactor=true', $artifact);
  }

  /**
   * Diagram A continuity anchors must remain present for sprint closure context.
   */
  public function testSystemMapRetainsDiagramAAnchors(): void {
    $systemMap = self::readFile('docs/aila/system-map.mmd');

    $this->assertCurrentDiagramAQualityGateAnchors($systemMap);
  }

}
