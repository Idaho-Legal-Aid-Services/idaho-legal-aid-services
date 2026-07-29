<?php

declare(strict_types=1);

namespace Drupal\ilas_seo\StructuredData;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Breadcrumb\BreadcrumbManager;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Controller\TitleResolverInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Path\PathMatcherInterface;
use Drupal\Core\Routing\AdminContext;
use Drupal\Core\Routing\CurrentRouteMatch;
use Drupal\Core\Site\Settings;
use Drupal\ilas_seo\CanonicalHost;
use Drupal\node\NodeInterface;
use Drupal\paragraphs\ParagraphInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Single source of truth for custom JSON-LD structured-data emission.
 *
 * Owns every standalone <script type="application/ld+json"> block this site
 * produces outside of the schema_metatag @graph (which remains the canonical
 * source for tag-driven schema such as Article/Event/Organization/WebPage).
 *
 * Responsibilities:
 *  - WebSite + SearchAction (front route only).
 *  - BreadcrumbList (all non-admin routes with >=2 valid links).
 *  - FAQPage aggregated across every faq_smart_section paragraph on the
 *    rendered node, emitted exactly once per page.
 *  - JobPosting @graph for employment nodes.
 *  - LearningResource for adept_lesson nodes.
 *
 * Each builder returns a render-array tuple plus CacheableMetadata; the caller
 * (ilas_seo_page_attachments) appends the tuples to #attached/html_head and
 * merges cacheability onto #cache.
 */
final class GraphBuilder {

  /**
   * Front-page WebSite/Organization @id used across the graph.
   */
  private const ORG_ID = 'https://idaholegalaid.org/#organization';
  private const WEBSITE_ID = 'https://idaholegalaid.org/#website';
  private const ORG_LOGO = 'https://idaholegalaid.org/themes/custom/b5subtheme/images/ILAS_logo_notagline_bg-white_raster.png';

  /**
   * Memoised canonical base, or '' when the rewrite is disabled.
   */
  private ?string $canonicalBase = NULL;

  public function __construct(
    private readonly PathMatcherInterface $pathMatcher,
    private readonly AdminContext $adminContext,
    private readonly CurrentRouteMatch $routeMatch,
    private readonly RequestStack $requestStack,
    private readonly EntityFieldManagerInterface $entityFieldManager,
    private readonly BreadcrumbManager $breadcrumbManager,
    private readonly TitleResolverInterface $titleResolver,
    private readonly Settings $settings,
  ) {
  }

  /**
   * Returns the configured canonical base, or '' when unset.
   *
   * The ORG_ID, WEBSITE_ID and ORG_LOGO constants above are already pinned to
   * the production apex. Without this, the request-derived URLs below would
   * disagree with them whenever the site is reached through a non-public
   * hostname, producing a graph whose @id cross-references do not resolve.
   */
  private function canonicalBase(): string {
    return $this->canonicalBase ??= CanonicalHost::normalizeBase(
      (string) $this->settings->get('ilas_canonical_base_url', '')
    );
  }

  /**
   * Pins an absolute URL to the canonical host.
   *
   * A no-op when no canonical base is configured.
   */
  private function pin(string $url): string {
    return CanonicalHost::rewrite($url, $this->canonicalBase());
  }

  /**
   * Builds every JSON-LD block applicable to the current request.
   *
   * @return array{blocks: array<int, array{0: array, 1: string}>, cache: \Drupal\Core\Cache\CacheableMetadata}
   *   Tuple list ready for #attached/html_head plus accumulated cacheability.
   */
  public function build(): array {
    $blocks = [];
    $cache = new CacheableMetadata();
    // Anything we emit varies per route + path + interface language.
    $cache->addCacheContexts(['route', 'url.path', 'languages:language_interface']);

    if ($this->adminContext->isAdminRoute()) {
      return ['blocks' => $blocks, 'cache' => $cache];
    }

    foreach ([
      $this->buildWebSiteSearch(),
      $this->buildBreadcrumbList(),
      $this->buildForCurrentNode(),
    ] as $result) {
      if ($result === NULL) {
        continue;
      }
      foreach ($result['blocks'] as $tuple) {
        $blocks[] = $tuple;
      }
      $cache = $cache->merge($result['cache']);
    }

    return ['blocks' => $blocks, 'cache' => $cache];
  }

