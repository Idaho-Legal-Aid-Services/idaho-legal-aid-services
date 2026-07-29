<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_seo\Unit;

use Drupal\ilas_seo\CanonicalHost;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Covers the SEO host normalisation helper.
 *
 * Regression coverage for validation §8.5: requests arriving through the
 * Pantheon platform hostname emitted self-referencing canonical, hreflang and
 * JSON-LD values. CanonicalHost pins the scheme and host of emitted SEO URLs
 * to a configured base while preserving path, query, fragment, language
 * prefixes and path aliases byte for byte.
 *
 * The helper has no Drupal dependencies, so no bootstrap is required.
 */
#[Group('ilas_seo')]
final class CanonicalHostTest extends TestCase {

  /**
   * The production apex used throughout these assertions.
   */
  private const APEX = 'https://idaholegalaid.org';

  /**
   * The Live Pantheon platform hostname that leaks into canonicals today.
   */
  private const PLATFORM = 'https://live-idaho-legal-aid-services.pantheonsite.io';

  /**
   * normalizeBase() reduces a raw value to scheme://host[:port].
   */
  #[DataProvider('providerNormalizeBase')]
  public function testNormalizeBase(string $raw, string $expected): void {
    $this->assertSame($expected, CanonicalHost::normalizeBase($raw));
  }

  /**
   * Data provider for testNormalizeBase().
   *
   * @return array<string, array{0: string, 1: string}>
   *   Raw value and expected normalised base.
   */
  public static function providerNormalizeBase(): array {
    return [
      'apex' => [self::APEX, self::APEX],
      'trailing slash stripped' => [self::APEX . '/', self::APEX],
      'path dropped' => [self::APEX . '/foo/bar', self::APEX],
      'query dropped' => [self::APEX . '/?a=1', self::APEX],
      'surrounding whitespace' => ['  ' . self::APEX . '  ', self::APEX],
      'uppercase host lowercased' => ['https://IdahoLegalAid.ORG', self::APEX],
      'explicit port preserved' => ['https://example.org:8443', 'https://example.org:8443'],
      'http kept as http' => ['http://example.org', 'http://example.org'],
      'platform host' => [self::PLATFORM, self::PLATFORM],
      'empty' => ['', ''],
      'whitespace only' => ['   ', ''],
      // The documented kill switch: an unparseable value disables everything.
      'kill switch' => ['-', ''],
      'no host' => ['https://', ''],
      'unsupported scheme' => ['ftp://example.org', ''],
      'bare word' => ['idaholegalaid.org', ''],
    ];
  }

  /**
   * rewrite() swaps scheme and host, preserving everything after it.
   */
  #[DataProvider('providerRewrite')]
  public function testRewrite(string $url, string $expected): void {
    $this->assertSame($expected, CanonicalHost::rewrite($url, self::APEX));
  }

  /**
   * Data provider for testRewrite().
   *
   * @return array<string, array{0: string, 1: string}>
   *   Input URL and expected output.
   */
  public static function providerRewrite(): array {
    return [
      // The §8.5 acceptance case.
      'english interior page' => [
        self::PLATFORM . '/legal-help/housing',
        self::APEX . '/legal-help/housing',
      ],
      'front page' => [self::PLATFORM . '/', self::APEX . '/'],

      // Language prefixes and aliases must survive untouched.
      'es prefix' => [self::PLATFORM . '/es', self::APEX . '/es'],
      'es alias' => [
        self::PLATFORM . '/es/resources/quiebra',
        self::APEX . '/es/resources/quiebra',
      ],
      'sw prefix' => [
        self::PLATFORM . '/sw/legal-help/housing',
        self::APEX . '/sw/legal-help/housing',
      ],
      'nl prefix' => [
        self::PLATFORM . '/nl/legal-help/housing',
        self::APEX . '/nl/legal-help/housing',
      ],

      // Query and fragment handling.
      'query preserved verbatim' => [
        self::PLATFORM . '/search?keys=divorce&page=2',
        self::APEX . '/search?keys=divorce&page=2',
      ],
      'query order preserved' => [
        self::PLATFORM . '/search?z=1&a=2',
        self::APEX . '/search?z=1&a=2',
      ],
      'fragment preserved' => [
        self::PLATFORM . '/faq#how-do-i-file',
        self::APEX . '/faq#how-do-i-file',
      ],
      'query and fragment preserved' => [
        self::PLATFORM . '/a?b=1#c',
        self::APEX . '/a?b=1#c',
      ],
      'encoded path preserved' => [
        self::PLATFORM . '/legal-help/casa%20segura',
        self::APEX . '/legal-help/casa%20segura',
      ],

      // Identity: already-canonical values must come back byte-identical.
      'already canonical' => [self::APEX . '/forms', self::APEX . '/forms'],
      'bare base gains no trailing slash' => [self::APEX, self::APEX],

      // Scheme and authority normalisation.
      'http upgraded to base scheme' => ['http://other.example/a', self::APEX . '/a'],
      'protocol relative' => ['//other.example/a', self::APEX . '/a'],
      'protocol relative with query' => ['//other.example/a?b=1', self::APEX . '/a?b=1'],
      'root relative' => ['/legal-help/housing', self::APEX . '/legal-help/housing'],
      'explicit port replaced' => ['https://other.example:8443/a', self::APEX . '/a'],

      // Left alone on purpose.
      'document relative is never guessed' => ['legal-help/housing', 'legal-help/housing'],
      'mailto' => ['mailto:info@idaholegalaid.org', 'mailto:info@idaholegalaid.org'],
      'tel' => ['tel:+12087467541', 'tel:+12087467541'],
      'data uri' => ['data:image/png;base64,AAAA', 'data:image/png;base64,AAAA'],
      'fragment only' => ['#anchor', '#anchor'],
      'empty' => ['', ''],
    ];
  }

