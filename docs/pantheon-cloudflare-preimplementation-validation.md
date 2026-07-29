# Pantheon + Cloudflare — Pre-Implementation Validation Review

**Site:** `idaho-legal-aid-services` · Live · Performance Small
**Companion to:** `docs/pantheon-cloudflare-traffic-audit.md` (2026-07-28)
**Review date:** 2026-07-28
**Author:** Claude Code — read-only validation. **No production changes were made.** All
Cloudflare API use was `GET`; all `terminus` use was reads and backup downloads; all origin
contact was `GET`/`HEAD`.

> **Scope.** This document validates the 12 changes proposed in the audit. It does not repeat
> the traffic audit. Where evidence contradicts the audit, the correction is marked
> **⚠️ CORRECTION TO AUDIT §x** — the audit remains on disk and readers may consult either.

---

## 1. Executive summary

Of the 12 proposed changes, **2 are safe to implement now**, 3 are safe after minor
verification, 3 require staged testing, 3 should be deferred, and **1 must be rejected as
specified because it will not work.**

Three findings materially change the audit's conclusions:

**1. A sitewide cacheability defect was found that the audit missed.** Nearly every anonymous
interior page on the site is completely uncacheable — not just 404s and redirects, but ordinary
200-OK content pages. The audit tested only the homepage, which is one of the few cacheable
paths. The mechanism is proven: Drupal's **browser language negotiation** trips the page-cache
kill switch on every URL whose first path segment is not a language prefix. This is the single
largest technical finding in either document, and fixing it is a one-line config change.

**2. The proposed Cloudflare bot change cannot work as designed.** The zone's
`sbfm_verified_bots` setting is `allow`. Removing `"Search Engine Optimization"` from the skip
rule therefore does **not** cause Super Bot Fight Mode to challenge those crawlers — it allows
them. The audit's stated mechanism ("no new rule is needed at all") is incorrect. A separate
explicit rule is required. The audit's bot-category table is also wrong in three places,
verified against the zone's own data.

**3. The July 6 cache-ratio drop is not attributable to the deployment.** Using a recovered
pre-deploy database backup, the deployed build artifacts, and Cloudflare analytics that reach
back to July 1, every candidate mechanism was tested and eliminated. Traffic was flat, config
was identical, and the relevant core code is byte-identical between the two deployed artifacts.
**Conclusion: Unsupported.** No code should be rolled back.

The audit's central *financial* conclusion is unchanged and independently confirmed: Pantheon
meters visits at its Global CDN, so none of this engineering work meaningfully reduces counted
visits. Everything here is justified on performance, reliability, correctness and user
experience — not on the bill.

---

## 2. What is already established and will not be reinvestigated

Carried forward from the audit as accepted:

| Established fact | Source |
|---|---|
| Cloudflare fronts **99.83 %** of origin traffic (66,575 / 66,691) | Audit §7.3, XFF-chain analysis |
| Cloudflare blocks ~38.5 % of edge requests before Pantheon | Audit §7.0 |
| Pantheon visits = 200-level (+303/304/305), deduped per IP+UA per day; **excludes** known bots, static assets, redirects, 4xx | `docs.pantheon.io/metrics` |
| 404s, 301s, static assets and known bots contribute **0 %** of counted visits | Audit §2, §10 |
| ~80 % of counted visits are legitimate humans; not reducible | Audit §10 |
| No custom Cloudflare cache ruleset exists; no HTML is cached at the edge | Audit §7.0 |
| Scanner probes are already absorbed by the WAF (48 origin requests / 11.5 days) | Audit §6.9 |
| The site returns real 404 status codes, not soft-200s | Audit §6.9 |
| Assistant/employment/donation endpoints are immaterial to traffic (417 req / 11.5 days) | Audit §6.8 |

**Newly confirmed** (was an open question in the audit): Pantheon states the metric *"comes
directly from our Global CDN, which tracks all requests for resources on Pantheon."* This
verifies the audit's §7.1 architectural inference — **the meter sits in front of the origin**,
so origin-load work cannot move the billed number.

---

## 3. Changes safe to implement now

| # | Change | Why safe |
|---|---|---|
| **1** | Add `/favicon.ico`, apple-touch icons, web manifest | Paths currently 404. No route claims them. Not gitignored, not scaffold-managed, not under `protected_web_paths`. Purely additive. **Corrections to the audit's file list apply — see §8.1.** |
| **2** | Move `www`→apex redirect to a Cloudflare Single Redirect | Target is byte-identical to what the origin already returns. Origin redirect remains underneath as a fallback, so there is no window in which `www` breaks. |

---

## 4. Changes safe after minor verification

| # | Change | Verification required first |
|---|---|---|
| **3** | Delete `old.idaholegalaid.org` A record | Confirm with an external DNS-history/takeover check that `72.3.167.82` is not a live third-party host. Zero repo/CI/config dependencies already confirmed. |
| **5a** | Fix the dynamic canonical URL *(split out of the platform-hostname item)* | Confirm no metatag override depends on `[current-page:url:absolute]` elsewhere. This is the real defect behind the "canonical leak"; the redirect is not. |
| **8** | Document and narrowly permit legitimate scripted access | Enumerate actual non-browser consumers (§9) and confirm none is currently broken before adding or withholding skip rules. |

---

## 5. Changes requiring staged testing

| # | Change | Staging requirement |
|---|---|---|
| **9-fix** | Remove `language-browser` negotiation (fixes sitewide uncacheability) | Dev → Test → Live, verifying `x-drupal-cache` flips and Spanish still resolves at each step. User-facing language behaviour change. |
| **4** | Legacy `/sites/idaholegalaid.org/files/` links | Per-file decisions; 7 of the top 22 have no replacement and need content-owner review. Fix source links *and* add redirects. |
| **6-alt** | Explicit SEO-crawler challenge rule (replaces the rejected change) | Log action ≥7 days, review, then Managed Challenge. Never Block first. |
| **7** | Browser-spoofing traffic | Analysis is **not yet complete**. Log-only first; no rule may be written on current evidence. |

---

## 6. Changes that should be deferred

| # | Change | Why deferred |
|---|---|---|
| **5** | Redirect the Live Pantheon platform hostname | Pantheon's built-in setting **cannot** do it (§8.5); the PHP alternative would break CI, which POSTs to that hostname; and Pantheon already serves `Disallow: /` there, so the SEO risk is already mitigated. Impact is 116 requests / 11.5 days (0.17 %). |
| **10** | Install `pantheon_advanced_page_cache` | It auto-disables Big Pipe (enabled here), carries an unfixed Webform file-upload regression, and would purge a cache that is currently ~80 % unusable anyway. Revisit **after** the §8.9 fix. |
| **11** | Cache 404 responses at Cloudflare | Fixing the icons removes ~60 % of all 404s. Re-evaluate what remains afterwards; caching an error class is a poor substitute for not generating it. |
| **12** | Cloudflare HTML caching ("Cache Everything") | Deferred by instruction, and now clearly unnecessary: fixing §8.9 restores Pantheon's *own* CDN, which addresses origin load without adding a second cache layer to invalidate. |

---

## 7. Changes that should be rejected

| # | Change | Why rejected |
|---|---|---|
| **6** | *"Remove `Search Engine Optimization` from skip rule `64fae5be`; SBFM will then challenge those crawlers."* | **The stated mechanism does not work.** `sbfm_verified_bots: "allow"` means verified bots are allowed by SBFM, not challenged. The change as written would produce close to no effect, while silently stopping Siteimprove (which the audit miscategorised). Rejected as specified; replaced by **6-alt** in §8.6. |

---

## 8. Detailed validation

Each item below covers: problem solved · metric affected · evidence · missing evidence ·
impact on third parties · implementation · testing · monitoring · rollback · classification.

---

### 8.1 — Missing favicon and Apple touch icons

**Problem solved.** Browsers and iOS request conventional icon paths that do not exist. Each
404 bootstraps Drupal and is structurally uncacheable, so it recurs indefinitely. It is also a
visible defect: no icon in browser tabs or iOS home-screen bookmarks.

**Metrics affected.** Origin requests ✅ · PHP work ✅ · UX ✅ · Counted visits ❌ (404s are
excluded by definition) · Security ➖ · SEO ➖ (minor: favicons appear in Google results).

**Evidence.**

Cloudflare edge, 2026-07-26 → 07-28 (2 days):

| Path | 404s |
|---|---:|
| `/favicon.ico` | 742 |
| `/apple-touch-icon.png` | 251 |
| `/apple-touch-icon-precomposed.png` | 228 |
| `/apple-touch-icon-120x120*.png` | 27 |
| `/apple-touch-icon-152x152*.png` | 11 |
| `/sites/all/themes/custom/dlaw4_bootswatch/apple-touch-icon.png` | 3 |
| **Total** | **1,262** (~630/day) |

Consistent with the audit's log-derived 6,848 / 11.5 days. These are the **top three 404 paths
on the entire zone**.

Root cause confirmed: `config/b5subtheme.settings.yml:19-22` sets
`favicon.path: 'public://ILAS Favicon_1.svg'` with `use_default: 0`. Drupal emits exactly one
`<link rel="icon">` from `BareHtmlPageRenderer.php:115-123`; the theme's own `favicon.ico` is
never consulted (`ThemeSettingsProvider.php:117-133`). `html.html.twig` (head at lines 40–59)
contains **no icon markup at all**.

**⚠️ CORRECTION TO AUDIT §13.1 — three defects in the proposed change:**

1. **Wrong logo.** The audit proposes copying
   `web/themes/custom/b5subtheme/favicon.ico`. That file is **byte-identical to Bootstrap's
   own docs favicon** — md5 `0ea09ca885a485dd6cc4ddb650a97f80`, matching
   `web/themes/contrib/bootstrap5/favicon.ico`. Shipping it would put the **Bootstrap "B" logo**
   on every ILAS browser tab. Icons must be rasterised from
   `web/sites/default/files/ILAS Favicon_1.svg` instead.
2. **Duplicate icon tag.** The proposed `<link rel="icon" …>` would be a *second* `rel="icon"`,
   because Drupal already injects one. Omit it — `/favicon.ico` is resolved by browser
   convention and needs no tag.
