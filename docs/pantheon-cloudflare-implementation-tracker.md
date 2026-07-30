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
| 5 | Audit GitHub repo vars for the live platform hostname | §8.5 | ✅ **Done 2026-07-29** | Read via `gh`. Only `ILAS_ASSISTANT_URL` exists, as a secret; the other three flagged names are **unset**. See the dedicated section below. |
| 6 | Fix the dynamic canonical | §8.5 | 🟢 **In progress 2026-07-29** | Branch `fix/canonical-host-normalization`. §11.5's prescribed token **does not exist** — fixed in PHP instead, with zero config changes. See the dedicated section below. |
| 7 | Legacy files — high-confidence rows (8 applied, 3 stay 404, 1 deferred) | §8.4 | ✅ **Done 2026-07-29** | Redirects only — there were **no source links to fix**. rid 5157–5158, 5160–5165. §8.4's premises needed three corrections; see the dedicated section below. |
| 8 | Legacy files — editorial rows | §8.4 | ⏸️ Blocked | Gated on content-owner / legal review. Now **12 rows**: §8.4's 10, plus `MANUFACTURED HOMES.brochure.pdf` (moved out of item 7 on verification) and the sibling Landlord-Tenant brochure §8.4 missed. Queue: `docs/legacy-file-content-owner-review.md`. |
| 9 | SEO-crawler rule in **Log** mode | §8.6 | 🟡 **In progress (observation) 2026-07-29** | Shipped as a **mirror-skip**, not Log — the Log action is Enterprise-only and this zone is Business. Rule `b79f504cabd347dc8eda9fd7c8748347`, index 0, ruleset v14→v17. **Zero enforcement change.** See the dedicated section below. |
| 10 | Review logs → Managed Challenge | §8.6 | ⬜ Not started | Gated on the day-7 review, earliest **2026-08-05T20:03Z**. Never Block first. **Blocker already known:** Googlebot, `GoogleAssociationService` and `Google-Adwords-Instant-Mobile` are inside the SEO category today — §8.6's own promotion test fails as written. See F-14. |
| 11 | Verify `old.` IP not third-party; delete record | §8.3 | ✅ **Done 2026-07-29** | `72.3.167.82` **is** third-party (Phoenix Group Holdings LLC, Rackspace space) — dangling DNS. Record deleted. See the dedicated section below. |
| 12 | Re-measure for one full billing cycle | §13 | ⬜ Not started | Depends on item 1 landing. |
| 14 | Close the canonical 404 encoding loop | follow-up plan | 🟢 **Fixed and verified 2026-07-30, not yet deployed** | Cost **21,904 origin 404s in July** — ~12 % of all origin traffic. Root cause in `drupal/token`; fixed with a patch plus 4xx tag suppression in `ilas_seo`. See the dedicated section below. |
| 13 | Re-evaluate `pantheon_advanced_page_cache` | §8.10 | 🟢 **Assessed 2026-07-29, not yet implemented** | **Un-deferred.** §8.10's precondition ("revisit after §8.9 is fixed") is met, and F-5 confirmed the cost: no `Surrogate-Key` on any Live page, front page served at `age: 43046` (~12 h stale). Both §8.10 blockers re-measured and found much smaller than recorded — see the assessment below. No code enabled anywhere. |
| — | Platform-hostname redirect | §8.5 | 🔵 Deferred | **Not implemented.** Breaks CI (promptfoo POSTs to it); benefit already mitigated by Pantheon's `Disallow: /`. Re-confirmed by test after item 6 — see that section. |
| — | 404 caching / Cloudflare HTML caching | §8.11–12 | 🔵 Deferred | Root-cause fixes first. |
| — | Remove `Search Engine Optimization` from skip rule `64fae5be` *as specified* | §8.6 | ❌ Rejected | `sbfm_verified_bots: "allow"` means the stated mechanism does not work. Replaced by item 9. Re-confirmed live 2026-07-29 — the setting is still `allow`. The category *was* removed from `64fae5be` as part of item 9, but paired with a mirror-skip so nothing falls through. |

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

## Item 5 — platform-hostname dependency audit (§8.5)

§8.5 recorded this as unresolvable: *"GitHub repo variables and secrets cannot be read from the
repo."* They can be read with the `gh` CLI, and were, read-only, on 2026-07-29
(token scopes `gist, read:org, repo, workflow`).

### GitHub Actions variables and secrets

**Repository variables — all four, with values:**

| Name | Value |
|---|---|
| `ILAS_CONFIGURED_RATE_LIMIT_PER_HOUR` | `600` |
| `ILAS_CONFIGURED_RATE_LIMIT_PER_MINUTE` | `15` |
| `SENTRY_ORG_SLUG` | `idaho-legal-aid-services` |
| `SENTRY_PROJECT_SLUG_BROWSER` | `php` |

**Repository secrets (names only):** `GITGUARDIAN_API_KEY`, `ILAS_ASSISTANT_URL`,
`SENTRY_AUTH_TOKEN`.
**Dependabot secrets:** `GITGUARDIAN_API_KEY`, `ILAS_ASSISTANT_URL`.
**Repository environments:** none exist.
**Organisation variables:** not readable — `403`, needs `admin:org`.

### The four names §8.5 flagged

| Name | Finding |
|---|---|
| `ILAS_ASSISTANT_URL` | **Exists**, as a repo secret and a Dependabot secret. Value unreadable by design — the one genuine remaining unknown |
| `ASSISTANT_BASE_URL` | **Not set**, as neither variable nor secret. Always derived by stripping `/assistant/api/message` off `ILAS_ASSISTANT_URL` |
| `A11Y_BASE_URL` | **Not set.** `a11y-gate` falls through four levels to that same strip |
| `ILAS_PLAYWRIGHT_BASE_URL` | **Not set.** `assistant-playwright.yml` is its only consumer, so its daily 08:23 UTC cron warns and skips **every day** → **FU-10** |

Also unset: `CI_PROMPTFOO_ENV`, `ASSISTANT_PR_TARGET_ENV`, `ASSISTANT_NIGHTLY_TARGET_ENV` and
every `A11Y_ROUTE_*`. Every `target_env` therefore resolves to the literal `'dev'` default.
**No CI job targets Live by default.** Live is reachable only via `workflow_dispatch` with
`target_env: live`, or if the `ILAS_ASSISTANT_URL` secret happens to hold the live host.

### Hardcoded live-host HTTP calls — exactly one

| Location | Method | Notes |
|---|---|---|
| `edge-cache-sample.sh:51` | **GET** ×2 | `curl -sS -o /dev/null -D -` — headers kept, body discarded. Note this is a GET, not a HEAD: it pulls a full homepage body twice per run. Manual diagnostic, not CI |
| `edge-sample.txt:235` | — | Recorded output artefact |

### Derived live-host reachability

