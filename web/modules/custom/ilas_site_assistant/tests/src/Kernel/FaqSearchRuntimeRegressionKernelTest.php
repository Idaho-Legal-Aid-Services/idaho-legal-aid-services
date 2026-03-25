<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Kernel;

use Drupal\Core\Cache\MemoryBackend;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\ilas_site_assistant\Service\FaqIndex;
use Drupal\ilas_site_assistant\Service\RetrievalConfigurationService;
use Drupal\KernelTests\KernelTestBase;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\paragraphs\Entity\Paragraph;
use Drupal\paragraphs\Entity\ParagraphsType;
use Drupal\path_alias\Entity\PathAlias;
use PHPUnit\Framework\Attributes\Group;

/**
 * Exercises FAQ retrieval against real entities with mixed-language parents.
 */
#[Group('ilas_site_assistant')]
final class FaqSearchRuntimeRegressionKernelTest extends KernelTestBase {

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
    'language',
    'path',
    'path_alias',
    'node',
    'entity_reference_revisions',
    'paragraphs',
    'ilas_site_assistant',
  ];

  /**
   * English FAQ paragraph ID.
   */
  private int $englishFaqParagraphId;

  /**
   * Foreign-parent FAQ paragraph ID.
   */
  private int $foreignParentFaqParagraphId;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('paragraph');
    $this->installEntitySchema('path_alias');

    $this->createContentModel();
    $this->createLanguages();
    $this->configureRetrievalSettings();
    $this->createFaqFixture();
  }

  /**
   * The real FAQ path filters foreign-language parent scopes.
   */
  public function testRealFaqSearchFiltersForeignLanguageParentScope(): void {
    $faq_index = $this->buildFaqIndex();

    $results = $faq_index->search('eviction', 5);

    $this->assertCount(1, $results, 'Only same-language FAQ items should survive the runtime-equivalent lexical fixture.');
    $this->assertSame('/resources/evictions#eviction-deadlines', $results[0]['url']);
    $this->assertSame('/resources/evictions', $results[0]['parent_url']);
    $this->assertSame('FAQ English', $results[0]['category']);
    $this->assertSame('faq_' . $this->englishFaqParagraphId, $results[0]['id']);

    $this->assertNotNull($faq_index->getById('faq_' . $this->englishFaqParagraphId));
    $this->assertNull(
      $faq_index->getById('faq_' . $this->foreignParentFaqParagraphId),
      'Cross-language FAQ IDs must fail closed in the same runtime-equivalent fixture.',
    );

    $this->assertSame(
      [['name' => 'FAQ English', 'count' => 1]],
      $faq_index->getCategories(),
      'Browse/category mode must not leak foreign-language parent labels.',
    );
  }

  /**
   * The same lexical-hit fixture leaks without the post-parent language gate.
   */
  public function testEquivalentPreFixBehaviorLeaksForeignLanguageResult(): void {
    $faq_index = $this->buildFaqIndex(disable_language_gate: TRUE);

    $results = $faq_index->search('eviction', 5);
    $result_urls = array_column($results, 'url');
    $categories = array_column($faq_index->getCategories(), 'name');

    $this->assertCount(
      2,
      $results,
      'The recreated lexical-hit fixture must prove the old behavior still leaks the foreign-parent FAQ item.',
    );
    $this->assertContains('/resources/evictions#eviction-deadlines', $result_urls);
    $this->assertContains('/es/resources/desalojos#eviction-deadlines-es-parent', $result_urls);
    $this->assertContains('Preguntas frecuentes', $categories);
    $this->assertNotNull($faq_index->getById('faq_' . $this->foreignParentFaqParagraphId));
  }

  /**
   * Creates the bundles and fields needed for FAQ retrieval.
   */
  private function createContentModel(): void {
    NodeType::create([
      'type' => 'standard_page',
      'name' => 'Standard Page',
    ])->save();

    foreach (['faq_item' => 'FAQ Item', 'faq_smart_section' => 'FAQ Smart Section'] as $id => $label) {
      ParagraphsType::create([
        'id' => $id,
        'label' => $label,
      ])->save();
    }

    $this->createFieldStorage('paragraph', 'field_faq_question', 'string');
    $this->createFieldStorage('paragraph', 'field_faq_answer', 'string_long');
    $this->createFieldStorage('paragraph', 'field_anchor_id', 'string');
    $this->createFieldStorage('paragraph', 'field_faq_items', 'entity_reference_revisions', [
      'target_type' => 'paragraph',
    ], -1);
    $this->createFieldStorage('node', 'field_faq_section', 'entity_reference_revisions', [
      'target_type' => 'paragraph',
    ], -1);

    $this->createField('paragraph', 'faq_item', 'field_faq_question', 'FAQ Question');
    $this->createField('paragraph', 'faq_item', 'field_faq_answer', 'FAQ Answer');
    $this->createField('paragraph', 'faq_item', 'field_anchor_id', 'Anchor ID');
    $this->createField('paragraph', 'faq_smart_section', 'field_faq_items', 'FAQ Items', [
      'handler' => 'default:paragraph',
      'handler_settings' => [
        'target_bundles' => ['faq_item' => 'faq_item'],
      ],
    ]);
    $this->createField('node', 'standard_page', 'field_faq_section', 'FAQ Section', [
      'handler' => 'default:paragraph',
      'handler_settings' => [
        'target_bundles' => ['faq_smart_section' => 'faq_smart_section'],
      ],
    ]);
  }

  /**
   * Creates the languages used by the regression fixture.
   */
  private function createLanguages(): void {
    ConfigurableLanguage::createFromLangcode('es')->save();
    $this->container->get('language_manager')->reset();
  }

  /**
   * Creates one English FAQ and one English paragraph under a foreign parent.
   */
  private function createFaqFixture(): void {
    $this->englishFaqParagraphId = $this->createFaqNode(
      node_langcode: 'en',
      node_title: 'FAQ English',
      alias: '/resources/evictions',
      paragraph_anchor: 'eviction-deadlines',
      paragraph_question: 'Eviction deadlines',
    );

    $this->foreignParentFaqParagraphId = $this->createFaqNode(
      node_langcode: 'es',
      node_title: 'Preguntas frecuentes',
      alias: '/es/resources/desalojos',
      paragraph_anchor: 'eviction-deadlines-es-parent',
      paragraph_question: 'Eviction deadlines',
    );
  }

  /**
   * Creates one FAQ node and returns the child FAQ paragraph ID.
   */
  private function createFaqNode(
    string $node_langcode,
    string $node_title,
    string $alias,
    string $paragraph_anchor,
    string $paragraph_question,
  ): int {
    $faq_item = Paragraph::create([
      'type' => 'faq_item',
      'langcode' => 'en',
      'field_faq_question' => $paragraph_question,
      'field_faq_answer' => 'Eviction answer copy.',
      'field_anchor_id' => $paragraph_anchor,
    ]);
    $faq_item->save();

    $faq_section = Paragraph::create([
      'type' => 'faq_smart_section',
      'langcode' => $node_langcode,
      'field_faq_items' => [[
        'target_id' => $faq_item->id(),
        'target_revision_id' => $faq_item->getRevisionId(),
      ]],
    ]);
    $faq_section->save();

    $node = Node::create([
      'type' => 'standard_page',
      'title' => $node_title,
      'langcode' => $node_langcode,
      'status' => Node::PUBLISHED,
      'field_faq_section' => [[
        'target_id' => $faq_section->id(),
        'target_revision_id' => $faq_section->getRevisionId(),
      ]],
    ]);
    $node->save();

    PathAlias::create([
      'path' => '/node/' . $node->id(),
      'alias' => $alias,
      'langcode' => $node_langcode,
    ])->save();

    return (int) $faq_item->id();
  }

  /**
   * Seeds the minimum retrieval config needed by the FAQ service.
   */
  private function configureRetrievalSettings(): void {
    $this->config('ilas_site_assistant.settings')
      ->set('retrieval', [
        'faq_index_id' => 'faq_accordion',
      ])
      ->set('vector_search', [
        'enabled' => FALSE,
      ])
      ->save();
  }

  /**
   * Builds the FAQ service with an isolated cache backend.
   */
  private function buildFaqIndex(bool $disable_language_gate = FALSE): FaqIndex {
    $retrieval_configuration = new RetrievalConfigurationService(
      $this->container->get('config.factory'),
      $this->container->get('entity_type.manager'),
    );

    $class = $disable_language_gate
      ? BrokenFaqSearchRuntimeRegressionKernelDouble::class
      : KernelFaqSearchRuntimeRegressionKernelDouble::class;

    return new $class(
      $this->container->get('entity_type.manager'),
      new MemoryBackend($this->container->get('datetime.time')),
      $this->container->get('config.factory'),
      $this->container->get('language_manager'),
      $retrieval_configuration,
      new KernelFaqSearchRuntimeRegressionIndex($this->loadLexicalHitParagraphs()),
    );
  }

  /**
   * Loads the lexical-hit paragraph entities used by the query double.
   *
   * @return array<int, \Drupal\paragraphs\Entity\Paragraph>
   *   Paragraphs in the same raw-hit order the audited failure exposed.
   */
  private function loadLexicalHitParagraphs(): array {
    $storage = $this->container->get('entity_type.manager')->getStorage('paragraph');
    $paragraphs = $storage->loadMultiple([
      $this->englishFaqParagraphId,
      $this->foreignParentFaqParagraphId,
    ]);

    return [
      $paragraphs[$this->englishFaqParagraphId],
      $paragraphs[$this->foreignParentFaqParagraphId],
    ];
  }

  /**
   * Creates one field storage definition.
   */
  private function createFieldStorage(
    string $entity_type,
    string $field_name,
    string $type,
    array $settings = [],
    int $cardinality = 1,
  ): void {
    FieldStorageConfig::create([
      'field_name' => $field_name,
      'entity_type' => $entity_type,
      'type' => $type,
      'settings' => $settings,
      'cardinality' => $cardinality,
    ])->save();
  }

  /**
   * Creates one bundle field definition.
   */
  private function createField(
    string $entity_type,
    string $bundle,
    string $field_name,
    string $label,
    array $settings = [],
  ): void {
    FieldConfig::create([
      'entity_type' => $entity_type,
      'bundle' => $bundle,
      'field_name' => $field_name,
      'label' => $label,
      'settings' => $settings,
    ])->save();
  }

}

