<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Preserves the historical TOVR-13 live-readiness evidence and trace contract.
 */
#[Group('ilas_site_assistant')]
final class Tovr13LiveGateContractTest extends TestCase {

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
   * TOVR-13 runtime report must remain available as the historical blocked
   * baseline that TOVR-17 supersedes.
   */
  public function testRuntimeReportPreservesHistoricalBlockedStateAndPrerequisites(): void {
    $report = self::readFile('docs/aila/runtime/tovr-13-pinecone-live-readiness.txt');

    $this->assertStringContainsString('Historical note', $report);
    $this->assertStringContainsString('superseded by `TOVR-17`', $report);
    $this->assertStringContainsString('Blocked with explicit evidence', $report);
    $this->assertStringContainsString('Exact prerequisites before any live enablement', $report);
    $this->assertStringContainsString('23218168501', $report);
    $this->assertStringContainsString('diagnostics_token_present=false', $report);
  }

  /**
   * Controller must emit the vector-specific Langfuse trace metadata fields.
   */
  public function testControllerCarriesVectorTraceMetadataFields(): void {
    $controller = self::readFile('web/modules/custom/ilas_site_assistant/src/Controller/AssistantApiController.php');

    $this->assertStringContainsString('collectRetrievalTraceMetadata', $controller);
    $this->assertStringContainsString("'vector_enabled_effective'", $controller);
    $this->assertStringContainsString("'vector_attempted'", $controller);
    $this->assertStringContainsString("'vector_status'", $controller);
    $this->assertStringContainsString("'vector_result_count'", $controller);
    $this->assertStringContainsString("'lexical_result_count'", $controller);
    $this->assertStringContainsString("'source_classes'", $controller);
    $this->assertStringContainsString("'degraded_reason'", $controller);
    $this->assertStringContainsString("'retrieval_operations'", $controller);
  }

  /**
   * Retrieval services must buffer telemetry for the controller trace summary.
   */
  public function testRetrievalServicesBufferPrivacySafeTelemetry(): void {
    $faq = self::readFile('web/modules/custom/ilas_site_assistant/src/Service/FaqIndex.php');
    $resource = self::readFile('web/modules/custom/ilas_site_assistant/src/Service/ResourceFinder.php');

    foreach ([$faq, $resource] as $source) {
      $this->assertStringContainsString('retrievalTelemetry', $source);
      $this->assertStringContainsString('drainRetrievalTelemetry', $source);
      $this->assertStringContainsString('recordRetrievalTelemetry', $source);
      $this->assertStringContainsString("'query_hash'", $source);
      $this->assertStringContainsString("'query_length_bucket'", $source);
      $this->assertStringContainsString("'vector_status'", $source);
      $this->assertStringContainsString("'source_classes'", $source);
    }
  }

  /**
   * Canonical evidence docs must preserve the historical TOVR-13 record.
   */
  public function testCanonicalEvidenceDocsReferenceHistoricalTovr13Disposition(): void {
    $evidence_index = self::readFile('docs/aila/evidence-index.md');

    $this->assertStringContainsString('## TOVR-13 Pinecone Live Readiness Review', $evidence_index);
    $this->assertStringContainsString('Blocked with explicit evidence', $evidence_index);
  }

}
