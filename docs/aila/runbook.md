# Aila Audit Runbook

This runbook defines how to run and verify ILAS Site Assistant (Aila) in local DDEV and Pantheon contexts, and how to regenerate this audit package without exposing secrets or user PII.

Related docs:
- `docs/aila/current-state.md`
- `docs/aila/evidence-index.md`
- `docs/aila/system-map.mmd`
- `docs/aila/artifacts/`
- `docs/aila/runtime/`

## 1) Safety rules (must follow)

- Never print secrets/tokens/keys. Redact all sensitive values before saving artifacts.
- Never capture real user PII from production logs or databases.
- Use synthetic payloads for endpoint checks.
- Do not mutate production behavior/config during audit verification.

### Phase 0 owner-role assignments (Entry criteria #2)

| Workstream | Owner role(s) |
|---|---|
| CSRF hardening (`IMP-SEC-01`) | Security Engineer + Drupal Lead |
| Policy governance (`IMP-GOV-01` prep) | Compliance Lead + Security Engineer |

These are role-based assignments (not individual assignees) and are grounded in restricted governance permissions.[^CLAIM-013]

## 2) Local verification (DDEV)

### Prerequisites

- Docker provider healthy and DDEV installed.
- Repo checked out at the target branch/commit.

### Preflight

```bash
cd /home/evancurry/idaho-legal-aid-services

uname -a
docker info
ddev version
```

Record sanitized output in `docs/aila/runtime/local-preflight.txt`.[^CLAIM-108]

### Host-shell PHPUnit entrypoint

Use the host-safe wrapper for local host-shell PHPUnit runs that may touch
Drupal kernel/bootstrap setup:

```bash
cd /home/evancurry/idaho-legal-aid-services

# Targeted host-side kernel proof.
bash scripts/ci/run-host-phpunit.sh \
  web/modules/custom/ilas_seo/tests/src/Kernel/FullHtmlFilterTest.php

# Host-side kernel/full-suite smoke runs.
npm run test:phpunit:host -- --testsuite kernel --stop-on-failure
npm run test:phpunit:host -- --stop-on-failure
```

- The wrapper only sets `SIMPLETEST_DB` when the caller has not already set it,
  and defaults host-shell kernel installs to an SQLite file under
  `${TMPDIR:-/tmp}`.
- Raw host `vendor/bin/phpunit --configuration /home/evancurry/idaho-legal-aid-services/phpunit.xml`
  is container-oriented because `phpunit.xml` expects the DDEV/Docker MySQL
  hostname `db`.
- Keep DDEV/MySQL parity commands unchanged for `VC-UNIT` and `VC-KERNEL`:
  `ddev exec vendor/bin/phpunit --configuration /var/www/html/phpunit.xml ...`

### Drupal/Drush checks

```bash
ddev start
ddev describe
ddev drush status
ddev drush cr
ddev drush pm:list --status=enabled --type=module
ddev drush router:debug | rg -i "ilas|aila|assistant|chat|site_assistant"
ddev drush config:status
ddev drush state:get system.cron_last
ddev drush queue:list
ddev drush watchdog:show --type=cron --count=30
```

If the target change touched any `*.services.yml` file, treat `ddev drush cr`
as mandatory before route/controller verification. Drupal keeps a compiled
service container, so stale local caches can surface `ServiceNotFoundException`
or "controller is not callable" failures against otherwise-correct code.

If `router:debug` is unavailable, capture the error and run fallback route checks:

```bash
ddev drush core:status
ddev drush php:script scripts/drush/route-names.php -- --filter=ilas
ddev drush php:script scripts/drush/route-names.php -- --filter=assistant
```

> **Warning:** Do NOT use `array_keys()` on `RouteProvider::getAllRoutes()`.
> It returns an `ArrayIterator`, not an array. Use `iterator_to_array()` first,
> or use the `scripts/drush/route-names.php` helper.

Store sanitized output in `docs/aila/runtime/local-runtime.txt`.[^CLAIM-109][^CLAIM-110][^CLAIM-111][^CLAIM-114]

### Route + endpoint schema verification (synthetic)

```bash
BASE_URL="https://ilas-pantheon.ddev.site"
COOKIE_JAR="$(mktemp -t ilas-csrf-cookie.XXXXXX)"
# Prime anonymous session with a cache-busting request so token/session binding
# is created even when anonymous page cache is warm.
CSRF_PRIME="$(date +%s%N)"
curl -k -sS -c "${COOKIE_JAR}" -b "${COOKIE_JAR}" "${BASE_URL}/assistant?csrf_prime=${CSRF_PRIME}" >/dev/null
CSRF_TOKEN=$(curl -k -sS -c "${COOKIE_JAR}" -b "${COOKIE_JAR}" "${BASE_URL}/assistant/api/session/bootstrap")

# Synthetic message request
curl -k -sS -X POST "${BASE_URL}/assistant/api/message" \
  -H "Content-Type: application/json" \
  -H "X-CSRF-Token: ${CSRF_TOKEN}" \
  -b "${COOKIE_JAR}" \
  -d '{"message":"SYNTHETIC EXAMPLE: where can I find housing forms?","conversation_id":"11111111-1111-4111-8111-111111111111"}'

# Synthetic track request
curl -k -sS -X POST "${BASE_URL}/assistant/api/track" \
  -H "Content-Type: application/json" \
  -H "Origin: ${BASE_URL%/}" \
  -d '{"event_type":"chat_open","event_value":"SYNTHETIC EXAMPLE"}'

# Anonymous CSRF matrix (same cookie jar for token-bound validation).
CSRF_PRIME="$(date +%s%N)"
curl -k -sS -c "${COOKIE_JAR}" -b "${COOKIE_JAR}" "${BASE_URL}/assistant?csrf_prime=${CSRF_PRIME}" >/dev/null
ANON_TOKEN=$(curl -k -sS -c "${COOKIE_JAR}" -b "${COOKIE_JAR}" "${BASE_URL}/assistant/api/session/bootstrap")

# message: missing token -> 403
curl -k -sS -D '<headers>' -o '<body>' -X POST "${BASE_URL}/assistant/api/message" \
  -H "Content-Type: application/json" \
  -b "${COOKIE_JAR}" \
  -d '{"message":"SYNTHETIC EXAMPLE: matrix missing token"}'

# message: invalid token -> 403
curl -k -sS -D '<headers>' -o '<body>' -X POST "${BASE_URL}/assistant/api/message" \
  -H "Content-Type: application/json" \
  -H "X-CSRF-Token: invalid-token" \
  -b "${COOKIE_JAR}" \
  -d '{"message":"SYNTHETIC EXAMPLE: matrix invalid token"}'

# message: valid token (same cookie jar) -> 200
curl -k -sS -D '<headers>' -o '<body>' -X POST "${BASE_URL}/assistant/api/message" \
  -H "Content-Type: application/json" \
  -H "X-CSRF-Token: ${ANON_TOKEN}" \
  -b "${COOKIE_JAR}" \
  -d '{"message":"SYNTHETIC EXAMPLE: matrix valid token"}'

# track request (missing Origin/Referer, no fallback token) -> 403
curl -k -sS -D '<headers>' -o '<body>' -X POST "${BASE_URL}/assistant/api/track" \
  -H "Content-Type: application/json" \
  -d '{"event_type":"chat_open","event_value":"SYNTHETIC EXAMPLE track missing browser proof"}'

# track request (same-origin Origin) -> 200
curl -k -sS -D '<headers>' -o '<body>' -X POST "${BASE_URL}/assistant/api/track" \
  -H "Content-Type: application/json" \
  -H "Origin: ${BASE_URL%/}" \
  -d '{"event_type":"chat_open","event_value":"SYNTHETIC EXAMPLE track same-origin origin"}'

# track request (cross-origin Origin) -> 403
curl -k -sS -D '<headers>' -o '<body>' -X POST "${BASE_URL}/assistant/api/track" \
  -H "Content-Type: application/json" \
  -H "Origin: https://evil.example" \
  -d '{"event_type":"chat_open","event_value":"SYNTHETIC EXAMPLE track cross-origin origin"}'

# track request (same-origin Referer, no Origin) -> 200
curl -k -sS -D '<headers>' -o '<body>' -X POST "${BASE_URL}/assistant/api/track" \
  -H "Content-Type: application/json" \
  -H "Referer: ${BASE_URL%/}/assistant" \
  -d '{"event_type":"chat_open","event_value":"SYNTHETIC EXAMPLE track same-origin referer"}'

# track request (missing Origin/Referer + bootstrap token fallback) -> 200
curl -k -sS -D '<headers>' -o '<body>' -X POST "${BASE_URL}/assistant/api/track" \
  -H "Content-Type: application/json" \
  -H "X-CSRF-Token: ${ANON_TOKEN}" \
  -b "${COOKIE_JAR}" \
  -d '{"event_type":"chat_open","event_value":"SYNTHETIC EXAMPLE track bootstrap fallback"}'

rm -f "${COOKIE_JAR}"

# Read endpoints and private diagnostics checks
curl -k -sS "${BASE_URL}/assistant/api/suggest?q=housing&type=all"
curl -k -sS "${BASE_URL}/assistant/api/faq?q=eviction"

# Anonymous diagnostics negative path -> 403 access_denied
curl -k -sS "${BASE_URL}/assistant/api/health"
curl -k -sS "${BASE_URL}/assistant/api/metrics"

# Positive diagnostics path -> admin session or private machine header
curl -k -sS -H "X-ILAS-Observability-Key: ${ILAS_ASSISTANT_DIAGNOSTICS_TOKEN}" "${BASE_URL}/assistant/api/health"
curl -k -sS -H "X-ILAS-Observability-Key: ${ILAS_ASSISTANT_DIAGNOSTICS_TOKEN}" "${BASE_URL}/assistant/api/metrics"

# RAUD-20 read-endpoint abuse-control proof. Use varied queries so Drupal does
# not satisfy the second request from a cached 200 before the controller-level
# flood guard executes.
ddev exec bash -lc "vendor/bin/phpunit \
  --configuration /var/www/html/phpunit.xml \
  /var/www/html/web/modules/custom/ilas_site_assistant/tests/src/Functional/AssistantApiFunctionalTest.php \
  --filter 'test(SuggestEndpointAccessible|FaqEndpointAccessible|SuggestEndpointRateLimitAppliesToRepeatedGetRequests|FaqEndpointRateLimitAppliesToRepeatedGetRequests)'"
```

Store status/headers/schema-key output (no secrets, synthetic payloads only) in `docs/aila/runtime/local-endpoints.txt`.[^CLAIM-112][^CLAIM-113]

Matrix acceptance test command (message CSRF matrix + track mitigation):

```bash
ddev exec bash -lc "vendor/bin/phpunit \
  --configuration /var/www/html/phpunit.xml \
  /var/www/html/web/modules/custom/ilas_site_assistant/tests/src/Functional/AssistantApiFunctionalTest.php \
  --filter 'test(MessageEndpoint(RequiresCsrfToken|RejectsInvalidCsrfToken|WithCsrfToken)|AnonymousMessageEndpoint(RequiresCsrfToken|RejectsInvalidCsrfToken|AllowsValidCsrfToken)|TrackEndpoint(WithoutBrowserProofReturnsTrackProofMissing|AcceptsValidEvent|RejectsCrossOriginOriginHeader|AllowsSameOriginOriginHeader|AllowsSameOriginRefererHeader|RejectsCrossOriginRefererHeader|AllowsBootstrapTokenWhenBrowserHeadersMissing|RejectsInvalidBootstrapTokenWhenBrowserHeadersMissing|RecoveryWithFreshBootstrapToken|FloodLimitAppliesToAllowedRequests)|AnonymousTrackEndpointWithoutBrowserProofReturnsTrackProofMissing)'"
```

CSRF deny telemetry verification:

```bash
ddev drush watchdog:show --count=200 | grep -E 'csrf_deny|token_state=|auth_state='
```

### Deterministic dependency degrade contract verification (`P1-OBJ-02`)

Use deterministic unit contracts to verify dependency-failure degrade behavior
without enabling live LLM output paths.

```bash
# Retrieval dependency failures: Search API unavailable/query exceptions and
# vector unavailable paths degrade to legacy/lexical outputs.
ddev exec vendor/bin/phpunit \
  --configuration /var/www/html/phpunit.xml \
  /var/www/html/web/modules/custom/ilas_site_assistant/tests/src/Unit/DependencyFailureDegradeContractTest.php

# LLM dependency-failure matrix: 429/5xx retry handling, timeout/no-status
# transport failures, unavailable dependency fallback, deterministic response class.
ddev exec vendor/bin/phpunit \
  --configuration /var/www/html/phpunit.xml \
  /var/www/html/web/modules/custom/ilas_site_assistant/tests/src/Unit/LlmEnhancerHardeningTest.php
```

Expected contract result:
- Retrieval dependency failures do not throw uncaught exceptions and degrade to
  stable legacy/lexical outputs.
- LLM dependency failures degrade to deterministic non-LLM response behavior
  when `llm.fallback_on_error=true`.
- Controller-level uncaught failures remain controlled `500 internal_error`.

### Integration failure contract verification (`IMP-REL-01`)

Consolidated failure-mode contract tests verifying controller catch-all behavior,
observability isolation, and cross-cutting request_id/correlation ID consistency.

```bash
ddev exec vendor/bin/phpunit --configuration /var/www/html/phpunit.xml \
  /var/www/html/web/modules/custom/ilas_site_assistant/tests/src/Unit/IntegrationFailureContractTest.php
```

Expected contract result:
- Controller catch-all returns HTTP 500 with `error.code=internal_error` and request_id.
- Observability services (AnalyticsLogger, ConversationLogger, LangfuseTracer)
  swallow internal exceptions without propagation.
- All response paths (200/400/413/429/500) include request_id in body and
  X-Correlation-ID in header.
- Failure matrix documents all 10 dependency failure → fallback class mappings.

### Idempotency and replay verification (`IMP-REL-02`)

Replay/idempotency contract tests verifying correlation ID resolution, cache key
determinism, repeated-message escalation, and request_id consistency.

```bash
ddev exec vendor/bin/phpunit --configuration /var/www/html/phpunit.xml \
  /var/www/html/web/modules/custom/ilas_site_assistant/tests/src/Unit/IdempotencyReplayContractTest.php
```

Expected contract result:
- Valid UUID4 correlation IDs are accepted; invalid inputs are rejected with
  deterministic UUID4 fallback generation.
- Conversation cache keys are deterministic (`ilas_conv:<uuid>`) and differ by ID.
- Three identical cached messages trigger repeated-message escalation (not duplication).
- All error response paths have body request_id matching X-Correlation-ID header.
- Replay with same input and correlation ID produces deterministic response type.

### Phase 3 objective #1 accessibility + mobile UX acceptance verification (`P3-OBJ-01`)

Use this command bundle to verify Phase 3 Objective #1:
"Complete accessibility and mobile UX hardening with explicit acceptance gates."

```bash
# 1) Required prompt validation aliases.
# VC-UNIT
ddev exec vendor/bin/phpunit \
  --configuration /var/www/html/phpunit.xml \
  --group ilas_site_assistant \
  /var/www/html/web/modules/custom/ilas_site_assistant/tests/src/Unit

# VC-DRUPAL-UNIT
vendor/bin/phpunit \
  --configuration /home/evancurry/idaho-legal-aid-services/phpunit.xml \
  --testsuite drupal-unit

# 2) Targeted acceptance test execution.
ddev exec vendor/bin/phpunit \
  --configuration /var/www/html/phpunit.xml \
  --group ilas_site_assistant \
  /var/www/html/web/modules/custom/ilas_site_assistant/tests/src/Unit/AccessibilityMobileUxAcceptanceGateTest.php

ddev exec vendor/bin/phpunit \
  --configuration /var/www/html/phpunit.xml \
  --group ilas_site_assistant \
  /var/www/html/web/modules/custom/ilas_site_assistant/tests/src/Unit/RecoveryUxContractTest.php

# 3) Source-anchor checks (accessibility + mobile UX claims).
rg -n "CLAIM-025|CLAIM-032|dialog roles|focus trap|ARIA|Escape-to-close" \
  docs/aila/evidence-index.md

rg -n "CLAIM-026|CLAIM-031|timeout|offline|reduced-motion|mobile layout" \
  docs/aila/evidence-index.md

# 4) Governance linkage checks (backlog/risk).
rg -n "Done \(IMP-UX-01, 2026-03-05\)" \
  docs/aila/backlog.md

rg -n "R-UX-01|R-UX-02|phase3-obj1-ux-a11y-mobile-acceptance.txt|Active mitigation" \
  docs/aila/risk-register.md

# 5) Optional docs continuity check (non-blocking).
ddev exec vendor/bin/phpunit \
  --configuration /var/www/html/phpunit.xml \
  --group ilas_site_assistant_docs \
  /var/www/html/web/modules/custom/ilas_site_assistant/tests/src/Unit/PhaseThreeObjectiveOneGateTest.php
```

Expected `P3-OBJ-01` verification result:
- `VC-UNIT` and `VC-DRUPAL-UNIT` pass with behavioral acceptance coverage included.
- `AccessibilityMobileUxAcceptanceGateTest` (20 test methods) and
  `RecoveryUxContractTest` (4 test methods) pass for widget accessibility,
  ARIA semantics, focus management, and recovery UX contracts.
- Source-anchor checks confirm accessibility (`CLAIM-025`, `CLAIM-032`) and
  mobile UX (`CLAIM-026`, `CLAIM-031`) evidence remains present.
- UX/accessibility backlog rows are marked Done and risk register entries
  (`R-UX-01`, `R-UX-02`) show active mitigation posture.
- Optional docs continuity remains runnable through
  `PhaseThreeObjectiveOneGateTest.php` in the non-blocking
  `ilas_site_assistant_docs` group.
- Scope boundaries remain unchanged: no net-new assistant channels or
  third-party model expansion beyond audited providers, and no platform-wide
  refactor of unrelated Drupal subsystems.

Store sanitized output in:
- `docs/aila/runtime/phase3-obj1-ux-a11y-mobile-acceptance.txt`[^CLAIM-149]

## 3) Pantheon-context verification

Direct verification commands:

```bash
terminus whoami

for ENV in dev test live; do
  terminus env:wake "idaho-legal-aid-services.${ENV}"
  terminus remote:drush "idaho-legal-aid-services.${ENV}" -- status
  terminus remote:drush "idaho-legal-aid-services.${ENV}" -- config:status
  terminus remote:drush "idaho-legal-aid-services.${ENV}" -- state:get system.cron_last
  terminus remote:drush "idaho-legal-aid-services.${ENV}" -- queue:list
  terminus remote:drush "idaho-legal-aid-services.${ENV}" -- config:get ilas_site_assistant.settings -y
  terminus remote:drush "idaho-legal-aid-services.${ENV}" -- pml --status=enabled --type=module --no-core --format=list | rg '^raven$'
  terminus remote:drush "idaho-legal-aid-services.${ENV}" -- php:eval "\$c=\Drupal::config('ilas_site_assistant.settings'); \$r=\Drupal::config('raven.settings'); echo 'langfuse_enabled=' . (\$c->get('langfuse.enabled') ? 'true':'false') . PHP_EOL; echo 'langfuse_public_key=' . (\$c->get('langfuse.public_key') ? 'present':'missing') . PHP_EOL; echo 'langfuse_secret_key=' . (\$c->get('langfuse.secret_key') ? 'present':'missing') . PHP_EOL; echo 'raven_client_key=' . (\$r->get('client_key') ? 'present':'missing') . PHP_EOL;"
  terminus remote:drush "idaho-legal-aid-services.${ENV}" -- watchdog:show --type=cron --count=30
done
```

When you document a Drupal config probe for `drush php:eval`, prefer the plain
`Drupal::config(...)` form shown in the validation bundles so the runbook stays
aligned with the exact commands re-executed during the 2026-03-13 TOVR-01
tooling-truth refresh.[^CLAIM-195]

Store sanitized outputs in:
- `docs/aila/runtime/pantheon-dev.txt`
- `docs/aila/runtime/pantheon-test.txt`
- `docs/aila/runtime/pantheon-live.txt`[^CLAIM-115][^CLAIM-116][^CLAIM-117][^CLAIM-118][^CLAIM-119][^CLAIM-120][^CLAIM-121]

Expected policy result: the `live` `config:get ilas_site_assistant.settings -y`
output must show effective `llm.enabled: false`.

### IMP-SLO-01 SLO set + alert policy (availability/latency/errors/cron/queue)

Canonical SLO targets (from `ilas_site_assistant.settings.slo`):

- Availability: `availability_pct >= 99.5`
- Latency: `p95 <= 2000ms`, `p99 <= 5000ms`
- Error rate: `error_rate_pct <= 5.0` (`error_budget_window_hours=168`)
- Cron freshness: `cron_max_age_seconds=7200` (`cron_expected_cadence_seconds=3600`)
- Queue health: `queue_max_depth=10000`, `queue_max_age_seconds=3600`

Alert policy contract:

- `/assistant/api/health` must expose `checks` for `availability_pct`,
  `latency_p95_ms`, `error_rate_pct`, `cron`, and `queue`.
- `/assistant/api/metrics` must expose `thresholds` matching `slo.*` config.
- `/assistant/api/health` and `/assistant/api/metrics` remain private:
  anonymous/sessionless requests should return `403 access_denied`, while
  operators and machine monitors must use Drupal permission or
  `X-ILAS-Observability-Key` sourced from the runtime-only
  `ILAS_ASSISTANT_DIAGNOSTICS_TOKEN` secret.[^CLAIM-212]
- `SloAlertService` emits structured watchdog warnings (`SLO violation: ...`)
  for availability, latency, error-rate, cron, and queue breaches.
- Alert cooldown is 900 seconds per SLO dimension to reduce noise.

Verification commands (local):

```bash
BASE_URL="https://<local-host>"
# Anonymous/sessionless negative path.
curl -k -sS "${BASE_URL}/assistant/api/health"
curl -k -sS "${BASE_URL}/assistant/api/metrics"

# Positive path for external monitors. Pull the token from the environment's
# secret manager; it is runtime-only and is not exposed through Drupal config,
# forms, or drupalSettings.
curl -k -sS -H "X-ILAS-Observability-Key: ${ILAS_ASSISTANT_DIAGNOSTICS_TOKEN}" \
  "${BASE_URL}/assistant/api/health"
curl -k -sS -H "X-ILAS-Observability-Key: ${ILAS_ASSISTANT_DIAGNOSTICS_TOKEN}" \
  "${BASE_URL}/assistant/api/metrics"

# Trigger cron-driven SLO checks and inspect emitted violations (if any).
ddev drush cron
ddev drush watchdog:show --count=200 | rg 'SLO violation|Chatbot API (latency|error rate)'
```

Verification commands (Pantheon):

```bash
for ENV in dev test live; do
  BASE_URL="$(terminus env:view "idaho-legal-aid-services.${ENV}" --print)"
  # Anonymous negative path.
  curl -k -sS "${BASE_URL%/}/assistant/api/health"
  curl -k -sS "${BASE_URL%/}/assistant/api/metrics"

  # Positive path with the environment-specific runtime secret.
  curl -k -sS -H "X-ILAS-Observability-Key: ${ILAS_ASSISTANT_DIAGNOSTICS_TOKEN}" \
    "${BASE_URL%/}/assistant/api/health"
  curl -k -sS -H "X-ILAS-Observability-Key: ${ILAS_ASSISTANT_DIAGNOSTICS_TOKEN}" \
    "${BASE_URL%/}/assistant/api/metrics"
done
```

### Phase 3 objective #2 performance + cost guardrails operational verification (`P3-OBJ-02`)

Use this command bundle to verify Phase 3 Objective #2:
"Finalize performance and cost guardrails with operational runbooks."

```bash
# 1) Required proof aliases.
# VC-PURE
vendor/bin/phpunit \
  -c /home/evancurry/idaho-legal-aid-services/phpunit.pure.xml \
  --filter 'CostControlPolicyTest|LlmControlConcurrencyTest|LlmEnhancerHardeningTest|ConfigCompletenessDriftTest|CrossPhaseDependencyRowSixBehaviorTest|AssistantApiControllerCostControlMetricsTest'

# VC-UNIT
vendor/bin/phpunit \
  --configuration /home/evancurry/idaho-legal-aid-services/phpunit.xml \
  --group ilas_site_assistant \
  /home/evancurry/idaho-legal-aid-services/web/modules/custom/ilas_site_assistant/tests/src/Unit/CostControlPolicyTest.php \
  /home/evancurry/idaho-legal-aid-services/web/modules/custom/ilas_site_assistant/tests/src/Unit/LlmControlConcurrencyTest.php \
  /home/evancurry/idaho-legal-aid-services/web/modules/custom/ilas_site_assistant/tests/src/Unit/LlmEnhancerHardeningTest.php \
  /home/evancurry/idaho-legal-aid-services/web/modules/custom/ilas_site_assistant/tests/src/Unit/ConfigCompletenessDriftTest.php \
  /home/evancurry/idaho-legal-aid-services/web/modules/custom/ilas_site_assistant/tests/src/Unit/CrossPhaseDependencyRowSixBehaviorTest.php \
  /home/evancurry/idaho-legal-aid-services/web/modules/custom/ilas_site_assistant/tests/src/Unit/AssistantApiControllerCostControlMetricsTest.php

# VC-QUALITY-GATE
bash /home/evancurry/idaho-legal-aid-services/web/modules/custom/ilas_site_assistant/tests/run-quality-gate.sh

# 2) Source guardrail anchor checks (LLM + performance/SLO).
rg -n "isLiveEnvironment|llm\\.enabled|circuitBreaker|rateLimiter|global rate limit|circuit breaker" \
  web/modules/custom/ilas_site_assistant/src/Service/LlmEnhancer.php

rg -n "class LlmCircuitBreaker|isAvailable|recordFailure|recordSuccess|STATE_KEY" \
  web/modules/custom/ilas_site_assistant/src/Service/LlmCircuitBreaker.php

rg -n "class LlmRateLimiter|isAllowed|recordCall|wasRateLimited|STATE_KEY" \
  web/modules/custom/ilas_site_assistant/src/Service/LlmRateLimiter.php

rg -n "class PerformanceMonitor|recordRequest|getSummary|THRESHOLD_P95_MS|THRESHOLD_ERROR_RATE" \
  web/modules/custom/ilas_site_assistant/src/Service/PerformanceMonitor.php

rg -n "class SloAlertService|checkAll|SLO violation|checkLatencySlo|checkErrorRateSlo|checkQueueSlo" \
  web/modules/custom/ilas_site_assistant/src/Service/SloAlertService.php

rg -n "class CostControlPolicy|isRequestAllowed|evaluateKillSwitch|estimateCost|getSummary|isDailyBudgetExhausted|isMonthlyBudgetExhausted" \
  web/modules/custom/ilas_site_assistant/src/Service/CostControlPolicy.php

rg -n "LlmControlConcurrencyTest.php|LlmEnhancerHardeningTest.php|AssistantApiControllerCostControlMetricsTest.php|cost-proof-per-ip-status|cost-proof-cache-hit-rate|cost-proof-call-reduction-rate" \
  web/modules/custom/ilas_site_assistant/tests/src/Unit/LlmControlConcurrencyTest.php \
  web/modules/custom/ilas_site_assistant/tests/src/Unit/LlmEnhancerHardeningTest.php \
  web/modules/custom/ilas_site_assistant/tests/src/Unit/AssistantApiControllerCostControlMetricsTest.php \
  docs/aila/runtime/phase3-obj2-performance-cost-guardrails.txt

# 3) Optional docs continuity check (non-blocking).
ddev exec vendor/bin/phpunit \
  --configuration /var/www/html/phpunit.xml \
  --group ilas_site_assistant_docs \
  /var/www/html/web/modules/custom/ilas_site_assistant/tests/src/Unit/PhaseThreeObjectiveTwoGateTest.php
```

Expected `P3-OBJ-02` verification result:
- `VC-PURE`, `VC-UNIT`, and `VC-QUALITY-GATE` pass with behavioral cost/performance proof included.
- LLM call guardrail anchors remain present in `LlmEnhancer`,
  `LlmCircuitBreaker`, and `LlmRateLimiter` without net-new provider/channel
  expansion.
- Performance/SLO guardrail anchors remain present in `PerformanceMonitor` and
  `SloAlertService`.
- `CostControlPolicy` anchors remain present with budget caps, per-IP budget
  enforcement, sampling gate, cache-hit monitoring, cache-effectiveness proof,
  cost estimation, and kill-switch evaluator (IMP-COST-01).
- Runtime proof includes `cost-proof-per-ip-status`, `cost-proof-per-ip-limit`,
  `cost-proof-cache-hit-rate`, `cost-proof-cache-hit-target`,
  `cost-proof-cache-sample-count`, `cost-proof-call-reduction-rate`, and
  `cost-proof-status`.
- Optional docs continuity remains runnable through
  `PhaseThreeObjectiveTwoGateTest.php` in the non-blocking
  `ilas_site_assistant_docs` group.
- Scope boundaries remain unchanged: no net-new assistant channels or
  third-party model expansion beyond audited providers, and no platform-wide
  refactor of unrelated Drupal subsystems.

Store sanitized output in:
- `docs/aila/runtime/phase3-obj2-performance-cost-guardrails.txt`[^CLAIM-147]

### Phase 3 exit #2 cost/performance controls documented + monitored + owner acceptance verification (`P3-EXT-02`)

Use this command bundle to verify Phase 3 Exit criterion #2:
"Cost/performance controls are documented, monitored, and accepted by
product/platform owners."

```bash
# 1) Validation command aliases from prompt matrix.
# VC-PURE
vendor/bin/phpunit \
  -c /home/evancurry/idaho-legal-aid-services/phpunit.pure.xml \
  --filter 'CostControlPolicyTest|LlmControlConcurrencyTest|LlmEnhancerHardeningTest|AssistantApiControllerCostControlMetricsTest|PhaseThreeExitCriteriaTwoGateTest|PhaseThreeObjectiveTwoGateTest'

# VC-QUALITY-GATE
bash /home/evancurry/idaho-legal-aid-services/web/modules/custom/ilas_site_assistant/tests/run-quality-gate.sh

# VC-PANTHEON-READONLY
for ENV in dev test live; do
  terminus remote:drush "idaho-legal-aid-services.${ENV}" -- config:get ilas_site_assistant.settings cost_control -y
done

for ENV in dev test live; do
  terminus remote:drush "idaho-legal-aid-services.${ENV}" -- php:eval '$controller = \Drupal::service("class_resolver")->getInstanceFromDefinition(\Drupal\ilas_site_assistant\Controller\AssistantApiController::class); $response = $controller->metrics(); $data = json_decode($response->getContent(), true); echo json_encode(["has_metrics_cost_control" => isset($data["metrics"]["cost_control"]), "has_thresholds_cost_control" => isset($data["thresholds"]["cost_control"]), "metrics_cost_control" => $data["metrics"]["cost_control"] ?? null, "thresholds_cost_control" => $data["thresholds"]["cost_control"] ?? null], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;'
done

# 2) Monitoring checks (local + Pantheon continuity).
LOCAL_BASE_URL="https://ilas-pantheon.ddev.site"
# Anonymous negative path.
curl -k -sS "${LOCAL_BASE_URL}/assistant/api/health"
curl -k -sS "${LOCAL_BASE_URL}/assistant/api/metrics"

# Positive path when the runtime diagnostics secret is available to the caller.
curl -k -sS -H "X-ILAS-Observability-Key: ${ILAS_ASSISTANT_DIAGNOSTICS_TOKEN}" \
  "${LOCAL_BASE_URL}/assistant/api/health"
curl -k -sS -H "X-ILAS-Observability-Key: ${ILAS_ASSISTANT_DIAGNOSTICS_TOKEN}" \
  "${LOCAL_BASE_URL}/assistant/api/metrics"

for ENV in dev test live; do
  BASE_URL="$(terminus env:view "idaho-legal-aid-services.${ENV}" --print)"
  # Anonymous negative path.
  curl -k -sS "${BASE_URL%/}/assistant/api/health"
  curl -k -sS "${BASE_URL%/}/assistant/api/metrics"

  # Positive path with the environment-specific runtime secret.
  curl -k -sS -H "X-ILAS-Observability-Key: ${ILAS_ASSISTANT_DIAGNOSTICS_TOKEN}" \
    "${BASE_URL%/}/assistant/api/health"
  curl -k -sS -H "X-ILAS-Observability-Key: ${ILAS_ASSISTANT_DIAGNOSTICS_TOKEN}" \
    "${BASE_URL%/}/assistant/api/metrics"
done

# 3) Guardrail and owner-acceptance linkage continuity checks.
rg -n "class LlmEnhancer|circuit breaker|global rate limit|CostControlPolicy" \
  web/modules/custom/ilas_site_assistant/src/Service/LlmEnhancer.php \
  web/modules/custom/ilas_site_assistant/src/Service/CostControlPolicy.php

rg -n "metrics\\.cost_control|thresholds\\.cost_control|AssistantApiControllerCostControlMetricsTest.php|cost-proof-status=pass|metrics-cost-control=present|thresholds-cost-control=present" \
  web/modules/custom/ilas_site_assistant/src/Controller/AssistantApiController.php \
  web/modules/custom/ilas_site_assistant/tests/src/Unit/AssistantApiControllerCostControlMetricsTest.php \
  docs/aila/runtime/phase3-exit2-cost-performance-owner-acceptance.txt

rg -n "Phase 3 Exit #2 disposition \\(2026-03-06\\)|P3-EXT-02|phase3-exit2-cost-performance-owner-acceptance.txt|CLAIM-154|PhaseThreeExitCriteriaTwoGateTest.php" \
  docs/aila/roadmap.md \
  docs/aila/current-state.md \
  docs/aila/evidence-index.md \
  docs/aila/runbook.md

rg -n "IMP-COST-01 / P3-OBJ-02, 2026-03-05|P3-EXT-02|phase3-exit2-cost-performance-owner-acceptance.txt|owner-acceptance" \
  docs/aila/backlog.md \
  docs/aila/risk-register.md

# 4) Optional docs continuity checks (non-blocking).
cd /home/evancurry/idaho-legal-aid-services && \
  vendor/bin/phpunit --configuration phpunit.xml --group ilas_site_assistant_docs \
  web/modules/custom/ilas_site_assistant/tests/src/Unit/PhaseThreeExitCriteriaTwoGateTest.php \
  web/modules/custom/ilas_site_assistant/tests/src/Unit/PhaseThreeObjectiveTwoGateTest.php
```

