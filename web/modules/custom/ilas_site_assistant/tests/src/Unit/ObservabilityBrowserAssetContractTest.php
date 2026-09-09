<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Contract tests for browser observability assets and theme wiring.
 */
#[Group('ilas_site_assistant')]
class ObservabilityBrowserAssetContractTest extends TestCase {

  /**
   * Returns repo root.
   */
  private static function repoRoot(): string {
    return dirname(__DIR__, 7);
  }

  /**
   * Tests the browser helper exposes the expected observability hooks.
   */
  public function testObservabilityHelperContainsSentryReplayHooks(): void {
    $script = file_get_contents(self::repoRoot() . '/web/modules/custom/ilas_site_assistant/js/observability.js');

    $this->assertIsString($script);
    $this->assertStringContainsString("lazyLoadIntegration('replayIntegration')", $script);
    $this->assertStringContainsString('showReportDialog', $script);
    $this->assertStringContainsString('ilas:assistant:error', $script);
    $this->assertStringContainsString('ilas:assistant:action', $script);
  }

  /**
   * Tests widget error captures keep a readable, bounded title (PHP-1M).
   *
   * The Sentry event processor must scrub the envelope with scrubEvent() (so
   * event.message / breadcrumb messages survive) and emitAssistantError must
   * title + fingerprint by feature and failure class instead of the constant
   * 'AILA browser error' that the key-name scrubber used to blank.
   */
  public function testObservabilityHelperTitlesAndFingerprintsWidgetErrors(): void {
    $script = file_get_contents(self::repoRoot() . '/web/modules/custom/ilas_site_assistant/js/observability.js');

    $this->assertIsString($script);
    $this->assertStringContainsString("var title = 'AILA browser error: ' + feature + ' (' + errorClass + ')';", $script);
    $this->assertStringContainsString('window.Sentry.captureMessage(title, \'error\')', $script);
    $this->assertStringNotContainsString("captureMessage('AILA browser error'", $script);
    $this->assertStringContainsString("scope.setFingerprint(['aila-browser-error', feature, errorClass])", $script);
    $this->assertStringContainsString('var scrubbed = scrubEvent(event || {});', $script);
    $this->assertStringContainsString('function classifyAssistantError(payload)', $script);
    $this->assertStringContainsString('assistant_status:', $script);
  }

  /**
   * Tests the browser scrubber carries cycle and depth guards (PHP-AJ).
   */
  public function testObservabilityScrubberHasRecursionGuards(): void {
    $script = file_get_contents(self::repoRoot() . '/web/modules/custom/ilas_site_assistant/js/observability.js');

    $this->assertIsString($script);
    $this->assertStringContainsString('var MAX_SCRUB_DEPTH = 10;', $script);
    $this->assertStringContainsString("return '[Circular]';", $script);
    $this->assertStringContainsString("return '[Truncated]';", $script);
    $this->assertStringContainsString('function scrubValue(value, ancestors, depth)', $script);
  }

  /**
   * Tests Drupal attaches the browser observability settings and helper.
   */
  public function testModuleAttachesObservabilityLibraryAndSettings(): void {
    $module = file_get_contents(self::repoRoot() . '/web/modules/custom/ilas_site_assistant/ilas_site_assistant.module');

    $this->assertIsString($module);
    $this->assertStringContainsString("ilas_site_assistant/observability", $module);
    $this->assertStringContainsString("drupalSettings']['ilasObservability']", $module);
    $this->assertStringContainsString("public_dsn", $module);
    $this->assertStringContainsString("browser_traces_sample_rate", $module);
  }

  /**
   * Tests assistant tracking stays out of GA/dataLayer.
   */
  public function testAssistantWidgetDoesNotPushToDataLayer(): void {
    $script = file_get_contents(self::repoRoot() . '/web/modules/custom/ilas_site_assistant/js/assistant-widget.js');

    $this->assertIsString($script);
    $this->assertStringNotContainsString('window.dataLayer.push', $script);
    $this->assertStringContainsString('this.emitAssistantAction(eventType, eventValue, metadata);', $script);
    $this->assertStringContainsString('return this.callTrackApi(payload)', $script);
  }

  /**
   * Tests the theme suppresses GA bootstrap on the assistant page route.
   */
  public function testThemeSuppressesGoogleTagOnAssistantPage(): void {
    $theme = file_get_contents(self::repoRoot() . '/web/themes/custom/b5subtheme/b5subtheme.theme');

    $this->assertIsString($theme);
    $this->assertStringContainsString("ilas_site_assistant.page", $theme);
    $this->assertStringContainsString("? NULL", $theme);
    $this->assertStringContainsString("Settings::get('google_tag_id')", $theme);
  }

}
