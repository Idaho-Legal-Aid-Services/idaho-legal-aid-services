<?php

namespace Drupal\Tests\ilas_site_assistant\Unit;

use Drupal\ilas_site_assistant\Service\FaqIndex;
use Drupal\ilas_site_assistant\Service\RetrievalContract;
use Drupal\ilas_site_assistant\Service\ResourceFinder;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests for vector search merge and scoring logic.
 *
 * Tests the pure-function aspects of supplementWithVectorResults(),
 * score thresholds, and normalization without requiring Pinecone
 * or Search API infrastructure.
 */
#[Group('ilas_site_assistant')]
class VectorSearchMergeTest extends TestCase {

  /**
   * Tests that disabled vector search reports the expected trigger reason.
   */
  public function testVectorDecisionMapReportsDisabledReason(): void {
    $faq = new TestFaqIndex(['enabled' => FALSE]);

    $decision = $faq->testVectorDecisionMap([
      ['paragraph_id' => 1, 'id' => 1, 'score' => 50],
    ]);

    $this->assertFalse($decision['enabled']);
    $this->assertFalse($decision['should_attempt']);
    $this->assertSame('disabled', $decision['reason']);
    $this->assertSame(1, $decision['lexical_count']);
    $this->assertSame(50.0, $decision['best_lexical_score']);
  }

  /**
   * Tests that sufficient lexical coverage reports the expected trigger reason.
   */
  public function testVectorDecisionMapReportsSufficientLexicalReason(): void {
    $faq = new TestFaqIndex([
      'enabled' => TRUE,
      'fallback_threshold' => 2,
      'min_lexical_score' => 0,
    ]);

    $decision = $faq->testVectorDecisionMap([
      ['paragraph_id' => 1, 'id' => 1, 'score' => 50],
      ['paragraph_id' => 2, 'id' => 2, 'score' => 40],
    ]);

    $this->assertTrue($decision['enabled']);
    $this->assertFalse($decision['should_attempt']);
    $this->assertSame('sufficient_lexical', $decision['reason']);
    $this->assertSame(2, $decision['lexical_count']);
    $this->assertSame(50.0, $decision['best_lexical_score']);
  }

  /**
   * Tests that sparse lexical coverage reports the expected trigger reason.
   */
  public function testVectorDecisionMapReportsSparseLexicalReason(): void {
    $faq = new TestFaqIndex([
      'enabled' => TRUE,
      'fallback_threshold' => 2,
      'min_lexical_score' => 0,
    ]);

    $decision = $faq->testVectorDecisionMap([
      ['paragraph_id' => 1, 'id' => 1, 'score' => 50],
    ]);

    $this->assertTrue($decision['should_attempt']);
    $this->assertSame('sparse_lexical', $decision['reason']);
    $this->assertSame(1, $decision['lexical_count']);
  }

  /**
   * Tests that low-quality lexical coverage reports the expected trigger reason.
   */
  public function testVectorDecisionMapReportsLowQualityLexicalReason(): void {
    $faq = new TestFaqIndex([
      'enabled' => TRUE,
      'fallback_threshold' => 2,
      'min_lexical_score' => 10.0,
    ]);

    $decision = $faq->testVectorDecisionMap([
      ['paragraph_id' => 1, 'id' => 1, 'score' => 4.0],
      ['paragraph_id' => 2, 'id' => 2, 'score' => 3.5],
    ]);

    $this->assertTrue($decision['should_attempt']);
    $this->assertSame('low_quality_lexical', $decision['reason']);
    $this->assertSame(4.0, $decision['best_lexical_score']);
  }

  /**
   * Tests that vector disabled returns lexical items unchanged.
   */
  public function testVectorDisabledReturnsLexicalUnchanged(): void {
    $faq = new TestFaqIndex(['enabled' => FALSE]);
    $lexical = [
      ['paragraph_id' => 1, 'id' => 1, 'score' => 50],
    ];

    $result = $faq->testSupplementWithVectorResults($lexical, 'test', 10);
    $this->assertSame($lexical, $result);
  }

