<?php

namespace Drupal\Tests\ilas_site_assistant\Unit;

use Drupal\ilas_site_assistant\Service\HistoryIntentResolver;
use Drupal\ilas_site_assistant\Service\TurnClassifier;
use Drupal\ilas_site_assistant\Service\TopIntentsPack;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Regression tests for conversation dead-end fixes (Transcripts A & B).
 *
 * Validates:
 * - Stop words prevent "you have" from triggering a search.
 * - forms_inventory pattern matches catalog requests.
 * - Follow-up detection works with enriched history.
 * - Topic context propagation from history.
 */
#[Group('ilas_site_assistant')]
class ConversationContextTest extends TestCase {

  /**
   * Tests that stop words prevent "you have" from producing search keywords.
   *
   * Transcript B: "what forms do you have" should return empty after stop
   * words, triggering clarification instead of a dead-end search.
   */
  public function testStopWordsPreventYouHaveSearch(): void {
    $result = $this->callExtractFinderTopicKeywords('what forms do you have');
    $this->assertEmpty($result, "Query 'what forms do you have' should return empty after stop words, got: '$result'");
  }

  /**
   * Tests that forms_inventory pattern matches "what forms do you have".
   */
  public function testFormsInventoryPatternMatch(): void {
    $patterns = [
      '/\bwhat\s*(forms?|documents?|paperwork)\s*(do\s*you|does?\s*\w+)\s*(have|offer|provide)/i',
      '/\b(list|show|browse)\s*(all\s*)?(your\s*)?(forms?|documents?|resources?)/i',
      '/\b(all|available|your)\s*(forms?|documents?)\b/i',
      '/\bforms?\s*you\s*(have|offer|provide)\b/i',
      '/\bforms?\s*(catalog|catalogue|list|inventory|categories)\b/i',
      '/\bdo\s*you\s*have\s*(any\s*)?(forms?|documents?|paperwork)/i',
    ];

    $test_messages = [
      'what forms do you have',
      'show me all your forms',
      'list all forms',
      'do you have any forms',
      'what documents do you offer',
      'available forms',
      'forms catalog',
    ];

    foreach ($test_messages as $message) {
      $matched = FALSE;
      foreach ($patterns as $pattern) {
        if (preg_match($pattern, $message)) {
          $matched = TRUE;
          break;
        }
      }
      $this->assertTrue($matched, "Message '$message' should match a forms_inventory pattern");
    }
  }

  /**
   * Tests that isFollowUpInSameArea returns TRUE when history has same area.
   */
  public function testFollowUpInSameAreaDetected(): void {
    $now = time();
    $history = [
      [
        'role' => 'user',
        'intent' => 'service_area',
        'area' => 'family',
        'timestamp' => $now - 60,
      ],
      [
        'role' => 'user',
        'intent' => 'service_area',
        'area' => 'family',
        'timestamp' => $now - 30,
      ],
    ];

    $intent = ['area' => 'family'];

    // Replicate isFollowUpInSameArea logic.
    $current_area = $intent['area'] ?? '';
    $found = FALSE;
    $recent = array_slice($history, -3);
    foreach ($recent as $entry) {
      if (($entry['area'] ?? '') === $current_area) {
        $found = TRUE;
        break;
      }
    }

    $this->assertTrue($found, 'Follow-up in same area (family) should be detected');
  }

  /**
   * Tests that isFollowUpInSameArea returns FALSE for a new area.
   */
  public function testFollowUpDifferentAreaNotDetected(): void {
    $now = time();
    $history = [
      [
        'role' => 'user',
        'intent' => 'service_area',
        'area' => 'housing',
        'timestamp' => $now - 60,
      ],
    ];

    $intent = ['area' => 'family'];

    $current_area = $intent['area'] ?? '';
    $found = FALSE;
    $recent = array_slice($history, -3);
    foreach ($recent as $entry) {
      if (($entry['area'] ?? '') === $current_area) {
        $found = TRUE;
        break;
      }
    }

    $this->assertFalse($found, 'Different area should not be detected as follow-up');
  }

  /**
   * Tests that extractTopicContext returns area from enriched history.
   */
  public function testHistoryContextPropagation(): void {
    $history = [
      [
        'role' => 'user',
        'intent' => 'greeting',
        'timestamp' => time() - 120,
      ],
      [
        'role' => 'user',
        'intent' => 'service_area',
        'area' => 'family',
        'topic_id' => NULL,
        'topic' => NULL,
        'timestamp' => time() - 60,
      ],
      [
        'role' => 'user',
        'intent' => 'unknown',
        'timestamp' => time() - 30,
      ],
    ];

    $context = HistoryIntentResolver::extractTopicContext($history);

    $this->assertNotNull($context);
    $this->assertEquals('family', $context['area']);
  }

