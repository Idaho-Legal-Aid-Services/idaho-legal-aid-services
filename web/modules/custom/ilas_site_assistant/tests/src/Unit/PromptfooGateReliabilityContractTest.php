<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Locks promptfoo gate reliability hardening artifacts.
 */
#[Group('ilas_site_assistant')]
final class PromptfooGateReliabilityContractTest extends TestCase {

  /**
   * Returns the repository root.
   */
  private static function repoRoot(): string {
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
   * Gate script must preserve phased execution and classified summary fields.
   */
  public function testPromptfooGateContainsPhasedReliabilityMarkers(): void {
    $script = self::readFile('scripts/ci/run-promptfoo-gate.sh');

    $this->assertStringContainsString('--connectivity-only', $script);
    $this->assertStringContainsString('QUALITY_PHASE="target_resolution"', $script);
    $this->assertStringContainsString('QUALITY_PHASE="preflight"', $script);
    $this->assertStringContainsString('QUALITY_PHASE="smoke"', $script);
    $this->assertStringContainsString('QUALITY_PHASE="full"', $script);
    $this->assertStringContainsString('promptfooconfig.smoke.yaml', $script);
    $this->assertStringContainsString('resolve-assistant-target.sh', $script);
    $this->assertStringContainsString('preflight-live.js', $script);
    $this->assertStringContainsString('discover-node-extra-ca-certs.js', $script);
    $this->assertStringContainsString('target_kind=', $script);
    $this->assertStringContainsString('target_source=', $script);
    $this->assertStringContainsString('requested_target_env=', $script);
    $this->assertStringContainsString('resolved_target_env=', $script);
    $this->assertStringContainsString('target_validation_status=', $script);
    $this->assertStringContainsString('connectivity_status=', $script);
    $this->assertStringContainsString('connectivity_error_code=', $script);
    $this->assertStringContainsString('quality_phase=', $script);
    $this->assertStringContainsString('rate_limit_source=', $script);
    $this->assertStringContainsString('effective_request_delay_ms=', $script);
    $this->assertStringContainsString('ddev_rate_limit_override=', $script);
    $this->assertStringContainsString('target_env_mismatch', $script);
    $this->assertStringContainsString('finalize_and_exit 2', $script);
    $this->assertStringContainsString('finalize_and_exit 3', $script);
    $this->assertStringContainsString('finalize_and_exit 4', $script);

    $overridePos = strpos($script, 'apply_ddev_rate_limit_override || finalize_and_exit 4');
    $preflightPos = strpos($script, 'run_connectivity_preflight || finalize_and_exit $?');
    $this->assertNotFalse($overridePos);
    $this->assertNotFalse($preflightPos);
    $this->assertLessThan(
      $preflightPos,
      $overridePos,
      'DDEV rate-limit override must occur before the live preflight so repeated local runs do not fail on stale flood counters.'
    );
  }

  /**
   * The documented direct gate invocation must be runnable on POSIX hosts.
   */
  public function testPromptfooGateScriptIsExecutableOnPosixHosts(): void {
    $path = self::repoRoot() . '/scripts/ci/run-promptfoo-gate.sh';
    self::assertFileExists($path);

    if (DIRECTORY_SEPARATOR === '\\' || PHP_OS_FAMILY === 'Windows') {
      $this->markTestSkipped('Executable bit assertions are POSIX-only.');
    }

    $this->assertTrue(
      is_executable($path),
      'scripts/ci/run-promptfoo-gate.sh must be executable so documented direct invocations work.'
    );
  }

  /**
   * Smoke config must be wired to the live provider at concurrency 1.
   */
  public function testSmokePromptfooConfigIsPinnedToSerializedLiveExecution(): void {
    $config = self::readFile('promptfoo-evals/promptfooconfig.smoke.yaml');

    $this->assertStringContainsString('description: "ILAS Site Assistant — Connectivity smoke evals"', $config);
    $this->assertStringContainsString('file://providers/ilas-live.js', $config);
    $this->assertStringContainsString('maxConcurrency: 1', $config);
    $this->assertStringContainsString('file://tests/simulated-user-smoke.yaml', $config);
  }

  /**
   * Wrapper scripts must use the repo-installed promptfoo CLI.
   */
  public function testPromptfooWrappersUseRepoInstalledCli(): void {
    $bashRunner = self::readFile('promptfoo-evals/scripts/run-promptfoo.sh');
    $psRunner = self::readFile('promptfoo-evals/scripts/run-promptfoo.ps1');

    $this->assertStringContainsString('npx --no-install promptfoo', $bashRunner);
    $this->assertStringContainsString('$PromptfooArgs = @("--no-install", "promptfoo")', $psRunner);
    $this->assertStringContainsString('& npx @PromptfooArgs eval', $psRunner);
  }

  /**
   * Workflow must pass explicit rate-limit envs into the real gate step.
   */
  public function testWorkflowPassesExplicitRemoteRateLimitEnvVars(): void {
    $workflow = self::readFile('.github/workflows/quality-gate.yml');

    $this->assertStringContainsString('CI_PROMPTFOO_ENV: dev', $workflow);
    $this->assertStringContainsString('ILAS_CONFIGURED_RATE_LIMIT_PER_MINUTE', $workflow);
    $this->assertStringContainsString('ILAS_CONFIGURED_RATE_LIMIT_PER_HOUR', $workflow);
    $this->assertStringContainsString('TARGET_ENV="${CI_PROMPTFOO_ENV}"', $workflow);
    $this->assertStringContainsString('npm run test:promptfoo:runtime', $workflow);
  }

}
