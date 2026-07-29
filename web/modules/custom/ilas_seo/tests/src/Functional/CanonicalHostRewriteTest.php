<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_seo\Functional;

use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\BrowserTestBase;
use PHPUnit\Framework\Attributes\Group;

/**
 * End-to-end coverage for SEO host normalisation (validation §8.5).
 *
 * The unit test proves the rewrite functions; this proves the hook chain. It
 * is the only level that catches an ordering regression — if Metatag, hreflang
 * or content_translation ever stopped depositing values before
 * ilas_seo_page_attachments_alter() runs, or if a later alter re-introduced a
 * Host-derived link, every assertion below fails.
 *
 * The canonical base is deliberately set to a host that is NOT the test
 * runner's host, so a passing assertion can only mean the rewrite fired.
 *
 * @see \Drupal\ilas_seo\CanonicalHost
 * @see ilas_seo_page_attachments_alter()
 */
#[Group('ilas_seo')]
final class CanonicalHostRewriteTest extends BrowserTestBase {

  /**
   * A host the test runner will never serve from.
   */
  private const BASE = 'https://canonical.example';

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   *
   * Both hreflang and content_translation are required: production runs
   * hreflang with defer_to_content_translation enabled, so the per-language
   * alternates come from core and only the x-default comes from the module.
   */
  protected static $modules = [
    // ilas_site_assistant_action_compat MUST come first: it provides legacy
    // node action plugin IDs (node_make_sticky_action et al.) that core's node
    // module install pass references via system.action.* config but whose
    // PHP-attribute-discovered classes are not yet registered with the
    // ActionManager at install time. Same deviation D-INFRA-01 as
    // SchemaPropertiesTest.
    'ilas_site_assistant_action_compat',
    'eca',
    'system',
    'user',
    'field',
    'filter',
    'text',
    'node',
    'language',
    'content_translation',
    'hreflang',
    'metatag',
    // Provides the og_url tag plugin and its config schema.
    'metatag_open_graph',
    'ilas_seo',
  ];

  /**
   * The node under test.
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

    ConfigurableLanguage::createFromLangcode('es')->save();

    \Drupal::service('content_translation.manager')
      ->setEnabled('node', 'page', TRUE);

    // Mirror the production canonical token. Metatag's own install default is
    // relative, which would not exercise the host swap.
    $this->config('metatag.metatag_defaults.global')
      ->set('tags.canonical_url', '[current-page:url:absolute]')
      ->set('tags.og_url', '[current-page:url:absolute]')
      ->save();

    // Mirror config/hreflang.settings.yml.
    $this->config('hreflang.settings')
      ->set('x_default', TRUE)
      ->set('x_default_fallback', TRUE)
      ->set('defer_to_content_translation', TRUE)
      ->save();

    $this->node = Node::create([
      'type' => 'page',
      'title' => 'Housing help',
      'status' => 1,
    ]);
    $this->node->save();
    $this->node->addTranslation('es', ['title' => 'Ayuda de vivienda'])->save();

    $this->drupalLogin($this->drupalCreateUser(['access content']));
  }

  /**
   * With a canonical base configured, every emitted SEO URL is pinned.
   */
  public function testEmittedUrlsArePinnedToCanonicalBase(): void {
    $this->setCanonicalBase(self::BASE);

    $path = '/node/' . $this->node->id();
    $this->drupalGet($path);
    $this->assertSession()->statusCodeEquals(200);

    $canonical = $this->hrefFor('link[rel="canonical"]');
    $this->assertSame(
      self::BASE . $path,
      $canonical,
      'The canonical link is pinned to the configured base and keeps its path.'
    );

    $this->assertSame(
      self::BASE . $path,
      $this->contentFor('meta[property="og:url"]'),
      'og:url is pinned.'
    );

    $alternates = $this->hrefsFor('link[rel="alternate"][hreflang]');
    $this->assertNotEmpty($alternates, 'hreflang alternates are emitted for a translated node.');
    foreach ($alternates as $href) {
      $this->assertStringStartsWith(
        self::BASE . '/',
        $href,
        'Every hreflang alternate is pinned to the configured base: ' . $href
      );
    }

    // The Spanish translation must keep its /es prefix after the host swap.
    $this->drupalGet('/es' . $path);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSame(
      self::BASE . '/es' . $path,
      $this->hrefFor('link[rel="canonical"]'),
      'The Spanish canonical keeps its language prefix.'
    );
  }

  /**
   * The rewrite changes the host and nothing else, including query handling.
   *
   * Drupal's own canonical token drops the query string on a routed page. That
   * is pre-existing behaviour and not this change's business to alter, so this
   * asserts parity: the pinned canonical must equal the unpinned one with only
   * the scheme and host swapped. CanonicalHost::rewrite() preserving a query
   * when one is present is covered directly in CanonicalHostTest.
   */
  public function testRewriteChangesOnlyTheHost(): void {
    $query = ['query' => ['keys' => 'divorce', 'page' => '2']];
    $path = '/node/' . $this->node->id();

    $this->drupalGet($path, $query);
    $this->assertSession()->statusCodeEquals(200);
    $before = (string) $this->hrefFor('link[rel="canonical"]');
    $this->assertStringStartsWith($this->baseUrl, $before);

    $this->setCanonicalBase(self::BASE);

    $this->drupalGet($path, $query);
    $this->assertSession()->statusCodeEquals(200);
    $after = (string) $this->hrefFor('link[rel="canonical"]');

    $this->assertSame(
      self::BASE . substr($before, strlen($this->baseUrl)),
      $after,
      'Only the scheme and host changed; the rest of the URL is byte-identical.'
    );
  }

  /**
   * With no canonical base configured the output is unchanged.
   *
   * Guards the off-Pantheon no-op path.
   */
  public function testOutputIsUnchangedWithoutCanonicalBase(): void {
    $path = '/node/' . $this->node->id();
    $this->drupalGet($path);
    $this->assertSession()->statusCodeEquals(200);

    $canonical = (string) $this->hrefFor('link[rel="canonical"]');
    $this->assertStringStartsWith(
      $this->baseUrl,
      $canonical,
      'Without a configured base the canonical stays on the request host.'
    );
    $this->assertStringNotContainsString(self::BASE, $canonical);
  }

  /**
   * Writes $settings['ilas_canonical_base_url'] into the test site.
   *
   * The child site runs in its own request, so the value has to reach its
   * settings.php rather than this process's Settings singleton.
   */
  private function setCanonicalBase(string $base): void {
    $this->writeSettings([
      'settings' => [
        'ilas_canonical_base_url' => (object) [
          'value' => $base,
          'required' => TRUE,
        ],
      ],
    ]);
    $this->rebuildAll();
  }

  /**
   * Returns the href of the first element matching a CSS selector.
   */
  private function hrefFor(string $selector): ?string {
    $element = $this->getSession()->getPage()->find('css', $selector);
    return $element === NULL ? NULL : $element->getAttribute('href');
  }

  /**
   * Returns the hrefs of every element matching a CSS selector.
   *
   * @return array<int, string>
   *   The href attribute values.
   */
  private function hrefsFor(string $selector): array {
    $hrefs = [];
    foreach ($this->getSession()->getPage()->findAll('css', $selector) as $element) {
      $hrefs[] = (string) $element->getAttribute('href');
    }
    return $hrefs;
  }

  /**
   * Returns the content of the first element matching a CSS selector.
   */
  private function contentFor(string $selector): ?string {
    $element = $this->getSession()->getPage()->find('css', $selector);
    return $element === NULL ? NULL : $element->getAttribute('content');
  }

}
