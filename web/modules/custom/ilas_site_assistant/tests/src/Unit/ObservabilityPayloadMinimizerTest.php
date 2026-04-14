<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use Drupal\ilas_site_assistant\Service\ObservabilityPayloadMinimizer;
use Drupal\ilas_site_assistant\Service\PiiRedactor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for observability payload minimization helpers.
 */
#[CoversClass(ObservabilityPayloadMinimizer::class)]
#[Group('ilas_site_assistant')]
class ObservabilityPayloadMinimizerTest extends TestCase {

  /**
   * Tests metadata generation for redacted text.
   */
  public function testBuildTextMetadataReturnsHashBucketAndProfile(): void {
    $metadata = ObservabilityPayloadMinimizer::buildTextMetadata('My email is john@example.com');

    $this->assertSame(64, strlen($metadata['text_hash']));
    $this->assertSame(ObservabilityPayloadMinimizer::LENGTH_BUCKET_MEDIUM, $metadata['length_bucket']);
    $this->assertSame('email', $metadata['redaction_profile']);
  }

  /**
   * Tests Langfuse previews are redacted, single-line, and quote-safe.
   */
  public function testBuildLangfuseRedactedPreviewSanitizesForUiDisplay(): void {
    $preview = ObservabilityPayloadMinimizer::buildLangfuseRedactedPreview(
      "My name is John Smith.\nMy email is john@example.com and phone is 208-555-1212. Case CV-24-1234. \"Quoted\"",
      160,
    );

    $this->assertStringContainsString(PiiRedactor::TOKEN_NAME, $preview);
    $this->assertStringContainsString(PiiRedactor::TOKEN_EMAIL, $preview);
    $this->assertStringContainsString(PiiRedactor::TOKEN_PHONE, $preview);
    $this->assertStringContainsString(PiiRedactor::TOKEN_CASE, $preview);
    $this->assertStringContainsString("'Quoted'", $preview);
    $this->assertStringNotContainsString('"', $preview);
    $this->assertStringNotContainsString("\n", $preview);
    $this->assertStringNotContainsString('John Smith', $preview);
    $this->assertStringNotContainsString('john@example.com', $preview);
    $this->assertStringNotContainsString('208-555-1212', $preview);
    $this->assertStringNotContainsString('CV-24-1234', $preview);
    $this->assertLessThanOrEqual(160, mb_strlen($preview));
  }

  /**
   * Tests language hints for Spanish text.
   */
  public function testBuildTextMetadataWithLanguageDetectsSpanish(): void {
    $metadata = ObservabilityPayloadMinimizer::buildTextMetadataWithLanguage('Me llamo Juan y necesito ayuda');

    $this->assertSame('es', $metadata['language_hint']);
  }

  /**
   * Tests conservative language-hint detection for English and Spanish text.
   */
  #[DataProvider('languageHintProvider')]
  public function testDetectLanguageHint(string $text, string $expected): void {
    $this->assertSame($expected, ObservabilityPayloadMinimizer::detectLanguageHint($text));
  }

  /**
   * Tests that click analytics normalize to URL paths only.
   */
  public function testNormalizeAnalyticsValueUsesPathForClicks(): void {
    $normalized = ObservabilityPayloadMinimizer::normalizeAnalyticsValue(
      'resource_click',
      'https://example.org/legal-help/housing?foo=bar#x'
    );

    $this->assertSame('/legal-help/housing', $normalized);
  }

  /**
   * Tests that topic selection only keeps controlled numeric IDs.
   */
  public function testNormalizeAnalyticsValueUsesTopicIds(): void {
    $this->assertSame('42', ObservabilityPayloadMinimizer::normalizeAnalyticsValue('topic_selected', '42'));
    $this->assertSame('', ObservabilityPayloadMinimizer::normalizeAnalyticsValue('topic_selected', 'Housing'));
    $this->assertSame('42', ObservabilityPayloadMinimizer::normalizeAnalyticsValue('topic_selected', 'Housing', [
      'topic_lookup' => ['housing' => '42'],
    ]));
  }

