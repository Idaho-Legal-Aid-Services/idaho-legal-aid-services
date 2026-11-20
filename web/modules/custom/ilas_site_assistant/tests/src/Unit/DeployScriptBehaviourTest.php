<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Runs the deploy command against a stand-in for Pantheon.
 *
 * The other tests of scripts/deploy/pantheon-deploy.sh only assert that
 * certain text is in the file, which a script that does the wrong thing in
 * the wrong order also satisfies. Here the real script runs, with a fake
 * `terminus` that records every call and answers from a scenario: whether
 * Pantheon is still rebuilding the environment, which commit it records, and
 * what is on the "server". The fingerprint the server returns comes from the
 * real scripts/deploy/code-fingerprint.php run in the server's directory.
 */
#[Group('ilas_site_assistant')]
final class DeployScriptBehaviourTest extends TestCase {

  /**
   * Directory holding everything for one test.
   */
  private string $base;

  /**
   * The maintainer's checkout, from which the deploy command runs.
   */
  private string $work;

  /**
   * The files on the Pantheon environment.
   */
  private string $server;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    foreach (['git', 'bash'] as $command) {
      if (trim((string) shell_exec('command -v ' . $command)) === '') {
        $this->markTestSkipped($command . ' is required.');
      }
    }

    $this->base = sys_get_temp_dir() . '/ilas-deploy-' . bin2hex(random_bytes(6));
    $this->work = $this->base . '/work';
    $this->server = $this->base . '/server';
    $source = dirname(__DIR__, 7) . '/scripts/deploy';

    $this->write($this->work . '/scripts/deploy/pantheon-deploy.sh', (string) file_get_contents($source . '/pantheon-deploy.sh'));
    $this->write($this->work . '/scripts/deploy/code-fingerprint.php', (string) file_get_contents($source . '/code-fingerprint.php'));
    $this->write($this->work . '/web/modules/custom/demo/demo.module', "<?php\n// today\n");
    $this->write($this->work . '/composer.lock', (string) json_encode([
      'packages' => [['name' => 'drupal/core', 'version' => '11.4.13']],
    ]));
    $this->write($this->work . '/.gitignore', "vendor/\n");

    $this->shell('git init -q -b master', $this->work);
    $this->shell('git config user.name Tester && git config user.email tester@example.test && git config commit.gpgsign false', $this->work);
    $this->shell('git add -A && git commit -q --no-verify -m "release"', $this->work);
    foreach (['github', 'origin'] as $remote) {
      $this->shell('git init -q --bare -b master ' . escapeshellarg($this->base . '/' . $remote . '.git'));
      $this->shell('git remote add ' . $remote . ' ' . escapeshellarg($this->base . '/' . $remote . '.git') . ' && git push -q ' . $remote . ' master', $this->work);
    }

    // The environment starts as a faithful copy of the commit, with the
    // packages composer.lock names.
    $this->shell('git archive HEAD | tar -x -C ' . escapeshellarg($this->makeDirectory($this->server)), $this->work);
    $this->serverPackages('11.4.13');

    $this->write($this->base . '/bin/terminus', $this->fakeTerminus());
    chmod($this->base . '/bin/terminus', 0755);
    $this->scenario('code_log', $this->head() . "\n");
    $this->scenario('workflows', "dev,succeeded\ntest,succeeded\nlive,succeeded\n");
    $this->scenario('running_polls', "0\n");
    $this->scenario('config_status', " [notice] No differences between DB and sync directory.\n");
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
   * The stand-in for the terminus command.
   */
  private function fakeTerminus(): string {
    return <<<'BASH'
#!/usr/bin/env bash
# Records the call, then answers from the scenario files in $FAKE.
printf '%s\n' "$*" >> "$FAKE/calls.log"
case "$1" in
  auth:whoami) echo "tester" ;;
  env:wake|env:deploy|backup:create) ;;
  workflow:list)
    left="$(cat "$FAKE/running_polls")"
    if (( left > 0 )); then
      echo $((left - 1)) > "$FAKE/running_polls"
      echo "${FAKE_ENV},running"
    fi
    cat "$FAKE/workflows"
    ;;
  env:code-log) cat "$FAKE/code_log" ;;
  drush)
    shift 3
    case "$1" in
      php:script)
        # Drush runs in the site root and looks for the script there.
        script="${2#/code/}"
        if [[ ! -f "$FAKE_SERVER/$script" ]]; then
          echo "Unable to find any of the following: $2" >&2
          exit 1
        fi
        php "$FAKE_SERVER/$script"
        ;;
      updatedb:status) echo " [success] No database updates required." ;;
      config:status) cat "$FAKE/config_status" ;;
      deploy) ;;
    esac
    ;;
