<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Source-level contract tests for the ilas:langfuse-status Drush command.
 *
 * Validates that the command class exists, is properly wired, and surfaces
 * the required Langfuse runtime truth data without exposing secrets.
 */
#[Group('ilas_site_assistant')]
class LangfuseStatusCommandTest extends TestCase {

  /**
   * Returns the module root path.
   */
  private static function moduleRoot(): string {
    return dirname(__DIR__, 3);
  }

  /**
   * Reads a module file after asserting it exists.
   */
  private static function readModuleFile(string $relativePath): string {
    $path = self::moduleRoot() . '/' . ltrim($relativePath, '/');
    self::assertFileExists($path, "Expected file does not exist: {$relativePath}");

    $contents = file_get_contents($path);
    self::assertIsString($contents, "Failed reading file: {$relativePath}");
    return $contents;
  }

  /**
   * Tests that the command class file exists.
   */
  public function testCommandClassExists(): void {
    $path = self::moduleRoot() . '/src/Commands/LangfuseStatusCommands.php';
    $this->assertFileExists($path);
  }

  /**
   * Tests that the command is registered in drush.services.yml.
   */
  public function testCommandIsRegisteredInDrushServices(): void {
    $services = self::readModuleFile('drush.services.yml');
    $this->assertStringContainsString('ilas_site_assistant.langfuse_status_commands', $services);
    $this->assertStringContainsString('LangfuseStatusCommands', $services);
    $this->assertStringContainsString('drush.command', $services);
  }

  /**
   * Tests that the command uses RuntimeTruthSnapshotBuilder.
   */
  public function testCommandUsesRuntimeTruthSnapshotBuilder(): void {
    $source = self::readModuleFile('src/Commands/LangfuseStatusCommands.php');
    $this->assertStringContainsString('RuntimeTruthSnapshotBuilder', $source);
    $this->assertStringContainsString('buildSnapshot', $source);
  }

  /**
   * Tests that the command includes queue health data.
   */
  public function testCommandIncludesQueueHealth(): void {
    $source = self::readModuleFile('src/Commands/LangfuseStatusCommands.php');
    $this->assertStringContainsString('QueueHealthMonitor', $source);
    $this->assertStringContainsString('getQueueHealthStatus', $source);
  }

  /**
   * Tests that the command does not expose secret values.
   */
  public function testCommandDoesNotExposeSecrets(): void {
    $source = self::readModuleFile('src/Commands/LangfuseStatusCommands.php');
    // Should reference presence checks, not raw values.
    $this->assertStringNotContainsString('public_key\'', $source);
    $this->assertStringNotContainsString('secret_key\'', $source);
  }

  /**
   * Tests the command annotation name is correct.
   */
  public function testCommandAnnotationName(): void {
    $source = self::readModuleFile('src/Commands/LangfuseStatusCommands.php');
    $this->assertStringContainsString('@command ilas:langfuse-status', $source);
    $this->assertStringContainsString('@aliases langfuse-status', $source);
  }

  /**
   * Tests that the drush.services.yml injects required dependencies.
   */
  public function testDrushServicesDependencies(): void {
    $services = self::readModuleFile('drush.services.yml');
    $this->assertStringContainsString('@ilas_site_assistant.runtime_truth_snapshot_builder', $services);
    $this->assertStringContainsString('@ilas_site_assistant.queue_health_monitor', $services);
    $this->assertStringContainsString('@ilas_site_assistant.slo_definitions', $services);
  }

  /**
   * Tests that the command filters divergences to Langfuse-only fields.
   */
  public function testCommandFiltersLangfuseDivergences(): void {
    $source = self::readModuleFile('src/Commands/LangfuseStatusCommands.php');
    $this->assertStringContainsString('filterLangfuseDivergences', $source);
    $this->assertStringContainsString("'langfuse.'", $source);
  }

}
