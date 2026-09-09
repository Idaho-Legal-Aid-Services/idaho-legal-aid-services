# Sentry active-error review — 2026-09-09

Org `idaho-legal-aid-services`, project `php` (PHP + browser events share it). Snapshot taken 2026-09-09 ~18:30 UTC via the REST API (read-only). Previous triage: 2026-07-20/21 (see memory `sentry-setup.md`). Releases: `live_183` deployed 2026-07-30, `live_184` deployed 2026-08-27.

## Summary

| Bucket | Issues | Notes |
|---|---|---|
| Unresolved total | 177 | 0 ignored, 1 resolved in 14d window |
| Active (seen ≤30 days) | 46 | Reviewed individually below |
| Stale (silent >30 days) | 131 | 64 are CSP "Blocked …" reports (all silent since 07-17); 67 other. Safe to bulk-resolve. |
| Every July 2026 fix held | — | PHP-5H, 2N, 6B, 8Q, Z, 2M, 4S, 9C/9J, 8G, 8H all silent after their fix/deploy date |

**Six things need a decision or a code change** (A1–A6). Everything else active is third-party noise that should be filtered at the SDK so it stops reaching Sentry, or is known telemetry. A5 was re-diagnosed later the same day: it is a Cloudflare bot-protection setting breaking pages for public visitors, not admin noise.

## A. Actionable — new since the July triage

### A1. Dev LLM circuit-breaker loop is back with a different cause (PHP-9P, PHP-8N, PHP-8P, PHP-9N)

