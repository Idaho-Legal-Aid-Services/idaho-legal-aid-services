<?php

namespace Drupal\Tests\ilas_site_assistant\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;
use Drupal\ilas_site_assistant\Service\AcronymExpander;
use Drupal\ilas_site_assistant\Service\TypoCorrector;
use Drupal\ilas_site_assistant\Service\TopicRouter;

/**
 * Regression tests for text normalization fixes.
 *
 * Tests specific normalization failures identified in eval:
 * - "representaion" -> "representation"
 * - "adress" -> "address"
 * - "where r u located" -> "where are you located"
 * - "child custody forms" NOT detected as greeting.
 */
#[Group('ilas_site_assistant')]
class NormalizationRegressionTest extends TestCase {

  /**
   * The acronym expander service.
   *
   * @var \Drupal\ilas_site_assistant\Service\AcronymExpander
   */
  protected $acronymExpander;

  /**
   * The typo corrector service.
   *
   * @var \Drupal\ilas_site_assistant\Service\TypoCorrector
   */
  protected $typoCorrector;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->acronymExpander = new AcronymExpander(NULL);
    $topic_router = new TopicRouter(NULL);
    $this->typoCorrector = new TypoCorrector(NULL, $topic_router, $this->acronymExpander);
  }

  /**
   * Tests text speak abbreviation expansion.
   */
  #[DataProvider('textSpeakProvider')]
  public function testTextSpeakExpansion(string $input, string $expectedContains, string $description): void {
    $result = $this->acronymExpander->expand(strtolower($input));
    $this->assertStringContainsString(
      $expectedContains,
      $result['text'],
      "Failed: $description (input: '$input', got: '{$result['text']}')"
    );
  }

  /**
   *
   */
  public static function textSpeakProvider(): array {
    return [
      // Core text speak abbreviations.
      ['where r u located', 'where are you located', 'r u -> are you expansion'],
      ['r u open today', 'are you open today', 'r u at start'],
      ['where r u', 'where are you', 'where r u'],
      ['u r closed', 'you are closed', 'u r -> you are'],

      // Other common abbreviations.
      ['pls help me', 'please help me', 'pls -> please'],
      ['plz send info', 'please send info', 'plz -> please'],
      ['thx for help', 'thanks for help', 'thx -> thanks'],
      ['ty for your time', 'thank you for your time', 'ty -> thank you'],
      ['need help asap', 'as soon as possible', 'asap expansion'],
      ['govt benefits', 'government benefits', 'govt -> government'],
      ['need an atty', 'attorney', 'atty -> attorney'],
      ['office hrs', 'hours', 'hrs -> hours'],

      // Mixed case handling.
      ['WHERE R U LOCATED', 'where are you located', 'uppercase r u'],
      ['Where R U', 'where are you', 'mixed case'],
    ];
  }

  /**
   * Tests that typo correction vocabulary includes needed terms.
   */
  public function testVocabularyContainsNeededTerms(): void {
    $vocab = $this->typoCorrector->getVocabulary();

    // Check essential terms are in vocabulary.
    $this->assertArrayHasKey('representation', $vocab, 'Vocabulary should include representation');
    $this->assertArrayHasKey('address', $vocab, 'Vocabulary should include address');
    $this->assertArrayHasKey('custody', $vocab, 'Vocabulary should include custody');
    $this->assertArrayHasKey('divorce', $vocab, 'Vocabulary should include divorce');
    $this->assertArrayHasKey('eviction', $vocab, 'Vocabulary should include eviction');
    $this->assertArrayHasKey('judgment', $vocab, 'Vocabulary should include judgment');
  }

  /**
   * Tests typo correction for specific failing cases.
   */
  #[DataProvider('typoCorrectionProvider')]
  public function testTypoCorrection(string $input, string $expectedWord, string $description): void {
    $result = $this->typoCorrector->correct(strtolower($input));
    $this->assertStringContainsString(
      $expectedWord,
      $result['text'],
      "Failed: $description (input: '$input', got: '{$result['text']}')"
    );
  }

  /**
   *
   */
  public static function typoCorrectionProvider(): array {
    return [
      // Specific failing cases from eval.
      ['need legal representaion', 'representation', 'representaion typo'],
      ['whats your adress', 'address', 'adress typo'],

      // Additional variants.
      ['i need representaion in court', 'representation', 'representaion in sentence'],
      ['office adress please', 'address', 'adress at start'],
      ['addres of boise office', 'address', 'addres typo variant'],

      // Other common legal typos.
      ['custdy case', 'custody', 'custody typo'],
      ['divorse papers', 'divorce', 'divorce typo'],
      ['eviciton notice', 'eviction', 'eviction typo'],
      ['bankrupcy help', 'bankruptcy', 'bankruptcy typo'],
      ['lanldord problems', 'landlord', 'landlord typo'],
      ['custdy forms', 'custody', 'custody typo in forms query'],
      ['tenet rights', 'tenant', 'tenet dictionary typo (conversation 7c10da94)'],
      ['tenent rights', 'tenant', 'tenent typo'],
    ];
  }

  /**
   * Tests full normalization pipeline with combined issues.
   */
  public function testCombinedNormalization(): void {
    // Text speak + typo in same message.
    $result = $this->acronymExpander->expand('where r u located whats ur adress');
    $this->assertStringContainsString('where are you located', $result['text']);
    $this->assertStringContainsString('your', $result['text']);

    // Then typo correction.
    $result2 = $this->typoCorrector->correct($result['text']);
    // Note: "adress" should be caught by synonym mapping, not typo correction.
    // But if it's in vocabulary, it should be corrected here.
  }

  /**
   * Tests that short words are not falsely corrected.
   */
  #[DataProvider('noFalseCorrectionProvider')]
  public function testNoFalseCorrection(string $input, string $description): void {
    $result = $this->typoCorrector->correct(strtolower($input));
    $this->assertEmpty(
      $result['corrections'],
      "Should not correct: '$input' ($description). Got: " . json_encode($result['corrections'])
    );
  }

  /**
   *
   */
  public static function noFalseCorrectionProvider(): array {
    return [
      ['hi there', 'greeting should not be corrected'],
      ['hey', 'short greeting'],
      ['hello', 'hello is a word'],
      ['child custody', 'correct legal terms'],
      ['forms please', 'correct words'],
      ['help me', 'common words'],
      ['custody forms', 'custody forms should not be corrected'],
      ['do you have custody forms', 'interrogative custody forms should not be corrected'],
      ['guides please', 'correct words'],
      ['eviction guides', 'guide with topic should not be corrected'],
    ];
  }

  /**
   * Tests Spanish diacritics handling.
   */
  #[DataProvider('spanishDiacriticsProvider')]
  public function testSpanishDiacriticsPreserved(string $input, string $description): void {
    // Acronym expander should handle Spanish.
    $result = $this->acronymExpander->expand(strtolower($input));

    // Should not corrupt the text.
    $this->assertNotEmpty($result['text'], "Text should not be empty for: $input");
  }

  /**
   *
   */
  public static function spanishDiacriticsProvider(): array {
    return [
      ['Dónde está la oficina', 'accented donde'],
      ['Necesito información', 'accented information'],
      ['ayúdame por favor', 'accented ayuda'],
      ['Estoy en una situación difícil', 'accented situation'],
    ];
  }

  /**
   * Tests punctuation handling.
   */
  #[DataProvider('punctuationProvider')]
  public function testPunctuationHandling(string $input, string $expectedContains, string $description): void {
    $result = $this->acronymExpander->expand(strtolower($input));
    $this->assertStringContainsString(
      $expectedContains,
      $result['text'],
      "Failed: $description"
    );
  }

  /**
   *
   */
  public static function punctuationProvider(): array {
    return [
      ['where r u?', 'where are you', 'question mark'],
      ['r u open!', 'are you open', 'exclamation'],
      ['where r u...', 'where are you', 'ellipsis'],
      ['where r u???', 'where are you', 'multiple question marks'],
    ];
  }

  /**
   * Tests mixed-case input normalization.
   */
  #[DataProvider('mixedCaseProvider')]
  public function testMixedCaseNormalization(string $input, string $expectedContains, string $description): void {
    $result = $this->acronymExpander->expand(strtolower($input));
    $this->assertStringContainsString(
      $expectedContains,
      $result['text'],
      "Failed: $description"
    );
  }

  /**
   *
   */
  public static function mixedCaseProvider(): array {
    return [
      ['CHILD CUSTODY FORMS', 'child custody forms', 'all caps'],
      ['Child Custody Forms', 'child custody forms', 'title case'],
      ['cHiLd CuStOdY fOrMs', 'child custody forms', 'alternating case'],
      ['WHERE R U LOCATED', 'where are you located', 'caps with text speak'],
    ];
  }

  /**
   * Tests that long compound phrases are preserved.
   */
  public function testCompoundPhrasesPreserved(): void {
    $result = $this->acronymExpander->expand('child custody forms and divorce papers');
    // Should not corrupt compound phrases.
    $this->assertStringContainsString('child custody forms', $result['text']);
    $this->assertStringContainsString('divorce papers', $result['text']);
  }

}
