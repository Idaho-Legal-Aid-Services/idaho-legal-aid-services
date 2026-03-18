<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use Drupal\ai_vdb_provider_pinecone\QueryTimeoutPineconeClient;
use GuzzleHttp\RequestOptions;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Probots\Pinecone\Requests\Data\QueryVectors;
use Symfony\Component\Yaml\Yaml;

/**
 * Contract tests for Pinecone query-time timeout wiring.
 */
#[Group('ilas_site_assistant')]
class PineconeQueryTimeoutContractTest extends TestCase {

  /**
   * Query timeout defaults exist in both install and active config.
   */
  public function testQueryTimeoutDefaultsExistInInstallAndActiveConfig(): void {
    $install = Yaml::parseFile($this->repoRoot() . '/web/modules/contrib/ai_vdb_provider_pinecone/config/install/ai_vdb_provider_pinecone.settings.yml');
    $active = Yaml::parseFile($this->repoRoot() . '/config/ai_vdb_provider_pinecone.settings.yml');

    $this->assertSame(1.0, (float) ($install['query_connect_timeout_seconds'] ?? NULL));
    $this->assertSame(2.0, (float) ($install['query_request_timeout_seconds'] ?? NULL));
    $this->assertSame(1.0, (float) ($active['query_connect_timeout_seconds'] ?? NULL));
    $this->assertSame(2.0, (float) ($active['query_request_timeout_seconds'] ?? NULL));
  }

  /**
   * Saloon applies the query-only connection and request timeouts.
   */
  public function testQueryTimeoutClientAppliesSaloonTimeoutConfig(): void {
    require_once $this->repoRoot() . '/web/modules/contrib/ai_vdb_provider_pinecone/src/QueryTimeoutPineconeClient.php';

    $client = new QueryTimeoutPineconeClient(
      'test-api-key',
      'https://example-pinecone.test',
      1.0,
      2.0,
    );

    $pending_request = $client->createPendingRequest(new QueryVectors(
      vector: [0.1, 0.2, 0.3],
      namespace: 'faq',
      topK: 3,
    ));
    $config = $pending_request->config()->all();

    $this->assertSame(1.0, $config[RequestOptions::CONNECT_TIMEOUT] ?? NULL);
    $this->assertSame(2.0, $config[RequestOptions::TIMEOUT] ?? NULL);
  }

  /**
   * Returns the repository root from the test location.
   */
  private function repoRoot(): string {
    return dirname(__DIR__, 7);
  }

}
