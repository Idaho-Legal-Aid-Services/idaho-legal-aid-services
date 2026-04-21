<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\State\StateInterface;
use Drupal\ilas_site_assistant\Service\RetrievalConfigurationService;
use Drupal\ilas_site_assistant\Service\VectorIndexHygieneService;
use Drupal\search_api\IndexInterface;
use Drupal\search_api\Item\ItemInterface;
use Drupal\search_api\Query\QueryInterface;
use Drupal\search_api\Query\ResultSetInterface;
use Drupal\search_api\ServerInterface;
use Drupal\search_api\Tracker\TrackerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for VectorIndexHygieneService.
 */
#[CoversClass(VectorIndexHygieneService::class)]
#[Group('ilas_site_assistant')]
final class VectorIndexHygieneServiceTest extends TestCase {

  /**
   * In-memory state store.
   *
   * @var array
   */
  private array $stateStore = [];

  /**
   * Builds a mock state service backed by in-memory storage.
   */
  private function buildState(): StateInterface {
    $state = $this->createMock(StateInterface::class);

    $state->method('get')
      ->willReturnCallback(function (string $key, $default = NULL) {
        return $this->stateStore[$key] ?? $default;
      });

    $state->method('set')
      ->willReturnCallback(function (string $key, $value): void {
        $this->stateStore[$key] = $value;
      });

    $state->method('delete')
      ->willReturnCallback(function (string $key): void {
        unset($this->stateStore[$key]);
      });

    return $state;
  }

  /**
   * Builds a config factory for vector-index hygiene policy.
   */
  private function buildConfigFactory(array $policyOverrides = [], array $retrievalOverrides = [], bool $vectorEnabled = TRUE): ConfigFactoryInterface {
    $defaultPolicy = [
      'enabled' => TRUE,
      'policy_version' => 'p2_del_03_v1',
      'refresh_mode' => 'incremental',
      'refresh_interval_hours' => 24,
      'probe_interval_hours' => 24,
      'overdue_grace_minutes' => 45,
      'max_items_per_run' => 5,
      'alert_cooldown_minutes' => 60,
      'managed_indexes' => [
        'faq_vector' => [
          'owner_role' => 'Content Operations Lead',
          'expected_server_id' => 'pinecone_vector_faq',
          'expected_metric' => 'cosine_similarity',
          'expected_embeddings_engine' => 'ilas_voyage__voyage-law-2',
          'expected_dimensions' => 1024,
          'queryability_probes' => [
            [
              'label' => 'faq_custody_canary',
              'query' => 'custody',
              'top_k' => 2,
              'min_results' => 1,
            ],
          ],
        ],
        'resource_vector' => [
          'owner_role' => 'Content Operations Lead',
          'expected_server_id' => 'pinecone_vector_resources',
          'expected_metric' => 'cosine_similarity',
          'expected_embeddings_engine' => 'ilas_voyage__voyage-law-2',
          'expected_dimensions' => 1024,
          'queryability_probes' => [
            [
              'label' => 'resource_eviction_canary',
              'query' => 'eviction',
              'langcode' => 'en',
              'top_k' => 1,
              'min_results' => 1,
            ],
          ],
        ],
      ],
    ];
    $defaultRetrieval = [
      'faq_vector_index_id' => 'faq_accordion_vector',
      'resource_vector_index_id' => 'assistant_resources_vector',
    ];

    $policy = array_replace_recursive($defaultPolicy, $policyOverrides);
    $retrieval = array_replace($defaultRetrieval, $retrievalOverrides);

    $config = $this->createStub(ImmutableConfig::class);
    $config->method('get')
      ->willReturnCallback(static function (string $key) use ($policy, $retrieval, $vectorEnabled) {
        return match ($key) {
          'vector_index_hygiene' => $policy,
          'retrieval' => $retrieval,
          'vector_search.enabled' => $vectorEnabled,
          default => NULL,
        };
      });

    $configFactory = $this->createStub(ConfigFactoryInterface::class);
    $configFactory->method('get')
      ->with('ilas_site_assistant.settings')
      ->willReturn($config);

    return $configFactory;
  }

  /**
   * Builds an entity-type manager for search_api_index loading.
   *
   * @param array<string, \Drupal\search_api\IndexInterface> $indexes
   *   Index map by machine name.
   */
  private function buildEntityTypeManager(array $indexes): EntityTypeManagerInterface {
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('load')
      ->willReturnCallback(static function (string $id) use ($indexes) {
        return $indexes[$id] ?? NULL;
      });

    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getStorage')
      ->with('search_api_index')
      ->willReturn($storage);

    return $entityTypeManager;
  }

