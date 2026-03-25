<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Config\MemoryStorage;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Site\Settings;
use Drupal\ilas_site_assistant\Service\ObservabilityProofTaxonomy;
use Drupal\ilas_site_assistant\Service\RetrievalConfigurationService;
use Drupal\ilas_site_assistant\Service\RuntimeDiagnosticsMatrixBuilder;
use Drupal\ilas_site_assistant\Service\RuntimeTruthSnapshotBuilder;
use Drupal\search_api\IndexInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Contract tests for the unified runtime-diagnostics matrix (AFRP-16).
 */
#[Group('ilas_site_assistant')]
final class RuntimeDiagnosticsMatrixContractTest extends TestCase {

  private const REQUIRED_TOP_LEVEL_KEYS = [
    'schema_version',
    'timestamp',
    'environment',
    'runtime_truth_summary',
    'diagnostics_matrix',
    'integration_status',
    'credential_inventory',
    'retrieval_inventory',
    'degraded_mode_state',
    'verification_commands',
  ];

  private const REQUIRED_MATRIX_ROW_KEYS = [
    'fact_key',
    'category',
    'current_value',
    'source',
    'proof_level',
    'proof_level_label',
    'static_proof_ceiling',
    'verification_command',
    'assertion',
  ];

  private const ALLOWED_ASSERTIONS = [
    ObservabilityProofTaxonomy::ASSERTION_PASS,
    ObservabilityProofTaxonomy::ASSERTION_FAIL,
    ObservabilityProofTaxonomy::ASSERTION_DEGRADED,
    ObservabilityProofTaxonomy::ASSERTION_SKIPPED,
  ];

  protected function tearDown(): void {
    new Settings([]);
    parent::tearDown();
  }

  /**
   * Output contains all required top-level keys.
   */
  public function testOutputContainsRequiredTopLevelKeys(): void {
    $diagnostics = $this->buildHealthyDiagnostics();
    $this->assertSame(self::REQUIRED_TOP_LEVEL_KEYS, array_keys($diagnostics));
  }

  /**
   * Every matrix row has exactly the required keys.
   */
  public function testMatrixRowsHaveRequiredKeys(): void {
    $diagnostics = $this->buildHealthyDiagnostics();
    $this->assertNotEmpty($diagnostics['diagnostics_matrix'], 'Matrix must contain at least one row');

    foreach ($diagnostics['diagnostics_matrix'] as $i => $row) {
      $this->assertSame(
        self::REQUIRED_MATRIX_ROW_KEYS,
        array_keys($row),
        "Matrix row {$i} ({$row['fact_key']}) must have exactly the required keys",
      );
    }
  }

  /**
   * All assertion values are from the allowed set.
   */
  public function testAssertionValuesAreFromAllowedSet(): void {
    $diagnostics = $this->buildHealthyDiagnostics();

    foreach ($diagnostics['diagnostics_matrix'] as $row) {
      $this->assertContains(
        $row['assertion'],
        self::ALLOWED_ASSERTIONS,
        "Matrix row '{$row['fact_key']}' assertion '{$row['assertion']}' is not in allowed set",
      );
    }
  }

  /**
   * All proof_level values reference valid taxonomy constants.
   */
  public function testProofLevelsAreValidTaxonomyConstants(): void {
    $diagnostics = $this->buildHealthyDiagnostics();

    foreach ($diagnostics['diagnostics_matrix'] as $row) {
      $this->assertContains(
        $row['proof_level'],
        ObservabilityProofTaxonomy::LEVELS_ORDERED,
        "Matrix row '{$row['fact_key']}' proof_level '{$row['proof_level']}' is not a valid taxonomy level",
      );
      $this->assertContains(
        $row['static_proof_ceiling'],
        ObservabilityProofTaxonomy::LEVELS_ORDERED,
        "Matrix row '{$row['fact_key']}' static_proof_ceiling '{$row['static_proof_ceiling']}' is not a valid taxonomy level",
      );
    }
  }

