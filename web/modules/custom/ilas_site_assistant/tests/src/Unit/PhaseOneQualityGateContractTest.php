<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Guards Phase 1 Objective #3 quality-gate formalization artifacts.
 */
#[Group('ilas_site_assistant')]
class PhaseOneQualityGateContractTest extends TestCase {

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
   * current-state must formalize the objective as enforced quality gates.
   */
  public function testCurrentStateFormalizesQualityGateContract(): void {
    $currentState = self::readFile('docs/aila/current-state.md');

    $this->assertStringContainsString('### F) Observability & monitoring', $currentState);
    $this->assertStringContainsString('Promptfoo + quality gate harness', $currentState);
    $this->assertStringContainsString('tests/run-quality-gate.sh', $currentState);
    $this->assertStringContainsString('scripts/ci/run-external-quality-gate.sh', $currentState);
    $this->assertStringContainsString('scripts/ci/run-promptfoo-gate.sh', $currentState);
    $this->assertStringContainsString('`main`/`release/*`', $currentState);
    $this->assertStringContainsString('## 8) Known unknowns', $currentState);
    $this->assertStringContainsString('Promptfoo CI ownership outside this repository', $currentState);
    $this->assertStringContainsString('### Phase 1 Objective #3 Quality Gate Disposition (2026-02-27)', $currentState);
  }

  /**
   * runbook section 4 must provide reproducible quality-gate commands.
   */
  public function testRunbookContainsEnforcedQualityGateVerificationSteps(): void {
    $runbook = self::readFile('docs/aila/runbook.md');

    $this->assertStringContainsString(
      '## 4) Quality gates + config parity checks (`P1-OBJ-03`, `IMP-CONF-01`)',
      $runbook,
    );
    $this->assertStringContainsString(
      '### Enforced quality gate verification (`P1-OBJ-03`)',
      $runbook,
    );
    $this->assertStringContainsString(
      'ddev exec bash /var/www/html/web/modules/custom/ilas_site_assistant/tests/run-quality-gate.sh',
      $runbook,
    );
    $this->assertStringContainsString(
      'scripts/ci/run-promptfoo-gate.sh --env dev --mode auto --skip-eval --simulate-pass-rate 85',
      $runbook,
    );
    $this->assertStringContainsString(
      'scripts/ci/run-external-quality-gate.sh --env dev --mode auto',
      $runbook,
    );
    $this->assertStringContainsString('`main`/`release/*`', $runbook);
  }

  /**
   * Evidence index addenda must link objective evidence under claims 086/105/122.
   */
  public function testEvidenceIndexAddendaReferenceQualityGateArtifacts(): void {
    $evidenceIndex = self::readFile('docs/aila/evidence-index.md');

    $this->assertStringContainsString('### CLAIM-086', $evidenceIndex);
    $this->assertStringContainsString('### CLAIM-105', $evidenceIndex);
    $this->assertStringContainsString('### CLAIM-122', $evidenceIndex);
    $this->assertStringContainsString(
      'Addendum (2026-02-27): existing Promptfoo test assets are now part of an',
      $evidenceIndex,
    );
    $this->assertStringContainsString(
      'Addendum (2026-02-27): deterministic classifier assets are promoted into an',
      $evidenceIndex,
    );
    $this->assertStringContainsString(
      'Addendum (2026-02-27): quality-gate enforcement is formalized with',
      $evidenceIndex,
    );
    $this->assertStringContainsString('tests/run-quality-gate.sh', $evidenceIndex);
    $this->assertStringContainsString('scripts/ci/run-external-quality-gate.sh', $evidenceIndex);
    $this->assertStringContainsString('scripts/ci/run-promptfoo-gate.sh', $evidenceIndex);
    $this->assertStringContainsString(
      'web/modules/custom/ilas_site_assistant/tests/src/Unit/PhaseOneQualityGateContractTest.php',
      $evidenceIndex,
    );
  }

  /**
   * Gate scripts must retain enforcement markers and branch policy.
   */
  public function testQualityGateScriptsContainExpectedEnforcementMarkers(): void {
    $qualityGate = self::readFile('web/modules/custom/ilas_site_assistant/tests/run-quality-gate.sh');
    $externalGate = self::readFile('scripts/ci/run-external-quality-gate.sh');
    $promptfooGate = self::readFile('scripts/ci/run-promptfoo-gate.sh');

    $this->assertStringContainsString('PHPUnit drupal-unit suite', $qualityGate);
    $this->assertStringContainsString('--testsuite drupal-unit', $qualityGate);
    $this->assertStringContainsString('GoldenTranscriptTest.php', $qualityGate);
    $this->assertStringContainsString('phpunit-summary.txt', $qualityGate);

    $this->assertStringContainsString('tests/run-quality-gate.sh', $externalGate);
    $this->assertStringContainsString('scripts/ci/run-promptfoo-gate.sh', $externalGate);

    $this->assertStringContainsString(
      'if [[ "$CI_BRANCH_NAME" == "main" || "$CI_BRANCH_NAME" =~ ^release/ ]]; then',
      $promptfooGate,
    );
    $this->assertStringContainsString('Promptfoo gate FAILED in blocking mode', $promptfooGate);
    $this->assertStringContainsString('Promptfoo gate FAILED in advisory mode', $promptfooGate);
  }

}
