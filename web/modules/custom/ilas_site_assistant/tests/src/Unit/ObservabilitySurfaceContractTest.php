<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Source-level contract tests for RAUD-11 observability surfaces.
 *
 */
#[Group('ilas_site_assistant')]
class ObservabilitySurfaceContractTest extends TestCase {

  /**
   * Returns the repository root path.
   */
  private static function repoRoot(): string {
    return dirname(__DIR__, 7);
  }

  /**
   * Reads a repository file after asserting it exists.
   */
  private static function readFile(string $relativePath): string {
    $path = self::repoRoot() . '/' . ltrim($relativePath, '/');
    self::assertFileExists($path, "Expected file does not exist: {$relativePath}");

    $contents = file_get_contents($path);
    self::assertIsString($contents, "Failed reading file: {$relativePath}");
    return $contents;
  }

  /**
   * Tests that controller telemetry/logging uses metadata-only helpers.
   */
  public function testControllerUsesMetadataOnlyObservabilityHelpers(): void {
    $source = self::readFile(
      'web/modules/custom/ilas_site_assistant/src/Controller/AssistantApiController.php'
    );

    $this->assertStringContainsString('buildLangfuseInputPayload', $source);
    $this->assertStringContainsString('buildLangfuseOutputPayload', $source);
    $this->assertStringContainsString("\$langfuse_input['display']", $source);
    $this->assertStringContainsString("\$langfuse_input['metadata']", $source);
    $this->assertGreaterThanOrEqual(5, substr_count($source, 'buildLangfuseOutputPayload('));
    $this->assertStringNotContainsString('trace-update', $source);
    $this->assertStringContainsString("'error_signature'", $source);
    $this->assertStringContainsString('query_hash=@query_hash', $source);
    $this->assertStringContainsString('keyword_count=@keyword_count', $source);
    $this->assertStringContainsString('serializeAssignments', $source);

    $this->assertStringNotContainsString('PiiRedactor::redactForLog(', $source);
    $this->assertStringNotContainsString('@message', $source);
  }

  /**
   * Tests that vector search logs do not include raw query text.
   */
  public function testVectorSearchServicesUseQueryHashes(): void {
    foreach ([
      'web/modules/custom/ilas_site_assistant/src/Service/FaqIndex.php',
      'web/modules/custom/ilas_site_assistant/src/Service/ResourceFinder.php',
    ] as $path) {
      $source = self::readFile($path);

      $this->assertStringContainsString('query_hash=@query_hash', $source, "{$path} must log query hashes");
      $this->assertStringContainsString('@error_signature', $source, "{$path} must log error signatures");
      $this->assertStringNotContainsString('PiiRedactor::redactForLog(', $source, "{$path} must not log redacted query snippets");
      $this->assertStringNotContainsString('@message', $source, "{$path} must not log raw exception messages");
    }
  }

  /**
   * Tests that admin/report views no longer reference text-bearing columns.
   */
  public function testAdminViewsUseMetadataOnlyColumns(): void {
    $reportSource = self::readFile(
      'web/modules/custom/ilas_site_assistant/src/Controller/AssistantReportController.php'
    );
    $conversationSource = self::readFile(
      'web/modules/custom/ilas_site_assistant/src/Controller/AssistantConversationController.php'
    );

    $this->assertStringNotContainsString('sanitized_query', $reportSource);
    $this->assertStringNotContainsString('redacted_message', $conversationSource);
    $this->assertStringContainsString('query_hash', $reportSource);
    $this->assertStringContainsString('message_hash', $conversationSource);
  }

}