  /**
   * Builds the service under test.
   *
   * @param array<string, \Drupal\search_api\IndexInterface> $indexes
   *   Search API index map.
   * @param array $policyOverrides
   *   Config override values.
   * @param \Psr\Log\LoggerInterface|null $logger
   *   Optional logger mock.
   */
  private function buildService(
    array $indexes = [],
    array $policyOverrides = [],
    ?LoggerInterface $logger = NULL,
    array $retrievalOverrides = [],
    bool $vectorEnabled = TRUE,
  ): VectorIndexHygieneService {
    $this->stateStore = [];
    $configFactory = $this->buildConfigFactory($policyOverrides, $retrievalOverrides, $vectorEnabled);
    $state = $this->buildState();
    $entityTypeManager = $this->buildEntityTypeManager($indexes);
    $retrievalConfiguration = new RetrievalConfigurationService($configFactory, $entityTypeManager);
    $logger = $logger ?? $this->createStub(LoggerInterface::class);

    return new VectorIndexHygieneService($configFactory, $state, $entityTypeManager, $retrievalConfiguration, $logger);
  }

  /**
   * Builds a compliant index mock.
   */
  private function buildCompliantIndexMock(
    int $totalItems = 100,
    int $indexedItems = 80,
    int $remainingItems = 20,
    string $serverId = 'pinecone_vector_faq',
    string $metric = 'cosine_similarity',
    string $embeddingsEngine = 'ilas_voyage__voyage-law-2',
    int $dimensions = 1024,
    array $probeScores = [0.91],
    ?TrackerInterface $trackerOverride = NULL,
    ?QueryInterface $queryOverride = NULL,
  ): IndexInterface {
    $tracker = $trackerOverride ?? $this->createMock(TrackerInterface::class);
    if (!$trackerOverride instanceof TrackerInterface) {
      $tracker->method('getTotalItemsCount')->willReturn($totalItems);
      $tracker->method('getIndexedItemsCount')->willReturn($indexedItems);
      $tracker->method('getRemainingItemsCount')->willReturn($remainingItems);
    }

    $server = $this->createMock(ServerInterface::class);
    $server->method('getBackendConfig')->willReturn([
      'database_settings' => ['metric' => $metric],
      'embeddings_engine' => $embeddingsEngine,
      'embeddings_engine_configuration' => ['dimensions' => $dimensions],
    ]);

    $index = $this->createMock(IndexInterface::class);
    $index->method('status')->willReturn(TRUE);
    $index->method('getServerId')->willReturn($serverId);
    $index->method('getServerInstanceIfAvailable')->willReturn($server);
    $index->method('getTrackerInstanceIfAvailable')->willReturn($tracker);
    $index->method('query')->willReturn($queryOverride ?? $this->buildQueryMock($probeScores));

    return $index;
  }

  /**
   * Builds a Search API query mock that returns deterministic result items.
   *
   * @param array<int, float|null> $scores
   *   Result scores to expose from getResultItems().
   * @param \Throwable|null $exception
   *   Optional execute exception.
   */
  private function buildQueryMock(array $scores = [0.91], ?\Throwable $exception = NULL): QueryInterface {
    $resultItems = array_map(function ($score): ItemInterface {
      $item = $this->createMock(ItemInterface::class);
      $item->method('getScore')->willReturn($score);
      return $item;
    }, $scores);

    $resultSet = $this->createMock(ResultSetInterface::class);
    $resultSet->method('getResultItems')->willReturn($resultItems);

    $query = $this->createMock(QueryInterface::class);
    $query->method('keys')->willReturnSelf();
    $query->method('range')->willReturnSelf();
    $query->method('addCondition')->willReturnSelf();
    if ($exception instanceof \Throwable) {
      $query->method('execute')->willThrowException($exception);
    }
    else {
      $query->method('execute')->willReturn($resultSet);
    }

    return $query;
  }

