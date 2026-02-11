<?php

namespace Drupal\Tests\ilas_security\Unit;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Asserts that settings.php contains required security blocks.
 *
 * Since settings.php pre-bootstrap blocks use die() and cannot be exercised
 * via BrowserTestBase, this test validates that the expected code patterns
 * exist in the file. Actual HTTP-level verification is done via curl in
 * the deployment verification checklist.
 */
#[Group('ilas_security')]
class SettingsSecurityTest extends TestCase {

  /**
   * Full contents of settings.php.
   */
  protected string $settingsContents;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $settingsFile = dirname(__DIR__, 7) . '/web/sites/default/settings.php';
    $this->assertFileExists($settingsFile,
      'settings.php not found at: ' . $settingsFile);
    $this->settingsContents = file_get_contents($settingsFile);
  }

  /**
   * C-2: settings.php must block direct access to install.php.
   */
  public function testInstallPhpBlockExists(): void {
    $this->assertStringContainsString(
      '/core/install.php',
      $this->settingsContents,
      'settings.php must contain a block for /core/install.php'
    );
    $this->assertStringContainsString(
      "'HTTP/1.1 403 Forbidden'",
      $this->settingsContents,
      'settings.php must return 403 for blocked scripts'
    );
  }

  /**
   * C-2: settings.php must block direct access to rebuild.php.
   */
  public function testRebuildPhpBlockExists(): void {
    $this->assertStringContainsString(
      '/core/rebuild.php',
      $this->settingsContents,
      'settings.php must contain a block for /core/rebuild.php'
    );
  }

  /**
   * The install/rebuild block must skip CLI to not break drush.
   */
  public function testBlockSkipsCli(): void {
    $cliPos = strpos($this->settingsContents, "PHP_SAPI !== 'cli'");
    $scriptsPos = strpos($this->settingsContents, '$blocked_scripts');
    $this->assertNotFalse($cliPos, 'CLI guard must exist in settings.php');
    $this->assertNotFalse($scriptsPos, '$blocked_scripts array must exist');
    $this->assertLessThan($scriptsPos, $cliPos,
      'CLI guard must appear before $blocked_scripts to protect drush');
  }

  /**
   * L-3: settings.php itself should be in the blocked scripts list.
   */
  public function testSettingsPhpSelfBlockExists(): void {
    $this->assertStringContainsString(
      '/sites/default/settings.php',
      $this->settingsContents,
      'settings.php must block direct access to itself'
    );
  }

}
