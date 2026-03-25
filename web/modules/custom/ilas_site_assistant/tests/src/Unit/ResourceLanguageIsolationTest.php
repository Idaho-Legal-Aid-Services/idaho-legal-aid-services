<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use Drupal\ilas_site_assistant\Service\ResourceFinder;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Guards resource retrieval against mixed-language leakage.
 *
 * Covers AFRP-14: language isolation across all ResourceFinder legacy
 * fallback paths (findByTypeLegacy, findByTopic, findByServiceArea).
 */
#[Group('ilas_site_assistant')]
final class ResourceLanguageIsolationTest extends TestCase {

  /**
   * Legacy resource search drops foreign-language URLs.
   */
  public function testFindByTypeLegacyDropsForeignLanguageResources(): void {
    $finder = new LanguageIsolationResourceFinder(
      legacy_candidates: [
        10 => $this->buildResourceCandidate(10, 'Eviction Forms', '/resources/eviction-forms'),
        11 => $this->buildResourceCandidate(11, 'Formularios de Desalojo', '/es/resources/formularios-de-desalojo'),
        12 => $this->buildResourceCandidate(12, 'Fomu za Kufukuzwa', '/sw/resources/fomu-za-kufukuzwa'),
      ],
    );

    $results = $finder->findResources('eviction', 5);

    $ids = array_column($results, 'id');
    $this->assertSame([10], $ids);
    $this->assertStringStartsWith('/resources/', $results[0]['url']);
  }

  /**
   * Topic-based resource retrieval drops foreign-language URLs.
   */
  public function testFindByTopicDropsForeignLanguageResources(): void {
    $finder = new LanguageIsolationResourceFinder(
      legacy_candidates: [
        10 => $this->buildResourceCandidate(10, 'Eviction Forms', '/resources/eviction-forms', 'form', [42], ['evictions']),
        11 => $this->buildResourceCandidate(11, 'Formularios de Desalojo', '/es/resources/formularios-de-desalojo', 'form', [42], ['desalojos']),
        12 => $this->buildResourceCandidate(12, 'Fomu za Kufukuzwa', '/sw/resources/fomu-za-kufukuzwa', 'form', [42], ['kufukuzwa']),
      ],
    );

    $results = $finder->findByTopic(42, 5);

    $ids = array_column($results, 'id');
    $this->assertSame([10], $ids);
    $this->assertStringStartsWith('/resources/', $results[0]['url']);
  }

  /**
   * Service-area resource retrieval drops foreign-language URLs.
   */
  public function testFindByServiceAreaDropsForeignLanguageResources(): void {
    $finder = new LanguageIsolationResourceFinder(
      legacy_candidates: [
        10 => $this->buildResourceCandidate(10, 'Eviction Forms', '/resources/eviction-forms', 'form', [], [], [['id' => 7, 'name' => 'Housing']]),
        11 => $this->buildResourceCandidate(11, 'Formularios de Desalojo', '/es/resources/formularios-de-desalojo', 'form', [], [], [['id' => 7, 'name' => 'Vivienda']]),
        12 => $this->buildResourceCandidate(12, 'Fomu za Kufukuzwa', '/sw/resources/fomu-za-kufukuzwa', 'form', [], [], [['id' => 7, 'name' => 'Makazi']]),
      ],
    );

    $results = $finder->findByServiceArea(7, 5);

    $ids = array_column($results, 'id');
    $this->assertSame([10], $ids);
    $this->assertStringStartsWith('/resources/', $results[0]['url']);
  }

  /**
   * Legacy resource search returns empty when only foreign-language items exist.
   */
  public function testFindByTypeLegacyReturnsEmptyWhenOnlyForeignLanguage(): void {
    $finder = new LanguageIsolationResourceFinder(
      legacy_candidates: [
        11 => $this->buildResourceCandidate(11, 'Formularios de Desalojo', '/es/resources/formularios-de-desalojo'),
        12 => $this->buildResourceCandidate(12, 'Fomu za Kufukuzwa', '/sw/resources/fomu-za-kufukuzwa'),
      ],
    );

    $this->assertSame([], $finder->findResources('eviction', 5));
  }

