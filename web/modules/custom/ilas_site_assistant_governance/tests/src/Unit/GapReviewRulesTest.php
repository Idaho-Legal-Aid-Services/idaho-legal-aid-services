<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant_governance\Unit;

use Drupal\ilas_site_assistant_governance\Entity\AssistantGapItem;
use Drupal\ilas_site_assistant_governance\Service\GapReviewRules;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Tests disposition validation rules for gap review.
 */
#[Group('ilas_site_assistant_governance')]
final class GapReviewRulesTest extends TestCase {

  /**
   * Resolved items must have a disposition.
   */
  public function testResolvedStateRequiresResolutionCode(): void {
    $errors = GapReviewRules::validateDisposition(
      AssistantGapItem::STATE_RESOLVED,
      NULL,
      NULL,
      NULL,
    );

    $this->assertArrayHasKey('resolution_code', $errors);
  }

  /**
   * Blank close dispositions default to "other" for close/archive flows.
   */
  public function testNormalizeCloseResolutionCodeDefaultsBlankValues(): void {
    $this->assertSame(
      AssistantGapItem::RESOLUTION_OTHER,
      GapReviewRules::normalizeCloseResolutionCode(AssistantGapItem::STATE_RESOLVED, '')
    );
    $this->assertSame(
      AssistantGapItem::RESOLUTION_OTHER,
      GapReviewRules::normalizeCloseResolutionCode(AssistantGapItem::STATE_ARCHIVED, NULL)
    );
    $this->assertNull(
      GapReviewRules::normalizeCloseResolutionCode(AssistantGapItem::STATE_REVIEWED, '')
    );
  }

  /**
   * FAQ/content/search dispositions require a reference.
   */
  public function testContentDispositionRequiresReference(): void {
    $errors = GapReviewRules::validateDisposition(
      AssistantGapItem::STATE_RESOLVED,
      AssistantGapItem::RESOLUTION_FAQ_CREATED,
      '',
      'Created a new FAQ entry',
    );

    $this->assertArrayHasKey('resolution_reference', $errors);
  }

  /**
   * Suppression dispositions require short reviewer notes.
   */
  public function testSuppressionDispositionRequiresNotes(): void {
    $errors = GapReviewRules::validateDisposition(
      AssistantGapItem::STATE_ARCHIVED,
      AssistantGapItem::RESOLUTION_TEST_EVAL_TRAFFIC,
      '',
      'too short',
    );

    $this->assertArrayHasKey('resolution_notes', $errors);
  }

  /**
   * A complete disposition passes validation.
   */
  public function testCompleteDispositionPassesValidation(): void {
    $errors = GapReviewRules::validateDisposition(
      AssistantGapItem::STATE_RESOLVED,
      AssistantGapItem::RESOLUTION_CONTENT_UPDATED,
      'node/123',
      'Content was updated to cover the missing question.',
    );

    $this->assertSame([], $errors);
  }

}
