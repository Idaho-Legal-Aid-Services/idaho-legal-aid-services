<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Tests the fingerprint the deploy command uses to prove what is deployed.
 *
 * The fingerprint has to be the same whether it is computed from git for a
 * commit or from that commit's files on a server, and different as soon as
 * the server has anything else. It also has to notice installed packages
 * that do not match composer.lock, which is the state live was left in when
 * a Pantheon deploy failed part-way on 2026-11-19.
 */
#[Group('ilas_site_assistant')]
final class DeployCodeFingerprintTest extends TestCase {

  /**
   * A throwaway checkout.
   */
  private string $repo;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    if (trim((string) shell_exec('command -v git')) === '') {
      $this->markTestSkipped('git is required.');
    }

    $this->repo = sys_get_temp_dir() . '/ilas-fingerprint-' . bin2hex(random_bytes(6));
    $script = dirname(__DIR__, 7) . '/scripts/deploy/code-fingerprint.php';
    $files = [
      'scripts/deploy/code-fingerprint.php' => (string) file_get_contents($script),
      'web/modules/custom/demo/demo.module' => "<?php\n// demo\n",
      'web/modules/custom/demo/demo.info.yml' => "name: Demo\n",
      'web/modules/custom/demo/js/demo.js' => "// not fingerprinted\n",
      'config/system.site.yml' => "name: Site\n",
      'pantheon.yml' => "api_version: 1\n",
      'composer.lock' => json_encode([
        'packages' => [
          ['name' => 'drupal/core', 'version' => '11.4.13'],
          ['name' => 'drupal/ai', 'version' => '1.5.3'],
        ],
        'packages-dev' => [['name' => 'phpunit/phpunit', 'version' => '11.5.58']],
      ]),
    ];
    foreach ($files as $path => $contents) {
      $this->write($path, $contents);
    }
    $this->writeInstalled(['drupal/core' => '11.4.13', 'drupal/ai' => '1.5.3']);
    // vendor/ is never part of the repository.
    $this->write('.gitignore', "vendor/\n");

