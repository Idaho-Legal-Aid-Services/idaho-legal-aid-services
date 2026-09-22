<?php

namespace Drupal\Tests\ilas_site_assistant\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;
use Drupal\ilas_site_assistant\Service\TopicRouter;

/**
 * Tests the TopicRouter service.
 */
#[Group('ilas_site_assistant')]
class TopicRouterTest extends TestCase {

  /**
   * The TopicRouter instance under test.
   *
   * @var \Drupal\ilas_site_assistant\Service\TopicRouter
   */
  protected $router;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->router = new TopicRouter(NULL);
  }

  /**
   * Sub-topic alias resolution used for sub-topic carry-through.
   */
  #[DataProvider('resolveTopicKeysProvider')]
  public function testResolveTopicKeys(string $input, array $expected_keys): void {
    $keys = array_column($this->router->resolveTopicKeys($input), 'canonical_key');
    $this->assertSame($expected_keys, $keys, "Sub-topic keys mismatch for '$input'");
  }

  /**
   *
   */
  public static function resolveTopicKeysProvider(): array {
    return [
      ['tenant rights', ['housing_landlord_tenant']],
      ['Tenant Rights', ['housing_landlord_tenant']],
      ['entering a residence', ['housing_landlord_tenant']],
      ['My landlord tried to enter my home without notice', ['housing_landlord_tenant']],
      ['my landlord is not renewing my lease because i complained', ['housing_landlord_tenant']],
      // Both eviction and landlord_tenant are named; order is deterministic.
      ['I got an eviction notice from my landlord', ['housing_eviction', 'housing_landlord_tenant']],
      ['housing', []],
      ['renew passport', []],
      ['custody', ['family_custody_parenting']],
      ['', []],
      // Sub-topics without a pack entry still resolve; the router decides
      // what to do with them.
      ['security deposits', ['housing_security_deposit']],
      ['security deposit', ['housing_security_deposit']],
      ['power of attorney', ['seniors_power_of_attorney']],
      ['mobile home', ['housing_mobile_home']],
      ['public benefits', ['health_public_benefits']],
      ['workplace rights', ['employment_workplace_rights']],
      ['deposit', []],
    ];
  }

  /**
   * pack_intent is carried into the alias index when configured.
   */
  public function testPackIntentCarriedForCustody(): void {
    $entries = $this->router->resolveTopicKeys('custody');
    $this->assertCount(1, $entries);
    $this->assertSame('topic_family_custody', $entries[0]['pack_intent']);
    $this->assertSame('custody', $entries[0]['matched_alias']);

    $entries = $this->router->resolveTopicKeys('tenant rights');
    $this->assertNull($entries[0]['pack_intent']);
  }

  /**
   * Tests that the topic map loaded correctly.
   */
  public function testTopicMapLoaded(): void {
    $map = $this->router->getTopicMap();
    $this->assertNotEmpty($map, 'Topic map should not be empty');
    $this->assertArrayHasKey('family', $map);
    $this->assertArrayHasKey('housing', $map);
    $this->assertArrayHasKey('consumer', $map);
    $this->assertArrayHasKey('seniors', $map);
    $this->assertArrayHasKey('health', $map);
    $this->assertArrayHasKey('civil_rights', $map);
    $this->assertArrayHasKey('employment', $map);
  }

  /**
 *
 */
  #[DataProvider('exactTokenProvider')]
  public function testExactTokenMatch(string $input, string $expected_topic, string $expected_url): void {
    $result = $this->router->route($input);
    $this->assertNotNull($result, "Expected match for '$input'");
    $this->assertEquals($expected_topic, $result['topic'], "Topic mismatch for '$input'");
    $this->assertEquals($expected_url, $result['url'], "URL mismatch for '$input'");
    $this->assertEquals('exact', $result['match_type']);
    $this->assertGreaterThanOrEqual(TopicRouter::CONFIDENCE_EXACT, $result['confidence']);
  }

  /**
   *
   */
  public static function exactTokenProvider(): array {
    return [
      ['divorce', 'family', '/legal-help/family'],
      ['custody', 'family', '/legal-help/family'],
      ['visitation', 'family', '/legal-help/family'],
      ['adoption', 'family', '/legal-help/family'],
      ['paternity', 'family', '/legal-help/family'],
      ['guardianship', 'family', '/legal-help/family'],
      ['family', 'family', '/legal-help/family'],
      ['eviction', 'housing', '/legal-help/housing'],
      ['housing', 'housing', '/legal-help/housing'],
      ['landlord', 'housing', '/legal-help/housing'],
      ['tenant', 'housing', '/legal-help/housing'],
      ['rent', 'housing', '/legal-help/housing'],
      ['foreclosure', 'housing', '/legal-help/housing'],
      ['bankruptcy', 'consumer', '/legal-help/consumer'],
      ['debt', 'consumer', '/legal-help/consumer'],
      ['scam', 'consumer', '/legal-help/consumer'],
      ['fraud', 'consumer', '/legal-help/consumer'],
      ['seniors', 'seniors', '/legal-help/seniors'],
      ['elderly', 'seniors', '/legal-help/seniors'],
      ['medicaid', 'health', '/legal-help/health'],
      ['benefits', 'health', '/legal-help/health'],
      ['disability', 'health', '/legal-help/health'],
      ['discrimination', 'civil_rights', '/legal-help/civil-rights'],
      ['harassment', 'civil_rights', '/legal-help/civil-rights'],
      ['fired', 'employment', '/legal-help/civil-rights'],
      ['wages', 'employment', '/legal-help/civil-rights'],
    ];
  }

  /**
 *
 */
  #[DataProvider('stemProvider')]
  public function testStemMatch(string $input, string $expected_topic): void {
    $result = $this->router->route($input);
    $this->assertNotNull($result, "Expected stem match for '$input'");
    $this->assertEquals($expected_topic, $result['topic'], "Topic mismatch for '$input'");
    $this->assertContains($result['match_type'], ['exact', 'stem']);
    $this->assertGreaterThanOrEqual(TopicRouter::CONFIDENCE_STEM, $result['confidence']);
  }

  /**
   *
   */
  public static function stemProvider(): array {
    return [
      ['divorced', 'family'],
      ['divorcing', 'family'],
      ['custodial', 'family'],
      ['adopted', 'family'],
      ['separated', 'family'],
      ['evicted', 'housing'],
      ['foreclosed', 'housing'],
      ['renting', 'housing'],
      ['garnished', 'consumer'],
      ['discriminated', 'civil_rights'],
      ['harassed', 'civil_rights'],
    ];
  }

  /**
 *
 */
  #[DataProvider('synonymProvider')]
  public function testSynonymMatch(string $input, string $expected_topic): void {
    $result = $this->router->route($input);
    $this->assertNotNull($result, "Expected synonym match for '$input'");
    $this->assertEquals($expected_topic, $result['topic'], "Topic mismatch for '$input'");
    $this->assertGreaterThanOrEqual(TopicRouter::CONFIDENCE_SYNONYM, $result['confidence']);
  }

  /**
   *
   */
  public static function synonymProvider(): array {
    return [
      ['divorcio', 'family'],
      ['custodia', 'family'],
      ['alimony', 'family'],
      ['desalojo', 'housing'],
      ['casero', 'housing'],
      ['homeless', 'housing'],
      ['mortgage', 'housing'],
      ['estafa', 'consumer'],
      ['deuda', 'consumer'],
      ['anciano', 'seniors'],
      ['retired', 'seniors'],
      ['beneficios', 'health'],
      ['welfare', 'health'],
      ['despedido', 'employment'],
      ['paycheck', 'employment'],
    ];
  }

  /**
 *
 */
  #[DataProvider('phraseProvider')]
  public function testPhraseMatch(string $input, string $expected_topic): void {
    $result = $this->router->route($input);
    $this->assertNotNull($result, "Expected phrase match for '$input'");
    $this->assertEquals($expected_topic, $result['topic'], "Topic mismatch for '$input'");
  }

  /**
   *
   */
  public static function phraseProvider(): array {
    return [
      ['child support', 'family'],
      ['child custody', 'family'],
      ['protection order', 'family'],
      ['restraining order', 'family'],
      ['identity theft', 'consumer'],
      ['elder abuse', 'seniors'],
      ['nursing home', 'seniors'],
      ['food stamps', 'health'],
      ['wrongful termination', 'employment'],
      ['civil rights', 'civil_rights'],
    ];
  }

  /**
 *
 */
  #[DataProvider('fuzzyProvider')]
  public function testFuzzyMatch(string $input, string $expected_topic): void {
    $result = $this->router->route($input);
    $this->assertNotNull($result, "Expected fuzzy match for '$input'");
    $this->assertEquals($expected_topic, $result['topic'], "Topic mismatch for '$input'");
    $this->assertGreaterThanOrEqual(TopicRouter::CONFIDENCE_FUZZY - 0.05, $result['confidence']);
  }

  /**
   *
   */
  public static function fuzzyProvider(): array {
    return [
      ['divorec', 'family'],
      ['evicton', 'housing'],
      ['bankrupcy', 'consumer'],
      ['harasment', 'civil_rights'],
    ];
  }

  /**
 *
 */
  #[DataProvider('negativeProvider')]
  public function testNegativeCases(string $input): void {
    $result = $this->router->route($input);
    $this->assertNull($result, "Expected no match for '$input', got topic: " . ($result['topic'] ?? 'none'));
  }

  /**
   *
   */
  public static function negativeProvider(): array {
    return [
      ['hi'],
      ['hello'],
      ['yes'],
      ['no'],
      ['ok'],
      ['the'],
      ['pizza'],
      ['cat'],
      ['weather'],
      ['basketball'],
    ];
  }

  /**
   * Tests case insensitivity.
   */
  public function testCaseInsensitivity(): void {
    $lower = $this->router->route('divorce');
    $upper = $this->router->route('DIVORCE');
    $mixed = $this->router->route('Divorce');

    $this->assertNotNull($lower);
    $this->assertNotNull($upper);
    $this->assertNotNull($mixed);
    $this->assertEquals($lower['topic'], $upper['topic']);
    $this->assertEquals($lower['topic'], $mixed['topic']);
  }

  /**
   * Tests punctuation handling.
   */
  public function testPunctuationHandling(): void {
    $result1 = $this->router->route('divorce?');
    $result2 = $this->router->route('eviction!');
    $result3 = $this->router->route('custody.');

    $this->assertNotNull($result1);
    $this->assertNotNull($result2);
    $this->assertNotNull($result3);
    $this->assertEquals('family', $result1['topic']);
    $this->assertEquals('housing', $result2['topic']);
    $this->assertEquals('family', $result3['topic']);
  }

  /**
   * Tests that urgency-modified queries still resolve to correct topics.
   */
  #[DataProvider('urgencyModifiedProvider')]
  public function testUrgencyModifiedQueries(string $input, string $expected_topic): void {
    $result = $this->router->route($input);
    $this->assertNotNull($result, "Expected match for urgency-modified '$input'");
    $this->assertEquals($expected_topic, $result['topic'], "Topic mismatch for '$input'");
  }

  /**
   *
   */
  public static function urgencyModifiedProvider(): array {
    return [
      ['custody now', 'family'],
      ['divorce please', 'family'],
      ['eviction asap', 'housing'],
      ['eviction urgent', 'housing'],
      ['bankruptcy fast', 'consumer'],
      ['debt today', 'consumer'],
      ['custody right now', 'family'],
      // Spanish urgency modifiers.
      ['custodia ahora', 'family'],
      ['desalojo urgente', 'housing'],
    ];
  }

  /**
   * Tests that messages over MAX_WORD_COUNT return NULL.
   */
  public function testLongMessagesRejected(): void {
    $result = $this->router->route('I need help with my divorce case please help me');
    $this->assertNull($result, 'Long messages should not be handled by TopicRouter');
  }

  /**
   * Tests confidence ordering.
   */
  public function testConfidenceOrdering(): void {
    $exact = $this->router->route('divorce');
    $stem = $this->router->route('divorced');
    $synonym = $this->router->route('divorcio');

    $this->assertNotNull($exact);
    $this->assertNotNull($stem);
    $this->assertNotNull($synonym);

    $this->assertGreaterThanOrEqual($stem['confidence'], $exact['confidence'],
      'Exact match should have >= confidence than stem');
    $this->assertGreaterThanOrEqual($synonym['confidence'], $stem['confidence'],
      'Stem match should have >= confidence than synonym');
  }

  /**
   * Tests result structure.
   */
  public function testResultStructure(): void {
    $result = $this->router->route('divorce');
    $this->assertNotNull($result);
    $this->assertArrayHasKey('type', $result);
    $this->assertArrayHasKey('topic', $result);
    $this->assertArrayHasKey('service_area', $result);
    $this->assertArrayHasKey('url', $result);
    $this->assertArrayHasKey('label', $result);
    $this->assertArrayHasKey('confidence', $result);
    $this->assertArrayHasKey('match_type', $result);
    $this->assertArrayHasKey('matched_token', $result);
    $this->assertEquals('topic_routed', $result['type']);
  }

  /**
   * Tests consumer debt exact token matches.
   */
  #[DataProvider('consumerDebtExactProvider')]
  public function testConsumerDebtExactMatch(string $input, string $expected_url): void {
    $result = $this->router->route($input);
    $this->assertNotNull($result, "Expected match for '$input'");
    $this->assertEquals('consumer', $result['topic'], "Topic should be consumer for '$input'");
    $this->assertEquals($expected_url, $result['url'], "URL mismatch for '$input'");
  }

  /**
   *
   */
  public static function consumerDebtExactProvider(): array {
    return [
      ['debt', '/legal-help/consumer'],
      ['bankruptcy', '/legal-help/consumer'],
      ['garnishment', '/legal-help/consumer'],
      ['repossession', '/legal-help/consumer'],
      ['collection', '/legal-help/consumer'],
      ['credit', '/legal-help/consumer'],
      ['levy', '/legal-help/consumer'],
      ['judgment', '/legal-help/consumer'],
      ['judgement', '/legal-help/consumer'],
      ['creditor', '/legal-help/consumer'],
      ['collector', '/legal-help/consumer'],
    ];
  }

  /**
   * Tests consumer debt Spanish synonyms.
   */
  #[DataProvider('consumerDebtSpanishProvider')]
  public function testConsumerDebtSpanishSynonyms(string $input): void {
    $result = $this->router->route($input);
    $this->assertNotNull($result, "Expected match for Spanish term '$input'");
    $this->assertEquals('consumer', $result['topic'], "Spanish term '$input' should route to consumer");
    $this->assertEquals('/legal-help/consumer', $result['url']);
  }

  /**
   *
   */
  public static function consumerDebtSpanishProvider(): array {
    return [
      ['deuda'],
      ['deudas'],
      ['bancarrota'],
      ['quiebra'],
      ['estafa'],
      ['fraude'],
      ['cobro'],
      ['cobranza'],
      ['cobrador'],
      ['cobradores'],
      ['credito'],
      ['embargo'],
      ['embargando'],
      ['embargar'],
      ['reposicion'],
      ['quitaron'],
      ['facturas'],
    ];
  }

  /**
   * Tests consumer debt phrase matches.
   */
  #[DataProvider('consumerDebtPhraseProvider')]
  public function testConsumerDebtPhraseMatch(string $input): void {
    $result = $this->router->route($input);
    $this->assertNotNull($result, "Expected match for phrase '$input'");
    $this->assertEquals('consumer', $result['topic'], "Phrase '$input' should route to consumer");
    $this->assertEquals('/legal-help/consumer', $result['url']);
  }

  /**
   *
   */
  public static function consumerDebtPhraseProvider(): array {
    return [
      ['medical bills'],
      ['medical debt'],
      ['hospital debt'],
      ['hospital bills'],
      ['wage garnishment'],
      ['debt collector'],
      ['bill collector'],
      ['identity theft'],
      ['payday loan'],
      ['credit report'],
      ['sued for debt'],
      ['default judgment'],
      ['car repossession'],
      ['bank levy'],
      ['frozen account'],
      ['cobrador de deudas'],
      ['embargo de sueldo'],
      ['deuda medica'],
    ];
  }

  /**
   * Tests that medical debt routes to consumer, NOT health.
   * This is a critical guardrail test.
   */
  #[DataProvider('medicalDebtNotHealthProvider')]
  public function testMedicalDebtRoutesToConsumerNotHealth(string $input): void {
    $result = $this->router->route($input);
    $this->assertNotNull($result, "Expected match for '$input'");
    $this->assertEquals('consumer', $result['topic'],
      "'$input' should route to CONSUMER (debt), NOT health");
    $this->assertEquals('/legal-help/consumer', $result['url'],
      "'$input' URL should be /legal-help/consumer");
    $this->assertNotEquals('/legal-help/health', $result['url'],
      "'$input' should NOT route to health page");
  }

  /**
   *
   */
  public static function medicalDebtNotHealthProvider(): array {
    return [
      ['medical bills'],
      ['medical debt'],
      ['hospital debt'],
      ['hospital bills'],
      ['deuda medica'],
      ['facturas medicas'],
    ];
  }

  /**
   * Tests consumer debt stem matches.
   */
  #[DataProvider('consumerDebtStemProvider')]
  public function testConsumerDebtStemMatch(string $input): void {
    $result = $this->router->route($input);
    $this->assertNotNull($result, "Expected stem match for '$input'");
    $this->assertEquals('consumer', $result['topic'], "Stem '$input' should route to consumer");
  }

  /**
   *
   */
  public static function consumerDebtStemProvider(): array {
    return [
      ['garnished'],
      ['garnishing'],
      ['repossessed'],
      ['collected'],
      ['collecting'],
      ['fraudulent'],
      ['predatory'],
    ];
  }

}
