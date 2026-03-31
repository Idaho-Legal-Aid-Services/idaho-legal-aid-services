<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use Drupal\ilas_site_assistant\Service\ResourceFinder;
use Drupal\ilas_site_assistant\Service\TopIntentsPack;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Covers document-backed forms and guides retrieval.
 */
#[Group('ilas_site_assistant')]
final class ResourceFinderDocumentRetrievalTest extends TestCase {

  /**
   * Forms retrieval prefers direct document media over resource-node fallback.
   */
  public function testFindFormsReturnsDocumentMediaForCustody(): void {
    $finder = new DocumentRetrievalTestFinder(
      documents: [
        'form' => [
          [
            'id' => 312,
            'title' => 'Temporary Orders Forms',
            'title_lower' => 'temporary orders forms',
            'url' => '/sites/default/files/forms/temporary-orders-forms.pdf',
            'source_url' => '/sites/default/files/forms/temporary-orders-forms.pdf',
            'type' => 'form',
            'has_file' => TRUE,
            'has_link' => FALSE,
            'description' => '',
            'topics' => ['custody', 'divorce'],
            'topic_ids' => [39],
            'topic_names' => ['custody', 'divorce'],
            'keywords' => ['temporary', 'orders', 'forms', 'custody', 'divorce'],
            'updated_at' => 1700000312,
          ],
          [
            'id' => 320,
            'title' => 'Ex Parte Emergency Temporary Order Packet',
            'title_lower' => 'ex parte emergency temporary order packet',
            'url' => '/sites/default/files/forms/ex-parte-emergency-temporary-order-packet.pdf',
            'source_url' => '/sites/default/files/forms/ex-parte-emergency-temporary-order-packet.pdf',
            'type' => 'form',
            'has_file' => TRUE,
            'has_link' => FALSE,
            'description' => '',
            'topics' => ['custody', 'divorce'],
            'topic_ids' => [39],
            'topic_names' => ['custody', 'divorce'],
            'keywords' => ['emergency', 'temporary', 'order', 'packet', 'custody', 'divorce'],
            'updated_at' => 1700000320,
          ],
        ],
      ],
      fallback_results: [
        'form' => [
          [
            'id' => 94,
            'title' => 'Custody Resource Node',
            'url' => '/resources/custody',
            'source_url' => '/resources/custody',
            'type' => 'form',
            'source' => 'legacy',
          ],
        ],
      ],
    );

    $results = $finder->findForms('custody', 6);

    $this->assertCount(2, $results);
    $this->assertSame('document_media', $results[0]['source']);
    $this->assertSame('form', $results[0]['type']);
    $this->assertStringEndsWith('.pdf', $results[0]['url']);
    $this->assertSame(
      ['Ex Parte Emergency Temporary Order Packet', 'Temporary Orders Forms'],
      array_column($results, 'title'),
    );
  }

  /**
   * Guides retrieval expands topic synonyms to media-backed custody guides.
   */
  public function testFindGuidesUsesTopicSynonymsForParentingPlan(): void {
    $finder = new DocumentRetrievalTestFinder(
      documents: [
        'guide' => [
          [
            'id' => 313,
            'title' => 'Custody Basics Guide',
            'title_lower' => 'custody basics guide',
            'url' => '/sites/default/files/guides/custody-basics-guide.pdf',
            'source_url' => '/sites/default/files/guides/custody-basics-guide.pdf',
            'type' => 'guide',
            'has_file' => TRUE,
            'has_link' => FALSE,
            'description' => '',
            'topics' => ['custody'],
            'topic_ids' => [39],
            'topic_names' => ['custody'],
            'keywords' => ['custody', 'basics', 'guide'],
            'updated_at' => 1700000313,
          ],
        ],
      ],
      fallback_results: ['guide' => []],
    );

    $results = $finder->findGuides('parenting plan', 6);

    $this->assertCount(1, $results);
    $this->assertSame('document_media', $results[0]['source']);
    $this->assertSame('Custody Basics Guide', $results[0]['title']);
  }

  /**
   * Document retrieval falls back to the legacy typed resource path when empty.
   */
  public function testFindFormsFallsBackWhenNoDocumentMatchesExist(): void {
    $finder = new DocumentRetrievalTestFinder(
      documents: ['form' => []],
      fallback_results: [
        'form' => [
          [
            'id' => 901,
            'title' => 'Legacy Bankruptcy Form',
            'url' => '/resources/legacy-bankruptcy-form',
            'source_url' => '/resources/legacy-bankruptcy-form',
            'type' => 'form',
            'source' => 'legacy',
          ],
        ],
      ],
    );

    $results = $finder->findForms('bankruptcy', 3);

    $this->assertCount(1, $results);
    $this->assertSame('legacy', $results[0]['source']);
    $this->assertSame('Legacy Bankruptcy Form', $results[0]['title']);
  }

}

/**
 * Test double for document-backed retrieval without Drupal bootstrap.
 */
final class DocumentRetrievalTestFinder extends ResourceFinder {

  /**
   * @param array<string, array<int, array<string, mixed>>> $documents
   *   Document candidates keyed by type.
   * @param array<string, array<int, array<string, mixed>>> $fallback_results
   *   Legacy fallback results keyed by type.
   */
  public function __construct(
    private array $documents = [],
    private array $fallback_results = [],
  ) {
    $this->topIntentsPack = new TopIntentsPack();
    $this->topicResolver = new class {
      public function resolveFromText(string $text): ?array {
        $normalized = strtolower(trim($text));
        return match (TRUE) {
          str_contains($normalized, 'custody') => ['id' => 39, 'name' => 'Custody'],
          str_contains($normalized, 'divorce') => ['id' => 40, 'name' => 'Divorce'],
          str_contains($normalized, 'bankruptcy') => ['id' => 52, 'name' => 'Bankruptcy'],
          default => NULL,
        };
      }
    };
    $this->cache = new class {
      public function get(string $cid) { return FALSE; }
      public function set(string $cid, $data, $expire = -1, array $tags = []) {}
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
        return ['en' => new \stdClass()];
      }
    };
    $this->rankingEnhancer = NULL;
    $this->sourceGovernance = NULL;
    $this->retrievalConfiguration = NULL;
  }

  /**
   * {@inheritdoc}
   */
  protected function loadPublishedDocumentCandidates(string $document_type): array {
    return $this->documents[$document_type] ?? [];
  }

  /**
   * {@inheritdoc}
   */
  protected function findByType(string $query, ?string $type, int $limit) {
    unset($query, $limit);
    return $this->fallback_results[$type ?? 'all'] ?? [];
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
  protected function getVectorSearchConfig(): array {
    return ['enabled' => FALSE];
  }

  /**
   * {@inheritdoc}
   */
  protected function recordRetrievalTelemetry(string $query, ?string $type, array $items, ?array $decision, ?array $vector_outcome, string $path, bool $cache_hit = FALSE): void {
    // No-op in unit tests.
  }

}
