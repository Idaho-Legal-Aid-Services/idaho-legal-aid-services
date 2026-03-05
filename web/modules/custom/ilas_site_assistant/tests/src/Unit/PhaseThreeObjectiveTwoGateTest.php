<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Guards Phase 3 Objective #2 closure artifacts (`P3-OBJ-02`).
 */
#[Group('ilas_site_assistant')]
final class PhaseThreeObjectiveTwoGateTest extends TestCase {

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
   * Roadmap must include dated closure disposition for Phase 3 Objective #2.
   */
  public function testRoadmapContainsPhaseThreeObjectiveTwoDisposition(): void {
    $roadmap = self::readFile('docs/aila/roadmap.md');

    $this->assertStringContainsString('## Phase 3 (Sprint 6): UX polish + performance/cost optimization', $roadmap);
    $this->assertStringContainsString('### Phase 3 Objective #2 disposition (2026-03-05)', $roadmap);
    $this->assertStringContainsString('Objective #2 is closed as implemented: performance and cost guardrails are finalized', $roadmap);
    $this->assertStringContainsString('phase3-obj2-performance-cost-guardrails.txt', $roadmap);
    $this->assertStringContainsString('PhaseThreeObjectiveTwoGateTest.php', $roadmap);
    $this->assertStringContainsString('CLAIM-147', $roadmap);
    $this->assertStringContainsString('No net-new assistant channels or third-party model expansion beyond audited providers.', $roadmap);
    $this->assertStringContainsString('No platform-wide refactor of unrelated Drupal subsystems.', $roadmap);
  }

  /**
   * Current-state must include the dated P3-OBJ-02 operational addendum.
   */
  public function testCurrentStateContainsObjectiveTwoOperationalAddendum(): void {
    $currentState = self::readFile('docs/aila/current-state.md');

    $this->assertStringContainsString(
      '### Phase 3 Objective #2 Performance + Cost Guardrails Operational Disposition (2026-03-05)',
      $currentState
    );
    $this->assertStringContainsString('`P3-OBJ-02`', $currentState);
    $this->assertStringContainsString('`CLAIM-077`', $currentState);
    $this->assertStringContainsString('`CLAIM-084`', $currentState);
    $this->assertStringContainsString('LlmEnhancer', $currentState);
    $this->assertStringContainsString('LlmCircuitBreaker', $currentState);
    $this->assertStringContainsString('LlmRateLimiter', $currentState);
    $this->assertStringContainsString('PerformanceMonitor', $currentState);
    $this->assertStringContainsString('SloAlertService', $currentState);
    $this->assertStringContainsString('phase3-obj2-performance-cost-guardrails.txt', $currentState);
    $this->assertStringContainsString('[^CLAIM-147]', $currentState);
  }

  /**
   * Runbook section 3 must include reproducible P3-OBJ-02 verification steps.
   */
  public function testRunbookContainsObjectiveTwoVerificationBundle(): void {
    $runbook = self::readFile('docs/aila/runbook.md');

    $this->assertStringContainsString(
      '### Phase 3 objective #2 performance + cost guardrails operational verification (`P3-OBJ-02`)',
      $runbook
    );
    $this->assertStringContainsString('# VC-UNIT', $runbook);
    $this->assertStringContainsString('# VC-DRUPAL-UNIT', $runbook);
    $this->assertStringContainsString('LlmEnhancer.php', $runbook);
    $this->assertStringContainsString('LlmCircuitBreaker.php', $runbook);
    $this->assertStringContainsString('LlmRateLimiter.php', $runbook);
    $this->assertStringContainsString('PerformanceMonitor.php', $runbook);
    $this->assertStringContainsString('SloAlertService.php', $runbook);
    $this->assertStringContainsString('PhaseThreeObjectiveTwoGateTest.php', $runbook);
    $this->assertStringContainsString('phase3-obj2-performance-cost-guardrails.txt', $runbook);
    $this->assertStringContainsString('[^CLAIM-147]', $runbook);
  }

  /**
   * Evidence index must include CLAIM-147 and P3 addenda under 077/084.
   */
  public function testEvidenceIndexContainsClaim147AndObjectiveAddenda(): void {
    $evidenceIndex = self::readFile('docs/aila/evidence-index.md');

    $this->assertStringContainsString('### CLAIM-077', $evidenceIndex);
    $this->assertStringContainsString('Addendum (2026-03-05): Phase 3 Objective #2 (`P3-OBJ-02`)', $evidenceIndex);
    $this->assertStringContainsString('### CLAIM-084', $evidenceIndex);
    $this->assertStringContainsString(
      '## Phase 3 Objective #2 Performance + Cost Guardrails Operational Closure (`P3-OBJ-02`)',
      $evidenceIndex
    );
    $this->assertStringContainsString('### CLAIM-147', $evidenceIndex);
    $this->assertStringContainsString('PhaseThreeObjectiveTwoGateTest.php', $evidenceIndex);
    $this->assertStringContainsString('IMP-COST-01', $evidenceIndex);
    $this->assertStringContainsString('R-PERF-01', $evidenceIndex);
  }

