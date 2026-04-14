<?php

namespace Drupal\Tests\ilas_site_assistant\Unit;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Validates retrieval/vector config schema contracts and ownership boundaries.
 */
#[Group('ilas_site_assistant')]
class VectorSearchConfigSchemaTest extends TestCase {

  private const MODULE_PATH = 'web/modules/custom/ilas_site_assistant';

  /**
   * Returns the repository root by walking up from __DIR__.
   */
  private static function repoRoot(): string {
    return dirname(__DIR__, 7);
  }

  /**
   * Returns parsed install config.
   */
  private static function installConfig(): array {
    return Yaml::parseFile(self::repoRoot() . '/' . self::MODULE_PATH . '/config/install/ilas_site_assistant.settings.yml');
  }

  /**
   * Returns parsed active config.
   */
  private static function activeConfig(): array {
    return Yaml::parseFile(self::repoRoot() . '/config/ilas_site_assistant.settings.yml');
  }

  /**
   * Returns parsed schema config.
   */
  private static function schemaConfig(): array {
    return Yaml::parseFile(self::repoRoot() . '/' . self::MODULE_PATH . '/config/schema/ilas_site_assistant.schema.yml');
  }

  /**
   * Returns parsed Search API index config.
   */
  private static function indexConfig(string $path): array {
    return Yaml::parseFile(self::repoRoot() . '/' . ltrim($path, '/'));
  }

  /**
   * Tests that every retrieval key in install defaults has a schema entry.
   */
  public function testRetrievalSchemaCoversAllInstallDefaultKeys(): void {
    $install = self::installConfig();
    $schema = self::schemaConfig();

    $this->assertArrayHasKey('retrieval', $install, 'Install defaults must define retrieval');
    $this->assertArrayHasKey(
      'retrieval',
      $schema['ilas_site_assistant.settings']['mapping'] ?? [],
      'Schema must define retrieval mapping'
    );

    $install_keys = array_keys($install['retrieval']);
    $schema_mapping = $schema['ilas_site_assistant.settings']['mapping']['retrieval']['mapping'] ?? [];
    $schema_keys = array_keys($schema_mapping);

    $this->assertEmpty(
      array_diff($install_keys, $schema_keys),
      'Retrieval install default keys missing from schema: ' . implode(', ', array_diff($install_keys, $schema_keys))
    );
    $this->assertEmpty(
      array_diff($schema_keys, $install_keys),
      'Retrieval schema keys without install defaults: ' . implode(', ', array_diff($schema_keys, $install_keys))
    );
  }

  /**
   * Back-compat anchor for cross-phase gating around schema coverage.
   */
  public function testSchemaCoversAllInstallDefaultKeys(): void {
    $this->testRetrievalSchemaCoversAllInstallDefaultKeys();
  }

  /**
   * Tests that retrieval schema types match the install default value types.
   */
  public function testRetrievalSchemaTypesMatchInstallDefaults(): void {
    $install_values = self::installConfig()['retrieval'];
    $schema_mapping = self::schemaConfig()['ilas_site_assistant.settings']['mapping']['retrieval']['mapping'];

    foreach ($install_values as $key => $value) {
      $this->assertArrayHasKey($key, $schema_mapping, "Schema must define key: $key");
      $this->assertSame(
        'string',
        $schema_mapping[$key]['type'],
        "retrieval.{$key} must use string schema type"
      );
      $this->assertIsString($value, "retrieval.{$key} install default must be a string");
    }
  }

  /**
   * Tests that active config retrieval values match install defaults.
   */
  public function testActiveRetrievalValuesMatchInstallDefaults(): void {
    $install = self::installConfig();
    $active = self::activeConfig();

    $this->assertArrayHasKey('retrieval', $active, 'Active config must include retrieval block');

    foreach ($install['retrieval'] as $key => $expected) {
      $this->assertArrayHasKey($key, $active['retrieval'], "Active config retrieval missing key: {$key}");
      $this->assertSame($expected, $active['retrieval'][$key], "retrieval.{$key}: active value drifted from install default");
    }
  }

