<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Guards Phase 2 Deliverable #2 closure artifacts (`P2-DEL-02`).
 */
#[Group('ilas_site_assistant')]
final class PhaseTwoDeliverableTwoGateTest extends TestCase {

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
   * Roadmap must include dated disposition for Phase 2 Deliverable #2.
   */
  public function testRoadmapContainsDeliverableTwoDisposition(): void {
    $roadmap = self::readFile('docs/aila/roadmap.md');

    $this->assertStringContainsString('### Phase 2 Deliverable #2 disposition (2026-03-03)', $roadmap);
    $this->assertStringContainsString('`IMP-RAG-01`', $roadmap);
    $this->assertStringContainsString('rag-contract-meta-present', $roadmap);
    $this->assertStringContainsString('rag-citation-coverage', $roadmap);
    $this->assertStringContainsString('rag-low-confidence-refusal', $roadmap);
    $this->assertStringContainsString('no live production LLM enablement in Phase 2', $roadmap);
    $this->assertStringContainsString('no broad platform migration outside current Pantheon baseline', $roadmap);
    $this->assertStringContainsString('CLAIM-135', $roadmap);
  }

  /**
   * Current-state must include P2-DEL-02 retrieval threshold addendum.
   */
  public function testCurrentStateContainsDeliverableTwoAddendum(): void {
    $currentState = self::readFile('docs/aila/current-state.md');

    $this->assertStringContainsString('### Phase 2 Deliverable #2 Retrieval Confidence/Refusal Threshold Gating Disposition (2026-03-03)', $currentState);
    $this->assertStringContainsString('`rag-contract-meta-present`', $currentState);
    $this->assertStringContainsString('`rag-citation-coverage`', $currentState);
    $this->assertStringContainsString('`rag-low-confidence-refusal`', $currentState);
    $this->assertStringContainsString('[^CLAIM-135]', $currentState);
  }

  /**
   * Runbook section 4 must include P2-DEL-02 verification bundle.
   */
  public function testRunbookContainsDeliverableTwoVerificationBundle(): void {
    $runbook = self::readFile('docs/aila/runbook.md');

    $this->assertStringContainsString(
      '### Phase 2 retrieval confidence/refusal threshold gating verification (`P2-DEL-02`)',
      $runbook
    );
    $this->assertStringContainsString('# VC-UNIT', $runbook);
    $this->assertStringContainsString('# VC-KERNEL', $runbook);
    $this->assertStringContainsString('# VC-QUALITY-GATE', $runbook);
    $this->assertStringContainsString('PhaseTwoDeliverableTwoGateTest', $runbook);
    $this->assertStringContainsString('rag-contract-meta-present', $runbook);
    $this->assertStringContainsString('rag-citation-coverage', $runbook);
    $this->assertStringContainsString('rag-low-confidence-refusal', $runbook);
    $this->assertStringContainsString('no live LLM enablement through Phase 2', $runbook);
  }

  /**
   * Evidence index must include CLAIM-135 for P2-DEL-02 closure.
   */
  public function testEvidenceIndexContainsDeliverableTwoClaim(): void {
    $evidenceIndex = self::readFile('docs/aila/evidence-index.md');

    $this->assertStringContainsString('## Phase 2 Deliverable #2 Retrieval Confidence/Refusal Threshold Gating (`P2-DEL-02`)', $evidenceIndex);
    $this->assertStringContainsString('### CLAIM-135', $evidenceIndex);
    $this->assertStringContainsString('promptfoo-evals/tests/retrieval-confidence-thresholds.yaml', $evidenceIndex);
    $this->assertStringContainsString('scripts/ci/run-promptfoo-gate.sh', $evidenceIndex);
    $this->assertStringContainsString('PhaseTwoDeliverableTwoGateTest.php', $evidenceIndex);
  }

  /**
   * Promptfoo config and provider must expose retrieval threshold signals.
   */
  public function testPromptfooConfigAndProviderContainThresholdSignals(): void {
    $abuseConfig = self::readFile('promptfoo-evals/promptfooconfig.abuse.yaml');
    $thresholdSuite = self::readFile('promptfoo-evals/tests/retrieval-confidence-thresholds.yaml');
    $provider = self::readFile('promptfoo-evals/providers/ilas-live.js');

    $this->assertStringContainsString('retrieval-confidence-thresholds.yaml', $abuseConfig);
    $this->assertStringContainsString('rag-contract-meta-present', $thresholdSuite);
    $this->assertStringContainsString('rag-citation-coverage', $thresholdSuite);
    $this->assertStringContainsString('rag-low-confidence-refusal', $thresholdSuite);

    $this->assertStringContainsString('[contract_meta]', $provider);
    $this->assertStringContainsString('citations_count', $provider);
    $this->assertStringContainsString('response_type', $provider);
    $this->assertStringContainsString('response_mode', $provider);
    $this->assertStringContainsString('reason_code', $provider);
    $this->assertStringContainsString('decision_reason', $provider);
  }

