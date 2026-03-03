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
  -d '{"message":"SYNTHETIC EXAMPLE: where can I find housing forms?","conversation_id":"11111111-1111-4111-8111-111111111111","context":{"history":[]}}'

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

# track request (same-origin, no CSRF required) -> 200
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

rm -f "${COOKIE_JAR}"

# Read endpoints and permission-gated checks
curl -k -sS "${BASE_URL}/assistant/api/suggest?q=housing&type=all"
curl -k -sS "${BASE_URL}/assistant/api/faq?q=eviction"
curl -k -sS "${BASE_URL}/assistant/api/health"
curl -k -sS "${BASE_URL}/assistant/api/metrics"
```

Store status/headers/schema-key output (no secrets, synthetic payloads only) in `docs/aila/runtime/local-endpoints.txt`.[^CLAIM-112][^CLAIM-113]

Matrix acceptance test command (message CSRF matrix + track mitigation):

```bash
ddev exec bash -lc "vendor/bin/phpunit \
  --configuration /var/www/html/phpunit.xml \
  /var/www/html/web/modules/custom/ilas_site_assistant/tests/src/Functional/AssistantApiFunctionalTest.php \
  --filter 'test(MessageEndpoint(RequiresCsrfToken|RejectsInvalidCsrfToken|WithCsrfToken)|AnonymousMessageEndpoint(RequiresCsrfToken|RejectsInvalidCsrfToken|AllowsValidCsrfToken)|TrackEndpoint(WithoutCsrf|AcceptsValidEvent|RejectsCrossOriginOriginHeader|AllowsSameOriginOriginHeader|AllowsSameOriginRefererHeader)|AnonymousTrackEndpointWithoutCsrf)'"
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
- `SloAlertService` emits structured watchdog warnings (`SLO violation: ...`)
  for availability, latency, error-rate, cron, and queue breaches.
- Alert cooldown is 900 seconds per SLO dimension to reduce noise.

Verification commands (local):

```bash
BASE_URL="https://<local-host>"
curl -k -sS "${BASE_URL}/assistant/api/health"
curl -k -sS "${BASE_URL}/assistant/api/metrics"

# Trigger cron-driven SLO checks and inspect emitted violations (if any).
ddev drush cron
ddev drush watchdog:show --count=200 | rg 'SLO violation|Chatbot API (latency|error rate)'
```

Verification commands (Pantheon):

```bash
for ENV in dev test live; do
  BASE_URL="$(terminus env:view "idaho-legal-aid-services.${ENV}" --print)"
  curl -k -sS "${BASE_URL%/}/assistant/api/health"
  curl -k -sS "${BASE_URL%/}/assistant/api/metrics"
done
```

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

### GitHub mirror onboarding (WSL2)

```bash
git remote -v
git remote rename origin pantheon
git remote add origin git@github.com:<github-user>/<repo>.git
git remote -v
```

### External CI promptfoo gate (Pantheon-derived URL)

Use repo scripts for provider-agnostic CI runners (Jenkins/Circle/GitLab/Buildkite/self-hosted):

```bash
# Blocking on master/main/release branches, advisory elsewhere (auto-detected via CI_BRANCH).
scripts/ci/run-promptfoo-gate.sh --env test --mode auto

# Force advisory/blocking for local simulation.
CI_BRANCH=feature/test scripts/ci/run-promptfoo-gate.sh --env dev --mode auto
CI_BRANCH=master scripts/ci/run-promptfoo-gate.sh --env dev --mode auto
CI_BRANCH=release/2026-03 scripts/ci/run-promptfoo-gate.sh --env dev --mode auto
```

Expected CI policy:
- `master`, `main`, and `release/*` branches are blocking for threshold failures.
- Other branches are advisory (non-zero eval result reported but does not fail job).
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
    --env test \
    --mode auto \
    --threshold 90 \
    --config promptfooconfig.abuse.yaml \
    --skip-eval \
    --simulate-pass-rate 85
```

Expected quality gate result:
- `tests/run-quality-gate.sh` blocks on `VC-UNIT` and full
  `VC-DRUPAL-UNIT` suite regressions, plus golden transcript failures.
- `scripts/ci/run-promptfoo-gate.sh` blocks threshold/eval failures on
  `master`/`main`/`release/*` and reports advisory-only failures on other branches.
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
rg -n "flowchart TD|Flood checks|SafetyClassifier|OutOfScopeClassifier|PolicyFilter fallback checks|LlmEnhancer call|Queue worker on cron" \
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
   - Verify `/assistant/api/track` remains origin/referer protected and does not depend on CSRF/session token bootstrap.
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
