<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use Drupal\ilas_site_assistant\Commands\LangfuseLookupCommands;
use Drupal\ilas_site_assistant\Service\LangfuseTraceLookupService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Unit tests for the ilas:langfuse-lookup Drush command.
 */
#[Group('ilas_site_assistant')]
class LangfuseLookupCommandTest extends TestCase {

  private const MODULE_PATH = 'web/modules/custom/ilas_site_assistant';

  /**
   * Returns the repository root.
   */
  private static function repoRoot(): string {
    return dirname(__DIR__, 7);
  }

  /**
   * Tests the command prints JSON and returns zero when a trace is found.
   */
  public function testCommandPrintsJsonOnSuccess(): void {
    $service = $this->createMock(LangfuseTraceLookupService::class);
    $service->expects($this->once())
      ->method('lookupTrace')
      ->with('trace-001', 3, 0)
      ->willReturn([
        'found' => TRUE,
        'trace_id' => 'trace-001',
        'http_status' => 200,
        'trace' => ['name' => 'assistant.message'],
      ]);

    $command = new LangfuseLookupCommands($service);

    ob_start();
    $result = $command->langfuseLookup('trace-001', ['attempts' => 3, 'delay-ms' => 0]);
    $output = ob_get_clean();

    $this->assertSame(0, $result);
    $this->assertIsString($output);
    $decoded = json_decode($output, TRUE, 512, JSON_THROW_ON_ERROR);
    $this->assertTrue($decoded['found']);
    $this->assertSame('assistant.message', $decoded['trace']['name']);
  }

  /**
   * Tests the command returns non-zero when the trace is not found.
   */
  public function testCommandReturnsNonZeroWhenTraceNotFound(): void {
    $service = $this->createMock(LangfuseTraceLookupService::class);
    $service->expects($this->once())
      ->method('lookupTrace')
      ->willReturn([
        'found' => FALSE,
        'trace_id' => 'trace-404',
        'http_status' => 404,
      ]);

    $command = new LangfuseLookupCommands($service);

    ob_start();
    $result = $command->langfuseLookup('trace-404', ['attempts' => 1, 'delay-ms' => 0]);
    $output = ob_get_clean();

    $this->assertSame(1, $result);
    $this->assertIsString($output);
    $decoded = json_decode($output, TRUE, 512, JSON_THROW_ON_ERROR);
    $this->assertFalse($decoded['found']);
  }

  /**
   * Tests the command is registered in drush.services.yml.
   */
  public function testCommandIsRegisteredInDrushServices(): void {
    $servicesPath = self::repoRoot() . '/' . self::MODULE_PATH . '/drush.services.yml';
    $this->assertFileExists($servicesPath);

    $services = Yaml::parseFile($servicesPath);
    $this->assertArrayHasKey('services', $services);
    $this->assertArrayHasKey('ilas_site_assistant.langfuse_lookup_commands', $services['services']);
    $this->assertSame(
      '\\' . LangfuseLookupCommands::class,
      $services['services']['ilas_site_assistant.langfuse_lookup_commands']['class'],
    );
  }
}
