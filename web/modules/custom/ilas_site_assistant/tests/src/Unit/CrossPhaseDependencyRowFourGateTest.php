<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Guards cross-phase dependency row #4 closure artifacts (`XDP-04`).
 */
#[Group('ilas_site_assistant')]
final class CrossPhaseDependencyRowFourGateTest extends TestCase {

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
   * Roadmap must retain row #4 and a dated XDP-04 disposition.
   */
  public function testRoadmapContainsRowFourAndDisposition(): void {
    $roadmap = self::readFile('docs/aila/roadmap.md');

    $this->assertStringContainsString(
      '| CI quality gate (`IMP-TST-01`) | CI owner/platform decisions | Phase 1 -> prerequisite for all subsequent release gates | QA/Automation Engineer + TPM |',
      $roadmap
    );
    $this->assertStringContainsString(
      '### Cross-phase dependency row #4 disposition (2026-03-06)',
      $roadmap
    );
    $this->assertStringContainsString(
      'downstream release-gate work is',
      $roadmap
    );
    $this->assertStringContainsString(
      'blocked whenever unresolved dependency count is non-zero',
      $roadmap
    );
    $this->assertStringContainsString(
      'phase1-xdp04-ci-quality-gate-dependency-gate.txt',
      $roadmap
    );
  }

  /**
   * Current-state must include XDP-04 addendum and unresolved dependency logic.
   */
  public function testCurrentStateContainsXdp04AddendumAndStatusRules(): void {
    $currentState = self::readFile('docs/aila/current-state.md');

    $this->assertStringContainsString(
      '### Cross-Phase Dependency Row #4 CI Quality Gate Guardrail Disposition (2026-03-06)',
      $currentState
    );
    $this->assertStringContainsString('`XDP-04`', $currentState);
    $this->assertStringContainsString('any unresolved prerequisite', $currentState);
    $this->assertStringContainsString('`xdp-04-status=blocked`', $currentState);
    $this->assertStringContainsString('all prerequisites', $currentState);
    $this->assertStringContainsString('`xdp-04-status=closed`', $currentState);
    $this->assertStringContainsString('xdp-04-unresolved-dependency-count', $currentState);
    $this->assertStringContainsString('phase1-xdp04-ci-quality-gate-dependency-gate.txt', $currentState);
    $this->assertStringContainsString('[^CLAIM-163]', $currentState);
  }

  /**
   * Runbook must include verification bundle for XDP-04.
   */
  public function testRunbookContainsXdp04VerificationBundle(): void {
    $runbook = self::readFile('docs/aila/runbook.md');

    $this->assertStringContainsString(
      '### Cross-phase dependency row #4 CI quality gate verification (`XDP-04`)',
      $runbook
    );
    $this->assertStringContainsString('# VC-UNIT', $runbook);
    $this->assertStringContainsString('# VC-RUNBOOK-PANTHEON', $runbook);
    $this->assertStringContainsString('any missing prerequisite => `xdp-04-status=blocked`', $runbook);
    $this->assertStringContainsString('all prerequisites present => `xdp-04-status=closed`', $runbook);
    $this->assertStringContainsString('xdp-04-unresolved-dependency-count=0', $runbook);
    $this->assertStringContainsString('phase1-xdp04-ci-quality-gate-dependency-gate.txt', $runbook);
    $this->assertStringContainsString('[^CLAIM-163]', $runbook);
  }

  /**
   * Evidence index must include CLAIM-122 addendum plus CLAIM-163 section.
   */
  public function testEvidenceIndexContainsXdp04ClaimAndAddendum(): void {
    $evidenceIndex = self::readFile('docs/aila/evidence-index.md');

    $this->assertStringContainsString('### CLAIM-122', $evidenceIndex);
    $this->assertStringContainsString('Addendum (2026-03-06): Cross-phase dependency row #4 (`XDP-04`)', $evidenceIndex);
    $this->assertStringContainsString(
      '## Cross-Phase Dependency Row #4 CI Quality Gate Guardrail (`XDP-04`)',
      $evidenceIndex
    );
    $this->assertStringContainsString('### CLAIM-163', $evidenceIndex);
    $this->assertStringContainsString('CrossPhaseDependencyRowFourGateTest.php', $evidenceIndex);
  }

