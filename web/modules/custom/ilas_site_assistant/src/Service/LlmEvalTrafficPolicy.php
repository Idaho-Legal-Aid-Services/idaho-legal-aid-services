<?php

declare(strict_types=1);

namespace Drupal\ilas_site_assistant\Service;

use Symfony\Component\HttpFoundation\Request;

/**
 * Decides how trusted eval traffic is treated by the LLM admission gates.
 *
 * The weekly hosted eval suite sends several hundred serial messages from a
 * single runner IP. Grading it against the per-identity LLM budget (10 per
 * hour) means most ambiguous-intent cases are scored on the deterministic
 * fallback path rather than the production LLM path. Eval clients already
 * identify themselves (X-ILAS-Eval-Run-ID header or eval_run_id payload
 * context, see GapReviewDecider::isPromptfooEvalRequest()).
 *
 * The header is client-controlled, so the decision is environment-gated:
 * never on live, and fail-closed when the environment cannot be resolved.
 * Only the per-identity bucket is skipped; daily/monthly budgets, the global
 * rate limiter, the kill switch, the circuit breaker, the sampling gate and
 * the HTTP flood limits all still apply to eval traffic.
 */
final class LlmEvalTrafficPolicy {

  /**
   * Admission option key carried from the controller to the coordinator.
   */
  public const OPTION_PER_IP_EXEMPT = 'per_ip_budget_exempt';

  /**
   * Returns TRUE when this request may skip the per-identity LLM budget.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The inbound assistant request.
   * @param array<string, mixed> $request_payload
   *   Decoded request body.
   * @param \Drupal\ilas_site_assistant\Service\EnvironmentDetector|null $environment
   *   Environment detector; NULL fails closed.
   */
  public static function isPerIpBudgetExempt(Request $request, array $request_payload, ?EnvironmentDetector $environment): bool {
    if ($environment === NULL || $environment->isLiveEnvironment() || !$environment->isDevOrTestEnvironment()) {
      return FALSE;
    }
    return GapReviewDecider::isPromptfooEvalRequest($request, $request_payload);
  }

}
