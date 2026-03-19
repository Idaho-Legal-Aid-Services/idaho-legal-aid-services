<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use Drupal\Component\Datetime\Time;
use Drupal\Core\Cache\MemoryBackend;
use Drupal\ilas_site_assistant\Service\FaqIndex;
use Drupal\ilas_site_assistant\Service\ResourceFinder;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Contract tests for deterministic dependency-failure degrade behavior.
 */
#[Group('ilas_site_assistant')]
class DependencyFailureDegradeContractTest extends TestCase {

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
   * Search API unavailable in FAQ path degrades to legacy retrieval.
   */
  public function testFaqSearchApiUnavailableFallsBackToLegacy(): void {
    $legacy = [['id' => 'faq_1', 'score' => 10.0, 'source' => 'legacy']];
    $faq = new ContractFaqIndex(NULL, $legacy);

    $result = $faq->search('eviction notice', 3);

    $this->assertSame($legacy, $result);
    $this->assertSame(1, $faq->legacyCallCount);
  }

  /**
   * Search API query exception in FAQ path degrades to legacy retrieval.
   */
  public function testFaqSearchApiQueryFailureFallsBackToLegacy(): void {
    $legacy = [['id' => 'faq_legacy', 'score' => 7.5, 'source' => 'legacy']];
    $index = new class {
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
          public function addCondition(string $field, string $value): self {
            return $this;
          }
          public function execute(): object {
            throw new \RuntimeException('Search backend timeout');
          }
        };
      }
    };

    $faq = new ContractFaqIndex($index, $legacy);

    $result = $faq->search('tenant rights', 3);

    $this->assertSame($legacy, $result);
    $this->assertSame(1, $faq->legacyCallCount);
  }

  /**
   * Search API unavailable in resource path degrades to legacy retrieval.
   */
  public function testResourceSearchApiUnavailableFallsBackToLegacy(): void {
    $legacy = [['id' => 101, 'score' => 9.0, 'source' => 'legacy']];
    $finder = new ContractResourceFinder(NULL, $legacy);

    $result = $finder->findResources('forms');

    $this->assertSame($legacy, $result);
    $this->assertSame(1, $finder->legacyCallCount);
  }

  /**
   * Search API query exception in resource path degrades to legacy retrieval.
   */
  public function testResourceSearchApiQueryFailureFallsBackToLegacy(): void {
    $legacy = [['id' => 202, 'score' => 8.0, 'source' => 'legacy']];
    $index = new class {
      public function id(): string {
        return 'assistant_resources';
      }
      public function status(): bool {
        return TRUE;
      }
      public function query(): object {
        return new class {
          public function keys(string $query): self {
            return $this;
          }
          public function addCondition(string $field, string|int $value): self {
            return $this;
          }
          public function range(int $start, int $length): self {
            return $this;
          }
          public function execute(): object {
            throw new \RuntimeException('Search API transport failure');
          }
        };
      }
    };

    $finder = new ContractResourceFinder($index, $legacy);

    $result = $finder->findResources('housing');

    $this->assertSame($legacy, $result);
    $this->assertSame(1, $finder->legacyCallCount);
  }

  /**
   * FAQ vector unavailable/failure preserves lexical results deterministically.
   */
  public function testFaqVectorUnavailablePreservesLexicalResults(): void {
    $lexical = [[
      'paragraph_id' => 1,
      'id' => 'faq_1',
      'score' => 5.0,
      'source' => 'lexical',
      'parent_url' => '/resources/evictions',
      'url' => '/resources/evictions#faq-1',
      'source_url' => '/resources/evictions#faq-1',
    ]];
    $faq = new ContractFaqIndex(
      NULL,
      [],
      ['enabled' => TRUE, 'fallback_threshold' => 2, 'min_lexical_score' => 0],
      []
    );

    $result = $faq->contractSupplement($lexical);

    $this->assertTrue($faq->vectorAttempted);
    $this->assertSame($lexical, $result);
  }

  /**
   * Resource vector unavailable/failure preserves lexical results deterministically.
   */
  public function testResourceVectorUnavailablePreservesLexicalResults(): void {
    $lexical = [['id' => 1, 'score' => 4.0, 'source' => 'lexical']];
    $finder = new ContractResourceFinder(
      NULL,
      [],
      ['enabled' => TRUE, 'fallback_threshold' => 2, 'min_lexical_score' => 0],
      []
    );

    $result = $finder->contractSupplement($lexical);

    $this->assertTrue($finder->vectorAttempted);
    $this->assertSame($lexical, $result);
  }

  /**
   * FAQ degraded vector outcomes avoid query caching and trigger backoff.
   */
  public function testFaqDegradedVectorOutcomeSkipsCacheAndBacksOffAcrossRequests(): void {
    $cache = new MemoryBackend(new Time());
    $lexical = [[
      'paragraph_id' => 1,
      'id' => 'faq_1',
      'score' => 5.0,
      'source' => 'lexical',
      'parent_url' => '/resources/evictions',
      'url' => '/resources/evictions#faq-1',
      'source_url' => '/resources/evictions#faq-1',
    ]];
    $vector_config = ['enabled' => TRUE, 'fallback_threshold' => 2, 'min_lexical_score' => 0];
    $index = new FakeFaqSearchIndex($lexical);

    $first = new CacheAwareFaqIndex($cache, $index, $vector_config, [[
      'attempted' => TRUE,
      'status' => 'degraded',
      'reason' => 'latency_budget_exceeded',
      'elapsed_ms' => 2501,
      'cacheable' => FALSE,
      'items' => [],
    ]]);

    $first_result = $first->search('eviction notice', 3);

    $this->assertSame($lexical, $first_result);
    $this->assertSame(1, $first->vectorAttemptCount);
    $this->assertSame('degraded', $first->lastVectorOutcome['status']);
    $this->assertFalse($cache->get($first->exposeQueryCacheKey('eviction notice', 3, NULL)));
    $this->assertNotFalse($cache->get($first->exposeBackoffCacheId()));

    $second = new CacheAwareFaqIndex($cache, $index, $vector_config, [[
      'attempted' => TRUE,
      'status' => 'healthy',
      'reason' => 'results_available',
      'elapsed_ms' => 100,
      'cacheable' => TRUE,
      'items' => [
        [
          'paragraph_id' => 99,
          'id' => 99,
          'score' => 90.0,
          'source' => 'vector',
          'parent_url' => '/resources/evictions',
          'url' => '/resources/evictions#faq-99',
          'source_url' => '/resources/evictions#faq-99',
        ],
      ],
    ]]);

    $second_result = $second->search('eviction notice', 3);

    $this->assertSame($lexical, $second_result);
    $this->assertSame(0, $second->vectorAttemptCount);
    $this->assertSame('backoff', $second->lastVectorOutcome['status']);
    $this->assertFalse($cache->get($second->exposeQueryCacheKey('eviction notice', 3, NULL)));
  }

  /**
   * FAQ healthy and policy-skipped outcomes still write the normal query cache.
   */
  public function testFaqHealthyAndPolicySkippedOutcomesRemainCacheable(): void {
    $healthy_cache = new MemoryBackend(new Time());
    $lexical = [[
      'paragraph_id' => 1,
      'id' => 'faq_1',
      'score' => 5.0,
      'source' => 'lexical',
      'parent_url' => '/resources/custody',
      'url' => '/resources/custody#faq-1',
      'source_url' => '/resources/custody#faq-1',
    ]];
    $healthy = new CacheAwareFaqIndex(
      $healthy_cache,
      new FakeFaqSearchIndex($lexical),
      ['enabled' => TRUE, 'fallback_threshold' => 2, 'min_lexical_score' => 0],
      [[
        'attempted' => TRUE,
        'status' => 'healthy',
        'reason' => 'results_available',
        'elapsed_ms' => 125,
        'cacheable' => TRUE,
        'items' => [
          [
            'paragraph_id' => 42,
            'id' => 42,
            'score' => 90.0,
            'source' => 'vector',
            'parent_url' => '/resources/custody',
            'url' => '/resources/custody#faq-42',
            'source_url' => '/resources/custody#faq-42',
          ],
        ],
      ]]
    );

    $healthy_result = $healthy->search('custody', 3);

    $this->assertCount(2, $healthy_result);
    $this->assertSame(1, $healthy->vectorAttemptCount);
    $this->assertNotFalse($healthy_cache->get($healthy->exposeQueryCacheKey('custody', 3, NULL)));

    $policy_cache = new MemoryBackend(new Time());
    $policy_skipped = new CacheAwareFaqIndex(
      $policy_cache,
      new FakeFaqSearchIndex($lexical),
      ['enabled' => FALSE],
      []
    );

    $policy_result = $policy_skipped->search('housing', 3);

    $this->assertSame($lexical, $policy_result);
    $this->assertSame(0, $policy_skipped->vectorAttemptCount);
    $this->assertNotFalse($policy_cache->get($policy_skipped->exposeQueryCacheKey('housing', 3, NULL)));
  }

  /**
   * Resource degraded vector outcomes avoid query caching and trigger backoff.
   */
  public function testResourceDegradedVectorOutcomeSkipsCacheAndBacksOffAcrossRequests(): void {
    $cache = new MemoryBackend(new Time());
    $lexical = [['id' => 1, 'score' => 4.0, 'source' => 'lexical']];
    $vector_config = ['enabled' => TRUE, 'fallback_threshold' => 2, 'min_lexical_score' => 0];

    $first = new CacheAwareResourceFinder($cache, $lexical, $vector_config, [[
      'attempted' => TRUE,
      'status' => 'degraded',
      'reason' => 'http_transport',
      'elapsed_ms' => 2100,
      'cacheable' => FALSE,
      'items' => [],
    ]]);

    $first_result = $first->findResources('forms');

    $this->assertSame($lexical, $first_result);
    $this->assertSame(1, $first->vectorAttemptCount);
    $this->assertSame('degraded', $first->lastVectorOutcome['status']);
    $this->assertFalse($cache->get($first->exposeQueryCacheKey('forms', NULL, 3)));
    $this->assertNotFalse($cache->get($first->exposeBackoffCacheId()));

    $second = new CacheAwareResourceFinder($cache, $lexical, $vector_config, [[
      'attempted' => TRUE,
      'status' => 'healthy',
      'reason' => 'results_available',
      'elapsed_ms' => 100,
      'cacheable' => TRUE,
      'items' => [
        ['id' => 9, 'score' => 80.0, 'source' => 'vector'],
      ],
    ]]);

    $second_result = $second->findResources('forms');

    $this->assertSame($lexical, $second_result);
    $this->assertSame(0, $second->vectorAttemptCount);
    $this->assertSame('backoff', $second->lastVectorOutcome['status']);
    $this->assertFalse($cache->get($second->exposeQueryCacheKey('forms', NULL, 3)));
  }

  /**
   * FAQ and resource backoff states do not interfere with each other.
   */
  public function testFaqBackoffDoesNotSuppressResourceVectorSearch(): void {
    $cache = new MemoryBackend(new Time());
    $faq = new CacheAwareFaqIndex(
      $cache,
      new FakeFaqSearchIndex([[
        'paragraph_id' => 1,
        'id' => 'faq_1',
        'score' => 5.0,
        'source' => 'lexical',
        'parent_url' => '/resources/evictions',
        'url' => '/resources/evictions#faq-1',
        'source_url' => '/resources/evictions#faq-1',
      ]]),
      ['enabled' => TRUE, 'fallback_threshold' => 2, 'min_lexical_score' => 0],
      [[
        'attempted' => TRUE,
        'status' => 'degraded',
        'reason' => 'index_unavailable',
        'elapsed_ms' => NULL,
        'cacheable' => FALSE,
        'items' => [],
      ]]
    );

    $faq->search('eviction notice', 3);

    $resource = new CacheAwareResourceFinder(
      $cache,
      [['id' => 1, 'score' => 4.0, 'source' => 'lexical']],
      ['enabled' => TRUE, 'fallback_threshold' => 2, 'min_lexical_score' => 0],
      [[
        'attempted' => TRUE,
        'status' => 'healthy',
        'reason' => 'results_available',
        'elapsed_ms' => 95,
        'cacheable' => TRUE,
        'items' => [
          ['id' => 9, 'score' => 80.0, 'source' => 'vector'],
        ],
      ]]
    );

    $result = $resource->findResources('guides');

    $this->assertCount(2, $result);
    $this->assertSame(1, $resource->vectorAttemptCount);
    $this->assertSame('healthy', $resource->lastVectorOutcome['status']);
  }

}

