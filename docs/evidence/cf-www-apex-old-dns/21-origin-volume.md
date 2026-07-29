# Origin-request change for www.idaholegalaid.org

Rule `ce2a37cdad2c44199770caee0516029d` created **2026-07-29T03:42:04Z**.
Method: Cloudflare GraphQL `httpRequestsAdaptiveGroups`, filtered to
`clientRequestHTTPHost = www.idaholegalaid.org`, grouped by edge and origin response status.
`origin=0` means **no origin request was made** — the edge answered.

## BEFORE — 2026-07-28T21:42Z to 2026-07-29T03:42Z (6 h, pre-rule)
```
edge=200 origin=0 n=61
edge=204 origin=0 n=1
edge=301 origin=0 n=136
edge=301 origin=301 n=198
edge=302 origin=0 n=2
edge=403 origin=0 n=1025
```

## AFTER — 2026-07-29T03:43Z to 2026-07-29T03:59Z
```
edge=301 origin=0 n=53
```

_Generated 2026-07-29T03:59:54Z._

---

## Interpretation

**The headline number.** `edge=301 origin=301` — a `www` redirect that cost a full Pantheon
round trip — ran at **198 in 6 hours (~33/h)** before the rule. After it: **zero**. Every one
of the 53 `www` requests in the measured window was answered at the edge with
`origin=0`.

**Everything on `www` collapses to a single response class.** Before, `www` produced six
distinct edge/origin combinations; after, exactly one. Three of those classes are gone for
reasons worth naming:

- `edge=301 origin=301` (198) — the origin round trip this change was built to remove.
- `edge=200 origin=0` (61) — cached 200s served on the `www` hostname. These were Cloudflare
  answering for `www` out of cache, which also meant `www` URLs could be served content
  directly rather than being canonicalised. They are now 301s to the apex, which is the
  correct behaviour and incidentally tightens canonicalisation.
- `edge=403 origin=0` (1,025) — bot traffic blocked by SBFM on `www`. These never reached the
  origin, so they were not costing origin requests, but they were consuming WAF evaluation.
  They now terminate one phase earlier as a cheap edge redirect. **This is a side effect of
  the phase order, not a change to any bot rule** — no bot or cache rule was touched.

**Caveat on the window.** The "after" window is 16 minutes against a 6-hour "before" window,
so the two are not directly rate-comparable in absolute terms. What is conclusive is the
*ratio*: `origin=301` went from 198 occurrences to 0, and no `www` request in the after window
reached the origin at all. Per validation §8.2 the expected steady-state saving is
**~6,300 origin requests/day**; confirm that against a full billing cycle under tracker
item 12.
