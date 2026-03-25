<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\ilas_site_assistant\Commands\LangfuseStatusCommands;
use Drupal\ilas_site_assistant\Service\QueueHealthMonitor;
use Drupal\ilas_site_assistant\Service\RuntimeTruthSnapshotBuilder;
use Drupal\ilas_site_assistant\Service\SloDefinitions;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Output tests for the ilas:langfuse-status command.
 */
#[Group('ilas_site_assistant')]
class LangfuseStatusCommandsOutputTest extends TestCase {

  /**
   * Tests the status command prints queue/export summaries.
   */
  public function testCommandPrintsExportOutcomeSummary(): void {
    $builder = $this->createMock(RuntimeTruthSnapshotBuilder::class);
    $builder->expects($this->once())
      ->method('buildSnapshot')
      ->willReturn([
        'environment' => ['effective_environment' => 'local'],
        'exported_storage' => ['langfuse' => ['enabled' => FALSE]],
        'effective_runtime' => ['langfuse' => ['enabled' => TRUE, 'environment' => 'local']],
        'override_channels' => ['langfuse.enabled' => 'settings.php'],
        'divergences' => [],
      ]);

    $monitor = $this->createMock(QueueHealthMonitor::class);
    $monitor->expects($this->once())
      ->method('getQueueHealthStatus')
      ->willReturn([
        'status' => 'healthy',
        'depth' => 0,
        'max_depth' => 10000,
      ]);
    $monitor->expects($this->once())
      ->method('getTotalDrained')
      ->willReturn(12);
    $monitor->expects($this->once())
      ->method('getExportOutcomeSummary')
      ->willReturn([
        'counters' => [
          'send_success' => 4,
          'send_partial_207' => 1,
        ],
        'totals' => [
          'send_success' => [
            'queue_items' => 4,
            'event_count' => 4,
            'success_count' => 4,
            'error_count' => 0,
            'lost_queue_items' => 0,
            'lost_event_count' => 0,
            'actionable' => FALSE,
          ],
          'send_partial_207' => [
            'queue_items' => 1,
            'event_count' => 3,
            'success_count' => 2,
            'error_count' => 1,
            'lost_queue_items' => 1,
            'lost_event_count' => 1,
            'actionable' => TRUE,
          ],
        ],
        'last_outcome' => [
          'outcome' => 'send_partial_207',
          'http_status' => 207,
        ],
        'action_required' => TRUE,
        'policies' => [
          'send_success' => [
            'classification' => 'success',
            'severity' => 'info',
            'requires_error_count' => FALSE,
            'actionable' => FALSE,
          ],
          'send_partial_207' => [
            'classification' => 'alertable_loss',
            'severity' => 'warning',
            'requires_error_count' => TRUE,
            'actionable' => TRUE,
          ],
        ],
        'alertable_loss_totals' => [
          'occurrences' => 1,
          'queue_items' => 1,
          'event_count' => 1,
        ],
        'informational_loss_totals' => [
          'occurrences' => 0,
          'queue_items' => 0,
          'event_count' => 0,
        ],
      ]);

    $config = $this->createStub(ImmutableConfig::class);
    $config->method('get')->willReturnCallback(fn($key) => match ($key) {
      'langfuse.host' => 'https://us.cloud.langfuse.com',
      'langfuse.timeout' => 5.0,
      default => NULL,
    });
    $configFactory = $this->createStub(ConfigFactoryInterface::class);
    $configFactory->method('get')
      ->with('ilas_site_assistant.settings')
      ->willReturn($config);

    $command = new LangfuseStatusCommands(
      $builder,
      $monitor,
      $this->createStub(SloDefinitions::class),
      $configFactory,
    );

    ob_start();
    $result = $command->langfuseStatus();
    $output = ob_get_clean();

    $this->assertSame(0, $result);
    $decoded = json_decode((string) $output, TRUE, 512, JSON_THROW_ON_ERROR);
    $this->assertSame(12, $decoded['queue']['total_drained']);
    $this->assertSame(4, $decoded['export']['counters']['send_success']);
    $this->assertSame('send_partial_207', $decoded['export']['last_outcome']['outcome']);
    $this->assertTrue($decoded['export']['action_required']);
    $this->assertSame(1, $decoded['export']['alertable_loss_totals']['event_count']);
  }
}