/**
 * FAQ contract test double with deterministic legacy/vector controls.
 */
class ContractFaqIndex extends FaqIndex {

  public int $legacyCallCount = 0;
  public bool $vectorAttempted = FALSE;

  /**
   * @param mixed $index
   */
  public function __construct(
    private mixed $contractIndex,
    private array $legacyResults,
    private array $vectorConfig = ['enabled' => FALSE],
    private array $vectorResults = [],
  ) {}

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
    return $this->contractIndex;
  }

  /**
   * {@inheritdoc}
   */
  protected function searchLegacy(string $query, int $limit) {
    $this->legacyCallCount++;
    return array_slice($this->legacyResults, 0, $limit);
  }

  /**
   * {@inheritdoc}
   */
  protected function getVectorSearchConfig(): array {
    return $this->vectorConfig;
  }

  /**
   * {@inheritdoc}
   */
  protected function searchVector(string $query, int $limit, ?string $type = NULL): array {
    $this->vectorAttempted = TRUE;
    return $this->vectorResults;
  }

  /**
   * Exposes protected vector supplement method for contract assertions.
   */
  public function contractSupplement(array $lexical): array {
    return $this->supplementWithVectorResults($lexical, 'contract query', 3, NULL);
  }

}

/**
 * Resource finder contract test double with deterministic legacy/vector controls.
 */
