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
- Claim: `/assistant/api/message` is a POST route with dual CSRF enforcement (`_csrf_request_header_token` + `_ilas_strict_csrf_token`) via `StrictCsrfRequestHeaderAccessCheck`. `/assistant/api/track` is a POST route with approved origin/referer mitigation and flood limits (no CSRF token requirement).
- Evidence:
  - `web/modules/custom/ilas_site_assistant/ilas_site_assistant.routing.yml:9-17` (message route with `_ilas_strict_csrf_token: 'TRUE'`)
  - `web/modules/custom/ilas_site_assistant/ilas_site_assistant.routing.yml:92-98` (track route without CSRF requirement)
  - `web/modules/custom/ilas_site_assistant/src/Access/StrictCsrfRequestHeaderAccessCheck.php:1-103` (access check implementation)
  - `web/modules/custom/ilas_site_assistant/src/Controller/AssistantApiController.php:1313-1373` (track mitigation: same-origin origin/referer + flood checks)
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

- Addendum (2026-03-02): IMP-REL-02 contract tests verify resolveCorrelationId
  accepts valid UUID4, rejects invalid (including UUID v1, XSS payloads, malformed),
  and generates a valid UUID4 fallback. jsonResponse header/body consistency is
  proven for all response paths including empty request_id omission.
- Addendum evidence:
  - `web/modules/custom/ilas_site_assistant/tests/src/Unit/IdempotencyReplayContractTest.php`

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

- Addendum (2026-03-02): IMP-REL-02 tests verify conversation cache key determinism
  (`ilas_conv:<uuid>`), repeated-message escalation (produces `escalation`/`repeated`
  type, not duplicate responses), and request_id consistency across all response paths
  including 429/400/413/500 error responses.
- Addendum evidence:
  - `web/modules/custom/ilas_site_assistant/tests/src/Unit/IdempotencyReplayContractTest.php`

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

- Addendum (2026-03-02): IMP-REL-01 tests verify the controller catch-all returns
  500 `internal_error` with request_id, observability services (AnalyticsLogger,
  ConversationLogger, LangfuseTracer) isolate exceptions internally without
  propagation, and a consolidated failure matrix documents all 10 dependency
  failure → fallback class mappings with cross-cutting request_id presence.
- Addendum evidence:
  - `web/modules/custom/ilas_site_assistant/tests/src/Unit/IntegrationFailureContractTest.php`

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
- Claim: `/assistant/api/health` and `/assistant/api/metrics` expose alert-ready SLO status/threshold contracts for availability, latency, errors, cron freshness, and queue health.
- Evidence:
  - `web/modules/custom/ilas_site_assistant/src/Controller/AssistantApiController.php:2902-2994`

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

- Addendum (2026-03-04): `P2-DEL-04` expands promptfoo safety-boundary
  scenario coverage to include prompt-injection refusal behavior and
  informational-vs-urgent boundary checks in a dedicated dataset.
