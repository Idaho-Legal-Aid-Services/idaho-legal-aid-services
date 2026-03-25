<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use Drupal\ilas_site_assistant\Exception\RetrievalDependencyUnavailableException;
use Drupal\ilas_site_assistant\Service\FaqIndex;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Guards FAQ retrieval against mixed-language leakage.
 */
#[Group('ilas_site_assistant')]
final class FaqLanguageIsolationTest extends TestCase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $container = new ContainerBuilder();
    $container->set('logger.factory', new class {
      public function get(string $channel): NullLogger {
        return new NullLogger();
      }
    });

    \Drupal::setContainer($container);
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    \Drupal::unsetContainer();
    parent::tearDown();
  }

  /**
   * English lexical FAQ search drops foreign-language URLs.
   */
  public function testLexicalSearchDropsForeignLanguageUrlsForEnglish(): void {
    $faq = new LanguageIsolationFaqIndex(
      index_items: [
        $this->buildFaqItem('faq_641', '/es/resources/desalojos', 'Preguntas frecuentes'),
        $this->buildFaqItem('faq_343', '/resources/evictions', 'FAQ'),
        $this->buildFaqItem('faq_904', '/sw/resources/kufukuzwa', 'Maswali Yanayoulizwa Mara kwa Mara'),
        $this->buildFaqItem('faq_345', '/resources/evictions', 'FAQ', 'Self-Help Eviction Forms'),
      ],
    );

    $results = $faq->search('eviction', 5);

    $this->assertSame(['faq_343', 'faq_345'], array_column($results, 'id'));
    foreach ($results as $item) {
      $this->assertStringStartsWith('/resources/', (string) $item['parent_url']);
    }
  }

  /**
   * English legacy FAQ fallback drops foreign-language URLs.
   */
  public function testLegacySearchDropsForeignLanguageUrlsForEnglish(): void {
    $faq = new LanguageIsolationFaqIndex(
      legacy_items: [
        'faq_641' => $this->buildFaqItem('faq_641', '/es/resources/desalojos', 'Preguntas frecuentes'),
        'faq_343' => $this->buildFaqItem('faq_343', '/resources/evictions', 'FAQ'),
        'faq_904' => $this->buildFaqItem('faq_904', '/sw/resources/kufukuzwa', 'Maswali Yanayoulizwa Mara kwa Mara'),
      ],
    );

    $results = $faq->search('eviction', 5);

    $this->assertSame(['faq_343'], array_column($results, 'id'));
    $this->assertSame('/resources/evictions', $results[0]['parent_url']);
  }

  /**
   * English legacy fallback can safely return empty when only foreign URLs exist.
   */
  public function testLegacySearchReturnsEmptyWhenOnlyForeignLanguageItemsExist(): void {
    $faq = new LanguageIsolationFaqIndex(
      legacy_items: [
        'faq_641' => $this->buildFaqItem('faq_641', '/es/resources/desalojos', 'Preguntas frecuentes'),
        'faq_904' => $this->buildFaqItem('faq_904', '/sw/resources/kufukuzwa', 'Maswali Yanayoulizwa Mara kwa Mara'),
      ],
    );

    $this->assertSame([], $faq->search('eviction', 5));
  }

  /**
   * Cross-language FAQ IDs fail closed.
   */
  public function testGetByIdReturnsNullForForeignLanguageFaq(): void {
    $faq = new LanguageIsolationFaqIndex(
      paragraph_items: [
        641 => $this->buildFaqItem('faq_641', '/es/resources/desalojos', 'Preguntas frecuentes'),
        343 => $this->buildFaqItem('faq_343', '/resources/evictions', 'FAQ'),
      ],
    );

    $this->assertNull($faq->getById('faq_641'));
    $this->assertSame('faq_343', $faq->getById('faq_343')['id'] ?? NULL);
  }

  /**
   * Lexical category aggregation excludes foreign-language labels.
   */
  public function testLexicalCategoriesExcludeForeignLanguageLabels(): void {
    $faq = new LanguageIsolationFaqIndex(
      index_items: [
        $this->buildFaqItem('faq_641', '/es/resources/desalojos', 'Preguntas frecuentes'),
        $this->buildFaqItem('faq_343', '/resources/evictions', 'FAQ'),
        $this->buildFaqItem('faq_345', '/resources/evictions', 'FAQ', 'Self-Help Eviction Forms'),
        $this->buildFaqItem('faq_904', '/sw/resources/kufukuzwa', 'Maswali Yanayoulizwa Mara kwa Mara'),
      ],
    );

    $categories = $faq->getCategories();

    $this->assertSame([['name' => 'FAQ', 'count' => 2]], $categories);
  }

  /**
   * Legacy category aggregation excludes foreign-language labels.
   */
  public function testLegacyCategoriesExcludeForeignLanguageLabels(): void {
    $faq = new LanguageIsolationFaqIndex(
      legacy_all_items: [
        'faq_641' => $this->buildFaqItem('faq_641', '/es/resources/desalojos', 'Preguntas frecuentes'),
        'faq_343' => $this->buildFaqItem('faq_343', '/resources/evictions', 'FAQ'),
        'faq_345' => $this->buildFaqItem('faq_345', '/resources/evictions', 'FAQ', 'Self-Help Eviction Forms'),
        'faq_904' => $this->buildFaqItem('faq_904', '/sw/resources/kufukuzwa', 'Maswali Yanayoulizwa Mara kwa Mara'),
      ],
    );

    $categories = $faq->getCategories();

    $this->assertSame([['name' => 'FAQ', 'count' => 2]], $categories);
  }

  /**
   * Required FAQ dependency loss still fail-closes public read/search paths.
   */
  public function testRequiredFaqDependencyLossThrowsOnPublicPaths(): void {
    $faq = new LanguageIsolationFaqIndex(
      dependency_snapshot: [
        'dependency_key' => 'faq_index',
        'classification' => 'required',
        'active' => TRUE,
        'status' => 'degraded',
        'failure_code' => 'index_unavailable',
      ],
    );

    foreach ([
      static fn(LanguageIsolationFaqIndex $index): mixed => $index->search('eviction', 5),
      static fn(LanguageIsolationFaqIndex $index): mixed => $index->getById('faq_343'),
      static fn(LanguageIsolationFaqIndex $index): mixed => $index->getCategories(),
    ] as $operation) {
      try {
        $operation($faq);
        $this->fail('Expected retrieval dependency loss to throw.');
      }
      catch (RetrievalDependencyUnavailableException $exception) {
        $this->assertSame('faq', $exception->getService());
        $this->assertSame('faq_retrieval_unavailable', $exception->getReasonCode());
      }
    }
  }

  /**
   * Builds one FAQ payload.
   */
  private function buildFaqItem(
    string $id,
    string $parent_url,
    string $category,
    string $question = 'Evictions and Reasonable Accommodations',
  ): array {
    return [
      'id' => $id,
      'question' => $question,
      'title' => $question,
      'answer' => 'Eviction notice deadlines and service rules.',
      'type' => 'faq_item',
      'category' => $category,
      'parent_url' => $parent_url,
      'url' => $parent_url . '#anchor',
      'source_url' => $parent_url . '#anchor',
      'source' => 'lexical',
    ];
  }

}

