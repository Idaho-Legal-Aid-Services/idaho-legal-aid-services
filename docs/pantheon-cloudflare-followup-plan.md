# Pantheon + Cloudflare — Follow-Up Plan

**Date:** 2026-07-30 · **Supersedes:** the scattered follow-up lists in
`docs/pantheon-cloudflare-preimplementation-validation.md` §15 and
`docs/pantheon-cloudflare-implementation-tracker.md` ("Follow-ups opened by this work", FU-1…FU-23).

This is a disposition plan for what remains, based on the site's state **today**. It is not
another audit and it does not restate settled analysis — each row points at the section that
already holds the reasoning.

**Scope note.** `docs/pantheon-cloudflare-implementation-status.md` does not exist. The equivalent
artifact is `docs/pantheon-cloudflare-implementation-tracker.md`, which is what was reviewed, along
with the validation report and every implementation artifact produced alongside them: the traffic
audit, the browser-spoofing/scripted-access analysis, the three legacy-file artifacts, the four
`docs/evidence/` directories, and the six `scripts/observability/cloudflare-*.sh`.

---

## Current state — measured 2026-07-30, read-only

Six facts changed the disposition of the old list. Everything else in the table follows from them.

| Measurement | Command | Result |
|---|---|---|
| **Cache hit ratio recovered** | `terminus env:metrics idaho-legal-aid-services.live --period=day --datapoints=14` | 2026-07-29 = **80.18 %** (2,193 hits / 2,735 pages) against a ~20 % 14-day baseline and 18.28 % the day before. §13's "above 60 % within 72 h" threshold is **met**. |
| **Zone 404s fell 71 %** | `scripts/observability/cloudflare-404-volume-check.sh` (07-29T05:00Z → 07-31T05:00Z) | **4,564 → 1,306.** `/favicon.ico` 888→92, `/apple-touch-icon.png` 247→105, `-precomposed` 233→33. Residual is the 11.5 h of pre-purge window. |
| **Item 6 is merged but unshipped** | `git log origin/master..HEAD` | **12 commits on GitHub master are not deployed to Pantheon**, including all four canonical-host commits (`e184736c1b`, `572a15a25e`, `9f31fc1172`, `77994d3d3e`). |
| **…and Live confirms it** | `curl` on the Live platform host | `canonical`, `og:url` and all five `hreflang` still emit `live-idaho-legal-aid-services.pantheonsite.io`. |
| **The recovered backups are still only in `/tmp`** | `ls /tmp/claude-1000/*.sql.gz` | `live_2026-06-30.sql.gz` (112 MB) + `live_2026-07-07.sql.gz` (34 MB). The Pantheon-side 06-30 backup **expires 2026-08-01**. |
| **Most of this work is uncommitted** | `git status --porcelain docs/ scripts/` | 3 modified + 12 untracked paths. All of items 7 / 9 / §8.7 / §8.8 exists only in the working tree. |

Two verifications the tracker records as "still pending" are answered by the first two rows: item 1's
72-hour cache-ratio checkpoint, and items 2 & 4's 48-hour 404 comparison. Both are closed below.

---

## Disposition table

