<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Runs the command that adopts commits pushed straight to Pantheon.
 *
 * The case that matters is the one that really happens: someone pushes to
 * Pantheon while GitHub and the local checkout hold work Pantheon does not
 * have yet. The command has to end with both sides in local master, without
 * rewriting any commit and without pushing anything.
 */
#[Group('ilas_site_assistant')]
final class ReconcileOriginScriptTest extends TestCase {

  /**
   * Directory holding the throwaway repositories.
   */
  private string $base;

  /**
   * The maintainer's checkout.
   */
  private string $work;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    if (trim((string) shell_exec('command -v git')) === '' || trim((string) shell_exec('command -v bash')) === '') {
      $this->markTestSkipped('git and bash are required.');
    }

    $this->base = sys_get_temp_dir() . '/ilas-reconcile-' . bin2hex(random_bytes(6));
    $this->work = $this->base . '/work';
    mkdir($this->work . '/scripts/git', 0777, TRUE);
    $scripts = dirname(__DIR__, 7) . '/scripts/git';
    foreach (['reconcile-origin.sh', 'common.sh'] as $script) {
      copy($scripts . '/' . $script, $this->work . '/scripts/git/' . $script);
    }
    file_put_contents($this->work . '/composer.lock', "lock 1\n");
    file_put_contents($this->work . '/settings.yml', "value: 1\n");

