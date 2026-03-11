<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Guards the runtime-only LegalServer intake URL posture for RAUD-21.
 */
#[Group('ilas_site_assistant')]
final class LegalServerRuntimeUrlGuardTest extends TestCase {

  /**
   * Returns the repository root path.
   */
  private static function repoRoot(): string {
    return dirname(__DIR__, 7);
  }

  /**
   * Reads a file from the repo.
   */
  private static function readFile(string $relativePath): string {
    $path = self::repoRoot() . '/' . ltrim($relativePath, '/');
    self::assertFileExists($path, "Expected file does not exist: {$relativePath}");

    $contents = file_get_contents($path);
    self::assertIsString($contents, "Failed reading file: {$relativePath}");
    return $contents;
  }

  /**
   * Parses a YAML file from the repo.
   */
  private static function readYaml(string $relativePath): array {
    $path = self::repoRoot() . '/' . ltrim($relativePath, '/');
    self::assertFileExists($path, "Expected YAML file does not exist: {$relativePath}");

    $parsed = Yaml::parseFile($path);
    self::assertIsArray($parsed, "Failed parsing YAML file: {$relativePath}");
    return $parsed;
  }

  /**
   * settings.php must resolve the LegalServer URL into a site setting only.
   */
  public function testSettingsPhpStoresLegalServerUrlInSiteSettingsOnly(): void {
    $settings = self::readFile('web/sites/default/settings.php');

    $this->assertStringContainsString("_ilas_get_secret('ILAS_LEGALSERVER_ONLINE_APPLICATION_URL')", $settings);
    $this->assertStringContainsString("\$settings['ilas_site_assistant_legalserver_online_application_url'] = \$ilas_legalserver_online_application_url;", $settings);
    $this->assertStringNotContainsString("['canonical_urls']['online_application']", $settings);
  }

  /**
   * Exported config and schema must omit the LegalServer URL and keep retrieval.
   */
  public function testConfigContractsOmitOnlineApplicationAndRetainRetrievalBlock(): void {
    $install = self::readYaml('web/modules/custom/ilas_site_assistant/config/install/ilas_site_assistant.settings.yml');
    $active = self::readYaml('config/ilas_site_assistant.settings.yml');
    $schema = self::readYaml('web/modules/custom/ilas_site_assistant/config/schema/ilas_site_assistant.schema.yml');

    $this->assertArrayHasKey('retrieval', $install);
    $this->assertArrayHasKey('retrieval', $active);
    $this->assertArrayHasKey('retrieval', $schema['ilas_site_assistant.settings']['mapping'] ?? []);

    $this->assertArrayNotHasKey('online_application', $install['canonical_urls']);
    $this->assertArrayNotHasKey('online_application', $active['canonical_urls']);
    $this->assertArrayNotHasKey(
      'online_application',
      $schema['ilas_site_assistant.settings']['mapping']['canonical_urls']['mapping'] ?? []
    );
  }

  /**
   * The settings form must show a runtime notice instead of an editable field.
   */
  public function testSettingsFormUsesRuntimeNoticeInsteadOfEditableLegalServerField(): void {
    $form = self::readFile('web/modules/custom/ilas_site_assistant/src/Form/AssistantSettingsForm.php');

    $this->assertStringContainsString('legalserver_online_application_runtime_notice', $form);
    $this->assertStringContainsString('ILAS_LEGALSERVER_ONLINE_APPLICATION_URL', $form);
    $this->assertStringNotContainsString("['urls']['url_online_application'] = [", $form);
  }

}