/**
 * Kernel-friendly FAQ service double backed by real paragraph entities.
 */
class KernelFaqSearchRuntimeRegressionKernelDouble extends FaqIndex {

  /**
   * Constructs the runtime-equivalent FAQ service double.
   */
  public function __construct(
    ...$args,
  ) {
    $index = array_pop($args);
    parent::__construct(...$args);
    $this->index = $index;
  }

  /**
   * {@inheritdoc}
   */
  protected function buildQueryCacheKey(string $query, int $limit, ?string $type): ?string {
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  protected function getFaqDependencySnapshot(): array {
    return [
      'dependency_key' => 'faq_index',
      'classification' => 'required',
      'active' => TRUE,
      'status' => 'healthy',
      'failure_code' => NULL,
    ];
  }

}

/**
 * Recreates the pre-fix behavior for regression-proof assertions only.
 */
final class BrokenFaqSearchRuntimeRegressionKernelDouble extends KernelFaqSearchRuntimeRegressionKernelDouble {

  /**
   * {@inheritdoc}
   */
  protected function parentInfoMatchesCurrentLanguage(array $parent_info): bool {
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  protected function itemMatchesCurrentLanguage(array $item): bool {
    return TRUE;
  }

}

/**
 * Minimal index double that yields real paragraph entities as lexical hits.
 */
final class KernelFaqSearchRuntimeRegressionIndex {