  /**
   * An empty base disables rewriting entirely.
   */
  public function testRewriteIsNoOpWithoutBase(): void {
    $url = self::PLATFORM . '/legal-help/housing';
    $this->assertSame($url, CanonicalHost::rewrite($url, ''));
  }

  /**
   * Metatag head tags are pinned; unrelated tags are untouched.
   */
  public function testNormalizeHeadTags(): void {
    $attachments = $this->attachmentsFixture();
    CanonicalHost::normalizeHeadTags($attachments, self::APEX);
    $head = $attachments['#attached']['html_head'];

    $this->assertSame(
      self::APEX . '/legal-help/housing',
      $head[0][0]['#attributes']['href'],
      'canonical is pinned to the configured base.'
    );
    $this->assertSame(
      self::APEX . '/node/42',
      $head[1][0]['#attributes']['href'],
      'shortlink is pinned.'
    );
    $this->assertSame(
      self::APEX . '/legal-help/housing',
      $head[2][0]['#attributes']['content'],
      'og:url is pinned.'
    );
    $this->assertSame(
      self::APEX . '/sites/default/files/social.png',
      $head[3][0]['#attributes']['content'],
      'og:image is pinned.'
    );
    $this->assertSame(
      self::APEX . '/sites/default/files/social.png',
      $head[4][0]['#attributes']['content'],
      'twitter:image is pinned.'
    );

    $this->assertSame(
      'Free civil legal assistance.',
      $head[5][0]['#attributes']['content'],
      'A non-URL meta tag is untouched.'
    );
    $this->assertSame(
      self::PLATFORM . '/node/42#webpage',
      $head[6][0]['#attributes']['content'],
      'schema_metatag tags are not handled by normalizeHeadTags().'
    );
    $this->assertSame(
      'https://twitter.com/IdahoLegalAid',
      $head[7][0]['#attributes']['content'],
      'An external sameAs URL is untouched.'
    );
    $this->assertStringContainsString(
      self::PLATFORM,
      (string) $head[8][0]['#value'],
      'A ld+json script block is not a meta tag and is left to GraphBuilder.'
    );
    $this->assertSame(
      self::PLATFORM . '/feed',
      $head[9][0]['#attributes']['href'],
      'A link rel outside the allowlist is untouched.'
    );
  }

  /**
   * hreflang alternates are pinned; feed alternates are not.
   */
  public function testNormalizeHeadLinks(): void {
    $attachments = $this->attachmentsFixture();
    CanonicalHost::normalizeHeadLinks($attachments, self::APEX);
    $links = $attachments['#attached']['html_head_link'];

    $this->assertSame(self::APEX . '/legal-help/housing', $links[0][0]['href'], 'core canonical link is pinned.');
    $this->assertSame(self::APEX . '/node/42', $links[1][0]['href'], 'core shortlink is pinned.');
    $this->assertSame(self::APEX . '/legal-help/housing', $links[2][0]['href'], 'x-default is pinned.');
    $this->assertSame(self::APEX . '/legal-help/housing', $links[3][0]['href'], 'hreflang=en is pinned.');
    $this->assertSame(self::APEX . '/es/ayuda-legal/vivienda', $links[4][0]['href'], 'hreflang=es keeps its alias.');
    $this->assertSame(self::APEX . '/sw/legal-help/housing', $links[5][0]['href'], 'hreflang=sw is pinned.');
    $this->assertSame(self::APEX . '/nl/legal-help/housing', $links[6][0]['href'], 'hreflang=nl is pinned.');

    $this->assertSame(
      self::PLATFORM . '/rss.xml',
      $links[7][0]['href'],
      'rel=alternate without hreflang is a feed link and must not be rewritten.'
    );
    $this->assertSame(
      self::PLATFORM . '/styles.css',
      $links[8][0]['href'],
      'A rel outside the allowlist is untouched.'
    );
  }

