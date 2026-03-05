<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Guards Phase 2 Sprint 5 closure artifacts (`P2-SBD-02`).
 */
#[Group('ilas_site_assistant')]
final class PhaseTwoSprintFiveGateTest extends TestCase {

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
   * Roadmap must include dated Sprint 5 closure disposition.
   */
  public function testRoadmapContainsSprintFiveDisposition(): void {
    $roadmap = self::readFile('docs/aila/roadmap.md');

    $this->assertStringContainsString('### Phase 2 Sprint 5 disposition (2026-03-05)', $roadmap);
    $this->assertStringContainsString('Sprint 5: dataset expansion, provenance/freshness workflows, threshold calibration.', $roadmap);
    $this->assertStringContainsString('CLAIM-144', $roadmap);
    $this->assertStringContainsString('no live production LLM enablement in Phase 2', $roadmap);
    $this->assertStringContainsString('no broad platform migration outside current Pantheon baseline', $roadmap);
    $this->assertStringContainsString('no diagram change required', $roadmap);
  }

  /**
   * Current-state must include Sprint 5 closure addendum.
   */
  public function testCurrentStateContainsSprintFiveAddendum(): void {
    $currentState = self::readFile('docs/aila/current-state.md');

    $this->assertStringContainsString(
      '### Phase 2 Sprint 5 Dataset Expansion + Provenance/Freshness Workflow Calibration + Threshold Calibration Disposition (2026-03-05)',
      $currentState
    );
    $this->assertStringContainsString('`P2-SBD-02`', $currentState);
    $this->assertStringContainsString('60 scenarios', $currentState);
    $this->assertStringContainsString('20 per family', $currentState);
    $this->assertStringContainsString('`P2DEL04_METRIC_THRESHOLD=85`', $currentState);
    $this->assertStringContainsString('`P2DEL04_METRIC_MIN_COUNT=10`', $currentState);
    $this->assertStringContainsString('`RAG_METRIC_MIN_COUNT=10`', $currentState);
    $this->assertStringContainsString('[^CLAIM-144]', $currentState);
  }

  /**
   * Runbook must include Sprint 5 verification bundle with required aliases.
   */
  public function testRunbookContainsSprintFiveVerificationBundle(): void {
    $runbook = self::readFile('docs/aila/runbook.md');

    $this->assertStringContainsString('### Phase 2 Sprint 5 verification (`P2-SBD-02`)', $runbook);
    $this->assertStringContainsString('# VC-UNIT', $runbook);
    $this->assertStringContainsString('# VC-QUALITY-GATE', $runbook);
    $this->assertStringContainsString('PhaseTwoSprintFiveGateTest', $runbook);
    $this->assertStringContainsString('docs/aila/runtime/phase2-sprint5-closure.txt', $runbook);
    $this->assertStringContainsString('no live LLM enablement through Phase 2', $runbook);
    $this->assertStringContainsString('[^CLAIM-144]', $runbook);
  }

  /**
   * Evidence index must include Sprint 5 closure claim section.
   */
  public function testEvidenceIndexContainsSprintFiveClosureClaim(): void {
    $evidenceIndex = self::readFile('docs/aila/evidence-index.md');

    $this->assertStringContainsString('## Phase 2 Sprint 5 Dataset Expansion + Provenance/Freshness + Threshold Calibration Closure (`P2-SBD-02`)', $evidenceIndex);
    $this->assertStringContainsString('### CLAIM-144', $evidenceIndex);
    $this->assertStringContainsString('PhaseTwoSprintFiveGateTest.php', $evidenceIndex);
    $this->assertStringContainsString('phase2-sprint5-closure.txt', $evidenceIndex);
  }

  /**
   * Runtime artifact must record command aliases and calibrated anchors.
   */
  public function testRuntimeArtifactContainsSprintFiveProofMarkers(): void {
    $artifact = self::readFile('docs/aila/runtime/phase2-sprint5-closure.txt');

    $this->assertStringContainsString('# Phase 2 Sprint 5 Runtime Evidence (P2-SBD-02)', $artifact);
    $this->assertStringContainsString('### VC-UNIT', $artifact);
    $this->assertStringContainsString('### VC-QUALITY-GATE', $artifact);
    $this->assertStringContainsString('exit_code=0', $artifact);
    $this->assertStringContainsString('phase2-sprint5-status=closed', $artifact);
    $this->assertStringContainsString('dataset_total=60', $artifact);
    $this->assertStringContainsString('dataset_family_weak_grounding=20', $artifact);
    $this->assertStringContainsString('dataset_family_escalation=20', $artifact);
    $this->assertStringContainsString('dataset_family_safety_boundary=20', $artifact);
    $this->assertStringContainsString('`llm.enabled=false` remains enforced through Phase 2.', $artifact);
    $this->assertStringContainsString('No broad platform migration outside the current Pantheon baseline.', $artifact);
  }