Everything else reaches the live host by derivation, through exactly two mechanisms:
`terminus env:view` and bash interpolation of `https://${ENV}-${SITE}.pantheonsite.io`.

| Location | Mechanism | Method |
|---|---|---|
| `scripts/ci/derive-assistant-url.sh:56` | `terminus env:view {site}.{env} --print`; hard-fails `exit 127` with no HTTP fallback if terminus is absent | none — prints a URL |
| `scripts/ci/resolve-assistant-target.sh:55-82` | precedence `ILAS_ASSISTANT_URL` → `ddev describe` → terminus | none |
| `promptfoo-evals/lib/gate-target.js:11` | `/^(dev\|test\|live)-[a-z0-9-]+\.pantheonsite\.io$/` — the only structural recognizer of the platform hostname in the repo | none |
| `promptfoo-evals/lib/ilas-live-shared.js:1348-1360` | `/assistant/api/session/bootstrap` | **GET** |
| `promptfoo-evals/lib/ilas-live-shared.js:1505-1508` | `/assistant/api/message` | **POST** |
| `scripts/smoke/assistant-smoke.mjs:291,542,557,658,688` | message, bootstrap, faq, suggest | **POST** + **GET** |
| `scripts/ci/run-vector-provenance-smoke.js:203` | bootstrap then message | **GET** + **POST** |
| `scripts/observability/language-cacheability-probe.sh:70,115,130,144` | `https://${ENV}-${SITE}.pantheonsite.io`, 10 paths × 3 passes | **HEAD** — 30 per live run, the highest-volume dependency |
| `scripts/git/finish.sh:377-409` | post-deploy gate, hardwired `--env dev` | GET + POST |
| `scripts/ci/publish-gates.lib.sh:356` | hardwired `--env dev` | GET + POST |
| `package.json:37` `eval:promptfoo:quality` | `--env ${CI_PROMPTFOO_ENV:-dev}` | GET + POST |
| `package.json:39` `eval:promptfoo:live` | **apex through Cloudflare**, not the platform host | GET + POST |
| `.github/workflows/quality-gate.yml` (hosted manual gate, `target_env: live`) | secret-driven | GET + POST |
| `.github/workflows/assistant-nightly-quality.yml` (weekly, Sun 10:00 UTC) | secret-driven | GET + POST |
| `.github/workflows/assistant-playwright.yml` (daily 08:23 UTC) | `vars.ILAS_PLAYWRIGHT_BASE_URL` — unset, so it skips | GET + widget POST |

**Not platform-hostname HTTP:** everything over `terminus` / `*.drush.in:2222` / `api.pantheon.io`
(`scripts/deploy/pantheon-deploy.sh`, `scripts/observability/sentry-release.sh:79`,
`scripts/pull-live.sh`, `run-promptfoo-gate.sh:520-526`). Cloudflare scripts target
`api.cloudflare.com`. `npm run git:publish` has no platform-host HTTP dependency at all.
Documentation and evidence artefacts under `docs/` reference the hostname but issue nothing.

### Structural gaps found while auditing

- **The env-mismatch guard is regex-gated.** `gate-target.js:11` recognises only
  `{env}-{slug}.pantheonsite.io`. A secret pointing at the apex or any Cloudflare-fronted host
  yields `pantheonEnv=''` → `not_applicable` → `resolve-assistant-target.sh:107` never fires.
  A secret set to the apex with `--env live` passes silently. → **FU-11**
- **Fail-open and fail-closed are mixed.** `a11y-gate` (`quality-gate.yml:301`) and
  `assistant-nightly-quality` (`:130`) warn-and-skip on a missing base URL; the smoke job
  (`:238`), the PR gate (`:493`) and the hosted gate (`:579`) hard-fail. Removing a secret
  silently disables the first group. → **FU-12**
- **Three hostname spellings in docs and comments:** `idaho-legal-aid-services` (correct, per
  `derive-assistant-url.sh:4`), `idaholegalaid` (`quality-gate.yml:24`,
  `run-quality-gate.sh:19,406`) and `idaho-legal-aid` (`fingerprint-smoke.sh:14`). Doc-only;
  they would 404 if copy-pasted. → **FU-13**

### What is still unknown

Which host the `ILAS_ASSISTANT_URL` secret holds. GitHub masks secret values in logs by design.
It can be settled without ever printing it: `run-promptfoo-gate.sh:613-614,847` records
`target_source` and a `classify_assistant_url` label in the gate summary, and the label
distinguishes apex from `*.pantheonsite.io` without echoing the value.

```bash
gh run list -w quality-gate.yml -L 5
gh run view <id> --log | grep -iE 'target_source|target_host|classif'
```

This also settles **FU-5**.

---

## Item 6 — canonical host normalisation (§8.5)

Branch `fix/canonical-host-normalization`.

### §11.5 could not be implemented as written — correction

§11.5 says: *"Replace `[current-page:url:absolute]` on the `canonical_url` key with a token that
resolves to the configured base URL."* **No such token exists.** Verified against the installed
code, not from memory:

| Candidate | Resolves via | Host-derived? |
|---|---|---|
| `[current-page:url:absolute]` | `Url::createFromRequest()` — `token/src/Hook/TokenTokensHooks.php:470-491` | **Yes** |
| `[site:url]` | `Url::fromRoute('<front>', [], ['absolute' => TRUE])` — `core/modules/system/src/Hook/SystemTokensHooks.php:150-155` | **Yes** |
| `[site:base-url]` | `router.request_context->getCompleteBaseUrl()` — `SystemTokensHooks.php:140-143` | **Yes** (see below) |
| a Metatag-provided base token | — | **Does not exist** in Metatag 2.2.0 |

And the obvious settings-level fix is also dead. `$base_url` in `settings.php` is **inert in
Drupal 11**: `DrupalKernel::initializeRequestGlobals()`
(`core/lib/Drupal/Core/DrupalKernel.php:1250-1258`) sets
`$base_root = $request->getSchemeAndHttpHost(); $base_url = $base_root;` **unconditionally**,
after `settings.php` has loaded. `RequestContext::fromRequest()` looks like an escape hatch but
runs afterwards and only populates `completeBaseUrl`, which is not what
`UrlGenerator::generateFromRoute()` uses — that reads `context->getScheme()` and
`context->getHost()` at `UrlGenerator.php:385,401`, straight off the Host header, filtered only
by `trusted_host_patterns`, which is `['.*']` (`settings.pantheon.php:185-187`).

So §11.5's intent was realised differently: the emitted values are normalised in PHP.
**Zero configuration files were changed.**

### Rejected: rewriting `router.request_context`

An event subscriber calling `$request_context->setHost()` would fix every absolute URL at once
and looks like the root-cause fix. It was rejected because **it silently implements the
redirect this item defers.** `config/redirect.settings.yml` has `route_normalizer_enabled: true`,
and `RouteNormalizerRequestSubscriber:118` generates with `['absolute' => TRUE]` and 301s on
mismatch — so every request on the platform hostname would redirect to the apex. Core's
`FormSubmitter::redirectForm()` also emits absolute redirects. A 301 on the promptfoo POST is
downgraded to GET with the body dropped: exactly the failure §8.5 defers the redirect to avoid.