  /**
   * Builds a Search API query mock with language-filter expectations.
   */
  private function buildQueryMockWithLanguageExpectation(array $scores, ?string $expectedLangcode): QueryInterface {
    $resultItems = array_map(function ($score): ItemInterface {
      $item = $this->createMock(ItemInterface::class);
      $item->method('getScore')->willReturn($score);
      return $item;
    }, $scores);

    $resultSet = $this->createMock(ResultSetInterface::class);
    $resultSet->method('getResultItems')->willReturn($resultItems);

    $query = $this->createMock(QueryInterface::class);
    $query->method('keys')->willReturnSelf();
    $query->method('range')->willReturnSelf();
    if ($expectedLangcode === NULL) {
      $query->expects($this->never())
        ->method('addCondition');
    }
    else {
      $query->expects($this->once())
        ->method('addCondition')
        ->with('search_api_language', $expectedLangcode)
        ->willReturnSelf();
    }
    $query->method('execute')->willReturn($resultSet);

    return $query;
  }

  public function testDefaultPolicySnapshotContractValues(): void {
    $service = $this->buildService();
    $snapshot = $service->getSnapshot();

    $this->assertSame('p2_del_03_v1', $snapshot['policy_version']);
    $this->assertSame('incremental', $snapshot['refresh_mode']);
    $this->assertSame(24, $snapshot['thresholds']['refresh_interval_hours']);
    $this->assertSame(24, $snapshot['thresholds']['probe_interval_hours']);
    $this->assertSame(45, $snapshot['thresholds']['overdue_grace_minutes']);
    $this->assertSame(5, $snapshot['thresholds']['max_items_per_run']);
    $this->assertSame(60, $snapshot['thresholds']['alert_cooldown_minutes']);

    $this->assertArrayHasKey('faq_vector', $snapshot['indexes']);
    $this->assertArrayHasKey('resource_vector', $snapshot['indexes']);
    $this->assertSame('faq_accordion_vector', $snapshot['indexes']['faq_vector']['index_id']);
    $this->assertSame('assistant_resources_vector', $snapshot['indexes']['resource_vector']['index_id']);
    $this->assertSame('ilas_voyage__voyage-law-2', $snapshot['indexes']['faq_vector']['expected']['embeddings_engine']);
    $this->assertSame('ilas_voyage__voyage-law-2', $snapshot['indexes']['resource_vector']['expected']['embeddings_engine']);
  }

  public function testDefaultPolicyDropsFaqLanguageFilterButKeepsResourceLanguageFilter(): void {
    $service = $this->buildService();
    $reflection = new \ReflectionMethod($service, 'getPolicy');
    $reflection->setAccessible(TRUE);
    $policy = $reflection->invoke($service);

    $faqProbe = $policy['managed_indexes']['faq_vector']['queryability_probes'][0] ?? [];
    $resourceProbe = $policy['managed_indexes']['resource_vector']['queryability_probes'][0] ?? [];

    $this->assertSame('faq_custody_canary', $faqProbe['label'] ?? NULL);
    $this->assertArrayNotHasKey('langcode', $faqProbe);
    $this->assertSame(2, $faqProbe['top_k'] ?? NULL);
    $this->assertSame('resource_eviction_canary', $resourceProbe['label'] ?? NULL);
    $this->assertSame('en', $resourceProbe['langcode'] ?? NULL);
    $this->assertSame(1, $resourceProbe['top_k'] ?? NULL);
  }

  public function testFaqProbeOmitsLanguageConditionWhileResourceProbeKeepsIt(): void {
    $faqIndex = $this->buildCompliantIndexMock(
      queryOverride: $this->buildQueryMockWithLanguageExpectation([0.91], NULL),
    );
    $resourceIndex = $this->buildCompliantIndexMock(
      serverId: 'pinecone_vector_resources',
      queryOverride: $this->buildQueryMockWithLanguageExpectation([0.87], 'en'),
    );

    $faqIndex->expects($this->once())->method('indexItems')->willReturn(1);
    $resourceIndex->expects($this->once())->method('indexItems')->willReturn(1);

    $service = $this->buildService([
      'faq_accordion_vector' => $faqIndex,
      'assistant_resources_vector' => $resourceIndex,
    ]);

    $service->runScheduledRefresh();
    $snapshot = $service->getSnapshot();

    $this->assertSame('healthy', $snapshot['indexes']['faq_vector']['probe_status']);
    $this->assertSame('healthy', $snapshot['indexes']['resource_vector']['probe_status']);
    $this->assertSame(2, $snapshot['totals']['probe_passed_count']);
    $this->assertSame(0, $snapshot['totals']['probe_failed_count']);
  }