| Priority | Item | Current status | Finding | Next action | Owner | Evidence needed | Completion criteria |
|---|---|---|---|---|---|---|---|
| **P1** | Item 0 — archive the two recovered DB backups | Open, never started | Both files sit only in volatile `/tmp`; the Pantheon 06-30 backup expires **2026-08-01**. Once gone, the July 6 pre/post pair cannot be reconstructed. | Copy both to durable storage with checksums; record the location in the tracker | You (choose location) → Claude (copy + verify) | `sha256sum` of both files at source and destination | Both archives readable outside `/tmp` with matching checksums, path recorded |
| **P1** | Commit the uncommitted Cloudflare / legacy work | Not tracked as an item | 3 modified + 12 untracked paths hold the traffic audit, the spoofing analysis, all three legacy-file artifacts, two new scripts and two evidence directories. A `/tmp` clear or a bad `git clean` loses it. | Stage and commit as one docs/evidence commit; publish via `npm run git:publish` | Claude (prepare) → you (publish) | `git status --porcelain` clean for `docs/` and `scripts/` | Work is on GitHub master |
| **P1** | Item 6 — canonical host normalisation | 🟢 in tracker; **actually merged and undeployed** | Code is on GitHub master; Pantheon `origin/master` is 12 commits behind. Live still emits the platform hostname in canonical, `og:url` and hreflang. | Deploy Dev → Test → Live, then re-probe and fill the tracker's empty "Before and after" section | You (deploy gate) → Claude (probe + write-up) | `curl` on Live for `canonical` / `og:url` / 5× `hreflang` per language; platform host still returns 200, not a redirect | All emit `https://idaholegalaid.org`; platform host un-redirected; tracker status → ✅ |
| **P1** | Item 12 — re-measure over a billing cycle | Started today | Day 1 reads **80.18 %**. One datapoint is not a cycle, and the billing-cycle dates are still unknown (see §15 Q5). | Capture `env:metrics` daily into `60-metrics-daily.txt` through day 14 | Claude | 14 consecutive daily readings | 14-day series recorded and compared against the 15.41–28.91 % baseline |
| **P1** | Traffic audit §11.1 — 22,800 (dashboard) vs 36,676 (API) visits | Open since 2026-07-28, untouched | The audit's own stated blocker: the gap decides whether the site is at 91 % or 147 % of plan. No engineering fix since has moved it. This — not any technical item — gates a plan decision. | Send the workspace administrator the §15 ask (screenshots 1–3 + questions 4–8) | **Pantheon workspace administrator** | Site→Overview usage panel with billing-cycle dates and today's date visible; Traffic/Metrics for the same range; Top Traffic Patterns with the Pages Served filter | One reconciled visit figure with known cycle boundaries |
| **P2** | FU-19 — Google UAs inside the SEO verified-bot category | Open; **blocks item 10** | `Googlebot/2.1`, `GoogleAssociationService`, `Google-Adwords-Instant-Mobile` sit in the SEO category, so §8.6's promotion test fails as written (F-14). ~28 requests / 7 days, but it is Google's traffic. | Decide artefact vs genuine; if genuine, add an explicit carve-out to the rule expression | Claude (investigate) → you (accept the carve-out) | Day-7 observation output showing the Google UAs' paths, ASNs and statuses | A written determination plus, if needed, a tested expression |
| **P2** | FU-18 — MJ12bot / Majestic reliance | Open; **blocks item 10** | §8.6's owner confirmation covered Semrush, Ahrefs and Siteimprove only. MJ12bot is the second-largest UA in the category (~490 req / 2 days). | Ask whether ILAS uses Majestic for backlink data | **You / program staff** | A yes/no on record | Answer recorded in the tracker |
| **P2** | Item 10 — promote the SEO rule to Managed Challenge | ⬜ Not started, date-gated | Earliest review **2026-08-05T20:03:44Z**. Triple-blocked by FU-18, FU-19 and FU-20. | Run the day-7 observation on/after that timestamp; **do not promote** until FU-18 and FU-19 are answered | Claude (run) → you (promote) | `cloudflare-seo-bot-observation.sh --days 7 --rule-id b79f504c… --out …/review-day7`; exit 2 on the Google UAs is the signal, not a defect | 7 days of matched UA × path × status data, tripwire clean, and both blockers resolved |
| **P2** | FU-20 — what would the managed WAF block? | Open, structurally unanswerable as configured | The mirror-skip deliberately preserves the managed-WAF skip, so the observation window cannot answer §8.6's question. Only a genuinely enforcing change can. | Decide whether the question is worth an enforcing experiment at all, or close it as not-worth-the-risk | You | — | A recorded decision either way |
| **P2** | Item 13 — `pantheon_advanced_page_cache` | 🟢 assessed, nothing installed anywhere | Confirmed today: no `Surrogate-Key` on any Live response, PAPC absent from `composer.json` and `core.extension.yml`. Editor changes can stay invisible for up to 24 h — the other half of item 1's trade-off. Both §8.10 blockers re-measured smaller; a new cost (62 image styles × every image save) surfaced. | Scope it as its own piece of work on an isolated multidev | You (scope/timing) → Claude (execute) | On a multidev: that tag purge actually fires; real `Surrogate-Key` byte sizes on live-like pages; image-save timing against the 62 styles | Three measurements recorded, then a separate Live go/no-go |
| **P2** | Interim staleness lever — `cache.page.max_age` 86400 → 3600 | Not applied | Caps CDN staleness at one hour with no module and instant revert. Costs cache efficiency; does not fix file/image freshness. A stopgap, not a substitute for item 13. | Decide whether 24 h staleness is tolerable while item 13 waits | You | Current editor-visible staleness complaints, if any | A decision; if yes, one config change deployed |
| **P2** | Item 8 — legacy files, editorial rows | ⏸️ Blocked on humans | 12 rows in `docs/legacy-file-content-owner-review.md`; most need a lawyer, not an engineer. Highest volume is `Process of a Civil Lawsuit Generally.pdf` (109 / 3 days). `MANUFACTURED HOMES.brochure.pdf` had its redirect created then deliberately removed on discovering the target is a 2013 edition. | Route the queue to the named leads | **Content owners + legal** | Per row: is the destination current, and is there Spanish parity | Each row decided: redirect, restore, replace, or leave 404 |
| **P2** | Unmapped legacy tail | Not started | §8.4 mapped the top 22; the current pull shows **184 distinct legacy paths / 1,631 requests over 3 days**, several with more volume than rows §8.4 did map. Flagged highest legal risk: `How to Change Gender on Birth Certificate Guide.pdf`. | Run `ilas_redirect_automation`'s `redirect:analyze` / `redirect:preview`, feed output into the content-owner queue. **Do not blanket-rewrite the prefix.** | Claude (generate) → content owners (decide) | A ranked path list with candidate destinations and volumes | Tail triaged into actionable / editorial / leave-404 |
| **P2** | The 12 `/files/html/` D7 content rows | Queued, untouched | Nodes 52 and 58. These already 301→`/forms`, so they are not 404s — the defect is that the link text promises a document the destination does not deliver. Half are Spanish. On node 52 the "Tenant Guide in English" link points at the page it sits on. | Editorial rewrite of the link text or replacement destinations | **Content owners** | The paragraph IDs and current URIs are already captured in `legacy-file-redirects-8-4-state.json` | Links either resolve to what they promise or are removed |
| **P2** | `courtselfhelp.idaho.gov` notification | Drafted, deliberately unsent | The only government backlink (9 req / 7 days), pointing at `EXECUTION OF JUDGMENTS brochure_May 2020.pdf`. Sending a URL before the file is consolidated hands the State a link that will move again. | Hold until the legal read and file consolidation land, then send | You → **State of Idaho** | Confirmed canonical destination URL | Email sent and the State's link updated |
| **P2** | FU-21 — programmatic partner consumers | Open | 14 days of edge data shows nothing legitimate blocked, but a quarterly or annual consumer falls outside the window. Not a blocker; no skip rule is justified until a real consumer is named. | Ask whether any partner, directory or referral aggregator pulls ILAS sitemaps, feeds or PDFs | **Program staff** | A named consumer, or a clean "none known" | Answer recorded; skip rule added only if a consumer is named |
| **P2** | FU-1 — assistant retrieval language | Open | The widget calls an unprefixed API path, so `/es/` visitors get English resource links regardless of page language. Existing language tests stub the language manager and neither fail on nor catch this. | Make `apiBase` language-aware or pass the page langcode | Claude | A test that fails before the fix | `/es/` page → Spanish resource links, with regression coverage |
| **P2** | FU-3 — flaky `LlmControlConcurrencyTest` | Open | Fails ~1 run in 5 and has already blocked a deploy. An intermittent lost-update indicates a real race in the harness or in `CostControlPolicy`'s state writes. | Diagnose the race and fix the product, not the threshold | Claude | 20 consecutive green runs | 20/20 green |
| **P2** | FU-4 — Test DB has 0 Spanish nodes | Open | 99 on dev, 0 on test. Test cannot validate any multilingual behaviour, which is why item 1's Spanish verification had to run on Dev and Live. | Refresh Test from Live | You (or Claude with approval) | `SELECT COUNT(*) FROM node_field_data WHERE langcode='es'` on test | Non-zero, and `/es` resolves to an aliased Spanish page |
| **P2** | FU-11 — env-mismatch guard regex | Open; supersedes FU-5 | `promptfoo-evals/lib/gate-target.js:11` recognises only `{env}-{slug}.pantheonsite.io`, so an apex or Cloudflare-fronted target yields `pantheonEnv=''` → `not_applicable`, and `resolve-assistant-target.sh:107` never fires. | Generalise the regex; this also settles which host `ILAS_ASSISTANT_URL` holds without printing it | Claude | Gate run showing a correct `target_source` / classification for an apex target | Guard fires on a deliberate env mismatch |
| **P2** | FU-12 — inconsistent fail-closed guards | Open | `a11y-gate` and `assistant-nightly-quality` warn-and-skip on a missing base URL while three other jobs hard-fail, so removing a secret silently disables the first group. | Make all base-URL guards fail closed | Claude | CI run with the variable unset | All affected jobs fail rather than skip |
| **P2** | FU-10 — `ILAS_PLAYWRIGHT_BASE_URL` | Open | Unset. `assistant-playwright.yml` is its only consumer, so its 08:23 UTC cron has warned and skipped every day rather than testing anything. | Set it or retire the workflow | You (decide) → Claude | A cron run that executes tests, or the workflow removed | No daily no-op run remains |
| **P2** | FU-9 — `ilas_site_assistant.settings` key-order drift | Open, pre-existing | Values are provably identical; only top-level key order differs, so `config:status` reports `Different` on every environment and **masks real drift**. | Fix once, deliberately, outside any feature deploy | Claude | `drush config:status` on dev/test/live | Clean on all three |
| **P2** | §15 Q9 — Pantheon Support, the July 6 change | Open | The symptom is gone (80.18 %), but the cause was never explained: config, code and traffic were all proven identical across 2026-07-06. A Pantheon-side CDN or measurement change is the only surviving hypothesis. | Decide whether to file it now that the symptom is resolved; if yes, open a ticket | You → **Pantheon Support** | The §8.9A elimination table | A Pantheon answer, or a recorded decision not to ask |
| **P1** | Multiply-encoded 404s — the canonical encoding loop | **Investigated 2026-07-30; fixed and verified locally, not deployed** | Far larger than the 48-hour sample suggested: **28,455 requests in July, 21,904 of them reaching origin PHP as 404s** (~12 % of all origin traffic), 382 MB egress, six origin 500s, 21,831 from SemrushBot alone. Our own 404s advertised a canonical one percent-encoding level deeper than the URL requested, so following it produced a deeper one forever. Root cause: `drupal/token` 8.x-1.17 `TokenTokensHooks.php:481`. Running since at least 2025-12-12 and hidden by the `/*%252*` glob in `redirect_404.settings.yml`. | Deploy Dev → Test → Live with the rest of the pending work, then re-measure | Claude (done) → you (deploy) | Tracker item 14 and `docs/evidence/canonical-404-encoding-loop/`; post-deploy, the three-step reproduction on each environment | The multiply-encoded count in `cloudflare-404-volume-check.sh` trends to 0 and the per-cycle minimum depth stops climbing |
| **Closed** | `/cdn-cgi/content` — 431 × 404 | **Investigated 2026-07-30; not a site defect** | 448 of 478 were one `AliyunSecBot` client from Alibaba HK, and every row carries `originResponseStatus: 0` — `/cdn-cgi/*` is reserved by Cloudflare, answered at the edge, and never reaches Pantheon. The defect was in the measurement. | Done: `cloudflare-404-volume-check.sh` now excludes `/cdn-cgi/*` and says why | Claude | `docs/evidence/canonical-404-encoding-loop/40-cdn-cgi-content.txt` | — |
| **P3** | FU-2 — tell program staff about the Spanish auto-switch | Open | Browser-based auto-switching to Spanish is gone; `/es` and the language switcher are unchanged. An accepted trade-off, but user-facing. | Send a short note to program staff | You | — | Staff notified |
| **P3** | FU-6 — GSC / GA4 `www` property | Open | `www` no longer reaches the origin at all, so any `www` property's hostname dimension goes quiet. | Check whether a `www` property is registered | You | GSC / GA4 property list | Confirmed, and consolidated if one exists |
| **P3** | FU-7 — Cloudflare token memory note is wrong | Open | Recorded as read-only, expiring 2026-07-18. It is actually active with no expiry and holds **edit** on DNS, Dynamic Redirect, Cache Purge and Zone WAF. | Correct the memory note | Claude | `GET /user/tokens/verify` + observed successful writes | Note matches reality |
| **P3** | FU-8 — Edge TTL for icon paths | Open, low priority | Pantheon sends `no-cache, must-revalidate` on `/favicon.ico` and `/apple-touch-icon*.png`, so every visit revalidates. The paths are 200s and Cloudflare caches them anyway. | Add a Cloudflare Cache Rule with a real Edge TTL | You (approve) → Claude | `cf-cache-status: HIT` with a climbing `age` | Revalidation replaced by a true edge hit |
| **P3** | FU-13 — three wrong hostname spellings | Open | `idaholegalaid` in `quality-gate.yml:24` and `run-quality-gate.sh:19,406`; `idaho-legal-aid` in `fingerprint-smoke.sh:14`. Doc-only, but they 404 if copy-pasted. | Correct all three to `idaho-legal-aid-services` | Claude | `grep` showing no remaining variants | Zero matches |
| **P3** | FU-14 — `MetatagCanonicalConfigTest` globs | Open | `views.view.forms_categories.yml:486` and `views.view.guides_categories.yml:486` carry embedded `metatag_views` canonical values no test guards. | Extend the globs to `config/views.view.*.yml` | Claude | Test fails on a simulated regression | Guard proven to fail, then pass |
| **P3** | FU-17 — document the two-layer purge | Open | `cloudflare-purge-urls.sh`'s header explains the Cloudflare 24 h problem but not that Pantheon's Fastly layer caches the same response **upstream**. A Cloudflare-only purge leaves a stale 404 in place — learned the hard way during item 7. | Add the ordering rule to the script header: `terminus env:clear-cache` **first**, then the Cloudflare purge | Claude | — | Header states the ordering |
| **P3** | FU-22 — `Archiver` / `Aggregator` bot categories | Open, low priority | `archive.org_bot` (116 × 200) and `Pinterestbot` (126 × 200) reach content today but are not in the retained skip-rule category list, so nothing guarantees that continues. | Decide whether to add them to `64fae5be` | You | Current 200 counts (already captured) | A recorded decision |
| **P3** | FU-23 — ~13,780 empty-user-agent requests | Open | Deliberately out of scope for §8.7, which is about *spoofed browser* UAs. An absent UA is a different question and was never analysed. | Characterise them: paths, ASNs, statuses | Claude | A GraphQL breakdown over 14 days | Either classified as benign or escalated with evidence |
| **P3** | `/site.webmanifest` MIME | New | Serves `content-type: text/plain`; `/manifest.json` correctly serves `application/json`. Previously checked and judged unfixable from the repo (Pantheon nginx ignores `.htaccess`) — a Cloudflare Transform Rule is the remaining lever. | Set the header via a Cloudflare response Transform Rule, or accept and document | Claude | `curl -I` showing `application/manifest+json` | Correct MIME, or a recorded decision to leave it |
| **P3** | `edge-cache-sample.sh:51` uses `GET` not `HEAD` | New | Pulls a full homepage body twice per run. Also the repo's only hardcoded live-host HTTP call, which is why it appeared in the item 5 audit. | Switch to `HEAD`; both root files are untracked and should be committed or removed | Claude | — | Script uses `HEAD`; file's git status resolved |
| **P3** | §8.3's subdomain enumeration is stale | Open (F-10) | `laci.idaholegalaid.org` was added 2026-07-22 and is not mentioned; §8.3 lists only `www.`, `mail.`, `old.`. | Add it to the record | Claude | `31-dns-after.json` already has it | Enumeration matches current DNS |
| **P3** | Adopt a monthly spoofing re-check | Not scheduled | The §8.7 analysis recommends a monthly cadence, or after any Cloudflare bot-management change. As a one-off it will silently go stale. | Schedule `cloudflare-browser-spoofing-analysis.sh --days 14` monthly | Claude | Dated output per run | A recurring run exists with its thresholds documented |
| **Deferred** | Cloudflare HTML caching (§8.12) | 🔵 Deferred | No invalidation mechanism exists, and the assistant bootstrap mints a 7-day `SSESS` cookie scoped to `.idaholegalaid.org` for anonymous visitors. | Revisit only if origin load is demonstrably still a problem now §8.9 is fixed — it is not | — | Origin load evidence | — |
| **Deferred** | Broad 404 caching as §8.11 framed it | 🔵 Deferred | Note the F-5 correction: the origin already declares **tag-backed** cacheable 4xx, which is a different and safe mechanism from §8.11's rejected "Edge TTL, ignore cache-control" rule. Measured exposure: static-extension 404s cached, HTML/page 404s not cached at all. | Nothing. The residual is edge cache-tag visibility, which is item 13's problem | — | — | — |
| **Deferred** | Platform-hostname redirect (§8.5) | 🔵 Deferred | Breaks CI — promptfoo POSTs to that hostname and a 301 downgrades a POST to GET with the body dropped. The SEO benefit is already covered by Pantheon's `Disallow: /`, and item 6 addresses the canonical leak directly. | Nothing | — | — | — |
| **Deferred** | Browser-spoofing enforcement rule (§8.7) | 🔵 Deferred with written re-open triggers | 99.2 % of the population is already actioned; only **42 requests in 14 days** reach content unchallenged. The mandatory JA4 gate is unmeasurable on a Business zone, and Log mode is Enterprise-only, so any rule would enforce from creation. | Re-open only on: Enterprise Bot Management; unchallenged-200 share above 2 %; or a traced origin/cache regression | — | Monthly re-check output | — |
| **Closed** | Items 1, 2, 3, 4, 5, 7, 11 | ✅ Done and verified on Live | Language negotiation, icons, `www`→apex, `fast_404`, the GitHub var audit, the eight high-confidence redirects, and the `old.` DNS deletion. | — | — | Already in the tracker | — |
| **Closed** | Item 1's 72-hour cache checkpoint | ✅ Passed | §13 said re-open §8.9 if the ratio had not exceeded 60 % within 72 h. Measured today: **80.18 %**. The mechanism was right. | — | — | `env:metrics` 2026-07-29 row | — |
| **Closed** | Items 2 & 4's 48-hour 404 comparison | ✅ Run | Zone 404s **4,564 → 1,306 (−71 %)**, close to the tracker's own "roughly a third" projection and well past §8.1's discredited 60 % claim. | — | — | `cloudflare-404-volume-check.sh` output | — |
| **Closed** | FU-15, FU-16 | ✅ Done 2026-07-29 | Both cache purges. FU-16 also produced the two-layer purge discovery now carried as FU-17. | — | — | — | — |
| **Closed** | §8.7 / §8.8 analysis | ✅ Complete, no rule proposed | Not deferred and not pending data — the analysis ran to completion and concluded no action. Re-open triggers are written. | — | — | — | — |
| **Closed** | FU-5 | Superseded | Generalised by FU-11, which fixes the class of problem rather than confirming one secret. | — | — | — | — |
| **Closed** | DMARC `rua` gap | ✅ Resolved | The record now reads `p=none; rua=mailto:…@dmarc-reports.cloudflare.net`. Long-standing; closed by the DNS work. | — | — | `34-dns-post-delete-verification.txt` | — |
| **Closed** | "Remove Search Engine Optimization from skip rule `64fae5be`" as §8.6 specified | ❌ Rejected | `sbfm_verified_bots: "allow"` means the stated mechanism cannot work. Re-confirmed live 2026-07-29. Replaced by item 9's mirror-skip. | — | — | `04-bot-management-before.json` | — |