The implemented approach rewrites only **advertised** URLs (SEO metadata) and never
**navigational** ones (redirects, form actions, links). That distinction is the whole point.

### Rejected: config-only

`canonical_url: 'https://idaholegalaid.org[current-page:url:relative]'` works mechanically —
`CanonicalUrl` carries no `absolute_url` flag so `MetaNameBase::output()` passes the literal
through, and `OgUrl` does carry it but `parse_url($value, PHP_URL_HOST)` is non-null on an
already-absolute value so it does not fire either. It was still rejected:

- It touches **14** files, including `config/views.view.forms_categories.yml:486` and
  `config/views.view.guides_categories.yml:486`, which carry embedded `metatag_views` values
  and sit **outside** `MetatagCanonicalConfigTest`'s glob. → **FU-14**
- **It cannot fix hreflang at all.** Those come from `hreflang.module:33,73,81` and core
  `ContentTranslationHooks::pageAttachments():519`, both `Url::setAbsolute()`, with no config
  surface. An apex canonical beside `*.pantheonsite.io` alternates is a *worse* signal than
  today's consistent-but-wrong output.
- It cannot fix the JSON-LD graph either.
- It is exposed to **F-2**: a blind `drush config:export` on this repo rewrites
  `config/metatag.metatag_defaults.global.yml` from stale local DB state, silently reverting the
  fix with no test failure. PHP cannot be reverted that way.
- Every environment would advertise the apex, so Dev and Test would lie about being production.

### The change

| Commit | Files |
|---|---|
| `d87c562fbd` | `ilas_seo/src/CanonicalHost.php` (new), `tests/src/Unit/CanonicalHostTest.php` (new), `web/sites/default/settings.php`, `docs/env-vars.md` |
| `a79a810a2e` | `ilas_seo/ilas_seo.module` |
| `7234a98824` | `ilas_seo/ilas_seo.services.yml`, `src/StructuredData/GraphBuilder.php`, `tests/src/Kernel/GraphBuilderTest.php` |
| `a3090eb450` | `tests/src/Functional/CanonicalHostRewriteTest.php` (new) |

`CanonicalHost` is a dependency-free `final class` of static methods, so it unit-tests under
plain `PHPUnit\Framework\TestCase`. It swaps **scheme, host and port only**, reassembling
`path + '?' . query + '#' . fragment` byte for byte. Values already on the base come back
unchanged by identity reassembly; `mailto:`, `tel:`, `data:` and document-relative references
are left alone.

Wired as the first statements of `ilas_seo_page_attachments_alter()`, above the admin early
return. Four layers, each a **one-line revert**:

| Call | Covers |
|---|---|
| `normalizeHeadTags()` | `<link rel="canonical">`, `<link rel="shortlink">`, `og:url`, and node-level `og:image` / `twitter:image` (which `MetaNameBase::output():544` prefixes with the request host) |
| `normalizeHeadLinks()` | hreflang alternates, plus core's entity canonical/shortlink. `rel="alternate"` is rewritten **only** when an `hreflang` attribute is present, so RSS feed links are untouched |
| `normalizeSchemaTags()` | the `@id` / `url` / `mainEntityOfPage` / `item` properties of `schema_metatag` meta tags, before `schema_metatag` folds them into `ld+json`. **`sameAs` is deliberately excluded** — it holds external social profile URLs, and rewriting them would corrupt the graph |
| `GraphBuilder::pin()` | the `BreadcrumbList` `@id` and every `item`. `ORG_ID`, `WEBSITE_ID`, `ORG_LOGO`, `url`, `urlTemplate` and `sameAs` were already apex-hardcoded, so these were the odd ones out and the graph's `@id` cross-references stopped resolving on the platform hostname |

**Ordering proof.** Every `hook_page_attachments()` runs before any
`hook_page_attachments_alter()`, so Metatag (`metatag.module:128`), hreflang
(`hreflang.module:18`) and `ContentTranslationHooks::pageAttachments()` have all deposited
values by then. Every `*_page_attachments_alter()` sorting after `ilas_seo` (weight 0,
alphabetical: `metatag` :108, `schema_metatag` :133, `simple_sitemap` :151 in
`core.extension.yml`) only *removes* or *folds* entries — `metatag` dedupes taxonomy
canonical/shortlink and moves itself last via `metatag_module_implements_alter():178`;
`simple_sitemap` unsets hreflang only when `disable_language_hreflang` is TRUE (it is `false`);
`schema_metatag` consumes flagged meta into one `ld+json` script. Nothing re-adds a
Host-derived link.

### Where the base comes from

`_ilas_canonical_base_url()` in `web/sites/default/settings.php`, assigned to
`$settings['ilas_canonical_base_url']`:

| Environment | Value |
|---|---|
| Live | `https://idaholegalaid.org` |
| Dev / Test / any multidev | `https://{env}-{PANTHEON_SITE_NAME}.pantheonsite.io` |
| DDEV / local / CI | `''` — **feature entirely inert**, today's behaviour preserved |

`ILAS_CANONICAL_BASE_URL` overrides it. Two uses, both documented in `docs/env-vars.md`:
timeboxed pre-Live verification on Dev, and a deploy-free kill switch (set it to `-`;
`normalizeBase()` returns `''` and every consumer no-ops).

`getenv('PUBLIC_SITE_URL')` was considered and rejected: Pantheon does not set it, it is
operator-set and may be absent (its only consumer, `settings.php:696`, tolerates absence with
`?: ''` because a missing Sentry regex is harmless — a missing canonical base is not equally
harmless and would give no signal), its meaning on non-Live is ambiguous, and it would couple
Sentry's trace-propagation allowlist to every canonical on the site.

### Cache metadata

**No cache contexts, tags or max-age were added, on purpose.** The emitted values are now
constants drawn from `$settings` rather than from the request, so head output is
**host-invariant**. Declaring `url.site` would fragment the render cache along a dimension the
output no longer varies on.

This also closes a latent bleed. Neither Metatag (`TokenTokensHooks.php:471-473` adds only
`url.path`) nor hreflang (`hreflang.module:86` adds only `url.query_args`) declares `url.site`,
while `dynamic_page_cache` is enabled and keys purely on declared contexts — so a response
rendered under the platform host could in principle be served under the apex with the wrong
canonical baked in. Anonymous traffic was safe only by accident, because
`PageCache::getCacheId()` keys on `getSchemeAndHttpHost() . getRequestUri()` regardless.

### Local test results (2026-07-29, pre-deploy)

