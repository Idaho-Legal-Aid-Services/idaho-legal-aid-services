<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_seo\Kernel;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Breadcrumb\Breadcrumb;
use Drupal\Core\Breadcrumb\BreadcrumbManager;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Link;
use Drupal\Core\Path\PathMatcherInterface;
use Drupal\Core\Routing\AdminContext;
use Drupal\Core\Url;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Verifies the gating, output, and admin-route behavior of the GraphBuilder.
 *
 * Companion to FaqSchemaTest which covers FAQ aggregation. This test exercises
 * the WebSite/SearchAction (front-only), BreadcrumbList (>=2 links),
 * admin-route bypass, and the hook_page_attachments integration that bubbles
 * blocks + cacheability into $attachments.
 *
 * Builders that require heavy content fixtures (JobPosting, LearningResource)
 * are out of scope here — their field-walking logic is structurally identical
 * to FaqSchemaTest's paragraph traversal, which already exercises that path.
 */
#[Group('ilas_seo')]
final class GraphBuilderTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'filter',
    'file',
    'text',
    'node',
    'entity_reference_revisions',
    'paragraphs',
    'ilas_seo',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('paragraph');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['system', 'filter', 'node']);
  }

  /**
   * Front route emits exactly one WebSite + SearchAction block.
   */
  public function testFrontPageEmitsWebSiteSearchAction(): void {
    $this->mockPathMatcher(TRUE);
    $this->mockBreadcrumb([]);

    $result = $this->container->get('ilas_seo.graph_builder')->build();
    $blocks = $this->blocksByKey($result['blocks']);

    $this->assertArrayHasKey('website_search_schema', $blocks, 'Front page must emit website_search_schema block.');
    $this->assertCount(1, $blocks['website_search_schema'], 'Exactly one WebSite block is emitted.');

    $payload = Json::decode((string) $blocks['website_search_schema'][0][0]['#value']);
    $this->assertSame('WebSite', $payload['@type']);
    $this->assertSame('SearchAction', $payload['potentialAction']['@type'] ?? NULL);
    $this->assertSame('required name=search_term_string', $payload['potentialAction']['query-input'] ?? NULL);
  }

  /**
   * Non-front routes do not emit the WebSite/SearchAction block.
   */
  public function testNonFrontPageOmitsWebSiteSearchAction(): void {
    $this->mockPathMatcher(FALSE);
    $this->mockBreadcrumb([]);

    $result = $this->container->get('ilas_seo.graph_builder')->build();
    $blocks = $this->blocksByKey($result['blocks']);

    $this->assertArrayNotHasKey('website_search_schema', $blocks);
  }

  /**
   * Breadcrumbs with fewer than two valid links must not emit a block.
   *
   * "Home" alone is not worth emitting and would be a spammy single-item
   * BreadcrumbList in Search Console.
   */
  public function testBreadcrumbWithSingleLinkIsOmitted(): void {
    $this->mockPathMatcher(FALSE);
    $this->mockBreadcrumb([
      Link::fromTextAndUrl('Home', Url::fromRoute('<front>')),
    ]);

    $result = $this->container->get('ilas_seo.graph_builder')->build();
    $blocks = $this->blocksByKey($result['blocks']);

    $this->assertArrayNotHasKey('breadcrumb_schema', $blocks);
  }

  /**
   * A configured canonical base pins every breadcrumb URL in the graph.
   *
   * Regression coverage for validation §8.5. ORG_ID/WEBSITE_ID are hardcoded
   * to the production apex, so request-derived breadcrumb URLs would otherwise
   * produce a graph whose @id cross-references do not resolve when the site is
   * reached through the Pantheon platform hostname.
   */
  public function testBreadcrumbUrlsArePinnedToCanonicalBase(): void {
    $this->setSetting('ilas_canonical_base_url', 'https://idaholegalaid.org');

    $this->mockPathMatcher(FALSE);
    $this->mockBreadcrumb([
      Link::fromTextAndUrl('Home', Url::fromRoute('<front>')),
      Link::fromTextAndUrl('User', Url::fromRoute('user.page')),
    ]);

    $result = $this->container->get('ilas_seo.graph_builder')->build();
    $blocks = $this->blocksByKey($result['blocks']);
    $this->assertArrayHasKey('breadcrumb_schema', $blocks);

    $payload = Json::decode((string) $blocks['breadcrumb_schema'][0][0]['#value']);

    $this->assertStringStartsWith(
      'https://idaholegalaid.org',
      (string) ($payload['@id'] ?? ''),
      'The BreadcrumbList @id is pinned to the canonical base.'
    );
    $this->assertNotEmpty($payload['itemListElement'] ?? []);
    foreach ($payload['itemListElement'] as $element) {
      $this->assertStringStartsWith(
        'https://idaholegalaid.org',
        (string) ($element['item'] ?? ''),
        'Every breadcrumb item URL is pinned to the canonical base.'
      );
    }
  }

  /**
   * With no configured base the graph keeps today's request-derived URLs.
   *
   * Guards the DDEV/local/CI no-op path: the feature must be entirely inert
   * when $settings['ilas_canonical_base_url'] is unset.
   */
  public function testBreadcrumbUrlsUseRequestHostWhenBaseIsUnset(): void {
    $this->mockPathMatcher(FALSE);
    $this->mockBreadcrumb([
      Link::fromTextAndUrl('Home', Url::fromRoute('<front>')),
      Link::fromTextAndUrl('User', Url::fromRoute('user.page')),
    ]);

    $host = $this->container->get('request_stack')
      ->getCurrentRequest()
      ->getSchemeAndHttpHost();

    $result = $this->container->get('ilas_seo.graph_builder')->build();
    $blocks = $this->blocksByKey($result['blocks']);
    $this->assertArrayHasKey('breadcrumb_schema', $blocks);

    $payload = Json::decode((string) $blocks['breadcrumb_schema'][0][0]['#value']);

    $this->assertStringStartsWith($host, (string) ($payload['@id'] ?? ''));
    foreach ($payload['itemListElement'] as $element) {
      $this->assertStringStartsWith($host, (string) ($element['item'] ?? ''));
    }
  }

  /**
   * Admin routes emit no schema at all.
   */
  public function testAdminRouteEmitsNothing(): void {
    $admin = $this->createMock(AdminContext::class);
    $admin->method('isAdminRoute')->willReturn(TRUE);
    $this->container->set('router.admin_context', $admin);

    // Ensure even a "front page" admin route emits nothing.
    $this->mockPathMatcher(TRUE);

    $result = $this->container->get('ilas_seo.graph_builder')->build();

    $this->assertSame([], $result['blocks'], 'Admin routes must not emit schema blocks.');
    $this->assertInstanceOf(CacheableMetadata::class, $result['cache']);
  }

  /**
   * hook_page_attachments() must bubble blocks into #attached/html_head and
   * apply cacheability onto #cache.
   */
  public function testHookPageAttachmentsAppendsBlocksAndCacheability(): void {
    $this->mockPathMatcher(TRUE);
    $this->mockBreadcrumb([]);

    require_once $this->root . '/modules/custom/ilas_seo/ilas_seo.module';

    $attachments = ['#attached' => ['html_head' => []], '#cache' => []];
    \ilas_seo_page_attachments($attachments);

    $head = $attachments['#attached']['html_head'] ?? [];
    $keys = array_map(static fn (array $tuple) => $tuple[1] ?? NULL, $head);
    $this->assertContains('website_search_schema', $keys, 'hook_page_attachments must inject website_search_schema on the front page.');

    // Cacheability bubbled in from the builder's CacheableMetadata.
    $cache = CacheableMetadata::createFromRenderArray($attachments);
    $this->assertContains('route', $cache->getCacheContexts(), 'Schema attachments must vary by route.');
    $this->assertContains('url.path', $cache->getCacheContexts(), 'Schema attachments must vary by url.path.');
  }

  /**
   * The employment template must not contain any inline JSON-LD.
   *
   * Companion to FaqSchemaTest's similar guard for the FAQ paragraph
   * template. JobPosting schema is owned by the GraphBuilder — re-introducing
   * a Twig <script> block would resurrect duplicate-emission risk.
   */
  public function testEmploymentTemplateHasNoInlineJsonLd(): void {
    $template = $this->root . '/themes/custom/b5subtheme/templates/node/node--employment.html.twig';
    $this->assertFileExists($template);
    $contents = (string) file_get_contents($template);

    $this->assertStringNotContainsString('application/ld+json', $contents);
    $this->assertStringNotContainsString('"@type": "JobPosting"', $contents);
    $this->assertStringNotContainsString("'@type': 'JobPosting'", $contents);
  }

  /**
   * The adept-lesson template must not contain any inline JSON-LD.
   *
   * The previous inline block used |e('html_attr') to escape JSON values,
   * which produced HTML-entity-encoded strings (&quot;, &amp;) that broke
   * JSON-LD validity. Schema is now built server-side in PHP.
   */
  public function testAdeptLessonTemplateHasNoInlineJsonLd(): void {
    $template = $this->root . '/themes/custom/b5subtheme/templates/node/node--adept-lesson.html.twig';
    $this->assertFileExists($template);
    $contents = (string) file_get_contents($template);

    $this->assertStringNotContainsString('application/ld+json', $contents);
    $this->assertStringNotContainsString('"@type": "LearningResource"', $contents);
  }

  /**
   * The b5subtheme.theme file must contain no JSON-LD emission helpers.
   *
   * Schema generation is now exclusively module-owned. A regression that
   * reintroduces a theme-side helper would put us back in the multi-source
   * state described in CONCERNS.md Tech Debt #1.
   */
  public function testThemeFileHasNoSchemaEmission(): void {
    $theme = $this->root . '/themes/custom/b5subtheme/b5subtheme.theme';
    $this->assertFileExists($theme);
    $contents = (string) file_get_contents($theme);

    $this->assertStringNotContainsString('application/ld+json', $contents);
    $this->assertStringNotContainsString('_b5subtheme_add_website_search_schema', $contents);
    $this->assertStringNotContainsString('_b5subtheme_add_breadcrumb_schema', $contents);
  }

  /**
   * Mocks path.matcher to return the given isFrontPage() value.
   */
  private function mockPathMatcher(bool $is_front): void {
    $matcher = $this->createMock(PathMatcherInterface::class);
    $matcher->method('isFrontPage')->willReturn($is_front);
    $this->container->set('path.matcher', $matcher);
  }

  /**
   * Mocks the breadcrumb service to return a Breadcrumb with the given links.
   */
  private function mockBreadcrumb(array $links): void {
    $breadcrumb = new Breadcrumb();
    $breadcrumb->setLinks($links);
    $manager = $this->createMock(BreadcrumbManager::class);
    $manager->method('build')->willReturn($breadcrumb);
    $this->container->set('breadcrumb', $manager);
  }

  /**
   * Groups builder result blocks by their html_head dedup key.
   *
   * @return array<string, array<int, array{0: array, 1: string}>>
   */
  private function blocksByKey(array $blocks): array {
    $by_key = [];
    foreach ($blocks as $tuple) {
      $key = $tuple[1] ?? '__unkeyed__';
      $by_key[$key][] = $tuple;
    }
    return $by_key;
  }

}
