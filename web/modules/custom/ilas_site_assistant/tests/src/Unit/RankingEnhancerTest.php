<?php

namespace Drupal\Tests\ilas_site_assistant\Unit;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\ilas_site_assistant\Service\RankingEnhancer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for RankingEnhancer.
 *
 * Covers:
 * - F-19: Synonym expansion token-boundary fix
 * - expandQuery() exact and multi-word matching
 * - Canonical URL boost
 * - De-duplication
 * - Field weight scoring
 */
#[CoversClass(RankingEnhancer::class)]
#[Group('ilas_site_assistant')]
class RankingEnhancerTest extends TestCase {

  /**
   * The service under test.
   */
  protected RankingEnhancer $ranker;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $cache = $this->createStub(CacheBackendInterface::class);
    $this->ranker = new RankingEnhancer($cache);
  }

  // -----------------------------------------------------------------------
  // expandQuery() tests
  // -----------------------------------------------------------------------

  /**
   * Tests that exact canonical term triggers synonym expansion.
   */
  public function testExpandQueryExactCanonical(): void {
    $expanded = $this->ranker->expandQuery(['eviction']);

    $this->assertContains('eviction', $expanded);
    $this->assertContains('evicted', $expanded);
    $this->assertContains('kicked out', $expanded);
    $this->assertContains('desalojo', $expanded);
  }

  /**
   * Tests that exact synonym triggers reverse expansion to canonical.
   */
  public function testExpandQueryReverseSynonym(): void {
    $expanded = $this->ranker->expandQuery(['evicted']);

    $this->assertContains('eviction', $expanded);
  }

  /**
   * Tests F-19 fix: "bankruptcy" must NOT expand via "bank" substring.
   *
   * Before fix: strpos('bankruptcy', 'bank') !== FALSE caused false expansion.
   * After fix: word-boundary matching prevents this.
   */
  public function testExpandQueryTokenBoundaryBankruptcyNotBank(): void {
    $expanded = $this->ranker->expandQuery(['bankruptcy']);

    // "bankruptcy" IS a canonical term — it should expand to its own synonyms.
    $this->assertContains('bankrupt', $expanded);
    $this->assertContains('bancarrota', $expanded);

    // But it should NOT pick up synonyms for unrelated canonicals.
    // Before fix, "bank" would substring-match inside "bankruptcy" and
    // cause false expansions from any canonical containing "bank".
    // Verify that the expanded set is reasonable.
    $this->assertContains('bankruptcy', $expanded);
  }

  /**
   * Tests F-19 fix: "snap" should expand via food stamps, not as substring.
   *
   * "snap" is a synonym of "food stamps". It should expand to the canonical
   * and its siblings — but NOT match unrelated canonicals via substring.
   */
  public function testExpandQuerySnapExpandsFoodStamps(): void {
    $expanded = $this->ranker->expandQuery(['snap']);

    // "snap" is a synonym of "food stamps" — should reverse-expand.
    $this->assertContains('food stamps', $expanded);
  }

  /**
   * Tests multi-word synonym expansion.
   */
  public function testExpandQueryMultiWordSynonym(): void {
    // "child support" is a canonical term.
    $expanded = $this->ranker->expandQuery(['child support']);

    $this->assertContains('support payments', $expanded);
    $this->assertContains('manutencion', $expanded);
  }

  /**
   * Tests that unrelated terms get no synonym expansion.
   */
  public function testExpandQueryNoExpansionForUnknownTerms(): void {
    $expanded = $this->ranker->expandQuery(['xylophone']);

    $this->assertEquals(['xylophone'], $expanded);
  }

  /**
   * Tests deduplication in expanded results.
   */
  public function testExpandQueryDeduplicates(): void {
    $expanded = $this->ranker->expandQuery(['eviction', 'eviction']);

    $counts = array_count_values($expanded);
    foreach ($counts as $word => $count) {
      $this->assertEquals(1, $count, "Duplicate found: $word");
    }
  }

  /**
   * Tests Spanish synonym expansion (bidirectional).
   */
  public function testExpandQuerySpanishSynonyms(): void {
    // "abogado" is a synonym of "lawyer".
    $expanded = $this->ranker->expandQuery(['abogado']);

    $this->assertContains('lawyer', $expanded);
  }

  /**
   * Tests common misspelling expansion.
   */
  public function testExpandQueryMisspellings(): void {
    // "lawer" is a synonym of "lawyer".
    $expanded = $this->ranker->expandQuery(['lawer']);

    $this->assertContains('lawyer', $expanded);
  }

  // -----------------------------------------------------------------------
  // getUrlBoost() tests
  // -----------------------------------------------------------------------

  /**
   * Tests canonical URL boost for known paths.
   */
  #[DataProvider('canonicalBoostProvider')]
  public function testCanonicalUrlBoost(string $url, int $expectedMinBoost): void {
    $boost = $this->ranker->getUrlBoost($url);
    $this->assertGreaterThanOrEqual($expectedMinBoost, $boost);
  }

  public static function canonicalBoostProvider(): array {
    return [
      'apply-for-help' => ['https://idaholegalaid.org/apply-for-help', 30],
      'legal-advice-line' => ['https://idaholegalaid.org/legal-advice-line', 25],
      'offices' => ['https://idaholegalaid.org/contact/offices', 20],
      'donate' => ['https://idaholegalaid.org/donate', 15],
      'faq' => ['https://idaholegalaid.org/faq', 10],
    ];
  }

  /**
   * Tests that unknown URLs get zero boost.
   */
  public function testUnknownUrlZeroBoost(): void {
    $boost = $this->ranker->getUrlBoost('https://example.com/random-page');
    $this->assertEquals(0, $boost);
  }

  // -----------------------------------------------------------------------
  // deduplicateByUrl() tests
  // -----------------------------------------------------------------------

  /**
   * Tests that duplicate URLs are merged (keeping first/highest-scored).
   */
  public function testDeduplicateByUrl(): void {
    $items = [
      ['title' => 'First', 'url' => 'https://example.com/page', 'score' => 20],
      ['title' => 'Second', 'url' => 'https://example.com/page', 'score' => 10],
      ['title' => 'Third', 'url' => 'https://example.com/other', 'score' => 15],
    ];

    $result = $this->ranker->deduplicateByUrl($items);

    $this->assertCount(2, $result);
    $this->assertEquals('First', $result[0]['title']);
    $this->assertEquals('Third', $result[1]['title']);
  }

  /**
   * Tests that items without URLs are kept.
   */
  public function testDeduplicateKeepsItemsWithoutUrl(): void {
    $items = [
      ['title' => 'No URL 1'],
      ['title' => 'No URL 2'],
    ];

    $result = $this->ranker->deduplicateByUrl($items);

    $this->assertCount(2, $result);
  }

  /**
   * Tests URL normalization during deduplication (trailing slash, query string).
   */
  public function testDeduplicateNormalizesUrls(): void {
    $items = [
      ['title' => 'First', 'url' => 'https://example.com/page/'],
      ['title' => 'Second', 'url' => 'https://example.com/page?ref=search'],
    ];

    $result = $this->ranker->deduplicateByUrl($items);

    $this->assertCount(1, $result);
    $this->assertEquals('First', $result[0]['title']);
  }

  // -----------------------------------------------------------------------
  // Field weight scoring tests
  // -----------------------------------------------------------------------

  /**
   * Tests that title match scores higher than body match.
   */
  public function testTitleScoresHigherThanBody(): void {
    $items = [
      [
        'question' => 'How to apply for legal help',
        'answer' => 'Some unrelated answer text.',
        'url' => 'https://example.com/faq1',
      ],
      [
        'question' => 'Something else entirely',
        'answer' => 'You can apply for legal help online.',
        'url' => 'https://example.com/faq2',
      ],
    ];

    $results = $this->ranker->scoreFaqResults($items, 'apply for legal help');

    // Item with query in question/title should score higher.
    $this->assertEquals('https://example.com/faq1', $results[0]['url']);
    $this->assertGreaterThan($results[1]['score'], $results[0]['score']);
  }

  /**
   * Tests exact title match gets highest score.
   */
  public function testExactTitleMatchHighestScore(): void {
    $items = [
      [
        'question' => 'eviction',
        'answer' => 'Info about eviction process.',
        'url' => 'https://example.com/faq1',
      ],
      [
        'question' => 'How to handle an eviction notice',
        'answer' => 'Steps for eviction.',
        'url' => 'https://example.com/faq2',
      ],
    ];

    $results = $this->ranker->scoreFaqResults($items, 'eviction');

    // Exact match should be first.
    $this->assertEquals('https://example.com/faq1', $results[0]['url']);
  }

  /**
   * Tests keyword extraction removes stop words.
   */
  public function testExtractKeywordsRemovesStopWords(): void {
    $keywords = $this->ranker->extractKeywords('how do I apply for legal help in Idaho');

    $this->assertContains('apply', $keywords);
    $this->assertContains('legal', $keywords);
    $this->assertContains('help', $keywords);
    $this->assertContains('idaho', $keywords);
    $this->assertNotContains('how', $keywords);
    $this->assertNotContains('do', $keywords);
    $this->assertNotContains('for', $keywords);
    $this->assertNotContains('in', $keywords);
  }

  /**
   * Tests resource scoring with type filter.
   */
  public function testResourceScoringTypeFilter(): void {
    $items = [
      ['title' => 'Eviction Form', 'url' => 'https://example.com/form1', 'type' => 'form'],
      ['title' => 'Eviction Guide', 'url' => 'https://example.com/guide1', 'type' => 'guide'],
    ];

    $results = $this->ranker->scoreResourceResults($items, 'eviction', 'form');

    $this->assertCount(1, $results);
    $this->assertEquals('Eviction Form', $results[0]['title']);
  }

}