| Gate | Result |
|---|---|
| `phpunit --testsuite unit --group ilas_seo` | **54 tests / 102 assertions, all pass** (47 of them new) |
| `phpunit --testsuite kernel --group ilas_seo` | **17 tests / 210 assertions, all pass** (2 new) |
| `phpunit --testsuite functional --group ilas_seo` | **9 tests / 58 assertions, all pass** (3 new) |
| `phpcs --standard=phpcs.xml.dist` on all changed module files | **0 errors, 0 warnings** |
| `phpstan analyse -c phpstan.neon.dist` | **No errors** — baseline unchanged, not regenerated |

`web/sites/default/settings.php` is outside `phpcs.xml.dist`'s scope (`web/modules/custom` and
`web/themes/custom` only) and already carried 180 `Drupal.WhiteSpace.ScopeIndent` findings
before this change; the new function adds more of the same known false positive and no new
class of finding.

`MetatagCanonicalConfigTest` needed **no change** — every YAML value is untouched, so it still
guards exactly what it guarded before.

### Findings during implementation

**F-10 — the canonical tag does not carry the query string, before or after this change.**
The functional test initially asserted that `?keys=divorce&page=2` survived into the canonical.
It does not: Drupal's own `[current-page:url:absolute]` token drops the query on a routed page.
That is pre-existing behaviour and outside this change's remit, so the test was rewritten to
assert **parity** — the pinned canonical must equal the unpinned one with only scheme and host
swapped. `CanonicalHost::rewrite()` preserving a query when one *is* present is covered
directly in the unit test. Recorded because §8.5 asks for "query handling" to be preserved, and
the honest answer is that it is preserved exactly as it already was, not that queries appear in
canonicals.

**F-11 — `twitter:url` does not exist and was deliberately not matched.**
`metatag_twitter_cards/src/Plugin/metatag/Tag/` ships only the three App-URL variants, and no
config key sets a Twitter page URL. It was omitted from the matcher rather than carried as dead
code. `twitter:image` *is* matched, because that one is real.

**F-12 — `BrowserTestBase` needs the action-compat shim.** `CanonicalHostRewriteTest` hit the
same `node_make_sticky_action` `PluginNotFoundException` documented as deviation D-INFRA-01 in
`SchemaPropertiesTest`, and needs `ilas_site_assistant_action_compat` first in `$modules`.
Pre-existing infrastructure quirk, not caused by this work.

### Before and after

*To be filled in after the Dev → Test → Live deploy, together with the per-language matrix and
the confirmation that the platform hostname still returns 200 and not a redirect.*

---

## Item 7 — legacy file links (§8.4)

**Status: done 2026-07-29.** Eight redirects live: **rid 5157, 5158, 5160, 5161, 5162, 5163, 5164,
5165**. Legacy-prefix redirect count 70 → 78. **No content entity was modified and no content
revision was created** — see correction 1.

Artifacts: `scripts/seo/legacy-file-redirects-8-4.php` ·
`docs/legacy-file-redirects-8-4-state.json` · `docs/legacy-file-redirects-8-4-rollback.md` ·
`docs/legacy-file-content-owner-review.md`.

### Three corrections to §8.4

**1. "Fix the source link" had no target.** §8.4's "Linking page: our site" column was inferred
from Cloudflare's referrer *host*, not from a verified link. A scan of every text, varchar and blob
column in the live database — 1,660 columns, whole-DB, not just `node__field_body` — found **zero**
current content containing `sites/idaholegalaid.org/files`, both before and after this work. The
referrer host comes from stale browser history, external caches, links inside PDFs we have
distributed, and search-engine snapshots. Redirects were the only available lever.

The only `idaholegalaid.org/files` matches anywhere are 12 rows pointing at `/files/html/…` — a
different D7 family (retired interactive training modules), already 301→`/forms` via 70 existing
redirect entities, not 404, and half of them Spanish. Queued for editorial review, not touched.

**2. The row counts in §8.4's summary are wrong.** It reads "10 high-confidence mechanical fixes …
2 should stay 404." The table actually carries **12 High rows: 9 actionable + 3 leave-404**
(`styles/logo_for_og/public/ilas-logo-100.png`, `images/LSClogo_0.jpeg`, `ILAS_60x60.jpg`).
Of the 9, one was withdrawn on verification (below), so **8** were applied.

**3. `MANUFACTURED HOMES.brochure.pdf` is not a mechanical row.** §8.4 rates it High confidence,
but its proposed destination `advice-for-renters-manufactured-homes.pdf` carries XMP `ModifyDate`
**2013-09-05** — seven years *older* than the 2020-02-24 file the legacy URL used to serve — while
a newer manufactured-homes document (`manufactured-homes-advice-renters.pdf`, **2025-12-23**) sits
in the same directory. Redirecting to a superseded edition of tenant-rights material is what the
brief forbids. The redirect was created (rid 5159), then **removed**; the path returns 404 pending
a legal-currency read. Also removed from the script's entry list so a re-run cannot recreate it.

### Document identity — how each destination was proven

Not merely *a* document at the far end, but the *right* one. Every legacy file was pulled from the
Wayback Machine and compared against what the redirect now serves.

| Destination | Proof |
|---|---|
| `debit-card-electronic-transactions-protections-fact-sheet.pdf` | **Byte-identical** to the archived legacy file (md5 match, 177,858 B) |
| `community-property-guide.pdf` | **Byte-identical** (363,258 B) |
| `caregiving-brochure.pdf` | **Byte-identical** (187,628 B) |
| `decision-making-as-we-age-brochure.pdf` | **Byte-identical** (5,325,070 B) |
| `bankruptcy-brochure.pdf` | **Byte-identical** (594,723 B) |
| `normal-wear-tear-guide.pdf` | **Byte-identical** (259,628 B) |
| `domestic-violence-survivors-relocation-guide.pdf` | Not in the Wayback index under that name. Content-verified: `/Title` "Should I Stay or Should I Go", `/Author` Deborah Goelman, 13 pages, text covers DV relocation, custody and parental-kidnapping risk. Unique candidate in the modern file set. |
| `/forms` | A page, not a document — supersession, not substitution. Route `view.forms_categories.page_1`, title "Free Legal Forms & Documents". |

Six byte-identical matches also settle the legal-currency question for those rows: the redirect
serves the *same file* users were already getting, at a new address. No new obsolescence introduced.

### The two-cache trap

The redirects were created and Cloudflare was purged, and the paths **still returned 404**. Cause:
Pantheon's own Global CDN (Fastly) had the 404 cached with `max-age=86400, public`, and it sits
*upstream* of Cloudflare — visible as `x-served-by: cache-bfi…`, `x-cache: MISS, HIT`, `age: 271`
on the platform hostname. `terminus env:clear-cache idaho-legal-aid-services.live` was required,
after which all eight resolved immediately. FU-16 covered only the Cloudflare half; FU-17 raised.

### Verification

Probed on the Pantheon origin, because Cloudflare's WAF 403s the operator IP for every request
including the homepage — an edge `curl` proves nothing about the redirect.

