# ILAS Site Assistant — Verified Audit Backlog

> **Created:** 2026-02-19
> **Source:** Production audit (Feb 2026) — all findings verified against codebase
> **Infrastructure:** Pantheon Basic (no Redis, no Solr). Cache bins are database-backed.

---

## 1. Observability (Langfuse / Sentry)

| Ticket | Audit ID | Verified? | Summary | Evidence | Constraints | Target |
|--------|----------|-----------|---------|----------|-------------|--------|
| OBS-1 | L-1 | **YES** | `endTrace()` emits `trace-create` instead of `trace-update`, creating duplicate traces | `LangfuseTracer.php:455` — `'type' => 'trace-create'` but `startTrace()` at line 197 already emits `trace-create`. Comment at line 446 says "trace-update". | None | dev |
| OBS-2 | L-2 / F-3 | **YES** | Langfuse queue has no size cap — unbounded growth during outage | `LangfuseTerminateSubscriber.php:69` — `$queue->createItem($payload)` with no `numberOfItems()` check. `LangfuseExportWorker.php:163` — `SuspendQueueException` retries indefinitely. | None | dev |
| OBS-3 | L-3 | **YES** | No queue item TTL — stale traces exported after outage recovery | Payload in `LangfuseTerminateSubscriber.php:63-69` has no `enqueued_at` timestamp. Worker at `LangfuseExportWorker.php:80-171` has no age check. | None | stage |
| OBS-4 | — | **YES (new)** | Sentry not integrated — no error tracking or performance monitoring | No `sentry` references in `composer.json` or `settings.php`. | Sentry free tier: 5k errors/month. | stage |

## 2. LLM Resilience

| Ticket | Audit ID | Verified? | Summary | Evidence | Constraints | Target |
|--------|----------|-----------|---------|----------|-------------|--------|
| LLM-1 | F-1 | **YES** | No circuit breaker — 32.5s worst-case PHP blocking during Gemini outage | `LlmEnhancer.php:658` — `makeApiRequest()` while loop. Line 660: `$maxRetries = 2`. Line 675: `'timeout' => 10`. Backoff at line 722: `500 * pow(2, attempt-1) + rand(0,250)ms`. Math: 10s + 0.75s + 10s + 1.25s + 10s = ~32s. `fallback_on_error: true` (config line 73) eventually returns rule-based, but PHP blocked the whole time. | None | dev |
| LLM-2 | T-3 | **YES** | No global LLM call rate limit — per-IP only | Rate limiting at `AssistantApiController.php:335-354` is per-IP via Drupal Flood. No global counter in `LlmEnhancer.php`. `callLlm()` at line 508 has no rate check. | None | dev |
| LLM-3 | F-6 | **YES** | LLM cache key collision — negligible risk, no action needed | `LlmEnhancer.php:518-524` — SHA-256 hash of prompt+model+tokens+temp+POLICY_VERSION. | N/A | N/A |

## 3. Safety / Prompt Injection

| Ticket | Audit ID | Verified? | Summary | Evidence | Constraints | Target |
|--------|----------|-----------|---------|----------|-------------|--------|
| SAF-1 | T-1 | **YES** | Indirect prompt injection via CMS content — no content fence | `LlmEnhancer.php:420-458` — `buildResultContext()` passes raw `full_answer` (line 432) and `description` (line 441) directly into LLM prompt. System prompts at lines 130-149 (`faq_summary`, `resource_summary`) do NOT include "don't follow instructions in the content" guard. No delimiters around retrieved content. | None | dev |
| SAF-2 | T-2 | **YES** | Direct prompt injection — acceptable risk, no action needed | `SafetyClassifier` has `prompt_injection` patterns. `PolicyFilter::sanitizeForLlmPrompt()` caps query. Low-stakes domain (no writes, no data exfiltration). | N/A | N/A |
| SAF-3 | T-4 | **YES** | CSRF protection — properly implemented | `ilas_site_assistant.routing.yml:16` — `_csrf_request_header_token: 'TRUE'` on POST `/assistant/api/message`. Line 90: same on `/assistant/api/track`. **Note:** Audit said `_csrf_token: TRUE` — actual key is `_csrf_request_header_token` (header-based variant). Both are valid Drupal CSRF mechanisms. | N/A | N/A |
| SAF-4 | T-5 | **YES** | XSS protection — properly implemented | `AssistantApiController.php:47-50` — `SECURITY_HEADERS` includes `X-Content-Type-Options: nosniff`, `Cache-Control: no-store`. | N/A | N/A |
| SAF-5 | T-6 | **YES** | Debug mode — server-side env var, not exploitable | `AssistantApiController.php:1175-1177` — `getenv('ILAS_CHATBOT_DEBUG') === '1'`. No client-side parameter. | N/A | N/A |
| SAF-6 | T-7 | **YES** | Conversation ID prediction — negligible risk | `AssistantApiController.php:416` — strict UUID4 regex. 122 bits entropy. | N/A | N/A |

