<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Guards Phase 1 Entry criteria #2: platform credentials and destination
 * approvals for telemetry integrations (Langfuse, Sentry).
 */
#[Group('ilas_site_assistant')]
class TelemetryCredentialGateTest extends TestCase {

  /**
   * Returns the repository root path.
   */
  private static function repoRoot(): string {
    // __DIR__ = <repo>/web/modules/custom/ilas_site_assistant/tests/src/Unit
    return dirname(__DIR__, 7);
  }

  /**
   * Reads a file from repo root after existence checks.
   */
  private static function readFile(string $relativePath): string {
    $path = self::repoRoot() . '/' . ltrim($relativePath, '/');
    self::assertFileExists($path, "Expected file does not exist: {$relativePath}");

    $contents = file_get_contents($path);
    self::assertIsString($contents, "Failed reading file: {$relativePath}");
    return $contents;
  }

  /**
   * Settings.php must contain Langfuse credential override wiring.
   */
  public function testSettingsPhpContainsLangfuseCredentialOverrideWiring(): void {
    $settings = self::readFile('web/sites/default/settings.php');

    $this->assertStringContainsString('LANGFUSE_PUBLIC_KEY', $settings);
    $this->assertStringContainsString('LANGFUSE_SECRET_KEY', $settings);
    $this->assertStringContainsString("langfuse']['public_key']", $settings);
    $this->assertStringContainsString("langfuse']['secret_key']", $settings);
  }

  /**
   * Settings.php must contain Sentry DSN override wiring.
   */
  public function testSettingsPhpContainsSentryDsnOverrideWiring(): void {
    $settings = self::readFile('web/sites/default/settings.php');

    $this->assertStringContainsString('SENTRY_DSN', $settings);
    $this->assertStringContainsString("raven.settings']['client_key']", $settings);
  }

  /**
   * Settings.php must contain the secret helper function with Pantheon and
   * getenv fallback paths.
   */
  public function testSettingsPhpContainsSecretHelperFunction(): void {
    $settings = self::readFile('web/sites/default/settings.php');

    $this->assertStringContainsString('function _ilas_get_secret', $settings);
    $this->assertStringContainsString('pantheon_get_secret', $settings);
    $this->assertStringContainsString('getenv', $settings);
  }

  /**
   * Install config defaults must include Langfuse credential keys with
   * enabled=false and the US cloud host.
   */
  public function testInstallConfigDefaultsIncludeLangfuseCredentialKeys(): void {
    $yamlPath = self::repoRoot()
      . '/web/modules/custom/ilas_site_assistant/config/install/ilas_site_assistant.settings.yml';
    $this->assertFileExists($yamlPath, 'Install config YAML does not exist');

    $config = Yaml::parseFile($yamlPath);
    $this->assertIsArray($config, 'Install config must parse as array');

    $this->assertArrayHasKey('langfuse', $config, 'Install config must have langfuse block');
    $langfuse = $config['langfuse'];

    $this->assertFalse($langfuse['enabled'], 'Langfuse must be disabled by default');
    $this->assertSame('https://us.cloud.langfuse.com', $langfuse['host']);
    $this->assertArrayHasKey('public_key', $langfuse, 'Langfuse block must include public_key');
    $this->assertArrayHasKey('secret_key', $langfuse, 'Langfuse block must include secret_key');
    $this->assertArrayHasKey('sample_rate', $langfuse, 'Langfuse block must include sample_rate');
  }

  /**
   * Runtime gates artifact must confirm credentials present on all three
   * Pantheon environments with llm.enabled=false preserved.
   */
  public function testRuntimeGatesArtifactShowsCredentialsPresentOnAllEnvironments(): void {
    $gates = self::readFile('docs/aila/runtime/phase1-observability-gates.txt');

    $this->assertSame(
      3,
      substr_count($gates, 'langfuse_public_key=present'),
      'Expected 3 environments with langfuse_public_key=present',
    );
    $this->assertSame(
      3,
      substr_count($gates, 'langfuse_secret_key=present'),
      'Expected 3 environments with langfuse_secret_key=present',
    );
    $this->assertSame(
      3,
      substr_count($gates, 'raven_client_key=present'),
      'Expected 3 environments with raven_client_key=present',
    );
    $this->assertStringContainsString("llm.enabled': false", $gates);
  }

  /**
   * Destination hosts must be documented in current-state.md and roadmap.md.
   */
  public function testDestinationHostsAreDocumented(): void {
    $currentState = self::readFile('docs/aila/current-state.md');

    $this->assertStringContainsString(
      'Phase 1 Entry #2 Credential and Destination Disposition',
      $currentState,
    );
    $this->assertStringContainsString(
      'https://us.cloud.langfuse.com',
      $currentState,
    );
    $this->assertStringContainsString(
      'Sentry (via drupal/raven)',
      $currentState,
    );

    $roadmap = self::readFile('docs/aila/roadmap.md');

    $this->assertStringContainsString(
      'Phase 1 Entry #2 credential and destination disposition',
      $roadmap,
    );
  }

  /**
   * Evidence index must capture CLAIM-126 with credential gate verification
   * referencing settings.php, the gate test, and the runtime artifact.
   */
  public function testEvidenceIndexCapturesCredentialGateVerification(): void {
    $evidenceIndex = self::readFile('docs/aila/evidence-index.md');

    $this->assertStringContainsString('### CLAIM-126', $evidenceIndex);
    $this->assertStringContainsString('settings.php', $evidenceIndex);
    $this->assertStringContainsString('TelemetryCredentialGateTest.php', $evidenceIndex);
    $this->assertStringContainsString('phase1-observability-gates.txt', $evidenceIndex);
  }

}