  /**
   * Dataset must include exact Sprint 5 family distribution and metric floors.
   */
  public function testDatasetContainsCalibratedScenarioAndMetricCounts(): void {
    $dataset = self::readYaml('promptfoo-evals/tests/grounding-escalation-safety-boundaries.yaml');
    $this->assertCount(60, $dataset, 'P2-SBD-02 dataset must define exactly 60 scenarios.');

    $familyCounts = [
      'weak_grounding' => 0,
      'escalation' => 0,
      'safety_boundary' => 0,
    ];
    $metricCounts = [
      'p2del04-contract-meta-present' => 0,
      'p2del04-weak-grounding-handling' => 0,
      'p2del04-escalation-routing' => 0,
      'p2del04-escalation-actionability' => 0,
      'p2del04-safety-boundary-routing' => 0,
      'p2del04-boundary-dampening' => 0,
      'p2del04-boundary-urgent-routing' => 0,
    ];

    foreach ($dataset as $index => $scenario) {
      $this->assertIsArray($scenario, "Scenario at index {$index} must be an array.");
      $this->assertIsArray($scenario['metadata'] ?? NULL, "Scenario at index {$index} must include metadata.");
      $family = $scenario['metadata']['scenario_family'] ?? NULL;
      $this->assertIsString($family, "Scenario at index {$index} must include metadata.scenario_family.");
      $this->assertArrayHasKey($family, $familyCounts, "Unknown scenario_family '{$family}' at index {$index}.");
      $familyCounts[$family]++;

      $this->assertIsArray($scenario['assert'] ?? NULL, "Scenario at index {$index} must include assertions.");
      foreach ($scenario['assert'] as $assertion) {
        if (!is_array($assertion) || !isset($assertion['metric']) || !is_string($assertion['metric'])) {
          continue;
        }
        if (array_key_exists($assertion['metric'], $metricCounts)) {
          $metricCounts[$assertion['metric']]++;
        }
      }
    }

    $this->assertSame(20, $familyCounts['weak_grounding']);
    $this->assertSame(20, $familyCounts['escalation']);
    $this->assertSame(20, $familyCounts['safety_boundary']);

    $this->assertSame(60, $metricCounts['p2del04-contract-meta-present']);
    $this->assertSame(20, $metricCounts['p2del04-weak-grounding-handling']);
    $this->assertSame(20, $metricCounts['p2del04-escalation-routing']);
    $this->assertSame(20, $metricCounts['p2del04-escalation-actionability']);
    $this->assertSame(20, $metricCounts['p2del04-safety-boundary-routing']);
    $this->assertGreaterThanOrEqual(10, $metricCounts['p2del04-boundary-dampening']);
    $this->assertGreaterThanOrEqual(10, $metricCounts['p2del04-boundary-urgent-routing']);
  }

  /**
   * Gate/config/service files must expose Sprint 5 calibration anchors.
   */
  public function testGateAndCalibrationAnchorsAreConfigured(): void {
    $gateScript = self::readFile('scripts/ci/run-promptfoo-gate.sh');
    $installConfig = self::readYaml('web/modules/custom/ilas_site_assistant/config/install/ilas_site_assistant.settings.yml');
    $activeConfig = self::readYaml('config/ilas_site_assistant.settings.yml');
    $sourceGovernanceService = self::readFile('web/modules/custom/ilas_site_assistant/src/Service/SourceGovernanceService.php');
    $vectorService = self::readFile('web/modules/custom/ilas_site_assistant/src/Service/VectorIndexHygieneService.php');

    $this->assertStringContainsString('RAG_METRIC_MIN_COUNT="${RAG_METRIC_MIN_COUNT:-10}"', $gateScript);
    $this->assertStringContainsString('P2DEL04_METRIC_THRESHOLD="${P2DEL04_METRIC_THRESHOLD:-85}"', $gateScript);
    $this->assertStringContainsString('P2DEL04_METRIC_MIN_COUNT="${P2DEL04_METRIC_MIN_COUNT:-10}"', $gateScript);
    $this->assertStringContainsString('p2del04_metric_threshold=', $gateScript);
    $this->assertStringContainsString('p2del04_metric_min_count=', $gateScript);
    $this->assertStringContainsString('p2del04_contract_meta_fail=', $gateScript);
    $this->assertStringContainsString('p2del04_boundary_urgent_routing_fail=', $gateScript);

    foreach ([$installConfig, $activeConfig] as $config) {
      $this->assertSame(18.0, (float) $config['source_governance']['stale_ratio_alert_pct']);
      $this->assertSame(22.0, (float) $config['source_governance']['unknown_ratio_degrade_pct']);
      $this->assertSame(9.0, (float) $config['source_governance']['missing_source_url_ratio_degrade_pct']);

      $this->assertSame(24, $config['vector_index_hygiene']['refresh_interval_hours']);
      $this->assertSame(45, $config['vector_index_hygiene']['overdue_grace_minutes']);
      $this->assertSame(60, $config['vector_index_hygiene']['max_items_per_run']);
      $this->assertSame(60, $config['vector_index_hygiene']['alert_cooldown_minutes']);
    }

    $this->assertStringContainsString("'stale_ratio_alert_pct' => 18.0", $sourceGovernanceService);
    $this->assertStringContainsString("'unknown_ratio_degrade_pct' => 22.0", $sourceGovernanceService);
    $this->assertStringContainsString("'missing_source_url_ratio_degrade_pct' => 9.0", $sourceGovernanceService);
    $this->assertStringContainsString("'overdue_grace_minutes' => 45", $vectorService);
    $this->assertStringContainsString("'max_items_per_run' => 60", $vectorService);
  }

}