3. **Wrong MIME.** The proposed `type="image/png"` on a `.ico` href is incorrect.

**Resolved caveat.** The audit flagged `protected_web_paths` as an unverified blocker.
`pantheon.upstream.yml:10-13` lists only `/private/`, `/sites/default/files/private/`,
`/sites/default/files/config/`. **Docroot-root static files are not blocked.** `web_docroot: true`
confirms `web/favicon.ico` serves at `/favicon.ico`. `git check-ignore` confirms none of the
new paths is ignored, and Composer scaffold does not manage them.

**Missing evidence.** The source SVG is **untracked** (`.gitignore:13` `/web/sites/*/files`), so
icons are not reproducible from git today. ImageMagick is not installed on the workstation, so
rasterisation needs a tool decision.

**Third-party impact.** None. Every affected path currently returns 404; nothing can regress.

**Implementation.**

Add (binary, all rasterised from `ILAS Favicon_1.svg` — 375×375 viewBox, opaque white ground,
so a square crop is clean):

| File | Spec |
|---|---|
| `web/favicon.ico` | multi-resolution ICO: 16×16, 32×32, 48×48 |
| `web/apple-touch-icon.png` | 180×180 PNG, no alpha |
| `web/apple-touch-icon-precomposed.png` | identical bytes |
| `web/site.webmanifest` | name, short_name, 192×192 + 512×512 icons, `theme_color` |
| `web/icon-192.png`, `web/icon-512.png` | manifest icon targets |
| `web/themes/custom/b5subtheme/images/ILAS-favicon-source.svg` | committed source, for reproducibility |

Also add `web/manifest.json` as a copy of `site.webmanifest` — 40 requests were recorded for
that exact path, and `.json` is **not** in the `fast_404` regex
(`config/system.performance.yml:11`), so those requests currently render the full `/node/119`
page rather than a cheap 404.

Template — `web/themes/custom/b5subtheme/templates/page/html.html.twig`, after line 41
(`<head-placeholder>`):

```twig
    <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png">
    <link rel="manifest" href="/site.webmanifest">
```

**Testing.**
```bash
for p in /favicon.ico /apple-touch-icon.png /apple-touch-icon-precomposed.png \
         /site.webmanifest /manifest.json; do
  curl -sI "https://idaholegalaid.org$p" | head -1
done
# expect: HTTP/2 200 on all five
curl -s https://idaholegalaid.org/ | grep -c 'rel="icon"'   # expect exactly 1
```
Visual: load the site in Chrome, Firefox and Safari; confirm the ILAS mark (not a Bootstrap
"B") in the tab; add to iOS home screen and confirm the icon.

**Monitoring.** `/favicon.ico` and `/apple-touch-icon*` 404 counts at the Cloudflare edge should
fall to ~0 within 24 h. Total edge 404s should drop by roughly 60 %.

**Rollback.** `git revert` the commit; the paths return to 404. No state, no cache, no config.

**Classification: ✅ Safe to implement now** (with the three corrections applied).

---

### 8.2 — Move `www` → apex redirect to Cloudflare

**Problem solved.** `www` is **18.3 %** of all Cloudflare traffic (19,013 / 103,702 over 3
days). The 301 is currently issued by the **Pantheon origin**, so every one of those requests
makes a full round trip to Pantheon to be told to go elsewhere.

**Metrics affected.** Pantheon edge requests ✅ (largest single item available) · latency ✅ ·
Counted visits ❌ (301s excluded) · Security ➖ · SEO ➖ (neutral; target unchanged).

**Evidence.** Audit §5.4 verified by explicit `Host` header: `Host: www.idaholegalaid.org` →
301 to `https://idaholegalaid.org/`; `Host: idaholegalaid.org` → 200. Audit §7.2 gives the
hostname split. Canonical consistency confirmed: `config/simple_sitemap.settings.yml:9`
`base_url: 'https://idaholegalaid.org'`, `config/robotstxt.settings.yml:132` sitemap URL, and
all ~40 metatag canonical/OG entries use the apex. No `www`-only vanity path found.

**Missing evidence.** Whether Google Search Console has both properties registered, and whether
any printed material or partner directory links to `www` in a way that must keep working —
it will, since the redirect preserves path and query.

**Third-party impact.** None expected. Redirect semantics are unchanged; only the responder
moves from Pantheon to Cloudflare. HSTS is unaffected: `pantheon.upstream.yml:4`
`enforce_https: transitional`, and Cloudflare terminates TLS for both hostnames already, both
proxied with A and AAAA records.

**Mechanism choice — Single Redirect, not Bulk Redirect.** Bulk Redirects *"don't support string
replacement or regex operations"* and would require enumerating every URL. Single Redirects on
the **Business** plan allow 50 rules with wildcard and regex support. One rule suffices.

**⚠️ Note not in the audit:** `preserve_query_string` **defaults to disabled**. It must be
explicitly enabled or every redirect will silently drop query strings — breaking UTM
attribution, search links and language parameters.

**Implementation.** See §10.1 for the exact expression.

**Testing (staged).**
```bash
# path + query preservation, HTTP and HTTPS, apex unaffected
curl -sI  "https://www.idaholegalaid.org/legal-help/housing?utm_source=x&a=1" | grep -iE 'HTTP/|location|server|cf-ray'
curl -sI  "http://www.idaholegalaid.org/legal-help/housing?utm_source=x"      | grep -iE 'HTTP/|location'
curl -sI  "https://www.idaholegalaid.org/"                                    | grep -iE 'HTTP/|location'
curl -sI  "https://idaholegalaid.org/legal-help/housing"                      | grep -iE 'HTTP/|location'   # must NOT redirect
curl -sIL "https://www.idaholegalaid.org/es/videoteca"                        | grep -iE 'HTTP/|location'   # single hop, no loop
```
Success = `server: cloudflare` **with** a `cf-ray` on the 301 (today it shows `server: nginx`),
exactly one redirect hop, query string intact, apex unchanged.

**Loop safety.** The rule matches `http.host eq "www.idaholegalaid.org"` and targets the apex,
which cannot re-match. Verify with `curl -sIL` that the chain is exactly one hop.

**Monitoring.** Cloudflare origin-bound request volume should fall by ~6,300/day. Watch the
`www` hostname's edge 301 count rise and Pantheon's 301 count fall correspondingly.

**Rollback.** Disable the Single Redirect rule (<30 s). The Pantheon origin redirect still
exists underneath, so `www` continues to work throughout — there is no broken window.

**Classification: ✅ Safe to implement now.**

---

### 8.3 — Dead `old.idaholegalaid.org` DNS record

**Problem solved.** Housekeeping, plus removal of a dangling-DNS surface.

**Metrics affected.** Security ✅ (marginal) · everything else ➖. **No traffic impact.**

**Evidence — searched exhaustively, all negative:**

| Surface | Result |
|---|---|
| Working tree (`old.idaholegalaid`, `72.3.167.82`) | Only in the untracked audit doc |
| Full git history, all refs (`git log -S` both strings) | **Zero commits** |
| `git log --all --grep='old\.'` | Zero |
| Drupal config (`config/**`) — trusted hosts, CORS, CSP, seckit, sitemap base_url, metatag | Zero |
| CI/CD (`.github/`, `scripts/`), `.ddev/`, `.env.example` | Zero |
| Custom modules, themes, docs, monitoring scripts | Zero |
| Subdomain enumeration across whole tree | Only `www.`, `mail.`, `old.` (docs only), `your-multidev.` (placeholder) |

Cloudflare has no HTTP analytics for it because the record is **DNS-only (unproxied)** — absence
of Cloudflare data is expected and is not evidence of absence of traffic.

**Missing evidence.** ⚠️ Two gaps that justify the "minor verification" gate:
1. **Whether `72.3.167.82` is currently allocated to a third party.** The audit found no HTTP
   response and no reverse DNS. If that IP sits in a cloud range someone else can claim, the
   record is a **subdomain-takeover risk** and deletion becomes more urgent, not less.
2. **Email/legacy references outside the repo** — old letterhead, partner directories, or
   Google indexing of `old.` URLs. Nothing can be found from inside the codebase.

**Third-party impact.** None identified. Nothing resolves it, nothing links to it in any
surface searchable from here.

**Implementation.** Cloudflare Dashboard → DNS → delete the `old` A record (`72.3.167.82`).
Record the prior value first.

**Testing.** `dig +short old.idaholegalaid.org` → empty. Confirm apex, `www` and `mail` are
untouched: `dig +short idaholegalaid.org www.idaholegalaid.org mail.idaholegalaid.org`.

**Monitoring.** None required. Optionally re-check after 48 h that no support ticket references
a broken `old.` link.

**Rollback.** Re-add an A record `old` → `72.3.167.82`, DNS-only, TTL auto. Propagation < 5 min.

**Classification: 🟡 Safe after minor verification** (confirm the IP is not third-party-claimable).

---

### 8.4 — Broken legacy document and file links

**Problem solved.** Real users clicking links **on our own pages** get a 404 instead of a legal
self-help document. This is a user-harm defect, not a bot artefact.

**Metrics affected.** UX ✅ (primary) · origin requests ✅ · SEO ✅ (link equity, crawl budget) ·
Counted visits ❌.

**Evidence.** Cloudflare, 7 days (2026-07-21 → 07-28): **172 distinct legacy paths, 1,135
requests**, all 404. Referrer data settles the ownership question — the top items carry
`referer: www.idaholegalaid.org`, i.e. **we link to them ourselves**. One external
**government** backlink found: `courtselfhelp.idaho.gov`.

**Key finding — the fix is far smaller than the audit implies.** The redirect table already
covers the *modern* path prefix but not the *legacy* one:

| Redirect source prefix | Count on live |
|---|---:|
| `sites/default/files/%` | **630** |
| `sites/idaholegalaid.org/files/%` | **70** |
| all redirects | 5,134 |

So for most files the correct destination **already exists and is already known to Drupal** —
the D7-era directory prefix simply was never mapped. This makes most of the work mechanical
rather than editorial.

