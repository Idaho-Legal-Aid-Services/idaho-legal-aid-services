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
    $this->assertStringContainsString('ILAS_LANGFUSE_ENABLED', $settings);
    $this->assertStringContainsString("langfuse']['public_key']", $settings);
    $this->assertStringContainsString("langfuse']['secret_key']", $settings);
  }

  /**
   * Settings.php must keep Langfuse export live-only by default.
   */
  public function testSettingsPhpLangfusePolicyIsLiveOnlyWithExplicitToggle(): void {
    $settings = self::readFile('web/sites/default/settings.php');

    $this->assertStringContainsString("_ilas_get_secret('ILAS_LANGFUSE_ENABLED')", $settings);
    $this->assertStringContainsString(
      '_ilas_read_boolean($langfuse_enabled_raw, $langfuse_environment === \'live\')',
      $settings,
    );
    $this->assertStringContainsString('if ($langfuse_enabled && $langfuse_pk && $langfuse_sk)', $settings);
    $this->assertStringNotContainsString('if ($langfuse_pk && $langfuse_sk)', $settings);
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
   * Runtime gates artifact must confirm live-only Langfuse export policy.
   */
  public function testRuntimeGatesArtifactShowsCredentialsPresentOnAllEnvironments(): void {
    $gates = self::readFile('docs/aila/runtime/phase1-observability-gates.txt');

    $this->assertSame(
      1,
      substr_count($gates, 'langfuse_public_key=present'),
      'Expected only live to inject langfuse_public_key into effective config',
    );
    $this->assertSame(
      1,
      substr_count($gates, 'langfuse_secret_key=present'),
      'Expected only live to inject langfuse_secret_key into effective config',
    );
    $this->assertSame(
      2,
      substr_count($gates, 'langfuse_public_key=not_injected'),
      'Expected dev/test to keep Langfuse credentials out of effective config',
    );
    $this->assertSame(
      2,
      substr_count($gates, 'langfuse_enabled=false'),
      'Expected dev/test Langfuse exports disabled by default',
    );
    $this->assertSame(
      3,
      substr_count($gates, 'raven_client_key=present'),
      'Expected 3 environments with raven_client_key=present',
    );
    $this->assertStringContainsString('langfuse_policy=live_only_default', $gates);
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

  /**
   * Tests settings.php keeps routine 403s out of Sentry.
   *
   * Core logs every access-denied response at warning. On a public site that
   * is anonymous traffic to protected paths; sent to Sentry it formed one
   * permanently open issue (PHP-8J) that hid nothing and taught nobody.
   */
  public function testSettingsPhpDoesNotSendAccessDeniedChannelToSentry(): void {
    $settings = self::readFile('web/sites/default/settings.php');

    $this->assertStringContainsString(
      "\$config['raven.settings']['ignored_channels'] = ['access denied'];",
      $settings,
    );
  }

  /**
   * Tests the stale-ratio warning is switched off everywhere except live.
   *
   * The threshold must not be what changes: the alert stays as strict as it
   * is on live, where content review actually happens (PHP-AR).
   */
  public function testSettingsPhpDisablesStaleRatioAlertOutsideLive(): void {
    $settings = self::readFile('web/sites/default/settings.php');

    $this->assertStringContainsString(
      "if (_ilas_observability_environment_name() !== 'pantheon-live') {\n  \$config['ilas_site_assistant.settings']['source_governance']['stale_ratio_alert_enabled'] = FALSE;",
      $settings,
    );
    $this->assertStringNotContainsString("['source_governance']['stale_ratio_alert_pct']", $settings);
  }

}