---

## Next five actions, in order

1. **Archive the two recovered DB backups out of `/tmp`.** Hard deadline 2026-08-01 — after that the
   06-30 Pantheon backup is gone and the July 6 pre/post pair cannot be reconstructed.
2. **Commit the 15 uncommitted Cloudflare / legacy paths.** Everything from items 7, 9, §8.7 and §8.8
   currently exists only in the working tree.
3. **Deploy the pending commits and close items 6 and 14.** Re-probe canonical, `og:url` and hreflang
   on Live, fill the tracker's empty "Before and after" section, and re-run the item 14 reproduction
   on each environment. Item 14 is the single largest origin-load win still unshipped.
4. **Send the workspace administrator the §15 + §11.1 ask.** Longest lead time of anything here, and
   the only thing that settles whether the site is at 91 % or 147 % of plan.
5. **Capture `env:metrics` daily to day 14**, then run the day-7 SEO observation on/after
   2026-08-05T20:03Z — resolving FU-18 and FU-19 **before** any promotion decision on item 10, and
   re-measuring SemrushBot volume *after* item 14 lands, since most of what would have justified
   enforcement was traffic we were generating ourselves.

## Claude can implement now

Backup archival · the commit · the daily metrics series · the day-7 observation run · FU-7, FU-9,
FU-13, FU-14, FU-17 · the `site.webmanifest` MIME rule · `edge-cache-sample.sh`'s `GET`→`HEAD` ·
the stale subdomain enumeration · FU-11 and FU-12 · FU-1 · FU-3 · the legacy-tail `redirect:analyze`
pass · the monthly spoofing re-check schedule. (Item 14 and the `/cdn-cgi/content` disposition are
**done**, pending deploy.)

