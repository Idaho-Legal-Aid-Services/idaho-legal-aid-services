# ILAS Site Assistant (Aila) Current State Audit

This document is the evidence-backed current-state specification for ILAS Site Assistant (Aila). It is an inventory of what exists now, not a recommendations/remediation document.

Related files:
- `docs/aila/evidence-index.md`
- `docs/aila/system-map.mmd`
- `docs/aila/runbook.md`
- `docs/aila/artifacts/`

## 1) Executive snapshot

Aila is a Drupal custom-module assistant exposed as `/assistant` and `/assistant/api/*`. The backend pipeline is deterministic first (flood controls, validation, safety, out-of-scope, policy, intent routing, retrieval), with optional LLM enhancement (Gemini or Vertex) behind config gates, and observability hooks for Drupal logs, analytics tables, Langfuse queue export, and optional Sentry capture.[^CLAIM-010][^CLAIM-011][^CLAIM-033][^CLAIM-038][^CLAIM-045][^CLAIM-069][^CLAIM-079][^CLAIM-083]

### Audit metadata and context

| Field | Current value | Evidence |
|---|---|---|
| Audit timestamp (UTC) | `2026-02-27T20:15:50Z` | [^CLAIM-001] |
| Runtime addendum capture window (UTC) | `2026-02-26T19:19:30Z` to `2026-02-27T20:15:50Z` | [^CLAIM-108][^CLAIM-122][^CLAIM-123] |
| Git branch | `master` | [^CLAIM-001] |
| Git commit | `4cc709269a3cc8ad1073c3c4e6411d2004dc9860` | [^CLAIM-001] |
| Worktree note | `docs/aila/` was uncommitted at capture | [^CLAIM-002] |
| Local runtime status | Verified in DDEV: stack started, Drupal bootstrap succeeded, drush/runtime endpoint checks captured | [^CLAIM-108][^CLAIM-109][^CLAIM-111][^CLAIM-112] |
| Environment context used | Local = code/config + runtime verification; Pantheon = direct Terminus `remote:drush` verification on dev/test/live + code/config | [^CLAIM-109][^CLAIM-115][^CLAIM-116][^CLAIM-117] |
| Audit generation method | `rg`/file inspection + sanitized runtime artifacts under `docs/aila/runtime/` and `docs/aila/artifacts/`; no secret values captured | [^CLAIM-001][^CLAIM-108][^CLAIM-115][^CLAIM-122] |

### Enablement summary (local export vs Pantheon behavior)

| Capability | Local/config-export view | Pantheon view | Notes |
|---|---|---|---|
| Global widget attachment | Enabled (`enable_global_widget: true`) | Verified enabled in dev/test/live active config | Attach is conditional and path-excluded | [^CLAIM-015][^CLAIM-119] |
| Write-endpoint protection (`/assistant/api/message`, `/assistant/api/track`) | `/assistant/api/message` requires `_csrf_request_header_token` + `_ilas_strict_csrf_token` via `StrictCsrfRequestHeaderAccessCheck`; `/assistant/api/track` uses same-origin `Origin`/`Referer` as primary browser proof, recovery-only bootstrap-token fallback when both headers are missing, plus flood limits | Route and controller contracts match current code; message CSRF matrix and track hybrid-mitigation behavior are covered by functional tests | Message endpoint enforces strict CSRF. Track endpoint uses approved hybrid mitigation for low-impact telemetry writes. | [^CLAIM-012][^CLAIM-123] |
| LLM enhancement | Disabled in exported active config (`llm.enabled: false`) | Verified disabled in dev/test/live active config (`llm.enabled: false`); live runtime override hard-disables `llm.enabled` in `settings.php` | Provider wiring supports Gemini/Vertex | [^CLAIM-069][^CLAIM-094][^CLAIM-099][^CLAIM-119] |
| Vector retrieval supplement | Present in install defaults, disabled by default | Present in active config (`enabled=false`) with schema + export parity checks enforced | Admin form persists values; schema/export parity is enforced by contract tests | [^CLAIM-093][^CLAIM-095][^CLAIM-096][^CLAIM-124] |
| Langfuse tracing/export | Present but disabled by default | `langfuse.settings` config not present in dev/test/live; `ilas_langfuse_export` queue exists with `0` items in sampled runtime | Queue-based export on terminate/cron when enabled/configured | [^CLAIM-079][^CLAIM-082][^CLAIM-118][^CLAIM-120] |
| Sentry integration | Conditional on secret injection | `raven.settings` config not present in dev/test/live sampled runtime | PII send disabled and payload redaction subscriber active when integration is configured | [^CLAIM-083][^CLAIM-120] |
| GA4 tag and live rate-limit override | Not applied outside `live` env branch | Applied in `PANTHEON_ENVIRONMENT=live` branch | Sets `google_tag_id` and per-IP limits | [^CLAIM-099] |
| Promptfoo harness | Repo-local npm scripts + provider | Pantheon target can be used via URL env var | Enforced external CI gate scripts remain in-repo (`scripts/ci/run-external-quality-gate.sh`, `scripts/ci/run-promptfoo-gate.sh`) and first-party workflow (`.github/workflows/quality-gate.yml`) is now the canonical mandatory gate path for blocking branches | [^CLAIM-086][^CLAIM-122] |

Pantheon `config:status` sample results: `dev` and `test` reported no DB/sync differences; `live` reported one `core.entity_view_display.node.adept_lesson.teaser` difference.[^CLAIM-116]

Primary request flow diagram: `docs/aila/system-map.mmd`.[^CLAIM-038][^CLAIM-043][^CLAIM-045]

## 2) System map

- Diagram A (`flowchart LR`) in `docs/aila/system-map.mmd` captures browser/widget -> Drupal -> integrations (Search API, Pinecone, Gemini/Vertex, Langfuse, Sentry, GA4, Promptfoo).[^CLAIM-011][^CLAIM-060][^CLAIM-066][^CLAIM-073][^CLAIM-079][^CLAIM-083][^CLAIM-086]
- Diagram B (`flowchart TD`) captures `/assistant/api/message` lifecycle from flood checks through post-generation enforcement and queue-backed telemetry export.[^CLAIM-033][^CLAIM-038][^CLAIM-045][^CLAIM-046][^CLAIM-048][^CLAIM-081][^CLAIM-082]

## 3) Inventory

### Modules/components

| Component | Type | Location | Current role |
|---|---|---|---|
| `ilas_site_assistant` | Custom Drupal module | `web/modules/custom/ilas_site_assistant` | Core Aila implementation (routes, services, controllers, hooks, templates) | [^CLAIM-010] |
| `b5subtheme` assistant styling | Theme SCSS | `web/themes/custom/b5subtheme/scss/_assistant-widget.scss` | Owns assistant CSS/responsive/accessibility visual rules | [^CLAIM-030][^CLAIM-031] |
| Runtime secret/config overrides | Drupal settings | `web/sites/default/settings.php` | Environment-specific runtime overrides for LLM/Langfuse/Pinecone/Sentry/GA | [^CLAIM-097][^CLAIM-098][^CLAIM-099] |
| Search/vector config entities | Drupal config export | `config/search_api.server.pinecone_vector.yml`; vector index configs | Pinecone-backed vector server + FAQ/resource vector indexes | [^CLAIM-066][^CLAIM-067] |
| Promptfoo evaluation harness | Node tooling | `promptfoo-evals/` + `package.json` scripts | Synthetic evaluation against live/local assistant endpoint | [^CLAIM-086][^CLAIM-103] |
| Security policy headers | SecKit + settings header | `config/seckit.settings.yml`; `web/sites/default/settings.php` | CSP allowlists + explicit Permissions-Policy header | [^CLAIM-100][^CLAIM-101] |

### Service container inventory

| Service ID | Class | Purpose | Depends On | Evidence |
|---|---|---|---|---|
| `cache.ilas_site_assistant` | `Drupal\Core\Cache\CacheBackendInterface` | Dedicated cache bin for assistant state | `ilas_site_assistant` | [^CLAIM-020] |
| `ilas_site_assistant.ranking_enhancer` | `Drupal\ilas_site_assistant\Service\RankingEnhancer` | Re-ranks retrieval candidates | `'@cache.default'` | [^CLAIM-020] |
| `ilas_site_assistant.acronym_expander` | `Drupal\ilas_site_assistant\Service\AcronymExpander` | Expands acronym variants for routing/search | `'@cache.default'` | [^CLAIM-020] |
| `ilas_site_assistant.typo_corrector` | `Drupal\ilas_site_assistant\Service\TypoCorrector` | Corrects common user typos | `'@cache.default', '@ilas_site_assistant.topic_router', '@ilas_site_assistant.acronym_expander'` | [^CLAIM-020] |
| `ilas_site_assistant.keyword_extractor` | `Drupal\ilas_site_assistant\Service\KeywordExtractor` | Extracts routing keywords from text | `'@cache.default', '@ilas_site_assistant.acronym_expander', '@ilas_site_assistant.typo_corrector'` | [^CLAIM-020] |
| `ilas_site_assistant.topic_router` | `Drupal\ilas_site_assistant\Service\TopicRouter` | Rule-based topic matcher | `'@cache.default'` | [^CLAIM-020] |
| `ilas_site_assistant.navigation_intent` | `Drupal\ilas_site_assistant\Service\NavigationIntent` | Navigation intent shortcuts | `-` | [^CLAIM-020] |
| `ilas_site_assistant.intent_router` | `Drupal\ilas_site_assistant\Service\IntentRouter` | Primary deterministic intent router | `'@config.factory', '@ilas_site_assistant.topic_resolver', '@ilas_site_assistant.keyword_extractor', '@ilas_site_assistant.topic_router', '@ilas_site_assistant.navigation_intent', '@ilas_site_assistant.disambiguator', '@ilas_site_assistant.top_intents_pack'` | [^CLAIM-020] |
| `ilas_site_assistant.topic_resolver` | `Drupal\ilas_site_assistant\Service\TopicResolver` | Maps topic IDs to metadata/URLs | `'@entity_type.manager', '@cache.default'` | [^CLAIM-020] |
| `ilas_site_assistant.faq_index` | `Drupal\ilas_site_assistant\Service\FaqIndex` | FAQ retrieval adapter | `'@entity_type.manager', '@cache.default', '@config.factory', '@language_manager', '@ilas_site_assistant.ranking_enhancer'` | [^CLAIM-020] |
| `ilas_site_assistant.resource_finder` | `Drupal\ilas_site_assistant\Service\ResourceFinder` | Resource/form/guide retrieval adapter | `'@entity_type.manager', '@ilas_site_assistant.topic_resolver', '@cache.default', '@language_manager', '@ilas_site_assistant.ranking_enhancer', '@config.factory'` | [^CLAIM-020] |
| `ilas_site_assistant.analytics_logger` | `Drupal\ilas_site_assistant\Service\AnalyticsLogger` | Writes analytics and no-answer records | `'@database', '@config.factory', '@datetime.time', '@logger.channel.ilas_site_assistant'` | [^CLAIM-020] |
| `ilas_site_assistant.policy_filter` | `Drupal\ilas_site_assistant\Service\PolicyFilter` | Fallback safety/policy checks | `'@config.factory'` | [^CLAIM-020] |
| `ilas_site_assistant.llm_admission_coordinator` | `Drupal\ilas_site_assistant\Service\LlmAdmissionCoordinator` | Atomic LLM admission and guard-state coordinator | `'@state', '@config.factory', '@logger.channel.ilas_site_assistant', '@lock'` | [^CLAIM-020] |
| `ilas_site_assistant.llm_circuit_breaker` | `Drupal\ilas_site_assistant\Service\LlmCircuitBreaker` | Circuit breaker around LLM calls | `'@state', '@config.factory', '@logger.channel.ilas_site_assistant', '@ilas_site_assistant.llm_admission_coordinator'` | [^CLAIM-020] |
| `ilas_site_assistant.llm_rate_limiter` | `Drupal\ilas_site_assistant\Service\LlmRateLimiter` | Global LLM call limiter | `'@state', '@config.factory', '@logger.channel.ilas_site_assistant', '@ilas_site_assistant.llm_admission_coordinator'` | [^CLAIM-020] |
| `ilas_site_assistant.cost_control_policy` | `Drupal\ilas_site_assistant\Service\CostControlPolicy` | Cost guardrails and atomic LLM admission policy | `'@state', '@config.factory', '@logger.channel.ilas_site_assistant', '@ilas_site_assistant.llm_circuit_breaker', '@ilas_site_assistant.llm_rate_limiter', '@ilas_site_assistant.llm_admission_coordinator'` | [^CLAIM-020] |
| `ilas_site_assistant.llm_enhancer` | `Drupal\ilas_site_assistant\Service\LlmEnhancer` | Gemini/Vertex enhancement orchestration | `'@config.factory', '@http_client', '@logger.factory', '@ilas_site_assistant.policy_filter', '@cache.ilas_site_assistant', '@ilas_site_assistant.llm_circuit_breaker', '@ilas_site_assistant.llm_rate_limiter', '@ilas_site_assistant.cost_control_policy'` | [^CLAIM-020] |
| `ilas_site_assistant.fallback_gate` | `Drupal\ilas_site_assistant\Service\FallbackGate` | Rule-based gate for LLM fallback | `'@config.factory'` | [^CLAIM-020] |
| `ilas_site_assistant.response_grounder` | `Drupal\ilas_site_assistant\Service\ResponseGrounder` | Grounding guard for links/claims | `-` | [^CLAIM-020] |
| `ilas_site_assistant.safety_classifier` | `Drupal\ilas_site_assistant\Service\SafetyClassifier` | Deterministic safety classifier | `'@config.factory'` | [^CLAIM-020] |
| `ilas_site_assistant.safety_response_templates` | `Drupal\ilas_site_assistant\Service\SafetyResponseTemplates` | Safety response templates | `-` | [^CLAIM-020] |
| `ilas_site_assistant.top_intents_pack` | `Drupal\ilas_site_assistant\Service\TopIntentsPack` | Curated top-intents metadata | `'@cache.default'` | [^CLAIM-020] |
| `ilas_site_assistant.turn_classifier` | `Drupal\ilas_site_assistant\Service\TurnClassifier` | Conversation turn-type classifier | `-` | [^CLAIM-020] |
| `ilas_site_assistant.disambiguator` | `Drupal\ilas_site_assistant\Service\Disambiguator` | Intent disambiguation helper | `-` | [^CLAIM-020] |
| `ilas_site_assistant.out_of_scope_classifier` | `Drupal\ilas_site_assistant\Service\OutOfScopeClassifier` | Deterministic out-of-scope classifier | `'@config.factory'` | [^CLAIM-020] |
| `ilas_site_assistant.out_of_scope_response_templates` | `Drupal\ilas_site_assistant\Service\OutOfScopeResponseTemplates` | Out-of-scope response templates | `-` | [^CLAIM-020] |
| `logger.channel.ilas_site_assistant` | `-` | Module log channel | `'ilas_site_assistant'` | [^CLAIM-020] |
| `ilas_site_assistant.performance_monitor` | `Drupal\ilas_site_assistant\Service\PerformanceMonitor` | Latency/error monitor for health/metrics | `'@state', '@logger.channel.ilas_site_assistant'` | [^CLAIM-020] |
| `ilas_site_assistant.pii_redactor` | `Drupal\ilas_site_assistant\Service\PiiRedactor` | PII redaction utilities | `-` | [^CLAIM-020] |
| `ilas_site_assistant.conversation_logger` | `Drupal\ilas_site_assistant\Service\ConversationLogger` | Stores redacted conversation exchanges | `'@database', '@config.factory', '@datetime.time', '@logger.channel.ilas_site_assistant'` | [^CLAIM-020] |
| `ilas_site_assistant.safety_violation_tracker` | `Drupal\ilas_site_assistant\Service\SafetyViolationTracker` | Tracks safety-violation timestamps | `'@state'` | [^CLAIM-020] |
| `ilas_site_assistant.safety_alert` | `Drupal\ilas_site_assistant\Service\SafetyAlertService` | Threshold-based safety email alerts | `'@config.factory', '@database', '@plugin.manager.mail', '@state', '@datetime.time', '@logger.channel.ilas_site_assistant', '@ilas_site_assistant.safety_violation_tracker'` | [^CLAIM-020] |
| `ilas_site_assistant.ab_testing` | `Drupal\ilas_site_assistant\Service\AbTestingService` | Deterministic experiment assignments | `'@config.factory'` | [^CLAIM-020] |
| `ilas_site_assistant.langfuse_tracer` | `Drupal\ilas_site_assistant\Service\LangfuseTracer` | Langfuse trace/span/event client | `'@config.factory', '@logger.channel.ilas_site_assistant'` | [^CLAIM-020] |
| `ilas_site_assistant.langfuse_terminate_subscriber` | `Drupal\ilas_site_assistant\EventSubscriber\LangfuseTerminateSubscriber` | Queues Langfuse payloads on terminate | `'@ilas_site_assistant.langfuse_tracer', '@queue', '@config.factory', '@logger.channel.ilas_site_assistant'` | [^CLAIM-020] |
| `ilas_site_assistant.sentry_options_subscriber` | `Drupal\ilas_site_assistant\EventSubscriber\SentryOptionsSubscriber` | Sentry payload redaction hooks | `-` | [^CLAIM-020] |

### Routes inventory

