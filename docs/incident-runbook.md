# Incident Runbook

## Named Responders
- **Primary Sentry responder:** `<NAME — fill after assignment>`
- **Escalation path:** Primary responder -> Platform Engineer -> Product Owner
- **Review cadence:** Weekly triage of open Sentry issues and alert noise ratio
- **On-call rotation:** `<ROTATION SCHEDULE — fill after setup, or "N/A" if single responder>`

## Initial Triage
1. Determine whether the signal started in Sentry, New Relic, Pantheon, or Drupal logs.
2. Capture:
   - `environment`
   - `release`
   - `git_sha`
   - `site_name`
   - `assistant_name`
   - `request_id` when present
3. Check whether the issue is:
   - backend exception
   - browser regression
   - assistant/API failure
   - deploy/change regression

## Sentry Path
1. Filter by `environment` and `assistant_name:aila` when assistant-related.
2. Inspect stack trace, tags, replay, and linked release.
3. If browser-only, verify source maps and replay availability for the release.
4. If cron-related, verify the cron monitor status and recent `drush cron` activity.

## New Relic Path
1. Check APM transaction throughput, error rate, and p95/p99 latency.
2. Review Browser page-load and Ajax requests for `/assistant/api/*`.
3. Confirm whether a deployment marker aligns with the start of the issue.
4. Check synthetics before declaring a site-wide outage.

## Assistant / AILA Path
1. Correlate `request_id` across Drupal logs, Sentry tags, and assistant responses.
2. Check `/assistant/api/health` and `/assistant/api/metrics`.
3. Confirm whether the failure is deterministic retrieval/policy/safety logic or an optional LLM path.
4. Verify that no prompt or user-form text appears in captured telemetry.

## Rollback / Recovery
1. If the issue lines up with a deployment marker, consider Pantheon rollback first.
2. If it is observability-only noise, reduce or disable the specific browser/replay path rather than backing out unrelated application code.
3. If New Relic or Sentry account-side configuration is missing, classify verification as partial instead of forcing runtime changes.
