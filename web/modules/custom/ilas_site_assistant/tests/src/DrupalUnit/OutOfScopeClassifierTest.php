<?php

namespace Drupal\Tests\ilas_site_assistant\DrupalUnit;

use Drupal\ilas_site_assistant\Service\OutOfScopeClassifier;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Unit tests for OutOfScopeClassifier service.
 *
 * Contains 50+ test cases covering criminal defense, immigration,
 * non-Idaho jurisdiction, emergency services, and other out-of-scope
 * categories.
 *
 */
#[CoversClass(OutOfScopeClassifier::class)]
#[Group('ilas_site_assistant')]
class OutOfScopeClassifierTest extends UnitTestCase {

  /**
   * The out-of-scope classifier.
   *
   * @var \Drupal\ilas_site_assistant\Service\OutOfScopeClassifier
   */
  protected $classifier;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Create mock config factory.
    $configFactory = $this->createMock('Drupal\Core\Config\ConfigFactoryInterface');
    $config = $this->createMock('Drupal\Core\Config\ImmutableConfig');
    $config->method('get')->willReturn([]);
    $configFactory->method('get')->willReturn($config);

    $this->classifier = new OutOfScopeClassifier($configFactory);
  }

  // =========================================================================
  // CRIMINAL DEFENSE TESTS (16 test cases)
  // =========================================================================

  /**
   * Tests criminal arrest detection.
     */
  #[DataProvider('criminalArrestProvider')]
  public function testCriminalArrestDetection(string $prompt): void {
    $result = $this->classifier->classify($prompt);

    $this->assertTrue($result['is_out_of_scope'], "Should be OOS: $prompt");
    $this->assertEquals(OutOfScopeClassifier::CATEGORY_CRIMINAL_DEFENSE, $result['category']);
    $this->assertEquals(OutOfScopeClassifier::RESPONSE_DECLINE_POLITELY, $result['response_type']);
    $this->assertStringStartsWith('oos_criminal_', $result['reason_code']);
  }

  /**
   * Data provider for criminal arrest prompts.
   */
  public static function criminalArrestProvider(): array {
    return [
      ['I was arrested last night'],
      ['I got arrested for shoplifting'],
      ["I've been arrested and need help"],
      ['I have been arrested and am in jail'],
      ['I was arrested by police'],
    ];
  }

  /**
   * Tests criminal charges detection.
     */
  #[DataProvider('criminalChargesProvider')]
  public function testCriminalChargesDetection(string $prompt): void {
    $result = $this->classifier->classify($prompt);

    $this->assertTrue($result['is_out_of_scope'], "Should be OOS: $prompt");
    $this->assertEquals(OutOfScopeClassifier::CATEGORY_CRIMINAL_DEFENSE, $result['category']);
  }

  /**
   * Data provider for criminal charges prompts.
   */
  public static function criminalChargesProvider(): array {
    return [
      ['I am facing charges for assault'],
      ['I was charged with theft'],
      ['I have a criminal charge pending'],
      ['I am accused of a felony'],
      ['I got a misdemeanor criminal charge'],
    ];
  }

  /**
   * Tests DUI/DWI detection.
     */
  #[DataProvider('duiDwiProvider')]
  public function testDuiDwiDetection(string $prompt): void {
    $result = $this->classifier->classify($prompt);

    $this->assertTrue($result['is_out_of_scope'], "Should be OOS: $prompt");
    $this->assertEquals(OutOfScopeClassifier::CATEGORY_CRIMINAL_DEFENSE, $result['category']);
    $this->assertStringStartsWith('oos_criminal_', $result['reason_code']);
  }

  /**
   * Data provider for DUI/DWI prompts.
   */
  public static function duiDwiProvider(): array {
    return [
      ['I got a DUI last week'],
      ['I was arrested for DWI'],
      ['I got pulled over for drunk driving'],
      ['Driving under the influence charge'],
    ];
  }

  /**
   * Tests incarceration detection.
     */
  #[DataProvider('incarcerationProvider')]
  public function testIncarcerationDetection(string $prompt): void {
    $result = $this->classifier->classify($prompt);

    $this->assertTrue($result['is_out_of_scope'], "Should be OOS: $prompt");
    $this->assertEquals(OutOfScopeClassifier::CATEGORY_CRIMINAL_DEFENSE, $result['category']);
  }

  /**
   * Data provider for incarceration prompts.
   */
  public static function incarcerationProvider(): array {
    return [
      ["I'm in jail right now"],
      ['I am currently in prison'],
      ["I'm locked up and need a lawyer"],
      ['I am an inmate at the county jail'],
      ["I'm doing time and have questions"],
    ];
  }

  /**
   * Tests probation/parole detection.
     */
  #[DataProvider('probationParoleProvider')]
  public function testProbationParoleDetection(string $prompt): void {
    $result = $this->classifier->classify($prompt);

    $this->assertTrue($result['is_out_of_scope'], "Should be OOS: $prompt");
    $this->assertEquals(OutOfScopeClassifier::CATEGORY_CRIMINAL_DEFENSE, $result['category']);
  }

  /**
   * Data provider for probation/parole prompts.
   */
  public static function probationParoleProvider(): array {
    return [
      ['I violated my probation'],
      ['I have a probation hearing next week'],
      ['My parole officer says I violated'],
      ["I'm on probation and need help"],
      ['I have a parole board hearing'],
    ];
  }

  /**
   * Tests criminal defense representation detection.
     */
  #[DataProvider('criminalRepresentationProvider')]
  public function testCriminalRepresentationDetection(string $prompt): void {
    $result = $this->classifier->classify($prompt);

    $this->assertTrue($result['is_out_of_scope'], "Should be OOS: $prompt");
    $this->assertEquals(OutOfScopeClassifier::CATEGORY_CRIMINAL_DEFENSE, $result['category']);
  }

  /**
   * Data provider for criminal representation prompts.
   */
  public static function criminalRepresentationProvider(): array {
    return [
      ['I need a public defender'],
      ['I need a criminal defense lawyer'],
      ['I need a criminal defense attorney for my case'],
      ['How do I get a public defender assigned'],
    ];
  }

  /**
   * Tests expungement detection.
     */
  #[DataProvider('expungementProvider')]
  public function testExpungementDetection(string $prompt): void {
    $result = $this->classifier->classify($prompt);

    $this->assertTrue($result['is_out_of_scope'], "Should be OOS: $prompt");
    $this->assertEquals(OutOfScopeClassifier::CATEGORY_CRIMINAL_DEFENSE, $result['category']);
  }

  /**
   * Data provider for expungement prompts.
   */
  public static function expungementProvider(): array {
    return [
      ['I want to expunge my record'],
      ['Can I get my criminal record sealed'],
      ['How do I clear my record'],
      ['I need help with expungement'],
    ];
  }

  // =========================================================================
  // IMMIGRATION TESTS (14 test cases)
  // =========================================================================

  /**
   * Tests visa-related detection.
     */
  #[DataProvider('visaProvider')]
  public function testVisaDetection(string $prompt): void {
    $result = $this->classifier->classify($prompt);

    $this->assertTrue($result['is_out_of_scope'], "Should be OOS: $prompt");
    $this->assertEquals(OutOfScopeClassifier::CATEGORY_IMMIGRATION, $result['category']);
    $this->assertEquals(OutOfScopeClassifier::RESPONSE_DECLINE_POLITELY, $result['response_type']);
  }

  /**
   * Data provider for visa prompts.
   */
  public static function visaProvider(): array {
    return [
      ['My visa was denied'],
      ['I need help with my visa application'],
      ['My visa expired last month'],
      ['I need a work visa'],
      ['My H1B visa status'],
    ];
  }

  /**
   * Tests green card detection.
     */
  #[DataProvider('greenCardProvider')]
  public function testGreenCardDetection(string $prompt): void {
    $result = $this->classifier->classify($prompt);

    $this->assertTrue($result['is_out_of_scope'], "Should be OOS: $prompt");
    $this->assertEquals(OutOfScopeClassifier::CATEGORY_IMMIGRATION, $result['category']);
  }

  /**
   * Data provider for green card prompts.
   */
  public static function greenCardProvider(): array {
    return [
      ['How do I get a green card'],
      ['I need to apply for a green card'],
      ['Green card through marriage'],
      ['I am a permanent resident with questions'],
    ];
  }

  /**
   * Tests citizenship/naturalization detection.
     */
  #[DataProvider('citizenshipProvider')]
  public function testCitizenshipDetection(string $prompt): void {
    $result = $this->classifier->classify($prompt);

    $this->assertTrue($result['is_out_of_scope'], "Should be OOS: $prompt");
    $this->assertEquals(OutOfScopeClassifier::CATEGORY_IMMIGRATION, $result['category']);
  }

  /**
   * Data provider for citizenship prompts.
   */
  public static function citizenshipProvider(): array {
    return [
      ['I want to become a citizen'],
      ['Help with naturalization'],
      ['Citizenship test preparation'],
      ['N-400 application help'],
    ];
  }

  /**
   * Tests deportation detection.
     */
  #[DataProvider('deportationProvider')]
  public function testDeportationDetection(string $prompt): void {
    $result = $this->classifier->classify($prompt);

    $this->assertTrue($result['is_out_of_scope'], "Should be OOS: $prompt");
    $this->assertEquals(OutOfScopeClassifier::CATEGORY_IMMIGRATION, $result['category']);
  }

  /**
   * Data provider for deportation prompts.
   */
  public static function deportationProvider(): array {
    return [
      ['I am facing deportation'],
      ['ICE detained my husband'],
      ['I have an immigration court hearing'],
      ['I got a notice to appear'],
    ];
  }

  /**
   * Tests asylum/refugee detection.
     */
  #[DataProvider('asylumProvider')]
  public function testAsylumDetection(string $prompt): void {
    $result = $this->classifier->classify($prompt);

    $this->assertTrue($result['is_out_of_scope'], "Should be OOS: $prompt");
    $this->assertEquals(OutOfScopeClassifier::CATEGORY_IMMIGRATION, $result['category']);
  }

  /**
   * Data provider for asylum prompts.
   */
  public static function asylumProvider(): array {
    return [
      ['I need asylum help'],
      ['Asylum application assistance'],
    ];
  }

  /**
   * Tests undocumented status detection.
     */
  #[DataProvider('undocumentedProvider')]
  public function testUndocumentedDetection(string $prompt): void {
    $result = $this->classifier->classify($prompt);

    $this->assertTrue($result['is_out_of_scope'], "Should be OOS: $prompt");
    $this->assertEquals(OutOfScopeClassifier::CATEGORY_IMMIGRATION, $result['category']);
  }

  /**
   * Data provider for undocumented status prompts.
   */
  public static function undocumentedProvider(): array {
    return [
      ['I am undocumented'],
      ['I am here without papers'],
      ['I overstayed my visa'],
      ['Help with DACA'],
    ];
  }

  /**
   * Tests general immigration detection.
     */
  #[DataProvider('generalImmigrationProvider')]
  public function testGeneralImmigrationDetection(string $prompt): void {
    $result = $this->classifier->classify($prompt);

    $this->assertTrue($result['is_out_of_scope'], "Should be OOS: $prompt");
    $this->assertEquals(OutOfScopeClassifier::CATEGORY_IMMIGRATION, $result['category']);
  }

  /**
   * Data provider for general immigration prompts.
   */
  public static function generalImmigrationProvider(): array {
    return [
      ['I need an immigration lawyer'],
      ['Help with my immigration case'],
      ['USCIS denied my application'],
    ];
  }

  // =========================================================================
  // NON-IDAHO JURISDICTION TESTS (8 test cases)
  // =========================================================================

  /**
   * Tests non-Idaho location detection.
     */
  #[DataProvider('nonIdahoProvider')]
  public function testNonIdahoDetection(string $prompt): void {
    $result = $this->classifier->classify($prompt);

    $this->assertTrue($result['is_out_of_scope'], "Should be OOS: $prompt");
    $this->assertEquals(OutOfScopeClassifier::CATEGORY_NON_IDAHO, $result['category']);
    $this->assertEquals(OutOfScopeClassifier::RESPONSE_REDIRECT, $result['response_type']);
  }

  /**
   * Data provider for non-Idaho prompts.
   */
  public static function nonIdahoProvider(): array {
    return [
      ['I live in Oregon'],
      ["I'm in Washington state"],
      ['I am from California'],
      ['I live in Montana'],
      ['I am in Nevada'],
      ["I'm from another state"],
      ['I am not in Idaho'],
      ['I live outside of Idaho'],
    ];
  }

  // =========================================================================
  // EMERGENCY SERVICES TESTS (6 test cases)
  // =========================================================================

  /**
   * Tests emergency services detection.
     */
  #[DataProvider('emergencyServicesProvider')]
  public function testEmergencyServicesDetection(string $prompt): void {
    $result = $this->classifier->classify($prompt);

    $this->assertTrue($result['is_out_of_scope'], "Should be OOS: $prompt");
    $this->assertEquals(OutOfScopeClassifier::CATEGORY_EMERGENCY_SERVICES, $result['category']);
    $this->assertEquals(OutOfScopeClassifier::RESPONSE_SUGGEST_EMERGENCY, $result['response_type']);
  }

  /**
   * Data provider for emergency services prompts.
   */
  public static function emergencyServicesProvider(): array {
    return [
      ['I need to call 911'],
      ['Call the police'],
      ['I need an ambulance'],
      ['My house is on fire'],
      ['Someone is breaking in'],
      ['I am having a heart attack'],
    ];
  }

  // =========================================================================
  // BUSINESS/COMMERCIAL TESTS (6 test cases)
  // =========================================================================

  /**
   * Tests business/commercial detection.
     */
  #[DataProvider('businessCommercialProvider')]
  public function testBusinessCommercialDetection(string $prompt): void {
    $result = $this->classifier->classify($prompt);

    $this->assertTrue($result['is_out_of_scope'], "Should be OOS: $prompt");
    $this->assertEquals(OutOfScopeClassifier::CATEGORY_BUSINESS_COMMERCIAL, $result['category']);
    $this->assertEquals(OutOfScopeClassifier::RESPONSE_DECLINE_POLITELY, $result['response_type']);
  }

  /**
   * Data provider for business/commercial prompts.
   */
  public static function businessCommercialProvider(): array {
    return [
      ['I want to start an LLC'],
      ['Help me incorporate my business'],
      ['I need to patent my invention'],
      ['I want to trademark my business name'],
      ['Help me form a corporation'],
      ['I need a commercial lease reviewed'],
    ];
  }

  // =========================================================================
  // FEDERAL MATTERS TESTS (6 test cases)
  // =========================================================================

  /**
   * Tests federal matters detection.
     */
  #[DataProvider('federalMattersProvider')]
  public function testFederalMattersDetection(string $prompt): void {
    $result = $this->classifier->classify($prompt);

    $this->assertTrue($result['is_out_of_scope'], "Should be OOS: $prompt");
    $this->assertEquals(OutOfScopeClassifier::CATEGORY_FEDERAL_MATTERS, $result['category']);
    $this->assertEquals(OutOfScopeClassifier::RESPONSE_REDIRECT, $result['response_type']);
  }

  /**
   * Data provider for federal matters prompts.
   */
  public static function federalMattersProvider(): array {
    return [
      ['I have IRS debt problems'],
      ['The IRS is auditing me'],
      ['I need help with my VA benefits'],
      ['Social security disability denied me'],
    ];
  }

  // =========================================================================
  // HIGH-VALUE CIVIL TESTS (5 test cases)
  // =========================================================================

  /**
   * Tests high-value civil detection.
     */
  #[DataProvider('highValueCivilProvider')]
  public function testHighValueCivilDetection(string $prompt): void {
    $result = $this->classifier->classify($prompt);

    $this->assertTrue($result['is_out_of_scope'], "Should be OOS: $prompt");
    $this->assertEquals(OutOfScopeClassifier::CATEGORY_HIGH_VALUE_CIVIL, $result['category']);
    $this->assertEquals(OutOfScopeClassifier::RESPONSE_REDIRECT, $result['response_type']);
  }

  /**
   * Data provider for high-value civil prompts.
   */
  public static function highValueCivilProvider(): array {
    return [
      ['I need a personal injury lawyer'],
      ['I was in a car accident and want to sue'],
      ['Medical malpractice case'],
      ['I want to file a wrongful death lawsuit'],
      ['I need a workers comp attorney'],
    ];
  }

  // =========================================================================
  // IN-SCOPE / SAFE TESTS (10 test cases)
  // =========================================================================

  /**
   * Tests that in-scope queries are not flagged.
     */
  #[DataProvider('inScopeProvider')]
  public function testInScopeQueries(string $prompt): void {
    $result = $this->classifier->classify($prompt);

    $this->assertFalse($result['is_out_of_scope'], "Should NOT be OOS: $prompt");
    $this->assertEquals(OutOfScopeClassifier::CATEGORY_IN_SCOPE, $result['category']);
    $this->assertEquals(OutOfScopeClassifier::RESPONSE_IN_SCOPE, $result['response_type']);
    $this->assertEmpty($result['suggestions']);
  }

  /**
   * Data provider for in-scope queries.
   */
  public static function inScopeProvider(): array {
    return [
      ['I need help with my eviction'],
      ['My landlord is not returning my deposit'],
      ['I need a divorce'],
      ['Help with child custody'],
      ['I am being denied food stamps'],
      ['My employer did not pay me'],
      ['I have a consumer complaint'],
      ['How do I file for a protection order'],
      ['I need help with my lease'],
      ['What services do you offer?'],
    ];
  }

  // =========================================================================
  // SPANISH LANGUAGE TESTS (4 test cases)
  // =========================================================================

  /**
   * Tests Spanish language out-of-scope detection.
     */
  #[DataProvider('spanishOosProvider')]
  public function testSpanishOosDetection(string $prompt, string $expected_category): void {
    $result = $this->classifier->classify($prompt);

    $this->assertTrue($result['is_out_of_scope'], "Should be OOS: $prompt");
    $this->assertEquals($expected_category, $result['category']);
  }

  /**
   * Data provider for Spanish OOS prompts.
   */
  public static function spanishOosProvider(): array {
    return [
      ['Me arrestaron anoche', OutOfScopeClassifier::CATEGORY_CRIMINAL_DEFENSE],
      ['Necesito ayuda con inmigracion', OutOfScopeClassifier::CATEGORY_IMMIGRATION],
      ['Soy indocumentado', OutOfScopeClassifier::CATEGORY_IMMIGRATION],
      ['Llame a la policia', OutOfScopeClassifier::CATEGORY_EMERGENCY_SERVICES],
    ];
  }

  // =========================================================================
  // UTILITY METHOD TESTS
  // =========================================================================

  /**
   * Tests reason code descriptions exist.
   */
  public function testReasonCodeDescriptions(): void {
    $description = $this->classifier->describeReasonCode('oos_criminal_arrested');
    $this->assertNotEmpty($description);
    $this->assertNotEquals('Unknown reason code', $description);

    $description = $this->classifier->describeReasonCode('oos_immigration_visa');
    $this->assertNotEmpty($description);

    $description = $this->classifier->describeReasonCode('unknown_code');
    $this->assertEquals('Unknown reason code', $description);
  }

  /**
   * Tests batch classification.
   */
  public function testBatchClassification(): void {
    $messages = [
      'criminal' => 'I was arrested for theft',
      'immigration' => 'I need a green card',
      'safe' => 'Help with my eviction',
    ];

    $results = $this->classifier->classifyBatch($messages);

    $this->assertArrayHasKey('criminal', $results);
    $this->assertArrayHasKey('immigration', $results);
    $this->assertArrayHasKey('safe', $results);

    $this->assertEquals(OutOfScopeClassifier::CATEGORY_CRIMINAL_DEFENSE, $results['criminal']['category']);
    $this->assertEquals(OutOfScopeClassifier::CATEGORY_IMMIGRATION, $results['immigration']['category']);
    $this->assertEquals(OutOfScopeClassifier::CATEGORY_IN_SCOPE, $results['safe']['category']);
  }

  /**
   * Tests rule statistics.
   */
  public function testRuleStatistics(): void {
    $stats = $this->classifier->getRuleStatistics();

    $this->assertArrayHasKey('total_categories', $stats);
    $this->assertArrayHasKey('total_patterns', $stats);
    $this->assertArrayHasKey('categories', $stats);

    $this->assertGreaterThanOrEqual(7, $stats['total_categories']);
    $this->assertGreaterThan(50, $stats['total_patterns']);
  }

  /**
   * Tests suggestions are provided for each OOS category.
   */
  public function testSuggestionsProvided(): void {
    $categories = [
      'I was arrested' => [OutOfScopeClassifier::CATEGORY_CRIMINAL_DEFENSE, TRUE],
      'I need a green card' => [OutOfScopeClassifier::CATEGORY_IMMIGRATION, TRUE],
      'I live in Oregon' => [OutOfScopeClassifier::CATEGORY_NON_IDAHO, TRUE],
      'Call 911' => [OutOfScopeClassifier::CATEGORY_EMERGENCY_SERVICES, TRUE],
      'I want to start an LLC' => [OutOfScopeClassifier::CATEGORY_BUSINESS_COMMERCIAL, TRUE],
      'File for bankruptcy' => [OutOfScopeClassifier::CATEGORY_IN_SCOPE, FALSE],
      'Personal injury lawyer' => [OutOfScopeClassifier::CATEGORY_HIGH_VALUE_CIVIL, TRUE],
    ];

    foreach ($categories as $prompt => [$expected_category, $expect_suggestions]) {
      $result = $this->classifier->classify($prompt);
      $this->assertEquals($expected_category, $result['category'], "Category mismatch for: $prompt");
      if ($expect_suggestions) {
        $this->assertNotEmpty($result['suggestions'], "No suggestions for: $prompt");
      }
      else {
        $this->assertEmpty($result['suggestions'], "Unexpected suggestions for in-scope prompt: $prompt");
      }
    }
  }

  /**
   * Tests priority ordering (emergency services highest priority).
   */
  public function testPriorityOrdering(): void {
    // Emergency should take precedence over criminal.
    $result = $this->classifier->classify('Call 911 I was just arrested');
    $this->assertEquals(OutOfScopeClassifier::CATEGORY_EMERGENCY_SERVICES, $result['category']);

    // Criminal should take precedence over business.
    $result = $this->classifier->classify('I was arrested at my LLC business');
    $this->assertEquals(OutOfScopeClassifier::CATEGORY_CRIMINAL_DEFENSE, $result['category']);
  }

  /**
   * Tests get reason codes for category.
   */
  public function testGetReasonCodesForCategory(): void {
    $codes = $this->classifier->getReasonCodesForCategory(OutOfScopeClassifier::CATEGORY_CRIMINAL_DEFENSE);
    $this->assertNotEmpty($codes);
    $this->assertContains('oos_criminal_arrested', $codes);
    $this->assertContains('oos_criminal_dui', $codes);
  }

}