  /**
   * Tests that lexical above count threshold skips vector search.
   */
  public function testLexicalAboveThresholdSkipsVector(): void {
    $faq = new TestFaqIndex([
      'enabled' => TRUE,
      'fallback_threshold' => 2,
      'min_lexical_score' => 0,
    ]);
    $lexical = [
      ['paragraph_id' => 1, 'id' => 1, 'score' => 50],
      ['paragraph_id' => 2, 'id' => 2, 'score' => 40],
      ['paragraph_id' => 3, 'id' => 3, 'score' => 30],
    ];

    $result = $faq->testSupplementWithVectorResults($lexical, 'test', 10);
    $this->assertSame($lexical, $result);
  }

  /**
   * Tests that lexical below threshold triggers vector merge.
   */
  public function testLexicalBelowThresholdTriggersVector(): void {
    $vector_items = [
      ['paragraph_id' => 10, 'id' => 10, 'score' => 85, 'source' => 'vector'],
    ];
    $faq = new TestFaqIndex([
      'enabled' => TRUE,
      'fallback_threshold' => 2,
      'min_lexical_score' => 0,
    ], $vector_items);

    $lexical = [
      ['paragraph_id' => 1, 'id' => 1, 'score' => 50],
    ];

    $result = $faq->testSupplementWithVectorResults($lexical, 'test', 10);
    $this->assertCount(2, $result);
  }

  /**
   * Tests that structured healthy vector outcomes still merge correctly.
   */
  public function testStructuredHealthyVectorOutcomeStillMerges(): void {
    $faq = new TestFaqIndex([
      'enabled' => TRUE,
      'fallback_threshold' => 2,
      'min_lexical_score' => 0,
    ], [
      'attempted' => TRUE,
      'status' => 'healthy',
      'reason' => 'results_available',
      'elapsed_ms' => 125,
      'cacheable' => TRUE,
      'items' => [
        ['paragraph_id' => 10, 'id' => 10, 'score' => 85, 'source' => 'vector'],
      ],
    ]);

    $payload = $faq->testSupplementWithVectorResultsDetailed([
      ['paragraph_id' => 1, 'id' => 1, 'score' => 50, 'source' => 'lexical'],
    ], 'test', 10);

    $this->assertSame('healthy', $payload['vector_outcome']['status']);
    $this->assertCount(2, $payload['items']);
    $this->assertContains('vector', array_column($payload['items'], 'source'));
  }

  /**
   * Tests that FAQ vector merge drops non-current-language URLs.
   */
  public function testFaqVectorMergeDropsNonCurrentLanguageUrls(): void {
    $faq = new TestFaqIndex([
      'enabled' => TRUE,
      'fallback_threshold' => 2,
      'min_lexical_score' => 0,
    ], [
      ['paragraph_id' => 10, 'id' => 10, 'score' => 85, 'source' => 'vector', 'parent_url' => '/es/resources/tenant-rights', 'url' => '/es/resources/tenant-rights#rights'],
      ['paragraph_id' => 11, 'id' => 11, 'score' => 84, 'source' => 'vector', 'parent_url' => '/resources/tenant-rights', 'url' => '/resources/tenant-rights#rights'],
    ]);

    $payload = $faq->testSupplementWithVectorResultsDetailed([], 'test', 10);

    $this->assertCount(1, $payload['items']);
    $this->assertSame('/resources/tenant-rights#rights', $payload['items'][0]['url']);
  }

  /**
   * Tests that degraded vector outcomes never merge into lexical results.
   */
  public function testDegradedVectorOutcomeNeverMerges(): void {
    $lexical = [
      ['paragraph_id' => 1, 'id' => 1, 'score' => 50, 'source' => 'lexical'],
    ];
    $faq = new TestFaqIndex([
      'enabled' => TRUE,
      'fallback_threshold' => 2,
      'min_lexical_score' => 0,
    ], [
      'attempted' => TRUE,
      'status' => 'degraded',
      'reason' => 'latency_budget_exceeded',
      'elapsed_ms' => 2501,
      'cacheable' => FALSE,
      'items' => [
        ['paragraph_id' => 99, 'id' => 99, 'score' => 95, 'source' => 'vector'],
      ],
    ]);

    $payload = $faq->testSupplementWithVectorResultsDetailed($lexical, 'test', 10);

    $this->assertSame($lexical, $payload['items']);
    $this->assertSame('degraded', $payload['vector_outcome']['status']);
    $this->assertSame('latency_budget_exceeded', $payload['vector_outcome']['reason']);
    $this->assertFalse($payload['vector_outcome']['cacheable']);
  }

