<?php

declare(strict_types=1);

namespace Drupal\ilas_seo;

/**
 * Rewrites the scheme and host of emitted SEO URLs to a configured base.
 *
 * Requests that reach Drupal through a non-public hostname — most importantly
 * the Pantheon platform domain
 * https://live-idaho-legal-aid-services.pantheonsite.io — produce
 * self-referencing canonical, hreflang, og:url and JSON-LD values, because
 * every absolute URL Drupal generates is derived from the incoming Host
 * header:
 *
 * - DrupalKernel::initializeRequestGlobals() unconditionally overwrites
 *   $base_url from settings.php with $request->getSchemeAndHttpHost(), so the
 *   documented settings.php override does not work in Drupal 11.
 * - UrlGenerator::generateFromRoute() assembles absolute URLs from the
 *   RequestContext scheme and host, which Symfony populates from the request.
 *
 * There is no supported global override, and no Metatag token resolves to a
 * configured base URL, so the emitted values are normalised here instead.
 * Path, query and fragment are preserved byte for byte.
 *
 * This deliberately rewrites only *advertised* URLs (SEO metadata). It never
 * touches *navigational* URLs — redirects, form actions, links — because
 * changing those would effectively redirect the platform hostname, which is
 * explicitly out of scope.
 *
 * No Drupal dependencies, so this is directly unit testable.
 */
final class CanonicalHost {

  /**
   * The link rel values whose href is a page URL this site owns.
   */
  private const REWRITABLE_RELS = ['canonical', 'shortlink', 'alternate'];

  /**
   * The meta property values whose content is a URL this site owns.
   */
  private const REWRITABLE_PROPERTIES = [
    'og:url',
    'og:image',
    'og:image:secure_url',
  ];

  /**
   * The meta name values whose content is a URL this site owns.
   */
  private const REWRITABLE_NAMES = ['twitter:image'];

  /**
   * Schema.org property names that always hold a URL this site owns.
   *
   * Deliberately narrow. "sameAs" holds external social profile URLs and
   * "logo"/"image" may point at a CDN, so rewriting them would corrupt the
   * graph rather than repair it.
   */
  private const REWRITABLE_SCHEMA_NAMES = [
    '@id',
    'url',
    'mainEntityOfPage',
    'item',
  ];

  /**
   * Normalises a raw settings value into "scheme://host[:port]".
   *
   * @param string $raw
   *   A configured base URL, which may carry a path or a trailing slash.
   *
   * @return string
   *   The scheme, host and optional port, or an empty string when the value is
   *   unusable. An empty string makes every other method in this class a
   *   no-op, which is how the feature stays inert off Pantheon.
   */
  public static function normalizeBase(string $raw): string {
    $raw = trim($raw);
    if ($raw === '') {
      return '';
    }

    $parts = parse_url($raw);
    if (!is_array($parts) || empty($parts['host'])) {
      return '';
    }

    $scheme = strtolower((string) ($parts['scheme'] ?? 'https'));
    if ($scheme !== 'http' && $scheme !== 'https') {
      return '';
    }

    $base = $scheme . '://' . strtolower((string) $parts['host']);
    if (isset($parts['port'])) {
      $base .= ':' . $parts['port'];
    }

    return $base;
  }

  /**
   * Swaps scheme, host and port on one URL, preserving the rest.
   *
   * Behaviour by input shape:
   * - '' or $base === '' — returned unchanged.
   * - https://other/a/b?c=1#d — $base . '/a/b?c=1#d'.
   * - A URL already on the base — returned unchanged by identity reassembly.
   * - http://other/a — $base . '/a'; the scheme follows the base.
   * - //other/a — $base . '/a'.
   * - /a/b — $base . '/a/b'.
   * - a/b — returned unchanged; resolving a document-relative reference would
   *   require the request URI, and guessing is worse than leaving it alone.
   * - mailto:, tel:, data:, urn: — returned unchanged.
   * - Malformed — returned unchanged.
   *
   * @param string $url
   *   The URL to rewrite.
   * @param string $base
   *   A base as returned by static::normalizeBase().
   *
   * @return string
   *   The rewritten URL, or the input unchanged.
   */
  public static function rewrite(string $url, string $base): string {
    if ($url === '' || $base === '') {
      return $url;
    }

    // Protocol-relative: //host/path.
    if (str_starts_with($url, '//')) {
      $parts = parse_url('https:' . $url);
      if (!is_array($parts) || empty($parts['host'])) {
        return $url;
      }
      return $base . self::tail($parts);
    }

    $parts = parse_url($url);
    if (!is_array($parts)) {
      return $url;
    }

    if (empty($parts['host'])) {
      // A non-hierarchical scheme such as mailto:, tel: or data:.
      if (isset($parts['scheme'])) {
        return $url;
      }
      // Root-relative gets the base; document-relative is left alone.
      return str_starts_with($url, '/') ? $base . $url : $url;
    }

    $scheme = strtolower((string) ($parts['scheme'] ?? ''));
    if ($scheme !== '' && $scheme !== 'http' && $scheme !== 'https') {
      return $url;
    }

    return $base . self::tail($parts);
  }

