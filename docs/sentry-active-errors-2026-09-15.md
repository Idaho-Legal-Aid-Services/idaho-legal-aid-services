# Sentry active-error review — 2026-09-15

Org `idaho-legal-aid-services`, project `php` (PHP + browser events share it). Snapshot taken 2026-09-15 ~15:30 UTC via the REST API (read-only). Previous review: `docs/sentry-active-errors-2026-09-09.md`. Deploys since then: `live_185` (f16508cce7, 09-10 18:11 UTC), then merge #187 and merge #188 (`02d4114a55`, 09-10 21:30 UTC, current live; carries the browser-noise filters from `ab901dd217`).

## Summary

| Bucket | Issues | Notes |
|---|---|---|
| Unresolved total | 47 | 131 stale issues were bulk-resolved on 09-10; nothing regressed |
| Seen in the last 14 days | 25 | The Sentry UI's default view. Every one reviewed below |
| Silent > 14 days | 22 | One-offs and pre-filter noise, reviewed below |
| For Review inbox | 8 | AV, AT, AR, AS, AQ, AP, AM, AN |

**Nothing active is a site bug.** The active set is (a) the Chrome-for-iOS / Google-app page-translation script overflowing its own stack, (b) third-party beacons and extensions that fired before the 09-10 filters reached live, (c) known telemetry and access-control denials, and (d) the dev circuit-breaker loop, whose fix is deployed but **still unverified** because the 09-13 nightly never reached the assistant (see B1).

**Three decisions / actions come out of this review:**
1. Two browser events got past the new filters after deploy (PHP-9F on 09-13, PHP-AV on 09-14). 9F is explained and needs one `ignoreErrors` entry; AV is not explained by the code as written and needs an SDK-level fallback plus a diagnostic tag (section D).
2. The nightly eval gate must tolerate Pantheon's dev environment failing its first hit (HTTP 555, MySQL not started). Until a real Sunday run completes, PHP-9P/8P/8N/9N stay open.
3. Thirty-one issues were safe to resolve and were resolved on 09-15 (section F).

## A. What changed since 09-09 — fixes verified or not

