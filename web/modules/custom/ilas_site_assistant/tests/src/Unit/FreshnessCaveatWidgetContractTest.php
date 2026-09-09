<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Source-level contract tests for the freshness caveat UI (PHP-9Z follow-up).
 *
 * SOFT freshness enforcement adds `freshness_caveat` to the response body;
 * the widget must render it, persist it across history restore, and style it.
 */
#[Group('ilas_site_assistant')]
class FreshnessCaveatWidgetContractTest extends TestCase {

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
   * The widget renders freshness_caveat through the text-only paragraph helper.
   */
  public function testWidgetRendersFreshnessCaveat(): void {
    $source = self::readModuleFile('js/assistant-widget.js');
    $this->assertStringContainsString('if (response.freshness_caveat)', $source);
    $this->assertStringContainsString("this.appendMessageParagraph(fragment, response.freshness_caveat, 'freshness-caveat', true)", $source);
  }

  /**
   * The history snapshot allowlist carries freshness_caveat.
   */
  public function testSnapshotAllowlistIncludesFreshnessCaveat(): void {
    $source = self::readModuleFile('js/assistant-widget.js');
    $start = strpos($source, 'snapshotAssistantResponse: function');
    $this->assertNotFalse($start);
    $window = substr($source, $start, 1200);
    $this->assertStringContainsString("'freshness_caveat',", $window);

    $test_double = self::readModuleFile('tests/js/assistant-widget-hardening.test.js');
    $this->assertStringContainsString("'freshness_caveat',", $test_double);
  }

  /**
   * The caveat shares the eligibility-caveat styling.
   */
  public function testCssStylesFreshnessCaveat(): void {
    $css = self::readModuleFile('css/assistant-widget.css');
    $this->assertMatchesRegularExpression('/\.eligibility-caveat,\s*\.freshness-caveat\s*\{/', $css);
  }

  /**
   * The server still emits the caveat text the widget renders.
   */
  public function testControllerEmitsFreshnessCaveat(): void {
    $controller = self::readModuleFile('src/Controller/AssistantApiController.php');
    $this->assertStringContainsString("\$response['freshness_caveat'] = ", $controller);
  }

}