**⚠️ Per the brief, the legacy prefix is NOT blanket-rewritten to `/sites/default/files/`.**
Each row below was resolved by probing the modern prefix on the origin and following the actual
redirect chain to a `200`.

#### Remediation map (top 22 by volume, 7-day)

| Broken URL (`/sites/idaholegalaid.org/files/…`) | Vol | Linking page | Current replacement | Recommended action | Confidence | Owner review? |
|---|---:|---|---|---|---|---|
| `Protections for Debit Card…Fact Sheet.pdf` | 349 | our site | `…/2025-12/debit-card-electronic-transactions-protections-fact-sheet.pdf` (200) | Fix source link + add redirect | **High** | No |
| `mortgage_interestonly.pdf` | 124 | our site | → `/forms` (already superseded) | Fix source link + redirect to `/forms` | **High** | No |
| `Assistive_Animal_Brochure.pdf` | 61 | our site | **none — 404 both prefixes** | Restore file or repoint link | Low | **Yes** |
| `What is Community Property Guide - FINAL.pdf` | 55 | our site | `…/2025-12/community-property-guide.pdf` (200) | Fix source link + add redirect | **High** | No |
| `MANUFACTURED HOMES.brochure.pdf` | 39 | our site | `…/2025-12/advice-for-renters-manufactured-homes.pdf` (200) | Fix source link + add redirect | **High** | No |
| `Caregiving Brochure - Final.pdf` | 32 | our site | `…/2025-12/caregiving-brochure.pdf` (200) | Fix source link + add redirect | **High** | No |
| `Process of a Civil Lawsuit Generally.pdf` | 26 | our site | → `/resources/senior-rights-and-information` | Redirect to replacement page | Medium | **Yes** — page, not document |
| `Grandparent Visitation Rights Guide - Final.pdf` | 23 | our site | **none** | Restore file or repoint link | Low | **Yes** |
| `Expungement brochure final.pdf` | 21 | (none) | → `/forms` | Redirect to `/forms` | Medium | **Yes** |
| `Decision Making As We Age Brochure.pdf` | 21 | (none) | `…/2025-12/decision-making-as-we-age-brochure.pdf` (200) | Add redirect | **High** | No |
| `bankruptcy brochure english.pdf` | 15 | our site | `…/2025-12/bankruptcy-brochure.pdf` (200) | Fix source link + add redirect | **High** | No |
| `styles/logo_for_og/public/ilas-logo-100.png` | 15 | (none) | **none** | Leave 404 — stale OG image derivative | **High** | No |
| `Resource_and_Referral_Guide.pdf` | 9 | our site | → `/forms` | Redirect to `/forms` | Medium | **Yes** |
| `What is Normal Wear and Tear Guide.pdf` | 9 | our site | `…/2025-12/normal-wear-tear-guide.pdf` (200) | Fix source link + add redirect | **High** | No |
| `EXECUTION OF JUDGMENTS brochure_May 2020.pdf` | 9 | **courtselfhelp.idaho.gov** | → `/forms` | Redirect; **notify the State** of the correct URL | Medium | **Yes** |
| `images/LSClogo_0.jpeg` | 9 | (none) | **none** | Leave 404 — D7 asset | **High** | No |
| `Landlord Tenant Rights…8.19.20.pdf` | 8 | our site | → `/resources/landlord-and-tenant` | Fix source link; redirect to topic page | Medium | **Yes** |
| `ILAS_60x60.jpg` | 7 | (none, facebookexternalhit) | **none** | Leave 404 — stale OG image | **High** | No |
| `Divorce - No Minor Children - Court Process.pdf` | 7 | our site | **none** | Restore or repoint | Low | **Yes** |
| `EXECUTION OF JUDGMENTS brochure.pdf` | 7 | our site | **none** (undated variant) | Consolidate with the dated version | Low | **Yes** |
| `Spanish Senior Guidebook_0.pdf` | 6 | our site | → `/forms` | Redirect; **verify a Spanish equivalent exists** | Low | **Yes** — language equity |
| `LRC Relocation Guide for DV Survivors.pdf` | 6 | our site | `…/2025-12/domestic-violence-survivors-relocation-guide.pdf` (200) | Fix source link + add redirect | **High** | No |

**Summary: 10 of 22 are high-confidence mechanical fixes. 10 need content-owner review. 2 should
stay 404** (stale D7 image derivatives — no user value in redirecting them).

**Missing evidence.**
- **Which page** carries each link. Cloudflare gives referrer *host*, not path. The local DDEV
  database is stale (newest node 2026-02-19), so link discovery must run against live.
- Whether each document is still **legally current**. Per the brief, obsolete legal material
  must not be redirected merely to clear a 404 — every "restore" row needs a lawyer's read.
- The remaining 150 low-volume paths (1–5 requests each) are not yet mapped.

**Third-party impact.** Positive for users and for the State of Idaho's self-help site. No
integration depends on these paths.

**Implementation.**
1. Find linking pages on live (read-only):
   ```bash
   terminus drush idaho-legal-aid-services.live -- sql:query \
     "SELECT entity_id FROM node__field_body \
      WHERE field_body_value LIKE '%sites/idaholegalaid.org/files%';"
   ```
   *(Confirm the real field table name first — `node__body` does not exist on this site.)*
2. **Fix the source links in content** — this is the primary fix. Redirects are a safety net
   for external and historical links, not a substitute.
3. Add redirects for the legacy prefix via the Drupal `redirect` module (keeps them editable by
   staff, unlike a Cloudflare rule).
4. Route the 10 review rows to a content owner before touching them.
5. `scripts/recover_wayback_files.py` already exists and targets exactly this prefix — use it to
   recover originals for the "restore" candidates.

**Testing.** For each remediated path: `curl -sIL` returns a single 301 → 200 with
`content-type: application/pdf` and a non-trivial size. Spot-check that the served document is
the *right* document, not merely *a* document.

**Monitoring.** Legacy-prefix 404 volume at the Cloudflare edge should trend to near zero.
Watch `redirect_404` for newly appearing legacy paths. **Caveat:** `config/redirect_404.settings.yml`
contains the globs `/*_*` and `/*__*`, which swallow a large share of real 404s — that table
understates reality and must not be used as the volume source.

**Rollback.** Delete the added redirect entities; revert the content edits from revision
history.

**Classification: 🟠 Requires staged testing** (mechanical rows first; editorial rows gated on
content-owner review).

---

### 8.5 — Pantheon platform hostname

**Problem solved.** `https://live-idaho-legal-aid-services.pantheonsite.io/` returns 200 with
the full site, bypassing Cloudflare, and emits a canonical tag pointing at itself.

**Metrics affected.** Security ✅ (small) · SEO ✅ (canonical correctness) · Counted visits ❌
(0.17 %, 116 requests / 11.5 days).

**⚠️ CORRECTION TO AUDIT §13.4 — "Option A (preferred, no code)" is not implementable.**
Pantheon's documentation states the primary-domain redirect applies to connected domains
*"except the platform domain"*, and that redirecting the platform domain *"must be done via
PHP."* The audit's preferred option does not exist. Additionally, the setting only appears when
two or more custom domains are attached.

**⚠️ The Live platform hostname is actively used by CI.** `scripts/ci/derive-assistant-url.sh:56`
runs `terminus env:view "${SITE}.${ENV}" --print`, which for `live` returns
`https://live-idaho-legal-aid-services.pantheonsite.io`. Reachable today via:

| Path to a live target | Location |
|---|---|
| `CI_PROMPTFOO_ENV=live npm run eval:promptfoo:quality` | `package.json` |
| `workflow_dispatch` → `target_env: live` | `.github/workflows/quality-gate.yml:22` |
| `workflow_dispatch` → `target_env: live` | `.github/workflows/assistant-nightly-quality.yml:16` |
| `edge-cache-sample.sh:51` origin-direct probe | diagnostic tooling |

The promptfoo gate **POSTs** to `/assistant/api/message`. A 301 on a POST is downgraded to GET
by most HTTP clients and the body is dropped — the gate would fail or, worse, silently evaluate
nothing. `promptfoo-evals/lib/gate-target.js:39-44` would still report the target as `matched`,
so nothing pre-flights this.

**Not affected** by a live-only redirect: all dev/test/multidev derivations; SSH/SFTP to
`codeserver.*.drush.in` and `appserver.live.*.drush.in`; `terminus drush`; anything under
`PHP_SAPI === 'cli'`; Playwright configs (no literal platform host — they read
`PLAYWRIGHT_BASE_URL`).

**Missing evidence.** ⚠️ **GitHub repo variables and secrets cannot be read from the repo.**
`ILAS_ASSISTANT_URL`, `ASSISTANT_BASE_URL`, `A11Y_BASE_URL`, `ILAS_PLAYWRIGHT_BASE_URL` may hold
the live platform hostname. **This must be checked in GitHub settings before any redirect is
enabled.** Also: Pantheon does not document whether platform health checks require direct
access — the "exclude `/pantheon_healthcheck`" pattern is community folklore, not vendor
guidance.

**Why the risk is not worth taking.** Pantheon serves a `Disallow: /` robots.txt on platform
domains, so the indexing risk the redirect would address **is already mitigated**. The measured
bypass is 0.17 % of traffic, most of it a WordPress scanner. The redirect trades a real CI
breakage risk for a negligible, already-mitigated exposure.

**Recommendation — split the item.**

- **Defer** the redirect.
- **Do fix the canonical independently.** This is the actual defect. `config/metatag.metatag_defaults.global.yml:10`
  uses `[current-page:url:absolute]`, which reflects whatever `Host` header arrived. Replace with
  a token that resolves to the configured base URL so the canonical is correct regardless of
  hostname. This carries none of the CI risk.

**Testing (canonical fix).**
```bash
curl -s https://live-idaho-legal-aid-services.pantheonsite.io/ | grep -i 'rel="canonical"'
# expect: href="https://idaholegalaid.org/"
curl -s https://idaholegalaid.org/legal-help/housing | grep -i 'rel="canonical"'
# expect the apex URL with the correct path
```

**Monitoring.** Direct-origin requests (XFF chain absent) should remain < 1 % of origin traffic.

