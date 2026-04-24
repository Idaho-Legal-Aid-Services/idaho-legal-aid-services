<?php

declare(strict_types=1);

namespace Drupal\ilas_site_assistant_governance\Service;

use Drupal\ilas_site_assistant\Service\ResponseBuilder;

/**
 * Builds immutable identity boundaries for assistant gap items.
 */
final class GapItemIdentityBuilder {

  /**
   * Builds identity data from runtime no-answer context.
   */
  public function buildFromRuntimeContext(string $query_hash, string $language_hint, array $context, array $topic_context): array {
    return $this->buildIdentity(
      $query_hash,
      $language_hint,
      $context['active_selection_key'] ?? NULL,
      $context['intent_type'] ?? ($context['intent']['type'] ?? NULL),
      $topic_context['topic_id'] ?? NULL,
      $topic_context['service_area_id'] ?? NULL,
    );
  }

  /**
   * Builds identity data from a persisted gap-hit evidence row.
   *
   * @param array<string, mixed> $hit
   *   Gap-hit row data.
   */
  public function buildFromHitRecord(array $hit): array {
    return $this->buildIdentity(
      (string) ($hit['query_hash'] ?? ''),
      (string) ($hit['language_hint'] ?? 'unknown'),
      $hit['active_selection_key'] ?? NULL,
      $hit['intent'] ?? NULL,
      $hit['observed_topic_tid'] ?? NULL,
      $hit['observed_service_area_tid'] ?? NULL,
    );
  }

  /**
   * Builds identity data for legacy aggregate rows.
   */
  public function buildFromLegacyNoAnswerRow(string $query_hash, string $language_hint): array {
    return $this->buildUnknownIdentity($query_hash, $language_hint, 'legacy');
  }

  /**
   * Builds a no-evidence fallback identity for existing gap items.
   */
  public function buildUnknownIdentity(string $query_hash, string $language_hint, string $identity_source = 'route'): array {
    $identity_source = $identity_source === 'legacy' ? 'legacy' : 'route';
    $identity_context_key = $identity_source . ':unknown';

    return [
      'identity_context_key' => $identity_context_key,
      'identity_source' => $identity_source,
      'identity_selection_key' => NULL,
      'identity_intent' => 'unknown',
      'identity_topic_tid' => NULL,
      'identity_service_area_tid' => NULL,
      'cluster_hash' => $this->buildClusterHash($query_hash, $language_hint, $identity_context_key),
    ];
  }

  /**
   * Builds the immutable cluster hash from canonical identity parts.
   */
  public function buildClusterHash(string $query_hash, string $language_hint, string $identity_context_key): string {
    return hash('sha256', trim($query_hash) . '|' . $this->normalizeLanguage($language_hint) . '|' . trim($identity_context_key));
  }

  /**
   * Builds the canonical immutable identity bundle.
   */
  public function buildIdentity(
    string $query_hash,
    string $language_hint,
    mixed $selection_key = NULL,
    mixed $intent = NULL,
    mixed $topic_id = NULL,
    mixed $service_area_id = NULL,
  ): array {
    $normalized_query_hash = trim($query_hash);
    $normalized_language = $this->normalizeLanguage($language_hint);
    $normalized_selection_key = $this->normalizeString($selection_key, 64);
    $normalized_intent = $this->normalizeIntent($intent);
    $normalized_topic_id = $this->normalizePositiveInt($topic_id);
    $normalized_service_area_id = $this->normalizePositiveInt($service_area_id);

    if ($normalized_selection_key !== NULL) {
      $identity_context_key = 'selection:' . $normalized_selection_key;
      $identity_source = 'selection';
    }
    else {
      $identity_context_key = 'route:' . ($normalized_intent ?? 'unknown');
      if ($normalized_topic_id !== NULL) {
        $identity_context_key .= '|topic:' . $normalized_topic_id;
      }
      if ($normalized_service_area_id !== NULL) {
        $identity_context_key .= '|area:' . $normalized_service_area_id;
      }
      $identity_source = 'route';
    }

    return [
      'identity_context_key' => $identity_context_key,
      'identity_source' => $identity_source,
      'identity_selection_key' => $normalized_selection_key,
      'identity_intent' => $normalized_intent ?? 'unknown',
      'identity_topic_tid' => $normalized_topic_id,
      'identity_service_area_tid' => $normalized_service_area_id,
      'cluster_hash' => $this->buildClusterHash($normalized_query_hash, $normalized_language, $identity_context_key),
    ];
  }

  /**
   * Returns a normalized positive integer or NULL.
   */
  private function normalizePositiveInt(mixed $value): ?int {
    if ($value === NULL || $value === '') {
      return NULL;
    }

    $normalized = (int) $value;
    return $normalized > 0 ? $normalized : NULL;
  }

  /**
   * Returns a normalized immutable string or NULL.
   */
  private function normalizeString(mixed $value, int $max_length): ?string {
    $normalized = trim((string) $value);
    if ($normalized === '') {
      return NULL;
    }

    return mb_substr($normalized, 0, $max_length);
  }

  /**
   * Returns a stable language token.
   */
  private function normalizeLanguage(string $language_hint): string {
    $normalized = trim($language_hint);
    return $normalized !== '' ? $normalized : 'unknown';
  }

  /**
   * Returns a stable route-intent token.
   */
  private function normalizeIntent(mixed $intent): ?string {
    $normalized = $this->normalizeString($intent, 64);
    if ($normalized === NULL) {
      return NULL;
    }

    $normalized = ResponseBuilder::normalizeIntentType($normalized);
    return $normalized !== '' ? $normalized : 'unknown';
  }

}
