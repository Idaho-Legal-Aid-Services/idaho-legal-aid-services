<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Site\Settings;
use Drupal\ilas_site_assistant\Commands\RuntimeDiagnosticsCommands;
use Drupal\ilas_site_assistant\Service\RetrievalConfigurationService;
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

  private const MODULE_PATH = 'web/modules/custom/ilas_site_assistant';
  private const MATRIX_BUILDER_SERVICE = 'ilas_site_assistant.runtime_diagnostics_matrix_builder';

  /**
   * Returns the repository root.
   */
  private static function repoRoot(): string {
    return dirname(__DIR__, 7);
  }

  protected function tearDown(): void {
    new Settings([]);
    parent::tearDown();
  }

  /**
   * Section output remains machine-readable JSON on success.
   */
  public function testRuntimeDiagnosticsPrintsRequestedSectionAsJson(): void {
    new Settings([
      'ilas_site_assistant_legalserver_online_application_url' => 'https://example.com/intake?pid=60&h=test',
    ]);

    $snapshotBuilder = $this->createMock(RuntimeTruthSnapshotBuilder::class);
    $snapshotBuilder->expects($this->once())
      ->method('buildSnapshot')
      ->willReturn([
        'environment' => [
          'effective_environment' => 'local',
          'pantheon_environment' => '',
        ],
        'effective_runtime' => [
          'llm' => [
            'enabled' => FALSE,
            'runtime_ready' => FALSE,
            'gemini_api_key_present' => FALSE,
            'vertex_service_account_present' => FALSE,
          ],
          'vector_search' => ['enabled' => FALSE],
          'langfuse' => [
            'enabled' => TRUE,
            'public_key_present' => TRUE,
            'secret_key_present' => TRUE,
          ],
          'voyage' => [
            'enabled' => FALSE,
            'runtime_ready' => FALSE,
            'api_key_present' => FALSE,
          ],
          'sentry' => [
            'client_key_present' => FALSE,
            'public_dsn_present' => FALSE,
          ],
          'pinecone' => ['key_present' => FALSE],
        ],
        'runtime_site_settings' => [
          'diagnostics_token_present' => FALSE,
        ],
        'override_channels' => [],
        'divergences' => [],
      ]);

    $retrievalConfiguration = new RetrievalConfigurationService(
      $this->buildRetrievalConfigFactory(),
      $this->buildEntityTypeManager(),
    );

    $builder = new RuntimeDiagnosticsMatrixBuilder(
      $snapshotBuilder,
      $retrievalConfiguration,
    );

    $container = $this->createMock(ContainerInterface::class);
    $container->expects($this->once())
      ->method('has')
      ->with(self::MATRIX_BUILDER_SERVICE)
      ->willReturn(TRUE);
    $container->expects($this->once())
      ->method('get')
      ->with(self::MATRIX_BUILDER_SERVICE)
      ->willReturn($builder);

    $logger = $this->createMock(DrushLoggerManager::class);
    $logger->expects($this->never())
      ->method('error');

    $output = new BufferedOutput();
    $command = new RuntimeDiagnosticsCommands($container);
    $command->setLogger($logger);
    $command->setOutput($output);

    $result = $command->runtimeDiagnostics(['section' => 'matrix']);

    $this->assertSame(0, $result);
    $decoded = json_decode($output->fetch(), TRUE, 512, JSON_THROW_ON_ERROR);
    $this->assertIsArray($decoded);
    $this->assertNotEmpty($decoded);
    $this->assertContains('langfuse.enabled', array_column($decoded, 'fact_key'));
  }

  /**
   * Missing builder service fails locally without breaking command discovery.
   */
  public function testRuntimeDiagnosticsFailsWhenBuilderServiceIsMissing(): void {
    $container = $this->createMock(ContainerInterface::class);
    $container->expects($this->once())
      ->method('has')
      ->with(self::MATRIX_BUILDER_SERVICE)
      ->willReturn(FALSE);
    $container->expects($this->never())
      ->method('get');

    $logger = $this->createMock(DrushLoggerManager::class);
    $logger->expects($this->once())
      ->method('error')
      ->with(
        'Runtime diagnostics unavailable: missing service {service}. Rebuild Drupal caches (`drush cr`) and retry.',
        $this->callback(static fn(array $context): bool => ($context['service'] ?? NULL) === self::MATRIX_BUILDER_SERVICE),
      );

    $command = new RuntimeDiagnosticsCommands($container);
    $command->setLogger($logger);
    $command->setOutput(new BufferedOutput());

    $this->assertSame(1, $command->runtimeDiagnostics());
  }

  /**
   * Drush service wiring resolves the builder lazily via the service container.
   */
  public function testDrushServicesUseServiceContainerForRuntimeDiagnostics(): void {
    $drushServicesPath = self::repoRoot() . '/' . self::MODULE_PATH . '/drush.services.yml';
    $this->assertFileExists($drushServicesPath);

    $drushServices = Yaml::parseFile($drushServicesPath);
    $service = $drushServices['services']['ilas_site_assistant.runtime_diagnostics_commands'] ?? NULL;

    $this->assertIsArray($service);
    $this->assertSame(
      '\\' . RuntimeDiagnosticsCommands::class,
      $service['class'] ?? NULL,
    );
    $this->assertSame(['@service_container'], $service['arguments'] ?? NULL);
    $this->assertNotContains(self::MATRIX_BUILDER_SERVICE, $service['arguments'] ?? []);
    $this->assertNotContains('@' . self::MATRIX_BUILDER_SERVICE, $service['arguments'] ?? []);
  }

  /**
   * Builds a healthy retrieval config factory for runtime diagnostics.
   */
  private function buildRetrievalConfigFactory(): ConfigFactoryInterface {
    $config = $this->createStub(ImmutableConfig::class);
    $config->method('get')->willReturnCallback(static fn(string $key): mixed => match ($key) {
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
    });

    $configFactory = $this->createStub(ConfigFactoryInterface::class);
    $configFactory->method('get')
      ->with('ilas_site_assistant.settings')
      ->willReturn($config);

    return $configFactory;
  }

  /**
   * Builds a healthy Search API entity manager stub.
   */
  private function buildEntityTypeManager(): EntityTypeManagerInterface {
    $databaseIndex = $this->createMock(IndexInterface::class);
    $databaseIndex->method('status')->willReturn(TRUE);
    $databaseIndex->method('getServerId')->willReturn('database');

    $faqVectorIndex = $this->createMock(IndexInterface::class);
    $faqVectorIndex->method('status')->willReturn(TRUE);
    $faqVectorIndex->method('getServerId')->willReturn('pinecone_vector_faq');

    $resourceVectorIndex = $this->createMock(IndexInterface::class);
    $resourceVectorIndex->method('status')->willReturn(TRUE);
    $resourceVectorIndex->method('getServerId')->willReturn('pinecone_vector_resources');

    $indexes = [
      'faq_accordion' => $databaseIndex,
      'assistant_resources' => $databaseIndex,
      'content' => $databaseIndex,
      'faq_accordion_vector' => $faqVectorIndex,
      'assistant_resources_vector' => $resourceVectorIndex,
    ];

    $enabledServer = new class {

      public function status(): bool {
        return TRUE;
      }

    };

    $servers = [
      'database' => $enabledServer,
      'pinecone_vector_faq' => $enabledServer,
      'pinecone_vector_resources' => $enabledServer,
    ];

    $indexStorage = $this->createMock(EntityStorageInterface::class);
    $indexStorage->method('load')
      ->willReturnCallback(static fn(string $id) => $indexes[$id] ?? NULL);

    $serverStorage = $this->createMock(EntityStorageInterface::class);
    $serverStorage->method('load')
      ->willReturnCallback(static fn(string $id) => $servers[$id] ?? NULL);

    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getStorage')
      ->willReturnCallback(static fn(string $entityTypeId) => match ($entityTypeId) {
        'search_api_index' => $indexStorage,
        'search_api_server' => $serverStorage,
        default => throw new \InvalidArgumentException('Unexpected storage: ' . $entityTypeId),
      });

    return $entityTypeManager;
  }

}
