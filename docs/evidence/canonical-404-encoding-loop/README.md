# Evidence — the canonical 404 encoding loop

Captured 2026-07-30 against zone `idaholegalaid.org`
(`7aef3c4adc977c9f645472338b031450`) and the Pantheon platform hostnames.

Backs the findings section in `docs/pantheon-cloudflare-implementation-tracker.md` and the
`/cdn-cgi/content` and encoded-path rows in `docs/pantheon-cloudflare-followup-plan.md`.

## Files

| File | Contents |
|---|---|
| `00-live-reproduction-before.txt` | The three-step live reproduction, pre-fix |
| `10-july-volume.json` | Raw GraphQL output: July totals, daily series, status/cache split |
| `20-query-string-proof.txt` | The decisive proof that the loop runs through the canonical tag |
| `30-depth-per-cycle.txt` | Encoding depth per crawl cycle, plus the July totals |
| `40-cdn-cgi-content.txt` | The `/cdn-cgi/content` disposition |
| `50-after-fix-local.txt` | Post-fix verification on DDEV |

Every Cloudflare call in this collection is a GraphQL analytics read. No ruleset,
bot-management setting or zone setting was created, changed or reordered.

## What was wrong

A 404 whose path contained any `%` advertised, in `<link rel="canonical">` and
`<meta property="og:url">`, a URL one percent-encoding level deeper than the one requested.
Following it produced a deeper one again, without bound.

Root cause: `drupal/token` 8.x-1.17,
`src/Hook/TokenTokensHooks.php:481`. On an unrouted path `Url::createFromRequest()` throws and the
`[current-page:url]` token falls back to `Url::fromUserInput($request->getPathInfo())`.
`getPathInfo()` is still percent-encoded; `fromUserInput()` expects a decoded path and encodes what
it is given, so each `%` becomes `%25`. `config/metatag.metatag_defaults.global.yml:10,13` resolves
both tags from that token.

Upstream [#3075857](https://www.drupal.org/project/token/issues/3075857) touched this code and was
closed as outdated in January 2026 without addressing the encoding.

## Why we are confident it is our output, not the crawler

Three independent lines, in `20-query-string-proof.txt` and `30-depth-per-cycle.txt`:

1. **Reproduced live**, three passes, deterministic — `00-live-reproduction-before.txt`.
2. **Query strings.** The depth-0 seeds are Drupal 7 Rate-module links carrying `?rate=<token>`.
   Of the 852 escalating requests in the retained nginx log, **0 carry any query string**. The
   canonical tag is the only step in the chain that drops the query (tracker item 6, F-10).
3. **Depth grows per crawl cycle.** Minimum observed depth climbs 4 → 7 → 6 → 19 → 26 → 33 → 34
   across SemrushBot's ~4-day cycles.

A prior read of the nginx log alone concluded the crawler was re-escaping its own stored string.
That is not consistent with point 2: an internal re-escape would have preserved the query.

## Scope

Fires on 4xx responses whose path contains `%`. Unprefixed, `/sw/` and `/nl/` were all affected;
`/es/` was not (it resolved to `/es/pagina-no-encontrada`). **200 responses were never affected** —
the four live Spanish aliases containing `%20` always emitted correct single-encoded canonicals, and
that is now covered by a regression test.

Not new: the nginx evidence is from 2025-12-12. `config/redirect_404.settings.yml` already carried
the glob `/*%252*`, which suppressed these from the 404 log — the symptom had been noticed and
hidden at the logging layer, and the cause was never found.

Item 1 (language negotiation, 2026-07-28) did not cause it but made it more expensive: 404s now
carry `max-age=86400, public`, so every URL in an unbounded set also took a CDN cache entry.

## The fix

Two independent layers, because Pantheon's integrated composer build cache is known to skip patches
(hence `scripts/composer/ensure-patches.php`):

1. `patches/token-current-page-url-404-double-encode.patch` — decode the path before
   `Url::fromUserInput()`, and refuse rather than emit a URL the decode would restructure.
2. `ilas_seo` drops `canonical` and `og:url` on any error page
   (`\Drupal\ilas_seo\ErrorPage`, `CanonicalHost::removeSelfReferencingTags()`), and `GraphBuilder`
   emits no JSON-LD there — it was publishing the caller's URL as a `BreadcrumbList` `item`/`@id`.

## `/cdn-cgi/content` — closed, no action

Not a site defect. 448 of 478 404s were one `AliyunSecBot` client; every row carries
`originResponseStatus: 0`, i.e. Cloudflare answers it and the origin never sees it. The only change
made was to stop counting `/cdn-cgi/*` in `scripts/observability/cloudflare-404-volume-check.sh`,
which is supposed to track paths we can actually serve.
