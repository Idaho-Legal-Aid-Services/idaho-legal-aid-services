# Browser-spoofing and scripted-access analysis

**Scope.** The prerequisite analysis demanded by §8.7 (browser-spoofing automated traffic)
and §8.8 (legitimate scripted and non-browser access) of
`docs/pantheon-cloudflare-preimplementation-validation.md`.

**Zone.** `idaholegalaid.org` — `7aef3c4adc977c9f645472338b031450`, **Business Website** plan.

**Window.** `2026-07-15T23:33:00Z` → `2026-07-29T23:33:00Z` — 14 days, 491,004 requests.
Raw per-request sub-window: trailing 3 days.

**Author / date.** Evan Curry, 2026-07-29.

**Collector.** `scripts/observability/cloudflare-browser-spoofing-analysis.sh`
**Evidence.** `docs/evidence/cf-browser-spoofing/browser-spoofing-analysis.json`

**No production security control was modified.** Every Cloudflare call in this analysis is a
GraphQL analytics read. No ruleset was created, updated, or reordered; no bot-management or
zone setting was changed; no rule was staged, scheduled, or deployed in any mode.

---

## Bottom line

| | |
|---|---|
| **Recommendation** | **Take no action.** Do not create a rule — not even an observation rule. |
| **Is there a genuine automated population?** | Yes. Identified with high confidence. |
| **Does it meet the report's decision standard?** | **No — and it cannot on this plan.** Two of the four mandatory gates are unmeasurable or unavailable. |
| **Is it currently harming the site?** | No. **99.2 % of it is already challenged, blocked, or absorbed** by controls that exist today. |
| **What changes if we do nothing?** | Nothing. A new rule would add false-positive risk to vulnerable users without adding coverage. |

The honest finding is not "no automation exists" — it plainly does. The finding is that
**enforcement has already happened**, by controls deployed for other reasons, and the
marginal value of a new rule is close to zero while its marginal risk to library patrons,
shelter networks, and accessibility users is not.

---

## 1. Method and measurement limits

### 1.1 What this plan will not tell us

§8.7 specifies the signals that must be measured. Six of them are **denied to this zone**.
The GraphQL schema advertises the dimensions; the API returns
`zone "7aef3c4adc977c9f645472338b031450" does not have access to the field '<name>'` because
they are Enterprise Bot Management features:

| §8.7 required signal | Dimension | Status |
|---|---|---|
| Bot score (a) | `botScore`, `botScoreBucketBy10` | **DENIED** |
| **TLS fingerprint (b)** | **`ja4`, `ja3Hash`** | **DENIED** |
| JS execution (f) | `jsDetectionPassed`, `botDetectionTags` | **DENIED** |

Substitutions used. Each is named at every point it is quoted, so no number in this document
silently stands in for a signal it is not:

| Signal | Substitute | Quality |
|---|---|---|
| Bot score 1–99 | `botManagementDecision` — Cloudflare's own five-bucket classification (`likely_human` / `likely_automated` / `automated` / `verified_bot` / `other`) of the same underlying score | **Good.** Same model, coarser output. Defensible for a ≥95 % threshold. |
| JS execution | `/cdn-cgi/rum` beacon counts per population, plus `botScoreSrcName = js_fingerprinting` | **Adequate.** Beacon absence is meaningful at population scale; per-request attribution is lost. |
| **JA4 / TLS fingerprint** | **None. No substitute exists.** | **Unmeasurable.** |
| Cookie behaviour | `cacheStatus` `dynamic`/`bypass` (a cookie-bearing request bypasses cache) | **Weak.** Reported, never relied on. |

Two further constraints, both measured against this zone rather than assumed:

- **`httpRequestsAdaptiveGroups` max span is 4w4d.** A 14-day window is one query. Retention
  is not the limiting factor.
- **`firewallEventsAdaptive` caps at a 3-day span** on this plan and must be chunked.
- Counts are from the **adaptive (sampled)** datasets. `avg.sampleInterval` and `sum.visits`
  are recorded next to every total in the JSON.

### 1.2 Consequence for the decision standard

The report's decision standard has four conditions. Their status here:

| Condition | Status |
|---|---|
| ≥ 95 % low bot-score | ✅ **Met** — 99.2 %, via the documented `botManagementDecision` proxy |
| Inconsistent with a genuine browser JA4 | ⛔ **Unmeasurable on this plan.** Not met, not refuted. |
| Concentrated in hosting-provider networks or similarly strong automation signals | ✅ **Met**, and by several independent signals |
| Free of known legitimate dependencies | ⚠️ **Substantially met, not cleanly** — see §3.1 |

And the report's process requirement — *"must begin in Log mode for at least 14 days"* — is
**not satisfiable**: the Cloudflare **Log action is Enterprise-only**, already established
for §8.6 and documented in the header of
`scripts/observability/cloudflare-seo-bot-observation.sh`.

This is the crux. One mandatory evidentiary gate cannot be evaluated and one mandatory
process gate cannot be executed. The report wrote those gates deliberately. Substituting
weaker signals and shipping a rule anyway would defeat the point of having written them.

### 1.3 How candidate populations were chosen

