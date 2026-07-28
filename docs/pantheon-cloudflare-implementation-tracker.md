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
| 2 | Add icon files + template tags | §8.1 | ⬜ Not started | Apply the three §8.1 corrections: rasterise from `ILAS Favicon_1.svg` (the theme's `favicon.ico` is Bootstrap's logo), no second `rel="icon"`, correct MIME. |
| 3 | `www`→apex Single Redirect | §8.2 | ⬜ Not started | Must explicitly enable `preserve_query_string` — it defaults to disabled. |
| 4 | Add `json\|xml\|webmanifest` to `fast_404` | §11.4 | ⬜ Not started | Pairs with item 2. |
| 5 | Audit GitHub repo vars for the live platform hostname | §8.5 | ⬜ Not started | Prerequisite before §8.5 could ever be reconsidered. Cannot be checked from the repo. |
| 6 | Fix the dynamic canonical | §8.5 | ⬜ Not started | `config/metatag.metatag_defaults.global.yml` uses `[current-page:url:absolute]`. |
| 7 | Legacy files — 10 high-confidence rows | §8.4 | ⬜ Not started | Fix source links *and* add redirects. |
| 8 | Legacy files — 10 editorial rows | §8.4 | ⏸️ Blocked | Gated on content-owner / legal review. |
| 9 | SEO-crawler rule in **Log** mode | §8.6 | ⬜ Not started | Replaces the rejected §13.2 change. |
| 10 | Review logs → Managed Challenge | §8.6 | ⬜ Not started | Only after ≥7 days of Log. Never Block first. |
| 11 | Verify `old.` IP not third-party; delete record | §8.3 | ⬜ Not started | Confirm `72.3.167.82` is not claimable before deleting. |
| 12 | Re-measure for one full billing cycle | §13 | ⬜ Not started | Depends on item 1 landing. |
| 13 | Re-evaluate `pantheon_advanced_page_cache` | §8.10 | 🔵 Deferred | Only meaningful once pages are cacheable. |
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

## Follow-ups opened by this work

| ID | Item | Origin | Status |
|---|---|---|---|
| FU-1 | Make the assistant's `apiBase` language-aware, or pass the page langcode in the request, so `/es/` visitors get Spanish resource links regardless of browser `Accept-Language` | F-1 | ⬜ Not started |
| FU-2 | Tell program staff that browser-based auto-switching to Spanish is gone; the `/es` prefix and the language switcher are unchanged | §8.9B accepted trade-off | ⬜ Not started |
| FU-3 | Fix the lost-update race behind `LlmControlConcurrencyTest::testCacheStatsDoNotLoseConcurrentIncrements` — it fails ~1 run in 5 and randomly blocks deploys | F-4 | ⬜ Not started |
| FU-4 | Refresh the Test database from Live (it has 0 Spanish nodes), so Test can validate multilingual behaviour | F-5 | ⬜ Not started |

---

## Open questions still owned elsewhere

§15 of the validation document lists eight items requiring Pantheon
workspace-administrator access, plus one technical question for Pantheon Support
(whether Global CDN configuration or `cache_hit_ratio` calculation changed around
2026-07-06). None of them blocks any item in this tracker.
