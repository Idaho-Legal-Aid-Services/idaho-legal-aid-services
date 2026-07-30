# Evidence — SEO verified-bot observation (tracker item 9)

Captured by Evan Curry, 2026-07-29, against zone `idaholegalaid.org`
(`7aef3c4adc977c9f645472338b031450`, **Business Website** plan).

Backs validation report §8.6 / §10.2 in
`docs/pantheon-cloudflare-preimplementation-validation.md`.

## Why the change does not match §10.2 as written

§10.2 Step 2 specifies a new custom rule with action **Log** for ≥ 7 days. **The Log action is
Enterprise-only**, and this zone is on Business Website:

> "Only available on Enterprise plans. Recommended for validating rules before committing to a
> more severe action."
> — https://developers.cloudflare.com/ruleset-engine/rules-language/actions/

The closest genuinely non-enforcing mechanism this account supports is a **mirror-skip**: the new
rule is a `skip` carrying the *exact* `action_parameters` that `64fae5be` applied to this category
before the change (`phases: [http_request_firewall_managed, http_ratelimit, http_request_sbfm]`,
`ruleset: current`), placed at position 0. Runtime behaviour is unchanged in every respect. The
only difference is that SEO-category matches now carry their own rule ID.

`"ruleset": "current"` is load-bearing — it makes a match skip *every remaining rule in the custom
ruleset*, including Drupal Hardening and the CN/RU Geo-Challenge. That is why the new rule must sit
at index 0 and carry identical `action_parameters`; anywhere else, or with different parameters,
the change would not be neutral.

**No enforcement was enabled. Managed Challenge was not created, staged, or scheduled.**

## Files

| File | Contents |
|---|---|
| `00-rulesets-list.json` | all zone rulesets, `GET /zones/{zone}/rulesets` |
| `01-waf-custom-before.json` | **rollback source of truth** — custom ruleset `f887ac01…` at **version 14** |
| `02-firewall-managed-before.json` | managed-WAF zone entrypoint (OWASP + Cloudflare Managed deployment) |
| `03-ratelimit-before.json` | `http_ratelimit` entrypoint (2 rules) |
| `04-bot-management-before.json` | Bot Management settings, incl. `sbfm_verified_bots: "allow"` |
| `05-zone-settings-before.json` | zone record, incl. `plan.legacy_id: business` |
| `06-baseline-bot-census.json` | 7-day pre-change census, 2026-07-22T19:00Z → 07-29T19:00Z |
| `10-waf-custom-after.json` | custom ruleset at **version 17** (post-change) |

## Change

Custom ruleset `f887ac01edd44986aae31e7e6c05c8bb` (`http_request_firewall_custom`), v14 → v17.

**New rule** `b79f504cabd347dc8eda9fd7c8748347`, ref `ilas_seo_category_observe`, index **0**:

```
expression        (cf.verified_bot_category eq "Search Engine Optimization")
action            skip
action_parameters phases: [http_request_firewall_managed, http_ratelimit, http_request_sbfm]
                  ruleset: current
logging.enabled   true
enabled           true
```

**Amended rule** `64fae5becbce484caf8c43fd58734a45` — expression only (plus a description
correction; `action`, `action_parameters`, `logging`, `enabled` untouched):

```diff
- (cf.verified_bot_category in {"Search Engine Crawler" "Search Engine Optimization" "Page Preview" "Monitoring & Analytics" "Accessibility" "Security" "Webhooks"})
+ (cf.verified_bot_category in {"Search Engine Crawler" "Page Preview" "Monitoring & Analytics" "Accessibility" "Security" "Webhooks"})
```

Applied 2026-07-29T20:03:44Z. Rules 2–8 verified byte-identical to the export.

## Pre-change confirmations (live, 2026-07-22 → 07-29)

| Confirmation | Result |
|---|---|
| `sbfm_verified_bots` still `allow` | ✅ `04-bot-management-before.json` |
| Better Stack + UptimeRobot = Monitoring & Analytics | ✅ 960 / 566 over 2 days |
| Search engines remain Search Engine Crawler | ✅ Googlebot, bingbot, Baiduspider, DuckDuckBot |
| SEO category holds the §8.6 bots | ✅ Semrush, MJ12, Ahrefs, Siteimprove, SiteAuditBot |
| ILAS relies on none of them | ✅ Semrush / Ahrefs / Siteimprove confirmed with owner 2026-07-28. **MJ12bot not covered — open** |
| Token has Zone WAF edit | ✅ probed non-destructively (errors `20115`/`20125` = validation, not authz) |
| Non-enforcing action available | ❌ `log` is Enterprise-only → mirror-skip |

## ⚠️ Finding: §8.6's SEO composition table is incomplete

The SEO category is **not** purely commercial crawlers. Over 7 days it also carried:

| UA | 7-day count |
|---|---:|
| `GoogleAssociationService` | 22 |
| `Googlebot/2.1` | 3 |
| `Google-Adwords-Instant-Mobile` | 2 |
| `SearchAtlas Bot` | 1 |

§8.6's own promotion test — *"confirm … Googlebot, bingbot, Better Stack and UptimeRobot never
appear"* — **would fail today**. Quantifying and explaining this is a precondition for tracker
item 10.

Baseline volume: **9,057** requests over 7 days (4,574 × 404, 2,569 × 200, 1,904 × 301).
SemrushBot alone accounts for the bulk of the 404s.

## Observation period

Start **2026-07-29T20:03:44Z**. Earliest review **2026-08-05T20:03:44Z** (7 full days).
Seven days covers a full weekly crawl cycle; Semrush/Ahrefs/MJ12 schedules are weekly-periodic
and a shorter window under-samples.

```bash
bash scripts/observability/cloudflare-seo-bot-observation.sh \
  --days 7 --rule-id b79f504cabd347dc8eda9fd7c8748347 \
  --out docs/evidence/cf-seo-bot-observation/review-day7
```

## Rollback

```bash
bash scripts/observability/cloudflare-seo-bot-rollback.sh            # dry run
bash scripts/observability/cloudflare-seo-bot-rollback.sh --apply    # execute
bash scripts/observability/cloudflare-seo-bot-rollback.sh --verify   # compare live vs export
```

Restores `64fae5be` from `01-waf-custom-before.json` **first**, then deletes the observation rule —
that order means SEO-category bots are never, at any instant, without a skip. Both the expression
and the description are restored. Verified working against the live zone both before and after the
change.

Manual equivalent, if the script is unavailable:

```
PATCH  /zones/{zone}/rulesets/f887ac01edd44986aae31e7e6c05c8bb/rules/64fae5becbce484caf8c43fd58734a45
DELETE /zones/{zone}/rulesets/f887ac01edd44986aae31e7e6c05c8bb/rules/b79f504cabd347dc8eda9fd7c8748347
```

Cloudflare's rule PATCH is **not** a partial update — omitting `action` fails with error `20015`.
Send the full rule body with only the changed fields swapped.

Version 14 also remains readable as an independent check:
`GET /zones/{zone}/rulesets/f887ac01edd44986aae31e7e6c05c8bb/versions/14`.

Roll back immediately if Better Stack or UptimeRobot ever appears in the match list, or if uptime
alerting fires. Because the rule is a behaviour-preserving mirror-skip neither should be possible;
a hit on those means the premise is wrong and the change should come out regardless.