| Route | Path | Methods | Permission / CSRF | Controller/Form | Purpose | Evidence |
|---|---|---|---|---|---|---|
| `ilas_site_assistant.page` | `/assistant` | `ANY` | `access content`; No CSRF header requirement | `\Drupal\ilas_site_assistant\Controller\AssistantPageController::page` | Dedicated assistant page | [^CLAIM-011] |
| `ilas_site_assistant.api.message` | `/assistant/api/message` | `POST` | `access content`; CSRF header required | `\Drupal\ilas_site_assistant\Controller\AssistantApiController::message` | Primary chat pipeline endpoint | [^CLAIM-012] |
| `ilas_site_assistant.api.suggest` | `/assistant/api/suggest` | `GET` | `access content`; No CSRF header requirement | `\Drupal\ilas_site_assistant\Controller\AssistantApiController::suggest` | Suggestion API endpoint | [^CLAIM-011] |
| `ilas_site_assistant.api.faq` | `/assistant/api/faq` | `GET` | `access content`; No CSRF header requirement | `\Drupal\ilas_site_assistant\Controller\AssistantApiController::faq` | FAQ API endpoint | [^CLAIM-011] |
| `ilas_site_assistant.admin.settings` | `/admin/config/ilas/site-assistant` | `ANY` | `administer ilas site assistant`; No CSRF header requirement | `\Drupal\ilas_site_assistant\Form\AssistantSettingsForm` | Admin settings form | [^CLAIM-011] |
| `ilas_site_assistant.admin.report` | `/admin/reports/ilas-assistant` | `ANY` | `view ilas site assistant reports`; No CSRF header requirement | `\Drupal\ilas_site_assistant\Controller\AssistantReportController::report` | Admin report dashboard | [^CLAIM-011] |
| `ilas_site_assistant.admin.conversations` | `/admin/reports/ilas-assistant/conversations` | `ANY` | `view ilas site assistant conversations`; No CSRF header requirement | `\Drupal\ilas_site_assistant\Controller\AssistantConversationController::list` | Conversation log list | [^CLAIM-011] |
| `ilas_site_assistant.admin.conversation_detail` | `/admin/reports/ilas-assistant/conversations/{conversation_id}` | `ANY` | `view ilas site assistant conversations`; No CSRF header requirement | `\Drupal\ilas_site_assistant\Controller\AssistantConversationController::detail` | Conversation log detail | [^CLAIM-011] |
| `ilas_site_assistant.api.health` | `/assistant/api/health` | `GET` | `view ilas site assistant reports`; No CSRF header requirement | `\Drupal\ilas_site_assistant\Controller\AssistantApiController::health` | Health check endpoint | [^CLAIM-011] |
| `ilas_site_assistant.api.metrics` | `/assistant/api/metrics` | `GET` | `view ilas site assistant reports`; No CSRF header requirement | `\Drupal\ilas_site_assistant\Controller\AssistantApiController::metrics` | Metrics endpoint | [^CLAIM-011] |
| `ilas_site_assistant.api.track` | `/assistant/api/track` | `POST` | `access content`; no route-level CSRF header requirement (controller enforces same-origin `Origin`/`Referer` proof with recovery-only bootstrap-token fallback) | `\Drupal\ilas_site_assistant\Controller\AssistantApiController::track` | Analytics tracking endpoint | [^CLAIM-012] |

### Libraries inventory

| Library / Asset owner | Assets | Where attached | Current behavior |
|---|---|---|---|
| `ilas_site_assistant/widget` | `js/assistant-widget.js` | `hook_page_attachments()` when widget enabled and path allowed | Global floating widget bootstrap | [^CLAIM-014][^CLAIM-015] |
| `ilas_site_assistant/page` | `js/assistant-widget.js` | `AssistantPageController::page()` | Dedicated `/assistant` page mode bootstrap | [^CLAIM-014][^CLAIM-022] |
| Theme assistant stylesheet | `scss/_assistant-widget.scss` imported by `scss/style.scss` | Theme build pipeline (not module library CSS) | Shared visual system for widget + page | [^CLAIM-030][^CLAIM-031] |

### Runtime entrypoints beyond routes

| Entrypoint type | Implementation | Current behavior |
|---|---|---|
| `hook_page_attachments` | `ilas_site_assistant_page_attachments()` | Conditionally attaches widget + `drupalSettings.ilasSiteAssistant` (API base, CSRF token, disclaimer, feature toggles, canonical URLs) | [^CLAIM-015] |
| `hook_cron` | `ilas_site_assistant_cron()` | Runs analytics cleanup, conversation cleanup, safety-violation prune, safety alert checks | [^CLAIM-016] |
| `hook_mail` | `ilas_site_assistant_mail()` (`safety_alert`) | Emits safety alert email payloads | [^CLAIM-017] |
| Event subscriber | `LangfuseTerminateSubscriber` | Queues Langfuse payload on `kernel.terminate` with depth guard | [^CLAIM-081] |
| Queue worker | `ilas_langfuse_export` | Cron queue worker exports Langfuse batches; retries transient failures via queue suspension | [^CLAIM-082] |
| Drush commands | `KbImportCommands` | Provides `ilas:kb-import` and `ilas:kb-list` CLI commands | [^CLAIM-018][^CLAIM-019] |
| Admin form endpoint | `AssistantSettingsForm` | Persists settings, including vector-search keys | [^CLAIM-011][^CLAIM-096] |

## 4) Feature specs

### A) UI/widget

| Spec item | Current state |
|---|---|
| What it is | JS-driven chat UI in two modes: floating global widget and dedicated `/assistant` page mode.[^CLAIM-021][^CLAIM-022][^CLAIM-029] |
| Where it lives | Module JS/template assets + theme-owned SCSS (`assistant-widget.js`, `assistant-page.html.twig`, `_assistant-widget.scss`).[^CLAIM-030][^CLAIM-031][^CLAIM-032] |
| Trigger | Global attach from `hook_page_attachments` when enabled/not excluded; page mode via `/assistant` route controller attach.[^CLAIM-015][^CLAIM-022] |
| Inputs/outputs | Sends JSON to `/assistant/api/message` and `/assistant/api/track`; renders typed response variants (`faq`, `resources`, `navigation`, `escalation`, etc.).[^CLAIM-024][^CLAIM-026][^CLAIM-049][^CLAIM-050] |
| State used | Client-side ephemeral UUIDv4 `conversationId`, in-memory `messageHistory`, and `drupalSettings.ilasSiteAssistant` config object.[^CLAIM-023][^CLAIM-024][^CLAIM-029] |
| Toggles/flags | `enable_global_widget`, `excluded_paths`, `enable_faq`, `enable_resources`, disclaimer/canonical URL settings propagated to JS.[^CLAIM-015][^CLAIM-094] |
| Accessibility | Dialog roles/labels, ARIA live log, Escape close, focus trap lifecycle, typing indicator status labels, page template ARIA labels.[^CLAIM-025][^CLAIM-032] |
| Mobile/desktop rendering | Theme SCSS includes dedicated mobile breakpoints, reduced-motion handling, and high-contrast rules.[^CLAIM-031] |
| CSP/SecKit implications | CSP enabled with explicit script/style/connect/frame allowlists; Permissions-Policy header is manually set in settings.php.[^CLAIM-100][^CLAIM-101] |
| Failure + observability | Client uses 15s timeout/offline/status-specific messaging; click/chat tracking goes to `dataLayer` and `/assistant/api/track`.[^CLAIM-026][^CLAIM-027][^CLAIM-049] |

### B) Conversation lifecycle

