<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\State\StateInterface;
use Drupal\ilas_site_assistant\Service\ResourceFinder;
use Drupal\ilas_site_assistant\Service\SourceGovernanceService;
use Drupal\ilas_site_assistant\Service\TopIntentsPack;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Covers document-backed forms and guides retrieval.
 */
#[Group('ilas_site_assistant')]
final class ResourceFinderDocumentRetrievalTest extends TestCase {

  /**
   * In-memory state store for governance stubs.
   *
   * @var array<string, mixed>
   */
  private array $stateStore = [];

  /**
   * Builds a mock state service backed by in-memory storage.
   */
  private function buildState(): StateInterface {
    $state = $this->createMock(StateInterface::class);

    $state->method('get')
      ->willReturnCallback(function (string $key, $default = NULL) {
        return $this->stateStore[$key] ?? $default;
      });

    $state->method('set')
      ->willReturnCallback(function (string $key, $value): void {
        $this->stateStore[$key] = $value;
      });

    $state->method('delete')
      ->willReturnCallback(function (string $key): void {
        unset($this->stateStore[$key]);
      });

    return $state;
  }

  /**
   * Builds a minimal source-governance service for document retrieval tests.
   */
  private function buildSourceGovernanceService(): SourceGovernanceService {
    $this->stateStore = [];
    $policy = [
      'enabled' => TRUE,
      'policy_version' => 'p2_obj_03_v1',
      'source_classes' => [
        'resource_lexical' => [
          'provenance_label' => 'search_api.index.assistant_resources',
          'owner_role' => 'Content Operations Lead',
          'max_age_days' => 180,
          'require_source_url' => TRUE,
        ],
      ],
    ];

    $config = $this->createStub(ImmutableConfig::class);
    $config->method('get')
      ->willReturnCallback(static function (string $key) use ($policy) {
        return $key === 'source_governance' ? $policy : NULL;
      });

    $configFactory = $this->createStub(ConfigFactoryInterface::class);
    $configFactory->method('get')
      ->with('ilas_site_assistant.settings')
      ->willReturn($config);

    return new SourceGovernanceService(
      $configFactory,
      $this->buildState(),
      $this->createStub(LoggerInterface::class),
    );
  }

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
      source_governance: $this->buildSourceGovernanceService(),
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
    foreach ($results as $result) {
      $this->assertSame('document_media', $result['source']);
      $this->assertSame('resource_lexical', $result['source_class']);
      $this->assertSame('entity_query', $result['provenance']['retrieval_method']);
      $this->assertSame('node.entity_query', $result['provenance']['provenance_label']);
      $this->assertSame('form', $result['type']);
      $this->assertStringEndsWith('.pdf', $result['url']);
    }
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
      source_governance: $this->buildSourceGovernanceService(),
      fallback_results: ['guide' => []],
    );

    $results = $finder->findGuides('parenting plan', 6);

    $this->assertCount(1, $results);
    $this->assertSame('document_media', $results[0]['source']);
    $this->assertSame('resource_lexical', $results[0]['source_class']);
    $this->assertSame('entity_query', $results[0]['provenance']['retrieval_method']);
    $this->assertSame('node.entity_query', $results[0]['provenance']['provenance_label']);
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
    ?SourceGovernanceService $source_governance = NULL,
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
    $this->sourceGovernance = $source_governance;
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
