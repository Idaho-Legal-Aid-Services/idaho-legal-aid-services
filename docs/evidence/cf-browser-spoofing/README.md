# Evidence — browser-spoofing and scripted-access analysis (§8.7 / §8.8)

Captured by Evan Curry, 2026-07-29, against zone `idaholegalaid.org`
(`7aef3c4adc977c9f645472338b031450`, **Business Website** plan).

Backs validation report §8.7 and §8.8 in
`docs/pantheon-cloudflare-preimplementation-validation.md`, and is the evidence base for
`docs/browser-spoofing-and-scripted-access-analysis.md`.

## Files

| File | Contents |
|---|---|
| `browser-spoofing-analysis.json` | Full machine-readable output of the 14-day collection |

Regenerate with:

```bash
scripts/observability/cloudflare-browser-spoofing-analysis.sh \
  --days 14 --out docs/evidence/cf-browser-spoofing --ua-like '%Chrome/120.0.0.0%'
```

Window captured: `2026-07-15T23:33:00Z` → `2026-07-29T23:33:00Z` (14 days, 491,004 requests).
The raw per-request sub-window is the trailing 3 days, because `httpRequestsAdaptive` caps at
10,000 rows and a narrow window is a denser sample than a thin slice of a wide one.

## No production control was modified

Every Cloudflare call in this collection is a **GraphQL analytics read**. No ruleset was
created, updated, or reordered; no bot-management or zone setting was changed; no rule was
staged or scheduled. The custom ruleset `f887ac01edd44986aae31e7e6c05c8bb` remained at the
same version throughout, verified against
`../cf-seo-bot-observation/10-waf-custom-after.json`.

## Measurement limits — read before using these numbers

This zone is **denied six of the dimensions §8.7 asks for**. The GraphQL schema advertises
them; the zone returns `does not have access to the field` because they are Enterprise Bot
Management:

`botScore`, `botScoreBucketBy10`, `ja4`, `ja3Hash`, `jsDetectionPassed`, `botDetectionTags`

Substitutions used, and recorded in the JSON under `measurement_limits`:

| §8.7 signal | What was used instead |
|---|---|
| Bot score (1–99) | `botManagementDecision` — Cloudflare's own five-bucket classification of the same score |
| JS execution | `/cdn-cgi/rum` beacon request counts + `botScoreSrcName = js_fingerprinting` |
| **JA4 / TLS fingerprint** | **Nothing. No substitute exists.** The report's JA4 gate cannot be evaluated on this plan. |
| Cookie behaviour | `cacheStatus` `dynamic`/`bypass` — a weak proxy, labelled as such wherever quoted |

Counts come from the **adaptive (sampled)** datasets. `avg.sampleInterval` and `sum.visits`
are recorded alongside every total so a reader can judge sampling effects rather than
having to assume they are absent.

## Candidate selection is derived, not asserted

Populations are selected on three criteria — a browser-claiming user agent, a request-volume
floor, and an automated-bucket share — and **browser-version staleness is deliberately not
one of them**. §8.7 is explicit that a stale Chrome build can equally be a corporate fleet on
a pinned version, and the collector is written so that a reviewer who disagrees with the
selection can see every signal for every population and reach their own conclusion.

`--ua-like '%Chrome/120.0.0.0%'` was passed so that the second-most-suspicious stale-version
population appears in the report **and is visibly cleared**, rather than merely being absent.

## Outcome

`status=clean`, exit 0. The protected-traffic tripwire found no uptime monitor, search
engine, social preview, accessibility, security-scanner, RSS-reader or translation user
agent inside any candidate population, and cross-category integrity held (Better Stack and
UptimeRobot still classified `Monitoring & Analytics`; Googlebot, bingbot, Baiduspider and
DuckDuckBot still `Search Engine Crawler`).

**No rule is proposed.** See the recommendation section of
`docs/browser-spoofing-and-scripted-access-analysis.md` for why — including the two of the
report's own mandatory gates that cannot be met on this plan.