  /**
   * Back-compat anchor for cross-phase gating around active config parity.
   */
  public function testActiveVectorSearchValuesMatchInstallDefaults(): void {
    $install = self::installConfig();
    $active = self::activeConfig();

    foreach ($install['vector_search'] as $key => $expected) {
      $this->assertArrayHasKey($key, $active['vector_search'], "Active config vector_search missing key: {$key}");
      if (is_numeric($expected) && is_numeric($active['vector_search'][$key])) {
        $this->assertEqualsWithDelta($expected, $active['vector_search'][$key], 0.001);
        continue;
      }
      $this->assertSame($expected, $active['vector_search'][$key]);
    }
  }

  /**
   * Tests that vector_search no longer owns index identifiers.
   */
  public function testVectorSearchContractNoLongerOwnsIndexIdentifiers(): void {
    $install = self::installConfig();
    $active = self::activeConfig();
    $schema = self::schemaConfig();

    foreach (['faq_index_id', 'resource_index_id'] as $removed_key) {
      $this->assertArrayNotHasKey($removed_key, $install['vector_search']);
      $this->assertArrayNotHasKey($removed_key, $active['vector_search']);
      $this->assertArrayNotHasKey(
        $removed_key,
        $schema['ilas_site_assistant.settings']['mapping']['vector_search']['mapping'] ?? []
      );
    }
  }

  /**
   * Tests that lexical index configs are tracked in active sync.
   */
  public function testLexicalIndexConfigsAreTrackedInActiveSync(): void {
    foreach ([
      'faq_accordion' => 'config/search_api.index.faq_accordion.yml',
      'assistant_resources' => 'config/search_api.index.assistant_resources.yml',
    ] as $index_id => $relative_path) {
      $config = self::indexConfig($relative_path);
      $this->assertSame($index_id, $config['id'] ?? NULL, sprintf('Active sync must track lexical index "%s".', $index_id));
      $this->assertSame('database', $config['server'] ?? NULL, sprintf('Lexical index "%s" must remain on the database Search API server.', $index_id));
    }
  }

  /**
   * Tests that active-sync lexical index definitions match install config.
   */
  public function testLexicalIndexActiveSyncMatchesInstallDefinitions(): void {
    $pairs = [
      'faq_accordion' => [
        'active' => 'config/search_api.index.faq_accordion.yml',
        'install' => self::MODULE_PATH . '/config/install/search_api.index.faq_accordion.yml',
      ],
      'assistant_resources' => [
        'active' => 'config/search_api.index.assistant_resources.yml',
        'install' => self::MODULE_PATH . '/config/install/search_api.index.assistant_resources.yml',
      ],
    ];

    foreach ($pairs as $index_id => $paths) {
      $active = self::indexConfig($paths['active']);
      $install = self::indexConfig($paths['install']);
      $this->assertSame($install, $active, sprintf('Active-sync lexical index "%s" must match module install config exactly.', $index_id));
    }
  }

  /**
   * Tests that vector indexes never direct-index on editorial saves.
   */
  public function testVectorIndexesDisableDirectIndexingInActiveSync(): void {
    $pairs = [
      'faq_accordion_vector' => 'config/search_api.index.faq_accordion_vector.yml',
      'assistant_resources_vector' => 'config/search_api.index.assistant_resources_vector.yml',
    ];

    foreach ($pairs as $index_id => $relative_path) {
      $config = self::indexConfig($relative_path);
      $this->assertSame($index_id, $config['id'] ?? NULL);
      $this->assertFalse(
        (bool) ($config['options']['index_directly'] ?? TRUE),
        sprintf('Vector index "%s" must keep options.index_directly disabled.', $index_id),
      );
    }
  }

}
