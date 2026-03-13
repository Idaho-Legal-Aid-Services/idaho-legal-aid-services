<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use Drupal\ilas_site_assistant\Service\PolicyFilter;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Contract tests locking safety-critical config defaults.
 *
 * These tests ensure that install defaults for safety-critical settings
 * (LLM disabled, PII redaction, rate limits, etc.) cannot drift without
 * deliberate review. Any change to these values must be intentional.
 */
#[Group('ilas_site_assistant')]
class SafetyConfigGovernanceTest extends TestCase {

  private const MODULE_PATH = 'web/modules/custom/ilas_site_assistant';

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
   * Install disclaimer text must contain legal advice refusal language.
   */
  public function testDisclaimerContainsLegalAdviceRefusal(): void {
    $install = self::installConfig();
    $this->assertArrayHasKey('disclaimer_text', $install);

    $disclaimer = strtolower($install['disclaimer_text']);
    $this->assertStringContainsString('cannot', $disclaimer, 'Install disclaimer must contain "cannot"');
    $this->assertStringContainsString('legal advice', $disclaimer, 'Install disclaimer must contain "legal advice"');
  }

  /**
   * Active config disclaimer text must also contain legal advice refusal.
   */
  public function testActiveDisclaimerContainsLegalAdviceRefusal(): void {
    $active = self::activeConfig();
    $this->assertArrayHasKey('disclaimer_text', $active);

    $disclaimer = strtolower($active['disclaimer_text']);
    $this->assertStringContainsString('cannot', $disclaimer, 'Active disclaimer must contain "cannot"');
    $this->assertStringContainsString('legal advice', $disclaimer, 'Active disclaimer must contain "legal advice"');
  }

  /**
   * LLM must be disabled by default in install config.
   */
  public function testLlmDisabledByDefault(): void {
    $install = self::installConfig();
    $this->assertArrayHasKey('llm', $install);
    $this->assertArrayHasKey('enabled', $install['llm']);
    $this->assertFalse($install['llm']['enabled'], 'LLM must be disabled in install defaults');
  }

  /**
   * Safety threshold default must be BLOCK_MEDIUM_AND_ABOVE.
   */
  public function testSafetyThresholdDefault(): void {
    $install = self::installConfig();
    $this->assertSame(
      'BLOCK_MEDIUM_AND_ABOVE',
      $install['llm']['safety_threshold'],
      'Safety threshold must default to BLOCK_MEDIUM_AND_ABOVE',
    );
  }

  /**
   * Rate limit defaults must be 15/min and 120/hour.
   */
  public function testRateLimitDefaults(): void {
    $install = self::installConfig();
    $this->assertSame(15, $install['rate_limit_per_minute'], 'Rate limit per minute must default to 15');
    $this->assertSame(120, $install['rate_limit_per_hour'], 'Rate limit per hour must default to 120');
  }

  /**
   * Anonymous bootstrap rate-limit defaults must be explicit and bounded.
   */
  public function testSessionBootstrapDefaults(): void {
    $install = self::installConfig();
    $this->assertArrayHasKey('session_bootstrap', $install);
    $this->assertSame(60, $install['session_bootstrap']['rate_limit_per_minute'], 'Bootstrap rate limit per minute must default to 60');
    $this->assertSame(600, $install['session_bootstrap']['rate_limit_per_hour'], 'Bootstrap rate limit per hour must default to 600');
    $this->assertSame(24, $install['session_bootstrap']['observation_window_hours'], 'Bootstrap observation window must default to 24 hours');
  }

  /**
   * Public read-endpoint rate limits must be explicit and bounded.
   */
  public function testReadEndpointRateLimitDefaults(): void {
    $install = self::installConfig();
    $this->assertArrayHasKey('read_endpoint_rate_limits', $install);
    $this->assertSame(120, $install['read_endpoint_rate_limits']['suggest']['rate_limit_per_minute']);
    $this->assertSame(1200, $install['read_endpoint_rate_limits']['suggest']['rate_limit_per_hour']);
    $this->assertSame(60, $install['read_endpoint_rate_limits']['faq']['rate_limit_per_minute']);
    $this->assertSame(600, $install['read_endpoint_rate_limits']['faq']['rate_limit_per_hour']);
  }

  /**
   * Conversation logging must redact PII by default.
   */
  public function testConversationLoggingRedactionDefault(): void {
    $install = self::installConfig();
    $this->assertArrayHasKey('conversation_logging', $install);
    $this->assertTrue(
      $install['conversation_logging']['redact_pii'],
      'PII redaction must be enabled by default in conversation_logging',
    );
  }

