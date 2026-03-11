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
   * Runbook must preserve the protected-master publish workflow.
   */
  public function testRunbookContainsProtectedMasterPublishCommands(): void {
    $runbook = self::readFile('docs/aila/runbook.md');
    $package = self::readFile('package.json');

    $this->assertStringContainsString('bash scripts/ci/install-pre-push-strict-hook.sh', $runbook);
    $this->assertStringContainsString('git status --short --branch', $runbook);
    $this->assertStringContainsString('npm run git:publish', $runbook);
    $this->assertStringContainsString('npm run git:finish', $runbook);
    $this->assertStringContainsString('npm run git:sync-master', $runbook);
    $this->assertStringContainsString('npm run git:publish -- --origin-only', $runbook);
    $this->assertStringContainsString('do not wait on stale PR', $runbook);
    $this->assertStringContainsString('terminus env:code-log idaho-legal-aid-services.dev --format=table', $runbook);
    $this->assertStringContainsString('PR-branch publishes from local `master` are advisory locally', $runbook);
    $this->assertStringContainsString('skips local promptfoo and trusts the already-passed', $runbook);
    $this->assertStringContainsString('GitHub `Promptfoo Gate` for that commit.', $runbook);
    $this->assertStringContainsString('promotion to Pantheon `test` and `live` is a separate deployment', $runbook);
    $this->assertStringContainsString('"git:finish": "bash scripts/git/finish.sh"', $package);
  }

  /**
   * Strict pre-push scripts must exist and wire required gate commands.
   */
  public function testStrictPrePushScriptsExistAndContainRequiredCommands(): void {
    $strictHook = self::readFile('scripts/ci/pre-push-strict.sh');
    $installer = self::readFile('scripts/ci/install-pre-push-strict-hook.sh');
    $publishHelper = self::readFile('scripts/git/publish.sh');
    $finishHelper = self::readFile('scripts/git/finish.sh');

    $this->assertStringContainsString('scripts/git/common.sh', $strictHook);
    $this->assertStringContainsString('scripts/git/sync-check.sh', $strictHook);
    $this->assertStringContainsString('Direct pushes to protected github/master are not supported', $strictHook);
    $this->assertStringContainsString('Refusing to push origin/master before github/master matches local master', $strictHook);
    $this->assertStringContainsString('Use: npm run git:publish', $strictHook);
    $this->assertStringContainsString('run-quality-gate.sh', $strictHook);
    $this->assertStringContainsString('resolve_promptfoo_branch', $strictHook);
    $this->assertStringContainsString('is_effective_target_branch', $strictHook);
    $this->assertStringContainsString('Promptfoo policy target branch', $strictHook);
    $this->assertStringContainsString('CI_BRANCH="$PROMPTFOO_BRANCH" bash scripts/ci/run-promptfoo-gate.sh --env dev --mode auto', $strictHook);
    $this->assertStringContainsString(
      'Skipping local promptfoo gate for origin/master: trusting GitHub Promptfoo Gate for this synced commit.',
      $strictHook
    );
    $this->assertStringNotContainsString('CI_BRANCH="$CURRENT_BRANCH" bash scripts/ci/run-promptfoo-gate.sh --env dev --mode auto', $strictHook);
    $this->assertStringContainsString('REMOTE_NAME', $strictHook);
    $this->assertStringContainsString('REMOTE_URL', $strictHook);

    $this->assertStringContainsString('.git/hooks/pre-push', $installer);
    $this->assertStringContainsString('pre-push-strict.sh', $installer);
    $this->assertStringContainsString('git status --short --branch', $installer);
    $this->assertStringContainsString('npm run git:publish', $installer);
    $this->assertStringContainsString('npm run git:finish', $installer);
    $this->assertStringContainsString('using the pushed target branch for blocking/advisory policy', $installer);
    $this->assertStringContainsString('trusts GitHub Promptfoo Gate for synced origin/master deploy pushes', $installer);
    $this->assertStringContainsString('Do not wait on stale PR numbers from earlier publishes.', $installer);
    $this->assertStringContainsString('git push --no-verify', $installer);

    $this->assertStringContainsString('publish/master-', $publishHelper);
    $this->assertStringContainsString('gh pr create', $publishHelper);
    $this->assertStringContainsString('npm run git:finish', $publishHelper);
    $this->assertStringContainsString('npm run git:sync-master', $publishHelper);
    $this->assertStringContainsString('npm run git:publish -- --origin-only', $publishHelper);

    $syncHelper = self::readFile('scripts/git/sync-master.sh');
    $this->assertStringContainsString('Syncing local master from github/master', $syncHelper);
    $this->assertStringContainsString('github/master does not yet include local master', $syncHelper);
    $this->assertStringContainsString('Pantheon is behind. Deploy with: npm run git:publish -- --origin-only', $syncHelper);

    $this->assertStringContainsString('gh pr checks', $finishHelper);
    $this->assertStringContainsString('gh pr merge', $finishHelper);
    $this->assertStringContainsString('sync-master.sh', $finishHelper);
    $this->assertStringContainsString('publish.sh" --origin-only', $finishHelper);
    $this->assertStringContainsString('Commit or stash them before running npm run git:finish.', $finishHelper);
  }

}