| Path | Before | After |
|---|---|---|
| `Protections for Debit Card and Electronic Transactions Fact Sheet.pdf` | 404 | 301→200 `application/pdf` 177,858 |
| `mortgage_interestonly.pdf` | 404 | 301→200 `text/html` `/forms` |
| `What is Community Property Guide - FINAL.pdf` | 404 | 301→200 `application/pdf` 363,258 |
| `MANUFACTURED HOMES.brochure.pdf` | 404 | **404 (withdrawn — deferred)** |
| `Caregiving Brochure - Final.pdf` | 404 | 301→200 `application/pdf` 187,628 |
| `Decision Making As We Age Brochure.pdf` | 404 | 301→200 `application/pdf` 5,325,070 |
| `bankruptcy brochure english.pdf` | 404 | 301→200 `application/pdf` 594,723 |
| `What is Normal Wear and Tear Guide.pdf` | 404 | 301→200 `application/pdf` 259,628 |
| `LRC Relocation Guide for Domestic Violence Survivors.pdf` | 404 | 301→200 `application/pdf` 385,693 |
| `styles/logo_for_og/public/ilas-logo-100.png` | 404 | 404 *(intended)* |
| `images/LSClogo_0.jpeg` | 404 | 404 *(intended)* |
| `ILAS_60x60.jpg` | 404 | 404 *(intended)* |

Every redirected path is exactly **one hop** — no chains, no loops. Rehearsed on Test first
(applied, re-run to prove idempotency, then rolled back) before live.

### Monitoring

Cloudflare edge, legacy prefix, 3-day baseline captured 2026-07-29 before the change: **1,631
requests across 184 distinct paths**. Note that most of that volume already returns 403 (WAF) or
301 (`www`→apex, item 3) rather than 404 — e.g. `mortgage_interestonly.pdf` was 122×403, 37×301,
33×404. **§8.4's 7-day 404 figures are not a valid baseline for this comparison.**

Track for 14 days with the GraphQL query in §13, filtering
`clientRequestPath_like: "/sites/idaholegalaid.org/files%"` and grouping by `edgeResponseStatus`.
Do **not** use `redirect_404` as the volume source — `config/redirect_404.settings.yml` globs
`/*_*` and `/*__*` swallow most real 404s.

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

## Item 9 — SEO-crawler observation rule (§8.6 / §10.2)

**Status: 🟡 observation running. No enforcement was enabled — Managed Challenge was not created,
staged, or scheduled.**

### What changed

Custom ruleset `f887ac01edd44986aae31e7e6c05c8bb` (`http_request_firewall_custom`), **v14 → v17**,
applied 2026-07-29T20:03:44Z.

**Step 1 — new rule, index 0:**

| Field | Value |
|---|---|
| Rule ID | `b79f504cabd347dc8eda9fd7c8748347` |
| `ref` | `ilas_seo_category_observe` |
| Position | **0**, immediately before `64fae5be` |
| Expression | `(cf.verified_bot_category eq "Search Engine Optimization")` |
| Action | `skip` (mirror — see F-13) |
| `action_parameters` | `phases: [http_request_firewall_managed, http_ratelimit, http_request_sbfm]`, `ruleset: current` |
| `logging.enabled` | `true` |
| `enabled` | `true` |

**Step 2 — amend `64fae5becbce484caf8c43fd58734a45`:**

```diff
- (cf.verified_bot_category in {"Search Engine Crawler" "Search Engine Optimization" "Page Preview" "Monitoring & Analytics" "Accessibility" "Security" "Webhooks"})
+ (cf.verified_bot_category in {"Search Engine Crawler" "Page Preview" "Monitoring & Analytics" "Accessibility" "Security" "Webhooks"})
```

Six categories preserved: Search Engine Crawler, Page Preview, Monitoring & Analytics,
Accessibility, Security, Webhooks. `action`, `action_parameters`, `logging` and `enabled` untouched;
the description was corrected because it still named the SEO category.

Order matters and was deliberate: **add first, amend second**. The reverse opens a window in which
SEO-category bots fall through to the managed WAF, rate limiter, Drupal Hardening and the CN/RU
Geo-Challenge.

### Verification

Rules 2–8 verified byte-identical to the pre-change export. All post-change assertions passed:
new rule at index 0 with `skip`/`enabled`/`logging`; its `action_parameters` **deep-equal** to
`64fae5be`'s (both live and as exported); SEO removed; all six kept categories present; category
count exactly 6.

**35-minute post-change watch (20:05Z → 20:38Z) — behaviour confirmed unchanged:**

| Check | Result |
|---|---|
| SEO category edge status | 15 × 404, 1 × 200 — **no 403s, no challenge pages** |
| Better Stack | 10 × 200 |
| UptimeRobot | 7 × 200 |
| Search Engine Crawler | 11 × 200, 8 × 301, 1 × 404 — normal |
| Rule attribution | `firewallEventsAdaptiveGroups`: **16 × `skip` on `b79f504cab…`**, exactly matching the 16 SEO-category requests in the window |

The new rule is confirmed to be the one matching SEO-category traffic — `64fae5be` no longer sees
it, and nothing else in the ruleset intercepts it.

Evidence: `docs/evidence/cf-seo-bot-observation/` (`01-waf-custom-before.json` = v14 rollback source
of truth, `10-waf-custom-after.json` = v17, `06-baseline-bot-census.json` = 7-day pre-change census).

### Findings

**F-13 — §10.2 Step 2 is not executable as written; the Log action is Enterprise-only.** This zone
is Business Website (`plan.legacy_id: business`). Cloudflare: *"Only available on Enterprise plans.
Recommended for validating rules before committing to a more severe action."* The rule was therefore
created as a **mirror-skip** — a `skip` carrying the exact `action_parameters` that `64fae5be`
applied to this category before the change — so runtime behaviour is unchanged and the rule exists
purely to give SEO-category matches their own rule ID. Promotion to Managed Challenge later is a
single-field edit. The trade-off is explicit: because the managed-WAF skip is preserved, this does
**not** gather §8.6's "missing evidence" about what the managed WAF would block. That question stays
open and is answerable only by a change that is genuinely enforcing.

`"ruleset": "current"` in `64fae5be` is load-bearing and easy to miss: it makes a match skip *every
remaining rule in the custom ruleset*, not just the three named phases. Any replacement rule must
sit at index 0 **and** carry identical `action_parameters`, or the change is not neutral.

**F-14 — §8.6's SEO-category composition table is incomplete, and its promotion test fails today.**
Over the 7 days to 2026-07-29 the category also carried `GoogleAssociationService` (22 requests),
`Googlebot/2.1` (3), `Google-Adwords-Instant-Mobile` (2) and `SearchAtlas Bot` (1). §8.6's stated
test for promotion — *"confirm … Googlebot, bingbot, Better Stack and UptimeRobot never appear"* —
would **fail as written**. This must be explained or carved out before item 10 is even considered.
Volume is low (~28 in 7 days against 9,057 total) but the affected traffic is Google's.

