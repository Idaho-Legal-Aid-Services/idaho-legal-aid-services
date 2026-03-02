# Aila Evidence Index

This index maps claim IDs used in `docs/aila/current-state.md` to concrete evidence.

Evidence precedence used in this audit:
1. Runtime command output (when available)
2. Source/config file inspection
3. Explicit inference (labeled)

---

## Audit Context + Runtime Verification

### CLAIM-001
- Claim: Audit metadata (UTC timestamp, branch, commit hash) for this run.
- Evidence:
  - `docs/aila/artifacts/context-latest.txt:1-4`

### CLAIM-002
- Claim: Repository state at capture time includes uncommitted `docs/aila/` worktree changes.
- Evidence:
  - `docs/aila/artifacts/context-latest.txt:5-6`

### CLAIM-003
- Claim: Local DDEV runtime could not be started because Docker provider was unavailable.
- Evidence:
  - `docs/aila/artifacts/ddev-status.txt:1-3`
  - `docs/aila/artifacts/ddev-start.txt:1-3`

### CLAIM-004
- Claim: DDEV Drush/bootstrap checks failed because Docker network/socket access was unavailable.
- Evidence:
  - `docs/aila/artifacts/drush-runtime-checks.txt:1-12`

### CLAIM-005
- Claim: Local HTTP runtime checks for assistant endpoints could not be executed (no listener on 127.0.0.1:80).
- Evidence:
  - `docs/aila/artifacts/curl-runtime-checks.txt:1-5`

### CLAIM-006
- Claim: Vendor Drush is installed locally at version 13.7.0.
- Evidence:
  - `docs/aila/artifacts/drush-version.txt:1-3`

### CLAIM-007
- Claim: DDEV local stack targets Drupal with PHP 8.3 / MariaDB 10.11 / nginx-fpm.
- Evidence:
  - `.ddev/config.yaml:1-9`

### CLAIM-008
- Claim: Pantheon upstream config targets PHP 8.3 / MariaDB 10.6 and defines protected private/config web paths.
- Evidence:
  - `pantheon.upstream.yml:1-14`

### CLAIM-009
- Claim: Pantheon service container YAML switches between preproduction and production files by environment.
- Evidence:
  - `web/sites/default/settings.pantheon.php:32-42`

---

## Module, Routes, Libraries, Services

### CLAIM-010
- Claim: Core Aila implementation is the custom module `ilas_site_assistant` with Search API + Paragraph dependencies.
- Evidence:
  - `web/modules/custom/ilas_site_assistant/ilas_site_assistant.info.yml:1-12`

### CLAIM-011
- Claim: Aila route inventory includes `/assistant` page, API endpoints, and admin report/settings routes.
- Evidence:
  - `web/modules/custom/ilas_site_assistant/ilas_site_assistant.routing.yml:1-90`
  - `docs/aila/artifacts/routes-inventory.tsv:1-12`

### CLAIM-012
- Claim: `/assistant/api/message` and `/assistant/api/track` are POST routes with dual CSRF enforcement (`_csrf_request_header_token` + `_ilas_strict_csrf_token`) via `StrictCsrfRequestHeaderAccessCheck`.
- Evidence:
  - `web/modules/custom/ilas_site_assistant/ilas_site_assistant.routing.yml:9-17` (message route with `_ilas_strict_csrf_token: 'TRUE'`)
  - `web/modules/custom/ilas_site_assistant/ilas_site_assistant.routing.yml:84-92` (track route with `_ilas_strict_csrf_token: 'TRUE'`)
  - `web/modules/custom/ilas_site_assistant/src/Access/StrictCsrfRequestHeaderAccessCheck.php:1-103` (access check implementation)
  - `web/modules/custom/ilas_site_assistant/ilas_site_assistant.services.yml:2-6` (access_check service registration)

### CLAIM-013
- Claim: Aila custom permissions include restricted admin/report/conversation governance access that supports role-based security/compliance ownership workflows.
- Evidence:
  - `web/modules/custom/ilas_site_assistant/ilas_site_assistant.permissions.yml:1-13`

### CLAIM-014
- Claim: Module libraries `widget` and `page` ship JS only; CSS is explicitly moved to theme SCSS.
- Evidence:
  - `web/modules/custom/ilas_site_assistant/ilas_site_assistant.libraries.yml:1-21`

### CLAIM-015
- Claim: Global widget attach is conditional (`enable_global_widget`, excluded paths) and injects `drupalSettings.ilasSiteAssistant` with API base, CSRF token, disclaimer, feature toggles, canonical URLs.
- Evidence:
  - `web/modules/custom/ilas_site_assistant/ilas_site_assistant.module:56-86`

### CLAIM-016
- Claim: Cron hook invokes analytics cleanup, conversation cleanup, safety violation pruning, and safety alert checks.
- Evidence:
  - `web/modules/custom/ilas_site_assistant/ilas_site_assistant.module:129-146`

### CLAIM-017
- Claim: Module exposes mail key `safety_alert`.
- Evidence:
  - `web/modules/custom/ilas_site_assistant/ilas_site_assistant.module:152-159`

### CLAIM-018
- Claim: Drush command class `KbImportCommands` is registered as `drush.command`.
- Evidence:
  - `web/modules/custom/ilas_site_assistant/drush.services.yml:1-6`

### CLAIM-019
- Claim: Drush commands include `ilas:kb-import` and `ilas:kb-list`.
- Evidence:
  - `web/modules/custom/ilas_site_assistant/src/Commands/KbImportCommands.php:38-47`
  - `web/modules/custom/ilas_site_assistant/src/Commands/KbImportCommands.php:144-147`

### CLAIM-020
- Claim: Service container definitions and dependencies are declared for cache, routing/classification, retrieval, LLM, safety, observability, and subscribers.
- Evidence:
  - `web/modules/custom/ilas_site_assistant/ilas_site_assistant.services.yml:1-139`
  - `docs/aila/artifacts/services-inventory.tsv:1-36`

---

## UI / Widget / Theme

### CLAIM-021
- Claim: Dedicated `/assistant` page is denied when global widget is disabled.
- Evidence:
  - `web/modules/custom/ilas_site_assistant/src/Controller/AssistantPageController.php:50-53`

### CLAIM-022
- Claim: `/assistant` page attaches Aila page library and `drupalSettings` with `pageMode: TRUE`.
- Evidence:
  - `web/modules/custom/ilas_site_assistant/src/Controller/AssistantPageController.php:99-113`

### CLAIM-023
- Claim: Widget JS creates client-side UUIDv4 `conversationId` and stores it in tab-local JS state.
- Evidence:
  - `web/modules/custom/ilas_site_assistant/js/assistant-widget.js:203-215`
  - `web/modules/custom/ilas_site_assistant/js/assistant-widget.js:221-225`

