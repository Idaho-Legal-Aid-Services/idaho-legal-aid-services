# Aila Improvement Roadmap (12 Weeks / 6 Sprints)

## Summary
This roadmap sequences safety hardening, reliability/observability foundations, retrieval quality improvements, and UX/performance optimization using the audited baseline. (Refs: current-state §1, §4F, §8; evidence-index CLAIM-033, CLAIM-079, CLAIM-084, CLAIM-122; system-map Diagram A; runbook §7)

Planning defaults applied:
- `llm.enabled` remains conservative in export and becomes effective only through runtime toggle + runtime secret (`ILAS_LLM_ENABLED` + `ILAS_COHERE_API_KEY`). (Refs: current-state §1; system-map Diagram B; runbook §3)
- Timeline = 12 weeks / 6 two-week sprints. (Refs: current-state §7; evidence-index CLAIM-108, CLAIM-115; system-map Diagram A; runbook §4)

### Cohere-first request-time transition addendum (2026-04-15)
1. Assistant request-time LLM scope is now Cohere-first for ambiguous-intent classification only.
2. Voyage remains the embeddings and reranking provider.
3. Residual Gemini config is kept only where Search API AI still proves a vector/chat dependency.
4. Dormant Vertex assistant runtime wiring is retired from `settings.php` and the custom request-time transport path.
5. Live rollout remains runtime-toggle controlled; exported config stays conservative and live vector search stays hard-disabled.

### Re-audit remediation addendum (2026-03-10)
This dated addendum records the 2026-03-10 state and is now superseded for the
assistant request-time path by the Cohere-first addendum above.

1. At that time, `RAUD-03` closed the repo-side implementation gap behind findings `C2` and
   `F-15`: Vertex service-account JSON is removed from the assistant admin UI,
   removed from install/active/schema config contracts, and resolved at runtime
   only via `ILAS_VERTEX_SA_JSON` -> `$settings['ilas_vertex_sa_json']`.
2. At that time, the parallel Drupal Key path was first remediated to runtime-only
   non-secret config, and TOVR-14 later removes the dormant synced
   `vertex_sa_credentials` entity entirely because the current Pinecone/vector
   runtime does not depend on it.
3. Remaining closure work is deployment-bound: Pantheon environments must be
   re-verified with read-only checks after deployment before the finding is
   considered fully fixed in a live runtime.
4. At that time, `RAUD-05` closed the repo-side LLM transport gap behind findings `C3`, `C4`,
   and `LLM-1`: `LlmEnhancer` now caches Vertex access tokens with buffered
   TTLs and reduces synchronous retry behavior to one bounded `<=250ms` backoff
   window without expanding the assistant architecture surface.
5. `RAUD-08` closes the repo-side request-identity trust gap behind findings
   `F-05` and `N-05`: forwarded headers are now trusted only when
   `ILAS_TRUSTED_PROXY_ADDRESSES` explicitly supplies a proxy allowlist, while
   assistant flood controls and admin diagnostics surface the effective
   client-IP source and trust status.
6. Remaining `RAUD-08` closure work is environment-bound: Pantheon `dev`,
   `test`, and `live` must set the runtime proxy allowlist and be rechecked
   read-only before the finding can move from `Partially Fixed` to `Fixed`.
7. `RAUD-09` closes the repo-side live debug exposure gap behind findings `H3`
   and `N-25`: live-environment detection is now centralized in
   `EnvironmentDetector`, `AssistantApiController::isDebugMode()` fails closed
   on live, and `settings.php` adds a runtime-only
   `ilas_site_assistant_debug_metadata_force_disable` flag in the Pantheon live
   branch.
8. Remaining `RAUD-09` closure work is deployment-bound: Pantheon `dev`,
   `test`, and `live` must be rechecked read-only after deployment to prove the
   new service is present and `effective_debug_mode=false` on `live`.
9. `RAUD-10` expands repo-side PII redaction coverage behind findings `H1`,
   `PII-1`, `PII-2`, and `N-24`: `PiiRedactor` now handles Spanish contextual
   name/DOB/address phrases, consumes full `+52`-style phone prefixes, redacts
   context-gated role names with Unicode-aware matching, and detects Idaho
   driver-license values when paired with license context.
10. Regression proof for `RAUD-10` now spans unit, observability-contract, and
    kernel logger suites so multilingual/contextual samples are exercised
    through the shared redaction path before persistence/export.
11. Remaining `RAUD-10` closure work is design-bound rather than
    environment-bound: truly free-form bare names remain intentionally
    unsupported to avoid deterministic false positives, so the finding remains
    `Partially Fixed` until the project accepts a higher-risk heuristic or a
    different name-detection approach.
12. `RAUD-11` closes the remaining observability minimization gap behind
    `F-03` / `N-03`: analytics event values are normalized to approved
    IDs/paths/hashes only, no-answer storage is metadata-only, and opt-in
    conversation logging now persists per-turn fingerprints rather than
    message text.
13. `RAUD-11` also rewires Langfuse/watchdog surfaces to metadata-only
    telemetry (`input_hash`, `output_hash`, `query_hash`, `keyword_count`,
    `error_signature`) and adds schema/update-hook coverage so legacy rows and
    queued trace batches are rewritten or discarded on deploy.
14. `RAUD-12` closes the repo-side anonymous bootstrap churn gap behind
    finding `NF-04`: a dedicated `AssistantSessionBootstrapGuard` now
    separates new anonymous session creation from same-session reuse, applies
    config-backed new-session flood thresholds keyed by resolved client IP, and
    records a rolling snapshot at
    `ilas_site_assistant.session_bootstrap.snapshot`.
15. `RAUD-12` also makes the bootstrap surface observable rather than purely
    implicit: admin metrics now expose `metrics.session_bootstrap` plus
    `thresholds.session_bootstrap`, and widget-side bootstrap failures preserve
    `status` / `Retry-After` so 429 recovery UX stays consistent with the
    existing error contract.
16. Remaining `RAUD-12` closure work is deployment-bound: Pantheon `dev`,
    `test`, and `live` still return `null` for
    `ilas_site_assistant.settings:session_bootstrap` and no bootstrap snapshot
    state in March 10, 2026 read-only checks, so the finding remains
    `Partially Fixed` until the new config/code is deployed and rechecked.
17. `RAUD-13` closes the remaining logger DI/testability gap behind findings
    `L1` / `N-28`: `AnalyticsLogger` and `ConversationLogger` now inject the
    module logger channel instead of reaching into global static logger state.
18. `RAUD-13` preserves the existing analytics/conversation log payload
    contract while adding dedicated unit and kernel regression coverage for
    swallowed exception paths and conversation cleanup info logging.
19. `RAUD-16` closes the repo-side bypass corpus gap behind `F-08`, `F-11`,
    and the unresolved portion of `N-08`: normalization now strips zero-width
    plus slash/comma/apostrophe/spaced-letter obfuscation, and request-path
    tests prove `PreRoutingDecisionEngine` exits on the reconstructed cases.
20. Live promptfoo abuse coverage on March 10, 2026 expanded to zero-width
    ignores, spaced-dot ignores, obfuscated `system prompt` leakage,
    obfuscated legal-advice asks, English guardrail/latest-directive
    paraphrases, and Spanish override/leak paraphrases; the abuse suite passed
    `105/105`, while the blocking deep suite still had two unrelated failures
    outside `RAUD-16`.
21. `RAUD-19` closes the repo-side multilingual routing/eval gap behind
    `I18N-1`, `EVAL-1`, and `N-35`: `LlmEnhancer` now adds Spanish/mixed
    prompt-language instructions while preserving canonical English intent
    labels, and `Disambiguator` now catches short English/Spanish
    "help with X" topic phrasing such as `Necesito ayuda con custodia` and
    `I need help with desalojo`.
22. `RAUD-19` also adds an authoritative offline multilingual routing evaluator
    (`MultilingualRoutingEvalRunner`, shared JSON fixtures, PHPUnit lock, and
    CLI report entrypoint) so Spanish and mixed routing/helpfulness coverage is
    executable without depending on live promptfoo traffic.
23. Live proof stays additive only: `promptfooconfig.deep.yaml` now includes a
    focused multilingual routing slice for paced endpoint verification, while
    unsupported non-Spanish languages remain outside scope and stay tracked as
    residual risk rather than implicit product expansion.
24. `RAUD-21` closes the repo-side retrieval/config governance gap behind
    `F-18`, `M4`, and `N-16`: retrieval index IDs now live in a dedicated
    `retrieval.*` block, LegalServer intake URL is runtime-only through
    `settings.php`, and `RetrievalConfigurationService` is the single runtime
    resolver for effective retrieval IDs plus canonical URLs.
25. `RAUD-21` also adds executable drift proof instead of policy-only
    assumptions: `/assistant/api/health` now exposes
    `checks.retrieval_configuration`, admin settings validate required
    machine-name retrieval IDs, and pure-PHP response/routing helpers no
    longer carry embedded canonical URL defaults.
26. Repo-side closure now also includes canonical active-sync ownership for the
    lexical Search API indexes (`faq_accordion`, `assistant_resources`) plus
    `ilas_site_assistant_update_10009()` to recreate them automatically on
    existing environments before config import.
27. Pantheon closure for `RAUD-21` completed on 2026-03-12 after deployment,
    lexical index recovery, runtime secret provisioning, and hosted
    verification that `dev`/`test`/`live` all report
    `checks.retrieval_configuration.status=healthy`.
28. `RAUD-22` removes the default full-resource cold-start dependency behind
    `M5` and `N-34`: sparse lexical resource retrieval no longer routes through
    `getAllResources()` just to fill remaining topic slots.
29. `RAUD-22` keeps the retrieval architecture unchanged while bounding the
    resource legacy fallback surfaces: `findByTypeLegacy()`, `findByTopic()`,
    and `findByServiceArea()` now query only `min(max(limit * 8, 20), 100)`
    published resource candidates before ranking.
30. FAQ legacy search is now bounded as well: `searchLegacy()` queries capped
    `faq_item` and `accordion_item` paragraph candidates instead of
    materializing the full FAQ corpus, with executable cold-start guard tests
    preventing regression.
31. Residual cold-cache tradeoff is explicit rather than hidden:
    `getCategoriesLegacy()` may still trigger the legacy full FAQ preload when
    the lexical FAQ index is unavailable, but that browse-only fallback is
    outside the normal request-path proof surface.
32. `RAUD-25` closes the repo-side crawler-policy gap behind `L2` and `N-29`:
    the authoritative static `web/robots.txt` now explicitly disallows
    `/assistant/api/` and `/index.php/assistant/api/` while leaving the public
    `/assistant` page crawlable.
33. The inactive Drupal `robotstxt` exports are also realigned to the same
    content and locked by `RobotsTxtCrawlerPolicyContractTest.php`, so a future
    source-of-truth shift cannot silently drop the assistant API disallow.
34. Edge behavior is documented conservatively rather than inferred: Pantheon
    non-production environments already serve platform-managed blanket
    `Disallow: /` crawler policy, but the repo still contains no proof of a
    production edge-only crawler rule.
35. Remaining `RAUD-25` closure work is deploy-bound: the finding must remain
    `Partially Fixed` until `https://idaholegalaid.org/robots.txt` is
    re-fetched after deployment and shown to serve the assistant API disallow.

## Phase-to-sprint mapping
| Phase | Scope | Sprint mapping |
|---|---|---|
| Phase 0 | Quick wins / safety hardening | Sprint 1 |
| Phase 1 | Observability + reliability baseline | Sprints 2-3 |
| Phase 2 | Retrieval quality + eval maturity | Sprints 4-5 |
| Phase 3 | UX polish + performance/cost optimization | Sprint 6 |

Explicit mapping:
- Phase 0 = Sprint 1.
- Phase 1 = Sprints 2-3.
- Phase 2 = Sprints 4-5.
- Phase 3 = Sprint 6.

## Phase 0 (Sprint 1): Quick wins / safety hardening
### Objectives
1. Resolve top security and config-governance unknowns that block downstream execution. (Refs: current-state §8; evidence-index CLAIM-012, CLAIM-095, CLAIM-113; system-map Diagram B; runbook §2)
2. Lock safety/compliance assumptions before adding new runtime complexity. (Refs: current-state §4C, §6; evidence-index CLAIM-039, CLAIM-058, CLAIM-090; system-map Diagram B; runbook §1)

### Key deliverables
1. CSRF auth matrix + endpoint hardening implementation scope and acceptance tests (`IMP-SEC-01`). (Refs: current-state §6, §8; evidence-index CLAIM-012, CLAIM-113; system-map Diagram B; runbook §2)
2. Config schema parity fix plan for `vector_search` and config drift checks (`IMP-CONF-01`). (Refs: current-state §4H, §5; evidence-index CLAIM-095, CLAIM-096; system-map Diagram A; runbook §4)
3. Policy governance spec for “no legal advice” audit fields and reporting (`IMP-GOV-01` prep). (Refs: current-state §4C, §4F; evidence-index CLAIM-039, CLAIM-047, CLAIM-058; system-map Diagram B; runbook §5)

### Entry criteria
1. Audit baseline accepted as source of truth. (Refs: current-state §1; evidence-index CLAIM-001; system-map Diagram A; runbook §4)
2. Security/compliance owner roles assigned for CSRF and policy workstreams. (Refs: current-state §7; evidence-index CLAIM-013; system-map Diagram A; runbook §1)

### Exit criteria
1. Authenticated/anonymous CSRF behavior verified with deterministic expected outcomes. (Refs: current-state §8; evidence-index CLAIM-113; system-map Diagram B; runbook §2)
2. `vector_search` config schema/export parity approach approved and test strategy defined. (Refs: current-state §4H; evidence-index CLAIM-095, CLAIM-096; system-map Diagram A; runbook §4)
3. Phase 1 observability stories have unblocked dependencies. (Refs: current-state §4F, §8; evidence-index CLAIM-120, CLAIM-122; system-map Diagram A; runbook §3)