  /**
   * Tests that duplicate paragraph_id keeps the higher-scored version.
   */
  public function testMergeDeduplicatesByParagraphId(): void {
    $vector_items = [
      ['paragraph_id' => 1, 'id' => 1, 'score' => 90, 'source' => 'vector'],
    ];
    $faq = new TestFaqIndex([
      'enabled' => TRUE,
      'fallback_threshold' => 2,
      'min_lexical_score' => 0,
    ], $vector_items);

    $lexical = [
      ['paragraph_id' => 1, 'id' => 1, 'score' => 50],
    ];

    $result = $faq->testSupplementWithVectorResults($lexical, 'test', 10);
    $this->assertCount(1, $result);
    $this->assertEquals(90, $result[0]['score']);
    $this->assertEquals('vector', $result[0]['source']);
  }

  /**
   * Tests that duplicate keeps lexical when it scores higher.
   */
  public function testMergeKeepsHigherScoredLexical(): void {
    $vector_items = [
      ['paragraph_id' => 1, 'id' => 1, 'score' => 30, 'source' => 'vector'],
    ];
    $faq = new TestFaqIndex([
      'enabled' => TRUE,
      'fallback_threshold' => 2,
      'min_lexical_score' => 0,
    ], $vector_items);

    $lexical = [
      ['paragraph_id' => 1, 'id' => 1, 'score' => 50, 'source' => 'lexical'],
    ];

    $result = $faq->testSupplementWithVectorResults($lexical, 'test', 10);
    $this->assertCount(1, $result);
    $this->assertEquals(50, $result[0]['score']);
    $this->assertEquals('lexical', $result[0]['source']);
  }

  /**
   * Tests that unique items from both sources are preserved.
   */
  public function testMergePreservesUniqueItems(): void {
    $vector_items = [
      ['paragraph_id' => 20, 'id' => 20, 'score' => 70, 'source' => 'vector'],
    ];
    $faq = new TestFaqIndex([
      'enabled' => TRUE,
      'fallback_threshold' => 2,
      'min_lexical_score' => 0,
    ], $vector_items);

    $lexical = [
      ['paragraph_id' => 10, 'id' => 10, 'score' => 60, 'source' => 'lexical'],
    ];

    $result = $faq->testSupplementWithVectorResults($lexical, 'test', 10);
    $this->assertCount(2, $result);
    $ids = array_column($result, 'paragraph_id');
    $this->assertContains(10, $ids);
    $this->assertContains(20, $ids);
  }

  /**
   * Tests that resource vector merge drops non-current-language URLs.
   */
  public function testResourceVectorMergeDropsNonCurrentLanguageUrls(): void {
    $finder = new TestResourceFinder([
      'enabled' => TRUE,
      'fallback_threshold' => 2,
      'min_lexical_score' => 0,
    ], [
      ['id' => 10, 'score' => 85, 'source' => 'vector', 'url' => '/es/resources/evictions'],
      ['id' => 11, 'score' => 84, 'source' => 'vector', 'url' => '/resources/evictions'],
    ]);

    $result = $finder->testSupplementWithVectorResults([], 'test', NULL, 10);

    $this->assertCount(1, $result);
    $this->assertSame('/resources/evictions', $result[0]['url']);
  }

  /**
   * Tests that merged results are sorted by score descending.
   */
  public function testMergedResultsSortedByScoreDescending(): void {
    $vector_items = [
      ['paragraph_id' => 20, 'id' => 20, 'score' => 95, 'source' => 'vector'],
      ['paragraph_id' => 30, 'id' => 30, 'score' => 60, 'source' => 'vector'],
    ];
    $faq = new TestFaqIndex([
      'enabled' => TRUE,
      'fallback_threshold' => 2,
      'min_lexical_score' => 0,
    ], $vector_items);

    $lexical = [
      ['paragraph_id' => 10, 'id' => 10, 'score' => 80],
    ];

    $result = $faq->testSupplementWithVectorResults($lexical, 'test', 10);
    $scores = array_column($result, 'score');
    $this->assertEquals([95, 80, 60], $scores);
  }