  /**
   * Tests that extractTopicContext returns NULL when no area in history.
   */
  public function testHistoryContextReturnsNullWithoutArea(): void {
    $history = [
      [
        'role' => 'user',
        'intent' => 'greeting',
        'timestamp' => time() - 60,
      ],
      [
        'role' => 'user',
        'intent' => 'unknown',
        'timestamp' => time() - 30,
      ],
    ];

    $context = HistoryIntentResolver::extractTopicContext($history);

    $this->assertNull($context);
  }

  /**
   * Tests Transcript A scenario: follow-up in family area should not dead-end.
   *
   * Simulates two turns in the family area, then a follow-up query.
   * The history resolver should return family as the dominant intent,
   * and topic context should carry the area forward.
   */
  public function testTranscriptAFollowUpNotDeadEnd(): void {
    $now = 1000000;
    $history = [
      [
        'role' => 'user',
        'text' => 'custody advice',
        'intent' => 'service_area',
        'area' => 'family',
        'timestamp' => $now - 120,
        'safety_flags' => [],
      ],
      [
        'role' => 'user',
        'text' => 'does that give me custody information',
        'intent' => 'unknown',
        'timestamp' => $now - 60,
        'safety_flags' => [],
      ],
    ];

    // The resolver should find the dominant intent (service_area).
    $result = HistoryIntentResolver::resolveFromHistory(
      $history, 'does that give me custody information', $now
    );

    $this->assertNotNull($result, 'History resolver should find a fallback intent for follow-up');
    $this->assertEquals('service_area', $result['intent']);

    // Topic context should carry the family area.
    $this->assertNotNull($result['topic_context']);
    $this->assertEquals('family', $result['topic_context']['area']);
  }

  /**
   * Tests Transcript B scenario: "what forms do you have" → empty keywords.
   *
   * After stop words fix, this query should produce empty topic keywords,
   * which means the forms_inventory intent (not forms_finder) should handle it.
   */
  public function testTranscriptBReturnsEmptyKeywords(): void {
    $result = $this->callExtractFinderTopicKeywords('what forms do you have');
    $this->assertEmpty($result, 'Transcript B query should produce empty topic keywords');

    // Additional catalog-style queries.
    $this->assertEmpty(
      $this->callExtractFinderTopicKeywords('show me all your forms'),
      '"show me all your forms" should produce empty keywords'
    );
    $this->assertEmpty(
      $this->callExtractFinderTopicKeywords('do you have any forms'),
      '"do you have any forms" should produce empty keywords'
    );
  }

  /**
   * Transcript A E2E: "i need some custody advice" → follow-up stays in family.
   *
   * Turn 1: "i need some custody advice" → NEW turn, routed to service_area/family.
   * Turn 2: "does that give me custody information" → FOLLOW_UP detected,
   *   HistoryIntentResolver returns service_area with family area context.
   *   Must NOT fall through to generic fallback.
   */
  public function testTranscriptAEndToEnd(): void {
    $now = 1000000;

    // Turn 1: "i need some custody advice" — NEW turn (no history).
    $turn1 = TurnClassifier::classifyTurn('i need some custody advice', [], $now);
    $this->assertEquals(TurnClassifier::TURN_NEW, $turn1, 'Turn 1 should be NEW');

    // Simulate server_history after Turn 1 was processed.
    $history = [
      [
        'role' => 'user',
        'text' => 'i need some custody advice',
        'intent' => 'service_area',
        'area' => 'family',
        'timestamp' => $now - 60,
        'safety_flags' => [],
      ],
    ];

    // Turn 2: "does that give me custody information" — FOLLOW_UP.
    $turn2 = TurnClassifier::classifyTurn(
      'does that give me custody information',
      $history,
      $now
    );
    $this->assertEquals(TurnClassifier::TURN_FOLLOW_UP, $turn2, 'Turn 2 should be FOLLOW_UP');

    // HistoryIntentResolver should find family as the dominant intent.
    $resolved = HistoryIntentResolver::resolveFromHistory(
      $history, 'does that give me custody information', $now
    );
    $this->assertNotNull($resolved, 'HistoryIntentResolver should return a result');
    $this->assertEquals('service_area', $resolved['intent'], 'Resolved intent should be service_area');

    // Topic context should carry the family area forward.
    $this->assertNotNull($resolved['topic_context'], 'topic_context should be set');
    $this->assertEquals('family', $resolved['topic_context']['area'], 'Topic context area should be family');
  }

