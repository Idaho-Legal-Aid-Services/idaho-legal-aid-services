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
    $this->assertStringContainsString('return this.callTrackApi({', $script);
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