  /**
   * Promptfoo gate script must enforce metric-specific retrieval thresholds.
   */
  public function testPromptfooGateScriptEnforcesRagThresholdMetrics(): void {
    $gateScript = self::readFile('scripts/ci/run-promptfoo-gate.sh');

    $this->assertStringContainsString('RAG_METRIC_THRESHOLD', $gateScript);
    $this->assertStringContainsString('rag-contract-meta-present', $gateScript);
    $this->assertStringContainsString('rag-citation-coverage', $gateScript);
    $this->assertStringContainsString('rag-low-confidence-refusal', $gateScript);
    $this->assertStringContainsString('RAG_METRIC_MIN_COUNT="${RAG_METRIC_MIN_COUNT:-10}"', $gateScript);
    $this->assertStringContainsString('RAG_METRIC_MIN_COUNT', $gateScript);
    $this->assertStringContainsString('rag_metric_min_count=', $gateScript);
    $this->assertStringContainsString('rag_contract_meta_count_fail=', $gateScript);
    $this->assertStringContainsString('rag_citation_coverage_count_fail=', $gateScript);
    $this->assertStringContainsString('rag_low_confidence_refusal_count_fail=', $gateScript);
    $this->assertStringContainsString('rag_metrics_enforced=', $gateScript);
    $this->assertStringContainsString('RAG threshold summary:', $gateScript);
    $this->assertStringContainsString('RAG count-floor summary:', $gateScript);
    $this->assertStringContainsString('P2DEL04_METRIC_THRESHOLD="${P2DEL04_METRIC_THRESHOLD:-85}"', $gateScript);
    $this->assertStringContainsString('P2DEL04_METRIC_MIN_COUNT="${P2DEL04_METRIC_MIN_COUNT:-10}"', $gateScript);
    $this->assertStringContainsString('p2del04_metrics_enforced=', $gateScript);
    $this->assertStringContainsString('p2del04_metric_threshold=', $gateScript);
    $this->assertStringContainsString('p2del04_metric_min_count=', $gateScript);
    $this->assertStringContainsString('p2del04_contract_meta_fail=', $gateScript);
    $this->assertStringContainsString('p2del04_weak_grounding_handling_fail=', $gateScript);
    $this->assertStringContainsString('p2del04_escalation_routing_fail=', $gateScript);
    $this->assertStringContainsString('p2del04_escalation_actionability_fail=', $gateScript);
    $this->assertStringContainsString('p2del04_safety_boundary_routing_fail=', $gateScript);
    $this->assertStringContainsString('p2del04_boundary_dampening_fail=', $gateScript);
    $this->assertStringContainsString('p2del04_boundary_urgent_routing_fail=', $gateScript);
    $this->assertStringContainsString('P2DEL04 threshold summary:', $gateScript);
    $this->assertStringContainsString('P2DEL04 count-floor summary:', $gateScript);
    $this->assertStringContainsString('P2DEL04_THRESHOLD_FAIL', $gateScript);
    $this->assertStringContainsString('RAG_THRESHOLD_FAIL', $gateScript);
  }

  /**
   * Backlog and risk linkage must move to active mitigation posture.
   */
  public function testBacklogAndRiskRegisterMoveToActiveMitigation(): void {
    $backlog = self::readFile('docs/aila/backlog.md');
    $riskRegister = self::readFile('docs/aila/risk-register.md');

    $this->assertStringContainsString('Active mitigation (IMP-RAG-01 / P2-DEL-01 / P2-DEL-02 / P2-SBD-01, 2026-03-05)', $backlog);

    $this->assertStringContainsString('| R-RAG-01 |', $riskRegister);
    $this->assertStringContainsString('rag-contract-meta-present', $riskRegister);
    $this->assertStringContainsString('rag-citation-coverage', $riskRegister);
    $this->assertStringContainsString('rag-low-confidence-refusal', $riskRegister);
    $this->assertStringContainsString('| Active mitigation |', $riskRegister);
  }

}
