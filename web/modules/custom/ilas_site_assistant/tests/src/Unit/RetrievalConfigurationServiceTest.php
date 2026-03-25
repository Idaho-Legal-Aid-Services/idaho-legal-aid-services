<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Component\Plugin\Exception\PluginNotFoundException;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Site\Settings;
use Drupal\ilas_site_assistant\Service\RetrievalConfigurationService;
use Drupal\search_api\IndexInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for retrieval configuration governance and drift checks.
 */
#[Group('ilas_site_assistant')]
final class RetrievalConfigurationServiceTest extends TestCase {

  /**
   * Resets site settings between tests.
   */
  protected function setUp(): void {
    parent::setUp();
    new Settings([]);
  }

  /**
   * Builds the service under test.
   *
   * @param array<string, mixed> $retrieval
   *   Retrieval config block.
   * @param array<string, mixed> $canonicalUrls
   *   Canonical URL config block.
   * @param array<string, \Drupal\search_api\IndexInterface> $indexes
   *   Search API indexes by machine name.
   * @param array<string, object> $servers
   *   Search API servers by machine name.
   */
  private function buildService(
    array $retrieval,
    array $canonicalUrls,
    array $indexes = [],
    array $servers = [],
    bool $enableFaq = TRUE,
    bool $enableResources = TRUE,
    bool $vectorEnabled = TRUE,
    ?EntityTypeManagerInterface $entityTypeManager = NULL,
  ): RetrievalConfigurationService {
    $config = $this->createStub(ImmutableConfig::class);
    $config->method('get')
      ->willReturnCallback(static function (string $key) use ($retrieval, $canonicalUrls, $enableFaq, $enableResources, $vectorEnabled) {
        return match ($key) {
          'retrieval' => $retrieval,
          'canonical_urls' => $canonicalUrls,
          'enable_faq' => $enableFaq,
          'enable_resources' => $enableResources,
          'vector_search.enabled' => $vectorEnabled,
          default => NULL,
        };
      });

    $configFactory = $this->createStub(ConfigFactoryInterface::class);
    $configFactory->method('get')
      ->with('ilas_site_assistant.settings')
      ->willReturn($config);

    $indexStorage = $this->createMock(EntityStorageInterface::class);
    $indexStorage->method('load')
      ->willReturnCallback(static function (string $id) use ($indexes) {
        return $indexes[$id] ?? NULL;
      });
    $serverStorage = $this->createMock(EntityStorageInterface::class);
    $serverStorage->method('load')
      ->willReturnCallback(static function (string $id) use ($servers) {
        return $servers[$id] ?? NULL;
      });

    if ($entityTypeManager === NULL) {
      $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
      $entityTypeManager->method('getStorage')
        ->willReturnCallback(static function (string $entity_type_id) use ($indexStorage, $serverStorage) {
          return match ($entity_type_id) {
            'search_api_index' => $indexStorage,
            'search_api_server' => $serverStorage,
            default => throw new \InvalidArgumentException('Unexpected storage request: ' . $entity_type_id),
          };
        });
    }

    return new RetrievalConfigurationService($configFactory, $entityTypeManager);
  }

  /**
   * Returns a healthy retrieval config block.
   */
  private function healthyRetrieval(): array {
    return [
      'faq_index_id' => 'faq_accordion',
      'resource_index_id' => 'assistant_resources',
      'resource_fallback_index_id' => 'content',
      'faq_vector_index_id' => 'faq_accordion_vector',
      'resource_vector_index_id' => 'assistant_resources_vector',
    ];
  }

  /**
   * Returns healthy canonical URLs with all required service areas.
   */
  private function healthyCanonicalUrls(): array {
    return [
      'apply' => '/apply-for-help',
      'services' => '/services',
      'service_areas' => [
        'housing' => '/legal-help/housing',
        'family' => '/legal-help/family',
        'seniors' => '/legal-help/seniors',
        'health' => '/legal-help/health',
        'consumer' => '/legal-help/consumer',
        'civil_rights' => '/legal-help/civil-rights',
      ],
    ];
  }

