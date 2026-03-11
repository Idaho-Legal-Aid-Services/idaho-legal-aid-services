<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Site\Settings;
use Drupal\ilas_site_assistant\Service\RetrievalConfigurationService;
use Drupal\search_api\IndexInterface;
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
   */
  private function buildService(array $retrieval, array $canonicalUrls, array $indexes = []): RetrievalConfigurationService {
    $config = $this->createStub(ImmutableConfig::class);
    $config->method('get')
      ->willReturnCallback(static function (string $key) use ($retrieval, $canonicalUrls) {
        return match ($key) {
          'retrieval' => $retrieval,
          'canonical_urls' => $canonicalUrls,
          default => NULL,
        };
      });

    $configFactory = $this->createStub(ConfigFactoryInterface::class);
    $configFactory->method('get')
      ->with('ilas_site_assistant.settings')
      ->willReturn($config);

    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('load')
      ->willReturnCallback(static function (string $id) use ($indexes) {
        return $indexes[$id] ?? NULL;
      });

    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getStorage')
      ->with('search_api_index')
      ->willReturn($storage);

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
   * Healthy config stays healthy and injects the runtime LegalServer URL.
   */
  public function testHealthySnapshotInjectsRuntimeLegalServerUrl(): void {
    new Settings([
      'ilas_site_assistant_legalserver_online_application_url' => 'https://example.com/intake?pid=60&h=test',
    ]);

    $service = $this->buildService($this->healthyRetrieval(), $this->healthyCanonicalUrls(), $this->healthyIndexes());
    $snapshot = $service->getHealthSnapshot();
    $canonicalUrls = $service->getCanonicalUrls();

    $this->assertSame('healthy', $snapshot['status']);
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

    $service = $this->buildService($retrieval, $this->healthyCanonicalUrls(), $this->healthyIndexes());
    $snapshot = $service->getHealthSnapshot();

    $this->assertSame('degraded', $snapshot['status']);
    $this->assertFalse($snapshot['retrieval']['faq_index']['configured']);
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

    $service = $this->buildService($retrieval, $this->healthyCanonicalUrls(), $this->healthyIndexes());
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

    $service = $this->buildService($this->healthyRetrieval(), $this->healthyCanonicalUrls(), $indexes);
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

    $service = $this->buildService($this->healthyRetrieval(), $this->healthyCanonicalUrls(), $indexes);
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

    $service = $this->buildService($this->healthyRetrieval(), $canonicalUrls, $this->healthyIndexes());
    $snapshot = $service->getHealthSnapshot();

    $this->assertSame('degraded', $snapshot['status']);
    $this->assertContains('health', $snapshot['canonical_urls']['service_areas']['missing']);
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
   *
   * @dataProvider legalServerUrlProvider
   */
  public function testLegalServerRuntimeUrlValidation(array $settings, string $expectedStatus, bool $expectedHttps, bool $expectedConfigured): void {
    new Settings($settings);

    $service = $this->buildService($this->healthyRetrieval(), $this->healthyCanonicalUrls(), $this->healthyIndexes());
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