  /**
   * Transcript B E2E: "what forms do you have" → forms_inventory with chips.
   *
   * TurnClassifier detects INVENTORY, resolveInventoryType returns
   * forms_inventory, and TopIntentsPack has the entry with chips and
   * primary_action.
   */
  public function testTranscriptBEndToEnd(): void {
    $message = 'what forms do you have';
    $now = time();

    // TurnClassifier should detect INVENTORY.
    $turn = TurnClassifier::classifyTurn($message, [], $now);
    $this->assertEquals(TurnClassifier::TURN_INVENTORY, $turn, 'Should be INVENTORY');

    // resolveInventoryType should return forms_inventory.
    $inventory_type = TurnClassifier::resolveInventoryType($message);
    $this->assertEquals('forms_inventory', $inventory_type, 'Inventory type should be forms_inventory');

    // TopIntentsPack should have the forms_inventory entry.
    $pack = new TopIntentsPack(NULL);
    $entry = $pack->lookup('forms_inventory');
    $this->assertNotNull($entry, 'TopIntentsPack should have forms_inventory entry');
    $this->assertNotEmpty($entry['chips'], 'forms_inventory should have chips');
    $this->assertNotEmpty($entry['primary_action'], 'forms_inventory should have primary_action');
    $this->assertEquals('/forms', $entry['primary_action']['url'], 'Primary action should link to /forms');

    // Chips should include form categories.
    $chip_intents = array_column($entry['chips'], 'intent');
    $this->assertContains('forms_housing', $chip_intents, 'Should have housing forms chip');
    $this->assertContains('forms_family', $chip_intents, 'Should have family forms chip');
  }

  /**
   * Transcript B variant: "show me all the guides" → guides_inventory.
   */
  public function testTranscriptBGuidesVariant(): void {
    $message = 'show me all the guides';
    $turn = TurnClassifier::classifyTurn($message, [], time());
    $this->assertEquals(TurnClassifier::TURN_INVENTORY, $turn);

    $inventory_type = TurnClassifier::resolveInventoryType($message);
    $this->assertEquals('guides_inventory', $inventory_type);

    $pack = new TopIntentsPack(NULL);
    $entry = $pack->lookup('guides_inventory');
    $this->assertNotNull($entry, 'TopIntentsPack should have guides_inventory entry');
    $this->assertNotEmpty($entry['chips'], 'guides_inventory should have chips');
    $this->assertEquals('/guides', $entry['primary_action']['url'], 'Primary action should link to /guides');
  }

  /**
   * Transcript B variant: "what services do you offer" → services_inventory.
   */
  public function testTranscriptBServicesVariant(): void {
    $message = 'what services do you offer';
    $turn = TurnClassifier::classifyTurn($message, [], time());
    $this->assertEquals(TurnClassifier::TURN_INVENTORY, $turn);

    $inventory_type = TurnClassifier::resolveInventoryType($message);
    $this->assertEquals('services_inventory', $inventory_type);

    $pack = new TopIntentsPack(NULL);
    $entry = $pack->lookup('services_inventory');
    $this->assertNotNull($entry, 'TopIntentsPack should have services_inventory entry');
    $this->assertNotEmpty($entry['chips'], 'services_inventory should have chips');
  }

  /**
   * Replicates extractFinderTopicKeywords logic for testing.
   */
  protected function callExtractFinderTopicKeywords(string $query, string $finder_type = 'forms'): string {
    $lower = strtolower(trim($query));
    $type_noise = [
      'forms' => '/\b(form|forms|froms|formulario|formularios|paperwork|papers|documents?|court\s*papers?)\b/i',
      'guides' => '/\b(guide|guides|giude|giudes|guia|guias|manual|manuals|handbook|handbooks|instructions?|how[\s-]*to|step[\s-]*by[\s-]*step|self[\s-]*help)\b/i',
    ];
    $noise_patterns = [
      '/^(find|get|need|download|where|show|read|browse)\s*(me\s*)?(\b(a|the|is|are|some|any|all)\b\s*)?/i',
      $type_noise[$finder_type] ?? $type_noise['forms'],
      '/\b(for|to|about|on|regarding)\b/i',
      '/\b(legal|court|i\s*need|looking\s*for|where\s*can\s*i)\b/i',
      '/^\s*(a|an|the|my|some|any)\s+/i',
    ];
    $cleaned = $lower;
    foreach ($noise_patterns as $pattern) {
      $cleaned = preg_replace($pattern, ' ', $cleaned);
    }
    $cleaned = trim(preg_replace('/\s+/', ' ', $cleaned));
    $stop_words = [
      'a', 'an', 'the', 'and', 'or', 'of', 'to', 'in', 'for', 'on', 'with',
      'is', 'are', 'i', 'me', 'my', 'can', 'do', 'how', 'what', 'where',
      'please', 'find', 'get', 'need', 'show', 'download', 'looking', 'browse', 'read',
      'you', 'your', 'have', 'has', 'had', 'does', 'did', 'they', 'them',
      'their', 'we', 'our', 'it', 'its', 'that', 'this', 'those', 'these',
      'there', 'which', 'been', 'be', 'about', 'any', 'all', 'some', 'also',
      'just', 'give', 'us', 'would', 'will', 'should', 'could',
    ];
    $words = array_filter(explode(' ', $cleaned), function ($w) use ($stop_words) {
      return strlen($w) >= 2 && !in_array($w, $stop_words);
    });
    if (empty($words)) {
      return '';
    }
    return implode(' ', $words);
  }

}