**Rollback.** Revert the metatag config export and redeploy.

**Classification: 🔵 Defer** (the redirect) · **🟡 Safe after minor verification** (the canonical fix).

---

### 8.6 — Cloudflare verified-bot bypass

**Problem solved (claimed).** Commercial SEO crawlers bypass the WAF via skip rule `64fae5be`
and generate high-volume, high-404 traffic.

**⚠️ CORRECTION TO AUDIT §13.2 — the proposed change will not produce the claimed effect.**

Read live from the Bot Management API (`GET /zones/{zone}/bot_management`):

```json
{ "sbfm_verified_bots":        "allow",
  "sbfm_definitely_automated": "block",
  "sbfm_likely_automated":     "managed_challenge",
  "sbfm_static_resource_protection": true,
  "ai_bots_protection": "block", "ai_training": "block",
  "crawler_protection": "enabled" }
```

Cloudflare evaluates **verified** bots under `sbfm_verified_bots`, a separate setting that
accepts only `allow` or `block` — there is deliberately no challenge option. The audit's claim
that removing the category makes those crawlers "fall through to Super Bot Fight Mode, which is
already set to `managed_challenge`… so no new rule is needed at all" is incorrect: they fall
through to SBFM and are **allowed**.

SemrushBot, AhrefsBot and MJ12bot all return a populated `verifiedBotCategory` in this zone,
which confirms Cloudflare treats them as verified.

The rule's real scope is also broader than the audit stated:
`phases: [http_request_firewall_managed, http_ratelimit, http_request_sbfm]`. Removing the
category re-exposes those bots to the **managed WAF and rate limiting only** — a small effect,
not the ~1,580/day reduction claimed.

**⚠️ CORRECTION TO AUDIT §7.4 — the bot-category table is wrong in three places.** Ground truth
from this zone's own `verifiedBotCategory` dimension, 2026-07-26 → 07-28:

| Bot | Audit said | **Actual category** | Consequence of the audit's plan |
|---|---|---|---|
| **LinkCheck by Siteimprove** | Accessibility — ✅ keep | **Search Engine Optimization** | Would have been **silently broken** |
| **IbouBot** | SEO — ❌ remove | **Search Engine Crawler** | Would **not** have been stopped |
| **SeekportBot** | SEO — ❌ remove | **Search Engine Crawler** | Would **not** have been stopped |

Confirmed correct in the audit: Googlebot, bingbot, Baiduspider, DuckDuckBot = Search Engine
Crawler; Better Stack + UptimeRobot = Monitoring & Analytics; facebookexternalhit = Page Preview.

**Full category census (2 days, all traffic):**

| Category | Requests | In skip rule? |
|---|---:|---|
| *(not a verified bot)* | 59,202 | — |
| Search Engine Optimization | 3,817 | ✅ |
| Search Engine Crawler | 3,324 | ✅ |
| Monitoring & Analytics | 1,580 | ✅ |
| AI Assistant | 1,328 | ❌ |
| Security | 602 | ✅ |
| Page Preview | 468 | ✅ |
| AI Crawler | 431 | ❌ |
| AI Search | 164 | ❌ |
| Accessibility | 105 | ✅ |
| Advertising & Marketing | 103 | ❌ |
| Aggregator (Pinterestbot) | 30 | ❌ |
| Feed Fetcher | 1 | ❌ |
| **Webhooks** | **1** (Slackbot) | ✅ |

**Composition of the SEO category** (2 days, by edge status):

| UA | 404 | 200 | 301 |
|---|---:|---:|---:|
| SemrushBot/7~bl | **2,720** | 109 | 3 |
| MJ12bot (v1.4.8 + v2.0.5) | — | 310 | 255 |
| AhrefsBot/7.0 | — | 140 | 70 |
| LinkCheck by Siteimprove | 13 | 13 | 37 |
| SiteAuditBot, SemrushBot-BA, Barkrowler, DataForSeoBot | — | ~12 | ~26 |

**SemrushBot generates 2,720 404s in two days — 96 % of its own traffic is errors.** It is the
single best challenge candidate on the zone.

**Dependency check.** *(Confirmed with the site owner, 2026-07-28: ILAS uses none of Semrush,
Ahrefs, or Siteimprove.)* No carve-out is required. Stopping Siteimprove LinkCheck is therefore
an accepted, deliberate consequence — recorded here explicitly because the audit had it
miscategorised and would have broken it unknowingly.

**Effect estimates — stated separately, as required:**

| Metric | Estimate | Confidence |
|---|---|---|
| Cloudflare edge requests | −1,900/day (SEO category total ÷ 2) | **High** — measured |
| Pantheon edge requests | −1,900/day, minus whatever the managed WAF already blocks | **Medium** |
| nginx / PHP requests | −900 to −1,500/day | **Medium** |
| **Pantheon counted visits** | **≈ 0** | **High** |

**On counted visits:** SemrushBot, AhrefsBot and MJ12bot self-identify in their user agents and
are long-standing, well-known crawlers. Pantheon's definition excludes "known bots," so these
are almost certainly excluded already. **Do not expect this change to move the bill.** It is
justified on origin load and crawl hygiene alone — 2,720 wasted PHP executions per two days.

**Missing evidence.** Whether Cloudflare's managed WAF rulesets would already block a share of
this traffic once the skip is removed (measurable only by making the change in Log mode).

**Implementation (6-alt).** Two coordinated edits — see §10.2 for exact expressions:
1. Remove `"Search Engine Optimization"` from skip rule `64fae5be` (so the skip and the new rule
   do not contradict each other).
2. Add a new custom rule **ahead of** SBFM matching that category, action **Log** initially.

**Staged rollout.** Log ≥ 7 days → review Security Events for any unexpected UA → promote to
**Managed Challenge** (never Block as the first action) → re-measure after 7 more days.

**Testing.** In Security Events, filter by the new rule ID; confirm matched UAs are only the
SEO-category crawlers and that Googlebot, bingbot, Better Stack and UptimeRobot never appear.

**Monitoring.** SemrushBot request count → near zero. **Watch uptime alerting closely** — if
Better Stack or UptimeRobot ever appears in the match list, roll back immediately.

**Rollback.** Set the new rule to Log or delete it, and re-add the category string to
`64fae5be`. Effect < 30 s.

**Classification: ❌ Reject as specified** · **🟠 Requires staged testing** in the 6-alt form.

---

### 8.7 — Browser-spoofing automated traffic

**Problem.** The audit estimates ~14.7 % of counted visits come from browser-looking clients
that fetch HTML but never fetch assets. **This is treated here as an unproven hypothesis.**

**Status: the analysis required to justify a rule has not been performed.** The audit's
signal — "fetches HTML, fetches no assets" — is explicitly ruled out by the brief as a sole
basis, and correctly so: it is the exact signature of several legitimate populations.

**What was observed in passing** (2 days, top user agents at the edge):

| UA | Requests | Note |
|---|---:|---|
| `Chrome/142.0.0.0` (macOS) | **9,935** | Largest single UA on the zone; version well behind the Chrome/150 seen elsewhere — a plausible spoof signature |
| `Chrome/150.0.0.0` (Windows) | 5,544 | Current version — likely genuine |
| `Chrome/150.0.0.0` (Android) | 4,761 | Likely genuine |
| `Safari 26.5.2` (iPhone) | 3,849 | Likely genuine |
| `curl/8.7.1` | 2,909 | Self-identifying; already 403'd |
| `axios/1.17.0` | 872 | Self-identifying script client |
| `NetworkingExtension/… iOS/26.5.2` | 655 | Apple system component, legitimate |

The `Chrome/142` concentration is the strongest lead but is **not sufficient evidence** — a
single stale-version UA can equally be a corporate fleet on a pinned browser build.

**Legitimate traffic that resembles this pattern** and must not be caught: cached returning
visitors (assets served from browser cache — will show HTML-only), privacy browsers that block
analytics, screen readers and accessibility tooling, RSS readers, corporate proxies and shared
NAT egress, text-only browsers, **email link scanners** (Microsoft Defender, Proofpoint — these
follow every link in every email), translation services, government link validators, and partner
integrations. For a legal-aid site, several of these correlate with **vulnerable users**:
someone on a library terminal, a shared device, or a domestic-violence shelter network is
disproportionately likely to look "suspicious" to a naive heuristic.

**Evidence still required before any rule is written:**

```graphql
# Per-candidate-population signals — run over ≥7 days
httpRequestsAdaptiveGroups(filter:{...}) {
  count
  dimensions {
    botScore                 # Cloudflare's own 1-99 score — the primary signal
    ja4                      # TLS fingerprint: does it match the claimed browser?
    clientASNDescription     # hosting provider vs consumer ISP
    clientCountryName
    clientRequestPath
    userAgent
    edgeResponseStatus
  }
}
```

Specifically needed: (a) bot-score distribution for the `Chrome/142` population; (b) whether its
JA4 fingerprint matches real Chrome; (c) ASN ownership — hosting provider vs residential ISP;
(d) request regularity (cron-like intervals vs human bursts); (e) whether these clients fetch
`/robots.txt`; (f) whether the `/cdn-cgi/rum` beacon fires for them (JS execution is a strong
human signal, and the zone already records 5,256 beacon hits); (g) path diversity and
navigation sequence; (h) persistence across multiple days.

**Recommendation.** Do not create a rule. Run the analysis above first. If a population is then
justified, the first action must be **Log**, for a **minimum 14-day** observation period, and
the eventual action should be **Managed Challenge** — never a block, and never rate limiting
keyed on `ip.src` alone, because shared/NAT egress is common among the site's users.

**Success criteria (if a rule is eventually deployed).** Matched population is ≥ 95 % low
bot-score; zero matches carrying a genuine browser JA4; no increase in support contacts; no drop
in GA4 sessions or in form completions.

**Rollback.** Delete the rule; effect is immediate.

**Classification: 🟠 Requires staged testing** — and the prerequisite analysis is **not yet
done**. No rule is proposed in this document.

---

### 8.8 — Legitimate scripted and non-browser access

