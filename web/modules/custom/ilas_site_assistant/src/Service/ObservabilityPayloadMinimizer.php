<?php

declare(strict_types=1);

namespace Drupal\ilas_site_assistant\Service;

use Drupal\Core\Site\Settings;

/**
 * Shared helper for minimizing observability payloads.
 */
final class ObservabilityPayloadMinimizer {

  const LENGTH_BUCKET_EMPTY = 'empty';
  const LENGTH_BUCKET_SHORT = '1-24';
  const LENGTH_BUCKET_MEDIUM = '25-99';
  const LENGTH_BUCKET_LONG = '100+';
  const PROFILE_NONE = 'none';

  /**
   * Returns normalized metadata for user-derived text.
   */
  public static function buildTextMetadata(string $text): array {
    $normalized = self::normalizeRedactedText($text);

    return [
      'text_hash' => self::saltedHash($normalized),
      'length_bucket' => self::lengthBucketForNormalized($normalized),
      'redaction_profile' => self::redactionProfileForNormalized($normalized),
    ];
  }

  /**
   * Returns normalized metadata plus language hint.
   */
  public static function buildTextMetadataWithLanguage(string $text): array {
    return self::buildTextMetadata($text) + [
      'language_hint' => self::detectLanguageHint($text),
    ];
  }

  /**
   * Normalizes redacted text for stable hashing.
   */
  public static function normalizeRedactedText(string $text): string {
    if ($text === '') {
      return '';
    }

    $normalized = preg_replace('/\s+/', ' ', trim(PiiRedactor::redact($text)));
    return is_string($normalized) ? $normalized : '';
  }

  /**
   * Returns a deterministic exception signature.
   */
  public static function exceptionSignature(\Throwable $throwable): string {
    return hash('sha256', implode('|', [
      get_class($throwable),
      (string) $throwable->getCode(),
      self::normalizeRedactedText($throwable->getMessage()),
    ]));
  }

  /**
   * Returns a stable hash for opaque identifiers.
   */
  public static function hashIdentifier(string $value): string {
    return self::saltedHash(mb_strtolower(trim($value)));
  }

  /**
   * Returns a SHA-256 hash with a per-installation salt.
   *
   * The salt prevents rainbow-table inversion of common query hashes
   * across installations. Falls back to Drupal's hash_salt when no
   * dedicated observability salt is configured, and to unsalted hash
   * when Settings is unavailable (e.g., unit tests).
   */
  public static function saltedHash(string $value): string {
    $salt = '';
    try {
      $candidate = Settings::get('ilas_observability_hash_salt');
      if (is_string($candidate) && $candidate !== '') {
        $salt = $candidate;
      }
      else {
        $hashSalt = Settings::getHashSalt();
        if (is_string($hashSalt) && $hashSalt !== '') {
          $salt = $hashSalt;
        }
      }
    }
    catch (\Throwable $e) {
      // Settings not initialized (e.g., unit tests without bootstrap).
    }
    if ($salt === '') {
      return hash('sha256', $value);
    }
    return hash('sha256', $salt . '|' . $value);
  }

  /**
   * Returns a short hash prefix for admin/report display.
   */
  public static function hashPrefix(string $hash, int $length = 12): string {
    return mb_substr($hash, 0, $length);
  }

  /**
   * Serializes safe scalar fields into a deterministic summary string.
   */
  public static function summarizeScalarMap(array $values): string {
    if ($values === []) {
      return '';
    }

    $pairs = [];
    ksort($values);
    foreach ($values as $key => $value) {
      if (!is_string($key) || $key === '') {
        continue;
      }

      $normalized = self::normalizeSummaryValue($value);
      if ($normalized === NULL) {
        continue;
      }

      $pairs[] = $key . '=' . $normalized;
    }

    return implode(',', $pairs);
  }

