<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\ilas_site_assistant\Service\SloDefinitions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for SloDefinitions typed accessor service.
 */
#[CoversClass(SloDefinitions::class)]
#[Group('ilas_site_assistant')]
class SloDefinitionsTest extends TestCase {

  /**
   * Builds a SloDefinitions service with given config overrides.
   *
   * @param array $overrides
   *   Key-value pairs for slo.* config keys.
   *
   * @return \Drupal\ilas_site_assistant\Service\SloDefinitions
   *   The configured service.
   */
  private function buildService(array $overrides = []): SloDefinitions {
    $config = $this->createStub(ImmutableConfig::class);
    $config->method('get')
      ->willReturnCallback(function ($key) use ($overrides) {
        if (str_starts_with($key, 'slo.')) {
          $sloKey = substr($key, 4);
          return $overrides[$sloKey] ?? NULL;
        }
        return NULL;
      });

    $configFactory = $this->createStub(ConfigFactoryInterface::class);
    $configFactory->method('get')->willReturn($config);

    return new SloDefinitions($configFactory);
  }

  /**
   * Tests that all getters return correct default values when config is empty.
   */
  public function testDefaultValues(): void {
    $slo = $this->buildService();

    $this->assertSame(99.5, $slo->getAvailabilityTargetPct());
    $this->assertSame(2000, $slo->getLatencyP95TargetMs());
    $this->assertSame(5000, $slo->getLatencyP99TargetMs());
    $this->assertSame(5.0, $slo->getErrorRateTargetPct());
    $this->assertSame(168, $slo->getErrorBudgetWindowHours());
    $this->assertSame(7200, $slo->getCronMaxAgeSeconds());
    $this->assertSame(3600, $slo->getCronExpectedCadenceSeconds());
    $this->assertSame(10000, $slo->getQueueMaxDepth());
    $this->assertSame(3600, $slo->getQueueMaxAgeSeconds());
  }

  /**
   * Tests that config overrides are returned instead of defaults.
   */
  public function testConfigOverrides(): void {
    $slo = $this->buildService([
      'availability_target_pct' => 99.9,
      'latency_p95_target_ms' => 1500,
      'latency_p99_target_ms' => 4000,
      'error_rate_target_pct' => 2.0,
      'error_budget_window_hours' => 336,
      'cron_max_age_seconds' => 3600,
      'cron_expected_cadence_seconds' => 1800,
      'queue_max_depth' => 5000,
      'queue_max_age_seconds' => 1800,
    ]);

    $this->assertSame(99.9, $slo->getAvailabilityTargetPct());
    $this->assertSame(1500, $slo->getLatencyP95TargetMs());
    $this->assertSame(4000, $slo->getLatencyP99TargetMs());
    $this->assertSame(2.0, $slo->getErrorRateTargetPct());
    $this->assertSame(336, $slo->getErrorBudgetWindowHours());
    $this->assertSame(3600, $slo->getCronMaxAgeSeconds());
    $this->assertSame(1800, $slo->getCronExpectedCadenceSeconds());
    $this->assertSame(5000, $slo->getQueueMaxDepth());
    $this->assertSame(1800, $slo->getQueueMaxAgeSeconds());
  }

  /**
   * Tests that all getters return the correct types.
   */
  public function testReturnTypes(): void {
    $slo = $this->buildService();

    $this->assertIsFloat($slo->getAvailabilityTargetPct());
    $this->assertIsInt($slo->getLatencyP95TargetMs());
    $this->assertIsInt($slo->getLatencyP99TargetMs());
    $this->assertIsFloat($slo->getErrorRateTargetPct());
    $this->assertIsInt($slo->getErrorBudgetWindowHours());
    $this->assertIsInt($slo->getCronMaxAgeSeconds());
    $this->assertIsInt($slo->getCronExpectedCadenceSeconds());
    $this->assertIsInt($slo->getQueueMaxDepth());
    $this->assertIsInt($slo->getQueueMaxAgeSeconds());
  }

}
