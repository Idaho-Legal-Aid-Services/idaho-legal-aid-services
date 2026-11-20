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
    $this->assertStringContainsString('--profile', $script);
    $this->assertStringContainsString('assistant-pr', $script);
    $this->assertStringContainsString('FaqSearchRuntimeRegressionKernelTest.php', $script);
    $this->assertStringContainsString('AssistantRetrievalGroundingKernelTest.php', $script);
    $this->assertStringContainsString('AssistantMessageRuntimeBehaviorFunctionalTest.php', $script);
    $this->assertStringContainsString('vc_kernel', $script);
    $this->assertStringContainsString('assistant_functional', $script);
    $this->assertStringContainsString('run-host-phpunit.sh', $script);
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

    $promptfooGate = self::readFile('scripts/ci/run-promptfoo-gate.sh');
    $this->assertStringContainsString('simulated_blocking_disallowed', $promptfooGate);
    $this->assertStringContainsString('--allow-simulated-blocking', $promptfooGate);
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
    $this->assertStringContainsString('promptfooconfig.deploy.yaml', $script);
    $this->assertStringContainsString('promptfooconfig.protected-push.yaml', $script);
    $this->assertStringContainsString('promptfooconfig.deep.yaml', $script);
    $this->assertStringContainsString('simulated_blocking_allowed=', $script);
    $this->assertStringContainsString('promptfooconfig.abuse.yaml', $script);
    $this->assertStringContainsString('config_file=', $script);
    $this->assertStringContainsString('eval_execution_mode=', $script);
    $this->assertStringContainsString('gate-metrics.js', $script);
    $this->assertStringContainsString('apply_metric_threshold_report', $script);
    $this->assertStringContainsString('structured-error-summary.json', $script);
    $this->assertStringContainsString('structured-error-summary.txt', $script);
  }

  /**
   * GitHub Actions workflow must wire quality gate and assistant smoke jobs.
   */
  public function testGitHubActionsWorkflowWiresQualityGateJobs(): void {
    $workflow = self::readFile('.github/workflows/quality-gate.yml');

    // Job 1: Basic quality gate references.
    $this->assertStringContainsString('detect-assistant-changes', $workflow);
    $this->assertStringContainsString('Assistant PR Quality Gate', $workflow);
    $this->assertStringContainsString('assistant_changed', $workflow);
    $this->assertStringContainsString('web/modules/custom/ilas_site_assistant/', $workflow);
    $this->assertStringContainsString('promptfoo-evals/', $workflow);
    $this->assertStringContainsString('phpunit.pure.xml', $workflow);
    $this->assertStringContainsString('Run PHPUnit pure/unit classifier and safety tests', $workflow);
    $this->assertStringContainsString('phpunit.xml', $workflow);
    $this->assertStringContainsString('--testsuite drupal-unit', $workflow);
    $this->assertStringContainsString('--profile basic --skip-phpunit', $workflow);
    $this->assertStringContainsString('upload-artifact', $workflow);

    // Assistant-path PR gate references.
    $this->assertStringContainsString(
      'run-assistant-widget-hardening.mjs',
      $workflow,
      'Basic gate must run the UX/a11y JS hardening suite'
    );
    $this->assertStringContainsString('Run provider/runtime harness and golden checks', $workflow);
    $this->assertStringContainsString('--profile assistant-pr --skip-phpunit', $workflow);
    $this->assertStringContainsString('run-promptfoo-gate.sh', $workflow);
    $this->assertStringContainsString('promptfooconfig.quality.yaml', $workflow);
    $this->assertStringContainsString('promptfooconfig.hosted.yaml', $workflow);
    $this->assertStringContainsString('--no-deep-eval', $workflow);
    $this->assertStringContainsString('ASSISTANT_BASE_URL', $workflow);
    $this->assertStringContainsString('ILAS_ASSISTANT_URL', $workflow);
    $this->assertStringContainsString('ILAS_CONFIGURED_RATE_LIMIT_PER_MINUTE', $workflow);
    $this->assertStringContainsString('ILAS_CONFIGURED_RATE_LIMIT_PER_HOUR', $workflow);
    $this->assertStringContainsString('test:assistant:smoke', $workflow);
    $this->assertStringContainsString('assistant-playwright-smoke:', $workflow);
    $this->assertStringContainsString('Assistant Mocked Playwright Smoke', $workflow);
    $this->assertStringContainsString("require.resolve('@playwright/test')", $workflow);
    $this->assertStringContainsString('npx playwright install --with-deps chromium', $workflow);
    $this->assertStringContainsString('test:assistant:playwright:mocked-smoke', $workflow);
    $this->assertStringContainsString('assistant-playwright-smoke-artifacts', $workflow);
    $this->assertStringContainsString('run-vector-provenance-smoke.js', $workflow);
    $this->assertStringContainsString('assistant-pr-quality-artifacts', $workflow);
    $this->assertStringContainsString('hosted-manual-gate-artifacts', $workflow);
    $this->assertStringContainsString('structured-error-summary.txt', $workflow);

    $nightly = self::readFile('.github/workflows/assistant-nightly-quality.yml');
    $this->assertStringContainsString('cron:', $nightly);
    $this->assertStringContainsString('promptfooconfig.deep.yaml', $nightly);
    $this->assertStringContainsString('test:assistant:playwright:journeys', $nightly);
    $this->assertStringContainsString('AssistantRetrievalGroundingKernelTest.php', $nightly);

    $playwright = self::readFile('.github/workflows/assistant-playwright.yml');
    $this->assertStringContainsString('schedule:', $playwright);
    $this->assertStringContainsString('workflow_dispatch:', $playwright);
    $this->assertStringContainsString('base_url:', $playwright);
    $this->assertStringContainsString('ILAS_PLAYWRIGHT_BASE_URL', $playwright);
    $this->assertStringContainsString('PLAYWRIGHT_BASE_URL', $playwright);
    $this->assertStringContainsString('npx playwright install --with-deps chromium', $playwright);
    $this->assertStringContainsString('npm run test:assistant:playwright:journeys', $playwright);
    $this->assertStringContainsString('assistant-playwright-artifacts', $playwright);
    $this->assertStringContainsString('Skipping full assistant Playwright journeys', $playwright);
  }

  /**
   * Tests the deploy script remains the whole deploy for every environment.
   *
   * Deploys used to be typed by hand, which is how dev came to run without
   * database updates or a config import, and how a broken site search went
   * unnoticed on dev and test. The script is only worth having as the single
   * path if none of its steps can be dropped quietly.
   */
  public function testDeployScriptPromotesAppliesAndChecks(): void {
    $script = self::readFile('scripts/deploy/pantheon-deploy.sh');

    // Promote (test/live), apply, and refuse to finish with pending updates.
    $this->assertStringContainsString('terminus env:deploy "$site_env" --updatedb --cc', $script);
    $this->assertStringContainsString('terminus drush "$site_env" -- deploy -y', $script);
    $this->assertStringContainsString('Post-deploy pending database updates remain', $script);
    // The code on the environment is proven before anything is applied.
    $this->assertStringContainsString('verify_deployed_code "$site_env" || exit 1', $script);
    $this->assertLessThan(
      strpos($script, 'terminus drush "$site_env" -- deploy -y'),
      strpos($script, 'verify_deployed_code "$site_env" || exit 1'),
    );
    $this->assertStringContainsString('php:script "$remote_script"', $script);
    // Config drift is fatal off dev, and both smokes gate success.
    $this->assertStringContainsString('check_config_drift "$site_env" || exit 1', $script);
    $this->assertStringContainsString('run_site_smoke || exit 1', $script);
    $this->assertStringContainsString('run_assistant_smoke || exit 1', $script);
    $this->assertStringContainsString('scripts/smoke/site-smoke.mjs', $script);
    $this->assertStringContainsString('scripts/smoke/assistant-smoke.mjs', $script);
    // Live keeps its backup and its typed confirmation.
    $this->assertStringContainsString('backup:create "$site_env" --keep-for=7', $script);
    $this->assertStringContainsString('Type this exact token to continue', $script);

    $package = self::readFile('package.json');
    foreach (['dev', 'test', 'live'] as $environment) {
      $this->assertStringContainsString(
        '"deploy:' . $environment . '": "bash scripts/deploy/pantheon-deploy.sh --env ' . $environment . '"',
        $package,
      );
    }

    // Publishing to dev goes through the same script.
    $this->assertStringContainsString(
      'scripts/deploy/pantheon-deploy.sh" --env dev',
      self::readFile('scripts/git/finish.sh'),
    );
  }

  /**
   * Tests the scheduled smoke covers every environment and reports failure.
   */
  public function testScheduledSiteSmokeWorkflowCoversEveryEnvironment(): void {
    $workflow = self::readFile('.github/workflows/site-smoke.yml');

    $this->assertStringContainsString('schedule:', $workflow);
    $this->assertStringContainsString('workflow_dispatch:', $workflow);
    $this->assertStringContainsString('types: [pantheon-deploy]', $workflow);
    $this->assertStringContainsString('environment: [dev, test, live]', $workflow);
    $this->assertStringContainsString('scripts/smoke/site-smoke.mjs', $workflow);
    // Code pushed to Pantheon by someone else is reported even when the
    // smoke passes.
    $this->assertStringContainsString('external-change-notice:', $workflow);
    $this->assertStringContainsString('vars.PANTHEON_EXPECTED_DEPLOYER', $workflow);
    $this->assertStringContainsString('--label external-push', $workflow);
    // A failed run must tell someone; no other workflow here does.
    $this->assertStringContainsString('gh issue create', $workflow);
    $this->assertStringContainsString('issues: write', $workflow);
  }

  /**
   * Tests the Pantheon hooks that run whoever deploys remain registered.
   *
   * Checks in the deploy script only run when that script is used. These
   * hooks are what covers a dashboard deploy, a direct push to the Pantheon
   * remote, and a database clone.
   */
  public function testPantheonHooksCoverCodeChangesAndDatabaseClones(): void {
    $pantheon = self::readFile('pantheon.yml');

    foreach (['clone_database:', 'sync_code:', 'deploy:'] as $workflow) {
      $this->assertStringContainsString("\n  " . $workflow, $pantheon);
    }
    foreach (['reindex_search_after_clone.php', 'dispatch_site_smoke.php'] as $script) {
      $this->assertStringContainsString('script: private/scripts/' . $script, $pantheon);
      $this->assertFileExists(self::repoRoot() . '/web/private/scripts/' . $script);
    }
    // The scripts must not be reachable over the web.
    $this->assertStringContainsString('- /private/', self::readFile('pantheon.upstream.yml'));

    $dispatch = self::readFile('web/private/scripts/dispatch_site_smoke.php');
    $this->assertStringContainsString("'event_type' => 'pantheon-deploy'", $dispatch);
    $this->assertStringContainsString('types: [pantheon-deploy]', self::readFile('.github/workflows/site-smoke.yml'));
    // Who deployed is sent by name; the address stays on Pantheon.
    $this->assertStringNotContainsString('user_email', $dispatch);

    // A database clone must not be able to change the Solr site hash.
    $this->assertMatchesRegularExpression(
      "/if \\(_ilas_raw_pantheon_environment\\(\\)\\) \\{\\s+\\\$settings\\['search_api_solr\\.site_hash'\\] = '[a-z0-9]{6}';/",
      self::readFile('web/sites/default/settings.php'),
    );
  }

  /**
   * Package scripts must expose mocked smoke and full journey commands.
   */
  public function testPackageScriptsExposeAssistantPlaywrightSmokeAndJourneys(): void {
    $package = self::readFile('package.json');

    $this->assertStringContainsString('"test:assistant:playwright:smoke"', $package);
    $this->assertStringContainsString('assistant.pr-smoke.spec.js', $package);
    $this->assertStringContainsString('"test:assistant:playwright:journeys"', $package);
    $this->assertStringContainsString('journeys/', $package);
  }

  /**
   * Workflow triggers must cover all blocking branches including release.
   */
  public function testWorkflowTriggersCoverAllBlockingBranches(): void {
    $workflow = self::readFile('.github/workflows/quality-gate.yml');

    // Push section must include all blocking branches.
    $pushPos = strpos($workflow, 'push:');
    $this->assertNotFalse($pushPos, 'push trigger must exist in workflow');

    // pull_request section must include release/** for blocking branch coverage.
    $prPos = strpos($workflow, 'pull_request:');
    $this->assertNotFalse($prPos, 'pull_request trigger must exist in workflow');

    $dispatchPos = strpos($workflow, 'workflow_dispatch:');
    $this->assertNotFalse($dispatchPos, 'workflow_dispatch trigger must exist in workflow');

    $pushSection = substr($workflow, (int) $pushPos, (int) $prPos - (int) $pushPos);
    $pullRequestSection = substr($workflow, (int) $prPos, (int) $dispatchPos - (int) $prPos);
    $this->assertStringContainsString('- master', $pushSection, 'push trigger must include master');
    $this->assertStringContainsString("'release/**'", $pushSection, 'push trigger must include release/**');
    $this->assertStringNotContainsString('- main', $pushSection, 'push trigger must not include main');

    $this->assertStringContainsString('- master', $pullRequestSection, 'pull_request trigger must include master');
    $this->assertStringContainsString("'release/**'", $pullRequestSection, 'pull_request trigger must include release/**');
    $this->assertStringNotContainsString('- main', $pullRequestSection, 'pull_request trigger must not include main');

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

    $this->assertStringContainsString('quality-gate', $workflow);
    $this->assertStringContainsString('assistant-pr-gate', $workflow);
    $this->assertStringContainsString('hosted-manual-gate', $workflow);
  }

  /**
   * Documentation must declare CI quality gate mandatory with enforcement.
   */
  public function testDocumentationDeclaresGateMandatory(): void {
    $currentState = self::readFile('docs/aila/current-state.md');

    $this->assertStringContainsString(
      '### Phase 1 Exit #2 Mandatory Gate Disposition (2026-03-03)',
      $currentState,
      'current-state.md must retain the mandatory gate disposition section'
    );
    $this->assertStringContainsString(
      'mandatory for merge/release path',
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
