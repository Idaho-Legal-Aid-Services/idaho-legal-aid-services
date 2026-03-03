<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Guards cron ordering: record run health before SLO alert checks.
 */
#[Group('ilas_site_assistant')]
final class CronHookSloAlertOrderingTest extends TestCase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    require_once dirname(__DIR__, 3) . '/ilas_site_assistant.module';
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    \Drupal::unsetContainer();
    parent::tearDown();
  }

  /**
   * Builds and sets a minimal container for hook_cron() execution.
   */
  private function setCronContainer(object $analyticsLogger, object $cronHealthTracker, object $sloAlert): void {
    $container = new ContainerBuilder();
    $container->set('logger.factory', new class {
      public function get(string $channel): NullLogger {
        return new NullLogger();
      }
    });
    $container->set('ilas_site_assistant.analytics_logger', $analyticsLogger);
    $container->set('ilas_site_assistant.cron_health_tracker', $cronHealthTracker);
    $container->set('ilas_site_assistant.slo_alert', $sloAlert);

    \Drupal::setContainer($container);
  }

  /**
   * Success path: recordRun() happens before checkAll().
   */
  public function testRecordRunPrecedesSloAlertCheckOnSuccess(): void {
    $calls = [];

    $analyticsLogger = new class($calls) {
      public array $calls;

      public function __construct(array &$calls) {
        $this->calls = &$calls;
      }

      public function cleanupOldData(): void {
        $this->calls[] = 'cleanup_old_data';
      }
    };

    $cronHealthTracker = new class($calls) {
      public array $calls;

      public function __construct(array &$calls) {
        $this->calls = &$calls;
      }

      public function recordRun(float $durationMs, bool $success): void {
        $this->calls[] = 'record_run:' . ($success ? 'success' : 'failure');
      }
    };

    $sloAlert = new class($calls) {
      public array $calls;

      public function __construct(array &$calls) {
        $this->calls = &$calls;
      }

      public function checkAll(): void {
        $this->calls[] = 'slo_check_all';
      }
    };

    $this->setCronContainer($analyticsLogger, $cronHealthTracker, $sloAlert);
    ilas_site_assistant_cron();

    $this->assertSame([
      'cleanup_old_data',
      'record_run:success',
      'slo_check_all',
    ], $calls);
  }

  /**
   * Failure path: recordRun(false) still occurs before checkAll().
   */
  public function testRecordRunPrecedesSloAlertCheckOnFailure(): void {
    $calls = [];

    $analyticsLogger = new class($calls) {
      public array $calls;

      public function __construct(array &$calls) {
        $this->calls = &$calls;
      }

      public function cleanupOldData(): void {
        $this->calls[] = 'cleanup_old_data';
        throw new \RuntimeException('Simulated cron failure');
      }
    };

    $cronHealthTracker = new class($calls) {
      public array $calls;

      public function __construct(array &$calls) {
        $this->calls = &$calls;
      }

      public function recordRun(float $durationMs, bool $success): void {
        $this->calls[] = 'record_run:' . ($success ? 'success' : 'failure');
      }
    };

    $sloAlert = new class($calls) {
      public array $calls;

      public function __construct(array &$calls) {
        $this->calls = &$calls;
      }

      public function checkAll(): void {
        $this->calls[] = 'slo_check_all';
      }
    };

    $this->setCronContainer($analyticsLogger, $cronHealthTracker, $sloAlert);
    ilas_site_assistant_cron();

    $this->assertSame([
      'cleanup_old_data',
      'record_run:failure',
      'slo_check_all',
    ], $calls);
  }

}