  /**
   * Returns an enabled Search API index stub.
   */
  private function enabledIndex(): IndexInterface {
    $index = $this->createMock(IndexInterface::class);
    $index->method('status')->willReturn(TRUE);
    return $index;
  }

  /**
   * Returns a disabled Search API index stub.
   */
  private function disabledIndex(): IndexInterface {
    $index = $this->createMock(IndexInterface::class);
    $index->method('status')->willReturn(FALSE);
    return $index;
  }

  /**
   * Returns the healthy index map for all governed retrieval IDs.
   *
   * @return array<string, \Drupal\search_api\IndexInterface>
   *   Healthy indexes by machine name.
   */
  private function healthyIndexes(): array {
    $enabled = $this->enabledIndex();
    return [
      'faq_accordion' => $enabled,
      'assistant_resources' => $enabled,
      'content' => $enabled,
      'faq_accordion_vector' => $enabled,
      'assistant_resources_vector' => $enabled,
    ];
  }

  /**
   * Returns the healthy server map for all governed Search API servers.
   *
   * @return array<string, object>
   *   Healthy servers by machine name.
   */
  private function healthyServers(): array {
    $enabled = new class {
      public function status(): bool {
        return TRUE;
      }
    };

    return [
      'database' => $enabled,
      'pinecone_vector_faq' => $enabled,
      'pinecone_vector_resources' => $enabled,
    ];
  }

  /**
   * Healthy config stays healthy and injects the runtime LegalServer URL.
   */
  public function testHealthySnapshotInjectsRuntimeLegalServerUrl(): void {
    new Settings([
      'ilas_site_assistant_legalserver_online_application_url' => 'https://example.com/intake?pid=60&h=test',
    ]);

    $service = $this->buildService($this->healthyRetrieval(), $this->healthyCanonicalUrls(), $this->healthyIndexes(), $this->healthyServers());
    $snapshot = $service->getHealthSnapshot();
    $canonicalUrls = $service->getCanonicalUrls();

    $this->assertSame('healthy', $snapshot['status']);
    $this->assertSame('required', $snapshot['retrieval']['faq_index']['classification']);
    $this->assertSame('feature_gated', $snapshot['retrieval']['faq_vector_index']['classification']);
    $this->assertSame('healthy', $snapshot['retrieval']['database_server']['status']);
    $this->assertSame('healthy', $snapshot['retrieval']['pinecone_vector_faq_server']['status']);
    $this->assertSame('healthy', $snapshot['retrieval']['pinecone_vector_resources_server']['status']);
    $this->assertSame('healthy', $snapshot['canonical_urls']['legalserver_intake_url']['status']);
    $this->assertSame('skipped', $snapshot['canonical_urls']['legalserver_intake_url']['probe_status']);
    $this->assertSame('https://example.com/intake?pid=60&h=test', $canonicalUrls['online_application']);
  }

  /**
   * Missing retrieval identifiers degrade the snapshot.
   */
  public function testMissingRetrievalIdDegradesSnapshot(): void {
    new Settings([
      'ilas_site_assistant_legalserver_online_application_url' => 'https://example.com/intake?pid=60&h=test',
    ]);

    $retrieval = $this->healthyRetrieval();
    unset($retrieval['faq_index_id']);

    $service = $this->buildService($retrieval, $this->healthyCanonicalUrls(), $this->healthyIndexes(), $this->healthyServers());
    $snapshot = $service->getHealthSnapshot();

    $this->assertSame('degraded', $snapshot['status']);
    $this->assertFalse($snapshot['retrieval']['faq_index']['configured']);
    $this->assertSame('index_id_unconfigured', $snapshot['retrieval']['faq_index']['failure_code']);
  }

  /**
   * Invalid machine names degrade the snapshot before entity lookup.
   */
  public function testInvalidMachineNameDegradesSnapshot(): void {
    new Settings([
      'ilas_site_assistant_legalserver_online_application_url' => 'https://example.com/intake?pid=60&h=test',
    ]);

    $retrieval = $this->healthyRetrieval();
    $retrieval['faq_vector_index_id'] = 'faq-vector';

    $service = $this->buildService($retrieval, $this->healthyCanonicalUrls(), $this->healthyIndexes(), $this->healthyServers());
    $snapshot = $service->getHealthSnapshot();

    $this->assertSame('degraded', $snapshot['status']);
    $this->assertFalse($snapshot['retrieval']['faq_vector_index']['machine_name_valid']);
  }

