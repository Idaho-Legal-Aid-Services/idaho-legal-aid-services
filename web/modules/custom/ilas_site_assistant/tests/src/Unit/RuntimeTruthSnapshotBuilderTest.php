<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Config\StorageInterface;
use Drupal\Core\Site\Settings;
use Drupal\ilas_site_assistant\Service\LlmEnhancer;
use Drupal\ilas_site_assistant\Service\RuntimeTruthSnapshotBuilder;
use Drupal\ilas_site_assistant\Service\VoyageReranker;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Runtime-truth coverage for the Cohere-first transition.
 */
#[Group('ilas_site_assistant')]
final class RuntimeTruthSnapshotBuilderTest extends TestCase {

  protected function tearDown(): void {
    new Settings([]);
    parent::tearDown();
  }

  public function testBuildSnapshotReportsCohereProviderAndRedactsSecrets(): void {
    new Settings([
      'ilas_cohere_api_key' => 'cohere-secret-value',
      'ilas_voyage_api_key' => 'voyage-secret-value',
      'ilas_observability' => ['environment' => 'local'],
      'hash_salt' => 'test-salt',
    ]);

    $llmEnhancer = $this->createStub(LlmEnhancer::class);
    $llmEnhancer->method('isEnabled')->willReturn(TRUE);
    $llmEnhancer->method('getProviderId')->willReturn('cohere');
    $llmEnhancer->method('getModelId')->willReturn('command-a-03-2025');
    $llmEnhancer->method('getCostControlSummary')->willReturn(['cache_hits' => 1]);

    $voyage = $this->createStub(VoyageReranker::class);
    $voyage->method('isEnabled')->willReturn(TRUE);
    $voyage->method('getRuntimeSummary')->willReturn(['state' => 'closed']);

    $builder = new RuntimeTruthSnapshotBuilder(
      $this->buildConfigFactory(TRUE),
      $this->buildStorage(FALSE),
      NULL,
      NULL,
      NULL,
      NULL,
      $llmEnhancer,
      $voyage,
    );

    $snapshot = $builder->buildSnapshot();

    $this->assertSame('cohere', $snapshot['exported_storage']['llm']['provider'] ?? NULL);
    $this->assertSame('cohere', $snapshot['effective_runtime']['llm']['provider'] ?? NULL);
    $this->assertTrue($snapshot['runtime_site_settings']['cohere_api_key_present'] ?? FALSE);
    $this->assertTrue($snapshot['effective_runtime']['llm']['runtime_ready'] ?? FALSE);
    $this->assertTrue($snapshot['effective_runtime']['llm']['request_time_generation_reachable'] ?? FALSE);

    $divergenceFields = array_column($snapshot['divergences'] ?? [], 'field');
    $this->assertContains('llm.enabled', $divergenceFields);

    $json = json_encode($snapshot, JSON_THROW_ON_ERROR);
    $this->assertStringNotContainsString('cohere-secret-value', $json);
    $this->assertStringNotContainsString('voyage-secret-value', $json);
    $this->assertStringNotContainsString('gemini-secret-value', $json);
    $this->assertStringNotContainsString('vertex-secret-value', $json);
  }

  public function testFallbackRuntimeReadyUsesCohereSettingWhenEnhancerUnavailable(): void {
    new Settings([
      'ilas_cohere_api_key' => 'cohere-secret-value',
      'ilas_observability' => ['environment' => 'local'],
      'hash_salt' => 'test-salt',
    ]);

    $builder = new RuntimeTruthSnapshotBuilder(
      $this->buildConfigFactory(TRUE),
      $this->buildStorage(FALSE),
    );

    $snapshot = $builder->buildSnapshot();

    $this->assertSame('cohere', $snapshot['effective_runtime']['llm']['provider'] ?? NULL);
    $this->assertTrue($snapshot['effective_runtime']['llm']['runtime_ready'] ?? FALSE);
    $this->assertTrue($snapshot['effective_runtime']['llm']['request_time_generation_reachable'] ?? FALSE);
  }

