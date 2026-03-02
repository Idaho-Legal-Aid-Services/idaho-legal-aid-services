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
CSRF_TOKEN=$(curl -k -sS -c "${COOKIE_JAR}" -b "${COOKIE_JAR}" "${BASE_URL}/session/token")

# Synthetic message request
curl -k -sS -X POST "${BASE_URL}/assistant/api/message" \
  -H "Content-Type: application/json" \
  -H "X-CSRF-Token: ${CSRF_TOKEN}" \
  -b "${COOKIE_JAR}" \
  -d '{"message":"SYNTHETIC EXAMPLE: where can I find housing forms?","conversation_id":"11111111-1111-4111-8111-111111111111","context":{"history":[]}}'

# Synthetic track request
curl -k -sS -X POST "${BASE_URL}/assistant/api/track" \
  -H "Content-Type: application/json" \
  -H "X-CSRF-Token: ${CSRF_TOKEN}" \
  -b "${COOKIE_JAR}" \
  -d '{"event_type":"chat_open","event_value":"SYNTHETIC EXAMPLE"}'

# Anonymous CSRF matrix (same cookie jar for token-bound validation).
CSRF_PRIME="$(date +%s%N)"
curl -k -sS -c "${COOKIE_JAR}" -b "${COOKIE_JAR}" "${BASE_URL}/assistant?csrf_prime=${CSRF_PRIME}" >/dev/null
ANON_TOKEN=$(curl -k -sS -c "${COOKIE_JAR}" -b "${COOKIE_JAR}" "${BASE_URL}/session/token")

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

# track: missing token -> 403
curl -k -sS -D '<headers>' -o '<body>' -X POST "${BASE_URL}/assistant/api/track" \
  -H "Content-Type: application/json" \
  -b "${COOKIE_JAR}" \
  -d '{"event_type":"chat_open","event_value":"SYNTHETIC EXAMPLE matrix missing token"}'

# track: invalid token -> 403
curl -k -sS -D '<headers>' -o '<body>' -X POST "${BASE_URL}/assistant/api/track" \
  -H "Content-Type: application/json" \
  -H "X-CSRF-Token: invalid-token" \
  -b "${COOKIE_JAR}" \
  -d '{"event_type":"chat_open","event_value":"SYNTHETIC EXAMPLE matrix invalid token"}'

# track: valid token (same cookie jar) -> 200
curl -k -sS -D '<headers>' -o '<body>' -X POST "${BASE_URL}/assistant/api/track" \
  -H "Content-Type: application/json" \
  -H "X-CSRF-Token: ${ANON_TOKEN}" \
  -b "${COOKIE_JAR}" \
  -d '{"event_type":"chat_open","event_value":"SYNTHETIC EXAMPLE matrix valid token"}'

rm -f "${COOKIE_JAR}"

# Read endpoints and permission-gated checks
curl -k -sS "${BASE_URL}/assistant/api/suggest?q=housing&type=all"
curl -k -sS "${BASE_URL}/assistant/api/faq?q=eviction"
curl -k -sS "${BASE_URL}/assistant/api/health"
curl -k -sS "${BASE_URL}/assistant/api/metrics"
```

Store status/headers/schema-key output (no secrets, synthetic payloads only) in `docs/aila/runtime/local-endpoints.txt`.[^CLAIM-112][^CLAIM-113]

Matrix acceptance test command (authenticated + anonymous, valid/missing/invalid CSRF):

```bash
ddev exec vendor/bin/phpunit \
  --configuration /var/www/html/phpunit.xml \
  /var/www/html/web/modules/custom/ilas_site_assistant/tests/src/Functional/AssistantApiFunctionalTest.php \
  --filter 'test(MessageEndpoint(RequiresCsrfToken|RejectsInvalidCsrfToken|WithCsrfToken)|TrackEndpoint(RequiresCsrfToken|RejectsInvalidCsrfToken|AcceptsValidEvent)|AnonymousMessageEndpoint(RequiresCsrfToken|RejectsInvalidCsrfToken|AllowsValidCsrfToken)|AnonymousTrackEndpoint(RequiresCsrfToken|RejectsInvalidCsrfToken|AllowsValidCsrfToken))'
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
- Promptfoo URL target is operator-supplied per environment (Pantheon/local),
  not a GitHub secret dependency.
- Telemetry activation remains a Phase 1 implementation activity after
  credential and destination approvals.

### External CI promptfoo gate (Pantheon-derived URL)

Use repo scripts for provider-agnostic CI runners (Jenkins/Circle/GitLab/Buildkite/self-hosted):

```bash
# Blocking on release branches, advisory elsewhere (auto-detected via CI_BRANCH).
scripts/ci/run-promptfoo-gate.sh --env test --mode auto

# Force advisory/blocking for local simulation.
CI_BRANCH=feature/test scripts/ci/run-promptfoo-gate.sh --env dev --mode auto
CI_BRANCH=release/2026-03 scripts/ci/run-promptfoo-gate.sh --env dev --mode auto
```

Expected CI policy:
- `main` and `release/*` branches are blocking for threshold failures.
- Other branches are advisory (non-zero eval result reported but does not fail job).

## 4) Quality gates + config parity checks (`P1-OBJ-03`, `IMP-CONF-01`)

### Enforced quality gate verification (`P1-OBJ-03`)

Use these commands to convert existing test assets into reproducible enforced
gates in local and external-runner contexts.

```bash
# 1) Mandatory module quality gate from existing test assets.
ddev exec bash /var/www/html/web/modules/custom/ilas_site_assistant/tests/run-quality-gate.sh

# 2) Branch-aware Promptfoo threshold policy simulation (no live eval required).
#    - release/main semantics -> blocking failure (expected non-zero on threshold fail)
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
  `main`/`release/*` and reports advisory-only failures on other branches.
- `scripts/ci/run-external-quality-gate.sh` composes repo-owned gate assets for
  CI platforms where workflow ownership is external to this repository.

Expected artifacts:
- `promptfoo-evals/output/phpunit-summary.txt` (per-phase PHPUnit gate status + timestamps).
- `promptfoo-evals/output/gate-summary.txt` (Promptfoo branch mode, threshold, pass rate, eval status).

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
   - Execute missing/invalid/valid token cases for anonymous and authenticated sessions on `/assistant/api/message` and `/assistant/api/track`.
   - Include expired/missing-session bootstrap path and verify UI recovery behavior is actionable.
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
