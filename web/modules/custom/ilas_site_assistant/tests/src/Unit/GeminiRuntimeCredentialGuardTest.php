<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Guards the post-transition Gemini footprint as vector-only residual config.
 */
#[Group('ilas_site_assistant')]
final class GeminiRuntimeCredentialGuardTest extends TestCase {

  private static function repoRoot(): string {
    return dirname(__DIR__, 7);
  }

  private static function readFile(string $relativePath): string {
    $path = self::repoRoot() . '/' . ltrim($relativePath, '/');
    self::assertFileExists($path);
    $contents = file_get_contents($path);
    self::assertIsString($contents);
    return $contents;
  }

  public function testAssistantSettingsFormStaysSecretlessAndCohereFirst(): void {
    $form = self::readFile('web/modules/custom/ilas_site_assistant/src/Form/AssistantSettingsForm.php');

    $this->assertStringContainsString('ILAS_COHERE_API_KEY', $form);
    $this->assertStringContainsString('ILAS_LLM_ENABLED', $form);
    $this->assertStringNotContainsString('gemini_api', $form);
    $this->assertStringNotContainsString('llm_api_key', $form);
  }

  public function testSettingsPhpSeparatesCohereAssistantSecretFromResidualGeminiKey(): void {
    $settings = self::readFile('web/sites/default/settings.php');

    $this->assertStringContainsString("_ilas_get_secret('ILAS_COHERE_API_KEY')", $settings);
    $this->assertStringContainsString("\$settings['ilas_cohere_api_key'] = \$ilas_cohere_key;", $settings);
    $this->assertStringContainsString("_ilas_get_secret('ILAS_LLM_ENABLED')", $settings);

    $this->assertStringContainsString("_ilas_get_secret('ILAS_GEMINI_API_KEY')", $settings);
    $this->assertStringContainsString("\$settings['ilas_gemini_api_key'] = \$ilas_gemini_key;", $settings);
    $this->assertStringNotContainsString("\$config['ilas_site_assistant.settings']['llm.api_key']", $settings);
  }

  public function testAssistantRuntimePathNoLongerContainsGoogleTransportCalls(): void {
    $enhancer = self::readFile('web/modules/custom/ilas_site_assistant/src/Service/LlmEnhancer.php');
    $controller = self::readFile('web/modules/custom/ilas_site_assistant/src/Controller/AssistantApiController.php');
    $services = self::readFile('web/modules/custom/ilas_site_assistant/ilas_site_assistant.services.yml');

    $this->assertStringNotContainsString('callGeminiApi', $enhancer);
    $this->assertStringNotContainsString('callVertexAi', $enhancer);
    $this->assertStringContainsString('cohere_llm_transport', $services);
    $this->assertStringContainsString('DECISION_FALLBACK_LLM', $controller);
    $this->assertStringContainsString('classifyIntent(', $controller);
  }

  public function testGeminiKeyEntityRemainsRuntimeOnlyForResidualVectorFootprint(): void {
    $config = Yaml::parseFile(self::repoRoot() . '/config/key.key.gemini_api_key.yml');

    $this->assertSame('ilas_runtime_site_setting', $config['key_provider'] ?? NULL);
    $this->assertSame('ilas_gemini_api_key', $config['key_provider_settings']['settings_key'] ?? NULL);
    $this->assertStringContainsString('runtime-only', (string) ($config['description'] ?? ''));
  }

  public function testRuntimeTruthContractReportsCohereProvider(): void {
    $builder = self::readFile('web/modules/custom/ilas_site_assistant/src/Service/RuntimeTruthSnapshotBuilder.php');
    $diagnostics = self::readFile('web/modules/custom/ilas_site_assistant/src/Service/RuntimeDiagnosticsMatrixBuilder.php');

    $this->assertStringContainsString("'provider' => 'cohere'", $builder);
    $this->assertStringContainsString('request_time_generation_reachable', $builder);
    $this->assertStringContainsString("valueFact('llm.provider'", $diagnostics);
    $this->assertStringContainsString('llm.cohere_api_key_present', $diagnostics);
  }

}