  /**
   * Tests that merged results are limited to the max limit.
   */
  public function testMergedResultsLimitedToMaxLimit(): void {
    $vector_items = [
      ['paragraph_id' => 20, 'id' => 20, 'score' => 90],
      ['paragraph_id' => 30, 'id' => 30, 'score' => 80],
      ['paragraph_id' => 40, 'id' => 40, 'score' => 70],
    ];
    $faq = new TestFaqIndex([
      'enabled' => TRUE,
      'fallback_threshold' => 2,
      'min_lexical_score' => 0,
    ], $vector_items);

    $lexical = [
      ['paragraph_id' => 10, 'id' => 10, 'score' => 85],
    ];

    $result = $faq->testSupplementWithVectorResults($lexical, 'test', 3);
    $this->assertCount(3, $result);
  }

  /**
   * Tests min_lexical_score triggers vector even above count threshold.
   */
  public function testMinLexicalScoreTriggersVectorEvenAboveCount(): void {
    $vector_items = [
      ['paragraph_id' => 100, 'id' => 100, 'score' => 85, 'source' => 'vector'],
    ];
    $faq = new TestFaqIndex([
      'enabled' => TRUE,
      'fallback_threshold' => 2,
      'min_lexical_score' => 10.0,
    ], $vector_items);

    // 3 lexical results (above threshold of 2) but all score below 10.
    $lexical = [
      ['paragraph_id' => 1, 'id' => 1, 'score' => 2.0],
      ['paragraph_id' => 2, 'id' => 2, 'score' => 1.5],
      ['paragraph_id' => 3, 'id' => 3, 'score' => 1.0],
    ];

    $result = $faq->testSupplementWithVectorResults($lexical, 'test', 10);
    // Should have 4 items: 3 lexical + 1 vector (vector search fired).
    $this->assertCount(4, $result);
    $sources = array_column($result, 'source');
    $this->assertContains('vector', $sources);
  }

  /**
   * Tests min_lexical_score=0 disables quality check.
   */
  public function testMinLexicalScoreZeroDisablesQualityCheck(): void {
    $vector_items = [
      ['paragraph_id' => 100, 'id' => 100, 'score' => 85, 'source' => 'vector'],
    ];
    $faq = new TestFaqIndex([
      'enabled' => TRUE,
      'fallback_threshold' => 2,
      'min_lexical_score' => 0,
    ], $vector_items);

    // 3 low-scoring lexical results above count threshold.
    $lexical = [
      ['paragraph_id' => 1, 'id' => 1, 'score' => 1.0],
      ['paragraph_id' => 2, 'id' => 2, 'score' => 0.5],
      ['paragraph_id' => 3, 'id' => 3, 'score' => 0.2],
    ];

    $result = $faq->testSupplementWithVectorResults($lexical, 'test', 10);
    // min_lexical_score=0 means quality check is disabled, count >= threshold,
    // so vector search should NOT fire.
    $this->assertSame($lexical, $result);
  }

  // =========================================================================
  // ResourceFinder merge tests (same logic, different merge key + param order)
  // =========================================================================

  /**
   * Tests ResourceFinder vector disabled returns lexical unchanged.
   */
  public function testResourceVectorDisabledReturnsLexical(): void {
    $finder = new TestResourceFinder(['enabled' => FALSE]);
    $lexical = [
      ['id' => 1, 'score' => 50],
    ];

    $result = $finder->testSupplementWithVectorResults($lexical, 'test', NULL, 10);
    $this->assertSame($lexical, $result);
  }

  /**
   * Tests ResourceFinder merge deduplicates by node ID.
   */
  public function testResourceMergeDeduplicatesByNodeId(): void {
    $vector_items = [
      ['id' => 1, 'score' => 90, 'source' => 'vector'],
    ];
    $finder = new TestResourceFinder([
      'enabled' => TRUE,
      'fallback_threshold' => 2,
      'min_lexical_score' => 0,
    ], $vector_items);

    $lexical = [
      ['id' => 1, 'score' => 50, 'source' => 'lexical'],
    ];

    $result = $finder->testSupplementWithVectorResults($lexical, 'test', NULL, 10);
    $this->assertCount(1, $result);
    $this->assertEquals(90, $result[0]['score']);
  }

