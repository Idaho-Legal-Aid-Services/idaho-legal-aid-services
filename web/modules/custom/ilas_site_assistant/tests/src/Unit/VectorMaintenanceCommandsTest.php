<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\State\StateInterface;
use Drupal\ilas_site_assistant\Commands\VectorMaintenanceCommands;
use Drupal\ilas_site_assistant\Service\RetrievalConfigurationService;
use Drupal\ilas_site_assistant\Service\VectorIndexHygieneService;
use Drupal\search_api\IndexInterface;
use Drupal\search_api\Item\ItemInterface;
use Drupal\search_api\Query\QueryInterface;
use Drupal\search_api\Query\ResultSetInterface;
use Drupal\search_api\ServerInterface;
use Drupal\search_api\Tracker\TrackerInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * Unit tests for vector maintenance Drush commands.
 */
#[Group('ilas_site_assistant')]
final class VectorMaintenanceCommandsTest extends TestCase {

  private const MODULE_PATH = 'web/modules/custom/ilas_site_assistant';

  /**
   * In-memory state store.
   *
   * @var array<string, mixed>
   */
  private array $stateStore = [];

  /**
   * Returns the repository root.
   */
  private static function repoRoot(): string {
    return dirname(__DIR__, 7);
  }

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

    return $state;
  }

  /**
   * Builds a config factory for vector hygiene and retrieval IDs.
   */
  private function buildConfigFactory(): ConfigFactoryInterface {
    $config = $this->createStub(ImmutableConfig::class);
    $config->method('get')
      ->willReturnCallback(static fn(string $key): mixed => match ($key) {
        'vector_index_hygiene' => [
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
              'expected_dimensions' => 3072,
              'queryability_probes' => [[
                'label' => 'faq_custody_canary',
                'query' => 'custody',
                'langcode' => 'en',
                'top_k' => 1,
                'min_results' => 1,
              ]],
            ],
            'resource_vector' => [
              'owner_role' => 'Content Operations Lead',
              'expected_server_id' => 'pinecone_vector_resources',
              'expected_metric' => 'cosine_similarity',
              'expected_dimensions' => 3072,
              'queryability_probes' => [[
                'label' => 'resource_eviction_canary',
                'query' => 'eviction',
                'langcode' => 'en',
                'top_k' => 1,
                'min_results' => 1,
              ]],
            ],
          ],
        ],
        'retrieval' => [
          'faq_vector_index_id' => 'faq_accordion_vector',
          'resource_vector_index_id' => 'assistant_resources_vector',
        ],
        default => NULL,
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
   *   Indexes keyed by machine name.
   */
  private function buildEntityTypeManager(array $indexes): EntityTypeManagerInterface {
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('load')
      ->willReturnCallback(static fn(string $id) => $indexes[$id] ?? NULL);

    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getStorage')
      ->with('search_api_index')
      ->willReturn($storage);

    return $entityTypeManager;
  }

  /**
   * Builds the real vector hygiene service with mocked dependencies.
   *
   * @param array<string, \Drupal\search_api\IndexInterface> $indexes
   *   Search API index map.
   */
  private function buildService(array $indexes): VectorIndexHygieneService {
    $this->stateStore = [];
    $configFactory = $this->buildConfigFactory();
    $entityTypeManager = $this->buildEntityTypeManager($indexes);
    $retrievalConfiguration = new RetrievalConfigurationService($configFactory, $entityTypeManager);

    return new VectorIndexHygieneService(
      $configFactory,
      $this->buildState(),
      $entityTypeManager,
      $retrievalConfiguration,
      $this->createStub(LoggerInterface::class),
    );
  }

  /**
   * Builds a Search API query mock that returns deterministic result items.
   *
   * @param array<int, float|null> $scores
   *   Result scores to expose from getResultItems().
   */
  private function buildQueryMock(array $scores = [0.91]): QueryInterface {
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
    $query->method('execute')->willReturn($resultSet);

    return $query;
  }

  /**
   * Builds a compliant index mock.
   */
  private function buildIndex(
    string $serverId,
    int $totalItems = 10,
    int $indexedItems = 5,
    int $remainingItems = 5,
    ?TrackerInterface $trackerOverride = NULL,
  ): IndexInterface {
    $tracker = $trackerOverride ?? $this->createMock(TrackerInterface::class);
    if (!$trackerOverride instanceof TrackerInterface) {
      $tracker->method('getTotalItemsCount')->willReturn($totalItems);
      $tracker->method('getIndexedItemsCount')->willReturn($indexedItems);
      $tracker->method('getRemainingItemsCount')->willReturn($remainingItems);
    }

    $server = $this->createMock(ServerInterface::class);
    $server->method('getBackendConfig')->willReturn([
      'database_settings' => ['metric' => 'cosine_similarity'],
      'embeddings_engine_configuration' => ['dimensions' => 3072],
    ]);

    $index = $this->createMock(IndexInterface::class);
    $index->method('status')->willReturn(TRUE);
    $index->method('getServerId')->willReturn($serverId);
    $index->method('getServerInstanceIfAvailable')->willReturn($server);
    $index->method('getTrackerInstanceIfAvailable')->willReturn($tracker);
    $index->method('query')->willReturn($this->buildQueryMock());

    return $index;
  }

  public function testVectorStatusPrintsSingleIndexJson(): void {
    $faqIndex = $this->buildIndex('pinecone_vector_faq', 10, 5, 5);
    $faqIndex->expects($this->never())->method('indexItems');

    $service = $this->buildService([
      'faq_accordion_vector' => $faqIndex,
      'assistant_resources_vector' => $this->buildIndex('pinecone_vector_resources', 8, 8, 0),
    ]);

    $command = new VectorMaintenanceCommands($service);

    ob_start();
    $result = $command->vectorStatus('faq_vector', ['probe-now' => TRUE]);
    $output = ob_get_clean();

    $this->assertSame(0, $result);
    $decoded = json_decode((string) $output, TRUE, 512, JSON_THROW_ON_ERROR);
    $this->assertSame('faq_vector', $decoded['index_key']);
    $this->assertSame('healthy', $decoded['hygiene_status']);
    $this->assertSame('healthy', $decoded['probe_status']);
    $this->assertEquals(50.0, $decoded['percent_complete']);
    $this->assertSame('faq_accordion_vector', $decoded['index_id']);
  }

  public function testVectorBackfillPrintsProgressJson(): void {
    $tracker = $this->createMock(TrackerInterface::class);
    $tracker->method('getTotalItemsCount')->willReturn(12);
    $tracker->method('getIndexedItemsCount')->willReturnOnConsecutiveCalls(6, 9, 9);
    $tracker->method('getRemainingItemsCount')->willReturnOnConsecutiveCalls(6, 3, 3);

    $resourceIndex = $this->buildIndex('pinecone_vector_resources', 12, 6, 6, $tracker);
    $resourceIndex->expects($this->once())->method('indexItems')->with(5)->willReturn(3);

    $service = $this->buildService([
      'faq_accordion_vector' => $this->buildIndex('pinecone_vector_faq', 10, 10, 0),
      'assistant_resources_vector' => $resourceIndex,
    ]);

    $command = new VectorMaintenanceCommands($service);

    ob_start();
    $result = $command->vectorBackfill('resource_vector');
    $output = ob_get_clean();

    $this->assertSame(0, $result);
    $decoded = json_decode((string) $output, TRUE, 512, JSON_THROW_ON_ERROR);
    $this->assertSame('resume', $decoded['mode']);
    $this->assertSame(3, $decoded['processed_this_run']);
    $this->assertSame(3, $decoded['remaining_items']);
    $this->assertEquals(75.0, $decoded['percent_complete']);
    $this->assertSame('batch_limit_reached', $decoded['stop_reason']);
  }

  public function testDrushServicesRegisterVectorMaintenanceCommands(): void {
    $drushServicesPath = self::repoRoot() . '/' . self::MODULE_PATH . '/drush.services.yml';
    $this->assertFileExists($drushServicesPath);

    $drushServices = Yaml::parseFile($drushServicesPath);
    $service = $drushServices['services']['ilas_site_assistant.vector_maintenance_commands'] ?? NULL;

    $this->assertIsArray($service);
    $this->assertSame(
      '\\' . VectorMaintenanceCommands::class,
      $service['class'] ?? NULL,
    );
    $this->assertSame(['@ilas_site_assistant.vector_index_hygiene'], $service['arguments'] ?? NULL);
  }

}