  private function buildConfigFactory(bool $llmEnabled): ConfigFactoryInterface {
    $assistant = [
      'llm' => [
        'enabled' => $llmEnabled,
        'max_tokens' => 150,
        'temperature' => 0.3,
        'safety_threshold' => 'BLOCK_MEDIUM_AND_ABOVE',
        'fallback_on_error' => TRUE,
        'cache_ttl' => 3600,
        'max_retries' => 1,
      ],
      'vector_search' => ['enabled' => FALSE],
      'voyage' => [
        'enabled' => TRUE,
        'rerank_model' => 'rerank-2',
        'api_timeout' => 3.0,
        'max_candidates' => 20,
        'top_k' => 5,
        'min_results_to_rerank' => 2,
        'fallback_on_error' => TRUE,
        'circuit_breaker' => [
          'failure_threshold' => 3,
          'cooldown_seconds' => 300,
        ],
      ],
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
        ],
      ],
      'conversation_logging' => [
        'enabled' => FALSE,
        'retention_hours' => 72,
        'redact_pii' => TRUE,
        'show_user_notice' => TRUE,
      ],
      'session_bootstrap' => [
        'rate_limit_per_minute' => 60,
        'rate_limit_per_hour' => 600,
        'observation_window_hours' => 24,
      ],
      'read_endpoint_rate_limits' => [
        'suggest' => ['rate_limit_per_minute' => 120, 'rate_limit_per_hour' => 1200],
        'faq' => ['rate_limit_per_minute' => 60, 'rate_limit_per_hour' => 600],
      ],
      'rate_limit_per_minute' => 15,
      'rate_limit_per_hour' => 120,
      'langfuse' => ['enabled' => FALSE],
    ];

    $map = [
      'ilas_site_assistant.settings' => $assistant,
      'ai.settings' => [
        'default_providers' => [
          'embeddings' => [
            'provider_id' => 'ilas_voyage',
            'model_id' => 'voyage-law-2',
          ],
        ],
      ],
      'raven.settings' => [],
      'key.key.pinecone_api_key' => ['key_provider_settings' => ['key_value' => '']],
      'key.key.voyage_ai_api_key' => [
        'key_provider' => 'ilas_runtime_site_setting',
        'key_provider_settings' => ['settings_key' => 'ilas_voyage_api_key'],
      ],
      'search_api.server.pinecone_vector_faq' => [
        'backend_config' => ['embeddings_engine' => 'ilas_voyage__voyage-law-2'],
      ],
      'search_api.server.pinecone_vector_resources' => [
        'backend_config' => ['embeddings_engine' => 'ilas_voyage__voyage-law-2'],
      ],
    ];

    $factory = $this->createStub(ConfigFactoryInterface::class);
    $factory->method('get')
      ->willReturnCallback(function (string $name) use ($map): ImmutableConfig {
        $config = $this->createStub(ImmutableConfig::class);
        $config->method('get')
          ->willReturnCallback(static fn(string $key): mixed => RuntimeTruthSnapshotBuilderTest::nestedValue($map[$name] ?? [], $key));
        return $config;
      });

    return $factory;
  }

  private function buildStorage(bool $llmEnabled): StorageInterface {
    $storage = $this->createStub(StorageInterface::class);
    $storage->method('read')
      ->willReturnCallback(function (string $name) use ($llmEnabled): ?array {
        $map = [
          'ilas_site_assistant.settings' => [
            'llm' => [
              'enabled' => $llmEnabled,
              'fallback_on_error' => TRUE,
              'safety_threshold' => 'BLOCK_MEDIUM_AND_ABOVE',
              'cache_ttl' => 3600,
              'max_retries' => 1,
            ],
            'vector_search' => ['enabled' => FALSE],
            'voyage' => ['enabled' => FALSE],
            'retrieval' => [
              'faq_index_id' => 'faq_accordion',
              'resource_index_id' => 'assistant_resources',
              'resource_fallback_index_id' => 'content',
              'faq_vector_index_id' => 'faq_accordion_vector',
              'resource_vector_index_id' => 'assistant_resources_vector',
            ],
            'canonical_urls' => ['service_areas' => ['housing' => '/legal-help/housing']],
            'conversation_logging' => ['enabled' => FALSE, 'retention_hours' => 72, 'redact_pii' => TRUE, 'show_user_notice' => TRUE],
            'session_bootstrap' => ['rate_limit_per_minute' => 60, 'rate_limit_per_hour' => 600, 'observation_window_hours' => 24],
            'read_endpoint_rate_limits' => [
              'suggest' => ['rate_limit_per_minute' => 120, 'rate_limit_per_hour' => 1200],
              'faq' => ['rate_limit_per_minute' => 60, 'rate_limit_per_hour' => 600],
            ],
            'rate_limit_per_minute' => 15,
            'rate_limit_per_hour' => 120,
          ],
          'ai.settings' => [
            'default_providers' => [
              'embeddings' => [
                'provider_id' => 'ilas_voyage',
                'model_id' => 'voyage-law-2',
              ],
            ],
          ],
          'key.key.pinecone_api_key' => ['key_provider_settings' => ['key_value' => '']],
          'key.key.voyage_ai_api_key' => [
            'key_provider' => 'ilas_runtime_site_setting',
            'key_provider_settings' => ['settings_key' => 'ilas_voyage_api_key'],
          ],
          'search_api.server.pinecone_vector_faq' => [
            'backend_config' => ['embeddings_engine' => 'ilas_voyage__voyage-law-2'],
          ],
          'search_api.server.pinecone_vector_resources' => [
            'backend_config' => ['embeddings_engine' => 'ilas_voyage__voyage-law-2'],
          ],
        ];

        return $map[$name] ?? [];
      });
    return $storage;
  }

  private static function nestedValue(array $data, string $key): mixed {
    $cursor = $data;
    foreach (explode('.', $key) as $segment) {
      if (!is_array($cursor) || !array_key_exists($segment, $cursor)) {
        return NULL;
      }
      $cursor = $cursor[$segment];
    }
    return $cursor;
  }

}