  /**
   * Missing Search API indexes degrade the snapshot.
   */
  public function testMissingIndexDegradesSnapshot(): void {
    new Settings([
      'ilas_site_assistant_legalserver_online_application_url' => 'https://example.com/intake?pid=60&h=test',
    ]);

    $indexes = $this->healthyIndexes();
    unset($indexes['assistant_resources_vector']);

    $service = $this->buildService($this->healthyRetrieval(), $this->healthyCanonicalUrls(), $indexes, $this->healthyServers());
    $snapshot = $service->getHealthSnapshot();

    $this->assertSame('degraded', $snapshot['status']);
    $this->assertFalse($snapshot['retrieval']['resource_vector_index']['exists']);
  }

  /**
   * Disabled Search API indexes degrade the snapshot.
   */
  public function testDisabledIndexDegradesSnapshot(): void {
    new Settings([
      'ilas_site_assistant_legalserver_online_application_url' => 'https://example.com/intake?pid=60&h=test',
    ]);

    $indexes = $this->healthyIndexes();
    $indexes['assistant_resources'] = $this->disabledIndex();

    $service = $this->buildService($this->healthyRetrieval(), $this->healthyCanonicalUrls(), $indexes, $this->healthyServers());
    $snapshot = $service->getHealthSnapshot();

    $this->assertSame('degraded', $snapshot['status']);
    $this->assertFalse($snapshot['retrieval']['resource_index']['enabled']);
  }

  /**
   * Missing service-area URLs degrade the snapshot.
   */
  public function testMissingServiceAreaDegradesSnapshot(): void {
    new Settings([
      'ilas_site_assistant_legalserver_online_application_url' => 'https://example.com/intake?pid=60&h=test',
    ]);

    $canonicalUrls = $this->healthyCanonicalUrls();
    unset($canonicalUrls['service_areas']['health']);

    $service = $this->buildService($this->healthyRetrieval(), $canonicalUrls, $this->healthyIndexes(), $this->healthyServers());
    $snapshot = $service->getHealthSnapshot();

    $this->assertSame('degraded', $snapshot['status']);
    $this->assertContains('health', $snapshot['canonical_urls']['service_areas']['missing']);
  }

  /**
   * Feature-gated vector dependencies are skipped when vector search is off.
   */
  public function testVectorDependenciesSkipWhenDisabled(): void {
    new Settings([
      'ilas_site_assistant_legalserver_online_application_url' => 'https://example.com/intake?pid=60&h=test',
    ]);

    $indexes = $this->healthyIndexes();
    unset($indexes['faq_accordion_vector'], $indexes['assistant_resources_vector']);
    $servers = $this->healthyServers();
    unset($servers['pinecone_vector_faq'], $servers['pinecone_vector_resources']);

    $service = $this->buildService(
      $this->healthyRetrieval(),
      $this->healthyCanonicalUrls(),
      $indexes,
      $servers,
      TRUE,
      TRUE,
      FALSE,
    );
    $snapshot = $service->getHealthSnapshot();

    $this->assertSame('healthy', $snapshot['status']);
    $this->assertFalse($snapshot['retrieval']['faq_vector_index']['active']);
    $this->assertSame('skipped', $snapshot['retrieval']['faq_vector_index']['status']);
    $this->assertSame('skipped', $snapshot['retrieval']['pinecone_vector_faq_server']['status']);
    $this->assertSame('skipped', $snapshot['retrieval']['pinecone_vector_resources_server']['status']);
  }

