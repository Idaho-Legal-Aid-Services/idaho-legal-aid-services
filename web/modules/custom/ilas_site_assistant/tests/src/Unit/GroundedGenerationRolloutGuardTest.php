<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Guards that grounded answer generation ships dark and stays runtime-gated.
 *
 * TOVR-18: exported config keeps llm.generation.enabled FALSE, live
 * enablement is secret-only (ILAS_LLM_GENERATION_ENABLED), and the admin
 * checkbox cannot enable it on live.
 */
#[Group('ilas_site_assistant')]
final class GroundedGenerationRolloutGuardTest extends TestCase {

  /**
   * Returns the repository root path.
   */
  private static function repoRoot(): string {
    return dirname(__DIR__, 7);
  }

  /**
   * Reads a repo-relative file and asserts it exists.
   */
  private static function readFile(string $relativePath): string {
    $path = self::repoRoot() . '/' . ltrim($relativePath, '/');
    self::assertFileExists($path);
    $contents = file_get_contents($path);
    self::assertIsString($contents);
    return $contents;
  }

  /**
   * Exported and install config must keep generation disabled.
   */
  public function testExportedConfigShipsGenerationDisabled(): void {
    foreach ([
      'config/ilas_site_assistant.settings.yml',
      'web/modules/custom/ilas_site_assistant/config/install/ilas_site_assistant.settings.yml',
    ] as $relativePath) {
      $config = self::readFile($relativePath);
      $this->assertStringContainsString("  generation:\n", $config, $relativePath);
      $this->assertMatchesRegularExpression('/^  generation:\n(?:    #.*\n)*    enabled: false$/m', $config, $relativePath);
      $this->assertStringNotContainsString("ilas_site_assistant_llm_enabled", $config, $relativePath);
    }

    $schema = self::readFile('web/modules/custom/ilas_site_assistant/config/schema/ilas_site_assistant.schema.yml');
    $this->assertStringContainsString("        generation:\n          type: mapping", $schema);
  }

  /**
   * Runtime toggle, admin form, and resolver guard anchors remain present.
   */
  public function testRuntimeToggleAndFormGuardAnchorsRemainPresent(): void {
    $settings = self::readFile('web/sites/default/settings.php');
    $form = self::readFile('web/modules/custom/ilas_site_assistant/src/Form/AssistantSettingsForm.php');
    $resolver = self::readFile('web/modules/custom/ilas_site_assistant/src/Service/LlmRuntimeConfigResolver.php');

    $this->assertStringContainsString("_ilas_get_secret('ILAS_LLM_GENERATION_ENABLED')", $settings);
    $this->assertStringContainsString("\$config['ilas_site_assistant.settings']['llm']['generation']['enabled'] = TRUE;", $settings);
    $this->assertStringContainsString("\$settings['ilas_llm_generation_override_channel']", $settings);
    $this->assertStringNotContainsString("['llm']['generation']['enabled'] = FALSE;", $settings);

    $this->assertStringContainsString("'llm_generation_enabled'", $form);
    $this->assertStringContainsString('Set <code>ILAS_LLM_GENERATION_ENABLED</code>', $form);
    $this->assertStringContainsString("setErrorByName(\n        'llm_generation_enabled',", $form);
    $this->assertStringContainsString("if (\$this->isLiveEnvironment()) {\n      \$llm_generation_enabled = FALSE;", $form);

    $this->assertStringContainsString("Settings::get('ilas_llm_generation_override_channel')", $resolver);
    $this->assertStringContainsString('public function isGenerationRuntimeReady(): bool', $resolver);
  }

  /**
   * Environment docs describe the new runtime secret.
   */
  public function testDocsDescribeTheGenerationSecret(): void {
    $envVars = self::readFile('docs/env-vars.md');
    $envExample = self::readFile('.ddev/.env.example');

    $this->assertStringContainsString('`ILAS_LLM_GENERATION_ENABLED`', $envVars);
    $this->assertStringContainsString('requires `ILAS_LLM_ENABLED=1`', $envVars);
    $this->assertStringContainsString('ILAS_LLM_GENERATION_ENABLED=0', $envExample);
  }

}
