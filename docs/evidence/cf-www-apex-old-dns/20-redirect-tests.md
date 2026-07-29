# `www` → apex Single Redirect — test matrix

**Rule:** `ILAS - www to apex` · id `ce2a37cdad2c44199770caee0516029d`
**Ruleset:** `47dd5943196a4187857c43474a753864` (`http_request_dynamic_redirect`, zone entrypoint), version 2 → 3
**Created:** 2026-07-29T03:42:04Z · **Tested:** 2026-07-29T03:43–03:47Z

---

## Propagation note — read this before interpreting a failure

The first test, run **11 seconds** after the rule was created, returned `HTTP/2 403` from the
pre-existing SBFM bot rule rather than the new 301. That was **propagation lag, not a rule
problem**. Roughly four minutes later every path returned the 301 correctly.

This briefly looked like evidence that redirect rules run *after* bot management on this zone,
contrary to Cloudflare's published phase order. They do not — the ordering is as documented,
`http_request_dynamic_redirect` before `http_request_sbfm`. **Allow a few minutes before
concluding anything from a redirect-rule test on this zone.**

---

## Results

All rows executed with `curl` against the public hostname unless noted.

| # | Test | Expected | Actual | ✓ |
|---|---|---|---|---|
| 1 | `https://www.idaholegalaid.org/` | 301 → apex root, edge-served | `HTTP/2 301` · `location: https://idaholegalaid.org/` · `server: cloudflare` · `cf-ray` present · **no `x-pantheon-redirect`** | ✅ |
| 2 | `https://www…/legal-help/housing` | same path on apex | `301` → `https://idaholegalaid.org/legal-help/housing` | ✅ |
| 3 | `…/legal-help/housing?utm_source=x&utm_medium=y&utm_campaign=z&a=1&b=2` | all 5 params intact, **none duplicated** | `301` → `…/legal-help/housing?utm_source=x&utm_medium=y&utm_campaign=z&a=1&b=2` — exact, single copy of each | ✅ |
| 4 | **UTM parameters** | preserved | covered by rows 3 and 6 — `utm_source`, `utm_medium`, `utm_campaign` all survive | ✅ |
| 5 | **Spanish URL** `…/es/videoteca?q=vivienda&page=2` | prefix + query preserved | `301` → `https://idaholegalaid.org/es/videoteca?q=vivienda&page=2` | ✅ |
| 6 | Other language prefix `…/sw/` | prefix preserved | `301` → `https://idaholegalaid.org/sw/` | ✅ |
| 7 | **HTTP, not HTTPS** — `http://www…/robots.txt?utm_source=x` | 301 to **https** apex | `HTTP/1.1 301` · `Location: https://idaholegalaid.org/robots.txt?utm_source=x` · `Server: cloudflare` · **1 hop**. The rule fires *before* Always Use HTTPS, so `http://www` reaches the apex in a single hop where a browser previously took two (`http://www` → `https://www` → apex). **Improvement.** | ✅ |
| 8 | **Apex must NOT redirect** — `https://idaholegalaid.org/legal-help/housing?a=1` | no redirect | `HTTP/2 403` (pre-existing SBFM block on scripted clients) with **no `location` header** — the apex is not matched by the rule. Corroborated on an SBFM-exempt path: `https://idaholegalaid.org/robots.txt` → `HTTP/2 200`, `cf-cache-status: HIT`, no `location` | ✅ |
| 9 | Apex HTTP unchanged — `http://idaholegalaid.org/robots.txt` | 1 hop (AUH only) | `hops=1` · final `https://idaholegalaid.org/robots.txt` · `code=200` | ✅ |
| 10 | **Redirect loop / hop count** — `https://www…/robots.txt?a=1&b=2` | exactly 1 hop, terminal 200 | `hops=1 final=https://idaholegalaid.org/robots.txt?a=1&b=2 code=200` — **no loop** | ✅ |
| 11 | Percent-encoded characters | preserved byte-for-byte | `?f%5B0%5D=tags%3A1867&q=vivienda%20casa` → identical in `location`; no double-encoding, no decoding | ✅ |
| 12 | `…/robots.txt`, `…/sitemap.xml`, `…/.well-known/security.txt` | 301 to apex equivalents | all three `301` to the matching apex URL | ✅ |

**Loop safety.** The rule matches only `http.host eq "www.idaholegalaid.org"` and targets
`https://idaholegalaid.org`, which cannot re-match the expression. Confirmed empirically in
rows 7, 9 and 10: every chain terminates in exactly one hop with a 200.

**Query-string handling.** `preserve_query_string: true` is set and the target expression does
**not** concatenate `http.request.uri.query` — row 3 confirms each parameter appears exactly
once, so the two mechanisms are not double-applying.

---

## A note on the apex control

The apex returns 403 to `curl` and always has — SBFM rule
`874a3e315c344b1281ad4f00046aab6f`, pre-existing and by design (validation §8.8, tracker F-6),
unrelated to this change. What matters for row 8 is that the response carries **no `location`
header**, i.e. the redirect rule did not match. The SBFM-exempt `/robots.txt` path gives the
clean `200` that confirms it positively.

---

## The headline result

Before, from `05-origin-redirect-semantics.txt`:

```
HTTP/2 301
server: Pantheon
location: https://idaholegalaid.org/legal-help/housing?utm_source=x&a=1&b=2
x-pantheon-redirect: primary-domain-policy-doc
```

After:

```
HTTP/2 301
server: cloudflare
location: https://idaholegalaid.org/legal-help/housing?utm_source=x&utm_medium=y&utm_campaign=z&a=1&b=2
cf-ray: a229231d2872a390-SEA
(no x-pantheon-redirect)
```

The 301 is now issued at the Cloudflare edge and no longer requires a Pantheon origin round
trip. Quantified in `21-origin-volume.md`.
