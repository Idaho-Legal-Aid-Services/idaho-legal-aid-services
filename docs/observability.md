# Observability

## Ownership
- Sentry owns backend errors, browser errors, stack traces, trace correlation, replay, assistant/browser incident capture, and release/source-map workflows.
- New Relic AILA integration was retired (TOVR-06, 2026-03-16). Pantheon platform-level APM is a separate concern.

## Environment Naming
- `local`
- `pantheon-dev`
- `pantheon-test`
- `pantheon-live`
- `pantheon-multidev-<name>`

These labels are produced at runtime in `settings.php` and reused across Sentry tags and browser telemetry.

## Release Naming
- Hosted environments use `PANTHEON_DEPLOYMENT_IDENTIFIER`.
- Git SHA is attached separately as `git_sha` when available.
- Use `scripts/observability/sentry-release.sh` to create/finalize the matching Sentry release and upload source maps after the Pantheon deploy exists.

## Sampling
- Sentry PHP traces: `local=1.0`, `pantheon-dev=0.5`, `pantheon-test=0.25`, `pantheon-live=0.10`, `pantheon-multidev-*=0.25`
- Sentry browser traces: `local=1.0`, `pantheon-dev=0.25`, `pantheon-test=0.10`, `pantheon-live=0.02`, `pantheon-multidev-*=0.05`
- Sentry replay: off by default locally; `dev/test=0.05 session / 1.0 on-error`; `live=0.01 session / 0.25 on-error`; `multidev=0.02 session / 1.0 on-error`
- Raven browser logs stay off by default to avoid redundant high-volume telemetry.

## Privacy / Scrubbing
- `send_default_pii` is forced off for Sentry.
- Backend Sentry callbacks scrub event messages, exception values, request/context payloads, transactions, and structured logs before send.
- Browser Sentry helper redacts emails, bearer tokens, UUIDs, SSNs, and user/body/query payloads before capture.
- Assistant browser events never emit raw prompts or form text; they carry only minimized fields such as `feature`, `surface`, `status`, and `errorCode`.

## Assistant / AILA Notes
- Shared tags: `assistant_name=aila`, `site_name`, `site_id`, `pantheon_env`, `multidev_name`, `runtime_context`, `release`, `git_sha`
- Browser assistant events:
  - `ilas:assistant:error`
  - `ilas:assistant:action` (event contract preserved; handler is a no-op after NR retirement)
- Backend assistant failures continue to flow through `AssistantApiController`, Langfuse, Drupal logs, and Sentry with the same `request_id`.

## Operational Ownership
- TOVR-03 status on 2026-03-16: account-side Sentry verification now confirms that project slug `php` currently receives both AILA PHP and browser events, ownership is mapped via `tags.assistant_name:aila -> evancurry@idaholegalaid.org`, permanent live AILA issue rules exist, and local release uploads for `test_155` and `test_156` succeeded. This section remains `Unverified` only because GitHub Actions still has no successful post-fix `Observability Release` run and fresh browser JS stack frames still do not resolve back to original source coordinates.
- **Project owner:** `Evan Curry <evancurry@idaholegalaid.org>`
- **Triage cadence:** Weekly review of Sentry issue stream, alert noise ratio, and unresolved incidents.
- **Review artifact location:** `docs/aila/runtime/phard-01-sentry-operationalization.txt`
- **Alert routing:** Sentry alerts route directly to the project owner's member email (`evancurry@idaholegalaid.org`) for the three permanent live AILA rules.
- **Escalation:** No backup responder is configured; Evan is the sole responder and escalation target.

## Approved Sentry Payload
The `SentryOptionsSubscriber` class defines the approved payload schema via constants:
- **`APPROVED_TAGS`** — The only tag keys that may appear on outbound Sentry events: `environment`, `pantheon_env`, `multidev_name`, `site_name`, `site_id`, `php_sapi`, `runtime_context`, `assistant_name`, `release`, `git_sha`, `intent`, `safety_class`, `fallback_path`, `request_id`, `env`.
- **`SENSITIVE_KEYS`** — Always fully redacted to `[REDACTED]`: `authorization`, `cookie`, `set-cookie`, `x-csrf-token`, `password`, `token`, `session`, `session_id`.
- **`BODY_LIKE_KEYS`** — PII-scrubbed but structurally preserved: `data`, `body`, `message`, `prompt`, `response`, `content`, `query_string`.
- **`SEND_DEFAULT_PII`** — Invariant: always `false`.

Contract tests in `SentryPayloadContractTest.php` enforce that these constants match the runtime enforcement logic.

## Approved Browser Sentry Payload
- `ilas:assistant:error` may send only bounded operational context needed for browser incident triage: `surface`, `pageMode`, `feature`, `errorCode`, `status`, `promptForFeedback`, and scrubbed arbitrary strings.
- Browser payload keys `prompt`, `body`, `content`, and `message` are fully redacted to `[REDACTED]` before capture.
- Other string values are scrubbed for emails, bearer tokens, UUIDs, SSNs, and query-like user text before capture.
- Browser assistant tags are bounded to operational metadata: `environment`, `pantheon_env`, `site_name`, `assistant_name`, `release`, `route_name`, `assistant_surface`, `assistant_mode`, `assistant_feature`, `assistant_route`, and `error_code`.
- Replay must only load when runtime settings explicitly enable it, and the replay integration must use `maskAllText`, `maskAllInputs`, and `blockAllMedia`.