| 09-09 item | Sentry IDs | Status on 09-15 | Evidence |
|---|---|---|---|
| A1 budget denial tripping the breaker | [PHP-9P](https://idaho-legal-aid-services.sentry.io/issues/7647740617/), [PHP-8P](https://idaho-legal-aid-services.sentry.io/issues/7600884076/), [PHP-8N](https://idaho-legal-aid-services.sentry.io/issues/7600884006/), [PHP-9N](https://idaho-legal-aid-services.sentry.io/issues/7647739579/) | **Deployed 09-10, NOT verified** | Last events 09-06 10:29–10:48 (Sunday eval). The 09-13 run never sent a message: `gate-summary.txt` has `connectivity_status=failed`, `failure_code=bootstrap_http`, 0 cases. Dev nginx log shows exactly one request in the window, `555 /assistant/api/session/bootstrap` at 10:20; dev `php-error.log` at 10:20:54 UTC: `Failed to start mysql. Got code: 0` / `Failed to connect to mtlsproxyhost port 9001`. Silence in Sentry that night proves nothing. |
| A2 stale-ratio freshness (review date) | [PHP-9Z](https://idaho-legal-aid-services.sentry.io/issues/7657016045/), [PHP-AR](https://idaho-legal-aid-services.sentry.io/issues/7722840241/) | Code live; live alert now below the sample gate | On live the new-template alert logged at **notice** on 09-14 09:16 ("stale 3 / total 3, min_observations 20; never_reviewed 3") — the 24 h observation window has not reached 20 since deploy, so raven does not forward it. PHP-9Z (old template, last 09-07) is superseded. PHP-AR is the dev successor; dev's 1316 observations / 1137 never-reviewed are eval traffic. Content review pass still pending. |
| A3 + A6 scrubber cycle guard, readable widget titles | PHP-AJ, PHP-1M | **Held.** Both resolved 09-10; no `AILA browser error:` issue has opened since, i.e. no widget network failures in 5 days | Sentry search `AILA browser error` = 0 issues |
| A4 Pinecone chunk deletes + per-env namespaces | [PHP-28](https://idaho-legal-aid-services.sentry.io/issues/7341743928/) | **Held.** No live event since 09-05; the 09-09 event is test cron draining trashed QA nodes 171–179 | Retained events: `entity:paragraph/402:en` (live) stop 09-05; last event `test_201` cron |
| A5 Cloudflare static-resource challenges | [PHP-AQ](https://idaho-legal-aid-services.sentry.io/issues/7722279454/), [PHP-AP](https://idaho-legal-aid-services.sentry.io/issues/7722279017/) | **Held.** No event since 09-09 17:47 | `cloudflare-security-action-items-check.sh`: `sbfm_static_resource_protection=false`, `static_render_asset_challenged_ips_24h=0` |
| C browser-noise filters (`sentry-filters.js`, `observability.js`) | beacon / extension / iabjs / `La` clusters | **Deployed and correctly ordered**; two escapes | Dev page: footer aggregate delta 1 contains `sentry-filters.js` (`denyUrls`, `beacon\.min\.js`) and the new `observability.js` (`MAX_SCRUB_DEPTH`, `isInjectedScriptBrowser`); the SDK bundle loads next; `Sentry.init(` is in delta 3. All pre-filter clusters are silent since 09-05 or earlier. |

## B. Two corrections to the 09-09 report

### B1 (correction 1). The injected-script cluster: line numbers follow the browser build, not the page

The 09-09 report called these injected because "line numbers are identical across pages". They are not identical across pages; they are identical across *builds*, which is stronger evidence for the same conclusion. Raw payloads (`/events/<id>/json/`):

| Issue | Browser | Page | Recursion frames (line:col) |
|---|---|---|---|
| [PHP-AB](https://idaho-legal-aid-services.sentry.io/issues/7690489028/) | Chrome iOS 151 | /forms/debt-collection | 224:408 / 224:63 |
| [PHP-AC](https://idaho-legal-aid-services.sentry.io/issues/7690489738/) | Chrome iOS 151 | /forms | 224:408 / 224:63 |
| [PHP-AS](https://idaho-legal-aid-services.sentry.io/issues/7722868889/) | Chrome iOS 152 | /legal-help/family | 232:408 / 232:63 |
| [PHP-AK](https://idaho-legal-aid-services.sentry.io/issues/7719544314/) | Google app 436 | /legal-advice-line | 226:408 / 226:63 |
| [PHP-AV](https://idaho-legal-aid-services.sentry.io/issues/7732377100/) | Google app 437 | /resources/powers-attorney | 226:408 / 226:63 |
| [PHP-AM](https://idaho-legal-aid-services.sentry.io/issues/7719697709/), [PHP-AN](https://idaho-legal-aid-services.sentry.io/issues/7719698093/) | Chrome iOS 152 | 3 different pages | 195:338, 194:41, 460:363 (identical) |

Column numbers are identical everywhere; only the two-letter minified names rotate per build (Hk/Fk → Pk/Nk → Qk/Ok → Tk/Rk). The live HTML for these pages has no executable inline script near those lines (the only inline blocks are JSON-LD and the drupalSettings JSON). Breadcrumbs on AV are `button.accordion-button.collapsed > font > font` and `span.btn__label > font > font` (Google Translate wraps translated text in nested `<font>`), and the users' locales are uk-UA, es-419, es-MX, es-US on English pages. This is the app's **page-translation** script recursing over the DOM. No site code is on the stack; no user-visible effect on our pages beyond the translation itself.

### B2 (correction 2). PHP-32 is stale iframe URLs for media that no longer exists, not random hash probing

[PHP-32](https://idaho-legal-aid-services.sentry.io/issues/7349719598/) · 34 events since 03-20, 6 retained · live only · `OEmbedIframeController::render` line 153 (`hash_equals` failed). The retained requests (`extra.request_uri`) are:

| Date | `url=` | Note |
|---|---|---|
| 09-12 | `http://www.slideshare.net/IDLegalAid/consumer-sentinel-presentation` | |
| 08-16 | `https:/youtu.be/vy7_DXCkvhY` | single slash |
| 08-15 | `https:/www.youtube.com/watch?v=kOTwZezNfC8` | single slash |
| 08-11 | `https:/www.youtube.com/watch?v=iXTagWVNUqs` | single slash |
| 08-09 | `https:/www.youtube.com/watch?v=hOAx-MXTggY` | single slash |
| 07-25 | `http://www.slideshare.net/IDLegalAid/consumer-sentinel-presentation` | |

`media__field_media_oembed_video` on live is **empty** (0 rows for youtube or slideshare). Every one of these is a client replaying an `/media/oembed?…&hash=` iframe URL from the pre-migration site or a cached copy of it; four of them also collapsed `//` to `/`, which no browser does. Noise, harmless, cacheable 400. Drop it in `SentryOptionsSubscriber::beforeSend` (exception class + `OEmbedIframeController`) or block `/media/oembed` for non-browser clients in Cloudflare; there is no content to fix. `media.settings` `iframe_domain` is null, so the domain branch is not involved.

## C. Per-issue extent and cause — seen in the last 14 days (25)

| ID | Level | Env | Events | Last | Cause | Disposition |
|---|---|---|---|---|---|---|
| [PHP-AV](https://idaho-legal-aid-services.sentry.io/issues/7732377100/) | error | live | 5 | 09-14 | Google app 437 translate script stack overflow (B1). **Escaped the new processor** even though it was installed (all 5 events carry the observability tags). See D. | Mitigate (D), then resolve |
| [PHP-AS](https://idaho-legal-aid-services.sentry.io/issues/7722868889/) | error | live | 4 | 09-09 | Same cluster, `live_184` (pre-filter) | Resolve |
| [PHP-AK](https://idaho-legal-aid-services.sentry.io/issues/7719544314/) | error | live | 2 | 09-08 | Same cluster, `live_184` | Resolve |
| [PHP-AM](https://idaho-legal-aid-services.sentry.io/issues/7719697709/), [PHP-AN](https://idaho-legal-aid-services.sentry.io/issues/7719698093/) | error | live | 2, 1 | 09-08 | Same injected script throwing `Error: La`; `ignoreErrors /^(La\|Ba)$/` is live | Resolve |
| [PHP-9F](https://idaho-legal-aid-services.sentry.io/issues/7625223791/) | error | live | 9 | 09-13 | Safari extension `Invalid call to runtime.sendMessage(). Tab not found.` on `/`; unhandled rejection with **no stack** and **no observability tags** — fired before the footer processor existed, and `denyUrls` has no URL to match | Add SDK-level `ignoreErrors`; resolve |
| [PHP-AQ](https://idaho-legal-aid-services.sentry.io/issues/7722279454/), [PHP-AP](https://idaho-legal-aid-services.sentry.io/issues/7722279017/) | error | live | 1, 1 | 09-09 | Cloudflare static-resource challenge (A5); off and verified | Resolve |
| [PHP-9P](https://idaho-legal-aid-services.sentry.io/issues/7647740617/), [PHP-8P](https://idaho-legal-aid-services.sentry.io/issues/7600884076/), [PHP-8N](https://idaho-legal-aid-services.sentry.io/issues/7600884006/), [PHP-9N](https://idaho-legal-aid-services.sentry.io/issues/7647739579/) | error/warn | dev | 45, 215, 7, 5 | 09-06 | Per-IP budget denial counted as breaker failure during Sunday eval (fixed 09-10, `11fa7f6e20`); unverified, see A | Leave open until a completed Sunday run is clean |
| [PHP-9Z](https://idaho-legal-aid-services.sentry.io/issues/7657016045/) | warning | live | 20 | 09-07 | Stale ratio, old template; superseded by the `never_reviewed` template | Resolve |
| [PHP-AR](https://idaho-legal-aid-services.sentry.io/issues/7722840241/) | warning | dev | 3 | 09-10 | Stale ratio, new template; dev eval traffic (1316 obs) | Leave open; dev-only |
| [PHP-28](https://idaho-legal-aid-services.sentry.io/issues/7341743928/) | warning | all | 94 | 09-09 | Groups on the template: live = stale Pinecone chunks (gone after 09-10 rebuild); test/dev = trashed QA nodes on cron (drained) | Resolve; recurrence = regression |
| [PHP-32](https://idaho-legal-aid-services.sentry.io/issues/7349719598/) | warning | live | 34 | 09-12 | Stale oEmbed iframe URLs (B2) | Drop in before_send; resolve |
| [PHP-8J](https://idaho-legal-aid-services.sentry.io/issues/7598977119/) | warning | dev 35 / live 29 / test 5 | 69 | 09-12 | 403s by path: `/admin/reports/ilas-assistant` 33, `/admin` 12, `/media/355/edit` 7, `/media/317/edit` 5, `/admin/content` 5, `/.well-known/change-password` 2 (password-manager probe), others 1 each. Week-37 spike (11) = 09-10 deploy-day smoke checks (7 that day) | Leave open; control working |
| [PHP-57](https://idaho-legal-aid-services.sentry.io/issues/7408884548/) | warning | dev 2 / live 1 (3 retained of 6) | 6 | 09-10 | `csrf_deny` on `/assistant/api/message` from `curl/8.5.0`, headless Chrome 152, and `ilas-assistant-smoke/1.0` — our own post-deploy smoke checks plus one scanner | Leave open; control working |
| [PHP-3D](https://idaho-legal-aid-services.sentry.io/issues/7358392145/) | warning | dev 18 / live 1 | 24 | 09-06 | Vector FAQ search > 3.5 s during Sunday eval bursts | Leave open; telemetry |
| [PHP-2J](https://idaho-legal-aid-services.sentry.io/issues/7342624412/) | error | dev | 61 lifetime, 3 in 90 d | 09-03 | Pinecone transport (`Saloon FatalRequestException` ×2, `ServerException` ×1), 120 s backoff worked | Leave open |
| [PHP-9B](https://idaho-legal-aid-services.sentry.io/issues/7621475027/) | error | live | 7 | 09-05 | Facebook Android in-app `iabjs://navigation_performance_logger_android`; `denyUrls /^iabjs:/` live | Resolve |
| [PHP-A0](https://idaho-legal-aid-services.sentry.io/issues/7657288758/) | error | live | 2 | 09-03 | Outlook SafeLinks / Edge `Object Not Found Matching Id`; `ignoreErrors` live | Resolve |
| [PHP-38](https://idaho-legal-aid-services.sentry.io/issues/7353529288/) | warning | live | 12 | 09-03 | Employment-application submit with invalid Content-Type. Logged IPs were Cloudflare edges (172.70/172.71/104.23) until 07-08; after the proxy-trust fix they are real crawler IPs (85.208.96.207, 185.191.171.2 = Semrush ranges; 209.101.218.215) | Leave open; control working |
| [PHP-AH](https://idaho-legal-aid-services.sentry.io/issues/7698928348/), [PHP-AG](https://idaho-legal-aid-services.sentry.io/issues/7698928328/), [PHP-9S](https://idaho-legal-aid-services.sentry.io/issues/7650188477/) | error | live | 3, 2, 5 | 09-02, 08-31, 08-31 | Cloudflare `beacon.min.js` (RUM) on browsers without `Array.prototype.at` / `findLast` (Chrome 87, Silk 88, Opera 90, KaiOS); `denyUrls` live | Resolve |

## D. The PHP-AV escape

Facts: all five AV events (09-14 23:55–23:56 UTC, `live_186`) carry `assistant_name`, `pantheon_env`, `route_name`, `site_name`, which only `observability.js` `configureSentry()` sets, immediately before it calls `Sentry.addEventProcessor`. So the processor was installed. Breadcrumb messages are readable (the pre-09-10 processor blanked them), so it was the new code. The dev aggregate contains the new code and loads before `Sentry.init`. Replaying the exact AV shape through the current `observability.js` in jsdom (GSA UA, 50 frames with `filename` = the page URL, no lineno) returns `null`, i.e. dropped; the 9F shape is dropped too.

Nothing in the code, the deployment, or the load order explains why the live processor kept these events. Do not spend more time guessing. Two changes make the next occurrence self-explaining and stop the noise regardless:

1. `web/modules/custom/ilas_seo/js/sentry-filters.js`: add `/runtime\.sendMessage/` to `ignoreErrors` (9F), and add a `beforeSend` that drops `RangeError: Maximum call stack size exceeded` when `navigator.userAgent` matches `CriOS/|GSA/` and every frame's `filename` is the document URL (same predicate as `isDocumentPathFrame` in `observability.js`). This runs inside the SDK from `init`, so it does not depend on the footer processor.
2. `observability.js` event processor: on every kept event, set a tag `noise_filter` = the branch taken (`first_party_frame`, `no_frames`, `ua_not_injected`, `frame_not_document:<filename>`, `no_match`). One tag, one string, no PII.

## E. Per-issue — silent more than 14 days (22)

| ID | Level | Events | Last | Cause | Disposition |
|---|---|---|---|---|---|
| [PHP-AT](https://idaho-legal-aid-services.sentry.io/issues/7730232602/), [PHP-AF](https://idaho-legal-aid-services.sentry.io/issues/7695804953/), [PHP-A9](https://idaho-legal-aid-services.sentry.io/issues/7688607891/), [PHP-A8](https://idaho-legal-aid-services.sentry.io/issues/7683295183/), [PHP-A7](https://idaho-legal-aid-services.sentry.io/issues/7677064017/), [PHP-95](https://idaho-legal-aid-services.sentry.io/issues/7609343506/) | warning | 1–2 each | 09-13 … 08-17 | Sentry auto-detected performance issues (`ui.long-animation-frame` 0.7–1.7 s on a single page load: fair-lending-brochure node, /donate, /legal-help/consumer, /resources/repossession, /resources/renter-resources; A8 is the `b5subtheme_ilashotspot` block admin form, 4.0 s load). One transaction each, no repeat page | Resolve; revisit if a page repeats |
| [PHP-6G](https://idaho-legal-aid-services.sentry.io/issues/7469189869/) | error | 4 | 08-27 | `Cron run failed` from `PantheonCommands::cron`, breadcrumb "Attempting to re-run cron while it is already running". Same warning on dev 09-15 09:55 during the hourly run. `automated_cron` is disabled and no workflow or script in the repo invokes cron, so the second trigger is platform-side | Leave open; raise with Pantheon if it climbs |
| [PHP-AD](https://idaho-legal-aid-services.sentry.io/issues/7690519742/) | error | 2 | 08-25 | beacon.min.js on KaiOS Firefox 84 | Resolve |
| [PHP-AC](https://idaho-legal-aid-services.sentry.io/issues/7690489738/), [PHP-AB](https://idaho-legal-aid-services.sentry.io/issues/7690489028/), [PHP-37](https://idaho-legal-aid-services.sentry.io/issues/7352864722/) | error | 1, 2, 30 | 08-25, 08-25, 08-17 | Injected translate script (B1); 37 is the March-onward umbrella for the same thing | Resolve |
| [PHP-9H](https://idaho-legal-aid-services.sentry.io/issues/7627232335/) | warning | 14 | 08-24 | 403s: `/admin/content` 10 (dev), `/media/317/edit` 2, `/admin/dashboard?check_logged_in=1` 1, `/admin/reports/status` 1 | Leave open |
| [PHP-92](https://idaho-legal-aid-services.sentry.io/issues/7604782077/), [PHP-A3](https://idaho-legal-aid-services.sentry.io/issues/7668534168/), [PHP-9G](https://idaho-legal-aid-services.sentry.io/issues/7625501191/) | error | 22, 2, 3 | 08-21, 08-12, 08-12 | MetaMask extension; ad-adjust extension wrapping `fetch` around gtag; `denyUrls` live | Resolve |
| [PHP-A6](https://idaho-legal-aid-services.sentry.io/issues/7672383504/) | error | 2 | 08-18 | GoGuardian (school-managed device) websocket in `/asset.js` | Resolve |
| [PHP-AE](https://idaho-legal-aid-services.sentry.io/issues/7692101916/), [PHP-AA](https://idaho-legal-aid-services.sentry.io/issues/7690248355/), [PHP-1E](https://idaho-legal-aid-services.sentry.io/issues/7337101694/) | error | 1, 1, 7 | 08-25, 08-24, 08-11 | `Can't find variable: _G` (injected global code); Safari `Load failed` (network abort on GA fetch); `CustomEvent` captured as promise rejection (Safari, no payload) | Resolve |
| [PHP-A4](https://idaho-legal-aid-services.sentry.io/issues/7671017648/) | error | 1 | 08-14 | Facebook Android bridge (`Java exception was raised`) | Resolve |
| [PHP-A5](https://idaho-legal-aid-services.sentry.io/issues/7671943043/) | error | 2 | 08-14 | dev `/node/add/*`, uid 1, Chisinau session; dev has no Cloudflare so A5's cause does not apply; unexplained | Leave open as a tripwire |

## F. Resolved 2026-09-15 (31)

Pre-filter noise, superseded, or drained — resolved via the API on 2026-09-15: PHP-AS AK AM AN AQ AP 9Z 28 9B A0 AH AG 9S AT AF A9 A8 A7 95 AD AC AB 37 92 A3 9G A6 AE AA 1E A4. Add PHP-AV, 9F and 32 once D and the oEmbed drop are deployed.

Write (needs the write-scoped `SENTRY_TOKEN`, see memory): `PUT /api/0/projects/idaho-legal-aid-services/php/issues/?id=…&id=…` with `{"status":"resolved"}`. Anything that recurs reopens as a regression.

## G. Leave open deliberately (16)

PHP-9P 8P 8N 9N (pending verification), AR (dev), 8J 57 9H (access control), 3D 2J (telemetry), 38 (bot guard), 6G (platform cron overlap), A5 (tripwire), plus AV 9F 32 until their drops ship.

PHP-AW (4 events, live, 09-15 15:58 UTC) opened during this review and was resolved the same hour: it is `drush watchdog:show --type="client error"` / `--type="access denied"` run over `terminus remote:drush` against live, which throws `Unrecognized message type` when dblog's 1000-row limit has already pruned that type, and raven reports CLI exceptions. Use `--filter=` instead of `--type=` on live.

## H. Follow-ups, in order

1. **Nightly gate resilience** — `assistant-nightly-quality.yml` / `run-promptfoo-gate.sh`: before bootstrap, hit the dev front page and retry on any 5xx for up to ~3 minutes (Pantheon starts the DB container on first hit). Otherwise A1 stays unverifiable on quiet weeks.
2. **D above** (two small JS changes + node tests in `ilas_seo/tests/js/node/sentry-filters.test.mjs` and `ilas_site_assistant/tests/js/node/observability-noise-filter.test.mjs`).
3. **PHP-32 before_send drop** in `SentryOptionsSubscriber`.
4. ~~Bulk-resolve section F~~ done 09-15 (31 issues); AV, 9F and 32 remain for after their drops ship.
5. **A2 content review pass** via `/admin/reports/ilas-assistant` (unchanged from 09-09).

## Method

Issues: `is:unresolved` (47), `lastSeen:-14d` (25), `is:for_review` (8). Per issue: `/issues/{id}/tags/`, `/issues/{id}/events/latest/`, full event history (`/issues/{id}/events/?full=false`, paginated) for environment / week / path / UA / IP histograms, and `/projects/…/events/{event_id}/json/` for raw frames and tags on the injected-script cluster and PHP-32. Deploy state: `terminus env:code-log` (live/test/dev all on `02d4114a55`). Logs: `terminus remote:drush … watchdog:show` (dblog `row_limit: 1000`, so only cron and assistant rows survive on live), `state:get` for the governance snapshot/alert keys, `sqlq` on `media__field_media_oembed_video`; dev `logs/nginx/nginx-access.log` and `logs/php/php-error.log` via rsync over the Pantheon SFTP host. Nightly: `gh run download` of `assistant-nightly-hosted-artifacts` for 09-13, 09-09, 09-06, 08-30 (`gate-summary.txt`). Cloudflare: `scripts/observability/cloudflare-security-action-items-check.sh --token-file ~/.secrets/cloudflare_api_token`. Filter behaviour: dev page + footer aggregates fetched from `pantheonsite.io`, and the AV/9F event shapes replayed through `observability.js` with the repo's jsdom harness (`tests/js/node/_harness.mjs`). Live pages themselves were not fetched (Cloudflare blocks this machine).
