<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Guards P1-EXT-03 artifacts for Phase 1 Exit criterion #3.
 */
#[Group('ilas_site_assistant')]
final class PhaseOneExitCriteriaThreeGateTest extends TestCase {

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
   * Roadmap must contain dated closure for Phase 1 Exit criterion #3.
   */
  public function testRoadmapContainsPhaseOneExitThreeDisposition(): void {
    $roadmap = self::readFile('docs/aila/roadmap.md');

    $this->assertStringContainsString(
      'Reliability failure matrix tests pass against target environments.',
      $roadmap,
    );
    $this->assertStringContainsString('### Phase 1 Exit #3 disposition (2026-03-03)', $roadmap);
    $this->assertStringContainsString(
      'docs/aila/runtime/phase1-exit3-reliability-failure-matrix.txt',
      $roadmap,
    );
    $this->assertStringContainsString('llm.enabled=false', $roadmap);
    $this->assertStringContainsString('no full retrieval-architecture redesign', $roadmap);
  }

  /**
   * Current-state addendum must capture exit #3 closure and claim footnote.
   */
  public function testCurrentStateContainsExitThreeAddendum(): void {
    $currentState = self::readFile('docs/aila/current-state.md');

    $this->assertStringContainsString(
      '### Phase 1 Exit #3 Reliability Failure Matrix Verification (2026-03-03)',
      $currentState,
    );
    $this->assertStringContainsString(
      'DependencyFailureDegradeContractTest.php',
      $currentState,
    );
    $this->assertStringContainsString(
      'IntegrationFailureContractTest.php',
      $currentState,
    );
    $this->assertStringContainsString(
      'LlmEnhancerHardeningTest.php',
      $currentState,
    );
    $this->assertStringContainsString(
      'phase1-exit3-reliability-failure-matrix.txt',
      $currentState,
    );
    $this->assertStringContainsString('[^CLAIM-128]', $currentState);
  }

  /**
   * Runbook section 4 must include reliability matrix verification commands.
   */
  public function testRunbookContainsExitThreeVerificationCommands(): void {
    $runbook = self::readFile('docs/aila/runbook.md');

    $this->assertStringContainsString(
      '### Phase 1 Exit #3 reliability failure matrix verification (`P1-EXT-03`)',
      $runbook,
    );
    $this->assertStringContainsString('DependencyFailureDegradeContractTest.php', $runbook);
    $this->assertStringContainsString('IntegrationFailureContractTest.php', $runbook);
    $this->assertStringContainsString('LlmEnhancerHardeningTest.php', $runbook);
    $this->assertStringContainsString('for ENV in dev test live; do', $runbook);
    $this->assertStringContainsString(
      'docs/aila/runtime/phase1-exit3-reliability-failure-matrix.txt',
      $runbook,
    );
    $this->assertStringContainsString('legacy_fallback', $runbook);
    $this->assertStringContainsString('lexical_preserved', $runbook);
    $this->assertStringContainsString('original_preserved', $runbook);
    $this->assertStringContainsString('internal_error', $runbook);
  }

  /**
   * Evidence index must include CLAIM-128 with expected references.
   */
  public function testEvidenceIndexContainsClaim128(): void {
    $evidenceIndex = self::readFile('docs/aila/evidence-index.md');

    $this->assertStringContainsString(
      '## Phase 1 Exit #3 Reliability Failure Matrix (`P1-EXT-03`)',
      $evidenceIndex,
    );
    $this->assertStringContainsString('### CLAIM-128', $evidenceIndex);
    $this->assertStringContainsString('Phase 1 Exit #3 (P1-EXT-03) is closed', $evidenceIndex);
    $this->assertStringContainsString(
      'Phase 1 Exit #3 reliability failure matrix verification subsection in section 4',
      $evidenceIndex,
    );
    $this->assertStringContainsString('phase1-exit3-reliability-failure-matrix.txt', $evidenceIndex);
    $this->assertStringContainsString('DependencyFailureDegradeContractTest.php', $evidenceIndex);
    $this->assertStringContainsString('IntegrationFailureContractTest.php', $evidenceIndex);
    $this->assertStringContainsString('LlmEnhancerHardeningTest.php', $evidenceIndex);
    $this->assertStringContainsString('PhaseOneExitCriteriaThreeGateTest.php', $evidenceIndex);
  }

  /**
   * Runtime artifact must exist with local and Pantheon proof lines.
   */
  public function testRuntimeArtifactContainsExitThreeProofLines(): void {
    $artifact = self::readFile('docs/aila/runtime/phase1-exit3-reliability-failure-matrix.txt');

    $this->assertStringContainsString('## Local (DDEV) reliability matrix suites', $artifact);
    $this->assertStringContainsString('DependencyFailureDegradeContractTest', $artifact);
    $this->assertStringContainsString('IntegrationFailureContractTest', $artifact);
    $this->assertStringContainsString('LlmEnhancerHardeningTest', $artifact);
    $this->assertStringContainsString('6 / 6 (100%)', $artifact);
    $this->assertStringContainsString('23 / 23 (100%)', $artifact);
    $this->assertStringContainsString('29 / 29 (100%)', $artifact);
    $this->assertStringContainsString('## Pantheon target-environment contract checks (dev/test/live)', $artifact);
    $this->assertStringContainsString("=== dev ===", $artifact);
    $this->assertStringContainsString("=== test ===", $artifact);
    $this->assertStringContainsString("=== live ===", $artifact);
    $this->assertStringContainsString("'ilas_site_assistant.settings:llm.enabled': false", $artifact);
    $this->assertStringContainsString("'ilas_site_assistant.settings:llm.fallback_on_error': true", $artifact);
    $this->assertStringContainsString("'ilas_site_assistant.settings:vector_search.enabled': false", $artifact);
  }

}
