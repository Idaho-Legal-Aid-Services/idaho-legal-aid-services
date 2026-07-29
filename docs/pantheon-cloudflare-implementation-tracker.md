# Pantheon + Cloudflare — Implementation Tracker

**Source of truth:** `docs/pantheon-cloudflare-preimplementation-validation.md`
(2026-07-28). Item numbering and ordering follow that document's
**§16 Recommended implementation order**. Classifications come from its §3–§7.

This tracker records *what was actually done*. It does not restate the analysis
and it does not add new recommendations. Where implementation revealed something
the validation document did not cover, it is recorded under **Findings during
implementation** and carried as an explicit follow-up.

| Status | Meaning |
|---|---|
| ✅ Done | Implemented and verified on Live |
| 🟢 In progress | Started, not yet on Live |
| ⬜ Not started | — |
| ⏸️ Blocked | Waiting on an external decision or party |
| 🔵 Deferred | Deliberately not being done now (per §6) |
| ❌ Rejected | Will not be done as specified (per §7) |

---

## Ordered items

| # | Item | Ref | Status | Evidence / notes |
|---|---|---|---|---|
| 0 | Archive recovered DB backups to durable storage | §8.9A | ⬜ Not started | The 2026-06-30 backup **expires 2026-08-01**. Time-critical and independent of everything below. |
| **1** | **Fix `language-browser` negotiation** | **§8.9B** | ✅ **Done 2026-07-28** | Deployed Dev → Test → Live. Commit `ec129b4834`, PR #147. See the dedicated section below. |
| 2 | Add icon files + template tags | §8.1 | ✅ **Done 2026-07-29** | All three §8.1 corrections applied. Commit `259ada7f8f`, PR #149. Deployed Dev → Test → Live. See the dedicated section below. |
| 3 | `www`→apex Single Redirect | §8.2 | ✅ **Done 2026-07-29** | Rule `ce2a37cdad2c44199770caee0516029d`. `preserve_query_string` enabled. See the dedicated section below. |
| 4 | Add `json\|xml\|webmanifest` to `fast_404` | §11.4 | ✅ **Done 2026-07-29** | Commit `c213ebb9a4`, PR #149. Shipped with item 2 but as a separate commit, independently revertable. See the dedicated section below. |
| 5 | Audit GitHub repo vars for the live platform hostname | §8.5 | ⬜ Not started | Prerequisite before §8.5 could ever be reconsidered. Cannot be checked from the repo. |
| 6 | Fix the dynamic canonical | §8.5 | ⬜ Not started | `config/metatag.metatag_defaults.global.yml` uses `[current-page:url:absolute]`. |
| 7 | Legacy files — 10 high-confidence rows | §8.4 | ⬜ Not started | Fix source links *and* add redirects. |
| 8 | Legacy files — 10 editorial rows | §8.4 | ⏸️ Blocked | Gated on content-owner / legal review. |
| 9 | SEO-crawler rule in **Log** mode | §8.6 | ⬜ Not started | Replaces the rejected §13.2 change. |
| 10 | Review logs → Managed Challenge | §8.6 | ⬜ Not started | Only after ≥7 days of Log. Never Block first. |
| 11 | Verify `old.` IP not third-party; delete record | §8.3 | ✅ **Done 2026-07-29** | `72.3.167.82` **is** third-party (Phoenix Group Holdings LLC, Rackspace space) — dangling DNS. Record deleted. See the dedicated section below. |
| 12 | Re-measure for one full billing cycle | §13 | ⬜ Not started | Depends on item 1 landing. |
| 13 | Re-evaluate `pantheon_advanced_page_cache` | §8.10 | 🟢 **Assessed 2026-07-29, not yet implemented** | **Un-deferred.** §8.10's precondition ("revisit after §8.9 is fixed") is met, and F-5 confirmed the cost: no `Surrogate-Key` on any Live page, front page served at `age: 43046` (~12 h stale). Both §8.10 blockers re-measured and found much smaller than recorded — see the assessment below. No code enabled anywhere. |
| — | Platform-hostname redirect | §8.5 | 🔵 Deferred | Breaks CI (promptfoo POSTs to it); benefit already mitigated by Pantheon's `Disallow: /`. |
| — | 404 caching / Cloudflare HTML caching | §8.11–12 | 🔵 Deferred | Root-cause fixes first. |
| — | Remove `Search Engine Optimization` from skip rule `64fae5be` *as specified* | §8.6 | ❌ Rejected | `sbfm_verified_bots: "allow"` means the stated mechanism does not work. Replaced by item 9. |

---

## Item 1 — `language-browser` negotiation (§8.9B)

**Change.** One line removed from `config/language.types.yml`:
`language-browser: -2` deleted from `negotiation.language_interface.enabled`.
Retained under `method_weights`, so re-enabling is a one-line restore.
No other negotiation method was modified.

**Why.** Core's `LanguageNegotiationBrowser::getLangcode()` triggers the page-cache
kill switch unconditionally, and is reached whenever `language-url` fails to match.
With prefixes `{en: '', es, sw, nl}` that is every URL except `/` and the
prefixed sections — so nearly every anonymous interior page, redirect and 404 on
the site was uncacheable. Measured Live cache hit ratio was ~20 %.

**Accepted trade-off** (confirmed with the site owner, 2026-07-28): first-time
visitors are no longer auto-switched to Spanish based on `Accept-Language`.
Preserved: the `/es` URL prefix, the language switcher, all Spanish content and
search, hreflang tags, and every existing Spanish URL.
**Program staff should be told**, even though it is not blocking.

