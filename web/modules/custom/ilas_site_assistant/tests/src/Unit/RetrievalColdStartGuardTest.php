<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use Drupal\ilas_site_assistant\Service\FaqIndex;
use Drupal\ilas_site_assistant\Service\ResourceFinder;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Guards against retrieval request paths regressing to corpus-sized cold loads.
 */
#[Group('ilas_site_assistant')]
final class RetrievalColdStartGuardTest extends TestCase {

  /**
   * Resource sparse-result topic fill must not use full resource preload.
   */
  public function testResourceSparseTopicFillUsesBoundedCandidates(): void {
    $finder = new ColdStartGuardResourceFinder(
      new ColdStartSearchIndex([
        new ColdStartSearchResultItem([
          'id' => 10,
          'title' => 'Eviction overview',
          'description' => 'Eviction resource summary',
          'topics' => ['housing'],
          'type' => 'resource',
        ], 42.0),
      ]),
      ['id' => 91, 'name' => 'Eviction'],
      [
        11 => $this->buildIndexedResource(11, 'Eviction form packet', 'form', [91], ['eviction']),
        12 => $this->buildIndexedResource(12, 'Eviction hearing checklist', 'guide', [91], ['eviction']),
      ],
      [],
      [],
    );

    $results = $finder->findResources('eviction', 3);

    $this->assertSame([10, 11, 12], array_column($results, 'id'));
    $this->assertCount(1, $finder->candidateLoadCalls);
    $this->assertSame([
      'query' => '',
      'limit' => 2,
      'topic_id' => 91,
      'service_area_id' => NULL,
    ], $finder->candidateLoadCalls[0]);
    $this->assertSame(0, $finder->fullLoadAttempts);
  }

  /**
   * Resource legacy fallback must not use full resource preload.
   */
  public function testResourceLegacyFallbackUsesBoundedCandidates(): void {
    $finder = new ColdStartGuardResourceFinder(
      NULL,
      ['id' => 44, 'name' => 'Eviction'],
      [],
      [
        21 => $this->buildIndexedResource(21, 'Eviction forms', 'form', [44], ['eviction']),
        22 => $this->buildIndexedResource(22, 'Housing guide', 'guide', [44], ['eviction']),
        23 => $this->buildIndexedResource(23, 'Benefits overview', 'resource', [5], ['benefits']),
      ],
      [],
    );

    $results = $finder->findResources('eviction', 2);

    $this->assertSame([21, 22], array_column($results, 'id'));
    $this->assertCount(1, $finder->candidateLoadCalls);
    $this->assertSame([
      'query' => 'eviction',
      'limit' => 2,
      'topic_id' => 44,
      'service_area_id' => NULL,
    ], $finder->candidateLoadCalls[0]);
    $this->assertSame(0, $finder->fullLoadAttempts);
  }

  /**
   * Service-area lookup must not use full resource preload.
   */
  public function testResourceServiceAreaLookupUsesBoundedCandidates(): void {
    $finder = new ColdStartGuardResourceFinder(
      NULL,
      NULL,
      [],
      [],
      [
        31 => $this->buildIndexedResource(31, 'Consumer debt worksheet', 'form', [], [], 77),
        32 => $this->buildIndexedResource(32, 'Debt collection guide', 'guide', [], [], 77),
      ],
    );

    $results = $finder->findByServiceArea(77, 2);

    $this->assertSame([31, 32], array_column($results, 'id'));
    $this->assertCount(1, $finder->candidateLoadCalls);
    $this->assertSame([
      'query' => '',
      'limit' => 2,
      'topic_id' => NULL,
      'service_area_id' => 77,
    ], $finder->candidateLoadCalls[0]);
    $this->assertSame(0, $finder->fullLoadAttempts);
  }

  /**
   * FAQ legacy fallback must not use full FAQ preload.
   */
  public function testFaqLegacyFallbackUsesBoundedCandidates(): void {
    $faq = new ColdStartGuardFaqIndex([
      'faq_10' => $this->buildFaqItem('faq_10', 'Eviction notice', 'Eviction notice deadlines and service rules.'),
      'faq_11' => $this->buildFaqItem('faq_11', 'Housing repairs', 'Repair requests and notice templates.'),
      'faq_12' => $this->buildFaqItem('faq_12', 'Consumer debt', 'Debt collection rights.'),
    ]);

    $results = $faq->search('eviction', 2);

    $this->assertSame(['faq_10'], array_column($results, 'id'));
    $this->assertCount(1, $faq->candidateLoadCalls);
    $this->assertSame([
      'query' => 'eviction',
      'limit' => 2,
    ], $faq->candidateLoadCalls[0]);
    $this->assertSame(0, $faq->fullLoadAttempts);
  }