### CLAIM-024
- Claim: Widget POST payload includes `message`, `conversation_id`, and context history/quickAction.
- Evidence:
  - `web/modules/custom/ilas_site_assistant/js/assistant-widget.js:584-593`
  - `web/modules/custom/ilas_site_assistant/js/assistant-widget.js:688-694`

### CLAIM-025
- Claim: Widget enforces accessibility semantics: dialog roles/labels, Escape-to-close, focus trap, typing status ARIA.
- Evidence:
  - `web/modules/custom/ilas_site_assistant/js/assistant-widget.js:300-315`
  - `web/modules/custom/ilas_site_assistant/js/assistant-widget.js:385-390`
  - `web/modules/custom/ilas_site_assistant/js/assistant-widget.js:500-543`
  - `web/modules/custom/ilas_site_assistant/js/assistant-widget.js:1234-1249`

### CLAIM-026
- Claim: Widget API client sends CSRF header, uses 15s AbortController timeout, maps 429/403/5xx error behaviors.
- Evidence:
  - `web/modules/custom/ilas_site_assistant/js/assistant-widget.js:1285-1341`
  - `web/modules/custom/ilas_site_assistant/js/assistant-widget.js:1350-1393`

### CLAIM-027
- Claim: Widget tracking pushes GA-style events to `dataLayer` and separately POSTs to `/assistant/api/track`.
- Evidence:
  - `web/modules/custom/ilas_site_assistant/js/assistant-widget.js:1420-1442`

### CLAIM-028
- Claim: URL and HTML/attribute sanitization is implemented in widget JS (`escapeHtml`, `escapeAttr`, `sanitizeUrl`).
- Evidence:
  - `web/modules/custom/ilas_site_assistant/js/assistant-widget.js:1461-1521`

### CLAIM-029
- Claim: Drupal behavior bootstrap initializes from `drupalSettings.ilasSiteAssistant` with `once()`.
- Evidence:
  - `web/modules/custom/ilas_site_assistant/js/assistant-widget.js:1527-1534`

### CLAIM-030
- Claim: Assistant styling is theme-owned (`style.scss` imports `_assistant-widget.scss`), not module library CSS.
- Evidence:
  - `web/themes/custom/b5subtheme/scss/style.scss:164-165`
  - `web/modules/custom/ilas_site_assistant/ilas_site_assistant.libraries.yml:3-6`

### CLAIM-031
- Claim: Assistant theme SCSS includes mobile layout rules, reduced-motion handling, and high-contrast adjustments.
- Evidence:
  - `web/themes/custom/b5subtheme/scss/_assistant-widget.scss:152-175`
  - `web/themes/custom/b5subtheme/scss/_assistant-widget.scss:1086-1236`
  - `web/themes/custom/b5subtheme/scss/_assistant-widget.scss:1241-1259`
  - `web/themes/custom/b5subtheme/scss/_assistant-widget.scss:1264-1287`

### CLAIM-032
- Claim: Assistant page Twig template includes ARIA labels, screen-reader text, and quick-action buttons.
- Evidence:
  - `web/modules/custom/ilas_site_assistant/templates/assistant-page.html.twig:14-79`

---

## Message Pipeline + Runtime Behavior

### CLAIM-033
- Claim: `/assistant/api/message` applies per-IP flood rate limits (minute/hour) and returns 429 with `Retry-After`.
- Evidence:
  - `web/modules/custom/ilas_site_assistant/src/Controller/AssistantApiController.php:345-367`
  - `web/modules/custom/ilas_site_assistant/src/Controller/AssistantApiController.php:352-365`

### CLAIM-034
- Claim: Message endpoint validates content type, request size (2000), JSON parse, and required `message`.
- Evidence:
  - `web/modules/custom/ilas_site_assistant/src/Controller/AssistantApiController.php:369-384`

### CLAIM-035
- Claim: Correlation IDs are accepted/generate UUID4 and returned as `X-Correlation-ID`.
- Evidence:
  - `web/modules/custom/ilas_site_assistant/src/Controller/AssistantApiController.php:303-307`
  - `web/modules/custom/ilas_site_assistant/src/Controller/AssistantApiController.php:323-330`

### CLAIM-036
- Claim: Conversation state cache key is `ilas_conv:<conversation_id>` and IDs must match UUID4 pattern.
- Evidence:
  - `web/modules/custom/ilas_site_assistant/src/Controller/AssistantApiController.php:426-435`

### CLAIM-037
- Claim: Repeated identical-message abuse detection short-circuits with escalation response.
- Evidence:
  - `web/modules/custom/ilas_site_assistant/src/Controller/AssistantApiController.php:437-449`

### CLAIM-038
- Claim: Classifier precedence contract is Safety -> OutOfScope -> PolicyFilter -> Intent routing.
- Evidence:
  - `web/modules/custom/ilas_site_assistant/src/Controller/AssistantApiController.php:470-482`

### CLAIM-039
- Claim: Safety classifier exits early with templated escalation/refusal response, reason code logging, and violation tracking.
- Evidence:
  - `web/modules/custom/ilas_site_assistant/src/Controller/AssistantApiController.php:484-559`

### CLAIM-040
- Claim: Out-of-scope classifier exits early with templated OOS response and logging.
- Evidence:
  - `web/modules/custom/ilas_site_assistant/src/Controller/AssistantApiController.php:569-636`

### CLAIM-041
- Claim: PolicyFilter remains as fallback enforcement layer if prior classifiers pass.
- Evidence:
  - `web/modules/custom/ilas_site_assistant/src/Controller/AssistantApiController.php:645-695`

### CLAIM-042
- Claim: Turn classification, quick-action short-circuit, and history fallback are part of intent routing.
- Evidence:
  - `web/modules/custom/ilas_site_assistant/src/Controller/AssistantApiController.php:722-879`

### CLAIM-043
- Claim: Early retrieval runs before gate evaluation; gate can force clarify or LLM fallback path.
- Evidence:
  - `web/modules/custom/ilas_site_assistant/src/Controller/AssistantApiController.php:880-947`

### CLAIM-044
- Claim: Intent processing applies hard-route URL enforcement and optional response grounding.
- Evidence:
  - `web/modules/custom/ilas_site_assistant/src/Controller/AssistantApiController.php:949-970`

### CLAIM-045
- Claim: Response enhancement includes LLM generation call then post-generation safety enforcement.
- Evidence:
  - `web/modules/custom/ilas_site_assistant/src/Controller/AssistantApiController.php:989-1021`