### Phase 0 Exit #3 dependency disposition (2026-02-27)
1. `IMP-OBS-01` dependency status: **unblocked for planning/start** via
   readiness gates (runbook verification + observability redaction/queue
   contract tests), while telemetry activation remains a Phase 1 activity.
2. `IMP-TST-01` dependency status: **unblocked** via Pantheon/local
   verification source-of-truth (runbook command bundle + repo-scripted
   external CI runner promptfoo targeting).

### Suggested sprint breakdown
1. Week 1: CSRF matrix tests + runtime verification updates.
2. Week 2: Config parity and governance artifacts for compliance reporting.

### What we will NOT do
1. No live LLM enablement. (Refs: current-state §5; evidence-index CLAIM-119; system-map Diagram B; runbook §3)
2. No major UI redesign. (Refs: current-state §4A; evidence-index CLAIM-031; system-map Diagram A; runbook §2)
3. No broad architectural refactor beyond minimal seam prep. (Refs: current-state §3; evidence-index CLAIM-020; system-map Diagram B; runbook §4)

## Phase 1 (Sprints 2-3): Observability + reliability baseline
### Objectives
1. Establish production-grade visibility (errors, traces, performance, queue health, SLOs). (Refs: current-state §4F, §4G; evidence-index CLAIM-051, CLAIM-079, CLAIM-082, CLAIM-084; system-map Diagram B; runbook §3)
2. Formalize deterministic degrade behavior under dependency failures. (Refs: current-state §4B, §4D; evidence-index CLAIM-048, CLAIM-063, CLAIM-065; system-map Diagram B; runbook §2)
3. Convert existing test assets into enforced quality gates. (Refs: current-state §4F, §8; evidence-index CLAIM-086, CLAIM-105, CLAIM-122; system-map Diagram A; runbook §4)

### Key deliverables
1. Sentry and Langfuse staged enablement with redaction validation (`IMP-OBS-01`). (Refs: current-state §4F, §6; evidence-index CLAIM-079, CLAIM-083, CLAIM-120; system-map Diagram A; runbook §5)
2. SLO set and alert policy for availability/latency/errors/cron/queue (`IMP-SLO-01`). (Refs: current-state §4F, §4G; evidence-index CLAIM-084, CLAIM-121; system-map Diagram B; runbook §3)
3. CI integration for PHPUnit + promptfoo smoke/regression (`IMP-TST-01`). (Refs: current-state §4F, §8; evidence-index CLAIM-086, CLAIM-105, CLAIM-122; system-map Diagram A; runbook §3)
4. Failure-mode contract tests and replay/idempotency test coverage (`IMP-REL-01`, `IMP-REL-02`). (Refs: current-state §4B, §4D; evidence-index CLAIM-035, CLAIM-046, CLAIM-063; system-map Diagram B; runbook §4)

### Entry criteria
1. Phase 0 CSRF and config-parity blockers are resolved or have approved mitigations. (Refs: current-state §8; evidence-index CLAIM-113, CLAIM-095; system-map Diagram B; runbook §2)
2. Platform credentials and destination approvals are available for telemetry integrations. (Refs: current-state §4H; evidence-index CLAIM-098; system-map Diagram A; runbook §3)

### Phase 1 Entry #1 blocker disposition (2026-03-03)
1. B-01 is resolved for `/assistant/api/message` strict CSRF enforcement via authenticated/anonymous matrix tests and strict access-check routing contract. (Refs: current-state §6, §8; evidence-index CLAIM-012, CLAIM-123; runbook §2)
2. `/assistant/api/track` uses approved hybrid mitigation: same-origin `Origin`/`Referer` is the primary browser proof, session-bound bootstrap-token retry is recovery-only when both headers are missing, and flood limits remain active. No route-level CSRF requirement is added. (Refs: current-state §6; evidence-index CLAIM-012, CLAIM-123; runbook §2)
3. B-02 is resolved via `vector_search` schema/export parity and drift contract tests (`VectorSearchConfigSchemaTest`, `ConfigCompletenessDriftTest`). (Refs: current-state §4H, §5; evidence-index CLAIM-095, CLAIM-124; runbook §4)

### Phase 1 Entry #2 credential and destination disposition (2026-03-02)
1. Credential availability confirmed: `LANGFUSE_PUBLIC_KEY`, `LANGFUSE_SECRET_KEY`, and `SENTRY_DSN` provisioned via `_ilas_get_secret()` on all Pantheon environments. (Refs: evidence-index CLAIM-097, CLAIM-098, CLAIM-126; runtime artifact `phase1-observability-gates.txt`)
2. Approved destinations: Langfuse US cloud (`https://us.cloud.langfuse.com`) for trace export; Sentry (DSN-controlled) for error tracking with PII redaction enforced. (Refs: current-state §4H, §6; evidence-index CLAIM-083, CLAIM-098)
3. Phase constraints preserved: `langfuse.enabled=false` in exported config; `llm.enabled=false` on all environments; live sample rate policy-capped at 0.1. (Refs: evidence-index CLAIM-119, CLAIM-120)

### Exit criteria
1. Critical alerts and dashboards operate in non-live and are tested. (Refs: current-state §4F; evidence-index CLAIM-084; system-map Diagram A; runbook §3)
2. CI quality gate is mandatory for merge/release path. (Refs: current-state §8; evidence-index CLAIM-122; system-map Diagram A; runbook §3)
3. Reliability failure matrix tests pass against target environments. (Refs: current-state §4B, §4D; evidence-index CLAIM-048, CLAIM-063, CLAIM-065; system-map Diagram B; runbook §4)

### Phase 1 Exit #1 disposition (2026-03-03)
1. Cron SLO evaluation ordering is corrected so `hook_cron()` records run health before `SloAlertService::checkAll()`; alert check failures are isolated and logged without crashing cron.
2. Dashboard surfaces are verified in non-live with controller-level checks for `/assistant/api/health`, `/assistant/api/metrics`, and `/admin/reports/ilas-assistant` (local + Pantheon `dev`/`test`).
3. Runtime proof is captured in `docs/aila/runtime/phase1-exit1-alerts-dashboards.txt`, including watchdog evidence for `SLO violation:` rows with `@slo_dimension` context.
4. Regression locks are added via `CronHookSloAlertOrderingTest`, expanded dashboard functional tests in `AssistantApiFunctionalTest`, and `PhaseOneExitCriteriaOneGateTest`.
5. Residual risk remains: B-04 (sustained cron/queue load behavior) is unchanged and stays outside this exit criterion.

### Phase 1 Exit #3 disposition (2026-03-03)
1. Local reliability matrix suites pass for retrieval dependency degrade, consolidated integration failure mappings, and LLM dependency-failure handling (`DependencyFailureDegradeContractTest`, `IntegrationFailureContractTest`, `LlmEnhancerHardeningTest`).
2. Pantheon target-environment assumptions for matrix behavior are verified on `dev`/`test`/`live`: `llm.enabled=false`, `llm.fallback_on_error=true`, and `vector_search.enabled=false`.
3. Runtime proof is captured in `docs/aila/runtime/phase1-exit3-reliability-failure-matrix.txt`.
4. Scope constraints remain preserved: no live LLM enablement and no full retrieval-architecture redesign.

### Phase 1 Sprint 2 disposition (2026-03-03)
1. Sprint 2 scope is closed as implemented: Sentry/Langfuse bootstrap remains staged, Drupal log context is normalized with canonical telemetry fields, and initial SLO drafts remain enforced in code/docs/test gates. (Refs: current-state §4F, §8; evidence-index CLAIM-079, CLAIM-084, CLAIM-129; runbook §3)
2. `TelemetrySchema::toLogContext()` is the canonical source for critical pipeline log contexts while preserving legacy placeholder aliases used by existing message strings. (Refs: evidence-index CLAIM-129)
3. Critical exits and completion/error logs in `AssistantApiController::message()` now carry canonical telemetry keys `intent`, `safety_class`, `fallback_path`, `request_id`, and `env` without changing response contracts. (Refs: current-state §4B; evidence-index CLAIM-048, CLAIM-129; runbook §2)
4. Scope boundaries remain unchanged: no live LLM rollout and no full redesign of retrieval architecture. (Refs: current-state §5, §4D; evidence-index CLAIM-119, CLAIM-060, CLAIM-065; runbook §3, §4)
5. Residual risk remains unchanged: B-04 (sustained cron/queue load behavior) stays open and outside Sprint 2 closure. (Refs: current-state §8; evidence-index CLAIM-118, CLAIM-121; runbook §3)

### Phase 1 Sprint 3 disposition (2026-03-03)
1. Sprint 3 scope is closed as implemented: alert policy finalization is verified through non-live alert/dashboard checks and SLO alert policy enforcement for availability/latency/error/cron/queue dimensions. (Refs: current-state §4F, §4G; evidence-index CLAIM-121, CLAIM-127, CLAIM-130; runbook §3)
2. CI gate rollout is completed and mandatory for merge/release path via first-party workflow coverage (`master`/`main`/`release/**`) and branch protection required checks (`PHPUnit Quality Gate`, `Promptfoo Gate`) on `master`. (Refs: current-state §8; evidence-index CLAIM-122, CLAIM-130; runbook §3, §4)
3. Reliability failure matrix completion is verified by local contract suites and Pantheon target-environment checks (`llm.enabled=false`, `llm.fallback_on_error=true`, `vector_search.enabled=false`) with runtime proof captured. (Refs: current-state §4B, §4D; evidence-index CLAIM-048, CLAIM-063, CLAIM-065, CLAIM-128, CLAIM-130; runbook §4)
4. Scope boundaries remain unchanged: no live LLM rollout and no full redesign of retrieval architecture. (Refs: current-state §5, §4D; evidence-index CLAIM-119, CLAIM-060, CLAIM-065; runbook §3, §4)
5. Residual risk remains unchanged: B-04 (sustained cron/queue load behavior) stays open and outside Sprint 3 closure. (Refs: current-state §8; evidence-index CLAIM-118, CLAIM-121; runbook §3)

### Phase 1 NDO #2 disposition (2026-03-03)
1. Phase 1 "What we will NOT do #2" is now explicitly enforceable as a scope boundary: no full redesign of retrieval architecture. (Refs: current-state §4D; evidence-index CLAIM-060, CLAIM-065, CLAIM-131; runbook §4)
2. Enforcement is wired through a dedicated runbook verification bundle and a docs/service continuity guard test (`PhaseOneNoRetrievalArchitectureRedesignGuardTest.php`) covering roadmap/current-state/evidence/system-map/service anchors. (Refs: evidence-index CLAIM-131; runbook §4)
3. This closure introduces boundary enforcement artifacts only and does not alter retrieval runtime behavior, retrieval pipeline architecture, or Phase 1 constraints. (Refs: current-state §4D, §5; evidence-index CLAIM-060, CLAIM-065, CLAIM-119, CLAIM-131; runbook §4)
4. Scope boundaries remain unchanged: no live LLM rollout and no full redesign of retrieval architecture. (Refs: current-state §5, §4D; evidence-index CLAIM-119, CLAIM-060, CLAIM-065, CLAIM-131; runbook §3, §4)

### Suggested sprint breakdown
1. Sprint 2: Sentry/Langfuse bootstrap, log schema normalization, initial SLO drafts.
2. Sprint 3: Alert policy finalization, CI gate rollout, reliability failure matrix completion.

### What we will NOT do
1. No live LLM rollout. (Refs: current-state §5; evidence-index CLAIM-119; system-map Diagram B; runbook §3)
2. No full redesign of retrieval architecture. (Refs: current-state §4D; evidence-index CLAIM-060, CLAIM-065; system-map Diagram B; runbook §4)

## Phase 2 (Sprints 4-5): Retrieval quality + eval harness maturity
### Objectives
1. Raise grounding quality with confidence-aware response behavior and citation-first responses. (Refs: current-state §4D; evidence-index CLAIM-062, CLAIM-065; system-map Diagram B; runbook §4)
2. Mature evaluation coverage and release confidence for RAG/response correctness. (Refs: current-state §4F; evidence-index CLAIM-086, CLAIM-105; system-map Diagram A; runbook §4)
3. Enforce governance around source freshness and provenance. (Refs: current-state §4D, §8; evidence-index CLAIM-067, CLAIM-122; system-map Diagram A; runbook §4)

### Key deliverables
1. `/assistant/api/message` contract expansion proposal and rollout plan: `confidence`, `citations[]`, `decision_reason`, request-id normalization. (Refs: current-state §4B, §4D; evidence-index CLAIM-035, CLAIM-049, CLAIM-062; system-map Diagram B; runbook §4)
2. Retrieval confidence/refusal thresholds integrated with eval harness and regression gating (`IMP-RAG-01`). (Refs: current-state §4D; evidence-index CLAIM-062, CLAIM-065, CLAIM-086; system-map Diagram B; runbook §4)
3. Vector index hygiene policy, metadata standards, and refresh monitoring (`IMP-RAG-02`). (Refs: current-state §4D, §4G; evidence-index CLAIM-066, CLAIM-067, CLAIM-121; system-map Diagram A; runbook §4)
4. Promptfoo dataset expansion for weak grounding, escalation, and safety boundary scenarios. (Refs: current-state §4C, §4F; evidence-index CLAIM-055, CLAIM-086, CLAIM-105; system-map Diagram B; runbook §4)

### Entry criteria
1. Observability + CI baselines are operational from Phase 1. (Refs: current-state §4F; evidence-index CLAIM-084, CLAIM-122; system-map Diagram A; runbook §3)
2. Config parity and retrieval tuning controls are stable across environments. (Refs: current-state §4H, §5; evidence-index CLAIM-095, CLAIM-096, CLAIM-116; system-map Diagram A; runbook §3)