  /**
   * No secret values leak into the JSON output.
   */
  public function testNoSecretsInOutput(): void {
    new Settings([
      'ilas_observability' => [
        'environment' => 'dev',
        'pantheon_environment' => 'dev',
      ],
      'ilas_gemini_api_key' => 'gemini-secret-value',
      'ilas_vertex_sa_json' => '{"private_key":"vertex-secret-value"}',
      'ilas_site_assistant_legalserver_online_application_url' => 'https://example.com/intake?pid=60&h=secret-token',
      'ilas_assistant_diagnostics_token' => 'diag-secret-token',
    ]);

    $diagnostics = $this->buildDiagnosticsWithSettings([
      'ilas_site_assistant.settings' => [
        'llm.enabled' => FALSE,
        'vector_search.enabled' => FALSE,
        'langfuse.enabled' => TRUE,
        'langfuse.public_key' => 'pk-live-secret',
        'langfuse.secret_key' => 'sk-live-secret',
        'langfuse.environment' => 'dev',
        'langfuse.sample_rate' => 1.0,
      ],
      'raven.settings' => [
        'client_key' => 'https://sentry-secret@example.ingest.sentry.io/123',
        'public_dsn' => 'https://browser-secret@example.ingest.sentry.io/123',
      ],
      'key.key.pinecone_api_key' => [
        'key_provider_settings' => [
          'key_value' => 'pinecone-secret',
        ],
      ],
    ]);

    $json = json_encode($diagnostics, JSON_THROW_ON_ERROR);
    $this->assertStringNotContainsString('gemini-secret-value', $json);
    $this->assertStringNotContainsString('vertex-secret-value', $json);
    $this->assertStringNotContainsString('diag-secret-token', $json);
    $this->assertStringNotContainsString('secret-token', $json);
    $this->assertStringNotContainsString('pk-live-secret', $json);
    $this->assertStringNotContainsString('sk-live-secret', $json);
    $this->assertStringNotContainsString('sentry-secret@', $json);
    $this->assertStringNotContainsString('browser-secret@', $json);
    $this->assertStringNotContainsString('pinecone-secret', $json);
  }

  /**
   * Schema version is present and follows semver format.
   */
  public function testSchemaVersionPresent(): void {
    $diagnostics = $this->buildHealthyDiagnostics();
    $this->assertMatchesRegularExpression(
      '/^\d+\.\d+\.\d+$/',
      $diagnostics['schema_version'],
    );
  }

  /**
   * Credential inventory contains only boolean values.
   */
  public function testCredentialInventoryBooleanOnly(): void {
    $diagnostics = $this->buildHealthyDiagnostics();
    $this->assertNotEmpty($diagnostics['credential_inventory']);

    foreach ($diagnostics['credential_inventory'] as $key => $value) {
      $this->assertIsBool($value, "Credential inventory key '{$key}' must be boolean");
    }
  }

  /**
   * Disabled features produce 'skipped' assertions for gated dependencies.
   */
  public function testDisabledFeaturesProduceSkippedAssertions(): void {
    $diagnostics = $this->buildDiagnosticsWithVectorDisabled();
    $matrix = $diagnostics['diagnostics_matrix'];

    $vectorIndexRows = array_filter($matrix, static fn(array $row): bool => in_array($row['fact_key'], [
      'retrieval.faq_vector_index',
      'retrieval.resource_vector_index',
      'retrieval.pinecone_vector_faq_server',
      'retrieval.pinecone_vector_resources_server',
    ], TRUE));

    $this->assertNotEmpty($vectorIndexRows, 'Vector dependency rows must exist in matrix');

    foreach ($vectorIndexRows as $row) {
      $this->assertSame(
        ObservabilityProofTaxonomy::ASSERTION_SKIPPED,
        $row['assertion'],
        "Row '{$row['fact_key']}' must be 'skipped' when vector_search is disabled",
      );
    }
  }

  /**
   * Missing required index produces 'fail' assertion.
   */
  public function testMissingRequiredIndexProducesFailAssertion(): void {
    $diagnostics = $this->buildDiagnosticsWithMissingFaqIndex();
    $matrix = $diagnostics['diagnostics_matrix'];

    $faqRow = $this->findMatrixRow($matrix, 'retrieval.faq_index');
    $this->assertNotNull($faqRow, 'FAQ index row must exist in matrix');
    $this->assertSame(
      ObservabilityProofTaxonomy::ASSERTION_FAIL,
      $faqRow['assertion'],
      'Missing required FAQ index must produce fail assertion',
    );
  }

