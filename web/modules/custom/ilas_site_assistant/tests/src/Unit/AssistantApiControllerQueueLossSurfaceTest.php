<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use Drupal;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\DependencyInjection\ContainerBuilder;
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
use Drupal\ilas_site_assistant\Service\QueueHealthMonitor;
use Drupal\ilas_site_assistant\Service\ResourceFinder;
use Drupal\ilas_site_assistant\Service\SloDefinitions;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Verifies queue-loss summary exposure on health and metrics endpoints.
 */
#[Group('ilas_site_assistant')]
final class AssistantApiControllerQueueLossSurfaceTest extends TestCase {

  protected function setUp(): void {
    parent::setUp();
    Drupal::setContainer(new ContainerBuilder());
  }

  public function testHealthAndMetricsExposeNestedQueueExportSummary(): void {
    $performanceMonitor = $this->createStub(PerformanceMonitor::class);
    $performanceMonitor->method('getSummary')->willReturn([
      'status' => 'healthy',
      'p95' => 100,
      'p99' => 150,
      'avg' => 80,
      'error_rate' => 0.5,
      'availability_pct' => 99.5,
      'throughput_per_min' => 3,
      'request_count' => 10,
      'average_response_time_ms' => 120.5,
      'p50_response_time_ms' => 90.0,
      'p95_response_time_ms' => 100.0,
      'p99_response_time_ms' => 150.0,
      'requests_per_minute' => 3.0,
      'all_endpoints' => ['sample_size' => 10],
      'by_endpoint' => ['message' => ['sample_size' => 10]],
      'by_outcome' => ['message.success' => ['sample_size' => 10]],
    ]);

    $queueMonitor = $this->createMock(QueueHealthMonitor::class);
    $queueMonitor->method('getQueueHealthStatus')
      ->willReturn([
        'status' => 'healthy',
        'depth' => 0,
        'max_depth' => 10000,
        'utilization_pct' => 0.0,
        'oldest_enqueued_at' => NULL,
        'oldest_item_age_seconds' => NULL,
        'max_age_seconds' => 3600,
      ]);
    $queueMonitor->method('getExportOutcomeSummary')
      ->willReturn([
        'counters' => [
          'discard_stale' => 1,
        ],
        'totals' => [
          'discard_stale' => [
            'queue_items' => 1,
            'event_count' => 2,
            'success_count' => 0,
            'error_count' => 0,
            'lost_queue_items' => 1,
            'lost_event_count' => 2,
            'actionable' => TRUE,
          ],
        ],
        'last_outcome' => [
          'outcome' => 'discard_stale',
          'recorded_at' => 1700000000,
        ],
        'action_required' => TRUE,
        'policies' => [
          'discard_stale' => [
            'classification' => 'alertable_loss',
            'severity' => 'warning',
            'requires_error_count' => FALSE,
            'actionable' => TRUE,
          ],
        ],
        'alertable_loss_totals' => [
          'occurrences' => 1,
          'queue_items' => 1,
          'event_count' => 2,
        ],
        'informational_loss_totals' => [
          'occurrences' => 0,
          'queue_items' => 0,
          'event_count' => 0,
        ],
      ]);

    $sloDefinitions = $this->createStub(SloDefinitions::class);
    $sloDefinitions->method('getAvailabilityTargetPct')->willReturn(99.0);
    $sloDefinitions->method('getLatencyP95TargetMs')->willReturn(2000);
    $sloDefinitions->method('getErrorRateTargetPct')->willReturn(5.0);
    $sloDefinitions->method('getErrorBudgetWindowHours')->willReturn(168);
    $sloDefinitions->method('getCronMaxAgeSeconds')->willReturn(7200);
    $sloDefinitions->method('getCronExpectedCadenceSeconds')->willReturn(3600);
    $sloDefinitions->method('getQueueMaxDepth')->willReturn(10000);
    $sloDefinitions->method('getQueueMaxAgeSeconds')->willReturn(3600);

    $container = new ContainerBuilder();
    $container->set('ilas_site_assistant.queue_health_monitor', $queueMonitor);
    $container->set('ilas_site_assistant.slo_definitions', $sloDefinitions);
    Drupal::setContainer($container);

    $controller = $this->buildController($performanceMonitor);

    $metrics = json_decode((string) $controller->metrics()->getContent(), TRUE);
    $healthResponse = $controller->health();
    $health = json_decode((string) $healthResponse->getContent(), TRUE);

    $this->assertSame(200, $healthResponse->getStatusCode());
    $this->assertTrue($metrics['queue']['export']['action_required']);
    $this->assertSame(1, $metrics['queue']['export']['alertable_loss_totals']['occurrences']);
    $this->assertSame('discard_stale', $health['checks']['queue']['export']['last_outcome']['outcome']);
    $this->assertTrue($health['checks']['queue']['export']['action_required']);
    $this->assertSame('healthy', $health['status']);
  }

  private function buildController(PerformanceMonitor $performanceMonitor): AssistantApiController {
    $config = $this->createStub(ImmutableConfig::class);
    $config->method('get')->willReturn(NULL);

    $configFactory = $this->createStub(ConfigFactoryInterface::class);
    $configFactory->method('get')->willReturn($config);
    $cache = $this->createStub(CacheBackendInterface::class);

    return new AssistantApiController(
      $configFactory,
      $this->createStub(IntentRouter::class),
      $this->createStub(FaqIndex::class),
      $this->createStub(ResourceFinder::class),
      $this->createStub(PolicyFilter::class),
      $this->createStub(AnalyticsLogger::class),
      $this->createStub(LlmEnhancer::class),
      $this->createStub(FallbackGate::class),
      $this->createStub(FloodInterface::class),
      $cache,
      $this->createStub(LoggerInterface::class),
      assistant_flow_runner: $this->createStub(AssistantFlowRunner::class),
      selection_registry: new \Drupal\ilas_site_assistant\Service\SelectionRegistry(new \Drupal\ilas_site_assistant\Service\TopIntentsPack()),
      selection_state_store: new \Drupal\ilas_site_assistant\Service\SelectionStateStore($cache),
      performance_monitor: $performanceMonitor,
    );
  }

}
