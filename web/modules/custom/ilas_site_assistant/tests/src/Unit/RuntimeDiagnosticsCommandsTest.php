<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Site\Settings;
use Drupal\ilas_site_assistant\Service\RetrievalConfigurationService;
use Drupal\ilas_site_assistant\Commands\RuntimeDiagnosticsCommands;
use Drupal\ilas_site_assistant\Service\RuntimeDiagnosticsMatrixBuilder;
use Drupal\ilas_site_assistant\Service\RuntimeTruthSnapshotBuilder;
use Drupal\search_api\IndexInterface;
use Drush\Log\DrushLoggerManager;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * Unit tests for the ilas:runtime-diagnostics Drush command.
 */
#[Group('ilas_site_assistant')]
final class RuntimeDiagnosticsCommandsTest extends TestCase {

  private const MATRIX_BUILDER_SERVICE = 'ilas_site_assistant.runtime_diagnostics_matrix_builder';

  private static function repoRoot(): string {
    return dirname(__DIR__, 7);
  }

  protected function tearDown(): void {
    new Settings([]);
    parent::tearDown();
  }

  public function testRuntimeDiagnosticsPrintsRequestedSectionAsJson(): void {
    $builder = $this->buildMatrixBuilder();

    $container = $this->createMock(ContainerInterface::class);
    $container->method('has')->with(self::MATRIX_BUILDER_SERVICE)->willReturn(TRUE);
    $container->method('get')->with(self::MATRIX_BUILDER_SERVICE)->willReturn($builder);

    $logger = $this->createMock(DrushLoggerManager::class);
    $logger->expects($this->never())->method('error');

    $output = new BufferedOutput();
    $command = new RuntimeDiagnosticsCommands($container);
    $command->setLogger($logger);
    $command->setOutput($output);

    $result = $command->runtimeDiagnostics(['section' => 'matrix']);

    $this->assertSame(0, $result);
    $decoded = json_decode($output->fetch(), TRUE, 512, JSON_THROW_ON_ERROR);
    $this->assertContains('llm.provider', array_column($decoded, 'fact_key'));
    $this->assertContains('llm.request_time_generation_reachable', array_column($decoded, 'fact_key'));
    $this->assertContains('llm.cohere_api_key_present', array_column($decoded, 'fact_key'));
    $this->assertNotContains('llm.request_time_retired', array_column($decoded, 'fact_key'));
    $this->assertNotContains('llm.google_generation_reachable', array_column($decoded, 'fact_key'));
  }

  public function testRuntimeDiagnosticsFailsWhenBuilderServiceIsMissing(): void {
    $container = $this->createMock(ContainerInterface::class);
    $container->method('has')->with(self::MATRIX_BUILDER_SERVICE)->willReturn(FALSE);

    $logger = $this->createMock(DrushLoggerManager::class);
    $logger->expects($this->once())->method('error');

    $command = new RuntimeDiagnosticsCommands($container);
    $command->setLogger($logger);
    $command->setOutput(new BufferedOutput());

    $this->assertSame(1, $command->runtimeDiagnostics());
  }

  public function testDrushServicesUseServiceContainerForRuntimeDiagnostics(): void {
    $drushServicesPath = self::repoRoot() . '/web/modules/custom/ilas_site_assistant/drush.services.yml';
    $this->assertFileExists($drushServicesPath);

    $drushServices = Yaml::parseFile($drushServicesPath);
    $service = $drushServices['services']['ilas_site_assistant.runtime_diagnostics_commands'] ?? NULL;

    $this->assertIsArray($service);
    $this->assertSame(['@service_container'], $service['arguments'] ?? NULL);
  }

  private function buildMatrixBuilder(): RuntimeDiagnosticsMatrixBuilder {
    new Settings([
      'ilas_site_assistant_legalserver_online_application_url' => 'https://example.com/intake?pid=60&h=test',
    ]);

    $snapshotBuilder = $this->createStub(RuntimeTruthSnapshotBuilder::class);
    $snapshotBuilder->method('buildSnapshot')->willReturn([
      'environment' => [
        'effective_environment' => 'pantheon-live',
        'pantheon_environment' => 'live',
      ],
      'effective_runtime' => [
        'llm' => [
          'enabled' => TRUE,
          'provider' => 'cohere',
          'model' => 'command-a-03-2025',
          'runtime_ready' => TRUE,
          'request_time_generation_reachable' => TRUE,
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
          'enabled' => TRUE,
          'runtime_ready' => TRUE,
          'api_key_present' => TRUE,
        ],
        'langfuse' => [
          'enabled' => FALSE,
          'public_key_present' => FALSE,
          'secret_key_present' => FALSE,
        ],
        'sentry' => [
          'enabled' => FALSE,
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
        'legalserver_online_application_url_present' => TRUE,
      ],
      'override_channels' => [
        'llm.provider' => 'Cohere-first request-time transport contract',
        'llm.model' => 'Cohere-first request-time transport contract',
        'llm.request_time_generation_reachable' => 'LlmEnhancer::isEnabled()',
        'vector_search.enabled' => 'config export',
      ],
      'divergences' => [],
    ]);

    return new RuntimeDiagnosticsMatrixBuilder(
      $snapshotBuilder,
      $this->buildRetrievalConfiguration(),
    );
  }

  private function buildRetrievalConfiguration(): RetrievalConfigurationService {
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

}
