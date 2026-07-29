# `old.idaholegalaid.org` — pre-deletion investigation

**Date:** 2026-07-29 · **Ref:** validation §8.3, tracker item 11
**Question:** is the record unused, and is `72.3.167.82` claimable by a third party?

---

## 1. The record as it stood

From `00-dns-before.json`:

```json
{
  "id": "168615565fcd4be24606e1eaed627821",
  "type": "A",
  "name": "old.idaholegalaid.org",
  "content": "72.3.167.82",
  "proxied": false,
  "ttl": 1,
  "comment": null,
  "created_on": "2025-02-10T20:30:46.899550Z",
  "modified_on": "2025-02-11T14:25:27.858877Z"
}
```

Created at zone onboarding (2025-02-10) — i.e. imported wholesale from the previous DNS
provider, not authored deliberately since.

---

## 2. Is the host alive?

| Probe | Result |
|---|---|
| `dig +short old.idaholegalaid.org A` | `72.3.167.82` — record resolves |
| `dig +short old.idaholegalaid.org AAAA` | empty |
| `dig +short -x 72.3.167.82` | **no reverse DNS** |
| `curl http://72.3.167.82/` | **connection timed out** (12 s) |
| `curl -H 'Host: old.idaholegalaid.org' http://72.3.167.82/` | **connection timed out** |
| `curl -k https://72.3.167.82/` | **connection timed out** |
| `curl http://old.idaholegalaid.org/` | **connection timed out** |

No service on 80 or 443. The name resolves to nothing that answers.

---

## 3. Who owns `72.3.167.82`? — the gate the tracker set

ARIN RDAP (`33-rdap-72.3.167.82.json`):

| Field | Value |
|---|---|
| Handle | `NET-72-3-167-80-1` |
| Range | `72.3.167.80` – `72.3.167.95` (a /28) |
| Type | `ASSIGNMENT` |
| **Registrant** | **Phoenix Group Holdings LLC**, 34 Mountain Blvd, Warren NJ 07059 |
| Registered / last changed | 2016-11-30 |
| Parent | `NET-72-3-128-0-1` → **Rackspace Backbone** |

The address is assigned to an **unrelated third-party company** inside legacy Rackspace
space. It is not, and cannot be, ILAS-controlled.

This resolves tracker item 11's gate ("confirm `72.3.167.82` is not claimable before
deleting") in the direction that makes deletion *more* urgent, not less: a DNS record we
control points at an IP someone else holds. That is textbook dangling DNS — if the current
holder ever binds a listener on it, they serve content under our hostname. Deleting the
record removes the surface entirely.

*(Side note: `google._domainkey.idaholegalaid.org` → `72.3.173.166` is in the same Rackspace
/17 and is likewise a legacy artefact, but it is a DKIM record and out of scope here.)*

---

## 4. Was it ever publicly used?

| Source | Result |
|---|---|
| Wayback Machine CDX API, `matchType=domain` over the whole hostname | **zero captures, ever** |
| Public web search for the bare hostname | zero results referencing it |
| Public web search for an archived/legacy ILAS site at that name | zero results |

A hostname that served public content for any length of time is normally captured at least
once. Zero captures plus zero index presence is consistent with a record that was migrated
in from the old DNS zone and never used, or used only internally long before archiving.

---

## 5. Dependency sweep — every surface

| Surface | Result |
|---|---|
| Working tree: `old.idaholegalaid`, `72.3.167.82`, `72.3.167`, bare `old.<host>` patterns — including dotfiles, untracked files, binaries, `node_modules/`, `vendor/`, `web/core/` | Hits in **only 3 files**, all Cloudflare/Pantheon audit prose describing the record as dead: `docs/pantheon-cloudflare-traffic-audit.md`, `docs/pantheon-cloudflare-preimplementation-validation.md`, `docs/pantheon-cloudflare-implementation-tracker.md:38` |
| `git log --all -S'old.idaholegalaid'` (all 30+ refs: master, origin, github, 10 `backup/*`, `temp/*`, `codex/*`, dependabot) | **zero commits** |
| `git log --all -S'72.3.167.82'` and `-S'72.3.167'` | **1 commit**, `ec129b4834` — the commit that *added* the tracker row proposing this deletion. Identical result for both terms, so no other `72.3.167.x` address has ever existed in history |
| `git log --all --grep` on `old\.`, `old\.idaholegalaid`, `72\.3\.167`, and legacy-host phrasings | **zero commits** |
| GitHub Actions: every `secrets.*` / `vars.*` name across `.github/**` | zero literal hits. `gh variable list` returns 4 variables, none a hostname; `gh secret list` returns 3, none a legacy host. In-repo default target is `dev-idaholegalaid.pantheonsite.io` |
| Monitoring: `scripts/observability/**`, Sentry, smoke targets, Better Stack / UptimeRobot configs | zero. Both Cloudflare scripts hardcode `ZONE_NAME="idaholegalaid.org"`; no uptime config exists in-repo |
| Drupal config `config/**`: trusted hosts, CORS, CSP/seckit, `redirect`, `redirect_404`, `simple_sitemap`, metatag, robotstxt | zero. (`redirect_404.settings.yml:2` contains a `/old*` **path** glob — a URL-path pattern, not a hostname) |
| Content / DB: `db_backups/ilas-pre-migrate-20260302.sql.gz`, `.ddev/.downloads/db.sql.gz`, fixtures, redirect-entity exports | zero |
| Local email / documentation under `/home/evancurry` (depth ≤ 2), `.planning/`, `.claude/`, `.ddev/`, `.env.example` | zero |
| **Pantheon domain attachment** — `terminus domain:list` on live / test / dev | Live carries exactly `idaholegalaid.org`, `www.idaholegalaid.org`, `live-idaho-legal-aid-services.pantheonsite.io`. **`old.` is attached to no environment.** Even if the name resolved to Pantheon, the platform would not route it |

Every distinct `idaholegalaid.org` subdomain appearing anywhere in the repo: `www` (27
occurrences), `old.` (10, documentation only), `mail.` (2), the `.idaholegalaid.org` cookie
domain (6), and one `your-multidev.` placeholder. Nothing else.

---

## 6. What this investigation could *not* establish

Stated plainly rather than glossed:

1. **Inbound traffic to `old.` was never measured.** `logs/nginx/*.gz` (63 files) omit the
   Host field from their log format, so the ~28k `idaholegalaid` matches per file are
   *referrers* only. Zero hits proves nothing was referred *from* `old.`, not that nothing
   *requested* it. Cloudflare analytics cannot fill the gap either — the record is DNS-only
   and unproxied, so its traffic never traversed the edge. The Pantheon domain-list check in
   §5 is the substantive answer: nothing was routable there in the first place.
2. **Off-repo references.** Printed letterhead, partner directories, or internal mailboxes
   referencing `old.` are outside every surface reachable from here. Given zero archive
   captures and zero index presence, the likelihood of a live consumer is very low.

Both residuals are bounded by the same fact: the target IP has answered nothing for as long
as it has been measurable, so any such reference is *already* broken and deleting the record
changes nothing for whoever holds it. Rollback is a 5-minute re-add (see the tracker).

---

## 7. Conclusion

Every surface that can be searched returns zero. The IP belongs to a third party and serves
nothing. The hostname is attached to no Pantheon environment, has never been archived, and is
not indexed.

**The evidence supports deletion.**
