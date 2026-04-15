<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Guards retirement of the dormant Vertex assistant runtime path.
 */
#[Group('ilas_site_assistant')]
final class VertexRuntimeCredentialGuardTest extends TestCase {

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

  public function testSettingsPhpNoLongerLoadsVertexAssistantSecret(): void {
    $settings = self::readFile('web/sites/default/settings.php');

    $this->assertStringNotContainsString('ILAS_VERTEX_SA_JSON', $settings);
    $this->assertStringNotContainsString('ilas_vertex_sa_json', $settings);
  }

  public function testAssistantCodeNoLongerContainsVertexTransportSurface(): void {
    $enhancer = self::readFile('web/modules/custom/ilas_site_assistant/src/Service/LlmEnhancer.php');
    $readme = self::readFile('web/modules/custom/ilas_site_assistant/README.md');

    $this->assertStringNotContainsString('VERTEX_AI_ENDPOINT', $enhancer);
    $this->assertStringNotContainsString('Vertex AI', $readme);
    $this->assertStringNotContainsString('ILAS_VERTEX_SA_JSON', $readme);
  }

  public function testRuntimeSiteSettingKeyProviderDefaultIsNoLongerVertexSpecific(): void {
    $provider = self::readFile('web/modules/custom/ilas_site_assistant/src/Plugin/KeyProvider/RuntimeSiteSettingKeyProvider.php');

    $this->assertStringContainsString("'settings_key' => ''", $provider);
    $this->assertStringNotContainsString("'settings_key' => 'ilas_vertex_sa_json'", $provider);
  }

}