  /**
   * LLM fallback_on_error must be true by default (fail-safe).
   */
  public function testFallbackOnErrorDefault(): void {
    $install = self::installConfig();
    $this->assertTrue(
      $install['llm']['fallback_on_error'],
      'LLM fallback_on_error must be true by default',
    );
  }

  /**
   * Safety alerting must be disabled by default.
   */
  public function testSafetyAlertingDisabledByDefault(): void {
    $install = self::installConfig();
    $this->assertArrayHasKey('safety_alerting', $install);
    $this->assertFalse(
      $install['safety_alerting']['enabled'],
      'Safety alerting must be disabled by default',
    );
  }

  /**
   * Schema must define mappings for all safety-critical config blocks.
   */
  public function testSchemaCoversAllSafetyCriticalBlocks(): void {
    $schema = self::schemaConfig();
    $mapping = $schema['ilas_site_assistant.settings']['mapping'];

    $requiredBlocks = [
      'llm',
      'conversation_logging',
      'safety_alerting',
      'rate_limit_per_minute',
      'rate_limit_per_hour',
      'disclaimer_text',
      'vector_search',
      'audit_governance',
      'session_bootstrap',
      'read_endpoint_rate_limits',
    ];

    foreach ($requiredBlocks as $block) {
      $this->assertArrayHasKey(
        $block,
        $mapping,
        "Schema missing safety-critical block: {$block}",
      );
    }
  }

  /**
   * Policy keywords must not remain exportable or runtime-configurable.
   */
  public function testPolicyKeywordsAreNoLongerExportedOrRuntimeOverridden(): void {
    $install = self::installConfig();
    $active = self::activeConfig();
    $schema = self::schemaConfig();

    $this->assertArrayNotHasKey('policy_keywords', $install);
    $this->assertArrayNotHasKey('policy_keywords', $active);
    $this->assertArrayNotHasKey('policy_keywords', $schema['ilas_site_assistant.settings']['mapping'] ?? []);

    $path = self::repoRoot() . '/web/sites/default/settings.php';
    $this->assertFileExists($path, 'settings.php not found');

    $contents = file_get_contents($path);
    $this->assertIsString($contents);
    $this->assertStringNotContainsString('policy_keywords', $contents);
  }

  /**
   * PolicyFilter fallback keywords remain explicit and reviewable in code.
   */
  public function testPolicyFilterFallbackKeywordsRemainGovernedInCode(): void {
    $reflection = new \ReflectionClass(PolicyFilter::class);

    $this->assertSame([
      'should i',
      'what are my chances',
      'is it legal',
      'can i sue',
      'statute',
      'law says',
      'my rights',
      'will i win',
      'case outcome',
      'legal advice',
      'advise me',
      'what should i do',
    ], $reflection->getConstant('GOVERNED_LEGAL_ADVICE_KEYWORDS'));

    $this->assertSame([
      '@',
      'my name is',
      'my address',
      'social security',
      'ssn',
      'phone number',
      'date of birth',
      'case number',
    ], $reflection->getConstant('GOVERNED_PII_INDICATORS'));
  }

  /**
   * Vector search must be disabled by default in install config.
   */
  public function testVectorSearchDisabledByDefault(): void {
    $install = self::installConfig();
    $this->assertArrayHasKey('vector_search', $install);
    $this->assertArrayHasKey('enabled', $install['vector_search']);
    $this->assertFalse(
      $install['vector_search']['enabled'],
      'Vector search must be disabled in install defaults',
    );
  }

