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
| Git commit | `eb57c238bee95544b6752c4fa98b94cf6dbfc00a` | [^CLAIM-001] |
| Worktree note | `docs/aila/` was uncommitted at capture | [^CLAIM-002] |
| Local runtime status | Verified in DDEV: stack started, Drupal bootstrap succeeded, drush/runtime endpoint checks captured | [^CLAIM-108][^CLAIM-109][^CLAIM-111][^CLAIM-112] |
| Environment context used | Local = code/config + runtime verification; Pantheon = direct Terminus `remote:drush` verification on dev/test/live + code/config | [^CLAIM-109][^CLAIM-115][^CLAIM-116][^CLAIM-117] |
| Audit generation method | `rg`/file inspection + sanitized runtime artifacts under `docs/aila/runtime/` and `docs/aila/artifacts/`; no secret values captured | [^CLAIM-001][^CLAIM-108][^CLAIM-115][^CLAIM-122] |

### Enablement summary (local export vs Pantheon behavior)

| Capability | Local/config-export view | Pantheon view | Notes |
|---|---|---|---|
| Global widget attachment | Enabled (`enable_global_widget: true`) | Verified enabled in dev/test/live active config | Attach is conditional and path-excluded | [^CLAIM-015][^CLAIM-119] |
| `/assistant/api/message` and `/assistant/api/track` CSRF protection | Route definitions require `_csrf_request_header_token` + `_ilas_strict_csrf_token` (dual enforcement via `StrictCsrfRequestHeaderAccessCheck`) | Route definitions match on Pantheon; local anonymous synthetic POSTs now return 403 for missing, valid-but-sessionless, and invalid tokens | CSRF enforcement verified: sessionless requests are rejected; browser-context widget uses session-bound token pair from page render | [^CLAIM-012][^CLAIM-123] |
| LLM enhancement | Disabled in exported active config (`llm.enabled: false`) | Verified disabled in dev/test/live active config (`llm.enabled: false`); live runtime override hard-disables `llm.enabled` in `settings.php` | Provider wiring supports Gemini/Vertex | [^CLAIM-069][^CLAIM-094][^CLAIM-099][^CLAIM-119] |
| Vector retrieval supplement | Present in install defaults, disabled by default | Inference: environment dependent active config | Admin form persists values; schema mapping gap exists | [^CLAIM-093][^CLAIM-095][^CLAIM-096] |
| Langfuse tracing/export | Present but disabled by default | `langfuse.settings` config not present in dev/test/live; `ilas_langfuse_export` queue exists with `0` items in sampled runtime | Queue-based export on terminate/cron when enabled/configured | [^CLAIM-079][^CLAIM-082][^CLAIM-118][^CLAIM-120] |
| Sentry integration | Conditional on secret injection | `raven.settings` config not present in dev/test/live sampled runtime | PII send disabled and payload redaction subscriber active when integration is configured | [^CLAIM-083][^CLAIM-120] |
| GA4 tag and live rate-limit override | Not applied outside `live` env branch | Applied in `PANTHEON_ENVIRONMENT=live` branch | Sets `google_tag_id` and per-IP limits | [^CLAIM-099] |
| Promptfoo harness | Repo-local npm scripts + provider | Pantheon target can be used via URL env var | Enforced external CI gate scripts are in-repo (`scripts/ci/run-external-quality-gate.sh`, `scripts/ci/run-promptfoo-gate.sh`); no first-party workflow file is the canonical gate path in this repository | [^CLAIM-086][^CLAIM-122] |

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
| `ilas_site_assistant.analytics_logger` | `Drupal\ilas_site_assistant\Service\AnalyticsLogger` | Writes analytics and no-answer records | `'@database', '@config.factory', '@datetime.time'` | [^CLAIM-020] |
| `ilas_site_assistant.policy_filter` | `Drupal\ilas_site_assistant\Service\PolicyFilter` | Fallback safety/policy checks | `'@config.factory'` | [^CLAIM-020] |
| `ilas_site_assistant.llm_circuit_breaker` | `Drupal\ilas_site_assistant\Service\LlmCircuitBreaker` | Circuit breaker around LLM calls | `'@state', '@config.factory', '@logger.channel.ilas_site_assistant'` | [^CLAIM-020] |
| `ilas_site_assistant.llm_rate_limiter` | `Drupal\ilas_site_assistant\Service\LlmRateLimiter` | Global LLM call limiter | `'@state', '@config.factory', '@logger.channel.ilas_site_assistant'` | [^CLAIM-020] |
| `ilas_site_assistant.llm_enhancer` | `Drupal\ilas_site_assistant\Service\LlmEnhancer` | Gemini/Vertex enhancement orchestration | `'@config.factory', '@http_client', '@logger.factory', '@ilas_site_assistant.policy_filter', '@cache.ilas_site_assistant', '@ilas_site_assistant.llm_circuit_breaker', '@ilas_site_assistant.llm_rate_limiter'` | [^CLAIM-020] |
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
| `ilas_site_assistant.conversation_logger` | `Drupal\ilas_site_assistant\Service\ConversationLogger` | Stores redacted conversation exchanges | `'@database', '@config.factory', '@datetime.time'` | [^CLAIM-020] |
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
| `ilas_site_assistant.api.track` | `/assistant/api/track` | `POST` | `access content`; CSRF header required | `\Drupal\ilas_site_assistant\Controller\AssistantApiController::track` | Analytics tracking endpoint | [^CLAIM-012] |

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