### CLAIM-046
- Claim: Conversation history cache keeps last 10 entries with 30-minute TTL; follow-up slot cache uses 30-minute TTL.
- Evidence:
  - `web/modules/custom/ilas_site_assistant/src/Controller/AssistantApiController.php:1050-1057`
  - `web/modules/custom/ilas_site_assistant/src/Controller/AssistantApiController.php:1074-1093`

### CLAIM-047
- Claim: Opt-in conversation logging stores request-linked exchanges when enabled.
- Evidence:
  - `web/modules/custom/ilas_site_assistant/src/Controller/AssistantApiController.php:1110-1120`
  - `web/modules/custom/ilas_site_assistant/src/Service/ConversationLogger.php:58-62`

### CLAIM-048
- Claim: Exceptions are logged, tagged to Sentry (if available), traced in Langfuse, and returned as `500 internal_error`.
- Evidence:
  - `web/modules/custom/ilas_site_assistant/src/Controller/AssistantApiController.php:1172-1217`

- Addendum (2026-02-27): deterministic dependency degrade behavior is formalized
  for controller-level failures as a controlled `500 internal_error` class,
  with verification steps and guard coverage tied to Phase 1 Objective #2.
- Addendum evidence:
  - `docs/aila/current-state.md` (Section 4B deterministic dependency degrade contract row)
  - `docs/aila/runbook.md` (Deterministic dependency degrade contract verification subsection in §2)
  - `web/modules/custom/ilas_site_assistant/tests/src/Unit/DependencyFailureDegradeContractTest.php`
  - `web/modules/custom/ilas_site_assistant/tests/src/Unit/PhaseOneDeterministicDegradeContractTest.php`

### CLAIM-049
- Claim: `/assistant/api/track` validates JSON payload, allows only known event types, and returns `{ok: true}`.
- Evidence:
  - `web/modules/custom/ilas_site_assistant/src/Controller/AssistantApiController.php:1231-1267`

### CLAIM-050
- Claim: `/assistant/api/suggest` and `/assistant/api/faq` are cacheable JSON responses with query-arg cache contexts.
- Evidence:
  - `web/modules/custom/ilas_site_assistant/src/Controller/AssistantApiController.php:1484-1528`
  - `web/modules/custom/ilas_site_assistant/src/Controller/AssistantApiController.php:1539-1575`

### CLAIM-051
- Claim: `/assistant/api/health` and `/assistant/api/metrics` expose monitor status and thresholds.
- Evidence:
  - `web/modules/custom/ilas_site_assistant/src/Controller/AssistantApiController.php:2780-2825`

---

## Safety / Compliance / Privacy Controls

### CLAIM-052
- Claim: Input normalization strips punctuation/spacing obfuscation before classification.
- Evidence:
  - `web/modules/custom/ilas_site_assistant/src/Service/InputNormalizer.php:35-42`
  - `web/modules/custom/ilas_site_assistant/src/Service/InputNormalizer.php:88-101`
  - `web/modules/custom/ilas_site_assistant/src/Service/InputNormalizer.php:123-134`

### CLAIM-053
- Claim: PII redaction uses deterministic token replacement for email/phone/SSN/CC/address/name/etc. and storage/log truncation helpers.
- Evidence:
  - `web/modules/custom/ilas_site_assistant/src/Service/PiiRedactor.php:21-30`
  - `web/modules/custom/ilas_site_assistant/src/Service/PiiRedactor.php:45-143`
  - `web/modules/custom/ilas_site_assistant/src/Service/PiiRedactor.php:157-178`

### CLAIM-054
- Claim: SafetyClassifier defines ordered deterministic rules and first-match return behavior with escalation/refusal flags.
- Evidence:
  - `web/modules/custom/ilas_site_assistant/src/Service/SafetyClassifier.php:12-27`
  - `web/modules/custom/ilas_site_assistant/src/Service/SafetyClassifier.php:90-447`
  - `web/modules/custom/ilas_site_assistant/src/Service/SafetyClassifier.php:466-507`

### CLAIM-055
- Claim: SafetyClassifier includes explicit prompt-injection/jailbreak pattern classes.
- Evidence:
  - `web/modules/custom/ilas_site_assistant/src/Service/SafetyClassifier.php:221-267`
  - `web/modules/custom/ilas_site_assistant/src/Service/SafetyClassifier.php:594-601`

### CLAIM-056
- Claim: OutOfScopeClassifier is deterministic with category-specific pattern rules and dampening for informational queries.
- Evidence:
  - `web/modules/custom/ilas_site_assistant/src/Service/OutOfScopeClassifier.php:12-35`
  - `web/modules/custom/ilas_site_assistant/src/Service/OutOfScopeClassifier.php:73-295`
  - `web/modules/custom/ilas_site_assistant/src/Service/OutOfScopeClassifier.php:312-350`

### CLAIM-057
- Claim: PolicyFilter still provides emergency/PII/criminal/legal-advice/document/external/frustration checks.
- Evidence:
  - `web/modules/custom/ilas_site_assistant/src/Service/PolicyFilter.php:31-170`
  - `web/modules/custom/ilas_site_assistant/src/Service/PolicyFilter.php:233-412`

### CLAIM-058
- Claim: User-facing legal-advice disclaimers are present in module settings and UI templates.
- Evidence:
  - `web/modules/custom/ilas_site_assistant/ilas_site_assistant.module:81-85`
  - `web/modules/custom/ilas_site_assistant/templates/assistant-page.html.twig:24-29`
  - `config/ilas_site_assistant.settings.yml:3`

### CLAIM-059
- Claim: Conversation admin report displays only pre-redacted stored messages and requires restricted permission.
- Evidence:
  - `web/modules/custom/ilas_site_assistant/src/Controller/AssistantConversationController.php:13-17`
  - `web/modules/custom/ilas_site_assistant/src/Controller/AssistantConversationController.php:198-248`
  - `web/modules/custom/ilas_site_assistant/ilas_site_assistant.permissions.yml:10-13`

---

## Retrieval / Search / Pinecone

### CLAIM-060
- Claim: FAQ retrieval uses Search API index `faq_accordion` with language filter and lexical result build path.
- Evidence:
  - `web/modules/custom/ilas_site_assistant/src/Service/FaqIndex.php:100`
  - `web/modules/custom/ilas_site_assistant/src/Service/FaqIndex.php:183-214`

### CLAIM-061
- Claim: FAQ vector supplement is controlled by `vector_search` config and only runs for sparse/low-quality lexical results.
- Evidence:
  - `web/modules/custom/ilas_site_assistant/src/Service/FaqIndex.php:581-584`
  - `web/modules/custom/ilas_site_assistant/src/Service/FaqIndex.php:644-698`