## Requires your decision

Where the backups live · when to deploy · item 13's scope and timing · whether to cap
`cache.page.max_age` at 3600 as the interim staleness lever · whether to refresh the Test DB now
(FU-4) · FU-2 and FU-6 communications · FU-8 · FU-10 (set or retire) · FU-20 (whether the
managed-WAF question is worth an enforcing experiment) · FU-22 · whether §15 Q9 is still worth
raising with Pantheon Support now the symptom is gone.

## Requires an outside person

- **Pantheon workspace administrator** — §15 questions 1–8 and the audit's §11.1 visit-count
  discrepancy. Confirmed today that the operating account is neither the site owner (`15212c00…`)
  nor a member of any organization, and `site:team:list` returns nothing. This cannot be worked
  around from the CLI.
- **Pantheon Support** — §15 Q9, the July 6 Global CDN / `cache_hit_ratio` question.
- **Content owners and legal** — item 8's 12 queue rows, the 12 `/files/html/` content rows, and
  the legal-currency read on `MANUFACTURED HOMES.brochure.pdf`.
- **State of Idaho** — the `courtselfhelp.idaho.gov` notification, after the legal read.
- **Program staff** — FU-18 (MJ12bot / Majestic) and FU-21 (programmatic partner consumers).

## Should remain deferred

