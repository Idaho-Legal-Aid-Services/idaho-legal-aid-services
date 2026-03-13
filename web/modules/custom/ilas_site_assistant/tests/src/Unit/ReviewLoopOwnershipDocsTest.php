<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Contract tests for PHARD-05 review-loop ownership documentation.
 *
 * Ensures that the review_loop config block exists in install config,
 * active config, and schema, with required fields populated.
 */
#[Group('ilas_site_assistant')]
class ReviewLoopOwnershipDocsTest extends TestCase {

  /**
   * Returns the repository root path.
   */
  private static function repoRoot(): string {
    return dirname(__DIR__, 7);
  }

  /**
   * Parses a YAML config file.
   */
  private static function parseYaml(string $relativePath): array {
    $path = self::repoRoot() . '/' . ltrim($relativePath, '/');
    self::assertFileExists($path, "Expected file does not exist: {$relativePath}");

    $contents = file_get_contents($path);
    self::assertIsString($contents, "Failed reading file: {$relativePath}");

    $parsed = Yaml::parse($contents);
    self::assertIsArray($parsed, "YAML parse failed for: {$relativePath}");
    return $parsed;
  }

  /**
   * Tests that install config contains review_loop block.
   */
  public function testInstallConfigContainsReviewLoopBlock(): void {
    $config = self::parseYaml(
      'web/modules/custom/ilas_site_assistant/config/install/ilas_site_assistant.settings.yml'
    );
    $this->assertArrayHasKey('review_loop', $config);
  }

  /**
   * Tests that active config contains review_loop block.
   */
  public function testActiveConfigContainsReviewLoopBlock(): void {
    $config = self::parseYaml('config/ilas_site_assistant.settings.yml');
    $this->assertArrayHasKey('review_loop', $config);
  }

  /**
   * Tests that install and active config review_loop blocks have parity.
   */
  public function testReviewLoopConfigParity(): void {
    $install = self::parseYaml(
      'web/modules/custom/ilas_site_assistant/config/install/ilas_site_assistant.settings.yml'
    );
    $active = self::parseYaml('config/ilas_site_assistant.settings.yml');

    $this->assertEquals(
      $install['review_loop'],
      $active['review_loop'],
      'Install and active config review_loop blocks must match.'
    );
  }

  /**
   * Tests that schema covers review_loop mapping.
   */
  public function testSchemaCoversReviewLoopKeys(): void {
    $schema = self::parseYaml(
      'web/modules/custom/ilas_site_assistant/config/schema/ilas_site_assistant.schema.yml'
    );
    $mapping = $schema['ilas_site_assistant.settings']['mapping'] ?? [];
    $this->assertArrayHasKey('review_loop', $mapping);

    $review_mapping = $mapping['review_loop']['mapping'] ?? [];
    $this->assertArrayHasKey('owner_role', $review_mapping);
    $this->assertArrayHasKey('cadence', $review_mapping);
    $this->assertArrayHasKey('scope', $review_mapping);
    $this->assertArrayHasKey('escalation_path', $review_mapping);
    $this->assertArrayHasKey('artifact_location', $review_mapping);
  }

  /**
   * Tests that owner_role is not empty.
   */
  public function testReviewLoopOwnerRoleIsNotEmpty(): void {
    $config = self::parseYaml(
      'web/modules/custom/ilas_site_assistant/config/install/ilas_site_assistant.settings.yml'
    );
    $this->assertNotEmpty(
      $config['review_loop']['owner_role'] ?? '',
      'review_loop.owner_role must not be empty.'
    );
  }

  /**
   * Tests that cadence is not empty.
   */
  public function testReviewLoopCadenceIsNotEmpty(): void {
    $config = self::parseYaml(
      'web/modules/custom/ilas_site_assistant/config/install/ilas_site_assistant.settings.yml'
    );
    $this->assertNotEmpty(
      $config['review_loop']['cadence'] ?? '',
      'review_loop.cadence must not be empty.'
    );
  }

  /**
   * Tests that scope has at least three items.
   */
  public function testReviewLoopScopeHasAtLeastThreeItems(): void {
    $config = self::parseYaml(
      'web/modules/custom/ilas_site_assistant/config/install/ilas_site_assistant.settings.yml'
    );
    $scope = $config['review_loop']['scope'] ?? [];
    $this->assertIsArray($scope);
    $this->assertGreaterThanOrEqual(3, count($scope), 'review_loop.scope must have at least 3 items.');
  }

}