  /**
   * Rewrites the head tags Metatag emits.
   *
   * $attachments['#attached']['html_head'] is a list of
   * [render_array, string $key] tuples. Matching is on the rendered shape
   * rather than the tuple key, so this is independent of Metatag's plugin id
   * naming and also catches any other emitter of the same tags.
   *
   * Tags flagged with schema_metatag are skipped here; see
   * static::normalizeSchemaTags().
   *
   * @param array $attachments
   *   The page attachments array, altered in place.
   * @param string $base
   *   A base as returned by static::normalizeBase().
   */
  public static function normalizeHeadTags(array &$attachments, string $base): void {
    if ($base === '' || empty($attachments['#attached']['html_head'])) {
      return;
    }

    foreach ($attachments['#attached']['html_head'] as $index => $item) {
      $element = $item[0] ?? NULL;
      if (!is_array($element) || empty($element['#attributes']) || !is_array($element['#attributes'])) {
        continue;
      }

      $attributes = $element['#attributes'];
      $tag = $element['#tag'] ?? '';

      // Structured data is handled separately, with a stricter allowlist.
      if (!empty($attributes['schema_metatag'])) {
        continue;
      }

      // <link rel="canonical|shortlink" href="...">.
      if ($tag === 'link'
        && isset($attributes['rel'], $attributes['href'])
        && is_string($attributes['href'])
        && in_array($attributes['rel'], ['canonical', 'shortlink'], TRUE)
      ) {
        $attachments['#attached']['html_head'][$index][0]['#attributes']['href']
          = self::rewrite($attributes['href'], $base);
        continue;
      }

      // <meta property="og:url" content="..."> and the social image tags.
      // Open Graph tags use the property attribute (MetaPropertyBase); Twitter
      // card tags use name (MetaNameBase).
      if ($tag === 'meta' && isset($attributes['content']) && is_string($attributes['content'])) {
        $property = $attributes['property'] ?? NULL;
        $name = $attributes['name'] ?? NULL;
        $matches = (is_string($property) && in_array($property, self::REWRITABLE_PROPERTIES, TRUE))
          || (is_string($name) && in_array($name, self::REWRITABLE_NAMES, TRUE));

        if ($matches) {
          $attachments['#attached']['html_head'][$index][0]['#attributes']['content']
            = self::rewrite($attributes['content'], $base);
        }
      }
    }
  }

  /**
   * Rewrites hreflang alternates and core-emitted canonical links.
   *
   * $attachments['#attached']['html_head_link'] is a list of
   * [attributes_array, ?bool $add_http_header] tuples. The producers that
   * matter here are hreflang_page_attachments(),
   * ContentTranslationHooks::pageAttachments() and
   * EntityViewController::view(), all of which call Url::setAbsolute().
   *
   * rel="alternate" is only rewritten when an hreflang attribute is present,
   * so feed links (rel="alternate" type="application/rss+xml") are left alone.
   *
   * @param array $attachments
   *   The page attachments array, altered in place.
   * @param string $base
   *   A base as returned by static::normalizeBase().
   */
  public static function normalizeHeadLinks(array &$attachments, string $base): void {
    if ($base === '' || empty($attachments['#attached']['html_head_link'])) {
      return;
    }

    foreach ($attachments['#attached']['html_head_link'] as $index => $item) {
      $attributes = $item[0] ?? NULL;
      if (!is_array($attributes)
        || !isset($attributes['rel'], $attributes['href'])
        || !is_string($attributes['rel'])
        || !is_string($attributes['href'])
      ) {
        continue;
      }

      if (!in_array($attributes['rel'], self::REWRITABLE_RELS, TRUE)) {
        continue;
      }

      if ($attributes['rel'] === 'alternate' && !isset($attributes['hreflang'])) {
        continue;
      }

      $attachments['#attached']['html_head_link'][$index][0]['href']
        = self::rewrite($attributes['href'], $base);
    }
  }

