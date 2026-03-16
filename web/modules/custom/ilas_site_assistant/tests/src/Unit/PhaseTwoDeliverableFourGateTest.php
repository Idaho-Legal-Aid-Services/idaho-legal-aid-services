<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Guards Phase 2 Deliverable #4 closure artifacts (`P2-DEL-04`).
 */
#[Group('ilas_site_assistant')]
final class PhaseTwoDeliverableFourGateTest extends TestCase {

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
   * Parses YAML from a repo-relative path.
   */
  private static function readYaml(string $relativePath): array {
    $path = self::repoRoot() . '/' . ltrim($relativePath, '/');
    self::assertFileExists($path, "Expected YAML file does not exist: {$relativePath}");

    $parsed = Yaml::parseFile($path);
    self::assertIsArray($parsed, "Failed parsing YAML: {$relativePath}");
    return $parsed;
  }

  /**
   * Roadmap must include dated closure disposition for Phase 2 Deliverable #4.
   */
  public function testRoadmapContainsDeliverableFourDisposition(): void {
    $roadmap = self::readFile('docs/aila/roadmap.md');

    $this->assertStringContainsString('### Phase 2 Deliverable #4 disposition (2026-03-04)', $roadmap);
    $this->assertStringContainsString('Promptfoo dataset coverage now explicitly includes weak grounding, escalation, and safety boundary scenarios', $roadmap);
    $this->assertStringContainsString('no direct backlog row', $roadmap);
    $this->assertStringContainsString('R-MNT-02', $roadmap);
    $this->assertStringContainsString('R-LLM-01', $roadmap);
    $this->assertStringContainsString('no live production LLM enablement in Phase 2', $roadmap);
    $this->assertStringContainsString('no broad platform migration outside current Pantheon baseline', $roadmap);
    $this->assertStringContainsString('CLAIM-137', $roadmap);
  }

  /**
   * Current-state must include P2-DEL-04 addendum and harness-row updates.
   */
  public function testCurrentStateContainsDeliverableFourAddendum(): void {
    $currentState = self::readFile('docs/aila/current-state.md');

    $this->assertStringContainsString('### Phase 2 Deliverable #4 Promptfoo Dataset Expansion Disposition (2026-03-04)', $currentState);
    $this->assertStringContainsString('grounding-escalation-safety-boundaries.yaml', $currentState);
    $this->assertStringContainsString('weak_grounding', $currentState);
    $this->assertStringContainsString('escalation', $currentState);
    $this->assertStringContainsString('safety_boundary', $currentState);
    $this->assertStringContainsString('escalation routing/actionability', $currentState);
    $this->assertStringContainsString('safety-boundary', $currentState);
    $this->assertStringContainsString('dampening/refusal transitions', $currentState);
    $this->assertStringContainsString('[^CLAIM-137]', $currentState);
  }

  /**
   * Runbook section 4 must include P2-DEL-04 verification bundle.
   */
  public function testRunbookContainsDeliverableFourVerificationBundle(): void {
    $runbook = self::readFile('docs/aila/runbook.md');

    $this->assertStringContainsString(
      '### Phase 2 promptfoo dataset expansion verification (`P2-DEL-04`)',
      $runbook
    );
    $this->assertStringContainsString('# VC-UNIT', $runbook);
    $this->assertStringContainsString('# VC-KERNEL', $runbook);
    $this->assertStringContainsString('# VC-QUALITY-GATE', $runbook);
    $this->assertStringContainsString('PhaseTwoDeliverableFourGateTest', $runbook);
    $this->assertStringContainsString('grounding-escalation-safety-boundaries.yaml', $runbook);
    $this->assertStringContainsString('p2del04-', $runbook);
    $this->assertStringContainsString('R-MNT-02', $runbook);
    $this->assertStringContainsString('R-LLM-01', $runbook);
    $this->assertStringContainsString('no live LLM enablement through Phase 2', $runbook);
    $this->assertStringContainsString('[^CLAIM-137]', $runbook);
  }

  /**
   * Evidence index must include deliverable closure claim and addenda anchors.
   */
  public function testEvidenceIndexContainsDeliverableFourClaimsAndAddenda(): void {
    $evidenceIndex = self::readFile('docs/aila/evidence-index.md');

    $this->assertStringContainsString('### CLAIM-055', $evidenceIndex);
    $this->assertStringContainsString('### CLAIM-086', $evidenceIndex);
    $this->assertStringContainsString('### CLAIM-105', $evidenceIndex);
    $this->assertStringContainsString('Addendum (2026-03-04): `P2-DEL-04`', $evidenceIndex);
    $this->assertStringContainsString('## Phase 2 Deliverable #4 Promptfoo Dataset Expansion (`P2-DEL-04`)', $evidenceIndex);
    $this->assertStringContainsString('### CLAIM-137', $evidenceIndex);
    $this->assertStringContainsString('grounding-escalation-safety-boundaries.yaml', $evidenceIndex);
    $this->assertStringContainsString('PhaseTwoDeliverableFourGateTest.php', $evidenceIndex);
    $this->assertStringContainsString('R-MNT-02', $evidenceIndex);
    $this->assertStringContainsString('R-LLM-01', $evidenceIndex);
  }