class ContractResourceFinder extends ResourceFinder {

  public int $legacyCallCount = 0;
  public bool $vectorAttempted = FALSE;

  /**
   * @param mixed $index
   */
  public function __construct(
    private mixed $contractIndex,
    private array $legacyResults,
    private array $vectorConfig = ['enabled' => FALSE],
    private array $vectorResults = [],
  ) {}

  /**
   * {@inheritdoc}
   */
  protected function buildQueryCacheKey(string $query, ?string $type, int $limit): ?string {
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
    return $this->contractIndex;
  }

  /**
   * {@inheritdoc}
   */
  protected function findByTypeLegacy(string $query, ?string $type, int $limit) {
    $this->legacyCallCount++;
    return array_slice($this->legacyResults, 0, $limit);
  }

  /**
   * {@inheritdoc}
   */
  protected function getVectorSearchConfig(): array {
    return $this->vectorConfig;
  }

  /**
   * {@inheritdoc}
   */
  protected function findByTypeVector(string $query, ?string $type, int $limit): array {
    $this->vectorAttempted = TRUE;
    return $this->vectorResults;
  }

  /**
   * Exposes protected vector supplement method for contract assertions.
   */
  public function contractSupplement(array $lexical): array {
    return $this->supplementWithVectorResults($lexical, 'contract query', NULL, 3);
  }

}

