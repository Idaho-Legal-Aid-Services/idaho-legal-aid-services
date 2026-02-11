<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_seo\Kernel;

use Drupal\KernelTests\KernelTestBase;

/**
 * Tests that the full_html text format strips dangerous tags.
 *
 * Validates security finding H-1: <script> must be stripped,
 * <iframe> attributes must be restricted (no srcdoc, no event handlers).
 *
 * NOTE: If KernelTestBase is unavailable due to PHPUnit version mismatch,
 * run the companion script instead:
 *   ddev drush php:script modules/custom/ilas_seo/tests/scripts/test-full-html-filter.php
 *
 * @group ilas_seo
 * @group security
 */
class FullHtmlFilterTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['filter', 'media'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installConfig(['filter']);

    $config_path = $this->root . '/../config';
    $source = new \Drupal\Core\Config\FileStorage($config_path);
    $config_data = $source->read('filter.format.full_html');

    if ($config_data) {
      $this->config('filter.format.full_html')->setData($config_data)->save();
    }
    else {
      $this->fail('Could not load filter.format.full_html from config sync directory.');
    }
  }

  /**
   * Tests that <script> tags are completely stripped.
   */
  public function testScriptTagStripped(): void {
    $input = '<p>Safe content</p><script>alert("XSS")</script><p>More safe</p>';
    $result = check_markup($input, 'full_html');

    $this->assertStringNotContainsString('<script', (string) $result);
    $this->assertStringNotContainsString('</script>', (string) $result);
    $this->assertStringContainsString('<p>Safe content</p>', (string) $result);
  }

  /**
   * Tests that <iframe srcdoc> has srcdoc stripped.
   */
  public function testIframeSrcdocStripped(): void {
    $input = '<iframe srcdoc="<script>alert(1)</script>" width="100"></iframe>';
    $result = check_markup($input, 'full_html');

    $this->assertStringNotContainsString('srcdoc', (string) $result);
    $this->assertStringContainsString('<iframe', (string) $result);
  }

  /**
   * Tests that <iframe> with allowed attributes is preserved.
   */
  public function testIframeAllowedAttributesPreserved(): void {
    $input = '<iframe src="https://www.youtube.com/embed/test" width="560" height="315" title="Video" allowfullscreen loading="lazy"></iframe>';
    $result = check_markup($input, 'full_html');

    $this->assertStringContainsString('src="https://www.youtube.com/embed/test"', (string) $result);
    $this->assertStringContainsString('width="560"', (string) $result);
  }

  /**
   * Tests that standard allowed tags survive filtering.
   */
  public function testAllowedTagsSurvive(): void {
    $input = '<p>Paragraph</p><strong>Bold</strong><em>Italic</em>';
    $result = check_markup($input, 'full_html');

    $this->assertStringContainsString('<p>Paragraph</p>', (string) $result);
    $this->assertStringContainsString('<strong>Bold</strong>', (string) $result);
    $this->assertStringContainsString('<em>Italic</em>', (string) $result);
  }

}
