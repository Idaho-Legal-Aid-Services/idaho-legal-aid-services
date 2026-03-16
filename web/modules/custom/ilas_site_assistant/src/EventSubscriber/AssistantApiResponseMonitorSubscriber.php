<?php

namespace Drupal\ilas_site_assistant\EventSubscriber;

use Drupal\ilas_site_assistant\Service\PerformanceMonitor;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Records final assistant API outcomes exactly once per request.
 */
class AssistantApiResponseMonitorSubscriber implements EventSubscriberInterface {

  /**
   * Allowed low-impact analytics event types for /track.
   */
  private const TRACK_ALLOWED_TYPES = [
    'chat_open',
    'suggestion_click',
    'resource_click',
    'hotline_click',
    'apply_click',
    'apply_cta_click',
    'apply_secondary_click',
    'service_area_click',
    'topic_selected',
    'feedback_helpful',
    'feedback_not_helpful',
    'ui_troubleshooting',
    'ui_fallback_used',
  ];

  /**
   * Constructs the subscriber.
   */
  public function __construct(
    private readonly ?PerformanceMonitor $performanceMonitor = NULL,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      KernelEvents::REQUEST => ['onRequest', 1000],
      KernelEvents::RESPONSE => ['onResponse', -100],
    ];
  }

  /**
   * Primes monitored requests before controller or cache handling runs.
   */
  public function onRequest(RequestEvent $event): void {
    if (!$event->isMainRequest() || $this->performanceMonitor === NULL) {
      return;
    }

    $request = $event->getRequest();
    $endpoint = $this->resolveEndpoint($request);
    if ($endpoint === NULL) {
      return;
    }

    if (!$request->attributes->has(PerformanceMonitor::ATTRIBUTE_START_TIME)) {
      $request->attributes->set(PerformanceMonitor::ATTRIBUTE_START_TIME, microtime(TRUE));
    }
    $request->attributes->set(PerformanceMonitor::ATTRIBUTE_ENDPOINT, $endpoint);
  }

  /**
   * Records the final classified response once per main request.
   */
  public function onResponse(ResponseEvent $event): void {
    if (!$event->isMainRequest() || $this->performanceMonitor === NULL) {
      return;
    }

    $request = $event->getRequest();
    if ((bool) $request->attributes->get(PerformanceMonitor::ATTRIBUTE_RECORDED, FALSE)) {
      return;
    }

    $endpoint = $request->attributes->get(PerformanceMonitor::ATTRIBUTE_ENDPOINT);
    if (!is_string($endpoint) || $endpoint === '') {
      $endpoint = $this->resolveEndpoint($request);
    }
    if ($endpoint === NULL) {
      return;
    }

    $classification = $this->resolveClassification($request, $event->getResponse(), $endpoint);
    if ($classification === NULL) {
      return;
    }

    $start = (float) $request->attributes->get(
      PerformanceMonitor::ATTRIBUTE_START_TIME,
      (float) ($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(TRUE))
    );
    $duration_ms = max(0.0, (microtime(TRUE) - $start) * 1000);

    $this->performanceMonitor->recordObservedRequest(
      $duration_ms,
      (bool) $classification['success'],
      $endpoint,
      (string) $classification['outcome'],
      (int) $classification['status_code'],
      (bool) ($classification['denied'] ?? FALSE),
      (bool) ($classification['degraded'] ?? FALSE),
      (string) ($classification['scenario'] ?? 'unknown'),
    );

    $request->attributes->set(PerformanceMonitor::ATTRIBUTE_RECORDED, TRUE);
  }

  /**
   * Resolves a canonical endpoint key from the request path.
   */
  private function resolveEndpoint(Request $request): ?string {
    return match ($request->getPathInfo()) {
      '/assistant/api/message' => PerformanceMonitor::ENDPOINT_MESSAGE,
      '/assistant/api/track' => PerformanceMonitor::ENDPOINT_TRACK,
      '/assistant/api/suggest' => PerformanceMonitor::ENDPOINT_SUGGEST,
      '/assistant/api/faq' => PerformanceMonitor::ENDPOINT_FAQ,
      default => NULL,
    };
  }

  /**
   * Resolves explicit or inferred monitoring classification.
   */
  private function resolveClassification(Request $request, Response $response, string $endpoint): ?array {
    $explicit_outcome = $request->attributes->get(PerformanceMonitor::ATTRIBUTE_OUTCOME);
    if (is_string($explicit_outcome) && $explicit_outcome !== '') {
      return [
        'success' => (bool) $request->attributes->get(PerformanceMonitor::ATTRIBUTE_SUCCESS, FALSE),
        'outcome' => $explicit_outcome,
        'status_code' => (int) $request->attributes->get(PerformanceMonitor::ATTRIBUTE_STATUS_CODE, $response->getStatusCode()),
        'denied' => (bool) $request->attributes->get(PerformanceMonitor::ATTRIBUTE_DENIED, FALSE),
        'degraded' => (bool) $request->attributes->get(PerformanceMonitor::ATTRIBUTE_DEGRADED, FALSE),
        'scenario' => (string) $request->attributes->get(PerformanceMonitor::ATTRIBUTE_SCENARIO, 'unknown'),
      ];
    }

    return match ($endpoint) {
      PerformanceMonitor::ENDPOINT_MESSAGE => $this->inferMessageOutcome($request, $response),
      PerformanceMonitor::ENDPOINT_TRACK => $this->inferTrackOutcome($request, $response),
      PerformanceMonitor::ENDPOINT_SUGGEST => $this->inferSuggestOutcome($request, $response),
      PerformanceMonitor::ENDPOINT_FAQ => $this->inferFaqOutcome($request, $response),
      default => NULL,
    };
  }

  /**
   * Infers /message outcomes when controller attributes are absent.
   */
  private function inferMessageOutcome(Request $request, Response $response): array {
    $status = $response->getStatusCode();
    $data = $this->decodeJsonResponse($response);
    $error_code = (string) ($data['error_code'] ?? '');
    $type = (string) ($data['type'] ?? '');
    $reason_code = (string) ($data['reason_code'] ?? '');
    $escalation_type = (string) ($data['escalation_type'] ?? '');

    if ($status === 403) {
      $suffix = $error_code !== '' ? $error_code : 'access_denied';
      return $this->classification(FALSE, 'message.' . $suffix, $status, TRUE);
    }
    if ($status === 429) {
      $error = mb_strtolower((string) ($data['error'] ?? ''));
      $suffix = str_contains($error, 'hourly') ? 'rate_limit_hour' : 'rate_limit_minute';
      return $this->classification(FALSE, 'message.' . $suffix, $status, TRUE);
    }
    if ($status === 413) {
      return $this->classification(FALSE, 'message.request_too_large', $status, TRUE);
    }
    if ($status === 400) {
      $suffix = match (TRUE) {
        $error_code === 'invalid_context' => 'invalid_context',
        $error_code === 'invalid_message' => 'invalid_message',
        str_contains(mb_strtolower((string) ($data['error'] ?? '')), 'content type') => 'invalid_content_type',
        default => 'invalid_request',
      };
      return $this->classification(FALSE, 'message.' . $suffix, $status, TRUE);
    }
    if ($status >= 500) {
      return $this->classification(FALSE, 'message.internal_error', $status);
    }
    if ($type === 'office_location') {
      return $this->classification(TRUE, 'message.office_followup_resolved', $status);
    }
    if ($type === 'office_location_clarify') {
      return $this->classification(TRUE, 'message.office_followup_clarify', $status);
    }
    if ($escalation_type === 'repeated') {
      return $this->classification(TRUE, 'message.repeated_message_escalation', $status);
    }
    if ($type === 'out_of_scope') {
      return $this->classification(TRUE, 'message.out_of_scope_exit', $status);
    }
    if (str_starts_with($reason_code, 'policy_')) {
      return $this->classification(TRUE, 'message.policy_exit', $status);
    }
    if ($type === 'refusal' || ($type === 'escalation' && $reason_code !== '')) {
      return $this->classification(TRUE, 'message.safety_exit', $status);
    }

    return $this->classification(TRUE, 'message.success', $status);
  }

  /**
   * Infers /track outcomes when controller attributes are absent.
   */
  private function inferTrackOutcome(Request $request, Response $response): array {
    $status = $response->getStatusCode();
    $data = $this->decodeJsonResponse($response);

    if ($status === 403) {
      $suffix = (string) ($data['error_code'] ?? 'origin_denied');
      return $this->classification(FALSE, 'track.' . $suffix, $status, TRUE);
    }
    if ($status === 429) {
      return $this->classification(FALSE, 'track.rate_limit', $status, TRUE);
    }
    if ($status === 413) {
      return $this->classification(FALSE, 'track.request_too_large', $status, TRUE);
    }
    if ($status === 400) {
      $suffix = match (TRUE) {
        str_contains(mb_strtolower((string) ($data['error'] ?? '')), 'content type') => 'invalid_content_type',
        str_contains(mb_strtolower((string) ($data['error'] ?? '')), 'missing event_type') => 'missing_event_type',
        default => 'invalid_request',
      };
      return $this->classification(FALSE, 'track.' . $suffix, $status, TRUE);
    }

    $payload = $this->decodeRequestJson($request);
    $event_type = (string) ($payload['event_type'] ?? '');
    if ($event_type !== '' && !in_array($event_type, self::TRACK_ALLOWED_TYPES, TRUE)) {
      return $this->classification(TRUE, 'track.ignored_event_type', $status);
    }

    return $this->classification(TRUE, 'track.success', $status);
  }

  /**
   * Infers /suggest outcomes for controller-bypassed cache hits.
   */
  private function inferSuggestOutcome(Request $request, Response $response): array {
    $status = $response->getStatusCode();
    if ($status === 429) {
      return $this->classification(FALSE, 'suggest.rate_limit', $status, TRUE);
    }

    $query = (string) $request->query->get('q', '');
    if (strlen($query) < 2) {
      return $this->classification(TRUE, 'suggest.short_query_empty', $status);
    }

    $degraded = $this->isDegradedCacheFallback($response);
    return $this->classification(!$degraded, $degraded ? 'suggest.degraded_empty' : 'suggest.success', $status, FALSE, $degraded);
  }

  /**
   * Infers /faq outcomes for controller-bypassed cache hits.
   */
  private function inferFaqOutcome(Request $request, Response $response): array {
    $status = $response->getStatusCode();
    if ($status === 429) {
      return $this->classification(FALSE, 'faq.rate_limit', $status, TRUE);
    }

    $mode = $request->query->has('id')
      ? 'id'
      : ((strlen((string) $request->query->get('q', '')) >= 2) ? 'query' : 'categories');
    $degraded = $this->isDegradedCacheFallback($response);

    return match ($mode) {
      'id' => $this->classification(FALSE, $degraded ? 'faq.degraded_id_not_found' : 'faq.id_not_found', $status, FALSE, $degraded),
      'query' => $this->classification(!$degraded, $degraded ? 'faq.degraded_empty_results' : 'faq.search_success', $status, FALSE, $degraded),
      default => $this->classification(!$degraded, $degraded ? 'faq.degraded_categories' : 'faq.categories_success', $status, FALSE, $degraded),
    };
  }

  /**
   * Builds a normalized classification payload.
   */
  private function classification(
    bool $success,
    string $outcome,
    int $status_code,
    bool $denied = FALSE,
    bool $degraded = FALSE,
  ): array {
    return [
      'success' => $success,
      'outcome' => $outcome,
      'status_code' => $status_code,
      'denied' => $denied,
      'degraded' => $degraded,
      'scenario' => 'unknown',
    ];
  }

  /**
   * Returns TRUE when the response uses the degraded short cache TTL.
   */
  private function isDegradedCacheFallback(Response $response): bool {
    $max_age = $response->getMaxAge();
    return $max_age > 0 && $max_age <= 60;
  }

  /**
   * Decodes a JSON response body.
   */
  private function decodeJsonResponse(Response $response): array {
    $content = (string) $response->getContent();
    if ($content === '') {
      return [];
    }

    $decoded = json_decode($content, TRUE);
    return is_array($decoded) ? $decoded : [];
  }

  /**
   * Decodes a JSON request body.
   */
  private function decodeRequestJson(Request $request): array {
    $decoded = json_decode($request->getContent(), TRUE);
    return is_array($decoded) ? $decoded : [];
  }

}
