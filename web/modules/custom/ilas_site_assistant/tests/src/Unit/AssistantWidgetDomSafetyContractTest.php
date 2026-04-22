<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Guards the assistant widget against dynamic HTML reparsing regressions.
 */
#[Group('ilas_site_assistant')]
final class AssistantWidgetDomSafetyContractTest extends TestCase {

  /**
   * Reads assistant-widget.js from repository.
   */
  private static function widgetSource(): string {
    $path = dirname(__DIR__, 7) . '/web/modules/custom/ilas_site_assistant/js/assistant-widget.js';
    self::assertFileExists($path, 'assistant-widget.js must exist');

    $contents = file_get_contents($path);
    self::assertIsString($contents, 'assistant-widget.js must be readable');
    return $contents;
  }

  /**
   * Assistant turns must use structured message kinds, not HTML reparsing.
   */
  public function testAssistantTurnsUseStructuredKindsWithoutHtmlParsing(): void {
    $source = self::widgetSource();

    $this->assertStringContainsString("kind: 'response'", $source);
    $this->assertStringContainsString("kind: 'recovery'", $source);
    $this->assertStringContainsString("kind: 'text'", $source);
    $this->assertStringContainsString('renderAssistantResponse: function', $source);
    $this->assertStringContainsString('buildRecoveryMessageContent: function', $source);
    $this->assertStringNotContainsString('DOMParser().parseFromString', $source);
    $this->assertStringNotContainsString('contentEl.innerHTML = content', $source);
    $this->assertStringNotContainsString('parseFromString(content,', $source);
  }

  /**
   * Legacy state migration must degrade rich assistant history to safe text.
   */
  public function testLegacyStateMigrationAnchorsRemainPresent(): void {
    $source = self::widgetSource();

    $this->assertStringContainsString('migrateStoredDisplayMessage: function', $source);
    $this->assertStringContainsString('extractLegacyAssistantText: function', $source);
    $this->assertStringContainsString("message.isHtml", $source);
    $this->assertStringContainsString("Previous assistant response restored as text.", $source);
  }

}
