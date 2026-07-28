# Before / after header comparison

All probes are anonymous `curl` HEAD requests with no cookies, against the **Pantheon platform
hostname** for each environment (`https://<env>-idaho-legal-aid-services.pantheonsite.io`).
The public hostname cannot be probed by script — Cloudflare returns 403 to scripted clients by TLS
fingerprint regardless of User-Agent (pre-existing, validation doc §8.8).

Before-change data is the Live baseline captured 2026-07-28T20:31Z, immediately before the change.

## Anonymous English interior pages — the target of the fix

| Path | Before `x-drupal-cache` | Before `cache-control` | After `x-drupal-cache` | After `cache-control` |
|---|---|---|---|---|
| `/legal-help/housing` | **UNCACHEABLE (response policy)** | `must-revalidate, no-cache, private` | MISS | `max-age=86400, public` |
| `/forms` | **UNCACHEABLE (response policy)** | `must-revalidate, no-cache, private` | MISS | `max-age=86400, public` |
| `/donate` | **UNCACHEABLE (response policy)** | `must-revalidate, no-cache, private` | MISS | `max-age=86400, public` |
| `/resources/probate` | **UNCACHEABLE (response policy)** | `must-revalidate, no-cache, private` | MISS | `max-age=86400, public` |
| `/contact/offices/boise-office` | **UNCACHEABLE (response policy)** | `must-revalidate, no-cache, private` | MISS | `max-age=86400, public` |

## Paths that were already cacheable — must not regress

| Path | Before | After | `content-language` after |
|---|---|---|---|
| `/` | MISS · `max-age=86400, public` | MISS · `max-age=86400, public` | `en` |
| `/es` | MISS · `max-age=86400, public` | MISS · `max-age=86400, public` | `es` |
| `/es/resources/quiebra` | MISS · `max-age=86400, public` | MISS · `max-age=86400, public` | `es` |
| `/sw` | MISS · `max-age=86400, public` | MISS · `max-age=86400, public` | `sw` |
| `/nl` | MISS · `max-age=86400, public` | MISS · `max-age=86400, public` | `nl` |

## The decisive pair — two nonexistent pages differing only by language prefix

| Path | Status | Before | After |
|---|---|---|---|
| `/this-page-does-not-exist-ilas-probe` | 404 | **UNCACHEABLE (response policy)** · `must-revalidate, no-cache, private` | MISS · `max-age=86400, public` |
| `/es/esta-pagina-no-existe-ilas-probe` | 404 | MISS · `max-age=86400, public` | MISS · `max-age=86400, public` |

Same missing page, same status code, differing only by the first path segment. Before the change one
was cacheable and the other was not; after the change both are. This isolates the discriminator to
language negotiation and nothing else.

## Redirects also became cacheable

| Path | After |
|---|---|
| `/employment` → `/careers/employment-opportunities` | 301 · `max-age=86400, public` |
| `/es/hogar` → `/es` | 301 · `max-age=86400, public` |

## Why `x-drupal-cache` reads MISS rather than HIT on repeat requests

The requested success criterion was `MISS` then `HIT` on `x-drupal-cache`. What actually happens
through Pantheon is better than that, and the reason matters:

| | Before | After |
|---|---|---|
| Repeat request, `x-cache` | `MISS, MISS` | **`MISS, HIT`** |
| Repeat request, `age` | `0` | **climbing (16–45s observed)** |
| Does the repeat reach PHP? | **Yes, every time** | **No** |

Because the response is now `public` with a `max-age`, **Pantheon's Global CDN caches it**. The
second request is served at the edge and never reaches Drupal, so `x-drupal-cache` stays frozen at
the value from the original origin response (`MISS`). A literal `x-drupal-cache: HIT` is only
observable when there is no CDN in front — and it was confirmed there:

```
Local DDEV, /forms:   request 1 -> x-drupal-cache: MISS
                      request 2 -> x-drupal-cache: HIT      cache-control: max-age=86400, public
```

This is the mechanism Pantheon's `cache_hit_ratio` metric measures, since Pantheon meters at its
Global CDN. Before the change the CDN could never store these responses; now it does.

## Intended behaviour change

| Request | Before | After |
|---|---|---|
| `Accept-Language: es-ES` on `/` | Spanish interface | **English** |
| `Accept-Language: es-ES` on `/forms` | Spanish interface | **English** |
| `/es`, `/es/...` | Spanish | Spanish (unchanged) |

## Session cookies

`Set-Cookie` was absent on every anonymous path probed, at every environment, before and after.
No anonymous page sets a session cookie.

## Per-environment status

| Environment | Cacheability | Spanish content | Notes |
|---|---|---|---|
| Local DDEV | ✅ MISS → **HIT** | ✅ | Only place `x-drupal-cache: HIT` is observable (no CDN) |
| Dev | ✅ MISS → edge HIT | ✅ 99 Spanish nodes | Full verification |
| Test | ✅ MISS → edge HIT | ⚠️ **0 Spanish nodes** | Test DB has no Spanish content — pre-existing, see below |
| Live | ✅ MISS → edge HIT | ✅ | Full verification |

**Test caveat.** `SELECT COUNT(*) FROM node_field_data WHERE langcode='es'` returns **99 on dev** and
**0 on test**. Test's `/es` therefore resolves to `/es/node/25` with no alias and advertises only
`x-default` + `en` hreflang. Dev and Test run identical code and identical config, so this is a
stale-database condition in Test, not a regression. Live (pre-change) and Dev (post-change) both
show the full 5-entry hreflang set. The "Spanish content remains Spanish" criterion is validated on
Dev and Live; Test has no Spanish content to validate.
