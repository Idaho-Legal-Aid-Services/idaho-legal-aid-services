# Manual Steps: Sentry

## Projects and SCM
1. Create or confirm two Sentry projects:
   - PHP: `<SENTRY_PROJECT_SLUG_PHP>`
   - Browser: `<SENTRY_PROJECT_SLUG_BROWSER>`
2. Connect the Sentry org `<SENTRY_ORG_SLUG>` to GitHub repo `<GITHUB_REPO_SLUG>`.
3. Add code mappings for this repository and enable suspect commits.
4. Review `CODEOWNERS` and mirror the same ownership logic in Sentry ownership rules if needed.

## Runtime Secrets
1. Provide `SENTRY_DSN` to Pantheon runtime secrets for backend capture.
2. Provide `SENTRY_BROWSER_DSN` to Pantheon runtime secrets for browser capture.
3. Provide `SENTRY_AUTH_TOKEN` only to CI/manual release tooling, not to Drupal runtime.
4. Optional: provide `SENTRY_CRON_MONITOR_ID` after creating the Drupal cron monitor.

## Releases and Source Maps
1. After the code deploy exists on Pantheon, run:
   `bash scripts/observability/sentry-release.sh --site <PANTHEON_SITE_NAME> --env <pantheon-env> --org <SENTRY_ORG_SLUG> --project <SENTRY_PROJECT_SLUG_BROWSER>`
2. Or use the manual GitHub workflow `Observability Release` with the Pantheon deployment identifier as `release_name`.
3. Verify the release contains uploaded source maps for `~/themes/custom/b5subtheme`.

## Alerts and Monitors
1. Create issue alerts for:
   - backend exception spikes
   - browser error spikes
   - assistant-specific failures (`assistant_name:aila`)
2. Create metric/transaction alerts for latency and error-rate regressions.
3. Create a cron monitor for Drupal cron and store its monitor ID in `SENTRY_CRON_MONITOR_ID`.
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
1. Document each Sentry alert rule after creation:
   - **Rule name:** `<fill>`
   - **Conditions:** `<fill>` (e.g., "New issue seen more than 5 times in 1 hour")
   - **Actions:** `<fill>` (e.g., "Send email to project owner")
   - **Environments:** `<fill>` (e.g., "pantheon-live, pantheon-test")
2. Test alert delivery and record proof (email/Slack screenshot) in the evidence artifact.

## Operational Owner
- **Named owner:** `<NAME — fill after assignment>`
- **Backup/escalation:** `<NAME — fill after assignment>`
- **Review cadence:** Weekly, documented in `docs/observability.md` Operational Ownership section.

## Feedback / Triage
1. Verify the browser project accepts replay and report-dialog traffic.
2. Confirm assistant/browser incidents show `assistant_name=aila`, `route_name`, `release`, and `git_sha`.
3. Optional MCP setup for Codex:
   `codex mcp add sentry --url https://mcp.sentry.dev/mcp`