### C) Safety & compliance layers

| Spec item | Current state |
|---|---|
| Legal-advice posture | UI + config contain explicit "cannot provide legal advice" disclaimers and escalation/refusal templates.[^CLAIM-058][^CLAIM-039] |
| PII/secret redaction | Deterministic redaction service covers common PII patterns with truncation/storage helpers; conversation view is redacted-only.[^CLAIM-053][^CLAIM-059] |
| Deterministic classifier logic | Priority contract is explicit and enforced: Safety -> OOS -> Policy fallback -> intent routing; rules are pattern-based with first-match behavior.[^CLAIM-038][^CLAIM-054][^CLAIM-056][^CLAIM-057] |
| Classifier test artifacts | Unit tests exist for SafetyClassifier and OutOfScopeClassifier behavior suites (file-level evidence only; not executed in this audit run).[^CLAIM-105] |
| Refusal/escalation behavior | Safety and OOS classes return templated early exits with reason codes and action links.[^CLAIM-039][^CLAIM-040] |
| Rate limiting/abuse controls | Per-IP Flood API minute/hour checks plus repeated-message abuse short-circuit behavior.[^CLAIM-033][^CLAIM-037] |
| CSRF protections | Message + track endpoints require `_csrf_request_header_token`.[^CLAIM-012] |
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
| Toggles/flags | Vector supplement behavior is gated by config (`vector_search.*`), with admin form persistence and schema gap noted.[^CLAIM-061][^CLAIM-095][^CLAIM-096] |
| Failure modes | Vector and Search API failures degrade gracefully to empty/legacy paths; FAQ has explicit legacy entity-query fallback.[^CLAIM-063][^CLAIM-065] |
| Deterministic degrade outcomes (formalized) | Search API unavailable or query exceptions deterministically route to legacy retrieval in FAQ/resource paths. Vector index unavailable/failing paths preserve lexical/legacy output and do not propagate unhandled dependency exceptions upstream.[^CLAIM-063][^CLAIM-065] |
| Observability | Retrieval warnings/info are logged; quality/empty-search conditions flow into analytics/no-answer capture paths.[^CLAIM-085][^CLAIM-047] |

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
| Runtime monitoring | `PerformanceMonitor` feeds `/assistant/api/health` and `/assistant/api/metrics` status/threshold outputs.[^CLAIM-084][^CLAIM-051] |
| Promptfoo + quality gate harness | Existing test assets are now enforced via repo scripts: `tests/run-quality-gate.sh` (unit + deterministic classifier Drupal-unit + golden transcript) and external runner gates (`scripts/ci/run-external-quality-gate.sh`, `scripts/ci/run-promptfoo-gate.sh`) with branch-aware blocking for `main`/`release/*` and advisory behavior elsewhere. First-party workflow ownership remains external to this repo snapshot.[^CLAIM-086][^CLAIM-105][^CLAIM-122] |
| Redaction posture | Sentry subscriber and analytics/conversation log codepaths apply redaction/truncation before persistence/export.[^CLAIM-053][^CLAIM-083][^CLAIM-085] |