  /**
   * Builds the WebSite + SearchAction graph for the front page.
   *
   * Lives here because schema_metatag's serialized potentialAction does not
   * reliably round-trip through SchemaMetatagManager into the rendered JSON-LD.
   */
  private function buildWebSiteSearch(): ?array {
    if (!$this->pathMatcher->isFrontPage()) {
      return NULL;
    }

    $schema = [
      '@context' => 'https://schema.org',
      '@type' => 'WebSite',
      '@id' => self::WEBSITE_ID,
      'name' => 'Idaho Legal Aid Services',
      'url' => 'https://idaholegalaid.org',
      'description' => 'Free civil legal assistance for low-income Idahoans. Idaho Legal Aid Services provides legal help with family law, housing, public benefits, consumer rights, and more.',
      'publisher' => [
        '@type' => 'NGO',
        '@id' => self::ORG_ID,
      ],
      'inLanguage' => ['en', 'es'],
      'potentialAction' => [
        '@type' => 'SearchAction',
        'target' => [
          '@type' => 'EntryPoint',
          'urlTemplate' => 'https://idaholegalaid.org/search?keys={search_term_string}',
        ],
        'query-input' => 'required name=search_term_string',
      ],
    ];

    $cache = new CacheableMetadata();
    // Front-page WebSite is invariant once cached per route.
    $cache->addCacheContexts(['url.path']);

    return [
      'blocks' => [$this->makeBlock($schema, 'website_search_schema')],
      'cache' => $cache,
    ];
  }

  /**
   * Builds the BreadcrumbList graph from Drupal's breadcrumb manager.
   *
   * Returns NULL if the breadcrumb has fewer than two valid items (just "Home"
   * is not worth emitting).
   */
  private function buildBreadcrumbList(): ?array {
    $request = $this->requestStack->getCurrentRequest();
    if ($request === NULL) {
      return NULL;
    }

    $breadcrumb = $this->breadcrumbManager->build($this->routeMatch);
    $links = $breadcrumb->getLinks();

    $base_url = $this->canonicalBase() ?: $request->getSchemeAndHttpHost();
    $current_url = strtok($base_url . $request->getRequestUri(), '?');

    $items = [];
    $position = 1;
    $last_url = '';

    foreach ($links as $link) {
      $url = $link->getUrl();
      $text = $link->getText();
      $name = is_object($text) ? (string) $text : (string) $text;
      if ($name === '') {
        continue;
      }

      $absolute = '';
      if ($url->isRouted()) {
        $route_name = $url->getRouteName();
        if (in_array($route_name, ['<nolink>', '<none>', '<button>'], TRUE)) {
          continue;
        }
        $absolute = $this->pin($url->setAbsolute()->toString());
      }
      else {
        $uri = $url->toString();
        if ($uri !== '') {
          $absolute = str_starts_with($uri, 'http') ? $this->pin($uri) : $base_url . $uri;
        }
      }

      if ($absolute === '') {
        continue;
      }

      $items[] = [
        '@type' => 'ListItem',
        'position' => $position,
        'name' => $name,
        'item' => $absolute,
      ];
      $last_url = $absolute;
      $position++;
    }

    // Append the current page if the breadcrumb did not include it.
    $current_title = $this->extractCurrentPageTitle();
    if ($current_title !== '' && $last_url !== $current_url) {
      $items[] = [
        '@type' => 'ListItem',
        'position' => $position,
        'name' => $current_title,
        'item' => $current_url,
      ];
    }

    if (count($items) < 2) {
      return NULL;
    }

    $schema = [
      '@context' => 'https://schema.org',
      '@type' => 'BreadcrumbList',
      '@id' => $current_url . '#breadcrumb',
      'itemListElement' => $items,
    ];

    $cache = new CacheableMetadata();
    $cache->addCacheContexts(['url.path']);
    $cache->addCacheableDependency($breadcrumb);

    return [
      'blocks' => [$this->makeBlock($schema, 'breadcrumb_schema')],
      'cache' => $cache,
    ];
  }

