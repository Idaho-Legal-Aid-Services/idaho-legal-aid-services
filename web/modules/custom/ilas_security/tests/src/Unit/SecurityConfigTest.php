<?php

namespace Drupal\Tests\ilas_security\Unit;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Asserts that security-sensitive config values are correct.
 *
 * Reads YAML files from the config sync directory and asserts that deployed
 * values match security requirements. No Drupal bootstrap needed.
 */
#[Group('ilas_security')]
class SecurityConfigTest extends TestCase {

  /**
   * Path to the config sync directory.
   */
  protected string $configDir;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // 7 levels up from Unit/ dir reaches the project root.
    $this->configDir = dirname(__DIR__, 7) . '/config';
    $this->assertDirectoryExists($this->configDir,
      'Config sync directory not found at: ' . $this->configDir);
  }

  /**
   * C-1: Error logging must be set to "hide" for production.
   *
   * Drupal "verbose" error_level displays full PHP backtraces to visitors.
   * Local development uses settings.ddev.php to override this at runtime.
   */
  public function testErrorLevelIsHidden(): void {
    $file = $this->configDir . '/system.logging.yml';
    $this->assertFileExists($file);

    $config = Yaml::parseFile($file);
    $this->assertIsArray($config);
    $this->assertArrayHasKey('error_level', $config);
    $this->assertSame('hide', $config['error_level'],
      'SECURITY: config/system.logging.yml error_level must be "hide". '
      . 'Current value: "' . $config['error_level'] . '". '
      . 'Verbose error display leaks PHP backtraces to visitors.');
  }

}
