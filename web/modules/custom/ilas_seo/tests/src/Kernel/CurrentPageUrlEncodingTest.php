<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_seo\Kernel;

use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Request;

/**
 * Guards the drupal/token patch for [current-page:url] on unrouted paths.
 *
 * Metatag resolves both canonical_url and og_url from
 * [current-page:url:absolute]. On a path with no matching route the token
 * module cannot use Url::createFromRequest() and falls back to
 * Url::fromUserInput($request->getPathInfo()) — but getPathInfo() is still
 * percent-encoded while fromUserInput() expects a decoded path and encodes
 * what it is given. Unpatched, "%C3%A9" is emitted as "%25C3%25A9", and a
 * client that follows the value requests a URL one level deeper, whose token
 * is deeper again. In July 2026 SemrushBot walked that ladder to a depth of
 * 1,879 and spent ~21,900 origin 404s doing it.
 *
 * ilas_seo removes the canonical on error pages, so in production this is
 * defence in depth — but the token is available to any consumer, and the
 * Pantheon build cache is known to skip composer patches, so both halves are
 * tested independently.
 *
 * @see patches/token-current-page-url-404-double-encode.patch
 * @see \Drupal\ilas_seo\ErrorPage
 */
#[Group('ilas_seo')]
final class CurrentPageUrlEncodingTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'token'];

  /**
   * An encoded path survives the token round trip without gaining a layer.
   */
  public function testEncodedPathIsNotDoubleEncoded(): void {
    $url = $this->currentPageUrlFor('/no-such-page-caf%C3%A9');

    $this->assertStringNotContainsString(
      '%25',
      $url,
      'The token must not escape the percent signs of an already-encoded path.'
    );
    $this->assertStringEndsWith('/no-such-page-caf%C3%A9', $url);
  }

  /**
   * The round trip is idempotent at any depth, so a loop cannot start.
   *
   * Feeding the token's own output back in must reproduce it exactly. This is
   * the property that matters: without it every pass adds "25" and the URL
   * space is unbounded.
   */
  public function testRoundTripIsIdempotent(): void {
    $path = '/no-such-page-caf%C3%A9';

    for ($i = 0; $i < 3; $i++) {
      $url = $this->currentPageUrlFor($path);
      $next = '/' . ltrim((string) parse_url($url, PHP_URL_PATH), '/');
      $this->assertSame(
        $path,
        $next,
        sprintf('Pass %d changed the path: %s became %s.', $i + 1, $path, $next)
      );
      $path = $next;
    }
  }

  /**
   * A plain ASCII path is unaffected.
   */
  public function testPlainPathIsUnchanged(): void {
    $this->assertStringEndsWith(
      '/no-such-page',
      $this->currentPageUrlFor('/no-such-page')
    );
  }

  /**
   * An encoded delimiter yields no replacement rather than a restructured URL.
   *
   * Decoding "%3F" would turn it into a real "?", silently converting part of
   * the path into a query string. Emitting nothing is the safe answer.
   */
  public function testEncodedDelimiterIsRefused(): void {
    $this->assertSame('', $this->currentPageUrlFor('/no-such-page%3Fa=b'));
  }

  /**
   * Resolves [current-page:url] against a request for the given raw path.
   *
   * @param string $path
   *   A raw, percent-encoded path.
   *
   * @return string
   *   The replaced token value, or '' when the token produced no replacement.
   */
  private function currentPageUrlFor(string $path): string {
    $request = Request::create($path);
    $stack = $this->container->get('request_stack');
    $stack->push($request);

    try {
      return (string) \Drupal::token()->replace('[current-page:url]', [], ['clear' => TRUE]);
    }
    finally {
      $stack->pop();
    }
  }

}
