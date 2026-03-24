# Manual Steps: Sentry

## Projects and SCM
1. Current verified topology on 2026-03-16: the Sentry org `idaho-legal-aid-services` currently exposes project slug `php`, and both AILA PHP and browser events resolve there. Use `php` for `SENTRY_PROJECT_SLUG_BROWSER` unless the Sentry org is intentionally restructured.
2. Connect the Sentry org `<SENTRY_ORG_SLUG>` to GitHub repo `<GITHUB_REPO_SLUG>`.
3. Add code mappings for this repository and enable suspect commits.
4. Review `CODEOWNERS` and mirror the same ownership logic in Sentry ownership rules if needed.
5. If a dedicated browser project is introduced later, update `SENTRY_PROJECT_SLUG_BROWSER` and rerun TOVR-03 release/event proof.

## Runtime Secrets
1. Provide `SENTRY_DSN` to Pantheon runtime secrets for backend capture.
2. Provide `SENTRY_BROWSER_DSN` to Pantheon runtime secrets for browser capture.
3. Provide `SENTRY_AUTH_TOKEN` only to CI/manual release tooling, not to Drupal runtime.
4. A write-capable local `SENTRY_AUTH_TOKEN` can be used for manual release upload, rule verification, and ownership updates, but it must remain a short-lived local session secret and never move into Pantheon runtime.
5. Optional: provide `SENTRY_CRON_MONITOR_ID` after creating the Drupal cron monitor.

## Releases and Source Maps
1. After the code deploy exists on Pantheon, run:
   `bash scripts/observability/sentry-release.sh --site <PANTHEON_SITE_NAME> --env <pantheon-env> --org <SENTRY_ORG_SLUG> --project <SENTRY_PROJECT_SLUG_BROWSER>`
2. Or use the manual GitHub workflow `Observability Release` with the Pantheon deployment identifier as `release_name`.
3. Current repo behavior expects modern `sentry-cli` syntax: `releases ...` plus `sourcemaps upload ...`. GitHub Actions run `23164126480` on 2026-03-16 proved the build path but failed at the older CLI syntax before this repo fix reached GitHub.
4. Local write-capable verification on 2026-03-16 successfully finalized releases `test_155` and `test_156`, but the current upload bundle only contains one actual sourcemap: `~/themes/custom/b5subtheme/css/style.css.map`.
5. Verify the release contains uploaded source maps or artifact bundles for `~/themes/custom/b5subtheme`, then confirm a fresh browser exception resolves to original source coordinates before calling JS de-minification proven.

## Alerts and Monitors
1. Create issue alerts for:
   - backend exception spikes
   - browser error spikes
   - assistant-specific failures (`assistant_name:aila`)
2. Create metric/transaction alerts for latency and error-rate regressions.
3. Create a cron monitor for Drupal cron:
   - **Name:** Website
   - **Slug:** website
   - **Schedule type:** crontab
   - **Schedule:** `0 * * * *` (hourly, matching Pantheon's actual cron cadence)
   - **Timezone:** UTC
   - **Check-in margin:** 30 minutes (absorbs Pantheon's ~15 min cron jitter)
   - **Max runtime:** 5 minutes (typical runs are 3-12s; budget guard allows up to 120s)
   - **Failure issue threshold:** 3 (alert only after 3 consecutive misses, i.e. ~3 hours without cron)
   - **Recovery threshold:** 1 (resolve after first successful check-in)
   - Store the monitor slug in Pantheon runtime secret `SENTRY_CRON_MONITOR_ID`.
   - The Raven module's cron hook automatically sends in_progress/ok check-ins on every `drush cron` run.
4. Add a public uptime monitor for the live site homepage.

## Verification Evidence (PHARD-01)
1. Run the synthetic probe on each environment:
   ```
   terminus remote:drush idaho-legal-aid-services.dev -- ilas:sentry-probe
   terminus remote:drush idaho-legal-aid-services.test -- ilas:sentry-probe
   terminus remote:drush idaho-legal-aid-services.live -- ilas:sentry-probe
   ```
2. Record each event ID in the runtime evidence artifact: `docs/aila/runtime/phard-01-sentry-operationalization.txt`.
3. Locate each event in Sentry.io by event ID and verify:
   - Tags match `APPROVED_TAGS` (environment, pantheon_env, php_sapi, runtime_context, etc.)
   - No raw PII in message, extra, or breadcrumbs
   - `send_default_pii` is false (no IP or cookies captured)
4. Screenshot or link the Sentry event detail page as evidence.

## Alert Configuration
1. Current verified AILA live rules on 2026-03-16:
   - `AILA live issues -> Evan` (`16801471`) — `assistant_name=aila`, `environment=pantheon-live`
   - `AILA live backend issues -> Evan` (`16801472`) — `assistant_name=aila`, `environment=pantheon-live`, `platform=php`
   - `AILA live browser issues -> Evan` (`16801473`) — `assistant_name=aila`, `environment=pantheon-live`, `platform=javascript`
2. Current ownership rule on project `php`:
   - `tags.assistant_name:aila evancurry@idaholegalaid.org`
3. Temporary `pantheon-test` proof rules were used on 2026-03-16 to prove route execution and then deleted:
   - backend proof `16801475` lastTriggered `2026-03-16T20:51:57.078326Z`
   - browser exception proof `16801486` lastTriggered `2026-03-16T20:53:56.099404Z`
4. If alert delivery proof must be repeated, recreate a temporary `pantheon-test` rule scoped to the exact proof tag, capture `lastTriggered`, confirm the mailbox receipt, then remove the rule.

## Operational Owner
- **Named owner:** `Evan Curry <evancurry@idaholegalaid.org>`
- **Backup/escalation:** `None configured; Evan is the sole responder`
- **Review cadence:** Weekly, documented in `docs/observability.md` Operational Ownership section.

## Feedback / Triage
1. Verify the browser project accepts replay and report-dialog traffic.
2. Confirm assistant/browser incidents show `assistant_name=aila`, `route_name`, `release`, and `git_sha`.
3. Optional MCP setup for Codex:
   `codex mcp add sentry --url https://mcp.sentry.dev/mcp`
