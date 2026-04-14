<?php

namespace Drupal\Tests\ilas_site_assistant\Unit;

use Drupal\ilas_site_assistant\Service\TopIntentsPack;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests for TopIntentsPack — unified intents config loader.
 */
#[Group('ilas_site_assistant')]
class TopIntentsPackTest extends TestCase {

  /**
   * The service under test.
   *
   * @var \Drupal\ilas_site_assistant\Service\TopIntentsPack
   */
  protected $pack;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // No cache — load fresh from YAML every time.
    $this->pack = new TopIntentsPack(NULL);
  }

  /**
   * Version is present and non-empty.
   */
  public function testVersionReturned(): void {
    $version = $this->pack->getVersion();
    $this->assertNotEmpty($version);
    $this->assertNotEquals('missing', $version);
    $this->assertNotEquals('empty', $version);
  }

  /**
   * getAllKeys returns a non-empty set of intent keys.
   */
  public function testGetAllKeysNonEmpty(): void {
    $keys = $this->pack->getAllKeys();
    $this->assertNotEmpty($keys);
    $this->assertGreaterThanOrEqual(30, count($keys));
  }

  /**
   * lookup returns NULL for unknown keys.
   */
  public function testLookupUnknownReturnsNull(): void {
    $this->assertNull($this->pack->lookup('nonexistent_intent_xyz'));
  }

  /**
   * lookup returns full entry for a known intent.
   */
  public function testLookupKnownReturnsEntry(): void {
    $entry = $this->pack->lookup('apply_for_help');
    $this->assertNotNull($entry);
    $this->assertArrayHasKey('label', $entry);
    $this->assertArrayHasKey('answer_text', $entry);
    $this->assertArrayHasKey('primary_action', $entry);
    $this->assertArrayHasKey('chips', $entry);
    $this->assertArrayHasKey('synonyms', $entry);
  }

  /**
   * getChips returns non-empty array for known intents.
   */
  public function testGetChipsReturnsArray(): void {
    $chips = $this->pack->getChips('topic_family');
    $this->assertNotEmpty($chips);
    $this->assertIsArray($chips);
    // Each chip has label and intent.
    foreach ($chips as $chip) {
      $this->assertArrayHasKey('label', $chip);
      $this->assertArrayHasKey('intent', $chip);
    }
  }

  /**
   * getChips returns empty array for unknown intents.
   */
  public function testGetChipsUnknownReturnsEmpty(): void {
    $chips = $this->pack->getChips('nonexistent_xyz');
    $this->assertEmpty($chips);
  }

  /**
   * getClarifier returns NULL for intents without clarifiers.
   */
  public function testGetClarifierReturnsNull(): void {
    $clarifier = $this->pack->getClarifier('apply_for_help');
    $this->assertNull($clarifier);
  }

  /**
   * matchSynonyms finds "custody" -> topic_family_custody.
   */
  public function testMatchSynonymsCustody(): void {
    $result = $this->pack->matchSynonyms('custody');
    $this->assertEquals('topic_family_custody', $result);
  }

  /**
   * matchSynonyms finds "eviction" -> topic_housing_eviction.
   */
  public function testMatchSynonymsEviction(): void {
    $result = $this->pack->matchSynonyms('eviction');
    $this->assertEquals('topic_housing_eviction', $result);
  }

  /**
   * matchSynonyms finds Spanish synonym.
   */
  public function testMatchSynonymsSpanish(): void {
    $result = $this->pack->matchSynonyms('custodia');
    $this->assertEquals('topic_family_custody', $result);
  }

  /**
   * matchSynonyms returns NULL for gibberish.
   */
  public function testMatchSynonymsNoMatch(): void {
    $result = $this->pack->matchSynonyms('qwerty asdf zxcvb');
    $this->assertNull($result);
  }

  /**
   * Synonym matching uses word boundaries and avoids substring contamination.
   */
  public function testMatchSynonymsUsesWordBoundaries(): void {
    $this->assertNotSame(
      'feedback',
      $this->pack->matchSynonyms('my landlord retaliated because i complained'),
      'feedback should not trigger from "complained" substring'
    );
    $this->assertNotSame(
      'legal_advice_line',
      $this->pack->matchSynonyms('a debt collector keeps calling me'),
      'hotline should not trigger from bare "calling" substring'
    );
  }

  /**
   * Phrase-level synonyms still match expected navigation intents.
   */
  public function testMatchSynonymsPhraseAliasesStillWork(): void {
    $this->assertSame('feedback', $this->pack->matchSynonyms('i need to file website complaint'));
    $this->assertNotSame('feedback', $this->pack->matchSynonyms('where do i file a complaint about my firing'));
    $this->assertNotSame('feedback', $this->pack->matchSynonyms('where do i file a complaint about this'));
    $this->assertSame('legal_advice_line', $this->pack->matchSynonyms('what is the call hotline number'));
    $this->assertSame('donations', $this->pack->matchSynonyms('how can i give money'));
  }

  /**
   * Custody chips include key follow-up intents.
   */
  public function testCustodyChipsContainExpectedIntents(): void {
    $chips = $this->pack->getChips('topic_family_custody');
    $chip_map = [];
    foreach ($chips as $chip) {
      $chip_map[$chip['label']] = $chip['intent'];
    }
    $this->assertSame('topic_family_divorce', $chip_map['Divorce'] ?? NULL);
    $this->assertSame('topic_family_child_support', $chip_map['Child Support'] ?? NULL);
    $this->assertSame('topic_family_protection_order', $chip_map['Protection Orders'] ?? NULL);
    $this->assertSame('apply_for_help', $chip_map['Apply for Help'] ?? NULL);
  }

  /**
   * Eviction chips include key follow-up intents.
   */
  public function testEvictionChipsContainExpectedIntents(): void {
    $chips = $this->pack->getChips('topic_housing_eviction');
    $chip_map = [];
    foreach ($chips as $chip) {
      $chip_map[$chip['label']] = $chip['intent'];
    }
    $this->assertSame('guides_topic_housing_eviction', $chip_map['Tenant Rights Guide'] ?? NULL);
    $this->assertSame('forms_topic_housing_eviction', $chip_map['Eviction Forms'] ?? NULL);
    $this->assertSame('apply_for_help', $chip_map['Apply for Help'] ?? NULL);
    $this->assertSame('legal_advice_line', $chip_map['Call Hotline'] ?? NULL);
  }

  /**
   * Finder chips must preserve forms/guides mode instead of jumping to topics.
   */
  public function testFinderAndInventoryChipsStayWithinStructuredModes(): void {
    $this->assertSame(
      ['forms_housing', 'forms_family', 'forms_consumer', 'apply_for_help'],
      array_column($this->pack->getChips('forms_finder'), 'intent')
    );
    $this->assertSame(
      ['guides_housing', 'guides_family', 'guides_consumer', 'apply_for_help'],
      array_column($this->pack->getChips('guides_finder'), 'intent')
    );
    $this->assertSame(
      ['forms_housing', 'forms_family', 'forms_consumer', 'forms_seniors'],
      array_column($this->pack->getChips('forms_inventory'), 'intent')
    );
    $this->assertSame(
      ['guides_housing', 'guides_family', 'guides_consumer', 'guides_seniors'],
      array_column($this->pack->getChips('guides_inventory'), 'intent')
    );
  }

  /**
   * Family subtopic chips must point at specific subtopics and finder branches.
   */
  public function testFamilySubtopicChipsUseSpecificTargets(): void {
    $family_chips = [];
    foreach ($this->pack->getChips('topic_family') as $chip) {
      $family_chips[$chip['label']] = $chip['intent'];
    }
    $this->assertSame('topic_family_child_support', $family_chips['Child Support'] ?? NULL);
    $this->assertSame('topic_family_protection_order', $family_chips['Protection Orders'] ?? NULL);

    $divorce_chips = [];
    foreach ($this->pack->getChips('topic_family_divorce') as $chip) {
      $divorce_chips[$chip['label']] = $chip['intent'];
    }
    $this->assertSame('topic_family_child_support', $divorce_chips['Child Support'] ?? NULL);
    $this->assertSame('forms_topic_family_divorce', $divorce_chips['Find Divorce Forms'] ?? NULL);
  }

  /**
   * getClarifier returns structured data for intents with clarifiers.
   */
  public function testGetClarifierReturnsStructuredData(): void {
    foreach (['topic_housing', 'topic_family', 'topic_consumer'] as $key) {
      $clarifier = $this->pack->getClarifier($key);
      $this->assertNotNull($clarifier, "Clarifier should exist for '$key'");
      $this->assertArrayHasKey('question', $clarifier);
      $this->assertNotEmpty($clarifier['question']);
      $this->assertArrayHasKey('options', $clarifier);
      $this->assertNotEmpty($clarifier['options']);
      foreach ($clarifier['options'] as $option) {
        $this->assertArrayHasKey('label', $option);
        $this->assertArrayHasKey('intent', $option);
      }
    }
  }

  /**
   * getClarifier returns NULL for intents without clarifiers defined.
   */
  public function testGetClarifierReturnsNullWhenNotDefined(): void {
    // Sub-topic intents should not have clarifiers.
    $this->assertNull($this->pack->getClarifier('topic_family_custody'));
    $this->assertNull($this->pack->getClarifier('topic_housing_eviction'));
    // Navigation intents should not have clarifiers.
    $this->assertNull($this->pack->getClarifier('apply_for_help'));
  }

  /**
   * Inventory intents exist with proper structure.
   */
  public function testInventoryIntentsExist(): void {
    foreach (['forms_inventory', 'guides_inventory', 'services_inventory'] as $key) {
      $entry = $this->pack->lookup($key);
      $this->assertNotNull($entry, "Inventory intent '$key' should exist");
      $this->assertNotEmpty($entry['chips'], "Inventory intent '$key' should have chips");
      $this->assertNotEmpty($entry['primary_action'], "Inventory intent '$key' should have primary_action");
    }
  }

}