**F-15 — Cloudflare's rule `PATCH` is not a partial update.** Sending `{"expression": "…"}` alone
fails with `20015`, *"the action is required to create or update a rule"*. The full rule body must
be sent with only the changed fields swapped. Both the amend step and the rollback script do this by
reading the live rule and replacing fields, so `action_parameters`/`logging` are preserved verbatim
rather than re-declared.

**F-17 — Cloudflare GraphQL windows floored to the hour hide the current partial hour.** The
observation script originally built its window with `date -u +%Y-%m-%dT%H:00:00Z`, following
`cloudflare-404-volume-check.sh`. That discards up to 59 minutes of the most recent data. Right
after the rule was created at 20:03:44Z the window ended at 20:00:00Z — *before the rule existed* —
so a working rule reported zero matches and looked broken. Fixed to minute precision. Worth knowing
for any short-window verification against this zone; it does not affect multi-day reviews
materially, but it does make immediate post-change checks lie.

**F-16 — the token does hold Zone WAF edit.** Probed non-destructively before any write, by POSTing
a deliberately invalid rule body: the response was `20115`/`20125` (validation), not an authorisation
error, and the ruleset stayed at version 14. This extends F-9 — the token holds DNS edit, Dynamic
Redirect edit, Cache Purge **and** WAF edit.

### Observation period

Start **2026-07-29T20:03:44Z**. Earliest review **2026-08-05T20:03:44Z** — at least 7 full days, to
cover a complete weekly crawl cycle, since Semrush/Ahrefs/MJ12 schedules are weekly-periodic.

```bash
bash scripts/observability/cloudflare-seo-bot-observation.sh \
  --days 7 --rule-id b79f504cabd347dc8eda9fd7c8748347 \
  --out docs/evidence/cf-seo-bot-observation/review-day7
```

Reports matched UA × path × status × ASN × country × count, a protected-traffic tripwire, and a
cross-category integrity check. Exit `2` = review required. It **will** exit 2 on the Google UAs in
F-14 — that is the signal, not a defect.

Baseline to compare against (7 days pre-change): **9,057** requests — 4,574 × 404, 2,569 × 200,
1,904 × 301. Category census: not-a-verified-bot 215,268; Search Engine Crawler 10,214;
**Search Engine Optimization 9,057**; Monitoring & Analytics 5,586; AI Assistant 5,004.

### Rollback

```bash
bash scripts/observability/cloudflare-seo-bot-rollback.sh            # dry run (default)
bash scripts/observability/cloudflare-seo-bot-rollback.sh --apply    # execute
bash scripts/observability/cloudflare-seo-bot-rollback.sh --verify   # live vs export
```

Restores `64fae5be`'s expression and description verbatim from `01-waf-custom-before.json`, **then**
deletes the observation rule — that order means SEO bots are never momentarily unprotected. Exercised
against the live zone both before the change (clean) and after (3 diffs detected). Version 14 is also
independently readable at `GET /zones/{zone}/rulesets/f887ac01…/versions/14`.

Roll back immediately if Better Stack or UptimeRobot ever appears in the match list.

---

## Items 8.7 / 8.8 — browser-spoofing analysis and scripted-access inventory

**Status: ✅ analysis complete, no enforcement proposed. Closed — not deferred, and not pending
further data. No production security control was modified.**

Full deliverable: [`browser-spoofing-and-scripted-access-analysis.md`](browser-spoofing-and-scripted-access-analysis.md).
Collector: `scripts/observability/cloudflare-browser-spoofing-analysis.sh`.
Evidence: `docs/evidence/cf-browser-spoofing/`.

§8.7 refused to propose a rule until a named list of signals had been measured over ≥7 days;
§8.8 asked for an inventory of legitimate scripted consumers. Both are now done, over
**14 days** (`2026-07-15T23:33Z` → `2026-07-29T23:33Z`, 491,004 requests). Every Cloudflare
call was a GraphQL analytics read.

### §8.7 — a real population, and no rule

The `Chrome/142.0.0.0` macOS lead was confirmed as automation: **41,000 requests (8.4 % of the
zone), 99.2 % in Cloudflare's `automated`/`likely_automated` buckets, 4 `likely_human`
requests**, eleven of its top twelve ASNs hosting or proxy-vendor networks, an HTML-to-asset
ratio of **≈2,560:1**, **zero** `/robots.txt` fetches, **zero** `/cdn-cgi/rum` beacons,
`Referer` on **0 %** of requests, facet-parameter stacking on `/search` (45 % of its volume),
and presence on **15 of 15 days** while growing. It is spread across **5,000+ distinct IPs at
a median of 2 requests each** — a rotating proxy pool, which also independently confirms
§8.7's warning that rate limiting on `ip.src` would be ineffective as well as risky.

**No rule was created, in any mode.** Three independent reasons:

1. **`ja4` and `ja3Hash` are denied to this zone** (Enterprise Bot Management), so §8.7's
   mandatory "inconsistent with a genuine browser JA4" gate cannot be evaluated at all. Also
   denied: `botScore`, `botScoreBucketBy10`, `jsDetectionPassed`, `botDetectionTags`.
2. **Log mode is Enterprise-only**, so the mandatory 14-day Log observation cannot be run —
   and item 9's **mirror-skip** workaround does not transfer, because it requires an existing
   skip to mirror and this population is challenged/blocked rather than skipped. Any rule
   would be enforcement from creation.
3. **It is already mitigated.** AI Labyrinth 20,510 + SBFM managed challenge 18,848 + block
   1,325 = **40,683 of 41,000 already actioned (99.2 %)**. Only **42 requests in 14 days**
   reach content unchallenged.

**The stale-version hypothesis was tested and does not hold.** `Chrome/120.0.0.0` — staler
than Chrome/142 — is only 76.3 % automated, 20 % `likely_human`, fetches `robots.txt`, fires
RUM beacons, and is dominated by **Microsoft/Azure ASN** traffic that mixes WordPress
vulnerability scanning with what is plausibly Microsoft 365 link scanning. It is recorded as
ambiguous and explicitly protected from enforcement.

### §8.8 — inventory complete, and the one open decision answered

- **`/sitemap.xml`: do not add the skip rule.** §8.8's condition was "only if a real consumer
  is identified". Breaking the 100 unverified 403s down by UA, they are the spoofing
  population itself (29), GPTBot and ReflectionBot blocked by deliberate AI policy (15),
  `Go-http-client/1.1` (11), and scanner traffic. **No partner, directory, or government
  aggregator is being blocked.** Verified bots get 200, so indexing is unaffected.
- **RSS feeds exist** (`config/views.view.taxonomy_term.yml`, across `en`/`es`/`nl`/`sw`) and
  serve 200 to verified crawlers — closing §8.8's "unknown whether any exist". But **no feed
  reader consumer was found**, and ~150 scripted requests to feed paths are already 403'd.
  No skip justified.