  /**
   * Healthy state produces no 'fail' assertions.
   */
  public function testHealthyStateProducesNoFailAssertions(): void {
    $diagnostics = $this->buildHealthyDiagnostics();

    $failures = array_filter(
      $diagnostics['diagnostics_matrix'],
      static fn(array $row): bool => $row['assertion'] === ObservabilityProofTaxonomy::ASSERTION_FAIL,
    );

    $failedKeys = array_map(static fn(array $row): string => $row['fact_key'], $failures);
    $this->assertEmpty($failures, 'Healthy environment must have no fail assertions, but found: ' . implode(', ', $failedKeys));
  }

  /**
   * Verification commands section contains expected keys.
   */
  public function testVerificationCommandsContainExpectedKeys(): void {
    $diagnostics = $this->buildHealthyDiagnostics();
    $commands = $diagnostics['verification_commands'];

    $expectedKeys = [
      'VC-RUNTIME-TRUTH',
      'VC-RUNTIME-DIAGNOSTICS',
      'VC-SENTRY-PROBE',
      'VC-LANGFUSE-PROBE-DIRECT',
      'VC-SEARCHAPI-INVENTORY',
      'VC-RUNTIME-LOCAL-SAFE',
    ];

    foreach ($expectedKeys as $key) {
      $this->assertArrayHasKey($key, $commands, "Verification commands must include {$key}");
      $this->assertIsString($commands[$key]);
    }
  }

  /**
   * Degraded mode state lists active degradations when present.
   */
  public function testDegradedModeStateListsActiveDegradations(): void {
    $diagnostics = $this->buildDiagnosticsWithDisabledFallbackIndex();
    $state = $diagnostics['degraded_mode_state'];

    $this->assertArrayHasKey('overall_status', $state);
    $this->assertArrayHasKey('active_degradations', $state);
    $this->assertArrayHasKey('feature_gates_off', $state);
    $this->assertArrayHasKey('missing_credentials', $state);
    $this->assertArrayHasKey('failed_facts', $state);

    $this->assertNotEmpty($state['active_degradations'], 'Degraded environment must list active degradations');
  }

  /**
   * Matrix categories are all from the FACT_CATEGORIES constant.
   */
  public function testMatrixCategoriesAreValid(): void {
    $diagnostics = $this->buildHealthyDiagnostics();

    foreach ($diagnostics['diagnostics_matrix'] as $row) {
      $this->assertContains(
        $row['category'],
        ObservabilityProofTaxonomy::FACT_CATEGORIES,
        "Matrix row '{$row['fact_key']}' category '{$row['category']}' is not in FACT_CATEGORIES",
      );
    }
  }

  /**
   * Integration status section contains expected integrations.
   */
  public function testIntegrationStatusContainsExpectedIntegrations(): void {
    $diagnostics = $this->buildHealthyDiagnostics();
    $integrations = $diagnostics['integration_status'];

    foreach (['sentry', 'langfuse', 'pinecone', 'voyage'] as $integration) {
      $this->assertArrayHasKey($integration, $integrations, "Integration status must include {$integration}");
      $this->assertArrayHasKey('enabled', $integrations[$integration]);
      $this->assertArrayHasKey('credential_present', $integrations[$integration]);
      $this->assertArrayHasKey('achieved_proof_level', $integrations[$integration]);
      $this->assertArrayHasKey('proof_ceiling', $integrations[$integration]);
      $this->assertArrayHasKey('verification_command', $integrations[$integration]);
    }
  }

  // -- Builders ----------------------------------------------------------

