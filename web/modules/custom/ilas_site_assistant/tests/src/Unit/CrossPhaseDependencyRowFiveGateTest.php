<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Guards cross-phase dependency row #5 closure artifacts (`XDP-05`).
 */
#[Group('ilas_site_assistant')]
final class CrossPhaseDependencyRowFiveGateTest extends TestCase {

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
   * Roadmap must retain row #5 and a dated XDP-05 disposition.
   */
  public function testRoadmapContainsRowFiveAndDisposition(): void {
    $roadmap = self::readFile('docs/aila/roadmap.md');

    $this->assertStringContainsString(
      '| Retrieval confidence contract (`IMP-RAG-01`) | Config parity + observability signals + eval harness | Phase 2 -> prerequisite for Phase 3 readiness signoff | AI/RAG Engineer |',
      $roadmap
    );
    $this->assertStringContainsString(
      '### Cross-phase dependency row #5 disposition (2026-03-06)',
      $roadmap
    );
    $this->assertStringContainsString(
      'downstream Phase 3 readiness',
      $roadmap
    );
    $this->assertStringContainsString(
      'signoff work is blocked whenever unresolved dependency count is non-zero',
      $roadmap
    );
    $this->assertStringContainsString(
      'phase2-xdp05-retrieval-confidence-contract-dependency-gate.txt',
      $roadmap
    );
  }

  /**
   * Current-state must include XDP-05 addendum and unresolved dependency logic.
   */
  public function testCurrentStateContainsXdp05AddendumAndStatusRules(): void {
    $currentState = self::readFile('docs/aila/current-state.md');

    $this->assertStringContainsString(
      '### Cross-Phase Dependency Row #5 Retrieval Confidence Contract Guardrail Disposition (2026-03-06)',
      $currentState
    );
    $this->assertStringContainsString('`XDP-05`', $currentState);
    $this->assertStringContainsString('any unresolved prerequisite', $currentState);
    $this->assertStringContainsString('`xdp-05-status=blocked`', $currentState);
    $this->assertStringContainsString('all prerequisites pass reports', $currentState);
    $this->assertStringContainsString('`xdp-05-status=closed`', $currentState);
    $this->assertStringContainsString('xdp-05-unresolved-dependency-count', $currentState);
    $this->assertStringContainsString('phase2-xdp05-retrieval-confidence-contract-dependency-gate.txt', $currentState);
    $this->assertStringContainsString('[^CLAIM-164]', $currentState);
  }

  /**
   * Runbook must include verification bundle for XDP-05.
   */
  public function testRunbookContainsXdp05VerificationBundle(): void {
    $runbook = self::readFile('docs/aila/runbook.md');

    $this->assertStringContainsString(
      '### Cross-phase dependency row #5 retrieval confidence contract verification (`XDP-05`)',
      $runbook
    );
    $this->assertStringContainsString('# VC-UNIT', $runbook);
    $this->assertStringContainsString('# VC-RUNBOOK-PANTHEON', $runbook);
    $this->assertStringContainsString('any missing prerequisite => `xdp-05-status=blocked`', $runbook);
    $this->assertStringContainsString('all prerequisites present => `xdp-05-status=closed`', $runbook);
    $this->assertStringContainsString('xdp-05-unresolved-dependency-count=0', $runbook);
    $this->assertStringContainsString('phase2-xdp05-retrieval-confidence-contract-dependency-gate.txt', $runbook);
    $this->assertStringContainsString('[^CLAIM-164]', $runbook);
  }

  /**
   * Evidence index must include CLAIM-135 addendum plus CLAIM-164 section.
   */
  public function testEvidenceIndexContainsXdp05ClaimAndAddendum(): void {
    $evidenceIndex = self::readFile('docs/aila/evidence-index.md');

    $this->assertStringContainsString('### CLAIM-135', $evidenceIndex);
    $this->assertStringContainsString('Addendum (2026-03-06): Cross-phase dependency row #5 (`XDP-05`)', $evidenceIndex);
    $this->assertStringContainsString(
      '## Cross-Phase Dependency Row #5 Retrieval Confidence Contract Guardrail (`XDP-05`)',
      $evidenceIndex
    );
    $this->assertStringContainsString('### CLAIM-164', $evidenceIndex);
    $this->assertStringContainsString('CrossPhaseDependencyRowFiveGateTest.php', $evidenceIndex);
  }

  /**
   * Runtime proof must contain deterministic markers and no unresolved items.
   */
  public function testRuntimeArtifactContainsClosedStatusAndMarkers(): void {
    $artifact = self::readFile('docs/aila/runtime/phase2-xdp05-retrieval-confidence-contract-dependency-gate.txt');

    $this->assertStringContainsString('xdp-05-status=closed', $artifact);
    $this->assertStringContainsString('xdp-05-workstream=IMP-RAG-01', $artifact);
    $this->assertStringContainsString('xdp-05-owner-role=AI/RAG Engineer', $artifact);
    $this->assertStringContainsString('xdp-05-consumed-in=Phase 2 -> prerequisite for Phase 3 readiness signoff', $artifact);
    $this->assertStringContainsString('dependency.config-parity=pass', $artifact);
    $this->assertStringContainsString('dependency.observability-signals=pass', $artifact);
    $this->assertStringContainsString('dependency.eval-harness=pass', $artifact);
    $this->assertStringContainsString('xdp-05-unresolved-dependencies=none', $artifact);

    $matches = [];
    $didMatch = preg_match('/xdp-05-unresolved-dependency-count=(\d+)/', $artifact, $matches);
    $this->assertSame(1, $didMatch, 'Runtime artifact missing unresolved dependency count marker.');
    $this->assertSame('0', $matches[1], 'XDP-05 must remain blocked unless unresolved dependency count is zero.');
  }