  public function testRunScheduledRefreshProcessesDueIndexesIncrementally(): void {
    $faqIndex = $this->buildCompliantIndexMock(100, 80, 20);
    $resourceIndex = $this->buildCompliantIndexMock(60, 40, 20, 'pinecone_vector_resources');

    $faqIndex->expects($this->once())
      ->method('indexItems')
      ->with(5)
      ->willReturn(7);
    $resourceIndex->expects($this->once())
      ->method('indexItems')
      ->with(5)
      ->willReturn(3);

    $service = $this->buildService([
      'faq_accordion_vector' => $faqIndex,
      'assistant_resources_vector' => $resourceIndex,
    ]);

    $service->runScheduledRefresh();
    $snapshot = $service->getSnapshot();

    $this->assertSame('healthy', $snapshot['status']);
    $this->assertSame(7, $snapshot['indexes']['faq_vector']['items_processed_last_run']);
    $this->assertSame(3, $snapshot['indexes']['resource_vector']['items_processed_last_run']);
    $this->assertSame('healthy', $snapshot['indexes']['faq_vector']['probe_status']);
    $this->assertSame('healthy', $snapshot['indexes']['resource_vector']['probe_status']);
    $this->assertNotEmpty($snapshot['indexes']['faq_vector']['last_refresh_at']);
    $this->assertNotEmpty($snapshot['indexes']['resource_vector']['last_refresh_at']);
  }

  public function testRunScheduledRefreshSkipsPassiveIndexingAndProbesWhenVectorSearchDisabled(): void {
    $faqIndex = $this->buildCompliantIndexMock();
    $resourceIndex = $this->buildCompliantIndexMock(serverId: 'pinecone_vector_resources');

    $faqIndex->expects($this->never())->method('indexItems');
    $resourceIndex->expects($this->never())->method('indexItems');
    $faqIndex->expects($this->never())->method('query');
    $resourceIndex->expects($this->never())->method('query');

    $service = $this->buildService([
      'faq_accordion_vector' => $faqIndex,
      'assistant_resources_vector' => $resourceIndex,
    ], vectorEnabled: FALSE);

    $service->runScheduledRefresh();
    $snapshot = $service->getSnapshot();

    $this->assertSame('healthy', $snapshot['status']);
    $this->assertFalse($snapshot['vector_search_enabled']);
    $this->assertSame('skipped', $snapshot['indexes']['faq_vector']['status']);
    $this->assertSame('skipped', $snapshot['indexes']['faq_vector']['indexing_status']);
    $this->assertSame('skipped', $snapshot['indexes']['faq_vector']['probe_status']);
    $this->assertSame('vector_search_disabled', $snapshot['indexes']['faq_vector']['probe_error']);
    $this->assertSame('vector_search_disabled', $snapshot['indexes']['faq_vector']['last_stop_reason']);
    $this->assertFalse($snapshot['indexes']['faq_vector']['due']);
    $this->assertFalse($snapshot['indexes']['resource_vector']['overdue']);
    $this->assertSame(0, $snapshot['totals']['degraded_indexes']);
    $this->assertSame(0, $snapshot['totals']['probe_failed_indexes']);
  }

  public function testRunScheduledRefreshSkipsIndexingWhenNotDue(): void {
    $faqIndex = $this->buildCompliantIndexMock();
    $resourceIndex = $this->buildCompliantIndexMock(serverId: 'pinecone_vector_resources');

    $faqIndex->expects($this->never())
      ->method('indexItems');
    $resourceIndex->expects($this->never())
      ->method('indexItems');

    $service = $this->buildService([
      'faq_accordion_vector' => $faqIndex,
      'assistant_resources_vector' => $resourceIndex,
    ]);

    $now = time();
    $this->stateStore['ilas_site_assistant.vector_index_hygiene.snapshot'] = [
      'policy_version' => 'p2_del_03_v1',
      'recorded_at' => $now,
      'refresh_mode' => 'incremental',
      'indexes' => [
        'faq_vector' => [
          'index_id' => 'faq_accordion_vector',
          'last_refresh_at' => $now,
          'metadata_status' => 'compliant',
          'status' => 'healthy',
        ],
        'resource_vector' => [
          'index_id' => 'assistant_resources_vector',
          'last_refresh_at' => $now,
          'metadata_status' => 'compliant',
          'status' => 'healthy',
        ],
      ],
      'totals' => [],
      'thresholds' => [],
    ];

    $service->runScheduledRefresh();
    $snapshot = $service->getSnapshot();

    $this->assertFalse($snapshot['indexes']['faq_vector']['due']);
    $this->assertFalse($snapshot['indexes']['resource_vector']['due']);
  }

