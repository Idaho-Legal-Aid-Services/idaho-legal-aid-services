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

### ILAS Site Assistant

- `ILAS_COHERE_API_KEY`
  - Runtime-only request-time Cohere API key for bounded ambiguous-intent
    classification.
- `ILAS_LLM_ENABLED`
  - Runtime-only rollout toggle for request-time assistant classification.
  - Honored on `local`, `dev`, `test`, and `live` when set truthy.
- `ILAS_GEMINI_API_KEY`
  - Optional runtime API key retained only for residual Search API AI paths
    that still prove a Gemini dependency.
  - The active Pinecone embeddings path no longer reads Gemini.
- `ILAS_PINECONE_API_KEY`
  - Required runtime Pinecone API key for vector index queries and refreshes.
- `ILAS_VECTOR_SEARCH_ENABLED`
  - Runtime-only rollout toggle for vector retrieval supplementation.
  - Truthy values are honored only on `local`, `dev`, and `test`.
  - `live` is hard-forced back to `false` in `settings.php` even if this
    toggle is set.
  - On `dev` / `test`, `settings.php` also checks
    `private://ilas-vector-search-enabled.txt` whenever vector search is still
    effectively disabled, including the case where a site-level falsey secret
    masks an env-level enablement override.
- `ILAS_VOYAGE_API_KEY`
  - Runtime Voyage AI API key for Pinecone embeddings and second-stage
    reranking.
- `ILAS_VOYAGE_ENABLED`
  - Runtime-only rollout toggle for Voyage reranking.
  - Honored on `local`, `dev`, `test`, and `live` when set truthy.
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
- `GITGUARDIAN_API_KEY`
  - Used by the dedicated GitHub Actions `GitGuardian CI` workflow for
    `ggshield` secret scanning.
  - Prefer a GitGuardian service-account token when the workspace is on the
    Business plan; otherwise use a personal access token from the GitGuardian
    API section.

### GitHub Actions variables

- `SENTRY_ORG_SLUG`
- `SENTRY_PROJECT_SLUG_BROWSER`

## Pantheon Support / Account-Side Inputs

These values are needed for operator workflows, but Drupal does not read them
at runtime on Pantheon.

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
- Put `ILAS_COHERE_API_KEY`, `ILAS_GEMINI_API_KEY`, and
  `ILAS_PINECONE_API_KEY` only in local env
  files, never in committed config.
- Put `ILAS_VOYAGE_API_KEY` only in local env files, never in committed
  config.
- Keep `ILAS_LLM_ENABLED=0` by default and only set it to `1` when
  intentionally verifying request-time Cohere rollout behavior.
- Keep `ILAS_VECTOR_SEARCH_ENABLED=0` by default and only set it to `1` when
  intentionally verifying non-live vector rollout behavior.
- Set `ILAS_LOCAL_BROWSER_OBSERVABILITY=1` only if you intentionally want local
  browser injection when the corresponding secrets exist

## Hosted Pantheon Rules

- Prefer Pantheon runtime secrets for hosted credentials.
- Do not commit DSNs, auth tokens, API keys, license keys, or browser snippets.
- Pantheon release tagging comes from `PANTHEON_DEPLOYMENT_IDENTIFIER`.
- `SENTRY_AUTH_TOKEN` belongs in GitHub Actions, not Pantheon runtime, unless
  you have a very specific operational reason outside this repo's normal flow.