### CLAIM-062
- Claim: FAQ vector path validates cosine metric, enforces min score, normalizes scores, and applies timeout/backoff handling.
- Evidence:
  - `web/modules/custom/ilas_site_assistant/src/Service/FaqIndex.php:599-623`
  - `web/modules/custom/ilas_site_assistant/src/Service/FaqIndex.php:719-723`
  - `web/modules/custom/ilas_site_assistant/src/Service/FaqIndex.php:748-758`
  - `web/modules/custom/ilas_site_assistant/src/Service/FaqIndex.php:813-823`

### CLAIM-063
- Claim: FAQ retrieval falls back to legacy entity-query search if Search API is unavailable/failing.
- Evidence:
  - `web/modules/custom/ilas_site_assistant/src/Service/FaqIndex.php:177-180`
  - `web/modules/custom/ilas_site_assistant/src/Service/FaqIndex.php:827-935`

- Addendum (2026-02-27): deterministic FAQ dependency degrade contract is now
  explicitly locked with unit coverage for Search API unavailable/query-failure
  paths and vector-unavailable preservation semantics.
- Addendum evidence:
  - `docs/aila/current-state.md` (Section 4D deterministic degrade outcomes row)
  - `docs/aila/runbook.md` (Deterministic dependency degrade contract verification subsection in §2)
  - `web/modules/custom/ilas_site_assistant/tests/src/Unit/DependencyFailureDegradeContractTest.php`
  - `web/modules/custom/ilas_site_assistant/tests/src/Unit/PhaseOneDeterministicDegradeContractTest.php`

### CLAIM-064
- Claim: Resource retrieval prefers dedicated index `assistant_resources` and falls back to `content` index.
- Evidence:
  - `web/modules/custom/ilas_site_assistant/src/Service/ResourceFinder.php:102-107`
  - `web/modules/custom/ilas_site_assistant/src/Service/ResourceFinder.php:147-157`

### CLAIM-065
- Claim: Resource retrieval applies lexical query filters, topic boost, vector supplement, and fallback legacy retrieval.
- Evidence:
  - `web/modules/custom/ilas_site_assistant/src/Service/ResourceFinder.php:454-555`
  - `web/modules/custom/ilas_site_assistant/src/Service/ResourceFinder.php:656-662`
  - `web/modules/custom/ilas_site_assistant/src/Service/ResourceFinder.php:722-774`
  - `web/modules/custom/ilas_site_assistant/src/Service/ResourceFinder.php:789-911`
  - `web/modules/custom/ilas_site_assistant/src/Service/ResourceFinder.php:914-1009`

- Addendum (2026-02-27): deterministic resource dependency degrade contract is
  formalized for Search API unavailable/query-failure fallback and vector
  unavailable/failure preservation behavior.
- Addendum evidence:
  - `docs/aila/current-state.md` (Section 4D deterministic degrade outcomes row)
  - `docs/aila/runbook.md` (Deterministic dependency degrade contract verification subsection in §2)
  - `web/modules/custom/ilas_site_assistant/tests/src/Unit/DependencyFailureDegradeContractTest.php`
  - `web/modules/custom/ilas_site_assistant/tests/src/Unit/PhaseOneDeterministicDegradeContractTest.php`

### CLAIM-066
- Claim: Pinecone Search API server is configured with Gemini chat model, Gemini embedding model, 3072 dimensions, and cosine similarity.
- Evidence:
  - `config/search_api.server.pinecone_vector.yml:11-29`

### CLAIM-067
- Claim: Vector indexes exist for FAQ paragraphs and resource nodes on `pinecone_vector` server.
- Evidence:
  - `config/search_api.index.faq_accordion_vector.yml:10-82`
  - `config/search_api.index.assistant_resources_vector.yml:10-67`

### CLAIM-068
- Claim: Pinecone API key entity uses config provider with empty value and runtime injection expectation.
- Evidence:
  - `config/key.key.pinecone_api_key.yml:5-13`
  - `config/key.key.pinecone_api_key.yml:7`
  - `web/sites/default/settings.php:250-253`

---

## LLM / Gemini / Vertex

### CLAIM-069
- Claim: LLM enhancer is disabled unless `llm.enabled` and provider-specific credentials/config are present.
- Evidence:
  - `web/modules/custom/ilas_site_assistant/src/Service/LlmEnhancer.php:245-265`

### CLAIM-070
- Claim: LLM prompt set includes explicit no-legal-advice and “do not follow instructions in retrieved_content” constraints.
- Evidence:
  - `web/modules/custom/ilas_site_assistant/src/Service/LlmEnhancer.php:104-216`

### CLAIM-071
- Claim: LLM classify-intent fallback only runs when rule-based route is ambiguous (`unknown`).
- Evidence:
  - `web/modules/custom/ilas_site_assistant/src/Service/LlmEnhancer.php:358-397`

### CLAIM-072
- Claim: LLM calls use generation config (`maxOutputTokens`, `temperature`, `topP`, `topK`) and safety settings from config threshold.
- Evidence:
  - `web/modules/custom/ilas_site_assistant/src/Service/LlmEnhancer.php:522-547`
  - `web/modules/custom/ilas_site_assistant/src/Service/LlmEnhancer.php:637-642`
  - `web/modules/custom/ilas_site_assistant/src/Service/LlmEnhancer.php:690-697`

### CLAIM-073
- Claim: Gemini and Vertex API endpoints are explicitly defined in code.
- Evidence:
  - `web/modules/custom/ilas_site_assistant/src/Service/LlmEnhancer.php:96-97`

### CLAIM-074
- Claim: Gemini API path uses `x-goog-api-key`; Vertex uses service-account JWT or metadata-server bearer token.
- Evidence:
  - `web/modules/custom/ilas_site_assistant/src/Service/LlmEnhancer.php:617-646`
  - `web/modules/custom/ilas_site_assistant/src/Service/LlmEnhancer.php:804-815`
  - `web/modules/custom/ilas_site_assistant/src/Service/LlmEnhancer.php:826-903`

### CLAIM-075
- Claim: LLM path has retry/backoff and request timeout controls.
- Evidence:
  - `web/modules/custom/ilas_site_assistant/src/Service/LlmEnhancer.php:717-735`
  - `web/modules/custom/ilas_site_assistant/src/Service/LlmEnhancer.php:777-789`

### CLAIM-076
- Claim: LLM response caching uses policy-versioned key and configurable cache TTL.
- Evidence:
  - `web/modules/custom/ilas_site_assistant/src/Service/LlmEnhancer.php:554-571`
  - `web/modules/custom/ilas_site_assistant/src/Service/LlmEnhancer.php:91`