  /**
   * Runtime proof must contain deterministic markers and no unresolved items.
   */
  public function testRuntimeArtifactContainsClosedStatusAndMarkers(): void {
    $artifact = self::readFile('docs/aila/runtime/phase1-xdp04-ci-quality-gate-dependency-gate.txt');

    $this->assertStringContainsString('xdp-04-status=closed', $artifact);
    $this->assertStringContainsString('xdp-04-workstream=IMP-TST-01', $artifact);
    $this->assertStringContainsString('xdp-04-owner-role=QA/Automation Engineer + TPM', $artifact);
    $this->assertStringContainsString('xdp-04-consumed-in=Phase 1 -> prerequisite for all subsequent release gates', $artifact);
    $this->assertStringContainsString('dependency.ci-owner-platform-decisions=pass', $artifact);
    $this->assertStringContainsString('dependency.mandatory-merge-release-gate=pass', $artifact);
    $this->assertStringContainsString('xdp-04-unresolved-dependencies=none', $artifact);

    $matches = [];
    $didMatch = preg_match('/xdp-04-unresolved-dependency-count=(\d+)/', $artifact, $matches);
    $this->assertSame(1, $didMatch, 'Runtime artifact missing unresolved dependency count marker.');
    $this->assertSame('0', $matches[1], 'XDP-04 must remain blocked unless unresolved dependency count is zero.');
  }

  /**
   * Prerequisite anchors must stay present in source/tests/docs.
   */
  public function testPrerequisiteAnchorsRemainPresent(): void {
    $workflow = self::readFile('.github/workflows/quality-gate.yml');
    $promptfooGateScript = self::readFile('scripts/ci/run-promptfoo-gate.sh');
    $externalGateScript = self::readFile('scripts/ci/run-external-quality-gate.sh');
    $qualityGateContractTest = self::readFile('web/modules/custom/ilas_site_assistant/tests/src/Unit/QualityGateEnforcementContractTest.php');
    $phaseOneQualityGateContractTest = self::readFile('web/modules/custom/ilas_site_assistant/tests/src/Unit/PhaseOneQualityGateContractTest.php');
    $phaseTwoEntryOneRuntime = self::readFile('docs/aila/runtime/phase2-entry1-observability-ci-baseline.txt');
    $systemMap = self::readFile('docs/aila/system-map.mmd');

    $this->assertStringContainsString('name: Quality Gate', $workflow);
    $this->assertStringContainsString("'release/**'", $workflow);
    $this->assertStringContainsString('cancel-in-progress: true', $workflow);
    $this->assertStringContainsString('name: PHPUnit Quality Gate', $workflow);
    $this->assertStringContainsString('name: Promptfoo Gate', $workflow);

    $this->assertStringContainsString('$CI_BRANCH_NAME" == "master"', $promptfooGateScript);
    $this->assertStringContainsString('$CI_BRANCH_NAME" == "main"', $promptfooGateScript);
    $this->assertStringContainsString('=~ ^release/', $promptfooGateScript);
    $this->assertStringContainsString('EFFECTIVE_MODE="blocking"', $promptfooGateScript);
    $this->assertStringContainsString('EFFECTIVE_MODE="advisory"', $promptfooGateScript);

    $this->assertStringContainsString('run-quality-gate.sh', $externalGateScript);
    $this->assertStringContainsString('run-promptfoo-gate.sh', $externalGateScript);

    $this->assertStringContainsString('testWorkflowTriggersCoverAllBlockingBranches', $qualityGateContractTest);
    $this->assertStringContainsString('testDocumentationDeclaresGateMandatory', $qualityGateContractTest);
    $this->assertStringContainsString('testPromptfooBranchPolicyRemainsBranchAware', $qualityGateContractTest);

    $this->assertStringContainsString('testCurrentStateFormalizesQualityGateContract', $phaseOneQualityGateContractTest);
    $this->assertStringContainsString('testRunbookContainsEnforcedQualityGateVerificationSteps', $phaseOneQualityGateContractTest);

    $this->assertStringContainsString('.github/workflows/quality-gate.yml:1:name: Quality Gate', $phaseTwoEntryOneRuntime);
    $this->assertStringContainsString('name: PHPUnit Quality Gate', $phaseTwoEntryOneRuntime);
    $this->assertStringContainsString('name: Promptfoo Gate', $phaseTwoEntryOneRuntime);

    $this->assertCurrentDiagramAQualityGateAnchors($systemMap);
  }

}