  public function testMetadataDriftDetectionMarksDriftFields(): void {
    $faqIndex = $this->buildCompliantIndexMock(
      totalItems: 100,
      indexedItems: 90,
      remainingItems: 10,
      serverId: 'wrong_server',
      metric: 'dot_product',
      embeddingsEngine: 'gemini__models/gemini-embedding-001',
      dimensions: 1536
    );
    $resourceIndex = $this->buildCompliantIndexMock(serverId: 'pinecone_vector_resources');

    $faqIndex->expects($this->never())->method('indexItems');
    $resourceIndex->expects($this->never())->method('indexItems');

    $service = $this->buildService([
      'faq_accordion_vector' => $faqIndex,
      'assistant_resources_vector' => $resourceIndex,
    ]);

    $now = time();
    $this->stateStore['ilas_site_assistant.vector_index_hygiene.snapshot'] = [
      'policy_version' => 'p2_del_03_v1',
      'recorded_at' => $now,
      'refresh_mode' => 'incremental',
      'indexes' => [
        'faq_vector' => ['index_id' => 'faq_accordion_vector', 'last_refresh_at' => $now],
        'resource_vector' => ['index_id' => 'assistant_resources_vector', 'last_refresh_at' => $now],
      ],
      'totals' => [],
      'thresholds' => [],
    ];

    $service->runScheduledRefresh();
    $snapshot = $service->getSnapshot();

    $this->assertSame('drift', $snapshot['indexes']['faq_vector']['metadata_status']);
    $this->assertContains('server_id', $snapshot['indexes']['faq_vector']['drift_fields']);
    $this->assertContains('metric', $snapshot['indexes']['faq_vector']['drift_fields']);
    $this->assertContains('embeddings_engine', $snapshot['indexes']['faq_vector']['drift_fields']);
    $this->assertContains('dimensions', $snapshot['indexes']['faq_vector']['drift_fields']);
    $this->assertSame('gemini__models/gemini-embedding-001', $snapshot['indexes']['faq_vector']['observed']['embeddings_engine']);
  }

  public function testOverdueDetectionUsesGraceWindow(): void {
    $service = $this->buildService();
    $lastRefresh = time() - ((24 * 3600) + (61 * 60));

    $this->stateStore['ilas_site_assistant.vector_index_hygiene.snapshot'] = [
      'policy_version' => 'p2_del_03_v1',
      'recorded_at' => time(),
      'refresh_mode' => 'incremental',
      'indexes' => [
        'faq_vector' => [
          'index_id' => 'faq_accordion_vector',
          'last_refresh_at' => $lastRefresh,
          'metadata_status' => 'compliant',
          'status' => 'healthy',
        ],
      ],
      'totals' => [],
      'thresholds' => [],
    ];

    $snapshot = $service->getSnapshot();
    $this->assertTrue($snapshot['indexes']['faq_vector']['overdue']);
    $this->assertSame('degraded', $snapshot['status']);
  }

  public function testSnapshotCapturesTrackerCounts(): void {
    $faqIndex = $this->buildCompliantIndexMock(200, 150, 50);
    $resourceIndex = $this->buildCompliantIndexMock(80, 70, 10, 'pinecone_vector_resources');

    $faqIndex->expects($this->once())->method('indexItems')->willReturn(5);
    $resourceIndex->expects($this->once())->method('indexItems')->willReturn(1);

    $service = $this->buildService([
      'faq_accordion_vector' => $faqIndex,
      'assistant_resources_vector' => $resourceIndex,
    ]);

    $service->runScheduledRefresh();
    $snapshot = $service->getSnapshot();

    $this->assertSame(200, $snapshot['indexes']['faq_vector']['total_items']);
    $this->assertSame(150, $snapshot['indexes']['faq_vector']['indexed_items']);
    $this->assertSame(50, $snapshot['indexes']['faq_vector']['remaining_items']);
    $this->assertSame(60, $snapshot['totals']['remaining_items']);
  }