  /**
   * Normalizes analytics event values to a minimized contract.
   *
   * @param string $eventType
   *   The analytics event type.
   * @param string $eventValue
   *   The candidate event value.
   * @param array $options
   *   Optional lookup maps for migrations.
   */
  public static function normalizeAnalyticsValue(string $eventType, string $eventValue, array $options = []): string {
    $value = trim($eventValue);

    return match ($eventType) {
      'chat_open',
      'no_answer',
      'grounding_refusal',
      'post_gen_safety_weak_grounding',
      'post_gen_stale_citations' => '',
      'resource_click',
      'hotline_click',
      'apply_click',
      'apply_cta_click',
      'apply_secondary_click',
      'service_area_click',
      'office_location_followup' => self::normalizePathLikeValue($value, $options['office_lookup'] ?? []),
      'topic_selected' => self::normalizeTopicValue($value, $options['topic_lookup'] ?? []),
      'office_location_followup_miss' => 'unresolved',
      'clarify_loop_break' => $value === '' ? '' : self::hashIdentifier($value),
      'disambiguation_trigger',
      'ambiguity_bucket' => self::normalizeAssignments($value),
      'post_gen_safety_review_flag',
      'post_gen_safety_legal_advice' => self::normalizeUuidLikeValue($value),
      'ab_assignment' => self::normalizeAssignments($value),
      'generic_answer',
      'feedback_helpful',
      'feedback_not_helpful' => self::normalizeApprovedTokenValue($value),
      default => self::normalizeApprovedTokenValue($value),
    };
  }

  /**
   * Returns a stable assignment string.
   */
  public static function serializeAssignments(array $assignments): string {
    if ($assignments === []) {
      return '';
    }

    ksort($assignments);
    $pairs = [];
    foreach ($assignments as $experiment => $variant) {
      $experimentToken = self::normalizeControlledToken((string) $experiment);
      $variantToken = self::normalizeControlledToken((string) $variant);
      if ($experimentToken !== '' && $variantToken !== '') {
        $pairs[] = $experimentToken . '=' . $variantToken;
      }
    }

    return implode(',', $pairs);
  }

  /**
   * Returns keyword-count metadata for search logs.
   *
   * @param array|string $keywords
   *   Keyword array or string.
   */
  public static function keywordCount(array|string $keywords): int {
    if (is_array($keywords)) {
      return count(array_filter($keywords, static fn($keyword) => trim((string) $keyword) !== ''));
    }

    $normalized = preg_replace('/[\s,|]+/', ' ', trim($keywords));
    if (!is_string($normalized) || $normalized === '') {
      return 0;
    }

    return count(array_filter(explode(' ', $normalized), static fn($keyword) => $keyword !== ''));
  }

  /**
   * Detects a coarse language hint without persisting text.
   */
  public static function detectLanguageHint(string $text): string {
    $normalized = mb_strtolower(trim($text));
    if ($normalized === '') {
      return 'unknown';
    }

    if (preg_match('/[áéíóúñü¿¡]/u', $normalized)) {
      return 'es';
    }

    // Keep ambiguous ASCII markers from flipping common English phrases to Spanish.
    $strong_spanish_patterns = [
      '/\bme\s+llamo\b/u',
      '/\bmi\s+nombre\b/u',
      '/\b(llamo|nombre|ayuda|necesito|direccion|telefono|licencia)\b/u',
    ];
    $weak_spanish_patterns = [
      '/\b(como|donde|para|por|vivo)\b/u',
    ];
    $english_patterns = [
      '/\b(the|and|help|need|where|what|how|with|my|apply|forms|guide|eviction|please|can|this)\b/u',
    ];

    $has_strong_spanish = self::matchesAnyPattern($normalized, $strong_spanish_patterns);
    $has_weak_spanish = self::matchesAnyPattern($normalized, $weak_spanish_patterns);
    $has_english = self::matchesAnyPattern($normalized, $english_patterns);

    if ($has_strong_spanish && $has_english) {
      return 'en';
    }

    if ($has_strong_spanish) {
      return 'es';
    }

    if ($has_english) {
      return 'en';
    }

    if ($has_weak_spanish) {
      return 'en';
    }

    if (preg_match('/[a-z]/u', $normalized)) {
      return 'en';
    }

    return 'other';
  }

