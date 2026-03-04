<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\State\StateInterface;
use Drupal\ilas_site_assistant\Service\VectorIndexHygieneService;
use Drupal\search_api\IndexInterface;
use Drupal\search_api\ServerInterface;
use Drupal\search_api\Tracker\TrackerInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for VectorIndexHygieneService.
 *
 * @group ilas_site_assistant
 * @coversDefaultClass \Drupal\ilas_site_assistant\Service\VectorIndexHygieneService
 */
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
  private function buildConfigFactory(array $policyOverrides = []): ConfigFactoryInterface {
    $defaultPolicy = [
      'enabled' => TRUE,
      'policy_version' => 'p2_del_03_v1',
      'refresh_mode' => 'incremental',
      'refresh_interval_hours' => 24,
      'overdue_grace_minutes' => 60,
      'max_items_per_run' => 50,
      'alert_cooldown_minutes' => 60,
      'managed_indexes' => [
        'faq_vector' => [
          'index_id' => 'faq_accordion_vector',
          'owner_role' => 'Content Operations Lead',
          'expected_server_id' => 'pinecone_vector',
          'expected_metric' => 'cosine_similarity',
          'expected_dimensions' => 3072,
        ],
        'resource_vector' => [
          'index_id' => 'assistant_resources_vector',
          'owner_role' => 'Content Operations Lead',
          'expected_server_id' => 'pinecone_vector',
          'expected_metric' => 'cosine_similarity',
          'expected_dimensions' => 3072,
        ],
      ],
    ];

    $policy = array_replace_recursive($defaultPolicy, $policyOverrides);

    $config = $this->createStub(ImmutableConfig::class);
    $config->method('get')
      ->willReturnCallback(static function (string $key) use ($policy) {
        return $key === 'vector_index_hygiene' ? $policy : NULL;
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
  private function buildService(array $indexes = [], array $policyOverrides = [], ?LoggerInterface $logger = NULL): VectorIndexHygieneService {
    $this->stateStore = [];
    $configFactory = $this->buildConfigFactory($policyOverrides);
    $state = $this->buildState();
    $entityTypeManager = $this->buildEntityTypeManager($indexes);
    $logger = $logger ?? $this->createStub(LoggerInterface::class);

    return new VectorIndexHygieneService($configFactory, $state, $entityTypeManager, $logger);
  }

  /**
   * Builds a compliant index mock.
   */
  private function buildCompliantIndexMock(
    int $totalItems = 100,
    int $indexedItems = 80,
    int $remainingItems = 20,
    string $serverId = 'pinecone_vector',
    string $metric = 'cosine_similarity',
    int $dimensions = 3072,
  ): IndexInterface {
    $tracker = $this->createMock(TrackerInterface::class);
    $tracker->method('getTotalItemsCount')->willReturn($totalItems);
    $tracker->method('getIndexedItemsCount')->willReturn($indexedItems);
    $tracker->method('getRemainingItemsCount')->willReturn($remainingItems);

    $server = $this->createMock(ServerInterface::class);
    $server->method('getBackendConfig')->willReturn([
      'database_settings' => ['metric' => $metric],
      'embeddings_engine_configuration' => ['dimensions' => $dimensions],
    ]);

    $index = $this->createMock(IndexInterface::class);
    $index->method('status')->willReturn(TRUE);
    $index->method('getServerId')->willReturn($serverId);
    $index->method('getServerInstanceIfAvailable')->willReturn($server);
    $index->method('getTrackerInstanceIfAvailable')->willReturn($tracker);

    return $index;
  }

  /**
   * @covers ::getSnapshot
   */
  public function testDefaultPolicySnapshotContractValues(): void {
    $service = $this->buildService();
    $snapshot = $service->getSnapshot();

    $this->assertSame('p2_del_03_v1', $snapshot['policy_version']);
    $this->assertSame('incremental', $snapshot['refresh_mode']);
    $this->assertSame(24, $snapshot['thresholds']['refresh_interval_hours']);
    $this->assertSame(60, $snapshot['thresholds']['overdue_grace_minutes']);
    $this->assertSame(50, $snapshot['thresholds']['max_items_per_run']);
    $this->assertSame(60, $snapshot['thresholds']['alert_cooldown_minutes']);

    $this->assertArrayHasKey('faq_vector', $snapshot['indexes']);
    $this->assertArrayHasKey('resource_vector', $snapshot['indexes']);
    $this->assertSame('faq_accordion_vector', $snapshot['indexes']['faq_vector']['index_id']);
    $this->assertSame('assistant_resources_vector', $snapshot['indexes']['resource_vector']['index_id']);
  }

  /**
   * @covers ::runScheduledRefresh
   * @covers ::getSnapshot
   */
  public function testRunScheduledRefreshProcessesDueIndexesIncrementally(): void {
    $faqIndex = $this->buildCompliantIndexMock(100, 80, 20);
    $resourceIndex = $this->buildCompliantIndexMock(60, 40, 20);

    $faqIndex->expects($this->once())
      ->method('indexItems')
      ->with(50)
      ->willReturn(7);
    $resourceIndex->expects($this->once())
      ->method('indexItems')
      ->with(50)
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
    $this->assertNotEmpty($snapshot['indexes']['faq_vector']['last_refresh_at']);
    $this->assertNotEmpty($snapshot['indexes']['resource_vector']['last_refresh_at']);
  }

  /**
   * @covers ::runScheduledRefresh
   * @covers ::getSnapshot
   */
  public function testRunScheduledRefreshSkipsIndexingWhenNotDue(): void {
    $faqIndex = $this->buildCompliantIndexMock();
    $resourceIndex = $this->buildCompliantIndexMock();

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

  /**
   * @covers ::runScheduledRefresh
   * @covers ::getSnapshot
   */
  public function testMetadataDriftDetectionMarksDriftFields(): void {
    $faqIndex = $this->buildCompliantIndexMock(
      totalItems: 100,
      indexedItems: 90,
      remainingItems: 10,
      serverId: 'wrong_server',
      metric: 'dot_product',
      dimensions: 1024
    );
    $resourceIndex = $this->buildCompliantIndexMock();

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
    $this->assertContains('dimensions', $snapshot['indexes']['faq_vector']['drift_fields']);
  }

  /**
   * @covers ::getSnapshot
   */
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

  /**
   * @covers ::runScheduledRefresh
   * @covers ::getSnapshot
   */
  public function testSnapshotCapturesTrackerCounts(): void {
    $faqIndex = $this->buildCompliantIndexMock(200, 150, 50);
    $resourceIndex = $this->buildCompliantIndexMock(80, 70, 10);

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

  /**
   * @covers ::runScheduledRefresh
   * @covers ::getSnapshot
   */
  public function testExceptionIsolationPreservesSecondIndexProcessing(): void {
    $faqIndex = $this->buildCompliantIndexMock();
    $resourceIndex = $this->buildCompliantIndexMock();

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

  /**
   * @covers ::runScheduledRefresh
   */
  public function testDegradedAlertUsesCooldown(): void {
    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->once())
      ->method('warning')
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