  /**
   * Resource candidate limits remain bounded.
   */
  public function testResourceLegacyCandidateLimitsAreBounded(): void {
    $finder = new ColdStartGuardResourceFinder();

    $this->assertSame(20, $finder->exposeLegacyCandidateLimit(1));
    $this->assertSame(80, $finder->exposeLegacyCandidateLimit(10));
    $this->assertSame(100, $finder->exposeLegacyCandidateLimit(20));
  }

  /**
   * FAQ candidate limits remain bounded.
   */
  public function testFaqLegacyCandidateLimitsAreBounded(): void {
    $faq = new ColdStartGuardFaqIndex([]);

    $this->assertSame(20, $faq->exposeLegacyCandidateLimit(1));
    $this->assertSame(80, $faq->exposeLegacyCandidateLimit(10));
    $this->assertSame(100, $faq->exposeLegacyCandidateLimit(20));
  }

  /**
   * Builds indexed resource metadata for test doubles.
   */
  private function buildIndexedResource(
    int $id,
    string $title,
    string $type,
    array $topicIds,
    array $topicNames,
    ?int $serviceAreaId = NULL,
  ): array {
    $description = $title . ' description';
    $keywords = array_values(array_unique(array_filter([
      ...preg_split('/\s+/', strtolower($title)) ?: [],
      ...$topicNames,
    ])));

    return [
      'id' => $id,
      'title' => $title,
      'title_lower' => strtolower($title),
      'url' => '/node/' . $id,
      'source_url' => '/node/' . $id,
      'updated_at' => 1700000000 + $id,
      'topics' => $topicIds,
      'topic_names' => $topicNames,
      'service_areas' => $serviceAreaId === NULL ? [] : [['id' => $serviceAreaId, 'name' => 'Area ' . $serviceAreaId]],
      'type' => $type,
      'has_file' => FALSE,
      'has_link' => FALSE,
      'description' => $description,
      'keywords' => $keywords,
    ];
  }

  /**
   * Builds a FAQ item candidate for legacy fallback tests.
   */
  private function buildFaqItem(string $id, string $question, string $answer): array {
    return [
      'id' => $id,
      'question' => $question,
      'title' => $question,
      'answer' => $answer,
      'source' => 'lexical',
      'url' => '/faq#' . $id,
      'source_url' => '/faq#' . $id,
      'category' => 'Housing',
    ];
  }

}

/**
 * ResourceFinder test double that records bounded candidate loads.
 */
class ColdStartGuardResourceFinder extends ResourceFinder {

  public int $fullLoadAttempts = 0;

  public array $candidateLoadCalls = [];