### Phase 2 Entry #1 disposition (2026-03-04)
1. Entry criterion #1 is closed as operational: observability baseline continuity from Phase 1 remains active through SLO-backed health/metrics contracts and alert-policy enforcement (`CLAIM-084`). (Refs: current-state §4F; evidence-index CLAIM-084, CLAIM-138; system-map Diagram A; runbook §3)
2. CI baseline continuity from Phase 1 remains operational through first-party workflow coverage (`.github/workflows/quality-gate.yml`), repo-owned gate scripts (`scripts/ci/run-promptfoo-gate.sh`, `scripts/ci/run-external-quality-gate.sh`), and branch-aware merge/release gate policy (`CLAIM-122`). (Refs: current-state §4F, §8; evidence-index CLAIM-122, CLAIM-138; system-map Diagram A; runbook §3, §4)
3. Runtime verification evidence for `VC-RUNBOOK-LOCAL` and `VC-TOGGLE-CHECK` is captured in `docs/aila/runtime/phase2-entry1-observability-ci-baseline.txt`. (Refs: evidence-index CLAIM-138; runbook §3)
4. Scope boundaries remain unchanged: no live production LLM enablement in Phase 2 and no broad platform migration outside current Pantheon baseline. (Refs: current-state §1, §5; evidence-index CLAIM-115, CLAIM-119, CLAIM-138; runbook §3)

### Phase 2 Entry #2 disposition (2026-03-04)
1. Entry criterion #2 is closed: config parity and retrieval tuning controls are stable across environments, enforced by `VectorSearchConfigSchemaTest` (4 tests) and `ConfigCompletenessDriftTest` (5 tests) providing three-way parity (install defaults / active config export / schema). (Refs: current-state §4H, §5; evidence-index CLAIM-095, CLAIM-124, CLAIM-139; runbook §3)
2. Retrieval tuning controls are verified stable: `vector_search` block (7 keys) and `fallback_gate.thresholds` block (12 keys) are present in schema, install defaults, and active config export with matching values. (Refs: current-state §4H; evidence-index CLAIM-096, CLAIM-124, CLAIM-139; runbook §3)
3. Runtime verification evidence for `VC-RUNBOOK-LOCAL` and `VC-TOGGLE-CHECK` is captured in `docs/aila/runtime/phase2-entry2-config-parity-retrieval-tuning.txt`. (Refs: evidence-index CLAIM-139; runbook §3)
4. Scope boundaries remain unchanged: no live production LLM enablement in Phase 2 and no broad platform migration outside current Pantheon baseline. (Refs: current-state §1, §5; evidence-index CLAIM-115, CLAIM-119, CLAIM-139; runbook §3)

### Exit criteria
1. Retrieval contract and confidence logic pass regression thresholds. (Refs: current-state §4D, §4F; evidence-index CLAIM-062, CLAIM-086; system-map Diagram B; runbook §4)
2. Citation coverage and low-confidence refusal metrics are within approved targets. (Refs: current-state §4D; evidence-index CLAIM-065; system-map Diagram B; runbook §4)
3. Live LLM remains disabled pending Phase 3 readiness review. (Refs: current-state §5; evidence-index CLAIM-119; system-map Diagram B; runbook §3)

### Phase 2 Exit #1 disposition (2026-03-04)
1. Exit criterion #1 is closed as implemented: retrieval contract and confidence logic regression thresholds are enforced through the Promptfoo harness and gate summary contract fields (`rag_contract_meta_fail`, `rag_citation_coverage_fail`, `rag_low_confidence_refusal_fail`) with no production runtime contract expansion beyond `P2-DEL-01`. (Refs: current-state §4D, §4F; evidence-index CLAIM-062, CLAIM-086, CLAIM-140; runbook §4)
2. Runtime verification evidence for `VC-RUNBOOK-LOCAL`, `VC-RUNBOOK-PANTHEON`, and full promptfoo gate execution is captured in `docs/aila/runtime/phase2-exit1-retrieval-contract-confidence-thresholds.txt`. (Refs: evidence-index CLAIM-140; runbook §4)
3. Retrieval architecture remains unchanged: Search API lexical retrieval with optional vector supplementation and legacy fallback paths remains the operative pattern. (Refs: current-state §4D; evidence-index CLAIM-060, CLAIM-065; system-map Diagram B; runbook §4)
4. Scope boundaries remain unchanged: no live production LLM enablement in Phase 2 and no broad platform migration outside current Pantheon baseline. (Refs: current-state §1, §5; evidence-index CLAIM-115, CLAIM-119, CLAIM-140; runbook §3)

### Phase 2 Exit #2 disposition (2026-03-04)
1. Exit criterion #2 is closed as implemented: citation coverage and low-confidence refusal metrics are within approved targets, enforced through the Promptfoo harness gate summary contract fields (`rag_citation_coverage_fail`, `rag_low_confidence_refusal_fail`) with 90% per-metric threshold policy in `scripts/ci/run-promptfoo-gate.sh`. (Refs: current-state §4D; evidence-index CLAIM-065, CLAIM-086, CLAIM-141; runbook §4)
2. Runtime verification evidence for `VC-RUNBOOK-LOCAL`, `VC-RUNBOOK-PANTHEON`, and scenario anchor checks is captured in `docs/aila/runtime/phase2-exit2-citation-coverage-refusal-targets.txt`. (Refs: evidence-index CLAIM-141; runbook §4)
3. Scenario coverage scope: 10 `rag-citation-coverage` scenarios and 10 `rag-low-confidence-refusal` scenarios in `promptfoo-evals/tests/retrieval-confidence-thresholds.yaml` are anchored and verified present. (Refs: evidence-index CLAIM-086, CLAIM-141; runbook §4)
4. Scope boundaries remain unchanged: no live production LLM enablement in Phase 2 and no broad platform migration outside current Pantheon baseline. (Refs: current-state §1, §5; evidence-index CLAIM-115, CLAIM-119, CLAIM-141; runbook §3)

### Phase 2 Exit #3 disposition (2026-03-04)
1. Exit criterion #3 is closed as implemented: live LLM remains disabled pending Phase 3 readiness review, with layered runtime guardrails in `settings.php`, `AssistantSettingsForm`, and service-level live-environment hard-disable checks in `LlmEnhancer` + `FallbackGate` to prevent live LLM activation from config drift. (Refs: current-state §5; evidence-index CLAIM-119, CLAIM-142; system-map Diagram B; runbook §3)
2. Runtime verification evidence for `VC-RUNBOOK-LOCAL` and `VC-RUNBOOK-PANTHEON` is captured in `docs/aila/runtime/phase2-exit3-live-llm-disabled-phase3-readiness.txt`. (Refs: evidence-index CLAIM-142; runbook §3)
3. Scope boundaries remain unchanged: no live production LLM enablement in Phase 2 and no broad platform migration outside current Pantheon baseline. (Refs: current-state §1, §5; evidence-index CLAIM-115, CLAIM-119, CLAIM-142; runbook §3)
4. Residual risk posture remains unchanged: B-04 (sustained cron/queue behavior under load) continues to require longitudinal runtime observation outside this closure item. (Refs: current-state §8; evidence-index CLAIM-118, CLAIM-121, CLAIM-142; runbook §3)

### Phase 2 Objective #2 disposition (2026-03-03)
1. Objective #2 is closed as implemented: evaluation coverage and release confidence for RAG/response correctness are formalized through repo-owned gate contracts, verification commands, and closure guard tests grounded in CLAIM-086/CLAIM-105 evidence paths. (Refs: current-state §4F; evidence-index CLAIM-086, CLAIM-105, CLAIM-132; system-map Diagram A; runbook §4)
2. Release confidence enforcement uses branch-aware Promptfoo gate behavior (`master`/`main`/`release/*` blocking; other branches advisory), with deep multi-turn (`promptfooconfig.deep.yaml`) plus abuse/safety (`promptfooconfig.abuse.yaml`) coverage retained in gate policy. (Refs: evidence-index CLAIM-086, CLAIM-132; runbook §4)
3. Deterministic response-correctness confidence remains contract-enforced through `VC-UNIT` and `VC-DRUPAL-UNIT` suites, including SafetyClassifier and OutOfScopeClassifier Drupal-unit coverage tied to existing gate scripts. (Refs: current-state §4F; evidence-index CLAIM-105, CLAIM-132; runbook §4)
4. Scope boundaries remain unchanged: no live production LLM enablement in Phase 2 and no broad platform migration outside current Pantheon baseline. (Refs: current-state §1, §5; evidence-index CLAIM-115, CLAIM-119, CLAIM-132; runbook §3)

### TOVR-06 New Relic binary decision — RETIRE (2026-03-16)
1. Decision: retire all AILA-owned New Relic integration. Zero data was flowing, browser snippet was dormant on all environments, Quicksilver change tracking was broken, and browser error capture duplicated proven Sentry coverage.
2. Code cleanup: deleted deploy-marker script, DDEV NR scaffold, NR setup doc; removed NR wiring from settings.php, b5subtheme.theme, html.html.twig, ilas_site_assistant.module, observability.js, .ddev/.env.example, .gitignore, and pantheon.yml workflows block.
3. Test updates: removed NR assertions from ObservabilityRuntimeContractTest, ObservabilityBrowserAssetContractTest, AuditBaselineAcceptanceTest, and observability-assistant-error.test.js.
4. Residual CWV/RUM gap is addressable via GA4 Web Vitals or a future platform-level (not AILA) decision. Pantheon platform APM is a separate concern and is not affected. (Refs: current-state §3, §8; evidence-index CLAIM-189, CLAIM-190, CLAIM-193, CLAIM-203; runtime/tovr-06-new-relic-decision.txt)

### TOVR-05 deploy-bound gate disposition (2026-03-16)
1. Prior status is now explicitly recorded: representative PR runs executed real promptfoo evals in advisory mode, representative protected-branch pushes executed simulated config-parity mode in blocking status, and synced `origin/master` deploy pushes skipped local promptfoo entirely.
2. Post-change status is now split by proof surface: helper `publish/master-*` PRs and protected-branch GitHub pushes both run hosted blocking checks with `promptfooconfig.hosted.yaml`, ordinary feature PRs remain hosted advisory, `git:finish` refuses merges when the helper PR artifact records advisory/simulated/failed Promptfoo results, and synced `origin/master` deploy pushes still block on local DDEV exact-code evals with `promptfooconfig.deploy.yaml --no-deep-eval`.
3. Deep multi-turn coverage remains in the broader repo-owned eval program but stays outside the deploy-bound gate so the hosted GitHub profile remains within the current `120/hour` Pantheon `dev` budget.
4. Hosted GitHub promptfoo runs remain mandatory merge and protected-branch checks, but deploy proof for Pantheon `dev` now comes from the local DDEV exact-code gate rather than hosted results. (Refs: current-state §4F, §8; evidence-index CLAIM-200, CLAIM-211; runbook §4)

### TOVR-08 override-aware runtime truth disposition (2026-03-17)
1. Repo/runtime verification now has a dedicated helper: `ilas:runtime-truth` backed by `RuntimeTruthSnapshotBuilder` emits sanitized stored-versus-effective JSON for override-prone assistant settings without printing secrets.
2. Canonical `VC-RUNTIME-LOCAL-SAFE` and `VC-RUNTIME-PANTHEON-SAFE` now use `drush status`, `drush ilas:runtime-truth`, and a rendered `/assistant` marker sample. `config:get raven.settings`, `config:get langfuse.settings`, and `config:get` on override-prone AILA keys are now explicitly storage-only historical evidence rather than runtime truth.
3. AFRP-02 later expands the same helper/report path beyond the original observability-heavy scope so it now covers assistant rate limits, conversation logging invariants, retrieval/runtime-only URL status, LLM readiness/cost-control config, and Voyage readiness in addition to Langfuse/Sentry/Pinecone/GA surfaces. (Refs: evidence-index CLAIM-256, CLAIM-257; runbook §3)
4. Fresh local post-edit verification passed, and TOVR-09 read-only reruns now confirm Pantheon `dev` / `test` / `live` execute `ilas:runtime-truth` successfully. Hosted post-change runtime proof is no longer deployment-gated on those environments. (Refs: current-state §5, §8; evidence-index CLAIM-213, CLAIM-214, CLAIM-215, CLAIM-216; runbook §3)
5. Scope boundaries remain unchanged: no live production LLM enablement in Phase 2 and no broad platform migration outside current Pantheon baseline.

### AFRP-02 runtime truth expansion (2026-03-18)
1. The canonical runtime-truth surface now distinguishes stored config from effective runtime behavior for assistant message/read/session rate limits, conversation-logging invariants, retrieval IDs plus runtime-only LegalServer URL health, LLM runtime readiness and cost-control config, Voyage runtime readiness, and prior observability integrations. (Refs: evidence-index CLAIM-256; runbook §3)
2. Current 2026-03-18 proof stays explicit rather than inferred: `local` / `dev` / `test` still report stored `vector_search.enabled=false` versus effective `true`; `live` still reports effective `vector_search.enabled=false`; `langfuse.enabled` remains stored `false` versus effective `true` in all sampled environments; `live` still reports effective `google_tag_id=true`; and `diagnostics_token_present=false` remains true everywhere sampled. (Refs: evidence-index CLAIM-258; runbook §3)
3. Operator guidance is now stricter: `config:get` on override-prone assistant settings is storage-only and historical, while authenticated `remote:drush ... -- ilas:runtime-truth` is the Pantheon read-only runtime standard until diagnostics tokens exist. (Refs: evidence-index CLAIM-257, CLAIM-258; runbook §3)