/**
 * Test double exposing FAQ language filtering without Drupal bootstrap.
 */
final class LanguageIsolationFaqIndex extends FaqIndex {

  /**
   * @param array<int, array<string, mixed>> $index_items
   * @param array<string, array<string, mixed>> $legacy_items
   * @param array<string, array<string, mixed>> $legacy_all_items
   * @param array<int, array<string, mixed>> $paragraph_items
   */
  public function __construct(
    array $index_items = [],
    private array $legacy_items = [],
    private array $legacy_all_items = [],
    array $paragraph_items = [],
    private array $dependency_snapshot = [
      'dependency_key' => 'faq_index',
      'classification' => 'required',
      'active' => TRUE,
      'status' => 'healthy',
      'failure_code' => NULL,
    ],
  ) {
    $this->index = match (TRUE) {
      $index_items !== [] => new LanguageIsolationSearchIndex($index_items),
      $legacy_items !== [] || $legacy_all_items !== [] => new LanguageIsolationFailingSearchIndex(),
      default => new LanguageIsolationSearchIndex([]),
    };
    $this->languageManager = new class {
      public function getCurrentLanguage(): object {
        return new class {
          public function getId(): string {
            return 'en';
          }
        };
      }

      public function getDefaultLanguage(): object {
        return new class {
          public function getId(): string {
            return 'en';
          }
        };
      }

      public function getLanguages(): array {
        return [
          'en' => new class {},
          'es' => new class {},
          'sw' => new class {},
          'nl' => new class {},
        ];
      }
    };
    $this->entityTypeManager = new class($paragraph_items) {
      public function __construct(private array $paragraphItems) {}

      public function getStorage(string $entity_type_id): object {
        return new class($entity_type_id, $this->paragraphItems) {
          public function __construct(
            private string $entityTypeId,
            private array $paragraphItems,
          ) {}

          public function load(int|string $id): ?object {
            if ($this->entityTypeId !== 'paragraph') {
              return NULL;
            }

            if (!isset($this->paragraphItems[(int) $id])) {
              return NULL;
            }

            return new LanguageIsolationParagraph($this->paragraphItems[(int) $id]);
          }
        };
      }
    };
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
  protected function getCurrentLanguage() {
    return 'en';
  }

  /**
   * {@inheritdoc}
   */
  protected function getIndex() {
    return $this->index;
  }

  /**
   * {@inheritdoc}
   */
  protected function getFaqDependencySnapshot(): array {
    return $this->dependency_snapshot;
  }

  /**
   * {@inheritdoc}
   */
  protected function buildResultItem($result_item) {
    return $result_item->getPayload();
  }

  /**
   * {@inheritdoc}
   */
  protected function buildResultItemFromParagraph($paragraph) {
    return $paragraph instanceof LanguageIsolationParagraph ? $paragraph->payload : NULL;
  }

  /**
   * {@inheritdoc}
   */
  protected function loadLegacySearchItems(string $query, int $limit): array {
    return $this->legacy_items;
  }

  /**
   * {@inheritdoc}
   */
  protected function getAllFaqsLegacy() {
    return $this->legacy_all_items;
  }

}

/**
 * Fake Search API index for FAQ language-isolation tests.
 */
final class LanguageIsolationSearchIndex {