### CLAIM-077
- Claim: LLM calls are gated by circuit breaker and global rate limiter services.
- Evidence:
  - `web/modules/custom/ilas_site_assistant/src/Service/LlmEnhancer.php:573-581`
  - `web/modules/custom/ilas_site_assistant/src/Service/LlmEnhancer.php:590-595`
  - `web/modules/custom/ilas_site_assistant/src/Service/LlmCircuitBreaker.php:69-92`
  - `web/modules/custom/ilas_site_assistant/src/Service/LlmRateLimiter.php:76-103`

### CLAIM-078
- Claim: Post-generation legal-advice detection blocks unsafe generated output.
- Evidence:
  - `web/modules/custom/ilas_site_assistant/src/Service/LlmEnhancer.php:924-967`

---

## Observability / Monitoring / Promptfoo

### CLAIM-079
- Claim: Langfuse enablement requires config flag + credentials and applies sampling.
- Evidence:
  - `web/modules/custom/ilas_site_assistant/src/Service/LangfuseTracer.php:116-155`
- Addendum (2026-02-27): IMP-OBS-01 adds `TelemetrySchema::normalize()` to all 5 controller `endTrace()` exit points, ensuring consistent field names across Langfuse metadata. Acceptance tests in `web/modules/custom/ilas_site_assistant/tests/src/Unit/ImpObs01AcceptanceTest.php` prove full lifecycle event-type coverage and install config policy-cap lock (sample_rate=1.0 install, 0.10 live).

### CLAIM-080
- Claim: Langfuse traces include spans, generations, events, and serialized batch payloads.
- Evidence:
  - `web/modules/custom/ilas_site_assistant/src/Service/LangfuseTracer.php:223-287`
  - `web/modules/custom/ilas_site_assistant/src/Service/LangfuseTracer.php:305-367`
  - `web/modules/custom/ilas_site_assistant/src/Service/LangfuseTracer.php:386-409`
  - `web/modules/custom/ilas_site_assistant/src/Service/LangfuseTracer.php:473-485`

### CLAIM-081
- Claim: Langfuse queue enqueue happens in `kernel.terminate` with max queue depth guard.
- Evidence:
  - `web/modules/custom/ilas_site_assistant/src/EventSubscriber/LangfuseTerminateSubscriber.php:75-121`

### CLAIM-082
- Claim: Langfuse queue worker `ilas_langfuse_export` runs on cron, enforces max item age, and retries transient failures by suspending queue.
- Evidence:
  - `web/modules/custom/ilas_site_assistant/src/Plugin/QueueWorker/LangfuseExportWorker.php:17-21`
  - `web/modules/custom/ilas_site_assistant/src/Plugin/QueueWorker/LangfuseExportWorker.php:87-113`
  - `web/modules/custom/ilas_site_assistant/src/Plugin/QueueWorker/LangfuseExportWorker.php:167-188`

### CLAIM-083
- Claim: Sentry/Raven options subscriber forces `send_default_pii=false` and redacts message/exception/extra fields before send.
- Evidence:
  - `web/modules/custom/ilas_site_assistant/src/EventSubscriber/SentryOptionsSubscriber.php:24-33`
  - `web/modules/custom/ilas_site_assistant/src/EventSubscriber/SentryOptionsSubscriber.php:42-60`
  - `web/modules/custom/ilas_site_assistant/src/EventSubscriber/SentryOptionsSubscriber.php:71-116`
- Addendum (2026-02-27): IMP-OBS-01 adds `TelemetrySchema::REQUIRED_FIELDS` tag promotion in `before_send` callback (extra→tags), Sentry `configureScope` now uses `TelemetrySchema` constant names for `request_id`/`intent`/`safety_class`/`fallback_path`/`env`. Acceptance tests in `web/modules/custom/ilas_site_assistant/tests/src/Unit/ImpObs01AcceptanceTest.php` and contract tests in `web/modules/custom/ilas_site_assistant/tests/src/Unit/TelemetrySchemaContractTest.php` verify PII redaction across all fields and tag enrichment.

### CLAIM-084
- Claim: Performance monitor stores rolling request metrics in state and marks degraded status by p95/error-rate thresholds.
- Evidence:
  - `web/modules/custom/ilas_site_assistant/src/Service/PerformanceMonitor.php:19-31`
  - `web/modules/custom/ilas_site_assistant/src/Service/PerformanceMonitor.php:117-176`
  - `web/modules/custom/ilas_site_assistant/src/Service/PerformanceMonitor.php:185-225`

### CLAIM-085
- Claim: Analytics logger redacts event values and stores no-answer queries as redacted/truncated hash records.
- Evidence:
  - `web/modules/custom/ilas_site_assistant/src/Service/AnalyticsLogger.php:56-66`
  - `web/modules/custom/ilas_site_assistant/src/Service/AnalyticsLogger.php:104-152`

### CLAIM-086
- Claim: Promptfoo harness is wired via npm scripts and custom provider that fetches Drupal CSRF token and handles 403/429 retries.
- Evidence:
  - `package.json:5-13`
  - `promptfoo-evals/promptfooconfig.yaml:1-22`
  - `promptfoo-evals/providers/ilas-live.js:4-17`
  - `promptfoo-evals/providers/ilas-live.js:133-153`
  - `promptfoo-evals/providers/ilas-live.js:195-208`
  - `promptfoo-evals/providers/ilas-live.js:226-257`

- Addendum (2026-02-27): existing Promptfoo test assets are now part of an
  enforced quality-gate contract via repo-owned gate scripts and runbook §4
  verification commands.
- Addendum evidence:
  - `docs/aila/runbook.md` (Enforced quality gate verification subsection in §4)
  - `scripts/ci/run-promptfoo-gate.sh`
  - `scripts/ci/run-external-quality-gate.sh`
  - `web/modules/custom/ilas_site_assistant/tests/src/Unit/PhaseOneQualityGateContractTest.php`

---

## Cron / Queues / Data Model / Retention

### CLAIM-087
- Claim: Analytics retention cleanup is configured by `log_retention_days` and runs batched deletes.
- Evidence:
  - `web/modules/custom/ilas_site_assistant/src/Service/AnalyticsLogger.php:160-220`

### CLAIM-088
- Claim: Conversation logs use retention window in hours and batched cleanup.
- Evidence:
  - `web/modules/custom/ilas_site_assistant/src/Service/ConversationLogger.php:156-208`

### CLAIM-089
- Claim: Safety violation tracker stores timestamps in state ring buffer and supports prune.
- Evidence:
  - `web/modules/custom/ilas_site_assistant/src/Service/SafetyViolationTracker.php:20-25`
  - `web/modules/custom/ilas_site_assistant/src/Service/SafetyViolationTracker.php:50-60`
  - `web/modules/custom/ilas_site_assistant/src/Service/SafetyViolationTracker.php:90-94`

