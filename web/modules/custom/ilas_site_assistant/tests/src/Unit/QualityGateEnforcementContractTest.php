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
    $this->assertStringContainsString('npm run test:promptfoo:runtime', $script);
    $this->assertStringContainsString('promptfoo_runtime', $script);
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

    $this->assertStringContainsString('$CI_BRANCH_NAME" == "master"', $script);
    $this->assertStringContainsString('$CI_BRANCH_NAME" == "main"', $script);
    $this->assertStringContainsString('=~ ^release/', $script);
    $this->assertStringContainsString('EFFECTIVE_MODE="blocking"', $script);
    $this->assertStringContainsString('EFFECTIVE_MODE="advisory"', $script);
    $this->assertStringContainsString('promptfooconfig.smoke.yaml', $script);
    $this->assertStringContainsString('promptfooconfig.deep.yaml', $script);
    $this->assertStringContainsString('promptfooconfig.abuse.yaml', $script);
    $this->assertStringContainsString('config_file=', $script);
    $this->assertStringContainsString('gate-metrics.js', $script);
    $this->assertStringContainsString('apply_metric_threshold_report', $script);
  }

  /**
   * GitHub Actions workflow must wire both quality gate jobs.
   */
  public function testGitHubActionsWorkflowWiresQualityGateJobs(): void {
    $workflow = self::readFile('.github/workflows/quality-gate.yml');

    // Job 1: PHPUnit quality gate references.
    $this->assertStringContainsString('phpunit.pure.xml', $workflow);
    $this->assertStringContainsString('phpunit.xml', $workflow);
    $this->assertStringContainsString('--testsuite drupal-unit', $workflow);
    $this->assertStringContainsString('run-quality-gate.sh', $workflow);
    $this->assertStringContainsString('upload-artifact', $workflow);

    // Job 2: Promptfoo gate references.
    $this->assertStringContainsString(
      'run-assistant-widget-hardening.mjs',
      $workflow,
      'Promptfoo gate must run the UX/a11y JS hardening suite'
    );
    $this->assertStringContainsString(
      'Run UX/a11y widget hardening suite (P3-EXT-01)',
      $workflow,
      'Promptfoo gate must include the explicit P3-EXT-01 step name'
    );
    $this->assertStringContainsString('Run promptfoo transport/runtime tests', $workflow);
    $this->assertStringContainsString('run-promptfoo-gate.sh', $workflow);
    $this->assertStringContainsString('--skip-eval', $workflow);
    $this->assertStringContainsString('--simulate-pass-rate', $workflow);
    $this->assertStringContainsString('CI_PROMPTFOO_ENV: dev', $workflow);
    $this->assertStringContainsString('TARGET_ENV="${CI_PROMPTFOO_ENV}"', $workflow);
    $this->assertStringContainsString('ILAS_CONFIGURED_RATE_LIMIT_PER_MINUTE', $workflow);
    $this->assertStringContainsString('ILAS_CONFIGURED_RATE_LIMIT_PER_HOUR', $workflow);

    // Branch-aware policy annotation.
    $this->assertStringContainsString('BLOCKING', $workflow);
    $this->assertStringContainsString('ADVISORY', $workflow);
  }

  /**
   * Workflow triggers must cover all blocking branches including release.
   */
  public function testWorkflowTriggersCoverAllBlockingBranches(): void {
    $workflow = self::readFile('.github/workflows/quality-gate.yml');

    // push section must include all blocking branches.
    $pushPos = strpos($workflow, 'push:');
    $this->assertNotFalse($pushPos, 'push trigger must exist in workflow');

    // pull_request section must include release/** for blocking branch coverage.
    $prPos = strpos($workflow, 'pull_request:');
    $this->assertNotFalse($prPos, 'pull_request trigger must exist in workflow');

    $pushSection = substr($workflow, (int) $pushPos, (int) $prPos - (int) $pushPos);
    $this->assertStringContainsString('- master', $pushSection, 'push trigger must include master');
    $this->assertStringContainsString('- main', $pushSection, 'push trigger must include main');
    $this->assertStringContainsString("'release/**'", $pushSection, 'push trigger must include release/**');

    // Find release/** after the pull_request: declaration.
    $releasePos = strpos($workflow, "release/**", $prPos);
    $this->assertNotFalse(
      $releasePos,
      'pull_request trigger must include release/** for blocking branch coverage'
    );

    // Concurrency control must exist to prevent stale-run races.
    $this->assertStringContainsString(
      'concurrency:',
      $workflow,
      'Workflow must include concurrency control'
    );
    $this->assertStringContainsString(
      'cancel-in-progress:',
      $workflow,
      'Workflow must include cancel-in-progress for concurrency control'
    );

    $this->assertStringContainsString(
      'needs: quality-gate',
      $workflow,
      'Promptfoo gate must explicitly depend on quality-gate job'
    );
  }

  /**
   * Documentation must declare CI quality gate mandatory with enforcement.
   */
  public function testDocumentationDeclaresGateMandatory(): void {
    $currentState = self::readFile('docs/aila/current-state.md');

    $this->assertStringContainsString(
      'CI quality gate is mandatory for merge/release path',
      $currentState,
      'current-state.md must declare CI quality gate mandatory'
    );
    $this->assertStringContainsString(
      'branch protection',
      $currentState,
      'current-state.md must reference branch protection enforcement'
    );
  }

  /**
   * Pure unit profile must exclude docs-only continuity guards.
   */
  public function testPureUnitProfileExcludesDocsOnlyContinuityGuards(): void {
    $purePhpunit = self::readFile('phpunit.pure.xml');

    $this->assertStringContainsString('<group>ilas_site_assistant_docs</group>', $purePhpunit);
    $this->assertStringContainsString('<exclude>', $purePhpunit);
  }

  /**
   * PROMPTFOO_SCRIPT existence check must come AFTER the SKIP_EVAL guard.
   */
  public function testPromptfooGateSkipEvalBeforeScriptCheck(): void {
    $script = self::readFile('scripts/ci/run-promptfoo-gate.sh');

    $skipEvalPos = strpos($script, 'if [[ "$SKIP_EVAL" == "true" ]]');
    $scriptCheckPos = strpos($script, 'if [[ ! -x "$PROMPTFOO_SCRIPT" ]]');

    $this->assertNotFalse($skipEvalPos, 'SKIP_EVAL guard must exist in script');
    $this->assertNotFalse($scriptCheckPos, 'PROMPTFOO_SCRIPT check must exist in script');
    $this->assertGreaterThan(
      $skipEvalPos,
      $scriptCheckPos,
      'PROMPTFOO_SCRIPT existence check must come AFTER the SKIP_EVAL guard'
    );
  }

}