  /**
   * @param array<int, array<string, mixed>> $items
   */
  public function __construct(private array $items) {}

  public function status(): bool {
    return TRUE;
  }

  public function query(): LanguageIsolationSearchQuery {
    return new LanguageIsolationSearchQuery($this->items);
  }

}

/**
 * Fake Search API index that forces the legacy fallback path.
 */
final class LanguageIsolationFailingSearchIndex {

  public function status(): bool {
    return TRUE;
  }

  public function query(): object {
    return new class {
      public function keys(string $query): self {
        return $this;
      }

      public function range(int $start, int $length): self {
        return $this;
      }

      public function addCondition(string $field, string|int $value): self {
        return $this;
      }

      public function execute(): object {
        throw new \RuntimeException('Simulated Search API lexical query failure');
      }
    };
  }

}

/**
 * Fake Search API query for FAQ language-isolation tests.
 */
final class LanguageIsolationSearchQuery {

  private int $start = 0;
  private ?int $length = NULL;

  /**
   * @param array<int, array<string, mixed>> $items
   */
  public function __construct(private array $items) {}

  public function keys(string $query): self {
    return $this;
  }

  public function range(int $start, int $length): self {
    $this->start = $start;
    $this->length = $length;
    return $this;
  }

  public function addCondition(string $field, string|int $value): self {
    return $this;
  }

  public function execute(): LanguageIsolationSearchResultSet {
    return new LanguageIsolationSearchResultSet(
      array_slice($this->items, $this->start, $this->length),
    );
  }

}

/**
 * Fake Search API result set for FAQ language-isolation tests.
 */
final class LanguageIsolationSearchResultSet {

  /**
   * @param array<int, array<string, mixed>> $items
   */
  public function __construct(private array $items) {}

  public function getResultItems(): array {
    return array_map(
      static fn(array $payload): LanguageIsolationSearchResultItem => new LanguageIsolationSearchResultItem($payload),
      $this->items,
    );
  }

}

/**
 * Fake Search API result item for FAQ language-isolation tests.
 */
final class LanguageIsolationSearchResultItem {

  /**
   * @param array<string, mixed> $payload
   */
  public function __construct(private array $payload) {}

  /**
   * Returns the payload expected by the test double.
   */
  public function getPayload(): array {
    return $this->payload;
  }

}

/**
 * Fake paragraph for FAQ language-isolation tests.
 */
final class LanguageIsolationParagraph {

  /**
   * @param array<string, mixed> $payload
   */
  public function __construct(public array $payload) {}

}
