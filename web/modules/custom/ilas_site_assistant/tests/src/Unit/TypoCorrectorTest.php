<?php

namespace Drupal\Tests\ilas_site_assistant\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;
use Drupal\ilas_site_assistant\Service\TypoCorrector;
use Drupal\ilas_site_assistant\Service\TopicRouter;
use Drupal\ilas_site_assistant\Service\AcronymExpander;

/**
 * Tests the TypoCorrector service.
 */
#[Group('ilas_site_assistant')]
class TypoCorrectorTest extends TestCase {

  /**
   * The TypoCorrector instance under test.
   *
   * @var \Drupal\ilas_site_assistant\Service\TypoCorrector
   */
  protected $corrector;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // Create with TopicRouter and AcronymExpander for full vocabulary.
    $topic_router = new TopicRouter(NULL);
    $acronym_expander = new AcronymExpander(NULL);
    $this->corrector = new TypoCorrector(NULL, $topic_router, $acronym_expander);
  }

  /**
   * Tests that vocabulary was built.
   */
  public function testVocabularyBuilt(): void {
    $size = $this->corrector->getVocabularySize();
    $this->assertGreaterThan(50, $size, 'Vocabulary should have at least 50 terms');
  }

  /**
 *
 */
  #[DataProvider('typoCorrectionProvider')]
  public function testTypoCorrection(string $input, string $expected_word, string $description): void {
    $result = $this->corrector->correct(strtolower($input));
    $this->assertStringContainsString(
      $expected_word,
      $result['text'],
      "Failed for: $description (input: '$input', got: '{$result['text']}')"
    );
    $this->assertNotEmpty(
      $result['corrections'],
      "Expected at least one correction for '$input' ($description)"
    );
  }

  /**
   *
   */
  public static function typoCorrectionProvider(): array {
    return [
      // === Family law typos (10 cases) ===
      ['custdy', 'custody', 'missing letter in custody'],
      ['cusotdy', 'custody', 'transposed letters in custody'],
      ['custidy', 'custody', 'wrong vowel in custody'],
      ['divorse', 'divorce', 'common misspelling of divorce'],
      // === Dictionary entries (config/routing/typos.yml) beyond fuzzy reach ===
      ['tenet', 'tenant', 'tenet -> tenant is distance 2 on a 5-letter token'],
      ['tenet rights', 'tenant', 'dictionary hit inside a two-word query'],
      ['tenent', 'tenant', 'tenent -> tenant'],
      ['tennant', 'tenant', 'tennant -> tenant'],
      ['landlard', 'landlord', 'landlard -> landlord'],
      ['leese', 'lease', 'leese -> lease'],
      ['deposite', 'deposit', 'deposite -> deposit'],
      ['rentor', 'renter', 'rentor -> renter'],
      ['divorec', 'divorce', 'transposed ending in divorce'],
      ['divroce', 'divorce', 'scrambled middle in divorce'],
      ['gaurdianship', 'guardianship', 'transposed letters in guardianship'],
      ['gurdianship', 'guardianship', 'missing letter in guardianship'],
      ['seperaton', 'separation', 'misspelled separation'],
      ['separtion', 'separation', 'missing letter in separation'],

      // === Housing typos (8 cases) ===
      ['eviciton', 'eviction', 'transposed letters in eviction'],
      ['evicton', 'eviction', 'missing letter in eviction'],
      ['evcition', 'eviction', 'scrambled eviction'],
      ['lanldord', 'landlord', 'transposed letters in landlord'],
      ['landord', 'landlord', 'missing letter in landlord'],
      ['forclosure', 'foreclosure', 'missing letter in foreclosure'],
      ['foreclsoure', 'foreclosure', 'scrambled foreclosure'],
      ['morgage', 'mortgage', 'missing letter in mortgage'],

      // === Consumer typos (6 cases) ===
      ['bankrupcy', 'bankruptcy', 'common misspelling of bankruptcy'],
      ['bankruptsy', 'bankruptcy', 'wrong ending in bankruptcy'],
      ['garnishmet', 'garnishment', 'typo in garnishment'],
      ['reposession', 'repossession', 'missing letter in repossession'],
      ['colection', 'collection', 'missing letter in collection'],
      ['bankruptcey', 'bankruptcy', 'wrong vowel in bankruptcy'],

      // === Benefits/health typos (4 cases) ===
      ['benifits', 'benefits', 'wrong vowel in benefits'],
      ['foreclsure', 'foreclosure', 'scrambled foreclosure variant'],
      ['disabilty', 'disability', 'missing letter in disability'],
      ['insurace', 'insurance', 'missing letter in insurance'],

      // === Employment typos (4 cases) ===
      ['employmnt', 'employment', 'missing letter in employment'],
      ['terminaed', 'terminated', 'missing letter in terminated'],
      ['harrassment', 'harassment', 'double r in harassment'],
      ['discrimation', 'discrimination', 'missing letters in discrimination'],

      // === General legal typos (8 cases) ===
      ['laywer', 'lawyer', 'transposed letters in lawyer'],
      ['attoney', 'attorney', 'missing letter in attorney'],
      ['elgibility', 'eligibility', 'scrambled eligibility'],
      ['eligable', 'eligible', 'misspelled eligible'],
      ['asistance', 'assistance', 'missing letter in assistance'],
      ['complant', 'complaint', 'missing letter in complaint'],
      ['donaton', 'donation', 'missing letter in donation'],
      ['locaton', 'location', 'missing letter in location'],
    ];
  }

  /**
 *
 */
  #[DataProvider('noFalseCorrectionProvider')]
  public function testNoFalseCorrection(string $input, string $description): void {
    $result = $this->corrector->correct(strtolower($input));
    $this->assertEmpty(
      $result['corrections'],
      "Should not correct anything in: '$input' ($description). Got: " . json_encode($result['corrections'])
    );
  }

  /**
   *
   */
  public static function noFalseCorrectionProvider(): array {
    return [
      ['divorce', 'Correct word should not be corrected'],
      ['custody', 'Correct word should not be corrected'],
      ['eviction', 'Correct word should not be corrected'],
      ['landlord', 'Correct word should not be corrected'],
      ['bankruptcy', 'Correct word should not be corrected'],
      ['help', 'Short word should not be corrected'],
      ['the', 'Stop word should not be corrected'],
      ['hi', 'Very short word should not be corrected'],
      ['xyz', 'Short unknown word should not be corrected'],
      ['renew', 'renew must not drift toward rent/renter'],
      ['renewal', 'renewal is vocabulary'],
      ['residence', 'residence is vocabulary'],
      ['hours', 'hours has no close legal term'],
      ['teens', 'teens must not become tenant'],
      ['tents', 'tents must not become tenant'],
      ['enter', 'enter is a common word'],
    ];
  }

  /**
   * Every dictionary target must be a vocabulary word and every entry a hit.
   */
  public function testDictionaryTargetsAreVocabulary(): void {
    $dictionary = $this->corrector->getDictionary();
    $this->assertArrayHasKey('tenet', $dictionary);
    $this->assertSame('tenant', $dictionary['tenet']);
    foreach ($dictionary as $misspelling => $canonical) {
      $this->assertNotSame($misspelling, $canonical, "Dictionary entry '$misspelling' maps to itself");
      $result = $this->corrector->correct($misspelling);
      $this->assertSame($canonical, $result['text'], "Dictionary entry '$misspelling' should correct to '$canonical'");
      $this->assertSame('dictionary', $result['corrections'][0]['source'] ?? NULL);
      // The canonical word itself must never be corrected.
      $this->assertEmpty($this->corrector->correct($canonical)['corrections'], "Canonical '$canonical' must be vocabulary");
    }
  }

  /**
   * Tests that correct words pass through unchanged.
   */
  public function testCorrectWordsUnchanged(): void {
    $input = 'i need help with divorce and custody';
    $result = $this->corrector->correct($input);
    $this->assertEmpty($result['corrections'], 'No corrections for correct text');
    $this->assertEquals($input, $result['text']);
  }

  /**
   * Tests multiple typos in one message.
   */
  public function testMultipleTypos(): void {
    $result = $this->corrector->correct('custdy divorse help');
    $this->assertStringContainsString('custody', $result['text']);
    $this->assertStringContainsString('divorce', $result['text']);
    $this->assertCount(2, $result['corrections']);
  }

  /**
   * Tests that very different words are not corrected (conservative).
   */
  public function testNoAggressiveCorrection(): void {
    // "pizza" is not close to any legal term.
    $result = $this->corrector->correct('pizza');
    $this->assertEmpty($result['corrections']);

    // "computer" is not close enough to any legal term.
    $result = $this->corrector->correct('computer');
    $this->assertEmpty($result['corrections']);
  }

  /**
   * Tests that the corrector handles empty input.
   */
  public function testEmptyInput(): void {
    $result = $this->corrector->correct('');
    $this->assertEquals('', $result['text']);
    $this->assertEmpty($result['corrections']);
  }

  /**
   * Tests correction in a full sentence context.
   */
  public function testSentenceContext(): void {
    $result = $this->corrector->correct('i need help with my custdy case and divorse papers');
    $this->assertStringContainsString('custody', $result['text']);
    $this->assertStringContainsString('divorce', $result['text']);
    // "help", "need", "with", "my", "case", "and", "papers" should be unchanged.
    $this->assertStringContainsString('help', $result['text']);
  }

}