  // =========================================================================
  // Retrieval contract merge tests (PHARD-06)
  // =========================================================================

  /**
   * Lexical priority boost wins for close scores (same paragraph_id).
   *
   * Lexical 85 + boost 5 = 90 effective > vector 87. Lexical wins.
   */
  public function testLexicalPriorityBoostWinsForCloseScores(): void {
    $vector_items = [
      ['paragraph_id' => 1, 'id' => 1, 'score' => 87, 'source' => 'vector'],
    ];
    $faq = new TestFaqIndex([
      'enabled' => TRUE,
      'fallback_threshold' => 2,
      'min_lexical_score' => 0,
    ], $vector_items);

    $lexical = [
      ['paragraph_id' => 1, 'id' => 1, 'score' => 85, 'source' => 'lexical'],
    ];

    $result = $faq->testSupplementWithVectorResults($lexical, 'test', 10);
    $this->assertCount(1, $result);
    $this->assertEquals(85, $result[0]['score']);
    $this->assertEquals('lexical', $result[0]['source']);
  }

  /**
   * Vector still wins for large score gap despite lexical boost.
   *
   * Lexical 50 + boost 5 = 55 effective < vector 90. Vector wins.
   */
  public function testVectorStillWinsForLargeScoreGap(): void {
    $vector_items = [
      ['paragraph_id' => 1, 'id' => 1, 'score' => 90, 'source' => 'vector'],
    ];
    $faq = new TestFaqIndex([
      'enabled' => TRUE,
      'fallback_threshold' => 2,
      'min_lexical_score' => 0,
    ], $vector_items);

    $lexical = [
      ['paragraph_id' => 1, 'id' => 1, 'score' => 50, 'source' => 'lexical'],
    ];

    $result = $faq->testSupplementWithVectorResults($lexical, 'test', 10);
    $this->assertCount(1, $result);
    $this->assertEquals(90, $result[0]['score']);
    $this->assertEquals('vector', $result[0]['source']);
  }

  /**
   * Minimum lexical preserved when vector dominates output.
   *
   * 1 lexical (score 10) + 5 vector (90,80,70,60,50), limit=3.
   * Without guarantee: [90,80,70] all vector.
   * With guarantee: at least 1 lexical survives.
   */
  public function testMinLexicalPreservedWhenVectorDominates(): void {
    $vector_items = [
      ['paragraph_id' => 20, 'id' => 20, 'score' => 90, 'source' => 'vector'],
      ['paragraph_id' => 30, 'id' => 30, 'score' => 80, 'source' => 'vector'],
      ['paragraph_id' => 40, 'id' => 40, 'score' => 70, 'source' => 'vector'],
      ['paragraph_id' => 50, 'id' => 50, 'score' => 60, 'source' => 'vector'],
      ['paragraph_id' => 60, 'id' => 60, 'score' => 50, 'source' => 'vector'],
    ];
    $faq = new TestFaqIndex([
      'enabled' => TRUE,
      'fallback_threshold' => 2,
      'min_lexical_score' => 0,
    ], $vector_items);

    $lexical = [
      ['paragraph_id' => 10, 'id' => 10, 'score' => 10, 'source' => 'lexical'],
    ];

    $result = $faq->testSupplementWithVectorResults($lexical, 'test', 3);
    $this->assertCount(3, $result);

    // At least 1 lexical must survive.
    $lexical_in_output = array_filter($result, fn($item) => ($item['source'] ?? 'lexical') !== 'vector');
    $this->assertGreaterThanOrEqual(
      RetrievalContract::MIN_LEXICAL_PRESERVED,
      count($lexical_in_output),
      'At least MIN_LEXICAL_PRESERVED lexical results must survive in merge output.',
    );
  }