**Guard added.** `web/modules/custom/ilas_seo/tests/src/Unit/LanguageNegotiationConfigTest.php`
— a pure-YAML contract test (no Drupal bootstrap) in the `unit` suite, therefore
in the `quality-gate` CI job. It fails if `language-browser` reappears under
`enabled`, if the `method_weights` entry is dropped, or if the remaining
negotiation methods or URL prefixes change. Verified to fail on a simulated
regression before being committed.

**Evidence.** `docs/evidence/browser-language-cacheability/` — see `50-header-comparison.md` for
the before/after table.

**Deployment record.**

| Step | When (UTC) | Result |
|---|---|---|
| PR #147 opened, all CI green | 2026-07-28 ~22:0x | 11 checks pass, 3 correctly skipped |
| Merged to `github/master` as `037e853655` | 2026-07-28 | — |
| Pushed to Pantheon `origin/master` | 2026-07-28 ~22:40 | Full deploy-bound gate incl. live promptfoo: **112/112 pass** |
| Dev: `config:import` → `cr` → `env:clear-cache` | 2026-07-28 ~22:45 | Verified |
| Test: `env:deploy --updatedb --cc` → import → `cr` → clear-cache | 2026-07-28 ~22:52 | Verified |
| Live: backup (`--keep-for=7`) → `env:deploy` → import → `cr` → clear-cache | 2026-07-28 ~23:00 | Verified |

**Result on Live.** All five target interior pages moved from
`UNCACHEABLE (response policy)` / `must-revalidate, no-cache, private` to
`max-age=86400, public`, and repeat requests are served from the Pantheon edge
(`x-cache: MISS, HIT` with a climbing `age`) instead of reaching PHP. English 404s and
301 redirects became cacheable too. `/es`, `/sw`, `/nl` unchanged and still correctly
localized; hreflang set intact; no anonymous page sets a session cookie.

**Baseline for monitoring.** Live cache hit ratio 2026-07-14 → 07-27: **15.41 %–28.91 %,
mean ≈ 20 %**. Track daily for 14 days in `60-metrics-daily.txt`. Per §13, if the ratio has
not exceeded 60 % within 72 h, re-open §8.9 — the mechanism is wrong or incomplete.

**Rollback.** Restore the one line under `enabled`, update the guard test in the
same commit, publish, then per environment `config:import -y` → `cr` →
`env:clear-cache`. No data loss.

### Findings during implementation

**F-1 — Assistant retrieval language now resolves to English on API paths.**
Not covered by the validation document. The assistant's retrieval layer filters
by *interface* language (`FaqIndex::getCurrentLanguage()`,
`web/modules/custom/ilas_site_assistant/src/Service/FaqIndex.php:170`; same
pattern in `ResourceFinder.php`), and the widget calls an **unprefixed**
endpoint (`apiBase => '/assistant/api'`,
`AssistantPageController.php:106`). Before this change the interface language for
those calls came from `Accept-Language`; after it, it is always English.

Verified against Live before the change:

```
GET /assistant/api/faq?q=eviction  Accept-Language: en-US → "/resources/renter-resources#…"
GET /assistant/api/faq?q=eviction  Accept-Language: es-ES → "/es/resources/recursos-para-inquilinos#…"
```

Prior behaviour was browser-keyed and already inconsistent with the page the user
was on (a Spanish-browser visitor on an English page got `/es/` links; an
English-browser visitor on `/es/` got English links). After the change it is
uniformly English. The narrow real degradation is for Spanish-browser visitors on
`/es/` pages, who now get English resource links.

The assistant's **answer language is unaffected** — it is detected from the
message text (`ObservabilityPayloadMinimizer::detectLanguageHint()`,
`AssistantApiController.php:3938`), not from negotiation.

The existing language tests (`FaqLanguageIsolationTest`,
`ResourceLanguageIsolationTest`, `FaqSearchRuntimeRegressionKernelTest`) stub the
language manager, so they neither fail on nor catch this.

→ **Follow-up FU-1** below. Deliberately not fixed here: out of scope for a
one-line negotiation change.

**F-2 — `drush config:export` rewrites five unrelated files from stale local DB
state.** `config/ilas_site_assistant.settings.yml`,
`config/metatag.metatag_defaults.global.yml`,
`config/search_api.index.faq_accordion_vector.yml`, and the two
`search_api_solr.solr_field_type.text_es_*` files. All five were reverted; only
`config/language.types.yml` was carried forward. Recorded in
`docs/evidence/browser-language-cacheability/03-local-export-drift-reverted.txt`.
**Never blind-commit a full `cex` on this repo.**

**F-3 — Pre-existing unit failure, unrelated.**
`ilas_site_assistant_governance` `GapItemManagerSelectionContextTest` fails with
1 error + 1 failure. Confirmed identical with and without this change by
reverting the config file and re-running. Not caused by, and not addressed by,
this work.

**F-4 — `LlmControlConcurrencyTest::testCacheStatsDoNotLoseConcurrentIncrements` is
flaky and blocked a deploy.** Observed **1 failure in 5 runs** of the same
checkout ("Failed asserting that 1 is identical to 2"). It blocked the first
`git push origin master` attempt and passed on retry. The test spawns four
concurrent processes via `runConcurrent(4, …)` that increment shared file-backed
state and asserts no increment is lost, so an intermittent failure indicates a
genuine lost-update race in either the test harness or `CostControlPolicy`'s
state writes. Unrelated to this change (these commits touch no assistant files),
but it is a real defect that will keep blocking deploys at random.
→ **Follow-up FU-3.**