  /**
   * Returns whether any pattern matches the normalized text.
   */
  private static function matchesAnyPattern(string $normalized, array $patterns): bool {
    foreach ($patterns as $pattern) {
      if (preg_match($pattern, $normalized)) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Returns the length bucket for normalized text.
   */
  private static function lengthBucketForNormalized(string $normalized): string {
    $length = mb_strlen($normalized);
    if ($length === 0) {
      return self::LENGTH_BUCKET_EMPTY;
    }
    if ($length <= 24) {
      return self::LENGTH_BUCKET_SHORT;
    }
    if ($length <= 99) {
      return self::LENGTH_BUCKET_MEDIUM;
    }
    return self::LENGTH_BUCKET_LONG;
  }

  /**
   * Returns a sorted token profile for normalized text.
   */
  private static function redactionProfileForNormalized(string $normalized): string {
    $tokenMap = [
      PiiRedactor::TOKEN_ADDRESS => 'address',
      PiiRedactor::TOKEN_CASE => 'case',
      PiiRedactor::TOKEN_CC => 'cc',
      PiiRedactor::TOKEN_DATE => 'date',
      PiiRedactor::TOKEN_DOB => 'dob',
      PiiRedactor::TOKEN_EMAIL => 'email',
      PiiRedactor::TOKEN_NAME => 'name',
      PiiRedactor::TOKEN_PHONE => 'phone',
      PiiRedactor::TOKEN_SSN => 'ssn',
    ];

    $profile = [];
    foreach ($tokenMap as $token => $label) {
      if (str_contains($normalized, $token)) {
        $profile[] = $label;
      }
    }

    sort($profile);
    return $profile === [] ? self::PROFILE_NONE : implode(',', $profile);
  }

  /**
   * Normalizes a low-cardinality slug/token.
   */
  private static function normalizeControlledToken(string $value): string {
    if ($value === '') {
      return '';
    }

    $normalized = mb_strtolower(trim($value));
    $normalized = preg_replace('/[^a-z0-9:_-]+/', '_', $normalized);
    $normalized = preg_replace('/_+/', '_', (string) $normalized);
    $normalized = trim((string) $normalized, '_');

    return mb_substr((string) $normalized, 0, 255);
  }

  /**
   * Normalizes only already-safe token values.
   */
  private static function normalizeApprovedTokenValue(string $value): string {
    if ($value === '') {
      return '';
    }

    $trimmed = trim($value);
    if (!preg_match('/^[A-Za-z0-9:_-]{1,255}$/', $trimmed)) {
      return '';
    }

    return self::normalizeControlledToken($trimmed);
  }

  /**
   * Normalizes safe scalar display values for Langfuse summaries.
   */
  private static function normalizeSummaryValue(mixed $value): ?string {
    if ($value === NULL) {
      return 'none';
    }

    if (is_bool($value)) {
      return $value ? 'true' : 'false';
    }

    if (is_int($value) || is_float($value)) {
      return (string) $value;
    }

    if (!is_string($value)) {
      return NULL;
    }

    $normalized = preg_replace('/\s+/', '_', trim($value));
    if (!is_string($normalized) || $normalized === '') {
      return 'none';
    }

    return mb_substr($normalized, 0, 255);
  }

  /**
   * Normalizes a UUID-like request identifier.
   */
  private static function normalizeUuidLikeValue(string $value): string {
    return preg_match('/^[a-f0-9-]{36}$/i', $value) ? mb_strtolower($value) : '';
  }

  /**
   * Normalizes a path-only value, optionally using a legacy lookup map.
   */
  private static function normalizePathLikeValue(string $value, array $lookup = []): string {
    if ($value === '') {
      return '';
    }

    if (isset($lookup[mb_strtolower($value)])) {
      $value = $lookup[mb_strtolower($value)];
    }

    $parts = parse_url($value);
    if (is_array($parts)) {
      $path = $parts['path'] ?? '';
      if (is_string($path) && str_starts_with($path, '/')) {
        return $path;
      }
    }

    return str_starts_with($value, '/') ? $value : '';
  }

  /**
   * Normalizes topic IDs, supporting legacy name->ID migration lookups.
   */
  private static function normalizeTopicValue(string $value, array $topicLookup): string {
    if ($value === '') {
      return '';
    }

    if (preg_match('/^\d+$/', $value)) {
      return $value;
    }

    $lookupKey = mb_strtolower(trim($value));
    return isset($topicLookup[$lookupKey]) ? (string) $topicLookup[$lookupKey] : '';
  }

  /**
   * Normalizes persisted A/B assignments.
   */
  private static function normalizeAssignments(string $value): string {
    if ($value === '') {
      return '';
    }

    $decoded = json_decode($value, TRUE);
    if (is_array($decoded)) {
      return self::serializeAssignments($decoded);
    }

    return self::normalizeControlledToken($value);
  }

}