  /**
   * Allowlisted Schema.org URL properties are pinned; the rest are not.
   */
  public function testNormalizeSchemaTags(): void {
    $attachments = $this->attachmentsFixture();
    CanonicalHost::normalizeSchemaTags($attachments, self::APEX);
    $head = $attachments['#attached']['html_head'];

    $this->assertSame(
      self::APEX . '/node/42#webpage',
      $head[6][0]['#attributes']['content'],
      'A schema @id is pinned.'
    );
    $this->assertSame(
      'https://twitter.com/IdahoLegalAid',
      $head[7][0]['#attributes']['content'],
      'sameAs holds external profile URLs and must never be rewritten.'
    );
    $this->assertSame(
      self::PLATFORM . '/legal-help/housing',
      $head[0][0]['#attributes']['href'],
      'Non-schema tags are left to normalizeHeadTags().'
    );
  }

  /**
   * A serialized or array schema value is skipped rather than corrupted.
   */
  public function testNormalizeSchemaTagsSkipsNonStringContent(): void {
    $serialized = 'a:2:{s:5:"@type";s:13:"PostalAddress";s:3:"url";s:5:"/here";}';
    $attachments = [
      '#attached' => [
        'html_head' => [
          [
            [
              '#tag' => 'meta',
              '#attributes' => [
                'schema_metatag' => TRUE,
                'group' => 'org',
                'name' => 'url',
                'content' => ['https://live-idaho-legal-aid-services.pantheonsite.io/a'],
              ],
            ],
            'schema_array',
          ],
          [
            [
              '#tag' => 'meta',
              '#attributes' => [
                'schema_metatag' => TRUE,
                'group' => 'org',
                'name' => 'address',
                'content' => $serialized,
              ],
            ],
            'schema_serialized',
          ],
        ],
      ],
    ];

    $before = $attachments;
    CanonicalHost::normalizeSchemaTags($attachments, self::APEX);

    $this->assertSame($before, $attachments, 'Array and non-allowlisted values are untouched.');
  }

  /**
   * With no configured base every walker leaves the attachments identical.
   */
  public function testAllWalkersAreNoOpWithoutBase(): void {
    $attachments = $this->attachmentsFixture();
    $before = $attachments;

    CanonicalHost::normalizeHeadTags($attachments, '');
    CanonicalHost::normalizeHeadLinks($attachments, '');
    CanonicalHost::normalizeSchemaTags($attachments, '');

    $this->assertSame($before, $attachments);
  }

  /**
   * Malformed entries must not raise notices or warnings.
   */
  public function testWalkersTolerateMalformedEntries(): void {
    $attachments = [
      '#attached' => [
        'html_head' => [
          [],
          ['not-an-array', 'key'],
          [['#tag' => 'link'], 'no-attributes'],
          [['#tag' => 'link', '#attributes' => ['rel' => 'canonical']], 'no-href'],
          [['#tag' => 'meta', '#attributes' => ['property' => 'og:url']], 'no-content'],
          [['#tag' => 'link', '#attributes' => ['rel' => 'canonical', 'href' => ['array']]], 'array-href'],
        ],
        'html_head_link' => [
          [],
          ['not-an-array'],
          [['rel' => 'canonical']],
          [['href' => 'https://example.org/a']],
          [['rel' => ['array'], 'href' => 'https://example.org/a']],
        ],
      ],
    ];

    $before = $attachments;
    CanonicalHost::normalizeHeadTags($attachments, self::APEX);
    CanonicalHost::normalizeHeadLinks($attachments, self::APEX);
    CanonicalHost::normalizeSchemaTags($attachments, self::APEX);

    $this->assertSame($before, $attachments, 'Malformed entries are skipped, not mangled.');
  }

  /**
   * Missing html_head / html_head_link keys are handled.
   */
  public function testWalkersTolerateEmptyAttachments(): void {
    $attachments = [];
    CanonicalHost::normalizeHeadTags($attachments, self::APEX);
    CanonicalHost::normalizeHeadLinks($attachments, self::APEX);
    CanonicalHost::normalizeSchemaTags($attachments, self::APEX);
    $this->assertSame([], $attachments);
  }