## 4. Privacy / PII

| Ticket | Audit ID | Verified? | Summary | Evidence | Constraints | Target |
|--------|----------|-----------|---------|----------|-------------|--------|
| PII-1 | P-1 | **YES** | Name detection requires "my name is" / "I'm called" prefix — freestanding names not caught | `PiiRedactor.php:136-141` — regex `/(my\s+name\s+is\|i'?m\s+called)\s+[A-Z][a-z]{2,}(\s+[A-Z][a-z]{2,})?/i`. "John Smith needs help with eviction" passes through unredacted. | None | stage |
| PII-2 | P-2 | **YES** | No Idaho driver's license pattern | Not in `PiiRedactor.php` pattern list (lines 40-143). Low risk — DL numbers rarely appear in chatbot queries. | None | stage |
| PII-3 | P-3 | **NO — DISPROVED** | Audit claimed "conversation cache stores raw (unredacted) user messages" — actually already redacted | `AssistantApiController.php:958` — `'text' => PiiRedactor::redactForStorage($user_message, 200)`. Cache writes at lines 979-983 store `$server_history` which contains the redacted text. The audit's reference to lines 418-422 is the cache *read*, not write. | N/A | N/A |
| PII-4 | — | **YES** | Conversation logs properly PII-redacted | `ConversationLogger.php:99-100` — `PiiRedactor::redactForStorage($userMessage, 500)`. Retention at line 162: configurable, default 72h. Batched cleanup at lines 156-208. | N/A | N/A |

## 5. Retrieval / Vector Search

| Ticket | Audit ID | Verified? | Summary | Evidence | Constraints | Target |
|--------|----------|-----------|---------|----------|-------------|--------|
| RET-1 | F-2 | **YES** | Search API fallback to legacy entity queries — already implemented | `ResourceFinder.php:375-381` — `findByType()` checks `$index->status()`, falls back to `findByTypeLegacy()`. | None | N/A |
| RET-2 | — | **YES** | Vector search (Pinecone) disabled but well-implemented | Config line 134-143: `vector_search.enabled: false`. `ResourceFinder.php:619-643` validates cosine metric. Lines 664-715: merge strategy with dedup by node ID. Lines 763-768: >5s latency warning. Lines 820-845: categorized exception handling. | Pinecone free tier: 100k vectors. No Solr/Redis dependency. | prod |
| RET-3 | F-4 | **YES** | Conversation cache eviction under memory pressure — context lost | Cache bin `ilas_site_assistant` in `services.yml:1-7` uses `cache_factory:get`. Database-backed on Pantheon Basic (no Redis). Eviction under memory pressure could lose mid-conversation context. | Redis not available on Pantheon Basic. Database cache is the only option. | stage |

## 6. Multilingual

| Ticket | Audit ID | Verified? | Summary | Evidence | Constraints | Target |
|--------|----------|-----------|---------|----------|-------------|--------|
| I18N-1 | Phase 2.3 | **YES** | LLM prompts English-only — Spanish users get unpredictable responses | System prompts at `LlmEnhancer.php:90-194` are all English. No language detection before LLM call. `callLlm()` at line 508 has no language parameter. FAQ/resource retrieval does filter by `search_api_language` (verified in ResourceFinder). | None | prod |

## 7. Evaluation

| Ticket | Audit ID | Verified? | Summary | Evidence | Constraints | Target |
|--------|----------|-----------|---------|----------|-------------|--------|
| EVAL-1 | — | **YES (new)** | No offline evaluation harness for intent routing accuracy | `AnalyticsLogger::logNoAnswer()` stores SHA-256 hashes — not reversible for eval. Need to build export + test framework. | None | prod |

## 8. Re-audit remediation

