<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use Drupal\ilas_site_assistant\Service\RetrievalContract;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the RetrievalContract constants and static validators.
 *
 * @group ilas_site_assistant
 * @coversDefaultClass \Drupal\ilas_site_assistant\Service\RetrievalContract
 */
#[Group('ilas_site_assistant')]
final class RetrievalContractTest extends TestCase {

  /**
   * Approved source classes must be exactly 4.
   */
  public function testApprovedSourceClassesAreExhaustive(): void {
    $this->assertCount(4, RetrievalContract::APPROVED_SOURCE_CLASSES);
    $this->assertContains('faq_lexical', RetrievalContract::APPROVED_SOURCE_CLASSES);
    $this->assertContains('faq_vector', RetrievalContract::APPROVED_SOURCE_CLASSES);
    $this->assertContains('resource_lexical', RetrievalContract::APPROVED_SOURCE_CLASSES);
    $this->assertContains('resource_vector', RetrievalContract::APPROVED_SOURCE_CLASSES);
  }

  /**
   * Primary and supplement sets are disjoint and their union covers all approved.
   */
  public function testPrimaryAndSupplementAreDisjointAndCoverAll(): void {
    $primary = RetrievalContract::PRIMARY_SOURCE_CLASSES;
    $supplement = RetrievalContract::SUPPLEMENT_SOURCE_CLASSES;
    $approved = RetrievalContract::APPROVED_SOURCE_CLASSES;

    // Disjoint: no overlap.
    $overlap = array_intersect($primary, $supplement);
    $this->assertEmpty($overlap, 'Primary and supplement source classes must not overlap.');

    // Union covers all approved.
    $union = array_merge($primary, $supplement);
    sort($union);
    $sorted_approved = $approved;
    sort($sorted_approved);
    $this->assertSame($sorted_approved, $union, 'Union of primary + supplement must equal approved.');
  }

  /**
   * Source priority constants: LEXICAL < VECTOR < LEGACY.
   */
  public function testSourcePriorityLexicalBeforeVector(): void {
    $this->assertLessThan(
      RetrievalContract::SOURCE_PRIORITY_VECTOR,
      RetrievalContract::SOURCE_PRIORITY_LEXICAL,
    );
    $this->assertLessThan(
      RetrievalContract::SOURCE_PRIORITY_LEGACY,
      RetrievalContract::SOURCE_PRIORITY_VECTOR,
    );
  }

  /**
   * assertApprovedSourceClass throws for unknown source class.
   */
  public function testAssertApprovedSourceClassThrowsForUnknown(): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessageMatches('/external_scraper/');
    RetrievalContract::assertApprovedSourceClass('external_scraper');
  }

  /**
   * isPrimarySource returns correct results for all approved classes.
   */
  #[DataProvider('allApprovedSourceClassesProvider')]
  public function testIsPrimarySourceCorrect(string $source_class, bool $expected_primary): void {
    $this->assertSame($expected_primary, RetrievalContract::isPrimarySource($source_class));
  }

  /**
   * isSupplementSource returns correct results for all approved classes.
   */
  #[DataProvider('allApprovedSourceClassesProvider')]
  public function testIsSupplementSourceCorrect(string $source_class, bool $expected_primary): void {
    // Supplement is the inverse of primary for approved classes.
    $this->assertSame(!$expected_primary, RetrievalContract::isSupplementSource($source_class));
  }

  /**
   * Data provider for parametric source class tests.
   */
  public static function allApprovedSourceClassesProvider(): array {
    return [
      'faq_lexical' => ['faq_lexical', TRUE],
      'faq_vector' => ['faq_vector', FALSE],
      'resource_lexical' => ['resource_lexical', TRUE],
      'resource_vector' => ['resource_vector', FALSE],
    ];
  }

}
