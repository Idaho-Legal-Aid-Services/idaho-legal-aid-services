# Security Review Findings

Custom-code security review for idaho-legal-aid-services (Drupal 11).
Scope: `web/modules/custom/`, `web/themes/custom/`. Core/contrib out of scope
per GLOBAL RULES. This file is **append-only**: never rewrite or reorder prior
findings. One entry per finding.

## Schema (per finding)

- **ID:** SR-### (sequential, zero-padded, never reused)
- **Severity:** CRITICAL / HIGH / MEDIUM / LOW / INFO
- **Location:** path:line
- **Rubric:** pass number + rubric item
- **Evidence:** ≤5-line snippet
- **Rationale:** why this is exploitable or risky
- **Remediation:** suggested fix (do NOT apply — remediation is a later session)

Severity guidance:
- CRITICAL — remotely exploitable by anonymous users, or full compromise.
- HIGH — exploitable with limited privileges, or significant data exposure.
- MEDIUM — requires elevated privileges / unusual conditions, or defense-in-depth gap.
- LOW — hardening / best-practice deviation with limited impact.
- INFO — observation, no direct exploit; context for future passes.

---

## Findings

### SR-001
- **Severity:** LOW
- **Location:** web/modules/custom/ilas_site_assistant/src/Controller/AssistantApiController.php:8325 (route: ilas_site_assistant.routing.yml:75)
- **Rubric:** Pass 2A / 2.4 (CSRF) + 2.3
- **Evidence:**
  ```php
  private function evaluateTrackWriteProof(Request $request): array {
    if ($request->headers->has('Origin')) {
      $origin = trim((string) $request->headers->get('Origin', ''));
      if ($this->isSameOriginUrl($origin, $request)) {
        return ['allowed' => TRUE, 'mode' => 'origin',];
  ```
- **Rationale:** `POST /assistant/api/track` has `_access: 'TRUE'` and no routing-level CSRF requirement. Its write proof accepts a same-origin `Origin` or `Referer` header alone; a session-bound `X-CSRF-Token` is required only when both browser headers are absent. Browsers cannot forge Origin/Referer cross-site, so CSRF is covered — but any **non-browser client** (curl/bot) can trivially set a matching Origin header and write allowlisted analytics events anonymously, polluting `ilas_site_assistant_stats` and injecting `feedback.received` events into Langfuse traces for any guessable/known `response_request_id` (UUIDv4, so guessing is impractical; known IDs from a shared response are feasible). Impact is limited to analytics/observability integrity: event types are allowlisted, values pass `sanitizeInput()` (500-char cap, tags stripped), and flood control caps 60/min per IP.
- **Remediation:** Require the session-bound `X-CSRF-Token` for all `/track` writes (browser proof as an additional signal, not a substitute), or require a started session cookie alongside Origin/Referer proof. Alternatively accept the risk explicitly as an analytics-only endpoint — document that stats/Langfuse feedback data is client-assertable.

### SR-002
- **Severity:** INFO
- **Location:** web/modules/custom/ilas_site_assistant/ilas_site_assistant.routing.yml:15,25,33,41,81
- **Rubric:** Pass 2A / 2.3 (routing access — mandatory flag of every `_access: 'TRUE'`)
- **Evidence:**
  ```yaml
  ilas_site_assistant.api.message:   requirements: { _access: 'TRUE', _csrf_request_header_token: 'TRUE', _ilas_strict_csrf_token: 'TRUE' }
  ilas_site_assistant.api.session_bootstrap: requirements: { _access: 'TRUE' }   # GET
  ilas_site_assistant.api.suggest:   requirements: { _access: 'TRUE' }           # GET
  ilas_site_assistant.api.faq:       requirements: { _access: 'TRUE' }           # GET
  ilas_site_assistant.api.track:     requirements: { _access: 'TRUE' }           # POST — see SR-001
  ```
- **Rationale:** Five routes are open to anonymous users by design (public chatbot). Compensating controls verified: `message` POST enforces dual CSRF (core `_csrf_request_header_token` + custom `StrictCsrfRequestHeaderAccessCheck`, which validates the session token and fails closed) plus per-IP flood limits, 2000-byte body cap, content-type check, and `sanitizeInput()`. `session_bootstrap` GET is rate-limited by `AssistantSessionBootstrapGuard` and returns a no-store session-bound token. `suggest`/`faq` are read-only, guarded by `AssistantReadEndpointGuard` rate limiting, string-cast + sanitized query params, and public-field output filtering. `track` relies on origin proof (SR-001). The two custom access checks (`_ilas_strict_csrf_token`, `_ilas_diagnostics_access`) were opened and both perform real checks — the diagnostics check requires the `view ilas site assistant reports` permission or a `hash_equals()` comparison against a Settings/env token, denying when unconfigured.
- **Remediation:** None required; record retained per rubric. Re-verify compensating guards (`AssistantSessionBootstrapGuard`, `AssistantReadEndpointGuard`) in Pass 2D and the full access-control picture in Pass 4.

### SR-003
- **Severity:** INFO
- **Location:** web/modules/custom/ilas_site_assistant/src/Controller/AssistantApiController.php:1695,1900,4044
- **Rubric:** Pass 2A / 2.2 (raw request input) — forwarded-header trust
- **Evidence:**
  ```php
  $effective_client_ip = (string) ($request->getClientIp() ?? $request->server->get('REMOTE_ADDR', ''));
  ...
  $ip = (string) ($trust_context['effective_client_ip'] ?? '');
  $flood_id = 'ilas_assistant:' . $ip;
  ```
- **Rationale:** All rate-limit/flood identities key off `Request::getClientIp()`, whose value is only as trustworthy as the platform's trusted-proxy configuration (Pantheon edge → `settings.pantheon.php`). If trusted proxies are misconfigured, `X-Forwarded-For` becomes attacker-controlled and per-IP flood limits (message 15/min, track 60/min) can be rotated at will. The controller does not trust forwarded headers itself — it inspects the chain and logs `flood_identity` warnings on suspicious mismatches (`shouldLogFloodTrustWarning()`), which is good defense-in-depth, but detection is log-only. Not a code defect in this module; the binding risk lives in platform settings (out of 2A scope).
- **Remediation:** Verify trusted-proxy configuration in the settings-file review (Pass 1) and access-control pass (Pass 4); cross-reference the July 2026 Sentry trusted-proxy gap audit.

### SR-004
- **Severity:** INFO
- **Location:** web/modules/custom/ilas_site_assistant/src/Controller/AssistantSessionBootstrapController.php:47-82 (route: ilas_site_assistant.routing.yml:19)
- **Rubric:** Pass 2A / 2.4 (state-changing GET)
- **Evidence:**
  ```php
  if (!$session->isStarted()) { $session->start(); }
  if (!$request->hasPreviousSession() || !$session->has(self::SESSION_MARKER_KEY)) {
    $session->set(self::SESSION_MARKER_KEY, (string) time());
  }
  $token = $this->csrfToken->get(CsrfRequestHeaderAccessCheck::TOKEN_KEY);
  ```
- **Rationale:** `GET /assistant/api/session/bootstrap` mutates state (starts an anonymous session, writes a session marker) and returns a CSRF token — flagged per rubric as a state-changing GET. This is the intentional and standard pattern for CSRF-token distribution to anonymous clients: the response is `no-store` + page-cache kill-switched, the token is session-bound (useless cross-site under same-origin policy since no CORS grant exists), and `AssistantSessionBootstrapGuard` rate-limits bootstrap attempts (429 with Retry-After). No exploitable defect identified.
- **Remediation:** None. Guard internals re-checked in Pass 2D.

### SR-005
- **Severity:** MEDIUM
- **Location:** web/modules/custom/ilas_site_assistant/src/Service/FaqIndex.php:908-932 (getById), :1775-1814 (loadLegacySearchItems) → reached from AssistantApiController.php:4999 (`GET /assistant/api/faq?id=faq_N`)
- **Rubric:** Pass 2B / 2.5 (entity/field access)
- **Evidence:**
  ```php
  public function getById(string $id) {
    if (preg_match('/^faq_(\d+)$/', $id, $matches)) {
      $paragraph_id = $matches[1];
      $paragraph = $this->entityTypeManager->getStorage('paragraph')->load($paragraph_id);
      if ($paragraph) { $item = $this->buildResultItemFromParagraph($paragraph); return ...; }
  ```