### CLAIM-090
- Claim: Safety alert service checks threshold/cooldown and sends non-PII email alerts from cron.
- Evidence:
  - `web/modules/custom/ilas_site_assistant/src/Service/SafetyAlertService.php:101-164`
  - `web/modules/custom/ilas_site_assistant/src/Service/SafetyAlertService.php:234-257`

### CLAIM-091
- Claim: Module schema defines custom tables `ilas_site_assistant_stats`, `ilas_site_assistant_no_answer`, `ilas_site_assistant_conversations`.
- Evidence:
  - `web/modules/custom/ilas_site_assistant/ilas_site_assistant.install:15-57`
  - `web/modules/custom/ilas_site_assistant/ilas_site_assistant.install:60-107`
  - `web/modules/custom/ilas_site_assistant/ilas_site_assistant.install:110-169`

### CLAIM-092
- Claim: Update hook adds `request_id` field and `intent_created` index on conversations table.
- Evidence:
  - `web/modules/custom/ilas_site_assistant/ilas_site_assistant.install:239-285`

---

## Config, Toggles, Secrets, Dependencies

### CLAIM-093
- Claim: Install default config includes extended feature maps (LLM, fallback_gate, vector_search, safety_alerting, history_fallback, ab_testing, langfuse).
- Evidence:
  - `web/modules/custom/ilas_site_assistant/config/install/ilas_site_assistant.settings.yml:58-198`

### CLAIM-094
- Claim: Exported active config in `config/ilas_site_assistant.settings.yml` currently includes only a subset of install defaults.
- Evidence:
  - `config/ilas_site_assistant.settings.yml:1-79`

### CLAIM-095
- Claim: Config schema defines llm/fallback/safety/history/ab/langfuse mappings but no `vector_search` mapping.
- Evidence:
  - `web/modules/custom/ilas_site_assistant/config/schema/ilas_site_assistant.schema.yml:1-357`
  - Inference basis: no `vector_search:` mapping key present in the schema file.

### CLAIM-096
- Claim: Admin settings form exposes and persists `vector_search` config values despite missing schema mapping.
- Evidence:
  - `web/modules/custom/ilas_site_assistant/src/Form/AssistantSettingsForm.php:258-340`
  - `web/modules/custom/ilas_site_assistant/src/Form/AssistantSettingsForm.php:554-562`
  - `web/modules/custom/ilas_site_assistant/src/Form/AssistantSettingsForm.php:601`

### CLAIM-097
- Claim: Settings helper `_ilas_get_secret()` resolves secrets from Pantheon runtime secrets first, then environment vars.
- Evidence:
  - `web/sites/default/settings.php:116-138`

### CLAIM-098
- Claim: Runtime secret overrides exist for Aila LLM, Langfuse, AI key entities, Pinecone key entity, and Sentry DSN.
- Evidence:
  - `web/sites/default/settings.php:190-193`
  - `web/sites/default/settings.php:201-204`
  - `web/sites/default/settings.php:212-221`
  - `web/sites/default/settings.php:228-231`
  - `web/sites/default/settings.php:238-241`
  - `web/sites/default/settings.php:250-253`
  - `web/sites/default/settings.php:261-276`

### CLAIM-099
- Claim: Production (`PANTHEON_ENVIRONMENT=live`) overrides include GA tag ID, chatbot per-IP rate limits, and a hard-disable for `llm.enabled`.
- Evidence:
  - `web/sites/default/settings.php:97-106`

### CLAIM-100
- Claim: SecKit CSP is enabled with explicit script/style/connect/frame allowlists; SecKit feature_policy is disabled.
- Evidence:
  - `config/seckit.settings.yml:3-23`
  - `config/seckit.settings.yml:44-46`

### CLAIM-101
- Claim: Permissions-Policy header is manually set in `settings.php`.
- Evidence:
  - `web/sites/default/settings.php:80-91`

### CLAIM-102
- Claim: Composer dependencies include AI, Vertex, Pinecone, Search API, Raven, SecKit, Langfuse SDK, Drush; versions captured in audit artifact.
- Evidence:
  - `composer.json:20-69`
  - `docs/aila/artifacts/dependency-versions.txt:2-11`

### CLAIM-103
- Claim: Frontend eval dependency includes Promptfoo with npm scripts for live/offline eval/view.
- Evidence:
  - `package.json:5-14`
  - `docs/aila/artifacts/dependency-versions.txt:13-14`

---

## Unknowns / Unverified Runtime Behaviors

### CLAIM-104
- Claim: Endpoint response schemas/statuses and runtime logs could not be directly verified in this audit run.
- Evidence:
  - `docs/aila/artifacts/ddev-status.txt:1-3`
  - `docs/aila/artifacts/drush-runtime-checks.txt:1-12`
  - `docs/aila/artifacts/curl-runtime-checks.txt:1-5`
- Missing evidence needed to verify: working Docker provider + successful `ddev start` + live `curl`/`drush` output snapshots.

---

## Additional Verification Claims

### CLAIM-105
- Claim: Deterministic classifier coverage exists in unit tests for both SafetyClassifier and OutOfScopeClassifier.
- Evidence:
  - `web/modules/custom/ilas_site_assistant/tests/src/DrupalUnit/SafetyClassifierTest.php:8-57`
  - `web/modules/custom/ilas_site_assistant/tests/src/DrupalUnit/OutOfScopeClassifierTest.php:8-58`

- Addendum (2026-02-27): deterministic classifier assets are promoted into an
  enforced quality gate by `tests/run-quality-gate.sh` and verified by guard
  tests tied to Phase 1 Objective #3.
- Addendum evidence:
  - `web/modules/custom/ilas_site_assistant/tests/run-quality-gate.sh`
  - `docs/aila/current-state.md` (Section 4F promptfoo + quality gate harness row)
  - `web/modules/custom/ilas_site_assistant/tests/src/Unit/PhaseOneQualityGateContractTest.php`

### CLAIM-106
- Claim: LLM request payload construction does not include explicit tool/function-calling fields; payload is `contents` + `generationConfig` + `safetySettings`.
- Evidence:
  - `web/modules/custom/ilas_site_assistant/src/Service/LlmEnhancer.php:628-643`
  - `web/modules/custom/ilas_site_assistant/src/Service/LlmEnhancer.php:681-697`
- Inference basis: no tool/function-call keys are present in the Gemini/Vertex payload builders.

### CLAIM-107
- Claim: Promptfoo execution is repo-local via npm scripts/harness; CI workflow location is not present in `.github` in this repository snapshot.
- Evidence:
  - `package.json:5-14`
  - `promptfoo-evals/promptfooconfig.yaml:1-22`
  - `docs/aila/artifacts/github-workflow-scan.txt:1-4`

---

## Runtime Addendum Verification (Local + Pantheon)