  /**
   * Rewrites the URL-valued properties of Schema.org meta tags.
   *
   * The schema_metatag module emits each property as
   * <meta name="..." content="..." schema_metatag="1" group="...">, then folds
   * them into a single ld+json block in its own hook_page_attachments_alter().
   * Because ilas_seo sorts before schema_metatag, the values are still plain
   * meta tags when this runs.
   *
   * Only string values whose property name is in a narrow allowlist are
   * touched, so external URLs (sameAs) and serialised or array values are
   * never rewritten.
   *
   * @param array $attachments
   *   The page attachments array, altered in place.
   * @param string $base
   *   A base as returned by static::normalizeBase().
   */
  public static function normalizeSchemaTags(array &$attachments, string $base): void {
    if ($base === '' || empty($attachments['#attached']['html_head'])) {
      return;
    }

    foreach ($attachments['#attached']['html_head'] as $index => $item) {
      $element = $item[0] ?? NULL;
      if (!is_array($element) || empty($element['#attributes']) || !is_array($element['#attributes'])) {
        continue;
      }

      $attributes = $element['#attributes'];
      if (empty($attributes['schema_metatag'])) {
        continue;
      }
      if (!isset($attributes['name'], $attributes['content'])
        || !is_string($attributes['name'])
        || !is_string($attributes['content'])
      ) {
        continue;
      }
      if (!in_array($attributes['name'], self::REWRITABLE_SCHEMA_NAMES, TRUE)) {
        continue;
      }

      $attachments['#attached']['html_head'][$index][0]['#attributes']['content']
        = self::rewrite($attributes['content'], $base);
    }
  }

  /**
   * Removes the head tags that advertise the current URL as a real page.
   *
   * Called on error pages only. A 404 must not tell a crawler "the canonical
   * address of this content is <the URL that just failed>" — the URL does not
   * resolve, so the statement is false whatever it says, and here it is also
   * corrupt: the token module re-encodes an already-encoded path on unrouted
   * requests, so the advertised URL is one percent-encoding level deeper than
   * the one requested. Following it produces a deeper one again, without
   * bound. See \Drupal\ilas_seo\ErrorPage for the mechanism.
   *
   * Only the two self-referencing tags are removed. hreflang alternates are
   * left alone because they point at the translated error pages, which do
   * exist, and og:image and the Twitter card tags are not derived from the
   * request path at all.
   *
   * Deliberately independent of normalizeBase(): unlike the host rewrite,
   * this must not be switchable off, or setting the documented
   * ILAS_CANONICAL_BASE_URL kill switch would silently reopen the loop.
   *
   * @param array $attachments
   *   The page attachments array, altered in place.
   */
  public static function removeSelfReferencingTags(array &$attachments): void {
    if (empty($attachments['#attached']['html_head'])) {
      return;
    }

    foreach ($attachments['#attached']['html_head'] as $index => $item) {
      $element = $item[0] ?? NULL;
      if (!is_array($element) || empty($element['#attributes']) || !is_array($element['#attributes'])) {
        continue;
      }

      $attributes = $element['#attributes'];
      $tag = $element['#tag'] ?? '';

      // Structured data is folded into a single ld+json block later and is
      // handled at source, in GraphBuilder.
      if (!empty($attributes['schema_metatag'])) {
        continue;
      }

      $is_canonical = $tag === 'link'
        && ($attributes['rel'] ?? NULL) === 'canonical';
      $is_og_url = $tag === 'meta'
        && ($attributes['property'] ?? NULL) === 'og:url';

      if ($is_canonical || $is_og_url) {
        unset($attachments['#attached']['html_head'][$index]);
      }
    }
  }

  /**
   * Reassembles path, query and fragment from parse_url() output.
   *
   * @param array $parts
   *   The result of parse_url().
   *
   * @return string
   *   Everything after the authority, or an empty string.
   */
  private static function tail(array $parts): string {
    $tail = (string) ($parts['path'] ?? '');
    if (isset($parts['query']) && $parts['query'] !== '') {
      $tail .= '?' . $parts['query'];
    }
    if (isset($parts['fragment']) && $parts['fragment'] !== '') {
      $tail .= '#' . $parts['fragment'];
    }
    return $tail;
  }

}