  /**
   * Builds diagnostics from a fully healthy environment.
   */
  private function buildHealthyDiagnostics(): array {
    new Settings([
      'ilas_observability' => [
        'environment' => 'dev',
        'pantheon_environment' => 'dev',
      ],
      'ilas_gemini_api_key' => 'test-key',
      'ilas_vertex_sa_json' => '{"key":"val"}',
      'ilas_site_assistant_legalserver_online_application_url' => 'https://example.com/intake?pid=60&h=test',
      'ilas_assistant_diagnostics_token' => 'test-diag-token',
      'ilas_voyage_api_key' => 'test-voyage-key',
    ]);

    return $this->buildDiagnosticsWithSettings(
      $this->healthyConfigValues(),
      $this->healthySyncData(),
      $this->buildEntityTypeManager($this->allHealthyIndexes(), $this->allHealthyServers()),
      TRUE,
    );
  }

  /**
   * Builds diagnostics with vector search disabled.
   */
  private function buildDiagnosticsWithVectorDisabled(): array {
    new Settings([
      'ilas_observability' => [
        'environment' => 'dev',
        'pantheon_environment' => 'dev',
      ],
      'ilas_site_assistant_legalserver_online_application_url' => 'https://example.com/intake?pid=60&h=test',
    ]);

    $config = $this->healthyConfigValues();
    $config['ilas_site_assistant.settings']['vector_search.enabled'] = FALSE;

    return $this->buildDiagnosticsWithSettings(
      $config,
      $this->healthySyncData(),
      $this->buildEntityTypeManager($this->allHealthyIndexes(), $this->allHealthyServers()),
      FALSE,
    );
  }

  /**
   * Builds diagnostics with missing FAQ index (required, active).
   */
  private function buildDiagnosticsWithMissingFaqIndex(): array {
    new Settings([
      'ilas_observability' => [
        'environment' => 'dev',
        'pantheon_environment' => 'dev',
      ],
      'ilas_site_assistant_legalserver_online_application_url' => 'https://example.com/intake?pid=60&h=test',
    ]);

    $indexes = $this->allHealthyIndexes();
    unset($indexes['faq_accordion']);

    return $this->buildDiagnosticsWithSettings(
      $this->healthyConfigValues(),
      $this->healthySyncData(),
      $this->buildEntityTypeManager($indexes, $this->allHealthyServers()),
    );
  }

  /**
   * Builds diagnostics with disabled fallback index (optional, active).
   */
  private function buildDiagnosticsWithDisabledFallbackIndex(): array {
    new Settings([
      'ilas_observability' => [
        'environment' => 'dev',
        'pantheon_environment' => 'dev',
      ],
      'ilas_site_assistant_legalserver_online_application_url' => 'https://example.com/intake?pid=60&h=test',
    ]);

    $indexes = $this->allHealthyIndexes();
    $disabledIndex = $this->createMock(IndexInterface::class);
    $disabledIndex->method('status')->willReturn(FALSE);
    $disabledIndex->method('getServerId')->willReturn('database');
    $indexes['content'] = $disabledIndex;

    return $this->buildDiagnosticsWithSettings(
      $this->healthyConfigValues(),
      $this->healthySyncData(),
      $this->buildEntityTypeManager($indexes, $this->allHealthyServers()),
    );
  }

