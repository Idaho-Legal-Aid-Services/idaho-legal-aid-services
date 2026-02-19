<?php

namespace Drupal\Tests\ilas_site_assistant\Unit;

use Drupal\ilas_site_assistant\Service\InputNormalizer;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Tests for InputNormalizer static utility.
 */
#[Group('ilas_site_assistant')]
class InputNormalizerTest extends TestCase {

  /**
   * Tests interstitial punctuation stripping.
   */
  #[DataProvider('interstitialPunctuationProvider')]
  public function testStripInterstitialPunctuation(string $input, string $expected): void {
    $result = InputNormalizer::stripInterstitialPunctuation($input);
    $this->assertEquals($expected, $result);
  }

  /**
   * Data provider for interstitial punctuation.
   */
  public static function interstitialPunctuationProvider(): array {
    return [
      'dotted letters - legal' => ['l.e.g.a.l', 'legal'],
      'dotted letters - should' => ['s.h.o.u.l.d', 'should'],
      'dotted letters - advice' => ['a.d.v.i.c.e', 'advice'],
      'hyphenated letters - legal' => ['l-e-g-a-l', 'legal'],
      'hyphenated letters - should' => ['s-h-o-u-l-d', 'should'],
      'underscored letters' => ['a_d_v_i_c_e', 'advice'],
      'preserves U.S. (2 segments)' => ['U.S.', 'U.S.'],
      'preserves A.M. (2 segments)' => ['A.M.', 'A.M.'],
      'preserves self-help (multi-char)' => ['self-help', 'self-help'],
      'preserves 3-day (multi-char)' => ['3-day', '3-day'],
      'mixed in sentence' => ['give me l.e.g.a.l a.d.v.i.c.e please', 'give me legal advice please'],
      'preserves normal text' => ['I need help with eviction', 'I need help with eviction'],
    ];
  }

  /**
   * Tests evasion spacing collapse.
   */
  #[DataProvider('evasionSpacingProvider')]
  public function testCollapseEvasionSpacing(string $input, string $expected): void {
    $result = InputNormalizer::collapseEvasionSpacing($input);
    $this->assertEquals($expected, $result);
  }

  /**
   * Data provider for evasion spacing.
   */
  public static function evasionSpacingProvider(): array {
    return [
      'spaced letters - legal' => ['l e g a l', 'legal'],
      'spaced letters - should' => ['s h o u l d', 'should'],
      'preserves normal sentence' => ['I need a form', 'I need a form'],
      'spaced in sentence' => ['give me l e g a l advice', 'give me legal advice'],
    ];
  }

  /**
   * Tests full normalization pipeline.
   */
  #[DataProvider('fullNormalizationProvider')]
  public function testFullNormalization(string $input, string $expected): void {
    $result = InputNormalizer::normalize($input);
    $this->assertEquals($expected, $result);
  }

  /**
   * Data provider for full normalization.
   */
  public static function fullNormalizationProvider(): array {
    return [
      'dotted evasion' => ['l.e.g.a.l a.d.v.i.c.e', 'legal advice'],
      'hyphen evasion' => ['s-h-o-u-l-d I file', 'should I file'],
      'spaced evasion' => ['l e g a l a d v i c e', 'legaladvice'],
      'mixed evasion' => ['give me l.e.g.a.l a d v i c e', 'give me legal advice'],
      'preserves normal text' => ['I need help with my eviction notice', 'I need help with my eviction notice'],
      'preserves 3-day notice' => ['I got a 3-day notice', 'I got a 3-day notice'],
      'preserves self-help' => ['I need a self-help guide', 'I need a self-help guide'],
      'extra whitespace' => ['  too   much   space  ', 'too much space'],
      'empty string' => ['', ''],
    ];
  }

  /**
   * Tests idempotency: normalize(normalize(x)) === normalize(x).
   */
  #[DataProvider('idempotencyProvider')]
  public function testIdempotency(string $input): void {
    $once = InputNormalizer::normalize($input);
    $twice = InputNormalizer::normalize($once);
    $this->assertEquals($once, $twice, 'Normalization should be idempotent');
  }

  /**
   * Data provider for idempotency.
   */
  public static function idempotencyProvider(): array {
    return [
      ['l.e.g.a.l a.d.v.i.c.e'],
      ['s-h-o-u-l-d I file a motion'],
      ['l e g a l advice please'],
      ['I need help with my eviction notice'],
      ['self-help guide for 3-day notice'],
      ['U.S. citizens'],
      [''],
    ];
  }

  /**
   * Tests safe text preservation — these should NOT be altered.
   */
  #[DataProvider('safeTextProvider')]
  public function testSafeTextPreservation(string $input): void {
    $result = InputNormalizer::normalize($input);
    $this->assertEquals($input, $result, "Safe text should not be modified: '$input'");
  }

  /**
   * Data provider for safe text.
   */
  public static function safeTextProvider(): array {
    return [
      ['What services do you offer?'],
      ['How do I apply for help?'],
      ['Where are your offices?'],
      ['I need a self-help guide'],
      ['Tell me about tenant rights'],
      ['I got a 3-day notice'],
      ['I live in the U.S.'],
    ];
  }

  /**
   * Tests Unicode NFKC normalization.
   */
  public function testUnicodeNfkc(): void {
    if (!class_exists('Normalizer')) {
      $this->markTestSkipped('intl extension not available');
    }

    // Fullwidth latin letters should be normalized.
    $fullwidth = "\xEF\xBC\xAC\xEF\xBC\xA5\xEF\xBC\xA7\xEF\xBC\xA1\xEF\xBC\xAC"; // ＬＥＧＡＬ
    $result = InputNormalizer::unicodeNfkc($fullwidth);
    $this->assertEquals('LEGAL', $result);
  }

}
