<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use Drupal;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Flood\FloodInterface;
use Drupal\ilas_site_assistant\Controller\AssistantApiController;
use Drupal\ilas_site_assistant\Service\AnalyticsLogger;
use Drupal\ilas_site_assistant\Service\AssistantFlowRunner;
use Drupal\ilas_site_assistant\Service\FaqIndex;
use Drupal\ilas_site_assistant\Service\FallbackGate;
use Drupal\ilas_site_assistant\Service\IntentRouter;
use Drupal\ilas_site_assistant\Service\LlmEnhancer;
use Drupal\ilas_site_assistant\Service\PerformanceMonitor;
use Drupal\ilas_site_assistant\Service\PolicyFilter;
use Drupal\ilas_site_assistant\Service\ResourceFinder;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Verifies cost-control metrics exposure on the admin metrics endpoint.
 */
#[Group('ilas_site_assistant')]
final class AssistantApiControllerCostControlMetricsTest extends TestCase {

  protected function setUp(): void {
    parent::setUp();
    Drupal::setContainer(new ContainerBuilder());
  }

  public function testMetricsExposeAggregateCostControlSnapshotAndThresholds(): void {
    $performanceMonitor = $this->createStub(PerformanceMonitor::class);
    $performanceMonitor->method('getSummary')->willReturn([
      'status' => 'healthy',
      'request_count' => 42,
      'average_response_time_ms' => 120.5,
      'p50_response_time_ms' => 90.0,
      'p95_response_time_ms' => 250.0,
      'p99_response_time_ms' => 400.0,
      'error_rate' => 0.01,
      'requests_per_minute' => 3.0,
      'all_endpoints' => [
        'sample_size' => 84,
        'error_count' => 5,
        'denied_count' => 2,
        'degraded_count' => 1,
      ],
      'by_endpoint' => [
        'message' => ['sample_size' => 42],
        'track' => ['sample_size' => 12],
        'suggest' => ['sample_size' => 20],
        'faq' => ['sample_size' => 10],
      ],
      'by_outcome' => [
        'message.success' => ['sample_size' => 40],
        'message.invalid_request' => ['sample_size' => 2],
      ],
    ]);

    $llmEnhancer = $this->createStub(LlmEnhancer::class);
    $llmEnhancer->method('getCostControlSummary')->willReturn([
      'daily_calls' => 7,
      'daily_limit' => 5000,
      'monthly_calls' => 12,
      'monthly_limit' => 100000,
      'cache_hits' => 9,
      'cache_misses' => 1,
      'cache_requests' => 10,
      'cache_hit_rate' => 0.9,
      'cache_hit_rate_target' => 0.3,
      'kill_switch_active' => FALSE,
      'sample_rate' => 1.0,
      'per_ip_hourly_call_limit' => 10,
      'per_ip_window_seconds' => 3600,
    ]);

    $controller = $this->buildController($llmEnhancer, $performanceMonitor);
    $response = $controller->metrics();

    $this->assertSame(200, $response->getStatusCode());
    $body = json_decode((string) $response->getContent(), TRUE);

    $this->assertSame([
      'daily_calls' => 7,
      'monthly_calls' => 12,
      'cache_hits' => 9,
      'cache_misses' => 1,
      'cache_requests' => 10,
      'cache_hit_rate' => 0.9,
      'kill_switch_active' => FALSE,
      'sample_rate' => 1,
    ], $body['metrics']['cost_control']);
    $this->assertSame([
      'daily_call_limit' => 5000,
      'monthly_call_limit' => 100000,
      'cache_hit_rate_target' => 0.3,
      'per_ip_hourly_call_limit' => 10,
      'per_ip_window_seconds' => 3600,
    ], $body['thresholds']['cost_control']);
    $this->assertSame(84, $body['metrics']['all_endpoints']['sample_size']);
    $this->assertSame(42, $body['metrics']['by_endpoint']['message']['sample_size']);
    $this->assertSame(2, $body['metrics']['by_outcome']['message.invalid_request']['sample_size']);
  }

  private function buildController(
    LlmEnhancer $llmEnhancer,
    PerformanceMonitor $performanceMonitor,
  ): AssistantApiController {
    $config = $this->createStub(ImmutableConfig::class);
    $config->method('get')->willReturn(NULL);

    $configFactory = $this->createStub(ConfigFactoryInterface::class);
    $configFactory->method('get')->willReturn($config);

    return new AssistantApiController(
      $configFactory,
      $this->createStub(IntentRouter::class),
      $this->createStub(FaqIndex::class),
      $this->createStub(ResourceFinder::class),
      $this->createStub(PolicyFilter::class),
      $this->createStub(AnalyticsLogger::class),
      $llmEnhancer,
      $this->createStub(FallbackGate::class),
      $this->createStub(FloodInterface::class),
      $this->createStub(CacheBackendInterface::class),
      $this->createStub(LoggerInterface::class),
      assistant_flow_runner: $this->createStub(AssistantFlowRunner::class),
      performance_monitor: $performanceMonitor,
    );
  }

}