Expected `P3-EXT-02` verification result:
- `VC-PURE` and `VC-QUALITY-GATE` confirm local behavioral proof and quality-gate continuity.
- `VC-PANTHEON-READONLY` confirms target-environment continuity on
  `dev`/`test`/`live`; the March 13, 2026 hosted verification showed
  `per_ip_hourly_call_limit=10`, `per_ip_window_seconds=3600`, and deployed
  `metrics.cost_control` / `thresholds.cost_control` continuity on all three
  environments.
- `/assistant/api/health` and `/assistant/api/metrics` monitoring checks return
  deterministic JSON payloads in local and Pantheon contexts: anonymous checks
  should remain controlled `access_denied` payloads, while operator-or-machine
  authenticated checks should return the operational health/metrics documents.
- Guardrail anchors remain present for `CLAIM-077` and `CLAIM-084` service paths,
  and `/assistant/api/metrics` exposes `metrics.cost_control` plus
  `thresholds.cost_control` in repo-local proof.
- Owner-acceptance continuity markers are present across roadmap/current-state/
  evidence/runbook/backlog/risk and runtime artifact references.
- Optional docs continuity remains runnable through
  `PhaseThreeExitCriteriaTwoGateTest.php` and
  `PhaseThreeObjectiveTwoGateTest.php` in the non-blocking
  `ilas_site_assistant_docs` group.
- Scope boundaries remain unchanged: no net-new assistant channels or
  third-party model expansion beyond audited providers, and no platform-wide
  refactor of unrelated Drupal subsystems. Residual `B-04` remains open.
- If `VC-PANTHEON-READONLY` later shows the deployed config missing
  `per_ip_hourly_call_limit` or `per_ip_window_seconds`, or hosted metrics
  continuity becomes unavailable, treat the finding as regressed until
  authenticated Pantheon continuity output is captured again.

Store sanitized output in:
- `docs/aila/runtime/phase3-exit2-cost-performance-owner-acceptance.txt`[^CLAIM-154]

### Phase 3 objective #3 release readiness package + governance attestation verification (`P3-OBJ-03`)

Use this command bundle to verify Phase 3 Objective #3:
"Deliver release readiness package and governance attestation."

```bash
# 1) Required prompt validation aliases.
# VC-UNIT
ddev exec vendor/bin/phpunit \
  --configuration /var/www/html/phpunit.xml \
  --group ilas_site_assistant \
  /var/www/html/web/modules/custom/ilas_site_assistant/tests/src/Unit

# VC-DRUPAL-UNIT
vendor/bin/phpunit \
  --configuration /home/evancurry/idaho-legal-aid-services/phpunit.xml \
  --testsuite drupal-unit

# 2) Objective continuity checks (roadmap/current-state/evidence).
rg -n "Phase 3 Objective #3 disposition \(2026-03-05\)|P3-OBJ-03|phase3-obj3-release-readiness-governance-attestation.txt|CLAIM-108|CLAIM-115|CLAIM-148|PhaseThreeObjectiveThreeGateTest.php" \
  docs/aila/roadmap.md

rg -n "Phase 3 Objective #3 Release Readiness \+ Governance Attestation Disposition \(2026-03-05\)|P3-OBJ-03|phase3-obj3-release-readiness-governance-attestation.txt|\[\^CLAIM-148\]" \
  docs/aila/current-state.md

rg -n "### CLAIM-108|### CLAIM-115|Addendum \(2026-03-05\): Phase 3 Objective #3|P3-OBJ-03|## Phase 3 Objective #3 Release Readiness Package \+ Governance Attestation Closure|### CLAIM-148|PhaseThreeObjectiveThreeGateTest.php" \
  docs/aila/evidence-index.md

# 3) Runtime readiness evidence anchor checks.
rg -n "Local Preflight|ddev version|docker info" \
  docs/aila/runtime/local-preflight.txt

rg -n "Pantheon Runtime Verification: idaho-legal-aid-services.dev|Drupal bootstrap : Successful|config:get ilas_site_assistant.settings -y" \
  docs/aila/runtime/pantheon-dev.txt

rg -n "Pantheon Runtime Verification: idaho-legal-aid-services.test|Drupal bootstrap : Successful|config:get ilas_site_assistant.settings -y" \
  docs/aila/runtime/pantheon-test.txt

rg -n "Pantheon Runtime Verification: idaho-legal-aid-services.live|Drupal bootstrap : Successful|config:get ilas_site_assistant.settings -y" \
  docs/aila/runtime/pantheon-live.txt

# 4) Governance attestation linkage checks (backlog/risk).
rg -n "Active mitigation \(IMP-GOV-01 / P3-OBJ-03, 2026-03-05\)|Active mitigation \(Retention/Access Attestation / P3-OBJ-03, 2026-03-05\)" \
  docs/aila/backlog.md

rg -n "R-GOV-01|phase3-obj3-release-readiness-governance-attestation.txt|Active mitigation" \
  docs/aila/risk-register.md

# 5) Diagram A continuity checks.
rg -n "flowchart LR|Drupal 11 / ilas_site_assistant|External Integrations|CI\\[External CI runner|PF\\[Promptfoo harness" \
  docs/aila/system-map.mmd

# 6) Optional docs continuity check (non-blocking).
ddev exec vendor/bin/phpunit \
  --configuration /var/www/html/phpunit.xml \
  --group ilas_site_assistant_docs \
  /var/www/html/web/modules/custom/ilas_site_assistant/tests/src/Unit/PhaseThreeObjectiveThreeGateTest.php
```

Expected `P3-OBJ-03` verification result:
- `VC-UNIT` and `VC-DRUPAL-UNIT` pass with behavioral release-readiness proof included.
- Roadmap/current-state/evidence-index continuity markers for `P3-OBJ-03`,
  `CLAIM-108`, `CLAIM-115`, and `CLAIM-148` are present.
- Local and Pantheon runtime readiness anchors remain present in
  `local-preflight.txt` and `pantheon-dev`/`test`/`live` artifacts.
- Governance attestation linkage is active in governance backlog rows and
  `R-GOV-01` risk posture with runtime proof continuity.
- Optional docs continuity remains runnable through
  `PhaseThreeObjectiveThreeGateTest.php` in the non-blocking
  `ilas_site_assistant_docs` group.
- Scope boundaries remain unchanged: no net-new assistant channels or
  third-party model expansion beyond audited providers, and no platform-wide
  refactor of unrelated Drupal subsystems.

Store sanitized output in:
- `docs/aila/runtime/phase3-obj3-release-readiness-governance-attestation.txt`[^CLAIM-148]

### Phase 1 Sprint 2 verification (`P1-SBD-01`)

Use this bundle to verify Sprint 2 closure scope:
"Sentry/Langfuse bootstrap, log schema normalization, initial SLO drafts."

```bash
# 1) Verify canonical log-context helper and controller usage.
rg -n "toLogContext|FIELD_INTENT|FIELD_SAFETY_CLASS|FIELD_FALLBACK_PATH|FIELD_REQUEST_ID|FIELD_ENV" \
  web/modules/custom/ilas_site_assistant/src/Service/TelemetrySchema.php \
  web/modules/custom/ilas_site_assistant/src/Controller/AssistantApiController.php

# 2) Run contract tests for telemetry schema + Sprint 2 doc/code gate.
ddev exec vendor/bin/phpunit \
  --configuration /var/www/html/phpunit.xml \
  /var/www/html/web/modules/custom/ilas_site_assistant/tests/src/Unit/TelemetrySchemaContractTest.php

ddev exec vendor/bin/phpunit \
  --configuration /var/www/html/phpunit.xml \
  /var/www/html/web/modules/custom/ilas_site_assistant/tests/src/Unit/PhaseOneSprintTwoGateTest.php

# 3) Validation command aliases used by implementation prompts.
# VC-UNIT
ddev exec vendor/bin/phpunit \
  --configuration /var/www/html/phpunit.xml \
  --group ilas_site_assistant \
  /var/www/html/web/modules/custom/ilas_site_assistant/tests/src/Unit

# VC-QUALITY-GATE
ddev exec bash /var/www/html/web/modules/custom/ilas_site_assistant/tests/run-quality-gate.sh
```

Expected Sprint 2 verification result:
- Canonical telemetry keys are normalized and present in critical log contexts
  (`intent`, `safety_class`, `fallback_path`, `request_id`, `env`) with legacy
  placeholders preserved for message-template stability.
- `TelemetrySchemaContractTest.php` and `PhaseOneSprintTwoGateTest.php` pass.
- `VC-UNIT` and `VC-QUALITY-GATE` pass.
- Scope boundaries remain enforced (`llm.enabled=false` through Phase 2; no full
  retrieval-architecture redesign).

### Phase 1 Sprint 3 verification (`P1-SBD-02`)

Use this bundle to verify Sprint 3 closure scope:
"Alert policy finalization, CI gate rollout, reliability failure matrix completion."

```bash
# VC-UNIT
ddev exec vendor/bin/phpunit \
  --configuration /var/www/html/phpunit.xml \
  --group ilas_site_assistant \
  /var/www/html/web/modules/custom/ilas_site_assistant/tests/src/Unit

# VC-QUALITY-GATE
ddev exec bash /var/www/html/web/modules/custom/ilas_site_assistant/tests/run-quality-gate.sh
```

Expected Sprint 3 verification result:
- `VC-UNIT` and `VC-QUALITY-GATE` pass with Sprint 3 gate coverage included.
- Runtime evidence summary is captured in
  `docs/aila/runtime/phase1-sprint3-closure.txt`.
- Linked closure artifacts remain present:
  `docs/aila/runtime/phase1-exit1-alerts-dashboards.txt` and
  `docs/aila/runtime/phase1-exit3-reliability-failure-matrix.txt`.
- Scope boundaries remain enforced (`llm.enabled=false` through Phase 2; no full
  retrieval-architecture redesign).

### Phase 2 entry #1 observability + CI baseline operational verification (`P2-ENT-01`)

Use this command bundle to verify Phase 2 Entry criterion #1:
"Observability + CI baselines are operational from Phase 1."

```bash
# 1) Required prompt validation aliases.
# VC-RUNBOOK-LOCAL
cd /home/evancurry/idaho-legal-aid-services && \
  ddev drush status && \
  ddev drush config:get ilas_site_assistant.settings -y && \
  ddev drush state:get system.cron_last

# VC-TOGGLE-CHECK
cd /home/evancurry/idaho-legal-aid-services && \
  rg -n "llm.enabled|vector_search|rate_limit_per_minute|conversation_logging" \
    docs/aila/current-state.md docs/aila/evidence-index.md

# 2) CI baseline continuity checks (repo-local).
rg -n "name: Quality Gate|release/\\*\\*|cancel-in-progress|name: PHPUnit Quality Gate|name: Promptfoo Gate" \
  .github/workflows/quality-gate.yml

rg -n "run-external-quality-gate.sh|run-promptfoo-gate.sh|derive-assistant-url.sh" \
  scripts/ci

# 3) Diagram A anchor continuity checks for observability + CI path.
rg -n "OBS\\[Observability|CI\\[External CI runner|PF\\[Promptfoo harness|CI -->\\|drives scripted quality gates\\| PF" \
  docs/aila/system-map.mmd
```

Expected P2-ENT-01 verification result:
- `VC-RUNBOOK-LOCAL` returns successful local runtime status, effective assistant
  settings visibility, and a concrete `system.cron_last` state value.
- `VC-TOGGLE-CHECK` confirms documented toggle continuity in current-state and
  evidence-index for `llm.enabled`, `vector_search`, flood limits, and
  `conversation_logging`.
- CI baseline anchors remain present: first-party workflow triggers and
  concurrency controls are intact, and repo gate scripts remain in canonical
  `scripts/ci/*` paths.
- Diagram A still documents observability + CI flow anchors for this entry
  criterion.
- Scope boundaries remain unchanged: no live LLM enablement through Phase 2 and
  no broad platform migration outside the current Pantheon baseline.

Store sanitized output in:
- `docs/aila/runtime/phase2-entry1-observability-ci-baseline.txt`[^CLAIM-138]

### Phase 2 entry #2 config parity + retrieval tuning stability verification (`P2-ENT-02`)

Use this command bundle to verify Phase 2 Entry criterion #2: "Config parity and
retrieval tuning controls are stable across environments."

Local (DDEV):

```bash
# VC-RUNBOOK-LOCAL
ddev drush status --fields=drupal-version,db-status,bootstrap && \
ddev drush config:get ilas_site_assistant.settings && \
ddev drush state:get system.cron_last
```

Config parity verification:

```bash
# VC-TOGGLE-CHECK
cd /home/evancurry/idaho-legal-aid-services && \
  rg -n "llm.enabled|vector_search|rate_limit_per_minute|conversation_logging" \
    docs/aila/current-state.md docs/aila/evidence-index.md

# 1) Config parity contract test anchor checks.
ls web/modules/custom/ilas_site_assistant/tests/src/Unit/VectorSearchConfigSchemaTest.php \
   web/modules/custom/ilas_site_assistant/tests/src/Unit/ConfigCompletenessDriftTest.php

# 2) Schema coverage anchor checks for vector_search and fallback_gate.
rg -n "vector_search|fallback_gate" \
  web/modules/custom/ilas_site_assistant/config/schema/ilas_site_assistant.schema.yml
```

Expected P2-ENT-02 verification result:
- `VC-RUNBOOK-LOCAL` returns successful local runtime status, effective assistant
  settings visibility including `vector_search` and `fallback_gate.thresholds` blocks,
  and a concrete `system.cron_last` state value.
- `VC-TOGGLE-CHECK` confirms documented toggle continuity in current-state and
  evidence-index for `llm.enabled`, `vector_search`, flood limits, and
  `conversation_logging`.
- Config parity contract tests exist: `VectorSearchConfigSchemaTest` (4 tests) enforces
  schema coverage for `vector_search`; `ConfigCompletenessDriftTest` (5 tests) enforces
  three-way parity (install defaults / active config export / schema).
- Schema covers both `vector_search` (7 keys) and `fallback_gate.thresholds` (12 keys).
- Scope boundaries remain unchanged: no live LLM enablement through Phase 2 and
  no broad platform migration outside the current Pantheon baseline.

Store sanitized output in:
- `docs/aila/runtime/phase2-entry2-config-parity-retrieval-tuning.txt`[^CLAIM-139]

### Phase 2 exit #3 live LLM disabled pending Phase 3 readiness review verification (`P2-EXT-03`)

Use this command bundle to verify Phase 2 exit criterion #3:
"Live LLM remains disabled pending Phase 3 readiness review."

```bash
# 1) Validation command aliases from prompt matrix.
# VC-RUNBOOK-LOCAL
cd /home/evancurry/idaho-legal-aid-services && \
  ddev drush status && \
  ddev drush config:get ilas_site_assistant.settings -y && \
  ddev drush state:get system.cron_last

# VC-RUNBOOK-PANTHEON
for ENV in dev test live; do
  terminus remote:drush "idaho-legal-aid-services.${ENV}" -- config:get ilas_site_assistant.settings -y
done

# 2) Closure-focused continuity checks.
for ENV in dev test live; do
  echo "=== ${ENV} ==="
  terminus remote:drush "idaho-legal-aid-services.${ENV}" -- config:get ilas_site_assistant.settings llm.enabled
  terminus remote:drush "idaho-legal-aid-services.${ENV}" -- config:get ilas_site_assistant.settings vector_search.enabled
  terminus remote:drush "idaho-legal-aid-services.${ENV}" -- config:get ilas_site_assistant.settings rate_limit_per_minute
done

# 3) Runtime guard anchor checks.
rg -n "PANTHEON_ENVIRONMENT.*live|llm\\.enabled.*FALSE" \
  web/sites/default/settings.php

rg -n "isLiveEnvironment|LLM enhancement cannot be enabled in the live environment through Phase 2.|llm_enabled = FALSE" \
  web/modules/custom/ilas_site_assistant/src/Form/AssistantSettingsForm.php

rg -n "isLiveEnvironment|llm\\.enabled" \
  web/modules/custom/ilas_site_assistant/src/Service/LlmEnhancer.php

rg -n "isLiveEnvironment|isLlmEffectivelyEnabled|llm\\.enabled" \
  web/modules/custom/ilas_site_assistant/src/Service/FallbackGate.php

# 4) Targeted guard/closure tests.
ddev exec vendor/bin/phpunit --configuration /var/www/html/phpunit.xml \
  /var/www/html/web/modules/custom/ilas_site_assistant/tests/src/Unit/PhaseTwoExitCriteriaThreeGateTest.php

ddev exec vendor/bin/phpunit --configuration /var/www/html/phpunit.xml \
  /var/www/html/web/modules/custom/ilas_site_assistant/tests/src/Unit/LlmEnhancerHardeningTest.php

ddev exec vendor/bin/phpunit --configuration /var/www/html/phpunit.xml \
  /var/www/html/web/modules/custom/ilas_site_assistant/tests/src/Unit/FallbackGateTest.php
```

Expected `P2-EXT-03` verification result:
- `VC-RUNBOOK-LOCAL` confirms local runtime visibility with `system.cron_last`
  value and `llm.enabled=false` continuity.
- `VC-RUNBOOK-PANTHEON` confirms `llm.enabled=false` continuity on
  `dev`/`test`/`live`.
- Runtime guard anchors remain present in `settings.php`,
  `AssistantSettingsForm`, `LlmEnhancer`, and `FallbackGate`.
- `PhaseTwoExitCriteriaThreeGateTest.php`, `LlmEnhancerHardeningTest.php`, and
  `FallbackGateTest.php` pass with live hard-disable expectations enforced.
- Scope boundaries remain unchanged: no live LLM enablement through Phase 2 and
  no broad platform migration outside the current Pantheon baseline.

Hard gate policy:
- If `VC-RUNBOOK-PANTHEON` fails (auth/connectivity/command failure),
  P2-EXT-03 is not closed.

Store sanitized output in:
- `docs/aila/runtime/phase2-exit3-live-llm-disabled-phase3-readiness.txt`[^CLAIM-142]

### Phase 2 NDO #1 no live production LLM enablement verification (`P2-NDO-01`)

Use this command bundle to verify Phase 2 "What we will NOT do #1":
"No live production LLM enablement in this phase."

```bash
# VC-TOGGLE-CHECK
cd /home/evancurry/idaho-legal-aid-services && \
  rg -n "llm.enabled|vector_search|rate_limit_per_minute|conversation_logging" \
    docs/aila/current-state.md docs/aila/evidence-index.md

# Runtime guard anchor checks
rg -n "llm.enabled.*FALSE" web/sites/default/settings.php
rg -n "cannot be enabled in the live environment" \
  web/modules/custom/ilas_site_assistant/src/Form/AssistantSettingsForm.php
rg -n "isLiveEnvironment" \
  web/modules/custom/ilas_site_assistant/src/Service/LlmEnhancer.php \
  web/modules/custom/ilas_site_assistant/src/Service/FallbackGate.php
rg -n "isLlmEffectivelyEnabled" \
  web/modules/custom/ilas_site_assistant/src/Service/FallbackGate.php

# Guard test
ddev exec vendor/bin/phpunit \
  web/modules/custom/ilas_site_assistant/tests/src/Unit/PhaseTwoNoLiveLlmProductionEnablementGuardTest.php \
  --group=ilas_site_assistant
```

Expected `P2-NDO-01` verification result:
- `VC-TOGGLE-CHECK` confirms `llm.enabled=false` continuity across
  current-state and evidence-index documentation.
- Runtime guard anchors remain present in `settings.php`,
  `AssistantSettingsForm`, `LlmEnhancer`, and `FallbackGate`.
- `PhaseTwoNoLiveLlmProductionEnablementGuardTest.php` passes with all
  assertion groups enforced: roadmap disposition, current-state addendum,
  evidence-index CLAIM-145, runbook verification bundle, runtime artifact
  proof markers, runtime guard anchors, and `VC-TOGGLE-CHECK` alias.
- Scope boundaries remain unchanged: no live LLM enablement through Phase 2 and
  no broad platform migration outside the current Pantheon baseline.

Store sanitized output in:
- `docs/aila/runtime/phase2-ndo1-no-live-llm-production-enablement.txt`[^CLAIM-145]

### Phase 2 NDO #2 no broad platform migration verification (`P2-NDO-02`)

Use this command bundle to verify Phase 2 "What we will NOT do #2":
"No broad platform migration outside current Pantheon baseline."

```bash
# VC-TOGGLE-CHECK
cd /home/evancurry/idaho-legal-aid-services && \
  rg -n "llm.enabled|vector_search|rate_limit_per_minute|conversation_logging" \
    docs/aila/current-state.md docs/aila/evidence-index.md

# Boundary continuity checks
rg -n "Phase 2 NDO #2 disposition \(2026-03-05\)|No broad platform migration outside current Pantheon baseline|CLAIM-146|PhaseTwoNoBroadPlatformMigrationGuardTest.php|phase2-ndo2-no-broad-platform-migration.txt" \
  docs/aila/roadmap.md

rg -n "Phase 2 NDO #2 No Broad Platform Migration Disposition \(2026-03-05\)|P2-NDO-02|phase2-ndo2-no-broad-platform-migration.txt|\[\^CLAIM-146\]" \
  docs/aila/current-state.md

rg -n "## Phase 2 NDO #2 No Broad Platform Migration Boundary \(`P2-NDO-02`\)|### CLAIM-146|CLAIM-115|CLAIM-119|PhaseTwoNoBroadPlatformMigrationGuardTest.php" \
  docs/aila/evidence-index.md

# Pantheon baseline anchor checks
rg -n "api_version: 1" pantheon.yml pantheon.upstream.yml
rg -n "web_docroot: true|php_version: 8.3|database:|version: 10.6|build_step: true|protected_web_paths:" \
  pantheon.upstream.yml
rg -n "include __DIR__ . \"/settings.pantheon.php\";|PANTHEON_ENVIRONMENT|llm\\.enabled.*FALSE" \
  web/sites/default/settings.php

# Diagram A continuity checks
rg -n "flowchart LR|Drupal 11 / ilas_site_assistant|External Integrations|CI\\[External CI runner|PF\\[Promptfoo harness" \
  docs/aila/system-map.mmd

# Guard test
ddev exec vendor/bin/phpunit \
  web/modules/custom/ilas_site_assistant/tests/src/Unit/PhaseTwoNoBroadPlatformMigrationGuardTest.php \
  --group=ilas_site_assistant
```

Expected `P2-NDO-02` verification result:
- `VC-TOGGLE-CHECK` succeeds with documented toggle continuity anchors present.
- Roadmap/current-state/evidence index continuity markers for `P2-NDO-02` and
  `CLAIM-146` are present.
- Pantheon baseline anchors remain present in `pantheon.yml`,
  `pantheon.upstream.yml`, and `web/sites/default/settings.php`.
- Diagram A continuity anchors remain unchanged and present in
  `docs/aila/system-map.mmd`.
- `PhaseTwoNoBroadPlatformMigrationGuardTest.php` passes with assertion groups
  enforced for docs, runtime artifact markers, baseline anchors, and alias
  continuity.
- Scope boundaries remain unchanged: no live LLM enablement through Phase 2 and
  no broad platform migration outside the current Pantheon baseline.

Store sanitized output in:
- `docs/aila/runtime/phase2-ndo2-no-broad-platform-migration.txt`[^CLAIM-146]

### Phase 3 NDO #1 no net-new assistant channels or third-party model expansion verification (`P3-NDO-01`)

Use this command bundle to verify Phase 3 "What we will NOT do #1":
"No net-new assistant channels or third-party model expansion beyond audited providers."

```bash
# VC-TOGGLE-CHECK
cd /home/evancurry/idaho-legal-aid-services && \
  rg -n "llm.enabled|vector_search|rate_limit_per_minute|conversation_logging" \
    docs/aila/current-state.md docs/aila/evidence-index.md

# Boundary continuity checks
rg -n "Phase 3 NDO #1 disposition \(2026-03-06\)|No net-new assistant channels or third-party model expansion beyond audited providers|CLAIM-158|PhaseThreeNoNetNewAssistantChannelsOrModelExpansionGuardTest.php|phase3-ndo1-no-net-new-assistant-channels-or-third-party-model-expansion.txt" \
  docs/aila/roadmap.md

rg -n "Phase 3 NDO #1 No Net-New Assistant Channels \+ No Third-Party Model Expansion Disposition \(2026-03-06\)|P3-NDO-01|phase3-ndo1-no-net-new-assistant-channels-or-third-party-model-expansion.txt|\[\^CLAIM-158\]" \
  docs/aila/current-state.md

rg -n "## Phase 3 NDO #1 No Net-New Assistant Channels \+ No Third-Party Model Expansion Boundary \(`P3-NDO-01`\)|### CLAIM-158|Addendum \(2026-03-06\): Phase 3 NDO #1 \(`P3-NDO-01`\)|PhaseThreeNoNetNewAssistantChannelsOrModelExpansionGuardTest.php" \
  docs/aila/evidence-index.md

# Assistant channel continuity anchors
rg -n "/assistant'|/assistant/api/message|/assistant/api/session/bootstrap|/assistant/api/suggest|/assistant/api/faq|/assistant/api/health|/assistant/api/metrics|/assistant/api/track" \
  web/modules/custom/ilas_site_assistant/ilas_site_assistant.routing.yml

rg -n "flowchart LR|Drupal 11 / ilas_site_assistant|External Integrations|CI\\[External CI runner|PF\\[Promptfoo harness" \
  docs/aila/system-map.mmd

# Audited-provider allowlist continuity anchors
rg -n "GEMINI_API_ENDPOINT|VERTEX_AI_ENDPOINT|provider === 'gemini_api'|provider === 'vertex_ai'|x-goog-api-key|Authorization' => 'Bearer '" \
  web/modules/custom/ilas_site_assistant/src/Service/LlmEnhancer.php

rg -n "'gemini_api' =>| 'vertex_ai' =>|llm_provider" \
  web/modules/custom/ilas_site_assistant/src/Form/AssistantSettingsForm.php

rg -n "LLM provider \\(gemini_api or vertex_ai\\)" \
  web/modules/custom/ilas_site_assistant/config/schema/ilas_site_assistant.schema.yml

rg -n "provider: 'gemini_api'.*gemini_api.*vertex_ai" \
  web/modules/custom/ilas_site_assistant/config/install/ilas_site_assistant.settings.yml

# Guard test
ddev exec vendor/bin/phpunit \
  --configuration /var/www/html/phpunit.xml \
  --group ilas_site_assistant \
  /var/www/html/web/modules/custom/ilas_site_assistant/tests/src/Unit/PhaseThreeNoNetNewAssistantChannelsOrModelExpansionGuardTest.php
```

Expected `P3-NDO-01` verification result:
- `VC-TOGGLE-CHECK` succeeds with documented toggle continuity anchors present.
- Roadmap/current-state/evidence continuity markers for `P3-NDO-01` and
  `CLAIM-158` are present.
- Assistant channel anchors remain unchanged across route inventory and Diagram A
  continuity context.
- Audited-provider allowlist anchors remain limited to Gemini API and Vertex AI
  across service dispatch/auth flow, admin provider options, schema, and install
  defaults.
- `PhaseThreeNoNetNewAssistantChannelsOrModelExpansionGuardTest.php` passes with
  docs/runtime/source continuity assertions enforced.
- Scope boundaries remain unchanged: no net-new assistant channels or
  third-party model expansion beyond audited providers, and no platform-wide
  refactor of unrelated Drupal subsystems.

Store sanitized output in:
- `docs/aila/runtime/phase3-ndo1-no-net-new-assistant-channels-or-third-party-model-expansion.txt`[^CLAIM-158]

### Phase 3 entry #1 retrieval quality targets met + documented verification (P3-ENT-01)

Use this command bundle to verify Phase 3 Entry criterion #1: all Phase 2
retrieval quality targets are met and documented.

```bash
# VC-RUNBOOK-LOCAL
cd /home/evancurry/idaho-legal-aid-services && \
  ddev drush status && \
  ddev drush config:get ilas_site_assistant.settings -y && \
  ddev drush state:get system.cron_last

# VC-TOGGLE-CHECK
cd /home/evancurry/idaho-legal-aid-services && \
  rg -n "llm.enabled|vector_search|rate_limit_per_minute|conversation_logging" \
    docs/aila/current-state.md docs/aila/evidence-index.md

# Phase 2 retrieval quality closure continuity checks
rg -n "Phase 2 Objective #2 disposition|Phase 2 Objective #3 disposition" \
  docs/aila/roadmap.md

rg -n "Phase 2 Deliverable #1 disposition|Phase 2 Deliverable #2 disposition|Phase 2 Deliverable #3 disposition|Phase 2 Deliverable #4 disposition" \
  docs/aila/roadmap.md

rg -n "Phase 2 Exit #1 disposition|Phase 2 Exit #2 disposition" \
  docs/aila/roadmap.md

rg -n "Phase 2 Sprint 4 disposition|Phase 2 Sprint 5 disposition" \
  docs/aila/roadmap.md

# Retrieval evidence anchor continuity
rg -n "CLAIM-065|CLAIM-086" \
  docs/aila/evidence-index.md | head -20

# Diagram B pipeline retrieval anchors
rg -n "Early retrieval|Fallback gate decision|flowchart TD" \
  docs/aila/system-map.mmd

# Optional docs continuity check (non-blocking)
ddev exec vendor/bin/phpunit \
  --configuration /var/www/html/phpunit.xml \
  --group ilas_site_assistant_docs \
  /var/www/html/web/modules/custom/ilas_site_assistant/tests/src/Unit/PhaseThreeEntryCriteriaOneGateTest.php
```

Expected `P3-ENT-01` verification result:
- `VC-RUNBOOK-LOCAL` returns Drupal bootstrap status, assistant settings, and
  `system.cron_last=<timestamp>`.
- `VC-TOGGLE-CHECK` confirms toggle references are present in docs.
- All Phase 2 retrieval quality dispositions are present in roadmap: Objective #2
  (2026-03-03), Objective #3 (2026-03-03), Deliverable #1 (2026-03-03),
  Deliverable #2 (2026-03-03), Deliverable #3 (2026-03-04), Deliverable #4
  (2026-03-04), Exit #1 (2026-03-04), Exit #2 (2026-03-04), Sprint 4
  (2026-03-05), Sprint 5 (2026-03-05).
- CLAIM-065 and CLAIM-086 evidence anchors remain present.
- Diagram B retrieval pipeline anchors (Early retrieval, Fallback gate decision)
  remain present.
- Optional docs continuity remains runnable through
  `PhaseThreeEntryCriteriaOneGateTest.php` in the non-blocking
  `ilas_site_assistant_docs` group.
- Scope boundaries remain unchanged: no net-new assistant channels or third-party
  model expansion beyond audited providers, and no platform-wide refactor of
  unrelated Drupal subsystems.

Store sanitized output in:
- `docs/aila/runtime/phase3-entry1-retrieval-quality-targets.txt`[^CLAIM-151]

### Phase 3 entry #2 SLO/alert operational trend history verification (P3-ENT-02)

Use this command bundle to verify Phase 3 Entry criterion #2:
"SLO/alert operational data has at least one sprint of trend history."
Sprint definition for this closure is locked to `10 business days`.

```bash
# VC-RUNBOOK-LOCAL
cd /home/evancurry/idaho-legal-aid-services && \
  ddev drush status && \
  ddev drush config:get ilas_site_assistant.settings -y && \
  ddev drush state:get system.cron_last

# VC-TOGGLE-CHECK
cd /home/evancurry/idaho-legal-aid-services && \
  rg -n "llm.enabled|vector_search|rate_limit_per_minute|conversation_logging" \
    docs/aila/current-state.md docs/aila/evidence-index.md

# Local watchdog trend min/max + span hours/days.
cd /home/evancurry/idaho-legal-aid-services && \
  ddev drush sqlq "SELECT FROM_UNIXTIME(MIN(timestamp)) AS trend_start_ts, FROM_UNIXTIME(MAX(timestamp)) AS trend_end_ts, TIMESTAMPDIFF(HOUR, FROM_UNIXTIME(MIN(timestamp)), FROM_UNIXTIME(MAX(timestamp))) AS span_hours, TIMESTAMPDIFF(DAY, DATE(FROM_UNIXTIME(MIN(timestamp))), DATE(FROM_UNIXTIME(MAX(timestamp)))) + 1 AS span_calendar_days, COUNT(*) AS cron_rows FROM watchdog WHERE type = 'cron' AND message = 'Cron run completed.';"

# Local watchdog calendar/business-day trend window (recursive CTE).
cd /home/evancurry/idaho-legal-aid-services && \
  ddev drush sqlq "WITH RECURSIVE bounds AS (SELECT DATE(FROM_UNIXTIME(MIN(timestamp))) AS start_day, DATE(FROM_UNIXTIME(MAX(timestamp))) AS end_day FROM watchdog WHERE type = 'cron' AND message = 'Cron run completed.'), calendar AS (SELECT start_day AS day, end_day FROM bounds UNION ALL SELECT DATE_ADD(day, INTERVAL 1 DAY), end_day FROM calendar WHERE day < end_day) SELECT MIN(day) AS trend_window_start, MAX(day) AS trend_window_end, COUNT(*) AS trend_calendar_days, SUM(CASE WHEN DAYOFWEEK(day) BETWEEN 2 AND 6 THEN 1 ELSE 0 END) AS trend_business_days FROM calendar;"

# Local watchdog SLO-violation count across trend window.
cd /home/evancurry/idaho-legal-aid-services && \
  ddev drush sqlq "WITH bounds AS (SELECT MIN(timestamp) AS min_ts, MAX(timestamp) AS max_ts FROM watchdog WHERE type = 'cron' AND message = 'Cron run completed.') SELECT COUNT(*) AS slo_violation_count, FROM_UNIXTIME(MIN(w.timestamp)) AS first_slo_violation, FROM_UNIXTIME(MAX(w.timestamp)) AS last_slo_violation FROM watchdog w CROSS JOIN bounds b WHERE w.message LIKE 'SLO violation:%' AND w.timestamp BETWEEN b.min_ts AND b.max_ts;"

# CLAIM-084 continuity anchors (SLO/performance monitor bundle evidence).
cd /home/evancurry/idaho-legal-aid-services && \
  rg -n "VC-RUNBOOK-LOCAL|system\\.cron_last|guard-anchor-performance-monitor|guard-anchor-slo-alert-service" \
    docs/aila/runtime/phase2-entry1-observability-ci-baseline.txt \
    docs/aila/runtime/phase3-obj2-performance-cost-guardrails.txt

# CLAIM-121 continuity anchors (cron watchdog trend evidence paths).
cd /home/evancurry/idaho-legal-aid-services && \
  rg -n "Cron run completed|ilas_site_assistant_cron\\(\\)|SLO violation:|system\\.cron_last" \
    docs/aila/runtime/phase1-exit1-alerts-dashboards.txt \
    docs/aila/runtime/pantheon-dev.txt \
    docs/aila/runtime/pantheon-test.txt \
    docs/aila/runtime/pantheon-live.txt | head -40

# Closure guard test
ddev exec vendor/bin/phpunit \
  --configuration /var/www/html/phpunit.xml \
  --group ilas_site_assistant \
  /var/www/html/web/modules/custom/ilas_site_assistant/tests/src/Unit/PhaseThreeEntryCriteriaTwoGateTest.php
```

