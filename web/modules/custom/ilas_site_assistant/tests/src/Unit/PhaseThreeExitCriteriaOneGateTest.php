<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Guards Phase 3 Exit criterion #1 closure artifacts (`P3-EXT-01`).
 */
#[Group('ilas_site_assistant')]
final class PhaseThreeExitCriteriaOneGateTest extends TestCase {

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
   * Roadmap must include dated closure disposition for Phase 3 Exit #1.
   */
  public function testRoadmapContainsPhaseThreeExitOneDisposition(): void {
    $roadmap = self::readFile('docs/aila/roadmap.md');

    $this->assertStringContainsString('### Phase 3 Exit #1 disposition (2026-03-06)', $roadmap);
    $this->assertStringContainsString('Exit criterion #1 is closed as implemented: UX/a11y test suite is gating and passing.', $roadmap);
    $this->assertStringContainsString('phase3-exit1-ux-a11y-gating.txt', $roadmap);
    $this->assertStringContainsString('CLAIM-153', $roadmap);
    $this->assertStringContainsString('no net-new assistant channels or third-party model expansion', $roadmap);
    $this->assertStringContainsString('no platform-wide refactor of unrelated Drupal subsystems', $roadmap);
  }

  /**
   * Current-state must include the dated P3-EXT-01 closure addendum.
   */
  public function testCurrentStateContainsPhaseThreeExitOneAddendum(): void {
    $currentState = self::readFile('docs/aila/current-state.md');

    $this->assertStringContainsString(
      '### Phase 3 Exit #1 UX/a11y Test Suite Gating + Passing Disposition (2026-03-06)',
      $currentState
    );
    $this->assertStringContainsString('`P3-EXT-01`', $currentState);
    $this->assertStringContainsString('`VC-RUNBOOK-LOCAL`', $currentState);
    $this->assertStringContainsString('`VC-RUNBOOK-PANTHEON`', $currentState);
    $this->assertStringContainsString('run-assistant-widget-hardening.mjs', $currentState);
    $this->assertStringContainsString('phase3-exit1-ux-a11y-gating.txt', $currentState);
    $this->assertStringContainsString('[^CLAIM-153]', $currentState);
  }

  /**
   * Runbook section 4 must include reproducible P3-EXT-01 verification steps.
   */
  public function testRunbookContainsPhaseThreeExitOneVerificationBundle(): void {
    $runbook = self::readFile('docs/aila/runbook.md');

    $this->assertStringContainsString(
      '### Phase 3 exit #1 UX/a11y test suite gating + passing verification (`P3-EXT-01`)',
      $runbook
    );
    $this->assertStringContainsString('# VC-RUNBOOK-LOCAL', $runbook);
    $this->assertStringContainsString('# VC-RUNBOOK-PANTHEON', $runbook);
    $this->assertStringContainsString('run-assistant-widget-hardening.mjs', $runbook);
    $this->assertStringContainsString('PhaseThreeExitCriteriaOneGateTest.php', $runbook);
    $this->assertStringContainsString('phase3-exit1-ux-a11y-gating.txt', $runbook);
    $this->assertStringContainsString('If `VC-RUNBOOK-PANTHEON` fails', $runbook);
    $this->assertStringContainsString('[^CLAIM-153]', $runbook);
  }

  /**
   * Evidence index must include addenda and CLAIM-153 closure section.
   */
  public function testEvidenceIndexContainsPhaseThreeExitOneClaim(): void {
    $evidenceIndex = self::readFile('docs/aila/evidence-index.md');

    $this->assertStringContainsString('### CLAIM-025', $evidenceIndex);
    $this->assertStringContainsString('### CLAIM-032', $evidenceIndex);
    $this->assertStringContainsString('### CLAIM-105', $evidenceIndex);
    $this->assertStringContainsString('Addendum (2026-03-06): Phase 3 Exit #1 (`P3-EXT-01`)', $evidenceIndex);
    $this->assertStringContainsString('## Phase 3 Exit #1 UX/a11y Test Suite Gating + Passing (`P3-EXT-01`)', $evidenceIndex);
    $this->assertStringContainsString('### CLAIM-153', $evidenceIndex);
    $this->assertStringContainsString('PhaseThreeExitCriteriaOneGateTest.php', $evidenceIndex);
  }

  /**
   * Runtime artifact must contain required closure markers.
   */
  public function testRuntimeArtifactContainsPhaseThreeExitOneProofMarkers(): void {
    $artifact = self::readFile('docs/aila/runtime/phase3-exit1-ux-a11y-gating.txt');

    $this->assertStringContainsString('# Phase 3 Exit #1 Runtime Evidence (P3-EXT-01)', $artifact);
    $this->assertStringContainsString('`VC-RUNBOOK-LOCAL`', $artifact);
    $this->assertStringContainsString('`VC-RUNBOOK-PANTHEON`', $artifact);
    $this->assertStringContainsString('p3-ext-01-status=closed', $artifact);
    $this->assertStringContainsString('p3-ext-01-ci-gate=promptfoo-gate-required', $artifact);
    $this->assertStringContainsString('p3-ext-01-a11y-js-suite=passed', $artifact);
    $this->assertStringContainsString('p3-ext-01-claim-025=present', $artifact);
    $this->assertStringContainsString('p3-ext-01-claim-032=present', $artifact);
    $this->assertStringContainsString('p3-ext-01-claim-105=present', $artifact);
  }

  /**
   * CI workflow and JS runner anchors must remain present.
   */
  public function testCiWorkflowAndJsRunnerAnchorsRemainPresent(): void {
    $workflow = self::readFile('.github/workflows/quality-gate.yml');
    $runner = self::readFile('web/modules/custom/ilas_site_assistant/tests/js/run-assistant-widget-hardening.mjs');

    $this->assertStringContainsString('Run UX/a11y widget hardening suite (P3-EXT-01)', $workflow);
    $this->assertStringContainsString('run-assistant-widget-hardening.mjs', $workflow);
    $this->assertStringContainsString('promptfoo-gate', $workflow);

    $this->assertStringContainsString("import { JSDOM } from 'jsdom';", $runner);
    $this->assertStringContainsString('window._assistantWidgetTestResults', $runner);
  }

  /**
   * Diagram A continuity anchors must remain present for exit context.
   */
  public function testSystemMapRetainsDiagramAAnchors(): void {
    $systemMap = self::readFile('docs/aila/system-map.mmd');

    $this->assertCurrentDiagramAQualityGateAnchors($systemMap);
  }

}
