<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * IMP-UX-01 — Accessibility & Mobile UX acceptance gate.
 *
 * Source-code contract tests that assert a11y attributes and patterns exist
 * in widget JS, SCSS, and page template. If any pattern is removed, the test
 * fails and blocks merge.
 */
#[Group('ilas_site_assistant')]
final class AccessibilityMobileUxAcceptanceGateTest extends TestCase {

  /**
   * Repository root.
   */
  private static function repoRoot(): string {
    return dirname(__DIR__, 7);
  }

  /**
   * Reads assistant-widget.js.
   */
  private static function widgetJs(): string {
    $path = self::repoRoot() . '/web/modules/custom/ilas_site_assistant/js/assistant-widget.js';
    self::assertFileExists($path);
    $contents = file_get_contents($path);
    self::assertIsString($contents);
    return $contents;
  }

  /**
   * Reads _assistant-widget.scss.
   */
  private static function widgetScss(): string {
    $path = self::repoRoot() . '/web/themes/custom/b5subtheme/scss/_assistant-widget.scss';
    self::assertFileExists($path);
    $contents = file_get_contents($path);
    self::assertIsString($contents);
    return $contents;
  }

  /**
   * Reads assistant-page.html.twig.
   */
  private static function pageTemplate(): string {
    $path = self::repoRoot() . '/web/modules/custom/ilas_site_assistant/templates/assistant-page.html.twig';
    self::assertFileExists($path);
    $contents = file_get_contents($path);
    self::assertIsString($contents);
    return $contents;
  }

  // =========================================================================
  // Section 1: Widget ARIA Contracts
  // =========================================================================

  /**
   * Quick-action buttons must have aria-label attributes.
   */
  public function testQuickActionButtonsHaveAriaLabels(): void {
    $js = self::widgetJs();

    $this->assertStringContainsString("Drupal.t('Search forms')", $js, 'Forms button needs aria-label');
    $this->assertStringContainsString("Drupal.t('Browse guides')", $js, 'Guides button needs aria-label');
    $this->assertStringContainsString("Drupal.t('View FAQs')", $js, 'FAQs button needs aria-label');
    $this->assertStringContainsString("Drupal.t('Apply for help')", $js, 'Apply button needs aria-label');
  }

  /**
   * Chat log must have aria-atomic="false" so SR reads individual messages.
   */
  public function testChatLogHasAriaAtomicFalse(): void {
    $js = self::widgetJs();

    $this->assertStringContainsString('aria-atomic="false"', $js);
  }

  /**
   * Widget input must have keyboard hint via aria-describedby.
   */
  public function testWidgetInputHasKeyboardHint(): void {
    $js = self::widgetJs();

    $this->assertStringContainsString('aria-describedby="widget-input-hint"', $js);
    $this->assertStringContainsString('id="widget-input-hint"', $js);
    $this->assertStringContainsString("Drupal.t('Press Enter to send your message')", $js);
  }

  /**
   * Page template input must retain keyboard hint (regression guard).
   */
  public function testPageInputHasKeyboardHint(): void {
    $twig = self::pageTemplate();

    $this->assertStringContainsString('aria-describedby="input-hint"', $twig);
    $this->assertStringContainsString('id="input-hint"', $twig);
    $this->assertStringContainsString("'Press Enter to send your message'", $twig);
  }

  /**
   * Toggle button must have aria-expanded and aria-controls.
   */
  public function testToggleButtonAriaExpandedAndControls(): void {
    $js = self::widgetJs();

    $this->assertStringContainsString('aria-expanded', $js);
    $this->assertStringContainsString('aria-controls="assistant-panel"', $js);
  }

  /**
   * Typing indicator must have role="status" and aria-label.
   */
  public function testTypingIndicatorHasRoleStatusAndAriaLabel(): void {
    $js = self::widgetJs();

    // Typing indicator container must have status role.
    $this->assertMatchesRegularExpression('/typing.*role=["\']status["\']|role=["\']status["\'].*typing/s', $js);
    // Must have aria-label for SR.
    $this->assertMatchesRegularExpression('/typing.*aria-label/s', $js);
  }

  /**
   * Recovery message must have role="alert".
   */
  public function testRecoveryMessageHasRoleAlert(): void {
    $js = self::widgetJs();

    $this->assertStringContainsString('buildRecoveryMessageContent: function', $js);
    $this->assertStringContainsString("setAttribute('role', 'alert')", $js);
  }

  // =========================================================================
  // Section 2: SR Announcement Contracts
  // =========================================================================

  /**
   * ScrollManager must create a live announcer element.
   */
  public function testJumpButtonHasLiveAnnouncer(): void {
    $js = self::widgetJs();

    $this->assertStringContainsString('this.liveAnnouncer', $js);
    $this->assertMatchesRegularExpression('/liveAnnouncer.*aria-live/s', $js);
  }

