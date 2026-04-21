<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Guards the live runtime-toggle contract for Voyage and vector retrieval.
 */
#[Group('ilas_site_assistant')]
final class VoyageLiveRuntimeGateGuardTest extends TestCase {

  /**
   * Returns the repository root path.
   */
  private static function repoRoot(): string {
    return dirname(__DIR__, 7);
  }

  /**
   * Reads a file from repo root after existence checks.
   */
  private static function readFile(string $relativePath): string {
    $path = self::repoRoot() . '/' . ltrim($relativePath, '/');
    self::assertFileExists($path, "Expected file does not exist: {$relativePath}");

    $contents = file_get_contents($path);
    self::assertIsString($contents, "Failed reading file: {$relativePath}");
    return $contents;
  }

  /**
   * settings.php must keep live runtime-toggle support for both Voyage and
   * vector retrieval while limiting the private-flag fallback to dev/test.
   */
  public function testSettingsPhpAllowsLiveVoyageAndLiveVectorRuntimeToggle(): void {
    $settings = self::readFile('web/sites/default/settings.php');

    $this->assertStringContainsString("_ilas_get_secret('ILAS_VECTOR_SEARCH_ENABLED')", $settings);
    $this->assertStringContainsString(
      "in_array(\$ilas_vector_search_environment, ['dev', 'test'], TRUE)",
      $settings,
    );
    $this->assertStringContainsString(
      "in_array(\$ilas_vector_search_environment, ['local', 'dev', 'test', 'live'], TRUE)",
      $settings,
    );
    $this->assertStringContainsString(
      "\$config['ilas_site_assistant.settings']['vector_search']['enabled'] = TRUE;",
      $settings,
    );
    $this->assertStringNotContainsString(
      "\$config['ilas_site_assistant.settings']['vector_search']['enabled'] = FALSE;",
      $settings,
    );

    $this->assertStringContainsString("_ilas_get_secret('ILAS_VOYAGE_ENABLED')", $settings);
    $this->assertStringContainsString(
      "in_array(\$ilas_vector_search_environment, ['local', 'dev', 'test', 'live'], TRUE)",
      $settings,
    );
    $this->assertStringContainsString(
      "\$config['ilas_site_assistant.settings']['voyage']['enabled'] = TRUE;",
      $settings,
    );
    $this->assertStringNotContainsString(
      "\$config['ilas_site_assistant.settings']['voyage']['enabled'] = FALSE;",
      $settings,
    );
  }

}
