<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Enforces config completeness parity: install defaults vs active vs schema.
 *
 * IMP-CONF-01 / CLAIM-124: Prevents config drift by asserting that the active
 * config export contains all top-level keys from install defaults, the schema
 * covers all install defaults, active config has no orphan keys, LLM sub-keys
 * are complete, and disabled-by-default blocks remain disabled in install.
 */
#[Group('ilas_site_assistant')]
class ConfigCompletenessDriftTest extends TestCase {

  private const MODULE_PATH = 'web/modules/custom/ilas_site_assistant';

  /**
   * Returns the repository root by walking up from __DIR__.
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
   * Every top-level key in install defaults must exist in active config.
   *
   * Catches the exact drift problem this task resolves: blocks present in
   * install defaults but missing from the exported active config.
   */
  public function testActiveConfigContainsAllInstallTopLevelKeys(): void {
    $install = self::installConfig();
    $active = self::activeConfig();

    $installKeys = array_keys($install);
    $activeKeys = array_keys($active);

    // _core is an active-config-only Drupal internal key; exclude from check.
    $activeKeysFiltered = array_diff($activeKeys, ['_core']);
    $installKeysFiltered = array_diff($installKeys, ['_core']);

    $missingFromActive = array_diff($installKeysFiltered, $activeKeysFiltered);
    $this->assertEmpty(
      $missingFromActive,
      'Active config is missing top-level keys present in install defaults: ' . implode(', ', $missingFromActive),
    );
  }

  /**
   * Every top-level key in install defaults must have a schema mapping entry.
   */
  public function testSchemaCoversAllInstallTopLevelKeys(): void {
    $install = self::installConfig();
    $schema = self::schemaConfig();

    $installKeys = array_keys($install);
    $schemaMapping = $schema['ilas_site_assistant.settings']['mapping'] ?? [];
    $schemaKeys = array_keys($schemaMapping);

    $missingFromSchema = array_diff($installKeys, $schemaKeys);
    $this->assertEmpty(
      $missingFromSchema,
      'Install default top-level keys missing from schema: ' . implode(', ', $missingFromSchema),
    );
  }

  /**
   * Active config must not contain keys absent from install defaults.
   *
   * Catches stale/orphaned config keys that no longer have install defaults.
   * The only exception is `_core` which Drupal adds automatically.
   */
  public function testActiveConfigHasNoOrphanTopLevelKeys(): void {
    $install = self::installConfig();
    $active = self::activeConfig();

    $installKeys = array_keys($install);
    $activeKeys = array_keys($active);

    // _core is Drupal-internal metadata, not an application config key.
    $orphans = array_diff($activeKeys, $installKeys, ['_core']);
    $this->assertEmpty(
      $orphans,
      'Active config contains top-level keys absent from install defaults (orphans): ' . implode(', ', $orphans),
    );
  }

  /**
   * All LLM sub-keys from install defaults exist in active config and schema.
   */
  public function testLlmSubKeysComplete(): void {
    $install = self::installConfig();
    $active = self::activeConfig();
    $schema = self::schemaConfig();

    $this->assertArrayHasKey('llm', $install, 'Install defaults must define llm block');
    $this->assertArrayHasKey('llm', $active, 'Active config must define llm block');

    $installLlmKeys = $this->flattenKeys($install['llm'], 'llm');
    $activeLlmKeys = $this->flattenKeys($active['llm'], 'llm');

    // Every install LLM key must exist in active config.
    $missingFromActive = array_diff($installLlmKeys, $activeLlmKeys);
    $this->assertEmpty(
      $missingFromActive,
      'Active config llm block is missing sub-keys: ' . implode(', ', $missingFromActive),
    );

    // Schema must cover all LLM sub-keys (check top-level llm mapping keys).
    $schemaLlmMapping = $schema['ilas_site_assistant.settings']['mapping']['llm']['mapping'] ?? [];
    $installLlmTopKeys = array_keys($install['llm']);
    $schemaLlmKeys = array_keys($schemaLlmMapping);

    $missingFromSchema = array_diff($installLlmTopKeys, $schemaLlmKeys);
    $this->assertEmpty(
      $missingFromSchema,
      'Schema llm mapping is missing sub-keys: ' . implode(', ', $missingFromSchema),
    );
  }

  /**
   * Vertex service-account JSON must not be exportable via config anymore.
   */
  public function testVertexServiceAccountJsonIsAbsentFromConfigContracts(): void {
    $install = self::installConfig();
    $active = self::activeConfig();
    $schema = self::schemaConfig();

    $this->assertArrayNotHasKey(
      'service_account_json',
      $install['llm'],
      'Install config must not define llm.service_account_json',
    );
    $this->assertArrayNotHasKey(
      'service_account_json',
      $active['llm'],
      'Active config export must not define llm.service_account_json',
    );
    $this->assertArrayNotHasKey(
      'service_account_json',
      $schema['ilas_site_assistant.settings']['mapping']['llm']['mapping'],
      'Schema must not define llm.service_account_json',
    );
  }

