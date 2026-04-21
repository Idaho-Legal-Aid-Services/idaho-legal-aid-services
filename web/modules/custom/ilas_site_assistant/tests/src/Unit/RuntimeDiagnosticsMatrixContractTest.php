<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Site\Settings;
use Drupal\ilas_site_assistant\Service\RetrievalConfigurationService;
use Drupal\ilas_site_assistant\Service\RuntimeDiagnosticsMatrixBuilder;
use Drupal\ilas_site_assistant\Service\RuntimeTruthSnapshotBuilder;
use Drupal\search_api\IndexInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Diagnostics matrix coverage for the Cohere-first runtime contract.
 */
#[Group('ilas_site_assistant')]
final class RuntimeDiagnosticsMatrixContractTest extends TestCase {

  protected function tearDown(): void {
    new Settings([]);
    parent::tearDown();
  }

  public function testDiagnosticsExposeCohereFactsAndHideRetiredGoogleFacts(): void {
    $snapshotBuilder = $this->createStub(RuntimeTruthSnapshotBuilder::class);
    $snapshotBuilder->method('buildSnapshot')->willReturn($this->buildSnapshot(FALSE, TRUE));

    $builder = new RuntimeDiagnosticsMatrixBuilder($snapshotBuilder, $this->buildRetrievalConfiguration());
    $diagnostics = $builder->buildDiagnostics();

    $factKeys = array_column($diagnostics['diagnostics_matrix'] ?? [], 'fact_key');
    $this->assertContains('llm.provider', $factKeys);
    $this->assertContains('llm.model', $factKeys);
    $this->assertContains('llm.request_time_generation_reachable', $factKeys);
    $this->assertContains('llm.cohere_api_key_present', $factKeys);
    $this->assertNotContains('llm.request_time_retired', $factKeys);
    $this->assertNotContains('llm.google_generation_reachable', $factKeys);

    $this->assertArrayHasKey('cohere_api_key', $diagnostics['credential_inventory'] ?? []);
    $this->assertArrayNotHasKey('vertex_service_account', $diagnostics['credential_inventory'] ?? []);
  }

  public function testDiagnosticsReflectVoyageReadyWhileLiveVectorRemainsDisabled(): void {
    $snapshotBuilder = $this->createStub(RuntimeTruthSnapshotBuilder::class);
    $snapshotBuilder->method('buildSnapshot')->willReturn($this->buildSnapshot(TRUE, FALSE));

    $builder = new RuntimeDiagnosticsMatrixBuilder($snapshotBuilder, $this->buildRetrievalConfiguration());
    $diagnostics = $builder->buildDiagnostics();

    $matrix = $diagnostics['diagnostics_matrix'] ?? [];
    $voyageRow = $this->findMatrixRow($matrix, 'voyage.enabled');
    $vectorRow = $this->findMatrixRow($matrix, 'vector_search.enabled');

    $this->assertTrue($voyageRow['current_value'] ?? FALSE);
    $this->assertFalse($vectorRow['current_value'] ?? TRUE);
  }

  /**
   * @return array<string, mixed>
   */
  private function buildSnapshot(bool $voyageEnabled, bool $requestTimeReachable): array {
    return [
      'environment' => [
        'effective_environment' => 'pantheon-live',
        'pantheon_environment' => 'live',
      ],
      'effective_runtime' => [
        'llm' => [
          'enabled' => $requestTimeReachable,
          'provider' => 'cohere',
          'model' => 'command-a-03-2025',
          'runtime_ready' => $requestTimeReachable,
          'request_time_generation_reachable' => $requestTimeReachable,
        ],
        'vector_search' => [
          'enabled' => FALSE,
          'override_channel' => 'config export',
        ],
        'embeddings' => [
          'runtime_ready' => TRUE,
          'api_key_present' => TRUE,
        ],
        'voyage' => [
          'enabled' => $voyageEnabled,
          'runtime_ready' => $voyageEnabled,
          'api_key_present' => $voyageEnabled,
        ],
        'langfuse' => [
          'enabled' => FALSE,
          'public_key_present' => FALSE,
          'secret_key_present' => FALSE,
        ],
        'sentry' => [
          'client_key_present' => FALSE,
          'public_dsn_present' => FALSE,
        ],
        'pinecone' => [
          'key_present' => TRUE,
          'runtime_ready' => FALSE,
        ],
      ],
      'runtime_site_settings' => [
        'cohere_api_key_present' => TRUE,
        'diagnostics_token_present' => FALSE,
      ],
      'override_channels' => [
        'llm.provider' => 'Cohere-first request-time transport contract',
        'llm.model' => 'Cohere-first request-time transport contract',
        'llm.request_time_generation_reachable' => 'LlmEnhancer::isEnabled()',
        'vector_search.enabled' => 'config export',
      ],
      'divergences' => [],
    ];
  }