Expected `P3-ENT-02` verification result:
- `VC-RUNBOOK-LOCAL` returns successful local runtime/config visibility with
  `system.cron_last=<timestamp>`.
- `VC-TOGGLE-CHECK` confirms canonical toggle references remain present in
  current-state and evidence-index.
- Trend-window queries return one sprint of local operational history using the
  locked definition `10 business days`, with explicit window dates
  2026-02-20 through 2026-03-05 (14 calendar days / 10 business days).
- SLO violation count query returns non-zero in-window evidence (`>=1`) for
  operational trend visibility without synthetic/backfilled data.
- CLAIM-084 and CLAIM-121 continuity anchors remain present in existing runtime
  evidence files.
- `PhaseThreeEntryCriteriaTwoGateTest.php` passes with all continuity assertion
  groups enforced.
- Entry criterion closure scope remains bounded: no net-new assistant channels
  or third-party model expansion beyond audited providers, no platform-wide
  refactor of unrelated Drupal subsystems, and residual `B-04` remains open.

Store sanitized output in:
- `docs/aila/runtime/phase3-entry2-slo-alert-trend-history.txt`[^CLAIM-152]

### Phase 3 exit #1 UX/a11y test suite gating + passing verification (`P3-EXT-01`)

Use this command bundle to verify Phase 3 Exit criterion #1:
"UX/a11y test suite is gating and passing."

```bash
# VC-RUNBOOK-LOCAL
cd /home/evancurry/idaho-legal-aid-services && \
  ddev drush status && \
  ddev drush config:get ilas_site_assistant.settings -y && \
  ddev drush state:get system.cron_last

# VC-RUNBOOK-PANTHEON
for ENV in dev test live; do
  terminus remote:drush "idaho-legal-aid-services.${ENV}" -- config:get ilas_site_assistant.settings -y
done

# Targeted UX/a11y JS suite runner (CI gate parity).
cd /home/evancurry/idaho-legal-aid-services && \
  node web/modules/custom/ilas_site_assistant/tests/js/run-assistant-widget-hardening.mjs

# Targeted guard tests for workflow + closure continuity.
cd /home/evancurry/idaho-legal-aid-services && \
  vendor/bin/phpunit --configuration phpunit.xml --group ilas_site_assistant \
  web/modules/custom/ilas_site_assistant/tests/src/Unit/QualityGateEnforcementContractTest.php \
  web/modules/custom/ilas_site_assistant/tests/src/Unit/PhaseThreeExitCriteriaOneGateTest.php

# CI wiring anchor checks.
cd /home/evancurry/idaho-legal-aid-services && \
  rg -n "Run UX/a11y widget hardening suite \\(P3-EXT-01\\)|run-assistant-widget-hardening\\.mjs|promptfoo-gate" \
    .github/workflows/quality-gate.yml \
    web/modules/custom/ilas_site_assistant/tests/src/Unit/QualityGateEnforcementContractTest.php \
    web/modules/custom/ilas_site_assistant/tests/src/Unit/PhaseThreeExitCriteriaOneGateTest.php
```

Expected `P3-EXT-01` verification result:
- `VC-RUNBOOK-LOCAL` confirms local runtime visibility with `system.cron_last`
  continuity and assistant config visibility.
- `VC-RUNBOOK-PANTHEON` confirms target-environment continuity on `dev`/`test`/`live`.
- JS suite runner reports `assistant-widget-hardening: pass=<N> fail=0`.
- `QualityGateEnforcementContractTest.php` and `PhaseThreeExitCriteriaOneGateTest.php`
  pass with CI/document/runtime continuity assertions enforced.
- CI anchor checks confirm required `Promptfoo Gate` wiring includes
  `run-assistant-widget-hardening.mjs`.
- Scope boundaries remain unchanged: no net-new assistant channels or
  third-party model expansion beyond audited providers, and no platform-wide
  refactor of unrelated Drupal subsystems.
- If `VC-RUNBOOK-PANTHEON` fails (auth/connectivity/command failure),
  treat `P3-EXT-01` closure as blocked until authenticated Pantheon
  continuity output is captured.

Store sanitized output in:
- `docs/aila/runtime/phase3-exit1-ux-a11y-gating.txt`[^CLAIM-153]

### Phase 3 Sprint 6 Week 1 UX/a11y + mobile hardening verification (`P3-SBD-01`)

Use this command bundle to verify Sprint 6 Week 1 closure scope:
"Sprint 6 Week 1: UX/a11y and mobile hardening."

```bash
# 1) Required validation aliases from prompt matrix.
# VC-UNIT
ddev exec vendor/bin/phpunit \
  --configuration /var/www/html/phpunit.xml \
  --group ilas_site_assistant \
  /var/www/html/web/modules/custom/ilas_site_assistant/tests/src/Unit

# VC-QUALITY-GATE
ddev exec bash /var/www/html/web/modules/custom/ilas_site_assistant/tests/run-quality-gate.sh

# 2) Sprint closure continuity anchors (docs + tests + runtime artifact).
cd /home/evancurry/idaho-legal-aid-services && \
  rg -n "Phase 3 Sprint 6 Week 1 disposition \\(2026-03-06\\)|P3-SBD-01|phase3-sprint6-week1-ux-a11y-mobile-hardening\\.txt|PhaseThreeSprintSixWeekOneGateTest\\.php" \
    docs/aila/roadmap.md \
    docs/aila/current-state.md \
    docs/aila/evidence-index.md \
    docs/aila/runbook.md \
    web/modules/custom/ilas_site_assistant/tests/src/Unit/PhaseThreeSprintSixWeekOneGateTest.php

cd /home/evancurry/idaho-legal-aid-services && \
  rg -n "AccessibilityMobileUxAcceptanceGateTest|RecoveryUxContractTest|assistant-widget-hardening\\.test\\.js|run-assistant-widget-hardening\\.mjs" \
    docs/aila/roadmap.md \
    docs/aila/current-state.md \
    docs/aila/evidence-index.md \
    web/modules/custom/ilas_site_assistant/tests/src/Unit/PhaseThreeSprintSixWeekOneGateTest.php

# 3) Sprint closure guard test.
ddev exec vendor/bin/phpunit \
  --configuration /var/www/html/phpunit.xml \
  --group ilas_site_assistant \
  /var/www/html/web/modules/custom/ilas_site_assistant/tests/src/Unit/PhaseThreeSprintSixWeekOneGateTest.php
```

Expected `P3-SBD-01` verification result:
- `VC-UNIT` and `VC-QUALITY-GATE` pass with Sprint 6 Week 1 closure coverage
  included.
- Continuity anchors are present across roadmap/current-state/runbook/evidence
  for `P3-SBD-01`, `CLAIM-149`, `CLAIM-153`, and `CLAIM-156`.
- `PhaseThreeSprintSixWeekOneGateTest.php` passes with
  roadmap/current-state/runbook/evidence/runtime/system-map continuity checks.
- Runtime proof markers in
  `docs/aila/runtime/phase3-sprint6-week1-ux-a11y-mobile-hardening.txt` record
  closed status and scope-boundary continuity.
- Scope boundaries remain unchanged: no net-new assistant channels or
  third-party model expansion beyond audited providers, and no platform-wide
  refactor of unrelated Drupal subsystems.

Store sanitized output in:
- `docs/aila/runtime/phase3-sprint6-week1-ux-a11y-mobile-hardening.txt`[^CLAIM-156]

### Phase 3 Sprint 6 Week 2 performance/cost guardrails + governance signoff verification (`P3-SBD-02`)

Use this command bundle to verify Sprint 6 Week 2 closure scope:
"Sprint 6 Week 2: performance/cost guardrails and governance signoff."

```bash
# 1) Required validation aliases from prompt matrix.
# VC-UNIT
ddev exec vendor/bin/phpunit \
  --configuration /var/www/html/phpunit.xml \
  --group ilas_site_assistant \
  /var/www/html/web/modules/custom/ilas_site_assistant/tests/src/Unit

# VC-QUALITY-GATE
ddev exec bash /var/www/html/web/modules/custom/ilas_site_assistant/tests/run-quality-gate.sh

# 2) Sprint closure continuity anchors (docs + tests + runtime artifact).
cd /home/evancurry/idaho-legal-aid-services && \
  rg -n "Phase 3 Sprint 6 Week 2 disposition \\(2026-03-06\\)|P3-SBD-02|phase3-sprint6-week2-performance-cost-governance-signoff\\.txt|PhaseThreeSprintSixWeekTwoGateTest\\.php" \
    docs/aila/roadmap.md \
    docs/aila/current-state.md \
    docs/aila/evidence-index.md \
    docs/aila/runbook.md \
    web/modules/custom/ilas_site_assistant/tests/src/Unit/PhaseThreeSprintSixWeekTwoGateTest.php

cd /home/evancurry/idaho-legal-aid-services && \
  rg -n "P3-OBJ-02|P3-OBJ-03|P3-EXT-02|P3-EXT-03|PhaseThreeObjectiveTwoGateTest|PhaseThreeObjectiveThreeGateTest|PhaseThreeExitCriteriaTwoGateTest|PhaseThreeExitCriteriaThreeGateTest" \
    docs/aila/roadmap.md \
    docs/aila/current-state.md \
    docs/aila/evidence-index.md \
    web/modules/custom/ilas_site_assistant/tests/src/Unit/PhaseThreeSprintSixWeekTwoGateTest.php

# 3) Sprint closure guard test.
ddev exec vendor/bin/phpunit \
  --configuration /var/www/html/phpunit.xml \
  --group ilas_site_assistant \
  /var/www/html/web/modules/custom/ilas_site_assistant/tests/src/Unit/PhaseThreeSprintSixWeekTwoGateTest.php
```

Expected `P3-SBD-02` verification result:
- `VC-UNIT` and `VC-QUALITY-GATE` pass with Sprint 6 Week 2 closure coverage
  included.
- Continuity anchors are present across roadmap/current-state/runbook/evidence
  for `P3-SBD-02`, `P3-OBJ-02`, `P3-OBJ-03`, `P3-EXT-02`, `P3-EXT-03`,
  and `CLAIM-157`.
- `PhaseThreeSprintSixWeekTwoGateTest.php` passes with
  roadmap/current-state/runbook/evidence/runtime/system-map continuity checks.
- Runtime proof markers in
  `docs/aila/runtime/phase3-sprint6-week2-performance-cost-governance-signoff.txt`
  record closed status, objective/exit linkage, and scope-boundary continuity.
- Scope boundaries remain unchanged: no net-new assistant channels or
  third-party model expansion beyond audited providers, and no platform-wide
  refactor of unrelated Drupal subsystems. Residual `B-04` remains open.

Store sanitized output in:
- `docs/aila/runtime/phase3-sprint6-week2-performance-cost-governance-signoff.txt`[^CLAIM-157]

### Phase 3 exit #3 final release packet known-unknown disposition + residual risk signoff verification (`P3-EXT-03`)

Use this command bundle to verify Phase 3 Exit criterion #3:
"Final release packet includes known-unknown disposition and residual risk signoff."

```bash
# 1) Validation command aliases from prompt matrix.
# VC-RUNBOOK-LOCAL
cd /home/evancurry/idaho-legal-aid-services && \
  ddev drush status && \
  ddev drush config:get ilas_site_assistant.settings -y && \
  ddev drush state:get system.cron_last

# VC-RUNBOOK-PANTHEON
for ENV in dev test live; do
  terminus remote:drush "idaho-legal-aid-services.${ENV}" -- config:get ilas_site_assistant.settings -y
done

# 2) Known-unknown and residual-risk continuity checks.
cd /home/evancurry/idaho-legal-aid-services && \
  rg -n "## 8\\) Known unknowns|Promptfoo CI ownership|Long-run cron cadence and queue drain timing under load|Phase 3 Exit #3 Final Release Packet Known-Unknown Disposition \\+ Residual Risk Signoff Disposition \\(2026-03-06\\)" \
    docs/aila/current-state.md

cd /home/evancurry/idaho-legal-aid-services && \
  rg -n "### CLAIM-122|Addendum \\(2026-03-06\\): Phase 3 Exit #3 \\(`P3-EXT-03`\\)|## Phase 3 Exit #3 Final Release Packet Includes Known-Unknown Disposition \\+ Residual Risk Signoff \\(`P3-EXT-03`\\)|### CLAIM-155" \
    docs/aila/evidence-index.md

cd /home/evancurry/idaho-legal-aid-services && \
  rg -n "\\| R-REL-02 \\||P3-EXT-03|phase3-exit3-release-packet-known-unknown-risk-signoff.txt|residual-risk-signoff-product-role|residual-risk-signoff-platform-role" \
    docs/aila/risk-register.md

# 3) Closure guard test.
cd /home/evancurry/idaho-legal-aid-services && \
  vendor/bin/phpunit --configuration phpunit.xml --group ilas_site_assistant \
  web/modules/custom/ilas_site_assistant/tests/src/Unit/PhaseThreeExitCriteriaThreeGateTest.php
```

Expected `P3-EXT-03` verification result:
- `VC-RUNBOOK-LOCAL` confirms local runtime visibility with `system.cron_last`
  continuity and assistant config visibility.
- `VC-RUNBOOK-PANTHEON` confirms target-environment continuity on
  `dev`/`test`/`live`.
- Current-state §8 known-unknown continuity remains explicit: Promptfoo CI
  ownership is resolved and long-run cron/queue load observation remains open.
- `CLAIM-122` continuity plus terminal closure claim `CLAIM-155` remain present
  in evidence index with `P3-EXT-03` addendum linkage.
- `R-REL-02` includes explicit `P3-EXT-03` runtime-marker continuity and
  role-based residual-risk signoff marker references.
- Runtime artifact includes release-packet closure markers and role-based signoff
  fields in
  `docs/aila/runtime/phase3-exit3-release-packet-known-unknown-risk-signoff.txt`.
- Scope boundaries remain unchanged: no net-new assistant channels or
  third-party model expansion beyond audited providers, and no platform-wide
  refactor of unrelated Drupal subsystems. Residual `B-04` remains open.
- If `VC-RUNBOOK-PANTHEON` fails (auth/connectivity/command failure),
  treat `P3-EXT-03` closure as blocked until authenticated Pantheon continuity
  output is captured.

Store sanitized output in:
- `docs/aila/runtime/phase3-exit3-release-packet-known-unknown-risk-signoff.txt`[^CLAIM-155]

### Phase 1 Exit #1 non-live alerts + dashboards verification

Use this command bundle to verify Phase 1 Exit criterion #1 in non-live contexts
without enabling live LLM paths.

Local (DDEV):

```bash
# Dashboard API/controller surfaces.
# AssistantApiController::health()
ddev drush php:eval "use Drupal\\ilas_site_assistant\\Controller\\AssistantApiController; \$c=\Drupal::service('class_resolver')->getInstanceFromDefinition(AssistantApiController::class); \$h=json_decode(\$c->health()->getContent(), TRUE); echo 'health_keys=' . implode(',', array_keys(\$h)) . PHP_EOL;"
# AssistantApiController::metrics()
ddev drush php:eval "use Drupal\\ilas_site_assistant\\Controller\\AssistantApiController; \$c=\Drupal::service('class_resolver')->getInstanceFromDefinition(AssistantApiController::class); \$m=json_decode(\$c->metrics()->getContent(), TRUE); echo 'metrics_keys=' . implode(',', array_keys(\$m)) . PHP_EOL;"
# AssistantReportController::report()
ddev drush php:eval "use Drupal\\ilas_site_assistant\\Controller\\AssistantReportController; \$c=\Drupal::service('class_resolver')->getInstanceFromDefinition(AssistantReportController::class); \$r=\$c->report(); echo 'report_sections=' . implode(',', array_keys(\$r)) . PHP_EOL;"

# Alert execution + watchdog context proof.
ddev drush php:eval "\Drupal::service('ilas_site_assistant.slo_alert')->checkAll(); echo 'slo_alert_check=invoked' . PHP_EOL;"
ddev drush sqlq "SELECT wid, message, variables FROM watchdog WHERE message LIKE 'SLO violation:%' ORDER BY wid DESC LIMIT 5;"
```

Pantheon non-live (`dev`, `test` only):

```bash
for ENV in dev test; do
  terminus remote:drush "idaho-legal-aid-services.${ENV}" -- php:eval "use Drupal\\ilas_site_assistant\\Controller\\AssistantApiController; \$c=\Drupal::service('class_resolver')->getInstanceFromDefinition(AssistantApiController::class); \$h=json_decode(\$c->health()->getContent(), TRUE); echo 'health_keys=' . implode(',', array_keys(\$h)) . PHP_EOL;"
  terminus remote:drush "idaho-legal-aid-services.${ENV}" -- php:eval "use Drupal\\ilas_site_assistant\\Controller\\AssistantApiController; \$c=\Drupal::service('class_resolver')->getInstanceFromDefinition(AssistantApiController::class); \$m=json_decode(\$c->metrics()->getContent(), TRUE); echo 'metrics_keys=' . implode(',', array_keys(\$m)) . PHP_EOL;"
  terminus remote:drush "idaho-legal-aid-services.${ENV}" -- php:eval "use Drupal\\ilas_site_assistant\\Controller\\AssistantReportController; \$c=\Drupal::service('class_resolver')->getInstanceFromDefinition(AssistantReportController::class); \$r=\$c->report(); echo 'report_sections=' . implode(',', array_keys(\$r)) . PHP_EOL;"
  terminus remote:drush "idaho-legal-aid-services.${ENV}" -- php:eval "\Drupal::service('ilas_site_assistant.slo_alert')->checkAll(); echo 'slo_alert_check=invoked' . PHP_EOL;"
  terminus remote:drush "idaho-legal-aid-services.${ENV}" -- sqlq "SELECT wid, message, variables FROM watchdog WHERE message LIKE 'SLO violation:%' ORDER BY wid DESC LIMIT 5;"
done
```

Store sanitized output snapshots in:
- `docs/aila/runtime/phase1-exit1-alerts-dashboards.txt`

### Promptfoo harness location check (repo-local)

```bash
rg -n --no-heading 'eval:promptfoo|eval:promptfoo:live|view:promptfoo' package.json
rg -n --no-heading 'promptfooconfig|ILAS_ASSISTANT_URL|run-promptfoo' promptfoo-evals/README.md promptfoo-evals/scripts
```

Store sanitized output in `docs/aila/runtime/promptfoo-ci-search.txt`.[^CLAIM-122]

### Phase 1 observability dependency gate verification

Use these checks to verify P0-EXT-03 dependency unblock status without enabling
live telemetry.

```bash
# 1) Verify runtime observability readiness state (no secret value output).
for ENV in dev test live; do
  terminus remote:drush "idaho-legal-aid-services.${ENV}" -- pml --status=enabled --type=module --no-core --format=list | rg '^raven$'
  terminus remote:drush "idaho-legal-aid-services.${ENV}" -- php:eval "\$c=\Drupal::config('ilas_site_assistant.settings'); \$r=\Drupal::config('raven.settings'); echo 'langfuse_enabled=' . (\$c->get('langfuse.enabled') ? 'true':'false') . PHP_EOL; echo 'langfuse_public_key=' . (\$c->get('langfuse.public_key') ? 'present':'missing') . PHP_EOL; echo 'langfuse_secret_key=' . (\$c->get('langfuse.secret_key') ? 'present':'missing') . PHP_EOL; echo 'raven_client_key=' . (\$r->get('client_key') ? 'present':'missing') . PHP_EOL;"
done

# 2) Derive assistant endpoint URLs for operator-run promptfoo checks.
for ENV in dev test live; do
  BASE_URL="$(terminus env:view "idaho-legal-aid-services.${ENV}" --print)"
  echo "${ENV} assistant_url=${BASE_URL%/}/assistant/api/message"
done

# 3) Run promptfoo smoke manually with operator-provided target URL.
export ILAS_ASSISTANT_URL="https://<env-host>/assistant/api/message"
npm run eval:promptfoo
```

Expected readiness result:
- Runtime booleans are the activation source-of-truth: `raven_client_key`,
  `langfuse_enabled`, and Langfuse key presence in effective config.
- `raven.settings` / `langfuse.settings` config objects may remain absent in
  active config storage because this stack uses runtime overrides.
- Promptfoo URL target is operator-supplied per environment.
  In GitHub Actions, set repository/environment secret `ILAS_ASSISTANT_URL`.
  In external CI, URL may be derived with Terminus
  (`scripts/ci/derive-assistant-url.sh`).
- Telemetry activation remains a Phase 1 implementation activity after
  credential and destination approvals.

### Cross-phase dependency row #1 CSRF hardening verification (`XDP-01`)

Use this command bundle to verify cross-phase dependency row #1:
"CSRF hardening (`IMP-SEC-01`)" remains closure-locked and unblocked for
downstream phase continuity.

```bash
# 1) Required validation aliases from prompt matrix.
# VC-PURE
vendor/bin/phpunit \
  -c /home/evancurry/idaho-legal-aid-services/phpunit.pure.xml \
  --filter 'CostControlPolicyTest|LlmControlConcurrencyTest|LlmEnhancerHardeningTest|CrossPhaseDependencyRowSixBehaviorTest|AssistantApiControllerCostControlMetricsTest'

# VC-UNIT
vendor/bin/phpunit \
  --configuration /home/evancurry/idaho-legal-aid-services/phpunit.xml \
  --group ilas_site_assistant \
  /home/evancurry/idaho-legal-aid-services/web/modules/custom/ilas_site_assistant/tests/src/Unit/CostControlPolicyTest.php \
  /home/evancurry/idaho-legal-aid-services/web/modules/custom/ilas_site_assistant/tests/src/Unit/LlmControlConcurrencyTest.php \
  /home/evancurry/idaho-legal-aid-services/web/modules/custom/ilas_site_assistant/tests/src/Unit/LlmEnhancerHardeningTest.php \
  /home/evancurry/idaho-legal-aid-services/web/modules/custom/ilas_site_assistant/tests/src/Unit/CrossPhaseDependencyRowSixBehaviorTest.php \
  /home/evancurry/idaho-legal-aid-services/web/modules/custom/ilas_site_assistant/tests/src/Unit/AssistantApiControllerCostControlMetricsTest.php

# VC-PANTHEON-READONLY
for ENV in dev test live; do
  terminus remote:drush "idaho-legal-aid-services.${ENV}" -- config:get ilas_site_assistant.settings cost_control -y
done

# 2) Prerequisite anchor checks: CSRF matrix + route enforcement verification.
rg -n "_ilas_strict_csrf_token: 'TRUE'|ilas_site_assistant.api.track" \
  web/modules/custom/ilas_site_assistant/ilas_site_assistant.routing.yml

rg -n "testAuthenticatedWithInvalidTokenIsForbiddenAndLogged|testAnonymousWithValidTokenIsAllowed" \
  web/modules/custom/ilas_site_assistant/tests/src/Unit/CsrfAuthMatrixTest.php

rg -n "testAnonymousMessageEndpointAllowsValidCsrfToken|testTrackEndpointRejectsCrossOriginOriginHeader|testTrackEndpointAllowsSameOriginRefererHeader" \
  web/modules/custom/ilas_site_assistant/tests/src/Functional/AssistantApiFunctionalTest.php

rg -n "POST /assistant/api/message \\+ CSRF|POST /assistant/api/track \\+ Origin/Referer or bootstrap-token recovery" \
  docs/aila/system-map.mmd

# 3) Targeted gate test for row #1 dependency enforcement.
ddev exec vendor/bin/phpunit \
  --configuration /var/www/html/phpunit.xml \
  --group ilas_site_assistant \
  /var/www/html/web/modules/custom/ilas_site_assistant/tests/src/Unit/CrossPhaseDependencyRowOneGateTest.php
```

Expected `XDP-01` dependency result:
- `VC-UNIT` passes with row #1 gate coverage included.
- `VC-RUNBOOK-PANTHEON` confirms target-environment continuity on
  `dev`/`test`/`live`.
- Prerequisite anchors for authenticated matrix + route enforcement remain
  present in routes/tests/system map.
- Dependency status semantics remain deterministic:
  any missing prerequisite => `xdp-01-status=blocked`;
  all prerequisites present => `xdp-01-status=closed`,
  `xdp-01-unresolved-dependency-count=0`,
  `xdp-01-unresolved-dependencies=none`.
- If `VC-RUNBOOK-PANTHEON` fails (auth/connectivity/command failure),
  treat `XDP-01` closure continuity as blocked until authenticated output is
  captured.

Store sanitized output in:
- `docs/aila/runtime/phase0-xdp01-csrf-hardening-dependency-gate.txt`[^CLAIM-160]

### Cross-phase dependency row #2 config parity verification (`XDP-02`)

Use this command bundle to verify cross-phase dependency row #2:
"Config parity (`IMP-CONF-01`)" remains closure-locked and unblocked for
downstream phase continuity.

```bash
# 1) Required validation aliases from prompt matrix.
# VC-UNIT
ddev exec vendor/bin/phpunit \
  --configuration /var/www/html/phpunit.xml \
  --group ilas_site_assistant \
  /var/www/html/web/modules/custom/ilas_site_assistant/tests/src/Unit

# VC-RUNBOOK-PANTHEON
for ENV in dev test live; do
  terminus remote:drush "idaho-legal-aid-services.${ENV}" -- \
    config:get ilas_site_assistant.settings vector_search --format=yaml
done

# 2) Prerequisite anchor checks: schema mapping + env drift checks.
rg -n "vector_search|fallback_gate" \
  web/modules/custom/ilas_site_assistant/config/schema/ilas_site_assistant.schema.yml

rg -n "testSchemaCoversAllInstallDefaultKeys|testActiveVectorSearchValuesMatchInstallDefaults" \
  web/modules/custom/ilas_site_assistant/tests/src/Unit/VectorSearchConfigSchemaTest.php

rg -n "testActiveConfigContainsAllInstallTopLevelKeys|testSchemaCoversAllInstallTopLevelKeys" \
  web/modules/custom/ilas_site_assistant/tests/src/Unit/ConfigCompletenessDriftTest.php

rg -n "Config parity \\+ drift checks \\(`IMP-CONF-01`\\)|vector-search-drift-report.txt|for ENV in dev test live; do" \
  docs/aila/runbook.md

rg -n "Config parity and retrieval tuning controls are stable across environments|vector_search|fallback_gate" \
  docs/aila/runtime/phase2-entry2-config-parity-retrieval-tuning.txt

# 3) Targeted gate test for row #2 dependency enforcement.
ddev exec vendor/bin/phpunit \
  --configuration /var/www/html/phpunit.xml \
  --group ilas_site_assistant \
  /var/www/html/web/modules/custom/ilas_site_assistant/tests/src/Unit/CrossPhaseDependencyRowTwoGateTest.php
```

Expected `XDP-02` dependency result:
- `VC-UNIT` passes with row #2 gate coverage included.
- `VC-RUNBOOK-PANTHEON` confirms target-environment continuity on
  `dev`/`test`/`live`.
- Prerequisite anchors for schema mapping + env drift checks remain present in
  schema/tests/runbook/runtime artifacts.
- Dependency status semantics remain deterministic:
  any missing prerequisite => `xdp-02-status=blocked`;
  all prerequisites present => `xdp-02-status=closed`,
  `xdp-02-unresolved-dependency-count=0`,
  `xdp-02-unresolved-dependencies=none`.
- If `VC-RUNBOOK-PANTHEON` fails (auth/connectivity/command failure),
  treat `XDP-02` closure continuity as blocked until authenticated output is
  captured.

Store sanitized output in:
- `docs/aila/runtime/phase0-xdp02-config-parity-dependency-gate.txt`[^CLAIM-161]

### Cross-phase dependency row #3 observability baseline verification (`XDP-03`)

Use this command bundle to verify cross-phase dependency row #3:
"Observability baseline (`IMP-OBS-01`)" remains closure-locked and unblocked
for downstream Phase 2/3 optimization continuity.

```bash
# 1) Required validation aliases from prompt matrix.
# VC-UNIT
ddev exec vendor/bin/phpunit \
  --configuration /var/www/html/phpunit.xml \
  --group ilas_site_assistant \
  /var/www/html/web/modules/custom/ilas_site_assistant/tests/src/Unit

# VC-RUNBOOK-PANTHEON
for ENV in dev test live; do
  terminus remote:drush "idaho-legal-aid-services.${ENV}" -- config:get ilas_site_assistant.settings -y
done

# 2) Prerequisite anchor checks: credentials readiness + redaction validation.
rg -n "LANGFUSE_PUBLIC_KEY|LANGFUSE_SECRET_KEY|SENTRY_DSN" \
  web/sites/default/settings.php

rg -n "langfuse_public_key=present|langfuse_secret_key=present|raven_client_key=present" \
  docs/aila/runtime/phase1-observability-gates.txt

rg -n "testRuntimeGatesArtifactShowsCredentialsPresentOnAllEnvironments|testSettingsPhpContainsLangfuseCredentialOverrideWiring|testSettingsPhpContainsSentryDsnOverrideWiring" \
  web/modules/custom/ilas_site_assistant/tests/src/Unit/TelemetryCredentialGateTest.php

rg -n "testAllNinePiiTypesRedactedAcrossAllSentryFields|testSentryEventGetsEnvironmentTagsAndPiiScrubbed" \
  web/modules/custom/ilas_site_assistant/tests/src/Unit/ImpObs01AcceptanceTest.php

rg -n "testSentryBeforeSendRedactsAllNinePiiTypes|testSentryBeforeSendRedactsExceptionPii|testSentryBeforeSendRedactsExtraContextPii" \
  web/modules/custom/ilas_site_assistant/tests/src/Unit/ObservabilityRedactionContractTest.php

rg -n "Observability|Langfuse tracer/queue|Sentry options subscriber|Sentry tag \\+ Langfuse error \\+ 500 internal_error" \
  docs/aila/system-map.mmd

# 3) Targeted gate test for row #3 dependency enforcement.
ddev exec vendor/bin/phpunit \
  --configuration /var/www/html/phpunit.xml \
  --group ilas_site_assistant \
  /var/www/html/web/modules/custom/ilas_site_assistant/tests/src/Unit/CrossPhaseDependencyRowThreeGateTest.php
```

Expected `XDP-03` dependency result:
- `VC-UNIT` passes with row #3 gate coverage included.
- `VC-RUNBOOK-PANTHEON` confirms target-environment continuity on
  `dev`/`test`/`live`.
- Prerequisite anchors for Sentry/Langfuse credential readiness and redaction
  validation remain present in settings/tests/runtime artifacts/system map.
- Dependency status semantics remain deterministic:
  any missing prerequisite => `xdp-03-status=blocked`;
  all prerequisites present => `xdp-03-status=closed`,
  `xdp-03-unresolved-dependency-count=0`,
  `xdp-03-unresolved-dependencies=none`.
- If `VC-RUNBOOK-PANTHEON` fails (auth/connectivity/command failure),
  treat `XDP-03` closure continuity as blocked until authenticated output is
  captured.

Store sanitized output in:
- `docs/aila/runtime/phase1-xdp03-observability-baseline-dependency-gate.txt`[^CLAIM-162]

### Cross-phase dependency row #4 CI quality gate verification (`XDP-04`)

Use this command bundle to verify cross-phase dependency row #4:
"CI quality gate (`IMP-TST-01`)" remains closure-locked and unblocked for
downstream release-gate continuity.

```bash
# 1) Required validation aliases from prompt matrix.
# VC-UNIT
ddev exec vendor/bin/phpunit \
  --configuration /var/www/html/phpunit.xml \
  --group ilas_site_assistant \
  /var/www/html/web/modules/custom/ilas_site_assistant/tests/src/Unit

# VC-RUNBOOK-PANTHEON
for ENV in dev test live; do
  terminus remote:drush "idaho-legal-aid-services.${ENV}" -- config:get ilas_site_assistant.settings -y
done

# 2) Prerequisite anchor checks: CI owner/platform decision continuity.
rg -n "name: Quality Gate|release/\\*\\*|cancel-in-progress|name: PHPUnit Quality Gate|name: Promptfoo Gate" \
  .github/workflows/quality-gate.yml

rg -n "run-quality-gate.sh|run-promptfoo-gate.sh|--mode auto|--skip-eval|--simulate-pass-rate" \
  scripts/ci/run-external-quality-gate.sh scripts/ci/run-promptfoo-gate.sh

rg -n "testWorkflowTriggersCoverAllBlockingBranches|testDocumentationDeclaresGateMandatory|testPromptfooBranchPolicyRemainsBranchAware" \
  web/modules/custom/ilas_site_assistant/tests/src/Unit/QualityGateEnforcementContractTest.php

rg -n "testCurrentStateFormalizesQualityGateContract|testRunbookContainsEnforcedQualityGateVerificationSteps" \
  web/modules/custom/ilas_site_assistant/tests/src/Unit/PhaseOneQualityGateContractTest.php

rg -n "name: Quality Gate|name: PHPUnit Quality Gate|name: Promptfoo Gate|cancel-in-progress: true" \
  docs/aila/runtime/phase2-entry1-observability-ci-baseline.txt

rg -n "CI\\[External CI runner|PF\\[Promptfoo harness|CI -->\\|drives scripted quality gates\\| PF" \
  docs/aila/system-map.mmd

# 3) Targeted gate test for row #4 dependency enforcement.
ddev exec vendor/bin/phpunit \
  --configuration /var/www/html/phpunit.xml \
  --group ilas_site_assistant \
  /var/www/html/web/modules/custom/ilas_site_assistant/tests/src/Unit/CrossPhaseDependencyRowFourGateTest.php
```