Contract tests in `observability-assistant-error.test.js` and `observability-noise-filter.test.js` enforce the browser payload and replay boundary.

## Scripted / Non-Browser Access Posture

Canonical allow/block posture at the Cloudflare edge for `idaholegalaid.org`
(zone `7aef3c4adc977c9f645472338b031450`, Business Website). Recorded here per validation
report §8.8 so future reviewers do not re-litigate it. Measured evidence — 14-day counts,
per-consumer breakdowns, and the reasoning behind each row — lives in
[`browser-spoofing-and-scripted-access-analysis.md`](browser-spoofing-and-scripted-access-analysis.md);
do not duplicate numbers here.

| Path / consumer | Posture | Mechanism |
|---|---|---|
| `/robots.txt`, `/.well-known/security.txt` (GET) | **Allowed to any client** — verified 200 | Skip rule `92082bed` (skips SBFM, BIC, security level) |
| `/assistant/api/session/bootstrap` (GET) | **Allowed** | Skip rule `db42c4d6` (SBFM skip) |
| Search engines — Googlebot, bingbot, Baiduspider, DuckDuckBot | **Allowed** | Verified-bot skip `64fae5be`, category `Search Engine Crawler` |
| **Better Stack, UptimeRobot** | **Allowed — load-bearing** | Verified-bot skip `64fae5be`, category `Monitoring & Analytics` |
| Social previews — facebookexternalhit, Twitterbot, Slack, Iframely | **Allowed** | Verified-bot skip `64fae5be`, category `Page Preview` |
| Accessibility tooling | **Allowed** | Verified-bot skip `64fae5be`, category `Accessibility` |
| Email / security scanners (incl. `MSOffice 16`) | **Allowed** | Verified-bot skip `64fae5be`, category `Security` |
| Webhooks | **Allowed** | Verified-bot skip `64fae5be`, category `Webhooks` |
| `/sitemap.xml` | **200 to verified bots; 403 to unverified scripted clients** | No skip rule. Indexing unaffected. **No skip added** — §8.8's "only if a real consumer is identified" was not met; the blocked clients are spoofing traffic, AI crawlers blocked by policy, and anonymous clients. |
| Taxonomy-term RSS feeds (`/taxonomy/term/{tid}/feed`, incl. `/es/`, `/nl/`, `/sw/`) | **200 to verified crawlers; 403 to plain scripted clients** | No skip rule. No feed *reader* consumer identified. If a partner reports a broken subscription, start from the analysis doc §5.3. |
| Public PDFs | **Allowed** | ⚠️ SBFM `023ec3b3` ("likely automated, static resources", challenge) **does hit PDFs** — the control most likely to affect legitimate document access |
| AI crawlers (GPTBot, ClaudeBot, etc. for training) | **403 by policy** | `ai_training=block`; `web/robots.txt` Content-Signal + Disallow groups. Intentional. |
| AI assistants / AI search | **Allowed** | AI policy `ai_user` / `ai_search`, not by bot bypass |
| Generic scripted clients — `curl`, `axios`, `python-requests`, `Go-http-client` | **403 by design** | TLS/HTTP2 fingerprint, not IP. Working as intended; no breakage reported. |
| Donation / employment integrations | **Unaffected** | Inbound only |
| `Archiver` (`archive.org_bot`), `Aggregator` (`Pinterestbot`) | **Reaching content today, but not skipped** | Not in `64fae5be`'s retained category list. No adverse action observed. Recorded, not changed. |

**Do not remove `Monitoring & Analytics` from skip rule `64fae5be`.** It is what keeps the
site's own uptime monitoring working. Note also that a verified monitoring bot on this zone
uses a plain `python-requests` client — permitted by *category*, which is why scripted access
must not be reasoned about by user-agent family.

Cloudflare recategorises verified bots without notice, and a silent recategorisation is the
most likely way this posture breaks. Both
`scripts/observability/cloudflare-seo-bot-observation.sh` and
`scripts/observability/cloudflare-browser-spoofing-analysis.sh` carry a cross-category
integrity check for exactly this; run either after any Cloudflare bot-management change.

**Browser-spoofing enforcement: none.** No rule targets browser-spoofing automation, in any
mode. The population is identified and ~99 % already absorbed by SBFM and AI Labyrinth; two
of §8.7's mandatory gates (JA4 comparison, Log-mode observation) are unavailable on this
plan. See the analysis doc §7 before proposing one.

## Verification Checklist
- Local:
  - `ddev restart`
  - `ddev composer install`
  - `ddev drush cr`
  - `ddev drush updb -y`
  - build theme assets when needed
  - trigger one backend exception, one browser error, and `ddev drush cron`
- Pantheon:
  - verify `raven` runtime config on `dev`, `test`, `live`
  - fetch rendered HTML and confirm Sentry trace headers
  - verify Sentry release association and source-map upload for the deployed release
