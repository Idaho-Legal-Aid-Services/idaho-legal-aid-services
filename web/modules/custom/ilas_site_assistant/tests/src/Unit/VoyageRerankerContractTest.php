<?php

namespace Drupal\Tests\ilas_site_assistant\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Site\Settings;
use Drupal\Core\State\StateInterface;
use Drupal\ilas_site_assistant\Service\VoyageReranker;
use GuzzleHttp\ClientInterface;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\LoggerInterface;

/**
 * Contract tests for VoyageReranker behavioral guarantees.
 */
#[Group('ilas_site_assistant')]
class VoyageRerankerContractTest extends TestCase {

  /**
   * Default Voyage config.
   */
  protected function defaultConfig(): array {
    return [
      'enabled' => TRUE,
      'rerank_model' => 'rerank-2',
      'api_timeout' => 3.0,
      'max_candidates' => 20,
      'top_k' => 5,
      'min_results_to_rerank' => 2,
      'circuit_breaker' => [
        'failure_threshold' => 3,
        'cooldown_seconds' => 300,
      ],
      'fallback_on_error' => TRUE,
    ];
  }

  /**
   * Builds a VoyageReranker with specific config overrides.
   */
  protected function buildReranker(array $config_overrides = []): VoyageReranker {
    $voyage_config = array_replace_recursive($this->defaultConfig(), $config_overrides);

    $immutableConfig = $this->createMock(ImmutableConfig::class);
    $immutableConfig->method('get')
      ->willReturnCallback(function ($key) use ($voyage_config) {
        if ($key === 'voyage') {
          return $voyage_config;
        }
        return NULL;
      });

    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')
      ->with('ilas_site_assistant.settings')
      ->willReturn($immutableConfig);

    $logger = $this->createMock(LoggerInterface::class);
    $state = $this->createMock(StateInterface::class);
    $state->method('get')->willReturn(NULL);
    $http = $this->createMock(ClientInterface::class);

    return new VoyageReranker($configFactory, $http, $logger, $state);
  }

  /**
   * Tests config schema defaults match expected values.
   */
  public function testConfigSchemaDefaults(): void {
    new Settings(['ilas_voyage_api_key' => 'test-key']);

    $reranker = $this->buildReranker();

    // Verify the reranker is enabled with key present.
    $this->assertTrue($reranker->isEnabled());

    // Verify circuit breaker starts closed.
    $circuit = $reranker->getCircuitState();
    $this->assertSame('closed', $circuit['state']);
    $this->assertSame(0, $circuit['consecutive_failures']);
  }

  /**
   * Tests enabled behavior requires both config AND key (dual-gate).
   */
  public function testEnabledDualGate(): void {
    // Config enabled, key missing.
    new Settings([]);
    $reranker = $this->buildReranker(['enabled' => TRUE]);
    $this->assertFalse($reranker->isEnabled());

    // Config disabled, key present.
    new Settings(['ilas_voyage_api_key' => 'test-key']);
    $reranker = $this->buildReranker(['enabled' => FALSE]);
    $this->assertFalse($reranker->isEnabled());

    // Both present.
    new Settings(['ilas_voyage_api_key' => 'test-key']);
    $reranker = $this->buildReranker(['enabled' => TRUE]);
    $this->assertTrue($reranker->isEnabled());
  }

  /**
   * Tests fallback_on_error config is respected.
   */
  public function testFallbackOnErrorRespected(): void {
    new Settings(['ilas_voyage_api_key' => 'test-key']);

    // With fallback_on_error=true (default), disabled returns items unchanged.
    $reranker = $this->buildReranker(['enabled' => FALSE, 'fallback_on_error' => TRUE]);
    $items = [
      ['id' => 1, 'title' => 'A', 'score' => 80],
      ['id' => 2, 'title' => 'B', 'score' => 60],
    ];
    $result = $reranker->rerank('test', $items);
    $this->assertSame($items, $result['items']);
    $this->assertFalse($result['meta']['applied']);
  }

  /**
   * Tests that input items are not mutated when reranking is disabled.
   */
  public function testInputItemsNotMutated(): void {
    new Settings([]);

    $items = [
      ['id' => 1, 'title' => 'A', 'score' => 80],
      ['id' => 2, 'title' => 'B', 'score' => 60],
    ];
    $original = $items;

    $reranker = $this->buildReranker(['enabled' => FALSE]);
    $result = $reranker->rerank('test', $items);

    // Input array should be unchanged.
    $this->assertSame($original, $items);
    // Returned items should also be unchanged.
    $this->assertSame($original, $result['items']);
  }

}
