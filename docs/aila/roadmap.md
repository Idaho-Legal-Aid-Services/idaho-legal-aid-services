# Aila Improvement Roadmap (12 Weeks / 6 Sprints)

## Summary
This roadmap sequences safety hardening, reliability/observability foundations, retrieval quality improvements, and UX/performance optimization using the audited baseline. (Refs: current-state §1, §4F, §8; evidence-index CLAIM-033, CLAIM-079, CLAIM-084, CLAIM-122; system-map Diagram A; runbook §7)

Planning defaults applied:
- `llm.enabled` remains disabled in `live` through Phase 2. (Refs: current-state §5; evidence-index CLAIM-069, CLAIM-119; system-map Diagram B; runbook §3)
- Timeline = 12 weeks / 6 two-week sprints. (Refs: current-state §7; evidence-index CLAIM-108, CLAIM-115; system-map Diagram A; runbook §4)

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
2. `/assistant/api/track` uses approved mitigation (same-origin Origin/Referer + flood limits) for low-impact telemetry writes without CSRF/session-token dependency. (Refs: current-state §6; evidence-index CLAIM-012, CLAIM-123; runbook §2)
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

### Exit criteria
1. Retrieval contract and confidence logic pass regression thresholds. (Refs: current-state §4D, §4F; evidence-index CLAIM-062, CLAIM-086; system-map Diagram B; runbook §4)
2. Citation coverage and low-confidence refusal metrics are within approved targets. (Refs: current-state §4D; evidence-index CLAIM-065; system-map Diagram B; runbook §4)
3. Live LLM remains disabled pending Phase 3 readiness review. (Refs: current-state §5; evidence-index CLAIM-119; system-map Diagram B; runbook §3)

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

### Key deliverables
1. Keyboard/SR regression suite and mobile timeout/error-state acceptance tests (`IMP-UX-01`). (Refs: current-state §4A; evidence-index CLAIM-025, CLAIM-026, CLAIM-032; system-map Diagram A; runbook §2)
2. Cost-control policy and budget guardrails (`IMP-COST-01`). (Refs: current-state §4E; evidence-index CLAIM-076, CLAIM-077, CLAIM-080; system-map Diagram A; runbook §3)
3. Release checklist with compliance/retention/access attestations and rollback playbook. (Refs: current-state §6, §7; evidence-index CLAIM-059, CLAIM-087, CLAIM-088; system-map Diagram A; runbook §5)

### Entry criteria
1. Phase 2 retrieval quality targets are met and documented. (Refs: current-state §4D, §4F; evidence-index CLAIM-065, CLAIM-086; system-map Diagram B; runbook §4)
2. SLO/alert operational data has at least one sprint of trend history. (Refs: current-state §4F, §4G; evidence-index CLAIM-084, CLAIM-121; system-map Diagram B; runbook §3)

### Exit criteria
1. UX/a11y test suite is gating and passing. (Refs: current-state §4A; evidence-index CLAIM-025, CLAIM-032, CLAIM-105; system-map Diagram A; runbook §4)
2. Cost/performance controls are documented, monitored, and accepted by product/platform owners. (Refs: current-state §4E, §4F; evidence-index CLAIM-077, CLAIM-084; system-map Diagram A; runbook §3)
3. Final release packet includes known-unknown disposition and residual risk signoff. (Refs: current-state §8; evidence-index CLAIM-122; system-map Diagram A; runbook §4)

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

## Critical path and blocker list
1. **Blocker B-01 (RESOLVED 2026-03-03):** `/assistant/api/message` strict CSRF path is verified; `/assistant/api/track` follows approved same-origin mitigation and no longer blocks Phase 1 entry criterion #1. (Refs: current-state §8; evidence-index CLAIM-012, CLAIM-123; system-map Diagram B; runbook §2)
2. **Blocker B-02 (RESOLVED 2026-03-03):** `vector_search` schema/export parity is restored and enforced by drift/schema contract tests, so cross-env retrieval tuning is no longer blocked by config parity. (Refs: current-state §4H, §5; evidence-index CLAIM-095, CLAIM-124; system-map Diagram A; runbook §4)
3. **Blocker B-03:** CI workflow ownership/source of truth unknown blocks mandatory gate rollout. (Refs: current-state §8; evidence-index CLAIM-122; system-map Diagram A; runbook §3)
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