    $this->git('init -q');
    $this->git('add -A');
    $this->git('-c user.name=t -c user.email=t@example.test commit -q -m initial');
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    if (isset($this->repo) && is_dir($this->repo)) {
      shell_exec('rm -rf ' . escapeshellarg($this->repo));
    }
    parent::tearDown();
  }

  /**
   * Writes a file inside the throwaway checkout.
   */
  private function write(string $path, string $contents): void {
    $full = $this->repo . '/' . $path;
    if (!is_dir(dirname($full))) {
      mkdir(dirname($full), 0777, TRUE);
    }
    file_put_contents($full, $contents);
  }

  /**
   * Writes vendor/composer/installed.json with the given versions.
   *
   * @param array<string, string> $versions
   *   Installed versions keyed by package name.
   */
  private function writeInstalled(array $versions): void {
    $packages = [];
    foreach ($versions as $name => $version) {
      $packages[] = ['name' => $name, 'version' => $version];
    }
    $this->write('vendor/composer/installed.json', (string) json_encode(['packages' => $packages]));
  }

  /**
   * Runs git in the throwaway checkout.
   */
  private function git(string $arguments): void {
    shell_exec('git -C ' . escapeshellarg($this->repo) . ' ' . $arguments . ' 2>&1');
  }

  /**
   * Runs the fingerprint script in the throwaway checkout.
   *
   * @return array<string, mixed>
   *   The decoded report.
   */
  private function fingerprint(string $arguments = ''): array {
    $output = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($this->repo . '/scripts/deploy/code-fingerprint.php') . ' ' . $arguments);

    return json_decode((string) $output, TRUE, 512, JSON_THROW_ON_ERROR);
  }

  /**
   * A commit's files on disk and the commit in git give the same fingerprint.
   */
  public function testDiskAndGitAgreeForTheSameCommit(): void {
    $disk = $this->fingerprint();
    $git = $this->fingerprint('--git-ref=HEAD');

    $this->assertSame($git['fingerprint'], $disk['fingerprint']);
    // The script, the module file, two YAML files, pantheon.yml and the lock.
    $this->assertSame(6, $disk['files']);
    $this->assertSame(6, $git['files']);
    $this->assertTrue($disk['packages']['checked']);
    $this->assertTrue($disk['packages']['match']);
    $this->assertSame('11.4.13', $disk['packages']['core']);
  }

  /**
   * Old custom code on the server changes the fingerprint (dev's failure).
   */
  public function testStaleSourceFileChangesTheFingerprint(): void {
    $expected = $this->fingerprint('--git-ref=HEAD')['fingerprint'];

    $this->write('web/modules/custom/demo/demo.module', "<?php\n// yesterday's code\n");
    $this->assertNotSame($expected, $this->fingerprint()['fingerprint']);
  }

  /**
   * A mismatch names the part of the code that differs, and only that part.
   */
  public function testGroupsNameWhereTheDifferenceIs(): void {
    $expected = $this->fingerprint('--git-ref=HEAD')['groups'];
    $this->assertSame(
      ['composer.lock', 'config', 'pantheon.yml', 'scripts/deploy', 'web/modules/custom/demo'],
      array_keys($expected),
    );
    $this->assertSame($expected, $this->fingerprint()['groups']);

    $this->write('web/modules/custom/demo/demo.module', "<?php\n// yesterday's code\n");
    $actual = $this->fingerprint()['groups'];
    $this->assertSame(['web/modules/custom/demo'], array_keys(array_diff_assoc($actual, $expected)));
  }

  /**
   * A missing or extra source file changes the fingerprint.
   */
  public function testMissingOrExtraSourceFileChangesTheFingerprint(): void {
    $expected = $this->fingerprint('--git-ref=HEAD')['fingerprint'];

    $this->write('web/modules/custom/demo/src/New.php', "<?php\n");
    $this->assertNotSame($expected, $this->fingerprint()['fingerprint']);

    unlink($this->repo . '/web/modules/custom/demo/src/New.php');
    unlink($this->repo . '/config/system.site.yml');
    $this->assertNotSame($expected, $this->fingerprint()['fingerprint']);
  }

  /**
   * Files outside the fingerprint do not affect it.
   */
  public function testBuildOutputAndDependenciesAreIgnored(): void {
    $expected = $this->fingerprint('--git-ref=HEAD')['fingerprint'];

    $this->write('web/modules/custom/demo/js/demo.js', "// rebuilt\n");
    $this->write('web/themes/custom/x/node_modules/pkg/index.php', "<?php\n");
    $this->write('vendor/acme/lib/src/Thing.php', "<?php\n");

    $this->assertSame($expected, $this->fingerprint()['fingerprint']);
  }

  /**
   * New code with yesterday's packages is reported (live's failure).
   */
  public function testInstalledPackagesBehindTheLockAreReported(): void {
    $this->writeInstalled(['drupal/core' => '11.4.12', 'drupal/ai' => '1.5.3']);
    $packages = $this->fingerprint()['packages'];

    $this->assertTrue($packages['checked']);
    $this->assertFalse($packages['match']);
    $this->assertSame(['drupal/core: locked 11.4.13, installed 11.4.12'], $packages['mismatches']);
    $this->assertSame('11.4.12', $packages['core']);

    // A locked package that is not installed at all is a mismatch too.
    $this->writeInstalled(['drupal/core' => '11.4.13']);
    $this->assertSame(
      ['drupal/ai: locked 1.5.3, installed (not installed)'],
      $this->fingerprint()['packages']['mismatches'],
    );
  }

  /**
   * Compares the checkout's own report with HEAD, as the deploy command does.
   *
   * @return array{0: int, 1: string}
   *   The exit status and the output.
   */
  private function compareWithHead(): array {
    $script = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($this->repo . '/scripts/deploy/code-fingerprint.php');
    $report = $this->repo . '/report.json';
    file_put_contents($report, (string) shell_exec($script));
    $lines = [];
    $status = 0;
    exec($script . ' --git-ref=HEAD --compare=' . escapeshellarg($report), $lines, $status);
    unlink($report);

    return [$status, implode("\n", $lines)];
  }

  /**
   * The comparison the deploy command runs passes only for a true deploy.
   */
  public function testComparisonPassesOnlyWhenCodeAndPackagesAreRight(): void {
    $this->assertSame(
      [0, '6 source files match HEAD; packages match composer.lock (drupal/core 11.4.13).'],
      $this->compareWithHead(),
    );

    // Dev on 2026-11-19: yesterday's custom code.
    $this->write('web/modules/custom/demo/demo.module', "<?php\n// yesterday's code\n");
    $this->assertSame(
      [1, 'Source files differ from the commit in: web/modules/custom/demo'],
      $this->compareWithHead(),
    );
    $this->git('checkout -q -- web/modules/custom/demo/demo.module');

    // Live on 2026-11-19: today's code, yesterday's packages.
    $this->writeInstalled(['drupal/core' => '11.4.12', 'drupal/ai' => '1.5.3']);
    $this->assertSame(
      [1, 'Installed packages do not match composer.lock: drupal/core: locked 11.4.13, installed 11.4.12'],
      $this->compareWithHead(),
    );

    // No vendor directory to inspect is a failure, not a pass.
    unlink($this->repo . '/vendor/composer/installed.json');
    $this->assertSame(
      [1, 'Installed packages could not be compared with composer.lock on the environment.'],
      $this->compareWithHead(),
    );
  }

  /**
   * An empty or unreadable report from the environment fails the comparison.
   */
  public function testComparisonFailsWithoutReport(): void {
    $script = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($this->repo . '/scripts/deploy/code-fingerprint.php');
    $lines = [];
    $status = 0;
    exec($script . ' --git-ref=HEAD --compare=' . escapeshellarg($this->repo . '/absent.json'), $lines, $status);

    $this->assertSame(1, $status);
    $this->assertSame(['The environment did not return a fingerprint.'], $lines);
  }

  /**
   * Dev-only packages are not expected on a server.
   */
  public function testDevPackagesAreNotRequiredToBeInstalled(): void {
    // setUp installs no phpunit although the lock lists it under packages-dev.
    $this->assertTrue($this->fingerprint()['packages']['match']);
  }

}