Expected `XDP-04` dependency result:
- `VC-UNIT` passes with row #4 gate coverage included.
- `VC-RUNBOOK-PANTHEON` confirms target-environment continuity on
  `dev`/`test`/`live`.
- Prerequisite anchors for CI owner/platform decision continuity remain present
  in workflow/scripts/tests/runtime/system-map artifacts.
- Dependency status semantics remain deterministic:
  any missing prerequisite => `xdp-04-status=blocked`;
  all prerequisites present => `xdp-04-status=closed`,
  `xdp-04-unresolved-dependency-count=0`,
  `xdp-04-unresolved-dependencies=none`.
- If `VC-RUNBOOK-PANTHEON` fails (auth/connectivity/command failure),
  treat `XDP-04` closure continuity as blocked until authenticated output is
  captured.

Store sanitized output in:
- `docs/aila/runtime/phase1-xdp04-ci-quality-gate-dependency-gate.txt`[^CLAIM-163]

### Cross-phase dependency row #5 retrieval confidence contract verification (`XDP-05`)

Use this command bundle to verify cross-phase dependency row #5:
"Retrieval confidence contract (`IMP-RAG-01`)" remains closure-locked and
unblocked for downstream Phase 3 readiness signoff continuity.

```bash
# 1) Required validation aliases from prompt matrix.
# VC-UNIT
ddev exec vendor/bin/phpunit \
  --configuration /var/www/html/phpunit.xml \
  --group ilas_site_assistant \
  /var/www/html/web/modules/custom/ilas_site_assistant/tests/src/Unit

# VC-RUNBOOK-PANTHEON
for ENV in dev test live; do
  terminus remote:drush "idaho-legal-aid-services.${ENV}" -- config:get ilas_site_assistant.settings -y
done

# 2) Prerequisite anchor checks: config parity + observability signals + eval harness.
rg -n "vector_search|fallback_gate" \
  web/modules/custom/ilas_site_assistant/config/schema/ilas_site_assistant.schema.yml

rg -n "testSchemaCoversAllInstallDefaultKeys|testActiveConfigContainsAllInstallTopLevelKeys" \
  web/modules/custom/ilas_site_assistant/tests/src/Unit/VectorSearchConfigSchemaTest.php \
  web/modules/custom/ilas_site_assistant/tests/src/Unit/ConfigCompletenessDriftTest.php

rg -n "Config Parity \\+ Retrieval Tuning Stability Verification|vector_search|fallback_gate" \
  docs/aila/runtime/phase2-entry2-config-parity-retrieval-tuning.txt

rg -n "langfuse_public_key=present|langfuse_secret_key=present|raven_client_key=present" \
  docs/aila/runtime/phase1-observability-gates.txt

rg -n "testRuntimeGatesArtifactShowsCredentialsPresentOnAllEnvironments|testSentryBeforeSendRedactsAllNinePiiTypes" \
  web/modules/custom/ilas_site_assistant/tests/src/Unit/TelemetryCredentialGateTest.php \
  web/modules/custom/ilas_site_assistant/tests/src/Unit/ObservabilityRedactionContractTest.php

rg -n "retrieval-confidence-thresholds.yaml|rag-contract-meta-present|rag-citation-coverage|rag-low-confidence-refusal" \
  promptfoo-evals/promptfooconfig.abuse.yaml \
  promptfoo-evals/tests/retrieval-confidence-thresholds.yaml

rg -n "\\[contract_meta\\]|citations_count|decision_reason" \
  promptfoo-evals/providers/ilas-live.js

rg -n "RAG_METRIC_THRESHOLD|RAG_METRIC_MIN_COUNT|rag-contract-meta-present|rag-citation-coverage|rag-low-confidence-refusal" \
  scripts/ci/run-promptfoo-gate.sh

rg -n "rag_contract_meta_fail=|rag_citation_coverage_fail=|rag_low_confidence_refusal_fail=" \
  docs/aila/runtime/phase2-exit1-retrieval-contract-confidence-thresholds.txt

# 3) Phase 3 readiness-signoff continuity anchors.
rg -n "Phase 3 Entry #1 disposition|phase3-entry1-retrieval-quality-targets.txt" \
  docs/aila/roadmap.md docs/aila/current-state.md

rg -n "Phase 2 Deliverable #2 disposition \\(2026-03-03\\): present|CLAIM-086" \
  docs/aila/runtime/phase3-entry1-retrieval-quality-targets.txt

# 4) Targeted behavioral dependency proof for row #5.
ddev exec vendor/bin/phpunit \
  --configuration /var/www/html/phpunit.xml \
  --group ilas_site_assistant \
  /var/www/html/web/modules/custom/ilas_site_assistant/tests/src/Unit/PhaseTwoDeliverableTwoBehaviorTest.php \
  /var/www/html/web/modules/custom/ilas_site_assistant/tests/src/Unit/CrossPhaseDependencyRowFiveBehaviorTest.php

# 5) Optional docs continuity check (non-blocking).
ddev exec vendor/bin/phpunit \
  --configuration /var/www/html/phpunit.xml \
  --group ilas_site_assistant_docs \
  /var/www/html/web/modules/custom/ilas_site_assistant/tests/src/Unit/CrossPhaseDependencyRowFiveGateTest.php
```

Expected `XDP-05` dependency result:
- `VC-UNIT` passes with row #5 behavioral proof included.
- `VC-RUNBOOK-PANTHEON` confirms target-environment continuity on
  `dev`/`test`/`live`.
- Prerequisite anchors for config parity, observability signals, and eval
  harness continuity remain present in schema/tests/runtime artifacts/scripts.
- Phase 3 readiness-signoff continuity anchors remain present in roadmap,
  current-state, and runtime closure artifacts.
- Optional docs continuity remains runnable through the non-blocking
  `ilas_site_assistant_docs` group.
- Dependency status semantics remain deterministic:
  any missing prerequisite => `xdp-05-status=blocked`;
  all prerequisites present => `xdp-05-status=closed`,
  `xdp-05-unresolved-dependency-count=0`,
  `xdp-05-unresolved-dependencies=none`.
- If `VC-RUNBOOK-PANTHEON` fails (auth/connectivity/command failure),
  treat `XDP-05` closure continuity as blocked until authenticated output is
  captured.

Store sanitized output in:
- `docs/aila/runtime/phase2-xdp05-retrieval-confidence-contract-dependency-gate.txt`[^CLAIM-164]

### Cross-phase dependency row #6 cost guardrails verification (`XDP-06`)

Use this command bundle to verify cross-phase dependency row #6:
"Cost guardrails (`IMP-COST-01`)" remains closure-locked and unblocked for
downstream Phase 3 cost-guardrail continuity.

```bash
# 1) Required validation aliases from prompt matrix.
# VC-UNIT
ddev exec vendor/bin/phpunit \
  --configuration /var/www/html/phpunit.xml \
  --group ilas_site_assistant \
  /var/www/html/web/modules/custom/ilas_site_assistant/tests/src/Unit

# VC-RUNBOOK-PANTHEON
for ENV in dev test live; do
  terminus remote:drush "idaho-legal-aid-services.${ENV}" -- config:get ilas_site_assistant.settings -y
done

# 2) Prerequisite anchor checks: observability + usage telemetry continuity from Phase 1/2.
rg -n "langfuse_public_key=present|langfuse_secret_key=present|raven_client_key=present" \
  docs/aila/runtime/phase1-observability-gates.txt

rg -n "health_keys=status,timestamp,checks|metrics_keys=timestamp,metrics,thresholds,cron,queue|slo_alert_check=invoked|SLO violation:" \
  docs/aila/runtime/phase1-exit1-alerts-dashboards.txt

rg -n "VC-RUNBOOK-LOCAL|VC-TOGGLE-CHECK|system\\.cron_last=|name: Quality Gate|CI -->\\|drives scripted quality gates\\| PF" \
  docs/aila/runtime/phase2-entry1-observability-ci-baseline.txt

# 3) Phase 3 cost-guardrail continuity anchors.
rg -n "p3-obj-02-status=closed|guard-anchor-cost-control-policy=present|cost-proof-status=pass|cost-proof-per-ip-status=pass" \
  docs/aila/runtime/phase3-obj2-performance-cost-guardrails.txt

rg -n "p3-ext-02-status=closed|owner-acceptance-product-role=accepted|owner-acceptance-platform-role=accepted|metrics-cost-control=present|thresholds-cost-control=present" \
  docs/aila/runtime/phase3-exit2-cost-performance-owner-acceptance.txt

# 4) Targeted behavioral dependency proof for row #6.
rg -n "dependency.per-ip-budget=pass|dependency.cache-effectiveness=pass|dependency.metrics-cost-control=pass" \
  docs/aila/runtime/phase3-xdp06-cost-guardrails-dependency-gate.txt

vendor/bin/phpunit \
  --configuration /home/evancurry/idaho-legal-aid-services/phpunit.xml \
  --group ilas_site_assistant \
  /home/evancurry/idaho-legal-aid-services/web/modules/custom/ilas_site_assistant/tests/src/Unit/CrossPhaseDependencyRowSixBehaviorTest.php

# 5) Optional docs continuity check (non-blocking).
ddev exec vendor/bin/phpunit \
  --configuration /var/www/html/phpunit.xml \
  --group ilas_site_assistant_docs \
  /var/www/html/web/modules/custom/ilas_site_assistant/tests/src/Unit/CrossPhaseDependencyRowSixGateTest.php
```

Expected `XDP-06` dependency result:
- `VC-PURE` and `VC-UNIT` pass with row #6 behavioral proof included.
- `VC-PANTHEON-READONLY` confirms target-environment continuity on
  `dev`/`test`/`live`.
- Cost-control config, fail-closed cost policy behavior, `dependency.per-ip-budget`,
  `dependency.cache-effectiveness`, `dependency.metrics-cost-control`, and SLO
  monitoring remain verified.
- Phase 3 cost-guardrail continuity anchors remain present in objective/exit
  runtime closure artifacts.
- Optional docs continuity remains runnable through the non-blocking
  `ilas_site_assistant_docs` group.
- Dependency status semantics remain deterministic:
  any missing prerequisite => `xdp-06-status=blocked`;
  all prerequisites present => `xdp-06-status=closed`,
  `xdp-06-unresolved-dependency-count=0`,
  `xdp-06-unresolved-dependencies=none`.
- If `VC-PANTHEON-READONLY` fails (auth/connectivity/command failure),
  treat `XDP-06` closure continuity as blocked until authenticated output is
  captured.

Store sanitized output in:
- `docs/aila/runtime/phase3-xdp06-cost-guardrails-dependency-gate.txt`[^CLAIM-165]

### P1-ENT-02 credential and destination approval verification

Use these checks to verify that P1-ENT-02 entry criterion is met: platform
credentials are available and destinations are approved.

```bash
# 1) Verify settings.php contains telemetry credential wiring (no secret output).
rg -n "LANGFUSE_PUBLIC_KEY|LANGFUSE_SECRET_KEY|SENTRY_DSN" \
  web/sites/default/settings.php

# 2) Verify install config includes Langfuse credential keys.
rg -n "public_key:|secret_key:|host:" \
  web/modules/custom/ilas_site_assistant/config/install/ilas_site_assistant.settings.yml

# 3) Verify runtime gates artifact confirms credentials on all environments.
rg -c "langfuse_public_key=present" docs/aila/runtime/phase1-observability-gates.txt
rg -c "raven_client_key=present" docs/aila/runtime/phase1-observability-gates.txt

# 4) Run the credential gate test.
ddev exec vendor/bin/phpunit \
  --configuration /var/www/html/phpunit.xml \
  /var/www/html/web/modules/custom/ilas_site_assistant/tests/src/Unit/TelemetryCredentialGateTest.php
```

Expected verification result:
- Settings.php contains `_ilas_get_secret()` wiring for Langfuse and Sentry credentials.
- Install config defaults include `langfuse.public_key`, `langfuse.secret_key`,
  `langfuse.host` with `enabled: false`.
- Runtime gates artifact shows 3 environments with credentials present.
- `TelemetryCredentialGateTest` passes with all assertions green.
- Approved destinations: Langfuse US cloud (`https://us.cloud.langfuse.com`),
  Sentry (DSN-controlled destination with PII redaction enforced).[^CLAIM-126]

### GitHub Actions secrets vs Pantheon runtime secrets

- Pantheon runtime secrets are available to the running Pantheon app, not CI
  runners.
- GitHub Actions needs separate CI secrets configuration.
- Required for real CI evals: `ILAS_ASSISTANT_URL` (use `dev`/`test`/multidev
  endpoint, not `live`).
- Optional only when deriving URL in CI: Terminus machine token for
  `terminus auth:login`.
- If the target environment is auth-protected, add request auth headers in
  `promptfoo-evals/providers/ilas-live.js`.

### Vertex runtime-only credential verification

- `ILAS_VERTEX_SA_JSON` must be provisioned only as a runtime secret; the
  assistant admin form no longer accepts or stores the Vertex service-account
  JSON.
- `settings.php` loads that secret into `$settings['ilas_vertex_sa_json']`.
  `LlmEnhancer` reads that runtime site setting directly, and TOVR-14 removes
  the dormant synced `vertex_sa_credentials` entity entirely. No exported
  Drupal config should now carry Vertex credential material.
- Read-only local checks after deploy/import:
  - `ddev drush config:get ilas_site_assistant.settings llm --format=yaml`
  - `ddev drush config:get key.key.vertex_sa_credentials --format=yaml`
    Expected result: config does not exist.
  - Optional runtime-presence check without printing the secret:
    `ddev drush php:eval "echo \Drupal\Core\Site\Settings::get('ilas_vertex_sa_json') ? 'present' : 'missing';"`
- Read-only Pantheon checks after deployment:
  - `terminus remote:drush idaho-legal-aid-services.dev -- config:get ilas_site_assistant.settings llm --format=yaml`
  - `terminus remote:drush idaho-legal-aid-services.dev -- config:get key.key.vertex_sa_credentials --format=yaml`
    Expected result: config does not exist.
  - Optional runtime-presence check without printing the secret:
    `terminus remote:drush idaho-legal-aid-services.dev -- php:eval "echo \Drupal\Core\Site\Settings::get('ilas_vertex_sa_json') ? 'present' : 'missing';"`

### RAUD-05 LLM transport hardening verification

- Baseline before the remediation: `LlmEnhancer::makeApiRequest()` retried
  `429`/`5xx` failures with synchronous exponential `usleep()` backoff, and the
  Vertex path fetched a fresh OAuth/metadata token on every LLM request.
- Required verification commands for the remediation report:
  - `VC-UNIT`
  - `VC-PURE`
  - `VC-QUALITY-GATE`
- Targeted local checks:
  - `vendor/bin/phpunit --configuration /home/evancurry/idaho-legal-aid-services/phpunit.xml /home/evancurry/idaho-legal-aid-services/web/modules/custom/ilas_site_assistant/tests/src/Unit/LlmEnhancerHardeningTest.php`
  - `vendor/bin/phpunit --configuration /home/evancurry/idaho-legal-aid-services/phpunit.xml /home/evancurry/idaho-legal-aid-services/web/modules/custom/ilas_site_assistant/tests/src/Unit/LlmEnhancerApiKeyTest.php /home/evancurry/idaho-legal-aid-services/web/modules/custom/ilas_site_assistant/tests/src/Unit/VertexRuntimeCredentialGuardTest.php /home/evancurry/idaho-legal-aid-services/web/modules/custom/ilas_site_assistant/tests/src/Unit/ConfigCompletenessDriftTest.php`
  - `/usr/bin/time -f 'elapsed=%E' vendor/bin/phpunit --configuration /home/evancurry/idaho-legal-aid-services/phpunit.xml --filter testRetryOn429 /home/evancurry/idaho-legal-aid-services/web/modules/custom/ilas_site_assistant/tests/src/Unit/LlmEnhancerHardeningTest.php`
- Expected contract after the remediation:
  - Retryable `429`/`5xx` paths perform at most one synchronous retry and the
    scheduled backoff never exceeds `250ms`.
  - Vertex service-account and metadata-server auth paths reuse cached bearer
    tokens until the buffered cache window expires.
  - Install and active config both pin `llm.max_retries: 1`.
- Archive the executed command summaries and measured latency result in
  `docs/aila/runtime/raud-05-llm-transport-hardening.txt`.

### RAUD-08 reverse-proxy / client-IP trust verification

- Baseline before the remediation:
  - Repo scan found no explicit `reverse_proxy`, `reverse_proxy_addresses`, or
    `reverse_proxy_trusted_headers` declarations outside Drupal defaults.
  - `AssistantApiController::message()` and `::track()` both keyed flood control
    directly from `Request::getClientIp()`.
  - Read-only Pantheon checks on March 9, 2026 reported `reverse_proxy=NULL`,
    `reverse_proxy_addresses=null`, `reverse_proxy_trusted_headers=NULL`,
    `trusted_proxies_runtime=[]`, and `trusted_header_set_runtime=-1` on
    `dev`, `test`, and `live`.
- Required verification commands for the remediation report:
  - `VC-UNIT`
  - `VC-PANTHEON-READONLY`
- Targeted local checks:
  - `ddev exec vendor/bin/phpunit --configuration /var/www/html/phpunit.xml --group ilas_site_assistant /var/www/html/web/modules/custom/ilas_site_assistant/tests/src/Unit/RequestTrustInspectorTest.php /var/www/html/web/modules/custom/ilas_site_assistant/tests/src/Unit/AssistantApiControllerProxyTrustTest.php /var/www/html/web/modules/custom/ilas_site_assistant/tests/src/Unit/ReverseProxySettingsContractTest.php`
  - `ddev exec bash -lc "vendor/bin/phpunit --configuration /var/www/html/phpunit.xml /var/www/html/web/modules/custom/ilas_site_assistant/tests/src/Functional/AssistantApiFunctionalTest.php --filter 'testHealthEndpointAccessibleToAdmin|testMetricsEndpointAccessibleToAdmin'"`
- Trust-specific Pantheon read-only checks after deployment:
  - `for ENV in dev test live; do terminus remote:drush "idaho-legal-aid-services.${ENV}" -- php:eval "use Drupal\\Core\\Site\\Settings; use Symfony\\Component\\HttpFoundation\\Request; echo 'reverse_proxy=' . var_export(Settings::get('reverse_proxy', NULL), TRUE) . PHP_EOL; echo 'reverse_proxy_addresses=' . json_encode(Settings::get('reverse_proxy_addresses', NULL)) . PHP_EOL; echo 'reverse_proxy_trusted_headers=' . var_export(Settings::get('reverse_proxy_trusted_headers', NULL), TRUE) . PHP_EOL; echo 'trusted_proxies_runtime=' . json_encode(Request::getTrustedProxies()) . PHP_EOL; echo 'trusted_header_set_runtime=' . Request::getTrustedHeaderSet() . PHP_EOL;" ; done`
  - `for ENV in dev test live; do terminus remote:drush "idaho-legal-aid-services.${ENV}" -- php:eval "use Drupal\\Core\\Site\\Settings; use Symfony\\Component\\HttpFoundation\\Request; \$headers = Settings::get('reverse_proxy_trusted_headers', Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_HOST | Request::HEADER_X_FORWARDED_PORT | Request::HEADER_X_FORWARDED_PROTO | Request::HEADER_FORWARDED); \$proxies = Settings::get('reverse_proxy_addresses', []); Request::setTrustedProxies(\$proxies, \$headers); \$request = Request::create('https://example.com/assistant/api/message', 'POST', [], [], [], ['REMOTE_ADDR' => (\$proxies[0] ?? '10.0.0.10'), 'HTTP_X_FORWARDED_FOR' => '198.51.100.7, ' . (\$proxies[0] ?? '10.0.0.10')]); echo json_encode(\\Drupal::service('ilas_site_assistant.request_trust_inspector')->inspectRequest(\$request), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;" ; done`
- Expected contract after the remediation:
  - `settings.php` trusts forwarded headers only when
    `ILAS_TRUSTED_PROXY_ADDRESSES` contains an explicit IP/CIDR allowlist.
  - `/assistant/api/message` and `/assistant/api/track` derive flood identity
    from the centralized request-trust inspector and warn when forwarded headers
    are present but currently untrusted.
  - Private `/assistant/api/health` and `/assistant/api/metrics` expose a
    `proxy_trust` diagnostic block for admin-or-machine-auth callers without
    changing the health status on proxy uncertainty alone.
  - If Pantheon read-only checks still show unset proxy trust settings or there
    is no authenticated HTTP capture of a live `proxy_trust` block, classify
    the finding as `Partially Fixed` rather than `Fixed`.
- Archive the executed command summaries and final classification in
  `docs/aila/runtime/raud-08-reverse-proxy-client-ip-trust.txt`.

### TOVR-07 private telemetry operationalization verification

- Baseline before the remediation:
  - Anonymous `GET /assistant/api/health` and `GET /assistant/api/metrics`
    returned HTTP `403` with JSON `error_code=access_denied` in local runtime.
  - `/admin/reports/ilas-assistant` and conversation views were already
    permission-gated Drupal-only review surfaces.
- Required verification commands for the remediation report:
  - `VC-UNIT`
  - `VC-KERNEL`
  - `VC-RUNTIME-LOCAL-SAFE`
  - `VC-RUNTIME-PANTHEON-SAFE`
  - `VC-ASSISTANT-SMOKE-LOCAL`
- Targeted local checks:
  - `vendor/bin/phpunit --configuration /home/evancurry/idaho-legal-aid-services/phpunit.xml /home/evancurry/idaho-legal-aid-services/web/modules/custom/ilas_site_assistant/tests/src/Unit/AssistantDiagnosticsAccessCheckTest.php`
  - `ddev exec bash -lc "vendor/bin/phpunit --configuration /var/www/html/phpunit.xml /var/www/html/web/modules/custom/ilas_site_assistant/tests/src/Functional/AssistantApiFunctionalTest.php --filter 'test(HealthEndpointAnonymousWithoutTokenReturnsAccessDenied|HealthEndpointPermissionCheck|HealthEndpointAccessibleWithValidMachineHeader|HealthEndpointAccessibleToAdmin|MetricsEndpointAnonymousWithoutTokenReturnsAccessDenied|MetricsEndpointPermissionCheck|MetricsEndpointAccessibleWithValidMachineHeader|MetricsEndpointAccessibleToAdmin)'"`.
  - Anonymous curls to `/assistant/api/health` and `/assistant/api/metrics`
    should stay `403 access_denied`.
  - Positive-path curls should send
    `X-ILAS-Observability-Key: ${ILAS_ASSISTANT_DIAGNOSTICS_TOKEN}` when the
    caller has the runtime secret; do not expect Drupal config export, forms,
    or `drupalSettings` to reveal it.[^CLAIM-212]
- Targeted Pantheon safe checks:
  - Anonymous curls to `/assistant/api/health` and `/assistant/api/metrics`
    should stay `403 access_denied`.
  - Safe runtime booleans may confirm whether
    `Settings::get('ilas_assistant_diagnostics_token')` is wired in the
    sampled environment, but positive-path HTTP proof still requires the real
    web-runtime secret to be provisioned to the monitor or operator making the
    request.
- Expected contract after the remediation:
  - `/assistant/api/health` and `/assistant/api/metrics` allow either the
    `view ilas site assistant reports` permission or a valid
    `X-ILAS-Observability-Key`.
  - Anonymous/sessionless callers continue to receive JSON
    `error_code=access_denied` with no public diagnostic payload.
  - `/admin/reports/ilas-assistant`,
    `/admin/reports/ilas-assistant/conversations`, and conversation detail
    remain Drupal-only manual-review surfaces.
  - `ilas_site_assistant_stats`, `ilas_site_assistant_no_answer`, and
    `ilas_site_assistant_conversations` remain metadata-only stores; do not add
    raw transcript or query retention to make monitoring easier.
  - If `VC-UNIT` or `VC-KERNEL` fail in unrelated pre-existing suites, record
    the specific failing tests and treat TOVR-07 as implemented with residual
    suite noise rather than silently treating the machine-auth path as
    unverified.
- Archive the executed command summaries, telemetry map, residual risks, and
  final classification in
  `docs/aila/runtime/tovr-07-internal-telemetry-operationalization.txt`.[^CLAIM-212]

### TOVR-08 override-aware runtime truth verification

- Baseline before the remediation:
  - Exported sync still reports `langfuse.enabled=false` in
    `config/ilas_site_assistant.settings.yml`, while fresh local and Pantheon
    baseline checks show effective runtime `langfuse.enabled=true`.
  - `config/raven.settings.yml` is absent from sync, and
    `config:get raven.settings` may return "does not exist" even when runtime
    `raven.settings.client_key` is present via `settings.php`.
  - `/assistant` HTML remains a separate truth surface for browser-only
    observability and live-only GA behavior; Drupal config inspection alone
    cannot prove those client markers.
- Required verification commands for the remediation report:
  - `VC-RUNTIME-LOCAL-SAFE`
  - `VC-RUNTIME-PANTHEON-SAFE`
  - `VC-SENTRY-PROBE`
  - `VC-LANGFUSE-PROBE-DIRECT`
- Canonical local checks:

```bash
cd /home/evancurry/idaho-legal-aid-services

ddev drush status --fields=uri,drupal-version,db-status
ddev drush ilas:runtime-truth
curl -skL https://ilas-pantheon.ddev.site/assistant | rg -o 'ilasObservability|environment":"[^"]+"|release":"[^"]+"|browserEnabled|showReportDialog|replaySessionSampleRate":[^,]+|googletagmanager|dataLayer|gtag\\(' -n || true
```

- Canonical Pantheon read-only checks after deployment:

```bash
for ENV in dev test live; do
  BASE_URL="$(terminus env:view "idaho-legal-aid-services.${ENV}" --print)"
  echo "=== ${ENV} ==="
  terminus remote:drush "idaho-legal-aid-services.${ENV}" -- status --fields=uri,drupal-version,db-status
  terminus remote:drush "idaho-legal-aid-services.${ENV}" -- ilas:runtime-truth
  curl -skL "${BASE_URL%/}/assistant" | rg -o 'ilasObservability|environment":"[^"]+"|release":"[^"]+"|browserEnabled|showReportDialog|replaySessionSampleRate":[^,]+|googletagmanager|dataLayer|gtag\\(' -n || true
done
```

- Stored-config-only habits to avoid:
  - `ddev drush config:get raven.settings`
  - `ddev drush config:get langfuse.settings`
  - `ddev drush config:get ilas_site_assistant.settings langfuse.enabled`
  - Historical ad hoc `php:eval` snapshots remain valid evidence for
    pre-TOVR-08 artifacts, but they are no longer the canonical runtime-truth
    path for `VC-RUNTIME-*`.
- Expected contract after the remediation:
  - `ilas:runtime-truth` emits a sanitized JSON snapshot with the fixed
    top-level sections `environment`, `exported_storage`,
    `effective_runtime`, `runtime_site_settings`, `browser_expected`,
    `override_channels`, and `divergences`.
  - `config:get raven.settings`, `config:get langfuse.settings`, and
    `config:get` on override-prone AILA keys are treated as stored-config
    inspection only, not effective runtime truth.
  - `/assistant` HTML sampling remains the authoritative companion proof for
    browser-only truth such as `ilasObservability`, browser Sentry flags, and
    assistant-route GA suppression.
  - `browser_expected.google_analytics.tag_present=true` now means sitewide GA
    is configured somewhere in the environment, not that `/assistant` should
    render GA. For the assistant route, the authoritative expectation is
    `assistant_page_suppressed=true`,
    `assistant_page_loader_expected=false`, and
    `assistant_page_data_layer_expected=false`; if a non-assistant public page
    still renders GA markers, record that separately as sitewide GA proof.
  - If Pantheon `dev`/`test`/`live` still report
    `Command "ilas:runtime-truth" is not defined`, classify the result as
    `repo remediated / deployment pending` instead of claiming hosted
    post-change runtime proof. Current TOVR-09 evidence confirms the helper now
    executes successfully on Pantheon `dev`/`test`/`live`.
- Archive the executed command summaries, misleading prior habits, residual
  risks, and final classification in
  `docs/aila/runtime/tovr-08-runtime-truth-verification.txt`.

### Langfuse runtime override pattern (reference)

Langfuse enablement uses a **secret-gated runtime override** pattern that is
standard for Drupal on Pantheon but can mislead auditors who only inspect
stored config files.

**How it works:**

1. Stored config (`config/ilas_site_assistant.settings.yml`) intentionally
   shows `langfuse.enabled: false` and empty credentials. This is by design —
   credentials must never appear in config exports.
2. At bootstrap, `settings.php` (L471-484) checks for `LANGFUSE_PUBLIC_KEY`
   and `LANGFUSE_SECRET_KEY` via `_ilas_get_secret()` (L380-390). If both are
   present, it sets `langfuse.enabled = TRUE` and injects the credentials into
   the active config via `$config` overrides.
3. The `_ilas_get_secret()` helper tries `pantheon_get_secret()` first (Pantheon
   runtime secrets), then falls back to `getenv()` (DDEV `.env` or shell env).
4. The `langfuse.environment` label is always overridden to `local` or
   `pantheon-{env}` regardless of what stored config says.

**Verification commands:**

```bash
# Canonical check: runtime truth with stored-vs-effective divergences
ddev drush ilas:runtime-truth

# Focused Langfuse status with queue health
ddev drush ilas:langfuse-status

# Admin UI: /admin/reports/ilas-assistant shows Observability Runtime Status
```

**Common audit pitfall:** Inspecting `config:get ilas_site_assistant.settings
langfuse.enabled` returns the stored value (`false`), not the effective runtime
value (`true`). Always use `ilas:runtime-truth` or `ilas:langfuse-status` for
the authoritative state.

### TOVR-09 Pinecone environment inventory verification

- Baseline before the investigation:
  - Repo config already defined `pinecone_vector`,
    `faq_accordion_vector`, and `assistant_resources_vector`, but prior docs
    still lacked one current per-environment answer for secret presence, index
    enablement, index population, queryability, and runtime gating.
  - The production audit still described Pinecone as disabled and partially
    unverified.
- Required verification commands for the inventory report:
  - `VC-RUNTIME-LOCAL-SAFE`
  - `VC-RUNTIME-PANTHEON-SAFE`
  - `VC-SEARCHAPI-INVENTORY`
  - `VC-PINECONE-QUERY-LOCAL`
  - `VC-PINECONE-PANTHEON-SAFE`
- Canonical local checks:

```bash
cd /home/evancurry/idaho-legal-aid-services

ddev drush status --fields=uri,drupal-version,db-status
ddev drush ilas:runtime-truth
ddev drush search-api:server-list
ddev drush search-api:list
ddev drush search-api:status faq_accordion_vector
ddev drush search-api:status assistant_resources_vector
ddev drush search-api:search faq_accordion_vector custody
ddev drush search-api:search assistant_resources_vector eviction
ddev drush config:status
ddev drush updatedb:status
```

- Canonical Pantheon read-only checks:

```bash
for ENV in dev test live; do
  BASE_URL="$(terminus env:view "idaho-legal-aid-services.${ENV}" --print)"
  echo "=== ${ENV} runtime ==="
  terminus remote:drush "idaho-legal-aid-services.${ENV}" -- status --fields=uri,drupal-version,db-status
  terminus remote:drush "idaho-legal-aid-services.${ENV}" -- ilas:runtime-truth
  echo "=== ${ENV} search ==="
  terminus remote:drush "idaho-legal-aid-services.${ENV}" -- search-api:server-list
  terminus remote:drush "idaho-legal-aid-services.${ENV}" -- search-api:list
  terminus remote:drush "idaho-legal-aid-services.${ENV}" -- search-api:status faq_accordion_vector
  terminus remote:drush "idaho-legal-aid-services.${ENV}" -- search-api:status assistant_resources_vector
  terminus remote:drush "idaho-legal-aid-services.${ENV}" -- search-api:search faq_accordion_vector custody
  terminus remote:drush "idaho-legal-aid-services.${ENV}" -- search-api:search assistant_resources_vector eviction
  terminus remote:drush "idaho-legal-aid-services.${ENV}" -- php:eval '$c=Drupal::config("ilas_site_assistant.settings"); echo json_encode(["vector_search" => $c->get("vector_search"), "retrieval" => $c->get("retrieval")], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;'
  terminus remote:drush "idaho-legal-aid-services.${ENV}" -- updatedb:status
  curl -skL "${BASE_URL%/}/assistant" | rg -o 'ilasObservability|environment":"[^"]+"|release":"[^"]+"|browserEnabled|showReportDialog|replaySessionSampleRate":[^,]+|googletagmanager|dataLayer' -n || true
done
```

- Expected inventory contract:
  - `local`, `dev`, `test`, and `live` each answer:
    - Pinecone secret present or absent
    - vector indexes enabled or disabled
    - vector indexes populated and searchable or blocked
    - effective `vector_search.enabled`
    - current blockers to live enablement
  - The report distinguishes "indexes exist" from "query path actually works".
  - Hosted `dev`/`test`/`live` are the current runtime baseline for query proof.
  - Local drift remains acceptable as an investigation finding, not a silent
    contradiction: document pending updates, config drift, and blocked local
    vector queries instead of treating local as equivalent to the hosted state.
