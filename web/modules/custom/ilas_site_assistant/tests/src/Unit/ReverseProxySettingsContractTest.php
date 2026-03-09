<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Guards the repo-side reverse-proxy trust contract in settings.php.
 */
#[Group('ilas_site_assistant')]
final class ReverseProxySettingsContractTest extends TestCase {

  /**
   * The trusted-proxy runtime contract must remain explicit in settings.php.
   */
  public function testSettingsPhpDeclaresTrustedProxyContract(): void {
    $settings = file_get_contents(self::repoRoot() . '/web/sites/default/settings.php');
    $this->assertIsString($settings);
    $this->assertStringContainsString('ILAS_TRUSTED_PROXY_ADDRESSES', $settings);
    $this->assertStringContainsString("\$settings['reverse_proxy'] = TRUE;", $settings);
    $this->assertStringContainsString("\$settings['reverse_proxy_addresses'] =", $settings);
    $this->assertStringContainsString("\$settings['reverse_proxy_trusted_headers'] =", $settings);
    $this->assertStringContainsString('HEADER_X_FORWARDED_FOR', $settings);
    $this->assertStringContainsString('HEADER_FORWARDED', $settings);
  }

  /**
   * Returns the repository root path.
   */
  private static function repoRoot(): string {
    return dirname(__DIR__, 7);
  }

}
