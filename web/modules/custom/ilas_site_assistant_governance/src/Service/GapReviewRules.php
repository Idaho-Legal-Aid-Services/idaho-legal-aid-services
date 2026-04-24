<?php

declare(strict_types=1);

namespace Drupal\ilas_site_assistant_governance\Service;

use Drupal\ilas_site_assistant_governance\Entity\AssistantGapItem;

/**
 * Pure review-workflow rules for gap-item disposition handling.
 */
final class GapReviewRules {

  /**
   * Normalizes the effective close disposition for resolved/archive states.
   */
  public static function normalizeCloseResolutionCode(string $review_state, ?string $resolution_code): ?string {
    $resolution_code = trim((string) $resolution_code);

    if (!in_array($review_state, [AssistantGapItem::STATE_RESOLVED, AssistantGapItem::STATE_ARCHIVED], TRUE)) {
      return $resolution_code !== '' ? $resolution_code : NULL;
    }

    return $resolution_code !== '' ? $resolution_code : AssistantGapItem::RESOLUTION_OTHER;
  }

  /**
   * Returns validation errors keyed by entity field name.
   *
   * @return array<string, string>
   *   Error messages keyed by form element name.
   */
  public static function validateDisposition(
    string $review_state,
    ?string $resolution_code,
    ?string $resolution_reference,
    ?string $resolution_notes,
  ): array {
    $errors = [];
    $resolution_code = trim((string) $resolution_code);
    $resolution_reference = trim((string) $resolution_reference);
    $resolution_notes = trim((string) $resolution_notes);

    if (in_array($review_state, [AssistantGapItem::STATE_RESOLVED, AssistantGapItem::STATE_ARCHIVED], TRUE) && $resolution_code === '') {
      $errors['resolution_code'] = 'Choose a disposition before resolving or archiving this gap item.';
    }

    if (self::requiresReference($resolution_code) && $resolution_reference === '') {
      $errors['resolution_reference'] = 'This disposition requires a reference to the FAQ, content change, or search tuning work.';
    }

    if (self::requiresSuppressionNotes($resolution_code) && mb_strlen($resolution_notes) < 10) {
      $errors['resolution_notes'] = 'Add a short note explaining why this item is being suppressed.';
    }

    return $errors;
  }

  /**
   * Returns TRUE when the resolution requires a reference.
   */
  public static function requiresReference(?string $resolution_code): bool {
    return in_array((string) $resolution_code, [
      AssistantGapItem::RESOLUTION_FAQ_CREATED,
      AssistantGapItem::RESOLUTION_CONTENT_UPDATED,
      AssistantGapItem::RESOLUTION_SEARCH_TUNED,
    ], TRUE);
  }

  /**
   * Returns TRUE when the resolution suppresses the item from human review.
   */
  public static function requiresSuppressionNotes(?string $resolution_code): bool {
    return in_array((string) $resolution_code, [
      AssistantGapItem::RESOLUTION_EXPECTED_OOS,
      AssistantGapItem::RESOLUTION_FALSE_POSITIVE,
      AssistantGapItem::RESOLUTION_TEST_EVAL_TRAFFIC,
    ], TRUE);
  }

}