**F-5 — Test environment has no Spanish content.**
`SELECT COUNT(*) FROM node_field_data WHERE langcode='es'` returns **99 on dev**
and **0 on test**; likewise 0 Spanish path aliases. Test's `/es` resolves to
`/es/node/25` with no alias and advertises only `x-default` + `en` hreflang.
Dev and Test run identical code and config, and both Dev (post-change) and Live
(pre-change) show the full 5-entry hreflang set, so this is a stale-database
condition in Test, not a regression. It does mean **Test cannot validate any
Spanish-language behaviour** — that validation has to happen on Dev and Live.
→ **Follow-up FU-4.**

**F-6 — The public hostname cannot be verified by script.**
Cloudflare returns 403 to scripted HTTP clients on `idaholegalaid.org` regardless
of User-Agent — the block is TLS/HTTP2-fingerprint based (validation doc §8.8,
pre-existing and by design). All header verification therefore ran against the
Pantheon platform hostname, which is the methodology §8.9B specifies. Browser-based
spot-checking of the public hostname is still worth doing by hand.

---

## Items 2 & 4 — browser icons and the `fast_404` extension (§8.1, §8.11/§11.4)

Shipped together on branch `fix/icons-and-static-404s` (PR #149, merged as `00c2e9c92e`) but
as **two commits on disjoint file sets**, so either part reverts without touching the other.

| Part | Commit | Files |
|---|---|---|
| A — icons | `259ada7f8f` | 7 static files under `web/`, the committed source SVG, the generator script, and 2 lines in `html.html.twig` |
| B — `fast_404` | `c213ebb9a4` | `config/system.performance.yml` — one line |

### Generated file inventory

All rasters come from `scripts/icons/build-favicons.mjs`, which reads the **committed** copy of
the mark at `web/themes/custom/b5subtheme/images/ILAS-favicon-source.svg` (375×375 viewBox,
opaque `#1263a0` ground). The script renders one 1024×1024 master and Lanczos-downscales every
target from it, then assembles the ICO from 32-bit BMP/DIB entries with a self-contained writer
— no new dependency, and no PNG-in-ICO compatibility question.

| File | Bytes | sha256 |
|---|---:|---|
| `web/favicon.ico` | 15,086 | `739a733a7df6b12a39df8f627c8d7334ae2652b7317d495704c52c00457c35db` |
| `web/apple-touch-icon.png` | 9,150 | `ea81d6bc4c209f0ec52453145e45992f33493b5595e30b53635fd802785f8a35` |
| `web/apple-touch-icon-precomposed.png` | 9,150 | `ea81d6bc4c209f0ec52453145e45992f33493b5595e30b53635fd802785f8a35` |
| `web/icon-192.png` | 9,946 | `35cfa3edc8ee14b77c57f58b6832eb8b43c492c05488c6705a4b0e374ac08c3e` |
| `web/icon-512.png` | 33,318 | `60da111ea893b9fb0d53c575cc89feb0f84d2c9cfdf95d790a2302117f2cb685` |
| `web/site.webmanifest` | 420 | `7d3bf5d08afbf09b966a50f4e463642ff6a0855b50d599edd4bbb506c459a3b4` |
| `web/manifest.json` | 420 | `7d3bf5d08afbf09b966a50f4e463642ff6a0855b50d599edd4bbb506c459a3b4` |

All seven were re-downloaded from the Live origin after deploy and hashed: **every one matches
its committed bytes**. The apple-touch pair and the manifest pair are intentionally identical.

`favicon.ico` was additionally parsed byte-by-byte — 3 directory entries (16/32/48, 32 bpp, DIB
heights correctly doubled for the AND mask, every offset in bounds) — and each entry was decoded
back to PNG and inspected: the ILAS Idaho mark, right-side-up, correct channel order. **Not**
Bootstrap's "B", which is what copying `web/themes/custom/b5subtheme/favicon.ico` would have
shipped.

### Before and after

Status and response body size, same paths, same method:

| Path | Before | After (Live origin) |
|---|---|---|
| `/favicon.ico` | 404 | **200** `image/vnd.microsoft.icon` 15,086 B |
| `/apple-touch-icon.png` | 404 | **200** `image/png` 9,150 B |
| `/apple-touch-icon-precomposed.png` | 404 | **200** `image/png` 9,150 B |
| `/site.webmanifest` | 404 (46,738 B page) | **200** `text/plain` 420 B |
| `/manifest.json` | 404 (46,723 B page) | **200** `application/json` 420 B |
| `/icon-192.png` | 404 | **200** `image/png` 9,946 B |
| `/icon-512.png` | 404 | **200** `image/png` 33,318 B |
| missing `.json` | 404, 46,768 B | 404, **233 B** |
| missing `.xml` | 404, 46,763 B | 404, **232 B** |
| missing `.webmanifest` | 404, ~46,7xx B | 404, **240 B** |
| missing `.pdf` | 404, 46,898 B | 404, 45,464 B — **unchanged by design** |
| `/sitemap.xml` | 200 | 200 — real routes unaffected |
| image-style derivative 404 | full page | full page — `exclude_paths` intact |

Verified identically on Dev, Test and the Live origin.

### HTML head

```
rel="icon" count: 1
<link rel="icon" href="/sites/default/files/ILAS%20Favicon_1.svg" type="image/svg+xml" />
<link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png">
<link rel="manifest" href="/site.webmanifest">
```

Exactly one `rel="icon"`, as §8.1 requires — it is Drupal's own, emitted from the b5subtheme
favicon setting, and the template deliberately adds no second one. `/favicon.ico` resolves by
browser convention without any tag.

### MIME and cache headers

| Path | Content-Type | Cache-Control (Live origin) |
|---|---|---|
| `/favicon.ico` | `image/vnd.microsoft.icon` | `no-cache, must-revalidate` |
| `/apple-touch-icon*.png`, `/icon-*.png` | `image/png` | `no-cache, must-revalidate` |
| `/manifest.json` | `application/json` | (none; ETag + Last-Modified) |
| `/site.webmanifest` | `text/plain` | (none; ETag + Last-Modified) |

Two things worth recording:

- **`.webmanifest` has no nginx MIME mapping**, so Pantheon serves it as `text/plain` (DDEV
  serves `application/octet-stream`). This is **not fixable from the repo** — Pantheon runs nginx
  and ignores `.htaccess`. It is also harmless: the manifest spec does not require a MIME type,
  and this was checked rather than assumed — real Chromium fetches and parses `/site.webmanifest`
  with no console warning. `/manifest.json`, which §8.1 wanted anyway for its 40 recorded 404s,
  doubles as a guaranteed `application/json` twin.
- **The icons return `no-cache, must-revalidate`** rather than a long `max-age`. That is
  Pantheon's default for these paths, not something this change introduced. The win still lands —
  a revalidated 200 costs far less than a 404 that bootstraps Drupal — but a Cache Rule to give
  the icon paths a real Edge TTL is a cheap follow-up (FU-8 below).

### Deploy timeline

| Step | UTC | Result |
|---|---|---|
| PR #149 opened | 2026-07-29 ~04:05 | 11 checks pass, 3 correctly skipped (no assistant paths touched) |
| Merged to `github/master` as `00c2e9c92e` | 04:08 | — |
| Pushed to Pantheon `origin/master` | 04:10 | Deploy-bound gate incl. live promptfoo: **112/112 pass, 0 failures** |
| Dev: build → `config:set` → `cr` → clear-cache | 04:37–04:41 | Verified |
| Test: `env:deploy --updatedb --cc` → `config:set` → `cr` → clear-cache | 04:44 | Verified |
| Live: backup (`--keep-for=7`) → `env:deploy --updatedb --cc` → `config:set` → `cr` → clear-cache | 04:48–04:51 | Verified |

### Findings during implementation

**F-1 — Pantheon's build artifact lags `env:code-log`.** Immediately after the push,
`terminus env:code-log dev` reported `00c2e9c92e` as deployed and `config:status` still listed
`system.performance` as differing — both of which read like a failed deploy. Neither was. The
`Sync code on "dev"` Integrated Composer workflow was still **running**: the source commit was in
Pantheon's git repo while the appserver was still serving the previous artifact (verified by
reading the filesystem directly — none of the seven files present, `html.html.twig` still at its
pre-change mtime). `env:code-log` is not proof that code is serving. Check
`terminus workflow:list` for a running `Sync code`, or read the filesystem, before diagnosing.