| ID | Level | Env | Events (14d) | Last seen | Title |
|---|---|---|---|---|---|
| [PHP-9P](https://idaho-legal-aid-services.sentry.io/issues/7647740617/) | error | dev | 45 (18) | 09-06 | Request-time LLM intent classification failed: RuntimeException (HTTP none) |
| [PHP-8N](https://idaho-legal-aid-services.sentry.io/issues/7600884006/) | warning | dev | 7 (2) | 09-06 | LLM circuit breaker opened after 3 consecutive failures within 60 s |
| [PHP-8P](https://idaho-legal-aid-services.sentry.io/issues/7600884076/) | warning | dev | 215 (14) | 09-06 | Skipping request-time LLM classification because the circuit breaker is open |
| [PHP-9N](https://idaho-legal-aid-services.sentry.io/issues/7647739579/) | warning | dev | 5 (2) | 09-06 | Per-IP LLM budget exhausted for identity … (10/10) |

**Diagnosis (confirmed in code, not just correlation).** The July Cohere `response_format` fix held: PHP-6B (ClientException) went silent 07-30. The new driver is `RuntimeException (HTTP none)`, and the only RuntimeExceptions the enhancer throws with no HTTP status are budget denial, non-array payload, and "failed after retries". PHP-9N fires in the same minute-window every time (09-06: budget exhausted 10:29 → breaker opened 10:39 → 9P at 10:48). In `LlmEnhancer::classifyIntent` the cost-control check inside `completeStructuredRequest` throws `\RuntimeException('Request-time LLM budget exceeded: …')` from *inside* the `try` block, so the `catch (\Throwable)` logs it as an error and calls `circuitBreaker->recordFailure()`. Three budget denials from one identity within 60 s (eval/CI traffic against dev, single source IP) opens the breaker for everyone. A client-side admission decision is being counted as an upstream transport failure.

**Fix (implemented 2026-09-09).** Admission denials now throw `LlmAdmissionDeniedException`, caught by type ahead of the generic catch in `LlmEnhancer::classifyIntent()`: logged at NOTICE, `meta.generation.reason` carries `budget_<reason>`, and `recordFailure()` is never called. The "skipping … circuit breaker is open" warning is emitted once per open window (keyed by the breaker's `opened_at`), NOTICE thereafter. Coverage: `tests/src/Unit/LlmEnhancerAdmissionDenialTest.php`. Traffic source: the Sunday 10:00 UTC `assistant-nightly-quality` workflow (485 serial messages from one runner IP) plus Dependabot-triggered PR gates on Thursdays; trusted eval traffic (`X-ILAS-Eval-Run-ID`) on dev/test is now exempt from the per-identity budget only (`LlmEvalTrafficPolicy`, kill switch `cost_control.eval_per_ip_budget_exempt`), and the promptfoo provider records `generation.reason` so weekly results show whether cases ran on the LLM path. Live is unaffected today because request-time LLM is disabled there; this fix is the prerequisite for enabling it.

### A2. Source-governance stale ratio on LIVE: 27 of 34 sources are "stale" (PHP-9Z)

[PHP-9Z](https://idaho-legal-aid-services.sentry.io/issues/7657016045/) · warning · live · 20 events (9 in 14d) · first 08-06, last 09-07 · releases live_183/184.

**Diagnosis.** This is the real version of the July PHP-4S notice (that one was below `min_observations`; this one is 34 ≥ 20, so it is a genuine alert). Freshness is computed in `SourceGovernanceService::annotateResult` as `changed`/`updated_at` older than `max_age_days: 180` (`config/ilas_site_assistant.settings.yml` L214–229, alert threshold `stale_ratio_alert_pct: 18.0` L205). The site's resource content was migrated/created around Feb 2026 and largely untouched since, so it crossed 180 days in early August, which is exactly when this started. The assistant is now flagging ~80 % of cited sources as stale on every live conversation.

**Decision needed.** (a) Content ops does a review pass and re-saves/updates resources (the intended path), or (b) freshness should key off an explicit review-date field rather than `changed`, or (c) raise `max_age_days` for the affected source classes. Recommend (b) long-term with (a) now; (c) just hides it.

**Decision / Fix (implemented 2026-09-09).** (b) implemented, (a) is the rollout, (c) rejected. Live snapshot on 09-09: 28/36 stale (77.8%); by class resource_lexical 17/23, faq_vector 6/8, faq_lexical 2/2, resource_vector 3/3; entity_query only 2/36, so media PDFs are not the driver. New date-only `field_last_reviewed` on `resource`, `standard_page`, `legal_content`, `get_involved`, `donate` (translatable, no default, hidden in view displays, grouped into a "Content review" sidebar details). Freshness = `max(changed, field_last_reviewed)` via `SourceGovernanceService::buildEntityFreshness()` / `classifyFreshness()`; every retrieval path (`ResourceFinder` ×3, `FaqIndex::getParentInfo`) now carries `reviewed_at`, and `freshness` reports `basis` (`reviewed`/`changed`). Future-dated reviews are ignored. `FaqIndex` caches gained the `node_list` tag so a host-node attestation takes effect immediately. The alert template now ends `never_reviewed N; by class: …` and the snapshot/metrics carry `never_reviewed`, so the next event says what to review; the template change means PHP-9Z will be superseded by a new Sentry issue and must be resolved by hand. `/admin/reports/ilas-assistant` gained a "Source freshness (content review queue)" table (stale first, per translation, edit links). The widget now renders the existing `freshness_caveat` (it was emitted but never shown). `max_age_days` stays 180. Rollout: deploy, then content ops sets Last reviewed + publishes on each stale row; the alert keeps firing until that pass completes, which is correct. Coverage: `SourceGovernanceServiceTest` (+16 tests), `FreshnessCaveatWidgetContractTest`, kernel `AssistantRetrievalGroundingKernelTest::testReviewAttestationKeepsUnchangedContentFresh`.

### A3. Our own JS scrubber can recurse forever (PHP-AJ)

[PHP-AJ](https://idaho-legal-aid-services.sentry.io/issues/7702946198/) · error · live · 1 event · 08-31 · `RangeError: Maximum call stack size exceeded` in footer aggregate → `scrubValue` ↔ `Array.forEach`.

**Diagnosis.** `scrubValue()` in `web/modules/custom/ilas_site_assistant/js/observability.js` (L25–48) walks objects recursively with no cycle guard and no depth limit. A browser extension ("Affirm Extension" console breadcrumb) injected a cyclic object into the captured event, and the scrubber blew the stack inside Sentry's `beforeSend`, which also means that event was lost.

**Fix (implemented 2026-09-09).** `scrubValue()` now carries an ancestor stack (ES5-safe, flags only true cycles so shared references still scrub normally) and a `MAX_SCRUB_DEPTH = 10` cap, returning `'[Circular]'` / `'[Truncated]'`. Landed together with A6 because it is the same function. Coverage: `tests/js/node/observability-noise-filter.test.mjs` (cyclic breadcrumb payload, shared refs, 20-deep nesting) and `tests/js/node/observability-scrub-guards.test.mjs` (cyclic / over-deep / 6000-wide input through both the `ilas:assistant:error` detail path and the event processor). Note the guard runs inside the `addEventProcessor` callback that observability.js installs, not a `beforeSend` option. Resolve PHP-AJ after the next live deploy.

### A4. Stale vectors in the shared Pinecone namespace surface as "could not load" (PHP-28)

[PHP-28](https://idaho-legal-aid-services.sentry.io/issues/7341743928/) · warning · 32 retained events (94 lifetime) · last 09-09 · `search_api: Could not load the following items on index %index: @items.`

**This issue groups on the message template, so it mixes unrelated causes.** Splitting the retained events by index and environment (2026-09-09):

| Index | Env | Events | SAPI | What it is |
|---|---|---|---|---|
| FAQ Accordion (Vector) | live | 13 | web | `entity:paragraph/402:en`, 07-21 → 09-05, in pairs per request |
| FAQ Accordion (Vector) | dev | 2 | web | `entity:paragraph/439:en`, `453:en` on 07-12 |
| Content / Content (Solr) / Assistant Resources (Vector) / Assistant Resource Finder | test, dev | 17 | cron | nodes 170–188, batches on 07-15, 07-20, 08-14, 08-16, 08-24, 09-09 |

**The original diagnosis ("paragraph 402 was deleted, tracker row orphaned") was wrong.** Read-only checks on live/test/dev/DDEV: paragraph 402 exists everywhere (Spanish `external_resource` paragraph under node 102), and no `search_api_item` row on any environment references it. The index only tracks `accordion_item`/`faq_item` paragraphs, so `ContentEntity::loadMultiple()` correctly refuses `402:en` on bundle and on the missing `en` translation. The ID comes back from **Pinecone at query time**: `FaqIndex::searchVector()` → `Item::getOriginalObject()` → `Index::loadItemsMultiple()` logs the warning and, because `delete_on_fail` is on, asks the backend to delete the item. Pinecone still holds `entity:paragraph/402:en:0`, `439:en:0`, `453:en:0` with metadata `paragraph_type=accordion_item`, i.e. vectors written by some other environment's content (every environment shared one namespace).

**Root cause.** `PineconeProvider::deleteItems()` resolves vector IDs with an exact-ID `fetch` of the Search API item ID, but the AI Search backend stores vectors as `<item id>:<chunk>`. The fetch finds nothing, so every per-item delete (Search API self-heal, entity deletes, and the delete-before-upsert on re-index) has been a silent no-op since launch. Upstream 1.1.x and 2.0.x carry the same code.

**Why the proposed one-liner would not have worked.** `search-api:reset-tracker` only runs `UPDATE search_api_item SET status=0`; it cannot remove rows and never touches Pinecone. `search-api:index` re-embeds everything and leaves the stale chunk. The `sapi-c` alternative calls `deleteAllFromNamespace`, which on test or dev would have wiped live's vectors because the namespace was shared.

**Fix (this branch).**
- `patches/ai-vdb-provider-pinecone-delete-chunk-ids.patch`: `getVdbIds()` lists vector IDs by prefix `<item id>:` (new `ListVectors` request against the serverless list endpoint) and keeps the exact-ID fetch as a fallback; `deleteItems()` chunks by 1000. Guarded by `PineconeDeleteChunkContractTest`.
- `web/sites/default/settings.php`: non-live environments (Pantheon dev/test/multidev, DDEV) now use `faq_accordion_vector-<env>` / `assistant_resources_vector-<env>`; live keeps the committed names. A reindex or clear off live can no longer touch live's vectors.
- `scripts/vector/pinecone-list-prefix.php`: read-only inventory by prefix (`auto:faq` / `auto:resource` resolve the effective namespace).
- Rollout: after deploy, backfill dev and test into their new namespaces, then rebuild live once with `ilas:vector-backfill faq_vector --clear-first --until-complete` and `resource_vector` (see `docs/aila/runbook.md`, "Stale vectors and per-environment namespaces"). The live rebuild removes the three stale chunks and anything dev/test/DDEV wrote over the shared namespace (1060 vectors held for 887 tracked items before the rebuild).

**The cron-time rows are a different, benign thing.** Nodes 170–188 on test (created 08-24 15:25 by uid 1, one "test" node per content type) and their dev counterparts (08-14, 08-16) were manual QA nodes that were then trashed. `trash` soft-deletes, which fires `entityUpdate` rather than `entityDelete`, so the rows stayed tracked until cron failed to load them and `delete_on_fail` dropped them. Test drained the last nine on 09-09 18:46 after weeks with no cron (environment asleep). Trackers on all three environments are clean. Follow-up (low priority): a `hook_entity_update` that untracks nodes when `deleted` becomes set would remove trashed content from Solr/DB/vector indexes immediately instead of on the next cron.

### A5. Cloudflare challenges CSS/JS/image subrequests for real visitors (PHP-AQ, PHP-AP, PHP-A5) — REVISED 2026-09-09

| ID | Env | Events | Last | Where |
|---|---|---|---|---|
| [PHP-AQ](https://idaho-legal-aid-services.sentry.io/issues/7722279454/) | live | 1 | 09-09 17:47:47 | `/admin/content`: "The following files could not be loaded: /sites/default/files/css/css_cqJQ…?delta=0&amp;language=en&amp;theme=gin&amp;include=…" |
| [PHP-AP](https://idaho-legal-aid-services.sentry.io/issues/7722279017/) | live | 1 | 09-09 17:47:25 | `/admin/dashboard`: ReferenceError: Drupal is not defined (aggregate line 3) |
| [PHP-A5](https://idaho-legal-aid-services.sentry.io/issues/7671943043/) | dev | 2 | 08-14 14:27 | `/node/add/*`: ReferenceError: Drupal is not defined (aggregate line 3) |

**The first draft of this item was wrong on both mechanism and scope.** It read the `&amp;` in the failed URL as a double-encoded aggregate URL and filed the whole thing as admin-only noise. Neither holds:

- The `&amp;` is produced by core itself. `ajax.js` `add_css` builds the message with `Drupal.t('… @dependencies', …)` and the `@` placeholder runs through `Drupal.checkPlain`, which turns `&` into `&amp;` (`web/core/misc/drupal.js` L244–252 and L282–283; `web/core/misc/ajax.js` L1754–1759). The AJAX `add_css` command carries attribute arrays as JSON, so the `href` the browser fetched had a plain `&`. There is no double-encoding and no core patch to look for.
- The two live events are the visible tip of a site-wide problem that mostly hits anonymous visitors, who have no Sentry init running early enough to report it.

**Diagnosis (verified against Cloudflare's firewall log, not inferred).** Super Bot Fight Mode has *Static resource protection* enabled (`bot_management.sbfm_static_resource_protection: true`, likely-automated → `managed_challenge`, definitely-automated → `block`). Rule `023ec3b3a7f5…` "Manage likely bots for static resources" challenged three asset requests from the reviewer's own browser (Cable One, Windows/Chrome 152, uid 1):

| UTC | Asset challenged (403 + challenge HTML) | Sentry event |
|---|---|---|
| 17:47:23 | `js_m2yIRZ…?scope=footer&delta=1&theme=gin` — first footer aggregate: once, backbone, **drupal.js**, drupal.init.js, … | PHP-AP at 17:47:25. Line 3 col 3564 of the delta-7 aggregate is `})(jQuery, Drupal, drupalSettings)` at the end of `toolbar.menu.js`; `jQuery` survived because core ships `jquery.min.js` with `preprocess: false` (own `<script>`, browser-cached) |
| 17:47:46 | `css_cqJQ…?delta=0&theme=gin&include=core/drupal.reset-appearance` — BigPipe-streamed `add_css` for Claro's local-task tabs on `/admin/content` | PHP-AQ at 17:47:47 |
| 17:47:54 | header aggregate delta 1 on the `/admin/content` reload | none (Sentry is not initialised until the footer) |

A `<script>`, `<link>` or `<img>` fetch cannot render or solve a managed challenge, so the browser receives the challenge page body with a 403 and the script never executes; every later script that names `Drupal` bare then throws. Cloudflare only injects its JavaScript detections into HTML responses, so subrequests carry no detection signal, which is why real browsers score "likely automated" on CSS/JS. In the same minute the sibling rule `5ac94856…` "definite bots for static resources" blocked a `symbolicator/26.8.0` request from a Google ASN: Sentry's source fetcher. That is why live JS events have no source context while dev events do.

**Scale.** Firewall events from the two static rules, browser user agents only, on render-critical paths (Drupal aggregates, module/theme JS+CSS, image styles, images, fonts):

| Day | Distinct visitor IPs challenged | Aggregate | Module/theme JS-CSS | Image/font |
|---|---|---|---|---|
| 09-07 | 210 | 90 | 31 | 319 |
| 09-08 | 330 | 208 | 71 | 507 |
| 09-09 | 288 | 168 | 46 | 417 |

Top networks: Verizon, Comcast, AT&T, Charter, Cox, T-Mobile, Cable One. These visitors got unstyled or non-functional pages. A further ~110 browser-UA IPs/day are blocked on PDFs/DOCs by the definite-bots static rule, which is mostly doing its job (xAI-PDF-Recovery and similar) but also catches browsers.

**PHP-A5 (dev) is a different event.** Two hits on 08-14 14:27 UTC from uid 1 in Chisinau (the maintenance agency's session, four days after their dev code push). Dev is on `pantheonsite.io` with no Cloudflare in front, so the static-resource rule cannot be the cause there; Pantheon's August logs are gone, so it stays unexplained. Leave it open; it reopens as a regression if it recurs.

**Fix (decided 2026-09-09, applied by hand in the Cloudflare dashboard).** Security → Bots → Configure Super Bot Fight Mode → *Static resource protection: Off*. HTML pages, `/search`, `/assistant/*` and the auth routes keep every existing protection; documents lose only the SBFM definite-bots block, while the AI-crawler block, `ai_training=block`, the verified-bot policy and the rate limit stay. `scripts/observability/cloudflare-security-action-items-check.sh` now prints `sbfm_static_resource_protection` (must be `false`) and a 24h count of browser clients challenged on render assets (must be 0). The narrower alternative, a custom Skip rule in phase `http_request_sbfm` for asset paths, was rejected because any path missed from the list still breaks pages.

**Verification.** 24h after the change: checker script shows `sbfm_static_status=ok` and `static_render_asset_status=ok`; `/admin/dashboard` and `/admin/content` load with no 403 on `/sites/default/files/{css,js}/` in DevTools. After 7 quiet days: resolve PHP-AQ and PHP-AP. Then revisit `web/modules/custom/ilas_seo/js/sentry-filters.js`: its `ignoreErrors` entry for `jQuery is not defined` (Sentry #7364900490, attributed to Baiduspider-render) may have been hiding the same challenge landing on `jquery.min.js` for real visitors, and can probably be removed to regain that signal.

### A6. Widget error reports are titled `[REDACTED]` (PHP-1M)

[PHP-1M](https://idaho-legal-aid-services.sentry.io/issues/7339281035/) · error · live · 45 events since March · last 09-02 · title `[REDACTED]`.

**Diagnosis.** This is AILA's own `Sentry.captureMessage('AILA browser error')` from `observability.js` L298; the message scrubber replaces the whole message. The event context says: feature `quick_action`, status `0`, breadcrumbs show `POST /assistant/api/track` and `/assistant/api/message` both failing with no response, on Chrome Mobile / Android. So it is "widget request failed at the network layer" (mobile connection drop), about once a week. Not a server bug, but the title makes it untriageable. Fix: keep the constant message string out of scrubbing (scrub params, not the template) and put `status`/`feature` in the title or fingerprint.

**Fix (implemented 2026-09-09).** Root cause confirmed: browser events never pass through PHP (no raven `tunnel`), and the JS event processor ran the key-name redactor `scrubValue()` over the whole Sentry envelope, so `event.message` — and every `breadcrumbs[].message`, including the `ui.click` selector — became the literal `[REDACTED]`. `observability.js` now scrubs the envelope with `scrubEvent()`: template fields (`message`, `logentry.message`, `breadcrumbs[].message`, `fingerprint`) get pattern scrubbing only (email/bearer/UUID/SSN/query params) while `contexts`, `extra`, `request` and the widget payload keep the key-based redaction. `emitAssistantError` now titles each capture `AILA browser error: <feature> (<class>)` and sets `fingerprint = ['aila-browser-error', feature, class]`, where class is the server `error_code` token, else `offline`/`timeout`, else `http_<status>`, else `network` (the status-0 case here). Only code-owned tokens (`/^[a-z0-9_]{1,64}$/i`) can reach the title. Tags: `error_code` is the class (no more `unknown` for status 0) and new `assistant_status` carries the numeric status as a string. Coverage: `tests/js/node/observability-assistant-error.test.mjs` + `observability-noise-filter.test.mjs` (new `node --test` suites, `npm run test:assistant:js`, wired into `quality-gate.yml` and `gate:github-local`; the previous jest-style files were never runnable because `jest` was never installed) and `ObservabilityBrowserAssetContractTest`. After the next live deploy, resolve PHP-1M; the network-drop events will reopen as `AILA browser error: quick_action (network)` (one issue per feature × class).

## B. Previously fixed, still firing?

None regressed. Every item from the July triage is silent after its deploy: PHP-5H (last 07-08), 2N, 6B (07-30), 8Q (07-28), Z (07-23), 2M (07-26), 4S (07-30), 9C (07-29) / 9J (07-29, patch deployed with live_182 on 07-29), 8G/8H (07-07). The one nuance is A1 above: 8N/8P are still firing but with a new cause.

## C. Third-party browser noise — filter at the SDK, not by hand

None of these are our code. There are no `ignore_errors` / `deny_urls` entries in the raven config in `web/sites/default/settings.php` today, so all of it lands in Sentry as `error`. Recommend adding browser-side filters once, then resolving these.

| Cluster | IDs | Events | Root | Filter |
|---|---|---|---|---|
| Cloudflare RUM beacon on old browsers (`Array.prototype.at` / `findLast` missing: KaiOS, Amazon Silk, old Chrome Mobile) | 9S, AG, AH, AD, 9R, 9T | 16 | `/beacon.min.js/v…` | `denyUrls: [/beacon\.min\.js/]` |
| Chrome-for-iOS / Google-app injected scripts (identical line numbers 187–226 / 194–460 across different pages ⇒ injected, not page HTML) | AK, AB, AC, 37, AM, AN, A1 | 40 | inline, filename = page URL | ignoreErrors `/^(La|Ba)$/`; or drop when browser ∈ {Chrome Mobile iOS, Google} and all frames' filename equals page URL |
| Facebook in-app browser (Android) `iabjs://navigation_performance_logger_android` | 9B, A4 | 8 | `Java object is gone` | `denyUrls: [/^iabjs:\/\//]` |
| Browser extensions: MetaMask, ad-adjust fetch wrapper, Safari `runtime.sendMessage`, Affirm | 92, 9G, A3, 9F | 36 | `chrome-extension://`, `injectScriptAdjust.js` | `denyUrls: [/^chrome-extension:\/\//, /^moz-extension:\/\//, /^safari-web-extension:\/\//]` + enable Sentry's built-in "browser extensions" inbound filter |
| Outlook SafeLinks / Edge read-aloud `Object Not Found Matching Id` | A0 | 2 | Microsoft injected | ignoreErrors `/Object Not Found Matching Id/` |
| Misc one-offs: `Can't find variable: _G` (AE), `Load failed` (AA), CustomEvent unhandledrejection (1E), WebSocket CONNECTING in `/asset.js` (A6) | AE, AA, 1E, A6 | 12 | injected / network abort | resolve; revisit if they recur |

## D. Known telemetry and bot traffic — leave open, no action

| ID | Env | 14d | What | Why it is fine |
|---|---|---|---|---|
| 8J / 11 | live+dev | 3 | 403 on `/admin/reports/ilas-assistant` | Access control working; bots + non-privileged users |
| 9H | dev 10 / live 3 | 0 | 403 on `/admin/dashboard?check_logged_in=1` | Post-login redirect for a role without dashboard access; cosmetic. If it annoys, set a per-role login destination |
| 3D | dev 18 / live 1 | 3 | Vector FAQ search >3.5 s, backoff | Dev latency telemetry; live hit once |
| 2J | dev | 1 | Vector FAQ search FatalRequestException (Saloon) | Dev Pinecone transport blip, 3 events since 08 |
| 38 | live | 1 | Employment app: invalid Content-Type from one IP | Bot posts, guard working |
| 57 | live/dev | 1 | csrf_deny on `/assistant/api/message` (headless Chrome 152, Linux) | Bot; guard working |
| 32 | live | 0 | oEmbed `This resource is not available` (33 total) | Bots hitting `/media/oembed` with bad hashes. Could add to before_send drops |
| A2 | live | 0 | Turnstile: response already validated | Double-submit, 1 event |
| 6G | live | 1 | `Cron run failed` — "Attempting to re-run cron while it is already running" | Overlapping cron trigger (4 since May). Watch; if it climbs, check cron lock TTL vs. run length |
| AF, A7, A9, A8, 95 | live | ~0 | Sentry performance issues ("Blocking Operation", "Degraded UI Performance") | Single-transaction performance detections, no user impact |

## E. Stale — safe to bulk-resolve (131 issues, ~125k historical events)

All silent for more than 30 days. Resolving them makes the unresolved list reflect reality; anything that recurs will reopen automatically as a regression.

**64 CSP "Blocked …" reports** (all last seen ≤ 2026-07-17; the report-uri was removed 07-20):
PHP-5W 44 60 24 3Y 4T 22 5S 6C 7F 74 2K 2W 1N 1V 3H 2P 25 2A 1P 21 7G 34 7M 23 5Z 3C 2Z 2B 7A 2C 29 7K 79 4Z 4R 40 7Y 7T 7N 5Y 4N 3F 39 8B 8A 89 86 85 83 7X 7Q 7P 7J 7C 6J 61 51 4W 4H 4G 45 3K 2X

**67 other stale issues** (July deploy transients, fixed items from the July triage, klaro EvalErrors, one-off JS errors, old perf detections):
PHP-5H 2M 9C 4S 6B 8Q 91 8G 93 6Q 11 9E 8H 90 8Z 8Y 9J Z 9R 9Y 9M 98 8M 7D 6Z 6H 20 A1 9X 9W 9V 9T 9Q 9K 9D 9A 99 97 96 94 8X 8W 8V 8T 8S 8R 8K 8F 8E 8D 8C 88 87 84 82 81 80 7Z 7W 7V 7S 7R 7H 7E 7B 72 5D

(Note: PHP-11 is in the stale list because its successor PHP-8J carries the live traffic now; PHP-9R/9T are stale members of the beacon cluster.)

Bulk-resolve is a write: `PUT /api/0/projects/idaho-legal-aid-services/php/issues/?id=…&id=…` with `{"status":"resolved"}`. It was permission-blocked in July and has not been run.

## Suggested order of work

1. A1 — budget denial must not trip the breaker (code + test). Blocks enabling LLM on live. DONE 2026-09-09.
2. A5 — turn off Cloudflare SBFM static-resource protection (dashboard). Visitor-facing: 200–330 IPs/day get challenge pages instead of CSS/JS/images.
3. A3 — cycle guard in `scrubValue` (tiny).
4. C — add `deny_urls` / `ignore_errors` to raven browser config, enable Sentry's browser-extension inbound filter.
5. A4 — deploy the Pinecone delete-chunk patch + per-environment namespaces, backfill dev/test, rebuild live (see A4).
6. A2 — ~~decide the freshness policy with content ops~~ code shipped 09-09; content ops review pass via the admin report is the remaining step.
7. E — bulk-resolve the 131 stale issues.
8. A6 — when convenient.

## Method

Issues API paginated with `statsPeriod=14d` (the only values the endpoint accepts are `''`, `24h`, `14d`), `is:unresolved` / `is:ignored` / `is:resolved`. The 46 issues seen in the last 30 days were enriched with `/issues/{id}/tags/` and `/issues/{id}/events/latest/`. Diagnoses for A1, A2, A3, A6 were verified against the code in this repo; A5 was verified against Cloudflare `firewallEventsAdaptive` / `httpRequestsAdaptiveGroups` for the exact second of each browser event (read-only token) and against core's `ajax.js` / `drupal.js`; the injected-script conclusion in C rests on identical stack line numbers across different pages. A direct fetch of the live page and of a live JS aggregate from this machine was blocked by Cloudflare ("Attention Required"), so those were not inspected byte-for-byte.
