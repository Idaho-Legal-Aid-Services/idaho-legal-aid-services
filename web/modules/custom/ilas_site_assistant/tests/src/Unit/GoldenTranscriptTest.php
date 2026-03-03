<?php

namespace Drupal\Tests\ilas_site_assistant\Unit;

use Drupal\ilas_site_assistant\Service\Disambiguator;
use Drupal\ilas_site_assistant\Service\HistoryIntentResolver;
use Drupal\ilas_site_assistant\Service\TurnClassifier;
use Drupal\ilas_site_assistant\Service\TopIntentsPack;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Yaml\Yaml;

/**
 * Golden transcript tests — validates full kernel flow per transcript.
 *
 * Loads golden transcripts from tests/goldens/golden-transcripts.yml and
 * replays each turn through TurnClassifier + HistoryIntentResolver +
 * TopIntentsPack, validating turn_type, intent, and chip presence.
 */
#[Group('ilas_site_assistant')]
class GoldenTranscriptTest extends TestCase {

  /**
   * Parsed golden transcripts.
   *
   * @var array
   */
  protected static $transcripts;

  /**
   * The TopIntentsPack instance.
   *
   * @var \Drupal\ilas_site_assistant\Service\TopIntentsPack
   */
  protected $pack;

  /**
   * {@inheritdoc}
   */
  public static function setUpBeforeClass(): void {
    parent::setUpBeforeClass();
    $yaml_path = dirname(__DIR__, 2) . '/goldens/golden-transcripts.yml';
    if (!file_exists($yaml_path)) {
      self::markTestSkipped("Golden transcripts file not found: $yaml_path");
    }
    self::$transcripts = Yaml::parseFile($yaml_path);
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->pack = new TopIntentsPack(NULL);
  }

  /**
   * Validates the YAML structure loads correctly.
   */
  public function testGoldenFileLoads(): void {
    $this->assertNotEmpty(self::$transcripts);
    $this->assertArrayHasKey('transcripts', self::$transcripts);
    $this->assertNotEmpty(self::$transcripts['transcripts']);
  }

  /**
   * Validates each transcript has required fields.
   */
  public function testTranscriptStructure(): void {
    foreach (self::$transcripts['transcripts'] as $i => $transcript) {
      $this->assertArrayHasKey('id', $transcript, "Transcript #$i missing id");
      $this->assertArrayHasKey('turns', $transcript, "Transcript '{$transcript['id']}' missing turns");
      $this->assertNotEmpty($transcript['turns'], "Transcript '{$transcript['id']}' has no turns");

      foreach ($transcript['turns'] as $j => $turn) {
        $this->assertArrayHasKey('message', $turn,
          "Transcript '{$transcript['id']}' turn #$j missing message");
        $this->assertArrayHasKey('expected_turn_type', $turn,
          "Transcript '{$transcript['id']}' turn #$j missing expected_turn_type");
      }
    }
  }

  /**
   * Replays all golden transcripts and validates turn types.
   */
  public function testTurnTypeClassification(): void {
    $now = 1000000;

    foreach (self::$transcripts['transcripts'] as $transcript) {
      $id = $transcript['id'];
      $server_history = [];
      $turn_time = $now;

      foreach ($transcript['turns'] as $j => $turn) {
        $message = $turn['message'];
        $expected_type = $turn['expected_turn_type'];

        $actual_type = TurnClassifier::classifyTurn($message, $server_history, $turn_time);

        $this->assertEquals($expected_type, $actual_type,
          "Transcript '$id' turn #$j ('$message'): expected $expected_type, got $actual_type");

        // Build simulated history entry for next turn.
        $intent = 'unknown';
        if ($actual_type === TurnClassifier::TURN_INVENTORY) {
          $intent = TurnClassifier::resolveInventoryType($message);
        }

        // Check synonym matching for intent resolution.
        $synonym_match = $this->pack->matchSynonyms($message);
        if ($synonym_match) {
          $intent = $synonym_match;
        }

        $server_history[] = [
          'role' => 'user',
          'text' => $message,
          'intent' => $intent,
          'timestamp' => $turn_time,
          'safety_flags' => [],
        ];

        $turn_time += 30;
      }
    }
  }