**F-2 — a deploy-bound master push requires `ILAS_LIVE_PROVIDER_GATE=1`.** The first
`npm run git:publish -- --origin-only` failed with
`PUBLISH-VERDICT FAIL gate=promptfoo_deploy_bound`. Nothing was broken: the phase exits 1 when
that env var is unset, by design — `gate_promptfoo_deploy_bound_required` is fail-closed on the
protected-master path (PIPE-02, closing the "deploy-bound gate silently auto-skipped" todo) so
that a deploy can never pass while the live eval ran nothing. Setting the flag makes the gate run
*more*, not less. Re-run with it: **112/112 pass**.

**F-3 — a full `config:import` was the wrong tool here.** The plan called for
`config:status` → `config:import` per environment. But `ilas_site_assistant.settings` already
showed as `Different` on **all three environments** before any of this work. An order-insensitive
deep compare found **zero value differences** — it is purely top-level key ordering
(`vector_search`/`voyage` sit in a different position). A full import would therefore have
rewritten an assistant config object on Live as a side effect of an icons deploy. Each
environment instead got a surgical
`drush config:set system.performance fast_404.paths '<value>'`, and the stored value was read
back and confirmed byte-identical to the repo file. **The ordering drift is pre-existing, benign,
and still open** — see FU-9.

**F-4 — the public hostname cannot be verified by automation.** `curl` and headless Chromium
both receive Cloudflare 403 / "Attention Required!" on `idaholegalaid.org`, including on the
homepage itself — SBFM's likely-static and automated-traffic rules, pre-existing and by design
(`/robots.txt` is the exception, via the `ilas_skip_well_known_text` skip rule). All header and
status verification therefore ran against the Pantheon platform hostname, which is the same
methodology item 1 used. Real-user evidence has to come from Cloudflare analytics, and
browser-based visual confirmation has to be done by hand.

### 404 volume — baseline and monitoring