    // Two bare repositories stand in for GitHub and Pantheon.
    $this->shell('git init -q --bare -b master ' . escapeshellarg($this->base . '/github.git'));
    $this->shell('git init -q --bare -b master ' . escapeshellarg($this->base . '/pantheon.git'));
    $this->git($this->work, 'init -q -b master');
    $this->identify($this->work);
    $this->git($this->work, 'remote add github ' . escapeshellarg($this->base . '/github.git'));
    $this->git($this->work, 'remote add origin ' . escapeshellarg($this->base . '/pantheon.git'));
    $this->commit($this->work, 'initial');
    $this->git($this->work, 'push -q github master');
    $this->git($this->work, 'push -q origin master');
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    if (isset($this->base) && is_dir($this->base)) {
      shell_exec('rm -rf ' . escapeshellarg($this->base));
    }
    parent::tearDown();
  }

  /**
   * Runs a shell command and returns its exit status and output.
   *
   * @return array{0: int, 1: string}
   *   The exit status and the combined output.
   */
  private function shell(string $command, ?string $cwd = NULL): array {
    $lines = [];
    $status = 0;
    $prefix = $cwd === NULL ? '' : 'cd ' . escapeshellarg($cwd) . ' && ';
    exec($prefix . 'env -u REPO_ROOT -u GIT_DIR -u GIT_WORK_TREE ' . $command . ' 2>&1', $lines, $status);

    return [$status, implode("\n", $lines)];
  }

  /**
   * Runs git in a repository and returns its trimmed output.
   */
  private function git(string $repo, string $arguments): string {
    return trim($this->shell('git ' . $arguments, $repo)[1]);
  }

  /**
   * Gives a repository a committer identity.
   */
  private function identify(string $repo): void {
    $this->git($repo, 'config user.name Tester');
    $this->git($repo, 'config user.email tester@example.test');
    $this->git($repo, 'config commit.gpgsign false');
  }

  /**
   * Commits everything in a repository.
   */
  private function commit(string $repo, string $message): void {
    $this->git($repo, 'add -A');
    $this->git($repo, 'commit -q --no-verify -m ' . escapeshellarg($message));
  }

  /**
   * Publishes a local commit to GitHub only, as a merged pull request would.
   */
  private function localWorkOnGitHubOnly(string $file, string $contents): void {
    file_put_contents($this->work . '/' . $file, $contents);
    $this->commit($this->work, 'our work');
    $this->git($this->work, 'push -q github master');
  }

  /**
   * Pushes a commit to Pantheon from somewhere else, bypassing GitHub.
   */
  private function agencyPushesToPantheon(string $file, string $contents): void {
    $agency = $this->base . '/agency';
    if (!is_dir($agency)) {
      $this->shell('git clone -q ' . escapeshellarg($this->base . '/pantheon.git') . ' ' . escapeshellarg($agency));
      $this->identify($agency);
    }
    file_put_contents($agency . '/' . $file, $contents);
    $this->commit($agency, 'Maintenance.');
    $this->git($agency, 'push -q origin master');
  }

  /**
   * Runs the reconcile command in the maintainer's checkout.
   *
   * @return array{0: int, 1: string}
   *   The exit status and the combined output.
   */
  private function reconcile(string $arguments = ''): array {
    return $this->shell('bash scripts/git/reconcile-origin.sh ' . $arguments, $this->work);
  }

  /**
   * The real case: Pantheon and local both moved, on different files.
   */
  public function testDivergedHistoriesEndInOneMergeWithBothSides(): void {
    $this->localWorkOnGitHubOnly('settings.yml', "value: 2\n");
    $this->agencyPushesToPantheon('composer.lock', "lock 2\n");
    $ours = $this->git($this->work, 'rev-parse HEAD');

    [$status, $output] = $this->reconcile('--dry-run');
    $this->assertSame(0, $status, $output);
    $this->assertStringContainsString('Maintenance.', $output);
    $this->assertStringContainsString('composer.lock', $output);
    $this->assertStringContainsString('predicted to apply without conflicts', $output);
    $this->assertSame($ours, $this->git($this->work, 'rev-parse HEAD'), 'A dry run must not move master.');
    $this->assertSame('', $this->git($this->work, 'branch --list "backup/*"'), 'A dry run must not create branches.');

    [$status, $output] = $this->reconcile();
    $this->assertSame(0, $status, $output);

    // One merge commit whose parents are our commit and Pantheon's commit.
    $pantheon = $this->git($this->work, 'rev-parse origin/master');
    $this->assertSame($ours . ' ' . $pantheon, $this->git($this->work, 'log -1 --format=%P'));
    // Both sides' content is present and nothing is left uncommitted.
    $this->assertSame("value: 2\n", file_get_contents($this->work . '/settings.yml'));
    $this->assertSame("lock 2\n", file_get_contents($this->work . '/composer.lock'));
    $this->assertSame('', $this->git($this->work, 'status --porcelain'));
    // The state before the merge is kept, and nothing was pushed.
    $backup = $this->git($this->work, 'for-each-ref --format="%(objectname)" "refs/heads/backup/recovery-local-*"');
    $this->assertSame($ours, $backup);
    $this->assertSame($ours, $this->git($this->base . '/github.git', 'rev-parse master'));
    $this->assertSame($pantheon, $this->git($this->base . '/pantheon.git', 'rev-parse master'));
    // GitHub's master is an ancestor, so the result publishes as a normal PR.
    $this->assertSame(0, $this->shell('git merge-base --is-ancestor github/master master', $this->work)[0]);
  }

  /**
   * Pantheon moved and local did not: still a merge, same outcome.
   */
  public function testPantheonAheadOnlyIsAdoptedToo(): void {
    $this->agencyPushesToPantheon('composer.lock', "lock 2\n");

    [$status, $output] = $this->reconcile();
    $this->assertSame(0, $status, $output);
    $this->assertSame("lock 2\n", file_get_contents($this->work . '/composer.lock'));
    $this->assertSame(0, $this->shell('git merge-base --is-ancestor origin/master master', $this->work)[0]);
  }

  /**
   * Both sides changed the same file: predicted, reported and undoable.
   */
  public function testConflictIsPredictedReportedAndCanBeUndone(): void {
    $this->localWorkOnGitHubOnly('settings.yml', "value: ours\n");
    $this->agencyPushesToPantheon('settings.yml', "value: theirs\n");
    $ours = $this->git($this->work, 'rev-parse HEAD');

    [$status, $output] = $this->reconcile('--dry-run');
    $this->assertSame(0, $status, $output);
    $this->assertStringContainsString('predicted to conflict', $output);
    $this->assertStringContainsString('settings.yml', $output);

    [$status, $output] = $this->reconcile();
    $this->assertSame(1, $status, $output);
    $this->assertStringContainsString('The merge stopped on conflicts in:', $output);
    $this->assertStringContainsString('settings.yml', $output);
    $this->assertStringContainsString('git merge --abort', $output);

    $this->git($this->work, 'merge --abort');
    $this->assertSame($ours, $this->git($this->work, 'rev-parse HEAD'));
    $this->assertSame("value: ours\n", file_get_contents($this->work . '/settings.yml'));
    $this->assertSame('', $this->git($this->work, 'status --porcelain'));
  }

  /**
   * With nothing new on Pantheon the command does nothing.
   */
  public function testInSyncChangesNothing(): void {
    $head = $this->git($this->work, 'rev-parse HEAD');

    [$status, $output] = $this->reconcile();
    $this->assertSame(0, $status, $output);
    $this->assertStringContainsString('no reconciliation is needed', $output);
    $this->assertSame($head, $this->git($this->work, 'rev-parse HEAD'));
  }

}
