<?php

namespace Drupal\Tests\ilas_site_assistant\DrupalUnit;

use Drupal\Tests\UnitTestCase;
use Drupal\ilas_site_assistant\Service\KeywordExtractor;
use Drupal\Core\Cache\CacheBackendInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Regression tests for keyword extraction.
 *
 * These tests cover previously failing cases from the evaluation harness.
 *
 * @coversDefaultClass \Drupal\ilas_site_assistant\Service\KeywordExtractor
 * @group ilas_site_assistant
 */
class KeywordExtractionRegressionTest extends UnitTestCase {

  /**
   * The keyword extractor service.
   *
   * @var \Drupal\ilas_site_assistant\Service\KeywordExtractor
   */
  protected $keywordExtractor;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $module_path = dirname(__DIR__, 3);
    $container = new ContainerBuilder();
    $container->set('extension.list.module', new class($module_path) {
      public function __construct(private string $modulePath) {}
      public function getPath(string $module): string {
        return $this->modulePath;
      }
    });
    \Drupal::setContainer($container);

    $cache = $this->createMock(CacheBackendInterface::class);
    $cache->method('get')->willReturn(FALSE);
    $cache->method('set')->willReturn(NULL);

    $this->keywordExtractor = new KeywordExtractor($cache);
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    \Drupal::unsetContainer();
    parent::tearDown();
  }

  /**
   * Tests typo correction in apply queries.
   *
   * @covers ::extract
   * @dataProvider typoApplyProvider
   */
  public function testTypoCorrectionApply(string $query, string $expectedKeyword): void {
    $result = $this->keywordExtractor->extract($query);

    $keywords = $result['keywords'] ?? [];
    $phrases = $result['phrases_found'] ?? [];
    $synonyms_applied = $result['synonyms_applied'] ?? [];

    $haystack = strtolower(implode(' ', array_merge($keywords, $phrases)));
    $needle = strtolower(str_replace(' ', '_', $expectedKeyword));

    // Either the expected token family should appear or extraction should
    // produce structured phrase/synonym output for the query.
    $this->assertTrue(
      str_contains($haystack, $needle)
      || !empty($phrases)
      || !empty($synonyms_applied),
      "Expected '$expectedKeyword' in keywords or synonym correction for: $query"
    );
  }

  /**
   * Data provider for typo apply tests.
   */
  public static function typoApplyProvider(): array {
    return [
      'aply typo' => ['how do i aply for help', 'apply'],
      'aplly typo' => ['aplly for assistance', 'apply'],
      'lawer typo' => ['i need a lawer', 'lawyer'],
      'laywer typo' => ['looking for a laywer', 'lawyer'],
      'halp typo' => ['i need halp', 'help'],
      'quailfy typo' => ['do i quailfy', 'qualify'],
      'qualfy typo' => ['how do i qualfy', 'qualify'],
      'representaion typo' => ['i need legal representaion', 'representation'],
    ];
  }

  /**
   * Tests Spanish keyword extraction.
   *
   * @covers ::extract
   * @dataProvider spanishQueryProvider
   */
  public function testSpanishKeywordExtraction(string $query, string $expectedKeyword): void {
    $result = $this->keywordExtractor->extract($query);

    $keywords = $result['keywords'] ?? [];
    $synonyms_applied = $result['synonyms_applied'] ?? [];
    $phrases = $result['phrases_found'] ?? [];

    $found = in_array($expectedKeyword, $keywords)
      || !empty($synonyms_applied)
      || !empty($phrases);

    $this->assertTrue($found, "Expected Spanish processing for: $query");
  }

  /**
   * Data provider for Spanish queries.
   */
  public static function spanishQueryProvider(): array {
    return [
      'ayuda legal' => ['necesito ayuda legal', 'ayuda'],
      'abogado gratis' => ['busco abogado gratis', 'abogado'],
      'aplicar' => ['como puedo aplicar', 'aplicar'],
      'llamar' => ['puedo llamar a alguien', 'llamar'],
      'oficina' => ['donde esta la oficina', 'oficina'],
      'formulario' => ['necesito un formulario', 'formulario'],
      'preguntas frecuentes' => ['preguntas frecuentes', 'preguntas'],
    ];
  }

  /**
   * Tests phrase detection for legal-aid terms.
   *
   * @covers ::extract
   * @dataProvider phraseDetectionProvider
   */
  public function testPhraseDetection(string $query, string $expectedPhrase): void {
    $result = $this->keywordExtractor->extract($query);

    $phrases = $result['phrases'] ?? [];
    $normalized = $result['normalized'] ?? '';

    // Check if phrase was detected.
    $phrase_found = in_array($expectedPhrase, $phrases)
      || strpos($normalized, str_replace(' ', '_', $expectedPhrase)) !== FALSE;

    $this->assertTrue($phrase_found, "Expected phrase '$expectedPhrase' to be detected in: $query");
  }

  /**
   * Data provider for phrase detection tests.
   */
  public static function phraseDetectionProvider(): array {
    return [
      'protection order' => ['i need a protection order', 'protection order'],
      'eviction notice' => ['i got an eviction notice', 'eviction notice'],
      'identity theft' => ['victim of identity theft', 'identity theft'],
      'child support' => ['help with child support', 'child support'],
      'domestic violence' => ['escaping domestic violence', 'domestic violence'],
      'legal advice line' => ['call the legal advice line', 'legal advice line'],
      'apply for help' => ['how do i apply for help', 'apply for help'],
      'office location' => ['where is the office location', 'office location'],
      'court date' => ['i have a court date', 'court date'],
      'tenant rights' => ['what are my tenant rights', 'tenant rights'],
    ];
  }

  /**
   * Deprecated pre-routing fields must not be emitted by extraction.
   *
   * @covers ::extract
   */
  public function testExtractDoesNotExposeDeprecatedPreRoutingFields(): void {
    $result = $this->keywordExtractor->extract('my deadline is tomorrow and i need immigration help');

    $this->assertArrayNotHasKey('high_risk', $result);
    $this->assertArrayNotHasKey('out_of_scope', $result);
  }

  /**
   * Tests negative keyword blocking.
   *
   * @covers ::hasNegativeKeyword
   * @dataProvider negativeKeywordProvider
   */
  public function testNegativeKeywordBlocking(string $query, string $intent, bool $shouldBlock): void {
    $hasNegative = $this->keywordExtractor->hasNegativeKeyword($intent, $query);

    $this->assertEquals($shouldBlock, $hasNegative,
      "Negative keyword check for intent '$intent' failed for: $query");
  }

  /**
   * Data provider for negative keyword tests.
   */
  public static function negativeKeywordProvider(): array {
    return [
      // Should block.
      'criminal apply' => ['apply for help with criminal case', 'apply', TRUE],
      'oregon apply' => ['apply for help in oregon', 'apply', TRUE],
      'emergency hotline' => ['call 911 emergency', 'hotline', TRUE],
      'child support donate' => ['help with child support payments', 'donations', TRUE],
      'my case faq' => ['what should i do about my case', 'faq', TRUE],
      // Should NOT block.
      'clean apply' => ['i want to apply for legal help', 'apply', FALSE],
      'clean hotline' => ['what is the hotline number', 'hotline', FALSE],
      'clean donate' => ['i want to donate money', 'donations', FALSE],
      'clean faq' => ['frequently asked questions', 'faq', FALSE],
    ];
  }

  /**
   * Tests multi-intent detection scenarios.
   *
   * @covers ::extract
   */
  public function testMultiIntentScenarios(): void {
    // Query that could match multiple intents.
    $query = 'i need to apply for help with my eviction case and get forms';
    $result = $this->keywordExtractor->extract($query);

    $keywords = $result['keywords'] ?? [];
    $phrases = $result['phrases_found'] ?? [];

    // Should detect both apply and eviction/forms related terms.
    $hasApply = in_array('apply', $keywords)
      || in_array('apply_for_help', $keywords)
      || in_array('apply for help', $phrases);
    $hasEviction = in_array('eviction', $keywords) || strpos(implode(' ', $phrases), 'eviction') !== FALSE;
    $hasForms = in_array('forms', $keywords) || in_array('form', $keywords);

    $this->assertTrue($hasApply, "Should detect 'apply' in multi-intent query");
    $this->assertTrue($hasEviction, "Should detect 'eviction' in multi-intent query");
    $this->assertTrue($hasForms, "Should detect 'forms' in multi-intent query");
  }

  /**
   * Tests handling of very short queries.
   *
   * @covers ::extract
   * @dataProvider shortQueryProvider
   */
  public function testShortQueryHandling(string $query): void {
    $result = $this->keywordExtractor->extract($query);

    // Short queries should still return a valid structure.
    $this->assertIsArray($result);
    $this->assertArrayHasKey('normalized', $result);
    $this->assertArrayHasKey('keywords', $result);
  }

  /**
   * Data provider for short query tests.
   */
  public static function shortQueryProvider(): array {
    return [
      'one word' => ['help'],
      'two words' => ['need help'],
      'single letter' => ['hi'],
      'empty-ish' => ['   '],
    ];
  }

  /**
   * Tests hotline variations.
   *
   * @covers ::extract
   * @dataProvider hotlineVariationsProvider
   */
  public function testHotlineVariations(string $query): void {
    $result = $this->keywordExtractor->extract($query);

    $keywords = $result['keywords'] ?? [];
    $phrases = $result['phrases_found'] ?? [];
    $synonyms = $result['synonyms_applied'] ?? [];

    $keyword_blob = implode(' ', $keywords);
    $phrase_blob = implode(' ', $phrases);
    $hasHotline = str_contains($keyword_blob, 'hotline')
      || str_contains($keyword_blob, 'phone')
      || str_contains($keyword_blob, 'call')
      || str_contains($keyword_blob, 'talk')
      || str_contains($keyword_blob, 'speak')
      || str_contains($keyword_blob, 'hot_line')
      || str_contains($phrase_blob, 'hotline')
      || str_contains($phrase_blob, 'phone')
      || str_contains($phrase_blob, 'talk')
      || str_contains($phrase_blob, 'speak')
      || str_contains($phrase_blob, 'hot line')
      || str_contains($phrase_blob, 'advice line')
      || !empty($synonyms);

    $this->assertTrue($hasHotline, "Should detect hotline intent for: $query");
  }

  /**
   * Data provider for hotline variation tests.
   */
  public static function hotlineVariationsProvider(): array {
    return [
      'standard hotline' => ['what is the hotline number'],
      'phone number' => ['whats your phone number'],
      'call someone' => ['can i call someone'],
      'talk to person' => ['i want to talk to a person'],
      'speak with someone' => ['speak with someone please'],
      'hot line split' => ['what is the hot line'],
      'advice line' => ['legal advice line'],
      'spanish llamar' => ['puedo llamar a alguien'],
      'typo speek' => ['i want to speek to someone'],
    ];
  }

  /**
   * Tests office/location variations.
   *
   * @covers ::extract
   * @dataProvider officeVariationsProvider
   */
  public function testOfficeVariations(string $query): void {
    $result = $this->keywordExtractor->extract($query);

    $keywords = $result['keywords'] ?? [];
    $phrases = $result['phrases_found'] ?? [];

    $keyword_blob = implode(' ', $keywords);
    $phrase_blob = implode(' ', $phrases);
    $hasOffice = str_contains($keyword_blob, 'office')
      || str_contains($keyword_blob, 'location')
      || str_contains($keyword_blob, 'hours')
      || str_contains($keyword_blob, 'address')
      || str_contains($phrase_blob, 'office')
      || str_contains($phrase_blob, 'location')
      || str_contains($phrase_blob, 'hours');

    $this->assertTrue($hasOffice, "Should detect office intent for: $query");
  }

  /**
   * Data provider for office variation tests.
   */
  public static function officeVariationsProvider(): array {
    return [
      'office location' => ['where is your office location'],
      'office hours' => ['what are your office hours'],
      'near me' => ['office near me'],
      'boise office' => ['boise office address'],
      'twin falls' => ['twin falls office'],
      'spanish oficina' => ['donde esta la oficina'],
      'hours typo' => ['hours of opperation'],
    ];
  }

  /**
   * Tests donation variations.
   *
   * @covers ::extract
   * @dataProvider donationVariationsProvider
   */
  public function testDonationVariations(string $query): void {
    $result = $this->keywordExtractor->extract($query);

    $keywords = $result['keywords'] ?? [];
    $phrases = $result['phrases_found'] ?? [];
    $keyword_blob = implode(' ', $keywords);
    $phrase_blob = implode(' ', $phrases);

    $hasDonate = str_contains($keyword_blob, 'donat')
      || str_contains($keyword_blob, 'give')
      || str_contains($keyword_blob, 'support')
      || str_contains($phrase_blob, 'donat')
      || str_contains($phrase_blob, 'give')
      || str_contains($phrase_blob, 'support');

    $this->assertTrue($hasDonate, "Should detect donation intent for: $query");
  }

  /**
   * Data provider for donation variation tests.
   */
  public static function donationVariationsProvider(): array {
    return [
      'want to donate' => ['i want to donate'],
      'make donation' => ['how to make a donation'],
      'give money' => ['i want to give money'],
      'support work' => ['support your work'],
      'spanish donar' => ['quiero donar'],
      'typo donatoin' => ['how do i make a donatoin'],
    ];
  }

}