esac
BASH;
  }

  /**
   * Writes a file, creating its directory.
   */
  private function write(string $path, string $contents): void {
    $this->makeDirectory(dirname($path));
    file_put_contents($path, $contents);
  }

  /**
   * Creates a directory if needed and returns its path.
   */
  private function makeDirectory(string $path): string {
    if (!is_dir($path)) {
      mkdir($path, 0777, TRUE);
    }
    return $path;
  }

  /**
   * Sets one scenario file read by the fake terminus.
   */
  private function scenario(string $name, string $contents): void {
    $this->write($this->base . '/fake/' . $name, $contents);
  }

  /**
   * Sets the drupal/core version installed on the server.
   */
  private function serverPackages(string $coreVersion): void {
    $this->write($this->server . '/vendor/composer/installed.json', (string) json_encode([
      'packages' => [['name' => 'drupal/core', 'version' => $coreVersion]],
    ]));
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
    exec($prefix . '{ ' . $command . '; } 2>&1', $lines, $status);

    return [$status, implode("\n", $lines)];
  }

  /**
   * Returns the commit the checkout is on.
   */
  private function head(): string {
    return trim($this->shell('git rev-parse HEAD', $this->work)[1]);
  }

  /**
   * Runs the deploy command.
   *
   * @return array{0: int, 1: string}
   *   The exit status and the combined output.
   */
  private function deploy(string $arguments, string $environment = 'dev'): array {
    $variables = [
      'PATH' => $this->base . '/bin:' . getenv('PATH'),
      'FAKE' => $this->base . '/fake',
      'FAKE_ENV' => $environment,
      'FAKE_SERVER' => $this->server,
      'ILAS_DEPLOY_WORKFLOW_POLL_SECONDS' => '0',
      'ILAS_DEPLOY_RECHECK_SECONDS' => '0',
    ];
    $prefix = 'env -u REPO_ROOT -u GIT_DIR -u GIT_WORK_TREE';
    foreach ($variables as $name => $value) {
      $prefix .= ' ' . $name . '=' . escapeshellarg($value);
    }

    return $this->shell($prefix . ' bash scripts/deploy/pantheon-deploy.sh ' . $arguments, $this->work);
  }

  /**
   * Returns the terminus calls made so far, one per entry.
   *
   * @return string[]
   *   The calls, in order.
   */
  private function calls(): array {
    $log = $this->base . '/fake/calls.log';

    return is_file($log) ? array_values(array_filter(explode("\n", (string) file_get_contents($log)))) : [];
  }

  /**
   * Returns the position of the first call containing the given text.
   */
  private function position(string $needle): int {
    foreach ($this->calls() as $index => $call) {
      if (str_contains($call, $needle)) {
        return $index;
      }
    }
    $this->fail("No terminus call contains '{$needle}'. Calls:\n" . implode("\n", $this->calls()));
  }

  /**
   * Asserts that the deploy pipeline was never started on the environment.
   */
  private function assertNothingWasApplied(): void {
    foreach ($this->calls() as $call) {
      $this->assertStringNotContainsString('-- deploy -y', $call, 'Database updates and config import must not run on unproven code.');
    }
  }

  /**
   * A good dev deploy: Pantheon idle, code proven, then applied and checked.
   */
  public function testDevDeployProvesTheCodeBeforeApplyingIt(): void {
    [$status, $output] = $this->deploy('--env dev --skip-smoke');

    $this->assertSame(0, $status, $output);
    $this->assertStringContainsString('source files match', $output);
    $this->assertStringContainsString('packages match composer.lock (drupal/core 11.4.13)', $output);
    $this->assertLessThan($this->position('php:script'), $this->position('workflow:list'));
    $this->assertLessThan($this->position('-- deploy -y'), $this->position('php:script'));
    $this->assertLessThan($this->position('config:status'), $this->position('-- deploy -y'));
    // Dev receives code by git push; nothing is promoted.
    $this->assertStringNotContainsString('env:deploy', implode("\n", $this->calls()));
  }

  /**
   * Pantheon is still rebuilding dev after the push: wait, then check.
   *
   * This is the state a deploy meets seconds after `git push`, and the one
   * the first real run of the fingerprint check failed on (2026-11-20).
   */
  public function testWaitsWhilePantheonIsStillRebuildingTheEnvironment(): void {
    $this->scenario('running_polls', "3\n");

    [$status, $output] = $this->deploy('--env dev --skip-smoke');

    $this->assertSame(0, $status, $output);
    $this->assertStringContainsString('Pantheon is still working on dev', $output);
    $calls = $this->calls();
    $firstCheck = $this->position('php:script');
    $polls = array_filter(array_slice($calls, 0, $firstCheck), static fn (string $call): bool => str_starts_with($call, 'workflow:list'));
    $this->assertCount(4, $polls, 'Three polls saw it running; the fourth saw it idle.');
  }

  /**
   * The server never gets the new code: looked at three times, then stop.
   */
  public function testServerWithoutTheScriptIsRecheckedThenStopsTheDeploy(): void {
    rename($this->server . '/scripts', $this->base . '/scripts-not-deployed');

    [$status, $output] = $this->deploy('--env dev --skip-smoke');

    $this->assertSame(1, $status, $output);
    $this->assertStringContainsString('The environment did not return a fingerprint.', $output);
    $this->assertStringContainsString('check 1 of 3', $output);
    $this->assertStringContainsString('check 2 of 3', $output);
    $this->assertStringContainsString('is not running commit', $output);
    $this->assertNothingWasApplied();
  }

  /**
   * Yesterday's code on the server (dev, 2026-11-19): stop before applying.
   */
  public function testOldCodeOnTheServerStopsTheDeploy(): void {
    $this->write($this->server . '/web/modules/custom/demo/demo.module', "<?php\n// yesterday\n");

    [$status, $output] = $this->deploy('--env dev --skip-smoke');

    $this->assertSame(1, $status, $output);
    $this->assertStringContainsString('Source files differ from the commit in: web/modules/custom/demo', $output);
    $this->assertStringContainsString('Nothing has been applied by this run.', $output);
    $this->assertNothingWasApplied();
  }

  /**
   * New code with old packages (live, 2026-11-19): stop before applying.
   */
  public function testOldPackagesOnTheServerStopTheDeploy(): void {
    $this->serverPackages('11.4.12');

    [$status, $output] = $this->deploy('--env dev --skip-smoke');

    $this->assertSame(1, $status, $output);
    $this->assertStringContainsString('drupal/core: locked 11.4.13, installed 11.4.12', $output);
    $this->assertNothingWasApplied();
  }

  /**
   * A failed Pantheon workflow is reported; the files decide the outcome.
   */
  public function testFailedPantheonWorkflowIsReportedAndTheFilesDecide(): void {
    $this->scenario('workflows', "dev,failed\n");

    [$status, $output] = $this->deploy('--env dev --skip-smoke');
    $this->assertSame(0, $status, $output);
    $this->assertStringContainsString('The most recent Pantheon workflow on dev failed', $output);

    $this->write($this->server . '/web/modules/custom/demo/demo.module', "<?php\n// yesterday\n");
    [$status, $output] = $this->deploy('--env dev --skip-smoke');
    $this->assertSame(1, $status, $output);
  }

  /**
   * Test: promote first, then prove, then apply; drift is fatal there.
   */
  public function testTestDeployPromotesThenProvesThenApplies(): void {
    // On test the newest recorded commit is Pantheon's own build commit.
    $this->scenario('code_log', str_repeat('a', 40) . "\n" . $this->head() . "\n");

    [$status, $output] = $this->deploy('--env test --skip-smoke', 'test');
    $this->assertSame(0, $status, $output);
    $this->assertLessThan($this->position('php:script'), $this->position('env:deploy'));
    $this->assertLessThan($this->position('-- deploy -y'), $this->position('php:script'));

    $this->scenario('config_status', "  system.site   Different\n");
    [$status, $output] = $this->deploy('--env test --skip-smoke --skip-promote', 'test');
    $this->assertSame(1, $status, $output);
    $this->assertStringContainsString('still differs from the repository', $output);
  }

  /**
   * A dry run contacts Pantheon for nothing.
   */
  public function testDryRunCallsNothing(): void {
    [$status, $output] = $this->deploy('--env live --dry-run', 'live');

    $this->assertSame(0, $status, $output);
    $this->assertSame([], $this->calls());
  }

  /**
   * The alignment check reads everything, changes nothing, and is honest.
   */
  public function testAlignmentCheckReportsAlignedAndNotAligned(): void {
    $this->scenario('code_log', $this->head() . "\n");

    [$status, $output] = $this->deploy('--check');
    $this->assertSame(0, $status, $output);
    $this->assertStringContainsString('Aligned: this checkout, GitHub, Pantheon and all three environments', $output);
    foreach ($this->calls() as $call) {
      $this->assertDoesNotMatchRegularExpression('/env:deploy|-- deploy|backup:create|env:wake/', $call);
    }

    // GitHub is behind, and the server has old packages.
    $this->write($this->work . '/web/modules/custom/demo/demo.module', "<?php\n// tomorrow\n");
    $this->shell('git commit -q --no-verify -am "next" && git push -q origin master', $this->work);
    $this->serverPackages('11.4.12');

    [$status, $output] = $this->deploy('--check');
    $this->assertSame(1, $status, $output);
    $this->assertMatchesRegularExpression('/github master\s+\w{10}\s+<-- differs from this checkout/', $output);
    $this->assertStringContainsString('<-- NOT the recorded commit', $output);
    $this->assertStringContainsString('Not aligned', $output);
  }

}
