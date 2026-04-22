<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Locks the current TOVR-17 live Pinecone runtime-toggle contract.
 */
#[Group('ilas_site_assistant')]
final class Tovr17LiveEnablementContractTest extends TestCase {

  /**
   * Returns repository root.
   */
  private static function repoRoot(): string {
    return dirname(__DIR__, 7);
  }

  /**
   * Reads a repo file with existence checks.
   */
  private static function readFile(string $relativePath): string {
    $path = self::repoRoot() . '/' . ltrim($relativePath, '/');
    self::assertFileExists($path, "Expected file does not exist: {$relativePath}");

    $contents = file_get_contents($path);
    self::assertIsString($contents, "Failed reading file: {$relativePath}");
    return $contents;
  }

  /**
   * TOVR-17 runtime report must exist and encode the new live contract.
   */
  public function testRuntimeReportDeclaresLiveRuntimeToggleContract(): void {
    $report = self::readFile('docs/aila/runtime/tovr-17-pinecone-live-enablement.txt');

    $this->assertStringContainsString('Pinecone Live Runtime-Toggle Enablement', $report);
    $this->assertStringContainsString('ILAS_VECTOR_SEARCH_ENABLED', $report);
    $this->assertStringContainsString('stored Drupal config remains false', $report);
    $this->assertStringContainsString('ilas:runtime-truth', $report);
    $this->assertStringContainsString('ilas:vector-status faq_vector --probe-now', $report);
    $this->assertStringContainsString('ILAS_VECTOR_SEARCH_ENABLED=0', $report);
  }

  /**
   * Canonical docs must point current live rollout posture at TOVR-17.
   */
  public function testCanonicalDocsReferenceTovr17Disposition(): void {
    $currentState = self::readFile('docs/aila/current-state.md');
    $roadmap = self::readFile('docs/aila/roadmap.md');
    $runbook = self::readFile('docs/aila/runbook.md');
    $evidenceIndex = self::readFile('docs/aila/evidence-index.md');

    $this->assertStringContainsString('TOVR-17', $currentState);
    $this->assertStringContainsString('runtime-toggle controlled through `ILAS_VECTOR_SEARCH_ENABLED`', $currentState);
    $this->assertStringContainsString('### TOVR-17 Pinecone live enablement disposition', $roadmap);
    $this->assertStringContainsString('### TOVR-17 Pinecone live runtime-toggle enablement verification', $runbook);
    $this->assertStringContainsString('## TOVR-17 Pinecone Live Runtime-Toggle Enablement', $evidenceIndex);
  }

}
