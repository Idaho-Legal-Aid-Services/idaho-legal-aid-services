<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Contract tests locking the two-mode chat widget UI surface area.
 *
 * P0-NDO-02: "No major UI redesign." These tests protect the existing
 * floating widget + /assistant page structure from scope creep during
 * safety hardening work. They lock file existence, config keys/values,
 * route paths, and library bindings — NOT internal content (CSS selectors,
 * JS logic, Twig markup).
 */
#[Group('ilas_site_assistant')]
class NoMajorUiRedesignGuardTest extends TestCase {

  private const MODULE_PATH = 'web/modules/custom/ilas_site_assistant';
  private const THEME_PATH = 'web/themes/custom/b5subtheme';

  /**
   * Returns the repository root path.
   */
  private static function repoRoot(): string {
    // __DIR__ = <repo>/web/modules/custom/ilas_site_assistant/tests/src/Unit
    return dirname(__DIR__, 7);
  }

  /**
   * Returns parsed install config.
   */
  private static function installConfig(): array {
    $path = self::repoRoot() . '/' . self::MODULE_PATH . '/config/install/ilas_site_assistant.settings.yml';
    self::assertFileExists($path, 'Install config YAML not found');
    return Yaml::parseFile($path);
  }

  /**
   * Returns parsed active config.
   */
  private static function activeConfig(): array {
    $path = self::repoRoot() . '/config/ilas_site_assistant.settings.yml';
    self::assertFileExists($path, 'Active config YAML not found');
    return Yaml::parseFile($path);
  }

  /**
   * Returns parsed schema config.
   */
  private static function schemaConfig(): array {
    $path = self::repoRoot() . '/' . self::MODULE_PATH . '/config/schema/ilas_site_assistant.schema.yml';
    self::assertFileExists($path, 'Schema YAML not found');
    return Yaml::parseFile($path);
  }

  /**
   * Returns parsed routing config.
   */
  private static function routingConfig(): array {
    $path = self::repoRoot() . '/' . self::MODULE_PATH . '/ilas_site_assistant.routing.yml';
    self::assertFileExists($path, 'Routing YAML not found');
    return Yaml::parseFile($path);
  }

  /**
   * Returns parsed libraries config.
   */
  private static function librariesConfig(): array {
    $path = self::repoRoot() . '/' . self::MODULE_PATH . '/ilas_site_assistant.libraries.yml';
    self::assertFileExists($path, 'Libraries YAML not found');
    return Yaml::parseFile($path);
  }

  // ---------------------------------------------------------------
  // Section 1: UI Feature Toggle Defaults (install config)
  // ---------------------------------------------------------------

  /**
   * Install config must contain the four UI feature toggle keys.
   */
  public function testUiFeatureTogglesExistInInstallConfig(): void {
    $install = self::installConfig();

    $this->assertArrayHasKey('enable_global_widget', $install, 'Install config missing enable_global_widget');
    $this->assertArrayHasKey('enable_assistant_page', $install, 'Install config missing enable_assistant_page');
    $this->assertArrayHasKey('enable_faq', $install, 'Install config missing enable_faq');
    $this->assertArrayHasKey('enable_resources', $install, 'Install config missing enable_resources');
  }

  /**
   * All four UI feature toggles must default to true.
   */
  public function testUiFeatureTogglesEnabledByDefault(): void {
    $install = self::installConfig();

    $this->assertTrue(
      $install['enable_global_widget'],
      'enable_global_widget must be true in install defaults',
    );
    $this->assertTrue(
      $install['enable_assistant_page'],
      'enable_assistant_page must be true in install defaults',
    );
    $this->assertTrue(
      $install['enable_faq'],
      'enable_faq must be true in install defaults',
    );
    $this->assertTrue(
      $install['enable_resources'],
      'enable_resources must be true in install defaults',
    );
  }

  /**
   * Excluded paths must default to admin, login, and password reset.
   */
  public function testExcludedPathsDefaultValue(): void {
    $install = self::installConfig();

    $this->assertArrayHasKey('excluded_paths', $install, 'Install config missing excluded_paths');
    $this->assertSame(
      ['/admin', '/user/login', '/user/password'],
      $install['excluded_paths'],
      'excluded_paths must default to [/admin, /user/login, /user/password]',
    );
  }

  // ---------------------------------------------------------------
  // Section 2: Install vs Active Config Parity
  // ---------------------------------------------------------------

  /**
   * UI feature toggles must match between install and active config.
   */
  public function testUiFeatureTogglesParityBetweenInstallAndActive(): void {
    $install = self::installConfig();
    $active = self::activeConfig();

    $toggles = ['enable_global_widget', 'enable_assistant_page', 'enable_faq', 'enable_resources'];
    foreach ($toggles as $key) {
      $this->assertSame(
        $install[$key],
        $active[$key],
        "Active {$key} has drifted from install default",
      );
    }
  }