  /**
   * Resolves the current page's title from the Drupal title resolver.
   *
   * Falls back to the empty string if no title is available.
   */
  private function extractCurrentPageTitle(): string {
    $route = $this->routeMatch->getRouteObject();
    $request = $this->requestStack->getCurrentRequest();
    if ($route === NULL || $request === NULL) {
      return '';
    }

    try {
      $title = $this->titleResolver->getTitle($request, $route);
    }
    catch (\Throwable $e) {
      return '';
    }

    if (is_array($title) && isset($title['#markup'])) {
      $title = $title['#markup'];
    }

    if (is_object($title) && method_exists($title, '__toString')) {
      $title = (string) $title;
    }

    return is_string($title) ? trim(strip_tags($title)) : '';
  }

  /**
   * Routes the current node, if any, to the matching node-level builder.
   *
   * Returns combined blocks + cacheability across all node-level emitters
   * (FAQ, JobPostings, LearningResource).
   */
  private function buildForCurrentNode(): ?array {
    $node = $this->routeMatch->getParameter('node');
    if (!$node instanceof NodeInterface) {
      return NULL;
    }

    $blocks = [];
    $cache = new CacheableMetadata();
    $cache->addCacheableDependency($node);

    foreach ([
      $this->buildFaqPage($node),
      $this->buildJobPostings($node),
      $this->buildLearningResource($node),
    ] as $result) {
      if ($result === NULL) {
        continue;
      }
      foreach ($result['blocks'] as $tuple) {
        $blocks[] = $tuple;
      }
      $cache = $cache->merge($result['cache']);
    }

    if ($blocks === []) {
      // Still bubble the node cache tag because we inspected it.
      return ['blocks' => [], 'cache' => $cache];
    }

    return ['blocks' => $blocks, 'cache' => $cache];
  }

  /**
   * Builds a single aggregated FAQPage from every faq_smart_section paragraph
   * referenced by any field on the given node.
   *
   * Emitting one FAQPage per node (instead of one per paragraph) avoids the
   * Search Console "duplicate FAQPage entity" issue the inline Twig caused.
   */
  private function buildFaqPage(NodeInterface $node): ?array {
    $faq_paragraphs = $this->collectParagraphsByBundle($node, 'faq_smart_section');
    if ($faq_paragraphs === []) {
      return NULL;
    }

    $cache = new CacheableMetadata();
    $main_entity = [];

    foreach ($faq_paragraphs as $faq_section) {
      $cache->addCacheableDependency($faq_section);
      if (!$faq_section->hasField('field_faq_items') || $faq_section->get('field_faq_items')->isEmpty()) {
        continue;
      }
      foreach ($faq_section->get('field_faq_items')->referencedEntities() as $item) {
        if (!$item instanceof ParagraphInterface) {
          continue;
        }
        $cache->addCacheableDependency($item);
        $question = $item->hasField('field_faq_question') ? (string) $item->get('field_faq_question')->value : '';
        $answer = $item->hasField('field_faq_answer') ? (string) $item->get('field_faq_answer')->value : '';
        if ($question === '' || $answer === '') {
          continue;
        }
        $main_entity[] = [
          '@type' => 'Question',
          'name' => $question,
          'acceptedAnswer' => [
            '@type' => 'Answer',
            'text' => $answer,
          ],
        ];
      }
    }

    if ($main_entity === []) {
      return NULL;
    }

    $schema = [
      '@context' => 'https://schema.org',
      '@type' => 'FAQPage',
      '@id' => $this->absoluteNodeUrl($node) . '#faq',
      'mainEntity' => $main_entity,
    ];

    return [
      'blocks' => [$this->makeBlock($schema, 'faq_page_schema')],
      'cache' => $cache,
    ];
  }