Three criteria: a **browser-claiming user agent**, a **volume floor** (default 5,000 requests
in the window), and an **automated-bucket share** at or above the threshold (default 95 %,
matching the report's own standard).

**Browser-version staleness is deliberately not a criterion.** §8.7 is explicit that a stale
Chrome build can equally be a corporate fleet on a pinned version. To make that concrete
rather than merely stated, the second-most-suspicious stale-version population
(`Chrome/120.0.0.0`) was **forced into the report** via `--ua-like` so it appears and is
visibly cleared — see §3.1. It would not have been selected on the criteria above.

---

## 2. Confirmed automated populations

### 2.1 The primary population

```
Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko)
  Chrome/142.0.0.0 Safari/537.36
```

**41,000 requests / 14 days — 8.4 % of all zone traffic.** The single largest
browser-claiming population on the zone after current-version Chrome on Windows.

All sixteen signals the task named:

| # | Signal | Measurement | Reading |
|---:|---|---|---|
| 1 | **Bot score** | `automated` 21,835 + `likely_automated` 18,853 = **99.2 %**. `likely_human` = **4 requests (0.01 %)** | Automated. Clears 95 % by a wide margin. |
| 2 | **JA4 / TLS fingerprint** | **Not available on this plan** | ⛔ Cannot be evaluated |
| 3 | **ASN / network ownership** | See the table below — hosting providers and named commercial proxy vendors dominate | Datacamp, M247, GTT, HostRoyale, Servers.com, Ace Data Centers, Latitude.sh are hosting/transit. Oxylabs sells residential-proxy access as a product. |
| 4 | **Country** | Spread across US, CA, PL, SG, ES, BR, PT, GB — with **`clientCountryName = US` on requests egressing from European hosting ASNs** | Geo is proxy-exit geography, not user geography. Country carries no signal here. |
| 5 | **User agent** | Single fixed string, byte-identical across 41,000 requests and 15 days | No UA entropy. A real macOS Chrome population drifts across minor versions and builds. |
| 6 | **Browser-version plausibility** | Chrome/142 against Chrome/150 current | **Weak signal, and not relied on.** See §5.2. |
| 7 | **Request timing / regularity** | 322 distinct hourly buckets of a possible 336 (14 × 24); present in essentially every hour of the window. **Per-IP inter-arrival intervals could not be computed** — see note below | Continuous, not diurnal. No overnight trough — inconsistent with a US-audience human population. But "cron-like vs bursty" was *not* established. |
| 8 | **Path diversity** | 391 distinct paths, but **`/search` alone is 18,585 requests (45.3 %)** | Crawl, not browsing. Concentrated on the one endpoint that generates unbounded URL space. |
| 9 | **Navigation order** | **`Referer` present on 0 % of requests** | No navigation. Every request is an isolated entry. A browser following links sends `Referer`. |
| 10 | **Cookie behaviour** | `cacheStatus`: `none` 40,994, `dynamic` 6 *(weak proxy)* | Consistent with no session cookie, but this proxy is confounded by challenge short-circuiting. **Not relied on.** |
| 11 | **`/robots.txt` access** | **0 fetches** in 14 days | Does not consult crawl policy. |
| 12 | **`/cdn-cgi/rum` execution** | **0 beacons** — against 31,145 on the zone overall | **No JavaScript execution anywhere in the population.** |
| 13 | **HTML-to-asset ratio** | html 40,955 : assets 16 → **≈ 2,560 : 1** | A real browser inverts this. 16 asset fetches across 41,000 page loads is not a cache effect; it is the absence of a rendering engine. |
| 14 | **Status-code distribution** | 403 × 40,683 · 301 × 263 · 200 × 42 · 302 × 7 · 404 × 5 | See §2.2 — the 403s are our own controls. |
| 15 | **Persistence across days** | **15 / 15 days.** Daily: 29 → 2,228 → 591 → 1,718 → 2,353 → 1,914 → 2,648 → 1,857 → 1,872 → 2,607 → 5,409 → 6,188 → 3,747 → 4,537 → 3,302 | Persistent and **growing** — a campaign, not a burst. |
| 16 | **Query-string behaviour** | Stacked facet parameters `f[0]`…`f[8]`, plus a pathological nested `amp;amp%3Bamp%3B…page` chain 26 levels deep | Facet-crawler signature. The nested `amp;` chain is a parser artifact no browser produces. |

**Network ownership in full** (aggregated by ASN, top 12 of the population's 41,000 requests):

| ASN | Requests | Network | Type |
|---|---:|---|---|
| AS212238 | 6,889 | Datacamp Limited | Hosting / CDN77 |
| AS9009 | 4,851 | M247 Europe SRL | Hosting / VPN egress |
| AS3257 | 4,271 | GTT Communications | Transit |
| AS210906 | 2,577 | UAB Bite Lietuva | Hosting |
| **AS22773** | **2,454** | **Cox Communications** | **Consumer ISP — see §3.1** |
| AS203020 | 2,234 | HostRoyale Technologies | Hosting |
| AS62874 | 1,896 | Web2Objects LLC | Hosting |
| AS7979 | 1,650 | Servers.com | Hosting |
| AS11798 | 1,507 | Ace Data Centers | Hosting |
| AS396356 | 1,475 | Latitude.sh | Hosting |
| AS398781 | 1,359 | OCULUS NETWORKS INC | Hosting |
| AS46635 | 1,358 | Contact Consumers | Hosting |

Eleven of the top twelve are hosting, transit, or proxy-vendor networks. Cox is the sole
consumer ISP and is treated as ambiguous in §3.1 rather than counted as automation.

Two further signals not on the list, both load-bearing:

- **Score source is `heuristics` for 40,678 of 41,000 requests** — not `machine_learning`
  (14). Cloudflare is not making a marginal ML judgement call here; a deterministic
  heuristic is firing. This raises confidence in signal 1 rather than merely restating it.
- **The population is spread across at least 5,000 distinct IPs** (the query cap), with a
  **median of 2 requests per IP**, a maximum of 11, and 1,404 IPs making exactly one request.
  No IP reached 5 requests within the 3-day raw sub-window.

That last measurement matters three times over. It confirms a rotating proxy pool — 41,000
requests from a fixed UA spread that thinly across that many addresses is not a human
population. It **independently vindicates §8.7's warning against rate limiting keyed on
`ip.src`**: against this traffic such a rule would be not merely risky but useless.

And it is why **signal 7 is incomplete.** §8.7 asks for "request regularity (cron-like
intervals vs human bursts)". Inter-arrival intervals require repeat requests from the same
client, and **no single IP in this population reached 5 requests within the 3-day raw
sub-window**. So the cron-vs-bursty question is **not answered for this population** — the
timing evidence here is hourly-bucket coverage and the IP-spread distribution, not
inter-arrival regularity. By contrast, the Chrome/120 population in §3.2 *did* have IPs with
enough volume to measure, and showed median gaps of 0 s with a coefficient of variation
around 5 — bursty, not scheduled.

### 2.2 It is already mitigated

| Outcome | Requests | Share |
|---|---:|---:|
| `403` — `link_maze_injected` (AI Labyrinth, live since 2026-07-02) | 20,510 | 50.0 % |
| `403` — `managed_challenge` (`firewallManaged`, SBFM) | 18,848 | 46.0 % |
| `403` — `block` (`firewallManaged`) | 1,325 | 3.2 % |
| **Total already actioned** | **40,683** | **99.2 %** |
| `301` redirect (unchallenged) | 263 | 0.6 % |
| `200` served (unchallenged) | 42 | 0.1 % |

**42 requests in 14 days reach content unchallenged** — three per day. This is the single
most important number in this analysis. Existing controls, deployed for other reasons, are
absorbing this population essentially completely.

### 2.3 Self-identifying automation (no analysis needed)

Already handled and correctly so — recorded for completeness, not as candidates:
`curl/8.7.1` (6,832), `axios`, `python-requests`, `Go-http-client/1.1`. These present no
browser TLS fingerprint and receive 403. Working as designed; no breakage reported.

---

## 3. Ambiguous populations

**None of the populations in this section should be subject to enforcement.** They are
recorded because §8.7 requires that ambiguity be stated rather than resolved by assumption.

### 3.1 Inside the primary population — the part that is not clean

Two findings prevent the primary population from being called "free of known legitimate
dependencies" without qualification:

- **`likely_human` = 4 requests.** Small, but not zero.
- **Cox Communications (AS22773) — 2,454 requests**, all in the `automated` /
  `likely_automated` buckets.
  Cox is a **consumer ISP**, not a hosting provider. The most likely explanation is
  residential-proxy egress, which is consistent with the rest of the evidence. But
  "residential proxy" and "real household on a shared connection" are the same network
  from the edge's point of view, and this zone cannot tell them apart without JA4.

For a legal-aid site this is exactly the population where being wrong is most costly. It
does not overturn the automation finding; it does mean a challenge rule would not have been
risk-free.

### 3.2 `Chrome/120.0.0.0` — the stale-version population that is *not* automation

Forced into the report deliberately (§1.3). **15,845 requests** across OS variants — and it
fails the standard on nearly every axis:

| Signal | Measurement | Reading |
|---|---|---|
| Bot decision | `automated` 10,157 / **`likely_human` 3,167 (20 %)** / `likely_automated` 1,940 | **76.3 % — below the 95 % gate** |
| ASN | **Microsoft Corporation: 8,246 requests** across US, BR, CA, NZ, SG, NO | Azure. Both a hosting provider *and* the egress for Microsoft 365 / Defender link scanning. |
| `/robots.txt` | **34 fetches** | Consults crawl policy |
| `/cdn-cgi/rum` | **30 beacons** | **Some of this population executes JavaScript** |
| HTML : assets | 15,524 : 175 → 89 : 1 | Elevated, but 30× lower than the primary population |
| `Referer` present | 6.3 % | Some navigation |
| Cache | `dynamic` 2,750 (17 %) | Some cookie-bearing traffic |
| Also present | `likely_human` from VNPT (345), Zenlayer (612) — consumer and edge ISPs | |
| Paths | `/` 2,190 · `/favicon.ico` 139 · then `/wk/index.php`, `/edit.php`, `/wp-admin/css/colors/ectoplasm/`, `/atomlib.php`, `/file.php` | **At least two different things share this UA** |

This population is a **mixture**: WordPress vulnerability scanning from rented Azure VMs,
plus something that fetches `robots.txt`, executes JS, and sends cookies. Microsoft ASN
egress is where Defender/Office link scanning comes from — §8.7 names email link scanners
explicitly as protected — but the WordPress-probe paths are equally clearly hostile scanning.
**This document does not claim to know the split, and that is the finding.** Any rule keyed
on this UA would hit both.

This is the concrete demonstration that **stale browser version is not evidence of
automation.** Chrome/120 is *more* stale than Chrome/142 and *less* automated.

### 3.3 Other ambiguous populations

| Population | Requests | Automated share | Reading |
|---|---:|---:|---|
| Chrome/150 Windows (current) | 41,334 | 6.6 % | Largest UA on the zone; overwhelmingly human |
| Chrome/150 Android | 37,523 | 1.4 % | Human |
| Safari 26.x iPhone | 29,704 | 0.1 % | Human |
| Chrome/150 Windows (variant) | 17,569 | 3.5 % | Human |
| Chrome/149 Linux | 8,388 | 27.4 % | Mixed. Linux desktop Chrome is a small real population **and** a common automation default. Below the gate. |
| Chrome/148 Linux | 6,532 | 32.0 % | As above |
| Chrome/149 Windows | 5,609 | 30.7 % | As above |
| **Empty user agent** | ~13,780 | — | Not analysed as a candidate: an absent UA is not a *spoofed browser*, so it is out of §8.7's scope. Flagged for separate review. |

---

## 4. Protected populations

§8.7 names eleven categories that must not be caught. For each: whether it exists on this
zone, what the edge measured, and whether the evidence separates it from automation.

The load-bearing fact for most rows: **these populations are separable by verified-bot
status, not by user agent.** Several present ordinary browser UAs — the `Security` category
includes current-Chrome-on-Windows UAs (1,840 and 1,301 requests) that are indistinguishable
from a real browser by UA alone. Skip rule `64fae5be` retains the categories
`Search Engine Crawler`, `Search Engine Optimization`, `Page Preview`,
`Monitoring & Analytics`, `Accessibility`, `Security`, `Webhooks`.

| Protected population | Present? | Evidence in this window | Would a UA/behaviour rule misclassify it? |
|---|---|---|---|
| **Shared networks / NAT egress** | Assumed yes | Not directly identifiable at the edge — shared egress looks like one IP with many users | **Yes.** This is why no rule may key on `ip.src`. §2.1 signal 3 shows per-IP rate limiting would also be ineffective against the real target. |
| **Libraries** | Assumed yes | Not separable from consumer ISP traffic | **Yes.** A library terminal with a pinned browser build and cleared cache produces the HTML-heavy, cookie-light pattern §8.7 warns about. |
| **Domestic-violence shelters** | Assumed yes | Not separable; would appear as shared NAT | **Yes**, and with the highest cost of error on the site. Weighs directly against any challenge action. |
| **Accessibility users / tooling** | **Yes — measured** | `Accessibility` category: **900 requests**, chiefly one Android `Chrome/138.0.0.0` UA (890) | **Yes** — it presents a stale-version mobile Chrome UA. Protected only by verified-bot status. A version-based rule would hit it. |
| **Corporate proxies** | **Yes — measured** | The Microsoft/Azure population in §3.2; `GoogleImageProxy` (44) under `Page Preview` | **Yes.** Corporate proxy egress is hosting-ASN egress. ASN concentration alone cannot distinguish them. |
| **Privacy browsers** | Assumed yes | Not directly identifiable — by design | **Yes.** A browser blocking analytics produces **zero `/cdn-cgi/rum` beacons** — the same reading as signal 12 in §2.1. This is why beacon absence must never be a sole criterion. |
| **Email security scanners** | **Yes — measured** | `Security` category: **3,521 requests**, including `Mozilla/4.0 (compatible; ms-office; MSOffice 16)` (53) and current-Chrome Windows UAs (1,840 + 1,301) | **Yes.** They present real browser UAs, fetch HTML, fetch no assets, execute no JS, and send no cookies. Behaviourally identical to the primary population. Protected only by category. |
| **Translation tools** | No hits found | No translation-service UA appeared in the window | Would be caught by any UA-based rule. The site's own ES/SW/NL translations are served from origin, so no external dependency. |
| **Government validators** | No hits found | No `W3C_Validator`-class UA in the window | Would be caught. No dependency identified. |
| **RSS readers** | **No reader identified** | Feed endpoints exist and serve 200 to crawlers, but **no Feedly / Inoreader / NetNewsWire / Miniflux-class UA appears** in the window. `Aggregator` (407) is `Pinterestbot`, a link crawler. ~150 scripted requests to feed paths are already 403'd — see §5.3 | **Yes, if one appeared.** A feed reader fetches XML and no assets — indistinguishable from the primary population on signals 11–13. This is a reason not to build such a heuristic, not evidence of a current subscriber. |
| **Partner integrations** | **None identified** | No partner or directory consumer appears in the sitemap, feed, or PDF data — see §5.2 | Cannot be assessed without program-staff input; see §9. |
| *(also present)* Archiver | **Yes** | `archive.org_bot` — 123 requests, **116 × 200 + 7 × 301, none challenged or blocked** | `Archiver` is *not* in `64fae5be`'s retained category list, so it is not skipped — but nothing is currently acting against it either. Recorded, not changed. |
| *(also present)* Aggregator | **Yes** | `Pinterestbot` — 407 requests, **126 × 200 + 272 × 301, none blocked** (6 hit a skip rule) | Same as Archiver: not in the retained list, but reaching content today. |

**Tripwire result: clean.** No uptime monitor, search engine, social preview, accessibility,
security-scanner, RSS-reader, or translation user agent appeared inside any candidate
population. Cross-category integrity held: Better Stack and UptimeRobot are still classified
`Monitoring & Analytics`; Googlebot, bingbot, Baiduspider, and DuckDuckBot are still
`Search Engine Crawler`.

---

## 5. Legitimate scripted consumers (§8.8 inventory)

### 5.1 Verified-bot census — 14 days

| Category | Requests | Status |
|---|---:|---|
| Search Engine Crawler | 21,580 | ✅ Skip `64fae5be` |
| Search Engine Optimization | 17,489 | ✅ Skip (owner confirmed ILAS depends on none of these — §8.6) |
| AI Assistant | 12,422 | ✅ Allowed by AI policy (`ai_user`) |
| **Monitoring & Analytics** | **11,186** | ✅ **Better Stack 6,718 · UptimeRobot 3,960** — both confirmed working |
| AI Crawler | 4,661 | 🚫 **403 by policy** — `ai_training=block`. Intentional. |
| Security | 3,521 | ✅ Skip — email/security scanners |
| AI Search | 2,993 | ✅ Allowed |
| Page Preview | 2,683 | ✅ `facebookexternalhit` 1,564 · Twitterbot 46 · GoogleImageProxy 44 |
| Accessibility | 900 | ✅ Skip |
| Advertising & Marketing | 535 | Not in retained list |
| Aggregator | 407 | Pinterestbot. Not in retained list. |
| Archiver | 123 | `archive.org_bot`. Not in retained list. |
| Webhooks | 79 | ✅ Skip |

Also under `Monitoring & Analytics`: `HeadlessChrome/109` (203), `SEBot-WA` (193),
`HubSpot Crawler` (56), and — worth noting — **`python-requests/2.34.2` (56)**. A verified
monitoring bot using a plain Python client is permitted by *category*, which is a concrete
reason not to reason about scripted clients by UA family.

### 5.2 `/sitemap.xml` — §8.8's one open decision, now answered

| Client | Status | Requests |
|---|---|---:|
| AI Assistant | 200 | 258 |
| Search Engine Optimization | 200 | 228 |
| Search Engine Crawler | 200 | 60 + 17 × 301 |
| AI Search | 200 | 48 |
| Unverified client | 200 | 7 |
| **Unverified client** | **403** | **100** |
| AI Crawler | 403 | 19 |

§8.8 said to add a skip **only if a real consumer is identified**. Breaking down the 100
unverified 403s by user agent:

| UA | Requests | What it is |
|---|---:|---|
| `Chrome/142.0.0.0` macOS and near-variants | 18 + 6 + 3 + 2 | **The §2.1 spoofing population itself** |
| `GPTBot/1.4` | 13 | AI crawler — 403 by deliberate policy |
| `Chrome/12x` Windows | 9 + 2 | Scanner traffic (§3.2 family) |
| `Go-http-client/1.1` | 11 | Generic scripted client, no identity |
| `ReflectionBot/1.0` | 2 | AI crawler |
| `Chrome` macOS 13_4_1 | 2 | Unattributed |

**No partner, directory, or government aggregator appears among the blocked clients.**
Search engines reach the sitemap as verified bots and indexing is unaffected. The 403s are
the spoofing population, AI crawlers blocked on purpose, and anonymous scripted clients.

**Conclusion: do not add the `/sitemap.xml` skip rule.** The condition §8.8 set was never
met. This is a positive finding, not an absence of data.

### 5.3 RSS / feed endpoints — §8.8's "unknown whether any exist"

**They exist. They are crawled. No feed *reader* consumes them.** Both halves matter.

Source: `config/views.view.taxonomy_term.yml`, the only view in the repo with a feed display.
Endpoints measured serving HTTP 200:

`/taxonomy/term/3/feed` (43) · `/nl/taxonomy/term/3/feed` (37) · `/taxonomy/term/6/feed` (23)
· `/taxonomy/term/37/feed` · `/taxonomy/term/84/feed` · `/taxonomy/term/57/feed` ·
`/taxonomy/term/2/feed` · `/taxonomy/term/41/feed` · `/taxonomy/term/83/feed` ·
`/taxonomy/term/32/feed` · `/taxonomy/term/39/feed` · `/taxonomy/term/40/feed`, plus `/es/`,
`/nl/`, and `/sw/` locale variants.

**Who actually fetches them**, on the taxonomy-term feed paths:

| Client | Requests | Status |
|---|---:|---|
| `IbouBot/1.0` | 202 | 200 |
| `Baiduspider/2.0` | 108 | 200 / 301 / 304 |
| `MJ12bot/v1.4.8` | 99 | 200 |
| `HeadlessChrome/149.0.0.0` | 98 | **403** |
| `Chrome/48.0.2564.11` (Windows WOW64) | 30 | 301 / **403** |
| assorted `Chrome/6x`–`Chrome/149` UAs | ~24 | **403** |

So the answer to §8.8's open item is more specific than "yes":

1. **The feed endpoints work** and are reached by verified search and SEO crawlers.
2. **No mainstream feed reader appears in the window** — no Feedly, Inoreader, NewsBlur,
   NetNewsWire, Miniflux, or Tiny Tiny RSS user agent. The `Aggregator` verified-bot
   category on this zone is `Pinterestbot`, which is a link crawler, not a feed reader.
3. **Scripted clients are being 403'd on feed paths** — ~150 requests, chiefly
   `HeadlessChrome/149`. Whether any of those is a legitimate consumer cannot be determined
   from the UA alone; none is identifiable as one.

**Therefore: no feed skip rule is justified, and no RSS dependency is demonstrated.** The
endpoints are not broken for crawlers, and nothing needs to change to keep the current
behaviour. If a program partner reports a broken feed subscription, this table is where to
start — the ~150 blocked requests are the candidate population.

⚠️ Do not read the §4 "RSS readers" row as evidence that this site has feed subscribers. It
records that feed readers are a category that *would* be misclassified by a
low-asset-ratio heuristic, which is why such a heuristic is not proposed — not that any is
currently present.

⚠️ **One number in the raw output must not be misread.**
`/themes/contrib/bootstrap5/images/icons/feed.svg` (630) is the feed **icon asset**, matched
by the collector's `%/feed%` path filter. It is not a feed consumer. Likewise `/feeds` (105),
`/comments/feed` (72), `//feed/` (17), `/feed.xml` (14) and `/get-involved/feedback` (64) are
WordPress-shaped probe noise or unrelated pages, not ILAS feed endpoints.

### 5.4 JSON endpoints

| Path | Requests | Consumer |
|---|---:|---|
| `/search_api_autocomplete/content_autocomplete` | 348 | Site search UI |
| `/assistant/api/track` | 318 | Site assistant |
| `/assistant/api/message` | 137 | Site assistant |
| `/editoria11y/api/results/report` | 24 | Accessibility checker |
| `/contextual/render` | 16 | Drupal authenticated UI |
| `/employment-application/token` | 16 | Employment application wizard |
| `/employment-application/jobs` | 14 | Employment application wizard |

`/assistant/api/session/bootstrap` remains covered by explicit skip rule `db42c4d6`.
Donation and employment integrations are **inbound only** and unaffected — confirmed by the
absence of any outbound-partner UA in the window.

### 5.5 Public PDFs

**8,310 × 200** and 40 × 206 (range requests) in 14 days. Reachable and heavily used.

⚠️ Note for future reviewers: SBFM rule `023ec3b3` ("likely automated, static resources",
challenge) **hits PDFs**. This is pre-existing and no change is proposed, but it is the
control most likely to affect legitimate document access and belongs on any future watchlist.

### 5.6 `robots.txt` and `.well-known`

`/robots.txt` reachable by any client via skip rule `92082bed` — verified. Origin
`web/robots.txt` (with `Sitemap:` and the AI-training `Disallow` groups) is being served, not
Cloudflare's managed replacement.

---

## 6. Unsupported assumptions

Recorded so they are not inherited as fact by the next reader.

1. **"~14.7 % of counted visits are browser-looking automation."** **Not reproduced.** The
   audit's figure cannot be reconstructed from any dimension available on this plan. The
   primary population is 8.4 % of *requests*, which is a different denominator from *visits*
   and should not be compared to it. The 14.7 % figure should be treated as unverified.
2. **"Fetches HTML, fetches no assets" identifies automation.** **Rejected**, as §8.7 says.
   §4 shows email security scanners, RSS readers, and privacy browsers all produce it. Used
   here only as one signal among sixteen, never alone.
3. **"A stale Chrome version indicates spoofing."** **Rejected, and empirically refuted** in
   §3.2: Chrome/120 is staler than Chrome/142 and markedly less automated.
4. **"Zero `/cdn-cgi/rum` beacons proves no browser."** **Not safe alone.** Privacy browsers
   and analytics blockers produce the same reading. Corroborating only.
5. **"`clientCountryName` tells us where the client is."** **False for this population.** US
   country codes appear on requests egressing European hosting ASNs. Country carries no
   signal here.
6. **"The report's JA4 gate was checked."** **It was not, and cannot be.** Any future claim
   that this population "does not match a genuine browser JA4" would be unfounded on this
   plan.
7. **"No identified partner consumer means none exists."** Only that none is *visible at the
   edge*. A quarterly consumer might not appear in 14 days. See §9.
8. **"Cox Communications traffic is proxy egress."** **Most likely, not established.** §3.1.

---

## 7. Proposed observation-only rule

### None is proposed.

Three independent reasons, any one of which is sufficient:

**1. A mandatory evidentiary gate cannot be evaluated.** The report requires that a matched
population be "inconsistent with a genuine browser JA4" and that a deployed rule show "zero
matches carrying a genuine browser JA4". `ja4` and `ja3Hash` are denied to this zone. That
gate cannot be met, and the correct response to an unmeetable gate is to not act — not to
quietly drop it.

**2. The required Log-mode observation period cannot be executed.** Log is Enterprise-only.
For §8.6 this was worked around with a **mirror-skip** — a rule carrying the exact
`action_parameters` that an existing skip already applied to that traffic, so runtime
behaviour was provably unchanged and matches merely acquired their own rule ID. **That
technique does not transfer here.** It depends on there being an existing skip to mirror. The
§2.1 population is not skipped by anything; it is challenged and blocked. There is no
behaviour-preserving rule that could be written for it, so any rule would be enforcement from
the moment it was created — exactly what the report forbids as a first step.

**3. It would add no coverage.** §2.2: 99.2 % is already actioned; 42 requests in 14 days
reach content unchallenged. A Managed Challenge rule would duplicate work SBFM and AI
Labyrinth already do, while adding a new false-positive surface for the populations in §3.1
and §4.

### What a rule would have looked like, if the gates had been met

Recorded so a future reviewer does not have to re-derive it. **Not deployed. Not staged.**

```
# NOT DEPLOYED — recorded for reference only.
(http.user_agent eq "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36"
 and not cf.client.bot
 and cf.bot_management.score lt 30
 and ip.src.asnum in {212238 9009 3257 210906 203020 62874 7979 11798 396356 398781 46635}
 and not http.request.uri.path in {"/robots.txt" "/.well-known/security.txt" "/sitemap.xml"})
```

Action **Managed Challenge**, never Block; never rate limiting keyed on `ip.src` alone —
§2.1 signal 3 shows that would be both risky and ineffective. Note the expression itself
depends on `cf.bot_management.score`, which is **also Enterprise-gated**.

### Conditions that would revive the question

Re-open only if one of these becomes true:

1. **The zone gains Enterprise Bot Management** — `ja4` and `botScore` become measurable and
   Log mode becomes available. Then the full §8.7 standard can actually be met.
2. **The population escapes current mitigation** — the unchallenged `200` share rises
   materially above the measured **0.1 %** (42 / 41,000). This is the tripwire in §8.
3. **Origin impact appears** — Pantheon origin load or cache-hit ratio degrades and is
   traced to this population. None observed.

---

## 8. False-positive analysis

For the rule that is **not** being deployed, quantified from measured data.

**Direct false positives inside the matched population:** 4 `likely_human` requests plus up
to 2,441 Cox Communications requests of uncertain nature — **0.01 % to 6.0 %** of the
population. The upper bound would breach the report's own ≥95 % standard if the Cox traffic
turned out to be residential rather than proxy, and this zone cannot resolve that without
JA4.

**Populations that would be caught by a looser rule** — the reason not to broaden any
criterion:

| If the rule keyed on… | It would catch | Measured cost |
|---|---|---|
| Zero `/cdn-cgi/rum` beacons | Privacy browsers, analytics blockers, screen readers | Unquantifiable — invisible by construction |
| High HTML-to-asset ratio | Email security scanners, RSS readers, cached returning visitors | ≥ 3,521 (`Security`) + 407 (`Aggregator`) |
| Stale Chrome version | The `Accessibility` category (890 requests on Chrome/138); the mixed Chrome/120 population (§3.2) | ≥ 890 protected requests, plus unknown Defender share |
| Hosting-provider ASN | Corporate proxies, Microsoft 365 link scanning, `GoogleImageProxy` | ≥ 8,246 Microsoft-ASN requests of mixed nature |
| `ip.src` rate limiting | Libraries, shelters, any shared NAT egress | Unquantifiable — and **ineffective against the actual target**, which never exceeds 11 requests per IP |
| Missing `Referer` | Bookmarked entries, typed URLs, privacy-mode referrer suppression | Substantial; not isolated in this window |

**Cost asymmetry.** For a legal-aid site the populations most likely to trip a naive
heuristic — library terminals, shelter networks, shared devices, accessibility tooling —
overlap with the users least able to recover from being challenged. A Managed Challenge that
fails for someone on a shelter network is a person who does not get legal help. Against that,
the measured benefit is 42 requests per 14 days.

---

## 9. Monitoring and rollback

### Rollback

**There is nothing to roll back.** No rule was created, in any mode. No ruleset, bot-management
setting, or zone setting was modified. Rollback applies only to this document and the
collector script, both of which are ordinary reverts.

Custom ruleset `f887ac01edd44986aae31e7e6c05c8bb` was verified unchanged against
`docs/evidence/cf-seo-bot-observation/10-waf-custom-after.json`.

### Recurring check

```bash
scripts/observability/cloudflare-browser-spoofing-analysis.sh --days 14 \
  --out docs/evidence/cf-browser-spoofing --ua-like '%Chrome/120.0.0.0%'
```

Exit 0 clean · 2 review required · 1 error. Suggested cadence: monthly, or after any
Cloudflare bot-management change.

### Thresholds that change the recommendation

| Watch | Baseline (14 d) | Escalate if |
|---|---:|---|
| Unchallenged `200` share of the primary population | **0.1 %** (42 / 41,000) | **> 2 %** — mitigation is degrading |
| Better Stack + UptimeRobot at 200 | 6,718 + 3,960 | Any sustained drop, or either leaving `Monitoring & Analytics` |
| Search engines in `Search Engine Crawler` | 55 UA rows | Any major engine recategorised |
| Protected UA inside a candidate population | 0 | Any non-zero — the tripwire; exit 2 |
| `/sitemap.xml` 403s to *identifiable* consumers | 0 | Any named partner/directory/government client appears → revisit §5.2 |
| PDF 200s | 8,310 | Sustained drop → check SBFM `023ec3b3` |
| Origin impact | None observed | Any origin-load or cache-ratio regression traced to this population |

The tripwire and cross-category integrity checks are the mechanism by which a silent
Cloudflare recategorisation — the most likely way this zone's monitoring breaks — is caught.

### Open item requiring a human, not a query

§8.8's remaining question **cannot be answered from inside the codebase or from edge data**:
are there partners, legal-services directories, or government referral aggregators that
consume ILAS sitemaps, feeds, or PDFs programmatically? §5.2 shows none is currently being
blocked, but a low-frequency consumer could fall outside a 14-day window. **This needs a
question to program staff.** Until then, no skip rule is justified.

---

## 10. Recommendation

### Take no action.

| §8.7 — browser-spoofing automation | |
|---|---|
| Analysis | **Complete.** All sixteen signals measured or explicitly recorded as unmeasurable, over 14 days. |
| Population | **Confirmed.** `Chrome/142.0.0.0` macOS, 41,000 requests, 99.2 % automated, distributed across 5,000+ rotating IPs. |
| Decision standard | **Not met** — the JA4 gate is unmeasurable on this plan and the Log-mode requirement is unexecutable. |
| Action | **No rule.** Not Block, not Managed Challenge, not an observation rule. |
| Rationale | 99.2 % is already mitigated; 42 requests / 14 days reach content unchallenged. A new rule would add false-positive risk to vulnerable users and no coverage. |

| §8.8 — legitimate scripted access | |
|---|---|
| Inventory | **Complete and measured** (§5). Feed endpoints confirmed to exist and to serve crawlers — closing the report's open item, with the finding that **no feed *reader* is present** and ~150 scripted feed requests are already 403'd (§5.3). |
| `/sitemap.xml` skip | **Do not add.** §8.8's condition — "only if a real consumer is identified" — is not met; the 403s are the spoofing population, AI crawlers blocked by policy, and anonymous clients. |
| Documentation | **Done.** Posture table added to `docs/observability.md` per §8.8. |
| Open item | One question for program staff (§9). Not a blocker. |

**Continue observing** via the committed script and the §9 thresholds. §8.7 and §8.8 can be
closed as *analysed, no enforcement proposed* — not deferred, and not pending further data.

The report was right to refuse a rule on the evidence it had. Having now gathered the
evidence, the conclusion is the same one, reached for better reasons: the automation is real,
it is already handled, and the gates that would justify acting on it cannot be met on this
plan.

---

## Appendix — reproduction

```bash
# Full 14-day collection (read-only; ~40 s)
scripts/observability/cloudflare-browser-spoofing-analysis.sh \
  --days 14 --out docs/evidence/cf-browser-spoofing --ua-like '%Chrome/120.0.0.0%'

# Confirm the denied dimensions for yourself
curl -sS https://api.cloudflare.com/client/v4/graphql \
  -H "Authorization: Bearer $(tr -d '\r\n' < ~/.secrets/cloudflare_api_token)" \
  -H 'Content-Type: application/json' \
  --data '{"query":"query{viewer{zones(filter:{zoneTag:\"7aef3c4adc977c9f645472338b031450\"}){httpRequestsAdaptiveGroups(limit:1,filter:{date_geq:\"2026-07-20\"}){dimensions{ja4}}}}}"}'
# -> "zone ... does not have access to the field 'ja4'"

# Confirm no production control changed
curl -sS "https://api.cloudflare.com/client/v4/zones/7aef3c4adc977c9f645472338b031450/rulesets/f887ac01edd44986aae31e7e6c05c8bb" \
  -H "Authorization: Bearer $(tr -d '\r\n' < ~/.secrets/cloudflare_api_token)" \
  | jq '.result.version'
# -> must match docs/evidence/cf-seo-bot-observation/10-waf-custom-after.json
```

**Related.** Validation report §8.6 (SEO-crawler observation, mirror-skip precedent) ·
§8.7 · §8.8 · §9 (legitimate services) · `docs/observability.md` ·
`docs/pantheon-cloudflare-implementation-tracker.md`
