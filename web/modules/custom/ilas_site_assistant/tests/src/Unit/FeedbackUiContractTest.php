<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Source-level contract tests for PHARD-05 feedback UI controls.
 *
 * Asserts that the widget JavaScript contains the required feedback
 * infrastructure without loading the full Drupal runtime.
 */
#[Group('ilas_site_assistant')]
class FeedbackUiContractTest extends TestCase {

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
   * Tests that widget JS contains the appendFeedback method.
   */
  public function testWidgetJsContainsAppendFeedbackMethod(): void {
    $source = self::readModuleFile('js/assistant-widget.js');
    $this->assertStringContainsString('appendFeedback', $source);
  }

  /**
   * Tests that widget JS contains both feedback event type strings.
   */
  public function testWidgetJsContainsFeedbackTrackEvents(): void {
    $source = self::readModuleFile('js/assistant-widget.js');
    $this->assertStringContainsString("'feedback_helpful'", $source);
    $this->assertStringContainsString("'feedback_not_helpful'", $source);
  }

  /**
   * Tests that greeting type is excluded from feedback.
   */
  public function testWidgetJsExcludesGreetingFromFeedback(): void {
    $source = self::readModuleFile('js/assistant-widget.js');
    $this->assertStringContainsString('noFeedbackTypes', $source);
    $this->assertMatchesRegularExpression(
      '/noFeedbackTypes\s*=\s*\[.*[\'"]greeting[\'"]/',
      $source
    );
  }

  /**
   * Tests that CSS contains feedback control styles.
   */
  public function testCssContainsFeedbackControlStyles(): void {
    $source = self::readModuleFile('css/assistant-widget.css');
    $this->assertStringContainsString('.feedback-controls', $source);
    $this->assertStringContainsString('.feedback-btn', $source);
    $this->assertStringContainsString('.feedback-controls--submitted', $source);
  }

  /**
   * Tests that feedback buttons have accessibility attributes.
   */
  public function testWidgetJsFeedbackButtonsHaveAriaLabels(): void {
    $source = self::readModuleFile('js/assistant-widget.js');
    $this->assertStringContainsString('aria-label', $source);
    $this->assertStringContainsString("Drupal.t('Helpful')", $source);
    $this->assertStringContainsString("Drupal.t('Not helpful')", $source);
  }

}