  /**
   * Builds the JobPosting @graph for an employment node.
   *
   * Walks field_job_listings → field_category_jobs to gather every paragraph
   * with a field_job_date_posted value.
   */
  private function buildJobPostings(NodeInterface $node): ?array {
    if ($node->bundle() !== 'employment') {
      return NULL;
    }
    if (!$node->hasField('field_job_listings') || $node->get('field_job_listings')->isEmpty()) {
      return NULL;
    }

    $cache = new CacheableMetadata();
    $page_url = $this->absoluteNodeUrl($node);
    $hiring_org = [
      '@type' => 'Organization',
      '@id' => self::ORG_ID,
      'name' => 'Idaho Legal Aid Services',
      'sameAs' => 'https://idaholegalaid.org',
      'logo' => self::ORG_LOGO,
    ];

    $postings = [];
    foreach ($node->get('field_job_listings')->referencedEntities() as $cat_index => $category) {
      if (!$category instanceof ParagraphInterface) {
        continue;
      }
      $cache->addCacheableDependency($category);
      if (!$category->hasField('field_category_jobs') || $category->get('field_category_jobs')->isEmpty()) {
        continue;
      }
      foreach ($category->get('field_category_jobs')->referencedEntities() as $job_index => $job) {
        if (!$job instanceof ParagraphInterface) {
          continue;
        }
        $cache->addCacheableDependency($job);
        $date_posted = $job->hasField('field_job_date_posted') ? $job->get('field_job_date_posted')->value : NULL;
        if (empty($date_posted)) {
          continue;
        }

        $work_arrangement = $job->hasField('field_job_work_arrangement') ? (string) $job->get('field_job_work_arrangement')->value : '';
        $title = $job->hasField('field_accordion_title') ? (string) $job->get('field_accordion_title')->value : '';
        $description = $job->hasField('field_accordion_body') ? (string) $job->get('field_accordion_body')->value : '';

        $posting = [
          '@type' => 'JobPosting',
          '@id' => $page_url . '#job-' . ($cat_index + 1) . '-' . ($job_index + 1),
          'title' => $title,
          'description' => $description,
          'datePosted' => $date_posted,
          'directApply' => TRUE,
          'hiringOrganization' => $hiring_org,
        ];

        if ($work_arrangement === 'hybrid') {
          $posting['jobLocationType'] = 'TELECOMMUTE';
          $posting['applicantLocationRequirements'] = [
            ['@type' => 'AdministrativeArea', 'name' => 'Idaho, USA'],
            ['@type' => 'Country', 'name' => 'US'],
          ];
        }

        $valid_through = $job->hasField('field_job_valid_through') ? $job->get('field_job_valid_through')->value : NULL;
        if (!empty($valid_through)) {
          $posting['validThrough'] = $valid_through;
        }

        $location = $job->hasField('field_job_location') ? (string) $job->get('field_job_location')->value : '';
        if ($location !== '') {
          $posting['jobLocation'] = [
            '@type' => 'Place',
            'address' => [
              '@type' => 'PostalAddress',
              'addressLocality' => $location,
              'addressRegion' => 'ID',
              'addressCountry' => 'US',
            ],
          ];
        }

        $emp_type = $job->hasField('field_job_employment_type') ? (string) $job->get('field_job_employment_type')->value : '';
        if ($emp_type !== '') {
          $posting['employmentType'] = $emp_type;
        }

        $postings[] = $posting;
      }
    }

    if ($postings === []) {
      return NULL;
    }

    $schema = [
      '@context' => 'https://schema.org',
      '@graph' => $postings,
    ];

    return [
      'blocks' => [$this->makeBlock($schema, 'job_posting_schema')],
      'cache' => $cache,
    ];
  }