**Problem.** Cloudflare returns 403 to ordinary scripted HTTP clients on all HTML paths,
verified from two unrelated networks. This is fingerprint-based (TLS/HTTP2 signature), not
IP-based. The question is whether anything legitimate depends on programmatic access.

**Currently permitted** (explicit skip rules, verified in the live ruleset):

| Path | Rule |
|---|---|
| `/robots.txt`, `/.well-known/security.txt` (GET) | `92082bed` — skips SBFM, BIC, security level |
| `/assistant/api/session/bootstrap` (GET) | `db42c4d6` — skips SBFM |

**Permitted by verified-bot status** (skip rule `64fae5be`): Googlebot, bingbot, Baiduspider,
DuckDuckBot (Search Engine Crawler); **Better Stack and UptimeRobot** (Monitoring & Analytics —
the site's own uptime monitoring, confirmed working at 958 and 565 requests / 2 days);
facebookexternalhit, Twitterbot, Slack-ImgProxy, Iframely (Page Preview); Slackbot (Webhooks).

**Intentionally blocked:** generic `curl`, `axios`, `python-requests` and similar clients
presenting no browser TLS fingerprint. This is working as designed and no breakage has been
reported.

**Assessment by consumer type:**

| Consumer | Status | Action |
|---|---|---|
| Search engines (Google/Bing/DDG/Baidu) | ✅ Verified-bot skip | None |
| Better Stack, UptimeRobot | ✅ Verified-bot skip | **Do not remove Monitoring & Analytics from the skip rule** |
| Social previews (Facebook, Twitter, Slack) | ✅ Page Preview skip | None |
| `robots.txt`, `security.txt` | ✅ Explicit skip | None |
| Assistant bootstrap | ✅ Explicit skip | None |
| **`/sitemap.xml`** | ⚠️ 403 to scripted clients | See below |
| RSS/JSON feeds | ⚠️ Unknown whether any exist or are consumed | Enumerate |
| Email security scanners | ⚠️ Likely 403 | Accept — they are not a dependency |
| Siteimprove | Currently allowed; **will be blocked** by 6-alt | Accepted (not used by ILAS) |
| Donation / employment integrations | ✅ Inbound only, unaffected | None |

**`/sitemap.xml` is the one item worth a decision.** Search engines reach it as verified bots,
so indexing is unaffected. But any partner, directory, or government aggregator consuming the
sitemap with a plain HTTP client currently gets a 403. Since a sitemap is public by design and
contains nothing sensitive, a narrow skip is defensible.

**Missing evidence.** No inventory exists of external parties consuming ILAS feeds, sitemaps, or
PDFs programmatically. Legal-services directories and government referral partners are the most
likely candidates and cannot be discovered from inside the codebase — this needs a question to
program staff, not a code search.

**Recommendation.** Add a skip for `/sitemap.xml` and any XML sitemap variants **only if** a
real consumer is identified. Do not broaden beyond that. Document the current allow/block
posture (this table) in `docs/observability.md` so future reviewers do not re-litigate it.

**Testing.** `curl -sI https://idaholegalaid.org/sitemap.xml` → 200 after any change; confirm
`/user/login` and `/wp-login.php` still return 403 to the same client.

**Monitoring.** Watch for a rise in unverified scripted traffic on any newly skipped path.

**Rollback.** Delete the skip rule.

**Classification: 🟡 Safe after minor verification** (documentation is safe now; new skip rules
only on demonstrated dependency).

---

### 8.9 — The July 6 cache-ratio change *(and a larger finding)*

This section reaches two separate conclusions. **They must not be conflated.**

#### A. The July 6 deployment did not cause the change — **Unsupported**

Every candidate mechanism was tested and eliminated:

| Hypothesis | Test | Result |
|---|---|---|
| Traffic mix shifted toward 404s/301s | Cloudflare daily series, Jul 1–28 | ❌ **Refuted** — see table below |
| Alias churn minted new redirects | `SELECT DATE(created), COUNT(*) FROM redirect` on live | ❌ **Refuted** — **no redirect created since 2026-02-19** |
| Caching config changed | Full `config` table diff, 06-30 vs 07-07 backups | ❌ No caching-relevant change |
| Language config changed | `language.types` extracted from the 06-30 backup | ❌ **Identical** — `language-browser: -2` already enabled |
| Modules changed | `core.extension` from the 06-30 backup | ❌ Identical (Redis came 07-07/08, *after* the drop) |
| Core changed the negotiation code | `git diff` of the two deployed build artifacts | ❌ **Byte-identical** (md5 `cc81863dc7a4ba22692a65285a0d0b11`) |
| Pantheon platform event | `terminus workflow:list` Jun 25 – Jul 12 | ❌ Only the deploys themselves |
| `system.performance` / `redirect` settings changed | git history | ❌ Untouched since 2025-12 |

**Cloudflare proves traffic was flat while Pantheon's ratio collapsed:**

| | Jul 1 (Wed) | Jul 5 (Sun) | **Jul 6 (Mon)** | **Jul 7 (Tue)** |
|---|---:|---:|---:|---:|
| Pantheon visits | 1,420 | 737 | 1,411 | 1,640 |
| Pantheon pages served | 2,487 | 1,128 | 2,797 | 2,914 |
| Pantheon cache hits | 2,220 | 1,000 | 1,370 | 599 |
| **Pantheon hit ratio** | **89.26 %** | **88.65 %** | **48.98 %** | **20.56 %** |
| Cloudflare origin-bound | 18,779 | 17,453 | 18,715 | 23,602 |
| Cloudflare 301s | 6,328 | 4,966 | 5,364 | 4,990 |
| Cloudflare 404s | 2,051 | 1,006 | 849 | 4,987 |

Jul 1 and Jul 6 are near-identical in visits (1,420 vs 1,411) and comparable in pages served,
with edge 301/404 volume flat-to-*down* — yet the hit ratio fell by 40 points.

**⚠️ CORRECTION TO AUDIT §8.2.** The audit concluded *"The 'cache collapse' is not a cacheability
regression — it is a change in traffic mix toward errors and redirects."* **This is refuted.**
Traffic mix did not change. It is a cacheability problem.

**⚠️ CORRECTION TO AUDIT §8.1.** The audit stated the July 6 window was *"unrecoverable"*
because nginx logs retain ~11 days. That is not correct: Cloudflare analytics reach back to
July 1, and the pre-deploy database backup (2026-06-30, 107 MB) was still available and has been
recovered. **Both have been captured** to `/tmp/claude-1000/live_2026-06-30.sql.gz` and
`live_2026-07-07.sql.gz` — these should be moved to durable storage, as the June 30 backup
expires **2026-08-01**.

**Verdict on causation: Unsupported.** Do **not** roll back any code. What changed on July 6
remains unexplained by anything in the repository, the configuration, or the traffic. The
remaining candidate is a **Pantheon-side CDN or measurement change**, which only Pantheon
support can confirm (see §15).

#### B. A sitewide cacheability defect exists and is **Proven**

Independently of July 6, the site has a serious, current defect the audit did not find because
**it tested only the homepage — one of the few cacheable paths.**

Live origin probe, anonymous, no cookies:

| Path | Code | `x-drupal-cache` | `cache-control` |
|---|---|---|---|
| `/` | 200 | **HIT** | `max-age=86400, public` |
| `/es` · `/sw` · `/nl` | 200 | MISS *(cacheable)* | `max-age=86400, public` |
| `/es/resources/quiebra` · `/es/videoteca` · `/es/resources/custodia` | 200 | MISS *(cacheable)* | `max-age=86400, public` |
| `/nl/videoteca` | **404** | MISS *(cacheable)* | `max-age=86400, public` |
| `/legal-help/housing` · `/forms` · `/donate` · `/resources/probate` · `/contact/offices/boise-office` | 200 | **UNCACHEABLE (response policy)** | `must-revalidate, no-cache, private` |
| `/videoteca` · `/news` | **404** | **UNCACHEABLE** | same |
| `/about-us` · `/legal-topics` · `/get-help` | **301** | **UNCACHEABLE** | same |

**The `/nl/videoteca` vs `/videoteca` pair is decisive**: the same missing page, the same 404
status, differing only by a language prefix — one cacheable, one not. The discriminator is
**purely the first path segment**, not content, not status, not forms, not blocks.

**Mechanism.** `config/language.types.yml` enables `language-browser: -2` for
`language_interface`. Core's
`web/core/modules/language/src/Plugin/LanguageNegotiation/LanguageNegotiationBrowser.php:63`
calls `$this->pageCacheKillSwitch->trigger()` **unconditionally** — core's own comment
acknowledges it disables the internal page cache. It is reached only when `language-url` fails
to match, and with `prefixes: {en: '', es: es, sw: sw, nl: nl}` that method matches only `/`
(empty `en` prefix) and paths beginning `es`/`sw`/`nl`. **Every other URL on the site trips the
kill switch**, including redirects and 404s, because negotiation runs at `kernel.request`
priority 255 — before routing.

The kill switch is tagged for **both** `page_cache_response_policy` and
`dynamic_page_cache_response_policy` (`web/core/core.services.yml:411-416`), so Dynamic Page
Cache is disabled for authenticated users too.

Ruled out by inspection: CAPTCHA (`enable_globally: 0`, `whitelist_ips: ''`, 3 user-form points
only), honeypot (`protect_all_forms: false`), antibot (no cache code at all), Webform, Klaro,
seckit, redirect/redirect_404, and all custom modules. **No contrib or custom response policy
exists in this codebase.** No block renders a Drupal form.

**Impact.** This is the direct cause of the current ~20 % hit ratio, ~2,000 avoidable PHP
executions per day, and slower page loads for every visitor on every page except the homepage
and Spanish/Swahili/Dutch sections.

**Fix.** Remove `language-browser: -2` from `negotiation.language_interface.enabled` in
`config/language.types.yml` (leave it in `method_weights` so the change is a one-line revert).
`language-user-admin`, `language-url` and `language-selected` remain; `language-selected`
already falls back to `site_default` (English).

**Accepted trade-off** *(decision confirmed with the site owner, 2026-07-28)*: first-time
visitors who have not chosen a language will no longer be auto-switched to Spanish based on
their browser's `Accept-Language`. **Preserved:** the `/es` URL prefix, the language switcher,
all Spanish content and search, hreflang tags, and every existing Spanish URL. This is a
user-facing change for Spanish-speaking visitors and should be communicated to program staff
even though it is not blocking.

**Testing (dev → test → live, verify at each step).**
```bash
BASE=https://<env>-idaho-legal-aid-services.pantheonsite.io
curl -sI $BASE/legal-help/housing | grep -iE 'x-drupal-cache|cache-control'
#   before: UNCACHEABLE (response policy) / must-revalidate, no-cache, private
#   after:  MISS then HIT             / max-age=86400, public
curl -sI $BASE/es/resources/quiebra | grep -i cache      # must stay cacheable
curl -s  $BASE/es/videoteca | grep -o 'lang="[a-z]*"'    # must still be Spanish
curl -sI $BASE/news | grep -i cache-control              # 404s become cacheable too
```
Also confirm the language switcher still works and that a visitor sending
`Accept-Language: es` on `/` now receives English (the intended behavioural change).

**Monitoring.** Pantheon `cache_hit_ratio` should climb toward 85–90 % within 48 h. Pages served
should fall. Origin PHP requests should drop sharply. Track daily for two weeks:
```bash
terminus env:metrics idaho-legal-aid-services.live --period=day --datapoints=14
```

**Rollback.** Re-add the one line to `config/language.types.yml`, export, deploy. Effect is
immediate on cache rebuild.

**Classification.** Causation for July 6: **Unsupported.** The cacheability defect: **Proven**,
and its fix is **🟠 Requires staged testing** — and is the **highest-value change in this
document.**

> **Follow-up recorded 2026-07-29 (post-implementation).** The fix works as designed, and its
> reach is wider than pages: 404s, 403s and 301s all now send `max-age=86400, public`. That is
> intended and tag-backed (see the correction in §8.11), but it **raises the priority of §8.10**.
> With responses cacheable and still no `Surrogate-Key` header, invalidation depends on the 24 h
> `max-age` expiring or a full CDN clear — measured on Live 2026-07-29, the front page was being
> served at `age: 43046` (~12 h old). §8.10 deferred itself *only* until §8.9 was fixed; that
> condition is now met and it has been taken up as tracker item 13.

---

### 8.10 — `pantheon_advanced_page_cache`

**Problem solved.** Without it the site emits no `Surrogate-Key` headers, so Pantheon's Global
CDN cannot purge by cache tag. Invalidation relies on the 24-hour `max-age` expiring or a full
CDN clear — editors see up to 24 h of staleness.

**Metrics affected.** Content freshness ✅ · cache management ✅ · **Counted visits ❌** ·
origin requests ➖ (indirect at best).

**Evidence (verified against current sources, 2026-07-28).**

| Question | Answer |
|---|---|
| Latest release | **2.4.0**, published 2026-06-26 |
| Drupal 11.4 compatible | ✅ `core_version_requirement: ^10 \|\| ^11` |
| Maintenance status | Active — Pantheon is the supporting org; last commit 2026-05-06; issue queue active this week |
| Security coverage | ✅ Covered by the security advisory policy |
| Pantheon's current recommendation | ✅ Still recommended (`docs.pantheon.io/drupal-cache`, doc updated 2026-07-27) |
| Bundled in the Pantheon upstream? | ❌ No — requires explicit `composer require` |
| Configuration required | None — zero-config |
| Redis compatibility | No direct conflict found |

**🔴 Two blockers.**

1. **Big Pipe incompatibility.** The module's `.info.yml` declares
   `incompatible_modules: [big_pipe]`, and Pantheon documents that *"for sites with Pantheon
   Advanced Page Cache 2.3.4 installed, BigPipe is automatically disabled."* **`big_pipe` is
   enabled on this site** (`config/core.extension.yml`, confirmed present in the 2026-06-30
   backup too). Installing PAPC will silently disable it, removing progressive rendering for
   authenticated users. That is a real behavioural change for staff, and it must be a decision,
   not a surprise.

2. **Unfixed Webform file-upload regression.** Issue
   [#3446954](https://www.drupal.org/project/pantheon_advanced_page_cache/issues/3446954),
   status **Needs review**, active 2026-07-22. Moving uploaded files temporary→permanent fires
   one edge purge *per file per image style*. The reported worst case is a **34-second form
   submission**. This site runs `webform` plus a custom employment-application intake that
   accepts document uploads — squarely in scope.

**Surrogate-Key size limit.** Capped at 25,000 bytes (Pantheon's nginx rejects total headers
over 32 KB with a 502). Keys beyond the cap are **silently trimmed**, meaning affected pages
*"will not be cleared from the cache"* — stale content rather than an error. Tunable via
`$config['pantheon_advanced_page_cache.settings']['surrogate_key_header_limit']`.

**Precise benefit expected for this site: currently near zero.** Tag-based purging accelerates
invalidation of *cached* pages. Right now roughly 80 % of pages are not cached at all (§8.9), so
there is almost nothing for it to purge. **Installing it before fixing §8.9 would add two known
risks in exchange for a benefit the site cannot yet realise.**

**Interactions assessed:** Cloudflare — none; Cloudflare has no visibility into Drupal cache
tags and no purge integration exists in this codebase. Search API, language negotiation,
authenticated pages — no issues found in the queue either way (recorded as *not verified*, not
as *safe*).

**Recommendation.** **Defer.** Revisit after §8.9 is fixed and the hit ratio has recovered.
At that point, evaluate the Big Pipe trade-off explicitly and test the employment-application
submission path with file uploads on Test before enabling on Live.

**Rollback (when eventually attempted).** `drush pmu pantheon_advanced_page_cache`,
`composer remove drupal/pantheon_advanced_page_cache`, re-enable `big_pipe`, export config,
deploy, clear caches.

**Classification: 🔵 Defer** (until §8.9 is resolved).

---

### 8.11 — Caching 404 responses

**Problem.** 404s are structurally uncacheable and each bootstraps Drupal.

> **⚠️ CORRECTION 2026-07-29 — the premise below is now stale.** It described the site *before*
> §8.9B was implemented, and was only ever true of non-prefixed paths: §8.9B's own evidence table
> already lists `/nl/videoteca` (a 404) as `MISS (cacheable) / max-age=86400, public`, and §8.9B's
> test script states `# 404s become cacheable too` as an expected outcome. Since item 1 shipped on
> 2026-07-28, **every** 404 — plus 403s and 301s — sends `cache-control: max-age=86400, public`,
> and Cloudflare caches the static-extension ones for 24 h.
>
> **The recommendation below is unaffected.** Option 5 was rejected as a *Cloudflare Cache Rule
> with Edge TTL "ignore cache-control"* — a mechanism that overrides origin intent and covers HTML
> too. What exists now is the origin declaring cacheability backed by Drupal's `4xx-response`
> cache tag, which `EntityBase::invalidateTagsOnSave()` invalidates on every entity create/update
> ("Creating or updating an entity may change a cached 403 or 404 response"). That is a supported
> Drupal pattern, not the rejected change.
>
> Measured 2026-07-29: of all zone 404s, **static-extension paths are cached (230 `hit`) and
> HTML/page paths are not (0 cached; 81 `dynamic`, 52 `none`)** — so this section's stated worry
> about "pages about to be published" does not arise at Cloudflare. The genuine residual is edges
> that cannot read Drupal cache tags, which is **§8.10's** problem, not this section's. See the
> tracker, items 2 & 4, finding F-5.

**Key technical constraint** *(as assessed 2026-07-28, superseded — see the correction above).*
Cloudflare caches 404/410 for **3 minutes by default**, but
**not** when the origin sends `private`, `no-store`, `no-cache` or `max-age=0`. This site's 404s
send `must-revalidate, no-cache, private` — tripping two of those. Default negative caching
therefore does **not** apply. An explicit Cache Rule with Edge TTL "ignore cache-control" would
be required. Separately, *"Pantheon's default is to not cache 404s"* — so there are **two**
layers to change, not one.

**Comparison of the five options:**

| Option | Effect | Risk | Verdict |
|---|---|---|---|
| **1. Fix the underlying missing files** (§8.1, §8.4) | Removes ~60 % of all 404s (icons) plus the legacy-file tail | **None** | ✅ **Do this first** |
| **2. Drupal fast 404** | Already enabled; regex covers `.ico/.png/.js/.css` etc. but **not `.json`/`.xml`** | None | 🟡 Minor: add `json\|xml` to the pattern |
| **3. Cheaper 404 page** | `page.404: /node/119` renders a full node with all blocks. A lightweight controller would cut per-404 cost | Low | 🟡 Worth doing *after* option 1, if volume remains |
| **4. Cloudflare negative caching, selected extensions only** | Would absorb residual asset-path 404s | Low — extension-scoped, no HTML | 🔵 Re-evaluate after option 1 |
| **5. Broad 404 caching** | Largest theoretical saving | **High** — caches "not found" for pages about to be published or files about to be uploaded; pollutes cache per query-string variant; no purge path exists | ❌ **Not recommended** |

**Why broad caching is rejected here.** There is no Cloudflare purge integration in this
codebase (confirmed: no `cloudflare` Drupal module, no purge module, no API purge in any custom
module). Without a purge path, a cached 404 on a newly published page or newly uploaded document
persists for the full TTL with no way to clear it except a global purge. For a site whose
purpose is delivering time-sensitive legal information, that is the wrong trade.

Additional considerations if it is ever revisited: Drupal's `redirect_404` tracking depends on
404s reaching the origin — edge caching would blind it; query-string variants multiply cache
entries; and `config/redirect_404.settings.yml`'s `/*_*` glob already makes that table
unreliable.

**Recommendation.** Implement §8.1 and §8.4, then re-measure. Add `json|xml` to the `fast_404`
pattern as a cheap independent win. Re-evaluate options 3 and 4 only against whatever 404 volume
actually remains.

**Classification: 🔵 Defer** (broad caching **rejected**; narrow options re-evaluated after
root-cause fixes).

---

### 8.12 — Cloudflare HTML caching

**Per the brief, no rule is designed here.** This section only determines whether further work
is justified. **It is not.**

| Consideration | Assessment |
|---|---|
| Expected reduction in origin requests | ~60 % (audit estimate, plausible) |
| **Expected reduction in Pantheon counted visits** | **Zero** — Pantheon meters at its own Global CDN, in front of the origin. Confirmed from Pantheon docs. |
| Session-cookie risk | Real. The assistant bootstrap mints a **7-day `SSESS` cookie scoped to `.idaholegalaid.org`** for anonymous visitors. |
| Authenticated-page risk | High — mis-scoped exclusions could serve one user's page to another |
| Webform / CSRF-token risk | High — cached form tokens break submissions |
| Search-result risk | High — `/search` is query-dependent and faceted |
| Assistant-session risk | High — per-session state |
| Employment-application risk | High — CSRF token + session nonce + `form_start_time`; caching would break the security pipeline |
| Donation-flow risk | High — token endpoint, `page_cache_kill_switch` |
| Language/personalisation risk | High — 4 languages with path-prefix negotiation |
| Invalidation | **No mechanism exists.** Cloudflare cannot see Drupal cache tags and there is no purge integration in this codebase. |
| Purge requirements | Would require building a node-save → Cloudflare purge-by-URL hook |
| Query-string variation | Would need an explicit cache-key policy for `utm_*`/`fbclid`/`gclid` |
| Cookie-based bypass | Mandatory — `SSESS`, `SESS`, `NO_CACHE` |

**Decisive argument against proceeding.** The origin-load problem this would solve is largely an
artefact of the §8.9 defect. Fixing browser language negotiation restores **Pantheon's own**
Global CDN — a cache layer that already exists, already understands the origin's headers, and
already has a purge path. Adding a second, tag-blind cache layer in front of it to compensate
for a broken first layer is the wrong order of operations.

There is no demonstrated origin-capacity or performance problem: the site is at 49 % of its
pages-served allowance and the origin serves ~5,800 requests/day.

**Classification: 🔵 Deferred.** Revisit only if, after §8.9 is fixed, origin load is
*demonstrably* still a problem.

---

## 9. Legitimate services and integrations that could be affected

| Service | Depends on | Affected by | Protection |
|---|---|---|---|
| **Better Stack Uptime** | HTTP GET, verified bot | Removing "Monitoring & Analytics" from `64fae5be` | **Never remove that category** |
| **UptimeRobot** | HTTP GET, verified bot | same | same |
| Googlebot / bingbot / DuckDuckBot / Baiduspider | Verified bot (Search Engine Crawler) | Removing that category | Not proposed |
| Facebook / Twitter / Slack / Iframely previews | Verified bot (Page Preview) | Removing that category | Not proposed |
| **Siteimprove LinkCheck** | Verified bot — **SEO category** | §8.6 **will block it** | Accepted; ILAS does not use it |
| **`courtselfhelp.idaho.gov`** | Deep link to a legacy PDF | §8.4 remediation | Redirect + notify the State of the correct URL |
| Pantheon platform health checks | Direct platform-hostname access | §8.5 redirect | **Deferred** — undocumented by Pantheon |
| **CI promptfoo quality gate** | POST to the Live platform hostname | §8.5 redirect | **Deferred** — would break the gate |
| Playwright / a11y suites | `PLAYWRIGHT_BASE_URL` repo variable | §8.5 redirect | Must audit GitHub vars before any change |
| Assistant bootstrap | Explicit WAF skip | Any WAF broadening | Skip rule `db42c4d6` retained |
| Donation / employment intake | Inbound POST from real browsers | §8.7 rules | Log-only first; never rate-limit on `ip.src` alone |
| Screen readers / accessibility tools | HTML-only fetch patterns | §8.7 rules | Explicitly protected — see §8.7 |
| Email link scanners, library/shared networks | HTML-only, NAT egress | §8.7 rules | Explicitly protected |
| Spanish-speaking first-time visitors | Browser language auto-detection | **§8.9 fix removes this** | Accepted; `/es`, switcher, hreflang all retained |

---

## 10. Exact proposed Cloudflare expressions

> Zone `idaholegalaid.org` · `7aef3c4adc977c9f645472338b031450` · Business Website plan.

### 10.1 — `www` → apex Single Redirect *(§8.2)*

**Recommended (wildcard form — Cloudflare's own documented pattern):**
```
Rule name:  ILAS - www to apex
When incoming requests match:
    (http.host eq "www.idaholegalaid.org")
Then:
    Type:                  Dynamic
    Expression:            concat("https://idaholegalaid.org", http.request.uri.path)
    Status code:           301
    Preserve query string: ENABLED        ← defaults to OFF; must be turned on
```

Equivalent wildcard formulation, if preferred in the UI:
```
    Request URL wildcard pattern:  https://www.idaholegalaid.org/*
    Target URL:                    https://idaholegalaid.org/${1}
    Status code:                   301
    Preserve query string:         ENABLED
```

⚠️ Do **not** also concatenate `http.request.uri.query` — with `preserve_query_string` enabled
that would duplicate the query string.

### 10.2 — SEO-crawler challenge *(§8.6 — replaces the rejected change)*

**Step 1 — amend the existing skip rule `64fae5be`:**
```diff
- (cf.verified_bot_category in {"Search Engine Crawler" "Search Engine Optimization"
-   "Page Preview" "Monitoring & Analytics" "Accessibility" "Security" "Webhooks"})
+ (cf.verified_bot_category in {"Search Engine Crawler"
+   "Page Preview" "Monitoring & Analytics" "Accessibility" "Security" "Webhooks"})
```
*(`Monitoring & Analytics` retained — it carries Better Stack and UptimeRobot.)*

**Step 2 — add a new custom rule, ordered BEFORE `64fae5be`:**
```
Rule name:  ILAS - SEO crawler challenge
Expression: (cf.verified_bot_category eq "Search Engine Optimization")
Action:     Log            ← phase 1, minimum 7 days
            → Managed Challenge  (phase 2, after review)
```
Step 2 is required because `sbfm_verified_bots: "allow"` means Super Bot Fight Mode will **not**
challenge these bots on its own.

### 10.3 — Not proposed

- **No rule is proposed for §8.7** (browser-spoofing) — the prerequisite analysis is incomplete.
- **No cache rule is proposed for §8.11 or §8.12.**

---

## 11. Exact proposed code and configuration changes

### 11.1 — `config/language.types.yml` *(§8.9 — highest value)*
```diff
   language_interface:
     enabled:
       language-user-admin: -10
       language-url: -8
-      language-browser: -2
       language-selected: 12
     method_weights:
       language-user-admin: -10
       language-url: -8
       language-session: -6
       language-user: -4
       language-browser: -2      # retained here — re-enable by restoring the line above
       language-selected: 12
```
UI equivalent: `/admin/config/regional/language/detection` → uncheck **Browser**.

### 11.2 — New static files *(§8.1)*
```
web/favicon.ico                        (ICO, 16+32+48, from ILAS Favicon_1.svg)
web/apple-touch-icon.png               (PNG 180x180, no alpha)
web/apple-touch-icon-precomposed.png   (identical bytes)
web/icon-192.png  web/icon-512.png     (manifest targets)
web/site.webmanifest                   (name, short_name, icons, theme_color)
web/manifest.json                      (copy of the above; 40 recorded 404s)
web/themes/custom/b5subtheme/images/ILAS-favicon-source.svg   (committed source)
```

### 11.3 — `web/themes/custom/b5subtheme/templates/page/html.html.twig` *(§8.1)*
```diff
   <head>
     <head-placeholder token="{{ placeholder_token }}">
     <title>{{ head_title|safe_join(' | ') }}</title>
+
+    {# Icons. /favicon.ico resolves by browser convention and needs no tag;
+       Drupal already injects <link rel="icon"> from theme settings. #}
+    <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png">
+    <link rel="manifest" href="/site.webmanifest">
```

### 11.4 — `config/system.performance.yml` *(§8.11, cheap independent win)*
```diff
 fast_404:
   enabled: true
-  paths: '/\.(?:txt|png|gif|jpe?g|css|js|ico|swf|flv|cgi|bat|pl|dll|exe|asp)$/i'
+  paths: '/\.(?:txt|png|gif|jpe?g|css|js|ico|swf|flv|cgi|bat|pl|dll|exe|asp|json|xml|webmanifest)$/i'
```

### 11.5 — `config/metatag.metatag_defaults.global.yml` *(§8.5 canonical fix)*
Replace `[current-page:url:absolute]` on the `canonical_url` key with a token that resolves to
the configured base URL rather than the received `Host` header. Verify the chosen token renders
correctly on both the apex and the platform hostname before deploying.

### 11.6 — Redirect entities *(§8.4)*
Created through the Drupal `redirect` UI or a Drush command, **not** committed to config
(redirects are content entities). Only the high-confidence rows from §8.4's table; editorial
rows wait on content-owner review.

---

## 12. Testing matrix

| # | Change | Env | Test | Pass criteria |
|---|---|---|---|---|
| 1 | Icons | Dev→Test→Live | `curl -sI` on 5 icon paths | All 200 |
| 1 | Icons | Live | `grep -c 'rel="icon"'` on homepage | Exactly 1 |
| 1 | Icons | Live | Chrome/Firefox/Safari + iOS home screen | ILAS mark, not Bootstrap "B" |
| 2 | www→apex | Live | `curl -sI` www with path+query | 301, `server: cloudflare`, `cf-ray` present, query intact |
| 2 | www→apex | Live | `curl -sIL` www | Exactly one hop, no loop |
| 2 | www→apex | Live | `curl -sI` apex | 200, no redirect |
| 3 | DNS delete | — | `dig +short old.idaholegalaid.org` | Empty; apex/www/mail unchanged |
| 4 | Legacy files | Live | `curl -sIL` per remediated path | 301→200, correct `content-type`, correct document |
| 6 | SEO rule | Live | Security Events by rule ID, 7 days | Only SEO-category UAs; **zero** Better Stack / UptimeRobot / Googlebot |
| 8 | Scripted access | Live | `curl -sI /sitemap.xml`; `/wp-login.php` | 200 / still 403 |
| 9 | Language fix | Dev→Test→Live | `curl -sI /legal-help/housing` | `x-drupal-cache: MISS`→`HIT`, `max-age=86400, public` |
| 9 | Language fix | Dev→Test→Live | `curl -sI /es/resources/quiebra` | Still cacheable, still Spanish |
| 9 | Language fix | Live | Language switcher, `/es` deep links | Function unchanged |
| 9 | Language fix | Live | `Accept-Language: es` on `/` | Returns English (intended change) |
| 11 | fast_404 | Test | `curl -sI /nonexistent.json` | Cheap 404 body, not `/node/119` |

---

## 13. Monitoring plan

**Daily for 14 days after any change:**
```bash
terminus env:metrics idaho-legal-aid-services.live --period=day --datapoints=14
```

| Intervention | Metric | Expected | Where |
|---|---|---|---|
| Icons | `/favicon.ico` + apple-touch 404s | → ~0 | Cloudflare edge |
| Icons | Total edge 404s | −60 % | Cloudflare |
| Icons | Counted visits | **no change** (expected) | `env:metrics` |
| **Language fix** | **`cache_hit_ratio`** | **20 % → 85–90 % within 48 h** | `env:metrics` |
| Language fix | Pages served | Falls | `env:metrics` |
| Language fix | Origin PHP requests | Falls sharply | nginx logs |
| Language fix | Counted visits | **no change** (expected) | `env:metrics` |
| www→apex | Origin-bound requests | −6,300/day | Cloudflare |
| SEO rule | SemrushBot requests | → ~0 | Cloudflare |
| SEO rule | **Uptime-monitor requests** | **unchanged — alert if not** | Cloudflare + Better Stack |
| Legacy files | Legacy-prefix 404s | → ~0 | Cloudflare |

**Alert thresholds** (carried forward from audit §17.4, plus one):

| Condition | Action |
|---|---|
| Cache hit ratio does not recover above 60 % within 72 h of the language fix | Re-open §8.9; the mechanism is wrong or incomplete |
| Better Stack or UptimeRobot appears in the SEO rule's match list | **Roll back rule immediately** |
| Cumulative visits > 70 % of plan before day 20 | Re-project |
| 404 share of origin requests > 15 % | New broken-link source |
| Direct-origin requests > 1 % | Bypass reopened |

**Log retention.** Pantheon keeps ~11 days of nginx logs and database backups expire on a
rolling window. Automate a monthly archive (audit §17.3) — the two backups recovered for this
review were close to expiring.

---

## 14. Rollback plan

| # | Change | Rollback | Time | Data loss |
|---|---|---|---|---|
| 1 | Icons | `git revert`, deploy | ~5 min | None |
| 2 | www→apex | Disable the Single Redirect | < 30 s | None — origin redirect still active underneath |
| 3 | DNS | Re-add A `old` → `72.3.167.82`, DNS-only | < 5 min | None |
| 4 | Legacy files | Delete redirect entities; revert content from revision history | ~15 min | None |
| 5 | Canonical | Revert metatag config, deploy | ~5 min | None |
| 6 | SEO rule | Set to Log / delete; re-add category to `64fae5be` | < 30 s | None |
| 9 | Language fix | Restore the one line, export, deploy, clear caches | ~10 min | None |
| 11 | fast_404 | Revert config, deploy | ~5 min | None |

**No proposed change is destructive.** Every one is reversible without data loss. The only
change with a user-visible behavioural consequence that a rollback would *not* undo
retroactively is §8.9 (visitors who were served English during the window).

---

## 15. Information still required from the Pantheon workspace administrator

*Kept separate from the technical recommendations, per the brief. No plan recommendation is made
in this document, and no plan pricing was investigated.*

**Screenshots / values needed:**

1. **Site → Overview → usage panel** — plan name, **billing-cycle start and end dates**, and
   current counted visits, with today's date visible.
2. **Traffic / Metrics**, same date range — Visits and Pages Served.
3. **Top Traffic Patterns** with the **Pages Served filter** applied. (`terminus` does not
   expose this; it is the only way to correlate top paths against *counted* pages.)

**Questions requiring workspace-level access:**

4. Does the **workspace-level billable metric** equal what `terminus env:metrics` reports? The
   dashboard figure of `22,800 of 25,000` and the API's `36,676` for July 1–27 cannot both
   describe the same window.
5. **What are the exact billing-cycle dates?** Until these are known, `22,800` and `36,676` are
   not comparable — they may simply cover different periods.
6. Are there **other sites in the workspace** contributing to a shared allowance?
7. Nine consecutive months show 34k–103k visits against an apparent 25,000 limit. **Why has no
   overage notification been raised?** The answer may reveal that the billable metric differs
   from the API metric.
8. What is Pantheon's **overage policy** for Performance Small — automatic tier upgrade, or
   billed overage?

**One technical question for Pantheon Support** (not billing):

9. **Did anything change in Pantheon's Global CDN configuration or in how `cache_hit_ratio` is
   calculated on or around 2026-07-06?** Every client-side cause has been eliminated (§8.9A):
   identical config, byte-identical code, flat traffic. This is the only remaining hypothesis
   and only Pantheon can confirm it.

**Conclusions that depend on the above:** every statement about plan headroom, overage exposure,
and whether optimisation can bring the site under its limit. **No conclusion in §§1–14 depends
on any of it** — the technical findings stand independently.

---

## 16. Recommended implementation order

| Order | Item | Ref | Effort | Risk | Why this position |
|---|---|---|---|---|---|
| **0** | **Archive the recovered DB backups to durable storage** | §8.9A | 5 min | None | The 2026-06-30 backup **expires 2026-08-01** |
| **1** | **Fix `language-browser` negotiation** | §8.9B | 30 min | Low | **Highest value.** Restores caching sitewide; every later caching decision depends on it |
| 2 | Add icon files + template tags | §8.1 | 1 h | **None** | Removes ~60 % of all 404s; no failure mode |
| 3 | `www`→apex Single Redirect | §8.2 | 15 min | Low | Largest origin-load win; instant rollback |
| 4 | Add `json\|xml` to `fast_404` | §11.4 | 5 min | None | Cheap; rides along with any deploy |
| 5 | Audit GitHub repo vars for the live platform hostname | §8.5 | 15 min | None | Prerequisite for ever reconsidering §8.5 |
| 6 | Fix the dynamic canonical | §8.5 | 30 min | Low | The real defect behind the "canonical leak" |
| 7 | Legacy files — 10 high-confidence rows | §8.4 | 2 h | None | Fix source links *and* add redirects |
| 8 | Legacy files — 10 editorial rows | §8.4 | — | — | **Gated on content-owner review** |
| 9 | SEO-crawler rule in **Log** mode | §8.6 | 15 min | None | Observation only |
| 10 | Review logs → Managed Challenge | §8.6 | 15 min | Low | After ≥ 7 days |
| 11 | Verify `old.` IP not third-party; delete record | §8.3 | 15 min | None | Housekeeping |
| 12 | Re-measure for one full billing cycle | §13 | — | — | Before any further caching work |
| 13 | *Then* re-evaluate `pantheon_advanced_page_cache` | §8.10 | — | — | Only meaningful once pages are cacheable |
| — | ~~Platform-hostname redirect~~ | §8.5 | — | — | **Deferred** — breaks CI, benefit already mitigated |
| — | ~~404 caching / HTML caching~~ | §8.11–12 | — | — | **Deferred** — root-cause fixes come first |

**Items 1–4 are individually reversible in under ten minutes and together address the large
majority of the wasted origin work identified across both documents.**

---

## Appendix — Evidence index for this review

| Claim | Method |
|---|---|
| Interior pages uncacheable | `curl -sI` on 21 paths via the Pantheon platform hostname |
| `/nl/videoteca` cacheable vs `/videoteca` not | Same, paired 404s |
| Kill-switch mechanism | `LanguageNegotiationBrowser.php:63`; `core.services.yml:411-416`; `language.types.yml` |
| No contrib/custom response policy exists | Grep for `page_cache_response_policy` across core/contrib/custom |
| `sbfm_verified_bots: "allow"` | `GET /zones/{zone}/bot_management` |
| Skip rule `64fae5be` expression and phases | `GET /zones/{zone}/rulesets/f887ac01…` |
| Bot categories | `httpRequestsAdaptiveGroups` → `verifiedBotCategory` dimension, 2026-07-26→28 |
| Daily edge series Jul 1–28 | `httpRequestsAdaptiveGroups` per-day, `edgeResponseStatus` + `cacheStatus` |
| Legacy 404 volumes + referrers | `httpRequestsAdaptiveGroups` filtered `clientRequestPath_like`, 7 days |
| Replacement targets for legacy PDFs | `curl -sIL` of `/sites/default/files/` equivalents |
| Redirect table counts | `terminus drush live -- sql:query` (SELECT only) |
| **No redirect created since 2026-02-19** | Same, `GROUP BY DATE(FROM_UNIXTIME(created))` |
| Pre-deploy config state | `terminus backup:get` 2026-06-30 → `config` table extraction |
| Config diff across the deploy | 2026-06-30 vs 2026-07-07 backups |
| Core code identical across artifacts | `git diff`/`md5sum` of `2a80ace07d` vs `f3a5397459` |
| No platform event on Jul 6 | `terminus workflow:list` |
| Theme favicon = Bootstrap logo | `md5sum` vs `web/themes/contrib/bootstrap5/favicon.ico` |
| `protected_web_paths` not blocking | `pantheon.upstream.yml:10-13` |
| Live platform hostname used by CI | `scripts/ci/derive-assistant-url.sh:56`; workflow inputs |
| PAPC status, Big Pipe conflict, Webform issue | drupal.org project + issue queue; `docs.pantheon.io` |
| Pantheon meters at the Global CDN | `docs.pantheon.io/metrics` |
| Primary domain cannot redirect platform domain | `docs.pantheon.io/guides/domains/primary-domain` |

IPv4 is masked and no visitor IP, cookie, token, or PII appears in this document.
