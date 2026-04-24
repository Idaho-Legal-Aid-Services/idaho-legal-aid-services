<?php

declare(strict_types=1);

namespace Drupal\ilas_site_assistant\Service;

use Symfony\Component\HttpFoundation\Request;

/**
 * Decides whether a runtime miss belongs in the human gap-review queue.
 */
final class GapReviewDecider {

  /**
   * Canonical reason code for a true dead-end fallback.
   */
  private const REVIEWABLE_REASON_CODE = 'no_match_fallback';

  /**
   * Builds the canonical governance context for a possible gap item.
   */
  public static function buildGovernanceContext(
    array $intent,
    array $response,
    ?array $response_selection_state,
    ?array $resolved_selection,
    ?array $active_selection,
  ): array {
    $selection = self::resolveSelectionContext($response_selection_state, $resolved_selection, $active_selection);
    $topic_id = self::normalizePositiveInt($response['topic']['id'] ?? ($intent['topic_id'] ?? NULL));
    $topic_label = trim((string) ($response['topic']['name'] ?? ($intent['topic'] ?? '')));
    $service_area_id = self::normalizePositiveInt($response['topic']['service_areas'][0]['id'] ?? NULL);
    $service_area_label = trim((string) ($response['topic']['service_areas'][0]['name'] ?? ($intent['area'] ?? '')));
    $selection_present = !empty($selection['button_id']);

    return [
      'intent' => $intent,
      'intent_type' => (string) ($intent['type'] ?? 'unknown'),
      'active_selection_key' => (string) ($selection['button_id'] ?? ''),
      'selection_label' => (string) ($selection['label'] ?? ''),
      'selection_query_label' => (string) ($selection['query_label'] ?? ''),
      'selection_topic_intent' => (string) ($selection['topic_intent'] ?? ''),
      'selection_kind' => (string) ($selection['kind'] ?? ''),
      'selection_resource_kind' => (string) ($selection['resource_kind'] ?? ''),
      'selection_service_area' => self::deriveSelectionServiceAreaKey($selection),
      'topic_id' => $topic_id,
      'topic_label' => $topic_label,
      'service_area_id' => $service_area_id,
      'service_area_label' => $service_area_label,
      'topic_confidence' => $topic_id !== NULL && isset($intent['confidence']) && is_numeric($intent['confidence'])
        ? (int) round(((float) $intent['confidence']) * 100)
        : NULL,
      'assignment_source' => $selection_present
        ? 'selection'
        : ($topic_id !== NULL || $topic_label !== ''
          ? 'router'
          : (!empty($response['topic']) ? 'retrieval' : 'unknown')),
    ];
  }

  /**
   * Returns TRUE when the interaction should create a human review item.
   */
  public static function shouldRecordGapItem(
    array $response,
    array $intent,
    array $governance_context,
    Request $request,
    array $request_payload = [],
  ): bool {
    if (!self::isTrueNoMatchFallback($response)) {
      return FALSE;
    }

    return !self::isPromptfooEvalRequest($request, $request_payload);
  }

  /**
   * Returns TRUE only for a true dead-end fallback response.
   */
  public static function isTrueNoMatchFallback(array $response): bool {
    $response_type = trim((string) ($response['type'] ?? ''));
    $reason_code = trim((string) ($response['reason_code'] ?? ''));

    return $response_type === 'fallback' && $reason_code === self::REVIEWABLE_REASON_CODE;
  }

  /**
   * Returns TRUE when the request came from promptfoo or another eval client.
   */
  public static function isPromptfooEvalRequest(Request $request, array $request_payload = []): bool {
    if (trim((string) $request->headers->get('X-ILAS-Eval-Run-ID', '')) !== '') {
      return TRUE;
    }

    $context = $request_payload['context'] ?? NULL;
    if (!is_array($context)) {
      return FALSE;
    }

    foreach (['eval_run_id', 'evalRunId', 'promptfoo_eval', 'promptfooEval'] as $key) {
      if (trim((string) ($context[$key] ?? '')) !== '') {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Chooses the most relevant structured selection for the current turn.
   */
  private static function resolveSelectionContext(
    ?array $response_selection_state,
    ?array $resolved_selection,
    ?array $active_selection,
  ): array {
    foreach ([$response_selection_state, $resolved_selection, $active_selection] as $selection) {
      if (is_array($selection) && !empty($selection['button_id'])) {
        return $selection;
      }
    }

    return [];
  }

  /**
   * Derives the canonical service-area key from a structured selection.
   */
  private static function deriveSelectionServiceAreaKey(array $selection): string {
    $intent_key = (string) ($selection['topic_intent'] ?? $selection['target_intent'] ?? '');
    $prefix_map = [
      'topic_housing' => 'housing',
      'topic_family' => 'family',
      'topic_consumer' => 'consumer',
      'topic_seniors' => 'seniors',
      'topic_health' => 'health',
      'topic_civil_rights' => 'civil_rights',
      'topic_employment' => 'employment',
    ];

    foreach ($prefix_map as $prefix => $service_area) {
      if ($intent_key === $prefix || str_starts_with($intent_key, $prefix . '_')) {
        return $service_area;
      }
    }

    return '';
  }

  /**
   * Returns a positive integer or NULL.
   */
  private static function normalizePositiveInt(mixed $value): ?int {
    if ($value === NULL || $value === '') {
      return NULL;
    }

    $normalized = (int) $value;
    return $normalized > 0 ? $normalized : NULL;
  }

}
