<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Guards cross-phase dependency row #6 closure artifacts (`XDP-06`).
 */
#[Group('ilas_site_assistant_docs')]
final class CrossPhaseDependencyRowSixGateTest extends TestCase {

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
   * Roadmap must retain row #6 and a dated XDP-06 disposition.
   */
  public function testRoadmapContainsRowSixAndDisposition(): void {
    $roadmap = self::readFile('docs/aila/roadmap.md');

    $this->assertStringContainsString(
      '| Cost guardrails (`IMP-COST-01`) | Observability and usage telemetry from Phase 1/2 | Phase 3 | Product + Platform |',
      $roadmap
    );
    $this->assertStringContainsString(
      '### Cross-phase dependency row #6 disposition (2026-03-07)',
      $roadmap
    );
    $this->assertStringContainsString(
      'downstream Phase 3 cost-guardrail',
      $roadmap
    );
    $this->assertStringContainsString(
      'work is blocked whenever unresolved dependency count is non-zero',
      $roadmap
    );
    $this->assertStringContainsString('per-IP budget enforcement and cache-effectiveness proof', $roadmap);
    $this->assertStringContainsString(
      'phase3-xdp06-cost-guardrails-dependency-gate.txt',
      $roadmap
    );
  }

  /**
   * Current-state must include XDP-06 addendum and unresolved dependency logic.
   */
  public function testCurrentStateContainsXdp06AddendumAndStatusRules(): void {
    $currentState = self::readFile('docs/aila/current-state.md');

    $this->assertStringContainsString(
      '### Cross-Phase Dependency Row #6 Cost Guardrails Guardrail Disposition (2026-03-07)',
      $currentState
    );
    $this->assertStringContainsString('`XDP-06`', $currentState);
    $this->assertStringContainsString('any unresolved prerequisite reports `xdp-06-status=blocked`', $currentState);
    $this->assertStringContainsString('pass reports `xdp-06-status=closed`', $currentState);
    $this->assertStringContainsString('xdp-06-unresolved-dependency-count', $currentState);
    $this->assertStringContainsString('dependency.per-ip-budget', $currentState);
    $this->assertStringContainsString('dependency.cache-effectiveness', $currentState);
    $this->assertStringContainsString('phase3-xdp06-cost-guardrails-dependency-gate.txt', $currentState);
    $this->assertStringContainsString('[^CLAIM-165]', $currentState);
  }

  /**
   * Runbook must include verification bundle for XDP-06.
   */
  public function testRunbookContainsXdp06VerificationBundle(): void {
    $runbook = self::readFile('docs/aila/runbook.md');

    $this->assertStringContainsString(
      '### Cross-phase dependency row #6 cost guardrails verification (`XDP-06`)',
      $runbook
    );
    $this->assertStringContainsString('# VC-PURE', $runbook);
    $this->assertStringContainsString('# VC-UNIT', $runbook);
    $this->assertStringContainsString('# VC-PANTHEON-READONLY', $runbook);
    $this->assertStringContainsString('any missing prerequisite => `xdp-06-status=blocked`', $runbook);
    $this->assertStringContainsString('all prerequisites present => `xdp-06-status=closed`', $runbook);
    $this->assertStringContainsString('xdp-06-unresolved-dependency-count=0', $runbook);
    $this->assertStringContainsString('dependency.per-ip-budget=pass', $runbook);
    $this->assertStringContainsString('dependency.cache-effectiveness=pass', $runbook);
    $this->assertStringContainsString('phase3-xdp06-cost-guardrails-dependency-gate.txt', $runbook);
    $this->assertStringContainsString('[^CLAIM-165]', $runbook);
  }

  /**
   * Evidence index must include CLAIM-077 addendum plus CLAIM-165 section.
   */
  public function testEvidenceIndexContainsXdp06ClaimAndAddendum(): void {
    $evidenceIndex = self::readFile('docs/aila/evidence-index.md');

    $this->assertStringContainsString('### CLAIM-077', $evidenceIndex);
    $this->assertStringContainsString('Addendum (2026-03-07): Cross-phase dependency row #6 (`XDP-06`)', $evidenceIndex);
    $this->assertStringContainsString(
      '## Cross-Phase Dependency Row #6 Cost Guardrails Guardrail (`XDP-06`)',
      $evidenceIndex
    );
    $this->assertStringContainsString('### CLAIM-165', $evidenceIndex);
    $this->assertStringContainsString('CrossPhaseDependencyRowSixGateTest.php', $evidenceIndex);
  }

