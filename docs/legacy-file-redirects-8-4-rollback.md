# Rollback — §8.4 legacy-file redirects (tracker item 7)

Undoes the eight redirects created on live on 2026-07-29. Ordered least- to most-invasive; stop as
soon as the site is in the state you want.

**State file:** `docs/legacy-file-redirects-8-4-state.json` — records the pre-change snapshot, the
created rids, and the backup reference. Everything below depends on it.

**What was changed:** eight `redirect` content entities, rid **5157, 5158, 5160, 5161, 5162, 5163,
5164, 5165**. Legacy-prefix redirect count went 70 → 78.

**What was *not* changed:** no content entity, and therefore **no content revision**. A whole-database
scan of all 1,660 text/varchar/blob columns found zero current content containing
`sites/idaholegalaid.org/files`, both before and after. There is nothing to revert in revision
history — step 3 below explains how to re-verify that claim rather than take it on faith.

> A ninth redirect (rid 5159, `MANUFACTURED HOMES.brochure.pdf`) was created and then removed during
> verification. It is already rolled back. See `docs/legacy-file-content-owner-review.md` §1.1.

---

## 1. Delete the redirects

```bash
# From the repo root. Strips the opening tag (eval takes code, not a file) and
# base64-wraps to avoid the terminus quoting trap.
B64=$(sed '1s/^<?php//' scripts/seo/legacy-file-redirects-8-4.php | base64 -w0)
ST=$(base64 -w0 docs/legacy-file-redirects-8-4-state.json)

terminus drush idaho-legal-aid-services.live -- php:eval \
  "file_put_contents('/tmp/ilas-8-4-state.json', base64_decode('$ST'));
   \$GLOBALS['ILAS_LEGACY_REDIRECT_ARGS']=['--rollback','/tmp/ilas-8-4-state.json'];
   eval(base64_decode('$B64'));"
```

Expected output — one line per rid, then:

```
Deleted 8. Legacy-prefix redirects now: 70 (expected 70).
Content revisions: none to restore — this change never modified content.
```

If a rid reports "already gone", someone deleted it by hand; that is not an error.

If the script has been deployed to live, the same thing without the base64 dance:

```bash
terminus drush idaho-legal-aid-services.live -- php:script \
  scripts/seo/legacy-file-redirects-8-4.php -- --rollback docs/legacy-file-redirects-8-4-state.json
```

## 2. Clear both cache layers — required

Deleting the redirect is not enough. Two caches will keep serving the 301 after the entity is gone,
and this bit was learned the hard way during the original rollout: purging Cloudflare alone left a
Pantheon-Fastly-cached response in place, and the change appeared not to have worked.

```bash
# Pantheon Global CDN (Fastly) — must run first, it is the upstream cache.
terminus env:clear-cache idaho-legal-aid-services.live

# Then Cloudflare, apex and www, for the same URLs.
scripts/observability/cloudflare-purge-urls.sh --file <urls.txt>
```

The URL list is the eight legacy paths in percent-encoded form, each on both
`https://idaholegalaid.org` and `https://www.idaholegalaid.org` (16 URLs). Reconstruct it from
`rid_map` in the state file, or reuse the list in the original run.

## 3. Verify

```bash
H=https://live-idaho-legal-aid-services.pantheonsite.io
UA='Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120.0 Safari/537.36'
curl -sIL -A "$UA" "$H/sites/idaholegalaid.org/files/<encoded-path>" \
  | grep -iE '^HTTP|^location|^content-type|^content-length'
```

Probe the origin, not the edge: Cloudflare's WAF 403s bare operator IPs, so a 403 there tells you
nothing about the redirect.

Expect all eight back to **404, 0 redirect hops**. Also confirm the three intentional-404 image
paths are untouched, and re-run the whole-DB scan to confirm content is still clean:

```bash
terminus drush idaho-legal-aid-services.live -- php:eval "\$db=\Drupal::database();
  print \$db->query(\"SELECT COUNT(*) FROM paragraph__field_external_link
    WHERE field_external_link_uri LIKE '%sites/idaholegalaid.org/files%'\")->fetchField();"
```

Expect `0` — the same result as before the change. The only `idaholegalaid.org/files` matches
anywhere in the database are the twelve `/files/html/` rows, which this work never touched.

## 4. Last resort — full database restore

Only if the redirect table has been corrupted beyond the targeted delete. This reverts **all**
database changes since the backup, including unrelated editorial work, so treat it as a genuine
last resort and check what else has changed first.

```bash
terminus backup:list idaho-legal-aid-services.live --element=db
# Target: idaho-legal-aid-services_live_2026-07-29T19-14-10_UTC_database.sql.gz
#         created 2026-07-29T19:15:19Z, expires 2027-07-29

terminus backup:restore idaho-legal-aid-services.live --element=db
```

Then run step 2 (both cache layers) again.

---

## Re-applying after a rollback

`scripts/seo/legacy-file-redirects-8-4.php --apply` is idempotent — it skips any source path that
already has a redirect — so it is safe to re-run. Always `--dry-run` first, and note that the
manufactured-homes row has been removed from the script's entry list on purpose; re-adding it needs
a legal-currency sign-off, not a code change.
