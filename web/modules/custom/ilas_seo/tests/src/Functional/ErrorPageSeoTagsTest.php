<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_seo\Functional;

use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\BrowserTestBase;
use PHPUnit\Framework\Attributes\Group;

/**
 * End-to-end guard for the canonical encoding loop on error pages.
 *
 * Drupal renders a 404 by re-routing a clone of the failed request, so
 * everything that derives a URL from the request describes the URL that did
 * not resolve. Metatag's canonical and og:url did exactly that, and because
 * the token module re-encoded the already-encoded path, the value advertised
 * was one percent-encoding level deeper than the URL requested. Following it
 * produced a deeper one again. Measured on Cloudflare for July 2026: 28,455
 * requests on such paths, 21,904 of them reaching PHP, encoding depth up to
 * 1,879, and six origin 500s from the resulting URL lengths.
 *
 * Two layers now prevent it and both are asserted here:
 *  - the drupal/token patch stops the re-encoding at source, and
 *  - ilas_seo drops the self-referencing tags on any error page, which holds
 *    even if the patch is ever lost to Pantheon's composer build cache.
 *
 * @see \Drupal\ilas_seo\ErrorPage
 * @see \Drupal\ilas_seo\CanonicalHost::removeSelfReferencingTags()
 * @see patches/token-current-page-url-404-double-encode.patch
 */
#[Group('ilas_seo')]
final class ErrorPageSeoTagsTest extends BrowserTestBase {

  /**
   * A missing path carrying a percent-encoded non-ASCII character.
   *
   * "%C3%A9" is "é" — the same shape as the live Spanish aliases the loop was
   * observed on (solicitaciones-telefónicas, violencia-doméstica).
   */
  private const MISSING_ENCODED = '/no-such-page-caf%C3%A9';

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    // See CanonicalHostRewriteTest for why this must come first.
    'ilas_site_assistant_action_compat',
    'eca',
    'system',
    'user',
    'field',
    'filter',
    'text',
    'node',
    'path',
    'path_alias',
    'metatag',
    'metatag_open_graph',
    'ilas_seo',
  ];

  /**
   * The node backing the 200-response control.
   */
  private Node $node;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    if (!NodeType::load('page')) {
      NodeType::create(['type' => 'page', 'name' => 'Page'])->save();
    }

    // Mirror the production canonical tokens; Metatag's own defaults are
    // relative and would not exercise this at all.
    $this->config('metatag.metatag_defaults.global')
      ->set('tags.canonical_url', '[current-page:url:absolute]')
      ->set('tags.og_url', '[current-page:url:absolute]')
      ->save();

    $this->node = Node::create([
      'type' => 'page',
      'title' => 'Cafe',
      'status' => 1,
    ]);
    $this->node->save();

    // A real, reachable alias with a non-ASCII character, standing in for the
    // live Spanish aliases that contain encoded characters. These must keep
    // their single encoding.
    $this->container->get('entity_type.manager')
      ->getStorage('path_alias')
      ->create([
        'path' => '/node/' . $this->node->id(),
        'alias' => '/vivienda-café',
      ])
      ->save();

    $this->drupalLogin($this->drupalCreateUser(['access content']));
  }

  /**
   * A 404 advertises no canonical and no og:url.
   */
  public function testErrorPageEmitsNoSelfReferencingTags(): void {
    $this->visitRaw(self::MISSING_ENCODED);
    $this->assertSession()->statusCodeEquals(404);

    $this->assertNull(
      $this->find('link[rel="canonical"]'),
      'A 404 must not claim a canonical URL: the address it would name does not resolve.'
    );
    $this->assertNull(
      $this->find('meta[property="og:url"]'),
      'A 404 must not emit og:url either; it resolves from the same token.'
    );
  }

  /**
   * Nothing on a 404 page echoes the requested path re-encoded.
   *
   * The broadest assertion in the suite, and the one that fails first if any
   * new emitter starts reflecting the request URI.
   */
  public function testErrorPageNeverDoubleEncodesTheRequestedPath(): void {
    $this->visitRaw(self::MISSING_ENCODED);
    $this->assertSession()->statusCodeEquals(404);

    $this->assertStringNotContainsString(
      '%25C3%25A9',
      $this->body(),
      'The 404 response re-encoded the requested path; the crawler loop is open again.'
    );
  }

  /**
   * Requesting an already-deepened URL does not deepen it further.
   *
   * This is the property that bounds the URL space. Without it each pass adds
   * another "25" and a crawler can walk forever.
   */
  public function testAlreadyEncodedRequestDoesNotEscalate(): void {
    $this->visitRaw('/no-such-page-caf%25C3%25A9');
    $this->assertSession()->statusCodeEquals(404);

    $this->assertStringNotContainsString(
      '%2525',
      $this->body(),
      'A doubly-encoded request produced a triply-encoded URL in the response.'
    );
  }

  /**
   * No JSON-LD on an error page describes the requested URL.
   *
   * GraphBuilder built a BreadcrumbList whose item and @id were
   * $request->getRequestUri(), which republished an arbitrary caller-supplied
   * string as structured data.
   *
   * The assertion is deliberately about content rather than block count.
   * Production also runs schema_metatag, whose Organization graph is built
   * entirely from constants pinned to the apex; that block says nothing about
   * this page and is correct to keep. What must never appear is the URL the
   * caller asked for.
   */
  public function testErrorPageStructuredDataDescribesNoUrl(): void {
    $this->visitRaw(self::MISSING_ENCODED);
    $this->assertSession()->statusCodeEquals(404);

    foreach ($this->getSession()->getPage()->findAll('css', 'script[type="application/ld+json"]') as $block) {
      $json = (string) $block->getText();
      $this->assertStringNotContainsString(
        'no-such-page',
        $json,
        'Structured data on a 404 republished the requested URL: ' . $json
      );
      $this->assertStringNotContainsString(
        'BreadcrumbList',
        $json,
        'A 404 has no breadcrumb trail to describe.'
      );
    }
  }

  /**
   * A reachable page with an encoded alias keeps its single encoding.
   *
   * The regression guard for the four live Spanish aliases that contain
   * encoded characters. Suppression must apply to error pages only.
   */
  public function testReachablePageWithEncodedAliasIsUnaffected(): void {
    $this->visitRaw('/vivienda-caf%C3%A9');
    $this->assertSession()->statusCodeEquals(200);

    $canonical = (string) $this->find('link[rel="canonical"]')?->getAttribute('href');
    $this->assertStringEndsWith(
      '/vivienda-caf%C3%A9',
      $canonical,
      'A 200 page keeps a correctly encoded canonical.'
    );
    $this->assertStringNotContainsString('%25', $canonical);
  }

  /**
   * Visits a raw path without letting the test framework re-encode it.
   *
   * The drupalGet() helper runs the path through Url::fromUri(), which would
   * normalise the very encoding under test.
   */
  private function visitRaw(string $path): void {
    $this->getSession()->visit(rtrim($this->baseUrl, '/') . $path);
  }

  /**
   * Returns the first element matching a CSS selector, or NULL.
   */
  private function find(string $selector): ?object {
    return $this->getSession()->getPage()->find('css', $selector);
  }

  /**
   * Returns the raw response body.
   */
  private function body(): string {
    return (string) $this->getSession()->getPage()->getContent();
  }

}
