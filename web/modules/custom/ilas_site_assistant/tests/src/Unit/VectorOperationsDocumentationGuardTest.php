<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Guards canonical vector-operations documentation against stale architecture text.
 */
#[Group('ilas_site_assistant')]
final class VectorOperationsDocumentationGuardTest extends TestCase {

  /**
   * Returns the repository root.
   */
  private static function repoRoot(): string {
    return dirname(__DIR__, 7);
  }

  /**
   * Reads a file from repo root.
   */
  private static function readFile(string $relativePath): string {
    $path = self::repoRoot() . '/' . ltrim($relativePath, '/');
    self::assertFileExists($path, "Expected file does not exist: {$relativePath}");

    $contents = file_get_contents($path);
    self::assertIsString($contents, "Failed reading file: {$relativePath}");
    return $contents;
  }

  public function testCanonicalDocsDescribeSplitVectorArchitectureAndOperatorCommands(): void {
    $currentState = self::readFile('docs/aila/current-state.md');
    $runbook = self::readFile('docs/aila/runbook.md');
    $evidenceIndex = self::readFile('docs/aila/evidence-index.md');
    $promptPack = self::readFile('docs/aila/tooling-observability-vector-remediation-prompt-pack.md');

    foreach ([$currentState, $runbook, $evidenceIndex, $promptPack] as $document) {
      $this->assertStringContainsString('pinecone_vector_faq', $document);
      $this->assertStringContainsString('pinecone_vector_resources', $document);
      $this->assertStringContainsString('faq_accordion_vector', $document);
      $this->assertStringContainsString('assistant_resources_vector', $document);
    }

    $this->assertStringContainsString('ilas-assistant', $currentState);
    $this->assertStringContainsString('ilas:vector-status', $runbook);
    $this->assertStringContainsString('ilas:vector-backfill', $runbook);
    $this->assertStringContainsString('queryability', $currentState);
    $this->assertStringContainsString('queryability', $runbook);
    $this->assertStringContainsString('queryability', $promptPack);

    $this->assertStringNotContainsString('config/search_api.server.pinecone_vector.yml', $currentState);
    $this->assertStringNotContainsString('config/search_api.server.pinecone_vector.yml', $evidenceIndex);
    $this->assertStringNotContainsString('config/search_api.server.pinecone_vector.yml', $promptPack);
  }

}