### TOVR-09 Pinecone environment inventory disposition (2026-03-17)
1. TOVR-09 captured the then-current Pinecone secret/runtime truth: `local`, `dev`, `test`, and `live` all reported Pinecone key presence in effective runtime, while the old `vector_search.enabled=false` assumption across all four sampled environments was later superseded by TOVR-12, TOVR-16, and AFRP-02. (Refs: current-state §1, §5; evidence-index CLAIM-216, CLAIM-218; runbook §3)
2. Hosted Pinecone/Search API posture is stronger than the prior docs implied: `dev`, `test`, and `live` now prove enabled lexical + vector indexes, populated vector trackers, and successful read-only sample vector queries for both FAQ and resource indexes. (Refs: current-state §1, §4D; evidence-index CLAIM-217; runbook §3)
3. Local differs materially from the hosted baseline: `search-api:list` currently lacks the canonical lexical indexes, `updatedb:status` still reports `ilas_site_assistant` updates `10008` / `10009` / `10010`, `config:status` still reports drift on `ilas_site_assistant.settings` and `key.key.gemini_api_key`, and both local sample vector queries fail with the AI provider `ProviderProxy` "unregistered callers" error. (Refs: current-state §1, §4D; evidence-index CLAIM-217; runbook §3)
4. Live enablement remains blocked by policy and readiness, not by hosted secret/index absence: TOVR-12 proves `local` / `dev` / `test` can be runtime-enabled under lexical-first safeguards, and TOVR-13 later confirms the current blockers are rollout quality/monitoring/timeout evidence rather than missing live secrets or indexes. (Refs: current-state §1, §8; evidence-index CLAIM-220, CLAIM-229, CLAIM-230, CLAIM-232, CLAIM-235, CLAIM-238; runbook §3)

### TOVR-12 Pinecone non-live enablement disposition (2026-03-17)
1. Runtime-only Pinecone enablement is now proven on `local`, `dev`, and `test`: sync config remains `vector_search.enabled=false`, but runtime truth shows stored `false` versus effective `true` on all three non-live environments while `live` remains forced off. (Refs: current-state §1, §8; evidence-index CLAIM-229, CLAIM-230, CLAIM-231; runbook §3)
2. The real `test` blocker was a precedence bug in `settings.php`, not an unknown Pantheon state: a falsey hosted secret previously bypassed the private flag fallback. TOVR-12 fixes that path and leaves `private://ilas-vector-search-enabled.txt` as a supported rollbackable override for `dev` / `test`. (Refs: current-state §1, §8; evidence-index CLAIM-231; runbook §3)
3. Post-enable retrieval proof is acceptable but not perfect: the eviction-notices prompt now shows mixed lexical/vector provenance on both hosted non-live environments, while lexical-control prompts remain stable. Prompts 2 / 3 still need stronger recall/relevance proof, so the remaining risk is quality validation rather than basic enablement. (Refs: current-state §4D, §8; evidence-index CLAIM-232; runbook §3, §4)
4. Live remains closed. TOVR-13 now records the production decision as `Blocked with explicit evidence`, with shared/global embeddings-timeout policy, incomplete prompt-level recall proof, missing positive diagnostics-token monitoring, and a red latest `master` quality gate all remaining open inputs. (Refs: current-state §1, §8; evidence-index CLAIM-227, CLAIM-228, CLAIM-232, CLAIM-233, CLAIM-234, CLAIM-238; runbook §3, §4)

### TOVR-13 Pinecone live readiness disposition (2026-03-17)
1. Live infrastructure is now explicitly verified rather than inferred: hosted `live` runtime truth shows release `live_148`, effective Pinecone/Langfuse/Sentry wiring, `vector_search.enabled=false`, `llm.enabled=false`, `debug_metadata_force_disable=true`, and `diagnostics_token_present=false`. Live Pinecone indexes remain fully indexed (`faq_accordion_vector` `883/883`, `assistant_resources_vector` `152/152`) with `healthy` retrieval and hygiene snapshots. (Refs: current-state §1, §4D, §5; evidence-index CLAIM-234, CLAIM-235; runbook §3)
2. Current live observability capture is proven but not rollout-complete: fresh live Sentry probe evidence exists and live issues are queryable through the Sentry API, while fresh direct Langfuse probe evidence still returns HTTP `207`. TOVR-13 also adds vector-specific Langfuse trace metadata in repo code so future hosted traces can answer whether vector retrieval was enabled, attempted, used, or degraded. (Refs: current-state §1, §4F, §8; evidence-index CLAIM-236, CLAIM-237; runbook §3, §5)
3. The final TOVR-13 verdict is `Blocked with explicit evidence`, not `Ready for live enablement`: the latest `master` Quality Gate run `23218168501` is still red, positive machine-auth diagnostics remain unproven because the live diagnostics token is absent, prompts 2 / 3 still lack accepted vector-quality proof, and embeddings-side timeout governance is still shared/global. (Refs: current-state §1, §8; evidence-index CLAIM-233, CLAIM-234, CLAIM-238; runbook §3, §4)
4. Exact prerequisites are now fixed in repo documentation: green replacement quality gate, live diagnostics-token proof or a formally adopted authenticated drush-monitoring standard, deploy and hosted proof for the new vector Langfuse metadata, accepted prompt-level quality scope, embeddings-timeout separation, and a documented runtime-only rollback sequence for any future live toggle. (Refs: current-state §8; evidence-index CLAIM-233, CLAIM-237, CLAIM-238; runbook §3, §4)

### TOVR-14 AI/provider footprint rationalization disposition (2026-03-17)
1. Enabled AI/provider modules are now reduced to the minimum proven vector stack: `ai`, `ai_search`, `ai_vdb_provider_pinecone`, `gemini_provider`, and `key` remain enabled because the current `pinecone_vector` Search API server still depends on Search API AI plus Gemini embeddings/chat and Pinecone key wiring. Dormant `ai_provider_google_vertex`, `ai_seo`, and `metatag_ai` are uninstalled along with their exported config. (Refs: current-state §1, §3, §4H; evidence-index CLAIM-239, CLAIM-241; runbook §3)
2. Scope stays intentionally narrow: Composer packages remain installed, no new LLM features are enabled, and the custom assistant's dormant Vertex path in `settings.php` / `LlmEnhancer` is left alone. The cleanup only removes unused Drupal-enabled module/config surface. (Refs: current-state §1, §4H; evidence-index CLAIM-239, CLAIM-241; runbook §3)
3. Local post-change proof is acceptable: focused contract tests stay green, `ilas:runtime-truth` still reports effective Pinecone/Gemini wiring, Search API vector indexes remain enabled/populated, and local assistant smoke behavior is unchanged across the fixed prompts used in TOVR-13/TOVR-12 follow-up. (Refs: current-state §1, §8; evidence-index CLAIM-240; runbook §3, §4)
4. Hosted post-change verification remains `Unverified` until deploy. The canonical residual risk is now the dormant runtime-only Vertex secret path plus any future re-expansion of AI provider surface, not synced Drupal config carrying Vertex credentials. (Refs: current-state §1, §8; evidence-index CLAIM-241, CLAIM-242; runbook §3)

### TOVR-16 final consolidation disposition (2026-03-18)
1. Cross-tool findings: fresh TOVR-16 reruns supersede three stale pending notes without reopening earlier prompt conclusions. `Observability Release` now has a successful GitHub run `23165713689`, assistant-route GA suppression is now proven on hosted `live` release `live_149`, and `local` / `dev` / `test` still report effective `vector_search.enabled=true` while `live` remains `false`. The latest `master` Quality Gate run is now `23225344665`, and it is still red because `Promptfoo Gate` failed while `PHPUnit Quality Gate` passed. Fresh safe-runtime reruns also tighten the diagnostics posture: `diagnostics_token_present=false` now appears in every sampled environment. (Refs: current-state §1, §8; evidence-index CLAIM-246, CLAIM-247, CLAIM-248, CLAIM-249, CLAIM-250; runbook §3)
2. Phased remediation:
   - Phase 1 closes observability truth gaps: prove Sentry browser JS source-map usefulness on the current deployed asset bundle, rerun hosted Langfuse direct/queued probes plus one live vector-field trace proof, and choose a binding diagnostics-monitoring standard (`ILAS_ASSISTANT_DIAGNOSTICS_TOKEN` or authenticated `remote:drush` plus probe commands). (Refs: current-state §1, §8; evidence-index CLAIM-247, CLAIM-248, CLAIM-250, CLAIM-251; runbook §3, §5)
   - Phase 2 closes live Pinecone rollout blockers: replace the failing latest `master` gate with a green replacement, either prove accepted vector improvement on prompts 2 / 3 or narrow the rollout scope in writing, separate embeddings-side timeout governance from the shared/global `ai.settings.request_timeout`, and keep the live rollback path runtime-only and documented. (Refs: current-state §1, §8; evidence-index CLAIM-246, CLAIM-248, CLAIM-250, CLAIM-251; runbook §3, §4)
   - Phase 3 reduces residual surface or captures optional expansion work: retire stale New Relic secrets if platform ownership agrees, complete the prove-and-clean decision on residual Gemini Search API/vector usage, and keep the retired Vertex assistant surface blocked from reappearing. Platform-level RUM/CWV remains outside AILA scope unless a separate platform decision funds it. (Refs: current-state §1, §8; evidence-index CLAIM-203, CLAIM-241, CLAIM-249, CLAIM-250, CLAIM-251; runbook §3)
3. Feature expansion opportunities: stale NR secret retirement is low-cost hygiene with modest value; residual Gemini vector/Search API cleanup is medium-cost with medium maintenance value; platform-level browser performance telemetry remains a separate governance/cost decision because TOVR-06 retired AILA-owned NR and TOVR-15 keeps assistant GA denied. (Refs: current-state §1, §8; evidence-index CLAIM-203, CLAIM-241, CLAIM-249, CLAIM-251; runbook §3)
4. Final scorecard and dependency summary:

| Area | State | Blocking dependency |
|---|---|---|
| Promptfoo/CI | `BLOCKED` | Green replacement `master` Quality Gate after failed run `23225344665` |
| Sentry | `partially proven` | Browser JS original-source resolution on the deployed asset bundle |
| Langfuse | `BLOCKED` | Hosted queued-export parity plus hosted live trace proof for the vector fields |
| Internal telemetry | `BLOCKED` | Diagnostics token provisioning or an approved authenticated drush-monitoring standard |
| Pinecone non-live | `closed by fresh evidence` | Continue monitoring only |
| Pinecone live | `BLOCKED` | Green `master` gate, prompt 2 / 3 acceptance, diagnostics standard, embeddings-timeout separation |
| Privacy/analytics | `closed by fresh evidence` | Continue regression monitoring only |
| AI/provider footprint | `historically closed but still monitored` | Residual Gemini Search API/vector proof decision plus Vertex reintroduction prevention |

   (Refs: current-state §1, §8; evidence-index CLAIM-246, CLAIM-247, CLAIM-248, CLAIM-249, CLAIM-250, CLAIM-251; runbook §3)

### Phase 2 Objective #3 disposition (2026-03-03)
1. Objective #3 is closed as implemented: source freshness and provenance governance is now enforced through additive config policy, retrieval-result annotations, observation snapshots, health/metrics exposure, and objective-level closure guards without runtime filtering side effects. (Refs: current-state §4D, §8; evidence-index CLAIM-067, CLAIM-122, CLAIM-133; system-map Diagram A; runbook §4)
2. Governance enforcement mode is soft-governance enforcement mode only: stale/missing/unknown conditions raise annotations, observability counters, and cooldowned alerts, while retrieval ranking/filtering behavior remains unchanged. (Refs: current-state §4D; evidence-index CLAIM-067, CLAIM-133; runbook §4)
3. Source governance scope is locked to four source classes (`faq_lexical`, `faq_vector`, `resource_lexical`, `resource_vector`) with policy-versioned provenance metadata and freshness thresholds documented and test-locked in repo artifacts. (Refs: current-state §4D; evidence-index CLAIM-067, CLAIM-133; runbook §4)
4. Scope boundaries remain unchanged: no live production LLM enablement in Phase 2 and No broad platform migration outside current Pantheon baseline. (Refs: current-state §1, §5; evidence-index CLAIM-115, CLAIM-119, CLAIM-133; runbook §3)
5. Follow-on governance tuning applies balanced ratio+sample degrade thresholds (`min_observations=20`, `unknown_ratio_degrade_pct=22.0`, `missing_source_url_ratio_degrade_pct=9.0`) and snapshot cooldown transparency fields (`last_alert_at`, `next_alert_eligible_at`, `cooldown_seconds_remaining`) while preserving soft-alert-only semantics. (Refs: current-state §4D, §8; evidence-index CLAIM-133, CLAIM-144; runbook §4)

### Phase 2 Deliverable #1 disposition (2026-03-03)
1. Key deliverable #1 is closed as implemented: `/assistant/api/message` 200-response contract now includes `confidence` (float 0-1), `citations[]` (formalized from ResponseGrounder sources), and `decision_reason` (human-readable string from FallbackGate reason codes or path-specific defaults). (Refs: current-state §4B, §4D; evidence-index CLAIM-134; runbook §4)
2. Request-id normalization is verified complete (IMP-REL-02): `resolveCorrelationId()` validates UUID4, rejects invalid, and generates fallback — no additional changes needed. (Refs: current-state §4B; evidence-index CLAIM-035, CLAIM-134; runbook §4)
3. Contract fields are injected across the current 200-response branches via `assembleContractFields()`: safety exit, repeated-message escalation, OOS exit, policy exit, normal pipeline completion, and retrieval-unavailable degraded navigation. Error responses (4xx/5xx) are excluded and retain their minimal shape. (Refs: evidence-index CLAIM-134; runbook §4)
4. Langfuse grounding span bug fixed: citation field check now uses `sources` (produced by ResponseGrounder) rather than `citations` (populated later by contract assembly). (Refs: evidence-index CLAIM-134)
5. Scope boundaries remain unchanged: no live production LLM enablement in Phase 2 and No broad platform migration outside current Pantheon baseline. (Refs: current-state §1, §5; evidence-index CLAIM-115, CLAIM-119, CLAIM-134; runbook §3)

