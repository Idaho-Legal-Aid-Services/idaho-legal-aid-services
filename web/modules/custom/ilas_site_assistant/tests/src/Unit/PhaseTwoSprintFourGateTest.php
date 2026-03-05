<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Guards Phase 2 Sprint 4 closure artifacts (`P2-SBD-01`).
 */
#[Group('ilas_site_assistant')]
final class PhaseTwoSprintFourGateTest extends TestCase {

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
   * Roadmap must include dated Sprint 4 closure disposition.
   */
  public function testRoadmapContainsSprintFourDisposition(): void {
    $roadmap = self::readFile('docs/aila/roadmap.md');

    $this->assertStringContainsString('### Phase 2 Sprint 4 disposition (2026-03-05)', $roadmap);
    $this->assertStringContainsString('Sprint 4: response contract + retrieval-confidence implementation and tests.', $roadmap);
    $this->assertStringContainsString('CLAIM-143', $roadmap);
    $this->assertStringContainsString('no live production LLM enablement in Phase 2', $roadmap);
    $this->assertStringContainsString('no broad platform migration outside current Pantheon baseline', $roadmap);
  }

  /**
   * Current-state must include Sprint 4 closure addendum.
   */
  public function testCurrentStateContainsSprintFourAddendum(): void {
    $currentState = self::readFile('docs/aila/current-state.md');

    $this->assertStringContainsString(
      '### Phase 2 Sprint 4 Response Contract + Retrieval Confidence Retune Disposition (2026-03-05)',
      $currentState
    );
    $this->assertStringContainsString('`P2-SBD-01`', $currentState);
    $this->assertStringContainsString('Response contract fields remain additive', $currentState);
    $this->assertStringContainsString('`<= 0.49`', $currentState);
    $this->assertStringContainsString('`REASON_NO_RESULTS`', $currentState);
    $this->assertStringContainsString('rag_metric_min_count', $currentState);
    $this->assertStringContainsString('[^CLAIM-143]', $currentState);
  }

  /**
   * Runbook must include Sprint 4 verification bundle with required aliases.
   */
  public function testRunbookContainsSprintFourVerificationBundle(): void {
    $runbook = self::readFile('docs/aila/runbook.md');

    $this->assertStringContainsString('### Phase 2 Sprint 4 verification (`P2-SBD-01`)', $runbook);
    $this->assertStringContainsString('# VC-UNIT', $runbook);
    $this->assertStringContainsString('# VC-QUALITY-GATE', $runbook);
    $this->assertStringContainsString('PhaseTwoSprintFourGateTest', $runbook);
    $this->assertStringContainsString('ResponseContractNormalizationTest', $runbook);
    $this->assertStringContainsString('docs/aila/runtime/phase2-sprint4-closure.txt', $runbook);
    $this->assertStringContainsString('no live LLM enablement through Phase 2', $runbook);
    $this->assertStringContainsString('[^CLAIM-143]', $runbook);
  }

  /**
   * Evidence index must include Sprint 4 closure claim section.
   */
  public function testEvidenceIndexContainsSprintFourClosureClaim(): void {
    $evidenceIndex = self::readFile('docs/aila/evidence-index.md');

    $this->assertStringContainsString('### CLAIM-134', $evidenceIndex);
    $this->assertStringContainsString('Addendum (2026-03-05): Sprint 4 closure (`P2-SBD-01`)', $evidenceIndex);
    $this->assertStringContainsString('### CLAIM-135', $evidenceIndex);
    $this->assertStringContainsString('## Phase 2 Sprint 4 Response Contract + Retrieval Confidence Retune Closure (`P2-SBD-01`)', $evidenceIndex);
    $this->assertStringContainsString('### CLAIM-143', $evidenceIndex);
    $this->assertStringContainsString('PhaseTwoSprintFourGateTest.php', $evidenceIndex);
  }

  /**
   * Runtime artifact must record command aliases and scope guardrails.
   */
  public function testRuntimeArtifactContainsSprintFourProofMarkers(): void {
    $artifact = self::readFile('docs/aila/runtime/phase2-sprint4-closure.txt');

    $this->assertStringContainsString('# Phase 2 Sprint 4 Runtime Evidence (P2-SBD-01)', $artifact);
    $this->assertStringContainsString('### VC-UNIT', $artifact);
    $this->assertStringContainsString('### VC-QUALITY-GATE', $artifact);
    $this->assertStringContainsString('exit_code=0', $artifact);
    $this->assertStringContainsString('phase2-sprint4-status=closed', $artifact);
    $this->assertStringContainsString('`llm.enabled=false` remains enforced through Phase 2.', $artifact);
    $this->assertStringContainsString('No broad platform migration outside the current Pantheon baseline.', $artifact);
  }

}