- Archive the executed command summaries, per-environment answers, residual
  risks, and blocker classification in
  `docs/aila/runtime/tovr-09-pinecone-inventory.txt`.

### TOVR-10 Pinecone index integrity and refresh readiness verification

- Baseline before the investigation:
  - TOVR-09 already proved hosted `dev` / `test` / `live` were provisioned and
    queryable while `local` was drifted.
  - TOVR-10 must go further and prove whether each vector index is structurally
    valid, populated, refreshable, and aligned with retrieval expectations.
  - Do not enable vector search first and diagnose later.
- Required validation commands for the integrity report:
  - `VC-UNIT`
  - `VC-PURE`
  - `VC-SEARCHAPI-INVENTORY`
  - `VC-PINECONE-QUERY-LOCAL`
  - `VC-PINECONE-PANTHEON-SAFE`
- Canonical local checks:

```bash
cd /home/evancurry/idaho-legal-aid-services

ddev drush search-api:server-list
ddev drush search-api:list
ddev drush search-api:status faq_accordion_vector
ddev drush search-api:status assistant_resources_vector
ddev drush search-api:search faq_accordion_vector custody
ddev drush search-api:search assistant_resources_vector eviction
ddev drush ilas:runtime-truth
ddev drush config:get ilas_site_assistant.settings retrieval --format=json
ddev drush config:status
ddev drush updatedb:status
ddev drush state:get ilas_site_assistant.vector_index_hygiene.snapshot --format=json
ddev drush php:eval '$r=Drupal::service("ilas_site_assistant.retrieval_configuration")->getHealthSnapshot(); echo "RETRIEVAL\n"; echo json_encode($r, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL; $v=Drupal::service("ilas_site_assistant.vector_index_hygiene")->getSnapshot(); echo "VECTOR\n"; echo json_encode($v, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;'

/usr/bin/time -p ddev drush search-api:search faq_accordion_vector custody
/usr/bin/time -p ddev drush search-api:search assistant_resources_vector eviction
```

- Canonical Pantheon read-only checks:

```bash
for ENV in dev test live; do
  echo "=== ${ENV} ==="
  terminus remote:drush "idaho-legal-aid-services.${ENV}" -- search-api:server-list
  terminus remote:drush "idaho-legal-aid-services.${ENV}" -- search-api:list
  terminus remote:drush "idaho-legal-aid-services.${ENV}" -- search-api:status faq_accordion_vector
  terminus remote:drush "idaho-legal-aid-services.${ENV}" -- search-api:status assistant_resources_vector
  terminus remote:drush "idaho-legal-aid-services.${ENV}" -- search-api:search faq_accordion_vector custody
  terminus remote:drush "idaho-legal-aid-services.${ENV}" -- search-api:search assistant_resources_vector eviction
  terminus remote:drush "idaho-legal-aid-services.${ENV}" -- php:eval '$r=Drupal::service("ilas_site_assistant.retrieval_configuration")->getHealthSnapshot(); echo "RETRIEVAL\n"; echo json_encode($r, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL; $v=Drupal::service("ilas_site_assistant.vector_index_hygiene")->getSnapshot(); echo "VECTOR\n"; echo json_encode($v, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;'
  terminus remote:drush "idaho-legal-aid-services.${ENV}" -- state:get ilas_site_assistant.vector_index_hygiene.snapshot --format=json
  /usr/bin/time -p terminus remote:drush "idaho-legal-aid-services.${ENV}" -- search-api:search faq_accordion_vector custody
  /usr/bin/time -p terminus remote:drush "idaho-legal-aid-services.${ENV}" -- search-api:search assistant_resources_vector eviction
done
```

- Scope and decision rules:
  - Hosted Pantheon remains read-only in TOVR-10; do not reset trackers or run
    full reindex commands there.
  - Treat current steady-state hosted freshness as proven only if Search API
    status, retrieval config, and hygiene snapshots agree.
  - Treat local refresh proof as blocked if local config drift or pending
    updates break retrieval governance.
  - Record query latency beside each probe, but do not infer timeout safety from
    a fast one-off probe alone.
  - If the repo still lacks a clean Pinecone transport timeout control, report
    timeout handling as `unproven` / `not sufficient for enablement`.
- Expected integrity contract:
  - One matrix row per environment per index (`local`, `dev`, `test`, `live`
    x `faq_accordion_vector`, `assistant_resources_vector`).
  - Each row answers:
    - retrieval ID matches current runtime expectation or not
    - server/metric/dimensions match or drift
    - index is populated or not
    - direct query succeeds, warns, or fails
    - hygiene snapshot is fresh/non-overdue or stale/overdue
    - current refresh path is sufficient, blocked, or unverified
    - timeout/degraded-response handling is proven or still unverified
  - The report must end with an explicit enablement verdict. If local drift,
    hosted rebuild proof, or timeout readiness remain unresolved, the index
    layer is not ready for enablement.
- Archive the executed command summaries, integrity matrix, residual risks, and
  still-unverified surfaces in
  `docs/aila/runtime/tovr-10-pinecone-index-integrity.txt`.

### TOVR-11 Pinecone retrieval integration hardening verification

- Baseline before the investigation:
  - TOVR-10 already proved the index layer was not enablement-ready.
  - TOVR-11 must focus on the application-layer retrieval contract in
    `FaqIndex` and `ResourceFinder`, not just index existence.
  - Keep vector retrieval supplement-only and lexical-first unless executable
    proof shows otherwise.
- Required validation commands for the integration report:
  - `VC-UNIT`
  - `VC-PURE`
  - `VC-SEARCHAPI-INVENTORY`
  - `VC-PINECONE-QUERY-LOCAL`
- Canonical local checks:

```bash
cd /home/evancurry/idaho-legal-aid-services

# Focused retrieval contracts while iterating.
vendor/bin/phpunit \
  --configuration /home/evancurry/idaho-legal-aid-services/phpunit.pure.xml \
  /home/evancurry/idaho-legal-aid-services/web/modules/custom/ilas_site_assistant/tests/src/Unit/VectorSearchMergeTest.php \
  /home/evancurry/idaho-legal-aid-services/web/modules/custom/ilas_site_assistant/tests/src/Unit/DependencyFailureDegradeContractTest.php \
  /home/evancurry/idaho-legal-aid-services/web/modules/custom/ilas_site_assistant/tests/src/Unit/PineconeQueryTimeoutContractTest.php

# Broad verification aliases.
vendor/bin/phpunit --configuration /home/evancurry/idaho-legal-aid-services/phpunit.pure.xml
ddev exec vendor/bin/phpunit --configuration /var/www/html/phpunit.xml --group ilas_site_assistant /var/www/html/web/modules/custom/ilas_site_assistant/tests/src/Unit

# Search API + local vector probes.
ddev drush search-api:server-list
ddev drush search-api:list
ddev drush search-api:status faq_accordion_vector
ddev drush search-api:status assistant_resources_vector

# Mandatory after any Pinecone service-definition change.
ddev drush cr

ddev drush search-api:search faq_accordion_vector custody
ddev drush search-api:search assistant_resources_vector eviction
```

- Scope and decision rules:
  - Reconstruct the trigger path before editing and record the decision points:
    `disabled`, `sufficient_lexical`, `sparse_lexical`,
    `low_quality_lexical`.
  - Treat vector results as mergeable only when the vector outcome is healthy
    or healthy-empty.
  - Treat degraded or backoff outcomes as lexical-only and non-cacheable in the
    normal per-query cache.
  - If Pinecone provider or service definitions changed, rebuild Drupal caches
    before interpreting any Drush query failure.
  - Query-only Pinecone timeouts may be added here, but if embeddings still use
    shared/global transport settings, keep the final verdict below full
    enablement readiness.
- Expected integration contract:
  - The report must include a trigger-path map for FAQ and resource retrieval.
  - Tests must prove:
    - trigger reasons are explicit
    - degraded/slow vector outcomes do not merge
    - degraded/backoff outcomes do not write the normal query cache
    - cross-request backoff works
    - healthy and policy-skipped outcomes still cache normally
    - Pinecone query-only timeout config is wired through the actual client
  - If local direct vector queries still fail or hosted post-change runtime
    proof is missing, the final result remains `Partially Fixed` or
    `Unverified`, not enablement-ready.
- Archive the executed command summaries, trigger-path map, residual risks, and
  still-unverified surfaces in
  `docs/aila/runtime/tovr-11-pinecone-retrieval-integration.txt`.

### TOVR-12 Pinecone non-live enablement verification

- Baseline before the enablement pass:
  - TOVR-09 through TOVR-11 proved Pinecone secret wiring, hosted vector-index
    availability, and lexical-first retrieval hardening, but did not actually
    enable vector supplementation anywhere.
  - Sync config must remain safe-by-default with
    `vector_search.enabled=false`; non-live rollout is runtime-only via
    `ILAS_VECTOR_SEARCH_ENABLED`.
  - On `dev` / `test`, `settings.php` also checks
    `private://ilas-vector-search-enabled.txt` whenever vector search is still
    effectively disabled, including the case where a site-level falsey secret
    masks an env-level enablement override.
  - `live` remains hard-forced off in `settings.php` even if the toggle is set.
- Required validation commands for the enablement report:
  - `VC-PURE`
  - `VC-UNIT`
  - `VC-KERNEL`
  - `VC-SEARCHAPI-INVENTORY`
  - `VC-PINECONE-QUERY-LOCAL`
  - `VC-PINECONE-REINDEX-LOCAL`
  - `VC-PINECONE-PANTHEON-SAFE`
  - `VC-ASSISTANT-SMOKE-LOCAL`
  - `VC-ASSISTANT-SMOKE-PANTHEON`
  - `VC-PROMPTFOO-PACED-LOCAL`
- Canonical local repair and pre-enable checks:

```bash
cd /home/evancurry/idaho-legal-aid-services

# Baseline with runtime toggle off.
ddev drush ilas:runtime-truth
ddev drush config:status
ddev drush updatedb:status
ddev drush search-api:list
ddev drush state:get ilas_site_assistant.vector_index_hygiene.snapshot --format=json
ddev drush php:eval '$snapshot = Drupal::service("ilas_site_assistant.retrieval_configuration")->getHealthSnapshot(); echo json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;'
ddev drush search-api:search faq_accordion_vector custody
ddev drush search-api:search assistant_resources_vector eviction

# Repair local parity before enablement.
ddev drush updatedb -y
ddev drush config:import --partial --source=/var/www/html/.codex-tmp/tovr12-local-config -y
ddev drush cr

# Confirm retrieval IDs, Gemini runtime provider, and Pinecone timeout keys.
ddev drush config:get ilas_site_assistant.settings retrieval
ddev drush config:get key.key.gemini_api_key
ddev drush config:get ai_vdb_provider_pinecone.settings

# Enable via runtime env only.
printf '\nILAS_VECTOR_SEARCH_ENABLED=1\n' >> .ddev/.env
ddev restart
ddev drush ilas:runtime-truth

# Provenance + lexical-first smoke checks.
BASE_URL=https://ilas-pantheon.ddev.site
COOKIE_JAR="$(mktemp)"
TOKEN="$(curl -sk -c "$COOKIE_JAR" "$BASE_URL/assistant/api/session/bootstrap")"
for QUERY in \
  "custody forms" \
  "do you have custody forms" \
  "where is the Boise office" \
  "what office helps me in Twin Falls" \
  "eviction forms or guides"; do
  curl -sk -b "$COOKIE_JAR" \
    -H "Content-Type: application/json" \
    -H "X-CSRF-Token: $TOKEN" \
    --data "{\"message\":\"$QUERY\"}" \
    "$BASE_URL/assistant/api/message"
  echo
done
rm -f "$COOKIE_JAR"

env NODE_EXTRA_CA_CERTS="$(mkcert -CAROOT)/rootCA.pem" \
  node scripts/ci/run-vector-provenance-smoke.js \
  --assistant-url "$BASE_URL/assistant/api/message" \
  --site-base-url "$BASE_URL" \
  --environment local
```

- Canonical hosted `dev` / `test` rollout sequence after protected `master`
  merge and Pantheon code publish:

```bash
cd /home/evancurry/idaho-legal-aid-services

# Push Pantheon only after protected github/master is green.
npm run git:publish -- --origin-only
terminus env:code-rebuild idaho-legal-aid-services.dev -y

# Dev: baseline with toggle absent/off.
terminus remote:drush idaho-legal-aid-services.dev -- ilas:runtime-truth
terminus remote:drush idaho-legal-aid-services.dev -- updatedb -y
terminus remote:drush idaho-legal-aid-services.dev -- config:import -y
terminus remote:drush idaho-legal-aid-services.dev -- cr
terminus remote:drush idaho-legal-aid-services.dev -- config:get ai_vdb_provider_pinecone.settings
terminus remote:drush idaho-legal-aid-services.dev -- php:eval '$snapshot = Drupal::service("ilas_site_assistant.retrieval_configuration")->getHealthSnapshot(); echo json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;'
terminus remote:drush idaho-legal-aid-services.dev -- state:get ilas_site_assistant.vector_index_hygiene.snapshot --format=json
terminus remote:drush idaho-legal-aid-services.dev -- search-api:search faq_accordion_vector custody
terminus remote:drush idaho-legal-aid-services.dev -- search-api:search assistant_resources_vector eviction

DEV_BASE_URL="$(terminus env:view idaho-legal-aid-services.dev --print)"
env NODE_EXTRA_CA_CERTS="$(mkcert -CAROOT)/rootCA.pem" \
  node scripts/ci/run-vector-provenance-smoke.js \
  --assistant-url "${DEV_BASE_URL%/}/assistant/api/message" \
  --site-base-url "${DEV_BASE_URL%/}" \
  --environment dev

terminus secret:site:set idaho-legal-aid-services ILAS_VECTOR_SEARCH_ENABLED 0 --type=env --scope=web,user
terminus secret:site:set idaho-legal-aid-services.dev ILAS_VECTOR_SEARCH_ENABLED 1
terminus env:clear-cache idaho-legal-aid-services.dev
terminus remote:drush idaho-legal-aid-services.dev -- cr

# If the secret path still does not appear in `ilas:runtime-truth`, or if a
# falsey site-level secret masks the env override, enable via the private flag
# file on Pantheon instead of exported config.
terminus remote:drush idaho-legal-aid-services.dev -- php:eval '$path = \Drupal::service("file_system")->realpath("private://") . "/ilas-vector-search-enabled.txt"; file_put_contents($path, "1\n"); echo $path . PHP_EOL;'
terminus remote:drush idaho-legal-aid-services.dev -- ilas:runtime-truth

# Promote code to test only after dev passes.
terminus env:deploy idaho-legal-aid-services.test --updatedb --cc --note="TOVR-12 dev to test" -y
terminus remote:drush idaho-legal-aid-services.test -- config:import -y
terminus remote:drush idaho-legal-aid-services.test -- cr

TEST_BASE_URL="$(terminus env:view idaho-legal-aid-services.test --print)"
env NODE_EXTRA_CA_CERTS="$(mkcert -CAROOT)/rootCA.pem" \
  node scripts/ci/run-vector-provenance-smoke.js \
  --assistant-url "${TEST_BASE_URL%/}/assistant/api/message" \
  --site-base-url "${TEST_BASE_URL%/}" \
  --environment test

terminus secret:site:set idaho-legal-aid-services.test ILAS_VECTOR_SEARCH_ENABLED 1
terminus env:clear-cache idaho-legal-aid-services.test
terminus remote:drush idaho-legal-aid-services.test -- cr
# If `ilas:runtime-truth` still shows `config export` or a falsey secret path,
# write the private flag file and rerun the command.
terminus remote:drush idaho-legal-aid-services.test -- php:eval '$path = \Drupal::service("file_system")->realpath("private://") . "/ilas-vector-search-enabled.txt"; file_put_contents($path, "1\n"); echo $path . PHP_EOL;'
terminus remote:drush idaho-legal-aid-services.test -- ilas:runtime-truth
```

- Required fixed provenance prompts for all non-live environments:
  - `what are idaho tenant rights for eviction notices`
  - `im raising my granddaughter because my daughter is on drugs... what are my legal options`
  - `is there any way to get my car back`
- Scope and decision rules:
  - Keep sync config at `vector_search.enabled=false`; only runtime toggles may
    enable non-live vector behavior.
  - Do not enable `dev` or `test` until the active Pinecone provider config
    proves `query_connect_timeout_seconds=1.0` and
    `query_request_timeout_seconds=2.0`.
  - Treat lexical-first control prompts as regression sentinels. After
    enablement they may remain lexical-only, but they must not degrade into
    vector-only or worse-quality results.
  - Treat untranslated or wrong-language vector hits as rollout defects. If the
    assistant response surface shows translation ghosts, clear the runtime
    toggle and stop promotion.
  - `live` remains out of scope. If any command path touches `live`, stop and
    re-scope the pass under `TOVR-13`.
- Expected enablement contract:
  - `ilas:runtime-truth` shows stored `false` versus effective `true` for
    `vector_search.enabled` on `local`, then `dev`, then `test`, with
    authoritative source
    `settings.php runtime toggle -> getenv/pantheon_get_secret` or
    `settings.php runtime toggle -> private flag file`.
  - `live` still reports `vector_search.enabled=false` with authoritative source
    `settings.php live branch`.
  - Each environment has explicit before/after evidence, exact command
    summaries, and a rollback switch:
    - local: unset `ILAS_VECTOR_SEARCH_ENABLED` in `.ddev/.env`, restart DDEV,
      rebuild caches
    - dev/test: either clear or set `ILAS_VECTOR_SEARCH_ENABLED=0`, or remove
      `private://ilas-vector-search-enabled.txt`, then clear caches and rerun
      `drush cr`
  - Archive the executed command summaries, before/after status by environment,
    changed files, residual risks, rollback notes, and still-unverified
    surfaces in `docs/aila/runtime/tovr-12-pinecone-non-live-enablement.txt`.

### TOVR-13 Pinecone live readiness verification

- Baseline before the live gate review:
  - `TOVR-12` proves runtime-only vector enablement on `local` / `dev` /
    `test`, while `live` remains hard-forced off.
  - `TOVR-13` must separate infrastructure readiness from rollout approval.
  - Do not enable live LLM response generation in this pass.
- Required validation commands for the live-readiness report:
  - `VC-RUNTIME-PANTHEON-SAFE`
  - `VC-PINECONE-PANTHEON-SAFE`
  - corrected `VC-ASSISTANT-SMOKE-PANTHEON`
  - vector provenance smoke on the three fixed prompts
  - `VC-PROMPTFOO-LIVE-SANITIZED`
  - `VC-SENTRY-PROBE`
  - `VC-LANGFUSE-PROBE-DIRECT`
- Canonical hosted checks:

```bash
cd /home/evancurry/idaho-legal-aid-services

terminus remote:drush idaho-legal-aid-services.live -- ilas:runtime-truth
terminus remote:drush idaho-legal-aid-services.live -- search-api:status faq_accordion_vector
terminus remote:drush idaho-legal-aid-services.live -- search-api:status assistant_resources_vector
terminus remote:drush idaho-legal-aid-services.live -- php:eval '$snapshot=Drupal::service("ilas_site_assistant.retrieval_configuration")->getHealthSnapshot(); echo json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;'
terminus remote:drush idaho-legal-aid-services.live -- state:get ilas_site_assistant.vector_index_hygiene.snapshot --format=json

LIVE_BASE_URL="$(terminus env:view idaho-legal-aid-services.live --print)"
BASE_URL="${LIVE_BASE_URL%/}"
COOKIE_JAR="$(mktemp)"
TOKEN="$(curl -sk -c "$COOKIE_JAR" "$BASE_URL/assistant/api/session/bootstrap")"
curl -sk -b "$COOKIE_JAR" \
  -H "Content-Type: application/json" \
  -H "X-CSRF-Token: $TOKEN" \
  --data '{"message":"custody forms"}' \
  "$BASE_URL/assistant/api/message"
rm -f "$COOKIE_JAR"

ILAS_ASSISTANT_URL="$BASE_URL/assistant/api/message" \
ILAS_SITE_BASE_URL="$BASE_URL" \
node scripts/ci/run-vector-provenance-smoke.js --environment live

gh run list --workflow quality-gate.yml -L 5 --json databaseId,status,conclusion,headBranch,displayTitle,updatedAt
terminus remote:drush idaho-legal-aid-services.live -- ilas:sentry-probe
terminus remote:drush idaho-legal-aid-services.live -- ilas:langfuse-probe --direct
python3 "$HOME/.codex/skills/sentry/scripts/sentry_api.py" \
  --org idaho-legal-aid-services \
  --project php \
  list-issues \
  --environment pantheon-live \
  --time-range 24h \
  --limit 10 \
  --query 'assistant_name:aila is:unresolved'
```

- Scope and decision rules:
  - Corrected smoke means POST without redirect-follow and a normalized base
    URL (`${BASE_URL%/}`).
  - Treat healthy secrets/indexes as necessary but not sufficient.
  - Treat live observability as `ready` only if Sentry capture is current and
    Langfuse direct ingestion is current; treat vector-path trace proof as
    incomplete until hosted traces show the new vector metadata fields.
  - Treat live monitoring as blocked while `diagnostics_token_present=false`
    unless a formally approved authenticated drush/probe standard is used
    instead of `/assistant/api/health` and `/assistant/api/metrics`.
  - Treat prompt-level quality as blocked until prompts 2 / 3 either show
    accepted vector improvement or are explicitly removed from the rollout
    acceptance set.
  - If the latest `master` Quality Gate is red, the final result cannot be
    `Ready for live enablement`.
- Expected readiness contract:
  - Final state must be exactly one of:
    - `Ready for live enablement`
    - `Blocked with explicit evidence`
    - `Partially ready with exact prerequisites`
  - Current expected result on the 2026-03-17 evidence set is
    `Blocked with explicit evidence`.
  - Archive the full report, command summaries, blockers, exact prerequisites,
    rollback notes, and still-unverified surfaces in
    `docs/aila/runtime/tovr-13-pinecone-live-readiness.txt`.

### TOVR-16 final consolidation verification

- Required validation commands for the consolidation report:
  - `VC-GHA-QUALITY-HISTORY`
  - `VC-GHA-OBS-RELEASE-HISTORY`
  - `VC-RUNTIME-LOCAL-SAFE`
  - `VC-RUNTIME-PANTHEON-SAFE`
- Supplemental run-detail commands used to avoid guessing on current history:

```bash
cd /home/evancurry/idaho-legal-aid-services

gh run view 23225344665 --json databaseId,displayTitle,conclusion,status,headBranch,event,workflowName,jobs,createdAt,updatedAt
gh run view 23165713689 --json databaseId,displayTitle,conclusion,status,headBranch,event,workflowName,jobs,createdAt,updatedAt
```

- Interpretation rules:
  - Use absolute dates and run IDs in the TOVR-16 report. Do not write
    relative references like "latest run" or "currently pending" without the
    specific run ID and date that prove it.
  - Treat `TOVR-01` through `TOVR-15` runtime reports as dated history. TOVR-16
    may supersede stale "deployment pending" or "failed-only" notes in current
    docs only when the fresh reruns prove the newer state directly.
  - Keep any surface not rerun by TOVR-16 explicitly labeled
    `still unverified`, even if an earlier prompt was optimistic.
  - Current 2026-03-18 expectations from the canonical reruns are:
    - latest `master` Quality Gate remains failing (`23225344665`)
    - `Observability Release` has at least one successful GitHub run
      (`23165713689`)
    - `local` / `dev` / `test` runtime truth reports
      `vector_search.enabled=true`
    - `live` runtime truth reports `vector_search.enabled=false` on
      `release=live_149`
    - `diagnostics_token_present=false` in all sampled runtimes
    - rendered `/assistant` samples no longer show GA markers on `live`
- Archive the full report, command summaries, scorecard, blockers, and
  still-unverified surfaces in
  `docs/aila/runtime/tovr-16-final-consolidation-roadmap.txt`.

### RAUD-09 live debug metadata guard verification

- Baseline before the remediation:
  - Repo scan showed only one response-debug enablement path:
    `AssistantApiController::isDebugMode()` returned
    `getenv('ILAS_CHATBOT_DEBUG') === '1'`.
  - Every `_debug` attachment site in `AssistantApiController::message()` and
    the office follow-up handlers depended on that single boolean.
  - `settings.php` and `SentryOptionsSubscriber.php` did not provide a live
    deny path for response debug metadata.
- Required verification commands for the remediation report:
  - `VC-UNIT`
  - `VC-PANTHEON-READONLY`
- Targeted local checks:
  - `ddev exec vendor/bin/phpunit --configuration /var/www/html/phpunit.xml --group ilas_site_assistant /var/www/html/web/modules/custom/ilas_site_assistant/tests/src/Unit/EnvironmentDetectorTest.php /var/www/html/web/modules/custom/ilas_site_assistant/tests/src/Unit/AssistantApiControllerDebugGuardTest.php /var/www/html/web/modules/custom/ilas_site_assistant/tests/src/Unit/FallbackGateTest.php /var/www/html/web/modules/custom/ilas_site_assistant/tests/src/Unit/LlmEnhancerHardeningTest.php /var/www/html/web/modules/custom/ilas_site_assistant/tests/src/Unit/VertexRuntimeCredentialGuardTest.php`
- Debug-specific Pantheon read-only checks after deployment:
  - `for ENV in dev test live; do terminus remote:drush "idaho-legal-aid-services.${ENV}" -- php:eval "use Drupal\\Core\\Site\\Settings; use Drupal\\ilas_site_assistant\\Controller\\AssistantApiController; use Symfony\\Component\\HttpFoundation\\Request; \$controller = Drupal::service('class_resolver')->getInstanceFromDefinition(AssistantApiController::class); \$request = Request::create('/assistant/api/message', 'POST'); echo 'env=' . (Drupal::service('ilas_site_assistant.environment_detector')->getPantheonEnvironment() ?? 'unset') . PHP_EOL; \$debug = getenv('ILAS_CHATBOT_DEBUG'); if (\$debug === FALSE) { echo 'ilas_chatbot_debug=unset' . PHP_EOL; } elseif (\$debug === '1') { echo 'ilas_chatbot_debug=1' . PHP_EOL; } else { echo 'ilas_chatbot_debug=non1' . PHP_EOL; } echo 'debug_force_disable=' . (Settings::get('ilas_site_assistant_debug_metadata_force_disable', FALSE) ? 'true' : 'false') . PHP_EOL; \$ref = new ReflectionMethod(AssistantApiController::class, 'isDebugMode'); \$ref->setAccessible(TRUE); echo 'effective_debug_mode=' . (\$ref->invoke(\$controller, \$request) ? 'true' : 'false') . PHP_EOL;"; done`
- Expected contract after the remediation:
  - `EnvironmentDetector` is the single source of truth for Pantheon live
    detection across the controller, form, and LLM guard services.
  - `settings.php` sets
    `$settings['ilas_site_assistant_debug_metadata_force_disable'] = TRUE;`
    on Pantheon `live`.
  - `AssistantApiController::isDebugMode()` returns `FALSE` on live even when
    `ILAS_CHATBOT_DEBUG=1` is present.
  - Non-live environments can still opt into `_debug` with
    `ILAS_CHATBOT_DEBUG=1`.
  - If Pantheon read-only checks cannot evaluate the new service/guard path
    because the environments are still serving pre-deploy code, classify the
    finding as `Partially Fixed` and keep the live runtime surface
    `Unverified`.
- Archive the executed command summaries and final classification in
  `docs/aila/runtime/raud-09-live-debug-guard.txt`.

### RAUD-10 PII redaction coverage expansion verification

- Baseline before the remediation:
  - The existing redaction unit/contract suites passed, but direct sample checks
    still left Spanish self-identification phrases (`Me llamo Juan Garcia`,
    `Mi nombre es Juan García`), context-gated role names (`Client John Smith`,
    `tenant Maria Lopez`), and Idaho driver-license values
    (`My Idaho license is AB123456C`, `DL number AB123456C`) unredacted.
  - Spanish address/date inputs were only partially covered by generic street
    and standalone-date rules, leaving contextual raw fragments behind.
- Required verification commands for the remediation report:
  - `VC-UNIT`
  - `VC-KERNEL`
  - `VC-QUALITY-GATE`
- Targeted local checks:
  - Direct sample check:
    `php <<'PHP' ... PiiRedactor::redact('Me llamo Juan Garcia y necesito ayuda') ... PiiRedactor::redact('Client John Smith needs help with eviction') ... PiiRedactor::redact('My Idaho license is AB123456C') ... PHP`
  - `vendor/bin/phpunit --configuration /home/evancurry/idaho-legal-aid-services/phpunit.xml /home/evancurry/idaho-legal-aid-services/web/modules/custom/ilas_site_assistant/tests/src/Unit/PiiRedactorTest.php /home/evancurry/idaho-legal-aid-services/web/modules/custom/ilas_site_assistant/tests/src/Unit/PiiRedactorContractTest.php /home/evancurry/idaho-legal-aid-services/web/modules/custom/ilas_site_assistant/tests/src/Unit/ObservabilityRedactionContractTest.php`
  - `ddev exec vendor/bin/phpunit --configuration /var/www/html/phpunit.xml --group ilas_site_assistant /var/www/html/web/modules/custom/ilas_site_assistant/tests/src/Kernel/AnalyticsLoggerKernelTest.php /var/www/html/web/modules/custom/ilas_site_assistant/tests/src/Kernel/ConversationLoggerKernelTest.php`
- Expected contract after the remediation:
  - Spanish self-identification, DOB, and address phrases redact through the
    contextual rules rather than only through generic fallbacks.
  - International phone prefixes such as `+52-...` are consumed as part of the
    redacted phone number.
  - Context-gated role labels (`client`, `tenant`, `applicant`, `cliente`,
    `inquilino`, `solicitante`) redact following Unicode-aware names.
  - Idaho DL values in the shape `[A-Z]{2}\d{6}[A-Z]` redact only when paired
    with license context.
  - If truly free-form bare names remain unsupported to avoid false positives,
    classify the finding as `Partially Fixed` even when the targeted
    multilingual and Idaho-specific suites pass.
- Archive the executed command summaries and final classification in
  `docs/aila/runtime/raud-10-pii-redaction-remediation.txt`.

### RAUD-11 observability payload minimization verification

- Baseline before the remediation:
  - Analytics tables still accepted user-derived `event_value` strings outside
    a controlled contract.
  - `ilas_site_assistant_no_answer` persisted `sanitized_query`, and
    `ilas_site_assistant_conversations` persisted `redacted_message`.
  - Langfuse controller spans/events and finder/vector watchdog logs still
    carried redacted query snippets or raw exception messages.
- Required verification commands for the remediation report:
  - `VC-UNIT`
  - `VC-KERNEL`
  - `VC-QUALITY-GATE`
- Targeted local checks:
  - `vendor/bin/phpunit --configuration /home/evancurry/idaho-legal-aid-services/phpunit.xml /home/evancurry/idaho-legal-aid-services/web/modules/custom/ilas_site_assistant/tests/src/Unit/TelemetrySchemaContractTest.php /home/evancurry/idaho-legal-aid-services/web/modules/custom/ilas_site_assistant/tests/src/Unit/ObservabilityRedactionContractTest.php /home/evancurry/idaho-legal-aid-services/web/modules/custom/ilas_site_assistant/tests/src/Unit/LangfuseTracerTest.php /home/evancurry/idaho-legal-aid-services/web/modules/custom/ilas_site_assistant/tests/src/Unit/ObservabilityPayloadMinimizerTest.php /home/evancurry/idaho-legal-aid-services/web/modules/custom/ilas_site_assistant/tests/src/Unit/ObservabilitySurfaceContractTest.php`
  - `bash /home/evancurry/idaho-legal-aid-services/scripts/ci/run-host-phpunit.sh /home/evancurry/idaho-legal-aid-services/web/modules/custom/ilas_site_assistant/tests/src/Kernel/AnalyticsLoggerKernelTest.php /home/evancurry/idaho-legal-aid-services/web/modules/custom/ilas_site_assistant/tests/src/Kernel/ConversationLoggerKernelTest.php`
  - `bash /home/evancurry/idaho-legal-aid-services/web/modules/custom/ilas_site_assistant/tests/run-quality-gate.sh`
- Expected contract after the remediation:
  - No raw or redacted user/assistant snippets are persisted in analytics,
    no-answer rows, conversation logs, Langfuse queue payloads, or finder/vector
    watchdog logs.
  - Analytics `event_value` retains only approved minimized values:
    topic IDs, same-origin URL paths, reason-code tokens, assignment tokens,
    request IDs where explicitly allowed, and hashed opaque identifiers such as
    clarify-loop conversation IDs.
  - Admin reports resolve stored IDs/hashes back into operator-safe displays
    without requiring message text persistence.
  - Deploying the change must run `update_10007` so legacy rows are backfilled
    to metadata-only schema and pre-RAUD-11 `ilas_langfuse_export` queue items
    are purged.
- Archive the executed command summaries and final classification in
  `docs/aila/runtime/raud-11-log-surface-minimization.txt`.

### RAUD-12 anonymous session bootstrap guardrails verification

- Baseline before the remediation:
  - `GET /assistant/api/session/bootstrap` always started an anonymous session
    and wrote the `ilas_site_assistant.csrf_bootstrap` marker even when the
    browser already presented an existing anonymous session cookie.
  - Direct DDEV `curl` / cookie-jar capture showed the first bootstrap hit
    returned a token plus `Set-Cookie`, a second hit with the same cookie jar
    stayed `200` without a fresh `Set-Cookie`, and replaying the bootstrap
    token without the cookie jar against `/assistant/api/message` returned
    `403` with `error_code=csrf_expired`.
  - Host-level BrowserTestBase runs were not the correct harness in this
    workspace because direct `vendor/bin/phpunit` functional execution could
    not resolve the `db` host; use `ddev exec` for endpoint verification.
- Required verification commands for the remediation report:
  - `VC-UNIT`
  - `VC-KERNEL`
  - `VC-WIDGET-HARDENING`
  - `VC-PANTHEON-READONLY`