/**
 * FAQ contract double that exercises normal query-cache behavior.
 */
class CacheAwareFaqIndex extends FaqIndex {

  public int $vectorAttemptCount = 0;
  public array $lastVectorOutcome = [];

  /**
   * @param mixed $index
   */
  public function __construct(
    private MemoryBackend $testCache,
    private mixed $searchIndex,
    private array $vectorConfig,
    private array $plannedVectorOutcomes,
  ) {
    $this->cache = $testCache;
  }

  /**
   * Exposes the deterministic query cache key.
   */
  public function exposeQueryCacheKey(string $query, int $limit, ?string $type): string {
    return $this->buildQueryCacheKey($query, $limit, $type);
  }

  /**
   * Exposes the FAQ vector backoff cache key.
   */
  public function exposeBackoffCacheId(): string {
    return self::VECTOR_BACKOFF_CACHE_ID;
  }

  /**
   * {@inheritdoc}
   */
  protected function buildQueryCacheKey(string $query, int $limit, ?string $type): ?string {
    return 'faq.search:' . md5($query . '|' . $limit . '|' . ($type ?? 'all'));
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
    return $this->searchIndex;
  }

  /**
   * {@inheritdoc}
   */
  protected function getVectorSearchConfig(): array {
    return $this->vectorConfig;
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
  protected function searchVector(string $query, int $limit, ?string $type = NULL): array {
    $backoff_until = $this->getVectorBackoffUntil();
    if ($backoff_until > time()) {
      return $this->lastVectorOutcome = $this->buildVectorOutcome(FALSE, 'backoff', 'backoff_active', [], NULL, FALSE) + [
        'backoff_until' => $backoff_until,
      ];
    }

    $this->vectorAttemptCount++;
    $planned = array_shift($this->plannedVectorOutcomes) ?? [];
    $outcome = $this->normalizeVectorOutcome($planned);
    if (($outcome['status'] ?? 'healthy') === 'degraded') {
      $this->activateVectorBackoff();
    }
    return $this->lastVectorOutcome = $outcome;
  }

}

/**
 * Resource contract double that exercises normal query-cache behavior.
 */
class CacheAwareResourceFinder extends ResourceFinder {

