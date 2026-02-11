<?php

namespace Drupal\Tests\ilas_hotspot\Unit;

use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\Xss;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Verifies that ilas_hotspot_create() escapes XSS payloads.
 *
 * Covers finding M-11: hotspot title, icon, and content must be sanitized
 * before rendering into Bootstrap popover attributes and HTML.
 *
 * @group ilas_hotspot
 */
#[Group('ilas_hotspot')]
class HotspotXssTest extends TestCase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Load the module file so ilas_hotspot_create() is available.
    $module_path = dirname(__DIR__, 3) . '/ilas_hotspot.module';
    if (!function_exists('ilas_hotspot_create')) {
      require_once $module_path;
    }
  }

  /**
   * Tests that XSS payloads in title are escaped in render array.
   */
  public function testTitleXssEscaped(): void {
    $hotspots = [
      [
        'title' => '<script>alert("xss")</script>',
        'content' => 'Safe content',
        'icon' => '/icon.svg',
        'category' => 'test',
        'placement' => 'top',
      ],
    ];

    $build = ilas_hotspot_create('/bg.jpg', $hotspots, TRUE, FALSE);

    $trigger = $build['hotspot_0']['trigger'];
    $title_attr = $trigger['#attributes']['data-bs-title'];
    $value = $trigger['#value'];

    // Title attribute must be HTML-escaped.
    $this->assertStringNotContainsString('<script>', $title_attr,
      'data-bs-title must not contain raw <script> tag.');
    $this->assertSame(Html::escape('<script>alert("xss")</script>'), $title_attr);

    // #value alt attribute must also be escaped.
    $this->assertStringNotContainsString('<script>', $value,
      '#value must not contain raw <script> tag.');
  }

  /**
   * Tests that XSS payloads in icon src are escaped.
   */
  public function testIconXssEscaped(): void {
    $hotspots = [
      [
        'title' => 'Safe title',
        'content' => 'Safe content',
        'icon' => '" onerror="alert(1)" src="x',
        'category' => 'test',
        'placement' => 'top',
      ],
    ];

    $build = ilas_hotspot_create('/bg.jpg', $hotspots, TRUE, FALSE);

    $value = $build['hotspot_0']['trigger']['#value'];

    // Icon src must be escaped — double quotes must be entity-encoded
    // so attribute breakout is impossible. The literal string 'onerror='
    // may still appear inside the escaped attribute value, but it cannot
    // be parsed as an HTML attribute because the surrounding quotes are
    // encoded as &quot;.
    $this->assertStringNotContainsString('" onerror="', $value,
      '#value must not contain unescaped double quotes that enable attribute injection.');
    $this->assertStringContainsString('&quot;', $value,
      '#value must HTML-encode double quotes from icon path.');
  }

  /**
   * Tests that XSS payloads in content are filtered by Xss::filterAdmin().
   */
  public function testContentXssFiltered(): void {
    $hotspots = [
      [
        'title' => 'Safe title',
        'content' => '<p>Valid</p><script>alert("xss")</script><img src=x onerror=alert(1)>',
        'icon' => '/icon.svg',
        'category' => 'test',
        'placement' => 'top',
      ],
    ];

    $build = ilas_hotspot_create('/bg.jpg', $hotspots, TRUE, FALSE);

    $content_attr = $build['hotspot_0']['trigger']['#attributes']['data-bs-content'];

    // <script> must be stripped entirely.
    $this->assertStringNotContainsString('<script>', $content_attr,
      'data-bs-content must not contain <script> tags.');

    // onerror event handler must be stripped.
    $this->assertStringNotContainsString('onerror', $content_attr,
      'data-bs-content must not contain event handler attributes.');

    // Safe HTML like <p> should be preserved.
    $this->assertStringContainsString('<p>', $content_attr,
      'data-bs-content should preserve safe HTML tags like <p>.');
  }

  /**
   * Tests that clean hotspot data passes through correctly.
   */
  public function testCleanDataPreserved(): void {
    $hotspots = [
      [
        'title' => 'Impact Statistics',
        'content' => '<p>We served <strong>5,000</strong> clients.</p>',
        'icon' => '/themes/custom/b5subtheme/images/icon-people.svg',
        'category' => 'impact',
        'placement' => 'bottom',
      ],
    ];

    $build = ilas_hotspot_create('/bg.jpg', $hotspots, TRUE, FALSE);

    $trigger = $build['hotspot_0']['trigger'];

    $this->assertSame('Impact Statistics', $trigger['#attributes']['data-bs-title']);
    $this->assertStringContainsString('<strong>5,000</strong>', $trigger['#attributes']['data-bs-content']);
    $this->assertStringContainsString('/themes/custom/b5subtheme/images/icon-people.svg', $trigger['#value']);
  }

}