  /**
   * Active config safety keys must match install defaults (no drift).
   *
   * Checks that the safety-critical values in the active (exported) config
   * have not drifted from the install defaults. Only checks keys present
   * in both configs to avoid false positives for keys added after initial
   * install.
   */
  public function testActiveConfigSafetyKeysMatchInstallDefaults(): void {
    $install = self::installConfig();
    $active = self::activeConfig();

    // Scalar safety keys.
    $this->assertSame(
      $install['rate_limit_per_minute'],
      $active['rate_limit_per_minute'],
      'Active rate_limit_per_minute has drifted from install default',
    );
    $this->assertSame(
      $install['rate_limit_per_hour'],
      $active['rate_limit_per_hour'],
      'Active rate_limit_per_hour has drifted from install default',
    );
    $this->assertSame(
      $install['session_bootstrap']['rate_limit_per_minute'],
      $active['session_bootstrap']['rate_limit_per_minute'],
      'Active session_bootstrap.rate_limit_per_minute has drifted from install default',
    );
    $this->assertSame(
      $install['session_bootstrap']['rate_limit_per_hour'],
      $active['session_bootstrap']['rate_limit_per_hour'],
      'Active session_bootstrap.rate_limit_per_hour has drifted from install default',
    );
    $this->assertSame(
      $install['session_bootstrap']['observation_window_hours'],
      $active['session_bootstrap']['observation_window_hours'],
      'Active session_bootstrap.observation_window_hours has drifted from install default',
    );
    $this->assertSame(
      $install['read_endpoint_rate_limits']['suggest']['rate_limit_per_minute'],
      $active['read_endpoint_rate_limits']['suggest']['rate_limit_per_minute'],
      'Active read_endpoint_rate_limits.suggest.rate_limit_per_minute has drifted from install default',
    );
    $this->assertSame(
      $install['read_endpoint_rate_limits']['suggest']['rate_limit_per_hour'],
      $active['read_endpoint_rate_limits']['suggest']['rate_limit_per_hour'],
      'Active read_endpoint_rate_limits.suggest.rate_limit_per_hour has drifted from install default',
    );
    $this->assertSame(
      $install['read_endpoint_rate_limits']['faq']['rate_limit_per_minute'],
      $active['read_endpoint_rate_limits']['faq']['rate_limit_per_minute'],
      'Active read_endpoint_rate_limits.faq.rate_limit_per_minute has drifted from install default',
    );
    $this->assertSame(
      $install['read_endpoint_rate_limits']['faq']['rate_limit_per_hour'],
      $active['read_endpoint_rate_limits']['faq']['rate_limit_per_hour'],
      'Active read_endpoint_rate_limits.faq.rate_limit_per_hour has drifted from install default',
    );

    // LLM safety keys.
    $this->assertSame(
      $install['llm']['enabled'],
      $active['llm']['enabled'],
      'Active llm.enabled has drifted from install default',
    );
    $this->assertSame(
      $install['llm']['safety_threshold'],
      $active['llm']['safety_threshold'],
      'Active llm.safety_threshold has drifted from install default',
    );
    $this->assertSame(
      $install['llm']['fallback_on_error'],
      $active['llm']['fallback_on_error'],
      'Active llm.fallback_on_error has drifted from install default',
    );

    // Conversation logging PII redaction.
    $this->assertTrue(
      $active['conversation_logging']['redact_pii'],
      'Active conversation_logging.redact_pii must remain true',
    );
  }

  /**
   * Live settings override must hard-disable LLM enablement.
   */
  public function testLiveSettingsOverrideForcesLlmDisabled(): void {
    $path = self::repoRoot() . '/web/sites/default/settings.php';
    $this->assertFileExists($path, 'settings.php not found');

    $contents = file_get_contents($path);
    $this->assertIsString($contents);
    $this->assertStringContainsString(
      "if (isset(\$_ENV['PANTHEON_ENVIRONMENT']) && \$_ENV['PANTHEON_ENVIRONMENT'] === 'live') {",
      $contents,
      'settings.php must include live environment branch',
    );
    $this->assertStringContainsString(
      "\$config['ilas_site_assistant.settings']['llm.enabled'] = FALSE;",
      $contents,
      'Live environment must hard-disable llm.enabled via runtime override',
    );
  }

  /**
   * Settings form must enforce the live LLM guard at UI, validation, and save.
   */
  public function testAssistantSettingsFormEnforcesLiveLlmGuard(): void {
    $path = self::repoRoot() . '/' . self::MODULE_PATH . '/src/Form/AssistantSettingsForm.php';
    $this->assertFileExists($path, 'AssistantSettingsForm.php not found');

    $contents = file_get_contents($path);
    $this->assertIsString($contents);
    $this->assertStringContainsString('protected function isLiveEnvironment(): bool', $contents);
    $this->assertStringContainsString("'#disabled' => \$is_live_environment", $contents);
    $this->assertStringContainsString("setErrorByName(\n        'llm_enabled',", $contents);
    $this->assertStringContainsString("if (\$this->isLiveEnvironment()) {\n      \$llm_enabled = FALSE;", $contents);
    $this->assertStringContainsString("'enabled' => \$llm_enabled", $contents);
  }

}
