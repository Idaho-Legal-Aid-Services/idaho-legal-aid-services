<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Guards strict dual-remote push workflow documentation and scripts.
 */
#[Group('ilas_site_assistant')]
final class PushWorkflowGuardTest extends TestCase {

  /**
   * Returns repository root.
   */
  private static function repoRoot(): string {
    // __DIR__ = <repo>/web/modules/custom/ilas_site_assistant/tests/src/Unit
    return dirname(__DIR__, 7);
  }

  /**
   * Reads a repository file with existence checks.
   */
  private static function readFile(string $relativePath): string {
    $path = self::repoRoot() . '/' . ltrim($relativePath, '/');
    self::assertFileExists($path, "Expected file does not exist: {$relativePath}");

    $contents = file_get_contents($path);
    self::assertIsString($contents, "Failed reading file: {$relativePath}");
    return $contents;
  }

  /**
   * Returns a normalized runbook excerpt for promptfoo publish guidance.
   */
  private static function readPromptfooRunbookSection(): string {
    return self::readFile('docs/aila/runbook.md');
  }

  /**
   * Runbook must preserve the protected-master publish workflow.
   */
  public function testRunbookContainsProtectedMasterPublishCommands(): void {
    $runbook = self::readPromptfooRunbookSection();
    $package = self::readFile('package.json');

    $this->assertStringContainsString('bash scripts/ci/install-pre-push-strict-hook.sh', $runbook);
    $this->assertStringContainsString('.git/hooks/pre-commit', $runbook);
    $this->assertStringContainsString('The pre-commit hook runs only on local `master`', $runbook);
    $this->assertStringContainsString('`github/master` is ahead or diverged', $runbook);
    $this->assertStringContainsString('git status --short --branch', $runbook);
    $this->assertStringContainsString('git branch backup/recovery-<timestamp> master', $runbook);
    $this->assertStringContainsString('npm run git:publish', $runbook);
    $this->assertStringContainsString('npm run git:finish', $runbook);
    $this->assertStringContainsString('npm run git:sync-master', $runbook);
    $this->assertStringContainsString('npm run git:publish -- --origin-only', $runbook);
    $this->assertStringContainsString('do not wait on stale PR', $runbook);
    $this->assertStringContainsString('terminus env:code-log idaho-legal-aid-services.dev --format=table', $runbook);
    $this->assertStringContainsString('PR-branch publishes from local `master` are advisory locally', $runbook);
    $this->assertStringContainsString('helper publish PRs are blocking on GitHub', $runbook);
    $this->assertStringContainsString('downloads the `gate-summary.txt` artifact before merging', $runbook);
    $this->assertStringContainsString('runs the local DDEV deploy-bound Promptfoo gate before the Pantheon push', $runbook);
    $this->assertStringContainsString('hosted GitHub check is not treated as deploy proof for `origin/master`', $runbook);
    $this->assertStringContainsString('composer install --no-interaction --no-progress --prefer-dist --dry-run', $runbook);
    $this->assertStringContainsString('GitHub `Install Composer dependencies` step', $runbook);
    $this->assertStringContainsString('promotion to Pantheon `test` and `live` is a separate deployment', $runbook);
    $this->assertStringContainsString('"git:finish": "bash scripts/git/finish.sh"', $package);
  }