  /**
   * Tests that unexpected free text is not preserved in analytics values.
   */
  public function testNormalizeAnalyticsValueDropsUnexpectedFreeText(): void {
    $normalized = ObservabilityPayloadMinimizer::normalizeAnalyticsValue(
      'search_query',
      'My SSN is 123-45-6789 and email is john@example.com'
    );

    $this->assertSame('', $normalized);
  }

  /**
   * Tests conversation identifiers are hashed for loop-break analytics.
   */
  public function testNormalizeAnalyticsValueHashesClarifyLoopBreakId(): void {
    $conversationId = '12345678-1234-4123-8123-123456789abc';

    $this->assertSame(
      ObservabilityPayloadMinimizer::hashIdentifier($conversationId),
      ObservabilityPayloadMinimizer::normalizeAnalyticsValue('clarify_loop_break', $conversationId)
    );
  }

  /**
   * Tests disambiguation-trigger analytics normalize to stable safe tokens.
   */
  public function testNormalizeAnalyticsValueForDisambiguationTrigger(): void {
    $normalized = ObservabilityPayloadMinimizer::normalizeAnalyticsValue(
      'disambiguation_trigger',
      json_encode(['name' => 'generic_help', 'kind' => 'family'])
    );

    $this->assertSame('kind=family,name=generic_help', $normalized);
  }

  /**
   * Tests ambiguity buckets serialize to low-cardinality safe metadata.
   */
  public function testNormalizeAnalyticsValueForAmbiguityBucket(): void {
    $normalized = ObservabilityPayloadMinimizer::normalizeAnalyticsValue(
      'ambiguity_bucket',
      json_encode([
        'family' => 'generic_help',
        'lang' => 'en',
        'len' => '1-24',
        'pair' => 'none',
      ])
    );

    $this->assertSame('family=generic_help,lang=en,len=1-24,pair=none', $normalized);
  }

  /**
   * Tests assignment serialization produces stable, token-only output.
   */
  public function testSerializeAssignmentsSortsAndNormalizes(): void {
    $serialized = ObservabilityPayloadMinimizer::serializeAssignments([
      'tone' => 'Friendly',
      'cta' => 'apply-first',
    ]);

    $this->assertSame('cta=apply-first,tone=friendly', $serialized);
  }

  /**
   * Tests deterministic scalar-map summaries for Langfuse-safe displays.
   */
  public function testSummarizeScalarMapSortsAndNormalizesValues(): void {
    $summary = ObservabilityPayloadMinimizer::summarizeScalarMap([
      'decision' => 'needs review',
      'confidence' => 0.8,
      'reason_code' => NULL,
      'allowed' => TRUE,
    ]);

    $this->assertSame(
      'allowed=true,confidence=0.8,decision=needs_review,reason_code=none',
      $summary,
    );
  }

  /**
   * Tests keyword counting across strings and arrays.
   */
  public function testKeywordCountHandlesStringsAndArrays(): void {
    $this->assertSame(3, ObservabilityPayloadMinimizer::keywordCount('eviction forms housing'));
    $this->assertSame(2, ObservabilityPayloadMinimizer::keywordCount(['eviction', '', 'forms']));
  }

  /**
   * Tests exception signatures are deterministic and redacted.
   */
  public function testExceptionSignatureIsDeterministicAndRedacted(): void {
    $throwable = new \RuntimeException('My email is john@example.com');
    $signature = ObservabilityPayloadMinimizer::exceptionSignature($throwable);

    $this->assertSame(64, strlen($signature));
    $this->assertSame($signature, ObservabilityPayloadMinimizer::exceptionSignature($throwable));
    $this->assertSame(
      hash('sha256', implode('|', [
        \RuntimeException::class,
        '0',
        PiiRedactor::redact('My email is john@example.com'),
      ])),
      $signature
    );
  }

  /**
   * Provides language-hint regression cases.
   */
  public static function languageHintProvider(): array {
    return [
      'english please-help phrase' => ['Please help me with this', 'en'],
      'english help-apply phrase' => ['Help me apply', 'en'],
      'english eviction phrase' => ['Can you help me with eviction forms?', 'en'],
      'obvious spanish phrase' => ['Me llamo Juan y necesito ayuda', 'es'],
      'unaccented spanish phrase' => ['Como aplico para ayuda legal', 'es'],
    ];
  }

}
