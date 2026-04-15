<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Guards the post-transition provider/channel boundary.
 */
#[Group('ilas_site_assistant')]
final class PhaseThreeNoNetNewAssistantChannelsOrModelExpansionGuardTest extends TestCase {

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

  public function testAssistantChannelSurfaceRemainsUnchanged(): void {
    $routing = self::readFile('web/modules/custom/ilas_site_assistant/ilas_site_assistant.routing.yml');

    $this->assertStringContainsString("path: '/assistant'", $routing);
    $this->assertStringContainsString("path: '/assistant/api/message'", $routing);
    $this->assertStringContainsString("path: '/assistant/api/session/bootstrap'", $routing);
    $this->assertStringContainsString("path: '/assistant/api/suggest'", $routing);
    $this->assertStringContainsString("path: '/assistant/api/faq'", $routing);
  }

  public function testProviderContractIsCohereRequestTimeWithVoyageEmbeddings(): void {
    $services = self::readFile('web/modules/custom/ilas_site_assistant/ilas_site_assistant.services.yml');
    $enhancer = self::readFile('web/modules/custom/ilas_site_assistant/src/Service/LlmEnhancer.php');
    $systemMap = self::readFile('docs/aila/system-map.mmd');

    $this->assertStringContainsString('ilas_site_assistant.cohere_llm_transport', $services);
    $this->assertStringContainsString('Greeting variation remains retired in the Cohere-first transition.', $enhancer);
    $this->assertStringNotContainsString('callGeminiApi', $enhancer);
    $this->assertStringNotContainsString('callVertexAi', $enhancer);
    $this->assertStringContainsString('Cohere request-time classification', $systemMap);
    $this->assertStringContainsString('Gemini residual', $systemMap);
  }

  public function testUiAndConfigRemainSecretlessAndDoNotExposeProviderDropdowns(): void {
    $form = self::readFile('web/modules/custom/ilas_site_assistant/src/Form/AssistantSettingsForm.php');
    $schema = self::readFile('web/modules/custom/ilas_site_assistant/config/schema/ilas_site_assistant.schema.yml');
    $install = self::readFile('web/modules/custom/ilas_site_assistant/config/install/ilas_site_assistant.settings.yml');

    $this->assertStringContainsString('ILAS_COHERE_API_KEY', $form);
    $this->assertStringNotContainsString('gemini_api', $form);
    $this->assertStringNotContainsString('vertex_ai', $form);
    $this->assertStringNotContainsString('LLM provider (gemini_api or vertex_ai)', $schema);
    $this->assertStringNotContainsString("provider: 'gemini_api'", $install);
  }

}