  /**
   * Strict pre-push scripts must exist and wire required gate commands.
   */
  public function testStrictPrePushScriptsExistAndContainRequiredCommands(): void {
    $preCommitHook = self::readFile('scripts/ci/pre-commit-master-sync.sh');
    $strictHook = self::readFile('scripts/ci/pre-push-strict.sh');
    $installer = self::readFile('scripts/ci/install-pre-push-strict-hook.sh');
    $publishHelper = self::readFile('scripts/git/publish.sh');
    $finishHelper = self::readFile('scripts/git/finish.sh');
    $syncHelper = self::readFile('scripts/git/sync-master.sh');

    $this->assertStringContainsString('fetch_remote "github"', $preCommitHook);
    $this->assertStringContainsString('describe_remote_status "github" "$CURRENT_BRANCH"', $preCommitHook);
    $this->assertStringContainsString('Local master is stale; github/master is ahead by', $preCommitHook);
    $this->assertStringContainsString('Local master diverged from github/master.', $preCommitHook);
    $this->assertStringContainsString('Run: npm run git:sync-master', $preCommitHook);
    $this->assertStringContainsString('git cherry-pick <local-master-commit>', $preCommitHook);
    $this->assertStringContainsString('github/master is required for commits on local master.', $preCommitHook);

    $this->assertStringContainsString('scripts/git/common.sh', $strictHook);
    $this->assertStringContainsString('scripts/git/sync-check.sh', $strictHook);
    $this->assertStringContainsString('Direct pushes to protected github/master are not supported', $strictHook);
    $this->assertStringContainsString('Refusing to push origin/master before github/master matches local master', $strictHook);
    $this->assertStringContainsString('Use: npm run git:publish', $strictHook);
    $this->assertStringContainsString('Running Composer installability parity check...', $strictHook);
    $this->assertStringContainsString('composer install --no-interaction --no-progress --prefer-dist --dry-run', $strictHook);
    $this->assertStringContainsString("mirrors the GitHub 'Install Composer dependencies' step", $strictHook);
    $this->assertStringContainsString('Running PHPUnit pure-unit parity gate (VC-PURE)...', $strictHook);
    $this->assertStringContainsString('vendor/bin/phpunit -c phpunit.pure.xml --colors=always', $strictHook);
    $this->assertStringContainsString("mirrors the GitHub 'Run PHPUnit pure-unit tests (VC-PURE)' step", $strictHook);
    $this->assertStringContainsString('run-quality-gate.sh', $strictHook);
    $this->assertStringContainsString('resolve_promptfoo_branch', $strictHook);
    $this->assertStringContainsString('is_effective_target_branch', $strictHook);
    $this->assertStringContainsString('Promptfoo policy target branch', $strictHook);
    $this->assertStringContainsString('CI_BRANCH="$PROMPTFOO_BRANCH" bash scripts/ci/run-promptfoo-gate.sh --env dev --mode auto', $strictHook);
    $this->assertStringContainsString('promptfooconfig.deploy.yaml', $strictHook);
    $this->assertStringContainsString('--no-deep-eval', $strictHook);
    $this->assertStringContainsString(
      'Running deploy-bound promptfoo gate for origin/master against local DDEV exact code...',
      $strictHook
    );
    $this->assertStringNotContainsString('CI_BRANCH="$CURRENT_BRANCH" bash scripts/ci/run-promptfoo-gate.sh --env dev --mode auto', $strictHook);
    $this->assertStringContainsString('REMOTE_NAME', $strictHook);
    $this->assertStringContainsString('REMOTE_URL', $strictHook);

    $this->assertStringContainsString('.git/hooks/pre-push', $installer);
    $this->assertStringContainsString('.git/hooks/pre-commit', $installer);
    $this->assertStringContainsString('pre-commit-master-sync.sh', $installer);
    $this->assertStringContainsString('pre-push-strict.sh', $installer);
    $this->assertStringContainsString('git status --short --branch', $installer);
    $this->assertStringContainsString('npm run git:sync-master', $installer);
    $this->assertStringContainsString('npm run git:publish', $installer);
    $this->assertStringContainsString('npm run git:finish', $installer);
    $this->assertStringContainsString('composer install --no-interaction --no-progress --prefer-dist --dry-run', $installer);
    $this->assertStringContainsString("mirroring the GitHub 'Install Composer dependencies' step", $installer);
    $this->assertStringContainsString('vendor/bin/phpunit -c phpunit.pure.xml --colors=always', $installer);
    $this->assertStringContainsString("mirroring the GitHub 'Run PHPUnit pure-unit tests (VC-PURE)' step", $installer);
    $this->assertStringContainsString('using the pushed target branch for blocking/advisory policy', $installer);
    $this->assertStringContainsString('requires local DDEV exact-code evals for synced origin/master deploy pushes', $installer);
    $this->assertStringContainsString('Do not wait on stale PR numbers from earlier publishes.', $installer);
    $this->assertStringContainsString('git push --no-verify', $installer);

    $this->assertStringContainsString('publish/master-', $publishHelper);
    $this->assertStringContainsString('gh pr create', $publishHelper);
    $this->assertStringContainsString('npm run git:finish', $publishHelper);
    $this->assertStringContainsString('npm run git:sync-master', $publishHelper);
    $this->assertStringContainsString('preserve and restack them onto github/master', $publishHelper);
    $this->assertStringContainsString('npm run git:publish -- --origin-only', $publishHelper);
    $this->assertStringContainsString('through the local DDEV deploy gate', $publishHelper);

    $this->assertStringContainsString('Syncing local master from github/master', $syncHelper);
    $this->assertStringContainsString('github/master does not yet include local master', $syncHelper);
    $this->assertStringContainsString('git branch backup/recovery-<timestamp> master', $syncHelper);
    $this->assertStringContainsString('After restacking, rerun: npm run git:sync-master', $syncHelper);
    $this->assertStringContainsString('Pantheon is behind. Deploy with: npm run git:publish -- --origin-only', $syncHelper);

    $this->assertStringContainsString('gh pr checks', $finishHelper);
    $this->assertStringContainsString('gh run download', $finishHelper);
    $this->assertStringContainsString('gh pr merge', $finishHelper);
    $this->assertStringContainsString('gh run list --workflow "Quality Gate" --event push', $finishHelper);
    $this->assertStringContainsString('gh run watch', $finishHelper);
    $this->assertStringContainsString('gate-summary.txt', $finishHelper);
    $this->assertStringContainsString('failure_kind', $finishHelper);
    $this->assertStringContainsString('eval_execution_mode', $finishHelper);
    $this->assertStringContainsString('mode=$mode', $finishHelper);
    $this->assertStringContainsString('sync-master.sh', $finishHelper);
    $this->assertStringContainsString('publish.sh" --origin-only', $finishHelper);
    $this->assertStringContainsString('Refusing to deploy Pantheon dev while master is red.', $finishHelper);
    $this->assertStringContainsString('deploying origin/master through the local DDEV deploy gate', $finishHelper);
    $this->assertStringContainsString('Commit or stash them before running npm run git:finish.', $finishHelper);
  }

}