### G) Cron/queues/background processes

| Spec item | Current state |
|---|---|
| Cron entrypoint | `hook_cron()` runs analytics cleanup, conversation cleanup, violation prune, and safety alert checks.[^CLAIM-016] |
| Queue workers | `ilas_langfuse_export` queue worker is cron-enabled (`cron.time=30`) for Langfuse export batches.[^CLAIM-082] |
| Langfuse queue behavior | Items are aged/validated, discarded when stale/disabled/misconfigured, and transient API failures suspend queue for retry.[^CLAIM-082] |
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
| Key/secrets management | `_ilas_get_secret()` reads Pantheon runtime secrets or env vars; runtime config overrides apply for LLM, Langfuse, Pinecone, Sentry, AI keys.[^CLAIM-097][^CLAIM-098] |
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
| LLM credentials | `ILAS_GEMINI_API_KEY`, `ILAS_VERTEX_SA_JSON`, `llm.project_id` | empty | API key/project/service account empty in export | Runtime secret override path in settings.php | Values redacted intentionally | [^CLAIM-069][^CLAIM-098] |
| LLM generation params | `llm.max_tokens`, `llm.temperature` + hard-coded `topP=0.8`, `topK=40` | `150`, `0.3` | `150`, `0.3` | Same code path | Safety threshold key also applied | [^CLAIM-072][^CLAIM-093][^CLAIM-094] |
| LLM retries/timeout | `llm.max_retries`; code timeout `10s` | `2` | `2` (synced) | Matches install default | Retryable HTTP codes include 429/5xx | [^CLAIM-075][^CLAIM-093][^CLAIM-094][^CLAIM-124] |
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

- Route-level CSRF header enforcement exists for message and tracking POST endpoints.[^CLAIM-012]
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
- Known config-model gap: `vector_search` settings are saved in form but not modeled in schema export mapping.[^CLAIM-095][^CLAIM-096]

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
| Promptfoo CI ownership outside this repository | No first-party CI workflow file exists in repository-root CI locations in this snapshot. Enforced repo scripts now define gate behavior, but merge/release authority still depends on an external CI owner/source-of-truth. | External CI system definition/source-of-truth (if managed outside repo) | [^CLAIM-122] |

### Phase 0 Exit #3 Dependency Disposition (2026-02-27)

This dated addendum preserves the historical baseline above and records the
P0-EXT-03 dependency-gate disposition for Phase 1 planning:

1. CLAIM-120 dependency is unblocked via readiness gates (runbook commands +
   redaction/queue contract tests); telemetry remains disabled until Phase 1
   credential provisioning and destination approvals.
2. CLAIM-122 dependency is unblocked via Pantheon/local gate ownership using
   runbook verification commands plus repo-scripted external CI runner
   promptfoo targeting (`scripts/ci/*`).

### Phase 1 Objective #3 Quality Gate Disposition (2026-02-27)

This dated addendum formalizes conversion of existing test assets into
enforced quality gates while preserving the external CI ownership known
unknown.

1. `tests/run-quality-gate.sh` is the mandatory module-level gate and now
   enforces unit coverage, deterministic classifier Drupal-unit coverage
   (Safety + OutOfScope), and golden transcript replay checks.
2. `scripts/ci/run-promptfoo-gate.sh` enforces branch-aware Promptfoo
   threshold policy (`main`/`release/*` blocking; other branches advisory).
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