  /**
   * A representative page-attachments array as the real hooks produce it.
   *
   * Each html_head entry is a [render_array, string $key] tuple; each
   * html_head_link entry is an [attributes_array, ?bool $header] tuple.
   *
   * @return array
   *   The fixture, on the Pantheon platform host throughout.
   */
  private function attachmentsFixture(): array {
    return [
      '#attached' => [
        'html_head' => [
          // 0 — Metatag canonical.
          [
            [
              '#tag' => 'link',
              '#attributes' => ['rel' => 'canonical', 'href' => self::PLATFORM . '/legal-help/housing'],
            ],
            'canonical_url',
          ],
          // 1 — Metatag shortlink.
          [
            [
              '#tag' => 'link',
              '#attributes' => ['rel' => 'shortlink', 'href' => self::PLATFORM . '/node/42'],
            ],
            'shortlink',
          ],
          // 2 — Open Graph URL (property attribute).
          [
            [
              '#tag' => 'meta',
              '#attributes' => ['property' => 'og:url', 'content' => self::PLATFORM . '/legal-help/housing'],
            ],
            'og_url',
          ],
          // 3 — Open Graph image, resolved from a node field.
          [
            [
              '#tag' => 'meta',
              '#attributes' => ['property' => 'og:image', 'content' => self::PLATFORM . '/sites/default/files/social.png'],
            ],
            'og_image',
          ],
          // 4 — Twitter card image (name attribute).
          [
            [
              '#tag' => 'meta',
              '#attributes' => ['name' => 'twitter:image', 'content' => self::PLATFORM . '/sites/default/files/social.png'],
            ],
            'twitter_cards_image',
          ],
          // 5 — A plain description, not a URL.
          [
            [
              '#tag' => 'meta',
              '#attributes' => ['name' => 'description', 'content' => 'Free civil legal assistance.'],
            ],
            'description',
          ],
          // 6 — schema_metatag @id, folded into ld+json later.
          [
            [
              '#tag' => 'meta',
              '#attributes' => [
                'schema_metatag' => TRUE,
                'group' => 'web_page',
                'name' => '@id',
                'content' => self::PLATFORM . '/node/42#webpage',
              ],
            ],
            'schema_web_page_id',
          ],
          // 7 — schema_metatag sameAs, an external URL.
          [
            [
              '#tag' => 'meta',
              '#attributes' => [
                'schema_metatag' => TRUE,
                'group' => 'organization',
                'name' => 'sameAs',
                'content' => 'https://twitter.com/IdahoLegalAid',
              ],
            ],
            'schema_organization_same_as',
          ],
          // 8 — A GraphBuilder ld+json block.
          [
            [
              '#type' => 'html_tag',
              '#tag' => 'script',
              '#value' => '{"@id":"' . self::PLATFORM . '/#breadcrumb"}',
              '#attributes' => ['type' => 'application/ld+json'],
            ],
            'breadcrumb_schema',
          ],
          // 9 — A link rel outside the allowlist.
          [
            [
              '#tag' => 'link',
              '#attributes' => ['rel' => 'preload', 'href' => self::PLATFORM . '/feed'],
            ],
            'preload',
          ],
        ],
        'html_head_link' => [
          // 0 — core entity canonical.
          [['rel' => 'canonical', 'href' => self::PLATFORM . '/legal-help/housing'], TRUE],
          // 1 — core entity shortlink.
          [['rel' => 'shortlink', 'href' => self::PLATFORM . '/node/42'], TRUE],
          // 2 — hreflang x-default.
          [['rel' => 'alternate', 'hreflang' => 'x-default', 'href' => self::PLATFORM . '/legal-help/housing']],
          // 3-6 — the four site languages.
          [['rel' => 'alternate', 'hreflang' => 'en', 'href' => self::PLATFORM . '/legal-help/housing']],
          [['rel' => 'alternate', 'hreflang' => 'es', 'href' => self::PLATFORM . '/es/ayuda-legal/vivienda']],
          [['rel' => 'alternate', 'hreflang' => 'sw', 'href' => self::PLATFORM . '/sw/legal-help/housing']],
          [['rel' => 'alternate', 'hreflang' => 'nl', 'href' => self::PLATFORM . '/nl/legal-help/housing']],
          // 7 — an RSS feed: rel=alternate but no hreflang.
          [['rel' => 'alternate', 'type' => 'application/rss+xml', 'href' => self::PLATFORM . '/rss.xml']],
          // 8 — a rel outside the allowlist.
          [['rel' => 'stylesheet', 'href' => self::PLATFORM . '/styles.css']],
        ],
      ],
    ];
  }

}