  /**
   * Runtime proof must contain deterministic markers and no unresolved items.
   */
  public function testRuntimeArtifactContainsClosedStatusAndMarkers(): void {
    $artifact = self::readFile('docs/aila/runtime/phase3-xdp06-cost-guardrails-dependency-gate.txt');

    $this->assertStringContainsString('xdp-06-status=closed', $artifact);
    $this->assertStringContainsString('xdp-06-workstream=IMP-COST-01', $artifact);
    $this->assertStringContainsString('xdp-06-owner-role=Product + Platform', $artifact);
    $this->assertStringContainsString('xdp-06-consumed-in=Phase 3', $artifact);
    $this->assertStringContainsString('dependency.cost-control-config=pass', $artifact);
    $this->assertStringContainsString('dependency.cost-policy-fail-closed=pass', $artifact);
    $this->assertStringContainsString('dependency.per-ip-budget=pass', $artifact);
    $this->assertStringContainsString('dependency.cache-effectiveness=pass', $artifact);
    $this->assertStringContainsString('dependency.metrics-cost-control=pass', $artifact);
    $this->assertStringContainsString('dependency.slo-monitoring=pass', $artifact);
    $this->assertStringContainsString('xdp-06-unresolved-dependencies=none', $artifact);

    $matches = [];
    $didMatch = preg_match('/xdp-06-unresolved-dependency-count=(\d+)/', $artifact, $matches);
    $this->assertSame(1, $didMatch, 'Runtime artifact missing unresolved dependency count marker.');
    $this->assertSame('0', $matches[1], 'XDP-06 must remain blocked unless unresolved dependency count is zero.');
  }

  /**
   * Prerequisite anchors must stay present in runtime/docs/tests artifacts.
   */
  public function testPrerequisiteAnchorsRemainPresent(): void {
    $phaseOneObservabilityRuntime = self::readFile('docs/aila/runtime/phase1-observability-gates.txt');
    $phaseOneExitOneRuntime = self::readFile('docs/aila/runtime/phase1-exit1-alerts-dashboards.txt');
    $phaseTwoEntryOneRuntime = self::readFile('docs/aila/runtime/phase2-entry1-observability-ci-baseline.txt');

    $phaseThreeObjectiveTwoRuntime = self::readFile('docs/aila/runtime/phase3-obj2-performance-cost-guardrails.txt');
    $phaseThreeExitTwoRuntime = self::readFile('docs/aila/runtime/phase3-exit2-cost-performance-owner-acceptance.txt');

    $phaseThreeObjectiveTwoGate = self::readFile('web/modules/custom/ilas_site_assistant/tests/src/Unit/PhaseThreeObjectiveTwoGateTest.php');
    $phaseThreeExitTwoGate = self::readFile('web/modules/custom/ilas_site_assistant/tests/src/Unit/PhaseThreeExitCriteriaTwoGateTest.php');
    $systemMap = self::readFile('docs/aila/system-map.mmd');

    $this->assertStringContainsString('langfuse_public_key=present', $phaseOneObservabilityRuntime);
    $this->assertStringContainsString('langfuse_secret_key=present', $phaseOneObservabilityRuntime);
    $this->assertStringContainsString('raven_client_key=present', $phaseOneObservabilityRuntime);

    $this->assertStringContainsString('health_keys=status,timestamp,checks', $phaseOneExitOneRuntime);
    $this->assertStringContainsString('metrics_keys=timestamp,metrics,thresholds,cron,queue', $phaseOneExitOneRuntime);
    $this->assertStringContainsString('slo_alert_check=invoked', $phaseOneExitOneRuntime);
    $this->assertStringContainsString('SLO violation: cron health is @status', $phaseOneExitOneRuntime);

    $this->assertStringContainsString('`VC-RUNBOOK-LOCAL`', $phaseTwoEntryOneRuntime);
    $this->assertStringContainsString('`VC-TOGGLE-CHECK`', $phaseTwoEntryOneRuntime);
    $this->assertStringContainsString('system.cron_last=', $phaseTwoEntryOneRuntime);
    $this->assertStringContainsString('name: Quality Gate', $phaseTwoEntryOneRuntime);

    $this->assertStringContainsString('p3-obj-02-status=closed', $phaseThreeObjectiveTwoRuntime);
    $this->assertStringContainsString('guard-anchor-cost-control-policy=present', $phaseThreeObjectiveTwoRuntime);
    $this->assertStringContainsString('cost-proof-status=pass', $phaseThreeObjectiveTwoRuntime);
    $this->assertStringContainsString('cost-proof-per-ip-status=pass', $phaseThreeObjectiveTwoRuntime);

    $this->assertStringContainsString('p3-ext-02-status=closed', $phaseThreeExitTwoRuntime);
    $this->assertStringContainsString('owner-acceptance-product-role=accepted', $phaseThreeExitTwoRuntime);
    $this->assertStringContainsString('owner-acceptance-platform-role=accepted', $phaseThreeExitTwoRuntime);
    $this->assertStringContainsString('metrics-cost-control=present', $phaseThreeExitTwoRuntime);
    $this->assertStringContainsString('vc-pantheon-readonly-status=', $phaseThreeExitTwoRuntime);

    $this->assertStringContainsString('testRuntimeArtifactContainsObjectiveTwoProofMarkers', $phaseThreeObjectiveTwoGate);
    $this->assertStringContainsString('testRuntimeArtifactContainsPhaseThreeExitTwoProofMarkers', $phaseThreeExitTwoGate);

    $this->assertStringContainsString('OBS[Observability', $systemMap);
    $this->assertStringContainsString('CI[External CI runner', $systemMap);
  }

}