  /**
   * Vector indexes pointed at the wrong Pinecone server degrade health.
   */
  public function testVectorServerMismatchDegradesSnapshot(): void {
    new Settings([
      'ilas_site_assistant_legalserver_online_application_url' => 'https://example.com/intake?pid=60&h=test',
    ]);

    $faqVectorIndex = $this->enabledIndex();
    $faqVectorIndex->method('getServerId')->willReturn('pinecone_vector_resources');

    $resourceVectorIndex = $this->enabledIndex();
    $resourceVectorIndex->method('getServerId')->willReturn('pinecone_vector_resources');

    $indexes = $this->healthyIndexes();
    $indexes['faq_accordion_vector'] = $faqVectorIndex;
    $indexes['assistant_resources_vector'] = $resourceVectorIndex;

    $service = $this->buildService($this->healthyRetrieval(), $this->healthyCanonicalUrls(), $indexes, $this->healthyServers());
    $snapshot = $service->getHealthSnapshot();

    $this->assertSame('degraded', $snapshot['status']);
    $this->assertSame('server_mismatch', $snapshot['retrieval']['faq_vector_index']['failure_code']);
    $this->assertSame('pinecone_vector_faq', $snapshot['retrieval']['faq_vector_index']['expected_server_id']);
    $this->assertSame('pinecone_vector_resources', $snapshot['retrieval']['faq_vector_index']['resolved_server_id']);
    $this->assertSame('healthy', $snapshot['retrieval']['resource_vector_index']['status']);
  }

  /**
   * Standby fallback index failures remain operator-visible.
   */
  public function testFallbackIndexFailureDegradesHealth(): void {
    new Settings([
      'ilas_site_assistant_legalserver_online_application_url' => 'https://example.com/intake?pid=60&h=test',
    ]);

    $indexes = $this->healthyIndexes();
    unset($indexes['content']);

    $service = $this->buildService($this->healthyRetrieval(), $this->healthyCanonicalUrls(), $indexes, $this->healthyServers());
    $snapshot = $service->getHealthSnapshot();

    $this->assertSame('degraded', $snapshot['status']);
    $this->assertSame('optional', $snapshot['retrieval']['resource_fallback_index']['classification']);
    $this->assertSame('index_missing', $snapshot['retrieval']['resource_fallback_index']['failure_code']);
  }

  /**
   * Lexical Search API server failures degrade active lexical retrieval.
   */
  public function testLexicalServerFailureDegradesHealth(): void {
    new Settings([
      'ilas_site_assistant_legalserver_online_application_url' => 'https://example.com/intake?pid=60&h=test',
    ]);

    $servers = $this->healthyServers();
    $servers['database'] = new class {
      public function status(): bool {
        return FALSE;
      }
    };

    $service = $this->buildService($this->healthyRetrieval(), $this->healthyCanonicalUrls(), $this->healthyIndexes(), $servers);
    $snapshot = $service->getHealthSnapshot();

    $this->assertSame('degraded', $snapshot['status']);
    $this->assertFalse($snapshot['retrieval']['faq_index']['server_enabled']);
    $this->assertSame('server_disabled', $snapshot['retrieval']['faq_index']['failure_code']);
    $this->assertSame('degraded', $snapshot['retrieval']['database_server']['status']);
  }

  /**
   * Missing Search API index entity type degrades health without fatalling.
   */
  public function testMissingSearchApiIndexEntityTypeDegradesSnapshot(): void {
    new Settings([
      'ilas_site_assistant_legalserver_online_application_url' => 'https://example.com/intake?pid=60&h=test',
    ]);

    $serverStorage = $this->createMock(EntityStorageInterface::class);
    $serverStorage->method('load')
      ->willReturnCallback(static function (string $id) {
        return in_array($id, ['database', 'pinecone_vector_faq', 'pinecone_vector_resources'], TRUE)
          ? new class {
            public function status(): bool {
              return TRUE;
            }
          }
          : NULL;
      });

    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getStorage')
      ->willReturnCallback(static function (string $entity_type_id) use ($serverStorage) {
        return match ($entity_type_id) {
          'search_api_index' => throw new PluginNotFoundException($entity_type_id),
          'search_api_server' => $serverStorage,
          default => throw new \InvalidArgumentException('Unexpected storage request: ' . $entity_type_id),
        };
      });

    $service = $this->buildService(
      $this->healthyRetrieval(),
      $this->healthyCanonicalUrls(),
      [],
      [],
      TRUE,
      TRUE,
      TRUE,
      $entityTypeManager,
    );
    $snapshot = $service->getHealthSnapshot();

    $this->assertSame('degraded', $snapshot['status']);
    $this->assertFalse($snapshot['retrieval']['faq_index']['index_entity_type_available']);
    $this->assertSame('index_entity_type_missing', $snapshot['retrieval']['faq_index']['failure_code']);
  }