  /**
   * Builds the LearningResource schema for an adept_lesson node.
   *
   * Replaces the inline Twig block whose |e('html_attr') escaping produced
   * invalid HTML-entity-encoded values inside JSON.
   */
  private function buildLearningResource(NodeInterface $node): ?array {
    if ($node->bundle() !== 'adept_lesson') {
      return NULL;
    }

    $page_url = $this->absoluteNodeUrl($node);
    $title = trim(strip_tags((string) $node->label()));

    $description = '';
    if ($node->hasField('field_adept_short_desc') && !$node->get('field_adept_short_desc')->isEmpty()) {
      $raw = (string) $node->get('field_adept_short_desc')->value;
      $description = mb_substr(trim(strip_tags($raw)), 0, 200);
    }

    $module_id = '';
    if ($node->hasField('field_adept_module_id') && !$node->get('field_adept_module_id')->isEmpty()) {
      $module_id = (string) $node->get('field_adept_module_id')->value;
    }

    $est_time = NULL;
    if ($node->hasField('field_adept_est_time') && !$node->get('field_adept_est_time')->isEmpty()) {
      $est_time = (int) $node->get('field_adept_est_time')->value;
    }

    $schema = [
      '@context' => 'https://schema.org',
      '@type' => 'LearningResource',
      '@id' => $page_url . '#learning-resource',
      'name' => $title,
      'description' => $description,
      'provider' => [
        '@type' => 'Organization',
        '@id' => self::ORG_ID,
        'name' => 'Idaho Legal Aid Services',
      ],
      'creator' => [
        '@type' => 'Organization',
        'name' => 'UC Davis MIND Institute',
      ],
      'educationalLevel' => 'Professional Development',
      'learningResourceType' => 'Interactive Module',
      'inLanguage' => 'en',
      'isPartOf' => [
        '@type' => 'Course',
        'name' => 'ADEPT Module ' . $module_id,
      ],
      'url' => $page_url,
    ];
    if ($est_time !== NULL && $est_time > 0) {
      $schema['timeRequired'] = 'PT' . $est_time . 'M';
    }

    $cache = new CacheableMetadata();
    return [
      'blocks' => [$this->makeBlock($schema, 'learning_resource_schema')],
      'cache' => $cache,
    ];
  }

  /**
   * Walks every entity_reference_revisions field on $node and returns
   * paragraphs of the requested bundle, recursing one level into nested
   * paragraph fields.
   *
   * @return \Drupal\paragraphs\ParagraphInterface[]
   */
  private function collectParagraphsByBundle(NodeInterface $node, string $bundle): array {
    $found = [];
    $field_definitions = $this->entityFieldManager->getFieldDefinitions('node', $node->bundle());
    foreach ($field_definitions as $field_name => $definition) {
      if ($definition->getType() !== 'entity_reference_revisions') {
        continue;
      }
      if (!$node->hasField($field_name) || $node->get($field_name)->isEmpty()) {
        continue;
      }
      foreach ($node->get($field_name)->referencedEntities() as $referenced) {
        if (!$referenced instanceof ParagraphInterface) {
          continue;
        }
        if ($referenced->bundle() === $bundle) {
          $found[$referenced->id()] = $referenced;
          continue;
        }
        // Recurse one level into nested paragraph references.
        foreach ($this->collectParagraphsFromParagraph($referenced, $bundle) as $nested) {
          $found[$nested->id()] = $nested;
        }
      }
    }
    return array_values($found);
  }

  /**
   * @return \Drupal\paragraphs\ParagraphInterface[]
   */
  private function collectParagraphsFromParagraph(ParagraphInterface $paragraph, string $bundle): array {
    $found = [];
    $field_definitions = $this->entityFieldManager->getFieldDefinitions('paragraph', $paragraph->bundle());
    foreach ($field_definitions as $field_name => $definition) {
      if ($definition->getType() !== 'entity_reference_revisions') {
        continue;
      }
      if (!$paragraph->hasField($field_name) || $paragraph->get($field_name)->isEmpty()) {
        continue;
      }
      foreach ($paragraph->get($field_name)->referencedEntities() as $referenced) {
        if (!$referenced instanceof ParagraphInterface) {
          continue;
        }
        if ($referenced->bundle() === $bundle) {
          $found[$referenced->id()] = $referenced;
        }
      }
    }
    return array_values($found);
  }

  /**
   * Returns the absolute, query-stripped canonical URL for the given node.
   */
  private function absoluteNodeUrl(NodeInterface $node): string {
    return $this->pin($node->toUrl('canonical', ['absolute' => TRUE])->toString());
  }

  /**
   * Wraps a schema array into a #attached/html_head tuple.
   *
   * @param array $schema
   *   The decoded JSON-LD payload.
   * @param string $key
   *   The Drupal html_head dedup key (must be unique per page).
   *
   * @return array{0: array, 1: string}
   */
  private function makeBlock(array $schema, string $key): array {
    return [
      [
        '#type' => 'html_tag',
        '#tag' => 'script',
        '#attributes' => ['type' => 'application/ld+json'],
        '#value' => Json::encode($schema),
      ],
      $key,
    ];
  }

}