Pre-deploy baseline, `httpRequestsAdaptiveGroups`, window `2026-07-27T02:00Z → 2026-07-29T02:00Z`
(2 days, 76,558 total requests):

| Path | 404s |
|---|---:|
| `/favicon.ico` | 888 |
| `/apple-touch-icon.png` | 247 |
| `/apple-touch-icon-precomposed.png` | 233 |
| `/apple-touch-icon-120x120*.png` | 30 |
| `/sites/idaholegalaid.org/files/favicon.ico` | 12 |
| `/manifest.json` | 1 |
| **Directly addressed (top 3)** | **1,368** |
| **Zone-wide 404s** | **4,564** |

The three fixed paths are **30.0 %** of all zone 404s in that window — real, but **below §8.1's
projected ~60 %**. §8.1's figure came from a window in which icons were a larger share; the
remaining 404s are a long tail of legacy `/sites/idaholegalaid.org/files/*.pdf` links (item 7)
and encoded-path junk. Expect the icon 404s to go to ~0 and the zone total to fall by roughly a
third, not by 60 %. Counted Pantheon visits should not move at all — 404s were never counted.

Live cutover was **2026-07-29T04:51:14Z**. Re-run
`scripts/observability/cloudflare-404-volume-check.sh` to compare; the baseline above is
embedded in that script. The full 48-hour comparison is **still pending**.

**First post-deploy reading — 2026-07-29T05:00Z → 16:24Z (11.5 h), i.e. *before* the cache purge.
The icon 404s had not yet fallen to zero, because Cloudflare was still serving 404s it had cached
before the cutover — see F-5:**

| Path | 404 | 200 | cacheStatus on the 404s |
|---|---:|---:|---|
| `/apple-touch-icon.png` | 98 | 1 | **hit** |
| `/favicon.ico` | 84 | 2 | **hit** |
| `/apple-touch-icon-precomposed.png` | 31 | 0 | **hit** |

Every one of those 404s was served **from Cloudflare's cache**, not from the origin. The origin
returned 200 for all three (verified by checksum). The handful of `200 miss` rows are PoPs that
had already re-fetched.

**After the purge at 16:32:15Z**, the same paths returned **200 with zero 404s** — first `miss`,
then `hit` on the newly cached 200. The 48-hour comparison against the baseline table above is
still pending; re-run `scripts/observability/cloudflare-404-volume-check.sh`, and note that only
data from **after 16:32Z** reflects the fixed state.

**F-5 — stale icon 404s were pinned in Cloudflare's cache; cleared by purge at 16:32Z.**

> **Correction, 2026-07-29.** This finding was first written up as "item 1 accidentally made 404s
> edge-cacheable, which is the broad 404 caching §8.11 rejected," with a proposed fix that
> rewrote `Cache-Control` on 4xx responses in `ilas_seo`. **That conclusion was wrong and the
> proposed fix has been withdrawn** — it would have partially reverted item 1, the audit's
> highest-value change. The measurements below were sound; the interpretation was not. What
> follows is the corrected analysis.

The observation stands: for the first 11.5 h after cutover the icon paths kept returning 404 with
`cacheStatus=hit`, while the origin returned 200 for all three (verified by checksum). Measured on
the Live origin, a `fast_404`, a regular Drupal 404, a 403 and a 301 all now send
`cache-control: max-age=86400, public`.

**Why that is correct behaviour, not a defect:**

1. **It is intended, and the audit predicted it.** §8.9's own evidence table lists `/nl/videoteca`
   — a **404** — as `MISS (cacheable) / max-age=86400, public` *before* item 1; that cacheable-404
   was the decisive evidence for the whole §8.9 diagnosis. §8.9's test script says so outright:
   `curl -sI $BASE/news | grep -i cache-control  # 404s become cacheable too`.
2. **Drupal supports cacheable 4xx by design.** `ClientErrorResponseSubscriber` tags every 4xx
   response with `4xx-response`, and `EntityBase::invalidateTagsOnSave()`
   (`web/core/lib/Drupal/Core/Entity/EntityBase.php:558-572`) invalidates that tag whenever an
   entity with a canonical link is created or updated. Core's comment: *"Creating or updating an
   entity may change a cached 403 or 404 response."* Correctness is handled by cache tags.
3. **It is not the mechanism §8.11 rejected.** §8.11's option 5 was a *Cloudflare Cache Rule with
   Edge TTL "ignore cache-control"* — overriding origin intent and applying to HTML as well. What
   exists here is the origin declaring tag-backed cacheability, which Cloudflare then honours.
   Different mechanism, different risk profile. §8.11's recommendation was never violated; only
   its factual premise ("this site's 404s send `must-revalidate, no-cache, private`") went stale,
   and that premise was already only true of non-prefixed paths when it was written.
4. **The exposure is narrower than first reported.** Splitting zone 404s since 05:00Z by path
   type: static-extension paths **230 cached (`hit`)**; HTML/page paths **0 cached** (81
   `dynamic`, 52 `none`). §8.11's headline worry — "pages about to be published" — is **not**
   exposed at Cloudflare at all. Only file-like paths are.

**Resolution.** The Cache Purge permission was added to the API token and the nine icon/manifest
URLs were purged at **2026-07-29 16:32:15Z**. Verified immediately after: requests to those paths
returned **200, zero 404s**, first as `miss` (re-fetched from origin) then `hit`. FU-15 closed.

**Residual, correctly attributed.** The real gap is not 404 policy — it is that **edges which
cannot read Drupal's cache tags never learn about an invalidation**:

- **Pantheon's Global CDN** — this is §8.10 verbatim: no `Surrogate-Key` header, so "invalidation
  relies on the 24-hour `max-age` expiring or a full CDN clear — editors see up to 24 h of
  staleness." Confirmed today: **no `Surrogate-Key` on any Live page**, and the front page was
  being served at `age: 43046` (**~12 h old**). Before item 1 most pages were uncacheable so edits
  appeared at once; now they may not. §8.10 deferred its own fix *only* because "roughly 80 % of
  pages are not cached at all… installing it before fixing §8.9 would add two known risks in
  exchange for a benefit the site cannot yet realise." §8.9 is now fixed, so that precondition is
  met — which is exactly **tracker item 13**; it has been assessed (see the item 13 section below), not implemented.
- **Cloudflare** — has no cache-tag visibility and no purge integration, and §8.10 already records
  that. At this volume a targeted purge is the right lever, not automation; see FU-16 for the
  item 7 case.

**No code change was made to `ilas_seo` or to any response header.**

### Rollback

`git revert 259ada7f8f` (icons) or `git revert c213ebb9a4` (fast_404), then redeploy. The commits
share no files. Reverting the icons returns those paths to 404 and nothing else. Reverting
`fast_404` additionally needs the config value put back on each environment, since it was applied
by `config:set` rather than by import.

---

## Item 3 — `www` → apex Single Redirect (§8.2)

**Change.** One Cloudflare Single Redirect on zone `idaholegalaid.org`. No code, no Drupal
config, no deploy.

| Field | Value |
|---|---|
| Rule name | `ILAS - www to apex` |
| Rule ID | `ce2a37cdad2c44199770caee0516029d` |
| Ruleset | `47dd5943196a4187857c43474a753864` — `http_request_dynamic_redirect`, zone entrypoint |
| Ruleset version | 2 → **3** |
| Expression | `(http.host eq "www.idaholegalaid.org")` |
| Target (dynamic) | `concat("https://idaholegalaid.org", http.request.uri.path)` |
| Status code | `301` |
| `preserve_query_string` | **true** |
| Enabled | true |
| Created | 2026-07-29T03:42:04Z |

Added with `POST …/rulesets/{id}/rules` (append) rather than `PUT` on the ruleset, so no
existing rule could be dropped. The ruleset was **empty** beforehand (`rule_count: 0`).

**Pre-change conflict check — clean.** Dynamic-redirect ruleset: zero rules. Page Rules:
zero. WAF custom rules: 8, none with a `redirect` action and none matching on `http.host`.
Exports in `docs/evidence/cf-www-apex-old-dns/`.

**Origin semantics reproduced exactly.** The Pantheon origin redirect was measured first with
explicit `Host` headers against the Live platform hostname: 301, path preserved, full
multi-parameter query preserved, apex unaffected. The validation document *asserted* query
preservation; it is now proven (`05-origin-redirect-semantics.txt`).

**Result.** The 301 moved from `server: Pantheon` +
`x-pantheon-redirect: primary-domain-policy-doc` to `server: cloudflare` + `cf-ray`, with no
origin round trip. Full matrix in `20-redirect-tests.md`, volume delta in `21-origin-volume.md`.

**Rollback.** Disable, don't delete:
```bash
TOKEN=$(tr -d '\n' < ~/.secrets/cloudflare_api_token); ZONE=7aef3c4adc977c9f645472338b031450
curl -s -X PATCH "https://api.cloudflare.com/client/v4/zones/$ZONE/rulesets/47dd5943196a4187857c43474a753864/rules/ce2a37cdad2c44199770caee0516029d" \
  -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" --data '{"enabled": false}'
```
Dashboard: Rules → Redirect Rules → toggle `ILAS - www to apex` off. The Pantheon origin
redirect remains underneath, so `www` keeps working throughout — there is no broken window.
**Do not** remove Pantheon's primary-domain setting and **do not** detach
`www.idaholegalaid.org` from the Live environment; `terminus domain:list` confirms that
attachment is what makes the origin fallback work.

---

## Item 11 — delete `old.idaholegalaid.org` (§8.3)

**Change.** Deleted the A record `old.idaholegalaid.org → 72.3.167.82` (DNS-only, TTL auto,
record id `168615565fcd4be24606e1eaed627821`) from the Cloudflare zone on 2026-07-29.

**The gate this item carried was "confirm `72.3.167.82` is not third-party-claimable." It is
third-party — that is why it was deleted.** ARIN RDAP puts `72.3.167.80/28`
(`NET-72-3-167-80-1`) under **Phoenix Group Holdings LLC**, inside Rackspace Backbone space,
registered 2016-11-30. A record we control pointed at an IP an unrelated company holds:
textbook dangling DNS.

**Evidence — every surface negative.** Full write-up in `30-dns-investigation.md`. Summary:
IP dead on 80/443 and no reverse DNS; **zero** Wayback captures ever; zero search-index
presence; zero hits in the working tree, all 30+ git refs, GitHub Actions and repo
variables/secrets, monitoring scripts, Drupal config, DB dumps, and local docs; and
`terminus domain:list` shows `old.` was **attached to no Pantheon environment**, so it was
never routable there regardless.

