<?php

namespace Drupal\Tests\ilas_site_assistant\Unit;

use Drupal\ilas_site_assistant\Service\HistoryIntentResolver;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests for HistoryIntentResolver — history-based intent fallback.
 */
#[Group('ilas_site_assistant')]
class HistoryIntentResolverTest extends TestCase {

  /**
   * Helper to build a history entry.
   */
  private function entry(string $intent, int $timestamp, string $text = ''): array {
    return [
      'role' => 'user',
      'text' => $text ?: "test message for $intent",
      'intent' => $intent,
      'safety_flags' => [],
      'timestamp' => $timestamp,
    ];
  }

  /**
   * 3 turns of housing, then an ambiguous follow-up.
   */
  public function testTopicEstablishedFollowUp(): void {
    $now = 1000000;
    $history = [
      $this->entry('topic_housing', $now - 120, 'I am being evicted'),
      $this->entry('topic_housing', $now - 90, 'what are my rights as a tenant'),
      $this->entry('topic_housing', $now - 60, 'can they change the locks'),
    ];

    $result = HistoryIntentResolver::resolveFromHistory(
      $history, 'what about those mediation programs?', $now
    );

    $this->assertNotNull($result);
    $this->assertEquals('topic_housing', $result['intent']);
    $this->assertEquals(3, $result['turns_analyzed']);
    $this->assertGreaterThanOrEqual(0.5, $result['confidence']);
  }

  /**
   * Direct routing handles topic shifts — this tests that the resolver
   * itself would still return housing if called (controller won't call it
   * because direct routing won't return unknown for "bankruptcy").
   */
  public function testTopicShiftHandledByDirectRouting(): void {
    $now = 1000000;
    $history = [
      $this->entry('topic_housing', $now - 120),
      $this->entry('topic_housing', $now - 90),
      $this->entry('topic_housing', $now - 60),
    ];

    // "Switching gears" triggers reset signal — resolver returns NULL.
    $result = HistoryIntentResolver::resolveFromHistory(
      $history, 'Switching gears: tell me about bankruptcy', $now
    );

    $this->assertNull($result);
  }

  /**
   * Explicit reset signal suppresses fallback.
   */
  public function testExplicitResetSignal(): void {
    $now = 1000000;
    $history = [
      $this->entry('topic_housing', $now - 120),
      $this->entry('topic_housing', $now - 90),
      $this->entry('topic_housing', $now - 60),
    ];

    $result = HistoryIntentResolver::resolveFromHistory(
      $history, 'New question: where is your office?', $now
    );

    $this->assertNull($result);
  }

  /**
   * History entries older than the time window are ignored.
   */
  public function testStaleHistoryIgnored(): void {
    $now = 1000000;
    $history = [
      $this->entry('topic_housing', $now - 700),  // >600s ago
      $this->entry('topic_housing', $now - 650),  // >600s ago
      $this->entry('topic_housing', $now - 620),  // >600s ago
    ];

    $result = HistoryIntentResolver::resolveFromHistory(
      $history, 'what about mediation?', $now
    );

    $this->assertNull($result);
  }

  /**
   * Direct match (not unknown) means controller won't call resolver at all.
   * This test confirms the resolver's behavior is independent.
   */
  public function testDirectMatchPreserved(): void {
    // This is really a controller-level concern, but we verify the resolver
    // returns a result if called — the controller is responsible for NOT
    // calling it when direct routing succeeds.
    $now = 1000000;
    $history = [
      $this->entry('topic_housing', $now - 60),
    ];

    $result = HistoryIntentResolver::resolveFromHistory(
      $history, 'I want to apply for help', $now
    );

    // Resolver would return housing, but controller won't call it.
    $this->assertNotNull($result);
    $this->assertEquals('topic_housing', $result['intent']);
  }

  /**
   * History of only excluded intents returns NULL.
   */
  public function testExcludedIntentsNotPropagated(): void {
    $now = 1000000;
    $history = [
      $this->entry('greeting', $now - 120),
      $this->entry('unknown', $now - 90),
      $this->entry('disambiguation', $now - 60),
    ];

    $result = HistoryIntentResolver::resolveFromHistory(
      $history, 'tell me more', $now
    );

    $this->assertNull($result);
  }

  /**
   * Tied intents produce no fallback.
   */
  public function testTiedIntentsNoFallback(): void {
    $now = 1000000;
    $history = [
      $this->entry('topic_housing', $now - 120),
      $this->entry('topic_housing', $now - 100),
      $this->entry('topic_family', $now - 80),
      $this->entry('topic_family', $now - 60),
    ];

    $result = HistoryIntentResolver::resolveFromHistory(
      $history, 'what about that?', $now
    );

    $this->assertNull($result);
  }

  /**
   * Single turn of housing is enough (1/1 = 100% dominance).
   */
  public function testSingleTurnDominance(): void {
    $now = 1000000;
    $history = [
      $this->entry('topic_housing', $now - 60),
    ];

    $result = HistoryIntentResolver::resolveFromHistory(
      $history, 'what about mediation?', $now
    );

    $this->assertNotNull($result);
    $this->assertEquals('topic_housing', $result['intent']);
    $this->assertEquals(1.0, $result['confidence']);
    $this->assertEquals(1, $result['turns_analyzed']);
  }

  /**
   * Dominance threshold enforced: 2/4 = 50% meets threshold for family.
   */
  public function testDominanceThresholdEnforced(): void {
    $now = 1000000;
    $history = [
      $this->entry('topic_housing', $now - 120),
      $this->entry('topic_family', $now - 100),
      $this->entry('topic_family', $now - 80),
      $this->entry('topic_consumer', $now - 60),
    ];

    $result = HistoryIntentResolver::resolveFromHistory(
      $history, 'tell me more about that', $now
    );

    $this->assertNotNull($result);
    $this->assertEquals('topic_family', $result['intent']);
    $this->assertEquals(0.5, $result['confidence']);
  }

  /**
   * Spanish reset signal detected.
   */
  public function testResetSignalSpanish(): void {
    $this->assertTrue(HistoryIntentResolver::detectResetSignal('otra pregunta por favor'));
    $this->assertTrue(HistoryIntentResolver::detectResetSignal('Quiero cambiar de tema'));
    $this->assertTrue(HistoryIntentResolver::detectResetSignal('otra cosa necesito'));
  }

  /**
   * Max turns config respected — only last 6 analyzed by default.
   */
  public function testMaxTurnsRespected(): void {
    $now = 1000000;
    $history = [];

    // 7 turns of consumer (oldest), then 3 turns of housing (newest).
    for ($i = 0; $i < 7; $i++) {
      $history[] = $this->entry('topic_consumer', $now - (300 - $i * 10));
    }
    for ($i = 0; $i < 3; $i++) {
      $history[] = $this->entry('topic_housing', $now - (30 - $i * 10));
    }

    // With max_turns=6, only the last 6 entries are analyzed.
    // That's 3 consumer + 3 housing = tied → NULL.
    $result = HistoryIntentResolver::resolveFromHistory(
      $history, 'what about that?', $now, ['history_max_turns' => 6]
    );

    $this->assertNull($result, 'Tied intents within max_turns window should return NULL');
  }

  /**
   * Empty history returns NULL.
   */
  public function testEmptyHistoryReturnsNull(): void {
    $result = HistoryIntentResolver::resolveFromHistory(
      [], 'hello there', time()
    );

    $this->assertNull($result);
  }

}