  /**
   * Builds diagnostics from explicit config values.
   */
  private function buildDiagnosticsWithSettings(
    array $configValues,
    ?array $syncData = NULL,
    ?EntityTypeManagerInterface $entityTypeManager = NULL,
    bool $vectorEnabled = TRUE,
  ): array {
    $syncStorage = new MemoryStorage();
    foreach (($syncData ?? $this->healthySyncData()) as $name => $data) {
      $syncStorage->write($name, $data);
    }

    $configFactory = $this->buildConfigFactory($configValues);

    $retrievalConfig = $this->createStub(ImmutableConfig::class);
    $retrievalConfig->method('get')->willReturnCallback(static function (string $key) use ($configValues, $vectorEnabled) {
      $retrieval = [
        'faq_index_id' => 'faq_accordion',
        'resource_index_id' => 'assistant_resources',
        'resource_fallback_index_id' => 'content',
        'faq_vector_index_id' => 'faq_accordion_vector',
        'resource_vector_index_id' => 'assistant_resources_vector',
      ];

      return match ($key) {
        'retrieval' => $retrieval,
        'canonical_urls' => [
          'service_areas' => [
            'housing' => '/legal-help/housing',
            'family' => '/legal-help/family',
            'seniors' => '/legal-help/seniors',
            'health' => '/legal-help/health',
            'consumer' => '/legal-help/consumer',
            'civil_rights' => '/legal-help/civil-rights',
          ],
        ],
        'enable_faq' => TRUE,
        'enable_resources' => TRUE,
        'vector_search.enabled' => $vectorEnabled,
        default => NULL,
      };
    });

    $retrievalConfigFactory = $this->createStub(ConfigFactoryInterface::class);
    $retrievalConfigFactory->method('get')
      ->with('ilas_site_assistant.settings')
      ->willReturn($retrievalConfig);

    $etm = $entityTypeManager ?? $this->buildEntityTypeManager($this->allHealthyIndexes(), $this->allHealthyServers());

    $retrievalService = new RetrievalConfigurationService($retrievalConfigFactory, $etm);
    $snapshotBuilder = new RuntimeTruthSnapshotBuilder($configFactory, $syncStorage, $retrievalService);

    $builder = new RuntimeDiagnosticsMatrixBuilder(
      $snapshotBuilder,
      $retrievalService,
    );

    return $builder->buildDiagnostics();
  }

  /**
   * Returns config values for a healthy environment.
   */
  private function healthyConfigValues(): array {
    return [
      'ilas_site_assistant.settings' => [
        'llm.enabled' => FALSE,
        'llm.provider' => 'gemini',
        'llm.model' => 'gemini-2.0-flash',
        'llm.api_key' => '',
        'llm.project_id' => '',
        'llm.location' => '',
        'llm.fallback_on_error' => TRUE,
        'llm.global_rate_limit.max_per_hour' => 100,
        'llm.global_rate_limit.window_seconds' => 3600,
        'vector_search.enabled' => TRUE,
        'langfuse.enabled' => TRUE,
        'langfuse.public_key' => 'pk-test',
        'langfuse.secret_key' => 'sk-test',
        'langfuse.environment' => 'dev',
        'langfuse.sample_rate' => 1.0,
        'rate_limit_per_minute' => 10,
        'rate_limit_per_hour' => 60,
        'voyage.enabled' => FALSE,
        'voyage.rerank_model' => 'rerank-2',
        'voyage.api_timeout' => 5.0,
        'voyage.max_candidates' => 50,
        'voyage.top_k' => 10,
        'voyage.min_results_to_rerank' => 3,
        'voyage.fallback_on_error' => TRUE,
        'conversation_logging.enabled' => TRUE,
        'conversation_logging.retention_hours' => 720,
        'conversation_logging.redact_pii' => TRUE,
        'conversation_logging.show_user_notice' => TRUE,
        'session_bootstrap.rate_limit_per_minute' => 30,
        'session_bootstrap.rate_limit_per_hour' => 300,
        'session_bootstrap.observation_window_hours' => 24,
        'read_endpoint_rate_limits' => [
          'suggest' => ['rate_limit_per_minute' => 60, 'rate_limit_per_hour' => 600],
          'faq' => ['rate_limit_per_minute' => 60, 'rate_limit_per_hour' => 600],
        ],
        'cost_control' => [
          'daily_call_limit' => 500,
          'monthly_call_limit' => 10000,
          'per_ip_hourly_call_limit' => 20,
          'per_ip_window_seconds' => 3600,
          'sample_rate' => 1.0,
          'cache_hit_rate_target' => 0.5,
          'cache_stats_window_seconds' => 3600,
          'manual_kill_switch' => FALSE,
          'alert_cooldown_minutes' => 30,
        ],
      ],
      'raven.settings' => [
        'client_key' => 'https://test@sentry.io/1',
        'public_dsn' => 'https://test-public@sentry.io/1',
        'environment' => 'dev',
        'release' => 'dev_1',
        'javascript_error_handler' => TRUE,
        'browser_traces_sample_rate' => 0.01,
        'show_report_dialog' => FALSE,
      ],
      'key.key.pinecone_api_key' => [
        'key_provider_settings' => [
          'key_value' => 'test-pinecone-key',
        ],
      ],
    ];
  }

