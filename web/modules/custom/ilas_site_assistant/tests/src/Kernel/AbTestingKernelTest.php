<?php

namespace Drupal\Tests\ilas_site_assistant\Kernel;

use Drupal\ilas_site_assistant\Service\AbTestingService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Kernel tests for AbTestingService.
 *
 * Tests deterministic variant assignment, config-driven experiment loading,
 * and allocation boundary behavior.
 *
 */
#[CoversClass(AbTestingService::class)]
#[Group('ilas_site_assistant')]
class AbTestingKernelTest extends AssistantKernelTestBase {

  /**
   * Tests that isEnabled returns FALSE when disabled.
   */
  public function testIsEnabledReturnsFalseWhenDisabled(): void {
    $service = $this->createAbTestingService([
      'ab_testing.enabled' => FALSE,
    ]);

    $this->assertFalse($service->isEnabled());
  }

  /**
   * Tests that isEnabled returns TRUE when enabled.
   */
  public function testIsEnabledReturnsTrueWhenEnabled(): void {
    $service = $this->createAbTestingService([
      'ab_testing.enabled' => TRUE,
    ]);

    $this->assertTrue($service->isEnabled());
  }

  /**
   * Tests that getExperiments returns empty array when disabled.
   */
  public function testGetExperimentsReturnsEmptyWhenDisabled(): void {
    $service = $this->createAbTestingService([
      'ab_testing.enabled' => FALSE,
      'ab_testing.experiments' => [
        ['id' => 'test', 'variants' => ['a', 'b'], 'allocation' => [50, 50]],
      ],
    ]);

    $this->assertEmpty($service->getExperiments());
  }

  /**
   * Tests that getExperiments returns configured experiments when enabled.
   */
  public function testGetExperimentsReturnsConfigured(): void {
    $experiments = [
      [
        'id' => 'welcome_v2',
        'variants' => ['control', 'expanded_welcome'],
        'allocation' => [50, 50],
      ],
    ];

    $service = $this->createAbTestingService([
      'ab_testing.enabled' => TRUE,
      'ab_testing.experiments' => $experiments,
    ]);

    $result = $service->getExperiments();
    $this->assertCount(1, $result);
    $this->assertEquals('welcome_v2', $result[0]['id']);
  }

  /**
   * Tests that assignVariant is deterministic (same inputs = same output).
   */
  public function testAssignVariantIsDeterministic(): void {
    $service = $this->createAbTestingServiceWithExperiment();
    $conv_id = '12345678-1234-4123-8123-123456789abc';

    $variant1 = $service->assignVariant('welcome_v2', $conv_id);
    $variant2 = $service->assignVariant('welcome_v2', $conv_id);
    $variant3 = $service->assignVariant('welcome_v2', $conv_id);

    $this->assertNotNull($variant1);
    $this->assertEquals($variant1, $variant2);
    $this->assertEquals($variant2, $variant3);
  }

  /**
   * Tests that different conversation IDs can produce different variants.
   */
  public function testAssignVariantDistributes(): void {
    $service = $this->createAbTestingServiceWithExperiment();

    // Test with many conversation IDs to verify distribution.
    $assignments = [];
    for ($i = 0; $i < 100; $i++) {
      $conv_id = sprintf('12345678-1234-4123-8123-%012d', $i);
      $variant = $service->assignVariant('welcome_v2', $conv_id);
      $this->assertNotNull($variant);
      $this->assertContains($variant, ['control', 'expanded_welcome']);
      $assignments[$variant] = ($assignments[$variant] ?? 0) + 1;
    }

    // With 50/50 allocation and 100 IDs, each variant should have some.
    // (Not exactly 50 each due to hash distribution, but both should appear.)
    $this->assertArrayHasKey('control', $assignments);
    $this->assertArrayHasKey('expanded_welcome', $assignments);
    $this->assertGreaterThan(20, $assignments['control']);
    $this->assertGreaterThan(20, $assignments['expanded_welcome']);
  }

  /**
   * Tests that assignVariant returns NULL for an unknown experiment ID.
   */
  public function testAssignVariantReturnsNullForUnknownExperiment(): void {
    $service = $this->createAbTestingServiceWithExperiment();

    $result = $service->assignVariant('nonexistent', '12345678-1234-4123-8123-123456789abc');
    $this->assertNull($result);
  }

  /**
   * Tests that assignVariant returns NULL for experiments with mismatched counts.
   */
  public function testAssignVariantReturnsNullForMismatchedAllocation(): void {
    $service = $this->createAbTestingService([
      'ab_testing.enabled' => TRUE,
      'ab_testing.experiments' => [
        [
          'id' => 'broken',
          'variants' => ['a', 'b', 'c'],
          'allocation' => [50, 50],
        ],
      ],
    ]);

    $result = $service->assignVariant('broken', '12345678-1234-4123-8123-123456789abc');
    $this->assertNull($result);
  }

