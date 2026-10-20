<?php

namespace Drupal\Tests\ilas_site_assistant\Unit;

use Drupal\ilas_site_assistant\Service\TopIntentsPack;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Yaml\Yaml;

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
   * GetAllKeys returns a non-empty set of intent keys.
   */
  public function testGetAllKeysNonEmpty(): void {
    $keys = $this->pack->getAllKeys();
    $this->assertNotEmpty($keys);
    $this->assertGreaterThanOrEqual(30, count($keys));
  }

  /**
   * Every answer_text is covered by the reviewer's recorded sign-off.
   *
   * The answer_text field is served verbatim to visitors, so it is reviewed
   * content. The digest pins the exact wording that was signed off: change any
   * answer_text and this fails until the reviewer re-records reviewed_on
   * and the new digest in answer_text_review (see docs/GOVERNANCE.md).
   */
  public function testAnswerTextMatchesRecordedSignOff(): void {
    $data = Yaml::parseFile(dirname(__DIR__, 3) . '/config/intents/top_intents.yml');
    $review = $data['answer_text_review'] ?? NULL;
    $this->assertIsArray($review, 'top_intents.yml has no answer_text_review block.');
    $this->assertNotEmpty(trim((string) ($review['reviewed_by'] ?? '')), 'answer_text_review.reviewed_by is empty.');

    $reviewed_on = (string) ($review['reviewed_on'] ?? '');
    $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $reviewed_on, new \DateTimeZone('UTC'));
    $this->assertNotFalse($date, 'answer_text_review.reviewed_on must be a quoted YYYY-MM-DD date.');
    $this->assertSame($reviewed_on, $date->format('Y-m-d'));
    $this->assertLessThanOrEqual(new \DateTimeImmutable('now', new \DateTimeZone('UTC')), $date, 'reviewed_on is in the future.');

    $this->assertSame(
      TopIntentsPack::answerTextDigest($data['intents']),
      $review['digest'] ?? NULL,
      'An answer_text changed since the recorded sign-off. Have the reviewer approve the new wording, then update answer_text_review.reviewed_on and digest (recipe in top_intents.yml).',
    );
  }

  /**
   * The digest depends only on answer_text, not on ordering or other keys.
   */
  public function testAnswerTextDigestIgnoresOrderAndOtherFields(): void {
    $a = ['x' => ['answer_text' => 'One', 'label' => 'A'], 'y' => ['answer_text' => 'Two']];
    $b = ['y' => ['answer_text' => 'Two', 'chips' => []], 'x' => ['answer_text' => 'One']];
    $this->assertSame(TopIntentsPack::answerTextDigest($a), TopIntentsPack::answerTextDigest($b));
    $this->assertNotSame(TopIntentsPack::answerTextDigest($a), TopIntentsPack::answerTextDigest(['x' => ['answer_text' => 'One.'], 'y' => ['answer_text' => 'Two']]));
  }

  /**
   * Lookup returns NULL for unknown keys.
   */
  public function testLookupUnknownReturnsNull(): void {
    $this->assertNull($this->pack->lookup('nonexistent_intent_xyz'));
  }

  /**
   * Lookup returns full entry for a known intent.
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
   * GetChips returns non-empty array for known intents.
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
   * GetChips returns empty array for unknown intents.
   */
  public function testGetChipsUnknownReturnsEmpty(): void {
    $chips = $this->pack->getChips('nonexistent_xyz');
    $this->assertEmpty($chips);
  }

  /**
   * GetClarifier returns NULL for intents without clarifiers.
   */
  public function testGetClarifierReturnsNull(): void {
    $clarifier = $this->pack->getClarifier('apply_for_help');
    $this->assertNull($clarifier);
  }

  /**
   * MatchSynonyms finds "custody" -> topic_family_custody.
   */
  public function testMatchSynonymsCustody(): void {
    $result = $this->pack->matchSynonyms('custody');
    $this->assertEquals('topic_family_custody', $result);
  }

  /**
   * MatchSynonyms finds "eviction" -> topic_housing_eviction.
   */
  public function testLandlordTenantSynonymsRouteToSubTopic(): void {
    $this->assertSame('topic_housing_landlord_tenant', $this->pack->matchSynonyms('what are my tenant rights'));
    $this->assertSame('topic_housing_landlord_tenant', $this->pack->matchSynonyms('landlord entry rules'));
    // Bare "landlord" stays an area-level synonym; IntentRouter's sub-topic
    // carry-through (SUBTOPIC_PRIORITY) is what promotes this to eviction.
    $this->assertSame('topic_housing', $this->pack->matchSynonyms('my landlord gave me an eviction notice'));
    $this->assertSame('/resources/landlord-and-tenant', $this->pack->lookup('topic_housing_landlord_tenant')['primary_action']['url'] ?? NULL);
  }

  /**
   *
   */
  public function testMatchSynonymsEviction(): void {
    $result = $this->pack->matchSynonyms('eviction');
    $this->assertEquals('topic_housing_eviction', $result);
  }

  /**
   * MatchSynonyms finds Spanish synonym.
   */
  public function testMatchSynonymsSpanish(): void {
    $result = $this->pack->matchSynonyms('custodia');
    $this->assertEquals('topic_family_custody', $result);
  }

  /**
   * MatchSynonyms returns NULL for gibberish.
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
   * MatchSynonymsWithTerm reports both the intent key and matched synonym.
   */
  public function testMatchSynonymsWithTermReturnsSynonym(): void {
    $result = $this->pack->matchSynonymsWithTerm('i need help with custody');
    $this->assertNotNull($result);
    $this->assertSame('topic_family_custody', $result['intent_key']);
    $this->assertSame('custody', $result['synonym']);

    $this->assertNull($this->pack->matchSynonymsWithTerm('qwerty asdf zxcvb'));
  }

  /**
   * SSI and social security route to the health/benefits topic.
   */
  public function testSsiSynonymsRouteToHealthTopic(): void {
    $this->assertSame('topic_health', $this->pack->matchSynonyms('i was denied ssi'));
    $this->assertSame('topic_health', $this->pack->matchSynonyms('help with social security'));
  }

  /**
   * Guardianship routes to the seniors topic.
   */
  public function testGuardianshipSynonymRoutesToSeniorsTopic(): void {
    $this->assertSame('topic_seniors', $this->pack->matchSynonyms('guardianship of my grandchild'));
  }

  /**
   * FindMatchingSynonyms returns every entry synonym present in the input.
   */
  public function testFindMatchingSynonymsReturnsAllMatchedTerms(): void {
    $matches = $this->pack->findMatchingSynonyms('topic_health', 'where can i read about ssi or disability benefits?');
    $this->assertContains('ssi', $matches);
    $this->assertContains('disability', $matches);
    $this->assertContains('benefits', $matches);

    $this->assertSame([], $this->pack->findMatchingSynonyms('topic_health', 'custody forms'));
    $this->assertSame([], $this->pack->findMatchingSynonyms('nonexistent_intent_xyz', 'benefits'));
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
   * GetClarifier returns structured data for intents with clarifiers.
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
   * GetClarifier returns NULL for intents without clarifiers defined.
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

  /**
   * No chip or clarifier option points back at the entry that offers it.
   *
   * A self-referential chip ("Guardianship" on the seniors card routing to
   * the seniors card) is a dead end: the user taps a specific topic and gets
   * the same generic answer again. Sub-topics without their own pack entry
   * must link to a forms/guides selection or a contact action instead.
   */
  public function testNoChipPointsBackAtItsOwnEntry(): void {
    $self_references = [];
    foreach ($this->pack->getAllKeys() as $key) {
      $options = array_merge(
        $this->pack->getChips($key),
        $this->pack->getClarifier($key)['options'] ?? [],
      );
      foreach ($options as $option) {
        if (($option['intent'] ?? NULL) === $key) {
          $self_references[] = $key . ' -> "' . ($option['label'] ?? '?') . '"';
        }
      }
    }

    $this->assertSame([], $self_references);
  }

}
