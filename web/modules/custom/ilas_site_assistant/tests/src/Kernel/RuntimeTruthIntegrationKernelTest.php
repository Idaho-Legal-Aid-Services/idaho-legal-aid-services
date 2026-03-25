<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Kernel;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Site\Settings;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Runtime integration tests for stored-versus-effective assistant truth.
 */
#[Group('ilas_site_assistant')]
#[RunTestsInSeparateProcesses]
final class RuntimeTruthIntegrationKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'filter',
    'text',
    'node',
    'taxonomy',
    'views',
    'search_api',
    'search_api_db',
    'entity_reference_revisions',
    'paragraphs',
    'ilas_site_assistant',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('taxonomy_term');
    $this->installEntitySchema('paragraph');
    $this->installEntitySchema('search_api_task');
    $this->installConfig(['search_api', 'search_api_db', 'ilas_site_assistant']);
    $this->copyConfig(
      $this->container->get('config.storage'),
      $this->container->get('config.storage.sync'),
    );
    $this->container->get('config.storage.sync')->write('key.key.pinecone_api_key', [
      'key_provider_settings' => [
        'key_value' => '',
      ],
    ]);
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    unset($GLOBALS['config']['ilas_site_assistant.settings']);
    new Settings([]);
    parent::tearDown();
  }

  /**
   * Real settings.php-style overrides surface in runtime truth safely.
   */
  public function testRuntimeTruthCapturesRealConfigOverrideAndSanitizesLegalServerUrl(): void {
    new Settings([
      'ilas_vector_search_override_channel' => 'settings.php runtime toggle -> getenv/pantheon_get_secret',
      'ilas_site_assistant_legalserver_online_application_url' => 'https://example.com/intake?pid=60&h=secret-token',
    ]);

    $GLOBALS['config']['ilas_site_assistant.settings']['vector_search']['enabled'] = TRUE;
    $this->resetAssistantConfig();

    $snapshot = $this->container
      ->get('ilas_site_assistant.runtime_truth_snapshot_builder')
      ->buildSnapshot();

    $this->assertFalse($snapshot['exported_storage']['vector_search']['enabled']);
    $this->assertTrue($snapshot['effective_runtime']['vector_search']['enabled']);
    $this->assertSame(
      'settings.php runtime toggle -> getenv/pantheon_get_secret',
      $snapshot['override_channels']['vector_search.enabled'],
    );

    $divergence = $this->findDivergence($snapshot, 'vector_search.enabled');
    $this->assertNotNull($divergence);
    $this->assertFalse($divergence['stored_value']);
    $this->assertTrue($divergence['effective_value']);
    $this->assertSame(
      'settings.php runtime toggle -> getenv/pantheon_get_secret',
      $divergence['authoritative_source'],
    );

    $this->assertFalse($snapshot['exported_storage']['retrieval']['legalserver_online_application_url']['present']);
    $this->assertTrue($snapshot['effective_runtime']['retrieval']['legalserver_online_application_url']['present']);
    $this->assertSame('healthy', $snapshot['effective_runtime']['retrieval']['legalserver_online_application_url']['status']);
    $this->assertSame(
      'healthy',
      $snapshot['effective_runtime']['retrieval']['health']['canonical_urls']['legalserver_intake_url']['status'],
    );
    $this->assertTrue($snapshot['runtime_site_settings']['legalserver_online_application_url_present']);

    $json = json_encode($snapshot, JSON_THROW_ON_ERROR);
    $this->assertStringNotContainsString('secret-token', $json);
    $this->assertStringNotContainsString('https://example.com/intake?pid=60&h=secret-token', $json);
  }

  /**
   * Missing runtime LegalServer settings remain visible as degraded truth.
   */
  public function testRuntimeTruthReportsMissingLegalServerRuntimeSettingAsDegraded(): void {
    $snapshot = $this->container
      ->get('ilas_site_assistant.runtime_truth_snapshot_builder')
      ->buildSnapshot();

    $this->assertFalse($snapshot['runtime_site_settings']['legalserver_online_application_url_present']);
    $this->assertFalse($snapshot['effective_runtime']['retrieval']['legalserver_online_application_url']['present']);
    $this->assertSame('degraded', $snapshot['effective_runtime']['retrieval']['legalserver_online_application_url']['status']);
    $this->assertSame(
      'degraded',
      $snapshot['effective_runtime']['retrieval']['health']['canonical_urls']['legalserver_intake_url']['status'],
    );

    $divergenceFields = array_column($snapshot['divergences'], 'field');
    $this->assertNotContains('retrieval.legalserver_online_application_url.present', $divergenceFields);
  }

  /**
   * Resets assistant config after mutating settings.php-style overrides.
   */
  private function resetAssistantConfig(): void {
    $config_factory = $this->container->get('config.factory');
    assert($config_factory instanceof ConfigFactoryInterface);
    $config_factory->reset('ilas_site_assistant.settings');
  }

  /**
   * Finds one divergence row by field.
   *
   * @return array<string, mixed>|null
   *   The divergence row, if present.
   */
  private function findDivergence(array $snapshot, string $field): ?array {
    foreach ($snapshot['divergences'] ?? [] as $divergence) {
      if (($divergence['field'] ?? NULL) === $field) {
        return $divergence;
      }
    }

    return NULL;
  }

}