**Post-delete verification** (`34-dns-post-delete-verification.txt`): authoritative
**NXDOMAIN** at `mark.ns.cloudflare.com`, empty at `1.1.1.1` and `8.8.8.8`, A and AAAA both
empty. Record count 31 → 30; the normalised diff (`32-dns-diff.txt`) shows exactly one removed
record. Apex, `www`, `mail`, `laci.*`, MX (Proofpoint), NS, SPF, DMARC and DKIM all unchanged.

**Rollback.**
```bash
curl -s -X POST "https://api.cloudflare.com/client/v4/zones/$ZONE/dns_records" \
  -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
  --data '{"type":"A","name":"old","content":"72.3.167.82","proxied":false,"ttl":1}'
```
Dashboard: DNS → Records → Add record → A, name `old`, IPv4 `72.3.167.82`, Proxy status **DNS
only**, TTL Auto. Propagation < 5 min. The new record ID will differ; nothing depends on it.

### Findings during implementation

**F-7 — Redirect rules take a few minutes to propagate; do not judge them immediately.** The
first test, run **11 seconds** after creation, returned `HTTP/2 403` from SBFM rule
`874a3e315c344b1281ad4f00046aab6f` instead of the new 301, on `curl`, `WebFetch` and a
browser-like User-Agent alike. That briefly looked like evidence that redirect rules run
*after* bot management on this zone, contrary to Cloudflare's documented phase order. **They
do not** — roughly four minutes later every path, exempt or not, returned the 301 correctly.
The ordering is as documented: `http_request_dynamic_redirect` precedes
`http_request_sbfm`, which is why a `curl` that gets 403 on the apex gets a clean 301 on `www`.

Two things worth keeping from the detour. First, allow several minutes before concluding
anything from an edge-rule test on this zone. Second, if a rule ever does need testing on a
path the WAF blocks, the existing skip rules `92082bed09cb429bbc3f0579a3d65d37`
(`/robots.txt`, `/.well-known/security.txt`) and `db42c4d6176a4c73ad704b459da1d32d`
(`/assistant/api/session/bootstrap`) exempt those paths from SBFM for any client.
Cloudflare's `request-tracer` endpoint would answer ordering questions directly but returns
`Authentication error` for the current token.

**F-8 — `http://www` now reaches the apex in one hop instead of two.** The redirect rule fires
*before* Always Use HTTPS, so `http://www.idaholegalaid.org/x` goes straight to
`https://idaholegalaid.org/x`. Previously a browser took `http://www` → `https://www` (AUH) →
apex (origin 301). An unlooked-for improvement; recorded because §8.2 predicted the hop count
might go either way.

**F-9 — the read-only token's scope is wider than recorded, and it fluctuates.** The memory
note describes `~/.secrets/cloudflare_api_token` as read-only and expiring 2026-07-18. It
actually verifies as **active with no expiry** and holds **DNS edit** and **Dynamic Redirect
read + edit** — both writes in this task succeeded with it. Two `GET`s on the
`http_request_dynamic_redirect` phase returned `request is not authorized` about twenty
minutes before the identical request succeeded, so that phase's authorisation is
intermittent, not absent. Treat a single `request is not authorized` from this token as
possibly transient and retry before concluding the scope is missing. It still cannot read
`GET /user/tokens` (cannot self-enumerate) or `POST …/request-tracer/trace`.

**F-10 — `laci.idaholegalaid.org` is a subdomain the validation document does not mention.**
Added 2026-07-22: A + 2×AAAA pointing at Pantheon IPs (DNS-only), `prod.`/`test.` CNAMEs to
EC2, AmazonSES DKIM CNAMEs and its own `_dmarc`. Untouched by this work and verified unchanged
afterwards, but §8.3's subdomain enumeration ("only `www.`, `mail.`, `old.`") is now stale.

---

## Item 13 — `pantheon_advanced_page_cache` (§8.10): assessment

**Status: assessed, not implemented. Nothing installed or enabled in any environment.**
§8.10 deferred this "until §8.9 is resolved". §8.9 is resolved, so the blockers were
re-measured against this site rather than taken from the issue queue. **Both are materially
smaller than §8.10 recorded, and one new cost surfaced that §8.10 did not name.**

**The problem it would solve.** Item 1 made pages cacheable — its intended win — but nothing
purges Pantheon's CDN by cache tag. Measured on Live 2026-07-29: **no `Surrogate-Key` header on
any page**, and the front page serving at `age: 43046` (**~12 h old**). An editor's change can
stay invisible to anonymous visitors for up to 24 h unless someone runs a full cache clear.
Before item 1 most pages were uncacheable, so edits appeared immediately; that protection is
gone. This is the more consequential half of item 1's trade-off.