  /**
   * Tests that getAssignments returns variants for all active experiments.
   */
  public function testGetAssignmentsCoversAllExperiments(): void {
    $service = $this->createAbTestingService([
      'ab_testing.enabled' => TRUE,
      'ab_testing.experiments' => [
        [
          'id' => 'welcome_v2',
          'variants' => ['control', 'expanded_welcome'],
          'allocation' => [50, 50],
        ],
        [
          'id' => 'cta_color',
          'variants' => ['blue', 'green'],
          'allocation' => [50, 50],
        ],
      ],
    ]);

    $conv_id = '12345678-1234-4123-8123-123456789abc';
    $assignments = $service->getAssignments($conv_id);

    $this->assertArrayHasKey('welcome_v2', $assignments);
    $this->assertArrayHasKey('cta_color', $assignments);
    $this->assertContains($assignments['welcome_v2'], ['control', 'expanded_welcome']);
    $this->assertContains($assignments['cta_color'], ['blue', 'green']);
  }

  /**
   * Tests that getAssignments returns empty array when disabled.
   */
  public function testGetAssignmentsEmptyWhenDisabled(): void {
    $service = $this->createAbTestingService([
      'ab_testing.enabled' => FALSE,
    ]);

    $assignments = $service->getAssignments('12345678-1234-4123-8123-123456789abc');
    $this->assertEmpty($assignments);
  }

  /**
   * Tests allocation boundary: 100/0 split assigns all to first variant.
   */
  public function testAllocationBoundaryAllToFirst(): void {
    $service = $this->createAbTestingService([
      'ab_testing.enabled' => TRUE,
      'ab_testing.experiments' => [
        [
          'id' => 'all_control',
          'variants' => ['control', 'treatment'],
          'allocation' => [100, 0],
        ],
      ],
    ]);

    // All conversations should get 'control'.
    for ($i = 0; $i < 20; $i++) {
      $conv_id = sprintf('12345678-1234-4123-8123-%012d', $i);
      $variant = $service->assignVariant('all_control', $conv_id);
      $this->assertEquals('control', $variant);
    }
  }

  /**
   * Tests three-way split with uneven allocation.
   */
  public function testThreeWaySplit(): void {
    $service = $this->createAbTestingService([
      'ab_testing.enabled' => TRUE,
      'ab_testing.experiments' => [
        [
          'id' => 'three_way',
          'variants' => ['a', 'b', 'c'],
          'allocation' => [10, 80, 10],
        ],
      ],
    ]);

    $counts = ['a' => 0, 'b' => 0, 'c' => 0];
    for ($i = 0; $i < 200; $i++) {
      $conv_id = sprintf('12345678-1234-4123-8123-%012d', $i);
      $variant = $service->assignVariant('three_way', $conv_id);
      $this->assertContains($variant, ['a', 'b', 'c']);
      $counts[$variant]++;
    }

    // 'b' should have the most assignments (80% allocation).
    $this->assertGreaterThan($counts['a'], $counts['b']);
    $this->assertGreaterThan($counts['c'], $counts['b']);
  }

  /**
   * Tests that experiments with empty variants return NULL.
   */
  public function testEmptyVariantsReturnsNull(): void {
    $service = $this->createAbTestingService([
      'ab_testing.enabled' => TRUE,
      'ab_testing.experiments' => [
        [
          'id' => 'empty',
          'variants' => [],
          'allocation' => [],
        ],
      ],
    ]);

    $result = $service->assignVariant('empty', '12345678-1234-4123-8123-123456789abc');
    $this->assertNull($result);
  }

  /**
   * Tests that experiments without ID are skipped in getAssignments.
   */
  public function testExperimentsWithoutIdAreSkipped(): void {
    $service = $this->createAbTestingService([
      'ab_testing.enabled' => TRUE,
      'ab_testing.experiments' => [
        [
          'variants' => ['a', 'b'],
          'allocation' => [50, 50],
        ],
      ],
    ]);

    $assignments = $service->getAssignments('12345678-1234-4123-8123-123456789abc');
    $this->assertEmpty($assignments);
  }

  /**
   * Creates an AbTestingService with the default welcome_v2 experiment.
   *
   * @return \Drupal\ilas_site_assistant\Service\AbTestingService
   *   The configured AbTestingService.
   */
  protected function createAbTestingServiceWithExperiment(): AbTestingService {
    return $this->createAbTestingService([
      'ab_testing.enabled' => TRUE,
      'ab_testing.experiments' => [
        [
          'id' => 'welcome_v2',
          'variants' => ['control', 'expanded_welcome'],
          'allocation' => [50, 50],
        ],
      ],
    ]);
  }

  /**
   * Creates an AbTestingService with configurable overrides.
   *
   * @param array $config_overrides
   *   Config values to override.
   *
   * @return \Drupal\ilas_site_assistant\Service\AbTestingService
   *   The configured AbTestingService.
   */
  protected function createAbTestingService(array $config_overrides = []): AbTestingService {
    $configFactory = $this->createMockConfigFactory($config_overrides);
    return new AbTestingService($configFactory);
  }

}