| Spec item | Current state |
|---|---|
| What it is | End-to-end message pipeline in `AssistantApiController::message` with deterministic gates before optional LLM enhancement.[^CLAIM-033][^CLAIM-038][^CLAIM-045] |
| Trigger | `POST /assistant/api/message` route, CSRF-protected, public `access content` permission.[^CLAIM-012][^CLAIM-011] |
| Session creation/ID | Client generates UUID conversation ID; backend validates UUID4 and uses cache key `ilas_conv:<uuid>`.[^CLAIM-023][^CLAIM-036] |
| Processing order | Flood -> validate -> SafetyClassifier -> OutOfScopeClassifier -> PolicyFilter -> intent routing -> retrieval/gate -> generation -> post-safety.[^CLAIM-033][^CLAIM-034][^CLAIM-038][^CLAIM-043][^CLAIM-045] |
| Message-window behavior | Repeated identical-message check triggers escalation short-circuit; server history stores last 10 entries with 30-minute TTL.[^CLAIM-037][^CLAIM-046] |
| Token/length constraints | Request body capped at 2000 chars; LLM generation config enforces `maxOutputTokens` (default path from config/options).[^CLAIM-034][^CLAIM-072] |
| State used | Cache entries for conversation/follow-up slots, optional DB conversation log, analytics tables, state-backed monitors.[^CLAIM-046][^CLAIM-047][^CLAIM-084][^CLAIM-091] |
| Toggles/flags | Rate limits, conversation logging, LLM enable/provider, fallback gate and history-fallback are config-driven (active vs install-default variance).[^CLAIM-093][^CLAIM-094] |
| Failure modes | 429 with `Retry-After`; 4xx validation errors; exceptions return 500 `internal_error` after logging/telemetry capture.[^CLAIM-033][^CLAIM-034][^CLAIM-048] |
| Deterministic dependency degrade contract (Phase 1 Objective #2) | Dependency failure handling is deterministic by response class: retrieval dependency failures degrade to legacy/lexical paths, while uncaught controller failures always return controlled `500 internal_error` (no partial mixed-mode response).[^CLAIM-048][^CLAIM-063][^CLAIM-065] |
| Observability | Request completion logs include intent/safety/gate; optional Langfuse trace spans/events and Sentry tagging on failures.[^CLAIM-048][^CLAIM-079][^CLAIM-080][^CLAIM-083] |
| Integration failure contract formalization (IMP-REL-01) | All failure paths are documented as a consolidated matrix with deterministic fallback classes (legacy_fallback, lexical_preserved, original_preserved, internal_error) and cross-cutting request_id presence. Controller catch-all, observability isolation, and correlation ID header consistency are contract-tested.[^CLAIM-048] |
| Idempotency and replay correctness (IMP-REL-02) | Correlation IDs are verified (accept valid UUID4, reject invalid, generate fallback). Cache keys are deterministic (`ilas_conv:<uuid>`). Repeated messages produce escalation (not duplication). All responses include consistent request_id in body and X-Correlation-ID header.[^CLAIM-035][^CLAIM-046] |
| Response contract expansion (`P2-DEL-01`) | All 200-response paths now include `confidence` (float 0-1, from FallbackGate or 1.0 for deterministic exits), `citations[]` (formalized from ResponseGrounder `sources`), and `decision_reason` (human-readable string from reason codes or path-specific defaults). Error responses (4xx/5xx) are excluded. Assembled by `assembleContractFields()` at five call sites.[^CLAIM-134] |

### C) Safety & compliance layers

| Spec item | Current state |
|---|---|
| Legal-advice posture | UI + config contain explicit "cannot provide legal advice" disclaimers and escalation/refusal templates.[^CLAIM-058][^CLAIM-039] |
| PII/secret redaction | Deterministic redaction service covers common PII patterns with truncation/storage helpers; conversation view is redacted-only.[^CLAIM-053][^CLAIM-059] |
| Deterministic classifier logic | Priority contract is explicit and enforced: Safety -> OOS -> Policy fallback -> intent routing; rules are pattern-based with first-match behavior.[^CLAIM-038][^CLAIM-054][^CLAIM-056][^CLAIM-057] |
| Classifier test artifacts | Unit tests exist for SafetyClassifier and OutOfScopeClassifier behavior suites (file-level evidence only; not executed in this audit run).[^CLAIM-105] |
| Refusal/escalation behavior | Safety and OOS classes return templated early exits with reason codes and action links.[^CLAIM-039][^CLAIM-040] |
| Rate limiting/abuse controls | Per-IP Flood API minute/hour checks plus repeated-message abuse short-circuit behavior.[^CLAIM-033][^CLAIM-037] |
| CSRF protections | Message endpoint enforces strict CSRF (`_csrf_request_header_token` + `_ilas_strict_csrf_token`) while track endpoint uses approved hybrid mitigation: same-origin `Origin`/`Referer` first, recovery-only bootstrap token when both headers are missing, and flood limits throughout.[^CLAIM-012][^CLAIM-123] |
| Prompt-injection defenses | Safety classifier includes prompt-injection/jailbreak patterns; LLM system prompt instructs model to ignore instructions in retrieved content.[^CLAIM-055][^CLAIM-070] |
| Failure/observability | Policy violations and safety exits are logged/analytics-tracked with reason codes; violations can feed safety alert logic.[^CLAIM-039][^CLAIM-047][^CLAIM-089][^CLAIM-090] |

### D) Retrieval

| Spec item | Current state |
|---|---|
| What it is | Retrieval services combine Search API lexical results with optional vector supplementation and legacy fallback paths.[^CLAIM-060][^CLAIM-061][^CLAIM-063][^CLAIM-065] |
| Trigger | Retrieval is invoked from message pipeline after intent routing, with early retrieval before gate decision.[^CLAIM-043] |
| Search API usage | FAQ path uses `faq_accordion`; resources prefer `assistant_resources` with fallback to `content` index.[^CLAIM-060][^CLAIM-064] |
| Ranking/filtering | Resource/FAQ paths apply query filters and score handling; vector results normalized and merged when enabled/needed.[^CLAIM-062][^CLAIM-065] |
| Pinecone details | Search API AI server `pinecone_vector` uses database `ilas-assistant`, collection `default`, cosine metric, Gemini embedding model, 3072 dimensions.[^CLAIM-066] |
| Vector indexes | Vector indexes exist for FAQ paragraphs and resource nodes on `pinecone_vector` server.[^CLAIM-067] |
| Vector index hygiene + metadata standards + refresh monitoring (`P2-DEL-03`) | Managed vector indexes (`faq_accordion_vector`, `assistant_resources_vector`) now run policy-versioned hygiene snapshots with incremental-only refresh cadence checks, metadata drift detection (`server_id`/`metric`/`dimensions`), backlog counters, and overdue tracking. Monitoring is additive only: health/metrics expose nested hygiene fields without retrieval ranking/filtering side effects.[^CLAIM-066][^CLAIM-067][^CLAIM-121][^CLAIM-136] |
| Toggles/flags | Vector supplement behavior is gated by config (`vector_search.*`) with admin form persistence and schema/export parity enforcement.[^CLAIM-061][^CLAIM-095][^CLAIM-096][^CLAIM-124] |
| Failure modes | Vector and Search API failures degrade gracefully to empty/legacy paths; FAQ has explicit legacy entity-query fallback.[^CLAIM-063][^CLAIM-065] |
| Deterministic degrade outcomes (formalized) | Search API unavailable or query exceptions deterministically route to legacy retrieval in FAQ/resource paths. Vector index unavailable/failing paths preserve lexical/legacy output and do not propagate unhandled dependency exceptions upstream.[^CLAIM-063][^CLAIM-065] |
| Observability | Retrieval warnings/info are logged; quality/empty-search conditions flow into analytics/no-answer capture paths.[^CLAIM-085][^CLAIM-047] |
| Source freshness + provenance governance | Retrieval results now include additive governance metadata (`provenance`, `freshness`, `governance_flags`) across lexical/vector FAQ/resource classes. Governance remains soft alerts only: snapshots and cooldowned warnings are exposed in health/metrics surfaces without stale-result suppression, reranking, or architecture rewrite.[^CLAIM-067][^CLAIM-122][^CLAIM-133] |
| Retrieval confidence formalization | FallbackGate `confidence` (float 0-1) and `reason_code` are now surfaced as formal response contract fields on all 200-response paths. Non-retrieval deterministic exits (safety/OOS/policy) receive `confidence: 1.0`. ResponseGrounder `sources[]` are formalized as `citations[]` in the response contract, and Promptfoo contract-metadata assertions now gate citation coverage plus low-confidence refusal behavior thresholds in branch-aware CI policy.[^CLAIM-062][^CLAIM-134][^CLAIM-135] |

### E) Generation / LLM integration

| Spec item | Current state |
|---|---|
| What it is | `LlmEnhancer` can summarize/enhance deterministic outputs when enabled and allowed by gating/policy checks.[^CLAIM-045][^CLAIM-069] |
| Provider wiring | Supports Gemini API and Vertex AI with explicit endpoint constants and auth flows (API key vs bearer token/JWT/metadata token).[^CLAIM-073][^CLAIM-074] |
| Prompt construction | Prompt includes no-legal-advice constraints, scope/tone rules, and instruction to ignore hostile instructions in retrieved content.[^CLAIM-070] |
| Sampling params | Uses `maxOutputTokens`, `temperature`, `topP`, `topK`, plus safety thresholds from config mapping.[^CLAIM-072] |
| Guardrails | Circuit breaker + global rate limiter + retry/backoff + request timeout + post-generation legal-advice enforcement.[^CLAIM-075][^CLAIM-077][^CLAIM-078] |
| State/caching | Policy-versioned cache key with configurable TTL for LLM responses.[^CLAIM-076] |
| Tool/function calling | No explicit tool/function-calling fields are present in Gemini/Vertex payload builders (code inference from payload structures).[^CLAIM-106] |
| Toggles/flags | LLM path requires `llm.enabled` and provider credentials/config; settings are also overridable via runtime secrets in `settings.php`.[^CLAIM-069][^CLAIM-098] |
| Failure + observability | Exception path returns controlled 500 and tags Sentry/Langfuse with request metadata; can fall back when configured.[^CLAIM-048][^CLAIM-075] |

### F) Observability & monitoring

| Spec item | Current state |
|---|---|
| Logging channels | Module-specific logger channel plus analytics logging service for event/no-answer records.[^CLAIM-020][^CLAIM-085] |
| Sentry status | Sentry integration is conditional; options subscriber enforces `send_default_pii=false` and payload redaction before send.[^CLAIM-083][^CLAIM-098] |
| Langfuse status | Langfuse requires config + credentials; traces capture spans/events/generations and export via terminate subscriber + queue worker.[^CLAIM-079][^CLAIM-080][^CLAIM-081][^CLAIM-082] |
| Runtime monitoring | `PerformanceMonitor` records rolling latency/error metrics and exposes p95/p99/error/availability values with SLO-backed thresholds via `/assistant/api/health` and `/assistant/api/metrics`.[^CLAIM-084][^CLAIM-051] |
| SLO policy + alerts | `SloDefinitions` + `SloAlertService` define/enforce availability, latency, error-rate, cron freshness, and queue depth/age SLOs with cooldowned structured warning alerts from cron.[^CLAIM-084][^CLAIM-121] |
| Promptfoo + quality gate harness | Existing test assets are enforced via repo scripts: `tests/run-quality-gate.sh` (unit + deterministic classifier Drupal-unit + golden transcript) and external runner gates (`scripts/ci/run-external-quality-gate.sh`, `scripts/ci/run-promptfoo-gate.sh`) with branch-aware blocking for `master`/`main`/`release/*` and advisory behavior elsewhere. Blocking mode retains deep multi-turn coverage (`promptfooconfig.deep.yaml`) and advisory mode retains abuse/safety coverage (`promptfooconfig.abuse.yaml`), while dataset assertions cover RAG/response-correctness families including topical coherence, caveat/escalation behavior, injection-resistance checks, retrieval confidence/refusal threshold metrics (`rag-contract-meta-present`, `rag-citation-coverage`, `rag-low-confidence-refusal`), and calibrated weak-grounding/escalation/safety-boundary scenario coverage (`p2del04-contract-meta-present`, `p2del04-weak-grounding-handling`, `p2del04-escalation-routing`, `p2del04-escalation-actionability`, `p2del04-safety-boundary-routing`, `p2del04-boundary-dampening`, `p2del04-boundary-urgent-routing`) across 60 Sprint 5 scenarios (20 per family). Harness integrity controls now additionally enforce multiline JS assertion return linting, deterministic per-run eval conversation salting via `ILAS_EVAL_RUN_ID`, and failure adjudication artifact generation without relaxing the 90% blocking threshold. [^CLAIM-086][^CLAIM-105][^CLAIM-122][^CLAIM-132][^CLAIM-135][^CLAIM-137][^CLAIM-144][^CLAIM-150] |
| Redaction posture | Sentry subscriber and analytics/conversation log codepaths apply redaction/truncation before persistence/export.[^CLAIM-053][^CLAIM-083][^CLAIM-085] |

### G) Cron/queues/background processes

| Spec item | Current state |
|---|---|
| Cron entrypoint | `hook_cron()` runs analytics cleanup, conversation cleanup, violation prune, safety alert checks, vector-index hygiene refresh snapshots (`runScheduledRefresh()` with failure isolation), records cron run health, then evaluates SLO alert checks.[^CLAIM-016][^CLAIM-084][^CLAIM-121][^CLAIM-127][^CLAIM-136] |
| Queue workers | `ilas_langfuse_export` queue worker is cron-enabled (`cron.time=30`) for Langfuse export batches.[^CLAIM-082] |
| Langfuse queue behavior | Items are aged/validated, discarded when stale/disabled/misconfigured, and transient API failures suspend queue for retry; queue health tracking exposes backlog depth/utilization and oldest-item age for SLO checks.[^CLAIM-082][^CLAIM-084] |
| Retention/cleanup | Analytics retention uses `log_retention_days`; conversation log retention uses `retention_hours` with batched deletes.[^CLAIM-087][^CLAIM-088] |
| Safety background logic | State-backed violation tracker + threshold/cooldown email alert service run via cron.[^CLAIM-089][^CLAIM-090] |
| Runtime-observed cadence sample | Local + Pantheon `system.cron_last` values were captured; watchdog samples include `ilas_site_assistant_cron()` executions (observed intervals in sample window: ~57 minutes on live, ~2 hours on dev/test).[^CLAIM-114][^CLAIM-117][^CLAIM-121] |

### H) Data model & config

| Spec item | Current state |
|---|---|
| Custom DB schema | Install schema defines `ilas_site_assistant_stats`, `ilas_site_assistant_no_answer`, `ilas_site_assistant_conversations`; update hook adds `request_id` + `intent_created` index.[^CLAIM-091][^CLAIM-092] |
| Config defaults vs active | Active config is now synced with install defaults: all blocks (`fallback_gate`, `safety_alerting`, `history_fallback`, `ab_testing`, `langfuse`, full LLM sub-keys) are present in active export. `conversation_logging.enabled` intentionally `true` in active (install default is `false`). `ConfigCompletenessDriftTest` enforces ongoing parity.[^CLAIM-093][^CLAIM-094][^CLAIM-124] |
| Config schema coverage | Schema covers all install-default blocks including `vector_search`, `fallback_gate`, `safety_alerting`, `history_fallback`, `ab_testing`, `langfuse`, and full LLM sub-keys. `ConfigCompletenessDriftTest` enforces schema-install parity.[^CLAIM-095][^CLAIM-096][^CLAIM-124] |
| Cache/state dependencies | Uses dedicated cache bin + conversation/follow-up TTLs; monitor/circuit/rate/safety trackers use Drupal state.[^CLAIM-020][^CLAIM-046][^CLAIM-077][^CLAIM-084][^CLAIM-089] |
| Key/secrets management | `_ilas_get_secret()` reads Pantheon runtime secrets or env vars; runtime config overrides remain in place for Gemini/Langfuse/Pinecone/Sentry, while the Vertex service-account JSON is now held only in Drupal site settings (`$settings['ilas_vertex_sa_json']`) and the custom Key provider reads that runtime setting without storing the blob in config.[^CLAIM-097][^CLAIM-098] |
| Env-specific overrides | `live` env branch sets GA tag id and per-IP limits in settings.php; Pantheon services YAML differs preprod vs production.[^CLAIM-099][^CLAIM-009] |
| Dependency inventory | Composer and npm dependency snapshots include AI providers, Pinecone, Search API, SecKit, Raven, Langfuse SDK, Drush, Promptfoo.[^CLAIM-102][^CLAIM-103] |

## 5) Toggles & configuration matrix

Values below are taken from install defaults, exported active config, and settings override logic. Secret values are intentionally redacted.

| Feature | Config key / env var | Install default | Exported active config | Pantheon/live behavior | Notes |
|---|---|---|---|---|---|
| Global widget | `enable_global_widget` | `true` | `true` | Verified `true` on dev/test/live | Controls global attach in `hook_page_attachments` | [^CLAIM-015][^CLAIM-093][^CLAIM-094][^CLAIM-119] |
| FAQ retrieval | `enable_faq` | `true` | `true` | Verified `true` on dev/test/live | Exposed to JS via `drupalSettings` | [^CLAIM-015][^CLAIM-093][^CLAIM-094][^CLAIM-119] |
| Resource retrieval | `enable_resources` | `true` | `true` | Verified `true` on dev/test/live | Exposed to JS via `drupalSettings` | [^CLAIM-015][^CLAIM-093][^CLAIM-094][^CLAIM-119] |
| Flood per-minute | `rate_limit_per_minute` | `15` | `15` | Verified `15` on dev/test/live (`ilas_site_assistant.settings`) | Applied in message endpoint flood checks | [^CLAIM-033][^CLAIM-094][^CLAIM-099][^CLAIM-119] |
| Flood per-hour | `rate_limit_per_hour` | `120` | `120` | Verified `120` on dev/test/live (`ilas_site_assistant.settings`) | Applied in message endpoint flood checks | [^CLAIM-033][^CLAIM-094][^CLAIM-099][^CLAIM-119] |
| Conversation logging | `conversation_logging.*` | `enabled=false`, retention `72h`, redaction true | `enabled=true`, retention `72h`, redaction true | Verified `enabled=true`, `retention_hours=72`, `redact_pii=true` on dev/test/live | DB logging is opt-in by config | [^CLAIM-047][^CLAIM-088][^CLAIM-093][^CLAIM-094][^CLAIM-119] |
| LLM master switch | `llm.enabled` | `false` | `false` | Verified `false` on dev/test/live; live runtime override enforces `false` | Gated before any LLM call | [^CLAIM-069][^CLAIM-094][^CLAIM-099][^CLAIM-119] |
| LLM provider/model | `llm.provider`, `llm.model` | `gemini_api`, `gemini-1.5-flash` | `gemini_api`, `gemini-1.5-flash` | Verified `gemini_api` + `gemini-1.5-flash` on dev/test/live | Endpoint constants support both providers | [^CLAIM-073][^CLAIM-074][^CLAIM-093][^CLAIM-094][^CLAIM-119] |
| LLM credentials | `ILAS_GEMINI_API_KEY`, `ILAS_VERTEX_SA_JSON`, `llm.project_id` | API key empty, project ID empty, no service-account blob key in install config | API key/project ID empty; `service_account_json` absent from export | `settings.php` injects Gemini via config override and Vertex via runtime-only site setting | Values redacted intentionally; Vertex JSON is no longer exportable through Drupal config | [^CLAIM-069][^CLAIM-098] |
| LLM generation params | `llm.max_tokens`, `llm.temperature` + hard-coded `topP=0.8`, `topK=40` | `150`, `0.3` | `150`, `0.3` | Same code path | Safety threshold key also applied | [^CLAIM-072][^CLAIM-093][^CLAIM-094] |
| LLM retries/timeout | `llm.max_retries`; code timeout `10s` | `1` | `1` (synced) | Matches install default | Retryable HTTP codes include 429/5xx; sync retry delay capped at `<=250ms` | [^CLAIM-075][^CLAIM-093][^CLAIM-094][^CLAIM-124][^CLAIM-167] |
| LLM cache | `llm.cache_ttl` | `3600` | `3600` (synced) | Matches install default | Cache key includes policy version | [^CLAIM-076][^CLAIM-093][^CLAIM-094][^CLAIM-124] |
| LLM circuit breaker | `llm.circuit_breaker.*` | threshold/window/cooldown present in install defaults | Present (synced) | Matches install defaults | State-backed breaker service | [^CLAIM-077][^CLAIM-093][^CLAIM-094][^CLAIM-124] |
| LLM global rate limit | `llm.global_rate_limit.*` | present in install defaults | Present (synced) | Matches install defaults | State-backed limiter service | [^CLAIM-077][^CLAIM-093][^CLAIM-094][^CLAIM-124] |
| Fallback gate | `fallback_gate.thresholds.*` | Present in install defaults | Present (synced) | Matches install defaults | Used to decide clarify vs LLM path | [^CLAIM-043][^CLAIM-093][^CLAIM-094][^CLAIM-124] |
| Vector supplement | `vector_search.*` | Present, `enabled=false` | Present, `enabled=false` (synced) | Matches install defaults | Schema coverage complete; form persists keys | [^CLAIM-061][^CLAIM-093][^CLAIM-094][^CLAIM-095][^CLAIM-096][^CLAIM-124] |
| History fallback | `history_fallback.*` | Present, `enabled=true` | Present, `enabled=true` (synced) | Matches install defaults | Supports multi-turn routing continuity | [^CLAIM-042][^CLAIM-093][^CLAIM-094][^CLAIM-124] |
| Safety alerting | `safety_alerting.*` | Present, `enabled=false` | Present, `enabled=false` (synced) | Matches install defaults | Cron checks threshold/cooldown; sends email | [^CLAIM-090][^CLAIM-093][^CLAIM-094][^CLAIM-124] |
| Langfuse enablement | `langfuse.enabled` | `false` | `false` (synced) | Requires active config + `LANGFUSE_PUBLIC_KEY`/`LANGFUSE_SECRET_KEY` | Queue depth/age/timeout keys in config path | [^CLAIM-079][^CLAIM-082][^CLAIM-093][^CLAIM-094][^CLAIM-098][^CLAIM-124] |
| Sentry enablement | `SENTRY_DSN` -> `raven.settings.*` | N/A | N/A | `raven.settings` config not present on sampled dev/test/live runtime | Subscriber redacts payload and suppresses default PII when integration is configured | [^CLAIM-083][^CLAIM-098][^CLAIM-120] |
| GA4 tag | `google_tag_id` | N/A | N/A | Set only when `PANTHEON_ENVIRONMENT=live` | Also see client `dataLayer` push behavior | [^CLAIM-027][^CLAIM-099] |
| Promptfoo target URL | `ILAS_ASSISTANT_URL` | N/A | N/A | Can point to Pantheon URL for eval runs | Repo scripts provide live/manual execution | [^CLAIM-086][^CLAIM-107] |

## 6) Security & privacy posture (current state)

### Protections present

- Message endpoint enforces strict CSRF header validation for write requests.[^CLAIM-012][^CLAIM-123]
- Track endpoint uses approved hybrid mitigation: same-origin `Origin`/`Referer` validation is primary, recovery-only bootstrap token is allowed when both headers are missing, and flood limits remain active.[^CLAIM-012]
- Per-IP Flood API limits enforce minute/hour request caps, with explicit 429 handling.[^CLAIM-033]
- Deterministic pre-LLM safety and scope classifiers enforce early exits with reason-coded templates.[^CLAIM-038][^CLAIM-039][^CLAIM-040][^CLAIM-054][^CLAIM-056]
- PII redaction utilities are used for storage/logging paths and Sentry payloads are scrubbed with default PII send disabled.[^CLAIM-053][^CLAIM-083][^CLAIM-085]
- CSP and Permissions-Policy controls are present in exported config/settings.[^CLAIM-100][^CLAIM-101]
- Prompt-injection defenses exist both in deterministic classifier patterns and LLM prompt constraints.[^CLAIM-055][^CLAIM-070]

### Data storage locations and retention

| Data type | Storage | Retention behavior |
|---|---|---|
| Analytics counters/events | `ilas_site_assistant_stats`, `ilas_site_assistant_no_answer` tables | `cleanupOldData()` batched delete by `log_retention_days` | [^CLAIM-087][^CLAIM-091] |
| Conversation exchanges (opt-in) | `ilas_site_assistant_conversations` table | `cleanup()` deletes older than `conversation_logging.retention_hours` | [^CLAIM-047][^CLAIM-088][^CLAIM-091] |
| Conversation context cache | `cache.ilas_site_assistant` (`ilas_conv:*`, follow-up slot keys) | 30-minute TTL, last 10 turns retained per conversation | [^CLAIM-046] |
| LLM/cache artifacts | `cache.ilas_site_assistant` | TTL from `llm.cache_ttl` (default path 3600s when configured/defaulted) | [^CLAIM-076] |
| Safety/performance/rate state | Drupal state API keys | Rolling buffers/windowed counters; pruned/managed in services | [^CLAIM-077][^CLAIM-084][^CLAIM-089] |
| Langfuse export queue | Drupal queue `ilas_langfuse_export` | Items dropped when stale/invalid; retry only for transient failures | [^CLAIM-082] |

### Redaction strategy and factual gaps

- Redaction occurs in dedicated `PiiRedactor`, analytics logger value handling, conversation logger, and Sentry before-send subscriber.[^CLAIM-053][^CLAIM-083][^CLAIM-085]
- Conversation admin UI is permission-gated and displays redacted stored content.[^CLAIM-059]
- Runtime endpoint schema/status checks were executed with synthetic payloads in local DDEV and captured in `docs/aila/runtime/local-endpoints.txt`.[^CLAIM-112][^CLAIM-113]
- Historical config-model gap (`vector_search` schema/export mismatch) is resolved; schema + install + active export parity is enforced by `ConfigCompletenessDriftTest` and `VectorSearchConfigSchemaTest`.[^CLAIM-095][^CLAIM-096][^CLAIM-124]

## 7) Operational runbook summary

Use `docs/aila/runbook.md` for exact commands and safety steps.

Critical command groups documented there:
- Local preflight/runtime (`uname -a`, `docker info`, `ddev version`, `ddev start`, `ddev drush ...`, synthetic `curl` checks).[^CLAIM-108][^CLAIM-109][^CLAIM-111][^CLAIM-112]
- Pantheon runtime checks (`terminus whoami`, `terminus env:wake`, `terminus remote:drush ...`) across dev/test/live.[^CLAIM-115][^CLAIM-116][^CLAIM-117][^CLAIM-118][^CLAIM-119][^CLAIM-120]
- Inventory regeneration (`rg`, route/service extraction, dependency snapshots) into `docs/aila/artifacts/`.[^CLAIM-001][^CLAIM-020][^CLAIM-102]
- Architectural boundary verification commands for Phase 0 NDO #3 (boundary text continuity, core seam-service anchors, bounded service inventory continuity, and Diagram B pipeline anchors).[^CLAIM-020][^CLAIM-125]
- Promptfoo synthetic eval runs (`npm run eval:promptfoo`, `npm run eval:promptfoo:live`).[^CLAIM-086][^CLAIM-103]
- Enforced quality-gate commands for existing test assets (`tests/run-quality-gate.sh`, `scripts/ci/run-external-quality-gate.sh`, `scripts/ci/run-promptfoo-gate.sh`) including branch-aware blocking policy checks.[^CLAIM-086][^CLAIM-105][^CLAIM-122]
- Safe log/trace capture with secret/PII redaction rules and synthetic examples only.[^CLAIM-053][^CLAIM-083][^CLAIM-122]
- Owner-role assignments for Phase 0 entry criterion #2 are documented in runbook §1 and mirrored in the roadmap owner matrix for CSRF hardening and policy governance workstreams.[^CLAIM-013]

## 8) Known unknowns

| Unknown | Why unknown now | Evidence needed to resolve |
|---|---|---|
| Long-run cron cadence and queue drain timing under load | Cron samples and `system.cron_last` snapshots were captured, but no continuous observation window or non-zero queue backlog was captured in this addendum | Time-series cron observations + queue depth/throughput metrics over a sustained interval | [^CLAIM-114][^CLAIM-117][^CLAIM-118][^CLAIM-121] |
| ~~Promptfoo CI ownership outside this repository~~ **RESOLVED** | First-party GitHub Actions workflow (`.github/workflows/quality-gate.yml`) now exists with branch protection required status checks (`PHPUnit Quality Gate` + `Promptfoo Gate`) on `master`. `enforce_admins: true` prevents bypass. Concurrency control prevents stale-run races. CI quality gate is mandatory for merge/release path. | [^CLAIM-122] |

### Phase 2 Objective #3 Source Freshness + Provenance Governance Disposition (2026-03-03)

This dated addendum records `P2-OBJ-03` completion for Phase 2 Objective #3:
"Enforce governance around source freshness and provenance."

1. Source freshness/provenance governance is now an enforceable in-repo contract:
   config policy, schema coverage, runtime annotations, and guard tests are
   aligned for four source classes (`faq_lexical`, `faq_vector`,
   `resource_lexical`, `resource_vector`).[^CLAIM-067][^CLAIM-133]
2. Governance behavior is explicitly soft alerts only: stale/unknown/missing
   provenance conditions are observable via annotations, snapshots, and
   cooldowned warnings, while retrieval filtering/ranking outputs remain
   unchanged.[^CLAIM-067][^CLAIM-133]
3. Monitoring surfaces now carry governance state in existing contracts:
   health checks include `checks.source_governance`; metrics include nested
   `metrics.source_governance` and `thresholds.source_governance` while
   preserving top-level payload shape.[^CLAIM-122][^CLAIM-133]
4. Scope boundaries remain unchanged for this objective closure: no live
   production LLM enablement through Phase 2 and no broad platform migration
   outside the current Pantheon baseline.[^CLAIM-115][^CLAIM-119][^CLAIM-133]
5. Follow-on tuning keeps soft-alert semantics but reduces small-sample noise:
   degraded status for `unknown_freshness`/`missing_source_url` now uses
   ratio+minimum-observation thresholds (`min_observations=20`,
   `unknown_ratio_degrade_pct=22.0`, `missing_source_url_ratio_degrade_pct=9.0`)
   while stale-ratio degradation remains unchanged. Snapshot fields expose
   cooldown timing (`last_alert_at`, `next_alert_eligible_at`,
   `cooldown_seconds_remaining`) for deterministic operations visibility.[^CLAIM-133]

### Phase 2 Deliverable #1 Response Contract Expansion Disposition (2026-03-03)

This dated addendum records `P2-DEL-01` completion for Phase 2 Key Deliverable #1:
"`/assistant/api/message` contract expansion: `confidence`, `citations[]`, `decision_reason`, request-id normalization."

1. All 200-response paths now carry three formal contract fields: `confidence`
   (float 0-1, from FallbackGate evaluation or 1.0 for deterministic exits),
   `citations[]` (formalized from ResponseGrounder `sources[]`), and
   `decision_reason` (human-readable string derived from FallbackGate reason
   codes or path-specific defaults for early exits).[^CLAIM-134]
2. Contract fields are assembled by `assembleContractFields()` and injected at
   five call sites: safety exit, OOS exit, policy violation exit, repeated-message
   exit, and normal pipeline completion. Error responses (4xx/5xx) are excluded
   and retain their minimal shape.[^CLAIM-134]
3. Request-id normalization (IMP-REL-02) is verified complete: `resolveCorrelationId()`
   validates UUID4, rejects invalid inputs, and generates fallback correlation IDs.
   No additional changes were needed.[^CLAIM-035][^CLAIM-134]
4. Langfuse grounding span bug fixed: citation field check now references
   `$response['sources']` (produced by ResponseGrounder) instead of
   `$response['citations']` (populated later by contract assembly).[^CLAIM-134]
5. Scope boundaries remain unchanged: no live production LLM enablement through
   Phase 2 and no broad platform migration outside the current Pantheon
   baseline.[^CLAIM-115][^CLAIM-119][^CLAIM-134]

### Phase 2 Deliverable #2 Retrieval Confidence/Refusal Threshold Gating Disposition (2026-03-03)

This dated addendum records `P2-DEL-02` completion for Phase 2 Key Deliverable #2:
"Retrieval confidence/refusal thresholds integrated with eval harness and regression gating (`IMP-RAG-01`)."

1. Promptfoo eval harness now includes retrieval confidence/refusal threshold
   contract tests (`promptfoo-evals/tests/retrieval-confidence-thresholds.yaml`)
   that assert machine-readable contract metadata and threshold metrics:
   `rag-contract-meta-present`, `rag-citation-coverage`,
   `rag-low-confidence-refusal`.[^CLAIM-086][^CLAIM-135]
2. Promptfoo provider output now appends a deterministic `[contract_meta]` JSON
   line carrying `confidence`, `citations_count`, `response_type`,
   `response_mode`, `reason_code`, and `decision_reason` for metric-level
   assertions without changing production API schema.[^CLAIM-134][^CLAIM-135]
3. Branch-aware gate script enforcement now includes per-metric retrieval
   thresholds at 90% minimum and records threshold outcomes in gate summary
   artifacts; blocking/advisory branch semantics remain unchanged.[^CLAIM-086][^CLAIM-132][^CLAIM-135]
4. Backlog/risk linkage moved to active mitigation for retrieval-confidence
   ambiguity (`R-RAG-01`) with detection signals centered on weak-result rate,
   citation coverage, and low-confidence refusal/clarification ratio trends.[^CLAIM-047][^CLAIM-085][^CLAIM-135]
5. Scope boundaries remain unchanged: no live production LLM enablement through
   Phase 2 and no broad platform migration outside the current Pantheon
   baseline.[^CLAIM-115][^CLAIM-119][^CLAIM-135]

### Phase 2 Deliverable #3 Vector Index Hygiene + Refresh Monitoring Disposition (2026-03-04)

This dated addendum records `P2-DEL-03` completion for Phase 2 Key Deliverable #3:
"Vector index hygiene policy, metadata standards, and refresh monitoring (`IMP-RAG-02`)."

1. Vector index hygiene is now an enforceable in-repo contract through
   `VectorIndexHygieneService` with policy-versioned defaults, managed index
   standards (`faq_accordion_vector`, `assistant_resources_vector`), and
   incremental-only refresh operations (`indexItems(max_items_per_run)`).[^CLAIM-066][^CLAIM-067][^CLAIM-136]
2. Metadata compliance checks now evaluate index existence/enabled state,
   expected server ID, backend metric, and dimensions; status is surfaced per
   index as `compliant`, `drift`, or `unknown` with explicit `drift_fields` and
   `last_error` capture for isolated failures.[^CLAIM-066][^CLAIM-067][^CLAIM-136]
3. Cron integration now captures hygiene snapshots every run with due/overdue
   cadence logic (`refresh_interval_hours=24`, `overdue_grace_minutes=45`),
   tracker backlog counters, and cooldowned degraded alerts while preserving
   existing cron-health and SLO-ordering behavior.[^CLAIM-121][^CLAIM-127][^CLAIM-136]
4. Monitoring contracts are extended additively: `/assistant/api/health` now
   includes `checks.vector_index_hygiene`, and `/assistant/api/metrics` includes
   `metrics.vector_index_hygiene` and `thresholds.vector_index_hygiene` without
   top-level payload-shape changes.[^CLAIM-121][^CLAIM-136]
5. Backlog/risk linkage moved to active mitigation for `R-RAG-02` and
   `R-GOV-02`; scope boundaries remain unchanged: no live production LLM
   enablement through Phase 2 and no broad platform migration outside the
   current Pantheon baseline.[^CLAIM-115][^CLAIM-119][^CLAIM-136]

### Phase 2 Deliverable #4 Promptfoo Dataset Expansion Disposition (2026-03-04)

This dated addendum records `P2-DEL-04` completion for Phase 2 Key Deliverable #4:
"Promptfoo dataset expansion for weak grounding, escalation, and safety boundary scenarios."

1. Promptfoo regression coverage now includes a dedicated `P2-DEL-04` dataset
   (`promptfoo-evals/tests/grounding-escalation-safety-boundaries.yaml`) with
   baseline `P2-DEL-04` family structure (`weak_grounding`, `escalation`,
   `safety_boundary`) tagged in `metadata.scenario_family`; Sprint 5 calibration
   expands this same dataset to 60 scenarios with 20 per family.[^CLAIM-086][^CLAIM-137][^CLAIM-144]
2. The new suite asserts contract metadata continuity
   (`confidence`, `response_type`, `response_mode`, `reason_code`,
   `decision_reason`) and family-specific behavior checks for weak-grounding
   handling, escalation routing/actionability, and safety-boundary
   dampening/refusal transitions.[^CLAIM-055][^CLAIM-086][^CLAIM-105][^CLAIM-137]
3. Gate wiring remains additive only: `promptfooconfig.abuse.yaml` includes the
   new dataset, while branch-aware pass/fail mode and retrieval-threshold gate
   policy remain unchanged.[^CLAIM-086][^CLAIM-135][^CLAIM-137]
4. Deliverable closure continuity is locked via
   `PhaseTwoDeliverableFourGateTest.php`; risk linkage is recorded to
   `R-MNT-02` and `R-LLM-01` with conservative text updates and no status
   transitions.[^CLAIM-105][^CLAIM-137]
5. Scope boundaries remain unchanged: no live production LLM enablement through
   Phase 2 and no broad platform migration outside the current Pantheon
   baseline.[^CLAIM-115][^CLAIM-119][^CLAIM-137]

### Phase 2 Sprint 4 Response Contract + Retrieval Confidence Retune Disposition (2026-03-05)

This dated addendum records `P2-SBD-01` completion for Phase 2 Sprint 4 closure:
"Sprint 4: response contract + retrieval-confidence implementation and tests."

1. Response contract fields remain additive and stable on all 200-response paths:
   `confidence`, `citations[]`, and `decision_reason` are still assembled at the
   same call sites, while confidence is now normalized/clamped to a finite
   float in `[0,1]` and citations are safely derived from result metadata when
   `sources[]` are sparse.[^CLAIM-134][^CLAIM-143]
2. Retrieval-confidence gate retune preserves current routing posture for
   high-intent/no-results retrieval: `REASON_NO_RESULTS` still answers, but
   confidence is capped at `<= 0.49` with explicit decision-details markers for
   debugging and threshold tuning. This closure explicitly enforces confidence
   cap (`<= 0.49`) for `REASON_NO_RESULTS` to keep weak-grounding behavior
   measurable without changing route class outcomes.[^CLAIM-062][^CLAIM-135][^CLAIM-143]
3. Promptfoo threshold policy remains unchanged at 90% per metric; gate
   summaries now include count-floor diagnostics
   (`rag_metric_min_count`, `rag_contract_meta_count_fail`,
   `rag_citation_coverage_count_fail`, `rag_low_confidence_refusal_count_fail`)
   to distinguish low-sample failures from pass-rate drift.[^CLAIM-086][^CLAIM-135][^CLAIM-143]
4. Sprint closure is now enforced by dedicated guard coverage
   (`PhaseTwoSprintFourGateTest.php`) and response-contract normalization tests
   (`ResponseContractNormalizationTest.php`) tied to required aliases
   `VC-UNIT` and `VC-QUALITY-GATE`.[^CLAIM-105][^CLAIM-143]
5. Scope boundaries remain unchanged: no live production LLM enablement through
   Phase 2 and no broad platform migration outside the current Pantheon
   baseline.[^CLAIM-115][^CLAIM-119][^CLAIM-143]

### Phase 2 Sprint 5 Dataset Expansion + Provenance/Freshness Workflow Calibration + Threshold Calibration Disposition (2026-03-05)

This dated addendum records `P2-SBD-02` completion for Phase 2 Sprint 5 closure:
"Sprint 5: dataset expansion, provenance/freshness workflows, threshold calibration."

1. Sprint 5 promptfoo dataset calibration is now locked in repo state:
   `promptfoo-evals/tests/grounding-escalation-safety-boundaries.yaml` defines
   60 scenarios with exact family distribution (`weak_grounding=20`,
   `escalation=20`, `safety_boundary=20`) and required `p2del04-*` metric
   coverage/floors (`contract-meta=60`, family checks=20 each,
   boundary dampening/urgent routing `>=10`).[^CLAIM-086][^CLAIM-137][^CLAIM-144]
2. Promptfoo gate policy is calibrated in `scripts/ci/run-promptfoo-gate.sh`:
   `RAG_METRIC_MIN_COUNT=10`, `P2DEL04_METRIC_THRESHOLD=85`,
   `P2DEL04_METRIC_MIN_COUNT=10`, plus `p2del04_*` summary/fail fields that are
   included in blocking/advisory pass-fail evaluation paths.[^CLAIM-086][^CLAIM-135][^CLAIM-144]
3. Source-governance threshold calibration is applied in both install and active
   config and mirrored in service defaults:
   `stale_ratio_alert_pct=18.0`, `unknown_ratio_degrade_pct=22.0`,
   `missing_source_url_ratio_degrade_pct=9.0`. Governance remains soft-alert-only
   with no retrieval filtering/ranking side effects.[^CLAIM-067][^CLAIM-133][^CLAIM-144]
4. Vector-index hygiene threshold calibration is applied in both install and
   active config and mirrored in service defaults:
   `refresh_interval_hours=24`, `overdue_grace_minutes=45`,
   `max_items_per_run=60`, `alert_cooldown_minutes=60`.[^CLAIM-066][^CLAIM-136][^CLAIM-144]
5. Sprint-level closure continuity is enforced through docs/evidence/runtime
   anchors and `PhaseTwoSprintFiveGateTest.php` with required aliases
   `VC-UNIT` and `VC-QUALITY-GATE` captured in
   `docs/aila/runtime/phase2-sprint5-closure.txt`.[^CLAIM-105][^CLAIM-144]
6. System-map continuity is unchanged for Sprint 5 scope; no diagram change
   required because no new architecture edge was introduced. Scope boundaries
   remain unchanged: no live production LLM enablement through Phase 2 and no
   broad platform migration outside the current Pantheon baseline.[^CLAIM-115][^CLAIM-119][^CLAIM-144]

### Phase 0 Exit #3 Dependency Disposition (2026-02-27)

This dated addendum preserves the historical baseline above and records the
P0-EXT-03 dependency-gate disposition for Phase 1 planning:

1. CLAIM-120 dependency is unblocked via readiness gates (runbook commands +
   redaction/queue contract tests); telemetry remains disabled until Phase 1
   credential provisioning and destination approvals.
2. CLAIM-122 dependency is unblocked via Pantheon/local gate ownership using
   runbook verification commands plus repo-scripted external CI runner
   promptfoo targeting (`scripts/ci/*`).

### Cross-Phase Dependency Row #1 CSRF Guardrail Disposition (2026-03-06)

This dated addendum records `XDP-01` closure for cross-phase dependency row #1:
"CSRF hardening (`IMP-SEC-01`)."

1. Dependency row #1 is closure-locked as implemented: authenticated test matrix
   and route-enforcement verification are required prerequisites for downstream
   Phase 1-3 continuity, with owner-role continuity preserved
   (Security Engineer + Drupal Lead).[^CLAIM-012][^CLAIM-123][^CLAIM-160]
2. Dependency reporting semantics are deterministic:
   any unresolved prerequisite reports `xdp-01-status=blocked`; all prerequisites
   pass reports `xdp-01-status=closed` with
   `xdp-01-unresolved-dependency-count=0` and
   `xdp-01-unresolved-dependencies=none`.[^CLAIM-160]
3. Runtime proof and command summaries are captured in
   `docs/aila/runtime/phase0-xdp01-csrf-hardening-dependency-gate.txt`,
   including prerequisite pass/fail markers for authenticated test matrix and
   route-enforcement verification.[^CLAIM-160]
4. Scope boundaries remain unchanged: this closure adds governance enforcement
   artifacts only and does not expand runtime behavior.[^CLAIM-160]

### Cross-Phase Dependency Row #2 Config Parity Guardrail Disposition (2026-03-06)

This dated addendum records `XDP-02` closure for cross-phase dependency row #2:
"Config parity (`IMP-CONF-01`)."

1. Dependency row #2 is closure-locked as implemented: schema mapping and env
   drift checks are required prerequisites for downstream Phase 2 retrieval
   tuning continuity, with owner-role continuity preserved (Drupal Lead).[^CLAIM-095][^CLAIM-124][^CLAIM-161]
2. Dependency reporting semantics are deterministic: any unresolved prerequisite
   reports `xdp-02-status=blocked`; all prerequisites pass reports
   `xdp-02-status=closed` with `xdp-02-unresolved-dependency-count=0` and
   `xdp-02-unresolved-dependencies=none`.[^CLAIM-161]
3. Runtime proof and command summaries are captured in
   `docs/aila/runtime/phase0-xdp02-config-parity-dependency-gate.txt`,
   including prerequisite pass/fail markers for schema mapping and env drift
   checks.[^CLAIM-161]
4. Scope boundaries remain unchanged: this closure adds governance enforcement
   artifacts only and does not expand runtime behavior.[^CLAIM-161]

### Cross-Phase Dependency Row #3 Observability Baseline Guardrail Disposition (2026-03-06)

This dated addendum records `XDP-03` closure for cross-phase dependency row #3:
"Observability baseline (`IMP-OBS-01`)."

1. Dependency row #3 is closure-locked as implemented: Sentry/Langfuse
   credential readiness and redaction validation are required prerequisites for
   downstream Phase 2/3 optimization continuity, with owner-role continuity
   preserved (SRE/Platform Engineer).[^CLAIM-083][^CLAIM-120][^CLAIM-126][^CLAIM-162]
2. Dependency reporting semantics are deterministic: any unresolved prerequisite
   reports `xdp-03-status=blocked`; all prerequisites pass reports
   `xdp-03-status=closed` with `xdp-03-unresolved-dependency-count=0` and
   `xdp-03-unresolved-dependencies=none`.[^CLAIM-162]
3. Runtime proof and command summaries are captured in
   `docs/aila/runtime/phase1-xdp03-observability-baseline-dependency-gate.txt`,
   including prerequisite pass/fail markers for Sentry/Langfuse credential
   readiness and redaction validation.[^CLAIM-162]
4. Scope boundaries remain unchanged: this closure adds governance enforcement
   artifacts only and does not expand runtime behavior.[^CLAIM-162]

### Cross-Phase Dependency Row #4 CI Quality Gate Guardrail Disposition (2026-03-06)

This dated addendum records `XDP-04` closure for cross-phase dependency row #4:
"CI quality gate (`IMP-TST-01`)."

1. Dependency row #4 is closure-locked as implemented: CI owner/platform
   decision continuity is a required prerequisite for downstream release-gate
   continuity, with owner-role continuity preserved
   (QA/Automation Engineer + TPM).[^CLAIM-122][^CLAIM-130][^CLAIM-163]
2. Dependency reporting semantics are deterministic:
   any unresolved prerequisite reports `xdp-04-status=blocked`; all prerequisites
   pass reports `xdp-04-status=closed` with
   `xdp-04-unresolved-dependency-count=0` and
   `xdp-04-unresolved-dependencies=none`.[^CLAIM-163]
3. Runtime proof and command summaries are captured in
   `docs/aila/runtime/phase1-xdp04-ci-quality-gate-dependency-gate.txt`,
   including prerequisite pass/fail markers for CI owner/platform decision
   continuity and mandatory merge/release gate continuity.[^CLAIM-163]
4. Scope boundaries remain unchanged: this closure adds governance enforcement
   artifacts only and does not expand runtime behavior.[^CLAIM-163]

### Cross-Phase Dependency Row #5 Retrieval Confidence Contract Guardrail Disposition (2026-03-06)

This dated addendum records `XDP-05` closure for cross-phase dependency row #5:
"Retrieval confidence contract (`IMP-RAG-01`)."

1. Dependency row #5 is closure-locked as implemented: config parity,
   observability signals, and eval-harness runtime behavior are required
   prerequisites for downstream Phase 3 readiness signoff, with
   owner-role continuity preserved (AI/RAG Engineer).[^CLAIM-120][^CLAIM-124][^CLAIM-135][^CLAIM-151][^CLAIM-164]
2. Dependency reporting semantics are deterministic:
   any unresolved prerequisite reports `xdp-05-status=blocked`; all prerequisites
   pass reports `xdp-05-status=closed` with
   `xdp-05-unresolved-dependency-count=0` and
   `xdp-05-unresolved-dependencies=none`.[^CLAIM-164]
3. Runtime proof and command summaries are captured in
   `docs/aila/runtime/phase2-xdp05-retrieval-confidence-contract-dependency-gate.txt`,
   including prerequisite pass/fail markers for config parity,
   observability signals, and eval-harness behavior, with non-blocking docs
   continuity retained separately.[^CLAIM-164]
4. Scope boundaries remain unchanged: this closure adds governance enforcement
   artifacts only and does not expand runtime behavior.[^CLAIM-164]

### Cross-Phase Dependency Row #6 Cost Guardrails Guardrail Disposition (2026-03-07)

This dated addendum records `XDP-06` closure for cross-phase dependency row #6:
"Cost guardrails (`IMP-COST-01`)."

1. Dependency row #6 is closure-locked as implemented: cost-control config,
   fail-closed cost policy behavior, and SLO monitoring are required
   prerequisites for downstream Phase 3 cost guardrails, with owner-role
   continuity preserved (Product + Platform).[^CLAIM-077][^CLAIM-084][^CLAIM-147][^CLAIM-165]
2. Dependency reporting semantics are deterministic:
   any unresolved prerequisite reports `xdp-06-status=blocked`; all prerequisites
   pass reports `xdp-06-status=closed` with
   `xdp-06-unresolved-dependency-count=0` and
   `xdp-06-unresolved-dependencies=none`.[^CLAIM-165]
3. Runtime proof and command summaries are captured in
   `docs/aila/runtime/phase3-xdp06-cost-guardrails-dependency-gate.txt`,
   including prerequisite pass/fail markers for cost-control config,
   fail-closed cost policy behavior, and SLO monitoring, with non-blocking
   docs continuity retained separately.[^CLAIM-165]
4. Scope boundaries remain unchanged: this closure adds governance enforcement
   artifacts only and does not expand runtime behavior.[^CLAIM-165]

### Phase 1 Objective #3 Quality Gate Disposition (2026-02-27)

This dated addendum formalizes conversion of existing test assets into
enforced quality gates while preserving the external CI ownership known
unknown.

Historical note: this disposition is superseded for merge/release enforcement by
the Phase 1 Exit #2 Mandatory Gate Disposition (2026-03-03).

1. `tests/run-quality-gate.sh` is the mandatory module-level gate and now
   enforces unit coverage, deterministic classifier Drupal-unit coverage
   (Safety + OutOfScope), and golden transcript replay checks.
2. `scripts/ci/run-promptfoo-gate.sh` enforces branch-aware Promptfoo
   threshold policy (`master`/`main`/`release/*` blocking; other branches advisory).
3. `scripts/ci/run-external-quality-gate.sh` composes PHPUnit and Promptfoo
   gates for external runners; first-party workflow ownership remains external
   to this repository.

### IMP-OBS-01 Completion Addendum (2026-02-27)

This dated addendum records IMP-OBS-01 (Sentry and Langfuse Staged Enablement
with Redaction Validation) completion:

1. **TelemetrySchema value object** (`src/Service/TelemetrySchema.php`) provides
   a single source of truth for telemetry field names (`intent`, `safety_class`,
   `fallback_path`, `request_id`, `env`) with safe defaults via `normalize()`.
2. **Controller integration**: All 5 `endTrace()` exit points in
   `AssistantApiController::message()` now emit normalized telemetry schema
   fields in Langfuse metadata. Sentry `configureScope` uses constant names.
3. **Sentry tag promotion**: `SentryOptionsSubscriber::beforeSendCallback()`
   promotes recognized `TelemetrySchema::REQUIRED_FIELDS` from extra context
   to tags, ensuring watchdog-captured errors get searchable telemetry tags.
4. **SLO alert dimensions**: All 4 `SloAlertService` warning log calls include
   `@slo_dimension` context for Sentry alert rule filtering.
5. **Acceptance tests**: `ImpObs01AcceptanceTest.php` proves Sentry AC (env tags
   + PII scrub + runbook doc-lock) and Langfuse AC (full lifecycle + queue
   health + policy-cap lock). `TelemetrySchemaContractTest.php` guards field
   consistency across controller and subscriber source files.
6. **Residual**: B-04 (cron/queue throughput under load) remains unresolved
   until sustained post-deploy observation. Does not block IMP-OBS-01.

### Phase 1 Entry #2 Credential and Destination Disposition (2026-03-02)

This dated addendum records P1-ENT-02 (Platform credentials and destination
approvals) verification:

1. **Credential availability**: Runtime evidence (`phase1-observability-gates.txt`)
   confirms `LANGFUSE_PUBLIC_KEY`, `LANGFUSE_SECRET_KEY`, and `SENTRY_DSN` are
   provisioned and resolved via `_ilas_get_secret()` on all Pantheon environments
   (dev/test/live). Langfuse keys wire to `ilas_site_assistant.settings.langfuse.*`;
   Sentry DSN wires to `raven.settings.client_key`.[^CLAIM-097][^CLAIM-098][^CLAIM-126]
2. **Approved telemetry destinations**:
   - Langfuse US cloud: `https://us.cloud.langfuse.com` (install config default,
     settings.php override path). Approved for trace/span/generation/event export.
   - Sentry (via drupal/raven): DSN-controlled destination. Approved for error
     tracking with PII redaction enforced (`send_default_pii=false`,
     `SentryOptionsSubscriber` before-send scrub).[^CLAIM-083][^CLAIM-098]
3. **Phase constraints preserved**: `langfuse.enabled` remains `false` in exported
   config; runtime overrides activate it per-environment. `llm.enabled` remains
   `false` on all environments. Live sample rate is policy-capped at 0.1.[^CLAIM-119][^CLAIM-120]
4. **Gate test**: `TelemetryCredentialGateTest.php` enforces settings.php wiring,
   install config defaults, runtime evidence, and destination documentation.[^CLAIM-126]

### Re-Audit Remediation RAUD-03 Vertex Runtime Secret Disposition (2026-03-09)

This dated addendum records re-audit remediation `RAUD-03` for findings `C2`
and `F-15`.

1. The custom admin form no longer exposes an editable Vertex service-account
   JSON textarea. Operators can still set `llm.provider`, `llm.project_id`, and
   `llm.location`, but the credential itself is runtime-only and is not
   displayed or stored in Drupal config.
2. `settings.php` now loads `ILAS_VERTEX_SA_JSON` into the Drupal site setting
   `ilas_vertex_sa_json` instead of writing the blob into
   `ilas_site_assistant.settings` or `key.key.vertex_sa_credentials`.
3. `LlmEnhancer` now reads the Vertex credential from the runtime site setting
   only, then falls back to the metadata-server token path when the runtime
   secret is absent.
4. The synced key entity `vertex_sa_credentials` now uses the custom
   `ilas_runtime_site_setting` provider with non-secret config
   (`settings_key: ilas_vertex_sa_json`) and `key_input: none`, eliminating the
   second Drupal config secret-storage path.
5. Regression coverage was added in `VertexRuntimeCredentialGuardTest.php` and
   `ConfigCompletenessDriftTest.php` to fail if the service-account JSON can be
   exported or saved again.
6. Local verification is captured in
   `docs/aila/runtime/raud-03-vertex-runtime-secret-remediation.txt`; Pantheon
   runtime-secret presence remains deployment-bound and must be rechecked after
   deployment before the finding can be marked fully fixed in a live
   environment.

### Re-Audit Remediation RAUD-05 LLM Transport Hardening (2026-03-09)

This dated addendum records re-audit remediation `RAUD-05` for findings `C3`,
`C4`, and `LLM-1`.

1. `LlmEnhancer` now caches Vertex bearer tokens in
   `cache.ilas_site_assistant` using source-specific cache keys derived from
   either the runtime service-account JSON hash or the metadata-server fallback
   path.
2. Cached token TTLs now apply a 100-second safety buffer and a 3500-second
   ceiling; malformed or missing `expires_in` values fall back to the capped
   3500-second window instead of forcing a fresh token fetch on every request.
3. Synchronous retry behavior is now bounded to one retry with a maximum
   `250ms` backoff window, eliminating the earlier exponential multi-second
   sleep path on ordinary `429`/`5xx` failures.
4. Install/default/exported config now pins `llm.max_retries` to `1` so the
   transport ceiling is explicit in the synced config contract.
5. Regression coverage in `LlmEnhancerHardeningTest.php` now proves cross-
   instance token reuse, buffered-expiry refresh, single-retry ceilings, and
   bounded retry delays.
6. Local verification and post-change latency evidence are captured in
   `docs/aila/runtime/raud-05-llm-transport-hardening.txt`.[^CLAIM-167]

### Re-Audit Remediation RAUD-08 Reverse-Proxy / Client-IP Trust (2026-03-09)

This dated addendum records re-audit remediation `RAUD-08` for findings `F-05`
and `N-05`.

1. `settings.php` now defines an explicit runtime-only reverse-proxy contract:
   `ILAS_TRUSTED_PROXY_ADDRESSES` must contain a comma-separated IP/CIDR
   allowlist before Drupal will enable `reverse_proxy`,
   `reverse_proxy_addresses`, and `reverse_proxy_trusted_headers`.
2. Request identity for assistant flood control is now centralized in
   `RequestTrustInspector`, which records the effective client IP, raw
   forwarded-header inputs, configured trusted proxies, and a normalized trust
   status before `/assistant/api/message` or `/assistant/api/track` derives its
   flood key.
3. Admin-only `/assistant/api/health` and `/assistant/api/metrics` now expose a
   `proxy_trust` diagnostic block so operators can prove which IP source is in
   effect without exposing the same data on public routes.
4. Regression coverage now includes unit tests for trusted/untrusted forwarded
   chains, controller-level flood identifier tests for `message()` and
   `track()`, a settings contract test for the runtime proxy allowlist, and
   functional assertions that admin health/metrics responses include
   `proxy_trust`.
5. Local verification is captured in
   `docs/aila/runtime/raud-08-reverse-proxy-client-ip-trust.txt`. Pantheon
   read-only verification on March 9, 2026 still reported unset reverse-proxy
   settings on `dev`, `test`, and `live`, so the finding is presently only
   `Partially Fixed` until deployment-time proxy configuration and authenticated
   request-context proof are rechecked.[^CLAIM-168]

### Re-Audit Remediation RAUD-09 Live Debug Metadata Guard (2026-03-10)

This dated addendum records re-audit remediation `RAUD-09` for findings `H3`
and `N-25`.

1. Response debug metadata is now guarded by a centralized
   `EnvironmentDetector` service that normalizes `PANTHEON_ENVIRONMENT` lookups
   across `AssistantApiController`, `AssistantSettingsForm`, `LlmEnhancer`, and
   `FallbackGate`.
2. `AssistantApiController::isDebugMode()` is now fail-closed: it returns
   `FALSE` when the runtime setting
   `ilas_site_assistant_debug_metadata_force_disable` is enabled or when the
   effective Pantheon environment is `live`, and only falls back to
   `ILAS_CHATBOT_DEBUG=1` outside live.
3. `settings.php` now sets
   `$settings['ilas_site_assistant_debug_metadata_force_disable'] = TRUE;`
   inside the Pantheon `live` branch, creating an authoritative runtime deny
   path in addition to the controller-level live guard.
4. Regression coverage now includes a dedicated `EnvironmentDetectorTest.php`
   suite plus controller-level assertions that `_debug` is emitted on non-live
   when explicitly enabled, but never on live or when the force-disable setting
   is present.
5. Local verification is captured in
   `docs/aila/runtime/raud-09-live-debug-guard.txt`. Pantheon read-only alias
   checks ran on March 10, 2026, but the targeted live-debug `php:eval` proof
   could not run against `dev`, `test`, or `live` because those environments
   are still serving pre-deploy code and do not yet expose the new
   `ilas_site_assistant.environment_detector` service. The repo-side fix is
   therefore implemented, but the live runtime surface remains `Unverified`
   until deployment-time rechecks confirm `effective_debug_mode=false` on
   `live`.[^CLAIM-169]

### Re-Audit Remediation RAUD-10 PII Redaction Coverage Expansion (2026-03-10)

This dated addendum records re-audit remediation `RAUD-10` for findings `H1`,
`PII-1`, `PII-2`, and `N-24`.

1. `PiiRedactor` now redacts Spanish self-identification, DOB, and address
   phrases (`me llamo`, `mi nombre es`, `fecha de nacimiento`, `nacido el`,
   `mi direccion`, `vivo en`) and consumes full international phone prefixes
   such as `+52-...` instead of leaving the country code behind.
2. Name coverage is now Unicode-aware and context-gated for role labels
   (`client`, `tenant`, `applicant`, `cliente`, `inquilino`, `solicitante`),
   while Idaho driver-license values shaped like `[A-Z]{2}\d{6}[A-Z]` are
   redacted only when paired with license context.
3. Regression coverage now includes expanded `PiiRedactor` unit fixtures,
   multilingual observability contract assertions, and kernel verification for
   analytics and conversation-log storage paths.
4. Local verification is captured in
   `docs/aila/runtime/raud-10-pii-redaction-remediation.txt`. The repo-side
   remediation improves multilingual and Idaho-specific coverage, but
   intentionally does not redact truly free-form bare names such as
   `John Smith needs help with eviction` because the deterministic false-
   positive risk remains too high; the finding therefore remains
   `Partially Fixed`.[^CLAIM-170]

### Re-Audit Remediation RAUD-11 Observability Payload Minimization (2026-03-10)

This dated addendum records re-audit remediation `RAUD-11` for findings
`F-03` and the unresolved observability portion of `N-03`.

1. Observability payload minimization is now centralized in
   `ObservabilityPayloadMinimizer`: user-derived text becomes a SHA-256
   fingerprint plus low-cardinality facets (`length_bucket`,
   `redaction_profile`, and `language_hint` where required) instead of raw or
   redacted snippets.
2. Persistence surfaces are metadata-only after `update_10007` runs:
   `ilas_site_assistant_no_answer` now stores `query_hash`,
   `language_hint`, `length_bucket`, and `redaction_profile`; opt-in
   conversation logging now stores `message_hash`,
   `message_length_bucket`, and `redaction_profile` per turn instead of any
   message body.
3. Telemetry and watchdog surfaces no longer emit free-text snippets:
   Langfuse generation input/output now uses hash/bucket payloads,
   controller/finder/vector logs use query hashes plus keyword counts, and
   exception telemetry uses `error_signature` instead of raw exception
   messages.
4. Local verification is captured in
   `docs/aila/runtime/raud-11-log-surface-minimization.txt`. Targeted unit and
   quality-gate runs passed on March 10, 2026, and targeted kernel verification
   passed when `SIMPLETEST_DB` was overridden to SQLite for this workspace's
   local test harness. The repo-side finding is therefore `Fixed`, with only
   deployment-time legacy backfill/purge work remaining.

### Re-Audit Remediation RAUD-12 Anonymous Session Bootstrap Guardrails (2026-03-10)

This dated addendum records re-audit remediation `RAUD-12` for finding
`NF-04`.

1. Anonymous bootstrap is now guarded by a dedicated
   `AssistantSessionBootstrapGuard` service that distinguishes `new_session`
   requests from `reuse` requests, keys new-session rate limits by the resolved
   client IP, and records a rolling state snapshot at
   `ilas_site_assistant.session_bootstrap.snapshot`.
2. `AssistantSessionBootstrapController::bootstrap()` now preserves the
   existing `GET` / `text/plain` request contract but only starts and marks the
   anonymous session when continuity is newly established; same-cookie reuse no
   longer churns session writes or rotates the anonymous session cookie.
3. The bootstrap thresholds are now explicit config under
   `ilas_site_assistant.settings:session_bootstrap`, and admin
   `/assistant/api/metrics` exposes both `metrics.session_bootstrap` counters
   and `thresholds.session_bootstrap` for operational review.
4. Widget bootstrap failures now preserve HTTP `status` and `Retry-After`, so
   a bootstrap-side `429` routes through the existing recovery copy instead of
   collapsing to a generic fetch error. Regression coverage now includes a
   dedicated guard unit suite, bootstrap functional assertions, config
   governance updates, and widget-hardening smoke proof.
5. Local verification is captured in
   `docs/aila/runtime/raud-12-anonymous-session-bootstrap.txt`. Local targeted
   unit, functional, widget, and full kernel verification passed on March 10,
   2026, but Pantheon read-only checks on the same date still returned
   `ilas_site_assistant.settings:session_bootstrap = null` and no
   `ilas_site_assistant.session_bootstrap.snapshot` state on `dev`, `test`, or
   `live`. The repo-side remediation is therefore implemented, but the deployed
   runtime surface remains `Unverified`, so the finding is presently only
   `Partially Fixed`.[^CLAIM-172]

### Re-Audit Remediation RAUD-13 Injected Logger Hardening (2026-03-10)

This dated addendum records re-audit remediation `RAUD-13` for findings `L1`
and `N-28`.

1. `AnalyticsLogger` and `ConversationLogger` no longer call
   `\Drupal::logger()` directly; both services now receive
   `@logger.channel.ilas_site_assistant` through constructor injection, keeping
   logging inside the module's service boundary.
2. The remediation preserves the pre-existing log contract: analytics
   stats/no-answer/cleanup failures still emit the same `@class` and
   `@error_signature` placeholders, conversation exchange/cleanup failures keep
   the same error payloads, and successful conversation cleanup still logs the
   deleted row count at `info`.
3. Regression coverage now includes `LoggerInjectionContractTest` for the
   injected error paths, updated constructor wiring in
   `IntegrationFailureContractTest`, and kernel verification for analytics and
   conversation persistence plus conversation cleanup info logging.
4. Local verification is captured in
   `docs/aila/runtime/raud-13-logger-di-hardening.txt`. Targeted unit,
   `VC-UNIT`, targeted kernel, and `VC-KERNEL` runs passed on March 10, 2026,
   so the repo-side finding is `Fixed` with no remaining schema or payload-
   contract work.[^CLAIM-173]

### Phase 1 Exit #1 Non-Live Alert + Dashboard Verification (2026-03-03)

This dated addendum records P1-EXT-01 completion for Phase 1 Exit criterion #1.

1. Critical alert and dashboard surfaces were verified in local and Pantheon non-live (dev/test) environments.
   Verification covered `/assistant/api/health`, `/assistant/api/metrics`, and `/admin/reports/ilas-assistant`,
   plus explicit `SloAlertService::checkAll()` invocation and watchdog evidence with `@slo_dimension` context.[^CLAIM-127]
2. Cron ordering now records run health before SLO alert evaluation, so alert checks evaluate same-run cron state.
   Alert-check failures are isolated and logged without failing cron execution.[^CLAIM-127]
3. Regression and gate coverage was added for cron ordering, dashboard route behavior, and artifact/doc continuity:
   `CronHookSloAlertOrderingTest.php`, expanded `AssistantApiFunctionalTest.php`,
   strengthened `SloAlertServiceTest.php`, and `PhaseOneExitCriteriaOneGateTest.php`.[^CLAIM-127]
4. Residual risk remains unchanged: B-04 (cron/queue throughput under load) remains unresolved pending sustained runtime observation.[^CLAIM-118][^CLAIM-121]

### Phase 1 Exit #2 Mandatory Gate Disposition (2026-03-03)

This dated addendum declares Phase 1 exit criterion #2 met: CI quality gate is
mandatory for merge/release path.

1. **GitHub Actions workflow** (`.github/workflows/quality-gate.yml`) covers
   `push` and `pull_request` for all blocking branches (`master`, `main`,
   `release/**`). Concurrency control (`cancel-in-progress: true`) prevents
   stale-run races on the same branch.[^CLAIM-122]
2. **Branch protection** on `master` requires both `PHPUnit Quality Gate` and
   `Promptfoo Gate` status checks to pass before merge. `strict: true` requires
   the branch to be up-to-date with the base. `enforce_admins: true` prevents
   admin bypass.
3. **Promptfoo branch policy** blocks threshold failures on `master`/`main`/
   `release/*` and reports advisory-only on other branches. When
   `ILAS_ASSISTANT_URL` is absent on blocking branches, the workflow fails
   explicitly rather than silently skipping.
4. **Contract tests** (`QualityGateEnforcementContractTest.php`) lock trigger
   coverage (`release/**` in `pull_request`), concurrency control, and
   documentation mandatory-gate declaration as enforced invariants.
5. **Repository visibility** changed to public to unlock GitHub branch
   protection features (free-tier requirement). No secrets in tracked files;
   credentials are injected at runtime via `_ilas_get_secret()`.

### Phase 1 Exit #3 Reliability Failure Matrix Verification (2026-03-03)

This dated addendum records P1-EXT-03 completion for Phase 1 Exit criterion #3.

1. Reliability failure matrix suites pass locally for retrieval dependency degrade,
   integration failure contract mapping, and LLM dependency-failure handling:
   `DependencyFailureDegradeContractTest.php`,
   `IntegrationFailureContractTest.php`, and
   `LlmEnhancerHardeningTest.php`.[^CLAIM-128]
2. Target-environment configuration assumptions required by the matrix are
   verified on Pantheon `dev`/`test`/`live`:
   `llm.enabled=false`, `llm.fallback_on_error=true`, and
   `vector_search.enabled=false`.[^CLAIM-128]
3. Runtime verification output is captured in
   `docs/aila/runtime/phase1-exit3-reliability-failure-matrix.txt`.[^CLAIM-128]
4. Scope boundaries remain unchanged: no live LLM rollout and no full retrieval
   architecture redesign.[^CLAIM-119][^CLAIM-060][^CLAIM-065]

### Phase 1 Sprint 2 Closure Addendum (2026-03-03)

This dated addendum records `P1-SBD-01` completion for Sprint 2 scope:
"Sentry/Langfuse bootstrap, log schema normalization, initial SLO drafts."

1. `TelemetrySchema::toLogContext()` is now the single helper for critical
   Drupal log context emission in `AssistantApiController::message()`, while
   preserving existing placeholder aliases so message strings remain stable.[^CLAIM-129]
2. Canonical telemetry keys are attached consistently to critical log contexts:
   `intent`, `safety_class`, `fallback_path`, `request_id`, `env`; legacy
   placeholders (`@request_id`, `@intent`, `@safety`, `@gate`) are retained for
   parser/alert compatibility.[^CLAIM-129]
3. Sentry/Langfuse bootstrap behavior remains staged/non-live and constrained:
   `llm.enabled=false` remains enforced on all environments through Phase 2 and
   no full retrieval-architecture redesign is introduced.[^CLAIM-119][^CLAIM-060][^CLAIM-065][^CLAIM-129]
4. SLO draft thresholds remain exposed via `/assistant/api/health` and `/assistant/api/metrics`
   and continue to drive `SloAlertService` evaluation behavior.[^CLAIM-051][^CLAIM-084][^CLAIM-121][^CLAIM-129]
5. Residual risk remains unchanged: B-04 (cron/queue throughput under load) remains unresolved
   pending sustained runtime observation.[^CLAIM-118][^CLAIM-121]

### Phase 1 Sprint 3 Closure Addendum (2026-03-03)

This dated addendum records `P1-SBD-02` completion for Sprint 3 scope:
"Alert policy finalization, CI gate rollout, reliability failure matrix completion."

1. Alert policy finalization is locked by non-live verification coverage for
   dashboard/controller surfaces (`/assistant/api/health`, `/assistant/api/metrics`,
   `/admin/reports/ilas-assistant`) and SLO warning emission context validation
   (`@slo_dimension`) captured in runtime evidence.[^CLAIM-127][^CLAIM-130]
2. CI gate rollout is complete and mandatory on merge/release path: first-party
   workflow triggers cover `master`/`main`/`release/**`, branch protection requires
   `PHPUnit Quality Gate` + `Promptfoo Gate` on `master`, and contract tests lock
   trigger/concurrency/documentation invariants.[^CLAIM-122][^CLAIM-130]
3. Reliability failure matrix completion remains verified for local matrix suites
   (`DependencyFailureDegradeContractTest.php`, `IntegrationFailureContractTest.php`,
   `LlmEnhancerHardeningTest.php`) plus Pantheon target-env setting assumptions,
   with consolidated runtime evidence retained.[^CLAIM-128][^CLAIM-130]
4. Scope boundaries remain unchanged: no live LLM rollout and no full retrieval
   architecture redesign.[^CLAIM-119][^CLAIM-060][^CLAIM-065]
5. Residual risk remains unchanged: B-04 (cron/queue throughput under load)
   remains unresolved pending sustained runtime observation.[^CLAIM-118][^CLAIM-121]

### Phase 2 Objective #2 Evaluation Coverage + Release Confidence Disposition (2026-03-03)

This dated addendum records `P2-OBJ-02` completion for Phase 2 Objective #2:
"Mature evaluation coverage and release confidence for RAG/response correctness."

1. Evaluation maturity is formalized as an enforceable contract across docs,
   runbook verification commands, and guard tests anchored to CLAIM-086
   (Promptfoo harness/gates) and CLAIM-105 (deterministic classifier coverage).[^CLAIM-086][^CLAIM-105][^CLAIM-132]
2. Release confidence remains branch-aware and reproducible: blocking branches
   (`master`/`main`/`release/*`) retain deep multi-turn Promptfoo coverage, while
   advisory branches retain abuse/safety regression coverage without merge-blocking
   semantics.[^CLAIM-086][^CLAIM-122][^CLAIM-132]
3. Deterministic correctness confidence remains enforced through `VC-UNIT` and
   `VC-DRUPAL-UNIT` suites, including SafetyClassifier and OutOfScopeClassifier
   coverage paths referenced by CLAIM-105.[^CLAIM-105][^CLAIM-132]
4. Intentional non-scope remains unchanged for this objective closure: no live
   production LLM enablement, no broad platform migration, and no runtime response
   contract or retrieval-behavior rewrite in this prompt scope.[^CLAIM-115][^CLAIM-119][^CLAIM-132]

### Phase 2 Entry #1 Observability + CI Baseline Operational Disposition (2026-03-04)

This dated addendum records `P2-ENT-01` completion for Phase 2 Entry criterion #1:
"Observability + CI baselines are operational from Phase 1."

1. Observability baseline continuity from Phase 1 remains operational through
   SLO-backed health/metrics contracts, queue-health telemetry, and alert-policy
   enforcement anchored to `CLAIM-084` without expanding production runtime scope.[^CLAIM-084][^CLAIM-138]
2. CI baseline continuity from Phase 1 remains operational through first-party
   workflow coverage (`.github/workflows/quality-gate.yml`), repo-owned gate
   scripts (`scripts/ci/run-promptfoo-gate.sh`,
   `scripts/ci/run-external-quality-gate.sh`), and branch-aware blocking policy
   continuity anchored to `CLAIM-122`.[^CLAIM-122][^CLAIM-138]
3. Prompt-level validation aliases for this entry criterion
   (`VC-RUNBOOK-LOCAL`, `VC-TOGGLE-CHECK`) are executed and captured in
   `docs/aila/runtime/phase2-entry1-observability-ci-baseline.txt` with
   sanitized command output and CI/diagram anchor checks.[^CLAIM-138]
4. Scope boundaries remain unchanged: no live production LLM enablement through
   Phase 2 and no broad platform migration outside the current Pantheon
   baseline.[^CLAIM-115][^CLAIM-119][^CLAIM-138]
5. Residual risk remains unchanged: B-04 (sustained cron/queue behavior under
   load) remains outside this entry-criterion closure and continues to require
   sustained runtime observation.[^CLAIM-118][^CLAIM-121][^CLAIM-138]

### Phase 2 Entry #2 Config Parity + Retrieval Tuning Stability Disposition (2026-03-04)

This dated addendum records `P2-ENT-02` completion for Phase 2 Entry criterion #2:
"Config parity and retrieval tuning controls are stable across environments."

1. Config parity enforcement is operational through `VectorSearchConfigSchemaTest`
   (4 tests) and `ConfigCompletenessDriftTest` (5 tests), providing three-way parity
   (install defaults / active config export / schema). Historical blocker B-02
   (config schema omitting `vector_search`) was resolved via CLAIM-124.[^CLAIM-095][^CLAIM-124][^CLAIM-139]
2. Retrieval tuning control stability is verified: `vector_search` block (7 keys) and
   `fallback_gate.thresholds` block (12 keys) are present in schema, install defaults,
   and active config export with matching values.[^CLAIM-096][^CLAIM-124][^CLAIM-139]
3. Prompt-level validation aliases for this entry criterion
   (`VC-RUNBOOK-LOCAL`, `VC-TOGGLE-CHECK`) are executed and captured in
   `docs/aila/runtime/phase2-entry2-config-parity-retrieval-tuning.txt` with
   sanitized command output and config parity anchor checks.[^CLAIM-139]
4. Scope boundaries remain unchanged: no live production LLM enablement through
   Phase 2 and no broad platform migration outside the current Pantheon
   baseline.[^CLAIM-115][^CLAIM-119][^CLAIM-139]
5. Residual risk remains unchanged: B-04 (sustained cron/queue behavior under
   load) remains outside this entry-criterion closure and continues to require
   sustained runtime observation.[^CLAIM-118][^CLAIM-121][^CLAIM-139]

### Phase 2 Exit #1 Retrieval Contract + Confidence Threshold Disposition (2026-03-04)

This dated addendum records `P2-EXT-01` completion for Phase 2 Exit criterion #1:
"Retrieval contract and confidence logic pass regression thresholds."

1. Retrieval contract and confidence threshold gating is now closure-anchored as
   a reproducible verification bundle using `VC-RUNBOOK-LOCAL`,
   `VC-RUNBOOK-PANTHEON`, and full promptfoo gate execution with metric fail-flag
   contract fields (`rag_contract_meta_fail`, `rag_citation_coverage_fail`,
   `rag_low_confidence_refusal_fail`) captured in runtime proof artifacts.[^CLAIM-062][^CLAIM-086][^CLAIM-140]
2. Promptfoo provider CSRF bootstrap now prefers
   `/assistant/api/session/bootstrap` (session-bound token + cookie continuity)
   with `/session/token` fallback, preserving existing `[contract_meta]` output
   shape and 403/429 retry behavior for regression harness compatibility.[^CLAIM-086][^CLAIM-140]
3. Runtime verification output is captured in
   `docs/aila/runtime/phase2-exit1-retrieval-contract-confidence-thresholds.txt`,
   including local/pantheon command excerpts and retrieval-threshold summary
   fields tied to gate policy anchors.[^CLAIM-140]
4. Scope boundaries remain unchanged: no live production LLM enablement through
   Phase 2 and no broad platform migration outside the current Pantheon
   baseline.[^CLAIM-115][^CLAIM-119][^CLAIM-140]
5. Residual risk posture remains unchanged: B-04 (sustained cron/queue behavior
   under load) continues to require longitudinal runtime observation outside this
   closure item.[^CLAIM-118][^CLAIM-121][^CLAIM-140]

### Phase 2 Exit #2 Citation Coverage + Low-Confidence Refusal Targets Disposition (2026-03-04)

This dated addendum records `P2-EXT-02` closure for Phase 2 exit criterion #2:
"Citation coverage and low-confidence refusal metrics are within approved targets."

1. Citation coverage and low-confidence refusal metrics meet the configured 90%
   per-metric threshold policy, enforced through `rag_citation_coverage_fail` and
   `rag_low_confidence_refusal_fail` gate summary fields in the promptfoo
   harness.[^CLAIM-065][^CLAIM-086][^CLAIM-141]
2. Enforcement mechanism verification confirms 10 `rag-citation-coverage` and
   10 `rag-low-confidence-refusal` scenarios anchored in
   `promptfoo-evals/tests/retrieval-confidence-thresholds.yaml`, with 90%
   threshold policy in `scripts/ci/run-promptfoo-gate.sh`.[^CLAIM-086][^CLAIM-141]
3. Runtime verification output is captured in
   `docs/aila/runtime/phase2-exit2-citation-coverage-refusal-targets.txt`,
   including `VC-RUNBOOK-LOCAL` and `VC-RUNBOOK-PANTHEON` continuity checks and
   scenario anchor verification.[^CLAIM-141]
4. Scope boundaries remain unchanged: no live production LLM enablement through
   Phase 2 and no broad platform migration outside the current Pantheon
   baseline.[^CLAIM-115][^CLAIM-119][^CLAIM-141]
5. Residual risk posture remains unchanged: B-04 (sustained cron/queue behavior
   under load) continues to require longitudinal runtime observation outside this
   closure item.[^CLAIM-118][^CLAIM-121][^CLAIM-141]

### Phase 2 Exit #3 Live LLM Disabled Pending Phase 3 Readiness Review Disposition (2026-03-04)

This dated addendum records `P2-EXT-03` closure for Phase 2 exit criterion #3:
"Live LLM remains disabled pending Phase 3 readiness review."

1. Live LLM disablement remains closure-anchored as a reproducible verification
   bundle using `VC-RUNBOOK-LOCAL` and `VC-RUNBOOK-PANTHEON`, with explicit
   `llm.enabled=false` continuity on local and Pantheon (`dev`/`test`/`live`)
   outputs captured in runtime proof artifacts.[^CLAIM-119][^CLAIM-142]
2. Runtime guardrails are now layered for defense-in-depth: live runtime override
   in `settings.php`, live enforcement in `AssistantSettingsForm`, and
   service-level effective-live guards in `LlmEnhancer` and `FallbackGate` to
   prevent live LLM activation from config drift paths.[^CLAIM-099][^CLAIM-119][^CLAIM-142]
3. Runtime verification output is captured in
   `docs/aila/runtime/phase2-exit3-live-llm-disabled-phase3-readiness.txt`,
   including alias command excerpts, guard-anchor checks, and targeted unit
   suite summaries tied to this closure item.[^CLAIM-142]
4. Scope boundaries remain unchanged: no live production LLM enablement through
   Phase 2 and no broad platform migration outside the current Pantheon
   baseline.[^CLAIM-115][^CLAIM-119][^CLAIM-142]
5. Residual risk posture remains unchanged: B-04 (sustained cron/queue behavior
   under load) continues to require longitudinal runtime observation outside this
   closure item.[^CLAIM-118][^CLAIM-121][^CLAIM-142]

### Phase 1 NDO #2 Boundary Enforcement Addendum (2026-03-03)

This dated addendum records `P1-NDO-02` closure for the Phase 1 scope boundary:
"No full redesign of retrieval architecture."

1. Boundary enforcement is now explicit and reproducible via a dedicated runbook
   verification subsection for `P1-NDO-02`, including roadmap/current-state/
   evidence/system-map/service-anchor continuity checks.[^CLAIM-131]
2. A dedicated guard test (`PhaseOneNoRetrievalArchitectureRedesignGuardTest.php`)
   locks Phase 1 NDO #2 text continuity, retrieval architecture shape language,
   retrieval claims (`CLAIM-060`, `CLAIM-065`), Diagram B retrieval anchors, and
   retrieval service anchors.[^CLAIM-060][^CLAIM-065][^CLAIM-131]
3. Retrieval architecture remains unchanged: Search API lexical retrieval with
   optional vector supplementation and legacy fallback paths remain the operative
   runtime pattern.[^CLAIM-060][^CLAIM-065]
4. Phase constraints remain unchanged: no live LLM rollout through Phase 2 and
   no full retrieval-architecture redesign in Phase 1.[^CLAIM-119][^CLAIM-131]

### Phase 2 NDO #1 No Live Production LLM Enablement Disposition (2026-03-04)

This dated addendum records `P2-NDO-01` closure for Phase 2 "What we will NOT
do #1": "No live production LLM enablement in this phase."

1. Live LLM disablement posture continuity is confirmed: `llm.enabled=false`
   remains enforced on all environments through Phase 2 with no change to the
   toggle matrix (§5) or runtime guard layering established in
   `P2-EXT-03`.[^CLAIM-119][^CLAIM-142][^CLAIM-145]
2. Defense-in-depth runtime guards remain layered: `settings.php` live
   hard-disable, `AssistantSettingsForm` form/validation/submit enforcement,
   `LlmEnhancer` and `FallbackGate` `isLiveEnvironment()` service
   checks.[^CLAIM-099][^CLAIM-119][^CLAIM-145]
3. Runtime verification output is captured in
   `docs/aila/runtime/phase2-ndo1-no-live-llm-production-enablement.txt`,
   including `VC-TOGGLE-CHECK` alias output and guard anchor verification
   markers.[^CLAIM-145]
4. Scope boundaries remain unchanged: no live production LLM enablement through
   Phase 2 and no broad platform migration outside the current Pantheon
   baseline.[^CLAIM-115][^CLAIM-119][^CLAIM-145]

### Phase 2 NDO #2 No Broad Platform Migration Disposition (2026-03-05)

This dated addendum records `P2-NDO-02` closure for Phase 2 "What we will NOT
do #2": "No broad platform migration outside current Pantheon baseline."

1. Pantheon baseline continuity is confirmed against current-state §1 and §5:
   environment/runtime posture and toggle matrix anchors remain unchanged for
   Phase 2, including continued `llm.enabled=false` posture on Pantheon
   `dev`/`test`/`live`.[^CLAIM-115][^CLAIM-119][^CLAIM-146]
2. Platform boundary enforcement is now explicit via a dedicated guard test
   (`PhaseTwoNoBroadPlatformMigrationGuardTest.php`) and runbook verification
   bundle covering Pantheon config anchors (`pantheon.yml`,
   `pantheon.upstream.yml`), settings include/override anchors in
   `web/sites/default/settings.php`, and Diagram A continuity checks.[^CLAIM-146]
3. Runtime verification output is captured in
   `docs/aila/runtime/phase2-ndo2-no-broad-platform-migration.txt`,
   including `VC-TOGGLE-CHECK` excerpts and platform-baseline anchor
   markers.[^CLAIM-146]
4. Scope boundaries remain unchanged: no live production LLM enablement through
   Phase 2 and no broad platform migration outside the current Pantheon
   baseline.[^CLAIM-115][^CLAIM-119][^CLAIM-146]

### Phase 3 Objective #2 Performance + Cost Guardrails Operational Disposition (2026-03-05)

This dated addendum records `P3-OBJ-02` closure for Phase 3 Objective #2:
"Finalize performance and cost guardrails with operational runbooks."

1. Performance and cost guardrails are now objective-level closure artifacts:
   LLM call guardrails remain enforced through circuit-breaker and global-rate-
   limiter integration (`CLAIM-077`), while SLO/performance monitoring guardrails
   remain enforced through `PerformanceMonitor` + `SloAlertService` (`CLAIM-084`)
   with no runtime architecture expansion.[^CLAIM-077][^CLAIM-084][^CLAIM-147]
2. `CostControlPolicy` service implements `IMP-COST-01` acceptance criteria:
   daily/monthly budget caps, LLM sampling gate, cache-hit-rate monitoring,
   cost estimation, and consolidated kill-switch evaluator. Integrated into
   `LlmEnhancer` as nullable dependency with full unit test coverage in
   `CostControlPolicyTest.php`.[^CLAIM-147]
3. Runbook section-3 verification for `P3-OBJ-02` now requires `VC-UNIT` and
   `VC-DRUPAL-UNIT` plus behavioral proof from `CostControlPolicyTest.php`,
   `PerformanceMonitorTest.php`, and `SloAlertServiceTest.php`, and captures
   sanitized runtime proof in
   `docs/aila/runtime/phase3-obj2-performance-cost-guardrails.txt`.[^CLAIM-147]
4. Cost governance linkage is promoted to active mitigation for
   backlog/risk artifacts (`IMP-COST-01`, `R-PERF-01`) with closure continuity
   retained as non-blocking docs continuity via
   `PhaseThreeObjectiveTwoGateTest.php`.[^CLAIM-147]
4. Scope boundaries remain unchanged: no net-new assistant channels or
   third-party model expansion beyond audited providers, and no platform-wide
   refactor of unrelated Drupal subsystems.[^CLAIM-010][^CLAIM-073][^CLAIM-074][^CLAIM-147]

### Phase 3 Objective #3 Release Readiness + Governance Attestation Disposition (2026-03-05)

This dated addendum records `P3-OBJ-03` closure for Phase 3 Objective #3:
"Deliver release readiness package and governance attestation."

1. Release readiness package closure is now codified through section-4
   verification continuity anchored to local runtime preflight evidence
   (`local-preflight.txt`, `CLAIM-108`) and Pantheon runtime verification
   evidence (`pantheon-dev`/`test`/`live`, `CLAIM-115`) with no runtime
   architecture expansion.[^CLAIM-108][^CLAIM-115][^CLAIM-148]
2. Runbook section-4 verification for `P3-OBJ-03` now requires `VC-UNIT` and
   `VC-DRUPAL-UNIT`, objective continuity checks across roadmap/current-state/
   evidence/backlog/risk markers, Diagram A anchor continuity validation, and
   captures sanitized runtime proof in
   `docs/aila/runtime/phase3-obj3-release-readiness-governance-attestation.txt`.[^CLAIM-148]
3. Governance attestation linkage is promoted to active mitigation for
   governance/compliance artifacts (`IMP-GOV-01` row, retention/access
   attestation workflow row, `R-GOV-01`) with behavioral runbook proof and
   non-blocking docs continuity via `PhaseThreeObjectiveThreeGateTest.php`.[^CLAIM-148]
4. Scope boundaries remain unchanged: no net-new assistant channels or
   third-party model expansion beyond audited providers, and no platform-wide
   refactor of unrelated Drupal subsystems.[^CLAIM-010][^CLAIM-073][^CLAIM-074][^CLAIM-148]

### Phase 3 Objective #1 Accessibility + Mobile UX Acceptance Disposition (2026-03-05)

This dated addendum records `P3-OBJ-01` closure for Phase 3 Objective #1:
"Complete accessibility and mobile UX hardening with explicit acceptance gates."

1. Accessibility and mobile UX acceptance gates are now objective-level closure
   artifacts: widget accessibility semantics remain enforced through dialog
   roles/labels, focus trap, and ARIA announcements (`CLAIM-025`), page Twig
   template ARIA labels and screen-reader text (`CLAIM-032`), API client
   timeout/error mapping (`CLAIM-026`), and mobile/reduced-motion SCSS contracts
   (`CLAIM-031`) with no runtime architecture expansion.[^CLAIM-025][^CLAIM-026][^CLAIM-031][^CLAIM-032][^CLAIM-149]
2. Runbook section-2 verification for `P3-OBJ-01` now requires `VC-UNIT` and
   `VC-DRUPAL-UNIT`, targeted `AccessibilityMobileUxAcceptanceGateTest`,
   `RecoveryUxContractTest`, and `assistant-widget-hardening.test.js` execution,
   source-anchor checks for accessibility/mobile UX claims, and captures
   sanitized runtime proof in
   `docs/aila/runtime/phase3-obj1-ux-a11y-mobile-acceptance.txt`.[^CLAIM-149]
3. Governance posture is updated to active mitigation for accessibility
   regression controls (`R-UX-01`) and mobile error-state quality controls
   (`R-UX-02`) with behavioral acceptance proof and non-blocking docs continuity via
   `PhaseThreeObjectiveOneGateTest.php`.[^CLAIM-149]
4. Scope boundaries remain unchanged: no net-new assistant channels or
   third-party model expansion beyond audited providers, and no platform-wide
   refactor of unrelated Drupal subsystems.[^CLAIM-010][^CLAIM-073][^CLAIM-074][^CLAIM-149]

### Phase 3 Entry #1 Retrieval Quality Targets Met + Documented Disposition (2026-03-05)

1. Phase 3 entry criterion #1 ("Phase 2 retrieval quality targets are met and
   documented") is closed. All Phase 2 retrieval quality deliverables have dated
   dispositions in the roadmap: Objective #2 (2026-03-03), Objective #3
   (2026-03-03), Deliverable #1 (2026-03-03), Deliverable #2 (2026-03-03),
   Deliverable #3 (2026-03-04), Deliverable #4 (2026-03-04), Exit #1
   (2026-03-04), Exit #2 (2026-03-04), Sprint 4 (2026-03-05), Sprint 5
   (2026-03-05).[^CLAIM-065][^CLAIM-086][^CLAIM-151]
2. Verification used `VC-RUNBOOK-LOCAL`, `VC-TOGGLE-CHECK`, and Phase 2
   retrieval closure continuity checks. Runtime proof is captured in
   `docs/aila/runtime/phase3-entry1-retrieval-quality-targets.txt`.[^CLAIM-151]
3. Retrieval pipeline anchors (Diagram B: Early retrieval, Fallback gate
   decision) and evidence anchors (CLAIM-065 resource retrieval, CLAIM-086
   promptfoo harness) remain present and unchanged.[^CLAIM-065][^CLAIM-086][^CLAIM-151]
4. Scope boundaries remain unchanged: no net-new assistant channels or
   third-party model expansion beyond audited providers, and no platform-wide
   refactor of unrelated Drupal subsystems.[^CLAIM-010][^CLAIM-073][^CLAIM-074][^CLAIM-151]

### Phase 3 Entry #2 SLO/Alert Trend History Disposition (2026-03-05)

1. Phase 3 entry criterion #2 ("SLO/alert operational data has at least one
   sprint of trend history") is closed using the locked sprint definition
   `10 business days` and explicit watchdog trend-window evidence from
   2026-02-20 through 2026-03-05 (14 calendar days / 10 business days). The
   closure is anchored to `CLAIM-084` (SLO/performance monitoring contracts)
   and `CLAIM-121` (cron watchdog interval continuity) without runtime behavior
   changes.[^CLAIM-084][^CLAIM-121][^CLAIM-152]
2. Verification used `VC-RUNBOOK-LOCAL`, `VC-TOGGLE-CHECK`, local trend-window
   SQL checks (min/max bounds, span hours/days, business-day window
   calculation, and SLO violation counts), plus continuity anchor checks against
   existing runtime evidence for `CLAIM-084` and `CLAIM-121`. Runtime proof is
   captured in `docs/aila/runtime/phase3-entry2-slo-alert-trend-history.txt`.[^CLAIM-152]
3. Scope boundaries remain unchanged: no net-new assistant channels or
   third-party model expansion beyond audited providers, and no platform-wide
   refactor of unrelated Drupal subsystems.[^CLAIM-010][^CLAIM-073][^CLAIM-074][^CLAIM-152]
4. Residual risk status remains unchanged: B-04 (sustained cron/queue load
   behavior under non-zero backlog) remains unresolved and outside this entry
   closure; no synthetic/backfilled operational data was introduced.[^CLAIM-118][^CLAIM-121][^CLAIM-152]

### Phase 3 Exit #1 UX/a11y Test Suite Gating + Passing Disposition (2026-03-06)

This dated addendum records `P3-EXT-01` closure for Phase 3 Exit criterion #1:
"UX/a11y test suite is gating and passing."

1. Exit criterion #1 is now enforced through required CI wiring: `.github/workflows/quality-gate.yml` `Promptfoo Gate` executes `web/modules/custom/ilas_site_assistant/tests/js/run-assistant-widget-hardening.mjs` before promptfoo gate evaluation, and continuity is locked by `QualityGateEnforcementContractTest.php`.[^CLAIM-025][^CLAIM-032][^CLAIM-105][^CLAIM-153]
2. The JS hardening suite remains coverage-preserving while using browser-correct safety assertions for `data-retry-message`: no DOM injection occurs, escaped serialization remains intact, and text payload round-trip behavior remains deterministic.[^CLAIM-025][^CLAIM-032][^CLAIM-153]
3. Verification for `P3-EXT-01` is codified in runbook section-4 via `VC-RUNBOOK-LOCAL`, `VC-RUNBOOK-PANTHEON`, targeted gate tests (`PhaseThreeExitCriteriaOneGateTest`, `QualityGateEnforcementContractTest`), direct JS runner execution, and CI anchor checks.[^CLAIM-153]
4. Sanitized runtime closure proof is captured in `docs/aila/runtime/phase3-exit1-ux-a11y-gating.txt` with explicit closure markers and scope-boundary continuity notes.[^CLAIM-153]
5. Scope boundaries remain unchanged: no net-new assistant channels or third-party model expansion beyond audited providers, and no platform-wide refactor of unrelated Drupal subsystems.[^CLAIM-010][^CLAIM-073][^CLAIM-074][^CLAIM-153]

### Phase 3 Exit #2 Cost/Performance Controls + Product/Platform Owner Acceptance Disposition (2026-03-06)

This dated addendum records `P3-EXT-02` closure for Phase 3 Exit criterion #2:
"Cost/performance controls are documented, monitored, and accepted by
product/platform owners."

1. Exit criterion #2 is closed as implemented: cost/performance controls are
   documented and monitored through section-3 operational guardrails anchored to
   existing `CLAIM-077` (LLM call guardrails) and `CLAIM-084` (SLO/performance
   monitoring contracts) continuity without runtime architecture expansion.[^CLAIM-077][^CLAIM-084][^CLAIM-154]
2. Verification for `P3-EXT-02` is codified in runbook section-3 via
   `VC-RUNBOOK-LOCAL`, `VC-RUNBOOK-PANTHEON`, dashboard monitoring checks for
   `/assistant/api/health` + `/assistant/api/metrics`, source-anchor checks, and
   targeted closure guard coverage in `PhaseThreeExitCriteriaTwoGateTest.php`.[^CLAIM-154]
3. Product/platform owner acceptance is recorded as role-based closure markers
   in runtime evidence (`owner-acceptance-product-role=accepted`,
   `owner-acceptance-platform-role=accepted`,
   `owner-acceptance-date=2026-03-06`) captured in
   `docs/aila/runtime/phase3-exit2-cost-performance-owner-acceptance.txt`.[^CLAIM-154]
4. Governance linkage remains active for `IMP-COST-01` and `R-PERF-01`, now
   carrying explicit `P3-EXT-02` owner-acceptance/runtime-marker continuity in
   backlog/risk artifacts with no change to risk posture.[^CLAIM-154]
5. Scope boundaries remain unchanged: no net-new assistant channels or
   third-party model expansion beyond audited providers, and no platform-wide
   refactor of unrelated Drupal subsystems. Residual `B-04` remains open and
   outside this closure item.[^CLAIM-010][^CLAIM-073][^CLAIM-074][^CLAIM-118][^CLAIM-121][^CLAIM-154]

### Phase 3 Exit #3 Final Release Packet Known-Unknown Disposition + Residual Risk Signoff Disposition (2026-03-06)

This dated addendum records `P3-EXT-03` closure for Phase 3 Exit criterion #3:
"Final release packet includes known-unknown disposition and residual risk signoff."

1. Exit criterion #3 is closed as implemented through a release-packet closure
   artifact that explicitly records known-unknown disposition continuity from
   current-state §8 and CI governance continuity from `CLAIM-122`, without
   runtime architecture expansion.[^CLAIM-122][^CLAIM-155]
2. Verification for `P3-EXT-03` is codified in runbook section-4 via
   `VC-RUNBOOK-LOCAL`, `VC-RUNBOOK-PANTHEON`, continuity checks for known unknowns
   and `R-REL-02`, and targeted closure guard coverage in
   `PhaseThreeExitCriteriaThreeGateTest.php`.[^CLAIM-155]
3. Known-unknown disposition is explicit in closure artifacts: Promptfoo CI
   ownership remains resolved, while long-run cron/queue load observation remains
   open and is carried as residual boundary `B-04` for sustained-load
   verification.[^CLAIM-118][^CLAIM-121][^CLAIM-122][^CLAIM-155]
4. Residual risk signoff is recorded as role-based release evidence in
   `docs/aila/runtime/phase3-exit3-release-packet-known-unknown-risk-signoff.txt`
   (`residual-risk-signoff-product-role=accepted`,
   `residual-risk-signoff-platform-role=accepted`,
   `residual-risk-signoff-date=2026-03-06`) while keeping residual risk open.[^CLAIM-155]
5. Scope boundaries remain unchanged: no net-new assistant channels or
   third-party model expansion beyond audited providers, and no platform-wide
   refactor of unrelated Drupal subsystems. Residual `B-04` remains open and
   outside runtime closure scope.[^CLAIM-010][^CLAIM-073][^CLAIM-074][^CLAIM-118][^CLAIM-121][^CLAIM-155]

### Phase 3 Sprint 6 Week 1 UX/a11y + Mobile Hardening Disposition (2026-03-06)

This dated addendum records `P3-SBD-01` completion for Phase 3 Sprint 6 Week 1 closure:
"Sprint 6 Week 1: UX/a11y and mobile hardening."

1. Sprint-level closure is completed as specified and anchored to existing
   objective/exit continuity: accessibility and mobile acceptance controls
   (`P3-OBJ-01`) and UX/a11y gating controls (`P3-EXT-01`) remain active with
   no runtime architecture expansion.[^CLAIM-149][^CLAIM-153][^CLAIM-156]
2. Verification for `P3-SBD-01` is codified in runbook section-4 using required
   validation aliases (`VC-UNIT`, `VC-QUALITY-GATE`) plus continuity anchors
   across acceptance/gating test artifacts (`AccessibilityMobileUxAcceptanceGateTest`,
   `RecoveryUxContractTest`, `assistant-widget-hardening.test.js`). Sanitized
   runtime proof is captured in
   `docs/aila/runtime/phase3-sprint6-week1-ux-a11y-mobile-hardening.txt`.[^CLAIM-105][^CLAIM-149][^CLAIM-153][^CLAIM-156]
3. Sprint closure continuity is enforceable via
   `PhaseThreeSprintSixWeekOneGateTest.php` across roadmap/current-state/
   runbook/evidence/runtime/system-map anchors for `P3-SBD-01`.[^CLAIM-156]
4. Scope boundaries remain unchanged: no net-new assistant channels or
   third-party model expansion beyond audited providers, and no platform-wide
   refactor of unrelated Drupal subsystems.[^CLAIM-010][^CLAIM-073][^CLAIM-074][^CLAIM-156]

### Phase 3 Sprint 6 Week 2 Performance/Cost Guardrails + Governance Signoff Disposition (2026-03-06)

This dated addendum records `P3-SBD-02` completion for Phase 3 Sprint 6 Week 2 closure:
"Sprint 6 Week 2: performance/cost guardrails and governance signoff."

1. Sprint-level closure is completed as specified and anchored to existing
   objective/exit continuity: performance/cost guardrails
   (`P3-OBJ-02`, `P3-EXT-02`) and governance signoff
   (`P3-OBJ-03`, `P3-EXT-03`) remain active with no runtime architecture
   expansion.[^CLAIM-147][^CLAIM-148][^CLAIM-154][^CLAIM-155][^CLAIM-157]
2. Verification for `P3-SBD-02` is codified in runbook sections 3/4 using
   required validation aliases (`VC-UNIT`, `VC-QUALITY-GATE`) plus continuity
   anchors across objective/exit guard tests
   (`PhaseThreeObjectiveTwoGateTest`, `PhaseThreeObjectiveThreeGateTest`,
   `PhaseThreeExitCriteriaTwoGateTest`, `PhaseThreeExitCriteriaThreeGateTest`).
   Sanitized runtime proof is captured in
   `docs/aila/runtime/phase3-sprint6-week2-performance-cost-governance-signoff.txt`.[^CLAIM-105][^CLAIM-147][^CLAIM-148][^CLAIM-154][^CLAIM-155][^CLAIM-157]
3. Sprint closure continuity is enforceable via
   `PhaseThreeSprintSixWeekTwoGateTest.php` across roadmap/current-state/
   runbook/evidence/runtime/system-map anchors for `P3-SBD-02`.[^CLAIM-157]
4. Scope boundaries remain unchanged: no net-new assistant channels or
   third-party model expansion beyond audited providers, and no platform-wide
   refactor of unrelated Drupal subsystems.[^CLAIM-010][^CLAIM-073][^CLAIM-074][^CLAIM-157]

### Phase 3 NDO #1 No Net-New Assistant Channels + No Third-Party Model Expansion Disposition (2026-03-06)

This dated addendum records `P3-NDO-01` closure for the Phase 3 scope boundary:
"No net-new assistant channels or third-party model expansion beyond audited providers."

1. Scope boundary closure is explicit and enforced: assistant channels remain
   the existing `/assistant` page mode + current `/assistant/api/*` surface, and
   provider wiring remains limited to audited Gemini/Vertex paths with no
   third-party model-provider expansion.[^CLAIM-073][^CLAIM-074][^CLAIM-158]
2. Verification for `P3-NDO-01` is codified in runbook section 3 using
   `VC-TOGGLE-CHECK` alias continuity plus explicit channel/provider anchor
   checks across route inventory, settings/provider allowlist anchors, and
   system-map Diagram A continuity.[^CLAIM-158]
3. Closure continuity is enforceable via
   `PhaseThreeNoNetNewAssistantChannelsOrModelExpansionGuardTest.php` across
   roadmap/current-state/evidence/runbook/runtime/source anchors for
   `P3-NDO-01`.[^CLAIM-158]
4. Sanitized runtime proof is captured in
   `docs/aila/runtime/phase3-ndo1-no-net-new-assistant-channels-or-third-party-model-expansion.txt`
   with deterministic closure and scope markers, including
   `p3-ndo-01-status=closed`,
   `no-net-new-assistant-channels=true`, and
   `no-third-party-model-expansion=true`.[^CLAIM-158]
5. Scope boundaries remain unchanged: no net-new assistant channels or
   third-party model expansion beyond audited providers, and no platform-wide
   refactor of unrelated Drupal subsystems.[^CLAIM-010][^CLAIM-073][^CLAIM-074][^CLAIM-158]

### Phase 3 NDO #2 No Platform-Wide Refactor of Unrelated Drupal Subsystems Disposition (2026-03-06)

This dated addendum records `P3-NDO-02` closure for the Phase 3 scope boundary:
"No platform-wide refactor of unrelated Drupal subsystems."

1. Scope boundary closure is explicit and enforced: implementation scope remains
   bounded to current `ilas_site_assistant` module architecture and documented
   Diagram A integration posture, with no platform-wide refactor across
   unrelated Drupal subsystems.[^CLAIM-010][^CLAIM-159]
2. Verification for `P3-NDO-02` is codified in runbook section 4 using
   `VC-TOGGLE-CHECK` alias continuity plus explicit module-scope anchors
   (`ilas_site_assistant.info.yml`), seam-service continuity anchors
   (`ilas_site_assistant.services.yml` + bounded service inventory), and
   system-map Diagram A continuity checks.[^CLAIM-159]
3. Closure continuity is enforceable via
   `PhaseThreeNoPlatformWideRefactorOfUnrelatedDrupalSubsystemsGuardTest.php`
   across roadmap/current-state/evidence/runbook/runtime/source anchors for
   `P3-NDO-02`.[^CLAIM-159]
4. Sanitized runtime proof is captured in
   `docs/aila/runtime/phase3-ndo2-no-platform-wide-refactor-of-unrelated-drupal-subsystems.txt`
   with deterministic closure and scope markers, including
   `p3-ndo-02-status=closed` and
   `no-platform-wide-refactor-of-unrelated-drupal-subsystems=true`.[^CLAIM-159]
5. Scope boundaries remain unchanged: no net-new assistant channels or
   third-party model expansion beyond audited providers, and no platform-wide
   refactor of unrelated Drupal subsystems.[^CLAIM-010][^CLAIM-073][^CLAIM-074][^CLAIM-159]

---

### Evidence footnotes

[^CLAIM-001]: [CLAIM-001](evidence-index.md#claim-001)
[^CLAIM-002]: [CLAIM-002](evidence-index.md#claim-002)
[^CLAIM-009]: [CLAIM-009](evidence-index.md#claim-009)
[^CLAIM-010]: [CLAIM-010](evidence-index.md#claim-010)
[^CLAIM-011]: [CLAIM-011](evidence-index.md#claim-011)
[^CLAIM-012]: [CLAIM-012](evidence-index.md#claim-012)
[^CLAIM-013]: [CLAIM-013](evidence-index.md#claim-013)
[^CLAIM-014]: [CLAIM-014](evidence-index.md#claim-014)
[^CLAIM-015]: [CLAIM-015](evidence-index.md#claim-015)
[^CLAIM-016]: [CLAIM-016](evidence-index.md#claim-016)
[^CLAIM-017]: [CLAIM-017](evidence-index.md#claim-017)
[^CLAIM-018]: [CLAIM-018](evidence-index.md#claim-018)
[^CLAIM-019]: [CLAIM-019](evidence-index.md#claim-019)
[^CLAIM-020]: [CLAIM-020](evidence-index.md#claim-020)
[^CLAIM-021]: [CLAIM-021](evidence-index.md#claim-021)
[^CLAIM-022]: [CLAIM-022](evidence-index.md#claim-022)
[^CLAIM-023]: [CLAIM-023](evidence-index.md#claim-023)
[^CLAIM-024]: [CLAIM-024](evidence-index.md#claim-024)
[^CLAIM-025]: [CLAIM-025](evidence-index.md#claim-025)
[^CLAIM-026]: [CLAIM-026](evidence-index.md#claim-026)
[^CLAIM-027]: [CLAIM-027](evidence-index.md#claim-027)
[^CLAIM-029]: [CLAIM-029](evidence-index.md#claim-029)
[^CLAIM-030]: [CLAIM-030](evidence-index.md#claim-030)
[^CLAIM-031]: [CLAIM-031](evidence-index.md#claim-031)
[^CLAIM-032]: [CLAIM-032](evidence-index.md#claim-032)
[^CLAIM-033]: [CLAIM-033](evidence-index.md#claim-033)
[^CLAIM-034]: [CLAIM-034](evidence-index.md#claim-034)
[^CLAIM-036]: [CLAIM-036](evidence-index.md#claim-036)
[^CLAIM-037]: [CLAIM-037](evidence-index.md#claim-037)
[^CLAIM-038]: [CLAIM-038](evidence-index.md#claim-038)
[^CLAIM-039]: [CLAIM-039](evidence-index.md#claim-039)
[^CLAIM-040]: [CLAIM-040](evidence-index.md#claim-040)
[^CLAIM-042]: [CLAIM-042](evidence-index.md#claim-042)
[^CLAIM-043]: [CLAIM-043](evidence-index.md#claim-043)
[^CLAIM-045]: [CLAIM-045](evidence-index.md#claim-045)
[^CLAIM-046]: [CLAIM-046](evidence-index.md#claim-046)
[^CLAIM-047]: [CLAIM-047](evidence-index.md#claim-047)
[^CLAIM-048]: [CLAIM-048](evidence-index.md#claim-048)
[^CLAIM-049]: [CLAIM-049](evidence-index.md#claim-049)
[^CLAIM-050]: [CLAIM-050](evidence-index.md#claim-050)
[^CLAIM-051]: [CLAIM-051](evidence-index.md#claim-051)
[^CLAIM-053]: [CLAIM-053](evidence-index.md#claim-053)
[^CLAIM-054]: [CLAIM-054](evidence-index.md#claim-054)
[^CLAIM-055]: [CLAIM-055](evidence-index.md#claim-055)
[^CLAIM-056]: [CLAIM-056](evidence-index.md#claim-056)
[^CLAIM-057]: [CLAIM-057](evidence-index.md#claim-057)
[^CLAIM-058]: [CLAIM-058](evidence-index.md#claim-058)
[^CLAIM-059]: [CLAIM-059](evidence-index.md#claim-059)
[^CLAIM-060]: [CLAIM-060](evidence-index.md#claim-060)
[^CLAIM-061]: [CLAIM-061](evidence-index.md#claim-061)
[^CLAIM-062]: [CLAIM-062](evidence-index.md#claim-062)
[^CLAIM-063]: [CLAIM-063](evidence-index.md#claim-063)
[^CLAIM-064]: [CLAIM-064](evidence-index.md#claim-064)
[^CLAIM-065]: [CLAIM-065](evidence-index.md#claim-065)
[^CLAIM-066]: [CLAIM-066](evidence-index.md#claim-066)
[^CLAIM-067]: [CLAIM-067](evidence-index.md#claim-067)
[^CLAIM-069]: [CLAIM-069](evidence-index.md#claim-069)
[^CLAIM-070]: [CLAIM-070](evidence-index.md#claim-070)
[^CLAIM-072]: [CLAIM-072](evidence-index.md#claim-072)
[^CLAIM-073]: [CLAIM-073](evidence-index.md#claim-073)
[^CLAIM-074]: [CLAIM-074](evidence-index.md#claim-074)
[^CLAIM-075]: [CLAIM-075](evidence-index.md#claim-075)
[^CLAIM-076]: [CLAIM-076](evidence-index.md#claim-076)
[^CLAIM-077]: [CLAIM-077](evidence-index.md#claim-077)
[^CLAIM-078]: [CLAIM-078](evidence-index.md#claim-078)
[^CLAIM-079]: [CLAIM-079](evidence-index.md#claim-079)
[^CLAIM-080]: [CLAIM-080](evidence-index.md#claim-080)
[^CLAIM-081]: [CLAIM-081](evidence-index.md#claim-081)
[^CLAIM-082]: [CLAIM-082](evidence-index.md#claim-082)
[^CLAIM-083]: [CLAIM-083](evidence-index.md#claim-083)
[^CLAIM-084]: [CLAIM-084](evidence-index.md#claim-084)
[^CLAIM-085]: [CLAIM-085](evidence-index.md#claim-085)
[^CLAIM-086]: [CLAIM-086](evidence-index.md#claim-086)
[^CLAIM-087]: [CLAIM-087](evidence-index.md#claim-087)
[^CLAIM-088]: [CLAIM-088](evidence-index.md#claim-088)
[^CLAIM-089]: [CLAIM-089](evidence-index.md#claim-089)
[^CLAIM-090]: [CLAIM-090](evidence-index.md#claim-090)
[^CLAIM-091]: [CLAIM-091](evidence-index.md#claim-091)
[^CLAIM-092]: [CLAIM-092](evidence-index.md#claim-092)
[^CLAIM-093]: [CLAIM-093](evidence-index.md#claim-093)
[^CLAIM-094]: [CLAIM-094](evidence-index.md#claim-094)
[^CLAIM-095]: [CLAIM-095](evidence-index.md#claim-095)
[^CLAIM-096]: [CLAIM-096](evidence-index.md#claim-096)
[^CLAIM-097]: [CLAIM-097](evidence-index.md#claim-097)
[^CLAIM-098]: [CLAIM-098](evidence-index.md#claim-098)
[^CLAIM-099]: [CLAIM-099](evidence-index.md#claim-099)
[^CLAIM-100]: [CLAIM-100](evidence-index.md#claim-100)
[^CLAIM-101]: [CLAIM-101](evidence-index.md#claim-101)
[^CLAIM-102]: [CLAIM-102](evidence-index.md#claim-102)
[^CLAIM-103]: [CLAIM-103](evidence-index.md#claim-103)
[^CLAIM-105]: [CLAIM-105](evidence-index.md#claim-105)
[^CLAIM-106]: [CLAIM-106](evidence-index.md#claim-106)
[^CLAIM-107]: [CLAIM-107](evidence-index.md#claim-107)
[^CLAIM-108]: [CLAIM-108](evidence-index.md#claim-108)
[^CLAIM-109]: [CLAIM-109](evidence-index.md#claim-109)
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
[^CLAIM-123]: [CLAIM-123](evidence-index.md#claim-123)
[^CLAIM-124]: [CLAIM-124](evidence-index.md#claim-124)
[^CLAIM-125]: [CLAIM-125](evidence-index.md#claim-125)
[^CLAIM-126]: [CLAIM-126](evidence-index.md#claim-126)
[^CLAIM-127]: [CLAIM-127](evidence-index.md#claim-127)
[^CLAIM-128]: [CLAIM-128](evidence-index.md#claim-128)
[^CLAIM-129]: [CLAIM-129](evidence-index.md#claim-129)
[^CLAIM-130]: [CLAIM-130](evidence-index.md#claim-130)
[^CLAIM-131]: [CLAIM-131](evidence-index.md#claim-131)
[^CLAIM-132]: [CLAIM-132](evidence-index.md#claim-132)
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
[^CLAIM-150]: [CLAIM-150](evidence-index.md#claim-150)
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
[^CLAIM-167]: [CLAIM-167](evidence-index.md#claim-167)
[^CLAIM-168]: [CLAIM-168](evidence-index.md#claim-168)
[^CLAIM-170]: [CLAIM-170](evidence-index.md#claim-170)
[^CLAIM-172]: [CLAIM-172](evidence-index.md#claim-172)
[^CLAIM-173]: [CLAIM-173](evidence-index.md#claim-173)
