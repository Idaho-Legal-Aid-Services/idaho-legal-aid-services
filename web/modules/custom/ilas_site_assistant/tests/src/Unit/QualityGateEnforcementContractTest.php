<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Locks enforced external CI quality gate behavior.
 */
#[Group('ilas_site_assistant')]
final class QualityGateEnforcementContractTest extends TestCase {

  /**
   * Returns repository root.
   */
  private static function repoRoot(): string {
    // __DIR__ = <repo>/web/modules/custom/ilas_site_assistant/tests/src/Unit
    return dirname(__DIR__, 7);
  }

  /**
   * Reads a repo file with existence checks.
   */
  private static function readFile(string $relativePath): string {
    $path = self::repoRoot() . '/' . ltrim($relativePath, '/');
    self::assertFileExists($path, "Expected file does not exist: {$relativePath}");

    $contents = file_get_contents($path);
    self::assertIsString($contents, "Failed reading file: {$relativePath}");
    return $contents;
  }

  /**
   * Quality gate script must enforce VC-UNIT and full VC-DRUPAL-UNIT.
   */
  public function testQualityGateScriptEnforcesUnitAndDrupalUnitSuites(): void {
    $script = self::readFile('web/modules/custom/ilas_site_assistant/tests/run-quality-gate.sh');

    $this->assertStringContainsString('--group ilas_site_assistant', $script);
    $this->assertStringContainsString('tests/src/Unit', $script);
    $this->assertStringContainsString('--testsuite drupal-unit', $script);
    $this->assertStringContainsString('promptfoo-evals/output', $script);
    $this->assertStringContainsString('phpunit-summary.txt', $script);
  }

  /**
   * External gate script must run PHPUnit phase then Promptfoo phase.
   */
  public function testExternalGateScriptInvokesPhpunitAndPromptfooGates(): void {
    $script = self::readFile('scripts/ci/run-external-quality-gate.sh');

    $this->assertStringContainsString('run-quality-gate.sh', $script);
    $this->assertStringContainsString('run-promptfoo-gate.sh', $script);
    $this->assertStringContainsString('--skip-eval', $script);
    $this->assertStringContainsString('--simulate-pass-rate', $script);
    $this->assertStringContainsString('--threshold', $script);
    $this->assertStringContainsString('--config', $script);
  }

  /**
   * Promptfoo branch policy must stay branch-aware.
   */
  public function testPromptfooBranchPolicyRemainsBranchAware(): void {
    $script = self::readFile('scripts/ci/run-promptfoo-gate.sh');

    $this->assertStringContainsString('$CI_BRANCH_NAME" == "main"', $script);
    $this->assertStringContainsString('=~ ^release/', $script);
    $this->assertStringContainsString('EFFECTIVE_MODE="blocking"', $script);
    $this->assertStringContainsString('EFFECTIVE_MODE="advisory"', $script);
  }

}