  public function testRunScheduledRefreshRecordsSuccessfulProbeEvidence(): void {
    $faqIndex = $this->buildCompliantIndexMock();
    $resourceIndex = $this->buildCompliantIndexMock(serverId: 'pinecone_vector_resources');

    $faqIndex->expects($this->once())->method('indexItems')->willReturn(1);
    $resourceIndex->expects($this->once())->method('indexItems')->willReturn(1);

    $service = $this->buildService([
      'faq_accordion_vector' => $faqIndex,
      'assistant_resources_vector' => $resourceIndex,
    ]);

    $service->runScheduledRefresh();
    $snapshot = $service->getSnapshot();

    $this->assertNotEmpty($snapshot['indexes']['faq_vector']['last_probe_at']);
    $this->assertSame('healthy', $snapshot['indexes']['faq_vector']['probe_status']);
    $this->assertSame(1, $snapshot['indexes']['faq_vector']['probe_passed_count']);
    $this->assertSame(0, $snapshot['indexes']['faq_vector']['probe_failed_count']);
    $this->assertSame('faq_custody_canary', $snapshot['indexes']['faq_vector']['probe_evidence'][0]['label']);
    $this->assertSame(2, $snapshot['totals']['probe_passed_count']);
    $this->assertSame(0, $snapshot['totals']['probe_failed_count']);
  }

  public function testFailedProbeDegradesOtherwiseHealthySnapshot(): void {
    $faqIndex = $this->buildCompliantIndexMock(
      queryOverride: $this->buildQueryMock([], new \RuntimeException('probe failed')),
    );
    $resourceIndex = $this->buildCompliantIndexMock(serverId: 'pinecone_vector_resources');

    $faqIndex->expects($this->once())->method('indexItems')->willReturn(1);
    $resourceIndex->expects($this->once())->method('indexItems')->willReturn(1);

    $service = $this->buildService([
      'faq_accordion_vector' => $faqIndex,
      'assistant_resources_vector' => $resourceIndex,
    ]);

    $service->runScheduledRefresh();
    $snapshot = $service->getSnapshot();

    $this->assertSame('failed', $snapshot['indexes']['faq_vector']['probe_status']);
    $this->assertSame('healthy', $snapshot['indexes']['resource_vector']['probe_status']);
    $this->assertSame('degraded', $snapshot['indexes']['faq_vector']['status']);
    $this->assertSame('degraded', $snapshot['status']);
    $this->assertSame(1, $snapshot['totals']['probe_failed_count']);
    $this->assertSame(1, $snapshot['totals']['probe_failed_indexes']);
    $this->assertStringContainsString('RuntimeException: probe failed', (string) $snapshot['indexes']['faq_vector']['probe_error']);
  }

  public function testRefreshSnapshotSkipsProbeWhenCadenceNotElapsed(): void {
    $faqIndex = $this->buildCompliantIndexMock();
    $resourceIndex = $this->buildCompliantIndexMock(serverId: 'pinecone_vector_resources');

    $faqIndex->expects($this->never())->method('indexItems');
    $resourceIndex->expects($this->never())->method('indexItems');
    $faqIndex->expects($this->never())->method('query');

    $service = $this->buildService([
      'faq_accordion_vector' => $faqIndex,
      'assistant_resources_vector' => $resourceIndex,
    ]);

    $now = time();
    $this->stateStore['ilas_site_assistant.vector_index_hygiene.snapshot'] = [
      'policy_version' => 'p2_del_03_v1',
      'recorded_at' => $now,
      'refresh_mode' => 'incremental',
      'indexes' => [
        'faq_vector' => [
          'index_id' => 'faq_accordion_vector',
          'last_refresh_at' => $now,
          'last_probe_at' => $now,
          'probe_status' => 'healthy',
          'metadata_status' => 'compliant',
          'status' => 'healthy',
        ],
        'resource_vector' => [
          'index_id' => 'assistant_resources_vector',
          'last_refresh_at' => $now,
          'last_probe_at' => $now,
          'probe_status' => 'healthy',
          'metadata_status' => 'compliant',
          'status' => 'healthy',
        ],
      ],
      'totals' => [],
      'thresholds' => [],
    ];

    $snapshot = $service->refreshSnapshot(FALSE, 'faq_vector');

    $this->assertSame($now, $snapshot['indexes']['faq_vector']['last_probe_at']);
    $this->assertSame('healthy', $snapshot['indexes']['faq_vector']['probe_status']);
  }

