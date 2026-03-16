<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Guards Phase 2 Objective #2 closure artifacts (`P2-OBJ-02`).
 */
#[Group('ilas_site_assistant')]
final class PhaseTwoObjectiveTwoGateTest extends TestCase {

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
   * Roadmap must include dated closure disposition for Phase 2 Objective #2.
   */
  public function testRoadmapContainsPhaseTwoObjectiveTwoDisposition(): void {
    $roadmap = self::readFile('docs/aila/roadmap.md');

    $this->assertStringContainsString('## Phase 2 (Sprints 4-5): Retrieval quality + eval harness maturity', $roadmap);
    $this->assertStringContainsString(
      '2. Mature evaluation coverage and release confidence for RAG/response correctness.',
      $roadmap
    );
    $this->assertStringContainsString('### Phase 2 Objective #2 disposition (2026-03-03)', $roadmap);
    $this->assertStringContainsString('branch-aware Promptfoo gate behavior', $roadmap);
    $this->assertStringContainsString('`VC-UNIT` and `VC-DRUPAL-UNIT`', $roadmap);
    $this->assertStringContainsString('no live production LLM enablement in Phase 2', $roadmap);
    $this->assertStringContainsString('No broad platform migration outside current Pantheon baseline', $roadmap);
    $this->assertStringContainsString('CLAIM-132', $roadmap);
  }

  /**
   * Current-state must retain the harness row expansion and P2 addendum.
   */
  public function testCurrentStateContainsObjectiveTwoMaturityAddendum(): void {
    $currentState = self::readFile('docs/aila/current-state.md');

    $this->assertStringContainsString('Promptfoo + quality gate harness', $currentState);
    $this->assertStringContainsString('branch-aware and reproducible', $currentState);
    $this->assertStringContainsString('retain deep multi-turn Promptfoo coverage', $currentState);
    $this->assertStringContainsString('Deterministic correctness confidence remains enforced through `VC-UNIT` and', $currentState);
    $this->assertStringContainsString('`VC-DRUPAL-UNIT` suites', $currentState);
    $this->assertStringContainsString(
      '### Phase 2 Objective #2 Evaluation Coverage + Release Confidence Disposition (2026-03-03)',
      $currentState
    );
    $this->assertStringContainsString('[^CLAIM-132]', $currentState);
  }

  /**
   * Runbook section 4 must provide reproducible P2-OBJ-02 verification steps.
   */
  public function testRunbookContainsObjectiveTwoVerificationBundle(): void {
    $runbook = self::readFile('docs/aila/runbook.md');

    $this->assertStringContainsString(
      '### Phase 2 evaluation coverage + release confidence verification (`P2-OBJ-02`)',
      $runbook
    );
    $this->assertStringContainsString('# VC-UNIT', $runbook);
    $this->assertStringContainsString('# VC-DRUPAL-UNIT', $runbook);
    $this->assertStringContainsString('run-promptfoo-gate.sh', $runbook);
    $this->assertStringContainsString('promptfooconfig.abuse.yaml|promptfooconfig.deep.yaml', $runbook);
    $this->assertStringContainsString('theme-coherence|includes-caveat', $runbook);
    $this->assertStringContainsString('no live LLM enablement through Phase 2', $runbook);
  }

  /**
   * Evidence index must include CLAIM-132 and P2 addenda under 086/105.
   */
  public function testEvidenceIndexContainsObjectiveTwoClaimsAndAddenda(): void {
    $evidenceIndex = self::readFile('docs/aila/evidence-index.md');

    $this->assertStringContainsString('### CLAIM-086', $evidenceIndex);
    $this->assertStringContainsString('### CLAIM-105', $evidenceIndex);
    $this->assertStringContainsString('Phase 2 Objective #2 (`P2-OBJ-02`)', $evidenceIndex);
    $this->assertStringContainsString('### CLAIM-132', $evidenceIndex);
    $this->assertStringContainsString('## Phase 2 Objective #2 Eval Coverage + Release Confidence (`P2-OBJ-02`)', $evidenceIndex);
    $this->assertStringContainsString('PhaseTwoObjectiveTwoGateTest.php', $evidenceIndex);
  }

  /**
   * Diagram A promptfoo/CI anchors must remain present for objective context.
   */
  public function testSystemMapRetainsDiagramAPromptfooCiAnchors(): void {
    $systemMap = self::readFile('docs/aila/system-map.mmd');

    $this->assertCurrentDiagramAQualityGateAnchors($systemMap, requireSyntheticEvalEdge: TRUE);
  }

  /**
   * Gate scripts and Promptfoo suites must retain deep + abuse policy markers.
   */
  public function testPromptfooPolicyAndAssertionMarkersRemainPresent(): void {
    $promptfooGate = self::readFile('scripts/ci/run-promptfoo-gate.sh');
    $deepConfig = self::readFile('promptfoo-evals/promptfooconfig.deep.yaml');
    $abuseConfig = self::readFile('promptfoo-evals/promptfooconfig.abuse.yaml');
    $deepTests = self::readFile('promptfoo-evals/tests/conversations-deep.yaml');
    $abuseTests = self::readFile('promptfoo-evals/tests/abuse-safety.yaml');
    $qualityGate = self::readFile('web/modules/custom/ilas_site_assistant/tests/run-quality-gate.sh');

    $this->assertStringContainsString('EFFECTIVE_MODE="blocking"', $promptfooGate);
    $this->assertStringContainsString('EFFECTIVE_MODE="advisory"', $promptfooGate);
    $this->assertStringContainsString('promptfooconfig.abuse.yaml', $promptfooGate);
    $this->assertStringContainsString('promptfooconfig.deep.yaml', $promptfooGate);

    $this->assertStringContainsString('description: "ILAS Site Assistant — Deep thematic conversation evals"', $deepConfig);
    $this->assertStringContainsString('description: "ILAS Site Assistant — Abuse & Safety Evals"', $abuseConfig);
    $this->assertStringContainsString('theme-coherence', $deepTests);
    $this->assertStringContainsString('includes-caveat', $deepTests);
    $this->assertStringContainsString('no-injection-compliance', $abuseTests);
    $this->assertStringContainsString('includes-caveat-or-escalation', $abuseTests);

    $this->assertStringContainsString('--group ilas_site_assistant', $qualityGate);
    $this->assertStringContainsString('--testsuite drupal-unit', $qualityGate);
  }

}