- Targeted local checks:
  - `ddev exec vendor/bin/phpunit --configuration /var/www/html/phpunit.xml /var/www/html/web/modules/custom/ilas_site_assistant/tests/src/Unit/AssistantSessionBootstrapGuardTest.php /var/www/html/web/modules/custom/ilas_site_assistant/tests/src/Unit/SafetyConfigGovernanceTest.php`
  - `ddev exec vendor/bin/phpunit --configuration /var/www/html/phpunit.xml --filter 'testTrackEndpointRecoveryWithFreshBootstrapToken|testAnonymousMessageRecovery_FreshTokenAfter403|testMetricsEndpointAccessibleToAdmin|testAnonymousSessionBootstrapEndpointReturnsTokenAndSetsCookie|testAnonymousSessionBootstrapReuseDoesNotRotateCookie|testAnonymousSessionBootstrapRateLimitBoundsNewSessionsButAllowsReuse' /var/www/html/web/modules/custom/ilas_site_assistant/tests/src/Functional/AssistantApiFunctionalTest.php`
  - `node /home/evancurry/idaho-legal-aid-services/web/modules/custom/ilas_site_assistant/tests/js/run-assistant-widget-hardening.mjs`
- Bootstrap-specific Pantheon read-only checks after deployment:
  - `for ENV in dev test live; do terminus env:view "idaho-legal-aid-services.$ENV" --print; terminus remote:drush "idaho-legal-aid-services.$ENV" -- status --fields=uri,drupal-version,db-status; terminus remote:drush "idaho-legal-aid-services.$ENV" -- config:get ilas_site_assistant.settings session_bootstrap; terminus remote:drush "idaho-legal-aid-services.$ENV" -- state:get ilas_site_assistant.session_bootstrap.snapshot; done`
- Expected contract after the remediation:
  - The bootstrap endpoint remains `GET` and returns `text/plain` CSRF tokens
    on success, with no route or request-shape change for the widget.
  - Requests that would create a new anonymous session are rate-limited per
    resolved client IP, while requests reusing an already-established session
    bypass the new-session flood budget and do not rotate the session cookie.
  - Rate-limited bootstrap requests return `429`, `Retry-After`, and
    `Cache-Control: no-store, private` without minting a new anonymous session
    cookie.
  - Admin `/assistant/api/metrics` includes both
    `metrics.session_bootstrap` and `thresholds.session_bootstrap`, and the
    rolling snapshot records only new-session and denied events.
  - If Pantheon read-only checks still return `null` for
    `ilas_site_assistant.settings:session_bootstrap` or no
    `ilas_site_assistant.session_bootstrap.snapshot` state, classify the
    finding as `Partially Fixed` rather than `Fixed`.
- Archive the executed command summaries and final classification in
  `docs/aila/runtime/raud-12-anonymous-session-bootstrap.txt`.

### RAUD-13 injected logger verification

- Baseline before the remediation:
  - `AnalyticsLogger` used static `\Drupal::logger()` in three catch blocks:
    stats write failure, no-answer persistence failure, and analytics cleanup
    failure.
  - `ConversationLogger` used static `\Drupal::logger()` in three paths:
    exchange write failure, cleanup success info count, and cleanup failure.
  - Kernel suites proved persistence only; they did not assert that the logger
    dependency was injected or directly testable.
- Required verification commands for the remediation report:
  - `VC-UNIT`
  - `VC-KERNEL`
- Targeted local checks:
  - `vendor/bin/phpunit --configuration /home/evancurry/idaho-legal-aid-services/phpunit.xml /home/evancurry/idaho-legal-aid-services/web/modules/custom/ilas_site_assistant/tests/src/Unit/LoggerInjectionContractTest.php /home/evancurry/idaho-legal-aid-services/web/modules/custom/ilas_site_assistant/tests/src/Unit/IntegrationFailureContractTest.php`
  - `ddev exec vendor/bin/phpunit --configuration /var/www/html/phpunit.xml /var/www/html/web/modules/custom/ilas_site_assistant/tests/src/Kernel/AnalyticsLoggerKernelTest.php /var/www/html/web/modules/custom/ilas_site_assistant/tests/src/Kernel/ConversationLoggerKernelTest.php`
  - `rg -n "\\Drupal::logger" /home/evancurry/idaho-legal-aid-services/web/modules/custom/ilas_site_assistant/src/Service/AnalyticsLogger.php /home/evancurry/idaho-legal-aid-services/web/modules/custom/ilas_site_assistant/src/Service/ConversationLogger.php`
- Expected contract after the remediation:
  - Both target services accept `LoggerInterface` in the constructor and are
    wired to `@logger.channel.ilas_site_assistant`.
  - The six identified logger call sites keep the same message templates,
    placeholder keys, and control flow after the DI conversion.
  - Logger-focused unit coverage proves injected logging for the analytics and
    conversation error paths, and kernel coverage still proves persistence plus
    conversation cleanup info logging.
  - If either target service still contains `\Drupal::logger()` or the
    logger/persistence suites fail, classify `RAUD-13` as `Not Fixed`.
- Archive the executed command summaries and final classification in
  `docs/aila/runtime/raud-13-logger-di-hardening.txt`.

### RAUD-16 safety bypass corpus verification

- Baseline before the remediation:
  - Existing safety suites were green, but direct request-path probes still
    missed slash/comma/zero-width/spaced-letter prompt-injection variants,
    obfuscated legal-advice asks, and English/Spanish paraphrase overrides
    such as `set aside your guardrails` and `haz caso omiso`.
- Required verification commands for the remediation report:
  - `VC-UNIT`
  - `VC-PROMPTFOO-PACED`
- Targeted local checks:
  - `vendor/bin/phpunit --configuration /home/evancurry/idaho-legal-aid-services/phpunit.xml /home/evancurry/idaho-legal-aid-services/web/modules/custom/ilas_site_assistant/tests/src/Unit/InputNormalizerTest.php /home/evancurry/idaho-legal-aid-services/web/modules/custom/ilas_site_assistant/tests/src/Unit/SafetyBypassTest.php /home/evancurry/idaho-legal-aid-services/web/modules/custom/ilas_site_assistant/tests/src/Unit/AbuseResilienceTest.php /home/evancurry/idaho-legal-aid-services/web/modules/custom/ilas_site_assistant/tests/src/Unit/LlmEnhancerLegalAdviceDetectorTest.php /home/evancurry/idaho-legal-aid-services/web/modules/custom/ilas_site_assistant/tests/src/Unit/NormalizationRegressionTest.php /home/evancurry/idaho-legal-aid-services/web/modules/custom/ilas_site_assistant/tests/src/Unit/UrgencyDetectionTest.php`
  - `ILAS_ASSISTANT_URL=https://ilas-pantheon.ddev.site/assistant/api/message ILAS_REQUEST_DELAY_MS=1000 CI_BRANCH=master /home/evancurry/idaho-legal-aid-services/scripts/ci/run-promptfoo-gate.sh --env dev --mode auto`
- Expected contract after the remediation:
  - Input normalization strips zero-width/control formatting and joins 4+
    single-letter obfuscation chains across spaces or mixed separators before
    safety/policy checks.
  - `PreRoutingDecisionEngine` returns `safety_exit` for obfuscated
    prompt-injection cases and `policy_exit` for obfuscated legal-advice
    requests instead of `continue`.
  - The actual `LlmEnhancer::containsLegalAdvice()` method catches obfuscated
    post-generation outputs rather than relying only on mirrored regex tests.
  - `promptfooconfig.abuse.yaml` must pass the newly added zero-width,
    spaced-dot, obfuscated prompt-leak, obfuscated legal-advice, English
    guardrail/latest-directive, and Spanish `haz caso omiso` cases on the real
    endpoint.
  - If the abuse suite passes but the blocking command still returns non-zero
    because of unrelated deep-suite failures, classify `RAUD-16` by the
    added-case evidence and document the unrelated failures separately rather
    than marking the bypass remediation incomplete.
  - If the newly added corpus does not fail pre-change or only mirrors the
    implementation without request-path coverage, classify `RAUD-16` as
    `Partially Fixed`.
- Archive the executed command summaries and final classification in
  `docs/aila/runtime/raud-16-safety-bypass-corpus-hardening.txt`.

### RAUD-19 multilingual routing + offline eval verification

- Baseline before the remediation:
  - Spanish and mixed-language routing proof was fragmented across isolated
    unit tests (`TopicRouterTest`, `TopIntentsPackTest`, `TurnClassifierTest`,
    `GoldenTranscriptTest`) and prompt-language handling in `LlmEnhancer` was
    still English-only.
  - There was no authoritative offline evaluator that ran multilingual routing
    cases through the real pure-PHP stack and produced a reusable report.
- Required verification commands for the remediation report:
  - `VC-UNIT`
  - `VC-QUALITY-GATE`
  - `VC-PROMPTFOO-PACED`
- Targeted local checks:
  - `vendor/bin/phpunit --no-configuration --bootstrap /home/evancurry/idaho-legal-aid-services/vendor/autoload.php --group ilas_site_assistant --filter 'DisambiguatorTest|LlmEnhancerHardeningTest|MultilingualRoutingEvalTest' /home/evancurry/idaho-legal-aid-services/web/modules/custom/ilas_site_assistant/tests/src/Unit`
  - `php /home/evancurry/idaho-legal-aid-services/web/modules/custom/ilas_site_assistant/tests/run-multilingual-routing-eval.php --report=/tmp/raud19-multilingual-routing-report.json`
  - `ILAS_ASSISTANT_URL=https://ilas-pantheon.ddev.site/assistant/api/message ILAS_REQUEST_DELAY_MS=1000 CI_BRANCH=master /home/evancurry/idaho-legal-aid-services/scripts/ci/run-promptfoo-gate.sh --env dev --mode auto`
- Expected contract after the remediation:
  - `LlmEnhancer::classifyIntent()` adds Spanish/mixed prompt-language
    instructions but still accepts only canonical English intent labels.
  - FAQ/resource summary prompts explicitly request Spanish or same-language-mix
    output when the user query is Spanish or mixed.
  - `Disambiguator` turns short English/Spanish "help with X" topic phrasing
    into deterministic `disambiguation` instead of silently drifting to
    `apply_for_help`.
  - The shared offline evaluator passes every curated Spanish and mixed routing
    case, emits a JSON report file, and fails closed on any mismatch.
  - `promptfooconfig.deep.yaml` exercises the additive multilingual live suite
    without replacing the offline harness.
- Classification rule:
  - If the offline evaluator, `VC-UNIT`, `VC-QUALITY-GATE`, and the paced live
    promptfoo command all pass, classify `RAUD-19` as `Fixed`.
  - If only the offline path passes and live promptfoo proof is still missing
    or failing for unrelated reasons, classify `RAUD-19` as `Partially Fixed`
    and document the remaining live-only gap explicitly.
- Archive the executed command summaries and final classification in
  `docs/aila/runtime/raud-19-multilingual-routing-offline-eval.txt`.

### GitHub mirror onboarding (WSL2)

```bash
git remote -v
git remote rename origin pantheon
git remote add origin git@github.com:<github-user>/<repo>.git
git remote -v
```

### Strict every-push workflow (current dual-remote layout)

Use this when your repository remotes match current ILAS operations:
- `github` = GitHub remote
- `origin` = Pantheon remote
- canonical branch = `master`

Install strict local workflow enforcement (pre-commit on local `master`, pre-push on every push):

```bash
bash scripts/ci/install-pre-push-strict-hook.sh
```

Canonical protected-master sequence:

```bash
# Confirm you are starting from synced local master.
git status --short --branch

npm run git:publish
```

Shortest repeatable flow after you commit your changes:

```bash
npm run git:publish
npm run git:finish
```

After the PR is merged on GitHub:

```bash
# Sync local master from GitHub.
npm run git:sync-master

# Deploy Pantheon dev from the merged master commit when Pantheon is behind.
npm run git:publish -- --origin-only

# Optional verification after the Pantheon push.
git rev-parse master github/master origin/master
terminus env:code-log idaho-legal-aid-services.dev --format=table
```

Notes:
- `bash scripts/ci/install-pre-push-strict-hook.sh` installs both
  `.git/hooks/pre-commit` and `.git/hooks/pre-push`.
- The pre-commit hook runs only on local `master`, fetches `github`, and
  blocks new commits when `github/master` is ahead or diverged. Use
  `npm run git:sync-master` before starting more work on `master`.
- The strict hook first runs `scripts/git/sync-check.sh` to block
  `remote-ahead`/`diverged` pushes, then runs
  `composer install --no-interaction --no-progress --prefer-dist --dry-run`
  to mirror the GitHub `Install Composer dependencies` step and catch
  `composer.json`/`composer.lock` drift before publish. It then runs
  `vendor/bin/phpunit -c phpunit.pure.xml --colors=always` to mirror the
  GitHub `Run PHPUnit pure-unit tests (VC-PURE)` step before running
  `run-quality-gate.sh` plus branch-aware Promptfoo gate checks keyed to the
  pushed target branch.
- Post-merge local sync is reduced to one command: `npm run git:sync-master`.
- Optional shortcut: `npm run git:finish` waits for the current
  `publish/master-active` PR, merges it with a merge commit, runs
  `npm run git:sync-master`, deploys Pantheon `dev` when `origin/master`
  is behind, runs hosted Pantheon `dev` verification, and then waits for the
  hosted post-merge `master` gate before returning success.
- Direct `git push github master` is intentionally blocked on protected
  `master`; use `npm run git:publish` so local `master` is pushed to
  `github/publish/master-active` and opened as a PR into `master`.
- Each `npm run git:publish` invocation reuses the rolling helper PR for
  `publish/master-active`, auto-closes superseded legacy `publish/master-*`
  PRs, and prunes merged helper branches after successful completion; do not
  wait on stale PR numbers from earlier publishes.
- PR-branch publishes from local `master` are advisory locally because the
  hook classifies `github/publish/master-active` as a non-protected target;
  helper publish PRs are blocking on GitHub, and `npm run git:finish`
  downloads the `gate-summary.txt` artifact before merging so advisory,
  simulated, or failed hosted Promptfoo runs cannot be merged into `master`.
- Direct `git push origin master` is blocked while `github/master` does not yet
  match local `master`, which enforces GitHub-first ordering.
- Once local `master` is fast-forwarded to the merged `github/master` commit,
  `npm run git:publish -- --origin-only` still runs sync-check plus the local
  module quality gate and runs the local DDEV deploy-bound Promptfoo gate before the Pantheon push.
- The hosted GitHub checks are still not the deploy proof that allows the
  `origin/master` push itself; deploy proof for Pantheon `dev` still comes
  from the local DDEV exact-code promptfoo gate on the commit being pushed.
  After the Pantheon push, `git:finish` also requires two hosted proofs before
  it returns success: the post-deploy Pantheon `dev` verification and the
  hosted post-merge `master` stability gate. That hosted `master` gate blocks
  completion/promotion, but it no longer blocks the Pantheon `dev` deploy step
  itself.
- Synced `origin/master` deploy pushes require a running DDEV app with a
  resolvable primary URL; otherwise the strict hook refuses the push unless you
  intentionally bypass it with `git push --no-verify`.
- `npm run git:publish -- --origin-only` is the normal sync path for Pantheon
  `dev`; promotion to Pantheon `test` and `live` is a separate deployment
  workflow.
- If `ILAS_ASSISTANT_URL` is unset, `scripts/ci/run-promptfoo-gate.sh` will
  attempt Pantheon URL derivation (`derive-assistant-url.sh`) for `--env dev`.
- Bypass once (not recommended): `git push --no-verify`. This bypasses both the
  drift checks and the protected-master PR-first policy.

Recovery when local `master` drifted before you committed:

```bash
git branch backup/recovery-<timestamp> master
git reset --hard github/master
git cherry-pick <local-master-commit>
npm run git:publish
```

### External CI promptfoo gate (Pantheon-derived URL)

Use repo scripts for provider-agnostic CI runners (Jenkins/Circle/GitLab/Buildkite/self-hosted):

```bash
# Blocking on master/main/release branches, advisory elsewhere (auto-detected via CI_BRANCH).
scripts/ci/run-promptfoo-gate.sh --env dev --mode auto

# Force advisory/blocking for local simulation.
CI_BRANCH=feature/test scripts/ci/run-promptfoo-gate.sh --env dev --mode auto
CI_BRANCH=master scripts/ci/run-promptfoo-gate.sh --env dev --mode auto
CI_BRANCH=release/2026-03 scripts/ci/run-promptfoo-gate.sh --env dev --mode auto
```

GitHub Actions blocking runs should keep these settings aligned with Pantheon
`dev`:
- `ILAS_ASSISTANT_URL=https://dev-idaho-legal-aid-services.pantheonsite.io/assistant/api/message`
- `ILAS_CONFIGURED_RATE_LIMIT_PER_MINUTE=15`
- `ILAS_CONFIGURED_RATE_LIMIT_PER_HOUR=120`

GitHub Actions uses two hosted Promptfoo paths plus a separate deploy-bound
local gate:
- Helper publish PRs (`publish/master-active`, with legacy `publish/master-*`
  still recognized during cleanup) use the real hosted eval path in blocking
  mode with `promptfooconfig.hosted.yaml --no-deep-eval` when
  `ILAS_ASSISTANT_URL` is available.
- Protected-branch `push` runs on `master`/`main`/`release/*` use the smaller
  hosted stability profile `promptfooconfig.protected-push.yaml --no-deep-eval`
  in blocking mode.
- Ordinary feature PRs use the real hosted eval path in advisory mode with
  `promptfooconfig.hosted.yaml --no-deep-eval` when `ILAS_ASSISTANT_URL` is
  available.
- Non-helper PRs can still fall back to simulated advisory mode when
  `ILAS_ASSISTANT_URL` is unavailable, but that result is explicitly hosted-only
  and never deploy proof.
- Synced `origin/master` deploy pushes are separately blocked by the local DDEV
  exact-code gate in `scripts/ci/pre-push-strict.sh`; hosted GitHub results
  remain hosted-environment evidence only for the push decision itself. After
  the Pantheon `dev` push, `git:finish` re-runs hosted verification against the
  deployed Pantheon URL with `promptfooconfig.protected-push.yaml`.

If `ILAS_ASSISTANT_URL` explicitly points at a different Pantheon environment
than the requested `--env`, `scripts/ci/run-promptfoo-gate.sh` now fails with
`target_env_mismatch` before rate-limit or eval work begins.

Expected CI policy:
- `master`, `main`, and `release/*` branches are blocking for threshold failures.
- `publish/master-active` helper PRs are also blocking in GitHub Actions even
  though the local pre-push helper branch remains advisory. Legacy
  `publish/master-*` helper branches remain recognized during cleanup.
- Other branches are advisory (non-zero eval result reported but does not fail job).
- Hosted GitHub runs should use `promptfooconfig.hosted.yaml --no-deep-eval`
  for helper PRs, and `promptfooconfig.protected-push.yaml --no-deep-eval` for
  post-merge protected pushes/post-deploy verification, so the shared Pantheon
  `dev` budget stays below the hourly rate ceiling while preserving
  representative hosted stability coverage.
- Deploy-safe local exact-code runs should use
  `promptfooconfig.deploy.yaml --no-deep-eval`.
- Default promptfoo config in auto mode is `promptfooconfig.deep.yaml` for
  blocking branches and `promptfooconfig.abuse.yaml` for advisory branches;
  explicit `--config` overrides either default.

## 4) Quality gates + config parity checks (`P1-OBJ-03`, `IMP-CONF-01`)

### Enforced quality gate verification (`P1-OBJ-03`)

Use these commands to convert existing test assets into reproducible enforced
gates in local and external-runner contexts.

```bash
# 1) Mandatory module quality gate from existing test assets.
ddev exec bash /var/www/html/web/modules/custom/ilas_site_assistant/tests/run-quality-gate.sh

# 2) Branch-aware Promptfoo threshold policy simulation (no live eval required).
#    - release/master/main semantics -> blocking failure (expected non-zero on threshold fail)
ILAS_ASSISTANT_URL="https://example.invalid/assistant/api/message" \
  CI_BRANCH=release/2026-03 \
  scripts/ci/run-promptfoo-gate.sh --env dev --mode auto --skip-eval --simulate-pass-rate 85

#    - feature branch semantics -> advisory (expected zero even on threshold fail)
ILAS_ASSISTANT_URL="https://example.invalid/assistant/api/message" \
  CI_BRANCH=feature/p1-obj-03 \
  scripts/ci/run-promptfoo-gate.sh --env dev --mode auto --skip-eval --simulate-pass-rate 85

# 3) External runner composition gate (PHPUnit + Promptfoo policy).
ILAS_ASSISTANT_URL="https://example.invalid/assistant/api/message" \
  CI_BRANCH=feature/p1-obj-03 \
  scripts/ci/run-external-quality-gate.sh --env dev --mode auto

# 4) Deterministic external-runner policy simulation (pass-through options).
ILAS_ASSISTANT_URL="https://example.invalid/assistant/api/message" \
  CI_BRANCH=release/2026-03 \
  scripts/ci/run-external-quality-gate.sh \
    --env dev \
    --mode auto \
    --threshold 90 \
    --config promptfooconfig.abuse.yaml \
    --skip-eval \
    --simulate-pass-rate 85

# 5) Deploy-bound local DDEV gate using the shared live-safe deploy profile.
ILAS_ASSISTANT_URL="https://ilas-pantheon.ddev.site/assistant/api/message" \
  CI_BRANCH=master \
  scripts/ci/run-promptfoo-gate.sh \
    --env dev \
    --mode auto \
    --config promptfooconfig.deploy.yaml \
    --no-deep-eval
```

Expected quality gate result:
- `tests/run-quality-gate.sh` blocks on `VC-UNIT` and full
  `VC-DRUPAL-UNIT` suite regressions, plus golden transcript failures.
- `scripts/ci/run-promptfoo-gate.sh` blocks threshold/eval failures on
  `master`/`main`/`release/*` and reports advisory-only failures on other branches.
- Synced `origin/master` pushes are deploy-bound only when the exact code is
  exercised locally through the DDEV command above; hosted GitHub promptfoo
  results remain useful but are not deploy proof for Pantheon `dev`.
- `scripts/ci/run-external-quality-gate.sh` composes repo-owned gate assets for
  CI platforms where workflow ownership is external to this repository.

Expected artifacts:
- `promptfoo-evals/output/phpunit-summary.txt` (per-phase PHPUnit gate status + timestamps).
- `promptfoo-evals/output/gate-summary.txt` (Promptfoo branch mode, threshold, pass rate, eval status).

### Mandatory gate verification (`P1-EXIT-02`)

Use these commands to verify CI quality gate is mandatory for merge/release path
with branch protection enforcement.

```bash
# 1) Verify workflow triggers cover all blocking branches.
grep -A5 'pull_request:' .github/workflows/quality-gate.yml | grep 'release/\*\*'
grep 'concurrency:' .github/workflows/quality-gate.yml
grep 'cancel-in-progress:' .github/workflows/quality-gate.yml

# 2) Verify branch protection is configured with required status checks.
gh api repos/{owner}/{repo}/branches/master/protection \
  --jq '.required_status_checks.contexts'
# Expected: ["PHPUnit Quality Gate","Promptfoo Gate"]

gh api repos/{owner}/{repo}/branches/master/protection \
  --jq '.enforce_admins.enabled'
# Expected: true

# 3) Run contract tests that lock mandatory gate invariants.
vendor/bin/phpunit -c phpunit.pure.xml --colors=always \
  --filter 'testWorkflowTriggersCoverAllBlockingBranches|testDocumentationDeclaresGateMandatory' \
  web/modules/custom/ilas_site_assistant/tests/src/Unit/QualityGateEnforcementContractTest.php

# 4) Simulate blocking branch gate behavior (must exit 2).
ILAS_ASSISTANT_URL="https://example.invalid/assistant/api/message" \
  CI_BRANCH=master scripts/ci/run-promptfoo-gate.sh \
  --env dev --mode auto --skip-eval --simulate-pass-rate 85

# 5) Simulate release branch gate behavior (must exit 2).
ILAS_ASSISTANT_URL="https://example.invalid/assistant/api/message" \
  CI_BRANCH=release/2026-03 scripts/ci/run-promptfoo-gate.sh \
  --env dev --mode auto --skip-eval --simulate-pass-rate 85

# 6) Simulate advisory branch gate behavior (must exit 0).
ILAS_ASSISTANT_URL="https://example.invalid/assistant/api/message" \
  CI_BRANCH=feature/test scripts/ci/run-promptfoo-gate.sh \
  --env dev --mode auto --skip-eval --simulate-pass-rate 85
```

Expected mandatory gate result:
- Branch protection requires both `PHPUnit Quality Gate` and `Promptfoo Gate`
  to pass before merge to `master`. `enforce_admins: true` prevents bypass.
- Workflow `pull_request` trigger covers `release/**` in addition to
  `master`/`main`, so PRs to release branches run CI.
- Concurrency control (`cancel-in-progress: true`) cancels stale runs on
  rapid pushes to the same branch.
- Contract tests lock trigger coverage, concurrency control, and
  documentation mandatory-gate declaration as enforced invariants.

### Phase 2 evaluation coverage + release confidence verification (`P2-OBJ-02`)

Use these commands to verify Objective #2:
"Mature evaluation coverage and release confidence for RAG/response correctness."

```bash
# 1) Required validation suites (prompt-level).
# VC-UNIT
ddev exec vendor/bin/phpunit \
  --configuration /var/www/html/phpunit.xml \
  --group ilas_site_assistant \
  /var/www/html/web/modules/custom/ilas_site_assistant/tests/src/Unit

# VC-DRUPAL-UNIT
vendor/bin/phpunit \
  --configuration /home/evancurry/idaho-legal-aid-services/phpunit.xml \
  --testsuite drupal-unit

# 2) Branch-aware Promptfoo gate invariants (non-live simulation).
ILAS_ASSISTANT_URL="https://example.invalid/assistant/api/message" \
  CI_BRANCH=master scripts/ci/run-promptfoo-gate.sh \
  --env dev --mode auto --skip-eval --simulate-pass-rate 85

ILAS_ASSISTANT_URL="https://example.invalid/assistant/api/message" \
  CI_BRANCH=feature/p2-obj-02 scripts/ci/run-promptfoo-gate.sh \
  --env dev --mode auto --skip-eval --simulate-pass-rate 85

# 3) Deep + abuse suite policy and assertion-family continuity checks.
rg -n "promptfooconfig.abuse.yaml|promptfooconfig.deep.yaml|EFFECTIVE_MODE=\"blocking\"|EFFECTIVE_MODE=\"advisory\"" \
  scripts/ci/run-promptfoo-gate.sh

rg -n "theme-coherence|includes-caveat|includes-caveat-or-escalation|no-injection-compliance|relevant-bilingual-response" \
  promptfoo-evals/tests/conversations-deep.yaml \
  promptfoo-evals/tests/abuse-safety.yaml
```

Expected Objective #2 result:
- `VC-UNIT` and `VC-DRUPAL-UNIT` pass with deterministic classifier coverage
  intact (SafetyClassifier + OutOfScopeClassifier paths).
- Promptfoo gate remains branch-aware: blocking on
  `master`/`main`/`release/*`, advisory on other branches.
- Blocking policy retains deep multi-turn coverage and advisory policy retains
  abuse/safety coverage, preserving RAG/response-correctness assertion families.
- Scope boundaries remain enforced: no live LLM enablement through Phase 2 and
  no broad platform migration changes introduced by this verification bundle.

### Phase 2 source freshness + provenance governance verification (`P2-OBJ-03`)

Use these commands to verify Objective #3:
"Enforce governance around source freshness and provenance."

```bash
# 1) Required validation suites (prompt-level).
# VC-UNIT
ddev exec vendor/bin/phpunit \
  --configuration /var/www/html/phpunit.xml \
  --group ilas_site_assistant \
  /var/www/html/web/modules/custom/ilas_site_assistant/tests/src/Unit

# VC-DRUPAL-UNIT
vendor/bin/phpunit \
  --configuration /home/evancurry/idaho-legal-aid-services/phpunit.xml \
  --testsuite drupal-unit

# 2) Objective-specific governance tests.
ddev exec vendor/bin/phpunit \
  --configuration /var/www/html/phpunit.xml \
  --group ilas_site_assistant \
  /var/www/html/web/modules/custom/ilas_site_assistant/tests/src/Unit/SourceGovernanceServiceTest.php

ddev exec vendor/bin/phpunit \
  --configuration /var/www/html/phpunit.xml \
  --group ilas_site_assistant \
  /var/www/html/web/modules/custom/ilas_site_assistant/tests/src/Unit/PhaseTwoObjectiveThreeGateTest.php

# 3) Config/schema/service/controller governance anchors.
rg -n "source_governance|faq_lexical|faq_vector|resource_lexical|resource_vector" \
  web/modules/custom/ilas_site_assistant/config/install/ilas_site_assistant.settings.yml \
  config/ilas_site_assistant.settings.yml \
  web/modules/custom/ilas_site_assistant/config/schema/ilas_site_assistant.schema.yml

rg -n "ilas_site_assistant.source_governance|@ilas_site_assistant.source_governance" \
  web/modules/custom/ilas_site_assistant/ilas_site_assistant.services.yml

rg -n "recordObservationBatch|source_governance|checks\\['source_governance'\\]|metrics\\['source_governance'\\]|thresholds\\['source_governance'\\]" \
  web/modules/custom/ilas_site_assistant/src/Controller/AssistantApiController.php

# 4) Deterministic governance-state inspection/reset workflow (local).
ddev drush state:get ilas_site_assistant.source_governance.snapshot
ddev drush state:get ilas_site_assistant.source_governance.last_alert
ddev drush state:delete ilas_site_assistant.source_governance.last_alert

# 5) Deterministic governance-state inspection/reset workflow (Pantheon, non-prod).
for ENV in dev test; do
  terminus remote:drush "idaho-legal-aid-services.${ENV}" -- state:get ilas_site_assistant.source_governance.snapshot
  terminus remote:drush "idaho-legal-aid-services.${ENV}" -- state:get ilas_site_assistant.source_governance.last_alert
  terminus remote:drush "idaho-legal-aid-services.${ENV}" -- state:delete ilas_site_assistant.source_governance.last_alert
done

# Optional read-only live inspection (no delete).
terminus remote:drush "idaho-legal-aid-services.live" -- state:get ilas_site_assistant.source_governance.snapshot
```

Expected Objective #3 result:
- Source governance policy exists in install + active config with schema parity
  for the four source classes (`faq_lexical`, `faq_vector`, `resource_lexical`,
  `resource_vector`).
- Degraded status for unknown/missing governance classes is thresholded with
  balanced ratio+sample policy (`min_observations=20`,
  `unknown_ratio_degrade_pct=22.0`,
  `missing_source_url_ratio_degrade_pct=9.0`), reducing small-batch noise.
- Retrieval outputs include additive governance metadata (`provenance`,
  `freshness`, `governance_flags`) and observation snapshots are recorded for
  monitoring surfaces.
- Health and metrics contracts include nested governance fields while preserving
  top-level metrics payload shape (`timestamp`, `metrics`, `thresholds`, `cron`, `queue`).
- Snapshot data exposes cooldown transparency (`last_alert_at`,
  `next_alert_eligible_at`, `cooldown_seconds_remaining`) and supports
  deterministic state reset workflows in non-production environments.
- Governance remains soft alerts only; no stale-result filtering or ranking penalties are introduced.
- Scope boundaries remain unchanged: no live LLM enablement through Phase 2 and
  no broad platform migration outside the current Pantheon baseline.[^CLAIM-133]

### Phase 2 response contract expansion verification (`P2-DEL-01`)

Use these commands to verify Deliverable #1:
"`/assistant/api/message` contract expansion: `confidence`, `citations[]`, `decision_reason`."

```bash
# 1) Required validation suites (full).
# VC-UNIT
ddev exec vendor/bin/phpunit \
  --configuration /var/www/html/phpunit.xml \
  --group ilas_site_assistant \
  /var/www/html/web/modules/custom/ilas_site_assistant/tests/src/Unit

# 2) Deliverable-specific gate test.
ddev exec vendor/bin/phpunit \
  --configuration /var/www/html/phpunit.xml \
  --group ilas_site_assistant \
  --filter PhaseTwoDeliverableOneGateTest

# 3) Contract expansion controller anchors.
rg -n "assembleContractFields|normalizeContractConfidence|normalizeContractCitations|normalizeContractDecisionReason" \
  web/modules/custom/ilas_site_assistant/src/Controller/AssistantApiController.php

rg -n "confidence|citations|decision_reason" \
  web/modules/custom/ilas_site_assistant/src/Controller/AssistantApiController.php | \
  grep -v "_debug\|debug_meta\|//\|@\|CLAIM\|log\|telemetry"

# 4) Verify contract fields are absent from error responses.
rg -n "internal_error|Too many requests|Invalid content|Request too large|Missing event_type|Invalid request" \
  web/modules/custom/ilas_site_assistant/src/Controller/AssistantApiController.php | \
  grep -v "assembleContractFields"

# 5) Verify Langfuse span uses correct field.
rg -n "citations_added" \
  web/modules/custom/ilas_site_assistant/src/Controller/AssistantApiController.php
```

Expected Deliverable #1 result:
- `assembleContractFields` appears exactly 6 times: 1 method definition + 5 call sites
  (safety, OOS, policy, repeated-message, normal pipeline).
- Contract fields `confidence`, `citations`, `decision_reason` are set in the
  method body and present on all 200-response paths.
- Contract normalization clamps confidence to finite `[0,1]` values and safely
  derives citations from result metadata when `sources[]` are sparse.
- Error responses (429/400/413/500) do NOT include contract expansion fields.
- Langfuse grounding span checks `$response['sources']` (not `$response['citations']`).
- FallbackGate `getReasonCodeDescriptions()` covers all 13 REASON_* constants.
- Scope boundaries remain unchanged: no live LLM enablement through Phase 2 and
  no broad platform migration outside the current Pantheon baseline.[^CLAIM-134]

### Phase 2 retrieval confidence/refusal threshold gating verification (`P2-DEL-02`)