  /**
   * @param object|null $index
   *   Optional Search API index double.
   * @param array|null $resolved_topic
   *   Topic resolver output.
   * @param array $topic_candidates
   *   Candidates returned for topic-based lookups.
   * @param array $legacy_candidates
   *   Candidates returned for legacy query lookups.
   * @param array $service_area_candidates
   *   Candidates returned for service-area lookups.
   */
  public function __construct(
    private ?object $testIndex = NULL,
    ?array $resolved_topic = NULL,
    private array $topic_candidates = [],
    private array $legacy_candidates = [],
    private array $service_area_candidates = [],
  ) {
    $this->topicResolver = new class($resolved_topic) {
      public function __construct(private ?array $topic) {}
      public function resolveFromText(string $query): ?array {
        return $this->topic;
      }
    };
  }

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
    return $this->testIndex;
  }

  /**
   * {@inheritdoc}
   */
  public function getAllResources() {
    $this->fullLoadAttempts++;
    throw new \RuntimeException('Full resource preload should not be used in RAUD-22 cold-start tests.');
  }

  /**
   * {@inheritdoc}
   */
  protected function loadLegacyResourceCandidates(string $query, int $limit, ?int $topic_id = NULL, ?int $service_area_id = NULL): array {
    $this->candidateLoadCalls[] = [
      'query' => $query,
      'limit' => $limit,
      'topic_id' => $topic_id,
      'service_area_id' => $service_area_id,
    ];

    if ($service_area_id !== NULL) {
      return $this->service_area_candidates;
    }

    if ($topic_id !== NULL && $query === '') {
      return $this->topic_candidates;
    }

    return $this->legacy_candidates;
  }

  /**
   * {@inheritdoc}
   */
  protected function determineResourceType($node) {
    return $node['type'] ?? 'resource';
  }

  /**
   * {@inheritdoc}
   */
  protected function buildResourceItem($node) {
    return [
      'id' => $node['id'],
      'title' => $node['title'],
      'url' => $node['url'] ?? '/node/' . $node['id'],
      'source_url' => $node['source_url'] ?? '/node/' . $node['id'],
      'type' => $node['type'] ?? 'resource',
      'has_file' => $node['has_file'] ?? FALSE,
      'has_link' => $node['has_link'] ?? FALSE,
      'description' => $node['description'] ?? '',
      'topics' => $node['topics'] ?? [],
      'updated_at' => $node['updated_at'] ?? NULL,
      'source' => $node['source'] ?? 'lexical',
    ];
  }

  /**
   * Exposes the bounded candidate limit helper.
   */
  public function exposeLegacyCandidateLimit(int $limit): int {
    return $this->getLegacyCandidateLimit($limit);
  }

}

/**
 * FaqIndex test double that records bounded candidate loads.
 */
class ColdStartGuardFaqIndex extends FaqIndex {

  public int $fullLoadAttempts = 0;

  public array $candidateLoadCalls = [];

  /**
   * @param array $legacy_candidates
   *   Candidate items returned for legacy search.
   */
  public function __construct(
    private array $legacy_candidates,
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
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  protected function loadLegacySearchItems(string $query, int $limit): array {
    $this->candidateLoadCalls[] = [
      'query' => $query,
      'limit' => $limit,
    ];
    return $this->legacy_candidates;
  }

  /**
   * {@inheritdoc}
   */
  protected function getAllFaqsLegacy() {
    $this->fullLoadAttempts++;
    throw new \RuntimeException('Full FAQ preload should not be used in RAUD-22 cold-start tests.');
  }

  /**
   * Exposes the bounded candidate limit helper.
   */
  public function exposeLegacyCandidateLimit(int $limit): int {
    return $this->getLegacyCandidateLimit($limit);
  }

}

/**
 * Search API index double with deterministic result items.
 */
final class ColdStartSearchIndex {

  /**
   * @param array $items
   *   Search result items.
   */
  public function __construct(private array $items) {}

  public function status(): bool {
    return TRUE;
  }

  public function query(): ColdStartSearchQuery {
    return new ColdStartSearchQuery($this->items);
  }

}

/**
 * Search API query double.
 */
final class ColdStartSearchQuery {

  private int $offset = 0;

  private ?int $length = NULL;

  /**
   * @param array $items
   *   Search result items.
   */
  public function __construct(private array $items) {}

  public function keys(string $query): self {
    return $this;
  }

  public function addCondition(string $field, string|int $value): self {
    return $this;
  }

  public function range(int $start, int $length): self {
    $this->offset = $start;
    $this->length = $length;
    return $this;
  }

  public function execute(): ColdStartSearchResultSet {
    $items = $this->length === NULL
      ? $this->items
      : array_slice($this->items, $this->offset, $this->length);
    return new ColdStartSearchResultSet($items);
  }

}

/**
 * Search API result-set double.
 */
final class ColdStartSearchResultSet {

  /**
   * @param array $items
   *   Search result items.
   */
  public function __construct(private array $items) {}

  public function getResultItems(): array {
    return $this->items;
  }

}

/**
 * Search API result-item double.
 */
final class ColdStartSearchResultItem {

  /**
   * @param mixed $value
   *   The original object payload.
   * @param float $score
   *   The Search API score.
   */
  public function __construct(
    private mixed $value,
    private float $score,
  ) {}

  public function getOriginalObject(): object {
    return new class($this->value) {
      public function __construct(private mixed $value) {}
      public function getValue(): mixed {
        return $this->value;
      }
    };
  }

  public function getScore(): float {
    return $this->score;
  }

}
