<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Source-level contract tests for PHARD-05 report controller enhancements.
 *
 * Asserts that the report controller contains quality signals, user feedback,
 * and review-loop sections required by the PHARD-05 acceptance criteria.
 */
#[Group('ilas_site_assistant')]
class SearchAnalyticsReportContractTest extends TestCase {

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
   * Tests that the report controller contains a quality signals section.
   */
  public function testReportControllerContainsQualitySignalsSection(): void {
    $source = self::readModuleFile('src/Controller/AssistantReportController.php');
    $this->assertStringContainsString('buildQualitySignalsTable', $source);
    $this->assertStringContainsString("'quality'", $source);
  }

  /**
   * Tests that the report controller contains a feedback section.
   */
  public function testReportControllerContainsFeedbackSection(): void {
    $source = self::readModuleFile('src/Controller/AssistantReportController.php');
    $this->assertStringContainsString('buildFeedbackSummaryTable', $source);
    $this->assertStringContainsString('buildFeedbackBreakdownTable', $source);
  }

  /**
   * Tests that the report controller contains a review-loop section.
   */
  public function testReportControllerContainsReviewLoopSection(): void {
    $source = self::readModuleFile('src/Controller/AssistantReportController.php');
    $this->assertStringContainsString('buildReviewLoopSection', $source);
    $this->assertStringContainsString("'review_loop'", $source);
  }

  /**
   * Tests that the report controller queries safety event types.
   */
  public function testReportControllerQueriesSafetyEventTypes(): void {
    $source = self::readModuleFile('src/Controller/AssistantReportController.php');
    $this->assertStringContainsString("'safety_violation'", $source);
    $this->assertStringContainsString("'out_of_scope'", $source);
    $this->assertStringContainsString("'generic_answer'", $source);
    $this->assertStringContainsString("'grounding_refusal'", $source);
    $this->assertStringContainsString("'post_gen_safety_legal_advice'", $source);
  }

  /**
   * Tests that the report controller queries feedback event types.
   */
  public function testReportControllerQueriesFeedbackEventTypes(): void {
    $source = self::readModuleFile('src/Controller/AssistantReportController.php');
    $this->assertStringContainsString("'feedback_helpful'", $source);
    $this->assertStringContainsString("'feedback_not_helpful'", $source);
  }

  /**
   * Tests that the report controller reads review_loop config.
   */
  public function testReportControllerReadsReviewLoopConfig(): void {
    $source = self::readModuleFile('src/Controller/AssistantReportController.php');
    $this->assertStringContainsString("'review_loop'", $source);
    $this->assertStringContainsString("'owner_role'", $source);
    $this->assertStringContainsString("'cadence'", $source);
    $this->assertStringContainsString("'escalation_path'", $source);
  }

}