  /**
   * Prerequisite anchors must stay present in source/tests/docs.
   */
  public function testPrerequisiteAnchorsRemainPresent(): void {
    $schema = self::readFile('web/modules/custom/ilas_site_assistant/config/schema/ilas_site_assistant.schema.yml');
    $vectorSearchSchemaTest = self::readFile('web/modules/custom/ilas_site_assistant/tests/src/Unit/VectorSearchConfigSchemaTest.php');
    $configDriftTest = self::readFile('web/modules/custom/ilas_site_assistant/tests/src/Unit/ConfigCompletenessDriftTest.php');
    $phaseTwoEntryTwoRuntime = self::readFile('docs/aila/runtime/phase2-entry2-config-parity-retrieval-tuning.txt');

    $telemetryCredentialGate = self::readFile('web/modules/custom/ilas_site_assistant/tests/src/Unit/TelemetryCredentialGateTest.php');
    $redactionContract = self::readFile('web/modules/custom/ilas_site_assistant/tests/src/Unit/ObservabilityRedactionContractTest.php');
    $phaseOneRuntime = self::readFile('docs/aila/runtime/phase1-observability-gates.txt');

    $abuseConfig = self::readFile('promptfoo-evals/promptfooconfig.abuse.yaml');
    $thresholdSuite = self::readFile('promptfoo-evals/tests/retrieval-confidence-thresholds.yaml');
    $provider = self::readFile('promptfoo-evals/providers/ilas-live.js');
    $promptfooGateScript = self::readFile('scripts/ci/run-promptfoo-gate.sh');
    $phaseTwoExitOneRuntime = self::readFile('docs/aila/runtime/phase2-exit1-retrieval-contract-confidence-thresholds.txt');

    $phaseThreeEntryOneGate = self::readFile('web/modules/custom/ilas_site_assistant/tests/src/Unit/PhaseThreeEntryCriteriaOneGateTest.php');
    $phaseThreeEntryOneRuntime = self::readFile('docs/aila/runtime/phase3-entry1-retrieval-quality-targets.txt');
    $systemMap = self::readFile('docs/aila/system-map.mmd');

    $this->assertStringContainsString('vector_search:', $schema);
    $this->assertStringContainsString('fallback_gate:', $schema);
    $this->assertStringContainsString('testSchemaCoversAllInstallDefaultKeys', $vectorSearchSchemaTest);
    $this->assertStringContainsString('testActiveConfigContainsAllInstallTopLevelKeys', $configDriftTest);
    $this->assertStringContainsString('Config Parity + Retrieval Tuning Stability Verification', $phaseTwoEntryTwoRuntime);

    $this->assertStringContainsString('testRuntimeGatesArtifactShowsCredentialsPresentOnAllEnvironments', $telemetryCredentialGate);
    $this->assertStringContainsString('testSentryBeforeSendRedactsAllNinePiiTypes', $redactionContract);
    $this->assertStringContainsString('langfuse_public_key=present', $phaseOneRuntime);
    $this->assertStringContainsString('raven_client_key=present', $phaseOneRuntime);

    $this->assertStringContainsString('retrieval-confidence-thresholds.yaml', $abuseConfig);
    $this->assertStringContainsString('rag-contract-meta-present', $thresholdSuite);
    $this->assertStringContainsString('rag-citation-coverage', $thresholdSuite);
    $this->assertStringContainsString('rag-low-confidence-refusal', $thresholdSuite);
    $this->assertStringContainsString('[contract_meta]', $provider);
    $this->assertStringContainsString('citations_count', $provider);
    $this->assertStringContainsString('decision_reason', $provider);
    $this->assertStringContainsString('RAG_METRIC_THRESHOLD', $promptfooGateScript);
    $this->assertStringContainsString('RAG_METRIC_MIN_COUNT', $promptfooGateScript);
    $this->assertStringContainsString('rag_contract_meta_fail=', $phaseTwoExitOneRuntime);
    $this->assertStringContainsString('rag_citation_coverage_fail=', $phaseTwoExitOneRuntime);
    $this->assertStringContainsString('rag_low_confidence_refusal_fail=', $phaseTwoExitOneRuntime);

    $this->assertStringContainsString('testRoadmapContainsPhaseThreeEntryOneDisposition', $phaseThreeEntryOneGate);
    $this->assertStringContainsString('testRuntimeArtifactContainsRequiredVerificationMarkers', $phaseThreeEntryOneGate);
    $this->assertStringContainsString('Phase 2 Deliverable #2 disposition (2026-03-03): present', $phaseThreeEntryOneRuntime);
    $this->assertStringContainsString('CLAIM-086', $phaseThreeEntryOneRuntime);

    $this->assertStringContainsString('Search API + optional vector', $systemMap);
    $this->assertStringContainsString('Observability', $systemMap);
    $this->assertStringContainsString('PF[Promptfoo harness]', $systemMap);
  }

}
