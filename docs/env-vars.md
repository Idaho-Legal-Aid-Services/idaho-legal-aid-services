# Environment Variables

This document is intentionally organized by where a value belongs. The biggest
source of confusion in this repo is mixing:

- Pantheon runtime secrets
- GitHub Actions secrets/variables
- Pantheon support inputs
- local-only DDEV values

## Pantheon Runtime Secrets

These are values the Drupal runtime or Pantheon Quicksilver hooks actually read
on hosted environments.

### Sentry

- `SENTRY_DSN`
  - Required backend Sentry DSN.
- `SENTRY_BROWSER_DSN`
  - Optional browser/frontend DSN.
  - If omitted, runtime falls back to `SENTRY_DSN`.
- `SENTRY_CRON_MONITOR_ID`
  - Optional Sentry cron monitor slug for Drupal cron.

### New Relic

- `NEW_RELIC_BROWSER_SNIPPET`
  - Full copy/paste Browser snippet, including `<script>...</script>`.
- `NEW_RELIC_API_KEY`
  - New Relic User key used by Pantheon Quicksilver change tracking.
- `NEW_RELIC_ENTITY_GUID_APM`
  - Target APM entity GUID for deploy change tracking.
- `NEW_RELIC_ENTITY_GUID_BROWSER`
  - Target Browser entity GUID for deploy change tracking.

### ILAS Site Assistant

- `ILAS_LEGALSERVER_ONLINE_APPLICATION_URL`
  - Required runtime-only LegalServer intake URL for assistant health checks.
  - Must be an absolute `https` URL and include the LegalServer `pid` and `h`
    query keys.
  - Required on Pantheon `dev`, `test`, and `live` if you want
    `checks.retrieval_configuration` to stay healthy across promotion.

## GitHub Actions Secrets and Variables

These are used by CI/release workflows, not by Drupal runtime.

### GitHub Actions secrets

- `SENTRY_AUTH_TOKEN`
  - Used for Sentry release creation and source-map upload.

### GitHub Actions variables

- `SENTRY_ORG_SLUG`
- `SENTRY_PROJECT_SLUG_BROWSER`

## Pantheon Support / Account-Side Inputs

These values are needed for operator workflows, but Drupal does not read them
at runtime on Pantheon.

### New Relic account and support values

- `NEW_RELIC_ACCOUNT_ID`
  - Needed for Pantheon BYO APM setup.
  - Not secret.
- `NEW_RELIC_LICENSE_KEY`
  - Needed for Pantheon BYO APM setup.
  - Secret.

### Reference-only Sentry values

- `SENTRY_ORG_SLUG`
- `SENTRY_PROJECT_SLUG_PHP`
- `SENTRY_PROJECT_SLUG_BROWSER`

These may be written in docs because they are not credentials, but do not put
DSNs or auth tokens in committed files.

## Environment / Metadata

These are either provided by Pantheon automatically or used as safe metadata.

- `PUBLIC_SITE_URL`
- `PANTHEON_SITE_NAME`
- `PANTHEON_SITE_ID`
- `PANTHEON_ENVIRONMENT`
- `PANTHEON_DEPLOYMENT_IDENTIFIER`
- `GITHUB_SHA`
- `SOURCE_VERSION`

## Local DDEV

Use local-only files for local values. Do not commit real credentials.

- Use `.ddev/.env` or `.ddev/.env.local`
- Start from `.ddev/.env.example`
- Set `ILAS_LOCAL_BROWSER_OBSERVABILITY=1` only if you intentionally want local
  browser injection when the corresponding secrets exist
- Keep `.ddev/config.newrelic.yaml` local-only
- Start from `.ddev/config.newrelic.yaml.example`

### Optional local-only New Relic values

- `NEW_RELIC_ACCOUNT_ID`
- `NEW_RELIC_LICENSE_KEY`
- `NEW_RELIC_BROWSER_SNIPPET`
- `NEW_RELIC_API_KEY`
- `NEW_RELIC_ENTITY_GUID_APM`
- `NEW_RELIC_ENTITY_GUID_BROWSER`

## Hosted Pantheon Rules

- Prefer Pantheon runtime secrets for hosted credentials.
- Do not commit DSNs, auth tokens, API keys, license keys, or browser snippets.
- Pantheon release tagging comes from `PANTHEON_DEPLOYMENT_IDENTIFIER`.
- `SENTRY_AUTH_TOKEN` belongs in GitHub Actions, not Pantheon runtime, unless
  you have a very specific operational reason outside this repo's normal flow.