### Phase 2 Deliverable #2 disposition (2026-03-03)
1. Key deliverable #2 is closed as implemented: retrieval confidence/refusal threshold checks are integrated into the Promptfoo eval harness via contract-metadata assertions and dedicated threshold scenarios (`rag-contract-meta-present`, `rag-citation-coverage`, `rag-low-confidence-refusal`). (Refs: current-state §4D, §4F; evidence-index CLAIM-062, CLAIM-065, CLAIM-086, CLAIM-135; runbook §4)
2. Branch-aware regression gating now enforces metric-specific threshold policy for retrieval confidence/refusal checks in `scripts/ci/run-promptfoo-gate.sh` at 90% minimum per metric, with blocking behavior on `master`/`main`/`release/*` and advisory behavior elsewhere. (Refs: evidence-index CLAIM-086, CLAIM-135; runbook §4)
3. Gate summary artifacts now include retrieval-confidence threshold status and per-metric pass-rate fields, enabling deterministic regression diagnosis for confidence/citation/refusal drift. (Refs: current-state §4F; evidence-index CLAIM-086, CLAIM-135; runbook §4)
4. Backlog/risk linkage is advanced to active mitigation for retrieval-confidence ambiguity risk (`R-RAG-01`) while preserving ongoing monitoring for citation coverage and low-confidence refusal ratios. (Refs: current-state §4D, §8; evidence-index CLAIM-047, CLAIM-085, CLAIM-135; runbook §3, §4)
5. Scope boundaries remain unchanged: no live production LLM enablement in Phase 2 and no broad platform migration outside current Pantheon baseline. (Refs: current-state §1, §5; evidence-index CLAIM-115, CLAIM-119, CLAIM-135; runbook §3)

### Phase 2 Deliverable #3 disposition (2026-03-04)
1. Key deliverable #3 is closed as implemented: vector index hygiene policy, metadata standards, and refresh monitoring (`IMP-RAG-02`) are now enforced through a dedicated `VectorIndexHygieneService` with policy-versioned defaults and managed index standards for `faq_accordion_vector` and `assistant_resources_vector`. (Refs: current-state §4D, §4G; evidence-index CLAIM-066, CLAIM-067, CLAIM-136; runbook §4)
2. Cron now invokes hygiene refresh snapshots with per-index failure isolation, incremental-only indexing (`indexItems(max_items_per_run)`), 24-hour cadence checks, overdue grace handling, tracker backlog capture, and cooldowned degraded alerts. (Refs: current-state §4G; evidence-index CLAIM-121, CLAIM-136; runbook §4)
3. Monitoring contracts are extended additively without changing top-level payload shape: `/assistant/api/health` now exposes `checks.vector_index_hygiene`, and `/assistant/api/metrics` exposes `metrics.vector_index_hygiene` plus `thresholds.vector_index_hygiene`. (Refs: current-state §4F, §4G; evidence-index CLAIM-121, CLAIM-136; runbook §4)
4. Backlog/risk linkage is advanced to active mitigation for vector hygiene/freshness governance risks (`R-RAG-02`, `R-GOV-02`) with drift and overdue detection signals anchored to hygiene snapshots and metadata drift fields. (Refs: current-state §8; evidence-index CLAIM-067, CLAIM-136; runbook §4)
5. Scope boundaries remain unchanged: no live production LLM enablement in Phase 2 and no broad platform migration outside current Pantheon baseline. (Refs: current-state §1, §5; evidence-index CLAIM-115, CLAIM-119, CLAIM-136; runbook §3)

### Phase 2 Deliverable #4 disposition (2026-03-04)
1. Key deliverable #4 is closed as implemented: Promptfoo dataset coverage now explicitly includes weak grounding, escalation, and safety boundary scenarios through `promptfoo-evals/tests/grounding-escalation-safety-boundaries.yaml`, wired into the primary abuse gate config (`promptfooconfig.abuse.yaml`). (Refs: current-state §4C, §4F; evidence-index CLAIM-055, CLAIM-086, CLAIM-105, CLAIM-137; system-map Diagram B; runbook §4)
2. The initial deliverable closure added 36 scenarios (12 per family) with explicit `metadata.scenario_family` markers (`weak_grounding`, `escalation`, `safety_boundary`) and contract-metadata assertions over `confidence`, `response_type`, `response_mode`, `reason_code`, and `decision_reason`, plus family-level safety/actionability checks; Sprint 5 calibration later expands this dataset to 60 scenarios while preserving the same family/contract design. (Refs: current-state §4F; evidence-index CLAIM-086, CLAIM-137, CLAIM-144; runbook §4)
3. Closure enforcement is anchored through runbook verification commands and a dedicated continuity guard (`PhaseTwoDeliverableFourGateTest.php`) without modifying promptfoo gate threshold policy or production runtime behavior. (Refs: evidence-index CLAIM-086, CLAIM-105, CLAIM-137; runbook §4)
4. Backlog linkage remains unchanged for this deliverable: no direct backlog row is introduced and roadmap closure remains authoritative. (Refs: docs/aila/backlog.md; runbook §4)
5. Risk linkage is recorded for `R-MNT-02` and `R-LLM-01` with conservative text updates only; existing risk status values remain unchanged. (Refs: docs/aila/risk-register.md; evidence-index CLAIM-137)
6. Scope boundaries remain unchanged: no live production LLM enablement in Phase 2 and no broad platform migration outside current Pantheon baseline. (Refs: current-state §1, §5; evidence-index CLAIM-115, CLAIM-119, CLAIM-137; runbook §3)

### Phase 2 Sprint 4 disposition (2026-03-05)
1. Sprint 4 closure item is completed as specified: "Sprint 4: response contract + retrieval-confidence implementation and tests." Runtime retune is implemented across response contract normalization (`confidence`, `citations[]`, `decision_reason`) and retrieval no-results confidence handling while preserving existing response shape and call-path coverage. (Refs: current-state §4B, §4D; evidence-index CLAIM-143; runbook §4)
2. Retrieval gate behavior remains additive and deterministic: high-intent no-results retrieval paths keep `REASON_NO_RESULTS` answer routing but cap confidence at `<= 0.49`, with explicit debug marker fields for tuning observability and no change to live-LLM guardrails. (Refs: current-state §4B, §4D; evidence-index CLAIM-119, CLAIM-143; runbook §3, §4)
3. Promptfoo regression policy remains at 90% per-metric thresholds and now adds metric-count floor diagnostics (`rag_metric_min_count`, `rag_*_count_fail`) in gate summaries to reduce brittle pass/fail interpretation when scenario counts are insufficient. (Refs: current-state §4F; evidence-index CLAIM-086, CLAIM-135, CLAIM-143; runbook §4)
4. Sprint-level closure enforcement is now anchored through docs/runtime evidence plus dedicated guard tests (`PhaseTwoSprintFourGateTest.php`, `ResponseContractNormalizationTest.php`) and required validation aliases (`VC-UNIT`, `VC-QUALITY-GATE`). (Refs: current-state §8; evidence-index CLAIM-105, CLAIM-143; runbook §4)
5. Scope boundaries remain unchanged: no live production LLM enablement in Phase 2 and no broad platform migration outside current Pantheon baseline. (Refs: current-state §1, §5; evidence-index CLAIM-115, CLAIM-119, CLAIM-143; runbook §3)

### Phase 2 Sprint 5 disposition (2026-03-05)
1. Sprint 5 closure item is completed as specified: "Sprint 5: dataset expansion, provenance/freshness workflows, threshold calibration." Promptfoo `P2-DEL-04` scenario coverage is expanded to 60 total scenarios with exact family distribution (`weak_grounding=20`, `escalation=20`, `safety_boundary=20`) and calibrated metric floors preserved (`p2del04-contract-meta-present=60`, `p2del04-weak-grounding-handling=20`, `p2del04-escalation-routing=20`, `p2del04-escalation-actionability=20`, `p2del04-safety-boundary-routing=20`, `p2del04-boundary-dampening>=10`, `p2del04-boundary-urgent-routing>=10`). (Refs: current-state §4F; evidence-index CLAIM-086, CLAIM-137, CLAIM-144; runbook §4)
2. Promptfoo gate calibration is now enforceable in repo policy: `RAG_METRIC_MIN_COUNT` default is raised to `10`, `P2DEL04_METRIC_THRESHOLD` defaults to `85`, `P2DEL04_METRIC_MIN_COUNT` defaults to `10`, and gate summary contracts now expose `p2del04_*` rate/count/fail fields that participate in blocking/advisory pass-fail outcomes. (Refs: current-state §4F; evidence-index CLAIM-086, CLAIM-135, CLAIM-144; runbook §4)
3. Provenance/freshness governance and vector hygiene thresholds are calibrated in install + active config exports and mirrored in service defaults: source-governance (`stale_ratio_alert_pct=18.0`, `unknown_ratio_degrade_pct=22.0`, `missing_source_url_ratio_degrade_pct=9.0`) and vector-hygiene (`refresh_interval_hours=24`, `overdue_grace_minutes=45`, `max_items_per_run=60`, `alert_cooldown_minutes=60`). Governance remains soft-alert-only; no stale-result suppression or ranking/filtering penalties are introduced. (Refs: current-state §4D, §4G; evidence-index CLAIM-133, CLAIM-136, CLAIM-144; runbook §4)
4. Sprint-level closure enforcement is anchored through docs/runtime evidence and dedicated guard tests (`PhaseTwoSprintFiveGateTest.php`) with required validation aliases (`VC-UNIT`, `VC-QUALITY-GATE`) captured in `docs/aila/runtime/phase2-sprint5-closure.txt`. (Refs: current-state §8; evidence-index CLAIM-105, CLAIM-144; runbook §4)
5. System map continuity is unchanged for Sprint 5 scope; no diagram change required because no new architecture edge was introduced. (Refs: system-map Diagram A, Diagram B; evidence-index CLAIM-144; runbook §4)
6. Scope boundaries remain unchanged: no live production LLM enablement in Phase 2 and no broad platform migration outside current Pantheon baseline. (Refs: current-state §1, §5; evidence-index CLAIM-115, CLAIM-119, CLAIM-144; runbook §3)

### Phase 2 NDO #1 disposition (2026-03-04)
1. Phase 2 "What we will NOT do #1" is closed as enforced: no live production LLM enablement in this phase. Live LLM disablement posture remains continuously enforced through defense-in-depth runtime guards with no behavioral change. (Refs: current-state §5; evidence-index CLAIM-119, CLAIM-142, CLAIM-145; runbook §3)
2. Enforcement is wired through guard test (`PhaseTwoNoLiveLlmProductionEnablementGuardTest.php`) plus runtime guards: `settings.php` live hard-disable, `AssistantSettingsForm` live enforcement, `LlmEnhancer` and `FallbackGate` `isLiveEnvironment()` service checks. (Refs: evidence-index CLAIM-145; runbook §3)
3. This is a boundary enforcement disposition only — no runtime behavior change, no new code paths, no new architecture edges. (Refs: system-map Diagram B; evidence-index CLAIM-145)
4. Runtime verification output is captured in `docs/aila/runtime/phase2-ndo1-no-live-llm-production-enablement.txt` with `VC-TOGGLE-CHECK` alias output and guard anchor verification markers. (Refs: evidence-index CLAIM-145; runbook §3)
5. Scope boundaries remain unchanged: no live production LLM enablement in Phase 2 and no broad platform migration outside current Pantheon baseline. (Refs: current-state §1, §5; evidence-index CLAIM-115, CLAIM-119, CLAIM-145; runbook §3)

### Phase 2 NDO #2 disposition (2026-03-05)
1. Phase 2 "What we will NOT do #2" is closed as enforced: no broad platform migration outside current Pantheon baseline. Pantheon baseline continuity remains unchanged across platform/runtime anchors with no broad migration actions introduced. (Refs: current-state §1, §5; evidence-index CLAIM-115, CLAIM-119, CLAIM-146; runbook §3)
2. Enforcement is wired through guard test (`PhaseTwoNoBroadPlatformMigrationGuardTest.php`) plus reproducible runbook verification commands that validate repository anchors in `pantheon.yml`, `pantheon.upstream.yml`, `web/sites/default/settings.php`, and Diagram A continuity in `docs/aila/system-map.mmd`. (Refs: evidence-index CLAIM-146; runbook §3)
3. This is a boundary enforcement disposition only — no runtime behavior change, no platform-architecture edge additions, and no migration of hosting/runtime baseline beyond Pantheon. (Refs: current-state §1, §5; evidence-index CLAIM-115, CLAIM-119, CLAIM-146; system-map Diagram A)
4. Runtime verification output is captured in `docs/aila/runtime/phase2-ndo2-no-broad-platform-migration.txt` with `VC-TOGGLE-CHECK` alias output and platform-baseline anchor verification markers. (Refs: evidence-index CLAIM-146; runbook §3)
5. Scope boundaries remain unchanged: no live production LLM enablement in Phase 2 and no broad platform migration outside current Pantheon baseline. (Refs: current-state §1, §5; evidence-index CLAIM-115, CLAIM-119, CLAIM-146; runbook §3)

### Suggested sprint breakdown
1. Sprint 4: response contract + retrieval-confidence implementation and tests.
2. Sprint 5: dataset expansion, provenance/freshness workflows, threshold calibration.

### What we will NOT do
1. No live production LLM enablement in this phase. (Refs: current-state §5; evidence-index CLAIM-119; system-map Diagram B; runbook §3)
2. No broad platform migration outside current Pantheon baseline. (Refs: current-state §1, §5; evidence-index CLAIM-115, CLAIM-119; system-map Diagram A; runbook §3)