- **Cloudflare HTML caching** — no invalidation mechanism, and anonymous visitors carry a 7-day
  `SSESS` cookie.
- **Broad 404 caching as §8.11 framed it** — the origin's tag-backed cacheable 4xx is a different,
  safe mechanism; the real residual belongs to item 13.
- **The platform-hostname redirect** — breaks the promptfoo CI gate; item 6 fixes the actual defect.
- **A browser-spoofing enforcement rule** — 42 unchallenged requests in 14 days against a
  false-positive cost measured in people who do not get legal help. Re-open triggers are written.

## Earlier recommendations now obsolete

| Recommendation | Why it is obsolete |
|---|---|
| §11.5 — replace the canonical token with one resolving to the configured base URL | **No such token exists** in Drupal 11 or Metatag. Replaced by `web/modules/custom/ilas_seo/src/CanonicalHost.php`. |
| §8.6 — remove the SEO category from skip rule `64fae5be` | `sbfm_verified_bots: "allow"` defeats the mechanism. Replaced by item 9's mirror-skip. |
| §10.2 — create the rule in **Log** mode for ≥7 days | Log is Enterprise-only; this zone is Business. |
| §8.7 — the JA4 and bot-score gates | Six required dimensions are denied to this zone. The gate cannot be evaluated, so no rule can clear it. |
| §8.11 — "this site's 404s send `must-revalidate, no-cache, private`" | Stale since item 1. The recommendation survives; the premise does not. |
| The proposed `ilas_seo` 4xx `Cache-Control` rewrite | Withdrawn — it would have partially reverted item 1, the highest-value change. |
| §8.4 — "Linking page: our site" | A whole-DB scan of 1,660 columns found **zero** current content containing `sites/idaholegalaid.org/files`. There was never a source link to fix. |
| §8.4 — the "10 high-confidence + 2 leave-404" row counts | Actually 12 High rows: 9 actionable + 3 leave-404, one of which was withdrawn on verification. |
| §8.4's 7-day 404 figures as a monitoring baseline | Most of that volume is now answered by the `www` 301 or a WAF 403. Use the 3-day floor recorded in the content-owner queue. |
| §8.1 — icons are ~60 % of zone 404s | Measured 30 %. The realised drop was 71 % of the zone total, but for a wider set of reasons. |
| §8.10's two PAPC blockers as stated | Both re-measured materially smaller; and it missed the everyday cost — 62 image styles × every image save. |
| Audit §8.2 — "not a cacheability regression, a change in traffic mix" | Refuted in §8.9A. Traffic mix did not change. |
| Audit §8.1 — "the July 6 window is unrecoverable" | Refuted. Both database backups were recovered — which is precisely why item 0 is time-critical. |
| Audit — "~14.7 % of counted visits are browser-looking automation" | **Not reproduced** by the 14-day analysis. Treat as unverified. |