  /**
   * Constructs the index double.
   *
   * @param array<int, \Drupal\paragraphs\Entity\Paragraph> $paragraphs
   *   Real paragraph entities to expose as lexical hits.
   */
  public function __construct(
    private readonly array $paragraphs,
  ) {}

  /**
   * Matches Search API's enabled-index check.
   */
  public function status(): bool {
    return TRUE;
  }

  /**
   * Returns a query double over the real paragraph entities.
   */
  public function query(): KernelFaqSearchRuntimeRegressionQuery {
    return new KernelFaqSearchRuntimeRegressionQuery($this->paragraphs);
  }

}

/**
 * Minimal query double that reproduces the audited lexical-hit fixture.
 */
final class KernelFaqSearchRuntimeRegressionQuery {

  /**
   * Current query string.
   */
  private string $keys = '';

  /**
   * Current result range start.
   */
  private int $rangeStart = 0;

  /**
   * Current result range length.
   */
  private int $rangeLength = 1000;

  /**
   * Current query conditions.
   *
   * @var array<string, mixed>
   */
  private array $conditions = [];

  /**
   * Constructs the query double.
   *
   * @param array<int, \Drupal\paragraphs\Entity\Paragraph> $paragraphs
   *   Paragraph entities available to the lexical-hit fixture.
   */
  public function __construct(
    private readonly array $paragraphs,
  ) {}