  /**
   * Excluded paths must match between install and active config.
   */
  public function testExcludedPathsParityBetweenInstallAndActive(): void {
    $install = self::installConfig();
    $active = self::activeConfig();

    $this->assertSame(
      $install['excluded_paths'],
      $active['excluded_paths'],
      'Active excluded_paths has drifted from install default',
    );
  }

  // ---------------------------------------------------------------
  // Section 3: Schema Coverage
  // ---------------------------------------------------------------

  /**
   * Schema must define mappings for all four UI config keys.
   */
  public function testSchemaCoversUiToggleKeys(): void {
    $schema = self::schemaConfig();
    $mapping = $schema['ilas_site_assistant.settings']['mapping'];

    $uiKeys = [
      'enable_global_widget',
      'enable_assistant_page',
      'enable_faq',
      'enable_resources',
      'excluded_paths',
    ];

    foreach ($uiKeys as $key) {
      $this->assertArrayHasKey(
        $key,
        $mapping,
        "Schema missing UI config key: {$key}",
      );
    }
  }

  // ---------------------------------------------------------------
  // Section 4: Critical UI Asset Files Exist
  // ---------------------------------------------------------------

  /**
   * The assistant widget JS file must exist.
   */
  public function testAssistantWidgetJsExists(): void {
    $path = self::repoRoot() . '/' . self::MODULE_PATH . '/js/assistant-widget.js';
    $this->assertFileExists($path, 'assistant-widget.js not found');
  }

  /**
   * The assistant widget Twig template must exist.
   */
  public function testAssistantWidgetTemplateExists(): void {
    $path = self::repoRoot() . '/' . self::MODULE_PATH . '/templates/assistant-widget.html.twig';
    $this->assertFileExists($path, 'assistant-widget.html.twig not found');
  }

  /**
   * The assistant page Twig template must exist.
   */
  public function testAssistantPageTemplateExists(): void {
    $path = self::repoRoot() . '/' . self::MODULE_PATH . '/templates/assistant-page.html.twig';
    $this->assertFileExists($path, 'assistant-page.html.twig not found');
  }

  /**
   * The assistant widget SCSS file must exist in the theme.
   */
  public function testAssistantWidgetScssExists(): void {
    $path = self::repoRoot() . '/' . self::THEME_PATH . '/scss/_assistant-widget.scss';
    $this->assertFileExists($path, '_assistant-widget.scss not found in theme');
  }

  // ---------------------------------------------------------------
  // Section 5: Route Existence
  // ---------------------------------------------------------------

  /**
   * The assistant page route must exist at /assistant.
   */
  public function testAssistantPageRouteExists(): void {
    $routing = self::routingConfig();

    $this->assertArrayHasKey(
      'ilas_site_assistant.page',
      $routing,
      'Route ilas_site_assistant.page not found',
    );
    $this->assertSame(
      '/assistant',
      $routing['ilas_site_assistant.page']['path'],
      'Assistant page route must have path /assistant',
    );
  }

  // ---------------------------------------------------------------
  // Section 6: Libraries Integrity
  // ---------------------------------------------------------------

  /**
   * Libraries must define widget and page entries referencing assistant-widget.js.
   */
  public function testLibrariesDefineWidgetAndPageEntries(): void {
    $libraries = self::librariesConfig();

    $this->assertArrayHasKey('widget', $libraries, 'Libraries missing widget entry');
    $this->assertArrayHasKey('page', $libraries, 'Libraries missing page entry');

    $this->assertArrayHasKey(
      'js/assistant-widget.js',
      $libraries['widget']['js'],
      'Widget library must reference js/assistant-widget.js',
    );
    $this->assertArrayHasKey(
      'js/assistant-widget.js',
      $libraries['page']['js'],
      'Page library must reference js/assistant-widget.js',
    );
  }

  /**
   * Both libraries must declare core/drupal, core/drupalSettings, core/once.
   */
  public function testLibrariesDependOnDrupalCore(): void {
    $libraries = self::librariesConfig();
    $requiredDeps = ['core/drupal', 'core/drupalSettings', 'core/once'];

    foreach (['widget', 'page'] as $entry) {
      $this->assertArrayHasKey('dependencies', $libraries[$entry], "{$entry} library missing dependencies key");
      foreach ($requiredDeps as $dep) {
        $this->assertContains(
          $dep,
          $libraries[$entry]['dependencies'],
          "{$entry} library must depend on {$dep}",
        );
      }
    }
  }

}