  /**
   * Promptfoo abuse config must include the P2-DEL-04 dataset.
   */
  public function testPromptfooAbuseConfigIncludesDeliverableFourDataset(): void {
    $abuseConfig = self::readFile('promptfoo-evals/promptfooconfig.abuse.yaml');

    $this->assertStringContainsString('abuse-safety.yaml', $abuseConfig);
    $this->assertStringContainsString('retrieval-confidence-thresholds.yaml', $abuseConfig);
    $this->assertStringContainsString('grounding-escalation-safety-boundaries.yaml', $abuseConfig);
  }

  /**
   * Dataset must include minimum family coverage and contract assertions.
   */
  public function testDatasetContainsFamilyCoverageAndScenarioCounts(): void {
    $dataset = self::readYaml('promptfoo-evals/tests/grounding-escalation-safety-boundaries.yaml');

    $this->assertCount(60, $dataset, 'P2-DEL-04 dataset must define exactly 60 scenarios.');

    $familyCounts = [
      'weak_grounding' => 0,
      'escalation' => 0,
      'safety_boundary' => 0,
    ];

    foreach ($dataset as $index => $scenario) {
      $this->assertIsArray($scenario, "Scenario at index {$index} must be an array.");
      $this->assertArrayHasKey('metadata', $scenario, "Scenario at index {$index} must include metadata.");
      $this->assertIsArray($scenario['metadata']);
      $this->assertArrayHasKey('scenario_family', $scenario['metadata'], "Scenario at index {$index} must include metadata.scenario_family.");

      $family = $scenario['metadata']['scenario_family'];
      $this->assertArrayHasKey($family, $familyCounts, "Unknown scenario_family '{$family}' at index {$index}.");
      $familyCounts[$family]++;

      $this->assertArrayHasKey('assert', $scenario, "Scenario at index {$index} must include assertions.");
      $this->assertIsArray($scenario['assert']);

      $assertText = json_encode($scenario['assert']);
      $this->assertIsString($assertText);
      $this->assertStringContainsString('p2del04-contract-meta-present', $assertText, "Scenario at index {$index} must assert contract metadata.");
      $this->assertStringContainsString('[contract_meta]', $assertText, "Scenario at index {$index} must parse [contract_meta].");
    }

    $this->assertSame(20, $familyCounts['weak_grounding']);
    $this->assertSame(20, $familyCounts['escalation']);
    $this->assertSame(20, $familyCounts['safety_boundary']);
  }

  /**
   * Risk register linkage must reference deliverable dataset coverage.
   */
  public function testRiskRegisterContainsDeliverableFourConservativeLinkage(): void {
    $riskRegister = self::readFile('docs/aila/risk-register.md');

    $this->assertStringContainsString('| R-MNT-02 |', $riskRegister);
    $this->assertStringContainsString('PhaseTwoDeliverableFourGateTest', $riskRegister);
    $this->assertStringContainsString('p2del04-*', $riskRegister);
    $this->assertStringContainsString('| Active mitigation |', $riskRegister);

    $this->assertStringContainsString('| R-LLM-01 |', $riskRegister);
    $this->assertStringContainsString('`P2-DEL-04` extends promptfoo safety-boundary coverage', $riskRegister);
    $this->assertStringContainsString('p2del04-safety-boundary-routing', $riskRegister);
    $this->assertStringContainsString('| Proposed |', $riskRegister);
  }

  /**
   * Scope-boundary language must remain continuous across closure docs.
   */
  public function testScopeBoundaryTextContinuity(): void {
    $roadmap = self::readFile('docs/aila/roadmap.md');
    $currentState = self::readFile('docs/aila/current-state.md');
    $runbook = self::readFile('docs/aila/runbook.md');

    $this->assertStringContainsString('no live production LLM enablement in Phase 2', $roadmap);
    $this->assertStringContainsString('no broad platform migration outside current Pantheon baseline', $roadmap);

    $this->assertStringContainsString('no live production LLM', $currentState);
    $this->assertStringContainsString('enablement through Phase 2', $currentState);
    $this->assertStringContainsString('no broad platform migration outside', $currentState);
    $this->assertStringContainsString('current Pantheon baseline', $currentState);

    $this->assertStringContainsString('no live LLM enablement through Phase 2', $runbook);
    $this->assertStringContainsString('no broad platform migration outside the current Pantheon baseline', $runbook);
  }

}