## Phase 3 (Sprint 6): UX polish + performance/cost optimization
### Objectives
1. Complete accessibility and mobile UX hardening with explicit acceptance gates. (Refs: current-state §4A; evidence-index CLAIM-025, CLAIM-031, CLAIM-032; system-map Diagram A; runbook §2)
2. Finalize performance and cost guardrails with operational runbooks. (Refs: current-state §4F, §4E; evidence-index CLAIM-077, CLAIM-084; system-map Diagram A; runbook §3)
3. Deliver release readiness package and governance attestation. (Refs: current-state §7; evidence-index CLAIM-108, CLAIM-115; system-map Diagram A; runbook §4)

### Phase 3 Objective #2 disposition (2026-03-05)
1. Objective #2 is closed as implemented: performance and cost guardrails are finalized through operational runbook verification and closure artifacts anchored to LLM call guardrails (`CLAIM-077`) and SLO/performance monitoring guardrails (`CLAIM-084`). Re-audit hardening now records that the prior global-only budget model is no longer accepted as closure evidence. (Refs: current-state §4E, §4F, §7; evidence-index CLAIM-077, CLAIM-084, CLAIM-147; system-map Diagram A; runbook §3)
2. Operational verification is codified in runbook section-3 command bundles (`VC-PURE`, `VC-UNIT`, `VC-QUALITY-GATE`) plus behavioral proof from `CostControlPolicyTest.php`, `LlmControlConcurrencyTest.php`, `LlmEnhancerHardeningTest.php`, `AssistantApiControllerCostControlMetricsTest.php`, `PerformanceMonitorTest.php`, and `SloAlertServiceTest.php`, with runtime proof captured in `docs/aila/runtime/phase3-obj2-performance-cost-guardrails.txt`. (Refs: evidence-index CLAIM-147; runbook §3)
3. Governance posture is updated to active mitigation for cost pre-rollout controls (`IMP-COST-01`, `R-PERF-01`) with objective-level behavioral proof and non-blocking docs continuity via `PhaseThreeObjectiveTwoGateTest.php`. `CostControlPolicy` service now carries per-IP budget enforcement proof and cache-effectiveness proof; global-only budget model is no longer accepted as closure evidence. (Refs: evidence-index CLAIM-147; runbook §3)
4. Scope boundaries remain unchanged: no net-new assistant channels or third-party model expansion beyond audited providers, and no platform-wide refactor of unrelated Drupal subsystems. (Refs: current-state §1, §4E; evidence-index CLAIM-010, CLAIM-073, CLAIM-074, CLAIM-147; system-map Diagram A; runbook §3, §4)
5. Promptfoo gate integrity remediation is enforced without threshold relaxation: multiline JS assertion return linting, deterministic per-run conversation salting (`ILAS_EVAL_RUN_ID`), failure adjudication artifacts, and targeted rubric precision fixes are applied while preserving the 90% blocking policy. (Refs: current-state §4F; evidence-index CLAIM-086, CLAIM-150; runbook §4)

### Phase 3 Objective #3 disposition (2026-03-05)
1. Objective #3 is closed as implemented: release readiness package and governance attestation are delivered through reproducible closure artifacts anchored to local preflight/runtime readiness evidence (`CLAIM-108`) and Pantheon runtime verification continuity (`CLAIM-115`) without runtime architecture expansion. (Refs: current-state §7; evidence-index CLAIM-108, CLAIM-115, CLAIM-148; system-map Diagram A; runbook §4)
2. Operational verification is codified in runbook section-4 command bundles (`VC-UNIT`, `VC-DRUPAL-UNIT`) plus behavioral runbook proof across roadmap/current-state/evidence/backlog/risk markers and Diagram A anchors, with runtime proof captured in `docs/aila/runtime/phase3-obj3-release-readiness-governance-attestation.txt`. (Refs: evidence-index CLAIM-148; runbook §4)
3. Governance attestation posture is updated to active mitigation for governance/compliance execution (`IMP-GOV-01`, retention/access attestation workflow, `R-GOV-01`) with objective-level behavioral proof and non-blocking docs continuity via `PhaseThreeObjectiveThreeGateTest.php`. (Refs: evidence-index CLAIM-148; runbook §4)
4. Scope boundaries remain unchanged: no net-new assistant channels or third-party model expansion beyond audited providers, and no platform-wide refactor of unrelated Drupal subsystems. (Refs: current-state §1, §4E; evidence-index CLAIM-010, CLAIM-073, CLAIM-074, CLAIM-148; system-map Diagram A; runbook §3, §4)

### Phase 3 Objective #1 disposition (2026-03-05)
1. Objective #1 is closed as implemented: accessibility and mobile UX hardening acceptance gates are delivered through reproducible test suites anchored to widget accessibility semantics (`CLAIM-025`), Twig ARIA/screen-reader markup (`CLAIM-032`), API client timeout/error mapping (`CLAIM-026`), and mobile/reduced-motion SCSS contracts (`CLAIM-031`). (Refs: current-state §4A; evidence-index CLAIM-025, CLAIM-026, CLAIM-031, CLAIM-032, CLAIM-149; system-map Diagram A; runbook §2)
2. Operational verification is codified in runbook section-2 command bundles (`VC-UNIT`, `VC-DRUPAL-UNIT`) plus targeted `AccessibilityMobileUxAcceptanceGateTest`, `RecoveryUxContractTest`, and `assistant-widget-hardening.test.js` execution, with runtime proof captured in `docs/aila/runtime/phase3-obj1-ux-a11y-mobile-acceptance.txt`. (Refs: evidence-index CLAIM-149; runbook §2)
3. Governance posture is updated to active mitigation for accessibility regression controls (`R-UX-01`) and mobile error-state quality controls (`R-UX-02`) with objective-level behavioral proof and non-blocking docs continuity via `PhaseThreeObjectiveOneGateTest.php`. (Refs: evidence-index CLAIM-149; runbook §2)
4. Scope boundaries remain unchanged: no net-new assistant channels or third-party model expansion beyond audited providers, and no platform-wide refactor of unrelated Drupal subsystems. (Refs: current-state §1, §4E; evidence-index CLAIM-010, CLAIM-073, CLAIM-074, CLAIM-149; system-map Diagram A; runbook §2, §4)

### Key deliverables
1. Keyboard/SR regression suite and mobile timeout/error-state acceptance tests (`IMP-UX-01`). (Refs: current-state §4A; evidence-index CLAIM-025, CLAIM-026, CLAIM-032; system-map Diagram A; runbook §2)
2. Cost-control policy and budget guardrails (`IMP-COST-01`). (Refs: current-state §4E; evidence-index CLAIM-076, CLAIM-077, CLAIM-080; system-map Diagram A; runbook §3)
3. Release checklist with compliance/retention/access attestations and rollback playbook. (Refs: current-state §6, §7; evidence-index CLAIM-059, CLAIM-087, CLAIM-088; system-map Diagram A; runbook §5)

### Entry criteria
1. Phase 2 retrieval quality targets are met and documented. (Refs: current-state §4D, §4F; evidence-index CLAIM-065, CLAIM-086; system-map Diagram B; runbook §4)
2. SLO/alert operational data has at least one sprint of trend history. (Refs: current-state §4F, §4G; evidence-index CLAIM-084, CLAIM-121; system-map Diagram B; runbook §3)

### Phase 3 Entry #1 disposition (2026-03-05)
1. Entry criterion #1 is closed as documented: all Phase 2 retrieval quality targets are met and documented through completed objectives, deliverables, sprint closures, and exit criteria with dated dispositions covering confidence-aware response behavior, eval coverage, source freshness governance, response contract expansion, retrieval confidence/refusal thresholds, vector index hygiene, and promptfoo dataset expansion. (Refs: current-state §4D, §4F; evidence-index CLAIM-065, CLAIM-086, CLAIM-151; system-map Diagram B; runbook §4)
2. Phase 2 retrieval quality completions referenced: Objective #2 (2026-03-03), Objective #3 (2026-03-03), Deliverable #1 (2026-03-03), Deliverable #2 (2026-03-03), Deliverable #3 (2026-03-04), Deliverable #4 (2026-03-04), Exit #1 (2026-03-04), Exit #2 (2026-03-04), Sprint 4 (2026-03-05), Sprint 5 (2026-03-05). (Refs: evidence-index CLAIM-132, CLAIM-133, CLAIM-134, CLAIM-135, CLAIM-136, CLAIM-137, CLAIM-140, CLAIM-141, CLAIM-143, CLAIM-144, CLAIM-151; runbook §4)
3. Runtime verification evidence for `VC-RUNBOOK-LOCAL` and `VC-TOGGLE-CHECK` is captured in `docs/aila/runtime/phase3-entry1-retrieval-quality-targets.txt`. (Refs: evidence-index CLAIM-151; runbook §4)
4. Scope boundaries remain unchanged: no net-new assistant channels or third-party model expansion beyond audited providers, and no platform-wide refactor of unrelated Drupal subsystems. (Refs: current-state §1, §4E; evidence-index CLAIM-010, CLAIM-073, CLAIM-074, CLAIM-151; runbook §4)

### Phase 3 Entry #2 disposition (2026-03-05)
1. Entry criterion #2 is closed as documented: SLO/alert operational data now has at least one sprint of trend history using the locked sprint definition `10 business days` and explicit local trend-window evidence from 2026-02-20 through 2026-03-05 (14 calendar days / 10 business days) derived from watchdog cron/SLO operational records. (Refs: current-state §4F, §4G; evidence-index CLAIM-084, CLAIM-121, CLAIM-152; system-map Diagram B; runbook §3)
2. Verification is codified via `VC-RUNBOOK-LOCAL`, `VC-TOGGLE-CHECK`, local watchdog trend queries (window bounds + business-day calculation + SLO-violation count), and continuity anchor checks tied to existing CLAIM-084/CLAIM-121 runtime evidence paths. Runtime proof is captured in `docs/aila/runtime/phase3-entry2-slo-alert-trend-history.txt`. (Refs: evidence-index CLAIM-152; runbook §3)
3. Scope boundaries remain unchanged: no net-new assistant channels or third-party model expansion beyond audited providers, and no platform-wide refactor of unrelated Drupal subsystems. (Refs: current-state §1, §4E; evidence-index CLAIM-010, CLAIM-073, CLAIM-074, CLAIM-152; system-map Diagram A; runbook §3, §4)
4. Residual risk status remains unchanged: B-04 (sustained cron/queue load behavior under non-zero backlog) remains open and outside this entry-criterion closure. (Refs: current-state §8; evidence-index CLAIM-118, CLAIM-121, CLAIM-152; runbook §3)

### Exit criteria
1. UX/a11y test suite is gating and passing. (Refs: current-state §4A; evidence-index CLAIM-025, CLAIM-032, CLAIM-105; system-map Diagram A; runbook §4)
2. Cost/performance controls are documented, monitored, and accepted by product/platform owners. (Refs: current-state §4E, §4F; evidence-index CLAIM-077, CLAIM-084; system-map Diagram A; runbook §3)
3. Final release packet includes known-unknown disposition and residual risk signoff. (Refs: current-state §8; evidence-index CLAIM-122; system-map Diagram A; runbook §4)

### Phase 3 Exit #1 disposition (2026-03-06)
1. Exit criterion #1 is closed as implemented: UX/a11y test suite is gating and passing. The required `Promptfoo Gate` CI job now executes `web/modules/custom/ilas_site_assistant/tests/js/run-assistant-widget-hardening.mjs` before promptfoo gate evaluation, and this workflow wiring is continuity-locked by `QualityGateEnforcementContractTest.php`. (Refs: current-state §4A; evidence-index CLAIM-025, CLAIM-032, CLAIM-105, CLAIM-153; system-map Diagram A; runbook §4)
2. JS a11y suite execution is now CI-deterministic and non-optional for required checks; suite hardening retains safety coverage while correcting browser-accurate `data-retry-message` assertion semantics without weakening DOM injection protections. (Refs: evidence-index CLAIM-025, CLAIM-032, CLAIM-153; runbook §4)
3. Runtime verification and closure markers are captured in `docs/aila/runtime/phase3-exit1-ux-a11y-gating.txt` with `VC-RUNBOOK-LOCAL`, `VC-RUNBOOK-PANTHEON`, targeted gate-test execution, and CI anchor checks recorded under `CLAIM-153`. (Refs: current-state §8; evidence-index CLAIM-153; runbook §4)
4. Scope boundaries remain unchanged: no net-new assistant channels or third-party model expansion beyond audited providers, and no platform-wide refactor of unrelated Drupal subsystems. (Refs: current-state §1, §4E; evidence-index CLAIM-010, CLAIM-073, CLAIM-074, CLAIM-153; system-map Diagram A; runbook §3, §4)

### Phase 3 Exit #2 disposition (2026-03-06)
1. Exit criterion #2 is closed as implemented: cost/performance controls are documented, monitored, and accepted by product/platform owners. Closure continuity is anchored to existing LLM call guardrails (`CLAIM-077`) and SLO/performance monitoring guardrails (`CLAIM-084`) without runtime architecture expansion. Re-audit hardening now requires explicit per-IP budget and cache-effectiveness proof markers in the runtime artifact. (Refs: current-state §4E, §4F, §7; evidence-index CLAIM-077, CLAIM-084, CLAIM-154; system-map Diagram A; runbook §3)
2. Verification is codified in runbook section-3 command bundles with required `VC-PURE`, `VC-QUALITY-GATE`, and `VC-PANTHEON-READONLY` aliases, dashboard monitoring checks (`/assistant/api/health`, `/assistant/api/metrics`), behavioral proof from the monitored services, and non-blocking docs continuity via `PhaseThreeExitCriteriaTwoGateTest.php`. Runtime proof is captured in `docs/aila/runtime/phase3-exit2-cost-performance-owner-acceptance.txt`. (Refs: evidence-index CLAIM-154; runbook §3)
3. Product/platform owner acceptance is recorded as role-based closure evidence (`owner-acceptance-product-role=accepted`, `owner-acceptance-platform-role=accepted`, dated 2026-03-06) in the runtime artifact and linked documentation. (Refs: current-state §7; evidence-index CLAIM-154; runbook §3)
4. Performance/cost governance linkage remains active and now includes explicit exit-criterion continuity in backlog/risk artifacts (`IMP-COST-01`, `R-PERF-01`) tied to `P3-EXT-02` runtime markers. Pantheon read-only verification passed on 2026-03-13 across `dev`/`test`/`live`, confirming the deployed per-IP keys plus `metrics.cost_control` / `thresholds.cost_control` continuity. (Refs: current-state §4E, §4F, §7; evidence-index CLAIM-077, CLAIM-084, CLAIM-154; runbook §3)
5. Scope boundaries remain unchanged: no net-new assistant channels or third-party model expansion beyond audited providers, and no platform-wide refactor of unrelated Drupal subsystems. (Refs: current-state §1, §4E; evidence-index CLAIM-010, CLAIM-073, CLAIM-074, CLAIM-154; system-map Diagram A; runbook §3, §4)
6. Residual risk posture remains unchanged: B-04 (sustained cron/queue load behavior under non-zero backlog) remains open and outside this closure item. (Refs: current-state §8; evidence-index CLAIM-118, CLAIM-121, CLAIM-154; runbook §3)