- Addendum evidence:
  - `promptfoo-evals/tests/grounding-escalation-safety-boundaries.yaml` (`scenario_family: safety_boundary`)
  - `docs/aila/roadmap.md` (Phase 2 Deliverable #4 disposition dated 2026-03-04)
  - `docs/aila/current-state.md` (P2-DEL-04 dataset expansion addendum)
  - `docs/aila/runbook.md` (P2-DEL-04 verification subsection in §4)
  - `web/modules/custom/ilas_site_assistant/tests/src/Unit/PhaseTwoDeliverableFourGateTest.php`

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

- Addendum (2026-03-04): Phase 2 Exit #1 (`P2-EXT-01`) confirms retrieval
  contract/confidence regression threshold closure is enforceable through
  runbook §4 verification bundles, runtime proof artifacts, and closure guard
  tests without changing retrieval architecture shape.
- Addendum evidence:
  - `docs/aila/roadmap.md` (Phase 2 Exit #1 disposition dated 2026-03-04)
  - `docs/aila/current-state.md` (P2-EXT-01 retrieval contract/confidence addendum)
  - `docs/aila/runbook.md` (P2-EXT-01 verification subsection in §4)
  - `docs/aila/runtime/phase2-exit1-retrieval-contract-confidence-thresholds.txt`
  - `web/modules/custom/ilas_site_assistant/tests/src/Unit/PhaseTwoExitCriteriaOneGateTest.php`

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

- Addendum (2026-03-04): Phase 2 Exit #2 (`P2-EXT-02`) confirms citation
  coverage and low-confidence refusal metrics are within approved targets,
  enforced through promptfoo gate summary fields and 90% threshold policy.
- Addendum evidence:
  - `docs/aila/roadmap.md` (Phase 2 Exit #2 disposition dated 2026-03-04)
  - `docs/aila/current-state.md` (P2-EXT-02 citation/refusal addendum)
  - `docs/aila/runbook.md` (P2-EXT-02 verification subsection in §4)
  - `docs/aila/runtime/phase2-exit2-citation-coverage-refusal-targets.txt`
  - `web/modules/custom/ilas_site_assistant/tests/src/Unit/PhaseTwoExitCriteriaTwoGateTest.php`

### CLAIM-066
- Claim: Pinecone Search API server is configured with Gemini chat model, Gemini embedding model, 3072 dimensions, and cosine similarity.
- Evidence:
  - `config/search_api.server.pinecone_vector.yml:11-29`

### CLAIM-067
- Claim: Vector indexes exist for FAQ paragraphs and resource nodes on `pinecone_vector` server.
- Evidence:
  - `config/search_api.index.faq_accordion_vector.yml:10-82`
  - `config/search_api.index.assistant_resources_vector.yml:10-67`
- Addendum (2026-03-03): Phase 2 Objective #3 (`P2-OBJ-03`) formalizes source
  freshness/provenance governance on retrieval outputs that originate from these
  index classes. Governance metadata and health/metrics observability are
  additive and do not introduce stale-result suppression or ranking penalties.
- Addendum evidence:
  - `web/modules/custom/ilas_site_assistant/src/Service/SourceGovernanceService.php`
  - `web/modules/custom/ilas_site_assistant/src/Service/FaqIndex.php`
  - `web/modules/custom/ilas_site_assistant/src/Service/ResourceFinder.php`
  - `docs/aila/current-state.md` (Section 4D governance row + P2-OBJ-03 addendum)
  - `docs/aila/runbook.md` (P2-OBJ-03 verification subsection in §4)
  - `docs/aila/roadmap.md` (Phase 2 Objective #3 disposition dated 2026-03-03)
  - `docs/aila/evidence-index.md` (CLAIM-133)

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
- Claim: Performance monitor stores rolling request metrics in state and marks degraded status by p95/error-rate thresholds; SLO services consume these metrics for availability/latency/error/cron/queue alert policy enforcement.
- Evidence:
  - `web/modules/custom/ilas_site_assistant/src/Service/PerformanceMonitor.php:19-291`
  - `web/modules/custom/ilas_site_assistant/src/Service/SloDefinitions.php:12-112`
  - `web/modules/custom/ilas_site_assistant/src/Service/SloAlertService.php:20-260`
  - `web/modules/custom/ilas_site_assistant/src/Service/QueueHealthMonitor.php:12-181`
- Addendum (2026-03-04): Phase 2 Entry #1 (`P2-ENT-01`) confirms
  observability baseline continuity from Phase 1 is operational via
  `VC-RUNBOOK-LOCAL` verification, runbook section-3 command bundle continuity,
  and closure guard coverage without runtime behavior changes.
- Addendum evidence:
  - `docs/aila/roadmap.md` (Phase 2 Entry #1 disposition dated 2026-03-04)
  - `docs/aila/current-state.md` (P2-ENT-01 observability + CI baseline operational addendum)
  - `docs/aila/runbook.md` (P2-ENT-01 verification subsection in §3)
  - `docs/aila/runtime/phase2-entry1-observability-ci-baseline.txt`
  - `web/modules/custom/ilas_site_assistant/tests/src/Unit/PhaseTwoEntryCriteriaOneGateTest.php`

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

- Addendum (2026-03-03): Phase 2 Objective #2 (`P2-OBJ-02`) formalizes eval
  coverage and release-confidence closure for RAG/response correctness using
  branch-aware gate policy (deep multi-turn in blocking mode; abuse/safety in
  advisory mode), runbook verification commands, and dedicated closure guard
  tests.
- Addendum evidence:
  - `docs/aila/roadmap.md` (Phase 2 Objective #2 disposition dated 2026-03-03)
  - `docs/aila/current-state.md` (P2-OBJ-02 evaluation coverage + release confidence addendum)
  - `docs/aila/runbook.md` (P2-OBJ-02 verification subsection in §4)
  - `web/modules/custom/ilas_site_assistant/tests/src/Unit/PhaseTwoObjectiveTwoGateTest.php`

- Addendum (2026-03-04): Phase 2 Deliverable #4 (`P2-DEL-04`) expands the
  abuse promptfoo config with a dedicated weak-grounding/escalation/
  safety-boundary dataset while preserving existing branch-aware gate policy.
- Addendum evidence:
  - `promptfoo-evals/promptfooconfig.abuse.yaml` (includes `grounding-escalation-safety-boundaries.yaml`)
  - `promptfoo-evals/tests/grounding-escalation-safety-boundaries.yaml`
  - `docs/aila/roadmap.md` (Phase 2 Deliverable #4 disposition dated 2026-03-04)
  - `docs/aila/current-state.md` (P2-DEL-04 dataset expansion addendum)
  - `docs/aila/runbook.md` (P2-DEL-04 verification subsection in §4)
  - `web/modules/custom/ilas_site_assistant/tests/src/Unit/PhaseTwoDeliverableFourGateTest.php`

- Addendum (2026-03-04): Phase 2 Exit #1 (`P2-EXT-01`) closes retrieval
  contract/confidence threshold verification with explicit runbook alias checks
  (`VC-RUNBOOK-LOCAL`, `VC-RUNBOOK-PANTHEON`), full promptfoo gate execution,
  retrieval threshold fail-flag assertions, and provider compatibility hardening
  for session-bound CSRF bootstrap.
- Addendum evidence:
  - `promptfoo-evals/providers/ilas-live.js` (bootstrap endpoint preference + fallback)
  - `scripts/ci/run-promptfoo-gate.sh` (retrieval threshold summary/fail flags)
  - `docs/aila/roadmap.md` (Phase 2 Exit #1 disposition dated 2026-03-04)
  - `docs/aila/current-state.md` (P2-EXT-01 retrieval contract/confidence addendum)
  - `docs/aila/runbook.md` (P2-EXT-01 verification subsection in §4)
  - `docs/aila/runtime/phase2-exit1-retrieval-contract-confidence-thresholds.txt`
  - `web/modules/custom/ilas_site_assistant/tests/src/Unit/PhaseTwoExitCriteriaOneGateTest.php`

- Addendum (2026-03-04): Phase 2 Exit #2 (`P2-EXT-02`) confirms citation
  coverage (10 scenarios) and low-confidence refusal (10 scenarios) metrics are
  within approved 90% threshold targets. Enforcement is verified through scenario
  anchor counts, gate summary fail-flag fields, and `VC-RUNBOOK-LOCAL` /
  `VC-RUNBOOK-PANTHEON` continuity checks.
- Addendum evidence:
  - `promptfoo-evals/tests/retrieval-confidence-thresholds.yaml` (scenario anchor counts)
  - `scripts/ci/run-promptfoo-gate.sh` (90% threshold policy and fail-flag enforcement)
  - `docs/aila/roadmap.md` (Phase 2 Exit #2 disposition dated 2026-03-04)
  - `docs/aila/current-state.md` (P2-EXT-02 citation/refusal addendum)
  - `docs/aila/runbook.md` (P2-EXT-02 verification subsection in §4)
  - `docs/aila/runtime/phase2-exit2-citation-coverage-refusal-targets.txt`
  - `web/modules/custom/ilas_site_assistant/tests/src/Unit/PhaseTwoExitCriteriaTwoGateTest.php`

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
- Claim: **SUPERSEDED by CLAIM-124.** Historical blocker basis: config schema previously defined llm/fallback/safety/history/ab/langfuse mappings but omitted `vector_search`.
- Evidence (historical, pre-fix):
  - `web/modules/custom/ilas_site_assistant/config/schema/ilas_site_assistant.schema.yml` (pre-fix snapshot preserved in git history)
- Addendum (2026-03-04): Phase 2 Entry #2 (`P2-ENT-02`) confirms this historical
  blocker (B-02) is fully resolved via CLAIM-124, with ongoing enforcement by
  `VectorSearchConfigSchemaTest` and `ConfigCompletenessDriftTest` contract suites.
- Addendum evidence:
  - `web/modules/custom/ilas_site_assistant/tests/src/Unit/VectorSearchConfigSchemaTest.php`
  - `web/modules/custom/ilas_site_assistant/tests/src/Unit/ConfigCompletenessDriftTest.php`
  - `docs/aila/runtime/phase2-entry2-config-parity-retrieval-tuning.txt`

### CLAIM-096
- Claim: Admin settings form exposes and persists `vector_search` config values. Historical schema mismatch from CLAIM-095 is resolved in CLAIM-124.
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

- Addendum (2026-03-03): Phase 2 Objective #2 (`P2-OBJ-02`) binds deterministic
  classifier coverage into release-confidence closure criteria by coupling
  Drupal-unit classifier suites with branch-aware Promptfoo gate controls and
  objective-level guard tests.
- Addendum evidence:
  - `docs/aila/roadmap.md` (Phase 2 Objective #2 disposition dated 2026-03-03)
  - `docs/aila/current-state.md` (P2-OBJ-02 evaluation coverage + release confidence addendum)
  - `docs/aila/runbook.md` (P2-OBJ-02 verification subsection in §4)
  - `web/modules/custom/ilas_site_assistant/tests/src/Unit/PhaseTwoObjectiveTwoGateTest.php`

- Addendum (2026-03-04): `P2-DEL-04` extends classifier-focused promptfoo
  regression coverage with explicit escalation and safety-boundary families and
  closure guard enforcement anchored to deliverable-level continuity checks.
- Addendum evidence:
  - `promptfoo-evals/tests/grounding-escalation-safety-boundaries.yaml`
  - `web/modules/custom/ilas_site_assistant/tests/src/Unit/PhaseTwoDeliverableFourGateTest.php`
  - `docs/aila/roadmap.md` (Phase 2 Deliverable #4 disposition dated 2026-03-04)
  - `docs/aila/current-state.md` (P2-DEL-04 dataset expansion addendum)
  - `docs/aila/runbook.md` (P2-DEL-04 verification subsection in §4)

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
- Addendum (2026-03-04): Phase 2 Entry #2 (`P2-ENT-02`) confirms Pantheon
  config:status parity for `ilas_site_assistant.settings`; the one `live` diff
  (`core.entity_view_display.node.adept_lesson.teaser`) is unrelated to assistant
  config and does not affect entry-criterion closure.
- Addendum evidence:
  - `docs/aila/runtime/phase2-entry2-config-parity-retrieval-tuning.txt`

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
- Addendum (2026-03-04): Phase 2 Exit #3 (`P2-EXT-03`) confirms live LLM
  remains disabled pending Phase 3 readiness review through layered guardrails:
  settings.php live override, settings-form live enforcement, and service-level
  effective-live checks in `LlmEnhancer` + `FallbackGate`, verified by
  `VC-RUNBOOK-LOCAL` and `VC-RUNBOOK-PANTHEON` continuity outputs.
- Addendum evidence:
  - `docs/aila/roadmap.md` (Phase 2 Exit #3 disposition dated 2026-03-04)
  - `docs/aila/current-state.md` (P2-EXT-03 live-LLM-disabled addendum)
  - `docs/aila/runbook.md` (P2-EXT-03 verification subsection in section 3)
  - `docs/aila/runtime/phase2-exit3-live-llm-disabled-phase3-readiness.txt`
  - `web/sites/default/settings.php`
  - `web/modules/custom/ilas_site_assistant/src/Form/AssistantSettingsForm.php`
  - `web/modules/custom/ilas_site_assistant/src/Service/LlmEnhancer.php`
  - `web/modules/custom/ilas_site_assistant/src/Service/FallbackGate.php`
  - `web/modules/custom/ilas_site_assistant/tests/src/Unit/PhaseTwoExitCriteriaThreeGateTest.php`

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
- Addendum (2026-03-03): CI quality gate is mandatory for merge/release path.
  First-party GitHub Actions workflow covers push+PR for all blocking branches
  (`master`, `main`, `release/**`). Branch protection on `master` requires
  `PHPUnit Quality Gate` + `Promptfoo Gate` status checks with
  `enforce_admins: true`. Concurrency control prevents stale-run races.
  Contract tests lock trigger coverage, concurrency, and mandatory-gate
  documentation as enforced invariants. Known unknown RESOLVED.
- Addendum evidence:
  - `.github/workflows/quality-gate.yml` (trigger coverage + concurrency block)
  - `docs/aila/current-state.md` (Phase 1 Exit #2 Mandatory Gate Disposition)
  - `docs/aila/runbook.md` (Mandatory gate verification subsection in §4)
  - `web/modules/custom/ilas_site_assistant/tests/src/Unit/QualityGateEnforcementContractTest.php` (trigger-coverage + mandatory-declaration tests)
- Addendum (2026-03-03): Phase 2 Objective #3 (`P2-OBJ-03`) extends CLAIM-122
  governance context by adding source freshness/provenance observability to the
  same existing health/metrics contracts and CI-verified validation commands
  (`VC-UNIT`, `VC-DRUPAL-UNIT`) without changing live LLM scope boundaries.
- Addendum evidence:
  - `web/modules/custom/ilas_site_assistant/src/Controller/AssistantApiController.php` (`health()` and `metrics()` nested governance fields)
  - `docs/aila/runbook.md` (P2-OBJ-03 verification subsection in §4)
  - `docs/aila/roadmap.md` (Phase 2 Objective #3 disposition dated 2026-03-03)
  - `web/modules/custom/ilas_site_assistant/tests/src/Unit/PhaseTwoObjectiveThreeGateTest.php`
  - `docs/aila/evidence-index.md` (CLAIM-133)
- Addendum (2026-03-04): Phase 2 Entry #1 (`P2-ENT-01`) confirms CI baseline
  continuity from Phase 1 remains operational through first-party workflow
  anchors, repo gate-script continuity, and validation alias/toggle checks,
  while preserving Phase 2 scope boundaries.
- Addendum evidence:
  - `.github/workflows/quality-gate.yml` (trigger coverage + concurrency + gate job names)
  - `scripts/ci/run-promptfoo-gate.sh`
  - `scripts/ci/run-external-quality-gate.sh`
  - `docs/aila/runbook.md` (P2-ENT-01 verification subsection in §3)
  - `docs/aila/current-state.md` (P2-ENT-01 observability + CI baseline operational addendum)
  - `docs/aila/runtime/phase2-entry1-observability-ci-baseline.txt`
  - `web/modules/custom/ilas_site_assistant/tests/src/Unit/PhaseTwoEntryCriteriaOneGateTest.php`

---

## Post-CSRF-Hardening Verification (IMP-SEC-01)

### CLAIM-123
- Claim: Post-fix blocker closure verified: `/assistant/api/message` enforces strict session-bound CSRF validation (missing/invalid/sessionless token paths denied), while `/assistant/api/track` uses an approved mitigation model for `/assistant/api/track` (same-origin `Origin`/`Referer` validation + flood limits, no CSRF token dependency). Browser-context widget uses session+token pair from `/assistant/api/session/bootstrap` before message POSTs. Unit coverage: `CsrfAuthMatrixTest`; functional coverage includes anonymous/authenticated message matrix plus track origin-mitigation tests.
- Evidence:
  - `docs/aila/runtime/local-endpoints.txt:1-132` (post-fix runtime artifact for message CSRF matrix + endpoint contract snapshots)
  - `web/modules/custom/ilas_site_assistant/src/Access/StrictCsrfRequestHeaderAccessCheck.php:1-103` (access check implementation)
  - `web/modules/custom/ilas_site_assistant/ilas_site_assistant.routing.yml:9-17` (message route with dual CSRF requirements)
  - `web/modules/custom/ilas_site_assistant/ilas_site_assistant.routing.yml:92-98` (track route without CSRF requirement)
  - `web/modules/custom/ilas_site_assistant/src/Controller/AssistantApiController.php:1313-1373` (track origin/referer mitigation + flood checks)
  - `web/modules/custom/ilas_site_assistant/ilas_site_assistant.services.yml:2-6` (access_check service registration)
  - `web/modules/custom/ilas_site_assistant/tests/src/Unit/CsrfAuthMatrixTest.php` (unit test matrix)
  - `web/modules/custom/ilas_site_assistant/tests/src/Functional/AssistantApiFunctionalTest.php` (functional CSRF + track mitigation coverage)
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

---

## Telemetry Credential and Destination Approvals (`P1-ENT-02`)

### CLAIM-126
- Claim: Platform credentials for telemetry integrations (Langfuse, Sentry) are provisioned on all Pantheon environments and destination approvals are formally documented. Settings.php contains `_ilas_get_secret()` resolution for `LANGFUSE_PUBLIC_KEY`, `LANGFUSE_SECRET_KEY`, and `SENTRY_DSN` with config override wiring. Install config defaults include all Langfuse credential keys with `enabled: false` and `host: 'https://us.cloud.langfuse.com'`. Runtime evidence confirms credentials present on dev/test/live with `llm.enabled: false` preserved.
- Evidence:
  - `web/sites/default/settings.php:131-141` (_ilas_get_secret helper)
  - `web/sites/default/settings.php:215-224` (Langfuse credential override wiring)
  - `web/sites/default/settings.php:264-279` (Sentry DSN override wiring)
  - `web/modules/custom/ilas_site_assistant/config/install/ilas_site_assistant.settings.yml` (Langfuse install defaults)
  - `docs/aila/runtime/phase1-observability-gates.txt` (runtime verification artifact)
  - `docs/aila/current-state.md` (Phase 1 Entry #2 Credential and Destination Disposition)
  - `web/modules/custom/ilas_site_assistant/tests/src/Unit/TelemetryCredentialGateTest.php` (gate test)

---

## Phase 1 Exit #1 Non-Live Alerts + Dashboards (`P1-EXT-01`)

### CLAIM-127
- Claim: Phase 1 Exit #1 (P1-EXT-01) is closed: critical alerts and dashboards operate in non-live and are tested. Cron hook records run health before `SloAlertService::checkAll()` so SLO checks evaluate same-run cron state, while dashboard and alert verification is captured for local + Pantheon `dev`/`test`.
- Evidence:
  - `web/modules/custom/ilas_site_assistant/ilas_site_assistant.module` (cron finally ordering and guarded SLO check)
  - `web/modules/custom/ilas_site_assistant/tests/src/Unit/CronHookSloAlertOrderingTest.php` (ordering regression: success and failure paths)
  - `web/modules/custom/ilas_site_assistant/tests/src/Unit/SloAlertServiceTest.php` (`@slo_dimension` warning-context assertions for availability/latency/error_rate/cron/queue)
  - `web/modules/custom/ilas_site_assistant/tests/src/Functional/AssistantApiFunctionalTest.php` (health/metrics/admin-report permission and access coverage)
  - `web/modules/custom/ilas_site_assistant/tests/src/Unit/PhaseOneExitCriteriaOneGateTest.php` (doc/evidence/runtime artifact continuity lock)
  - `docs/aila/roadmap.md` (Phase 1 Exit #1 disposition dated 2026-03-03)
  - `docs/aila/current-state.md` (Phase 1 Exit #1 non-live verification addendum)
  - `docs/aila/system-map.mmd` (Diagram A dashboard + critical-alert observability surfaces)
  - `docs/aila/runbook.md` (Phase 1 Exit #1 non-live verification command bundle)
  - `docs/aila/runtime/phase1-exit1-alerts-dashboards.txt` (local + Pantheon non-live runtime proof)

---

## Phase 1 Exit #3 Reliability Failure Matrix (`P1-EXT-03`)

### CLAIM-128
- Claim: Phase 1 Exit #3 (P1-EXT-03) is closed: reliability failure matrix tests pass against target environments (local + Pantheon `dev`/`test`/`live`) with deterministic fallback-class coverage and environment assumptions verified.
- Evidence:
  - `docs/aila/runbook.md` (Phase 1 Exit #3 reliability failure matrix verification subsection in section 4)
  - `docs/aila/roadmap.md` (Phase 1 Exit #3 disposition dated 2026-03-03)
  - `docs/aila/current-state.md` (Phase 1 Exit #3 reliability failure matrix verification addendum)
  - `docs/aila/runtime/phase1-exit3-reliability-failure-matrix.txt` (local suite outputs + Pantheon key checks)
  - `web/modules/custom/ilas_site_assistant/tests/src/Unit/DependencyFailureDegradeContractTest.php`
  - `web/modules/custom/ilas_site_assistant/tests/src/Unit/IntegrationFailureContractTest.php`
  - `web/modules/custom/ilas_site_assistant/tests/src/Unit/LlmEnhancerHardeningTest.php`
  - `web/modules/custom/ilas_site_assistant/tests/src/Unit/PhaseOneExitCriteriaThreeGateTest.php` (doc/evidence/runtime artifact continuity lock)

---

## Phase 1 Sprint 2 Gap Closure (`P1-SBD-01`)

### CLAIM-129
- Claim: Phase 1 Sprint 2 (`P1-SBD-01`) is closed in-repo: Sentry/Langfuse bootstrap remains staged, Drupal log schema normalization is enforced through canonical telemetry context keys, and initial SLO drafts remain documented and verifiable without changing live LLM or retrieval-architecture scope boundaries.
- Evidence:
  - `web/modules/custom/ilas_site_assistant/src/Service/TelemetrySchema.php` (`toLogContext()` canonical + alias context helper)
  - `web/modules/custom/ilas_site_assistant/src/Controller/AssistantApiController.php` (critical exit/complete/error logs enriched via `TelemetrySchema::toLogContext()`)
  - `web/modules/custom/ilas_site_assistant/tests/src/Unit/TelemetrySchemaContractTest.php` (canonical field + helper contract checks)
  - `web/modules/custom/ilas_site_assistant/tests/src/Unit/PhaseOneSprintTwoGateTest.php` (Sprint 2 doc/code closure gate)
  - `docs/aila/roadmap.md` (Phase 1 Sprint 2 disposition dated 2026-03-03)
  - `docs/aila/current-state.md` (Phase 1 Sprint 2 closure addendum)
  - `docs/aila/runbook.md` (Phase 1 Sprint 2 verification subsection)
  - `docs/aila/backlog.md` (SLO baseline story marked done with dated closure reference)
  - `docs/aila/system-map.mmd` (Observability node includes normalized telemetry log schema)

---

## Phase 1 Sprint 3 Gap Closure (`P1-SBD-02`)

### CLAIM-130
- Claim: Phase 1 Sprint 3 (`P1-SBD-02`) is closed in-repo: alert policy finalization, CI gate rollout, and reliability failure matrix completion are documented, runtime-evidenced, and locked by closure contract tests without enabling live LLM or redesigning retrieval architecture.
- Evidence:
  - `docs/aila/roadmap.md` (Phase 1 Sprint 3 disposition dated 2026-03-03 + Blocker B-03 resolved language)
  - `docs/aila/current-state.md` (Phase 1 Sprint 3 Closure Addendum + updated quality-gate harness row)
  - `docs/aila/runbook.md` (Phase 1 Sprint 3 verification subsection with VC-UNIT + VC-QUALITY-GATE aliases)
  - `docs/aila/runtime/phase1-sprint3-closure.txt` (Sprint 3 command/output summary and linked artifacts)
  - `.github/workflows/quality-gate.yml` (first-party mandatory gate workflow wiring)
  - `docs/aila/runtime/phase1-exit1-alerts-dashboards.txt` (alert policy finalization runtime proof)
  - `docs/aila/runtime/phase1-exit3-reliability-failure-matrix.txt` (reliability matrix completion runtime proof)
  - `docs/aila/backlog.md` (CI quality gate + reliability matrix stories marked done with closure linkage)
  - `web/modules/custom/ilas_site_assistant/tests/src/Unit/PhaseOneSprintThreeGateTest.php` (Sprint 3 doc/evidence/runtime continuity lock)

---

## Phase 1 NDO #2 Retrieval Architecture Boundary (`P1-NDO-02`)

### CLAIM-131
- Claim: Phase 1 NDO #2 boundary ("No full redesign of retrieval architecture") is enforceable and violations are detectable via dedicated runbook verification commands plus a dedicated docs/service continuity guard test, while preserving existing retrieval runtime architecture (Search API lexical retrieval + optional vector supplement + legacy fallback).
- Evidence:
  - `docs/aila/roadmap.md` (Phase 1 "What we will NOT do" #2 + dated P1-NDO-02 disposition)
  - `docs/aila/current-state.md` (Section 4D retrieval architecture shape + Phase 1 NDO #2 Boundary Enforcement Addendum)
  - `docs/aila/runbook.md` (Phase 1 retrieval architecture boundary verification subsection in section 4)
  - `docs/aila/system-map.mmd` (Diagram B retrieval anchors)
  - `web/modules/custom/ilas_site_assistant/ilas_site_assistant.services.yml` (retrieval service anchors: `faq_index`, `resource_finder`, `ranking_enhancer`)
  - `web/modules/custom/ilas_site_assistant/tests/src/Unit/PhaseOneNoRetrievalArchitectureRedesignGuardTest.php` (P1-NDO-02 guard test)

---

## Phase 2 Objective #2 Eval Coverage + Release Confidence (`P2-OBJ-02`)

### CLAIM-132
- Claim: Phase 2 Objective #2 (`P2-OBJ-02`) is closed in-repo: evaluation
  coverage and release confidence for RAG/response correctness are formalized
  through branch-aware Promptfoo gate policy, required PHPUnit validation
  commands (`VC-UNIT`, `VC-DRUPAL-UNIT`), and objective-level guard tests,
  without enabling live LLM or introducing broad platform migration changes.
- Evidence:
  - `docs/aila/roadmap.md` (Phase 2 Objective #2 disposition dated 2026-03-03)
  - `docs/aila/current-state.md` (Section 4F harness row + P2-OBJ-02 addendum)
  - `docs/aila/runbook.md` (P2-OBJ-02 verification subsection in section 4)
  - `docs/aila/system-map.mmd` (Diagram A Promptfoo/CI integration path anchors)
  - `scripts/ci/run-promptfoo-gate.sh` (blocking/advisory branch-aware mode + deep/abuse config policy)
  - `promptfoo-evals/promptfooconfig.deep.yaml` (deep multi-turn eval config)
  - `promptfoo-evals/promptfooconfig.abuse.yaml` (abuse/safety eval config)
  - `promptfoo-evals/tests/conversations-deep.yaml` (deep multi-turn RAG/response-correctness assertions)
  - `promptfoo-evals/tests/abuse-safety.yaml` (abuse/safety and refusal/caveat assertion coverage)
  - `web/modules/custom/ilas_site_assistant/tests/src/DrupalUnit/SafetyClassifierTest.php`
  - `web/modules/custom/ilas_site_assistant/tests/src/DrupalUnit/OutOfScopeClassifierTest.php`
  - `web/modules/custom/ilas_site_assistant/tests/src/Unit/PhaseTwoObjectiveTwoGateTest.php` (P2-OBJ-02 continuity/enforcement lock)

---

## Phase 2 Objective #3 Source Freshness + Provenance Governance (`P2-OBJ-03`)

### CLAIM-133
- Claim: Phase 2 Objective #3 (`P2-OBJ-03`) is closed in-repo: source
  freshness/provenance governance is enforced through additive config policy,
  schema coverage, runtime retrieval annotations, state-backed observation
  snapshots, and health/metrics observability with cooldowned soft alerts only.
  Enforcement remains non-blocking (no stale-result suppression/reranking),
  preserves Phase 2 scope boundaries, and retains existing Pantheon baseline
  architecture.
- Evidence:
  - `web/modules/custom/ilas_site_assistant/config/install/ilas_site_assistant.settings.yml` (`source_governance` defaults)
  - `config/ilas_site_assistant.settings.yml` (`source_governance` active config)
  - `web/modules/custom/ilas_site_assistant/config/schema/ilas_site_assistant.schema.yml` (`source_governance` typed mapping)
  - `web/modules/custom/ilas_site_assistant/src/Service/SourceGovernanceService.php` (annotation + snapshot + cooldowned alert behavior)
  - `web/modules/custom/ilas_site_assistant/src/Service/FaqIndex.php` (FAQ lexical/vector source-class annotations + updated_at propagation)
  - `web/modules/custom/ilas_site_assistant/src/Service/ResourceFinder.php` (resource lexical/vector + legacy/topic/service-path governance annotations)
  - `web/modules/custom/ilas_site_assistant/src/Controller/AssistantApiController.php` (observation recording + health/metrics governance exposure + retrieval debug metadata fields)
  - `web/modules/custom/ilas_site_assistant/ilas_site_assistant.services.yml` (source-governance service registration + retrieval service injections)
  - `web/modules/custom/ilas_site_assistant/tests/src/Unit/SourceGovernanceServiceTest.php`
  - `web/modules/custom/ilas_site_assistant/tests/src/Unit/PhaseTwoObjectiveThreeGateTest.php`
  - `docs/aila/roadmap.md` (Phase 2 Objective #3 disposition dated 2026-03-03)
  - `docs/aila/current-state.md` (Section 4D governance row + P2-OBJ-03 addendum)
  - `docs/aila/system-map.mmd` (Diagram A source-governance node/path anchors)
  - `docs/aila/runbook.md` (P2-OBJ-03 verification subsection in section 4)
- Follow-on addendum (2026-03-03): balanced ratio+sample degrade thresholds
  are now enforced for unknown/missing governance status (`min_observations=20`,
  `unknown_ratio_degrade_pct=25.0`, `missing_source_url_ratio_degrade_pct=10.0`)
  while stale-ratio degradation stays unchanged. Snapshot payload now includes
  cooldown transparency fields (`last_alert_at`, `next_alert_eligible_at`,
  `cooldown_seconds_remaining`) to support deterministic operations checks.
- Follow-on addendum evidence:
  - `web/modules/custom/ilas_site_assistant/src/Service/SourceGovernanceService.php`
  - `web/modules/custom/ilas_site_assistant/tests/src/Unit/SourceGovernanceServiceTest.php`
  - `docs/aila/runbook.md` (P2-OBJ-03 state inspection/reset workflow)
  - `docs/aila/current-state.md` (P2-OBJ-03 follow-on tuning bullet)
  - `docs/aila/roadmap.md` (Phase 2 Objective #3 follow-on tuning bullet)

---

## Phase 2 Deliverable #1 Response Contract Expansion (`P2-DEL-01`)

### CLAIM-134
- Claim: Phase 2 Deliverable #1 (`P2-DEL-01`) is closed in-repo: 200-response
  contract includes `confidence` (float 0-1), `citations[]` (formalized from
  ResponseGrounder sources), and `decision_reason` (human-readable string from
  FallbackGate reason codes or path-specific defaults). Request-id normalization
  (IMP-REL-02) is verified complete. Langfuse grounding span citation field bug
  is fixed. Error responses (4xx/5xx) are excluded from the expanded contract.
  Phase 2 scope constraints are preserved: no live LLM enablement, no retrieval
  architecture redesign, no platform migration.
- Evidence:
  - `web/modules/custom/ilas_site_assistant/src/Controller/AssistantApiController.php` (`assembleContractFields()` method + 5 call sites)
  - `web/modules/custom/ilas_site_assistant/src/Service/FallbackGate.php` (`getReasonCodeDescriptions()` with 13 REASON_* constants)
  - `web/modules/custom/ilas_site_assistant/src/Service/ResponseGrounder.php` (`sources[]` production in `groundResponse()`)
  - `web/modules/custom/ilas_site_assistant/tests/src/Unit/PhaseTwoDeliverableOneGateTest.php`
  - `docs/aila/roadmap.md` (Phase 2 Deliverable #1 disposition dated 2026-03-03)
  - `docs/aila/current-state.md` (Section 4B contract expansion row + Section 4D retrieval confidence row + P2-DEL-01 disposition)
  - `docs/aila/runbook.md` (P2-DEL-01 verification subsection in section 4)
- Addendum (2026-03-05): Sprint 4 closure (`P2-SBD-01`) retunes response
  contract normalization to clamp confidence to finite `[0,1]`, keep contract
  fields additive, and derive citations safely from retrieval results when
  `sources[]` are sparse.
- Addendum evidence:
  - `web/modules/custom/ilas_site_assistant/src/Controller/AssistantApiController.php` (normalization helpers in `assembleContractFields()`)
  - `web/modules/custom/ilas_site_assistant/tests/src/Unit/ResponseContractNormalizationTest.php`
  - `web/modules/custom/ilas_site_assistant/tests/src/Unit/PhaseTwoDeliverableOneGateTest.php`
  - `docs/aila/current-state.md` (P2-SBD-01 closure addendum)
  - `docs/aila/runbook.md` (P2-SBD-01 verification subsection)

---

## Phase 2 Deliverable #2 Retrieval Confidence/Refusal Threshold Gating (`P2-DEL-02`)

### CLAIM-135
- Claim: Phase 2 Deliverable #2 (`P2-DEL-02`) is closed in-repo: retrieval
  confidence/refusal thresholds are integrated with the Promptfoo eval harness
  and branch-aware regression gating. Harness assertions now enforce three
  retrieval-specific metrics (`rag-contract-meta-present`,
  `rag-citation-coverage`, `rag-low-confidence-refusal`) and gate policy
  enforces a 90% minimum per metric when eval data is present. Gate summary
  artifacts expose per-metric rates/counts/fail flags. Scope constraints remain
  unchanged (no live LLM enablement, no broad platform migration).
- Evidence:
  - `promptfoo-evals/providers/ilas-live.js` (appended `[contract_meta]` JSON metadata line for eval assertions)
  - `promptfoo-evals/tests/retrieval-confidence-thresholds.yaml` (metric assertions for metadata, citation coverage, low-confidence refusal/clarify behavior)
  - `promptfoo-evals/promptfooconfig.abuse.yaml` (includes retrieval confidence threshold suite in primary gate config)
  - `scripts/ci/run-promptfoo-gate.sh` (metric parsing via namedScores/namedScoresCount + 90% per-metric threshold enforcement + summary fields)
  - `web/modules/custom/ilas_site_assistant/tests/src/Unit/PhaseTwoDeliverableTwoGateTest.php` (closure continuity/enforcement lock)
  - `docs/aila/roadmap.md` (Phase 2 Deliverable #2 disposition dated 2026-03-03)
  - `docs/aila/current-state.md` (Section 4D retrieval row + Section 4F harness row + P2-DEL-02 disposition)
  - `docs/aila/runbook.md` (P2-DEL-02 verification subsection in section 4)
  - `docs/aila/backlog.md` (Retrieval Quality row moved to active mitigation posture)
  - `docs/aila/risk-register.md` (`R-RAG-01` moved to active mitigation with threshold-gated detection signals)
- Addendum (2026-03-05): Sprint 4 closure (`P2-SBD-01`) keeps 90%
  retrieval-threshold policy while adding metric count-floor diagnostics
  (`rag_metric_min_count`, `rag_*_count_fail`) and replacing one brittle
  citation scenario prompt for improved deterministic retrieval grounding.
- Addendum evidence:
  - `scripts/ci/run-promptfoo-gate.sh` (metric count-floor summary fields + fail flags)
  - `promptfoo-evals/tests/retrieval-confidence-thresholds.yaml` (retuned citation scenario text)
  - `promptfoo-evals/providers/ilas-live.js` (normalized `contract_meta.confidence`)
  - `web/modules/custom/ilas_site_assistant/tests/src/Unit/PhaseTwoDeliverableTwoGateTest.php`
  - `docs/aila/current-state.md` (P2-SBD-01 closure addendum)
  - `docs/aila/runbook.md` (P2-SBD-01 verification subsection)

---

## Phase 2 Deliverable #3 Vector Index Hygiene + Refresh Monitoring (`P2-DEL-03`)

### CLAIM-136
- Claim: Phase 2 Deliverable #3 (`P2-DEL-03`) is closed in-repo: vector index
  hygiene policy, metadata standards, and refresh monitoring (`IMP-RAG-02`) are
  enforced through a dedicated service, policy-versioned config/schema defaults,
  cron-driven incremental refresh snapshots, metadata drift detection, and
  additive health/metrics exposure. Enforcement remains non-invasive for
  retrieval behavior (no ranking/filtering penalties), and scope constraints
  remain unchanged (no live LLM enablement, no broad platform migration).
- Evidence:
  - `web/modules/custom/ilas_site_assistant/src/Service/VectorIndexHygieneService.php` (policy defaults, metadata checks, incremental refresh cadence, per-index failure isolation, snapshot + cooldowned alert behavior)
  - `web/modules/custom/ilas_site_assistant/config/install/ilas_site_assistant.settings.yml` (`vector_index_hygiene` defaults)
  - `config/ilas_site_assistant.settings.yml` (`vector_index_hygiene` active config)
  - `web/modules/custom/ilas_site_assistant/config/schema/ilas_site_assistant.schema.yml` (`vector_index_hygiene` typed mapping + managed indexes)
  - `web/modules/custom/ilas_site_assistant/ilas_site_assistant.services.yml` (`ilas_site_assistant.vector_index_hygiene` registration)
  - `web/modules/custom/ilas_site_assistant/ilas_site_assistant.module` (`hook_cron()` hygiene refresh invocation with failure isolation)
  - `web/modules/custom/ilas_site_assistant/src/Controller/AssistantApiController.php` (health `checks.vector_index_hygiene`; metrics `metrics.vector_index_hygiene` + `thresholds.vector_index_hygiene`)
  - `web/modules/custom/ilas_site_assistant/tests/src/Unit/VectorIndexHygieneServiceTest.php`
  - `web/modules/custom/ilas_site_assistant/tests/src/Unit/PhaseTwoDeliverableThreeGateTest.php`
  - `docs/aila/roadmap.md` (Phase 2 Deliverable #3 disposition dated 2026-03-04)
  - `docs/aila/current-state.md` (Section 4D retrieval row + Section 4G cron row + P2-DEL-03 disposition)
  - `docs/aila/runbook.md` (P2-DEL-03 verification subsection in section 4)
  - `docs/aila/system-map.mmd` (Diagram A vector-index-hygiene node/path anchors)
  - `docs/aila/backlog.md` (Retrieval Quality row moved to active mitigation for `IMP-RAG-02 / P2-DEL-03`)
  - `docs/aila/risk-register.md` (`R-RAG-02` and `R-GOV-02` moved to active mitigation with hygiene drift/overdue signals)

---

## Phase 2 Deliverable #4 Promptfoo Dataset Expansion (`P2-DEL-04`)

### CLAIM-137
- Claim: Phase 2 Deliverable #4 (`P2-DEL-04`) is closed in-repo: promptfoo
  dataset coverage is expanded with explicit weak-grounding, escalation, and
  safety-boundary scenarios (36 total, 12 per family) wired into the primary
  abuse promptfoo config. Coverage asserts contract-metadata continuity and
  family-specific behavior checks while preserving existing branch-aware gate
  policy and Phase 2 scope constraints (no live LLM enablement, no broad
  platform migration).
- Evidence:
  - `promptfoo-evals/tests/grounding-escalation-safety-boundaries.yaml` (36 scenarios; `metadata.scenario_family` families + `p2del04-*` metrics)
  - `promptfoo-evals/promptfooconfig.abuse.yaml` (dataset wiring in primary abuse gate config)
  - `web/modules/custom/ilas_site_assistant/tests/src/Unit/PhaseTwoDeliverableFourGateTest.php` (closure continuity/enforcement lock)
  - `docs/aila/roadmap.md` (Phase 2 Deliverable #4 disposition dated 2026-03-04)
  - `docs/aila/current-state.md` (Section 4F harness row + P2-DEL-04 addendum)
  - `docs/aila/runbook.md` (P2-DEL-04 verification subsection in section 4)
  - `docs/aila/risk-register.md` (`R-MNT-02` and `R-LLM-01` conservative linkage text updates with unchanged status values)

---

## Phase 2 Entry #1 Observability + CI Baseline Operational (`P2-ENT-01`)

### CLAIM-138
- Claim: Phase 2 Entry criterion #1 (`P2-ENT-01`) is closed in-repo:
  observability and CI baselines from Phase 1 are operational and verifiable via
  command aliases (`VC-RUNBOOK-LOCAL`, `VC-TOGGLE-CHECK`), section-3 runbook
  verification commands, CI workflow/script continuity anchors, and Diagram A
  observability/CI path anchors. Scope constraints remain unchanged: no live LLM
  enablement through Phase 2 and no broad platform migration outside the current
  Pantheon baseline.
- Evidence:
  - `docs/aila/roadmap.md` (Phase 2 Entry #1 disposition dated 2026-03-04)
  - `docs/aila/current-state.md` (P2-ENT-01 observability + CI baseline operational addendum)
  - `docs/aila/runbook.md` (P2-ENT-01 verification subsection in section 3)
  - `docs/aila/runtime/phase2-entry1-observability-ci-baseline.txt` (sanitized VC command output + CI/diagram anchor checks)
  - `.github/workflows/quality-gate.yml` (first-party CI baseline workflow anchors)
  - `scripts/ci/run-promptfoo-gate.sh`
  - `scripts/ci/run-external-quality-gate.sh`
  - `docs/aila/system-map.mmd` (Diagram A observability + CI anchors)
  - `web/modules/custom/ilas_site_assistant/tests/src/Unit/PhaseTwoEntryCriteriaOneGateTest.php` (closure continuity/enforcement lock)

---

## Phase 2 Entry #2 Config Parity + Retrieval Tuning Stability (`P2-ENT-02`)

### CLAIM-139
- Claim: Phase 2 Entry criterion #2 (`P2-ENT-02`) is closed in-repo: config parity
  and retrieval tuning controls are stable across environments, enforced by
  `VectorSearchConfigSchemaTest` (4 tests) and `ConfigCompletenessDriftTest` (5 tests)
  providing three-way parity (install defaults / active config export / schema).
  `vector_search` (7 keys) and `fallback_gate.thresholds` (12 keys) are verified
  present in schema, install defaults, and active config export. Scope constraints
  remain unchanged: no live LLM enablement through Phase 2 and no broad platform
  migration outside the current Pantheon baseline.
- Evidence:
  - `docs/aila/roadmap.md` (Phase 2 Entry #2 disposition dated 2026-03-04)
  - `docs/aila/current-state.md` (P2-ENT-02 config parity + retrieval tuning stability addendum)
  - `docs/aila/runbook.md` (P2-ENT-02 verification subsection in section 3)
  - `docs/aila/runtime/phase2-entry2-config-parity-retrieval-tuning.txt` (sanitized VC command output + config parity anchor checks)
  - `web/modules/custom/ilas_site_assistant/tests/src/Unit/VectorSearchConfigSchemaTest.php` (schema parity enforcement)
  - `web/modules/custom/ilas_site_assistant/tests/src/Unit/ConfigCompletenessDriftTest.php` (install/active/schema parity enforcement)
  - `web/modules/custom/ilas_site_assistant/config/schema/ilas_site_assistant.schema.yml` (schema coverage for vector_search + fallback_gate)
  - `web/modules/custom/ilas_site_assistant/tests/src/Unit/PhaseTwoEntryCriteriaTwoGateTest.php` (closure continuity/enforcement lock)

---

## Phase 2 Exit #1 Retrieval Contract + Confidence Thresholds (`P2-EXT-01`)

### CLAIM-140
- Claim: Phase 2 Exit criterion #1 (`P2-EXT-01`) is closed in-repo: retrieval
  contract and confidence logic pass regression thresholds through explicit
  runbook alias execution (`VC-RUNBOOK-LOCAL`, `VC-RUNBOOK-PANTHEON`), full
  promptfoo gate execution, and deterministic retrieval-threshold fail-flag
  validation (`rag_contract_meta_fail`, `rag_citation_coverage_fail`,
  `rag_low_confidence_refusal_fail`). Promptfoo provider bootstrap now prefers
  `/assistant/api/session/bootstrap` with `/session/token` fallback to preserve
  session-bound CSRF behavior in eval harness runs. Scope constraints remain
  unchanged: no live LLM enablement through Phase 2 and no broad platform
  migration outside the current Pantheon baseline.
- Evidence:
  - `docs/aila/roadmap.md` (Phase 2 Exit #1 disposition dated 2026-03-04)
  - `docs/aila/current-state.md` (P2-EXT-01 retrieval contract/confidence addendum)
  - `docs/aila/runbook.md` (P2-EXT-01 verification subsection in section 4)
  - `docs/aila/runtime/phase2-exit1-retrieval-contract-confidence-thresholds.txt` (sanitized local/pantheon/gate summary proof)
  - `promptfoo-evals/providers/ilas-live.js` (assistant session bootstrap endpoint preference + /session/token fallback)
  - `scripts/ci/run-promptfoo-gate.sh` (retrieval threshold summary fields and fail-flag enforcement)
  - `web/modules/custom/ilas_site_assistant/tests/src/Unit/PhaseTwoExitCriteriaOneGateTest.php` (closure continuity/enforcement lock)

## Phase 2 Exit #2 Citation Coverage + Low-Confidence Refusal Targets (`P2-EXT-02`)

### CLAIM-141
- Claim: Phase 2 exit criterion #2 ("Citation coverage and low-confidence refusal
  metrics are within approved targets") is closed as implemented. Citation
  coverage (10 `rag-citation-coverage` scenarios) and low-confidence refusal
  (10 `rag-low-confidence-refusal` scenarios) pass the configured 90% per-metric
  threshold in `scripts/ci/run-promptfoo-gate.sh`, verified through gate summary
  fail-flag fields (`rag_citation_coverage_fail=no`,
  `rag_low_confidence_refusal_fail=no`), scenario anchor counts in
  `retrieval-confidence-thresholds.yaml`, and `VC-RUNBOOK-LOCAL` /
  `VC-RUNBOOK-PANTHEON` scope continuity checks. Scope constraints remain
  unchanged: no live LLM enablement through Phase 2 and no broad platform
  migration outside the current Pantheon baseline.
- Evidence:
  - `docs/aila/roadmap.md` (Phase 2 Exit #2 disposition dated 2026-03-04)
  - `docs/aila/current-state.md` (P2-EXT-02 citation/refusal addendum)
  - `docs/aila/runbook.md` (P2-EXT-02 verification subsection in section 4)
  - `docs/aila/runtime/phase2-exit2-citation-coverage-refusal-targets.txt` (sanitized local/pantheon/scenario anchor proof)
  - `promptfoo-evals/tests/retrieval-confidence-thresholds.yaml` (10 citation + 10 refusal scenario anchors)
  - `scripts/ci/run-promptfoo-gate.sh` (90% threshold policy and fail-flag enforcement)
  - `web/modules/custom/ilas_site_assistant/tests/src/Unit/PhaseTwoExitCriteriaTwoGateTest.php` (closure continuity/enforcement lock)

---

## Phase 2 Exit #3 Live LLM Disabled Pending Phase 3 Readiness Review (`P2-EXT-03`)

### CLAIM-142
- Claim: Phase 2 exit criterion #3 ("Live LLM remains disabled pending Phase 3
  readiness review") is closed as implemented. Live LLM disablement is enforced
  by layered runtime controls (Pantheon live settings override, settings-form
  live guardrails, and service-level effective-live checks in `LlmEnhancer` and
  `FallbackGate`) plus explicit `VC-RUNBOOK-LOCAL` and
  `VC-RUNBOOK-PANTHEON` continuity verification. Scope constraints remain
  unchanged: no live LLM enablement through Phase 2 and no broad platform
  migration outside the current Pantheon baseline.
- Evidence:
  - `docs/aila/roadmap.md` (Phase 2 Exit #3 disposition dated 2026-03-04)
  - `docs/aila/current-state.md` (P2-EXT-03 live-LLM-disabled addendum)
  - `docs/aila/runbook.md` (P2-EXT-03 verification subsection in section 3)
  - `docs/aila/runtime/phase2-exit3-live-llm-disabled-phase3-readiness.txt` (sanitized local/pantheon/guard-anchor proof)
  - `web/sites/default/settings.php` (live hard-disable override for `llm.enabled`)
  - `web/modules/custom/ilas_site_assistant/src/Form/AssistantSettingsForm.php` (live UI/validation/submit guardrails)
  - `web/modules/custom/ilas_site_assistant/src/Service/LlmEnhancer.php` (service-level effective-live hard-disable)
  - `web/modules/custom/ilas_site_assistant/src/Service/FallbackGate.php` (service-level effective-live fallback routing guard)
  - `web/modules/custom/ilas_site_assistant/tests/src/Unit/LlmEnhancerHardeningTest.php` (live guard behavior contract)
  - `web/modules/custom/ilas_site_assistant/tests/src/Unit/FallbackGateTest.php` (live fallback decision contract)
  - `web/modules/custom/ilas_site_assistant/tests/src/Unit/PhaseTwoExitCriteriaThreeGateTest.php` (closure continuity/enforcement lock)

---

## Phase 2 Sprint 4 Response Contract + Retrieval Confidence Retune Closure (`P2-SBD-01`)

### CLAIM-143
- Claim: Phase 2 Sprint 4 closure item (`P2-SBD-01`) is completed in-repo as
  specified: "Sprint 4: response contract + retrieval-confidence implementation
  and tests." Runtime behavior is retuned additively by normalizing response
  contract fields (`confidence`, `citations[]`, `decision_reason`), capping
  high-intent/no-results retrieval confidence at `<= 0.49` while preserving
  answer routing (`REASON_NO_RESULTS`), and extending promptfoo gate summaries
  with metric count-floor diagnostics under unchanged 90% threshold policy.
  Scope constraints remain unchanged: no live LLM enablement through Phase 2
  and no broad platform migration outside the current Pantheon baseline.
- Evidence:
  - `web/modules/custom/ilas_site_assistant/src/Controller/AssistantApiController.php` (`assembleContractFields()` normalization helpers)
  - `web/modules/custom/ilas_site_assistant/src/Service/FallbackGate.php` (no-results high-intent confidence cap + debug markers)
  - `promptfoo-evals/providers/ilas-live.js` (normalized `contract_meta.confidence`)
  - `promptfoo-evals/tests/retrieval-confidence-thresholds.yaml` (retuned citation scenario while preserving 10+10 metric anchors)
  - `scripts/ci/run-promptfoo-gate.sh` (`rag_metric_min_count` + `rag_*_count_fail` summary enforcement)
  - `web/modules/custom/ilas_site_assistant/tests/src/Unit/ResponseContractNormalizationTest.php`
  - `web/modules/custom/ilas_site_assistant/tests/src/Unit/FallbackGateTest.php`
  - `web/modules/custom/ilas_site_assistant/tests/src/Unit/PhaseTwoDeliverableOneGateTest.php`
  - `web/modules/custom/ilas_site_assistant/tests/src/Unit/PhaseTwoDeliverableTwoGateTest.php`
  - `web/modules/custom/ilas_site_assistant/tests/src/Unit/PhaseTwoSprintFourGateTest.php`
  - `docs/aila/roadmap.md` (Phase 2 Sprint 4 disposition dated 2026-03-05)
  - `docs/aila/current-state.md` (P2-SBD-01 closure addendum)
  - `docs/aila/runbook.md` (P2-SBD-01 verification subsection in section 4)
  - `docs/aila/runtime/phase2-sprint4-closure.txt` (sanitized VC alias output + scope guardrails)
  - `docs/aila/backlog.md` (retrieval-quality row continuity addendum)
  - `docs/aila/risk-register.md` (`R-RAG-01` detection-signal addendum)