  /**
   * Returns sync storage data for a healthy environment.
   */
  private function healthySyncData(): array {
    return [
      'ilas_site_assistant.settings' => [
        'llm' => ['enabled' => FALSE],
        'vector_search' => ['enabled' => FALSE],
        'langfuse' => [
          'enabled' => FALSE,
          'public_key' => '',
          'secret_key' => '',
          'environment' => '',
          'sample_rate' => 0.0,
        ],
      ],
      'key.key.pinecone_api_key' => [
        'key_provider_settings' => ['key_value' => ''],
      ],
    ];
  }

  /**
   * Returns a set of all-healthy Search API indexes.
   */
  private function allHealthyIndexes(): array {
    $enabledDatabaseIndex = $this->createMock(IndexInterface::class);
    $enabledDatabaseIndex->method('status')->willReturn(TRUE);
    $enabledDatabaseIndex->method('getServerId')->willReturn('database');

    $enabledFaqVectorIndex = $this->createMock(IndexInterface::class);
    $enabledFaqVectorIndex->method('status')->willReturn(TRUE);
    $enabledFaqVectorIndex->method('getServerId')->willReturn('pinecone_vector_faq');

    $enabledResourceVectorIndex = $this->createMock(IndexInterface::class);
    $enabledResourceVectorIndex->method('status')->willReturn(TRUE);
    $enabledResourceVectorIndex->method('getServerId')->willReturn('pinecone_vector_resources');

    return [
      'faq_accordion' => $enabledDatabaseIndex,
      'assistant_resources' => $enabledDatabaseIndex,
      'content' => $enabledDatabaseIndex,
      'faq_accordion_vector' => $enabledFaqVectorIndex,
      'assistant_resources_vector' => $enabledResourceVectorIndex,
    ];
  }

  /**
   * Returns a set of all-healthy Search API servers.
   */
  private function allHealthyServers(): array {
    $enabledServer = new class {

      public function status(): bool {
        return TRUE;
      }

    };

    return [
      'database' => $enabledServer,
      'pinecone_vector_faq' => $enabledServer,
      'pinecone_vector_resources' => $enabledServer,
    ];
  }

  /**
   * Builds an EntityTypeManager stub for Search API.
   */
  private function buildEntityTypeManager(array $indexes, array $servers): EntityTypeManagerInterface {
    $indexStorage = $this->createMock(EntityStorageInterface::class);
    $indexStorage->method('load')->willReturnCallback(static fn(string $id) => $indexes[$id] ?? NULL);

    $serverStorage = $this->createMock(EntityStorageInterface::class);
    $serverStorage->method('load')->willReturnCallback(static fn(string $id) => $servers[$id] ?? NULL);

    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getStorage')->willReturnCallback(static fn(string $entityTypeId) => match ($entityTypeId) {
      'search_api_index' => $indexStorage,
      'search_api_server' => $serverStorage,
      default => throw new \InvalidArgumentException('Unexpected storage: ' . $entityTypeId),
    });

    return $entityTypeManager;
  }

  /**
   * Builds a config factory stub.
   */
  private function buildConfigFactory(array $configValues): ConfigFactoryInterface {
    $configs = [];

    foreach ($configValues as $configName => $values) {
      $config = $this->createStub(ImmutableConfig::class);
      $config->method('get')->willReturnCallback(static fn(string $key): mixed => $values[$key] ?? NULL);
      $configs[$configName] = $config;
    }

    $empty = $this->createStub(ImmutableConfig::class);
    $empty->method('get')->willReturn(NULL);

    $configFactory = $this->createStub(ConfigFactoryInterface::class);
    $configFactory->method('get')->willReturnCallback(static fn(string $name) => $configs[$name] ?? $empty);

    return $configFactory;
  }

  /**
   * Finds a matrix row by fact_key.
   */
  private function findMatrixRow(array $matrix, string $factKey): ?array {
    foreach ($matrix as $row) {
      if ($row['fact_key'] === $factKey) {
        return $row;
      }
    }

    return NULL;
  }

}
