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
| **1** | **Fix `language-browser` negotiation** | **§8.9B** | 🟢 **In progress** | Branch `fix/browser-language-cacheability`. See the dedicated section below. |
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

**Evidence.** `docs/evidence/browser-language-cacheability/`.

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

---

## Follow-ups opened by this work

| ID | Item | Origin | Status |
|---|---|---|---|
| FU-1 | Make the assistant's `apiBase` language-aware, or pass the page langcode in the request, so `/es/` visitors get Spanish resource links regardless of browser `Accept-Language` | F-1 | ⬜ Not started |
| FU-2 | Tell program staff that browser-based auto-switching to Spanish is gone; the `/es` prefix and the language switcher are unchanged | §8.9B accepted trade-off | ⬜ Not started |

---

## Open questions still owned elsewhere

§15 of the validation document lists eight items requiring Pantheon
workspace-administrator access, plus one technical question for Pantheon Support
(whether Global CDN configuration or `cache_hit_ratio` calculation changed around
2026-07-06). None of them blocks any item in this tracker.