  /**
   * Stores the fulltext search keys.
   */
  public function keys(string $query): self {
    $this->keys = mb_strtolower($query);
    return $this;
  }

  /**
   * Stores the result range.
   */
  public function range(int $start, int $length): self {
    $this->rangeStart = $start;
    $this->rangeLength = $length;
    return $this;
  }

  /**
   * Stores a query condition.
   */
  public function addCondition(string $field, mixed $value): self {
    $this->conditions[$field] = $value;
    return $this;
  }

  /**
   * Returns Search API-like result items wrapping real paragraphs.
   */
  public function execute(): KernelFaqSearchRuntimeRegressionResultSet {
    $matches = array_values(array_filter(
      $this->paragraphs,
      function (Paragraph $paragraph): bool {
        if (($this->conditions['paragraph_type'] ?? NULL) !== NULL && $paragraph->bundle() !== $this->conditions['paragraph_type']) {
          return FALSE;
        }

        if (($this->conditions['search_api_language'] ?? NULL) !== NULL && $paragraph->language()->getId() !== $this->conditions['search_api_language']) {
          return FALSE;
        }

        if ($this->keys === '') {
          return TRUE;
        }

        $question = mb_strtolower((string) ($paragraph->get('field_faq_question')->value ?? ''));
        $answer = mb_strtolower((string) ($paragraph->get('field_faq_answer')->value ?? ''));
        return str_contains($question, $this->keys) || str_contains($answer, $this->keys);
      },
    ));

    return new KernelFaqSearchRuntimeRegressionResultSet(array_slice($matches, $this->rangeStart, $this->rangeLength));
  }

}

/**
 * Minimal result-set double returning Search API-like result items.
 */
final class KernelFaqSearchRuntimeRegressionResultSet {

  /**
   * Constructs the result-set double.
   *
   * @param array<int, \Drupal\paragraphs\Entity\Paragraph> $paragraphs
   *   Real paragraph entities in result order.
   */
  public function __construct(
    private readonly array $paragraphs,
  ) {}

  /**
   * Returns Search API-like result items.
   *
   * @return array<int, KernelFaqSearchRuntimeRegressionResultItem>
   *   Result items wrapping real paragraphs.
   */
  public function getResultItems(): array {
    return array_map(
      static fn(Paragraph $paragraph): KernelFaqSearchRuntimeRegressionResultItem => new KernelFaqSearchRuntimeRegressionResultItem($paragraph),
      $this->paragraphs,
    );
  }

}

/**
 * Minimal Search API result item double wrapping a real paragraph entity.
 */
final class KernelFaqSearchRuntimeRegressionResultItem {

  /**
   * Constructs the result item double.
   */
  public function __construct(
    private readonly Paragraph $paragraph,
  ) {}

  /**
   * Returns a Search API-like original object wrapper.
   */
  public function getOriginalObject(): object {
    return new class($this->paragraph) {

      /**
       * Constructs the original-object wrapper.
       */
      public function __construct(
        private readonly Paragraph $paragraph,
      ) {}

      /**
       * Returns the wrapped paragraph entity.
       */
      public function getValue(): Paragraph {
        return $this->paragraph;
      }

    };
  }

}