  /**
   * LegalServer intake URL must remain runtime-only, not exported config.
   */
  public function testCanonicalUrlContractOmitsExportedLegalServerUrl(): void {
    $install = self::installConfig();
    $active = self::activeConfig();
    $schema = self::schemaConfig();

    $this->assertArrayHasKey('canonical_urls', $install);
    $this->assertArrayHasKey('canonical_urls', $active);

    $this->assertArrayNotHasKey('online_application', $install['canonical_urls']);
    $this->assertArrayNotHasKey('online_application', $active['canonical_urls']);
    $this->assertArrayNotHasKey(
      'online_application',
      $schema['ilas_site_assistant.settings']['mapping']['canonical_urls']['mapping'] ?? []
    );
  }

  /**
   * Retrieval IDs must live in retrieval.* and not in duplicate policy blocks.
   */
  public function testRetrievalConfigOwnershipAndHygieneContract(): void {
    $install = self::installConfig();
    $active = self::activeConfig();
    $schema = self::schemaConfig();

    $required_retrieval_keys = [
      'faq_index_id',
      'resource_index_id',
      'resource_fallback_index_id',
      'faq_vector_index_id',
      'resource_vector_index_id',
    ];

    foreach ($required_retrieval_keys as $key) {
      $this->assertArrayHasKey($key, $install['retrieval'], "Install retrieval missing {$key}");
      $this->assertArrayHasKey($key, $active['retrieval'], "Active retrieval missing {$key}");
      $this->assertArrayHasKey(
        $key,
        $schema['ilas_site_assistant.settings']['mapping']['retrieval']['mapping'] ?? [],
        "Schema retrieval missing {$key}"
      );
    }

    foreach (['faq_vector', 'resource_vector'] as $managed_index) {
      $this->assertArrayNotHasKey('index_id', $install['vector_index_hygiene']['managed_indexes'][$managed_index] ?? []);
      $this->assertArrayNotHasKey('index_id', $active['vector_index_hygiene']['managed_indexes'][$managed_index] ?? []);
    }
  }

  /**
   * Cost-control block must exist in install + active config with key fields.
   */
  public function testCostControlBlockPresentAndComplete(): void {
    $install = self::installConfig();
    $active = self::activeConfig();

    $this->assertArrayHasKey('cost_control', $install, 'Install config must define cost_control');
    $this->assertArrayHasKey('cost_control', $active, 'Active config must define cost_control');

    $required_keys = [
      'daily_call_limit',
      'monthly_call_limit',
      'per_ip_hourly_call_limit',
      'per_ip_window_seconds',
      'sample_rate',
      'cache_hit_rate_target',
      'cache_stats_window_seconds',
      'manual_kill_switch',
      'pricing',
      'alert_cooldown_minutes',
    ];

    foreach ($required_keys as $key) {
      $this->assertArrayHasKey($key, $install['cost_control'], "Install cost_control missing key {$key}");
      $this->assertArrayHasKey($key, $active['cost_control'], "Active cost_control missing key {$key}");
    }

    $this->assertIsArray($install['cost_control']['pricing'], 'Install cost_control.pricing must be an array');
    $this->assertIsArray($active['cost_control']['pricing'], 'Active cost_control.pricing must be an array');

    foreach (['model', 'input_per_1m_tokens', 'output_per_1m_tokens'] as $pricing_key) {
      $this->assertArrayHasKey($pricing_key, $install['cost_control']['pricing'], "Install pricing missing {$pricing_key}");
      $this->assertArrayHasKey($pricing_key, $active['cost_control']['pricing'], "Active pricing missing {$pricing_key}");
    }
  }

  /**
   * Blocks with `enabled` sub-keys must all be disabled in install defaults.
   *
   * Ensures no feature is accidentally shipped enabled before operator review.
   */
  public function testDisabledByDefaultBlocks(): void {
    $install = self::installConfig();

    $blocksWithEnabledFlag = [
      'llm',
      'vector_search',
      'safety_alerting',
      'ab_testing',
      'langfuse',
    ];

    foreach ($blocksWithEnabledFlag as $block) {
      $this->assertArrayHasKey($block, $install, "Install defaults must define block: {$block}");
      $this->assertArrayHasKey('enabled', $install[$block], "Block {$block} must have an 'enabled' key");
      $this->assertFalse(
        $install[$block]['enabled'],
        "Block {$block} must be disabled (enabled: false) in install defaults",
      );
    }
  }

  /**
   * Recursively flattens array keys into dot-notation paths.
   *
   * @param array $data
   *   The array to flatten.
   * @param string $prefix
   *   The key prefix for dot notation.
   *
   * @return string[]
   *   Flat list of dot-notation key paths.
   */
  private function flattenKeys(array $data, string $prefix = ''): array {
    $keys = [];
    foreach ($data as $key => $value) {
      $fullKey = $prefix ? "{$prefix}.{$key}" : $key;
      $keys[] = $fullKey;
      if (is_array($value) && !array_is_list($value)) {
        $keys = array_merge($keys, $this->flattenKeys($value, $fullKey));
      }
    }
    return $keys;
  }

}