- **Better Stack (6,718) and UptimeRobot (3,960) confirmed working**; both still classified
  `Monitoring & Analytics`. Search engines still `Search Engine Crawler` (55 UA rows).
- Public PDFs: 8,310 × 200. ⚠️ SBFM `023ec3b3` does hit PDFs — recorded as the control most
  likely to affect legitimate document access.
- Posture table added to `docs/observability.md` per §8.8, so this is not re-litigated.

### Verification

| Check | Result |
|---|---|
| `bash -n` + `shellcheck` on the collector | Clean |
| Collector `--days 14` | exit 0, `status=clean`, no GraphQL errors |
| Protected-traffic tripwire | Clean — no monitor, search engine, preview, accessibility, security-scanner, RSS or translation UA inside any candidate |
| Cross-category integrity | Intact |
| Custom ruleset `f887ac01…` | **v17, 9 rules, `last_updated` unchanged** — byte-identical `ref`/`action`/`enabled` diff against `10-waf-custom-after.json` |
| `/robots.txt`, `/.well-known/security.txt` from a plain client | **200** — skip `92082bed` working |
| `/sitemap.xml`, `/taxonomy/term/3/feed` from a plain client | **403** — the documented finding |
| `/user/login`, `/wp-login.php` from a plain client | **403** — hardening intact |

### Rollback

Nothing to roll back — no rule exists in any mode. Reverting the document, the collector, and
the evidence directory is an ordinary revert.

### Re-open only if

The zone gains Enterprise Bot Management (JA4 + Log become available); **or** the unchallenged
`200` share of the population rises above **2 %** from its measured **0.1 %**; **or** origin
load or cache ratio regresses and is traced to this population.

---

## Item 14 — the canonical 404 encoding loop (opened 2026-07-30)

**Status: ✅ fixed locally, verified, not yet deployed.** Opened while investigating two rows the
follow-up plan filed as "P2 — needs investigation". Evidence:
`docs/evidence/canonical-404-encoding-loop/`.

**What was wrong.** A 404 whose path contained any `%` advertised, in `<link rel="canonical">` and
`<meta property="og:url">`, a URL **one percent-encoding level deeper than the one requested**.
Reproduced live on dev, test and Live:

```
req /node/1628/solicitaciones-telef%C3%B3nicas      → canonical …telef%25C3%25B3nicas
req /node/1628/solicitaciones-telef%25C3%25B3nicas  → canonical …telef%2525C3%2525B3nicas
req /node/1628/solicitaciones-telef%2525C3%2525B3nicas → canonical …telef%252525C3%252525B3nicas
```

