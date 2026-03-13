<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use Drupal\ilas_site_assistant\Service\ObservabilityPayloadMinimizer;
use Drupal\ilas_site_assistant\Service\PiiRedactor;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for observability payload minimization helpers.
 *
 * @group ilas_site_assistant
 * @coversDefaultClass \Drupal\ilas_site_assistant\Service\ObservabilityPayloadMinimizer
 */
#[Group('ilas_site_assistant')]
class ObservabilityPayloadMinimizerTest extends TestCase {

  /**
   * Tests metadata generation for redacted text.
   *
   * @covers ::buildTextMetadata
   */
  public function testBuildTextMetadataReturnsHashBucketAndProfile(): void {
    $metadata = ObservabilityPayloadMinimizer::buildTextMetadata('My email is john@example.com');

    $this->assertSame(64, strlen($metadata['text_hash']));
    $this->assertSame(ObservabilityPayloadMinimizer::LENGTH_BUCKET_MEDIUM, $metadata['length_bucket']);
    $this->assertSame('email', $metadata['redaction_profile']);
  }

  /**
   * Tests language hints for Spanish text.
   *
   * @covers ::buildTextMetadataWithLanguage
   */
  public function testBuildTextMetadataWithLanguageDetectsSpanish(): void {
    $metadata = ObservabilityPayloadMinimizer::buildTextMetadataWithLanguage('Me llamo Juan y necesito ayuda');

    $this->assertSame('es', $metadata['language_hint']);
  }

  /**
   * Tests that click analytics normalize to URL paths only.
   *
   * @covers ::normalizeAnalyticsValue
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
   *
   * @covers ::normalizeAnalyticsValue
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
   *
   * @covers ::normalizeAnalyticsValue
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
   *
   * @covers ::normalizeAnalyticsValue
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
   *
   * @covers ::normalizeAnalyticsValue
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
   *
   * @covers ::normalizeAnalyticsValue
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
   *
   * @covers ::serializeAssignments
   */
  public function testSerializeAssignmentsSortsAndNormalizes(): void {
    $serialized = ObservabilityPayloadMinimizer::serializeAssignments([
      'tone' => 'Friendly',
      'cta' => 'apply-first',
    ]);

    $this->assertSame('cta=apply-first,tone=friendly', $serialized);
  }

  /**
   * Tests keyword counting across strings and arrays.
   *
   * @covers ::keywordCount
   */
  public function testKeywordCountHandlesStringsAndArrays(): void {
    $this->assertSame(3, ObservabilityPayloadMinimizer::keywordCount('eviction forms housing'));
    $this->assertSame(2, ObservabilityPayloadMinimizer::keywordCount(['eviction', '', 'forms']));
  }

  /**
   * Tests exception signatures are deterministic and redacted.
   *
   * @covers ::exceptionSignature
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

}
