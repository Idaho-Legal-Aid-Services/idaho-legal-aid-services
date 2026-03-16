# Observability

## Ownership
- Sentry owns backend errors, browser errors, stack traces, trace correlation, replay, assistant/browser incident capture, and release/source-map workflows.
- New Relic owns Pantheon APM, browser performance/RUM, change tracking, synthetics, SLO dashboards, and account-level alert/workflow routing.

## Environment Naming
- `local`
- `pantheon-dev`
- `pantheon-test`
- `pantheon-live`
- `pantheon-multidev-<name>`

These labels are produced at runtime in `settings.php` and reused across Sentry tags, browser telemetry, and New Relic custom attributes.

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
- New Relic Browser must be injected through `NEW_RELIC_BROWSER_SNIPPET`; apply replay masking and obfuscation there before storing it as a runtime secret.

## Assistant / AILA Notes
- Shared tags: `assistant_name=aila`, `site_name`, `site_id`, `pantheon_env`, `multidev_name`, `runtime_context`, `release`, `git_sha`
- Browser assistant events:
  - `ilas:assistant:error`
  - `ilas:assistant:action`
- Backend assistant failures continue to flow through `AssistantApiController`, Langfuse, Drupal logs, and Sentry with the same `request_id`.

## Operational Ownership
- TOVR-03 status on 2026-03-16: account-side Sentry verification now confirms that project slug `php` currently receives both AILA PHP and browser events, ownership is mapped via `tags.assistant_name:aila -> evancurry@idaholegalaid.org`, permanent live AILA issue rules exist, and local release uploads for `test_155` and `test_156` succeeded. This section remains `Unverified` only because GitHub Actions still has no successful post-fix `Observability Release` run and fresh browser JS stack frames still do not resolve back to original source coordinates.
- **Project owner:** `Evan Curry <evancurry@idaholegalaid.org>`
- **Triage cadence:** Weekly review of Sentry issue stream, alert noise ratio, and unresolved incidents.
- **Review artifact location:** `docs/aila/runtime/phard-01-sentry-operationalization.txt`
- **Alert routing:** Sentry alerts route directly to the project owner’s member email (`evancurry@idaholegalaid.org`) for the three permanent live AILA rules.
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
  - fetch rendered HTML and confirm New Relic snippet and Sentry trace headers
  - verify New Relic change-tracking markers after a deploy
  - verify Sentry release association and source-map upload for the deployed release