  public function testRefreshSnapshotSkipsProbesWhenVectorSearchDisabled(): void {
    $faqIndex = $this->buildCompliantIndexMock();
    $resourceIndex = $this->buildCompliantIndexMock(serverId: 'pinecone_vector_resources');

    $faqIndex->expects($this->never())->method('indexItems');
    $resourceIndex->expects($this->never())->method('indexItems');
    $faqIndex->expects($this->never())->method('query');
    $resourceIndex->expects($this->never())->method('query');

    $service = $this->buildService([
      'faq_accordion_vector' => $faqIndex,
      'assistant_resources_vector' => $resourceIndex,
    ], vectorEnabled: FALSE);

    $snapshot = $service->refreshSnapshot(TRUE, 'faq_vector');

    $this->assertSame('healthy', $snapshot['status']);
    $this->assertSame('skipped', $snapshot['indexes']['faq_vector']['status']);
    $this->assertSame('skipped', $snapshot['indexes']['faq_vector']['probe_status']);
    $this->assertSame('vector_search_disabled', $snapshot['indexes']['faq_vector']['probe_error']);
    $this->assertSame('skipped', $snapshot['indexes']['faq_vector']['indexing_status']);
  }

  public function testGetSnapshotSuppressesStoredDegradedVectorStateWhenVectorSearchDisabled(): void {
    $service = $this->buildService(vectorEnabled: FALSE);
    $lastRefresh = time() - ((24 * 3600) + (61 * 60));

    $this->stateStore['ilas_site_assistant.vector_index_hygiene.snapshot'] = [
      'policy_version' => 'p2_del_03_v1',
      'recorded_at' => time(),
      'refresh_mode' => 'incremental',
      'indexes' => [
        'faq_vector' => [
          'index_id' => 'faq_accordion_vector',
          'last_refresh_at' => $lastRefresh,
          'metadata_status' => 'drift',
          'status' => 'degraded',
          'probe_status' => 'failed',
          'probe_error' => 'old_failure',
        ],
      ],
      'totals' => [],
      'thresholds' => [],
    ];

    $snapshot = $service->getSnapshot();

    $this->assertSame('healthy', $snapshot['status']);
    $this->assertSame('skipped', $snapshot['indexes']['faq_vector']['status']);
    $this->assertSame('skipped', $snapshot['indexes']['faq_vector']['probe_status']);
    $this->assertSame('vector_search_disabled', $snapshot['indexes']['faq_vector']['probe_error']);
    $this->assertFalse($snapshot['indexes']['faq_vector']['due']);
    $this->assertFalse($snapshot['indexes']['faq_vector']['overdue']);
  }

  public function testBackfillIndexReturnsBatchLimitedProgressAndPersistsStopReason(): void {
    $tracker = $this->createMock(TrackerInterface::class);
    $tracker->method('getTotalItemsCount')->willReturn(10);
    $tracker->method('getIndexedItemsCount')->willReturnOnConsecutiveCalls(3, 5, 5);
    $tracker->method('getRemainingItemsCount')->willReturnOnConsecutiveCalls(7, 5, 5);
    $faqIndex = $this->buildCompliantIndexMock(10, 3, 7, trackerOverride: $tracker);
    $faqIndex->expects($this->once())->method('indexItems')->with(2)->willReturn(2);

    $service = $this->buildService([
      'faq_accordion_vector' => $faqIndex,
      'assistant_resources_vector' => $this->buildCompliantIndexMock(serverId: 'pinecone_vector_resources'),
    ]);

    $report = $service->backfillIndex('faq_vector', 2, 1, 0, FALSE, FALSE);

    $this->assertSame('resume', $report['mode']);
    $this->assertSame(2, $report['processed_this_run']);
    $this->assertSame('batch_limit_reached', $report['stop_reason']);
    $this->assertSame(10, $report['total_items']);
    $this->assertSame(5, $report['indexed_items']);
    $this->assertSame(5, $report['remaining_items']);
    $this->assertSame('batch_limit_reached', $report['last_stop_reason']);
    $snapshot = $service->getSnapshot();
    $this->assertSame('batch_limit_reached', $snapshot['indexes']['faq_vector']['last_stop_reason']);
  }

