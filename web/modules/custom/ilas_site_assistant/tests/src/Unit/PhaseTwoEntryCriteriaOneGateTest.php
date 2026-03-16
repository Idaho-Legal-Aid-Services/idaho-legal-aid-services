<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Guards Phase 2 Entry criteria #1 closure artifacts (`P2-ENT-01`).
 */
#[Group('ilas_site_assistant')]
final class PhaseTwoEntryCriteriaOneGateTest extends TestCase {

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
   * Roadmap must include dated closure disposition for Phase 2 Entry #1.
   */
  public function testRoadmapContainsPhaseTwoEntryOneDisposition(): void {
    $roadmap = self::readFile('docs/aila/roadmap.md');

    $this->assertStringContainsString('### Phase 2 Entry #1 disposition (2026-03-04)', $roadmap);
    $this->assertStringContainsString('Entry criterion #1 is closed as operational', $roadmap);
    $this->assertStringContainsString('CI baseline continuity from Phase 1 remains operational', $roadmap);
    $this->assertStringContainsString('phase2-entry1-observability-ci-baseline.txt', $roadmap);
    $this->assertStringContainsString('no live production LLM enablement in Phase 2', $roadmap);
    $this->assertStringContainsString('no broad platform migration outside current Pantheon baseline', $roadmap);
    $this->assertStringContainsString('CLAIM-138', $roadmap);
  }

  /**
   * Current-state must include the dated P2-ENT-01 continuity addendum.
   */
  public function testCurrentStateContainsEntryOneOperationalAddendum(): void {
    $currentState = self::readFile('docs/aila/current-state.md');

    $this->assertStringContainsString(
      '### Phase 2 Entry #1 Observability + CI Baseline Operational Disposition (2026-03-04)',
      $currentState
    );
    $this->assertStringContainsString('`VC-RUNBOOK-LOCAL`, `VC-TOGGLE-CHECK`', $currentState);
    $this->assertStringContainsString('phase2-entry1-observability-ci-baseline.txt', $currentState);
    $this->assertStringContainsString('B-04', $currentState);
    $this->assertStringContainsString('[^CLAIM-138]', $currentState);
  }

  /**
   * Runbook section 3 must include reproducible P2-ENT-01 verification steps.
   */
  public function testRunbookContainsEntryOneVerificationBundle(): void {
    $runbook = self::readFile('docs/aila/runbook.md');

    $this->assertStringContainsString(
      '### Phase 2 entry #1 observability + CI baseline operational verification (`P2-ENT-01`)',
      $runbook
    );
    $this->assertStringContainsString('# VC-RUNBOOK-LOCAL', $runbook);
    $this->assertStringContainsString('# VC-TOGGLE-CHECK', $runbook);
    $this->assertStringContainsString('.github/workflows/quality-gate.yml', $runbook);
    $this->assertStringContainsString('scripts/ci', $runbook);
    $this->assertStringContainsString('phase2-entry1-observability-ci-baseline.txt', $runbook);
    $this->assertStringContainsString('no live LLM enablement through Phase 2', $runbook);
    $this->assertStringContainsString('no broad platform migration outside the current Pantheon baseline', $runbook);
    $this->assertStringContainsString('[^CLAIM-138]', $runbook);
  }

  /**
   * Evidence index must include addenda and the new P2-ENT-01 closure claim.
   */
  public function testEvidenceIndexContainsEntryOneClosureClaimAndAddenda(): void {
    $evidenceIndex = self::readFile('docs/aila/evidence-index.md');

    $this->assertStringContainsString('### CLAIM-084', $evidenceIndex);
    $this->assertStringContainsString('### CLAIM-122', $evidenceIndex);
    $this->assertStringContainsString('Addendum (2026-03-04): Phase 2 Entry #1 (`P2-ENT-01`)', $evidenceIndex);
    $this->assertStringContainsString('## Phase 2 Entry #1 Observability + CI Baseline Operational (`P2-ENT-01`)', $evidenceIndex);
    $this->assertStringContainsString('### CLAIM-138', $evidenceIndex);
    $this->assertStringContainsString('PhaseTwoEntryCriteriaOneGateTest.php', $evidenceIndex);
  }

  /**
   * Runtime artifact must contain the required VC and CI/diagram continuity markers.
   */
  public function testRuntimeArtifactContainsRequiredVerificationMarkers(): void {
    $artifact = self::readFile('docs/aila/runtime/phase2-entry1-observability-ci-baseline.txt');

    $this->assertStringContainsString('`VC-RUNBOOK-LOCAL`', $artifact);
    $this->assertStringContainsString('`VC-TOGGLE-CHECK`', $artifact);
    $this->assertStringContainsString('system.cron_last=', $artifact);
    $this->assertStringContainsString('name: Quality Gate', $artifact);
    $this->assertStringContainsString('run-promptfoo-gate.sh', $artifact);
    $this->assertStringContainsString('CI -->|drives scripted quality gates| PF', $artifact);
  }

  /**
   * Diagram A and CI workflow anchors must remain present for entry continuity.
   */
  public function testSystemMapAndWorkflowAnchorsRemainPresent(): void {
    $systemMap = self::readFile('docs/aila/system-map.mmd');
    $workflow = self::readFile('.github/workflows/quality-gate.yml');

    $this->assertCurrentDiagramAQualityGateAnchors($systemMap, requireObservability: TRUE);

    $this->assertStringContainsString('name: Quality Gate', $workflow);
    $this->assertStringContainsString("'release/**'", $workflow);
    $this->assertStringContainsString('cancel-in-progress: true', $workflow);
    $this->assertStringContainsString('name: PHPUnit Quality Gate', $workflow);
    $this->assertStringContainsString('name: Promptfoo Gate', $workflow);

    self::assertFileExists(self::repoRoot() . '/scripts/ci/run-promptfoo-gate.sh');
    self::assertFileExists(self::repoRoot() . '/scripts/ci/run-external-quality-gate.sh');
  }

}
