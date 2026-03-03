<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Locks 403 recovery UX semantics for widget/page mode.
 */
#[Group('ilas_site_assistant')]
final class RecoveryUxContractTest extends TestCase {

  /**
   * Returns repository root.
   */
  private static function repoRoot(): string {
    // __DIR__ = <repo>/web/modules/custom/ilas_site_assistant/tests/src/Unit
    return dirname(__DIR__, 7);
  }

  /**
   * Reads assistant-widget.js from repository.
   */
  private static function widgetSource(): string {
    $path = self::repoRoot() . '/web/modules/custom/ilas_site_assistant/js/assistant-widget.js';
    self::assertFileExists($path, 'assistant-widget.js must exist');

    $contents = file_get_contents($path);
    self::assertIsString($contents, 'assistant-widget.js must be readable');
    return $contents;
  }

  /**
   * Recovery contract must expose actionable controls.
   */
  public function testRecoveryControlsAndCopyArePresent(): void {
    $source = self::widgetSource();

    $this->assertStringContainsString('addRecoveryMessage: function', $source);
    $this->assertStringContainsString('recovery-btn--retry', $source);
    $this->assertStringContainsString('recovery-btn--refresh', $source);
    $this->assertStringContainsString("Drupal.t('Try again')", $source);
    $this->assertStringContainsString("Drupal.t('Refresh page')", $source);
  }

  /**
   * Recovery container must preserve keyboard and screen-reader semantics.
   */
  public function testRecoveryAccessibilitySemanticsArePresent(): void {
    $source = self::widgetSource();

    $this->assertStringContainsString('role="alert"', $source);
    $this->assertStringContainsString('aria-label="', $source);
    $this->assertStringContainsString('.recovery-btn--retry, .recovery-btn--refresh', $source);
    $this->assertStringContainsString('firstBtn.focus()', $source);
  }

  /**
   * Retry path must force CSRF refresh before replay.
   */
  public function testRetryPathForcesTokenRefresh(): void {
    $source = self::widgetSource();

    $this->assertStringContainsString('retrySend: function', $source);
    $this->assertStringContainsString('fetchCsrfToken(true)', $source);
    $this->assertStringContainsString('recovery_retry: true', $source);
  }

  /**
   * Recovery branching must include canonical and migration alias codes.
   */
  public function testRecoveryErrorCodeBranchingIncludesCanonicalAndAlias(): void {
    $source = self::widgetSource();

    $this->assertStringContainsString("case 'csrf_missing':", $source);
    $this->assertStringContainsString("case 'csrf_invalid':", $source);
    $this->assertStringContainsString("case 'csrf_expired':", $source);
    $this->assertStringContainsString("case 'session_expired':", $source);
  }

}

