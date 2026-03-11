<?php

namespace Drupal\Tests\ilas_site_assistant\Unit;

require_once __DIR__ . '/../Support/CanonicalUrlFixtures.php';

use Drupal\ilas_site_assistant\Service\FallbackTreeEvaluator;
use Drupal\ilas_site_assistant\Service\TopIntentsPack;
use Drupal\Tests\ilas_site_assistant\Support\CanonicalUrlFixtures;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Tests for FallbackTreeEvaluator — 4-level no-dead-end fallback tree.
 */
#[Group('ilas_site_assistant')]
class FallbackTreeEvaluatorTest extends TestCase {

  /**
   * The TopIntentsPack instance.
   *
   * @var \Drupal\ilas_site_assistant\Service\TopIntentsPack
   */
  protected $pack;

  /**
   * Canonical URL fixture map.
   *
   * @var array
   */
  protected $canonicalUrls;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->pack = new TopIntentsPack(NULL);
    $this->canonicalUrls = CanonicalUrlFixtures::defaults();
  }

  /**
   * Level 1: first failure with known intent.
   */
  public function testLevel1FirstAttempt(): void {
    $result = FallbackTreeEvaluator::evaluateLevel('topic_family_custody', [], [], $this->pack, $this->canonicalUrls);

    $this->assertEquals(1, $result['level']);
    $this->assertNotEmpty($result['message']);
    $this->assertNotEmpty($result['primary_action']);
    $this->assertNotEmpty($result['primary_action']['url']);
    $this->assertGreaterThanOrEqual(2, count($result['links']));
  }

  /**
   * Level 1: area-specific link included for topic intents.
   */
  public function testLevel1IncludesAreaLink(): void {
    $result = FallbackTreeEvaluator::evaluateLevel('topic_housing_eviction', [], [], $this->pack, $this->canonicalUrls);

    $link_urls = array_column($result['links'], 'url');
    $this->assertContains('/legal-help/housing', $link_urls);
  }

  /**
   * Level 2: repeated same-area failure.
   */
  public function testLevel2RepeatedSameArea(): void {
    $history = [
      [
        'role' => 'user',
        'intent' => 'topic_housing',
        'area' => 'housing',
        'response_type' => 'fallback',
        'timestamp' => time() - 60,
      ],
    ];

    $result = FallbackTreeEvaluator::evaluateLevel('topic_housing_eviction', [], $history, $this->pack, $this->canonicalUrls);

    $this->assertEquals(2, $result['level']);
    $this->assertGreaterThanOrEqual(2, count($result['links']));
    $link_urls = array_column($result['links'], 'url');
    $this->assertContains('/legal-help/housing', $link_urls);
  }

  /**
   * Level 3: two consecutive failures.
   */
  public function testLevel3TwoConsecutiveFailures(): void {
    $now = time();
    $history = [
      [
        'role' => 'user',
        'intent' => 'unknown',
        'response_type' => 'fallback',
        'timestamp' => $now - 120,
      ],
      [
        'role' => 'user',
        'intent' => 'unknown',
        'response_type' => 'fallback',
        'timestamp' => $now - 60,
      ],
    ];

    $result = FallbackTreeEvaluator::evaluateLevel('unknown', [], $history, $this->pack, $this->canonicalUrls);

    $this->assertEquals(3, $result['level']);
    $this->assertGreaterThanOrEqual(2, count($result['links']));
    // Level 3 should have prominent contact info.
    $link_urls = array_column($result['links'], 'url');
    $this->assertContains('/apply-for-help', $link_urls);
    $this->assertContains('/Legal-Advice-Line', $link_urls);
    $this->assertContains('/contact/offices', $link_urls);
  }

  /**
   * Level 4: three or more consecutive failures (terminal).
   */
  public function testLevel4Terminal(): void {
    $now = time();
    $history = [
      ['intent' => 'unknown', 'response_type' => 'fallback', 'timestamp' => $now - 180],
      ['intent' => 'unknown', 'response_type' => 'fallback', 'timestamp' => $now - 120],
      ['intent' => 'unknown', 'response_type' => 'fallback', 'timestamp' => $now - 60],
    ];

    $result = FallbackTreeEvaluator::evaluateLevel('unknown', [], $history, $this->pack, $this->canonicalUrls);

    $this->assertEquals(4, $result['level']);
    $this->assertStringContainsString('connect you', strtolower($result['message']));
    $this->assertGreaterThanOrEqual(2, count($result['links']));
  }

  /**
   * Invariant: every level includes at least 2 actionable links.
   */
  #[DataProvider('levelScenariosProvider')]
  public function testInvariantMinTwoLinks(string $intent, array $history): void {
    $result = FallbackTreeEvaluator::evaluateLevel($intent, [], $history, $this->pack, $this->canonicalUrls);

    $this->assertGreaterThanOrEqual(2, count($result['links']),
      "Level {$result['level']} must have >= 2 links");
    // All links must have url.
    foreach ($result['links'] as $link) {
      $this->assertNotEmpty($link['url'], 'Every link must have a url');
      $this->assertNotEmpty($link['label'], 'Every link must have a label');
    }
    // primary_action must be present.
    $this->assertNotEmpty($result['primary_action']['url']);
    $this->assertNotEmpty($result['primary_action']['label']);
  }

  /**
   * Data provider for the invariant test.
   */
  public static function levelScenariosProvider(): array {
    $now = time();
    return [
      'Level 1 - known intent' => ['topic_family_custody', []],
      'Level 1 - unknown intent' => ['unknown', []],
      'Level 2 - repeated area' => ['topic_housing', [
        ['intent' => 'topic_housing', 'area' => 'housing', 'response_type' => 'fallback', 'timestamp' => $now - 60],
      ]],
      'Level 3 - two failures' => ['unknown', [
        ['intent' => 'unknown', 'response_type' => 'fallback', 'timestamp' => $now - 120],
        ['intent' => 'unknown', 'response_type' => 'fallback', 'timestamp' => $now - 60],
      ]],
      'Level 4 - three failures' => ['unknown', [
        ['intent' => 'unknown', 'response_type' => 'fallback', 'timestamp' => $now - 180],
        ['intent' => 'unknown', 'response_type' => 'fallback', 'timestamp' => $now - 120],
        ['intent' => 'unknown', 'response_type' => 'fallback', 'timestamp' => $now - 60],
      ]],
    ];
  }

  /**
   * resolveArea maps intent keys to service areas.
   */
  public function testResolveAreaMappings(): void {
    $this->assertEquals('housing', FallbackTreeEvaluator::resolveArea('topic_housing'));
    $this->assertEquals('housing', FallbackTreeEvaluator::resolveArea('topic_housing_eviction'));
    $this->assertEquals('family', FallbackTreeEvaluator::resolveArea('topic_family'));
    $this->assertEquals('family', FallbackTreeEvaluator::resolveArea('topic_family_custody'));
    $this->assertEquals('consumer', FallbackTreeEvaluator::resolveArea('topic_consumer'));
    $this->assertEquals('consumer', FallbackTreeEvaluator::resolveArea('topic_consumer_debt_collection'));
    $this->assertNull(FallbackTreeEvaluator::resolveArea('unknown'));
    $this->assertNull(FallbackTreeEvaluator::resolveArea('apply_for_help'));
  }

  /**
   * Level 1 with pack includes suggestions from clarifier or chips.
   */
  public function testLevel1WithPackIncludesSuggestions(): void {
    // topic_family has a clarifier, so we should get suggestions.
    $result = FallbackTreeEvaluator::evaluateLevel('topic_family', [], [], $this->pack, $this->canonicalUrls);

    $this->assertEquals(1, $result['level']);
    $this->assertNotEmpty($result['suggestions']);
  }

  /**
   * Non-consecutive fallbacks don't escalate level.
   */
  public function testNonConsecutiveFallbacksStayLevel1(): void {
    $now = time();
    $history = [
      ['intent' => 'unknown', 'response_type' => 'fallback', 'timestamp' => $now - 180],
      ['intent' => 'topic_housing', 'response_type' => 'navigation', 'timestamp' => $now - 120],
      ['intent' => 'unknown', 'response_type' => 'fallback', 'timestamp' => $now - 60],
    ];

    $result = FallbackTreeEvaluator::evaluateLevel('unknown', [], $history, $this->pack, $this->canonicalUrls);

    // Only 1 consecutive fallback (the last one), so Level 1.
    $this->assertEquals(1, $result['level']);
  }

}