  /**
   * No adjustment needed when lexical already present in output.
   *
   * 1 lexical (score 85) + 2 vector (90, 70), limit=3.
   * Output: [90, 85, 70]. Lexical already present.
   */
  public function testMinLexicalPreservedNotNeededWhenLexicalAlreadyPresent(): void {
    $vector_items = [
      ['paragraph_id' => 20, 'id' => 20, 'score' => 90, 'source' => 'vector'],
      ['paragraph_id' => 30, 'id' => 30, 'score' => 70, 'source' => 'vector'],
    ];
    $faq = new TestFaqIndex([
      'enabled' => TRUE,
      'fallback_threshold' => 2,
      'min_lexical_score' => 0,
    ], $vector_items);

    $lexical = [
      ['paragraph_id' => 10, 'id' => 10, 'score' => 85, 'source' => 'lexical'],
    ];

    $result = $faq->testSupplementWithVectorResults($lexical, 'test', 3);
    $this->assertCount(3, $result);
    $scores = array_column($result, 'score');
    $this->assertEquals([90, 85, 70], $scores);

    // Lexical present, no adjustment made.
    $lexical_in_output = array_filter($result, fn($item) => ($item['source'] ?? 'lexical') !== 'vector');
    $this->assertCount(1, $lexical_in_output);
  }

  /**
   * ResourceFinder: lexical priority boost wins for close scores.
   */
  public function testResourceLexicalPriorityBoostWinsForCloseScores(): void {
    $vector_items = [
      ['id' => 1, 'score' => 87, 'source' => 'vector'],
    ];
    $finder = new TestResourceFinder([
      'enabled' => TRUE,
      'fallback_threshold' => 2,
      'min_lexical_score' => 0,
    ], $vector_items);

    $lexical = [
      ['id' => 1, 'score' => 85, 'source' => 'lexical'],
    ];

    $result = $finder->testSupplementWithVectorResults($lexical, 'test', NULL, 10);
    $this->assertCount(1, $result);
    $this->assertEquals(85, $result[0]['score']);
    $this->assertEquals('lexical', $result[0]['source']);
  }

  /**
   * ResourceFinder: minimum lexical preserved when vector dominates.
   */
  public function testResourceMinLexicalPreservedWhenVectorDominates(): void {
    $vector_items = [
      ['id' => 20, 'score' => 90, 'source' => 'vector'],
      ['id' => 30, 'score' => 80, 'source' => 'vector'],
      ['id' => 40, 'score' => 70, 'source' => 'vector'],
      ['id' => 50, 'score' => 60, 'source' => 'vector'],
      ['id' => 60, 'score' => 50, 'source' => 'vector'],
    ];
    $finder = new TestResourceFinder([
      'enabled' => TRUE,
      'fallback_threshold' => 2,
      'min_lexical_score' => 0,
    ], $vector_items);

    $lexical = [
      ['id' => 10, 'score' => 10, 'source' => 'lexical'],
    ];

    $result = $finder->testSupplementWithVectorResults($lexical, 'test', NULL, 3);
    $this->assertCount(3, $result);

    // At least 1 lexical must survive.
    $lexical_in_output = array_filter($result, fn($item) => ($item['source'] ?? 'lexical') !== 'vector');
    $this->assertGreaterThanOrEqual(
      RetrievalContract::MIN_LEXICAL_PRESERVED,
      count($lexical_in_output),
      'At least MIN_LEXICAL_PRESERVED lexical results must survive in ResourceFinder merge output.',
    );
  }

  /**
   * Tests ResourceFinder min_lexical_score triggers vector.
   */
  public function testResourceMinLexicalScoreTriggersVector(): void {
    $vector_items = [
      ['id' => 100, 'score' => 85, 'source' => 'vector'],
    ];
    $finder = new TestResourceFinder([
      'enabled' => TRUE,
      'fallback_threshold' => 2,
      'min_lexical_score' => 10.0,
    ], $vector_items);

    $lexical = [
      ['id' => 1, 'score' => 2.0],
      ['id' => 2, 'score' => 1.5],
    ];

    $result = $finder->testSupplementWithVectorResults($lexical, 'test', NULL, 10);
    $this->assertCount(3, $result);
  }

  /**
   * Tests ResourceFinder reports the low-quality lexical trigger reason.
   */
  public function testResourceVectorDecisionMapReportsLowQualityLexicalReason(): void {
    $finder = new TestResourceFinder([
      'enabled' => TRUE,
      'fallback_threshold' => 2,
      'min_lexical_score' => 10.0,
    ]);

    $decision = $finder->testVectorDecisionMap([
      ['id' => 1, 'score' => 4.0],
      ['id' => 2, 'score' => 3.5],
    ]);

    $this->assertTrue($decision['should_attempt']);
    $this->assertSame('low_quality_lexical', $decision['reason']);
  }

}