  /**
   * Missing Search API server entity type degrades health without fatalling.
   */
  public function testMissingSearchApiServerEntityTypeDegradesSnapshot(): void {
    new Settings([
      'ilas_site_assistant_legalserver_online_application_url' => 'https://example.com/intake?pid=60&h=test',
    ]);

    $indexStorage = $this->createMock(EntityStorageInterface::class);
    $indexStorage->method('load')
      ->willReturnCallback(function (string $id) {
        $index = $this->createMock(IndexInterface::class);
        $index->method('status')->willReturn(TRUE);
        $index->method('getServerId')->willReturn(match ($id) {
          'faq_accordion', 'assistant_resources', 'content' => 'database',
          'faq_accordion_vector' => 'pinecone_vector_faq',
          'assistant_resources_vector' => 'pinecone_vector_resources',
          default => '',
        });
        return $index;
      });

    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getStorage')
      ->willReturnCallback(static function (string $entity_type_id) use ($indexStorage) {
        return match ($entity_type_id) {
          'search_api_index' => $indexStorage,
          'search_api_server' => throw new PluginNotFoundException($entity_type_id),
          default => throw new \InvalidArgumentException('Unexpected storage request: ' . $entity_type_id),
        };
      });

    $service = $this->buildService(
      $this->healthyRetrieval(),
      $this->healthyCanonicalUrls(),
      [],
      [],
      TRUE,
      TRUE,
      TRUE,
      $entityTypeManager,
    );
    $snapshot = $service->getHealthSnapshot();

    $this->assertSame('degraded', $snapshot['status']);
    $this->assertFalse($snapshot['retrieval']['faq_index']['server_entity_type_available']);
    $this->assertSame('server_entity_type_missing', $snapshot['retrieval']['faq_index']['failure_code']);
    $this->assertFalse($snapshot['retrieval']['database_server']['entity_type_available']);
    $this->assertSame('server_entity_type_missing', $snapshot['retrieval']['database_server']['failure_code']);
  }

  /**
   * LegalServer runtime URL validation catches missing and malformed values.
   *
   * @param array<string, mixed> $settings
   *   Runtime site settings.
   * @param string $expectedStatus
   *   Expected LegalServer check status.
   * @param bool $expectedHttps
   *   Expected HTTPS validation flag.
   * @param bool $expectedConfigured
   *   Expected configured flag.
   */
  #[DataProvider('legalServerUrlProvider')]
  public function testLegalServerRuntimeUrlValidation(array $settings, string $expectedStatus, bool $expectedHttps, bool $expectedConfigured): void {
    new Settings($settings);

    $service = $this->buildService($this->healthyRetrieval(), $this->healthyCanonicalUrls(), $this->healthyIndexes(), $this->healthyServers());
    $check = $service->getHealthSnapshot()['canonical_urls']['legalserver_intake_url'];

    $this->assertSame($expectedStatus, $check['status']);
    $this->assertSame($expectedHttps, $check['https']);
    $this->assertSame($expectedConfigured, $check['configured']);
  }

  /**
   * Data provider for LegalServer runtime URL validation.
   */
  public static function legalServerUrlProvider(): array {
    return [
      'missing' => [
        [],
        'degraded',
        FALSE,
        FALSE,
      ],
      'non_https' => [
        ['ilas_site_assistant_legalserver_online_application_url' => 'http://example.com/intake?pid=60&h=test'],
        'degraded',
        FALSE,
        TRUE,
      ],
      'missing_query_key' => [
        ['ilas_site_assistant_legalserver_online_application_url' => 'https://example.com/intake?pid=60'],
        'degraded',
        TRUE,
        TRUE,
      ],
      'healthy' => [
        ['ilas_site_assistant_legalserver_online_application_url' => 'https://example.com/intake?pid=60&h=test'],
        'healthy',
        TRUE,
        TRUE,
      ],
    ];
  }

}