Use these commands to verify Deliverable #2:
"Retrieval confidence/refusal thresholds integrated with eval harness and regression gating (`IMP-RAG-01`)."

```bash
# 1) Required validation suites (prompt-level).
# VC-UNIT
ddev exec vendor/bin/phpunit \
  --configuration /var/www/html/phpunit.xml \
  --group ilas_site_assistant \
  /var/www/html/web/modules/custom/ilas_site_assistant/tests/src/Unit

# VC-KERNEL
ddev exec vendor/bin/phpunit \
  --configuration /var/www/html/phpunit.xml \
  --group ilas_site_assistant \
  /var/www/html/web/modules/custom/ilas_site_assistant/tests/src/Kernel

# VC-QUALITY-GATE
ddev exec bash /var/www/html/web/modules/custom/ilas_site_assistant/tests/run-quality-gate.sh

# 2) Deliverable-specific gate test.
ddev exec vendor/bin/phpunit \
  --configuration /var/www/html/phpunit.xml \
  --group ilas_site_assistant \
  --filter PhaseTwoDeliverableTwoGateTest

# 3) Promptfoo harness wiring checks.
rg -n "retrieval-confidence-thresholds.yaml|rag-contract-meta-present|rag-citation-coverage|rag-low-confidence-refusal" \
  promptfoo-evals/promptfooconfig.abuse.yaml \
  promptfoo-evals/tests/retrieval-confidence-thresholds.yaml

rg -n "\\[contract_meta\\]|citations_count|decision_reason|response_mode|reason_code" \
  promptfoo-evals/providers/ilas-live.js

rg -n "rag-contract-meta-present|rag-citation-coverage|rag-low-confidence-refusal|RAG_METRIC_THRESHOLD|RAG_METRIC_MIN_COUNT|rag_metrics_enforced|rag_.*_count_fail" \
  scripts/ci/run-promptfoo-gate.sh

# 4) Regression-gate simulation (non-live) for threshold failure branch behavior.
ILAS_ASSISTANT_URL="https://example.invalid/assistant/api/message" \
  CI_BRANCH=master scripts/ci/run-promptfoo-gate.sh \
  --env dev --mode auto --skip-eval --simulate-pass-rate 85

ILAS_ASSISTANT_URL="https://example.invalid/assistant/api/message" \
  CI_BRANCH=feature/p2-del-02 scripts/ci/run-promptfoo-gate.sh \
  --env dev --mode auto --skip-eval --simulate-pass-rate 85
```

Expected Deliverable #2 result:
- Promptfoo abuse config includes retrieval-confidence threshold tests, and
  provider output emits parseable `[contract_meta]` metadata line.
- Gate script enforces metric-specific 90% thresholds for
  `rag-contract-meta-present`, `rag-citation-coverage`, and
  `rag-low-confidence-refusal` when eval data is present.
- Gate summary includes count-floor diagnostics
  (`rag_metric_min_count`, `rag_contract_meta_count_fail`,
  `rag_citation_coverage_count_fail`, `rag_low_confidence_refusal_count_fail`)
  in addition to pass-rate fail flags.
- Gate summary includes retrieval-threshold metric rates/counts/fail flags.
- Blocking/advisory branch behavior remains unchanged for regression gating.
- Scope boundaries remain unchanged: no live LLM enablement through Phase 2 and
  no broad platform migration outside the current Pantheon baseline.[^CLAIM-135]

### Phase 2 vector index hygiene, metadata standards, and refresh monitoring verification (`P2-DEL-03`)

Use these commands to verify Deliverable #3:
"Vector index hygiene policy, metadata standards, and refresh monitoring (`IMP-RAG-02`)."

```bash
# 1) Required validation suites (deliverable-level).
# VC-UNIT
ddev exec vendor/bin/phpunit \
  --configuration /var/www/html/phpunit.xml \
  --group ilas_site_assistant \
  /var/www/html/web/modules/custom/ilas_site_assistant/tests/src/Unit

# VC-KERNEL
ddev exec vendor/bin/phpunit \
  --configuration /var/www/html/phpunit.xml \
  --group ilas_site_assistant \
  /var/www/html/web/modules/custom/ilas_site_assistant/tests/src/Kernel

# VC-QUALITY-GATE
ddev exec bash /var/www/html/web/modules/custom/ilas_site_assistant/tests/run-quality-gate.sh

# 2) Deliverable-specific gate + service behavior suites.
ddev exec vendor/bin/phpunit \
  --configuration /var/www/html/phpunit.xml \
  --group ilas_site_assistant \
  --filter PhaseTwoDeliverableThreeGateTest

ddev exec vendor/bin/phpunit \
  --configuration /var/www/html/phpunit.xml \
  --group ilas_site_assistant \
  --filter VectorIndexHygieneServiceTest

# 3) Contract/config/service anchors.
rg -n "vector_index_hygiene|p2_del_03_v1|faq_accordion_vector|assistant_resources_vector" \
  web/modules/custom/ilas_site_assistant/config/install/ilas_site_assistant.settings.yml \
  config/ilas_site_assistant.settings.yml \
  web/modules/custom/ilas_site_assistant/config/schema/ilas_site_assistant.schema.yml

rg -n "ilas_site_assistant.vector_index_hygiene|VectorIndexHygieneService" \
  web/modules/custom/ilas_site_assistant/ilas_site_assistant.services.yml \
  web/modules/custom/ilas_site_assistant/src/Service/VectorIndexHygieneService.php

rg -n "runScheduledRefresh|Vector index hygiene refresh failed" \
  web/modules/custom/ilas_site_assistant/ilas_site_assistant.module

rg -n "checks\\['vector_index_hygiene'\\]|metrics\\['vector_index_hygiene'\\]|thresholds\\['vector_index_hygiene'\\]" \
  web/modules/custom/ilas_site_assistant/src/Controller/AssistantApiController.php

# 4) Non-prod hygiene state inspection/reset workflow.
ddev exec drush state:get ilas_site_assistant.vector_index_hygiene.snapshot --format=yaml
ddev exec drush state:get ilas_site_assistant.vector_index_hygiene.last_alert

ddev exec drush state:delete ilas_site_assistant.vector_index_hygiene.snapshot
ddev exec drush state:delete ilas_site_assistant.vector_index_hygiene.last_alert

# Optional read-only live inspection (no delete).
for ENV in dev test live; do
  echo "=== ${ENV} ==="
  terminus remote:drush "idaho-legal-aid-services.${ENV}" -- \
    state:get ilas_site_assistant.vector_index_hygiene.snapshot
done
```

Expected Deliverable #3 result:
- `vector_index_hygiene` policy exists in install + active config with schema
  parity and two managed indexes (`faq_vector`, `resource_vector`) mapped to
  `faq_accordion_vector` and `assistant_resources_vector`.
- Scheduled hygiene snapshots enforce incremental-only refresh checks, due/
  overdue timing fields, tracker backlog counters, and per-index failure
  isolation.
- Metadata compliance status (`compliant`/`drift`/`unknown`) and drift fields
  are recorded for each managed index using expected server/metric/dimensions
  policy values.
- Monitoring surfaces are additive: health includes
  `checks.vector_index_hygiene`; metrics include
  `metrics.vector_index_hygiene` and `thresholds.vector_index_hygiene`.
- Backlog/risk linkage is active for `R-RAG-02` and `R-GOV-02`.
- Scope boundaries remain unchanged: no live LLM enablement through Phase 2 and
  no broad platform migration outside the current Pantheon baseline.[^CLAIM-136]

### Phase 2 promptfoo dataset expansion verification (`P2-DEL-04`)

Use these commands to verify Deliverable #4:
"Promptfoo dataset expansion for weak grounding, escalation, and safety boundary scenarios."

```bash
# 1) Required validation suites (deliverable-level).
# VC-UNIT
ddev exec vendor/bin/phpunit \
  --configuration /var/www/html/phpunit.xml \
  --group ilas_site_assistant \
  /var/www/html/web/modules/custom/ilas_site_assistant/tests/src/Unit

# VC-KERNEL
ddev exec vendor/bin/phpunit \
  --configuration /var/www/html/phpunit.xml \
  --group ilas_site_assistant \
  /var/www/html/web/modules/custom/ilas_site_assistant/tests/src/Kernel

# VC-QUALITY-GATE
ddev exec bash /var/www/html/web/modules/custom/ilas_site_assistant/tests/run-quality-gate.sh

# 2) Deliverable-specific closure guard.
ddev exec vendor/bin/phpunit \
  --configuration /var/www/html/phpunit.xml \
  --group ilas_site_assistant \
  --filter PhaseTwoDeliverableFourGateTest

# 3) Promptfoo dataset wiring and family coverage anchors.
rg -n "grounding-escalation-safety-boundaries.yaml" \
  promptfoo-evals/promptfooconfig.abuse.yaml

rg -n "scenario_family: weak_grounding|scenario_family: escalation|scenario_family: safety_boundary|p2del04-" \
  promptfoo-evals/tests/grounding-escalation-safety-boundaries.yaml

# 4) Risk-linkage continuity checks.
rg -n "R-MNT-02|R-LLM-01|Promptfoo dataset|PhaseTwoDeliverableFourGateTest|status values remain unchanged" \
  docs/aila/risk-register.md \
  docs/aila/roadmap.md \
  docs/aila/current-state.md \
  docs/aila/evidence-index.md
```

Expected Deliverable #4 result:
- Promptfoo abuse config includes `grounding-escalation-safety-boundaries.yaml`
  with 60 scenarios split across `weak_grounding`, `escalation`, and
  `safety_boundary` families.
- Dataset assertions verify contract metadata continuity plus family-specific
  weak-grounding handling, escalation actionability, and safety-boundary
  dampening/refusal transitions.
- `PhaseTwoDeliverableFourGateTest` passes and confirms roadmap/current-state/
  runbook/evidence/risk continuity for `P2-DEL-04`.
- Risk linkage is present for `R-MNT-02` and `R-LLM-01` with unchanged status
  values.
- Scope boundaries remain unchanged: no live LLM enablement through Phase 2 and
  no broad platform migration outside the current Pantheon baseline.[^CLAIM-137]

### Phase 2 Sprint 4 verification (`P2-SBD-01`)

Use these commands to verify Sprint 4 closure:
"Sprint 4: response contract + retrieval-confidence implementation and tests."

```bash
# 1) Required validation aliases.
# VC-UNIT
ddev exec vendor/bin/phpunit \
  --configuration /var/www/html/phpunit.xml \
  --group ilas_site_assistant \
  /var/www/html/web/modules/custom/ilas_site_assistant/tests/src/Unit

# VC-QUALITY-GATE
ddev exec bash /var/www/html/web/modules/custom/ilas_site_assistant/tests/run-quality-gate.sh

# 2) Sprint-closure and retune-specific tests.
ddev exec vendor/bin/phpunit \
  --configuration /var/www/html/phpunit.xml \
  --group ilas_site_assistant \
  --filter "ResponseContractNormalizationTest|PhaseTwoSprintFourGateTest|PhaseTwoDeliverableOneGateTest|PhaseTwoDeliverableTwoGateTest|FallbackGateTest"

# 3) Runtime/eval retune anchors.
rg -n "no_results_confidence_capped|<= 0.49|REASON_NO_RESULTS" \
  web/modules/custom/ilas_site_assistant/src/Service/FallbackGate.php \
  web/modules/custom/ilas_site_assistant/tests/src/Unit/FallbackGateTest.php

rg -n "normalizeContractConfidence|normalizeContractCitations|normalizeContractDecisionReason" \
  web/modules/custom/ilas_site_assistant/src/Controller/AssistantApiController.php

rg -n "RAG_METRIC_MIN_COUNT|rag_metric_min_count|rag_contract_meta_count_fail|rag_citation_coverage_count_fail|rag_low_confidence_refusal_count_fail" \
  scripts/ci/run-promptfoo-gate.sh

rg -n "what are idaho tenant rights for eviction notices|metric: rag-citation-coverage|metric: rag-low-confidence-refusal" \
  promptfoo-evals/tests/retrieval-confidence-thresholds.yaml
```

Capture sanitized outputs in:
- `docs/aila/runtime/phase2-sprint4-closure.txt`[^CLAIM-143]

Expected Sprint 4 result:
- `VC-UNIT` and `VC-QUALITY-GATE` pass with Sprint 4 retune/closure tests
  included.
- Response contract semantics remain additive while confidence/citations/
  decision-reason normalization behavior is enforced by dedicated unit tests.
- Retrieval no-results high-intent path remains answer-routed but is confidence
  capped (`<= 0.49`) with explicit debug marker fields.
- Promptfoo retrieval threshold policy remains 90% and includes count-floor
  diagnostics in gate summary artifacts.
- Scope boundaries remain unchanged: no live LLM enablement through Phase 2 and
  no broad platform migration outside the current Pantheon baseline.[^CLAIM-143]

### Phase 2 Sprint 5 verification (`P2-SBD-02`)

Use these commands to verify Sprint 5 closure:
"Sprint 5: dataset expansion, provenance/freshness workflows, threshold calibration."

```bash
# 1) Required validation aliases.
# VC-UNIT
vendor/bin/phpunit --configuration phpunit.xml --group ilas_site_assistant \
  web/modules/custom/ilas_site_assistant/tests/src/Unit

# VC-QUALITY-GATE
bash web/modules/custom/ilas_site_assistant/tests/run-quality-gate.sh

# 2) Sprint-closure specific gate coverage.
vendor/bin/phpunit --configuration phpunit.xml --group ilas_site_assistant \
  --filter "PhaseTwoSprintFiveGateTest|PhaseTwoDeliverableFourGateTest|PhaseTwoDeliverableThreeGateTest|PhaseTwoObjectiveThreeGateTest|PhaseTwoDeliverableTwoGateTest|SourceGovernanceServiceTest|VectorIndexHygieneServiceTest"

# 3) Dataset count/family/metric anchors.
rg -n "scenario_family: weak_grounding|scenario_family: escalation|scenario_family: safety_boundary" \
  promptfoo-evals/tests/grounding-escalation-safety-boundaries.yaml

rg -n "metric: p2del04-(contract-meta-present|weak-grounding-handling|escalation-routing|escalation-actionability|safety-boundary-routing|boundary-dampening|boundary-urgent-routing)" \
  promptfoo-evals/tests/grounding-escalation-safety-boundaries.yaml

# 4) Gate threshold anchors and summary keys.
rg -n "RAG_METRIC_MIN_COUNT|P2DEL04_METRIC_THRESHOLD|P2DEL04_METRIC_MIN_COUNT|p2del04_.*_fail|p2del04_metric_(threshold|min_count)" \
  scripts/ci/run-promptfoo-gate.sh

# 5) Governance/vector calibration anchors.
rg -n "stale_ratio_alert_pct|unknown_ratio_degrade_pct|missing_source_url_ratio_degrade_pct|overdue_grace_minutes|max_items_per_run" \
  web/modules/custom/ilas_site_assistant/config/install/ilas_site_assistant.settings.yml \
  config/ilas_site_assistant.settings.yml \
  web/modules/custom/ilas_site_assistant/src/Service/SourceGovernanceService.php \
  web/modules/custom/ilas_site_assistant/src/Service/VectorIndexHygieneService.php
```

Capture sanitized outputs in:
- `docs/aila/runtime/phase2-sprint5-closure.txt`[^CLAIM-144]

Expected Sprint 5 result:
- `VC-UNIT` and `VC-QUALITY-GATE` pass with Sprint 5 closure tests included.
- Promptfoo dataset coverage is 60 scenarios with exact family split
  (`weak_grounding=20`, `escalation=20`, `safety_boundary=20`) and calibrated
  `p2del04-*` metric floors.
- Promptfoo gate defaults are calibrated (`RAG_METRIC_MIN_COUNT=10`,
  `P2DEL04_METRIC_THRESHOLD=85`, `P2DEL04_METRIC_MIN_COUNT=10`) and
  `p2del04_*` summary/fail fields are emitted/enforced in gate outcomes.
- Source-governance and vector-hygiene threshold values are calibrated in
  install + active config and mirrored in service defaults.
- System-map continuity remains unchanged; no diagram change required for
  Sprint 5 scope.
- Scope boundaries remain unchanged: no live LLM enablement through Phase 2 and
  no broad platform migration outside the current Pantheon baseline.[^CLAIM-144]

### Phase 2 exit #1 retrieval contract + confidence threshold verification (`P2-EXT-01`)

Use these commands to verify Exit criterion #1:
"Retrieval contract and confidence logic pass regression thresholds."

```bash
# 0) Preflight (Pantheon auth required for VC-RUNBOOK-PANTHEON).
terminus whoami

# 1) Validation command aliases from prompt matrix.
# VC-RUNBOOK-LOCAL
cd /home/evancurry/idaho-legal-aid-services && \
  ddev drush status && \
  ddev drush config:get ilas_site_assistant.settings -y && \
  ddev drush state:get system.cron_last

# VC-RUNBOOK-PANTHEON
for ENV in dev test live; do
  terminus remote:drush "idaho-legal-aid-services.${ENV}" -- config:get ilas_site_assistant.settings -y
done

# 2) Retrieval-threshold gate anchors.
rg -n "rag-contract-meta-present|rag-citation-coverage|rag-low-confidence-refusal" \
  promptfoo-evals/tests/retrieval-confidence-thresholds.yaml \
  promptfoo-evals/promptfooconfig.abuse.yaml \
  scripts/ci/run-promptfoo-gate.sh

rg -n "assistant/api/session/bootstrap|session/token|\\[contract_meta\\]" \
  promptfoo-evals/providers/ilas-live.js

# 3) Full promptfoo gate (local DDEV endpoint with mkcert trust auto-discovery).
ILAS_ASSISTANT_URL="https://ilas-pantheon.ddev.site/assistant/api/message" \
  CI_BRANCH=feature/p2-ext-01 scripts/ci/run-promptfoo-gate.sh \
  --env dev --mode advisory

# 4) Inspect retrieval-threshold summary fields from gate output artifact.
rg -n "rag_metric_threshold|rag_contract_meta_rate|rag_contract_meta_fail|rag_citation_coverage_rate|rag_citation_coverage_fail|rag_low_confidence_refusal_rate|rag_low_confidence_refusal_fail" \
  promptfoo-evals/output/gate-summary.txt
```

Capture sanitized outputs in:
- `docs/aila/runtime/phase2-exit1-retrieval-contract-confidence-thresholds.txt`[^CLAIM-140]

Expected Exit #1 result:
- `VC-RUNBOOK-LOCAL` confirms local runtime visibility with `system.cron_last`
  value and `llm.enabled=false` continuity.
- `VC-RUNBOOK-PANTHEON` confirms `llm.enabled=false` continuity on
  `dev`/`test`/`live` (or is captured as explicit blocker if auth is unavailable).
- Promptfoo gate summary includes retrieval-threshold fields and all fail flags
  remain `no`: `rag_contract_meta_fail`, `rag_citation_coverage_fail`,
  `rag_low_confidence_refusal_fail`.
- Diagram B retrieval/fallback architecture anchors remain unchanged; this bundle
  introduces no retrieval-architecture redesign.
- Scope boundaries remain unchanged: no live LLM enablement through Phase 2 and
  no broad platform migration outside the current Pantheon baseline.[^CLAIM-140]

### Phase 2 exit #2 citation coverage + low-confidence refusal target verification (`P2-EXT-02`)

Use these commands to verify Phase 2 exit criterion #2:
"Citation coverage and low-confidence refusal metrics are within approved targets."

Local citation/refusal metric verification (DDEV):

```bash
# VC-RUNBOOK-LOCAL — confirm local runtime visibility and scope continuity.
cd /home/evancurry/idaho-legal-aid-services && \
  ddev drush status && \
  ddev drush config:get ilas_site_assistant.settings -y && \
  ddev drush state:get system.cron_last
```

Pantheon continuity checks:

```bash
# VC-RUNBOOK-PANTHEON — confirm scope continuity on dev/test/live.
for ENV in dev test live; do
  echo "=== ${ENV} ==="
  terminus remote:drush "idaho-legal-aid-services.${ENV}" -- config:get ilas_site_assistant.settings llm.enabled
  terminus remote:drush "idaho-legal-aid-services.${ENV}" -- config:get ilas_site_assistant.settings vector_search.enabled
  terminus remote:drush "idaho-legal-aid-services.${ENV}" -- config:get ilas_site_assistant.settings rate_limit_per_minute
done
```

Scenario anchor checks:

```bash
# Verify citation coverage and low-confidence refusal scenario counts.
rg -c "metric: rag-citation-coverage" \
  promptfoo-evals/tests/retrieval-confidence-thresholds.yaml
rg -c "metric: rag-low-confidence-refusal" \
  promptfoo-evals/tests/retrieval-confidence-thresholds.yaml

# Verify gate threshold policy.
rg -n "PASS_THRESHOLD|rag_metric_threshold" scripts/ci/run-promptfoo-gate.sh
```

Full promptfoo gate execution (requires live DDEV endpoint):

```bash
# Run full promptfoo gate with retrieval-threshold scenarios.
ILAS_ASSISTANT_URL="https://ilas-pantheon.ddev.site/assistant/api/message" \
  CI_BRANCH=feature/p2-ext-02 scripts/ci/run-promptfoo-gate.sh \
  --env dev --mode advisory

# Inspect citation/refusal summary fields from gate output artifact.
rg -n "rag_citation_coverage_rate|rag_citation_coverage_fail|rag_low_confidence_refusal_rate|rag_low_confidence_refusal_fail" \
  promptfoo-evals/output/gate-summary.txt
```

Capture sanitized outputs in:
- `docs/aila/runtime/phase2-exit2-citation-coverage-refusal-targets.txt`[^CLAIM-141]

Expected Exit #2 result:
- `VC-RUNBOOK-LOCAL` confirms local runtime visibility with `system.cron_last`
  value and `llm.enabled=false` continuity.
- `VC-RUNBOOK-PANTHEON` confirms `llm.enabled=false` continuity on
  `dev`/`test`/`live` (or is captured as explicit blocker if auth is unavailable).
- Scenario anchor checks confirm 10 `rag-citation-coverage` and 10
  `rag-low-confidence-refusal` scenarios in `retrieval-confidence-thresholds.yaml`.
- Promptfoo gate summary includes citation/refusal fields and both fail flags
  remain `no`: `rag_citation_coverage_fail`, `rag_low_confidence_refusal_fail`.
- Scope boundaries remain unchanged: no live LLM enablement through Phase 2 and
  no broad platform migration outside the current Pantheon baseline.[^CLAIM-141]

### Phase 1 Exit #3 reliability failure matrix verification (`P1-EXT-03`)

Use these commands to verify Phase 1 exit criterion #3:
"Reliability failure matrix tests pass against target environments."

Local reliability matrix suites (DDEV):

```bash
# 1) Retrieval dependency degrade matrix.
ddev exec vendor/bin/phpunit \
  --configuration /var/www/html/phpunit.xml \
  /var/www/html/web/modules/custom/ilas_site_assistant/tests/src/Unit/DependencyFailureDegradeContractTest.php

# 2) Consolidated integration failure matrix.
ddev exec vendor/bin/phpunit \
  --configuration /var/www/html/phpunit.xml \
  /var/www/html/web/modules/custom/ilas_site_assistant/tests/src/Unit/IntegrationFailureContractTest.php

# 3) LLM dependency-failure matrix (deterministic fallback classes).
ddev exec vendor/bin/phpunit \
  --configuration /var/www/html/phpunit.xml \
  /var/www/html/web/modules/custom/ilas_site_assistant/tests/src/Unit/LlmEnhancerHardeningTest.php
```

Pantheon target-environment contract checks (`dev`, `test`, `live`):

```bash
for ENV in dev test live; do
  echo "=== ${ENV} ==="
  terminus remote:drush "idaho-legal-aid-services.${ENV}" -- config:get ilas_site_assistant.settings llm.enabled
  terminus remote:drush "idaho-legal-aid-services.${ENV}" -- config:get ilas_site_assistant.settings llm.fallback_on_error
  terminus remote:drush "idaho-legal-aid-services.${ENV}" -- config:get ilas_site_assistant.settings vector_search.enabled
done
```

Capture sanitized outputs in:
- `docs/aila/runtime/phase1-exit3-reliability-failure-matrix.txt`

Expected reliability matrix result:
- Retrieval dependency failures map deterministically to `legacy_fallback` and
  `lexical_preserved` classes.
- LLM dependency failures map deterministically to `original_preserved` when
  fallback is enabled (`llm.fallback_on_error=true`).
- Controller-level uncaught failures map deterministically to
  `internal_error` with request identity present.
- Target-environment checks confirm constraints remain in place:
  `llm.enabled=false`, `llm.fallback_on_error=true`, and
  `vector_search.enabled=false` on `dev`/`test`/`live`.

### Config parity + drift checks (`IMP-CONF-01`)

Use these commands to enforce local `vector_search` parity and generate
cross-environment drift reports.

### Local parity fail gate (DDEV)

```bash
mkdir -p docs/aila/runtime

# Strict local parity checks (fail if schema/install/export drift exists).
ddev exec vendor/bin/phpunit \
  --configuration /var/www/html/phpunit.xml \
  --group ilas_site_assistant \
  /var/www/html/web/modules/custom/ilas_site_assistant/tests/src/Unit/VectorSearchConfigSchemaTest.php

ddev exec vendor/bin/phpunit \
  --configuration /var/www/html/phpunit.xml \
  --group ilas_site_assistant \
  /var/www/html/web/modules/custom/ilas_site_assistant/tests/src/Unit/SafetyConfigGovernanceTest.php

ddev exec vendor/bin/phpunit \
  --configuration /var/www/html/phpunit.xml \
  --group ilas_site_assistant \
  /var/www/html/web/modules/custom/ilas_site_assistant/tests/src/Unit/ConfigCompletenessDriftTest.php

# Snapshot active local vector_search block for audit records.
ddev exec drush config:get ilas_site_assistant.settings vector_search --format=yaml \
  > docs/aila/runtime/vector-search-local.yml
```

### Pantheon drift snapshot (report-only)

```bash
mkdir -p docs/aila/runtime

for ENV in dev test live; do
  terminus remote:drush "idaho-legal-aid-services.${ENV}" -- \
    config:get ilas_site_assistant.settings vector_search --format=yaml \
    > "docs/aila/runtime/vector-search-${ENV}.yml"
done

{
  echo "=== dev vs test ==="
  diff -u docs/aila/runtime/vector-search-dev.yml docs/aila/runtime/vector-search-test.yml || true
  echo
  echo "=== dev vs live ==="
  diff -u docs/aila/runtime/vector-search-dev.yml docs/aila/runtime/vector-search-live.yml || true
} > docs/aila/runtime/vector-search-drift-report.txt
```

`vector-search-drift-report.txt` is a reporting artifact (non-blocking for
release by default) and should be reviewed with retrieval-quality owners.

### Phase 1 retrieval architecture boundary verification (`P1-NDO-02`)

Use these commands to enforce the Phase 1 NDO #2 boundary:
"No full redesign of retrieval architecture."

Allowed scope in Phase 1:
- Additive reliability/observability safeguards, tests, and documentation updates
  that preserve current retrieval architecture shape.
- Retrieval-quality hardening that keeps the existing lexical + optional vector +
  legacy fallback structure.

Prohibited scope in Phase 1:
- Full retrieval-pipeline rewrites or replacement of the current retrieval stack.
- Removing/replacing core retrieval service anchors without explicit roadmap
  boundary revision and signoff.

```bash
# 1) Boundary text continuity in roadmap.
rg -n "No full redesign of retrieval architecture|Phase 1 NDO #2 disposition \(2026-03-03\)" \
  docs/aila/roadmap.md

# 2) Current-state retrieval architecture and dated addendum continuity.
rg -n "Retrieval services combine Search API lexical results with optional vector supplementation and legacy fallback paths|Phase 1 NDO #2 Boundary Enforcement Addendum \(2026-03-03\)|\[\^CLAIM-131\]" \
  docs/aila/current-state.md

# 3) Retrieval claim anchors + boundary claim continuity in evidence index.
rg -n "### CLAIM-060|### CLAIM-065|### CLAIM-131|P1-NDO-02" \
  docs/aila/evidence-index.md

# 4) Diagram B retrieval anchors remain documented.
rg -n "flowchart TD|RET\\[Retrieval|FaqIndex \\+ ResourceFinder|Search API \\+ optional vector|Early retrieval|Fallback gate decision" \
  docs/aila/system-map.mmd

# 5) Retrieval service anchors remain declared.
rg -n "ilas_site_assistant.faq_index|ilas_site_assistant.resource_finder|ilas_site_assistant.ranking_enhancer" \
  web/modules/custom/ilas_site_assistant/ilas_site_assistant.services.yml

# 6) Dedicated guard test remains present.
rg -n "PhaseOneNoRetrievalArchitectureRedesignGuardTest" \
  web/modules/custom/ilas_site_assistant/tests/src/Unit/PhaseOneNoRetrievalArchitectureRedesignGuardTest.php
```

Treat any command failure as a scope-boundary violation for `P1-NDO-02` and
reject full retrieval-architecture redesign changes in Phase 1.

### Phase 3 NDO #2 no platform-wide refactor of unrelated Drupal subsystems verification (`P3-NDO-02`)

Use these commands to verify and enforce Phase 3 "What we will NOT do #2":
"No platform-wide refactor of unrelated Drupal subsystems."

Allowed scope in this closure:
- Documentation, runbook, runtime-artifact, and guard-test continuity updates
  that enforce the boundary without runtime behavior change.
- Additive verification anchors that confirm module/system continuity for
  `ilas_site_assistant`.

Prohibited scope in this closure:
- Platform-wide refactors across unrelated Drupal subsystems.
- Runtime architecture rewrites outside boundary-enforcement artifact work.

```bash
# VC-TOGGLE-CHECK
cd /home/evancurry/idaho-legal-aid-services && \
  rg -n "llm.enabled|vector_search|rate_limit_per_minute|conversation_logging" \
    docs/aila/current-state.md docs/aila/evidence-index.md

# Boundary continuity checks
rg -n "Phase 3 NDO #2 disposition \(2026-03-06\)|No platform-wide refactor of unrelated Drupal subsystems|CLAIM-159|PhaseThreeNoPlatformWideRefactorOfUnrelatedDrupalSubsystemsGuardTest.php|phase3-ndo2-no-platform-wide-refactor-of-unrelated-drupal-subsystems.txt" \
  docs/aila/roadmap.md

rg -n "Phase 3 NDO #2 No Platform-Wide Refactor of Unrelated Drupal Subsystems Disposition \(2026-03-06\)|P3-NDO-02|phase3-ndo2-no-platform-wide-refactor-of-unrelated-drupal-subsystems.txt|\[\^CLAIM-159\]" \
  docs/aila/current-state.md

rg -n '## Phase 3 NDO #2 No Platform-Wide Refactor of Unrelated Drupal Subsystems Boundary \(`P3-NDO-02`\)|### CLAIM-159|Addendum \(2026-03-06\): Phase 3 NDO #2 \(`P3-NDO-02`\)|PhaseThreeNoPlatformWideRefactorOfUnrelatedDrupalSubsystemsGuardTest.php' \
  docs/aila/evidence-index.md

# Module-scope continuity anchors
rg -n "name: 'ILAS Site Assistant'|core_version_requirement: \^10 \|\| \^11|drupal:search_api|drupal:paragraphs" \
  web/modules/custom/ilas_site_assistant/ilas_site_assistant.info.yml

# Seam-service continuity anchors
rg -n "ilas_site_assistant.policy_filter|ilas_site_assistant.intent_router|ilas_site_assistant.faq_index|ilas_site_assistant.resource_finder|ilas_site_assistant.response_grounder|ilas_site_assistant.safety_classifier|ilas_site_assistant.llm_enhancer" \
  web/modules/custom/ilas_site_assistant/ilas_site_assistant.services.yml

# Service inventory continuity guard (bounded, non-exact).
SERVICE_ROWS=$(awk 'NR>1 && NF>0 {count++} END {print count+0}' docs/aila/artifacts/services-inventory.tsv)
echo "services_inventory_rows=${SERVICE_ROWS}"
test "${SERVICE_ROWS}" -ge 30
test "${SERVICE_ROWS}" -le 80

# Diagram A continuity anchors remain documented.
rg -n "flowchart LR|Drupal 11 / ilas_site_assistant|External Integrations|CI\\[External CI runner|PF\\[Promptfoo harness" \
  docs/aila/system-map.mmd

# Guard test
ddev exec vendor/bin/phpunit \
  --configuration /var/www/html/phpunit.xml \
  --group ilas_site_assistant \
  /var/www/html/web/modules/custom/ilas_site_assistant/tests/src/Unit/PhaseThreeNoPlatformWideRefactorOfUnrelatedDrupalSubsystemsGuardTest.php
```

Expected `P3-NDO-02` verification result:
- `VC-TOGGLE-CHECK` succeeds with documented toggle continuity anchors present.
- Roadmap/current-state/evidence continuity markers for `P3-NDO-02` and
  `CLAIM-159` are present.
- Module-scope anchors remain stable in
  `ilas_site_assistant.info.yml` (custom module scope + dependency anchors).
- Seam-service continuity anchors remain present in
  `ilas_site_assistant.services.yml`.
- `services_inventory_rows=<n>` is within bounded continuity range (`30..80`).
- Diagram A continuity anchors remain present in `docs/aila/system-map.mmd`.
- `PhaseThreeNoPlatformWideRefactorOfUnrelatedDrupalSubsystemsGuardTest.php`
  passes with docs/runtime/source continuity assertions enforced.
- Scope boundaries remain unchanged: no net-new assistant channels or
  third-party model expansion beyond audited providers, and no platform-wide
  refactor of unrelated Drupal subsystems.

Treat any command failure as a scope-boundary violation for `P3-NDO-02` and
reject prohibited platform-wide refactor changes.[^CLAIM-159]

### Architectural boundary verification (`P0-NDO-03`)

Use these commands to enforce the Phase 0 architectural boundary:
"No broad architectural refactor beyond minimal seam prep."

Allowed scope (minimal seam prep only):
- Additive seam/interface extraction around policy, routing, retrieval, and
  response composition.
