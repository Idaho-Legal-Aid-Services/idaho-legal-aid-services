<?php

declare(strict_types=1);

namespace Drupal\ilas_site_assistant\Service;

/**
 * Decides whether a kb-stub FAQ entry may be imported as live content.
 *
 * FAQ answers are legal information published under ILAS's name and cited by
 * the site assistant, so a stub is importable only when an attorney has
 * written and signed off on the answer. The sign-off lives on the stub:
 *
 * @code
 * review_status: approved        # unreviewed | approved
 * reviewed_by: "Attorney name"
 * reviewed_on: 2026-09-18        # YYYY-MM-DD, not in the future
 * @endcode
 *
 * A stub with no review block is treated as unreviewed.
 */
final class KbStubValidator {

  public const STATUS_APPROVED = 'approved';

  /**
   * Fields every stub needs before a paragraph can be built from it.
   */
  private const REQUIRED_FIELDS = ['id', 'question', 'answer', 'anchor_id'];

  /**
   * Returns the reasons a stub must not be imported; empty when importable.
   *
   * @param array $stub
   *   One entry of a kb-stubs file's `faq_stubs` list.
   * @param \DateTimeImmutable|null $today
   *   Reference date for the reviewed_on check (defaults to now, UTC).
   *
   * @return string[]
   *   Human-readable blocking reasons.
   */
  public static function blockingReasons(array $stub, ?\DateTimeImmutable $today = NULL): array {
    $reasons = [];

    foreach (self::REQUIRED_FIELDS as $field) {
      if (!is_string($stub[$field] ?? NULL) || trim($stub[$field]) === '') {
        $reasons[] = "missing {$field}";
      }
    }

    $answer = is_string($stub['answer'] ?? NULL) ? $stub['answer'] : '';
    if (preg_match('/\bTODO\b/i', $answer)) {
      $reasons[] = 'answer still contains a TODO placeholder';
    }

    $status = is_string($stub['review_status'] ?? NULL) ? trim($stub['review_status']) : '';
    if ($status !== self::STATUS_APPROVED) {
      $reasons[] = $status === ''
        ? 'no review_status (attorney sign-off required)'
        : "review_status is '{$status}', not 'approved'";
    }

    if (!is_string($stub['reviewed_by'] ?? NULL) || trim($stub['reviewed_by']) === '') {
      $reasons[] = 'missing reviewed_by';
    }

    $reasons = array_merge($reasons, self::reviewedOnReasons($stub['reviewed_on'] ?? NULL, $today));

    return $reasons;
  }

  /**
   * Validates every stub in a file; keyed by stub id (or list position).
   *
   * @return array<string, string[]>
   *   Blocking reasons per stub, only for stubs that are blocked.
   */
  public static function blockedStubs(array $stubs, ?\DateTimeImmutable $today = NULL): array {
    $blocked = [];
    foreach (array_values($stubs) as $index => $stub) {
      $reasons = is_array($stub) ? self::blockingReasons($stub, $today) : ['stub is not a mapping'];
      if ($reasons !== []) {
        $key = is_array($stub) && is_string($stub['id'] ?? NULL) && $stub['id'] !== ''
          ? $stub['id']
          : '#' . ($index + 1);
        $blocked[$key] = $reasons;
      }
    }
    return $blocked;
  }

  /**
   * Checks the reviewed_on date.
   *
   * Symfony YAML parses an unquoted 2026-09-18 into a Unix timestamp, so
   * both strings and integers are accepted.
   */
  private static function reviewedOnReasons(mixed $value, ?\DateTimeImmutable $today): array {
    if ($value === NULL || $value === '') {
      return ['missing reviewed_on'];
    }

    $utc = new \DateTimeZone('UTC');
    $date = NULL;
    if (is_int($value)) {
      $date = (new \DateTimeImmutable('@' . $value))->setTimezone($utc);
    }
    elseif (is_string($value)) {
      $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', trim($value), $utc);
      if ($parsed !== FALSE && $parsed->format('Y-m-d') === trim($value)) {
        $date = $parsed;
      }
    }

    if ($date === NULL) {
      return ['reviewed_on is not a YYYY-MM-DD date'];
    }

    $today = ($today ?? new \DateTimeImmutable('now', $utc))->setTimezone($utc)->setTime(23, 59, 59);
    if ($date > $today) {
      return ['reviewed_on is in the future'];
    }

    return [];
  }

}
