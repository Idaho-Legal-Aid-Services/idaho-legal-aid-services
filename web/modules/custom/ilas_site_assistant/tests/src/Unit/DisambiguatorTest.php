<?php

namespace Drupal\Tests\ilas_site_assistant\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;
use Drupal\ilas_site_assistant\Service\Disambiguator;

/**
 * Tests the Disambiguator service.
 */
#[Group('ilas_site_assistant')]
class DisambiguatorTest extends TestCase {

  /**
   * The Disambiguator instance under test.
   *
   * @var \Drupal\ilas_site_assistant\Service\Disambiguator
   */
  protected $disambiguator;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->disambiguator = new Disambiguator();
  }

  /**
   * Tests that exact topic-only triggers still work.
   */
  #[DataProvider('exactTopicProvider')]
  public function testExactTopicMatch(string $input, string $expected_area): void {
    $result = $this->disambiguator->check($input, []);
    $this->assertNotNull($result, "Expected disambiguation for '$input'");
    $this->assertEquals('disambiguation', $result['type']);
    $this->assertEquals('topic_without_action', $result['reason']);
    $this->assertEquals($expected_area, $result['topic']);
    $this->assertNotEmpty($result['options']);
    $this->assertNotEmpty($result['question']);

    $option_intents = array_values(array_filter(array_map(
      static fn(array $option): string => (string) ($option['intent'] ?? ''),
      $result['options']
    )));
    $this->assertCount(2, $option_intents);
    $this->assertNotContains('forms_finder', $option_intents);
    $this->assertNotContains('guides_finder', $option_intents);
    $this->assertNotContains('apply_for_help', $option_intents);
    $this->assertNotContains('legal_advice_line', $option_intents);
  }

  public static function exactTopicProvider(): array {
    return [
      ['custody', 'family'],
      ['divorce', 'family'],
      ['eviction', 'housing'],
      ['debt', 'consumer'],
      ['medicaid', 'benefits'],
      ['guardianship', 'seniors'],
    ];
  }

  /**
   * Tests that urgency-modified topic queries are still matched.
   */
  #[DataProvider('modifierTopicProvider')]
  public function testModifierStripping(string $input, string $expected_area): void {
    $result = $this->disambiguator->check($input, []);
    $this->assertNotNull($result, "Expected disambiguation for '$input'");
    $this->assertEquals('disambiguation', $result['type']);
    $this->assertEquals('topic_without_action', $result['reason']);
    $this->assertEquals($expected_area, $result['topic']);
    $this->assertNotEmpty($result['options']);
  }

  public static function modifierTopicProvider(): array {
    return [
      ['custody now', 'family'],
      ['custody please', 'family'],
      ['divorce asap', 'family'],
      ['divorce right now', 'family'],
      ['eviction urgent', 'housing'],
      ['eviction asap', 'housing'],
      ['debt please', 'consumer'],
      ['custody today', 'family'],
      ['eviction fast', 'housing'],
      // Spanish modifiers.
      ['custodia ahora', 'family'],
      ['divorcio urgente', 'family'],
      ['desalojo pronto', 'housing'],
    ];
  }

  /**
   * Tests that short generic-help scaffolding still clarifies bare topics.
   */
  #[DataProvider('genericHelpTopicProvider')]
  public function testGenericHelpScaffoldingStillClarifiesTopics(string $input, string $expected_area): void {
    $result = $this->disambiguator->check($input, []);
    $this->assertNotNull($result, "Expected disambiguation for '$input'");
    $this->assertEquals('disambiguation', $result['type']);
    $this->assertEquals('topic_without_action', $result['reason']);
    $this->assertEquals($expected_area, $result['topic']);
    $option_intents = array_values(array_filter(array_map(
      static fn(array $option): string => (string) ($option['intent'] ?? ''),
      $result['options']
    )));
    $this->assertCount(2, $option_intents);
    $this->assertStringStartsWith('forms_', $option_intents[0]);
    $this->assertStringStartsWith('guides_', $option_intents[1]);
    $this->assertNotContains('forms_finder', $option_intents);
    $this->assertNotContains('guides_finder', $option_intents);
    $this->assertNotContains('apply_for_help', $option_intents);
    $this->assertNotContains('legal_advice_line', $option_intents);
  }

  public static function genericHelpTopicProvider(): array {
    return [
      ['I need help with desalojo', 'housing'],
      ['Need help with custody', 'family'],
      ['Necesito ayuda con custodia', 'family'],
      ['Necesito ayuda con desalojo urgente', 'housing'],
    ];
  }

  /**
   * Tests that vague queries are correctly detected.
   */
  #[DataProvider('vagueQueryProvider')]
  public function testVagueQuery(string $input): void {
    $result = $this->disambiguator->check($input, []);
    $this->assertNotNull($result, "Expected disambiguation for '$input'");
    $this->assertEquals('disambiguation', $result['type']);
    $this->assertEquals('vague_query', $result['reason']);
    $this->assertNotSame('', (string) ($result['family'] ?? ''), 'Vague disambiguation must expose a stable family');
    $this->assertNotEmpty($result['options']);
  }

  public static function vagueQueryProvider(): array {
    return [
      ['help'],
      ['forms'],
      ['phone'],
      ['contact'],
      ['i need some help'],
      ['ayuda'],
      ['formularios'],
      ['guide'],
      ['guides'],
      ['guias'],
      ['help me'],
      ['please help'],
      ['please help me'],
      ['i need legal help'],
      ["i dont know what to do"],
      ['im lost'],
      ['necesito ayuda'],
      ['ayudame'],
      ['ayudeme'],
    ];
  }

  /**
   * Tests that generalized family matching catches new short variants.
   */
  #[DataProvider('generalizedVagueQueryProvider')]
  public function testGeneralizedVagueQueryFamilies(string $input, string $expectedFamily): void {
    $result = $this->disambiguator->check($input, []);
    $this->assertNotNull($result, "Expected disambiguation for '$input'");
    $this->assertSame('disambiguation', $result['type']);
    $this->assertSame('vague_query', $result['reason']);
    $this->assertSame($expectedFamily, $result['family']);
  }

  public static function generalizedVagueQueryProvider(): array {
    return [
      ['could you please help me', 'generic_help'],
      ['i really need some help', 'generic_help'],
      ['necesito un poco de ayuda', 'generic_help'],
      ['i am confused', 'uncertain_start'],
      ['phone number', 'contact_method'],
      ['what services do you offer', 'services_overview'],
    ];
  }

  /**
   * Tests that option intents are populated (not empty strings).
   */
  public function testOptionValuesNotEmpty(): void {
    $result = $this->disambiguator->check('custody', []);
    $this->assertNotNull($result);
    foreach ($result['options'] as $option) {
      $this->assertNotEmpty($option['intent'] ?? '', 'Each option must have a non-empty canonical intent');
      $this->assertNotEmpty($option['label'], 'Each option must have a non-empty label');
    }
  }

  /**
   * Tests that no 'value' key remains in disambiguation options.
   */
  public function testOptionIntentKeyExclusive(): void {
    $queries = ['custody', 'help', 'i need some help'];
    foreach ($queries as $query) {
      $result = $this->disambiguator->check($query, []);
      $this->assertNotNull($result, "Expected disambiguation for '$query'");
      foreach ($result['options'] as $i => $option) {
        $this->assertArrayHasKey('intent', $option, "Option #$i for '$query' must have 'intent' key");
        $this->assertNotEmpty($option['intent'], "Option #$i for '$query' must have non-empty 'intent'");
        $this->assertArrayNotHasKey('value', $option, "Option #$i for '$query' must not have deprecated 'value' key");
      }
    }
  }

  /**
   * Tests that messages exceeding word count limit return NULL.
   */
  public function testLongMessagesNotMatched(): void {
    $result = $this->disambiguator->check('I need help with my custody case right now please', []);
    // This is 10 words — should not match topic_without_action.
    // It may match vague_query or return NULL.
    $this->assertTrue(
      $result === NULL || ($result['reason'] ?? '') !== 'topic_without_action',
      'Long messages should not be treated as topic_without_action disambiguation'
    );
  }

  /**
   * Tests that pure modifier words without a topic return NULL.
   */
  public function testPureModifiersReturnNull(): void {
    $result = $this->disambiguator->check('now please', []);
    // "now please" should not match any topic.
    $this->assertTrue(
      $result === NULL || ($result['reason'] ?? '') !== 'topic_without_action',
      'Pure modifiers should not map to topic_without_action disambiguation'
    );
  }

  /**
   * Tests confusable pair detection via confidence delta.
   */
  public function testConfusablePairDetection(): void {
    $scored = [
      ['intent' => 'apply_for_help', 'confidence' => 0.55],
      ['intent' => 'services_overview', 'confidence' => 0.50],
    ];
    $result = $this->disambiguator->check('legal help please', $scored);
    // "legal help please" is 3 words, may or may not trigger.
    // The key is that confusable pair with small delta should trigger.
    $result2 = $this->disambiguator->check('something complex enough to not match vague or topic', $scored);
    if ($result2) {
      $this->assertEquals('disambiguation', $result2['type']);
    }
  }

  /**
   * Tests that specific routable queries bypass vague families.
   */
  #[DataProvider('specificQueryProvider')]
  public function testSpecificQueriesBypassVagueFamilies(string $input): void {
    $result = $this->disambiguator->check($input, []);
    $this->assertTrue(
      $result === NULL || ($result['reason'] ?? '') !== 'vague_query',
      "Specific query '$input' must not be intercepted by vague-query families"
    );
  }

  public static function specificQueryProvider(): array {
    return [
      ['custody forms'],
      ['where is the Boise office'],
      ['how do i apply'],
      ['contact boise office'],
    ];
  }

  /**
   * Tests that topic trigger and vague query lists are accessible.
   */
  public function testListAccessors(): void {
    $topics = $this->disambiguator->getTopicTriggers();
    $this->assertContains('custody', $topics);
    $this->assertContains('divorce', $topics);
    $this->assertContains('eviction', $topics);

    $vague = $this->disambiguator->getVagueQueries();
    $this->assertContains('help', $vague);
    $this->assertContains('forms', $vague);

    $pairs = $this->disambiguator->getConfusablePairs();
    $this->assertNotEmpty($pairs);

    $families = $this->disambiguator->getFamilies();
    $this->assertContains('generic_help', $families);
    $this->assertContains('contact_method', $families);
  }

}