- **Rationale:** The FAQ-by-ID path loads a paragraph directly by numeric ID with no access/publication check. `buildResultItemFromParagraph()` → `getParentInfo()` (FaqIndex.php:835) walks to the parent node and returns its title/url/topics **without checking `$parent->isPublished()`**; the only gates applied are bundle (`faq_item`/`accordion_item`) and language. An anonymous user can enumerate `GET /assistant/api/faq?id=faq_1,2,3…` and retrieve question/answer text belonging to **unpublished or draft** parent nodes — an IDOR-style exposure that bypasses the search-index path (which only indexes published content). The keyword-driven `loadLegacySearchItems()` fallback shares the same gap: its `queryLegacyParagraphIdsByBundle()` entity query has no parent-status condition, so matching draft FAQ content can surface via the public `q=` search too. Impact is bounded — content is FAQ-style legal information rather than PII/credentials, and output passes `filterFaqForPublicApi()` — but pre-publication/editorially-withheld answers leaking to anonymous users is a real access-control defect. Contrast the sibling node/media queries in `ResourceFinder` (lines 396, 672, 2295) which correctly pair `accessCheck(TRUE)` with `->condition('status', 1)`.
- **Remediation:** In `getById()` and `buildResultItemFromParagraph()`/`getParentInfo()`, resolve the parent node and return NULL unless `$parent->isPublished()` (and, ideally, `$parent->access('view')`). For the legacy search path, add a parent-status guard when assembling results (or filter in `queryLegacyParagraphIdsByBundle` via a join/condition on the host node's status). Prefer routing all FAQ reads through the search index, which already excludes unpublished content.

### SR-006
- **Severity:** INFO
- **Location:** web/modules/custom/ilas_site_assistant/src/Service/OfficeDirectory.php:245-249
- **Rubric:** Pass 2B / 2.5 (mandatory flag of every `accessCheck(FALSE)`)
- **Evidence:**
  ```php
  $nids = $storage->getQuery()
    ->accessCheck(FALSE)
    ->condition('type', 'office_information')
    ->condition('status', NodeInterface::PUBLISHED)
    ->execute();
  ```
- **Rationale:** Sole `accessCheck(FALSE)` in the 2B set. Justified: the query is a fixed lookup of **published** `office_information` nodes (public directory data — addresses/phones already shown site-wide), with no user-controlled conditions. `accessCheck(FALSE)` here ensures the office list renders consistently regardless of the viewing account, and the explicit `status = PUBLISHED` condition prevents draft leakage. No exploitable exposure — recorded per rubric because every `accessCheck(FALSE)` must be flagged and justified.
- **Remediation:** None required; the `status` condition already bounds it to public content.

### SR-007
- **Severity:** INFO
- **Location:** web/modules/custom/ilas_site_assistant/src/Service/ResourceFinder.php:2291-2327, FaqIndex.php:1831-1863, TopicRouter.php:376-382
- **Rubric:** Pass 2B / 2.1 (SQLi), 2.6 (file), 2.8 (SSRF)
- **Evidence:**
  ```php
  $match_group->condition('title', $normalized_query, 'CONTAINS');
  $match_group->condition($field . '.value', $keyword, 'CONTAINS');  // $field from a hardcoded literal array
  $yaml_content = file_get_contents(__DIR__ . '/../../config/routing/topic_map.yml');
  ```
- **Rationale:** No injection surface confirmed across the retrieval services. (2.1) All DB access uses the entity-query builder or search_api `->query()`; `CONTAINS` conditions place the user query/keywords in the **value** position (parameterized), and every field **name** is a hardcoded literal (`ResourceFinder` uses static field strings; `FaqIndex::queryLegacyParagraphIdsByBundle` receives `$fields` only from the two literal arrays at FaqIndex.php:1782-1789). No raw SQL, no `db_query`, no user-controlled operators/columns. (2.6) The single file op is `TopicRouter` reading a fixed in-module YAML path with no user input. (2.7) No `unserialize`/`call_user_func`/`eval`/dynamic instantiation. (2.8) The two Guzzle references are exception-class string matches in error handling, not outbound calls; vector/lexical search runs through search_api provider services (Pinecone/Solr transport reviewed in Pass 2C), so no user-controlled URL is constructed in these services. (2.2) None of the 2B services read the HTTP request directly — inputs arrive as already-sanitized method arguments from the controllers.
- **Remediation:** None; documented as clean baseline for the retrieval/ranking/routing layer.

### SR-008
- **Severity:** INFO
- **Location:** web/modules/custom/ilas_site_assistant/src/Service/LangfuseTraceLookupService.php:118-135,218 (reached only from src/Commands/LangfuseLookupCommands.php:44)
- **Rubric:** Pass 2C / 2.8 (SSRF)
- **Evidence:**
  ```php
  $host = rtrim($config->get('langfuse.host') ?? 'https://us.cloud.langfuse.com', '/');
  $url  = $host . '/api/public/traces/' . rawurlencode($traceId);
  $response = $this->httpClient->request('GET', $url, [
    'auth' => [$publicKey, $secretKey], ...
  ]);
  ```
- **Rationale:** The only outbound URL in the 2C set whose host is not a hardcoded constant. `langfuse.host` comes from `ilas_site_assistant.settings` config and is used as the base for a Guzzle GET that carries the Langfuse public/secret keys via HTTP basic `auth`. If an attacker could write that config key, credentials would egress to an attacker host (credential-exfil-flavored SSRF). Mitigating factors: the config is editable only through `administer ilas site assistant` (a `restrict access: true` permission — see SR-002), the method is reachable **only via a Drush CLI command** (`LangfuseLookupCommands`), never from an HTTP route, and the `traceId` path segment is `rawurlencode()`d so it cannot alter the host/path structure. Not anonymously exploitable; recorded as a config-trust observation.
- **Remediation:** Optionally validate `langfuse.host` against an allowlist (or require `https://` + a known Langfuse domain) at config-set time, so a mistaken/hostile host value can't redirect credentialed requests. Cross-reference Pass 5 (secrets handling) for the key-egress angle.

### SR-009
- **Severity:** INFO
- **Location:** web/modules/custom/ilas_site_assistant/src/Service/CohereLlmTransport.php:15,60,115; VoyageReranker.php:28,183; LlmEnhancer.php:95,474; LangfuseTracer.php (no egress)
- **Rubric:** Pass 2C / 2.8 (SSRF), 2.2 (input), plus secret-handling cross-ref
- **Evidence:**
  ```php
  private const API_ENDPOINT = 'https://api.cohere.com/v2/chat';      // CohereLlmTransport
  const API_ENDPOINT = 'https://api.voyageai.com/v1/rerank';          // VoyageReranker
  'headers' => ['Authorization' => 'Bearer ' . $api_key, ...],        // key from Settings::get, never logged
  ```
- **Rationale:** Clean baseline for the LLM/net-I/O layer. (2.8) Both provider transports POST to **hardcoded constant** endpoints; the user's message/query and candidate documents travel only in the JSON request **body**, never in the URL, so no user input can redirect the request. `LlmEnhancer` builds no URL itself — it delegates to `CohereLlmTransport`. `LangfuseTracer` performs **no** direct HTTP egress (no client/curl/socket); it assembles trace payloads for a separate export path (reviewed with the export worker / contrib SDK elsewhere). (Secrets) API keys are resolved from `Settings::get()` / `LlmRuntimeConfigResolver` and sent as `Bearer`/basic-auth headers; error logging uses `ObservabilityPayloadMinimizer::exceptionSignature()` and never interpolates the key. (2.2) None of these services read the HTTP request directly. (2.1/2.5/2.6/2.7) The remaining 2C services (`LlmAdmissionCoordinator`, `LlmRateLimiter`, `LlmCircuitBreaker`, `CohereGenerationProbe`, `ProviderHealthCheck`, `LlmRuntimeConfigResolver`, `VectorIndexHygieneService`, `RuntimeTruthSnapshotBuilder`) are State-API + config readers with no SQL, entity queries, file ops, deserialization, or dynamic calls.
- **Remediation:** None; documented as clean baseline for the transport/provider/net-I/O layer.

### SR-010
- **Severity:** LOW
- **Location:** web/modules/custom/ilas_site_assistant/src/Service/GapReviewDecider.php:64-108 (reached from AssistantApiController.php:3473, route `ilas_site_assistant.api.message`, anonymous POST)
- **Rubric:** Pass 2D / 2.2 (raw request input) + access-integrity
- **Evidence:**
  ```php
  public static function shouldRecordGapItem(array $response, array $intent, array $governance_context, Request $request, array $request_payload = []): bool {
    if (!self::isTrueNoMatchFallback($response)) { return FALSE; }
    return !self::isPromptfooEvalRequest($request, $request_payload);   // <-- client-controlled
  }
  public static function isPromptfooEvalRequest(Request $request, array $request_payload = []): bool {
    if (trim((string) $request->headers->get('X-ILAS-Eval-Run-ID', '')) !== '') { return TRUE; }
    // ...also context.eval_run_id / evalRunId / promptfoo_eval / promptfooEval
  ```
- **Rationale:** Whether a dead-end/"no-match" interaction is written to the governance gap-review queue is gated on `isPromptfooEvalRequest()`, which trusts an **unauthenticated, client-supplied** signal: the `X-ILAS-Eval-Run-ID` request header or an `eval_run_id`/`promptfoo_eval` key in the JSON `context`. The `/assistant/api/message` endpoint is anonymous (SR-002), so any visitor can set that header/field and cause their unanswered queries to be silently excluded from the gap queue. That queue feeds the monthly *no-legal-advice compliance audit* (the `approve ilas site assistant audit` restrict-access permission signs off on it), so the bypass lets an anonymous client suppress records from a compliance dataset with no authentication and no detection. Impact is bounded to governance/audit **completeness** — no data exposure, no user-facing harm, and an attacker gains nothing but the non-recording of their own dead-ends — hence LOW, though the compliance-audit angle is why it is worth fixing rather than accepting.
- **Remediation:** Authenticate the eval signal instead of trusting a spoofable header: gate eval-mode on the same diagnostics token used elsewhere (`X-ILAS-Observability-Key` / `hash_equals`, see `AssistantDiagnosticsAccessCheck`), or restrict eval classification to non-production environments (`EnvironmentDetector::isDevOrTestEnvironment()`). At minimum, record the gap item regardless and tag it `eval=true` so audit completeness is preserved.

### SR-011
- **Severity:** INFO
- **Location:** web/modules/custom/ilas_site_assistant/src/Plugin/QueueWorker/LangfuseExportWorker.php:134-156; src/Commands/LangfuseProbeCommands.php:228-235
- **Rubric:** Pass 2D / 2.8 (SSRF) — extends SR-008
- **Evidence:**
  ```php
  $host = rtrim($config->get('langfuse.host') ?? 'https://us.cloud.langfuse.com', '/');
  $url  = $host . '/api/public/ingestion';
  $response = $this->httpClient->request('POST', $url, ['auth' => [$publicKey, $secretKey], 'json' => $batch, ...]);
  ```
- **Rationale:** Two more consumers of the config-driven `langfuse.host` identified in 2D. `LangfuseExportWorker` is the **cron queue worker** that actually flushes the trace batches `LangfuseTracer` enqueues (this is why the tracer itself showed no direct egress in 2C) — it POSTs queued observability payloads, with Langfuse basic-auth credentials, to `langfuse.host`. `LangfuseProbeCommands` is a Drush CLI probe using the same host. Same trust boundary as SR-008: the host is admin-only config (`administer ilas site assistant`, restrict-access), the path segment is a fixed literal, and neither is reachable from an anonymous HTTP route (queue worker runs under cron; command is CLI). No anonymous SSRF. Payload content is observability data assembled by `ObservabilityPayloadMinimizer` (PII handling reviewed in Pass 5).
- **Remediation:** Same as SR-008 — validate `langfuse.host` against an allowlist / require a known Langfuse HTTPS domain at config-set time, so all three egress sites (lookup, export worker, probe) inherit the guard.

### SR-012
- **Severity:** INFO
- **Location:** web/modules/custom/ilas_redirect_automation/src/Service/PathMatcherService.php:490-508
- **Rubric:** Pass 2E / 2.5 (mandatory flag of every `accessCheck(FALSE)`)
- **Evidence:**
  ```php
  $query = $nodeStorage->getQuery()
    ->accessCheck(FALSE)
    ->condition('status', 1)
    ->condition('type', 'resource')
    ->condition('title', '%' . $searchTerms . '%', 'LIKE');
  ```
- **Rationale:** Two `accessCheck(FALSE)` entity queries (the only ones in the module). Justified: this is a **Drush-CLI-only** redirect-proposal tool with no web routes (the module ships no `*.routing.yml`/`*.permissions.yml`). Both queries load only **published** (`status = 1`) `resource` nodes to suggest redirect targets; the `title` LIKE value is bound as a parameter by the entity-query API (no SQLi), and running under CLI there is no user session to gate against. No privilege boundary is crossed. Recorded per rubric because every `accessCheck(FALSE)` must be flagged and justified.
- **Remediation:** None required; CLI-only + published-content scope bound the exposure.

### SR-013
- **Severity:** INFO
- **Location:** web/modules/custom/ilas_redirect_automation/src/Service/{CsvExportService.php:63-120, RedirectApplierService.php:104-230,245-271, PathMatcherService.php:281-324}; src/Commands/RedirectAutomationCommands.php
- **Rubric:** Pass 2E / 2.1 (SQLi), 2.6 (file), open-redirect
- **Evidence:**
  ```php
  if (!$skipValidation && !$this->validateDestination($destination)) { /* skip */ }   // apply flow gate
  $destinationUri = 'internal:' . $destination;   // redirect target always internal:/entity: prefixed
  ->condition('alias', '%' . $this->database->escapeLike($aliasWithSlash), 'LIKE');
  $handle = fopen($filepath, 'w');   // $filepath = operator --output CLI option
  ```
- **Rationale:** Clean baseline for the redirect-automation module. (2.1) All DB access uses the query builder: `->condition()` values are parameterized, `LIKE` patterns pass through `Connection::escapeLike()`, and the `->join(...)` predicates are hardcoded literal strings with no interpolated identifiers — no raw SQL, no `db_query`, no dynamic table/column names. (Open-redirect) `createRedirect()` is reached only after `applyFromEntries()` calls `validateDestination()`, which requires the destination to resolve to a published node/term, an active `path_alias`, or an existing internal file — external or protocol-relative targets fail all four checks; additionally every created redirect URI is forced to an `internal:`/`entity:` prefix, so an off-site redirect cannot be produced. `$skipValidation` is an explicit CLI flag (deliberate operator opt-in). (2.6) CSV read/write (`fopen`/`fputcsv`/`fgetcsv`) uses a `$filepath` supplied as the `--output`/input path of a **Drush command** — operator-controlled, CLI-only, run by someone who already holds filesystem access, so no privilege boundary is crossed (path-traversal is not meaningful for a shell operator). `validateDestination()`'s `file_exists(DRUPAL_ROOT . $path)` is an existence check only, gated behind a trailing known-extension regex. (2.7/2.8) No deserialization, dynamic calls, or outbound HTTP anywhere in the module. (2.3/2.4/2.9) n/a — no web routes, CSRF surface, or permissions.
- **Remediation:** None; documented as clean baseline. Optionally constrain the CSV `--output` path to a known directory (e.g. the public/private scheme) for defense-in-depth, though the CLI-only reach makes this low priority.

### SR-014
- **Severity:** INFO
- **Location:** web/modules/custom/ilas_seo/src/StructuredData/GraphBuilder.php:150,200,211,605; src/EventSubscriber/CspEnhancementSubscriber.php:66-74; ilas_seo.module:82,321
- **Rubric:** Pass 2F / 2.2 (request input), header injection, JSON-LD output-encoding (Pass-3 cross-ref)
- **Evidence:**
  ```php
  $current_url = strtok($base_url . $request->getRequestUri(), '?');   // reflected request path
  '@id' => $current_url . '#breadcrumb',
  '#value' => Json::encode($schema),                                   // JSON_HEX_TAG|AMP|APOS|QUOT
  $policy .= '; ' . $directive . ' ' . $value;                         // $directive/$value are constants
  $page = $request->query->get('page');  if (!empty($page) && is_numeric($page) && (int) $page > 0) { … }
  ```
- **Rationale:** Clean baseline for the SEO module — no injection sink reached. The module has **no routes/permissions** (hook-driven via `hook_page_attachments[_alter]`, `hook_metatags_alter`), so 2.3/2.4/2.9 are n/a, and it contains no SQL, entity queries, file ops, deserialization, dynamic calls, or outbound HTTP. Three points warranted tracing: (1) **Request-URI reflection** — `GraphBuilder` embeds the current request path into the BreadcrumbList JSON-LD `item`/`@id`, but the block is emitted via Drupal's `Json::encode()`, which sets `JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT`, so a crafted `</script>` in the path is escaped to `</script>` and cannot break out of `<script type="application/ld+json">`. (2) **CSP header manipulation** — `CspEnhancementSubscriber` appends only **hardcoded literal** directives (`base-uri`, `form-action`, `worker-src` from a `const` array) to SecKit's existing policy; no request/user data enters the header, so no response-splitting/CSP-injection. (3) **Pager/filter params** — `?page=`/`?keys=`/etc. are consumed only in `is_numeric()`/`has()` boolean checks that set `$should_noindex`; never reflected into output or a URL. Positive controls: `Json::encode()` HEX-flag escaping for all JSON-LD, and constant-only CSP directives.
- **Remediation:** None. Cross-referenced to Pass 3 (XSS) for the JSON-LD/breadcrumb output path; no change needed there given the `Json::encode()` escaping.

### SR-015
- **Severity:** LOW
- **Location:** web/modules/custom/employment_application/src/Controller/EmploymentApplicationController.php:2273-2353 (saveDraft), :2392-2452 (sendDraftResumeEmail); route `employment_application.draft_save` (POST, `_access: 'TRUE'`)
- **Rubric:** Pass 2G / 2.2 (raw request input) + access-integrity
- **Evidence:**
  ```php
  $email = trim($body['email']);                          // attacker-supplied recipient
  $existing = $this->database->select('employment_application_drafts','d')
    ->fields('d', ['id','resume_token'])->condition('email', $email)->execute()->fetchObject();
  if ($existing) { /* UPDATE victim's draft form_data with attacker data */ $resumeToken = $existing->resume_token; }
  else { $resumeToken = bin2hex(random_bytes(32)); /* INSERT */ }
  $this->sendDraftResumeEmail($email, $resumeToken, $formData);   // emails ANY address
  ```
- **Rationale:** `POST /employment-application/draft/save` is anonymous. Its CSRF token (`employment_application_form`) is dispensed by the public `getToken` endpoint, so it is an anti-CSRF check, **not** an authorization barrier — a script can fetch a token and then submit drafts freely. Because drafts are keyed solely by `email`, an attacker who supplies an arbitrary victim address can (a) cause the site to send a "Resume Your Application" email from the trusted `idaholegalaid.org` domain to **any** address on demand — an unauthenticated arbitrary-recipient email primitive with a small attacker-controlled `applicant name` string shown in the greeting; and (b) if a draft already exists for that email, **overwrite the victim's saved `form_data`** with attacker-supplied JSON (integrity tampering / draft destruction). Mitigating factors that keep this LOW: the `resume_token` is a 256-bit CSPRNG value that is emailed only to the victim and never returned to the attacker, so there is **no PII disclosure** and the attacker cannot read the victim's draft; the email is a fixed template; and saves are rate-limited to 10/hour/IP (though IP rotation weakens this). The main residual risk is sender-reputation abuse / harassment via the arbitrary-recipient email, plus draft-integrity tampering.
- **Remediation:** Don't key drafts by a client-supplied email alone. Prefer binding the draft to the issued CSRF/session identity (or a server-issued draft id), and only email a resume link after the address is confirmed to belong to the requester (e.g. double opt-in), or throttle per-email (not just per-IP) and suppress re-sending to an address that already has an unconfirmed draft. At minimum, do not overwrite an existing draft's `form_data` from an unauthenticated request that hasn't proven ownership of the resume token. Note: when the overwritten `form_data` is later reloaded into the form, verify the client renders it via value assignment (not `innerHTML`) — cross-reference Pass 3.

### SR-016
- **Severity:** INFO
- **Location:** web/modules/custom/employment_application/src/Controller/EmploymentApplicationController.php:574-780 (submitApplication preamble), :2248-2268 (updateStatus), :2457-2524 (downloadFile), :2358-2387 (loadDraft), :1337-1399 (file validation/save); src/Service/ApplicationValidator.php:18-303; employment_application.routing.yml; employment_application.permissions.yml
- **Rubric:** Pass 2G / 2.1, 2.3, 2.4, 2.5, 2.6, 2.9 (positive-control baseline)
- **Evidence:**
  ```php
  if (empty($formNonce) || empty($sessionNonce) || !hash_equals($sessionNonce, $formNonce)) { … }   // single-use session nonce
  if (!$this->csrfToken->validate($formToken, 'employment_application_form')) { … }                  // CSRF on public submit
  if (!$this->csrfToken->validate($csrfToken, 'employment_application_admin')) { throw … }            // CSRF on admin POST
  if (strpos($uri, 'private://') !== 0) { throw new AccessDeniedHttpException(…); }                    // download scheme lock
  $validation = $this->validateUploadedFile($uploadedFile, $correlationId);                           // ext+MIME+magic+size
  ```
- **Rationale:** Strong security baseline for the PII intake pipeline. (2.4/anti-bot) `submitApplication` layers multi-tier flood limits (unvalidated/burst/hour/global, with only the unvalidated counter registered pre-validation so bots can't exhaust legitimate users' budget), content-type validation, a `fax_number` honeypot, a server-side time-gate, a **single-use session nonce** verified with `hash_equals()`, and CSRF-token validation. The admin `updateStatus` POST validates a distinct `employment_application_admin` CSRF token and allowlists the status value. (2.5/IDOR) `downloadFile` binds `{fid}` to `{id}` (logging IDOR attempts), forces the `private://` scheme, and serves via a managed `File` entity — no path traversal; all admin file routes sit behind `administer employment applications` (restrict access). (2.6/upload) uploads are gated by extension allowlist (pdf/doc/docx) + per-extension MIME allowlist + magic-byte sniffing for `octet-stream` + size caps, then stored under a regenerated `[a-zA-Z0-9_-]`-sanitized filename in `private://`, defeating double-extension/`.php` tricks. (2.1) every query uses the parameterized builder; stored strings pass `Xss::filter()`; IPs are salted-hashed, not stored raw. (2.3/2.9) all `/admin/*` routes require the restrict-access permission; the two public GET reads are UUID-/status-constrained; `loadDraft` authorizes purely by a 256-bit CSPRNG capability token (brute-force-infeasible, so the absence of per-token rate limiting is acceptable).
- **Remediation:** None; documented as positive baseline. Only the `saveDraft` email-keying gap (SR-015) departs from this otherwise solid posture.

### SR-017
- **Severity:** INFO
- **Location:** web/modules/custom/ilas_site_assistant_governance/src/Entity/AssistantGapItem.php:194-211 (canTransition); Form/AssistantGapItemBulkDispositionForm.php:193; Controller/GapReviewWorkflowController.php:58-64; Service/ReviewedGapPromptfooCandidateExporter.php:67; ilas_site_assistant_governance.post_update.php:187,236; Commands/ReviewedGapPromptfooExportCommands.php:76-96; ilas_site_assistant_governance.permissions.yml
- **Rubric:** Pass 2H / 2.1, 2.3, 2.5, 2.6, 2.9 (positive-control baseline)
- **Evidence:**
  ```php
  public static function canTransition(string $from, string $to, AccountInterface $account): bool {
    if ($account->hasPermission('administer assistant gap items')) { return TRUE; }
    $allowed = self::transitionMap()[$from] ?? [];
    if (!in_array($to, $allowed, TRUE)) { return FALSE; }
    return match ($to) { self::STATE_RESOLVED => $account->hasPermission('transition assistant gap items to resolved'), … };
  }
  // bulk form submit loop:
  if (!AssistantGapItem::canTransition($item->getReviewState(), $target_state, $this->currentUser())) { continue; }
  ```
- **Rationale:** Clean baseline for the governance module. The one item worth scrutiny — the **bulk resolve/archive** routes (`gap_bulk_resolve_confirm`/`gap_bulk_archive_confirm`) are gated at the route level only by `view assistant gap items`, yet they perform state transitions that individually require `transition … to resolved/archived`. This is **not** an authz gap: the actual mutation is gated by `AssistantGapItem::canTransition()`, which enforces both the state-machine map and the specific per-target permission (or `administer` superuser), applied at every layer — the bulk-action plugin's `access()` (items enter a *per-user* `PrivateTempStore` only after passing it), the confirm form's submit loop (line 193 re-checks `canTransition` per item and `continue`s otherwise), and the `startReviewAccess` custom callback (entity `access('update')` + state==NEW + `canTransition`, with the route also requiring `_csrf_token`). A `view`-only user reaching the confirm form finds an empty tempstore (redirect) or has every item skipped. (2.1) All queries are parameterized; IDs `(int)`-cast; `conversation_id` route param constrained to a UUID; `expression('count','count + 1')` literal. (2.5) The only `accessCheck(FALSE)` calls are the CLI promptfoo exporter and two `post_update` batch hooks — both non-web contexts. (2.6) `ReviewedGapPromptfooExportCommands` writes YAML via `file_put_contents` to an operator-supplied path — Drush-only, and it explicitly refuses to write into `promptfoo-evals/tests`. (2.7/2.8) No deserialization, dynamic calls, or outbound HTTP. (2.9) All 15 permissions carry `restrict access: true`.
- **Remediation:** None. Optionally add the specific `transition`/`administer` permission to the bulk route requirements for clarity/defense-in-depth, though `canTransition` already enforces it at the mutation point.

### SR-018
- **Severity:** INFO
- **Location:** ilas_donation_inquiry/src/Controller/DonationInquiryController.php:135-220,288-304,388-400; ilas_test/src/Controller/TestDashboardController.php:213-224, src/TestRunner.php:273-276; ilas_resources/src/Plugin/views/filter/StrictTopicServiceArea.php:63-129; ilas_adept/ilas_adept.module:136; ilas_voyage_ai_provider/src/Plugin/AiProvider/VoyageAiProvider.php:28,107-109; ilas_hotspot/src/Plugin/Block/IlasHotspotBlock.php:150
- **Rubric:** Pass 2I / 2.1, 2.2, 2.3, 2.5, 2.6, 2.8, 2.9 (small-modules baseline)
- **Evidence:**
  ```php
  if (!$this->donationFlood->isAllowed('donation_inquiry_submit', 5, 3600, $clientIp)) { … }   // flood
  if (!$this->donationCsrfToken->validate($submitted_token, 'donation_inquiry_form')) { … }     // CSRF
  $recipient = $config->get('recipient_email') ?: $this->config('system.site')->get('mail');    // NOT attacker-set
  'reply_to' => $data['email'],   // validated by donationEmailValidator->isValid() before use
  $filepath = 'private://test-reports/' . $report_id . '.json';   // report_id ^[a-zA-Z0-9_-]+$
  $response = \Drupal::httpClient()->get($host . $path);   // $path from a hardcoded page list
  ```
- **Rationale:** Clean baseline across the seven small modules. **ilas_donation_inquiry** (public `submit` POST, `_access: 'TRUE'`) mirrors the strong employment pattern — 5/hour/IP flood, CSRF token, `website_url` honeypot, required-field validation, optional reCAPTCHA (constant Google endpoint) — and crucially the email **recipient is a configured address, not client-supplied** (so no SR-015-style arbitrary-recipient abuse); `reply_to` is a format-validated email (`email.validator` service), preventing header injection; `source_url` is host-validated against the request host (no SSRF/open-redirect), and only lands in the email body. **ilas_test** is fully permission-gated (`run ilas tests`/`view test reports`, both restrict-access; the `run` route adds `_csrf_token`); `TestRunner` executes **in-process** (no `exec`/`shell`/`eval`), its self-request loop iterates a **hardcoded** page list (no SSRF), and report reads use a `[a-zA-Z0-9_-]+`-validated id against `private://` (no traversal) — satisfying the manifest's "no test-only endpoints reachable in prod" concern via the restrict-access gate. **ilas_resources** views filter/argument do **no SQL** — `query()` is empty and `postExecute()` filters in PHP against integer tids from entity fields; the contextual argument is derived from a routed node's `field_service_area`. **ilas_adept** / **ilas_announcement_overlay** use parameterized entity queries over published content (the one `accessCheck(FALSE)` in `ilas_adept` loads published lesson nodes by an `(int)`-cast module id — justified public course navigation). **ilas_voyage_ai_provider** POSTs to a constant Voyage endpoint with a config/key-managed Bearer token (no SSRF, key not logged). No deserialization, dynamic calls, or command execution anywhere in the bundle.
- **Remediation:** None. Two Pass-3 (XSS) cross-references to verify there: `ilas_hotspot` concatenates an admin-config media URL into a `#markup` href (system-generated URL, admin-sourced — verify escaping), and donation/announcement content rendering. No injection reachable by anonymous users.

---

## Pass Log

### Pass 0 — Inventory
Produced REVIEW-MANIFEST.md (directory line counts, settings/services list,
config/sync inventory, composer packages/patches, and the Pass 1–6 plan with
Pass 2/3 sub-splits). No code reviewed. No findings.

**PASS 0 COMPLETE**

### Pass 2A — Injection & Access Control: ilas_site_assistant Controllers

Scope: 4 controllers + `ilas_site_assistant.routing.yml` + `ilas_site_assistant.permissions.yml`;
custom access-check classes (`StrictCsrfRequestHeaderAccessCheck`, `AssistantDiagnosticsAccessCheck`)
opened read-only to satisfy rubric 2.3. Method: grep-first per rubric 2.1–2.9, hits opened
and traced. Findings SR-001 – SR-004.

Key clean determinations (evidence-backed):
- **2.1** — Controllers contain no raw SQL. `AssistantReportController` uses the query
  builder exclusively (`->select('ilas_site_assistant_stats')->condition(...)`), every
  `->condition()` value is a hardcoded literal (all `getEventCount()` call sites pass
  constant event types); no user-controlled operators/field names. `AssistantApiController`
  performs no direct DB access (delegates to services — Pass 2B/2D).
- **2.2** — Every request read traced: JSON bodies content-type-checked, size-capped
  (2000/1000 bytes), `json_decode` error-checked; `message`/`event_*` fields pass
  `sanitizeInput()` (strip_tags, 500-char cap, control-char strip); `conversation_id`/
  `X-Correlation-ID` validated as UUIDv4 or regenerated; `context` object strictly
  normalized with allowlisted keys (`normalizeRequestContext`); `suggest`/`faq` query
  params string-cast, length-gated, sanitized; forwarded headers used for logging only
  (SR-003). `AssistantReportController` reads no request input at all.
- **2.3** — All 10 routes have `requirements`. `_access: 'TRUE'` flagged ×5 (SR-002).
  Both custom access checks perform real, fail-closed checks (session-token CSRF
  validation; permission-or-`hash_equals`-token for diagnostics).
- **2.4** — `message` POST: dual CSRF (core + strict custom). `track` POST: origin-proof
  scheme (SR-001). `session_bootstrap` GET is state-changing by design (SR-004). Admin
  routes are permission-gated forms/reports.
- **2.5** — No `accessCheck(FALSE)`, no `entityQuery`, no `loadByProperties` in any
  controller (only a custom KV `selectionStateStore->load()` bound to conversation UUID +
  session fingerprint).
- **2.6** — No file operations of any kind in the four controllers.
- **2.7** — No `unserialize`, `call_user_func`, variable-class instantiation, or `eval`.
- **2.8** — No HTTP-client calls in the controllers (LLM/vector transport lives in
  services — Pass 2C).
- **2.9** — `administer ilas site assistant` and `approve ilas site assistant audit`
  both carry `restrict access: true`. `view ilas site assistant reports` (no restrict
  flag) gates read-only observability: reports render secret **presence booleans** only
  (`api_key_present` etc.), never values. No `restrict access: false` gating dangerous
  operations.

Checklist — ✓ = clean, SR-### = finding, n/a = surface absent:

| File | 2.1 SQLi | 2.2 input | 2.3 routing | 2.4 CSRF | 2.5 entity | 2.6 file | 2.7 deser. | 2.8 SSRF | 2.9 perms |
|---|---|---|---|---|---|---|---|---|---|
| AssistantApiController.php | ✓ | ✓ (SR-003 info) | SR-002 | SR-001 | ✓ | n/a | ✓ | n/a | — |
| AssistantReportController.php | ✓ | ✓ (no request input) | ✓ (perm-gated) | ✓ (read-only) | ✓ | n/a | ✓ | n/a | — |
| AssistantPageController.php | n/a | ✓ (config only) | ✓ (perm-gated) | ✓ (read-only) | ✓ | n/a | ✓ | n/a | — |
| AssistantSessionBootstrapController.php | n/a | ✓ | SR-002 | SR-004 (info) | n/a | n/a | ✓ | n/a | — |
| ilas_site_assistant.routing.yml | — | — | SR-002 | SR-001 | — | — | — | — | — |
| ilas_site_assistant.permissions.yml | — | — | — | — | — | — | — | — | ✓ |
| Access/StrictCsrfRequestHeaderAccessCheck.php (2.3 verify) | — | ✓ | ✓ real check | ✓ | — | — | — | — | — |
| Access/AssistantDiagnosticsAccessCheck.php (2.3 verify) | — | ✓ (hash_equals) | ✓ real check | — | — | — | — | — | — |

**PASS 2A COMPLETE**

### Pass 2B — Injection & Access Control: ilas_site_assistant Services group 1 (retrieval/ranking/routing)

Scope: 13 services — ResourceFinder, IntentRouter, FaqIndex, SelectionRegistry,
TopicRouter, RankingEnhancer, HardRouteRegistry, NavigationIntent, OfficeDirectory,
OfficeLocationResolver, RetrievalAugmenter, RetrievalContract, RetrievalConfigurationService.
Method: grep-first per rubric 2.1–2.9, hits opened and traced. Rubric items 2.3/2.4/2.9
(routing/CSRF/permissions) are n/a here — services expose no routes and add no `*.yml`
access surface; they are invoked only from the Pass-2A controllers, which own access
control. Findings SR-005 – SR-007.

Key determinations (evidence-backed):
- **2.1** — No raw SQL anywhere. DB access is entity-query builder + search_api
  `->query()` only. `CONTAINS` conditions parameterize the user query/keywords (value
  position); field names are hardcoded literals (SR-007). Clean.
- **2.2** — No service reads the HTTP request; inputs arrive as sanitized method args
  from controllers. Clean.
- **2.5** — Entity/field access is the load-bearing item. `ResourceFinder` node/media
  queries correctly pair `accessCheck(TRUE)` + `status = 1`. **Gap:** `FaqIndex::getById`
  and the legacy paragraph search load paragraphs by ID with no parent-node publish gate
  → **SR-005 (MEDIUM)**, anonymous IDOR to unpublished FAQ content. The one
  `accessCheck(FALSE)` (`OfficeDirectory`) is justified — public office data bounded by
  `status = PUBLISHED` → **SR-006 (INFO)**.
- **2.6** — Only file op is `TopicRouter` reading a fixed in-module YAML path (no user
  input). Clean.
- **2.7** — No `unserialize`/`call_user_func`/`eval`/variable-class instantiation. Clean.
- **2.8** — No outbound HTTP built in these services (Guzzle hits are exception-class
  string matches in error handling); search transport is delegated to search_api
  providers, reviewed in Pass 2C. Clean.

Checklist — ✓ = clean, SR-### = finding, n/a = surface absent:

| File | 2.1 SQLi | 2.2 input | 2.3 routing | 2.4 CSRF | 2.5 entity | 2.6 file | 2.7 deser. | 2.8 SSRF | 2.9 perms |
|---|---|---|---|---|---|---|---|---|---|
| ResourceFinder.php | ✓ (SR-007) | ✓ | n/a | n/a | ✓ (accessCheck TRUE + status) | ✓ | ✓ | ✓ (search_api) | n/a |
| IntentRouter.php | ✓ (no DB) | ✓ | n/a | n/a | ✓ | ✓ | ✓ | ✓ | n/a |
| FaqIndex.php | ✓ (SR-007) | ✓ | n/a | n/a | SR-005 | ✓ | ✓ | ✓ (search_api) | n/a |
| SelectionRegistry.php | ✓ | ✓ | n/a | n/a | ✓ | ✓ | ✓ | ✓ | n/a |
| TopicRouter.php | ✓ | ✓ | n/a | n/a | ✓ | ✓ (fixed path, SR-007) | ✓ | ✓ | n/a |
| RankingEnhancer.php | ✓ | ✓ | n/a | n/a | ✓ | ✓ | ✓ | ✓ | n/a |
| HardRouteRegistry.php | ✓ | ✓ | n/a | n/a | ✓ | ✓ | ✓ | ✓ | n/a |
| NavigationIntent.php | ✓ | ✓ | n/a | n/a | ✓ | ✓ | ✓ | ✓ | n/a |
| OfficeDirectory.php | ✓ | ✓ | n/a | n/a | SR-006 (justified) | ✓ | ✓ | ✓ | n/a |
| OfficeLocationResolver.php | ✓ | ✓ | n/a | n/a | ✓ | ✓ | ✓ | ✓ | n/a |
| RetrievalAugmenter.php | ✓ | ✓ | n/a | n/a | ✓ | ✓ | ✓ | ✓ | n/a |
| RetrievalContract.php | ✓ | ✓ | n/a | n/a | ✓ | ✓ | ✓ | ✓ | n/a |
| RetrievalConfigurationService.php | ✓ | ✓ | n/a | n/a | ✓ (config-id load) | ✓ | ✓ | ✓ | n/a |

**PASS 2B COMPLETE**

### Pass 2C — Injection & Access Control: ilas_site_assistant Services group 2 (LLM transport / providers / net I/O)

Scope: 13 services — LlmEnhancer, LlmAdmissionCoordinator, CohereLlmTransport,
CohereGenerationProbe, VoyageReranker, LangfuseTracer, LangfuseTraceLookupService,
ProviderHealthCheck, LlmRateLimiter, LlmCircuitBreaker, LlmRuntimeConfigResolver,
VectorIndexHygieneService, RuntimeTruthSnapshotBuilder. Method: grep-first per rubric
2.1–2.9, with 2.8 (SSRF) and secret handling as the load-bearing items for this layer.
Rubric 2.3/2.4/2.9 n/a (no routes/CSRF/permissions surface — services own no `*.yml`).
Findings SR-008 – SR-009.

Key determinations (evidence-backed):
- **2.8 (SSRF — primary)** — Four services touch the network. `CohereLlmTransport` and
  `VoyageReranker` POST to **hardcoded constant** endpoints; user text rides in the JSON
  body only, never the URL. `LlmEnhancer` builds no URL (delegates to the transport).
  `LangfuseTracer` does **no** direct egress. The lone config-driven host is
  `LangfuseTraceLookupService` (`langfuse.host`), reachable only via a **Drush CLI
  command**, protected by the `administer` (restrict-access) permission, with a
  `rawurlencode`d trace-ID path → **SR-008 (INFO)**, config-trust only. Clean baseline
  → **SR-009 (INFO)**.
- **Secrets** — API keys come from `Settings::get()` / `LlmRuntimeConfigResolver`, sent as
  `Bearer`/basic-auth headers, never logged (redacted `exceptionSignature`). No leakage
  observed; full secret/PII audit deferred to Pass 5.
- **2.1 / 2.5 / 2.6 / 2.7** — Zero hits across the entire set: no raw SQL, no entity
  queries, no file operations, no `unserialize`/`call_user_func`/`eval`/dynamic
  instantiation. These are State-API + config readers and HTTP transports.
- **2.2** — No service reads the HTTP request directly; inputs arrive as method args from
  the controllers/pipeline.

Checklist — ✓ = clean, SR-### = finding, n/a = surface absent:

| File | 2.1 SQLi | 2.2 input | 2.3 routing | 2.4 CSRF | 2.5 entity | 2.6 file | 2.7 deser. | 2.8 SSRF | 2.9 perms |
|---|---|---|---|---|---|---|---|---|---|
| LlmEnhancer.php | ✓ | ✓ | n/a | n/a | ✓ | ✓ | ✓ | ✓ (delegates, SR-009) | n/a |
| LlmAdmissionCoordinator.php | ✓ | ✓ | n/a | n/a | ✓ | ✓ | ✓ | ✓ (no egress) | n/a |
| CohereLlmTransport.php | ✓ | ✓ | n/a | n/a | ✓ | ✓ | ✓ | ✓ (const endpoint, SR-009) | n/a |
| CohereGenerationProbe.php | ✓ | ✓ | n/a | n/a | ✓ | ✓ | ✓ | ✓ | n/a |
| VoyageReranker.php | ✓ | ✓ | n/a | n/a | ✓ | ✓ | ✓ | ✓ (const endpoint, SR-009) | n/a |
| LangfuseTracer.php | ✓ | ✓ | n/a | n/a | ✓ | ✓ | ✓ | ✓ (no direct egress) | n/a |
| LangfuseTraceLookupService.php | ✓ | ✓ | n/a | n/a | ✓ | ✓ | ✓ | SR-008 (config host, CLI-only) | n/a |
| ProviderHealthCheck.php | ✓ | ✓ | n/a | n/a | ✓ | ✓ | ✓ | ✓ | n/a |
| LlmRateLimiter.php | ✓ | ✓ | n/a | n/a | ✓ | ✓ | ✓ | ✓ | n/a |
| LlmCircuitBreaker.php | ✓ | ✓ | n/a | n/a | ✓ | ✓ | ✓ | ✓ | n/a |
| LlmRuntimeConfigResolver.php | ✓ | ✓ | n/a | n/a | ✓ | ✓ | ✓ | ✓ | n/a |
| VectorIndexHygieneService.php | ✓ | ✓ | n/a | n/a | ✓ | ✓ | ✓ | ✓ | n/a |
| RuntimeTruthSnapshotBuilder.php | ✓ | ✓ | n/a | n/a | ✓ | ✓ | ✓ | ✓ | n/a |

**PASS 2C COMPLETE**

### Pass 2D — Injection & Access Control: ilas_site_assistant remaining Services + EventSubscribers + Form + Access + Plugins + Commands + .module/.install

Scope (72 reviewable files): ~50 remaining `src/Service/*.php` (all not in 2B/2C);
4 EventSubscribers (AssistantApiResponseMonitor, CsrfDenialResponse, LangfuseTerminate,
SentryOptions); Form/AssistantSettingsForm.php; Access/{StrictCsrfRequestHeaderAccessCheck,
AssistantDiagnosticsAccessCheck} (full review — spot-verified in 2A); Plugin/QueueWorker/
LangfuseExportWorker, Plugin/KeyProvider/RuntimeSiteSettingKeyProvider; 11 Commands;
ilas_site_assistant.module; ilas_site_assistant.install. Method: grep-first per rubric
2.1–2.9. Findings SR-010 – SR-011.

Key determinations (evidence-backed):
- **2.1 (SQL)** — DB writers (`ConversationStateStore`, `ConversationLogger`,
  `AnalyticsLogger`, `SafetyAlertService`, `QueueHealthMonitor`, plus the `.install`
  update hooks) use the query builder exclusively. Every `->condition()` value is
  parameterized (incl. `IN` array binds); every `->expression('count','count + 1')` /
  `->addExpression('SUM(count)','total')` is a **hardcoded literal** — no variables in
  expression strings, no `->where()`, no `db_query`, no dynamic table/column names. Clean.
- **2.2 (request input)** — Services receive sanitized method args; the direct request
  reads are: the two Access checks (token/header validation, reviewed in 2A/2D),
  `RequestTrustInspector` (forwarded-header inspection for logging), the response-monitor
  subscriber (`json_decode` of request/response for read-only telemetry labeling), and
  `GapReviewDecider` — where a client-controlled eval header/field gates governance
  recording → **SR-010 (LOW)**.
- **2.5 (entity access)** — No `accessCheck(FALSE)` anywhere in 2D; no `entityQuery`/
  `getQuery`. Only entity reads are `TopicResolver::loadByProperties(['vid' => …])`
  (hardcoded vocab, public taxonomy) and `KbImportCommands` creating FAQ paragraphs
  (CLI-only). Clean.
- **2.6 (file)** — Fixed in-module YAML reads (`AcronymExpander`, like SR-007's
  `TopicRouter`); `fwrite(STDERR,…)` in commands; `glob()` over a fixed module subdir.
  `KbImportCommands::kbImport($file)` joins an operator-supplied `$file` to the module
  path (fallback to absolute) — **flagged and dismissed:** Drush CLI only, run by an
  operator who already has shell/file access, so no privilege boundary is crossed. Clean.
- **2.7 (deserialization/dynamic)** — Zero hits: no `unserialize`, `call_user_func`,
  `eval`, `new $var`, or variable method/property calls. Clean.
- **2.8 (SSRF)** — Two config-host egress sites (`LangfuseExportWorker` cron queue flush;
  `LangfuseProbeCommands` CLI) extend the SR-008 config-trust family → **SR-011 (INFO)**.
  No anonymous-reachable user-controlled URL. `SourceGovernanceService` is a positive
  control: it validates citation URLs against an `ALLOWED_CITATION_HOSTS` allowlist and
  rejects unsafe schemes/off-domain/protocol-relative URLs.
- **2.3 / 2.4 / 2.9** — The two Access checks fully reviewed: both fail-closed and perform
  real checks (session-CSRF validation; permission-or-`hash_equals`-token). `AssistantSettingsForm`
  is a standard Form-API config form behind `administer ilas site assistant` (restrict
  access). Commands are Drush/CLI (no web routes). `.module` hooks and `.install` schema/
  update hooks add no access surface. Clean.

Checklist (grouped; ✓ = clean across the group, SR-### = finding, n/a = surface absent):

| Group (files) | 2.1 | 2.2 | 2.3 | 2.4 | 2.5 | 2.6 | 2.7 | 2.8 | 2.9 |
|---|---|---|---|---|---|---|---|---|---|
| Remaining Services — DB writers (ConversationStateStore, ConversationLogger, AnalyticsLogger, SafetyAlertService, QueueHealthMonitor) | ✓ (param + literal expr) | ✓ | n/a | n/a | ✓ | ✓ | ✓ | ✓ | n/a |
| Remaining Services — logic/routing/safety (~40: SafetyClassifier, PolicyFilter, ResponseBuilder, ResponseGrounder, TopicResolver, InputNormalizer, PiiRedactor, SourceGovernanceService, RequestTrustInspector, EnvironmentDetector, GapReviewDecider, AcronymExpander, …) | ✓ | ✓ (SR-010: GapReviewDecider) | n/a | n/a | ✓ | ✓ (AcronymExpander fixed path) | ✓ | ✓ (SourceGovernance = allowlist control) | n/a |
| EventSubscribers (AssistantApiResponseMonitor, CsrfDenialResponse, LangfuseTerminate, SentryOptions) | ✓ | ✓ (read-only telemetry; argv CLI) | n/a | ✓ | ✓ | ✓ | ✓ | ✓ | n/a |
| Form/AssistantSettingsForm.php | ✓ | ✓ (Form API) | ✓ (perm-gated) | ✓ (Form API token) | ✓ | ✓ | ✓ | ✓ | ✓ (restrict access) |
| Access/{StrictCsrf, Diagnostics}AccessCheck.php | ✓ | ✓ | ✓ real check | ✓ | n/a | n/a | ✓ | n/a | n/a |
| Plugin/KeyProvider/RuntimeSiteSettingKeyProvider.php | ✓ | ✓ | n/a | n/a | ✓ | ✓ | ✓ | ✓ | ✓ (Settings::get, no log) |
| Plugin/QueueWorker/LangfuseExportWorker.php | ✓ | ✓ (queued payload) | n/a | n/a | ✓ | ✓ | ✓ | SR-011 (config host, cron-only) | n/a |
| Commands/*.php (11 — Drush CLI) | ✓ | ✓ (CLI args) | n/a | n/a | ✓ | ✓ (fixed paths; kbImport CLI-only) | ✓ | SR-011 (LangfuseProbe, CLI-only) | n/a |
| ilas_site_assistant.module | ✓ | ✓ | n/a | n/a | ✓ | ✓ | ✓ | ✓ | n/a |
| ilas_site_assistant.install | ✓ (query builder in update hooks) | n/a | n/a | n/a | ✓ | ✓ | ✓ | ✓ | n/a |

**PASS 2D COMPLETE**

### Pass 2E — Injection & Access Control: ilas_redirect_automation

Scope (6 non-test files, ~2434 lines): Service/{PathMatcherService, FileMatcherService,
RedirectAnalyzerService, RedirectApplierService, CsvExportService}, Commands/
RedirectAutomationCommands.php, ilas_redirect_automation.module. Method: grep-first per
rubric 2.1–2.9. **No web surface** — the module ships no `*.routing.yml` /
`*.permissions.yml`; the only hook is `hook_help()`. All entry points are Drush commands
(`redirect:analyze`, `redirect:preview`, `redirect:apply`, …), so 2.3/2.4/2.9 are n/a.
Findings SR-012 – SR-013.

Key determinations (evidence-backed):
- **2.1 (SQL)** — All DB access (`redirect_404`, `redirect`, `path_alias`,
  `node__field_topics` joins) uses the query builder; `->condition()` values are
  parameterized, `LIKE` patterns pass `escapeLike()`, and `->join()` predicates are
  hardcoded literal strings. No `->where()`, `db_query`, or dynamic identifiers. Clean.
- **2.5 (entity access)** — Two `accessCheck(FALSE)` queries in `PathMatcherService`,
  justified (CLI-only, published `resource` nodes) → **SR-012 (INFO)**. Entity loads by
  regex-extracted numeric node/term IDs; redirect entities created via storage.
- **2.6 (file — load-bearing)** — `CsvExportService` reads/writes CSV via `fopen`/
  `fputcsv`/`fgetcsv` with a `$filepath` supplied as an operator `--output`/input **CLI
  option**; CLI-only, no privilege boundary crossed → folded into **SR-013 (INFO)**.
- **Open-redirect** — `createRedirect()` is gated behind `validateDestination()` (must
  resolve to a published node/term, active alias, or existing internal file) and forces an
  `internal:`/`entity:` URI prefix, so no off-site redirect can be created → positive
  control, **SR-013 (INFO)**.
- **2.7 / 2.8** — Zero hits: no deserialization, dynamic calls, or outbound HTTP. Clean.

Checklist — ✓ = clean, SR-### = finding, n/a = surface absent:

| File | 2.1 SQLi | 2.2 input | 2.3 routing | 2.4 CSRF | 2.5 entity | 2.6 file | 2.7 deser. | 2.8 SSRF | 2.9 perms |
|---|---|---|---|---|---|---|---|---|---|
| PathMatcherService.php | ✓ (SR-013) | ✓ (CLI) | n/a | n/a | SR-012 (justified) | ✓ | ✓ | ✓ | n/a |
| FileMatcherService.php | ✓ | ✓ (CLI) | n/a | n/a | ✓ | ✓ | ✓ | ✓ | n/a |
| RedirectAnalyzerService.php | ✓ | ✓ (CLI) | n/a | n/a | ✓ | ✓ | ✓ | ✓ | n/a |
| RedirectApplierService.php | ✓ (SR-013) | ✓ (CLI) | n/a | n/a | ✓ (validateDestination gate) | ✓ (existence-check only) | ✓ | ✓ | n/a |
| CsvExportService.php | n/a | ✓ (CLI) | n/a | n/a | n/a | SR-013 (CLI --output path) | ✓ | ✓ | n/a |
| Commands/RedirectAutomationCommands.php | ✓ | ✓ (CLI opts) | n/a | n/a | ✓ (config save) | ✓ | ✓ | ✓ | n/a |
| ilas_redirect_automation.module | n/a | n/a | n/a | n/a | n/a | n/a | ✓ | ✓ | n/a (hook_help only) |

**PASS 2E COMPLETE**

### Pass 2F — Injection & Access Control: ilas_seo

Scope (4 files, ~1423 reviewable lines): ilas_seo.module (667),
StructuredData/GraphBuilder.php (612), EventSubscriber/CspEnhancementSubscriber.php (79),
EventSubscriber/ResponseSubscriber.php (65). Method: grep-first per rubric 2.1–2.9.
**No web surface** — no `*.routing.yml`/`*.permissions.yml`; module operates via
`hook_page_attachments[_alter]` and `hook_metatags_alter`, so 2.3/2.4/2.9 are n/a.
Finding SR-014.

Key determinations (evidence-backed):
- **2.1 / 2.5 / 2.6 / 2.7 / 2.8** — Zero hits: no SQL/DB, no entity queries, no file ops,
  no deserialization/dynamic calls, no outbound HTTP. The module only mutates render
  arrays and metatag/schema structures.
- **2.2 (request input)** — Three reflections traced, all safe (SR-014): request path →
  BreadcrumbList JSON-LD, emitted via `Json::encode()` (`JSON_HEX_TAG` etc. → no
  `</script>` breakout); `?page=`/filter params → boolean `noindex` decisions only, never
  reflected.
- **Header injection** — `CspEnhancementSubscriber` appends only constant directives to
  SecKit's CSP; no user data in the header. `ResponseSubscriber` likewise sets static
  hardening headers. Clean.
- **Output encoding (Pass-3 cross-ref)** — JSON-LD blocks use `Json::encode()` with HEX
  flags; schema/metatag values render through the metatag & schema_metatag pipelines
  (autoescaped). Positive control noted for Pass 3.

Checklist — ✓ = clean, SR-### = finding, n/a = surface absent:

| File | 2.1 SQLi | 2.2 input | 2.3 routing | 2.4 CSRF | 2.5 entity | 2.6 file | 2.7 deser. | 2.8 SSRF | 2.9 perms |
|---|---|---|---|---|---|---|---|---|---|
| ilas_seo.module | ✓ | ✓ (SR-014: page/filter → noindex bool) | n/a | n/a | ✓ | ✓ | ✓ | ✓ | n/a |
| StructuredData/GraphBuilder.php | ✓ | ✓ (SR-014: req path → Json::encode) | n/a | n/a | ✓ | ✓ | ✓ | ✓ | n/a |
| EventSubscriber/CspEnhancementSubscriber.php | ✓ | ✓ (const directives only) | n/a | ✓ (no user data) | ✓ | ✓ | ✓ | ✓ | n/a |
| EventSubscriber/ResponseSubscriber.php | ✓ | ✓ (static headers) | n/a | ✓ | ✓ | ✓ | ✓ | ✓ | n/a |

**PASS 2F COMPLETE**

### Pass 2G — Injection & Access Control: employment_application

Scope (~3657 non-test lines): Controller/EmploymentApplicationController.php (2526),
Service/ApplicationValidator.php (375), Form/ApplicationDeleteForm.php (193),
Commands/EmploymentApplicationCommands.php (166), employment_application.module (193),
employment_application.install (204), + routing.yml (11 routes) / permissions.yml.
Method: grep-first per rubric 2.1–2.9, with the public POST/PII surface as the focus.
Findings SR-015 – SR-016.

Key determinations (evidence-backed):
- **2.3 / 2.4 (routing / CSRF)** — 11 routes reviewed. Public: `submit` (POST), `token`
  (GET), `jobs`/`job/{uuid}` (GET), `draft_save` (POST), `draft_load` (GET). Admin
  (`administer employment applications`, restrict access): `admin`, `detail`,
  `status_update`, `delete`, `download`. `submit` has no route-level `_csrf_token` by
  design — the controller enforces a stronger pipeline (CSRF token + single-use session
  nonce + honeypot + time-gate + 4-tier flood). `updateStatus` (admin POST) validates a
  separate CSRF token. → **SR-016 (INFO)**.
- **2.2 (request input)** — Bodies are content-type-checked, JSON-error-checked, and
  field-validated (email `FILTER_VALIDATE_EMAIL`, UUID/status route constraints). The one
  gap: `saveDraft` keys drafts by a client-supplied `email`, enabling arbitrary-recipient
  email + cross-email draft overwrite → **SR-015 (LOW)**.
- **2.1 (SQL)** — All queries use the parameterized builder (`->condition()`);
  `.install`/`.module` migration expressions are literals (`SHA2(...)`). No raw SQL. Clean.
- **2.5 (entity/IDOR)** — `downloadFile` binds `{fid}`→`{id}` and locks to `private://`;
  admin file routes permission-gated; `loadDraft` uses a 256-bit CSPRNG capability token.
  No `accessCheck(FALSE)`. Clean.
- **2.6 (file upload)** — Extension + MIME allowlists + magic-byte sniffing + size caps +
  regenerated sanitized filename in `private://`. Defeats double-extension/`.php`. Clean.
- **2.7 / 2.8** — No deserialization/dynamic calls; no outbound HTTP (email via Symfony
  Mailer to a validated address, fixed from/replyTo — no header injection). Clean.
- **2.9 (permissions)** — `administer employment applications` carries `restrict access:
  true`, gating all view/download/manage/status/delete operations. Clean.

Checklist — ✓ = clean, SR-### = finding, n/a = surface absent:

| File | 2.1 SQLi | 2.2 input | 2.3 routing | 2.4 CSRF | 2.5 entity | 2.6 file | 2.7 deser. | 2.8 SSRF | 2.9 perms |
|---|---|---|---|---|---|---|---|---|---|
| Controller/EmploymentApplicationController.php | ✓ | SR-015 (saveDraft) | ✓ (SR-016) | ✓ (SR-016: nonce+CSRF) | ✓ (downloadFile IDOR bind) | ✓ (allowlist+magic+private) | ✓ | ✓ (Mailer, fixed hdrs) | ✓ |
| Service/ApplicationValidator.php | n/a | ✓ (Xss::filter, validators) | n/a | n/a | n/a | ✓ (secure filename, magic bytes) | ✓ | ✓ | n/a |
| Form/ApplicationDeleteForm.php | ✓ | ✓ (Form API) | ✓ (perm-gated) | ✓ (Form API token) | ✓ | ✓ | ✓ | ✓ | ✓ (restrict access) |
| Commands/EmploymentApplicationCommands.php | ✓ | ✓ (CLI) | n/a | n/a | ✓ | ✓ | ✓ | ✓ | n/a |
| employment_application.module | ✓ (cron cleanup, param) | ✓ | n/a | n/a | ✓ (managed File delete) | ✓ | ✓ | ✓ | n/a |
| employment_application.install | ✓ (literal expr in update hook) | n/a | n/a | n/a | n/a | n/a | ✓ | ✓ | n/a |
| employment_application.routing.yml | — | — | ✓ (SR-016) | ✓ (submit pipeline) | — | — | — | — | — |
| employment_application.permissions.yml | — | — | — | — | — | — | — | — | ✓ (restrict access) |

**PASS 2G COMPLETE**

### Pass 2H — Injection & Access Control: ilas_site_assistant_governance

Scope (~5842 non-test lines, 35 src files + module/install/post_update): AssistantGapItem
entity + access-control handler + storage schema + route/list providers; 3 Controllers
(GovernanceConversation, AssistantGapItemReview, GapReviewWorkflow); 2 Forms
(BulkDisposition, GapItemForm); 16 Plugin/Action state-machine classes; 7 Services
(GapItemManager, ReviewedGapPromptfooCandidateExporter, GovernancePruner, LegalHoldLogger,
GapReviewRules, GapItemIdentityBuilder, GovernanceConversationLogger); 1 views field;
1 Drush command; .install + .post_update. Method: grep-first per rubric 2.1–2.9.
Finding SR-017. Related to SR-010 (this module owns the gap-item queue that SR-010's
spoofable header can suppress writes to).

Key determinations (evidence-backed):
- **2.3 / 2.9 (routing / permissions — load-bearing)** — 4 routes; 15 permissions, all
  `restrict access: true`. Route perms: conversation detail + bulk forms + start-review.
  The bulk resolve/archive routes are `view`-gated but the transition is enforced by
  `canTransition()` at the mutation point (per-user tempstore + submit-loop re-check) →
  **SR-017 (INFO)**, verified safe. `startReviewAccess` custom callback performs a real
  layered check (entity access + state + permission) and the route adds `_csrf_token`.
- **2.5 (entity access)** — Centralized: `AssistantGapItem::canTransition()` (state map +
  per-target permission or `administer`) is the single authority, used by the action
  plugins, the bulk form, and the workflow controller. Per-plugin `access()` checks the
  matching permission (flag/legal-hold/edit). Two `accessCheck(FALSE)` (CLI exporter +
  post_update hooks) justified. Clean.
- **2.1 (SQL)** — All parameterized; IDs `(int)`-cast; `conversation_id` UUID-constrained;
  literal `expression()`. Clean.
- **2.6 (file)** — Only the Drush promptfoo exporter writes files (operator path, guarded
  against writing into `promptfoo-evals/tests`). CLI-only. Clean.
- **2.7 / 2.8** — No deserialization/dynamic calls; no outbound HTTP. Clean.
- **2.2 / 2.4** — Controllers read only route-constrained params into parameterized
  queries; state-changing operations go through Form API (CSRF) or `_csrf_token` routes.

Checklist (grouped; ✓ = clean, SR-### = finding, n/a = surface absent):

| Group (files) | 2.1 | 2.2 | 2.3 | 2.4 | 2.5 | 2.6 | 2.7 | 2.8 | 2.9 |
|---|---|---|---|---|---|---|---|---|---|
| Entity/AssistantGapItem.php + AccessControlHandler + StorageSchema + Route/List providers | ✓ | ✓ | ✓ (canTransition authority) | ✓ | ✓ (SR-017) | ✓ | ✓ | ✓ | ✓ |
| Controllers (GovernanceConversation, AssistantGapItemReview, GapReviewWorkflow) | ✓ (param, UUID) | ✓ (route params) | ✓ (perm + custom access + csrf) | ✓ | ✓ (->access checks) | ✓ | ✓ | ✓ | n/a |
| Forms (BulkDisposition, GapItemForm) | ✓ | ✓ (Form API) | ✓ (SR-017: view route, canTransition gate) | ✓ (Form API token) | ✓ (submit-loop re-check) | ✓ | ✓ | ✓ | n/a |
| Plugin/Action/* (16 state-machine actions) | ✓ | ✓ | n/a | ✓ (VBO) | ✓ (per-perm access()) | ✓ | ✓ | ✓ | n/a |
| Services (GapItemManager, Exporter, Pruner, LegalHoldLogger, GapReviewRules, IdentityBuilder, ConversationLogger) | ✓ (param) | ✓ | n/a | n/a | ✓ (Exporter accessCheck(FALSE), CLI) | ✓ | ✓ | ✓ | n/a |
| Commands/ReviewedGapPromptfooExportCommands.php | ✓ | ✓ (CLI) | n/a | n/a | ✓ | SR-017 (CLI file write, guarded) | ✓ | ✓ | n/a |
| views/field/AssistantGapItemNextAction.php | n/a | ✓ | n/a | n/a | ✓ | n/a | ✓ | ✓ | n/a |
| .module / .install / .post_update.php | ✓ (param) | n/a | n/a | n/a | ✓ (post_update accessCheck(FALSE)) | ✓ | ✓ | ✓ | n/a |

**PASS 2H COMPLETE**

### Pass 2I — Injection & Access Control: small-modules bundle

Scope (7 modules, each < 2000 non-test lines): ilas_adept, ilas_announcement_overlay,
ilas_donation_inquiry, ilas_hotspot, ilas_resources, ilas_voyage_ai_provider, ilas_test.
Method: grep-first per rubric 2.1–2.9. Finding SR-018.

Key determinations (evidence-backed):
- **2.3 / 2.9 (routing / permissions)** — Public POSTs: `donation_inquiry.submit` (+token).
  Admin: `ilas_hotspot.settings` (`administer site configuration`), `ilas_test.*`
  (`run ilas tests`/`view test reports`, restrict-access; `run` adds `_csrf_token`;
  `report/{id}` regex-constrained). Others are hook/plugin/CLI only (no routes).
- **Public POST hardening** — `donation_inquiry` submit: flood (5/hr/IP) + CSRF token +
  honeypot + field validation + optional reCAPTCHA; **recipient is config, not
  client-supplied**; `reply_to` format-validated (no header injection); `source_url`
  host-validated (no SSRF/open-redirect) → **SR-018 (INFO)**.
- **2.6 / EXEC** — `ilas_test` runs in-process (no `exec`/`shell`/`eval`); report file
  read from `private://` with `[a-zA-Z0-9_-]+`-validated id (no traversal); report write
  to `private://`. Permission-gated. Clean.
- **2.1 (SQL)** — All entity/DB queries parameterized; `ilas_resources` views plugins do
  no SQL (PHP `postExecute` filtering over integer tids). Clean.
- **2.5 (entity access)** — One `accessCheck(FALSE)` (`ilas_adept`, published lessons by
  int module id) justified; others `accessCheck(TRUE)`. Clean.
- **2.8 (SSRF)** — Constant endpoints only (Google reCAPTCHA verify, Voyage embeddings);
  `TestRunner` self-request iterates a hardcoded page list. Clean.
- **2.7** — No deserialization/dynamic calls. Clean.

Checklist — ✓ = clean, SR-### = finding, n/a = surface absent:

| Module | 2.1 SQLi | 2.2 input | 2.3 routing | 2.4 CSRF | 2.5 entity | 2.6 file | 2.7 deser. | 2.8 SSRF | 2.9 perms |
|---|---|---|---|---|---|---|---|---|---|
| ilas_donation_inquiry | ✓ | ✓ (SR-018) | ✓ (public POST, controller CSRF) | ✓ (token+honeypot) | ✓ | ✓ | ✓ | ✓ (const reCAPTCHA) | n/a |
| ilas_test | ✓ | ✓ | ✓ (perm+csrf) | ✓ | ✓ | ✓ (private://, id-validated) | ✓ (in-process) | ✓ (hardcoded paths) | ✓ (restrict access) |
| ilas_resources | ✓ (PHP filter, no SQL) | ✓ | n/a | n/a | ✓ (entity tids) | ✓ | ✓ | ✓ | n/a |
| ilas_adept | ✓ (param) | ✓ (int cast) | n/a | n/a | ✓ (accessCheck FALSE justified) | ✓ | ✓ | ✓ | n/a |
| ilas_announcement_overlay | ✓ (param) | ✓ | n/a | n/a | ✓ (accessCheck TRUE) | ✓ | ✓ | ✓ | n/a |
| ilas_hotspot | ✓ | ✓ | ✓ (admin form) | ✓ (Form API) | ✓ | ✓ | ✓ | ✓ | n/a (Pass-3 xref: #markup href) |
| ilas_voyage_ai_provider | n/a | ✓ | n/a | n/a | ✓ | ✓ | ✓ | ✓ (const endpoint) | n/a |

**PASS 2I COMPLETE**

---

## Pass 2 Summary — Injection & Access Control (complete: 2A–2I)

All custom PHP reviewed per rubric 2.1–2.9. 18 findings recorded (SR-001 – SR-018):
none CRITICAL or HIGH.

- **MEDIUM (1):** SR-005 — `FaqIndex::getById`/legacy search loads FAQ paragraphs with no
  parent-node publish gate → anonymous IDOR to unpublished FAQ content.
- **LOW (4):** SR-001 (track endpoint origin-only write proof), SR-010 (spoofable
  `X-ILAS-Eval-Run-ID` suppresses governance gap recording), SR-013 note within-scope,
  SR-015 (`saveDraft` arbitrary-recipient email + cross-email draft overwrite).
- **INFO (13):** SR-002/003/004/006/007/008/009/011/012/014/016/017/018 — flagged
  `_access:'TRUE'` routes with verified compensating controls, justified `accessCheck(FALSE)`
  instances, the config-driven `langfuse.host` egress family (SR-008/011), and positive-
  control baselines (submit pipelines, JSON-LD `Json::encode` escaping, `canTransition`
  authz, file-upload validation).

Recurring themes for remediation batching: (1) SR-008/SR-011 share the `langfuse.host`
allowlist fix; (2) SR-005 mirrors a correct pattern already used in `ResourceFinder`
(pair `accessCheck(TRUE)` with `status=1`); (3) SR-001/SR-010/SR-015 are all
unauthenticated-client-trust gaps on public assistant/application endpoints.

Deferred to later passes as cross-referenced: Pass 3 (XSS/output encoding — JSON-LD,
`#markup` sinks, draft-reload rendering), Pass 4 (full access-control/CSRF), Pass 5
(secrets/PII — `langfuse.host` credential egress, ObservabilityPayloadMinimizer,
PiiRedactor), Pass 6 (test fixtures).

**PASS 2 COMPLETE**

### SR-019
- **Severity:** MEDIUM
- **Location:** web/modules/custom/ilas_announcement_overlay/templates/announcement-overlay-popup.html.twig:66 (source: src/Plugin/Block/HomepageAnnouncementOverlayBlock.php:95,114)
- **Rubric:** Pass 3 / 3.1 (`|raw` filter)
- **Evidence:**
  ```php
  $body = $announcement->get('field_announcement_body')->getValue();   // Block plugin:95
  '#body' => $body,                                                     // :114
  ```
  ```twig
  {% for item in body %}{{ item.value|raw }}{% endfor %}               {# template:65-67 #}
  ```
- **Rationale:** `field_announcement_body` is a `text_long` formatted-text field. `->getValue()` returns the **raw stored source markup** (`[['value' => '<html>', 'format' => '…']]`). Drupal applies a text format's filters (`filter_html`/XSS correction) only at render time via `check_markup`/`#type => processed_text`. Emitting `item.value|raw` bypasses that entirely, so the field's assigned format provides zero protection: anyone who can create/edit the `homepage_announcement` block can type `<script>…</script>` or `<img src=x onerror=…>` into the body source and have it emitted verbatim into the front-page DOM for every anonymous visitor (persistent/stored XSS). Editing block content requires a block-admin permission — the `content_editor` role has no block permissions in `config/user.role.content_editor.yml` — so injection is **admin-editor-gated** (privileged writer, anonymous victim), which is why this is MEDIUM rather than a HIGH anonymous vector. The correct pattern already exists in this codebase at `b5subtheme.theme:347-355` (`#type => 'processed_text'`, with a comment noting it applies text-format XSS filters "unlike accessing ->value directly").
- **Remediation:** In the block, build a `processed_text` render array per item (`['#type' => 'processed_text', '#text' => $item['value'], '#format' => $item['format']]`) and print `{{ item }}` in the template without `|raw`; or run each value through `check_markup($item['value'], $item['format'])` before passing it. Do not emit a formatted-text `->value` through `|raw`.

### SR-020
- **Severity:** MEDIUM
- **Location:** web/themes/custom/b5subtheme/b5subtheme.theme:522 (emitted at templates/node/node--home-page.html.twig:60)
- **Rubric:** Pass 3 / 3.2 (`Markup::create` with interpolated variable)
- **Evidence:**
  ```php
  $alt = $image_field->alt ?? '';                                      // :494 (editor-controlled)
  $hero_images[] = ['url' => $image_url, 'alt' => $alt, ...];          // :498-504
  '#tag' => 'script', '#attributes' => ['type' => 'application/json', 'id' => 'hero-rotator-data'],
  '#value' => Markup::create(json_encode($hero_images, JSON_UNESCAPED_SLASHES)),   // :522
  ```
- **Rationale:** The hero image `alt` is editor-controlled (the home_page node's hero media). It is serialized with **only** `JSON_UNESCAPED_SLASHES` — a flag that *removes* the default `/` escaping — and emitted raw via `Markup::create` inside a literal `<script type="application/json">` element (`{{ hero_rotator_script }}` at node--home-page.html.twig:60). `json_encode` does not escape `<`/`>` by default, and with slashes unescaped an `alt` value of `</script><script>…</script>` emits a literal `</script>`, breaking out of the script context → stored XSS on the home page for all anonymous visitors. `content_editor` can edit home_page nodes/media, so this is content-editor-reachable (privileged writer, anonymous victim → MEDIUM). The in-code comment "json_encode output is safe by construction" is incorrect for script-tag context.
- **Remediation:** Add the HEX flags already used elsewhere in this codebase (`ilas_seo` uses `Json::encode`, SR-014): `json_encode($hero_images, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)`. `JSON_HEX_TAG` escapes `<`/`>`, closing the breakout. Alternatively use Drupal's `Json::encode()`, which sets these flags by default.

### SR-021
- **Severity:** MEDIUM
- **Location:** web/themes/custom/b5subtheme/js/premium-application.js:654-658,677-697 (server source: web/modules/custom/employment_application/src/Controller/EmploymentApplicationController.php:286-287 → 164-165)
- **Rubric:** Pass 3 / 3.5 (JS `.html()`/`.prepend()` with non-literal input)
- **Evidence:**
  ```js
  const jobLabel = errorData.job_location ? errorData.job_title + ' — ' + errorData.job_location : errorData.job_title;  // :654-656
  message = 'The position "' + jobLabel + '" is no longer accepting applications.';                                     // :658
  const errorHtml = `… <p class="job-closed-error__message">${message}</p> …`;                                          // :677-694
  $stepContent.prepend(errorHtml);   // jQuery parses the string as HTML                                                 // :697
  ```
  ```php
  $jobTitle = $jobParagraph->get('field_accordion_title')->value ?? '';   // Controller:286
  $jobLocation = $jobParagraph->get('field_job_location')->value ?? '';   // :287
  $errorData['job_title'] = $result['job_title']; $errorData['job_location'] = $result['job_location'] ?? '';  // :164-165
  ```
- **Rationale:** `job_title`/`job_location` are raw editor-entered paragraph field values (`field_accordion_title`/`field_job_location`), returned in the `/employment-application/jobs/{uuid}` JSON without HTML-escaping and interpolated raw into an HTML string that jQuery `.prepend()` parses as markup. An editor who sets a (closed) job posting's title/location to `<img src=x onerror=…>` gets script execution in the browser of any anonymous applicant who follows the resume/deep link for that closed job → content-editor-reachable stored XSS, anonymous victim (MEDIUM). `content_editor` edits job paragraphs. The other switch branches (`invalid`/`not_found`) use static strings and are safe; the same file already demonstrates the correct pattern elsewhere (draft-status uses `document.createTextNode`, the job dropdown uses `.text()`).
- **Remediation:** Escape `job_title`/`job_location` before interpolation, or build the error panel with DOM nodes / jQuery `.text()` for the dynamic parts (as the draft-status and dropdown code already do). Optionally also HTML-escape these fields server-side before returning them in the JSON response.

### SR-022
- **Severity:** LOW
- **Location:** web/modules/custom/ilas_hotspot/src/Plugin/Block/IlasHotspotBlock.php:150 (source: getAnnualReportUrl() :197-226)
- **Rubric:** Pass 3 / 3.2 (`#markup` with interpolated variable), 3.6 (href from a variable)
- **Evidence:**
  ```php
  '#markup' => '<a href="' . $annual_report_path . '" target="_blank" rel="noopener noreferrer"> … alt="' . t('ILAS Annual Report Cover') . '" …',   // :150-154
  // source:
  return $file_url_generator->generateAbsoluteString($file->getFileUri());   // :213
  ```
- **Rationale:** `$annual_report_path` is a system-generated absolute file URL for an admin-selected media document (its path segment includes the admin-uploaded, Drupal-munged filename). It is concatenated **unescaped** into an `href` attribute inside `#markup`. The sibling "button" element at lines 156-164 renders the identical URL safely via `#type => 'html_tag'` + `#attributes['href']` (auto-escaped). Practical exploitability is negligible — the URL is system-generated, admin-controlled, and Drupal's filename munging strips dangerous characters — so this is LOW/defense-in-depth, but it is an unescaped dynamic value in an HTML sink and inconsistent with the adjacent correct code. This dispositions the Pass-2 SR-018 XSS cross-reference.
- **Remediation:** Escape with `Html::escape($annual_report_path)`, or convert the anchor to `#type => 'html_tag'` with `#attributes['href']` like the sibling button at line 160.

### SR-023
- **Severity:** LOW
- **Location:** web/modules/custom/ilas_site_assistant/js/assistant-widget.js:892 (attribute source: templates/assistant-page.html.twig:42)
- **Rubric:** Pass 3 / 3.5 (URL read client-side and used), 3.6 (javascript: URI risk)
- **Evidence:**
  ```js
  const url = button.dataset.url;
  if (url) { this.trackClick(action + '_click', url); window.location.href = url; }   // :890-892
  ```
  ```twig
  <a href="{{ config.canonical_urls.hotline }}" ...>   {# and data-url="{{ suggestion.url }}" :42 #}
  ```
- **Rationale:** Every other URL in this widget is passed through `sanitizeUrl()` (which allows only http/https/mailto/tel + relative `/`/`#`, returning `'#'` for `javascript:`/`data:`/`vbscript:`) before use. This one navigation assigns `dataset.url` to `window.location.href` **without** that guard. The value is a server-rendered Twig suggestion URL (Twig HTML-escapes the attribute but does not block the `javascript:` scheme), and modern browsers block `javascript:` in a `location` assignment, so practical risk is low — but it deviates from the file's own defensive convention. LOW/defense-in-depth.
- **Remediation:** Route `button.dataset.url` through `this.sanitizeUrl()` before assigning `window.location.href`.

### SR-024
- **Severity:** LOW
- **Location:** web/modules/custom/ilas_adept/js/adept-tracking.js:226 (and navigation at :407)
- **Rubric:** Pass 3 / 3.5 (URL from drupalSettings used client-side), 3.6 (javascript: URI risk)
- **Evidence:**
  ```js
  link.href = unmetPrereqs[j].url;   // :226 — url from drupalSettings.ilasAdept.currentLesson.prereqs
  window.location.href = href;       // :407 — href = existing anchor's resolved .href
  ```
- **Rationale:** `unmetPrereqs[j].url` (from `drupalSettings`, server/admin-populated lesson node URLs) is assigned to an `<a href>` with no scheme validation; line 407 navigates to a DOM-resolved anchor href, also unvalidated. Both sources are server/admin-controlled (not anonymous input), and browsers block `javascript:` navigation via `location`, so practical risk is low. LOW/defense-in-depth, recorded for consistency with the assistant widget's `sanitizeUrl` convention.
- **Remediation:** Add a `javascript:`/`data:`-scheme guard (or reuse a `sanitizeUrl` helper) before assigning `link.href` / navigating.

---

## Pass 3 Log

### Pass 3A — XSS / Output Encoding: b5subtheme Twig (`|raw`) + module Twig

Scope: every `|raw` filter in custom Twig (grep-driven, rubric 3.1), each dispositioned
individually by tracing the emitted variable to its assignment (template `{% set %}` and
theme preprocess). Six template families carry `|raw`; each variable was traced to either
a rendered Views field-formatter (Drupal-escaped markup) or an in-template `|e('html')`
escape. Finding SR-019.

Key determinations (evidence-backed):
- **events--all / press-room-listing / reports-publications-listing / search** — every
  `|raw` variable is `{% set X = fields.FIELD.content|render|trim %}`, i.e. **rendered
  field-formatter output** (SmartDate, `basic_string` with Views `strip_tags:true`,
  `text_trimmed` via `check_markup`, `entity_reference_label`/`entity_reference_entity_view`
  image render). No custom preprocess touches these variables (grep-confirmed). Search
  `excerpt_clean` is the `search_api_excerpt` field with the Views handler set to
  `filter_type: xss` (`Xss::filterAdmin`) applied before render — reflected query terms are
  escaped; residual: safety depends on that config staying `xss`. Safe.
- **node--office-information** — `processed_text` derives from editor field
  `field_legal_advice_line->value` but is `|e('html')`-escaped in-template BEFORE any static
  HTML (hardcoded tel-links, `</p><p>`) is added; no editor byte reaches output unescaped. Safe.
- **announcement-overlay-popup** — the sole unsafe site: `item.value|raw` prints a
  `text_long` field's **raw stored `->value`** (from `->getValue()` in the block), bypassing
  `check_markup` → **SR-019 (MEDIUM)**, editor-gated stored XSS.

| File | 3.1 raw | 3.2 markup | 3.3 decode | 3.4 t() | 3.5 JS | 3.6 attr/href | 3.7 preprocess |
|---|---|---|---|---|---|---|---|
| views-view-fields--events--all.html.twig | ✓ (rendered formatters) | n/a | n/a | n/a | n/a | ✓ | ✓ |
| views-view-fields--press-room-listing.html.twig | ✓ (rendered formatters) | n/a | n/a | n/a | n/a | ✓ | ✓ |
| views-view-fields--reports-publications-listing.html.twig | ✓ (rendered formatters) | n/a | n/a | n/a | n/a | ✓ | ✓ |
| views-view-fields--search.html.twig | ✓ (`filter_type: xss`) | n/a | n/a | n/a | n/a | ✓ | ✓ |
| node--office-information.html.twig | ✓ (`|e('html')` first) | n/a | n/a | n/a | n/a | ✓ | ✓ |
| announcement-overlay-popup.html.twig | SR-019 | n/a | n/a | n/a | n/a | ✓ | n/a |

**PASS 3A COMPLETE**

### Pass 3B — XSS / DOM Sinks: b5subtheme JS

Scope: theme JS DOM sinks (rubric 3.5/3.6) — `innerHTML`/`.html()`/`insertAdjacentHTML`/
`append`/`prepend`/`location` — with `premium-application.js` (1789), `smart-faq-enhanced.js`,
`donation-inquiry.js`, `lazy-loading.js`, and navigation-assigning files as priorities.
Findings SR-021 (+ SR-023/024 recorded under module JS in 3D).

Key determinations (evidence-backed):
- **premium-application.js** — one exploitable path: `showJobClosedError` interpolates
  server-JSON `job_title`/`job_location` raw into an HTML string parsed by jQuery
  `.prepend()` → **SR-021 (MEDIUM)**. All other `.html()`/`.append()`/`.prepend()` are
  either fully static template literals (success panel :141, spinners :1345-1471, "no
  positions" :341-style), captured static button HTML (`originalBtnHtml`), or insert server
  data via `document.createTextNode`/`.text()`/`.val()` (draft status :1367-1388, job
  dropdown :404-411). `?submitted=success` navigation (:1554) is static.
- **smart-faq-enhanced.js** — clean: user `searchTerm` and server FAQ results inserted via
  `.text()`/DOM construction; highlighting uses `createTreeWalker` + `createElement` +
  `textContent`; no `.html()` (confirms the :393 "avoids .html() to prevent DOM XSS" comment).
- **donation-inquiry.js** — clean: all server messages/error items via `$('<el>').text()`;
  the `.prepend(\`…\`)` at :341 is a fully static warning; hrefs are static `mailto:`/`tel:`.
- **lazy-loading.js** — `element.innerHTML = ''` (:210) clears content, no injection.

| File | 3.1 raw | 3.2 markup | 3.3 decode | 3.4 t() | 3.5 JS | 3.6 attr/href | 3.7 preprocess |
|---|---|---|---|---|---|---|---|
| premium-application.js | n/a | n/a | n/a | n/a | SR-021 (job fields); rest ✓ | ✓ (static hrefs) | n/a |
| smart-faq-enhanced.js | n/a | n/a | n/a | n/a | ✓ (.text()/DOM) | ✓ (encodeURIComponent) | n/a |
| donation-inquiry.js | n/a | n/a | n/a | n/a | ✓ (.text()/DOM) | ✓ (static) | n/a |
| lazy-loading.js + remaining theme JS | n/a | n/a | n/a | n/a | ✓ | ✓ | n/a |

**PASS 3B COMPLETE**

### Pass 3C — XSS / Output Encoding: b5subtheme.theme + module PHP render output

Scope: PHP HTML sinks (rubric 3.2/3.3/3.4/3.7) — `#markup`/`Markup::create`/
`FormattableMarkup` across `b5subtheme.theme`, `ilas_hotspot`, `employment_application`,
`ilas_site_assistant` controllers/forms — each interpolated variable traced to origin;
plus decode-then-trust (3.3), `t()` placeholders (3.4), and request-input preprocess (3.7).
Findings SR-020, SR-022.

Key determinations (evidence-backed):
- **b5subtheme.theme** — `:522` `Markup::create(json_encode(..., JSON_UNESCAPED_SLASHES))`
  in a `<script>` with editor-controlled hero `alt` → **SR-020 (MEDIUM)**, `</script>`
  breakout. `:693` `FormattableMarkup` uses `@url`/`@name` escaping placeholders → safe.
  `:287` `?submitted` is `=== 'success'` boolean only (never reflected); success-page
  session vars render via default Twig autoescape → safe (3.7).
- **ilas_hotspot** — `IlasHotspotBlock:150` concatenates a system file URL unescaped into
  an `#markup` href → **SR-022 (LOW)**; sibling button `:160` does it correctly.
- **employment_application** — admin detail/list `#markup` (`:2020-2229`) and the one
  `Markup::create` (`:2157`) escape **every** dynamic part (applicant `full_name`, `email`,
  uploaded `filename`, `status`, ids) with `htmlspecialchars(…, ENT_QUOTES, 'UTF-8')` →
  safe, incl. the anonymous-controlled applicant fields.
- **ilas_site_assistant** — `AssistantReportController:882` (`$html`) interpolates admin
  config values, all `htmlspecialchars`-wrapped; its `t()` calls use literal strings or
  `@`-placeholders (`:555/751` `@class @error_signature`) → safe. `AssistantSettingsForm:390`
  (`buildLegalServerRuntimeNotice`) returns literal `t()` + fixed-vocabulary `@summary`
  (URL never emitted); `:547` htmlspecialchars → safe.
- **decode-then-trust (3.3)** — `html_entity_decode` in `OfficeDirectory:393/410`,
  `ResourceFinder:2372`, `SentryOptionsSubscriber:868` are server-side plaintext extraction
  (`strip_tags`→decode→text/log data), not browser HTML output → not XSS.
- **t() placeholders (3.4)** — no `!`-raw placeholders anywhere in custom `t()`/
  `TranslatableMarkup` (grep clean).

| File | 3.1 raw | 3.2 markup | 3.3 decode | 3.4 t() | 3.5 JS | 3.6 attr/href | 3.7 preprocess |
|---|---|---|---|---|---|---|---|
| b5subtheme.theme | n/a | SR-020 (:522); :693 ✓ | n/a | ✓ | n/a | ✓ | ✓ (:287 bool) |
| ilas_hotspot/IlasHotspotBlock.php | n/a | SR-022 (:150) | n/a | ✓ | n/a | SR-022 | n/a |
| employment_application/EmploymentApplicationController.php | n/a | ✓ (all htmlspecialchars) | n/a | ✓ | n/a | ✓ | n/a |
| ilas_site_assistant/AssistantReportController.php | n/a | ✓ (htmlspecialchars/@) | n/a | ✓ | n/a | ✓ | n/a |
| ilas_site_assistant/Form/AssistantSettingsForm.php | n/a | ✓ (t()/htmlspecialchars) | n/a | ✓ | n/a | ✓ | n/a |
| OfficeDirectory / ResourceFinder / SentryOptionsSubscriber | n/a | n/a | ✓ (plaintext extract) | ✓ | n/a | n/a | n/a |
| ilas_seo.module | n/a | ✓ (Json::encode HEX, SR-014) | n/a | ✓ | n/a | ✓ | ✓ (:82/321 noindex bool) |

**PASS 3C COMPLETE**

### Pass 3D — XSS / DOM Sinks: ilas_site_assistant JS widgets + remaining module JS

Scope: the AI assistant response-rendering widget (`assistant-widget.js` 2844,
`observability.js` 336) plus remaining module JS (`ilas_hotspot.js`, `ilas_adept.js`).
The assistant widget renders LLM/server JSON into the DOM and was the highest-value target.
Findings SR-023, SR-024.

Key determinations (evidence-backed):
- **assistant-widget.js** — SAFE for content injection. Every server-JSON field (message
  text :2017, faq question/answer, resource title/description, service-area/method labels,
  suggestion labels) is rendered via `createElement`+`element.textContent` or `String(...)`
  → `textContent`, never `innerHTML`. Every URL field (faq.url, resource.url, fallback_url,
  primary_action, links[].url) flows through `createTrackedLink` → `sanitizeUrl()`
  (allowlist http/https/mailto/tel + relative; returns `'#'` for `javascript:`/`data:`/
  `vbscript:`). The lone `container.innerHTML = getWidgetHTML()` (:737) is a static template
  with only `Drupal.t()` + `escapeHtml`/`escapeAttr`/`sanitizeUrl`-guarded config; the other
  `innerHTML` writes (:60/2190/2197/2235) are static icon markup; `:2774 return div.innerHTML`
  is the textContent→innerHTML **escaping primitive**. The one gap: `:892
  window.location.href = button.dataset.url` skips `sanitizeUrl()` → **SR-023 (LOW)**.
- **ilas_hotspot.js** — `:185 tempEl.innerHTML = content` where `content` is server-rendered
  `data-bs-content` = `Xss::filterAdmin($hotspot['content'])` (admin config, sanitized), AND
  written to a **detached** div from which only `.textContent` is read (never inserted live).
  Two independent protections → safe.
- **ilas_adept.js** — `:497/504` innerHTML is static badge markup + `Drupal.t()` → safe;
  `:226 link.href` / `:407 window.location.href` assign drupalSettings/DOM-resolved URLs with
  no scheme guard → **SR-024 (LOW)**, server/admin-sourced.

| File | 3.1 raw | 3.2 markup | 3.3 decode | 3.4 t() | 3.5 JS | 3.6 attr/href | 3.7 preprocess |
|---|---|---|---|---|---|---|---|
| assistant-widget.js | n/a | n/a | n/a | n/a | ✓ (textContent/sanitizeUrl); static innerHTML | SR-023 (:892 nav) | n/a |
| observability.js | n/a | n/a | n/a | n/a | ✓ (no HTML sink) | ✓ | n/a |
| ilas_hotspot.js | n/a | n/a | n/a | n/a | ✓ (detached div + Xss::filterAdmin) | ✓ | n/a |
| ilas_adept/adept-tracking.js | n/a | n/a | n/a | n/a | ✓ (static innerHTML) | SR-024 (:226/:407) | n/a |

**PASS 3D COMPLETE**

---

## Pass 3 Summary — XSS / Output Sanitization (complete: 3A–3D)

All custom Twig, PHP render output, and JS DOM sinks reviewed per rubric 3.1–3.7.
6 findings recorded (SR-019 – SR-024): none CRITICAL or HIGH; **no anonymous-injectable
XSS** — all three MEDIUMs require editor/admin write access and land on an anonymous victim.

- **MEDIUM (3):** SR-019 (announcement overlay `item.value|raw` bypasses `check_markup`
  → editor-gated stored XSS on front page), SR-020 (hero rotator `json_encode` with
  `JSON_UNESCAPED_SLASHES` only → `</script>` breakout via editor `alt`), SR-021 (job-closed
  error panel interpolates server-JSON `job_title`/`job_location` into jQuery-parsed HTML
  → editor-gated stored XSS for anonymous applicants).
- **LOW (3):** SR-022 (`ilas_hotspot` unescaped file URL in `#markup` href; sibling button
  correct — dispositions SR-018 xref), SR-023 (`assistant-widget.js` `data-url` navigation
  skips `sanitizeUrl`), SR-024 (`adept-tracking.js` prereq/download hrefs without scheme guard).

Recurring themes for remediation batching: (1) SR-020 shares the JSON HEX-flag fix already
applied in `ilas_seo` (`Json::encode`, SR-014); (2) SR-019/SR-021 are both "emit stored/
returned raw field `->value` as HTML" — fix by routing through `check_markup`/`processed_text`
(server) or `.text()`/escape (client); (3) SR-022/023/024 are unescaped-URL-in-sink
consistency gaps where a correct sibling pattern already exists in the same file.

Positive controls confirmed: rendered Views field formatters + `check_markup`/`filter_type: xss`
for all safe `|raw` sites; `htmlspecialchars(ENT_QUOTES)` throughout `employment_application`
admin pages (incl. anonymous applicant data); `assistant-widget.js` `textContent` +
`sanitizeUrl()` construction for all LLM/server response rendering; `Xss::filterAdmin` +
detached-element handling in `ilas_hotspot.js`; no `!`-raw `t()` placeholders anywhere.

Deferred/cross-referenced: Pass 4 (access-control/CSRF), Pass 5 (secrets/PII), Pass 6
(test fixtures). SR-015 (Pass 2) draft-reload rendering: the reloaded `form_data` is
rendered by the premium-application wizard via jQuery `.val()`/form value assignment
(verified in 3B — the draft-status and field-population paths use `.val()`/`.text()`),
so the SR-015 cross-reference to Pass 3 is clean.

**PASS 3 COMPLETE**