### Phase 3 Exit #3 disposition (2026-03-06)
1. Exit criterion #3 is closed as implemented: final release packet includes known-unknown disposition and residual risk signoff. Closure continuity is anchored to current-state known-unknown posture (`§8`) and CI-governance continuity (`CLAIM-122`) without runtime architecture expansion. (Refs: current-state §8; evidence-index CLAIM-122, CLAIM-155; system-map Diagram A; runbook §4)
2. Verification is codified in runbook section-4 command bundles with required `VC-RUNBOOK-LOCAL` and `VC-RUNBOOK-PANTHEON` aliases, continuity checks for current-state §8 known unknowns and `R-REL-02`, and closure guard-test enforcement in `PhaseThreeExitCriteriaThreeGateTest.php`. Runtime proof is captured in `docs/aila/runtime/phase3-exit3-release-packet-known-unknown-risk-signoff.txt`. (Refs: evidence-index CLAIM-155; runbook §4)
3. Known-unknown disposition is explicit for release closure: Promptfoo CI ownership remains resolved, while long-run cron/queue load observation remains open and is carried as residual boundary `B-04` in the release packet. (Refs: current-state §8; evidence-index CLAIM-122, CLAIM-118, CLAIM-121, CLAIM-155; runbook §4)
4. Residual risk signoff is recorded as role-based release evidence (`residual-risk-signoff-product-role=accepted`, `residual-risk-signoff-platform-role=accepted`, dated 2026-03-06) in the runtime artifact and linked closure docs. (Refs: current-state §8; evidence-index CLAIM-155; runbook §4)
5. Scope boundaries remain unchanged: no net-new assistant channels or third-party model expansion beyond audited providers, and no platform-wide refactor of unrelated Drupal subsystems. (Refs: current-state §1, §4E; evidence-index CLAIM-010, CLAIM-073, CLAIM-074, CLAIM-155; system-map Diagram A; runbook §3, §4)
6. Residual risk posture remains unchanged: B-04 (sustained cron/queue load behavior under non-zero backlog) remains open and explicitly signed off as accepted residual risk for this closure item. (Refs: current-state §8; evidence-index CLAIM-118, CLAIM-121, CLAIM-155; runbook §4)

### Phase 3 Sprint 6 Week 1 disposition (2026-03-06)
1. Sprint 6 Week 1 closure item is completed exactly as specified: "Sprint 6 Week 1: UX/a11y and mobile hardening." Closure continuity is anchored to already-closed objective/exit evidence paths for accessibility/mobile acceptance (`P3-OBJ-01`) and UX/a11y gating (`P3-EXT-01`) without runtime architecture expansion. (Refs: current-state §4A, §8; evidence-index CLAIM-149, CLAIM-153, CLAIM-156; system-map Diagram A; runbook §2, §4)
2. Sprint-level verification is codified with required validation aliases (`VC-UNIT`, `VC-QUALITY-GATE`) plus targeted continuity anchors for accessibility/mobile hardening tests (`AccessibilityMobileUxAcceptanceGateTest.php`, `RecoveryUxContractTest.php`, `assistant-widget-hardening.test.js`) and runtime proof captured in `docs/aila/runtime/phase3-sprint6-week1-ux-a11y-mobile-hardening.txt`. (Refs: evidence-index CLAIM-105, CLAIM-149, CLAIM-153, CLAIM-156; runbook §4)
3. Sprint closure enforcement is continuity-locked by `PhaseThreeSprintSixWeekOneGateTest.php` across roadmap/current-state/runbook/evidence/runtime/system-map anchors for `P3-SBD-01`. (Refs: evidence-index CLAIM-156; runbook §4)
4. Scope boundaries remain unchanged: no net-new assistant channels or third-party model expansion beyond audited providers, and no platform-wide refactor of unrelated Drupal subsystems. (Refs: current-state §1, §4E; evidence-index CLAIM-010, CLAIM-073, CLAIM-074, CLAIM-156; system-map Diagram A; runbook §3, §4)

### Phase 3 Sprint 6 Week 2 disposition (2026-03-06)
1. Sprint 6 Week 2 closure item is completed exactly as specified: "Sprint 6 Week 2: performance/cost guardrails and governance signoff." Closure continuity is anchored to already-closed objective/exit evidence paths for performance/cost guardrails (`P3-OBJ-02`, `P3-EXT-02`) and governance signoff (`P3-OBJ-03`, `P3-EXT-03`) without runtime architecture expansion. (Refs: current-state §4E, §4F, §7, §8; evidence-index CLAIM-147, CLAIM-148, CLAIM-154, CLAIM-155, CLAIM-157; system-map Diagram A; runbook §3, §4)
2. Sprint-level verification is codified with required validation aliases (`VC-UNIT`, `VC-QUALITY-GATE`) plus behavioral proof for the closed objective/exit workstreams and non-blocking docs continuity coverage (`PhaseThreeObjectiveTwoGateTest.php`, `PhaseThreeObjectiveThreeGateTest.php`, `PhaseThreeExitCriteriaTwoGateTest.php`, `PhaseThreeExitCriteriaThreeGateTest.php`) and runtime proof captured in `docs/aila/runtime/phase3-sprint6-week2-performance-cost-governance-signoff.txt`. (Refs: evidence-index CLAIM-105, CLAIM-147, CLAIM-148, CLAIM-154, CLAIM-155, CLAIM-157; runbook §3, §4)
3. Sprint closure enforcement is continuity-locked by `PhaseThreeSprintSixWeekTwoGateTest.php` across roadmap/current-state/runbook/evidence/runtime/system-map anchors for `P3-SBD-02`. (Refs: evidence-index CLAIM-157; runbook §4)
4. Scope boundaries remain unchanged: no net-new assistant channels or third-party model expansion beyond audited providers, and no platform-wide refactor of unrelated Drupal subsystems. (Refs: current-state §1, §4E; evidence-index CLAIM-010, CLAIM-073, CLAIM-074, CLAIM-157; system-map Diagram A; runbook §3, §4)

### Phase 3 NDO #1 disposition (2026-03-06)
1. Phase 3 "What we will NOT do #1" is closed as enforced: no net-new assistant channels or third-party model expansion beyond audited providers. Closure continuity is anchored to the audited provider wiring posture (Cohere-first request-time classification, Voyage reranking, and residual Gemini only where Search API/vector proof still requires it) and existing assistant channel surface (`/assistant` page + existing `/assistant/api/*` endpoints) without runtime architecture expansion. (Refs: current-state §4E; evidence-index CLAIM-073, CLAIM-074, CLAIM-158; system-map Diagram A; runbook §3)
2. Enforcement is codified through runbook section-3 `VC-TOGGLE-CHECK` alias continuity plus explicit channel/provider anchor checks and a dedicated guard test (`PhaseThreeNoNetNewAssistantChannelsOrModelExpansionGuardTest.php`) that locks roadmap/current-state/evidence/runbook/runtime/source continuity markers. (Refs: evidence-index CLAIM-158; runbook §3)
3. This closure is boundary enforcement only: no runtime behavior changes, no new assistant channel routes/surfaces, and no provider expansion beyond the audited Cohere-first request-time path plus already-proven retrieval providers. (Refs: current-state §1, §4E; evidence-index CLAIM-010, CLAIM-073, CLAIM-074, CLAIM-158; system-map Diagram A)
4. Runtime verification output is captured in `docs/aila/runtime/phase3-ndo1-no-net-new-assistant-channels-or-third-party-model-expansion.txt` with sanitized `VC-TOGGLE-CHECK` output, assistant channel continuity markers, audited-provider continuity markers, and closure status fields. (Refs: evidence-index CLAIM-158; runbook §3)
5. Scope boundaries remain unchanged: no net-new assistant channels or third-party model expansion beyond audited providers, and no platform-wide refactor of unrelated Drupal subsystems. (Refs: current-state §1, §4E; evidence-index CLAIM-010, CLAIM-073, CLAIM-074, CLAIM-158; system-map Diagram A; runbook §3, §4)

### Phase 3 NDO #2 disposition (2026-03-06)
1. Phase 3 "What we will NOT do #2" is closed as enforced: no platform-wide refactor of unrelated Drupal subsystems. Closure continuity remains anchored to current architecture scope in current-state §1 and module/system boundary anchors for `ilas_site_assistant` with no runtime architecture expansion. (Refs: current-state §1; evidence-index CLAIM-010, CLAIM-159; system-map Diagram A; runbook §4)
2. Enforcement is codified through runbook section-4 `VC-TOGGLE-CHECK` alias continuity plus explicit module-scope and seam-service anchor checks and a dedicated guard test (`PhaseThreeNoPlatformWideRefactorOfUnrelatedDrupalSubsystemsGuardTest.php`) that locks roadmap/current-state/evidence/runbook/runtime/source continuity markers. (Refs: evidence-index CLAIM-159; runbook §4)
3. This closure is boundary enforcement only: no runtime behavior changes, no assistant channel/provider expansion, and no broad refactor across unrelated Drupal subsystems. (Refs: current-state §1, §4E; evidence-index CLAIM-010, CLAIM-073, CLAIM-074, CLAIM-159; system-map Diagram A)
4. Runtime verification output is captured in `docs/aila/runtime/phase3-ndo2-no-platform-wide-refactor-of-unrelated-drupal-subsystems.txt` with sanitized `VC-TOGGLE-CHECK` output, module/system continuity markers, and closure status fields. (Refs: evidence-index CLAIM-159; runbook §4)
5. Scope boundaries remain unchanged: no net-new assistant channels or third-party model expansion beyond audited providers, and no platform-wide refactor of unrelated Drupal subsystems. (Refs: current-state §1, §4E; evidence-index CLAIM-010, CLAIM-073, CLAIM-074, CLAIM-159; system-map Diagram A; runbook §3, §4)

### Suggested sprint breakdown
1. Sprint 6 Week 1: UX/a11y and mobile hardening.
2. Sprint 6 Week 2: performance/cost guardrails and governance signoff.

### What we will NOT do
1. No net-new assistant channels or third-party model expansion beyond audited providers. (Refs: current-state §4E; evidence-index CLAIM-073, CLAIM-074; system-map Diagram A; runbook §3)
2. No platform-wide refactor of unrelated Drupal subsystems. (Refs: current-state §1; evidence-index CLAIM-010; system-map Diagram A; runbook §4)

## Cross-phase dependency and owner matrix
| Workstream | Depends on | Consumed in phase | Owner role |
|---|---|---|---|
| CSRF hardening (`IMP-SEC-01`) | Authenticated test matrix and route enforcement verification | Phase 0 -> prerequisite for Phases 1-3 | Security Engineer + Drupal Lead |
| Policy governance (`IMP-GOV-01` prep) | Audit reporting scope, policy boundary definitions, and restricted signoff workflow | Phase 0 -> prerequisite for governance/compliance execution in Phases 1-3 | Compliance Lead + Security Engineer |
| Config parity (`IMP-CONF-01`) | Schema mapping + env drift checks | Phase 0 -> prerequisite for Phase 2 retrieval tuning | Drupal Lead |
| Observability baseline (`IMP-OBS-01`) | Sentry/Langfuse credentials, redaction validation | Phase 1 -> prerequisite for Phase 2/3 optimization | SRE/Platform Engineer |
| CI quality gate (`IMP-TST-01`) | CI owner/platform decisions | Phase 1 -> prerequisite for all subsequent release gates | QA/Automation Engineer + TPM |
| Retrieval confidence contract (`IMP-RAG-01`) | Config parity + observability signals + eval harness | Phase 2 -> prerequisite for Phase 3 readiness signoff | AI/RAG Engineer |
| Cost guardrails (`IMP-COST-01`) | Observability and usage telemetry from Phase 1/2 | Phase 3 | Product + Platform |

### Cross-phase dependency row #1 disposition (2026-03-06)
1. Dependency row #1 (`XDP-01`) is closed as implemented for CSRF hardening
   (`IMP-SEC-01`): authenticated test matrix and route-enforcement verification
   remain locked as Phase 0 prerequisite evidence consumed by Phases 1-3.
   (Refs: current-state §6, §8; evidence-index CLAIM-012, CLAIM-123, CLAIM-160; system-map Diagram B; runbook §2)
2. Dependency-gate enforcement is codified through docs/runtime continuity and
   `CrossPhaseDependencyRowOneGateTest.php`; downstream dependency work is
   blocked whenever unresolved dependency count is non-zero.
3. Runtime proof is captured in
   `docs/aila/runtime/phase0-xdp01-csrf-hardening-dependency-gate.txt` with
   deterministic status and unresolved-dependency markers.
