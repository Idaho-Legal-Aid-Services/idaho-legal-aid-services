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

  /**
   * H-2: CSP script-src must not contain 'unsafe-eval'.
   *
   * No custom or core JS requires eval(). CKEditor 5, BigPipe, and GA4
   * all work without it. Allowing eval() enables injected script execution.
   */
  public function testCspScriptSrcNoUnsafeEval(): void {
    $config = $this->loadSeckitConfig();
    $scriptSrc = $config['seckit_xss']['csp']['script-src'] ?? '';
    $this->assertStringNotContainsString("'unsafe-eval'", $scriptSrc,
      'SECURITY: CSP script-src must not contain unsafe-eval. '
      . 'No JS on this site requires eval().');
  }

  /**
   * M-5: SecKit CSRF origin checking must be enabled.
   *
   * Validates the Origin header on POST requests as defense-in-depth
   * against cross-site request forgery.
   */
  public function testCsrfOriginCheckEnabled(): void {
    $config = $this->loadSeckitConfig();
    $this->assertTrue($config['seckit_csrf']['origin'] ?? FALSE,
      'SECURITY: seckit_csrf.origin must be true. '
      . 'Origin header checking adds defense-in-depth against CSRF.');
  }

  /**
   * H-5: HSTS must be enabled with max-age >= 31536000.
   *
   * SecKit emits the strong HSTS header. Pantheon's edge adds a weaker
   * duplicate (max-age=300) that cannot be suppressed from the app layer.
   */
  public function testHstsEnabled(): void {
    $config = $this->loadSeckitConfig();
    $ssl = $config['seckit_ssl'] ?? [];
    $this->assertTrue($ssl['hsts'] ?? FALSE,
      'SECURITY: HSTS must be enabled in SecKit.');
    $this->assertGreaterThanOrEqual(31536000, $ssl['hsts_max_age'] ?? 0,
      'SECURITY: HSTS max-age must be at least 31536000 (1 year).');
    $this->assertTrue($ssl['hsts_preload'] ?? FALSE,
      'SECURITY: HSTS preload must be enabled for HSTS preload list inclusion.');
  }

  /**
   * M-6: Authenticated role must not bypass honeypot or skip CAPTCHA.
   *
   * These permissions allow bots operating under authenticated sessions
   * to bypass form protection. Only admin roles should have them.
   */
  public function testAuthenticatedRoleNoHoneypotBypass(): void {
    $file = $this->configDir . '/user.role.authenticated.yml';
    $this->assertFileExists($file);

    $config = Yaml::parseFile($file);
    $permissions = $config['permissions'] ?? [];

    $this->assertNotContains('bypass honeypot protection', $permissions,
      'SECURITY M-6: authenticated role must not have "bypass honeypot protection".');
    $this->assertNotContains('skip CAPTCHA', $permissions,
      'SECURITY M-6: authenticated role must not have "skip CAPTCHA".');
  }

  /**
   * Loads and caches the SecKit settings config.
   */
  protected function loadSeckitConfig(): array {
    static $config;
    if ($config === NULL) {
      $file = $this->configDir . '/seckit.settings.yml';
      $this->assertFileExists($file);
      $config = Yaml::parseFile($file);
      $this->assertIsArray($config);
    }
    return $config;
  }

}