### CLAIM-108
- Claim: Local audit runtime preflight was executed successfully in WSL2 with Docker and DDEV available.
- Evidence:
  - `docs/aila/runtime/local-preflight.txt:4-10`
  - `docs/aila/runtime/local-preflight.txt:20-27`
  - `docs/aila/runtime/local-preflight.txt:74-80`

### CLAIM-109
- Claim: Local DDEV stack started and Drupal bootstrap succeeded for runtime verification.
- Evidence:
  - `docs/aila/runtime/local-runtime.txt:4-10`
  - `docs/aila/runtime/local-runtime.txt:45-55`
  - `docs/aila/runtime/local-runtime.txt:481-490`

### CLAIM-110
- Claim: `drush router:debug` was unavailable in this Drush runtime; fallback commands were executed.
- Evidence:
  - `docs/aila/runtime/local-runtime.txt:349-356`
  - `docs/aila/runtime/local-runtime.txt:481-510`

### CLAIM-111
- Claim: Local runtime drush checks captured module/config/state/queue evidence, including enabled `ilas_site_assistant` and `ilas_langfuse_export` queue listing.
- Evidence:
  - `docs/aila/runtime/local-runtime.txt:345-346`
  - `docs/aila/runtime/local-runtime.txt:358-383`

### CLAIM-112
- Claim: Local synthetic endpoint checks captured response status/schema for read endpoints (`suggest`, `faq`) and permission-gated endpoints (`health`, `metrics`).
- Evidence:
  - `docs/aila/runtime/local-endpoints.txt:6-28`
  - `docs/aila/runtime/local-endpoints.txt:78-100`

### CLAIM-113
- Claim: **SUPERSEDED by CLAIM-123.** Pre-fix observation: in local anonymous synthetic requests, `POST /assistant/api/message` and `POST /assistant/api/track` returned 200 both with and without explicit `X-CSRF-Token`. This behavior was caused by Drupal's permissive `_csrf_request_header_token` for anonymous users. Post-fix (`StrictCsrfRequestHeaderAccessCheck`), all sessionless anonymous POSTs return 403. See CLAIM-123 for current behavior.
- Evidence (historical, pre-fix):
  - `docs/aila/runtime/local-endpoints.txt:30-76` (pre-fix snapshot preserved in git history at commit `8f23d0e2d`)

### CLAIM-114
- Claim: Local cron runtime evidence includes `system.cron_last`, `ilas_site_assistant_cron()` execution in watchdog, and cron re-run warnings.
- Evidence:
  - `docs/aila/runtime/local-runtime.txt:372-373`
  - `docs/aila/runtime/local-runtime.txt:389-454`
  - `docs/aila/runtime/local-runtime.txt:419-427`

### CLAIM-115
- Claim: Terminus-authenticated Pantheon runtime checks succeeded on `dev`, `test`, and `live`.
- Evidence:
  - `docs/aila/runtime/pantheon-dev.txt:4-10`
  - `docs/aila/runtime/pantheon-test.txt:4-10`
  - `docs/aila/runtime/pantheon-live.txt:4-10`
  - `docs/aila/runtime/pantheon-dev.txt:10-40`
  - `docs/aila/runtime/pantheon-test.txt:10-39`
  - `docs/aila/runtime/pantheon-live.txt:10-39`

### CLAIM-116
- Claim: Pantheon `config:status` results differ by environment in sampled runtime (`dev`/`test` no diffs; `live` contains a reported diff).
- Evidence:
  - `docs/aila/runtime/pantheon-dev.txt:42-45`
  - `docs/aila/runtime/pantheon-test.txt:41-43`
  - `docs/aila/runtime/pantheon-live.txt:41-48`

### CLAIM-117
- Claim: Pantheon `system.cron_last` values were captured directly for `dev`, `test`, and `live`.
- Evidence:
  - `docs/aila/runtime/pantheon-dev.txt:47-50`
  - `docs/aila/runtime/pantheon-test.txt:45-47`
  - `docs/aila/runtime/pantheon-live.txt:50-52`

### CLAIM-118
- Claim: Pantheon queue snapshots show `ilas_langfuse_export` queue present with `0` items in `dev`, `test`, and `live`.
- Evidence:
  - `docs/aila/runtime/pantheon-dev.txt:52-60`
  - `docs/aila/runtime/pantheon-test.txt:49-57`
  - `docs/aila/runtime/pantheon-live.txt:54-62`

### CLAIM-119
- Claim: Pantheon active `ilas_site_assistant.settings` in `dev`/`test`/`live` shows global widget/FAQ/resources enabled, `conversation_logging.enabled=true`, `rate_limit_per_minute=15`, `rate_limit_per_hour=120`, and `llm.enabled=false`.
- Evidence:
  - `docs/aila/runtime/pantheon-dev.txt:68-76`
  - `docs/aila/runtime/pantheon-dev.txt:126-135`
  - `docs/aila/runtime/pantheon-test.txt:64-72`
  - `docs/aila/runtime/pantheon-test.txt:122-130`
  - `docs/aila/runtime/pantheon-live.txt:69-77`
  - `docs/aila/runtime/pantheon-live.txt:127-135`

### CLAIM-120
- Claim: `config:get` for `raven.settings` and `langfuse.settings` returned “Config ... does not exist” on Pantheon `dev`, `test`, and `live`.
- Evidence:
  - `docs/aila/runtime/pantheon-dev.txt:152-168`
  - `docs/aila/runtime/pantheon-test.txt:147-161`
  - `docs/aila/runtime/pantheon-live.txt:152-166`
- Addendum (2026-02-27): Phase 0 Exit #3 marks this as a dependency-readiness
  baseline. Activation checks now rely on runtime override booleans
  (`raven_client_key`, Langfuse key presence, and `langfuse_enabled`) rather
  than requiring `raven.settings` / `langfuse.settings` config objects.