  /**
   * Topic retrieval returns empty when only foreign-language items exist.
   */
  public function testFindByTopicReturnsEmptyWhenOnlyForeignLanguage(): void {
    $finder = new LanguageIsolationResourceFinder(
      legacy_candidates: [
        11 => $this->buildResourceCandidate(11, 'Formularios de Desalojo', '/es/resources/formularios-de-desalojo', 'form', [42], ['desalojos']),
        12 => $this->buildResourceCandidate(12, 'Fomu za Kufukuzwa', '/sw/resources/fomu-za-kufukuzwa', 'form', [42], ['kufukuzwa']),
      ],
    );

    $this->assertSame([], $finder->findByTopic(42, 5));
  }

  /**
   * Service-area retrieval returns empty when only foreign-language items exist.
   */
  public function testFindByServiceAreaReturnsEmptyWhenOnlyForeignLanguage(): void {
    $finder = new LanguageIsolationResourceFinder(
      legacy_candidates: [
        11 => $this->buildResourceCandidate(11, 'Formularios de Desalojo', '/es/resources/formularios-de-desalojo', 'form', [], [], [['id' => 7, 'name' => 'Vivienda']]),
        12 => $this->buildResourceCandidate(12, 'Fomu za Kufukuzwa', '/sw/resources/fomu-za-kufukuzwa', 'form', [], [], [['id' => 7, 'name' => 'Makazi']]),
      ],
    );

    $this->assertSame([], $finder->findByServiceArea(7, 5));
  }

  /**
   * Builds one resource candidate matching the shape of buildIndexedResource().
   */
  private function buildResourceCandidate(
    int $id,
    string $title,
    string $url,
    string $type = 'resource',
    array $topic_ids = [],
    array $topic_names = [],
    array $service_areas = [],
  ): array {
    return [
      'id' => $id,
      'title' => $title,
      'title_lower' => strtolower($title),
      'url' => $url,
      'source_url' => $url,
      'updated_at' => 1700000000 + $id,
      'topics' => $topic_ids,
      'topic_names' => $topic_names,
      'service_areas' => $service_areas,
      'type' => $type,
      'has_file' => FALSE,
      'has_link' => FALSE,
      'description' => $title . ' description',
      'keywords' => array_values(array_filter(
        preg_split('/\s+/', strtolower($title)) ?: [],
        static fn(string $w): bool => strlen($w) >= 3,
      )),
    ];
  }

}

/**
 * Test double exposing resource language filtering without Drupal bootstrap.
 *
 * Skips the parent constructor and injects pre-built legacy candidates
 * so that findByTypeLegacy, findByTopic, and findByServiceArea can be
 * exercised without Search API or entity storage.
 */
final class LanguageIsolationResourceFinder extends ResourceFinder {

  /**
   * @param array<int, array<string, mixed>> $legacy_candidates
   *   Pre-built resource candidates keyed by node ID.
   */
  public function __construct(
    private array $legacy_candidates = [],
  ) {
    // Skip parent constructor — no Drupal services needed.
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
          'en' => new \stdClass(),
          'es' => new \stdClass(),
          'sw' => new \stdClass(),
          'nl' => new \stdClass(),
        ];
      }
    };
    $this->topicResolver = new class {
      public function resolveFromText(string $text): ?array {
        return NULL;
      }
    };
    $this->cache = new class {
      public function get(string $cid) { return FALSE; }
      public function set(string $cid, $data, $expire = -1, array $tags = []) {}
    };
    $this->rankingEnhancer = NULL;
    $this->sourceGovernance = NULL;
    $this->retrievalConfiguration = NULL;
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
  protected function buildQueryCacheKey(string $query, ?string $type, int $limit): ?string {
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  protected function loadLegacyResourceCandidates(string $query, int $limit, ?int $topic_id = NULL, ?int $service_area_id = NULL): array {
    return $this->legacy_candidates;
  }

  /**
   * {@inheritdoc}
   */
  protected function isDependencyUnavailable(): bool {
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  protected function getVectorSearchConfig(): array {
    return ['enabled' => FALSE];
  }

  /**
   * {@inheritdoc}
   */
  protected function recordRetrievalTelemetry(string $query, ?string $type, array $results, ?array $decision = NULL, ?array $vector_outcome = NULL, string $resolution_path = 'legacy', bool $was_cached = FALSE): void {
    // No-op in test.
  }

}