  public int $vectorAttemptCount = 0;
  public array $lastVectorOutcome = [];

  public function __construct(
    private MemoryBackend $testCache,
    private array $lexicalResults,
    private array $vectorConfig,
    private array $plannedVectorOutcomes,
  ) {
    $this->cache = $testCache;
  }

  /**
   * Exposes the deterministic query cache key.
   */
  public function exposeQueryCacheKey(string $query, ?string $type, int $limit): string {
    return $this->buildQueryCacheKey($query, $type, $limit);
  }

  /**
   * Exposes the resource vector backoff cache key.
   */
  public function exposeBackoffCacheId(): string {
    return self::VECTOR_BACKOFF_CACHE_ID;
  }

  /**
   * {@inheritdoc}
   */
  protected function buildQueryCacheKey(string $query, ?string $type, int $limit): ?string {
    return 'resource.search:' . md5($query . '|' . ($type ?? 'all') . '|' . $limit);
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
    return new class {
      public function status(): bool {
        return TRUE;
      }
    };
  }

  /**
   * {@inheritdoc}
   */
  public function isUsingDedicatedIndex(): bool {
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  protected function getVectorSearchConfig(): array {
    return $this->vectorConfig;
  }

  /**
   * {@inheritdoc}
   */
  protected function findByTypeSearchApi(string $query, ?string $type, int $limit) {
    return $this->supplementWithVectorResultsDetailed(array_slice($this->lexicalResults, 0, $limit), $query, $type, $limit);
  }

  /**
   * {@inheritdoc}
   */
  protected function findByTypeVector(string $query, ?string $type, int $limit): array {
    $backoff_until = $this->getVectorBackoffUntil();
    if ($backoff_until > time()) {
      return $this->lastVectorOutcome = $this->buildVectorOutcome(FALSE, 'backoff', 'backoff_active', [], NULL, FALSE) + [
        'backoff_until' => $backoff_until,
      ];
    }

    $this->vectorAttemptCount++;
    $planned = array_shift($this->plannedVectorOutcomes) ?? [];
    $outcome = $this->normalizeVectorOutcome($planned);
    if (($outcome['status'] ?? 'healthy') === 'degraded') {
      $this->activateVectorBackoff();
    }
    return $this->lastVectorOutcome = $outcome;
  }

}

/**
 * Fake FAQ Search API index for cache/backoff contract tests.
 */
final class FakeFaqSearchIndex {

  public function __construct(private array $items) {}

  public function status(): bool {
    return TRUE;
  }

  public function query(): FakeFaqSearchQuery {
    return new FakeFaqSearchQuery($this->items);
  }

}

/**
 * Fake FAQ Search API query for cache/backoff contract tests.
 */
final class FakeFaqSearchQuery {

  private int $start = 0;
  private ?int $length = NULL;

  public function __construct(private array $items) {}

  public function keys(string $query): self {
    return $this;
  }

  public function range(int $start, int $length): self {
    $this->start = $start;
    $this->length = $length;
    return $this;
  }

  public function addCondition(string $field, string $value): self {
    return $this;
  }

  public function execute(): FakeFaqSearchResultSet {
    return new FakeFaqSearchResultSet(array_slice($this->items, $this->start, $this->length));
  }

}

/**
 * Fake FAQ Search API result set for cache/backoff contract tests.
 */
final class FakeFaqSearchResultSet {

  public function __construct(private array $items) {}

  public function getResultItems(): array {
    return array_map(static fn(array $payload) => new FakeFaqSearchResultItem($payload), $this->items);
  }

}

/**
 * Fake FAQ Search API result item for cache/backoff contract tests.
 */
final class FakeFaqSearchResultItem {

  public function __construct(private array $payload) {}

  public function getPayload(): array {
    return $this->payload;
  }

}