| §8.10 blocker | Re-measured on this site |
|---|---|
| "Installing PAPC will **silently disable** Big Pipe… a real behavioural change for staff" | **Stronger mechanism, far smaller impact.** `pantheon_advanced_page_cache.install:53-58` — `hook_install` *actively uninstalls* `big_pipe`, and `hook_requirements` raises `REQUIREMENT_ERROR` calling it incompatible and able to cause "504 Gateway Timeout and site breakage". But BigPipe only affects **authenticated** rendering and Live has **3 active users**. Pantheon's own text: *"Big Pipe provides no benefit on Pantheon."* Not the staff-facing regression §8.10 implied. |
| Webform file-upload regression [#3446954](https://www.drupal.org/project/pantheon_advanced_page_cache/issues/3446954) — one purge per file per image style, 34 s worst case | **Does not reach the employment-application path.** The image-style loop is **MIME-gated** (`pantheon_advanced_page_cache.module:26`, `strpos($file->getMimeType(), 'image') === 0`). Resume and cover-letter fields accept `pdf doc docx` only → never enter the loop. Only the optional *additional documents* field allows `jpg jpeg png`, capped at 3 → bounded worst case ~188 purge paths. |
| `Surrogate-Key` capped at 25,000 bytes, keys past the cap **silently trimmed** | Real, and **not fully silent** — `CacheableResponseSubscriber.php:113` logs a watchdog WARNING naming the consequence ("this page will not be cleared from the cache"). Still unmeasured on real pages; must be measured, not assumed. |

**New cost §8.10 did not name.** The same MIME-gated loop runs on **routine editor image
uploads**: **62 image styles** × every image saved = 62 edge-purge calls per image. That is the
everyday path, not the employment form. Worth auditing whether all 62 styles are in use before
enabling.

**Assessment: worth doing, as its own scoped piece of work** — not attached to an unrelated
deploy. When taken up, measure exactly three things on an isolated multidev: that tag purge
actually works, real `Surrogate-Key` header sizes, and image-upload save timing against the 62
styles. A Live decision stays separate and evidence-backed, per §8.10's own instruction.

**Interim lever, if the staleness needs capping first.** `config/system.performance.yml`
`cache.page.max_age: 86400` → `3600` caps CDN staleness at one hour instead of 24, with no
module, no BigPipe removal, and instant revert. It costs some cache efficiency and does **not**
fix file/image freshness, so it is a stopgap rather than a substitute.

---

## Follow-ups opened by this work

| ID | Item | Origin | Status |
|---|---|---|---|
| FU-1 | Make the assistant's `apiBase` language-aware, or pass the page langcode in the request, so `/es/` visitors get Spanish resource links regardless of browser `Accept-Language` | F-1 | ⬜ Not started |
| FU-2 | Tell program staff that browser-based auto-switching to Spanish is gone; the `/es` prefix and the language switcher are unchanged | §8.9B accepted trade-off | ⬜ Not started |
| FU-3 | Fix the lost-update race behind `LlmControlConcurrencyTest::testCacheStatsDoNotLoseConcurrentIncrements` — it fails ~1 run in 5 and randomly blocks deploys | F-4 | ⬜ Not started |
| FU-4 | Refresh the Test database from Live (it has 0 Spanish nodes), so Test can validate multilingual behaviour | F-5 | ⬜ Not started |
| FU-5 | Confirm the GitHub secret `ILAS_ASSISTANT_URL` resolves to the apex or a `*.pantheonsite.io` host, not `www` — a `POST` to `www` is now 301'd at the edge and would be downgraded to `GET`. The repo default is the apex (`promptfoo-evals/lib/ilas-live-shared.js:3`) and the origin already 301'd `www` before this change, so this is a confirmation, not a suspected break | Item 3 | ⬜ Not started |
| FU-6 | Check whether Google Search Console / GA4 have a `www` property registered — its hostname dimension now goes quiet, since `www` no longer reaches the origin at all | Item 3 | ⬜ Not started |
| FU-7 | Update the memory note on the Cloudflare token: it is active with no expiry and holds DNS + Dynamic Redirect **edit**, not read-only as recorded | F-9 | ⬜ Not started |
| FU-8 | Give the icon paths a real Edge TTL via a Cloudflare Cache Rule — Pantheon sends `no-cache, must-revalidate` on `/favicon.ico` and `/apple-touch-icon*.png`, so every visit still revalidates. Cheap, and it converts a revalidation into a true edge hit. Low priority: the paths are now 200s and Cloudflare is caching them anyway (`hit` observed post-purge) | Items 2 & 4, F-2 | ⬜ Not started |
| FU-9 | Resolve the pre-existing `ilas_site_assistant.settings` ordering drift on Dev/Test/Live. Values are provably identical; only top-level key order differs, so `config:status` reports `Different` on every environment and masks real drift. Fix once, deliberately, outside a feature deploy | Items 2 & 4, F-3 | ⬜ Not started |
| FU-15 | Purge the nine icon/manifest URLs from the Cloudflare cache so the item 2 fix takes effect immediately instead of waiting out the 24 h TTL | Items 2 & 4, F-5 | ✅ **Done 2026-07-29 16:32Z** — token was granted Cache Purge; purge succeeded and the paths verified serving 200 at the edge |
| FU-16 | Purge the affected URLs when item 7 adds redirects for legacy `/sites/idaholegalaid.org/files/*.pdf` paths. Those URLs 404 today, are edge-cached for 24 h because they carry a static extension, and Cloudflare has no cache-tag visibility — so adding the redirect alone will not dislodge the cached 404. Use `scripts/observability/cloudflare-purge-urls.sh` | Items 2 & 4, F-5 | ⬜ Not started |

> **Numbering note.** FU-10 – FU-14 are reserved: they were assigned in parallel on the
> `fix/canonical-host-normalization` branch (items 5 and 6) and will arrive on `master` when that
> work merges. Mine were renumbered to FU-15/FU-16 to avoid the collision.

---

## Open questions still owned elsewhere

§15 of the validation document lists eight items requiring Pantheon
workspace-administrator access, plus one technical question for Pantheon Support
(whether Global CDN configuration or `cache_hit_ratio` calculation changed around
2026-07-06). None of them blocks any item in this tracker.