  public function testBackfillIndexCanClearFirstAndRunUntilComplete(): void {
    $tracker = $this->createMock(TrackerInterface::class);
    $tracker->method('getTotalItemsCount')->willReturn(4);
    $tracker->method('getIndexedItemsCount')->willReturnOnConsecutiveCalls(0, 0, 2, 4, 4);
    $tracker->method('getRemainingItemsCount')->willReturnOnConsecutiveCalls(4, 4, 2, 0, 0);
    $faqIndex = $this->buildCompliantIndexMock(4, 0, 4, trackerOverride: $tracker);
    $faqIndex->expects($this->once())->method('clear');
    $faqIndex->expects($this->exactly(2))->method('indexItems')->with(2)->willReturn(2);

    $service = $this->buildService([
      'faq_accordion_vector' => $faqIndex,
      'assistant_resources_vector' => $this->buildCompliantIndexMock(serverId: 'pinecone_vector_resources'),
    ]);

    $report = $service->backfillIndex('faq_vector', 2, 1, 0, TRUE, TRUE);

    $this->assertSame('rebuild', $report['mode']);
    $this->assertSame(4, $report['processed_this_run']);
    $this->assertSame('complete', $report['stop_reason']);
    $this->assertSame(100.0, $report['percent_complete']);
  }

  public function testBackfillIndexStillProcessesWhenVectorSearchDisabled(): void {
    $tracker = $this->createMock(TrackerInterface::class);
    $tracker->method('getTotalItemsCount')->willReturn(10);
    $tracker->method('getIndexedItemsCount')->willReturnOnConsecutiveCalls(3, 5, 5);
    $tracker->method('getRemainingItemsCount')->willReturnOnConsecutiveCalls(7, 5, 5);
    $faqIndex = $this->buildCompliantIndexMock(10, 3, 7, trackerOverride: $tracker);
    $faqIndex->expects($this->once())->method('indexItems')->with(2)->willReturn(2);

    $service = $this->buildService([
      'faq_accordion_vector' => $faqIndex,
      'assistant_resources_vector' => $this->buildCompliantIndexMock(serverId: 'pinecone_vector_resources'),
    ], vectorEnabled: FALSE);

    $report = $service->backfillIndex('faq_vector', 2, 1, 0, FALSE, FALSE);

    $this->assertSame('resume', $report['mode']);
    $this->assertSame(2, $report['processed_this_run']);
    $this->assertSame('batch_limit_reached', $report['stop_reason']);
    $this->assertSame(5, $report['remaining_items']);
    $this->assertSame('batch_limit_reached', $report['last_stop_reason']);

    $snapshot = $service->getSnapshot();
    $this->assertSame('skipped', $snapshot['indexes']['faq_vector']['status']);
    $this->assertSame('skipped', $snapshot['indexes']['faq_vector']['probe_status']);
    $this->assertSame('batch_limit_reached', $snapshot['indexes']['faq_vector']['last_stop_reason']);
  }

  public function testExceptionIsolationPreservesSecondIndexProcessing(): void {
    $faqIndex = $this->buildCompliantIndexMock();
    $resourceIndex = $this->buildCompliantIndexMock(serverId: 'pinecone_vector_resources');

    $faqIndex->expects($this->once())
      ->method('indexItems')
      ->willThrowException(new \RuntimeException('Simulated FAQ failure'));
    $resourceIndex->expects($this->once())
      ->method('indexItems')
      ->willReturn(4);

    $service = $this->buildService([
      'faq_accordion_vector' => $faqIndex,
      'assistant_resources_vector' => $resourceIndex,
    ]);

    $service->runScheduledRefresh();
    $snapshot = $service->getSnapshot();

    $this->assertStringContainsString('RuntimeException: Simulated FAQ failure', (string) $snapshot['indexes']['faq_vector']['last_error']);
    $this->assertSame(4, $snapshot['indexes']['resource_vector']['items_processed_last_run']);
    $this->assertSame('degraded', $snapshot['status']);
  }

  public function testDegradedAlertUsesCooldown(): void {
    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->once())
      ->method('notice')
      ->with(
        $this->stringContains('Vector index hygiene degraded'),
        $this->isType('array')
      );

    // No indexes are loadable, so status will be degraded and alertable.
    $service = $this->buildService(indexes: [], policyOverrides: [], logger: $logger);
    $service->runScheduledRefresh();
    $service->runScheduledRefresh();

    $this->assertArrayHasKey('ilas_site_assistant.vector_index_hygiene.last_alert', $this->stateStore);
  }

}