| Ticket | Audit ID | Verified? | Summary | Evidence | Constraints | Target |
|--------|----------|-----------|---------|----------|-------------|--------|
| RAUD-03 | C2 / F-15 | **YES (implemented in repo)** | Vertex service-account JSON is no longer accepted in the assistant admin UI or exportable via Drupal config. Runtime secret injection is the only supported credential source. | `AssistantSettingsForm.php`, `LlmEnhancer.php`, `settings.php`, `key.key.vertex_sa_credentials.yml`, `VertexRuntimeCredentialGuardTest.php` | Pantheon/runtime verification still requires deploy-time read-only checks. | next deploy |
| RAUD-05 | C3 / C4 / LLM-1 | **YES (implemented in repo)** | Vertex access tokens are now cached with buffered TTLs, and LLM transport retry/backoff is bounded to one retry with `<=250ms` synchronous delay. | `LlmEnhancer.php`, `ilas_site_assistant.settings.yml`, `LlmEnhancerHardeningTest.php`, `raud-05-llm-transport-hardening.txt` | Verified locally; live environments still keep `llm.enabled=false` pending rollout governance. | next deploy |

---

## Prioritized Implementation Order

### P0 — Before enabling Langfuse/LLM (Week 1-2)

| Ticket | Fix | File(s) |
|--------|-----|---------|
| **OBS-1** | Change `'type' => 'trace-create'` to `'type' => 'trace-update'` at line 455 | `LangfuseTracer.php` |
| **OBS-2** | Add `$queue->numberOfItems() < 10000` check before `createItem()` | `LangfuseTerminateSubscriber.php` |
| **LLM-1** | Add circuit breaker: track consecutive failures in Drupal State, skip LLM for 5min cooldown after 3 failures in 60s | `LlmEnhancer.php` |
| **LLM-2** | Add global LLM rate limit (500/hr) via Drupal Flood or State | `LlmEnhancer.php` |

### P1 — Safety hardening (Week 3-4)

| Ticket | Fix | File(s) |
|--------|-----|---------|
| **SAF-1** | Wrap retrieved content in `<retrieved_content>` delimiters + add system prompt guard | `LlmEnhancer.php` |
| **OBS-3** | Add `enqueued_at` to queue payload, discard items >1hr in worker | `LangfuseTerminateSubscriber.php`, `LangfuseExportWorker.php` |

### P2 — Privacy + observability (Week 5-8)

| Ticket | Fix | File(s) |
|--------|-----|---------|
| **PII-1** | Add contextual name patterns (after "client"/"tenant"/"applicant") | `PiiRedactor.php` |
| **PII-2** | Add Idaho DL pattern `[A-Z]{2}\d{6}[A-Z]` | `PiiRedactor.php` |
| **OBS-4** | Install `sentry/sentry-php`, configure in `settings.php` with PII scrubbing | `composer.json`, `settings.php` |

### P3 — Retrieval + multilingual + eval (Week 9-12)

| Ticket | Fix | File(s) |
|--------|-----|---------|
| **RET-2** | Enable Pinecone vector search in staging, verify index + embeddings | Config |
| **RET-3** | Evaluate conversation cache loss frequency; document or switch to database cache bin | `services.yml` |
| **I18N-1** | Add language detection before LLM call; skip or prepend language instruction | `LlmEnhancer.php` |
| **EVAL-1** | Build offline eval harness for no-answer queries | New script |

### No Action Required

| Ticket | Reason |
|--------|--------|
| **LLM-3** | SHA-256 collision risk negligible |
| **SAF-2** | Acceptable risk for low-stakes domain |
| **SAF-3** | CSRF properly implemented |
| **SAF-4** | XSS headers properly implemented |
| **SAF-5** | Debug mode server-side only |
| **SAF-6** | UUID4 not guessable |
| **PII-3** | **DISPROVED** — PII already redacted before cache write |
| **PII-4** | Conversation logging already properly redacted |
| **RET-1** | Search API fallback already works |
| **F-5** | Flood cleanup handled by Drupal core cron |

---

## Corrected Line References (Audit Errata)

| Audit Ref | Claimed | Actual | Note |
|-----------|---------|--------|------|
| F-1 evidence | `LlmEnhancer.php:672` | `LlmEnhancer.php:675` (timeout), `:658` (method start), `:660` (maxRetries) | Line numbers shifted |
| T-4 | `_csrf_token: TRUE` | `_csrf_request_header_token: 'TRUE'` | Different Drupal CSRF mechanism (header-based, not token param) |
| P-3 evidence | `AssistantApiController.php:418-422` "raw text stored" | Line 958: `PiiRedactor::redactForStorage($user_message, 200)` — already redacted | **Finding disproved** |
| L-1 evidence | `LangfuseTracer.php:455` | Confirmed accurate | — |