  /**
   * Validates expected intents match when specified.
   */
  public function testExpectedIntents(): void {
    $disambiguator = new Disambiguator();

    foreach (self::$transcripts['transcripts'] as $transcript) {
      $id = $transcript['id'];

      foreach ($transcript['turns'] as $j => $turn) {
        $expected_intent = $turn['expected_intent'] ?? NULL;
        if ($expected_intent === NULL) {
          continue;
        }

        $message = $turn['message'];

        // For INVENTORY turns, check resolveInventoryType.
        if ($turn['expected_turn_type'] === 'INVENTORY') {
          $actual = TurnClassifier::resolveInventoryType($message);
          $this->assertEquals($expected_intent, $actual,
            "Transcript '$id' turn #$j: expected inventory type '$expected_intent', got '$actual'");
        }

        // For disambiguation turns, verify Disambiguator triggers.
        if ($turn['expected_turn_type'] === 'NEW' && $expected_intent === 'disambiguation') {
          $result = $disambiguator->check($message, []);
          $this->assertNotNull($result,
            "Transcript '$id' turn #$j: '$message' should trigger disambiguation");
          $this->assertSame('disambiguation', $result['type'],
            "Transcript '$id' turn #$j: '$message' should return type=disambiguation");
        }
      }
    }
  }

  /**
   * Validates disambiguation options expose canonical 'intent' key, no 'value'.
   */
  public function testDisambiguationOptionsHaveIntentKey(): void {
    $disambiguator = new Disambiguator();

    foreach (self::$transcripts['transcripts'] as $transcript) {
      $id = $transcript['id'];

      foreach ($transcript['turns'] as $j => $turn) {
        $expected_intent = $turn['expected_intent'] ?? NULL;
        if ($expected_intent !== 'disambiguation') {
          continue;
        }

        $message = $turn['message'];
        $result = $disambiguator->check($message, []);
        $this->assertNotNull($result,
          "Transcript '$id' turn #$j: '$message' should trigger disambiguation");

        foreach ($result['options'] as $k => $option) {
          $this->assertArrayHasKey('intent', $option,
            "Transcript '$id' turn #$j option #$k must have 'intent' key");
          $this->assertNotEmpty($option['intent'],
            "Transcript '$id' turn #$j option #$k must have non-empty 'intent'");
          $this->assertArrayNotHasKey('value', $option,
            "Transcript '$id' turn #$j option #$k must not have deprecated 'value' key");
        }
      }
    }
  }

  /**
   * Validates chip presence for turns that expect chips.
   */
  public function testChipPresence(): void {
    foreach (self::$transcripts['transcripts'] as $transcript) {
      $id = $transcript['id'];

      foreach ($transcript['turns'] as $j => $turn) {
        $expected_chips = $turn['expected_chips_present'] ?? NULL;
        if ($expected_chips === NULL) {
          continue;
        }

        $message = $turn['message'];

        // Check if intent has chips in the pack.
        $synonym_match = $this->pack->matchSynonyms($message);
        if ($synonym_match && $expected_chips) {
          $chips = $this->pack->getChips($synonym_match);
          $this->assertNotEmpty($chips,
            "Transcript '$id' turn #$j ('$message' → '$synonym_match'): expected chips but pack has none");
        }

        // For INVENTORY turns, verify the inventory intent has chips.
        if ($turn['expected_turn_type'] === 'INVENTORY' && $expected_chips) {
          $inventory_type = TurnClassifier::resolveInventoryType($message);
          $chips = $this->pack->getChips($inventory_type);
          $this->assertNotEmpty($chips,
            "Transcript '$id' turn #$j: inventory '$inventory_type' should have chips");
        }
      }
    }
  }

  /**
   * Validates that history resolver finds context in multi-turn transcripts.
   */
  public function testHistoryResolutionInMultiTurn(): void {
    $now = 1000000;

    // Replay transcript A scenario (happy_path_custody).
    $history = [
      [
        'role' => 'user',
        'text' => 'I need help with custody',
        'intent' => 'topic_family_custody',
        'area' => 'family',
        'timestamp' => $now - 60,
        'safety_flags' => [],
      ],
    ];

    $resolved = HistoryIntentResolver::resolveFromHistory(
      $history, 'tell me more about that', $now
    );

    $this->assertNotNull($resolved, 'History resolver should find context for follow-up');
    $this->assertEquals('topic_family_custody', $resolved['intent']);
  }

}