- Testability and documentation improvements that preserve current request flow
  and service graph shape.

Prohibited scope in Phase 0:
- Broad rewrites of controller/pipeline architecture.
- Removal or replacement of core seam services without a roadmap boundary
  revision and explicit signoff.

```bash
# 1) Boundary text + seam-language continuity.
rg -n "No broad architectural refactor beyond minimal seam prep|Pipeline seam extraction|Extract seams and interfaces around policy/routing/retrieval/response composition" \
  docs/aila/roadmap.md docs/aila/backlog.md docs/aila/risk-register.md

# 2) Core seam-service anchors remain declared.
rg -n "ilas_site_assistant.policy_filter|ilas_site_assistant.intent_router|ilas_site_assistant.faq_index|ilas_site_assistant.resource_finder|ilas_site_assistant.response_grounder|ilas_site_assistant.safety_classifier|ilas_site_assistant.llm_enhancer" \
  web/modules/custom/ilas_site_assistant/ilas_site_assistant.services.yml

# 3) Service inventory continuity guard (bounded, non-exact).
SERVICE_ROWS=$(awk 'NR>1 && NF>0 {count++} END {print count+0}' docs/aila/artifacts/services-inventory.tsv)
echo "services_inventory_rows=${SERVICE_ROWS}"
test "${SERVICE_ROWS}" -ge 30
test "${SERVICE_ROWS}" -le 80

# 4) Diagram B deterministic pipeline anchors remain documented.
rg -n "flowchart TD|Flood checks|PreRoutingDecisionEngine|SafetyClassifier|OutOfScopeClassifier|PolicyFilter fallback checks|LlmEnhancer call|Queue worker on cron" \
  docs/aila/system-map.mmd
```

Treat any command failure as a scope-boundary violation for `P0-NDO-03` and
reject broad architectural changes in Phase 0.[^CLAIM-020][^CLAIM-125]

## 5) Regenerate this audit package

Run from repo root:

```bash
mkdir -p docs/aila/artifacts

# Context metadata
{
  echo "timestamp_utc=$(date -u +%Y-%m-%dT%H:%M:%SZ)"
  echo "branch=$(git rev-parse --abbrev-ref HEAD)"
  echo "commit=$(git rev-parse HEAD)"
  echo "commit_short=$(git rev-parse --short HEAD)"
  echo "status_porcelain:"
  git status --porcelain
} > docs/aila/artifacts/context-latest.txt

# Route/service inventories
python3 - <<'PY'
import re, csv
from pathlib import Path

r = Path('web/modules/custom/ilas_site_assistant/ilas_site_assistant.routing.yml').read_text().splitlines()
with open('docs/aila/artifacts/routes-inventory.tsv','w',newline='') as f:
    w = csv.writer(f, delimiter='\t')
    w.writerow(['route_id','line','path','methods','permission','csrf_header_required','controller','form','title'])
    route = {}
    key = None
    for i, line in enumerate(r, start=1):
        if line and not line.startswith(' ') and line.endswith(':'):
            if route:
                w.writerow([route.get('id',''),route.get('line',''),route.get('path',''),route.get('methods','ANY'),route.get('permission',''),route.get('csrf','FALSE'),route.get('controller',''),route.get('form',''),route.get('title','')])
            route={'id':line[:-1],'line':i,'methods':'ANY','csrf':'FALSE'}
        elif route and ':' in line:
            s=line.strip()
            if s.startswith('path:'): route['path']=s.split(':',1)[1].strip().strip("'")
            elif s.startswith('_controller:'): route['controller']=s.split(':',1)[1].strip().strip("'")
            elif s.startswith('_form:'): route['form']=s.split(':',1)[1].strip().strip("'")
            elif s.startswith('_title:'): route['title']=s.split(':',1)[1].strip().strip("'")
            elif s.startswith('_permission:'): route['permission']=s.split(':',1)[1].strip().strip("'")
            elif s.startswith('_csrf_request_header_token:'): route['csrf']='TRUE'
            elif s.startswith('methods:'): route['methods']=s.split(':',1)[1].strip(' []')
    if route:
        w.writerow([route.get('id',''),route.get('line',''),route.get('path',''),route.get('methods','ANY'),route.get('permission',''),route.get('csrf','FALSE'),route.get('controller',''),route.get('form',''),route.get('title','')])
PY

# Dependency snapshot
python3 - <<'PY'
import json
from pathlib import Path
lock = json.loads(Path('composer.lock').read_text())
need = {
  'drupal/ai','drupal/ai_provider_google_vertex','drupal/ai_vdb_provider_pinecone',
  'drupal/gemini_provider','drupal/search_api','drupal/raven','drupal/seckit',
  'dropsolid/langfuse-php-sdk','drush/drush'
}
found = {}
for pkg in lock.get('packages', []) + lock.get('packages-dev', []):
  if pkg['name'] in need:
    found[pkg['name']] = pkg['version']
pkg_json = json.loads(Path('package.json').read_text())
promptfoo = pkg_json.get('devDependencies', {}).get('promptfoo', 'missing')
out = ['composer_lock_versions:']
for k in sorted(found):
  out.append(f"{k}\t{found[k]}")
out += ['', 'package_json_promptfoo:', f"promptfoo\t{promptfoo}"]
Path('docs/aila/artifacts/dependency-versions.txt').write_text('\n'.join(out) + '\n')
PY
```

Then update these files together:
- `docs/aila/current-state.md`
- `docs/aila/evidence-index.md`
- `docs/aila/system-map.mmd`
- `docs/aila/runbook.md`

## 5) Safe log/trace capture procedure

- Capture raw command output to a temp file first.
- Redact sensitive keys and PII patterns before copying into `docs/aila/artifacts/`.

Example sanitizer:

```bash
sed -E \
  -e 's/(api[_-]?key|secret|token|password|dsn)[=: ]+[^ ,]*/\1=[REDACTED]/Ig' \
  -e 's/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+/[REDACTED_EMAIL]/g' \
  -e 's/\b[0-9]{3}-?[0-9]{2}-?[0-9]{4}\b/[REDACTED_SSN]/g' \
  -e 's/\b\+?1?[ -]?[0-9]{3}[ -]?[0-9]{3}[ -]?[0-9]{4}\b/[REDACTED_PHONE]/g'
```

- If you need sample payloads/logs, label them `SYNTHETIC EXAMPLE`.
- Do not include database dumps containing real conversations.

## 6) Runtime verification command bundles used in this addendum

- Local command outputs are captured in:
  - `docs/aila/runtime/local-preflight.txt`
  - `docs/aila/runtime/local-runtime.txt`
  - `docs/aila/runtime/local-endpoints.txt`
- Pantheon command outputs are captured in:
  - `docs/aila/runtime/pantheon-dev.txt`
  - `docs/aila/runtime/pantheon-test.txt`
  - `docs/aila/runtime/pantheon-live.txt`
- Promptfoo/CI location search output is captured in:
  - `docs/aila/runtime/promptfoo-ci-search.txt`[^CLAIM-122]
- Cross-phase dependency row #1 CSRF hardening gate proof is captured in:
  - `docs/aila/runtime/phase0-xdp01-csrf-hardening-dependency-gate.txt`[^CLAIM-160]
- Cross-phase dependency row #2 config parity gate proof is captured in:
  - `docs/aila/runtime/phase0-xdp02-config-parity-dependency-gate.txt`[^CLAIM-161]
- Cross-phase dependency row #3 observability baseline gate proof is captured in:
  - `docs/aila/runtime/phase1-xdp03-observability-baseline-dependency-gate.txt`[^CLAIM-162]
- Cross-phase dependency row #4 CI quality gate proof is captured in:
  - `docs/aila/runtime/phase1-xdp04-ci-quality-gate-dependency-gate.txt`[^CLAIM-163]
- Cross-phase dependency row #5 retrieval confidence contract gate proof is captured in:
  - `docs/aila/runtime/phase2-xdp05-retrieval-confidence-contract-dependency-gate.txt`[^CLAIM-164]
- Cross-phase dependency row #6 cost guardrails gate proof is captured in:
  - `docs/aila/runtime/phase3-xdp06-cost-guardrails-dependency-gate.txt`[^CLAIM-165]
- Phase 2 Entry #1 observability + CI baseline proof is captured in:
  - `docs/aila/runtime/phase2-entry1-observability-ci-baseline.txt`[^CLAIM-138]
- Phase 2 Exit #1 retrieval contract + confidence threshold proof is captured in:
  - `docs/aila/runtime/phase2-exit1-retrieval-contract-confidence-thresholds.txt`[^CLAIM-140]
- Phase 2 Exit #2 citation coverage + low-confidence refusal target proof is captured in:
  - `docs/aila/runtime/phase2-exit2-citation-coverage-refusal-targets.txt`[^CLAIM-141]
- Phase 2 Exit #3 live LLM disablement continuity proof is captured in:
  - `docs/aila/runtime/phase2-exit3-live-llm-disabled-phase3-readiness.txt`[^CLAIM-142]
- Phase 2 Sprint 5 closure proof is captured in:
  - `docs/aila/runtime/phase2-sprint5-closure.txt`[^CLAIM-144]
- Phase 2 NDO #1 no live production LLM enablement proof is captured in:
  - `docs/aila/runtime/phase2-ndo1-no-live-llm-production-enablement.txt`[^CLAIM-145]
- Phase 2 NDO #2 no broad platform migration proof is captured in:
  - `docs/aila/runtime/phase2-ndo2-no-broad-platform-migration.txt`[^CLAIM-146]
- Phase 3 Objective #2 performance + cost guardrails operational proof is captured in:
  - `docs/aila/runtime/phase3-obj2-performance-cost-guardrails.txt`[^CLAIM-147]
- Phase 3 Objective #3 release readiness package + governance attestation proof is captured in:
  - `docs/aila/runtime/phase3-obj3-release-readiness-governance-attestation.txt`[^CLAIM-148]
- Phase 3 Entry #2 SLO/alert trend-history closure proof is captured in:
  - `docs/aila/runtime/phase3-entry2-slo-alert-trend-history.txt`[^CLAIM-152]
- Phase 3 Exit #1 UX/a11y suite gating + passing closure proof is captured in:
  - `docs/aila/runtime/phase3-exit1-ux-a11y-gating.txt`[^CLAIM-153]
- Phase 3 Sprint 6 Week 1 UX/a11y + mobile hardening closure proof is captured in:
  - `docs/aila/runtime/phase3-sprint6-week1-ux-a11y-mobile-hardening.txt`[^CLAIM-156]
- Phase 3 Sprint 6 Week 2 performance/cost guardrails + governance signoff closure proof is captured in:
  - `docs/aila/runtime/phase3-sprint6-week2-performance-cost-governance-signoff.txt`[^CLAIM-157]
- Phase 3 NDO #2 no platform-wide refactor of unrelated Drupal subsystems proof is captured in:
  - `docs/aila/runtime/phase3-ndo2-no-platform-wide-refactor-of-unrelated-drupal-subsystems.txt`[^CLAIM-159]
- Phase 3 Exit #2 cost/performance controls + owner acceptance closure proof is captured in:
  - `docs/aila/runtime/phase3-exit2-cost-performance-owner-acceptance.txt`[^CLAIM-154]
- Phase 3 Exit #3 final release packet known-unknown disposition + residual risk signoff proof is captured in:
  - `docs/aila/runtime/phase3-exit3-release-packet-known-unknown-risk-signoff.txt`[^CLAIM-155]

### PHARD-02 Langfuse live operationalization verification

- VC-LANGFUSE-LIVE commands (config + queue depth per env):
  ```bash
  for ENV in dev test live; do
    terminus remote:drush "idaho-legal-aid-services.${ENV}" -- \
      config:get ilas_site_assistant.settings langfuse
    terminus remote:drush "idaho-legal-aid-services.${ENV}" -- \
      php:eval "echo 'queue_depth=' . \Drupal::queue('ilas_langfuse_export')->numberOfItems() . PHP_EOL;"
  done
  ```
- Synthetic probe commands (direct mode per env):
  ```bash
  for ENV in dev test live; do
    terminus remote:drush "idaho-legal-aid-services.${ENV}" -- ilas:langfuse-probe --direct
  done
  ```
- Synthetic probe commands (queue mode per env):
  ```bash
  for ENV in dev test live; do
    terminus remote:drush "idaho-legal-aid-services.${ENV}" -- ilas:langfuse-probe
  done
  ```
- Contract test execution:
  ```bash
  vendor/bin/phpunit --configuration phpunit.xml \
    web/modules/custom/ilas_site_assistant/tests/src/Unit/LangfuseProbeCommandTest.php \
    web/modules/custom/ilas_site_assistant/tests/src/Unit/Phard02LangfuseLiveAcceptanceTest.php
  ```
- Expected verification result: Direct probes return exit `0` plus HTTP `207`
  summaries, queued probes store top-level `batch` / `metadata` / `enqueued_at`
  rows (no top-level `payload` wrapper), queue processing logs partial success
  instead of `invalid queue item`, and fresh trace IDs resolve in Langfuse
  UI/API with metadata-only input/output summaries.
- Evidence artifacts:
  - `docs/aila/runtime/phard-02-langfuse-operationalization.txt`
  - `docs/aila/runtime/tovr-04-langfuse-remediation.txt`

### PHARD-06 retrieval contract verification

The Drupal-primary retrieval contract is enforced by `RetrievalContract.php` and tested by:
- `RetrievalContractTest.php` — contract constants validity
- `VectorSearchMergeTest.php` — merge behavior with lexical priority
- `SourceGovernanceServiceTest.php` — source class validation
- `RetrievalContractGuardTest.php` — architectural invariants
- `ConfigCompletenessDriftTest.php` — config parity

Verification commands:

```bash
# VC-UNIT: Contract + merge + governance tests
ddev exec vendor/bin/phpunit --configuration /var/www/html/phpunit.xml \
  --group=ilas_site_assistant \
  --filter="RetrievalContract|VectorSearchMerge|SourceGovernance"

# VC-RETRIEVAL-LOCAL: Architectural guard tests
ddev exec vendor/bin/phpunit --configuration /var/www/html/phpunit.xml \
  web/modules/custom/ilas_site_assistant/tests/src/Unit/RetrievalContractGuardTest.php
```

Expected PHARD-06 verification result:
- `RetrievalContractTest` passes: 4 approved classes, disjoint primary/supplement,
  priority ordering, unapproved class rejection, parametric isPrimary/isSupplement.
- `VectorSearchMergeTest` passes: lexical priority boost wins for close scores,
  vector still wins for large gaps, minimum lexical preservation enforced.
- `SourceGovernanceServiceTest` passes: unapproved source class rejected,
  all approved accepted, provenance includes contract version + enforcement mode.
- `RetrievalContractGuardTest` passes: imports present in FaqIndex/ResourceFinder/
  SourceGovernanceService, lexical search precedes vector supplement, config contains
  retrieval_contract block.
- `ConfigCompletenessDriftTest` passes: retrieval_contract block present in both
  install and active config.

### RAUD-21 retrieval configuration governance verification

Use this bundle to verify retrieval-ID ownership, runtime-only LegalServer URL
resolution, and admin health drift checks after deploying `RAUD-21`.

```bash
# VC-UNIT
ddev exec vendor/bin/phpunit --configuration /var/www/html/phpunit.xml \
  --group ilas_site_assistant \
  /var/www/html/web/modules/custom/ilas_site_assistant/tests/src/Unit

# Local contract spot-checks
bash /home/evancurry/idaho-legal-aid-services/scripts/ci/run-host-phpunit.sh \
  /home/evancurry/idaho-legal-aid-services/web/modules/custom/ilas_site_assistant/tests/src/Unit/RetrievalConfigurationServiceTest.php \
  /home/evancurry/idaho-legal-aid-services/web/modules/custom/ilas_site_assistant/tests/src/Unit/LegalServerRuntimeUrlGuardTest.php \
  /home/evancurry/idaho-legal-aid-services/web/modules/custom/ilas_site_assistant/tests/src/Unit/VectorSearchConfigSchemaTest.php \
  /home/evancurry/idaho-legal-aid-services/web/modules/custom/ilas_site_assistant/tests/src/Kernel/RetrievalIndexUpdateHookKernelTest.php

# VC-PANTHEON-READONLY
terminus remote:drush idaho-legal-aid-services.dev -- \
  config:get ilas_site_assistant.settings retrieval --format=yaml
terminus remote:drush idaho-legal-aid-services.dev -- \
  config:get ilas_site_assistant.settings canonical_urls --format=yaml
terminus remote:drush idaho-legal-aid-services.dev -- \
  php:eval '$storage = \Drupal::entityTypeManager()->getStorage("search_api_index"); foreach (["faq_accordion", "assistant_resources"] as $id) { $index = $storage->load($id); echo $id . "=" . ($index && $index->status() ? "enabled" : ($index ? "disabled" : "missing")) . PHP_EOL; }'
terminus remote:drush idaho-legal-aid-services.dev -- \
  php:eval '$service = \Drupal::hasService("ilas_site_assistant.retrieval_configuration") ? \Drupal::service("ilas_site_assistant.retrieval_configuration") : NULL; if (!$service) { echo "retrieval_configuration_service=missing" . PHP_EOL; } else { echo json_encode($service->getHealthSnapshot(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL; } echo "legalserver_setting_present=" . ((string) \Drupal\Core\Site\Settings::get("ilas_site_assistant_legalserver_online_application_url", "") !== "" ? "true" : "false") . PHP_EOL;'
```

Expected `RAUD-21` verification result:
- Local/unit proof shows `retrieval.*` present in install/active/schema,
  `vector_search` no longer owns index IDs, lexical Search API indexes are
  tracked in active sync, `update_10009` recreates missing lexical indexes,
  and runtime-only LegalServer guard tests pass.
- `/assistant/api/health` exposes `checks.retrieval_configuration` with
  lexical/vector index status, service-area completeness, and LegalServer URL
  diagnostics.
- Pantheon closure requires all of the following on `dev`, `test`, and `live`:
  `faq_accordion=enabled`, `assistant_resources=enabled`,
  `legalserver_setting_present=true`, and
  `checks.retrieval_configuration.status=healthy`.
- If hosted checks still show missing lexical indexes or an absent LegalServer
  runtime setting, treat that as real operational drift, run
  `updb`/`cim`/`cr`, reindex the lexical Search API indexes, and verify the
  Pantheon runtime secret before calling the finding `Fixed`.

### RAUD-22 retrieval cold-start remediation verification

Use this bundle to verify bounded request-path retrieval after `RAUD-22`.

```bash
# VC-PURE targeted proof
vendor/bin/phpunit --configuration /home/evancurry/idaho-legal-aid-services/phpunit.pure.xml \
  /home/evancurry/idaho-legal-aid-services/web/modules/custom/ilas_site_assistant/tests/src/Unit/RetrievalColdStartGuardTest.php \
  /home/evancurry/idaho-legal-aid-services/web/modules/custom/ilas_site_assistant/tests/src/Unit/DependencyFailureDegradeContractTest.php \
  /home/evancurry/idaho-legal-aid-services/web/modules/custom/ilas_site_assistant/tests/src/Unit/VectorSearchMergeTest.php \
  /home/evancurry/idaho-legal-aid-services/web/modules/custom/ilas_site_assistant/tests/src/Unit/VectorIndexHygieneServiceTest.php

# VC-UNIT
ddev exec vendor/bin/phpunit --configuration /var/www/html/phpunit.xml \
  --group ilas_site_assistant \
  /var/www/html/web/modules/custom/ilas_site_assistant/tests/src/Unit
```

Expected `RAUD-22` verification result:
- Resource sparse-result topic fill no longer routes through
  `getAllResources()`; it loads only remaining-slot topic candidates through
  `loadLegacyResourceCandidates()`.
- Resource legacy retrieval (`findByTypeLegacy()`, `findByTopic()`,
  `findByServiceArea()`) uses bounded entity queries capped at
  `min(max(limit * 8, 20), 100)` with `accessCheck(TRUE)` and `changed DESC`.
- FAQ legacy search no longer routes through `getAllFaqsLegacy()`;
  `searchLegacy()` loads bounded `faq_item` and `accordion_item` paragraph
  candidates before ranking.
- `RetrievalColdStartGuardTest` passes and fails on any regression that
  reintroduces `getAllResources()` or `getAllFaqsLegacy()` into default
  request/search paths.
- `getCategoriesLegacy()` may still use `getAllFaqsLegacy()` when the FAQ
  lexical index is unavailable; treat that as an explicit browse-only residual
  fallback, not a request-path verification failure.

### RAUD-25 assistant API crawler-policy verification

- Baseline before the remediation:
  - Hosted production fetch on March 13, 2026 showed
    `https://idaholegalaid.org/robots.txt` omitted
    `Disallow: /assistant/api/`.
  - Public `GET /assistant/api/suggest` and
    `GET /assistant/api/session/bootstrap` remained reachable on the primary
    domain with no endpoint `X-Robots-Tag` header, so crawler policy depended
    on `robots.txt` rather than per-endpoint noindex headers.
  - `web/robots.txt` existed in repo and `composer.json` preserved it via
    `"[web-root]/robots.txt": false`, making the static file the effective
    source of truth rather than the Drupal `robotstxt` route.
  - Pantheon `dev`/`test`/`live.pantheonsite.io` already served
    platform-managed `robots.txt` responses with `Disallow: /` and
    `X-Robots-Tag: noindex`, but the same public assistant GET endpoints still
    returned `200`.
- Required verification commands for the remediation report:
  - `VC-PURE`
  - `VC-RUNBOOK-LOCAL`
  - `VC-PANTHEON-READONLY`
- Targeted repo/local checks:
  - `rg -n "\[web-root\]/robots.txt|assistant/api/" composer.json web/robots.txt config/robotstxt.settings.yml web/sites/default/files/sync/robotstxt.settings.yml`
  - `vendor/bin/phpunit --configuration /home/evancurry/idaho-legal-aid-services/phpunit.pure.xml /home/evancurry/idaho-legal-aid-services/web/modules/custom/ilas_site_assistant/tests/src/Unit/RobotsTxtCrawlerPolicyContractTest.php`
  - `curl -k -sS -D - https://ilas-pantheon.ddev.site/robots.txt -o /tmp/raud25-local-robots.txt && sed -n '1,120p' /tmp/raud25-local-robots.txt`
- Hosted read-only checks after deployment:
  - `curl -L -sS -D - https://idaholegalaid.org/robots.txt -o /tmp/raud25-prod-robots.txt && sed -n '1,120p' /tmp/raud25-prod-robots.txt`
  - `curl -L -sS -D - "https://idaholegalaid.org/assistant/api/suggest?q=housing&type=all" -o /tmp/raud25-prod-suggest.json | sed -n '1,20p'`
  - `curl -L -sS -D - "https://idaholegalaid.org/assistant/api/session/bootstrap" -o /tmp/raud25-prod-bootstrap.txt | sed -n '1,20p'`
  - `for ENV in dev test live; do BASE_URL="$(terminus env:view "idaho-legal-aid-services.${ENV}" --print)"; echo "=== ${ENV} robots ==="; curl -L -sS -D - "${BASE_URL%/}/robots.txt" -o "/tmp/raud25-${ENV}-robots.txt" | sed -n '1,20p'; echo "=== ${ENV} suggest ==="; curl -L -sS -D - "${BASE_URL%/}/assistant/api/suggest?q=housing&type=all" -o "/tmp/raud25-${ENV}-suggest.json" | sed -n '1,20p'; done`
- Expected contract after the remediation:
  - `web/robots.txt` and both `robotstxt` config exports all disallow
    `/assistant/api/` and `/index.php/assistant/api/`.
  - The local served `https://ilas-pantheon.ddev.site/robots.txt` response
    includes both assistant API rules.
  - The hosted primary domain `https://idaholegalaid.org/robots.txt` must
    include the same assistant API rules before the finding can be called
    `Fixed`.
  - Pantheon non-production blanket `Disallow: /` responses are supporting
    infrastructure evidence only; they do not prove primary-domain closure by
    themselves.
  - If the primary domain still omits the rule after repo changes, classify
    the finding as `Partially Fixed` or `Unverified`, never `Fixed`.
- Archive the executed command summaries and final classification in
  `docs/aila/runtime/raud-25-crawler-policy-controls.txt`.

### RAUD-27 performance monitor coverage verification

- Baseline before the remediation:
  - `/assistant/api/message` recorded only the main post-routing success/error
    path; validation failures, rate-limit exits, repeated-message escalation,
    safety/policy/out-of-scope exits, and office follow-up shortcuts could
    return without a monitor record.
  - `/assistant/api/track`, `/assistant/api/suggest`, and `/assistant/api/faq`
    did not consistently produce performance-monitor entries for denied,
    throttled, or degraded responses.
  - Route-level `/assistant/api/message` CSRF `403` JSON denials were
    observable in logs but were invisible to `PerformanceMonitor`.
  - Cached read responses for `/assistant/api/suggest` and `/assistant/api/faq`
    could be served without controller execution, creating an observability
    blind spot unless the final response was classified separately.
- Required verification commands for the remediation report:
  - `VC-UNIT`
  - `VC-PURE`
  - `VC-QUALITY-GATE`
- Targeted repo/local checks:
  - `vendor/bin/phpunit --configuration /home/evancurry/idaho-legal-aid-services/phpunit.xml /home/evancurry/idaho-legal-aid-services/web/modules/custom/ilas_site_assistant/tests/src/Unit/PerformanceMonitorTest.php`
  - `vendor/bin/phpunit --configuration /home/evancurry/idaho-legal-aid-services/phpunit.xml /home/evancurry/idaho-legal-aid-services/web/modules/custom/ilas_site_assistant/tests/src/Unit/AssistantApiReadEndpointContractTest.php`
  - `vendor/bin/phpunit --configuration /home/evancurry/idaho-legal-aid-services/phpunit.xml /home/evancurry/idaho-legal-aid-services/web/modules/custom/ilas_site_assistant/tests/src/Unit/IntegrationFailureContractTest.php`
  - `vendor/bin/phpunit --configuration /home/evancurry/idaho-legal-aid-services/phpunit.xml /home/evancurry/idaho-legal-aid-services/web/modules/custom/ilas_site_assistant/tests/src/Unit/CsrfDenialResponseSubscriberTest.php`
  - `vendor/bin/phpunit --configuration /home/evancurry/idaho-legal-aid-services/phpunit.xml /home/evancurry/idaho-legal-aid-services/web/modules/custom/ilas_site_assistant/tests/src/Unit/AssistantApiControllerCostControlMetricsTest.php`
  - `vendor/bin/phpunit --configuration /home/evancurry/idaho-legal-aid-services/phpunit.xml /home/evancurry/idaho-legal-aid-services/web/modules/custom/ilas_site_assistant/tests/src/Unit/ImpObs01AcceptanceTest.php`
- Expected contract after the remediation:
  - Exactly one classified performance outcome is recorded for each final
    response on `/assistant/api/message`, `/assistant/api/track`,
    `/assistant/api/suggest`, and `/assistant/api/faq`, plus route-level
    `/assistant/api/message` CSRF `403` JSON denials.
  - `PerformanceMonitor` preserves `/assistant/api/message` as the top-level
    SLO/health rollup while `/assistant/api/metrics` adds additive
    `all_endpoints`, `by_endpoint`, and `by_outcome` breakdowns for operator
    analysis.
  - User-visible denied and degraded responses count as failures in the monitor;
    intentional safety, policy, out-of-scope, escalation, and office-follow-up
    responses remain successful classified outcomes.
  - Cached read responses are backfilled at `kernel.response` using final
    response inspection so degraded fallback paths do not disappear from
    observability.
  - If any public assistant endpoint still bypasses classification at final
    response time, classify the finding as `Partially Fixed`, not `Fixed`.
- Archive the executed command summaries, final classification, and residual
  blind spots in `docs/aila/runtime/raud-27-performance-monitor-coverage.txt`.

## 7) Retrospective regression checklist (mandatory)

Run this checklist for every future audit cycle that touches assistant routing, fallback, or endpoint hardening:

1. **Transcript replay: vague + mixed-intent cases**
   - Replay `i need some help`, `custody forms?`, `eviction forms or guides?`, and repeated `eviction forms`.
   - Record response class transitions and assert no repeated clarify-loop without escalation fallback.
2. **Disambiguation option schema contract**
   - Verify each disambiguation option emitted by API has actionable `intent`/`action`.
   - During migration, verify legacy `value` aliases map deterministically to `intent` and emit deprecation telemetry.
3. **Anonymous/session CSRF matrix including recovery**
   - Execute missing/invalid/expired/valid token cases for `/assistant/api/message` (anonymous bootstrap + authenticated session sanity).
   - Assert 403 JSON uses machine-readable `error_code` with canonical outputs: `csrf_missing`, `csrf_invalid`, `csrf_expired` (legacy input alias `session_expired` is normalized to `csrf_expired`).
   - Verify deterministic recovery UX in both widget and page modes:
     - `csrf_missing` / `csrf_invalid` => show `Try again` + `Refresh page`.
     - `csrf_expired` => show `Refresh page` only.
     - Recovery container keeps `role="alert"` and keyboard focus moves to first recovery action.
   - Verify `/assistant/api/track` remains same-origin `Origin`/`Referer` protected, denies missing-header writes without approved fallback proof, and succeeds through the bootstrap-token recovery path when both browser headers are absent.
4. **Post-sanitize empty-message guard**
   - Submit payloads that sanitize to empty (e.g., whitespace/control-only strings) and assert deterministic `400 invalid_message`.
   - Verify router/retrieval code paths are not invoked for empty-effective queries.
5. **UI-controller action wiring check**
   - Assert topic/disambiguation chips emitted by backend map to clickable UI actions.
   - Fail checklist if any option renders without action payload.
6. **Blocking deep-suite gate**
   - Confirm blocking gate covers deep multi-turn suite in addition to abuse-only suite.
   - Archive gate artifacts with pass/fail summary and transcript identifiers.

---

[^CLAIM-108]: [CLAIM-108](evidence-index.md#claim-108)
[^CLAIM-013]: [CLAIM-013](evidence-index.md#claim-013)
[^CLAIM-109]: [CLAIM-109](evidence-index.md#claim-109)
[^CLAIM-110]: [CLAIM-110](evidence-index.md#claim-110)
[^CLAIM-111]: [CLAIM-111](evidence-index.md#claim-111)
[^CLAIM-112]: [CLAIM-112](evidence-index.md#claim-112)
[^CLAIM-113]: [CLAIM-113](evidence-index.md#claim-113)
[^CLAIM-114]: [CLAIM-114](evidence-index.md#claim-114)
[^CLAIM-115]: [CLAIM-115](evidence-index.md#claim-115)
[^CLAIM-116]: [CLAIM-116](evidence-index.md#claim-116)
[^CLAIM-117]: [CLAIM-117](evidence-index.md#claim-117)
[^CLAIM-118]: [CLAIM-118](evidence-index.md#claim-118)
[^CLAIM-119]: [CLAIM-119](evidence-index.md#claim-119)
[^CLAIM-120]: [CLAIM-120](evidence-index.md#claim-120)
[^CLAIM-121]: [CLAIM-121](evidence-index.md#claim-121)
[^CLAIM-122]: [CLAIM-122](evidence-index.md#claim-122)
[^CLAIM-125]: [CLAIM-125](evidence-index.md#claim-125)
[^CLAIM-126]: [CLAIM-126](evidence-index.md#claim-126)
[^CLAIM-133]: [CLAIM-133](evidence-index.md#claim-133)
[^CLAIM-134]: [CLAIM-134](evidence-index.md#claim-134)
[^CLAIM-135]: [CLAIM-135](evidence-index.md#claim-135)
[^CLAIM-136]: [CLAIM-136](evidence-index.md#claim-136)
[^CLAIM-137]: [CLAIM-137](evidence-index.md#claim-137)
[^CLAIM-138]: [CLAIM-138](evidence-index.md#claim-138)
[^CLAIM-139]: [CLAIM-139](evidence-index.md#claim-139)
[^CLAIM-140]: [CLAIM-140](evidence-index.md#claim-140)
[^CLAIM-141]: [CLAIM-141](evidence-index.md#claim-141)
[^CLAIM-142]: [CLAIM-142](evidence-index.md#claim-142)
[^CLAIM-143]: [CLAIM-143](evidence-index.md#claim-143)
[^CLAIM-144]: [CLAIM-144](evidence-index.md#claim-144)
[^CLAIM-145]: [CLAIM-145](evidence-index.md#claim-145)
[^CLAIM-146]: [CLAIM-146](evidence-index.md#claim-146)
[^CLAIM-147]: [CLAIM-147](evidence-index.md#claim-147)
[^CLAIM-148]: [CLAIM-148](evidence-index.md#claim-148)
[^CLAIM-149]: [CLAIM-149](evidence-index.md#claim-149)
[^CLAIM-151]: [CLAIM-151](evidence-index.md#claim-151)
[^CLAIM-152]: [CLAIM-152](evidence-index.md#claim-152)
[^CLAIM-153]: [CLAIM-153](evidence-index.md#claim-153)
[^CLAIM-154]: [CLAIM-154](evidence-index.md#claim-154)
[^CLAIM-155]: [CLAIM-155](evidence-index.md#claim-155)
[^CLAIM-156]: [CLAIM-156](evidence-index.md#claim-156)
[^CLAIM-157]: [CLAIM-157](evidence-index.md#claim-157)
[^CLAIM-158]: [CLAIM-158](evidence-index.md#claim-158)
[^CLAIM-159]: [CLAIM-159](evidence-index.md#claim-159)
[^CLAIM-160]: [CLAIM-160](evidence-index.md#claim-160)
[^CLAIM-161]: [CLAIM-161](evidence-index.md#claim-161)
[^CLAIM-162]: [CLAIM-162](evidence-index.md#claim-162)
[^CLAIM-163]: [CLAIM-163](evidence-index.md#claim-163)
[^CLAIM-164]: [CLAIM-164](evidence-index.md#claim-164)
[^CLAIM-165]: [CLAIM-165](evidence-index.md#claim-165)
[^CLAIM-212]: [CLAIM-212](evidence-index.md#claim-212)
