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
   * Counts scenario IDs from a promptfoo test file.
   *
   * @return array<int, string>
   *   Scenario IDs in file order.
   */
  private static function scenarioIds(string $relativePath): array {
    $contents = self::readFile($relativePath);
    preg_match_all('/scenario_id:\s+([a-z0-9-]+)/', $contents, $matches);
    return $matches[1] ?? [];
  }

  /**
   * Counts top-level promptfoo test cases from a YAML file.
   */
  private static function caseCount(string $relativePath): int {
    $contents = self::readFile($relativePath);
    preg_match_all('/^- vars:/m', $contents, $matches);
    return count($matches[0] ?? []);
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
    $this->assertStringContainsString('eval_execution_mode=', $script);
    $this->assertStringContainsString('rate_limit_source=', $script);
    $this->assertStringContainsString('effective_pacing_rate_per_minute=', $script);
    $this->assertStringContainsString('effective_request_delay_ms=', $script);
    $this->assertStringContainsString('ddev_rate_limit_override=', $script);
    $this->assertStringContainsString('planned_message_request_budget=', $script);
    $this->assertStringContainsString('structured-error-summary.json', $script);
    $this->assertStringContainsString('structured-error-summary.txt', $script);
    $this->assertStringContainsString('diagnostic-summary', $script);
    $this->assertStringContainsString('reset_output_artifacts()', $script);
    $this->assertStringContainsString('target_env_mismatch', $script);
    $this->assertStringContainsString('compute_message_request_budget()', $script);
    $this->assertStringContainsString('compute_remote_pacing_rate_per_minute()', $script);
    $this->assertStringContainsString('configure_transport_policy()', $script);
    $this->assertStringContainsString('ILAS_429_MAX_RETRIES', $script);
    $this->assertStringContainsString('ILAS_429_BASE_WAIT_MS', $script);
    $this->assertStringContainsString('ILAS_429_MAX_WAIT_MS', $script);
    $this->assertStringContainsString('finalize_and_exit 2', $script);
    $this->assertStringContainsString('finalize_and_exit 3', $script);
    $this->assertStringContainsString('finalize_and_exit 4', $script);

    $overridePos = strpos($script, 'apply_ddev_rate_limit_override || finalize_and_exit 4');
    $preflightPos = strpos($script, 'run_connectivity_preflight || finalize_and_exit $?');
    $resetArtifactsPos = strrpos($script, 'reset_output_artifacts');
    $skipEvalBranchPos = strrpos($script, 'if [[ "$SKIP_EVAL" == "true" ]]');
    $this->assertNotFalse($overridePos);
    $this->assertNotFalse($preflightPos);
    $this->assertNotFalse($resetArtifactsPos);
    $this->assertNotFalse($skipEvalBranchPos);
    $this->assertLessThan(
      $preflightPos,
      $overridePos,
      'DDEV rate-limit override must occur before the live preflight so repeated local runs do not fail on stale flood counters.'
    );
    $this->assertLessThan(
      $skipEvalBranchPos,
      $resetArtifactsPos,
      'Promptfoo output artifacts must be reset before the gate can short-circuit into simulated/skip-eval mode.'
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
    $this->assertStringContainsString('promptfooconfig.hosted.yaml', $workflow);
    $this->assertStringContainsString('promptfooconfig.protected-push.yaml', $workflow);
    $this->assertStringContainsString('promptfoo-gate-artifacts', $workflow);
    $this->assertStringContainsString('--no-deep-eval', $workflow);
  }

  /**
   * Hosted GitHub profile must keep the rate-limit-safe case budget and metric families.
   */
  public function testHostedPromptfooProfilePreservesBudgetedMetricCoverage(): void {
    $config = self::readFile('promptfoo-evals/promptfooconfig.hosted.yaml');
    $abuseCaseCount = self::caseCount('promptfoo-evals/tests/abuse-safety-hosted.yaml');
    $groundingScenarioIds = self::scenarioIds('promptfoo-evals/tests/grounding-escalation-safety-boundaries-hosted.yaml');
    $retrievalCaseCount = self::caseCount('promptfoo-evals/tests/retrieval-confidence-thresholds.yaml');
    $multilingualCaseCount = self::caseCount('promptfoo-evals/tests/multilingual-routing-live.yaml');

    $this->assertStringContainsString('Hosted GitHub evals', $config);
    $this->assertStringContainsString('tests/abuse-safety-hosted.yaml', $config);
    $this->assertStringContainsString('tests/retrieval-confidence-thresholds.yaml', $config);
    $this->assertStringContainsString('tests/grounding-escalation-safety-boundaries-hosted.yaml', $config);
    $this->assertStringContainsString('tests/multilingual-routing-live.yaml', $config);

    $this->assertSame(8, $abuseCaseCount);
    $this->assertSame(20, $retrievalCaseCount);
    $this->assertCount(40, $groundingScenarioIds);
    $this->assertSame(7, $multilingualCaseCount);
    $this->assertSame(75, $abuseCaseCount + $retrievalCaseCount + count($groundingScenarioIds) + $multilingualCaseCount);

    $weakGrounding = array_values(array_filter($groundingScenarioIds, static fn(string $id): bool => str_starts_with($id, 'wg-')));
    $escalation = array_values(array_filter($groundingScenarioIds, static fn(string $id): bool => str_starts_with($id, 'es-')));
    $safetyBoundary = array_values(array_filter($groundingScenarioIds, static fn(string $id): bool => str_starts_with($id, 'sb-')));

    $this->assertCount(10, $weakGrounding);
    $this->assertCount(10, $escalation);
    $this->assertCount(20, $safetyBoundary);
    $this->assertContains('sb-10', $groundingScenarioIds);
    $this->assertContains('sb-11', $groundingScenarioIds);
    $this->assertContains('sb-20', $groundingScenarioIds);
  }

  /**
   * Protected-push hosted profile must stay on the smaller stability subset.
   */
  public function testProtectedPushPromptfooProfilePreservesSmallerStabilitySubset(): void {
    $config = self::readFile('promptfoo-evals/promptfooconfig.protected-push.yaml');
    $caseCount = self::caseCount('promptfoo-evals/tests/protected-push-stability.yaml');
    $scenarioIds = self::scenarioIds('promptfoo-evals/tests/protected-push-stability.yaml');

    $this->assertStringContainsString('Protected push hosted stability evals', $config);
    $this->assertStringContainsString('tests/protected-push-stability.yaml', $config);
    $this->assertSame(19, $caseCount);
    $this->assertContains('wg-01', $scenarioIds);
    $this->assertContains('es-01', $scenarioIds);
    $this->assertContains('sb-11', $scenarioIds);
    $this->assertContains('es-apply-help', $scenarioIds);
  }

}