- Addendum evidence:
  - `docs/aila/runbook.md` (Phase 1 observability dependency gate verification)
  - `web/modules/custom/ilas_site_assistant/tests/src/Unit/PhaseOneObservabilityDependencyGateTest.php`
  - `docs/aila/current-state.md` (Phase 0 Exit #3 Dependency Disposition)
  - `docs/aila/runtime/phase1-observability-gates.txt`

### CLAIM-121
- Claim: Pantheon cron watchdog output includes `ilas_site_assistant_cron()` execution, and sampled timestamps show roughly hourly-to-two-hour cron intervals during the captured windows.
- Evidence:
  - `docs/aila/runtime/pantheon-dev.txt:170-227`
  - `docs/aila/runtime/pantheon-dev.txt:254-262`
  - `docs/aila/runtime/pantheon-test.txt:163-220`
  - `docs/aila/runtime/pantheon-test.txt:247-257`
  - `docs/aila/runtime/pantheon-live.txt:168-225`
  - `docs/aila/runtime/pantheon-live.txt:252-262`
- Inference basis: interval estimate is derived from adjacent `Cron run completed` timestamps in the sampled watchdog windows.

### CLAIM-122
- Claim: In this repo snapshot, no first-party CI workflow file was found in repository-root CI locations, while Promptfoo execution remains script-driven and contrib dependencies include their own CI files.
- Evidence:
  - `docs/aila/runtime/promptfoo-ci-search.txt:4-6`
  - `docs/aila/runtime/promptfoo-ci-search.txt:69-151`
  - `docs/aila/runtime/promptfoo-ci-search.txt:152-158`
  - `docs/aila/runtime/promptfoo-ci-search.txt:204`
- Addendum (2026-02-27): dependency ownership/source-of-truth is now aligned
  to Pantheon/local verification gates plus repo-scripted external CI runner
  execution (`scripts/ci/*`) with Pantheon-derived assistant URLs.
- Addendum evidence:
  - `docs/aila/runbook.md` (Phase 1 observability dependency gate verification)
  - `scripts/ci/derive-assistant-url.sh`
  - `scripts/ci/run-external-quality-gate.sh`
  - `scripts/ci/run-promptfoo-gate.sh`
  - `web/modules/custom/ilas_site_assistant/tests/src/Unit/QualityGateEnforcementContractTest.php`
  - `web/modules/custom/ilas_site_assistant/tests/src/Unit/PhaseOneObservabilityDependencyGateTest.php`
  - `docs/aila/runtime/phase1-observability-gates.txt`
- Addendum (2026-02-27): quality-gate enforcement is formalized with
  repo-owned scripts and branch-aware blocking policy, while CI owner/workflow
  source-of-truth remains an external known unknown.
- Addendum evidence:
  - `docs/aila/current-state.md` (Phase 1 Objective #3 Quality Gate Disposition)
  - `docs/aila/runbook.md` (Enforced quality gate verification subsection in §4)
  - `web/modules/custom/ilas_site_assistant/tests/run-quality-gate.sh`
  - `scripts/ci/run-external-quality-gate.sh`
  - `scripts/ci/run-promptfoo-gate.sh`
  - `web/modules/custom/ilas_site_assistant/tests/src/Unit/PhaseOneQualityGateContractTest.php`

---

## Post-CSRF-Hardening Verification (IMP-SEC-01)

### CLAIM-123
- Claim: Post-fix CSRF enforcement verified: both `/assistant/api/message` and `/assistant/api/track` return 403 for all sessionless anonymous POST requests regardless of CSRF token presence/validity. The `StrictCsrfRequestHeaderAccessCheck` service enforces session-bound CSRF token validation, rejecting missing, invalid, and valid-but-sessionless tokens. Browser-context widget uses session+token pair from `drupalSettings` injection during page render. Unit test coverage: `CsrfAuthMatrixTest` (10 tests, 25 assertions). Functional test coverage: `AssistantApiFunctionalTest` 12-cell CSRF matrix.
- Evidence:
  - `docs/aila/runtime/local-endpoints.txt:1-101` (post-fix runtime artifact showing 403 for all 6 POST cells)
  - `web/modules/custom/ilas_site_assistant/src/Access/StrictCsrfRequestHeaderAccessCheck.php:1-103` (access check implementation)
  - `web/modules/custom/ilas_site_assistant/ilas_site_assistant.routing.yml:9-17` (message route with dual CSRF requirements)
  - `web/modules/custom/ilas_site_assistant/ilas_site_assistant.routing.yml:84-92` (track route with dual CSRF requirements)
  - `web/modules/custom/ilas_site_assistant/ilas_site_assistant.services.yml:2-6` (access_check service registration)
  - `web/modules/custom/ilas_site_assistant/tests/src/Unit/CsrfAuthMatrixTest.php` (unit test matrix)
  - `web/modules/custom/ilas_site_assistant/tests/src/Functional/AssistantApiFunctionalTest.php` (functional test 12-cell matrix)
- Addendum evidence:
  - `docs/aila/runbook.md` (Phase 1 observability dependency gate verification)
  - `web/modules/custom/ilas_site_assistant/tests/src/Unit/PhaseOneObservabilityDependencyGateTest.php`
  - `docs/aila/current-state.md` (Phase 0 Exit #3 Dependency Disposition)
  - `docs/aila/runtime/phase1-observability-gates.txt`

---

## Config Parity Resolution (IMP-CONF-01)

### CLAIM-124
- Claim: Active config export now includes all install-default blocks (`fallback_gate`, `safety_alerting`, `history_fallback`, `ab_testing`, `langfuse`, full LLM sub-keys `cache_ttl`/`max_retries`/`circuit_breaker`/`global_rate_limit`, and `canonical_urls.online_application`). Config completeness drift test enforces install-vs-active-vs-schema parity with 5 test methods. All values match install defaults; `conversation_logging.enabled` remains `true` in active config (intentional operational deviation from install `false`).
- Evidence:
  - `config/ilas_site_assistant.settings.yml` (synced active config)
  - `web/modules/custom/ilas_site_assistant/config/install/ilas_site_assistant.settings.yml` (install defaults source of truth)
  - `web/modules/custom/ilas_site_assistant/config/schema/ilas_site_assistant.schema.yml` (schema coverage)
  - `web/modules/custom/ilas_site_assistant/tests/src/Unit/ConfigCompletenessDriftTest.php` (5 tests: top-level key parity, schema coverage, orphan detection, LLM sub-key completeness, disabled-by-default enforcement)

---

## Architectural Boundary Enforcement (`P0-NDO-03`)

### CLAIM-125
- Claim: Phase 0 boundary "No broad architectural refactor beyond minimal seam prep" is enforceable and violations are detectable via runbook verification commands and a dedicated docs/seam guard unit test. Enforcement confirms boundary text continuity, seam-only language in backlog/risk artifacts, core seam-service anchor presence, bounded service-inventory continuity, and Diagram B pipeline anchor continuity.
- Evidence:
  - `docs/aila/roadmap.md` (Phase 0 "What we will NOT do" #3 boundary text)
  - `docs/aila/backlog.md` (`Pipeline seam extraction` backlog row)
  - `docs/aila/risk-register.md` (`R-MNT-01` seam extraction mitigation language)
  - `docs/aila/runbook.md` (section 4 architectural boundary verification commands)
  - `docs/aila/artifacts/services-inventory.tsv` (service continuity inventory)
  - `docs/aila/system-map.mmd` (Diagram B deterministic pipeline anchors)
  - `web/modules/custom/ilas_site_assistant/tests/src/Unit/ArchitectureBoundaryGuardTest.php` (contract tests)