**Root cause.** `drupal/token` 8.x-1.17, `src/Hook/TokenTokensHooks.php:481`. On an unrouted path
`Url::createFromRequest()` throws and `[current-page:url]` falls back to
`Url::fromUserInput($request->getPathInfo())`. `getPathInfo()` is still percent-encoded;
`fromUserInput()` expects a decoded path and encodes what it is given, so every `%` becomes `%25`.
`config/metatag.metatag_defaults.global.yml:10,13` resolves both tags from that token. Upstream
[#3075857](https://www.drupal.org/project/token/issues/3075857) touched this code and was closed as
outdated in January 2026 without addressing the encoding, so there is no upstream fix to wait for.

**Cost, July 2026, zone-wide.** 28,455 requests on multiply-encoded paths, of which **21,904 reached
the origin as a 404** — roughly **12 % of all origin traffic** against the audit's ~5,800/day.
6,506 more were blocked at the edge; 382 MB egress; **six origin 500s and one 504** from the
resulting URL lengths. **21,831 of the 21,904 were SemrushBot.** For scale, the icon fix — the
programme's headline 404 win — removed ~1,368 404s per two days, almost all at the edge.

**Why we are confident it is our output, not the crawler.** Three independent lines:

1. Reproduced live, deterministically, three passes.
2. **Query strings.** The depth-0 seeds are Drupal 7 Rate-module links carrying `?rate=<token>`. Of
   the 852 escalating requests in the retained nginx log, **0 carry any query string** — and the
   canonical tag is the only step in the chain that drops the query (item 6, F-10). An internal
   re-escape inside the crawler would have preserved it.
3. **Depth grows per crawl cycle.** Minimum observed depth climbs 4 → 7 → 6 → 19 → 26 → 33 → 34
   across SemrushBot's ~4-day cycles; maximum reached **1,879**.

**Scope.** 4xx responses whose path contains `%`. Unprefixed, `/sw/` and `/nl/` were all affected;
`/es/` was not. **200 responses were never affected** — the four live Spanish aliases containing
`%20` always emitted correct canonicals, and that is now a regression test.

**Not new, and previously papered over.** The nginx evidence is from **2025-12-12**.
`config/redirect_404.settings.yml` already carried the glob `/*%252*`, which suppressed these from
the 404 log. The symptom had been noticed and hidden at the logging layer; the cause was never found.

**Item 1 did not cause it but raised the price.** Since 2026-07-28 these 404s carry
`max-age=86400, public`, so every URL in an unbounded set also took a CDN cache entry for 24 h.

### The fix — two independent layers

| Change | Why |
|---|---|
| `patches/token-current-page-url-404-double-encode.patch` | Decodes the path before `Url::fromUserInput()`, making the round trip idempotent at any depth. Refuses rather than emit a URL the decode would restructure (an encoded `?` or `#` would become a real delimiter). |
| `ilas_seo` drops `canonical` + `og:url` on any error page | `\Drupal\ilas_seo\ErrorPage` + `CanonicalHost::removeSelfReferencingTags()`. A 404 must not name a canonical address that does not resolve, and this holds even if the patch is lost to Pantheon's build cache — the failure mode `scripts/composer/ensure-patches.php` exists for. Deliberately **not** gated on `ilas_canonical_base_url`, or the documented kill switch would reopen the loop. |
| `GraphBuilder` emits no JSON-LD on an error page | It was publishing `$request->getRequestUri()` as the `BreadcrumbList` `item` and `@id` — republishing an arbitrary caller-supplied string as structured data. This is in the item 6 code, so it never reached Live. |
| `cloudflare-404-volume-check.sh` excludes `/cdn-cgi/*` | See below. Also now reports the multiply-encoded count directly, so the fix is measurable. |

**Detection is not spoofable.** `ErrorPage` reads the `exception` request attribute set by
`HttpExceptionSubscriberBase::onException()`, not the `_exception_statuscode` query parameter core
also adds — that one is reachable from any URL, and trusting it would let anyone strip the canonical
off a real page. There is a unit test for exactly that.

### Verification

94 tests pass in `ilas_seo`; phpcs and phpstan clean. Removing the fix makes the new kernel test fail
3 of 4 and the new functional test 4 of 5, while the 200-page control passes either way. On DDEV the
loop is closed at every depth and all sampled 200 pages keep their canonicals.

**Still to do:** deploy Dev → Test → Live, re-run the three-step reproduction on each, then re-run
`cloudflare-404-volume-check.sh` and the per-cycle depth query. Volume decays as SemrushBot's stored
URLs age out rather than dropping instantly.

### `/cdn-cgi/content` — closed, no action

The other flagged row. 448 of 478 404s were a single `AliyunSecBot` client from Alibaba HK, and
every row carries `originResponseStatus: 0`: `/cdn-cgi/*` is reserved by Cloudflare, answered at the
edge, and never reaches Pantheon. It also explains why these still arrive on `www` — Cloudflare
handles the prefix before the dynamic-redirect phase. No site change can affect it; the only defect
was in the measurement, now corrected.

### Sequencing note for items 9 and 10

SemrushBot sits in the `Search Engine Optimization` verified-bot category, which item 9's mirror-skip
currently skips past the managed WAF, rate limiter and SBFM — that is what let it spend 21,904 origin
PHP executions here in a month. **Re-measure after this deploys, before deciding item 10.** Most of
the volume that would have justified enforcement was traffic we were generating ourselves.

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
| FU-10 | Set or retire `vars.ILAS_PLAYWRIGHT_BASE_URL`. It is unset, and `assistant-playwright.yml` is its only consumer, so that workflow's daily 08:23 UTC cron has been warning and skipping every day rather than testing anything | Item 5 | ⬜ Not started |
| FU-11 | Close the env-mismatch guard gap in `promptfoo-evals/lib/gate-target.js:11`. The regex recognises only `{env}-{slug}.pantheonsite.io`, so an apex or Cloudflare-fronted target yields `pantheonEnv=''` → `not_applicable` and `resolve-assistant-target.sh:107` never fires. Supersedes and generalises FU-5 | Item 5 | ⬜ Not started |
| FU-12 | Make the base-URL guards consistently fail-closed. `a11y-gate` and `assistant-nightly-quality` warn-and-skip when the base URL is missing while three other jobs hard-fail, so removing a secret silently disables the first group | Item 5 | ⬜ Not started |
| FU-13 | Fix the three hostname spellings in docs and comments — `idaholegalaid` (`quality-gate.yml:24`, `run-quality-gate.sh:19,406`) and `idaho-legal-aid` (`fingerprint-smoke.sh:14`) should both be `idaho-legal-aid-services`. Doc-only, but they 404 if copy-pasted | Item 5 | ⬜ Not started |
| FU-14 | Extend `MetatagCanonicalConfigTest`'s globs to cover `config/views.view.*.yml`. `views.view.forms_categories.yml:486` and `views.view.guides_categories.yml:486` carry embedded `metatag_views` canonical values that no test currently guards | Item 6 | ⬜ Not started |
| FU-15 | Purge the nine icon/manifest URLs from the Cloudflare cache so the item 2 fix takes effect immediately instead of waiting out the 24 h TTL | Items 2 & 4, F-5 | ✅ **Done 2026-07-29 16:32Z** — token was granted Cache Purge; purge succeeded and the paths verified serving 200 at the edge |
| FU-16 | Purge the affected URLs when item 7 adds redirects for legacy `/sites/idaholegalaid.org/files/*.pdf` paths. Those URLs 404 today, are edge-cached for 24 h because they carry a static extension, and Cloudflare has no cache-tag visibility — so adding the redirect alone will not dislodge the cached 404. Use `scripts/observability/cloudflare-purge-urls.sh` | Items 2 & 4, F-5 | ✅ **Done 2026-07-29** — purged 18 URLs (9 paths × apex + www), then 2 more after the manufactured-homes row was withdrawn. **The Cloudflare purge alone was not sufficient**: Pantheon's own Fastly layer was still serving the cached 404 (`x-served-by: cache-bfi…`, `x-cache: HIT`, `age: 271`) on the platform hostname, which sits *upstream* of Cloudflare. `terminus env:clear-cache` was required as well. FU-17 raised to record this. |
| FU-17 | Document the two-layer purge in `scripts/observability/cloudflare-purge-urls.sh`. Its header explains the Cloudflare 24 h edge-cache problem but not that Pantheon's Global CDN caches the same response upstream, so a Cloudflare-only purge leaves a stale 404 in place. Every future "made a 404 path resolve" change needs `terminus env:clear-cache` **first**, then the Cloudflare purge | Item 7, FU-16 | ⬜ Not started |
| FU-18 | Confirm with the site owner that ILAS does not rely on **MJ12bot / Majestic**. §8.6 records confirmation for Semrush, Ahrefs and Siteimprove only, but MJ12bot is the second-largest UA in the SEO category (~490 requests / 2 days). Needed before item 10 | Item 9 | ⬜ Not started |
| FU-19 | Resolve F-14 before promoting item 10: decide whether `Googlebot/2.1`, `GoogleAssociationService` and `Google-Adwords-Instant-Mobile` inside the SEO category are a Cloudflare classification artefact or genuine, and if genuine add an explicit carve-out to the rule expression. Promotion is blocked until this is answered | Item 9, F-14 | ⬜ Not started |
| FU-20 | Decide how to answer §8.6's still-open "would the managed WAF block a share of this traffic?" question. The mirror-skip deliberately preserves the managed-WAF skip, so the observation period cannot answer it; only a genuinely enforcing change can | Item 9, F-13 | ⬜ Not started |
| FU-21 | Ask **program staff** whether any partner, legal-services directory, or government referral aggregator consumes ILAS sitemaps, RSS feeds, or PDFs programmatically. §8.8 flagged this as undiscoverable from inside the codebase, and the 14-day edge data shows nothing legitimate currently blocked — but a quarterly or annual consumer would fall outside the window. Not a blocker; no skip rule is justified until a real consumer is named | Items 8.7/8.8, §8.8 | ⬜ Not started |
| FU-22 | Decide whether the `Archiver` (`archive.org_bot`) and `Aggregator` (`Pinterestbot`) verified-bot categories should be added to skip rule `64fae5be`. Both currently reach content (116 and 126 × 200 respectively, none blocked), but neither is in the retained category list, so nothing guarantees that continues. Low priority — recorded so a future bot-management change does not break them silently | Items 8.7/8.8 | ⬜ Not started |
| FU-23 | Review the **~13,780 empty-user-agent requests** in the 14-day window. Deliberately out of scope for §8.7, which is about *spoofed browser* UAs — an absent UA is a different question and was not analysed | Items 8.7/8.8 | ⬜ Not started |

---

## Open questions still owned elsewhere

§15 of the validation document lists eight items requiring Pantheon
workspace-administrator access, plus one technical question for Pantheon Support
(whether Global CDN configuration or `cache_hit_ratio` calculation changed around
2026-07-06). None of them blocks any item in this tracker.