  /**
   * @return array<string, mixed>
   */
  private function buildRetrievalHealth(): array {
    return [
      'retrieval' => [],
      'canonical_urls' => [
        'service_areas' => ['status' => 'healthy'],
        'legalserver_intake_url' => ['status' => 'healthy'],
      ],
    ];
  }

  private function buildRetrievalConfiguration(): RetrievalConfigurationService {
    new Settings([
      'ilas_site_assistant_legalserver_online_application_url' => 'https://example.com/intake?pid=60&h=test',
    ]);

    $config = $this->createStub(ImmutableConfig::class);
    $config->method('get')
      ->willReturnCallback(static function (string $key): mixed {
        return match ($key) {
          'retrieval' => [
            'faq_index_id' => 'faq_accordion',
            'resource_index_id' => 'assistant_resources',
            'resource_fallback_index_id' => 'content',
            'faq_vector_index_id' => 'faq_accordion_vector',
            'resource_vector_index_id' => 'assistant_resources_vector',
          ],
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
          'vector_search.enabled' => FALSE,
          default => NULL,
        };
      });

    $configFactory = $this->createStub(ConfigFactoryInterface::class);
    $configFactory->method('get')
      ->with('ilas_site_assistant.settings')
      ->willReturn($config);

    $indexes = [
      'faq_accordion' => $this->buildIndex('database'),
      'assistant_resources' => $this->buildIndex('database'),
      'content' => $this->buildIndex('database'),
      'faq_accordion_vector' => $this->buildIndex('pinecone_vector_faq'),
      'assistant_resources_vector' => $this->buildIndex('pinecone_vector_resources'),
    ];

    $indexStorage = $this->createMock(EntityStorageInterface::class);
    $indexStorage->method('load')
      ->willReturnCallback(static function (string $id) use ($indexes): mixed {
        return $indexes[$id] ?? NULL;
      });

    $server = new class {
      public function status(): bool {
        return TRUE;
      }
    };
    $servers = [
      'database' => $server,
      'pinecone_vector_faq' => $server,
      'pinecone_vector_resources' => $server,
    ];

    $serverStorage = $this->createMock(EntityStorageInterface::class);
    $serverStorage->method('load')
      ->willReturnCallback(static function (string $id) use ($servers): mixed {
        return $servers[$id] ?? NULL;
      });

    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getStorage')
      ->willReturnCallback(static function (string $entityTypeId) use ($indexStorage, $serverStorage): EntityStorageInterface {
        return match ($entityTypeId) {
          'search_api_index' => $indexStorage,
          'search_api_server' => $serverStorage,
          default => throw new \InvalidArgumentException('Unexpected storage request: ' . $entityTypeId),
        };
      });

    return new RetrievalConfigurationService($configFactory, $entityTypeManager);
  }

  private function buildIndex(string $serverId): IndexInterface {
    $index = $this->createMock(IndexInterface::class);
    $index->method('status')->willReturn(TRUE);
    $index->method('getServerId')->willReturn($serverId);
    return $index;
  }

  /**
   * @param array<int, array<string, mixed>> $matrix
   */
  private function findMatrixRow(array $matrix, string $factKey): array {
    foreach ($matrix as $row) {
      if (($row['fact_key'] ?? NULL) === $factKey) {
        return $row;
      }
    }

    $this->fail('Missing diagnostics row for fact key: ' . $factKey);
  }

}