  /**
   * Runtime artifact must contain required closure markers.
   */
  public function testRuntimeArtifactContainsObjectiveTwoProofMarkers(): void {
    $artifact = self::readFile('docs/aila/runtime/phase3-obj2-performance-cost-guardrails.txt');

    $this->assertStringContainsString('# Phase 3 Objective #2 Runtime Evidence (P3-OBJ-02)', $artifact);
    $this->assertStringContainsString('### VC-UNIT', $artifact);
    $this->assertStringContainsString('### VC-DRUPAL-UNIT', $artifact);
    $this->assertStringContainsString('p3-obj-02-status=closed', $artifact);
    $this->assertStringContainsString('guard-anchor-llm-enhancer=present', $artifact);
    $this->assertStringContainsString('guard-anchor-llm-circuit-breaker=present', $artifact);
    $this->assertStringContainsString('guard-anchor-llm-rate-limiter=present', $artifact);
    $this->assertStringContainsString('guard-anchor-performance-monitor=present', $artifact);
    $this->assertStringContainsString('guard-anchor-slo-alert-service=present', $artifact);
    $this->assertStringContainsString('guard-anchor-cost-control-policy=present', $artifact);
  }

  /**
   * Backlog and risk linkage must move to active mitigation posture.
   */
  public function testBacklogAndRiskRegisterMoveToActiveMitigation(): void {
    $backlog = self::readFile('docs/aila/backlog.md');
    $riskRegister = self::readFile('docs/aila/risk-register.md');

    $this->assertStringContainsString('Active mitigation (IMP-COST-01 / P3-OBJ-02, 2026-03-05)', $backlog);
    $this->assertStringContainsString('| R-PERF-01 |', $riskRegister);
    $this->assertStringContainsString('| Active mitigation |', $riskRegister);
    $this->assertStringContainsString('phase3-obj2-performance-cost-guardrails.txt', $riskRegister);
  }

  /**
   * Diagram A anchors must remain present for objective context.
   */
  public function testSystemMapRetainsDiagramAAnchors(): void {
    $systemMap = self::readFile('docs/aila/system-map.mmd');

    $this->assertStringContainsString('flowchart LR', $systemMap);
    $this->assertStringContainsString('OBS[Observability', $systemMap);
    $this->assertStringContainsString('CI[External CI runner', $systemMap);
    $this->assertStringContainsString('PF[Promptfoo harness]', $systemMap);
  }

  /**
   * Source guardrail anchors must remain present in service code.
   */
  public function testSourceGuardrailAnchorsRemainPresent(): void {
    $enhancer = self::readFile('web/modules/custom/ilas_site_assistant/src/Service/LlmEnhancer.php');
    $breaker = self::readFile('web/modules/custom/ilas_site_assistant/src/Service/LlmCircuitBreaker.php');
    $limiter = self::readFile('web/modules/custom/ilas_site_assistant/src/Service/LlmRateLimiter.php');
    $monitor = self::readFile('web/modules/custom/ilas_site_assistant/src/Service/PerformanceMonitor.php');
    $sloAlert = self::readFile('web/modules/custom/ilas_site_assistant/src/Service/SloAlertService.php');

    $this->assertStringContainsString('protected function isLiveEnvironment(): bool', $enhancer);
    $this->assertStringContainsString('LLM circuit breaker is open, skipping API call.', $enhancer);
    $this->assertStringContainsString('LLM global rate limit exceeded, skipping API call.', $enhancer);

    $this->assertStringContainsString('class LlmCircuitBreaker', $breaker);
    $this->assertStringContainsString('public function isAvailable(): bool', $breaker);

    $this->assertStringContainsString('class LlmRateLimiter', $limiter);
    $this->assertStringContainsString('public function isAllowed(): bool', $limiter);

    $this->assertStringContainsString('class PerformanceMonitor', $monitor);
    $this->assertStringContainsString('public function recordRequest', $monitor);
    $this->assertStringContainsString('public function getSummary(): array', $monitor);

    $this->assertStringContainsString('class SloAlertService', $sloAlert);
    $this->assertStringContainsString('public function checkAll(): void', $sloAlert);
    $this->assertStringContainsString('SLO violation: P95 latency', $sloAlert);

    $costPolicy = self::readFile('web/modules/custom/ilas_site_assistant/src/Service/CostControlPolicy.php');
    $this->assertStringContainsString('class CostControlPolicy', $costPolicy);
    $this->assertStringContainsString('public function isRequestAllowed(): array', $costPolicy);
    $this->assertStringContainsString('public function evaluateKillSwitch(): array', $costPolicy);
    $this->assertStringContainsString('public function estimateCost(array $tokenUsage): float', $costPolicy);
  }

}