/**
 * Test-only subclass of FaqIndex exposing protected merge logic.
 *
 * Skips the parent constructor to avoid Drupal service dependencies.
 * Overrides searchVector() to return controlled test data.
 */
class TestFaqIndex extends FaqIndex {

  /**
   * Test vector search config.
   */
  private array $testVectorConfig;

  /**
   * Test vector items to return from searchVector().
   */
  private array $testVectorItems;

  /**
   * Constructs test instance without Drupal dependencies.
   */
  public function __construct(array $vector_config, array $vector_items = []) {
    // Skip parent constructor — we only test merge logic.
    $this->testVectorConfig = $vector_config;
    $this->testVectorItems = $vector_items;
    $this->languageManager = $this->buildLanguageManagerStub();
  }

  /**
   * {@inheritdoc}
   */
  protected function getVectorSearchConfig(): array {
    return $this->testVectorConfig;
  }

  /**
   * {@inheritdoc}
   */
  protected function searchVector(string $query, int $limit, ?string $type = NULL): array {
    return $this->testVectorItems;
  }

  /**
   * Exposes protected supplementWithVectorResults() for testing.
   */
  public function testSupplementWithVectorResults(array $lexical, string $query, int $limit, ?string $type = NULL): array {
    return $this->supplementWithVectorResults($lexical, $query, $limit, $type);
  }

  /**
   * Exposes protected supplementWithVectorResultsDetailed() for testing.
   */
  public function testSupplementWithVectorResultsDetailed(array $lexical, string $query, int $limit, ?string $type = NULL): array {
    return $this->supplementWithVectorResultsDetailed($lexical, $query, $limit, $type);
  }

  /**
   * Exposes the vector decision map for testing.
   */
  public function testVectorDecisionMap(array $lexical): array {
    return $this->buildVectorDecisionMap($lexical);
  }

  /**
   * Builds a minimal language-manager stub for URL-language filtering.
   */
  private function buildLanguageManagerStub(): object {
    return new class {
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
          'nl' => new \stdClass(),
          'sw' => new \stdClass(),
        ];
      }
    };
  }

}

/**
 * Test-only subclass of ResourceFinder exposing protected merge logic.
 *
 * Skips the parent constructor to avoid Drupal service dependencies.
 * Overrides findByTypeVector() to return controlled test data.
 */
class TestResourceFinder extends ResourceFinder {

  /**
   * Test vector search config.
   */
  private array $testVectorConfig;

  /**
   * Test vector items to return from findByTypeVector().
   */
  private array $testVectorItems;

  /**
   * Constructs test instance without Drupal dependencies.
   */
  public function __construct(array $vector_config, array $vector_items = []) {
    // Skip parent constructor — we only test merge logic.
    $this->testVectorConfig = $vector_config;
    $this->testVectorItems = $vector_items;
    $this->languageManager = $this->buildLanguageManagerStub();
  }

  /**
   * {@inheritdoc}
   */
  protected function getVectorSearchConfig(): array {
    return $this->testVectorConfig;
  }

  /**
   * {@inheritdoc}
   */
  protected function findByTypeVector(string $query, ?string $type, int $limit): array {
    return $this->testVectorItems;
  }

  /**
   * Exposes protected supplementWithVectorResults() for testing.
   */
  public function testSupplementWithVectorResults(array $lexical, string $query, ?string $type, int $limit): array {
    return $this->supplementWithVectorResults($lexical, $query, $type, $limit);
  }

  /**
   * Exposes the vector decision map for testing.
   */
  public function testVectorDecisionMap(array $lexical): array {
    return $this->buildVectorDecisionMap($lexical);
  }

  /**
   * Builds a minimal language-manager stub for URL-language filtering.
   */
  private function buildLanguageManagerStub(): object {
    return new class {
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
          'nl' => new \stdClass(),
          'sw' => new \stdClass(),
        ];
      }
    };
  }

}