4. Owner-role continuity remains unchanged for this row:
   Security Engineer + Drupal Lead.
5. Scope boundaries remain unchanged: preserve roadmap sequencing and all phase
   boundaries; no runtime behavior expansion.

### Cross-phase dependency row #2 disposition (2026-03-06)
1. Dependency row #2 (`XDP-02`) is closed as implemented for config parity
   (`IMP-CONF-01`): schema mapping and env drift checks remain locked as
   Phase 0 prerequisite evidence consumed by Phase 2 retrieval tuning.
   (Refs: current-state §4H, §5; evidence-index CLAIM-095, CLAIM-124, CLAIM-161; system-map Diagram A; runbook §3, §4)
2. Dependency-gate enforcement is codified through docs/runtime continuity and
   `CrossPhaseDependencyRowTwoGateTest.php`; downstream retrieval-tuning work is
   blocked whenever unresolved dependency count is non-zero.
3. Runtime proof is captured in
   `docs/aila/runtime/phase0-xdp02-config-parity-dependency-gate.txt` with
   deterministic status and unresolved-dependency markers.
4. Owner-role continuity remains unchanged for this row: Drupal Lead.
5. Scope boundaries remain unchanged: preserve roadmap sequencing and all phase
   boundaries; no runtime behavior expansion.

### Cross-phase dependency row #3 disposition (2026-03-06)
1. Dependency row #3 (`XDP-03`) is closed as implemented for observability
   baseline (`IMP-OBS-01`): Sentry/Langfuse credential readiness and redaction
   validation remain locked as Phase 1 prerequisite evidence consumed by
   Phase 2/3 optimization work.
   (Refs: current-state §4F, §6; evidence-index CLAIM-083, CLAIM-120, CLAIM-126, CLAIM-162; system-map Diagram A; runbook §3, §5)
2. Dependency-gate enforcement is codified through docs/runtime continuity and
   `CrossPhaseDependencyRowThreeGateTest.php`; downstream Phase 2/3
   optimization work is blocked whenever unresolved dependency count is
   non-zero.
3. Runtime proof is captured in
   `docs/aila/runtime/phase1-xdp03-observability-baseline-dependency-gate.txt`
   with deterministic status and unresolved-dependency markers.
4. Owner-role continuity remains unchanged for this row:
   SRE/Platform Engineer.
5. Scope boundaries remain unchanged: preserve roadmap sequencing and all phase
   boundaries; no runtime behavior expansion.

### Cross-phase dependency row #4 disposition (2026-03-06)
1. Dependency row #4 (`XDP-04`) is closed as implemented for CI quality gate
   (`IMP-TST-01`): CI owner/platform decision continuity remains locked as
   Phase 1 prerequisite evidence consumed by all subsequent release gates.
   (Refs: current-state §4F, §8; evidence-index CLAIM-122, CLAIM-130, CLAIM-163; system-map Diagram A; runbook §3, §4)
2. Dependency-gate enforcement is codified through docs/runtime continuity and
   `CrossPhaseDependencyRowFourGateTest.php`; downstream release-gate work is
   blocked whenever unresolved dependency count is non-zero.
3. Runtime proof is captured in
   `docs/aila/runtime/phase1-xdp04-ci-quality-gate-dependency-gate.txt`
   with deterministic status and unresolved-dependency markers.
4. Owner-role continuity remains unchanged for this row:
   QA/Automation Engineer + TPM.
5. Scope boundaries remain unchanged: preserve roadmap sequencing and all phase
   boundaries; no runtime behavior expansion.

### Cross-phase dependency row #5 disposition (2026-03-06)
1. Dependency row #5 (`XDP-05`) is closed as implemented for retrieval
   confidence contract (`IMP-RAG-01`): config parity, observability signals, and
   eval-harness continuity remain locked as Phase 2 prerequisite evidence
   consumed by Phase 3 readiness signoff.
   (Refs: current-state §4D, §4F, §6; evidence-index CLAIM-120, CLAIM-124, CLAIM-135, CLAIM-151, CLAIM-164; system-map Diagram B; runbook §4)
2. Dependency-gate enforcement is codified through behavioral proof
   (`PhaseTwoDeliverableTwoBehaviorTest.php`, `CrossPhaseDependencyRowFiveBehaviorTest.php`) plus non-blocking docs continuity via
   `CrossPhaseDependencyRowFiveGateTest.php`; downstream Phase 3 readiness
   signoff work is blocked whenever unresolved dependency count is non-zero.
3. Runtime proof is captured in
   `docs/aila/runtime/phase2-xdp05-retrieval-confidence-contract-dependency-gate.txt`
   with deterministic status and unresolved-dependency markers.
4. Owner-role continuity remains unchanged for this row:
   AI/RAG Engineer.
5. Scope boundaries remain unchanged: preserve roadmap sequencing and all phase
   boundaries; no runtime behavior expansion.

### Cross-phase dependency row #6 disposition (2026-03-07)
1. Dependency row #6 (`XDP-06`) is closed as implemented for cost guardrails
   (`IMP-COST-01`): observability and usage telemetry continuity from Phase 1/2
   remains locked as prerequisite evidence consumed in Phase 3.
   (Refs: current-state §4E, §4F, §7; evidence-index CLAIM-126, CLAIM-127, CLAIM-138, CLAIM-147, CLAIM-154, CLAIM-165; system-map Diagram A; runbook §3)
2. Dependency-gate enforcement is codified through behavioral proof
   (`CostControlPolicyTest.php`, `LlmControlConcurrencyTest.php`, `LlmEnhancerHardeningTest.php`, `AssistantApiControllerCostControlMetricsTest.php`, `PerformanceMonitorTest.php`, `SloAlertServiceTest.php`, `CrossPhaseDependencyRowSixBehaviorTest.php`) plus non-blocking docs continuity via
   `CrossPhaseDependencyRowSixGateTest.php`; downstream Phase 3 cost-guardrail
   work is blocked whenever unresolved dependency count is non-zero. Row #6 now explicitly requires per-IP budget enforcement and cache-effectiveness proof in addition to the earlier config and SLO prerequisites.
3. Runtime proof is captured in
   `docs/aila/runtime/phase3-xdp06-cost-guardrails-dependency-gate.txt` with
   deterministic status and unresolved-dependency markers.
4. Owner-role continuity remains unchanged for this row:
   Product + Platform.
5. Scope boundaries remain unchanged: preserve roadmap sequencing and all phase
   boundaries; no runtime behavior expansion.

## Critical path and blocker list
1. **Blocker B-01 (RESOLVED 2026-03-03):** `/assistant/api/message` strict CSRF path is verified; `/assistant/api/track` follows the approved hybrid same-origin mitigation with bootstrap-token recovery for missing-header browsers and no longer blocks Phase 1 entry criterion #1. (Refs: current-state §8; evidence-index CLAIM-012, CLAIM-123; system-map Diagram B; runbook §2)
2. **Blocker B-02 (RESOLVED 2026-03-03):** `vector_search` schema/export parity is restored and enforced by drift/schema contract tests, so cross-env retrieval tuning is no longer blocked by config parity. (Refs: current-state §4H, §5; evidence-index CLAIM-095, CLAIM-124; system-map Diagram A; runbook §4)
3. **Blocker B-03 (RESOLVED 2026-03-03):** CI workflow ownership/source of truth is first-party in-repo (`.github/workflows/quality-gate.yml`) with branch-protection required checks enforcing mandatory merge/release gate behavior. (Refs: current-state §8; evidence-index CLAIM-122, CLAIM-130; system-map Diagram A; runbook §3, §4)
4. **Blocker B-04:** Sustained cron/queue load behavior unverified blocks final SLO tuning for async telemetry pipelines. (Refs: current-state §8; evidence-index CLAIM-118, CLAIM-121; system-map Diagram B; runbook §3)

## Scope boundaries across roadmap
1. LLM live enablement is explicitly out of scope through Phase 2 and only reconsidered after Phase 3 readiness review. (Refs: current-state §5; evidence-index CLAIM-119; system-map Diagram B; runbook §3)
2. Roadmap focuses on safety, quality, reliability, and governance improvements on current architecture; no full rewrite is planned. (Refs: current-state §1, §3; evidence-index CLAIM-010, CLAIM-020; system-map Diagram A; runbook §4)

## Retrospective addendum (2026-02-27 production failures)
This addendum captures regression classes observed in production transcript review and binds them to explicit roadmap delivery IDs.

### Addendum delivery items
| ID | Phase | Sprint mapping | Priority | Scope | Acceptance gate |
|---|---|---|---|---|---|
| IMP-SEC-02 | 0 | Sprint 1 | High | Add explicit machine-readable CSRF/session failure codes on write endpoints and map widget recovery UX to those codes. | 1) 403 responses include stable error code for missing/invalid/expired token states. 2) Widget recovery copy branches on error code, not status text only. 3) Functional matrix covers anonymous bootstrap + missing/invalid/expired token recovery. |
| IMP-REL-03 | 1 | Sprint 2 | High | Normalize disambiguation option schema (`intent` canonical; `value` accepted as deprecated alias), and harden mixed forms/guides clarify behavior. | 1) Disambiguation responses always emit actionable `action`/`intent` for every option. 2) Query `eviction forms or guides?` yields a single clarify response with forms+guides options. 3) Contract test validates canonical + alias compatibility path. |
| IMP-REL-04 | 1 | Sprint 2 | High | Add controller guard for empty-after-sanitize messages and loop-prevention metadata for repeated clarify cycles. | 1) Empty-after-sanitize input returns deterministic `400 invalid_message` and does not invoke router/retrieval. 2) Clarify counter/hash in conversation state prevents repeated identical clarify loops. 3) Multi-turn replay test verifies loop-break fallback path. |
| IMP-TST-02 | 1 | Sprint 3 | High | Expand blocking regression gate to include deep multi-turn transcript replay and UI/controller contract assertions. | 1) Golden replay includes `i need some help`, `custody forms?`, `eviction forms or guides?`, and repeated `eviction forms` no-loop behavior. 2) Disambiguation option contract test fails on null-action chips. 3) Deep suite is part of blocking gate, not advisory-only abuse suite. |
| IMP-UX-02 | 3 | Sprint 6 | Medium | Standardize CSRF/session denial UX recovery path across widget/page modes (refresh/retry with guidance). | 1) 403 UX copy references concrete recovery action. 2) Retry path works after token/session refresh without page dead-end state. 3) Mobile and keyboard flows preserve accessibility semantics during recovery. |

### Addendum dependency rows
| Workstream | Depends on | Consumed in phase | Owner role |
|---|---|---|---|
| CSRF/session recovery contract (`IMP-SEC-02`) | Strict CSRF enforcement baseline (`IMP-SEC-01`) + widget error handling branch points | Phase 0 -> prerequisite for Phase 1 reliability replay tests | Security Engineer + Frontend Engineer |
| Disambiguation schema + mixed-intent contract (`IMP-REL-03`) | Intent-router/disambiguator option normalization and response contract guard tests | Phase 1 -> prerequisite for transcript replay gate (`IMP-TST-02`) | Drupal Lead + QA/Automation Engineer |
| Empty-query + loop-breaker safeguards (`IMP-REL-04`) | Conversation-state metadata extension and controller pre-routing guards | Phase 1 -> prerequisite for transcript replay gate (`IMP-TST-02`) | Drupal Lead + QA/Automation Engineer |
| Transcript replay gate expansion (`IMP-TST-02`) | CI owner/platform decision (`IMP-TST-01`) + deep suite harness wiring | Phase 1 -> prerequisite for Phase 2/3 release confidence | QA/Automation Engineer + TPM |

### RAUD-28 Closure Addendum (2026-03-14)

**RAUD completion summary:**
- 18/27 RAUDs executed with evidence files (RAUD-03, -05, -08, -09, -10, -11, -12, -13, -16, -17, -18, -19, -20, -21, -22, -25, -26, -27)
- 6/9 un-executed RAUDs independently mitigated (covered by overlapping RAUD scopes, quality-gate enforcement, or verified N/A status)
- After 2026-03-13 reverification: 60 Fixed, 1 Partially Fixed, 0 Unverified, 1 Open, 13 N/A (75 total)

**Remaining backlog (only 2 non-Fixed findings):**
1. **Medium:** OBS-3 queue item TTL auto-purge (partially fixed — monitoring exists, auto-drop missing)
2. **Low:** NF-03 doc-anchor gate tests (open — replace with behavioral assertions)

**Operational follow-ups:**
- RAUD-11 update_10007 backfill for legacy observability rows
- Sentry account-side capture/alert/source-map proof (TOVR-02/TOVR-03)
- New Relic AILA integration retired (TOVR-06)

Full closure memo: [`docs/aila/runtime/raud-28-audit-closure-memo.md`](aila/runtime/raud-28-audit-closure-memo.md)

### AFRP-16 Runtime diagnostics hardening (2026-03-25)

1. AFRP-02 delivered `RuntimeTruthSnapshotBuilder` + `ilas:runtime-truth` to surface stored-vs-effective config divergences. AFRP-12 delivered the `ObservabilityProofTaxonomy` (L0-L6). However, operators still had to run multiple commands and mentally correlate output to determine environment health and proof coverage.
2. AFRP-16 delivers `RuntimeDiagnosticsMatrixBuilder` + `ilas:runtime-diagnostics` — a unified, jq-parseable JSON artifact that composes runtime truth, retrieval dependency health, credential inventory, and proof-level annotations into a single machine-checkable diagnostic. Each runtime fact receives a `pass`/`fail`/`degraded`/`skipped` assertion.
3. `ObservabilityProofTaxonomy` extended with `TOOL_RUNTIME_DIAGNOSTICS` (L0 ceiling), `FACT_CATEGORIES`, and `ASSERTION_*` constants. Contract-locked by 14 tests.
4. Disposition: **Fixed** (CLAIM-273, CLAIM-274, CLAIM-275).