  /**
   * _showJumpBtn must set announcer text.
   */
  public function testJumpButtonShowTriggersAnnouncement(): void {
    $js = self::widgetJs();

    // Extract _showJumpBtn method body.
    $this->assertMatchesRegularExpression('/_showJumpBtn.*liveAnnouncer\.textContent\s*=/s', $js);
  }

  /**
   * _hideJumpBtn must clear announcer text.
   */
  public function testJumpButtonHideClearsAnnouncement(): void {
    $js = self::widgetJs();

    $this->assertMatchesRegularExpression("/_hideJumpBtn.*liveAnnouncer\\.textContent\\s*=\\s*['\"]\\s*['\"]/s", $js);
  }

  // =========================================================================
  // Section 3: Error/Timeout/Offline Contracts
  // =========================================================================

  /**
   * callApi must check navigator.onLine === false.
   */
  public function testOfflineCheckInCallApi(): void {
    $js = self::widgetJs();

    $this->assertStringContainsString('navigator.onLine === false', $js);
  }

  /**
   * openPanel must check navigator.onLine for proactive offline warning.
   */
  public function testOfflineCheckOnPanelOpen(): void {
    $js = self::widgetJs();

    // Must have an offline check near openPanel.
    $this->assertMatchesRegularExpression('/openPanel.*navigator\.onLine/s', $js);
  }

  /**
   * Timeout/abort error handling must exist.
   */
  public function testTimeoutErrorHandling(): void {
    $js = self::widgetJs();

    $this->assertStringContainsString('AbortError', $js);
    $this->assertStringContainsString('controller.abort()', $js);
  }

  /**
   * Recovery focus must have a setTimeout fallback for slow DOM.
   */
  public function testRecoveryFocusHasTimeoutFallback(): void {
    $js = self::widgetJs();

    // Must have setTimeout near recovery button focus logic.
    $this->assertMatchesRegularExpression('/recovery-btn.*setTimeout|setTimeout.*recovery-btn/s', $js);
  }

  /**
   * Default 403 message must be actionable (mention session + Refresh).
   */
  public function testDefaultForbiddenMessageIsActionable(): void {
    $js = self::widgetJs();

    $this->assertStringContainsString('session could not be verified', $js);
    $this->assertStringContainsString('Refresh the page', $js);
  }

  // =========================================================================
  // Section 4: Mobile/Motion/Contrast Contracts
  // =========================================================================

  /**
   * SCSS must have at least 5 mobile breakpoint usages.
   */
  public function testMobileBreakpointCoverage(): void {
    $scss = self::widgetScss();

    $count = substr_count($scss, '@include mobile');
    $this->assertGreaterThanOrEqual(5, $count, "Expected at least 5 @include mobile usages, found $count");
  }

  /**
   * SCSS must include prefers-reduced-motion media query.
   */
  public function testReducedMotionMediaQuery(): void {
    $scss = self::widgetScss();

    $this->assertStringContainsString('prefers-reduced-motion', $scss);
  }

  /**
   * SCSS must include prefers-contrast: high media query.
   */
  public function testHighContrastMediaQuery(): void {
    $scss = self::widgetScss();

    $this->assertStringContainsString('prefers-contrast: high', $scss);
  }

  // =========================================================================
  // Section 5: Typing Indicator Contrast
  // =========================================================================

  /**
   * Typing indicator dots must use $color-gray-muted (not $color-gray-text).
   */
  public function testTypingIndicatorUsesHighContrastColor(): void {
    $scss = self::widgetScss();

    // Extract the .typing-indicator span block (base, not inside media query).
    $this->assertMatchesRegularExpression(
      '/\.typing-indicator\s*\{[^}]*span\s*\{[^}]*\$color-gray-muted/s',
      $scss,
      'Typing indicator dots must use $color-gray-muted for contrast'
    );
  }

  /**
   * High-contrast mode must override typing indicator dots to $color-black.
   */
  public function testHighContrastTypingIndicatorOverride(): void {
    $scss = self::widgetScss();

    // Must have .typing-indicator span inside the prefers-contrast: high block.
    $this->assertMatchesRegularExpression(
      '/prefers-contrast:\s*high\).*\.typing-indicator\s+span\s*\{[^}]*\$color-black/s',
      $scss,
      'High-contrast mode must set typing indicator dots to $color-black'
    );
  }

  /**
   * Typing indicator dots must have minimum 8px dimensions.
   */
  public function testTypingIndicatorDotMinimumSize(): void {
    $scss = self::widgetScss();

    $this->assertMatchesRegularExpression(
      '/\.typing-indicator\s*\{[^}]*span\s*\{[^}]*width:\s*8px/s',
      $scss
    );
    $this->assertMatchesRegularExpression(
      '/\.typing-indicator\s*\{[^}]*span\s*\{[^}]*height:\s*8px/s',
      $scss
    );
  }

}
