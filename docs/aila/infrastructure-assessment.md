# ILAS Infrastructure & AI Services — Evidence-Based Technical and Operational Assessment

**Date:** 2026-03-25
**Scope:** Full-stack inventory, Drupal/Pantheon coupling analysis, cloud migration viability, cost modeling, and transition planning for Idaho Legal Aid Services
**Method:** Automated codebase analysis of the `idaho-legal-aid-services` repository plus cross-reference with authoritative public documentation

---

## PART 1 — Stack Inventory

### 1.1 Drupal Core

| Attribute | Value | Evidence |
|---|---|---|
| Core version | Drupal 11 (`drupal/core ^11`) | `composer.json` line 37 |
| Platform layer | Drupal CMS 1.1.3 (`drupal/drupal_cms ^1.1.3`) | `composer.json` line 39 |
| PHP version | 8.3 | `pantheon.upstream.yml` line 5, `.ddev/config.yaml` |
| Database | MariaDB 10.6 | `pantheon.upstream.yml` line 7 |
| Cache backend | Redis (PhpRedis) | `web/sites/default/redis.settings.inc` |
| Search | Search API + Search API DB (database backend) | `composer.json`, `config/install/search_api.server.database.yml` |
| Drush | 13.6 (repo) / Pantheon drush v10 (platform) | `composer.json`, `pantheon.upstream.yml` line 8 |

**Category: Confirmed from provided artifacts**

### 1.2 Hosting & Runtime

| Component | Where it runs | Evidence |
|---|---|---|
| **Pantheon PaaS** | All environments (dev/test/live) | `pantheon.upstream.yml`, `settings.pantheon.php` |
| **DDEV** | Local development | `.ddev/config.yaml` (drupal11, MariaDB 10.6, nginx-fpm) |
| **Domain** | `idaholegalaid.org` | `web/robots.txt` sitemap declaration |
| **HTTPS** | Transitional enforcement | `pantheon.upstream.yml` line 4 |
| **CDN** | Pantheon Global CDN (Fastly-backed) | `PantheonServiceProvider11` cache integration in `settings.pantheon.php` |
| **Build step** | Enabled (composer install on deploy) | `pantheon.upstream.yml` line 9 |

**Category: Confirmed from provided artifacts**

### 1.3 Pantheon-Specific Files & Configuration

| File | Purpose | Coupling level |
|---|---|---|
| `pantheon.upstream.yml` | PHP 8.3, MariaDB 10.6, HTTPS, drush v10, build_step, protected paths | Platform config |
| `pantheon.yml` | Override placeholder (minimal: `api_version: 1` only) | Platform config |
| `web/sites/default/settings.pantheon.php` | DB credentials via `PRESSFLOW_SETTINGS`, hash salt from `PANTHEON_ENVIRONMENT`, Twig cache on `PANTHEON_ROLLING_TMP`, `PantheonServiceProvider11` for edge cache clearing, trusted host patterns | Deep coupling |
| `web/sites/default/settings.php` | `_ilas_get_secret()` using `pantheon_get_secret()` with `getenv()` fallback; 14 secrets; live hard-gates for LLM/vector/Voyage; environment detection via `PANTHEON_ENVIRONMENT` | Medium coupling (abstracted) |
| `web/sites/default/redis.settings.inc` | Redis config via `REDIS_HOST`/`REDIS_PORT` env vars | Portable |
| `scripts/deploy/pantheon-deploy.sh` | Deployment pipeline script | Platform-specific |
| `scripts/pull-live.sh` | Terminus-based DB/files sync to DDEV | Dev tooling |
| `pantheon-systems/drupal-integrations` (^11) | Composer package for Pantheon compatibility | Platform package |

**Category: Confirmed from provided artifacts**

### 1.4 Contributed Modules (Key Categories)

**AI & Machine Learning (9 packages):**
- `drupal/ai`, `drupal/ai_agents`, `drupal/ai_image_alt_text`
- `drupal/ai_provider_anthropic`, `drupal/ai_provider_openai` (dormant — not actively used by assistant)
- `drupal/ai_provider_google_vertex` (uninstalled per TOVR-14, composer package retained)
- `drupal/gemini_provider` (active — used for Pinecone embeddings)
- `drupal/ai_vdb_provider_pinecone` (active — with custom patch for query-only timeout)
- `drupal/ai_seo`, `drupal/metatag_ai` (uninstalled per TOVR-14)

**Search (6 packages):**
- `drupal/search_api`, `drupal/search_api_db`, `drupal/search_api_autocomplete`
- `drupal/search_api_exclude`, `drupal/search_api_page`
- `drupal/simple_search_form`

**SEO (12+ packages):**
- `drupal/metatag` (26 submodules), `drupal/schema_metatag`
- `drupal/simple_sitemap`, `drupal/pathauto`, `drupal/hreflang`, `drupal/yoast_seo`, `drupal/redirect`

**Content Management (10+ packages):**
- `drupal/paragraphs`, `drupal/layout_paragraphs`, `drupal/entity_reference_revisions`
- `drupal/scheduler`, `drupal/webform`, `drupal/field_group`

**Translation (3 packages):**
- `drupal/tmgmt`, `drupal/tmgmt_google`, `drupal/google_translator`

**Security (5+ packages):**
- `drupal/seckit`, `drupal/captcha`, `drupal/antibot`, `drupal/honeypot`, `drupal/turnstile`

**Observability (1 package):**
- `drupal/raven` (Sentry integration)

**Email (3 packages):**
- `drupal/easy_email`, `drupal/mailsystem`, `drupal/symfony_mailer_lite`

**Total contrib module count:** ~88 packages in `composer.json`

**Category: Confirmed from provided artifacts**

### 1.5 Custom Modules

| Module | Path | Description | AI/External dependency |
|---|---|---|---|
| **ilas_site_assistant** | `web/modules/custom/ilas_site_assistant/` | Aila chatbot: 96+ services, 8 Drush commands, 12+ API routes, 217 test files | Gemini, Pinecone, Langfuse, Sentry, Voyage AI |
| **employment_application** | `web/modules/custom/employment_application/` | Employment form handler with CSRF/nonce/flood protection | None (independent) |
| **ilas_seo** | `web/modules/custom/ilas_seo/` | SEO: noindex, schema fixes | None |
| **ilas_adept** | `web/modules/custom/ilas_adept/` | ADEPT autism education module, GA4 + localStorage | GA4 only |
| **ilas_hotspot** | `web/modules/custom/ilas_hotspot/` | Interactive hotspot graphics | None |
| **ilas_announcement_overlay** | `web/modules/custom/ilas_announcement_overlay/` | Homepage announcement overlay | None |
| **ilas_donation_inquiry** | `web/modules/custom/ilas_donation_inquiry/` | Donation inquiry form + email | SMTP only |
| **ilas_redirect_automation** | `web/modules/custom/ilas_redirect_automation/` | 404 redirect automation | None |
| **ilas_resources** | `web/modules/custom/ilas_resources/` | Resource content type + Views | None |
| **ilas_security** | `web/modules/custom/ilas_security/` | Security hardening | None |
| **ilas_test** | `web/modules/custom/ilas_test/` | Testing suite | None |

**Category: Confirmed from provided artifacts**

### 1.6 Custom Theme

| Theme | Base | Description |
|---|---|---|
| **b5subtheme** | Bootstrap 5 (`drupal/bootstrap5`) | Custom subtheme with SCSS, custom regions, library overrides, CKEditor5 styling |

Libraries defined: global-styling, custom-scripts, search-overlay, scroll-behaviors, lazy-loading, language-switcher

**Category: Confirmed from provided artifacts**

### 1.7 Frontend JavaScript Integrations

| Component | File | Purpose | External calls |
|---|---|---|---|
| Assistant widget | `js/assistant-widget.js` (~2500 LOC) | Chat UI with CSRF, abort timeout, ARIA, sanitization | `/assistant/api/*` (same-origin) |
| Observability JS | `js/observability.js` | Browser-side Sentry/observability config | Sentry SaaS |
| Premium application | `b5subtheme/js/premium-application.js` | Employment application wizard | Same-origin |

**Category: Confirmed from provided artifacts**

### 1.8 External API Integrations (Complete Inventory)

| Service | Endpoint | Auth method | Production status | Evidence |
|---|---|---|---|---|
| **Cohere** | `api.cohere.com/v2/chat` | Bearer token (`ILAS_COHERE_API_KEY`) | Request-time ambiguous-intent classification only; runtime-toggle controlled and conservative in export/live | `settings.php`, `CohereLlmTransport.php`, `LlmEnhancer.php` |
| **Google Gemini API** | Search API / provider-managed Gemini endpoints | API key (`ILAS_GEMINI_API_KEY`) | Residual Search API/vector-only dependency until prove-and-clean removal is complete; not used by the custom assistant request-time path | `settings.php`, `config/search_api.server.pinecone_vector.yml`, `current-state.md` |
| **Google Vertex AI** | Historical retired custom assistant surface | Retired from current request-time path | Modules uninstalled and custom assistant wiring removed; kept only as historical evidence in older TOVR docs | `docs/aila/runtime/tovr-14-ai-provider-footprint-rationalization.txt`, `current-state.md` |
| **Pinecone** | Via `ai_vdb_provider_pinecone` plugin | API key (`ILAS_PINECONE_API_KEY`) | Active on dev/test; hard-gated OFF on live | `settings.php:585-588` |
| **Voyage AI** | `api.voyageai.com/v1/rerank` | Bearer token (`ILAS_VOYAGE_API_KEY`) | Hard-gated OFF on live | `settings.php:618-621`, `VoyageReranker.php` |
| **Langfuse** | `us.cloud.langfuse.com/api/public/ingestion` | Basic auth (public+secret key) | **ACTIVE in all environments** (auto-enabled when keys present) | `settings.php:532-536`, `current-state.md:48` |
| **Sentry** | DSN-based (via `drupal/raven`) | DSN | **ACTIVE in all environments** | `settings.php:623+`, `current-state.md:49` |
| **LegalServer** | External intake URL | URL secret (`ILAS_LEGALSERVER_ONLINE_APPLICATION_URL`) | Active (link target only, no API call) | `settings.php:519-522` |
| **Google Translate (TMGMT)** | Google Translate API | API key (`TMGMT_GOOGLE_API_KEY`) | Active | `settings.php:484-487` |
| **Cloudflare Turnstile** | Turnstile CAPTCHA | Site+secret key | Active | `settings.php:469-476` |
| **SMTP (Symfony Mailer)** | Configured SMTP server | Password | Active | `settings.php:451-454` |
| **Promptfoo** | N/A (CI/CD tool, not runtime) | N/A | Active in GitHub Actions | `.github/workflows/quality-gate.yml` |

**Category: Confirmed from provided artifacts**

### 1.9 Cron, Queues & Background Jobs

| Component | Mechanism | Constraint | Evidence |
|---|---|---|---|
| **Drupal cron** | Pantheon hourly trigger → `drush cron` | Hourly cadence, shared with all modules | Pantheon platform default |
| **ilas_site_assistant_cron()** | Hook cron: analytics cleanup, conversation log cleanup, safety threshold checks, vector index hygiene | 120-second budget guard | `ilas_site_assistant.module` |
| **LangfuseExportWorker** | Queue worker (`ilas_langfuse_export`), cron time 60s | Processes during cron only; max depth 10K; max item age 3600s | `LangfuseExportWorker.php` |
| **Sentry Cron Monitor** | External monitor checks cron execution | `SENTRY_CRON_MONITOR_ID` secret | `settings.php` |

**Key constraint:** No standalone daemon processes. All background work runs within Drupal cron's PHP request lifecycle on Pantheon.

**Category: Confirmed from provided artifacts**

### 1.10 Secrets & Config Management

**Representative runtime secrets and toggles** managed through `_ilas_get_secret()` and related runtime env handling (Pantheon runtime secrets → `getenv()` fallback):

| Secret | Service | Storage |
|---|---|---|
| `SMTP_PASSWORD` | Email | Pantheon runtime secret |
| `TURNSTILE_SITE_KEY` / `TURNSTILE_SECRET_KEY` | CAPTCHA | Pantheon runtime secret |
| `TMGMT_GOOGLE_API_KEY` | Translation | Pantheon runtime secret |
| `ILAS_GEMINI_API_KEY` | Residual Search API/vector Gemini provider | Pantheon runtime secret |
| `ILAS_COHERE_API_KEY` | Request-time Cohere classification | Pantheon runtime secret |
| `LANGFUSE_PUBLIC_KEY` / `LANGFUSE_SECRET_KEY` | Observability | Pantheon runtime secret |
| `ILAS_PINECONE_API_KEY` | Vector DB | Pantheon runtime secret |
| `ILAS_VOYAGE_API_KEY` | Reranking | Pantheon runtime secret |
| `ILAS_LLM_ENABLED` | Request-time LLM runtime toggle | Pantheon runtime secret / env flag |
| `ILAS_VECTOR_SEARCH_ENABLED` | Feature toggle | Pantheon runtime secret |
| `ILAS_VOYAGE_ENABLED` | Feature toggle | Pantheon runtime secret |
| `SENTRY_DSN` / `SENTRY_BROWSER_DSN` | Error tracking | Pantheon runtime secret |
| `SENTRY_CRON_MONITOR_ID` | Cron monitoring | Pantheon runtime secret |
| `ILAS_ASSISTANT_DIAGNOSTICS_TOKEN` | Machine auth | Pantheon runtime secret |
| `ILAS_LEGALSERVER_ONLINE_APPLICATION_URL` | External link | Pantheon runtime secret |

**Architecture:** All secrets resolve at runtime from environment, never exported in Drupal config. The `_ilas_get_secret()` helper already has a `getenv()` fallback, making secrets portable to any hosting platform that supports environment variables.

**Category: Confirmed from provided artifacts**

### 1.11 CI/CD & Build/Deploy Pipelines

| Pipeline | Trigger | Purpose | Evidence |
|---|---|---|---|
| **Quality Gate** (GitHub Actions) | Push/PR to master/release/* | PHPUnit (pure-unit, drupal-unit, kernel) + Promptfoo (widget hardening, hosted evals) | `.github/workflows/quality-gate.yml` |
| **Observability Release** (GitHub Actions) | Manual (workflow_dispatch) | Sentry release + source-map upload | `.github/workflows/observability-release.yml` |
| **GitGuardian** (GitHub Actions) | Push | Secret scanning | `.github/workflows/gitguardian-ci.yml` |
| **Deploy to Pantheon** | Git push to Pantheon remote | Composer build step → deploy | `scripts/deploy/pantheon-deploy.sh` |
| **Git workflow scripts** | Manual | Branch management, publish, sync | `scripts/git/publish.sh`, `finish.sh`, `sync-master.sh` |
| **Pre-push hooks** | Git hook | Strict quality checks before push | `scripts/ci/pre-push-strict.sh` |

**Category: Confirmed from provided artifacts**

### 1.12 Data Flows

```
User Browser
  ├─► Pantheon CDN (Fastly) ─► Drupal (PHP-FPM)
  │     ├─► MariaDB 10.6 (sessions, content, analytics, queues)
  │     ├─► Redis (cache bins, flood control, locks)
  │     ├─► Search API DB (lexical FAQ + resource search)
  │     ├─► [DISABLED ON LIVE] Pinecone (vector fallback search)
  │     ├─► [RUNTIME-TOGGLED] Cohere (request-time ambiguous-intent classification)
  │     ├─► [RESIDUAL / PROVE-AND-CLEAN] Gemini provider (Search API/vector behavior)
  │     ├─► [DISABLED ON LIVE] Voyage AI (post-retrieval reranking)
  │     ├─► [ACTIVE] Langfuse (trace export via cron queue)
  │     └─► [ACTIVE] Sentry (error/performance tracking)
  ├─► Sentry Browser SDK (client-side errors)
  └─► /assistant/api/* (CSRF-protected JSON API)

GitHub Actions
  ├─► PHPUnit quality gate
  ├─► Promptfoo evaluation (against live/dev assistant URL)
  ├─► GitGuardian secret scanning
  └─► Sentry release upload (manual)
```

**Category: Confirmed from provided artifacts**

---

## PART 2 — Drupal/Pantheon Coupling Analysis

### 2.1 Component-by-Component Classification

| Component | Classification | Rationale |
|---|---|---|
| **Drupal CMS core** | Pantheon-dependent | `settings.pantheon.php` handles DB credentials, hash salt, edge cache, Twig cache, trusted hosts |
| **ilas_site_assistant module** (all 96+ services) | **Drupal-embedded, Pantheon-independent** | Zero Pantheon references in module code. All integration via standard Drupal config/settings APIs |
| **Search API DB** | Drupal-embedded | Uses MariaDB backend, no external dependency |
| **Pinecone integration** | Cloud-portable (SaaS) | HTTP API calls from Drupal service, key from environment variable |
| **Cohere request-time LLM** | Cloud-portable (SaaS) | HTTP API calls from Drupal service, key from environment variable |
| **Residual Gemini Search API provider** | Cloud-portable (SaaS) | Search API/provider-managed integration still wired by Drupal config until removal is proven safe |
| **Voyage AI reranking** | Cloud-portable (SaaS) | HTTP API calls from Drupal service, key from environment variable |
| **Langfuse observability** | Cloud-portable (SaaS) | HTTP API calls from queue worker, keys from environment variable |
| **Sentry** | Cloud-portable (SaaS) | DSN-configured SDK, no Pantheon dependency |
| **Redis cache** | Pantheon-compatible, portable | `REDIS_HOST`/`REDIS_PORT` env vars with `localhost` fallback |
| **Promptfoo evaluation** | External already | Runs in GitHub Actions, no Pantheon dependency |
| **b5subtheme** | Drupal-embedded | Standard Drupal theme, no platform dependency |
| **employment_application** | Drupal-embedded | No external dependencies |
| **Other custom modules** | Drupal-embedded | No external dependencies beyond SMTP |

**Category: Confirmed from provided artifacts**

### 2.2 Coupling Dimensions

| Dimension | Coupling level | Details |
|---|---|---|
| **Code coupling** | **Low for AI stack, Medium for CMS** | The assistant module has zero Pantheon-specific code. All 96+ services use standard Drupal APIs. Pantheon coupling is isolated in `settings.php` and `settings.pantheon.php`. |
| **Runtime coupling** | **Medium** | `PRESSFLOW_SETTINGS` for DB, `PANTHEON_ENVIRONMENT` for environment detection (10+ uses), `PANTHEON_ROLLING_TMP` for Twig cache, `pantheon_get_secret()` for secrets (14 uses with `getenv()` fallback). |
| **Deployment coupling** | **High** | Git push to Pantheon remote, `build_step: true` runs `composer install`, Terminus CLI for DB/file sync, `scripts/deploy/pantheon-deploy.sh`. |
| **Data coupling** | **Low** | All data in MariaDB (portable). No Pantheon-proprietary data formats. Redis is standard. |
| **Authentication coupling** | **Low** | Standard Drupal auth. No Pantheon SSO. Service-to-service auth via env var API keys (portable). |
| **Networking/domain coupling** | **Medium** | Pantheon manages DNS routing, SSL certificates, CDN (Fastly-backed). Domain transfer requires DNS changes + CDN replacement. |
| **Logging/monitoring coupling** | **Low** | Sentry is SaaS. Langfuse is SaaS. Drupal watchdog is standard. Pantheon logging is supplementary only. |
| **Editorial/admin workflow coupling** | **None** | Standard Drupal admin. No Pantheon-specific editorial features used. |
| **Cost coupling** | **Medium** | Pantheon plan includes hosting + CDN + Redis + MariaDB + dev/test/live environments + auto-scaling. Separating these would increase cost. |
| **Vendor lock-in** | **Low-Medium** | The `_ilas_get_secret()` abstraction already provides a portable secret retrieval path. The main lock-in is deployment workflow (git push → Pantheon build) and environment management (dev/test/live promotion). |

**Category: Confirmed from provided artifacts + Inferred from evidence**

### 2.3 Pantheon Constraints That Matter

| Constraint | Impact on AI stack | Evidence |
|---|---|---|
| **No long-running processes** (PHP request ~120s max) | Langfuse queue worker limited to 60s/cron run; cron budget guard at 120s | `ilas_site_assistant.module` cron budget guard |
| **No custom daemons/workers** | Cannot run continuous queue consumers, embedding pipelines, or batch jobs | Pantheon architecture (confirmed from Pantheon docs) |
| **No persistent local disk** beyond `/tmp` | Cannot store large model files, embeddings, or persistent caches outside DB/Redis | `PANTHEON_ROLLING_TMP` in `settings.pantheon.php` |
| **Cron runs hourly** (default) | Queue drain frequency limited; Langfuse traces batch-processed hourly | Pantheon platform default |
| **No WebSocket support** | Cannot do streaming LLM responses or real-time updates | Pantheon architecture |
| **No custom server software** | Cannot run self-hosted Langfuse, Qdrant, Weaviate, or other services | Pantheon architecture |
| **Composer build on deploy** | Adds deploy time but handles dependency management | `pantheon.upstream.yml build_step: true` |

**Category: Confirmed from provided artifacts (code) + Confirmed from authoritative public documentation (Pantheon constraints)**

### 2.4 What Can Stay on Pantheon Without Issue

- Drupal CMS, all content management, theming, editorial workflows
- Search API DB (lexical search) — active and working
- Rule-based assistant pipeline (intent routing, FAQ search, resource discovery, safety classification)
- Session management, CSRF protection, rate limiting, analytics logging
- All custom modules except ilas_site_assistant's external API integrations
- Redis cache
- Deployment pipeline (git push → Pantheon build)
- Environment promotion (dev → test → live)

### 2.5 What Is Already External to Pantheon

| Service | Status | Connection from Drupal |
|---|---|---|
| Sentry | SaaS, active | DSN-configured SDK, outbound HTTPS |
| Langfuse | SaaS, active (all envs) | Queue worker POSTs to `us.cloud.langfuse.com`, outbound HTTPS |
| Pinecone | SaaS, active (dev/test) | Plugin-abstracted HTTP calls, outbound HTTPS |
| Gemini API | SaaS, active (embeddings) | `LlmEnhancer.php` HTTPS POST, outbound HTTPS |
| Voyage AI | SaaS, present (gated) | `VoyageReranker.php` HTTPS POST, outbound HTTPS |
| Promptfoo | GitHub Actions | No Drupal runtime connection |
| GitHub Actions CI/CD | SaaS | No Drupal runtime connection |
| Google Translate | SaaS | TMGMT module, outbound HTTPS |
| Cloudflare Turnstile | SaaS | JS + server-side validation, outbound HTTPS |

**Critical finding:** All AI/ML services are already external SaaS services communicating via outbound HTTPS from Drupal. There is no AI infrastructure running on Pantheon. The "AI stack" is a set of API keys and HTTP client calls, not resident services.

**Category: Confirmed from provided artifacts**

---

## PART 3 — Target-State Options Evaluation

### Key Framing Insight

The question is NOT "where to move the AI stack" — the AI stack already runs externally as SaaS services. The real questions are:

1. Should any SaaS services be replaced with self-hosted alternatives?
2. Is additional cloud infrastructure needed for capabilities Pantheon cannot provide (workers, pipelines)?
3. Should Drupal itself move off Pantheon?

### Option A: Keep Drupal on Pantheon, Enable SaaS AI Services (Minimal Change)

**Viability: VIABLE — Recommended**

| Aspect | Assessment |
|---|---|
| **What changes** | Lift live hard-gates in `settings.php` for LLM/vector/Voyage as governance criteria are met. All SaaS services already integrated and tested. |
| **Required managed services** | None new. Continue using existing Gemini, Pinecone, Langfuse, Sentry, Voyage SaaS subscriptions. |
| **Operational burden** | Minimal — same team, same platform, same deployment workflow. Only change is enabling toggles. |
| **Cost sensitivity** | Lowest. Only incremental cost is SaaS service usage (estimated $50-160/mo total for AI services). |
| **Nonprofit discounts** | Sentry for Good (free Team plan), Google for Nonprofits (Gemini credits), Cloudflare Project Galileo (free Enterprise CDN). |
| **Security/compliance** | No new attack surfaces. All existing PII redaction, CSRF, safety classification remains. |
| **Migration complexity** | Zero. No migration needed. |
| **Rollback difficulty** | Trivial — flip config toggles back to `FALSE`. |
| **Minimum technical skill** | Drupal developer familiar with the codebase. |
| **Major risks** | Langfuse queue drain limited to cron cadence (may lose traces under high load). Cron budget contention if all services enabled simultaneously. |
| **What stays on Pantheon** | Everything. |
| **What moves** | Nothing — already external. |
| **Unconfirmed** | Actual Langfuse queue loss rate under live traffic; Pantheon plan pricing; actual SaaS tier costs. |

### Option B: Keep Drupal on Pantheon, Enable SaaS AI + Azure

**Viability: CONDITIONALLY VIABLE — if Langfuse queue drain proves insufficient**

| Aspect | Assessment |
|---|---|
| **What changes** | Same as Option A, plus add Azure for: (1) lightweight worker for continuous Langfuse queue drain, (2) optional embedding pipeline for content updates, (3) optional API gateway for future streaming LLM. |
| **Required managed services** | Azure Container Apps (serverless containers) or Azure Functions, Azure Key Vault (secrets). |
| **Operational burden** | Low-Moderate. New infrastructure to maintain, but Azure Container Apps is near-serverless. |
| **Cost sensitivity** | Low. Azure Container Apps: ~$0-10/mo at low volume. Azure Functions: free tier covers light workloads. |
| **Nonprofit discounts** | **Azure for Nonprofits: $3,500/year in Azure credits (automatic upon enrollment). Up to $35,000/year in advanced grants (application required).** Microsoft 365 Business Premium free licenses also included. [Source: nonprofit.microsoft.com](https://nonprofit.microsoft.com) |
| **Security/compliance** | Adds cross-origin communication (Drupal → Azure worker). Requires secure credential passing. Azure Key Vault for secrets. |
| **Migration complexity** | Low. Build a small container/function that reads from Drupal's queue table or a webhook, pushes to Langfuse. |
| **Rollback difficulty** | Easy — disable worker, revert to cron-only queue processing. |
| **Minimum technical skill** | Docker basics + Azure portal familiarity. |
| **Major risks** | New infrastructure to monitor; Azure credential management; cross-platform debugging. |
| **What stays on Pantheon** | Everything (Drupal, content, search, API). |
| **What moves** | Only background worker(s) for queue processing/embedding. |
| **Unconfirmed** | Whether Azure nonprofit enrollment is approved; actual Azure Container Apps pricing at ILAS volume; whether the Langfuse queue actually needs continuous draining. |

### Option C: Keep Drupal on Pantheon, Enable SaaS AI + AWS

**Viability: CONDITIONALLY VIABLE — same use case as Option B**

| Aspect | Assessment |
|---|---|
| **What changes** | Same as Option B but on AWS. Lambda or ECS Fargate for workers. |
| **Required managed services** | AWS Lambda (serverless functions) or ECS Fargate (containers), AWS Secrets Manager or SSM Parameter Store. |
| **Operational burden** | Low-Moderate. Lambda is fully serverless. ECS Fargate requires container management. |
| **Cost sensitivity** | Low. Lambda: free tier covers 1M requests/mo + 400K GB-seconds. ECS Fargate: ~$5-15/mo for small task. |
| **Nonprofit discounts** | **AWS Imagine Grant: $1,000-$100,000 in credits. Application through TechSoup or direct.** [Source: aws.amazon.com/government-education/nonprofits/](https://aws.amazon.com/government-education/nonprofits/) |
| **Security/compliance** | Same cross-origin considerations as Option B. |
| **Migration complexity** | Low. Lambda function reads queue, pushes to Langfuse. |
| **Rollback difficulty** | Easy. |
| **Minimum technical skill** | AWS console + Lambda basics. |
| **Major risks** | AWS complexity can grow rapidly; billing surprises without budget alerts. |
| **What stays on Pantheon** | Everything. |
| **What moves** | Only background worker(s). |
| **Unconfirmed** | Whether AWS Imagine Grant is approved; approval timeline (4-8 weeks typical). |

### Option D: Keep Drupal on Pantheon, Enable SaaS AI + DigitalOcean

**Viability: CONDITIONALLY VIABLE — simplest sidecar option**

| Aspect | Assessment |
|---|---|
| **What changes** | Same as Option B but on DigitalOcean. Small Droplet or App Platform worker. |
| **Required managed services** | DigitalOcean Droplet ($4-6/mo) or App Platform worker ($5/mo). |
| **Operational burden** | Low. Droplet requires basic Linux admin. App Platform is more managed. |
| **Cost sensitivity** | Lowest of cloud options. $4-6/mo for a basic Droplet. |
| **Nonprofit discounts** | **Limited. DigitalOcean has no documented nonprofit program.** Hatch program is startup-focused. Community credits occasionally available. |
| **Security/compliance** | Same cross-origin considerations. Secrets in Droplet env or App Platform env vars. |
| **Migration complexity** | Lowest. Simplest platform. |
| **Rollback difficulty** | Easiest — destroy Droplet. |
| **Minimum technical skill** | Basic Linux + SSH. |
| **Major risks** | No nonprofit discount; less enterprise-grade tooling; manual scaling. |
| **What stays on Pantheon** | Everything. |
| **What moves** | Only background worker(s). |
| **Unconfirmed** | Whether DigitalOcean has any current nonprofit offerings. |

### Options E/F: Hybrid Migration of Drupal-Adjacent Services (AWS/Azure/DO)

**Viability: NOT RECOMMENDED at this time**

**Rationale:** There are no Drupal-adjacent services that need to move. The assistant module runs entirely within Drupal's PHP process. The only potential sidecar is a queue worker, which is covered by Options B-D. Moving Drupal-adjacent services (like search) to a separate platform would add complexity without benefit — Search API DB works fine on Pantheon's MariaDB.

### Options G/H/I: Full Migration Off Pantheon (AWS/Azure/DO)

**Viability: NOT RECOMMENDED**

| Reason | Details |
|---|---|
| **No technical driver** | Pantheon can host the Drupal CMS perfectly well. The AI services are already external. |
| **Cost increase** | Self-managing MariaDB + Redis + PHP + CDN + SSL + environments + backups costs more than Pantheon's bundled PaaS. |
| **Ops burden increase** | A small nonprofit team would need to manage: database backups/failover, Redis clustering, PHP-FPM tuning, SSL renewal, CDN configuration, staging environments, deployment pipeline. |
| **Risk** | High migration risk for zero user-facing benefit. |
| **Estimated monthly cost** | $150-500/mo for infrastructure alone (before ops labor), vs. $50-100/mo Pantheon plan (estimated). |
| **When this changes** | Only consider if: (1) Pantheon pricing becomes prohibitive, (2) a major feature requires WebSockets/streaming at scale, (3) the team grows to include dedicated DevOps staff. |

**Category: Confirmed (architecture analysis) + Inferred from evidence (cost estimates) + Needs validation (actual Pantheon pricing)**

---

## PART 4 — Service Mapping

### 4.1 Current Stack → Cloud Provider Mapping

| Current component | Current provider | If AWS needed | If Azure needed | If DigitalOcean needed |
|---|---|---|---|---|
| **Web hosting (Drupal)** | Pantheon (KEEP) | EC2/ECS ($30-80/mo) | App Service ($20-50/mo) | Droplet ($12-24/mo) |
| **MariaDB** | Pantheon (KEEP) | RDS ($15-30/mo) | Azure DB ($15-30/mo) | Managed MySQL ($15/mo) |
| **Redis** | Pantheon (KEEP) | ElastiCache ($13-25/mo) | Azure Cache ($13-25/mo) | Managed Redis ($15/mo) |
| **CDN/SSL** | Pantheon Global CDN (KEEP) | CloudFront ($5-15/mo) | Azure CDN ($5-15/mo) | Cloudflare free tier |
| **Background workers** | Drupal cron (limited) | Lambda (free tier) | Container Apps ($0-10/mo) | Droplet ($4-6/mo) |
| **Queue service** | Drupal DB queue | SQS (free tier 1M/mo) | Service Bus ($10/mo) | Redis-based or DB queue |
| **Secrets management** | Pantheon runtime secrets | Secrets Manager ($0.40/secret/mo) | Key Vault ($0.03/10K ops) | Env vars (free) |
| **LLM** | Gemini API (KEEP) | Bedrock (alternative) | Azure OpenAI (alternative) | N/A (no LLM service) |
| **Vector DB** | Pinecone SaaS (KEEP) | OpenSearch Serverless ($24+/mo) | AI Search ($50+/mo) | N/A (no vector service) |
| **Observability** | Langfuse SaaS (KEEP) | N/A (keep SaaS) | N/A (keep SaaS) | Self-hosted Langfuse ($6-12/mo Droplet) |
| **Error tracking** | Sentry SaaS (KEEP) | N/A (keep SaaS) | N/A (keep SaaS) | N/A (keep SaaS) |
| **Evaluation** | Promptfoo (GitHub Actions, KEEP) | N/A | N/A | N/A |
| **CI/CD** | GitHub Actions (KEEP) | CodePipeline (alternative) | Azure DevOps (alternative) | N/A |
| **DNS** | Pantheon (KEEP) | Route 53 ($0.50/zone) | Azure DNS ($0.50/zone) | Cloudflare (free) |

**Key insight:** The "If AWS/Azure/DigitalOcean needed" column only applies to full migration (Options G/H/I), which is not recommended. For Options B-D (sidecar only), only the "Background workers" row applies.

### 4.2 Hidden Cost Drivers

| Service | Hidden cost | Risk level |
|---|---|---|
| Gemini API | Token-based pricing can spike with high conversation volume or long prompts | Medium (mitigated by `CostControlPolicy` with daily/monthly caps) |
| Pinecone | Storage-based pricing grows with vector count; Standard plan jumps from $0 to $70/mo | Medium (ILAS corpus is small, likely fits free Starter tier) |
| Langfuse | Observation-based pricing; Pro plan at $59/mo if free tier exceeded | Low (ILAS traffic likely within Hobby free tier limits) |
| AWS/Azure data egress | Outbound data transfer charges on full migration | N/A if staying on Pantheon |
| Sentry | Event-based pricing; Developer plan free but limited | Low (Sentry for Good provides free Team plan for nonprofits) |

### 4.3 Feature Gaps

| Gap | Details | Impact |
|---|---|---|
| **DigitalOcean: No managed LLM service** | Cannot replace Gemini with a DO-native service | None — Gemini SaaS is fine |
| **DigitalOcean: No managed vector DB** | Cannot replace Pinecone with a DO-native service | None — Pinecone SaaS is fine |
| **AWS/Azure: LLM alternatives (Bedrock/Azure OpenAI)** | Could replace Gemini but would require code changes to `LlmEnhancer.php` | Low priority — Gemini works |
| **Self-hosted Langfuse** | Could save $59/mo on Pro tier but adds ops burden | Not recommended unless cost is critical |

**Category: Confirmed from provided artifacts (current stack) + Confirmed from authoritative public documentation (cloud service capabilities) + Inferred from evidence (pricing estimates)**

---

## PART 5 — Cost Analysis

### 5.1 Workload Assumptions

**These are ASSUMPTIONS based on codebase evidence (rate limits, SLO targets, cost control settings). Actual values need validation.**

| Metric | Assumed value | Basis |
|---|---|---|
| Monthly page views | 20,000-50,000 | Small-to-moderate nonprofit legal aid site |
| Monthly assistant conversations | 500-2,000 | Based on rate limits (15/min, 120/hr) suggesting moderate usage |
| Monthly LLM API calls (when enabled) | 1,000-5,000 | `cost_control.monthly_call_limit: 100000` is a ceiling; actual usage much lower |
| Average tokens per LLM call | ~300 input + ~150 output | `max_tokens: 150`, short prompts |
| Pinecone vector count | ~5,000-20,000 | Small FAQ + resource corpus across 2 indexes |
| Langfuse observations/month | 1,000-5,000 | 1:1 with assistant conversations at `sample_rate: 1.0` |
| Sentry events/month | 500-2,000 | Error tracking on a stable production site |
| Content updates/month | 50-200 | Typical nonprofit content update frequency |
| Embedding refreshes/month | 50-200 | Mirrors content updates for vector index hygiene |

### 5.2 Current Costs (Estimated)

| Item | Monthly cost | Confidence | Notes |
|---|---|---|---|
| Pantheon hosting | $50-200/mo | **Unconfirmed** | Depends on plan tier (Basic $50, Performance $175+). Nonprofit pricing may apply. |
| Sentry | $0-26/mo | Inferred | Developer plan free; Team $26/mo. Sentry for Good may provide free Team. |
| Langfuse | $0/mo (Hobby) | Inferred | Hobby plan: free, 50K observations/mo. ILAS likely within this. |
| Pinecone | $0/mo (Starter) | Inferred | Starter plan: free, 100K vectors, 1 index. May need Standard ($70/mo) for 2+ indexes. |
| Gemini API (embeddings only) | $1-5/mo | Inferred | Low embedding volume. `text-embedding-004` pricing: $0.00025/1K tokens. |
| Google Translate | $5-20/mo | Inferred | Translation volume likely low. $20/1M characters. |
| Cloudflare Turnstile | $0/mo | Confirmed | Free tier is generous. |
| SMTP | $0-10/mo | Unconfirmed | Depends on provider. |
| GitHub Actions | $0/mo | Confirmed | Free for public repos or 2000 min/mo for private. |
| **Current total** | **~$56-261/mo** | | |

### 5.3 Option A: Enable All SaaS Services (No Migration)

| Item | Monthly baseline | Monthly likely | Monthly worst-case | Notes |
|---|---|---|---|---|
| Pantheon hosting | $50-200 | $50-200 | $50-200 | No change |
| Sentry | $0 | $0 | $26 | Apply for Sentry for Good first |
| Langfuse | $0 | $0 | $59 | Hobby free; Pro if >50K obs/mo |
| Pinecone | $0 | $0 | $70 | Starter free (100K vectors, 1 index); Standard if >1 index needed on free tier |
| Gemini API (LLM + embeddings) | $5 | $15 | $50 | 5K calls × ~450 tokens avg × pricing |
| Voyage AI reranking | $0 | $5 | $15 | Pay-per-call, low volume |
| Existing services (translate, SMTP, etc.) | $5 | $15 | $30 | No change |
| **Total** | **$60** | **$285** | **$450** | |

**Cost minimization opportunities:**
- Apply for Sentry for Good → saves $26/mo
- Apply for Google for Nonprofits → potential Gemini credits
- Keep Langfuse on Hobby tier by tuning `sample_rate` if needed
- Pinecone Starter plan may suffice if vectors fit within 100K limit
- `CostControlPolicy` already enforces daily/monthly LLM caps

### 5.4 Option B-D: Pantheon + Sidecar Worker

| Item | Monthly cost (Option B: Azure) | Monthly cost (Option C: AWS) | Monthly cost (Option D: DO) |
|---|---|---|---|
| Everything in Option A | $60-450 | $60-450 | $60-450 |
| Sidecar worker | $0-10 (Container Apps) | $0 (Lambda free tier) | $4-6 (Droplet) |
| Secrets management | $0.40 (Key Vault) | $1-2 (Secrets Manager) | $0 (env vars) |
| **Additional cost** | **$0-11** | **$0-2** | **$4-6** |

**Nonprofit credits that offset sidecar costs:**
- Azure: $3,500/year ($291/mo) covers sidecar + much more — **Category: Confirmed from authoritative public documentation** ([nonprofit.microsoft.com](https://nonprofit.microsoft.com))
- AWS: $1,000-$100,000 one-time grant — **Category: Confirmed from authoritative public documentation** ([aws.amazon.com/government-education/nonprofits/](https://aws.amazon.com/government-education/nonprofits/))
- DigitalOcean: No confirmed nonprofit program — **Category: Confirmed (absence of program)**

### 5.5 Options G-I: Full Migration (for reference only — NOT recommended)

| Item | AWS (monthly) | Azure (monthly) | DigitalOcean (monthly) |
|---|---|---|---|
| Compute (PHP) | $30-80 | $20-50 | $12-24 |
| MariaDB/MySQL (managed) | $15-30 | $15-30 | $15 |
| Redis (managed) | $13-25 | $13-25 | $15 |
| CDN/SSL | $5-15 | $5-15 | $0 (Cloudflare) |
| Load balancer | $20 | $20 | $12 |
| Storage (S3/Blob/Spaces) | $1-5 | $1-5 | $5 |
| DNS | $0.50 | $0.50 | $0 |
| Monitoring | $0-10 | $0-10 | $0-5 |
| AI SaaS services | $60-450 | $60-450 | $60-450 |
| **Infrastructure subtotal** | **$145-635** | **$135-605** | **$119-511** |
| **Ops labor premium** (estimated) | **$500-2,000** | **$500-2,000** | **$300-1,500** |
| **Total** | **$645-2,635** | **$635-2,605** | **$419-2,011** |

The ops labor premium reflects staff time (or contractor cost) for: patching, backups, monitoring, SSL management, deployment pipeline maintenance, incident response, environment management. This is the single largest cost driver and the primary reason full migration is not recommended.

**Category: Inferred from evidence (cost estimates based on published pricing pages, subject to change)**

---

## PART 6 — Recommendation

### 6.1 Best Paths

| Category | Recommendation | Why |
|---|---|---|
| **Best immediate path** | **Option A: Enable SaaS services on Pantheon** | Zero migration, zero new infrastructure. Lift live hard-gates as governance criteria are met. All code is already written and tested. |
| **Best low-cost path** | **Option A** | $60-285/mo total with nonprofit discounts applied. No additional infrastructure costs. |
| **Best low-ops path** | **Option A** | Same team, same deployment workflow, same monitoring. The only "new" operational work is monitoring the newly-enabled SaaS integrations, which already have built-in circuit breakers, rate limiters, and health monitoring. |
| **Best long-term scalable path** | **Option A → Option B (Azure) when needed** | Start with Option A. If Langfuse queue drain, streaming LLM, or embedding pipelines are needed, add Azure sidecar using $3,500/yr nonprofit credits. Azure's nonprofit program is the most generous and predictable. |
| **Should Pantheon remain?** | **Yes, absolutely.** | Pantheon handles CMS hosting, CDN, SSL, environments, backups, scaling, and deployment. Replacing it would cost more and add significant ops burden for a small nonprofit. |

### 6.2 Decision Matrix

| Criterion | Weight | AWS | Azure | DigitalOcean |
|---|---|---|---|---|
| Nonprofit discount value | 25% | 4 ($1K-100K one-time grant, variable) | **5** ($3,500/yr automatic + $35K advanced) | 1 (none confirmed) |
| Ease of setup | 20% | 3 (Lambda is simple, but console is complex) | 4 (Container Apps is near-serverless) | **5** (Droplet is simplest) |
| Managed services breadth | 15% | **5** (most services) | **5** (most services) | 3 (fewer managed options) |
| Cost at ILAS scale | 15% | 4 (Lambda free tier) | **5** ($0 with credits) | 4 ($4-6/mo) |
| Operational simplicity | 15% | 3 (IAM complexity) | 4 (simpler IAM) | **5** (SSH + basic admin) |
| Future flexibility | 10% | **5** (most services) | **5** (most services) | 3 (limited AI services) |
| **Weighted score** | 100% | **3.80** | **4.70** | **3.35** |

**Winner for sidecar option: Azure** — primarily due to the $3,500/year automatic nonprofit credit, which fully covers the sidecar infrastructure cost and provides headroom for experimentation.

### 6.3 Do First / Do Later / Avoid for Now

**Do First (Weeks 1-4):**
1. Confirm Pantheon plan tier and verify/apply nonprofit pricing
2. Apply for nonprofit programs: Sentry for Good, Google for Nonprofits, Microsoft Azure Nonprofits
3. Verify Langfuse Hobby tier limits against actual usage
4. Verify Pinecone Starter tier limits against actual vector count
5. Monitor Langfuse queue health on live (already active) — measure trace loss rate
6. Resolve remaining quality gate blockers for live enablement (TOVR-13 items)

**Do Later (Weeks 5-12):**
7. Lift vector search live hard-gate (`settings.php:586`) when governance criteria met
8. Lift Voyage AI live hard-gate (`settings.php:619`) when governance criteria met
9. Evaluate LLM live enablement based on Promptfoo deep eval results
10. If Langfuse queue loss rate >5%, evaluate Azure sidecar worker (Option B)

**Avoid for Now:**
- Full migration off Pantheon (no technical driver, high cost/risk)
- Self-hosting Langfuse, Pinecone, or any other SaaS service (ops burden not justified)
- Replacing Gemini with AWS Bedrock or Azure OpenAI (unnecessary code change)
- Adding complex infrastructure (Kubernetes, Terraform, etc.)
- Building custom embedding pipelines (Gemini handles this via contrib module)

### 6.4 Dependency-Risk Summary

| Risk | Probability | Impact | Mitigation |
|---|---|---|---|
| Langfuse trace loss under live traffic | Low-Medium | Medium (gaps in observability, not service failure) | `QueueHealthMonitor` tracks loss; `SloAlertService` warns on thresholds; escalate to sidecar if >5% |
| Gemini API cost spike | Low | Medium | `CostControlPolicy` enforces daily cap (5,000 calls), monthly cap (100,000 calls), per-IP hourly cap (10 calls). Manual kill switch available. |
| Pinecone free tier exceeded | Low | Low | Monitor vector count; upgrade to Standard ($70/mo) if needed |
| Langfuse free tier exceeded | Low | Low | Tune `sample_rate` below 1.0 to reduce observation volume |
| Pantheon cron contention | Low | Low | 120-second budget guard already in place; vector hygiene is time-guarded |
| SaaS vendor pricing changes | Medium | Medium | No single vendor dependency; all services have circuit breaker fallback to rule-based mode |
| Nonprofit discount approval delays | Medium | Low | Can operate without discounts; apply early |

### 6.5 Confidence Rating

| Aspect | Confidence | Basis |
|---|---|---|
| Architecture analysis | **High** | Direct code inspection of all 96+ services, settings.php, settings.pantheon.php |
| Pantheon constraints | **High** | Code evidence + confirmed from Pantheon public documentation |
| SaaS service integration status | **High** | Verified from `current-state.md` (evidence-backed audit with 250+ claims) + code inspection |
| Recommendation (Option A) | **High** | Strong code evidence that all AI services are already decoupled and externalized |
| Cost estimates | **Medium** | Based on published pricing but unverified for ILAS-specific volume |
| Nonprofit discount availability | **Medium** | Based on public program pages; actual approval requires application |
| Sidecar necessity | **Low** | Depends on actual Langfuse queue loss rate under live traffic, which is not yet measured |

---

## PART 7 — Transition Blueprint

### Phase 0: Validation & Discovery (Week 1)

**Objective:** Establish baseline measurements and confirm assumptions.

**Tasks:**
1. Confirm Pantheon plan tier, pricing, and contract terms
2. Run `drush ilas:runtime-truth` on live to capture current enablement snapshot
3. Run `drush ilas:langfuse-status` on live to measure queue depth, loss rate, and export health
4. Query Langfuse dashboard for current observation count (verify Hobby tier headroom)
5. Query Pinecone dashboard for current vector count across indexes (verify Starter tier headroom)
6. Review Sentry dashboard for current event volume
7. Document actual monthly assistant conversation volume from analytics logger data
8. Gather ILAS 501(c)(3) documentation for nonprofit program applications

**Deliverables:**
- Baseline metrics document (traffic, conversations, queue health, SaaS tier usage)
- Pantheon billing confirmation
- 501(c)(3) documentation package ready for submissions

**Prerequisites:** Access to Pantheon dashboard, Langfuse dashboard, Pinecone dashboard, Sentry dashboard, Drupal admin reports.

**Risks:** Discovery may reveal unexpected SaaS tier limits or Pantheon constraints.

**Owner type needed:** Drupal developer with Pantheon dashboard access.

**Stop/go:** If Langfuse queue loss rate is already >10% on live, immediately prioritize Phase 2b (sidecar evaluation) over Phase 1 nonprofit applications.

### Phase 1: Architecture Decisions (Week 1-2)

**Objective:** Lock decisions on enablement sequence and sidecar necessity.

**Tasks:**
1. Review Phase 0 metrics against SaaS tier limits
2. Decide: Is Langfuse Hobby tier sufficient or need Pro ($59/mo)?
3. Decide: Is Pinecone Starter tier sufficient or need Standard ($70/mo)?
4. Decide: Is live LLM enablement deferred to Phase 4 or later?
5. Confirm enablement sequence: Langfuse (already active) → Vector search → Voyage → LLM
6. Decide: Is a sidecar worker needed now, later, or never?

**Deliverables:**
- Architecture decision record (ADR) documenting each decision with rationale
- Updated `docs/aila/roadmap.md` reflecting decisions

**Prerequisites:** Phase 0 metrics.

**Risks:** May need to adjust SaaS tiers before enabling additional features.

**Owner type needed:** Product owner + Drupal developer.

**Stop/go:** Stakeholder signoff on enablement sequence before proceeding.

### Phase 2: Environment Setup (Weeks 2-3)

**Objective:** Apply nonprofit discounts, verify SaaS tiers, prepare environments.

**Tasks:**
1. Apply for Sentry for Good (free Team plan)
2. Apply for Google for Nonprofits (Gemini credits, Google Workspace)
3. Apply for Microsoft Azure for Nonprofits ($3,500/yr credits) — even if sidecar not immediately needed, credits are valuable insurance
4. Apply for Cloudflare Project Galileo (if CDN redundancy desired)
5. Verify/upgrade SaaS tier plans as decided in Phase 1
6. Provision any missing runtime secrets on Pantheon (`terminus secrets:set`)
7. Deploy outstanding code from master branch to Pantheon dev/test
8. Verify `ilas:langfuse-status` and `ilas:runtime-diagnostics` on deployed environments

**Deliverables:**
- Nonprofit program enrollment confirmations
- SaaS tier verification document
- All environments deployed with current master code

**Prerequisites:** Phase 1 decisions, 501(c)(3) documentation.

**Risks:** Nonprofit program approvals may take 2-8 weeks. Proceed with standard pricing until approved.

**Owner type needed:** Organization administrator (for nonprofit applications) + Drupal developer (for Pantheon configuration).

**Stop/go:** At least Sentry for Good and Google for Nonprofits applications submitted before proceeding.

### Phase 3: Data/Services Enablement (Weeks 3-6)

**Objective:** Lift live hard-gates for vector search and Voyage AI.

**Tasks:**
1. Resolve remaining quality gate blockers (from TOVR-13): prompts 2/3 quality proof, embeddings timeout separation, green master quality gate run
2. Remove vector search live hard-gate: modify `settings.php:585-588` to allow live enablement
3. Set `ILAS_VECTOR_SEARCH_ENABLED=1` on Pantheon live via `terminus secrets:set`
4. Monitor vector search quality via Promptfoo protected-push checks
5. Monitor retrieval metrics via admin reports and `ilas:runtime-truth`
6. After 1-2 weeks stable: remove Voyage AI live hard-gate (`settings.php:618-621`)
7. Set `ILAS_VOYAGE_ENABLED=1` on Pantheon live
8. Monitor reranking quality via Promptfoo evals

**Deliverables:**
- Vector search live on production with monitoring active
- Voyage AI reranking live on production with monitoring active
- Quality gate passing on all protected branches

**Prerequisites:** Phase 2 environment setup; TOVR-13 blocker resolution; green master quality gate.

**Risks:** Vector search quality may be lower than lexical-only on some query types. Circuit breakers and fallback gates mitigate this.

**Owner type needed:** Drupal developer with Promptfoo evaluation experience.

**Stop/go:** If Promptfoo pass rate drops below 90% after vector search enablement, pause and investigate before enabling Voyage.

### Phase 4: LLM Enablement (Weeks 6-10)

**Objective:** Evaluate and potentially enable LLM enhancement on live.

**Tasks:**
1. Define stakeholder acceptance criteria for LLM on live
2. Run Promptfoo deep evaluation suite against LLM-enabled dev/test
3. Run Promptfoo abuse evaluation suite (105 tests) against LLM-enabled dev/test
4. Review cost projections: actual conversation volume × token pricing
5. If criteria met: remove LLM live hard-gate (`settings.php:403-404`)
6. Monitor via `CostControlPolicy`, `LlmCircuitBreaker`, `LlmRateLimiter`
7. Monitor cost via Gemini API dashboard

**Deliverables:**
- LLM evaluation report with Promptfoo results
- Cost projection validated against actual usage
- Live LLM enablement (if criteria met)

**Prerequisites:** Phase 3 stable; stakeholder acceptance criteria defined.

**Risks:** LLM may generate responses that approach legal advice boundary. Safety classifier + policy filter + post-generation checks mitigate this. `CostControlPolicy` prevents cost runaway.

**Owner type needed:** Drupal developer + product owner (for acceptance criteria) + compliance stakeholder (for legal advice boundary review).

**Stop/go:** Stakeholder signoff on Promptfoo evaluation results before live enablement. If any safety test fails, do not enable on live.

### Phase 5: Testing & Observability (Weeks 8-12)

**Objective:** Validate full stack under live conditions and establish operational baselines.

**Tasks:**
1. Run load tests (`scripts/load/run-loadtest.sh`) against live assistant endpoints
2. Monitor Langfuse queue health under increased load (from newly-enabled features)
3. Measure SLO metrics: availability, latency p95/p99, error rate, cron health, queue health
4. Verify Sentry alert routing for assistant-related errors
5. Verify Langfuse trace completeness (sample traces, check for gaps)
6. Run `ilas:runtime-diagnostics` weekly to track system health
7. Establish weekly review loop (per `review_loop` config in settings.yml)

**Deliverables:**
- SLO baseline document (actual vs. target for all SLO metrics)
- Load test results
- Operational monitoring dashboard (Sentry + Langfuse)
- Weekly review process established

**Prerequisites:** Phase 4 complete (or Phase 3 if LLM deferred).

**Risks:** Load testing may reveal performance bottlenecks. Cron budget contention may surface.

**Owner type needed:** Drupal developer + platform engineer.

**Stop/go:** If SLO violations exceed error budget (`error_budget_window_hours: 168`), pause further enablement and investigate.

### Phase 6: Sidecar Evaluation & Cutover (Week 10-12, if needed)

**Objective:** Evaluate whether a cloud sidecar worker is needed based on live data.

**Tasks:**
1. Analyze 4+ weeks of live Langfuse queue health data
2. Calculate trace loss rate, queue depth trends, processing latency
3. If loss rate >5% or queue depth regularly approaches 10K ceiling:
   a. Design sidecar worker architecture (Azure Container Apps recommended)
   b. Implement worker that reads from Drupal queue table or HTTP endpoint
   c. Deploy to Azure (using nonprofit credits)
   d. Verify continuous queue drain
   e. Monitor for 1-2 weeks
4. If loss rate <5%: document decision to stay on cron-only processing

**Deliverables:**
- Queue health analysis report
- Decision: sidecar needed or not (with evidence)
- If needed: deployed Azure sidecar worker

**Prerequisites:** Phase 5 observability data (4+ weeks).

**Risks:** Building a sidecar adds infrastructure complexity. Mitigated by using Azure Container Apps (near-serverless) and nonprofit credits.

**Owner type needed:** Developer with Docker + Azure basics (if sidecar needed); otherwise Drupal developer only.

**Stop/go:** Only build sidecar if live data justifies it. Do not build speculatively.

### Phase 7: Rollback & Steady State (Ongoing)

**Objective:** Document rollback procedures and establish ongoing operations.

**Rollback procedures (per component):**
| Component | Rollback method | Time to rollback |
|---|---|---|
| Vector search | Set `ILAS_VECTOR_SEARCH_ENABLED=0` on Pantheon | < 5 minutes |
| Voyage AI | Set `ILAS_VOYAGE_ENABLED=0` on Pantheon | < 5 minutes |
| LLM enhancement | Re-add `$config['ilas_site_assistant.settings']['llm.enabled'] = FALSE` to settings.php | < 15 minutes (deploy required) |
| Sidecar worker (if built) | Stop/delete container | < 5 minutes |
| All AI features | Disable via settings.php overrides | < 15 minutes (deploy required) |

**Ongoing operations:**
- Weekly: Review Sentry errors, Langfuse traces, admin reports, content gaps
- Monthly: Review cost across all SaaS services, verify nonprofit credits usage
- Quarterly: Run full Promptfoo evaluation suite, review SLO trends
- Annually: Renew nonprofit program enrollments, review architecture decisions

**Decommission checklist (if moving off a service):**
1. Disable feature toggle in settings.php
2. Deploy to all Pantheon environments
3. Remove runtime secret via `terminus secrets:delete`
4. Remove SaaS account (or downgrade to free tier)
5. Update `docs/aila/current-state.md` and `docs/aila/runbook.md`

---

## PART 8 — Missing Information Checklist

### Critical (blocks cost/architecture decisions)

| # | Missing item | Why it matters | How to get it | Priority |
|---|---|---|---|---|
| 1 | **Current Pantheon plan tier and monthly billing** | Determines cost baseline and whether nonprofit pricing applies | Check Pantheon dashboard → Account → Plan or billing page | P0 |
| 2 | **ILAS 501(c)(3) determination letter** | Required for all nonprofit program applications | Organization records / IRS correspondence | P0 |
| 3 | **Actual monthly assistant conversation volume** | Sizes all SaaS tiers and cost projections | Run SQL query on analytics_logger table or check admin reports at `/admin/reports/ilas-assistant` | P0 |
| 4 | **Langfuse queue loss rate on live** | Determines whether sidecar is needed | Run `drush ilas:langfuse-status` on live; check `QueueHealthMonitor` counters | P0 |
| 5 | **Pinecone current vector count and index count** | Verifies free tier sufficiency | Pinecone dashboard → Indexes | P1 |
| 6 | **Langfuse current observation count** | Verifies free tier sufficiency | Langfuse dashboard → Usage | P1 |
| 7 | **Stakeholder acceptance criteria for live LLM** | Gates Phase 4 | Product owner + compliance stakeholder meeting | P1 |

### Important (improves accuracy but doesn't block)

| # | Missing item | Why it matters | How to get it |
|---|---|---|---|
| 8 | Peak vs. average request rate for assistant endpoints | Right-sizes rate limiters and cost projections | Sentry performance dashboard or Pantheon traffic analytics |
| 9 | Content update frequency (nodes created/modified per month) | Determines embedding refresh volume | Drupal admin content overview |
| 10 | Budget ceiling for AI services (monthly hard cap) | Constrains SaaS tier choices | Finance/executive decision |
| 11 | Pantheon contract renewal date | Timing for pricing negotiation | Pantheon dashboard or contract |
| 12 | Whether Pantheon offers nonprofit pricing | Could reduce hosting cost | Ask Pantheon sales |
| 13 | Current Sentry plan and event volume | Verify free tier or existing plan | Sentry dashboard → Settings → Subscription |
| 14 | Whether ILAS already has Google for Nonprofits enrollment | May already have Gemini credits | Check admin.google.com or Google for Nonprofits portal |
| 15 | Resolution status of TOVR-13 blockers | Gates vector search live enablement | Review roadmap and quality gate status |

---

## PART 9 — Vendor Contact & Follow-Up List

### Pantheon

| Item | Details |
|---|---|
| **Purpose** | Confirm plan tier, inquire about nonprofit pricing, review contract terms |
| **Nonprofit program** | No confirmed nonprofit-specific program. However, Pantheon may offer custom pricing. |
| **Contact** | Pantheon dashboard → Support, or [pantheon.io/contact-us](https://pantheon.io/contact-us) |
| **Sales route** | Account manager (if assigned) or sales@pantheon.io |
| **What to ask** | "We are a 501(c)(3) legal aid nonprofit. Do you offer nonprofit pricing or discounts? What is our current plan tier and can we review options?" |
| **Evidence to have ready** | 501(c)(3) determination letter, current site URL, current plan details, annual budget context |

**Category: Needs validation (Pantheon nonprofit pricing is unconfirmed)**

### Sentry

| Item | Details |
|---|---|
| **Purpose** | Apply for Sentry for Good (free Team plan for nonprofits) |
| **Nonprofit program** | **Sentry for Good** — free Team plan for qualifying nonprofits |
| **Contact** | [sentry.io/for/good/](https://sentry.io/for/good/) |
| **Application** | Online form, requires nonprofit verification |
| **What to ask** | "We are a 501(c)(3) legal aid nonprofit and would like to apply for the Sentry for Good program." |
| **Evidence to have ready** | 501(c)(3) letter, current Sentry org URL, description of how Sentry is used |

**Category: Confirmed from authoritative public documentation**

### Google (Gemini API + Google for Nonprofits)

| Item | Details |
|---|---|
| **Purpose** | Enroll in Google for Nonprofits; access Gemini API credits; Google Workspace free |
| **Nonprofit program** | **Google for Nonprofits** — free Google Workspace, Google Ad Grants ($10K/mo in ads), potential Cloud credits |
| **Contact** | [google.com/nonprofits/](https://www.google.com/nonprofits/) or via TechSoup |
| **Application** | Requires TechSoup validation of nonprofit status |
| **What to ask** | "We use Google Gemini API for our legal aid chatbot. Are there credits or discounted pricing for nonprofits beyond the standard free tier?" |
| **Evidence to have ready** | 501(c)(3) letter, TechSoup membership, current Gemini API usage estimate |

**Category: Confirmed from authoritative public documentation (Google for Nonprofits exists; specific Gemini credits need verification)**

### Microsoft Azure

| Item | Details |
|---|---|
| **Purpose** | Enroll for $3,500/year Azure credits (automatic) and potentially $35,000 advanced grant |
| **Nonprofit program** | **Microsoft for Nonprofits** — $3,500/year Azure credits (automatic), up to $35,000/year advanced grants (application required), free Microsoft 365 Business Premium |
| **Contact** | [nonprofit.microsoft.com](https://nonprofit.microsoft.com) |
| **Application** | Online enrollment; requires nonprofit verification via Microsoft's eligibility checker |
| **What to ask** | "We are a 501(c)(3) and would like to enroll in Microsoft for Nonprofits for Azure credits. We also want to explore the advanced Azure grant for AI services." |
| **Evidence to have ready** | 501(c)(3) letter, organization details, planned Azure usage description |
| **Partner/architect route** | Microsoft has dedicated nonprofit solution architects accessible after enrollment |

**Category: Confirmed from authoritative public documentation**

### AWS

| Item | Details |
|---|---|
| **Purpose** | Apply for AWS Imagine Grant (credits) — only if sidecar or additional AWS services needed |
| **Nonprofit program** | **AWS Imagine Grant** — $1,000-$100,000 in AWS credits for nonprofits |
| **Contact** | [aws.amazon.com/government-education/nonprofits/](https://aws.amazon.com/government-education/nonprofits/) or via TechSoup |
| **Application** | Through TechSoup validation or direct AWS application |
| **What to ask** | "We are a 501(c)(3) legal aid nonprofit exploring AWS for AI/ML workloads (background workers, queue processing). Are we eligible for the Imagine Grant program?" |
| **Evidence to have ready** | 501(c)(3) letter, TechSoup membership, planned AWS usage, estimated annual spend |

**Category: Confirmed from authoritative public documentation (program exists; grant amounts and approval are variable)**

### DigitalOcean

| Item | Details |
|---|---|
| **Purpose** | Inquire about any nonprofit programs or credits |
| **Nonprofit program** | **None confirmed.** Hatch program is startup-focused, not nonprofit-specific. |
| **Contact** | [digitalocean.com/company/contact](https://www.digitalocean.com/company/contact) or community forums |
| **What to ask** | "We are a 501(c)(3) nonprofit. Do you offer any nonprofit discounts, credits, or special programs?" |

**Category: Confirmed (absence of documented program)**

### TechSoup

| Item | Details |
|---|---|
| **Purpose** | Central portal for nonprofit tech discounts; validates nonprofit status for Google, AWS, and others |
| **Contact** | [techsoup.org](https://www.techsoup.org) |
| **Application** | Register organization, validate nonprofit status |
| **What to prepare** | 501(c)(3) letter, EIN, organization details |

**Category: Confirmed from authoritative public documentation**

### Langfuse

| Item | Details |
|---|---|
| **Purpose** | Inquire about nonprofit pricing or OSS discount |
| **Nonprofit program** | **No documented nonprofit program.** Self-hosted OSS version is free. |
| **Contact** | hello@langfuse.com or in-app support |
| **What to ask** | "We are a 501(c)(3) legal aid nonprofit using Langfuse Cloud for LLM observability. Do you offer nonprofit pricing or discounts?" |

**Category: Confirmed (absence of documented program; needs direct inquiry)**

### Pinecone

| Item | Details |
|---|---|
| **Purpose** | Verify Starter plan limits; inquire about nonprofit pricing |
| **Nonprofit program** | **No documented nonprofit program.** |
| **Contact** | [pinecone.io/contact](https://www.pinecone.io/contact/) |
| **What to ask** | "We are a 501(c)(3) nonprofit using Pinecone for a legal aid chatbot. Our vector count is approximately [X]. Do you offer nonprofit pricing? Does the Starter plan support multiple indexes?" |

**Category: Confirmed (absence of documented program; needs direct inquiry)**

### Cloudflare

| Item | Details |
|---|---|
| **Purpose** | Apply for Project Galileo (free Enterprise-level protection for qualifying nonprofits) |
| **Nonprofit program** | **Cloudflare Project Galileo** — free Enterprise-level service for civil liberties, human rights, and public interest organizations |
| **Contact** | [cloudflare.com/galileo/](https://www.cloudflare.com/galileo/) |
| **What to ask** | "We are a legal aid nonprofit providing free legal services. We would like to apply for Project Galileo for DDoS protection and CDN services." |
| **Evidence to have ready** | 501(c)(3) letter, description of mission, current site URL |

**Category: Confirmed from authoritative public documentation (ILAS likely qualifies as public interest organization)**

---

## PART 10 — Execution Handoff Packet

```
BEGIN_EXECUTION_HANDOFF
project_summary:
  organization_type: "501(c)(3) legal aid nonprofit"
  current_primary_platform: "Pantheon PaaS"
  current_problem_statement: "Organization needs to understand how much of its AI/assistant stack is integrated into Drupal/Pantheon, whether transitioning to a cloud provider is viable, and what the optimal next steps are — with cost sensitivity and minimal ops burden."
  target_outcomes:
    - "Clear understanding of what runs where and what is coupled to what"
    - "Evidence-based recommendation on whether to stay, hybrid-migrate, or fully migrate"
    - "Actionable transition blueprint with phases and decision gates"
    - "Cost model appropriate for a small nonprofit"

current_stack:
  drupal:
    version: "Drupal 11 (drupal/core ^11)"
    hosting: "Pantheon PaaS (PHP 8.3, MariaDB 10.6)"
    custom_modules:
      - "ilas_site_assistant (Aila chatbot — 96+ services, 12+ API routes, 217 tests)"
      - "employment_application (form handler, independent of AI)"
      - "ilas_seo (SEO optimizations)"
      - "ilas_adept (autism education)"
      - "ilas_hotspot (interactive graphics)"
      - "ilas_announcement_overlay (homepage overlay)"
      - "ilas_donation_inquiry (donation form)"
      - "ilas_redirect_automation (404 automation)"
      - "ilas_resources (resource content)"
      - "ilas_security (security hardening)"
      - "ilas_test (testing suite)"
    contrib_modules: "~88 packages including search_api, paragraphs, metatag, ai, ai_vdb_provider_pinecone, gemini_provider, raven (Sentry), tmgmt, webform, bootstrap5"
    themes:
      - "b5subtheme (custom Bootstrap 5 subtheme)"
  pantheon:
    environments: "dev, test, live"
    pantheon_specific_dependencies:
      - "settings.pantheon.php (PRESSFLOW_SETTINGS, hash salt, PantheonServiceProvider, trusted hosts)"
      - "pantheon.upstream.yml (PHP 8.3, MariaDB 10.6, build_step, protected paths)"
      - "pantheon_get_secret() for 14 runtime secrets (with getenv() fallback)"
      - "scripts/deploy/pantheon-deploy.sh (deployment pipeline)"
      - "Terminus CLI (DB/files sync)"
    pantheon_constraints:
      - "No long-running processes (PHP ~120s max)"
      - "No custom daemons or standalone workers"
      - "No persistent local disk beyond /tmp"
      - "No WebSocket support"
      - "Cron runs hourly (default)"
      - "Queue processing only via Drupal cron"
  ai_components:
    assistants_chatbots:
      - "ilas_site_assistant (Aila) — rule-based pipeline with optional LLM enhancement"
    orchestration:
      - "IntentRouter (96+ services, deterministic-first pipeline)"
      - "FallbackGate (rule-based vs LLM decision)"
      - "PreRoutingDecisionEngine (safety > out-of-scope > policy > urgency)"
    observability:
      - "Langfuse (SaaS, ACTIVE in all environments, queue-based export)"
      - "Sentry (SaaS, ACTIVE in all environments, via drupal/raven)"
      - "QueueHealthMonitor (internal Drupal service)"
      - "SloAlertService (internal Drupal service)"
      - "PerformanceMonitor (internal Drupal service)"
    evaluation:
      - "Promptfoo (CI/CD in GitHub Actions, 6 config variants, 105 abuse tests)"
    vector_storage:
      - "Pinecone (SaaS, active on dev/test, HARD-GATED OFF on live)"
      - "Search API DB (lexical, active on all environments)"
    llm_providers:
      - "Google Gemini API (embeddings active, LLM HARD-GATED OFF on live)"
      - "Google Vertex AI (modules uninstalled per TOVR-14, dormant code path)"
    embeddings:
      - "Gemini text-embedding-004 (via gemini_provider contrib module)"
    other:
      - "Voyage AI reranking (HARD-GATED OFF on live)"
      - "SafetyClassifier, PolicyFilter, PiiRedactor (internal safety pipeline)"

component_coupling:
  tightly_coupled_to_drupal:
    - "ilas_site_assistant module (all 96+ services use Drupal APIs)"
    - "Search API DB indexes (Drupal module + MariaDB)"
    - "Analytics/conversation logging (Drupal DB tables)"
    - "Admin reports (Drupal routes + DB queries)"
    - "Queue worker (Drupal queue API)"
    - "Cron hooks (Drupal cron system)"
  tightly_coupled_to_pantheon:
    - "Database credential injection (PRESSFLOW_SETTINGS)"
    - "Edge cache clearing (PantheonServiceProvider)"
    - "Deployment pipeline (git push → Pantheon build)"
    - "Environment promotion (dev → test → live)"
    - "Runtime secrets (pantheon_get_secret with getenv fallback)"
  loosely_coupled:
    - "Redis cache (REDIS_HOST/PORT env vars, portable)"
    - "Environment detection (PANTHEON_ENVIRONMENT, replaceable)"
    - "Twig cache (PANTHEON_ROLLING_TMP, standard fallback)"
  externally_hosted_already:
    - "Sentry (SaaS, active)"
    - "Langfuse (SaaS, active)"
    - "Pinecone (SaaS, active on dev/test)"
    - "Gemini API (SaaS, active for embeddings)"
    - "Voyage AI (SaaS, gated)"
    - "Promptfoo (GitHub Actions)"
    - "Google Translate (SaaS)"
    - "Cloudflare Turnstile (SaaS)"
  unconfirmed:
    - "Vertex AI operational status (modules uninstalled but code path retained)"
    - "New Relic (retired per TOVR-06, but some references may remain)"

target_options:
  aws:
    viability: "Conditionally viable (for sidecar only)"
    recommended_pattern: "Lambda function for queue drain if Pantheon cron proves insufficient"
    key_services:
      - "Lambda (serverless queue worker)"
      - "Secrets Manager or SSM Parameter Store"
    monthly_cost_estimate: "$0-2/mo (Lambda free tier covers expected volume)"
    nonprofit_benefits: "AWS Imagine Grant: $1,000-$100,000 in credits (application required, variable approval)"
    top_risks:
      - "IAM complexity for small team"
      - "Grant approval is variable and non-renewable without reapplication"
      - "AWS billing can be unpredictable without budget alerts"
  azure:
    viability: "Conditionally viable (for sidecar only) — BEST nonprofit value"
    recommended_pattern: "Container Apps for queue drain and/or embedding pipeline"
    key_services:
      - "Container Apps (serverless containers)"
      - "Key Vault (secrets)"
    monthly_cost_estimate: "$0-10/mo (fully covered by nonprofit credits)"
    nonprofit_benefits: "Azure for Nonprofits: $3,500/year automatic credits + up to $35,000/year advanced grant. Free Microsoft 365 Business Premium."
    top_risks:
      - "New platform to learn (mitigated by Container Apps simplicity)"
      - "Nonprofit enrollment processing time"
  digitalocean:
    viability: "Conditionally viable (for sidecar only) — simplest setup"
    recommended_pattern: "Small Droplet ($4-6/mo) with cron-based queue consumer"
    key_services:
      - "Droplet (basic VM)"
    monthly_cost_estimate: "$4-6/mo"
    nonprofit_benefits: "None confirmed"
    top_risks:
      - "No nonprofit discount"
      - "Manual server management (updates, monitoring)"
      - "Limited managed services"

recommended_path:
  target_provider: "Stay on Pantheon (Option A). If sidecar needed later, use Azure (Option B)."
  target_pattern: "Enable existing SaaS integrations on Pantheon by lifting live hard-gates as governance criteria are met. No migration needed."
  why_this_path:
    - "All AI services are ALREADY external SaaS — no infrastructure to move"
    - "Zero migration effort, zero new infrastructure, zero additional ops burden"
    - "All integrations already coded, tested (2452+ unit tests), and hardened (28 RAUD remediations)"
    - "Lowest cost ($60-285/mo with nonprofit discounts)"
    - "Existing phased roadmap (docs/aila/roadmap.md) already defines the enablement sequence"
    - "Every feature has a trivial rollback (config toggle or secret removal)"
  why_not_the_others:
    - "Full migration (Options G-I): No technical driver; costs 3-10x more; requires DevOps skills ILAS likely lacks"
    - "Hybrid migration (Options E-F): No Drupal-adjacent services need to move; the only potential sidecar is a queue worker"
    - "AWS over Azure for sidecar: Azure's $3,500/yr automatic nonprofit credit is more predictable than AWS's variable Imagine Grant"
    - "DigitalOcean for sidecar: No nonprofit discount; slightly more manual than Azure Container Apps"

transition_scope:
  keep_on_pantheon:
    - "Drupal CMS and all content management"
    - "Search API DB (lexical search)"
    - "Rule-based assistant pipeline"
    - "Session management, CSRF, analytics"
    - "Redis cache"
    - "All custom and contrib modules"
    - "Deployment pipeline"
  move_off_pantheon:
    - "Nothing (all AI services already external SaaS)"
  refactor_before_migration:
    - "No refactoring needed for Option A"
    - "If sidecar (Option B): implement queue drain endpoint or direct DB reader for worker"
  deprecate_replace:
    - "Vertex AI custom code path (dormant since TOVR-14 module uninstall)"
    - "Any remaining New Relic references (retired per TOVR-06)"

implementation_backlog:
  phase_0_validation:
    - "Confirm Pantheon plan tier and billing"
    - "Run ilas:runtime-truth on live"
    - "Run ilas:langfuse-status on live"
    - "Measure actual conversation volume"
    - "Gather 501(c)(3) documentation"
  phase_1_foundation:
    - "Lock enablement sequence decisions"
    - "Verify SaaS tier headroom (Langfuse, Pinecone)"
    - "Decide sidecar necessity"
  phase_2_platform_setup:
    - "Apply for Sentry for Good"
    - "Apply for Google for Nonprofits"
    - "Apply for Microsoft Azure for Nonprofits"
    - "Deploy current master to Pantheon environments"
  phase_3_application_changes:
    - "Resolve TOVR-13 quality gate blockers"
    - "Lift vector search live hard-gate (settings.php:585-588)"
    - "Lift Voyage AI live hard-gate (settings.php:618-621)"
  phase_4_data_migration:
    - "No data migration needed (all data stays on Pantheon)"
  phase_5_testing:
    - "Run load tests against live assistant endpoints"
    - "Monitor SLO metrics for 4+ weeks"
    - "Establish weekly review loop"
  phase_6_cutover:
    - "Evaluate LLM live enablement (settings.php:403-404)"
    - "If queue issues: build Azure sidecar worker"
  phase_7_rollback:
    - "Document per-component rollback procedures"
    - "Establish quarterly cost and architecture review"

architecture_requirements:
  networking_dns:
    - "No changes needed (Pantheon manages DNS, CDN, SSL)"
    - "If sidecar: outbound HTTPS from Azure to Langfuse API (no inbound needed)"
  identity_access:
    - "No changes needed (standard Drupal auth + Pantheon dashboard access)"
    - "If sidecar: Azure Managed Identity or Key Vault for credentials"
  secrets_management:
    - "Continue using Pantheon runtime secrets via _ilas_get_secret()"
    - "If sidecar: Azure Key Vault for worker credentials"
  logging_monitoring:
    - "Sentry for errors (already active)"
    - "Langfuse for LLM observability (already active)"
    - "Drupal admin reports for assistant analytics"
    - "QueueHealthMonitor + SloAlertService for internal health"
  storage:
    - "Pantheon file system (public/private) — no changes"
    - "MariaDB for content, analytics, queue tables"
  database:
    - "MariaDB 10.6 on Pantheon — no changes"
  workers_queues:
    - "Drupal cron queue (ilas_langfuse_export) — current"
    - "If sidecar: Azure Container Apps consuming same queue"
  backup_recovery:
    - "Pantheon automated backups — no changes"

integration_changes_required:
  drupal_code_changes:
    - "settings.php: Remove live hard-gates for vector search (line 586), Voyage AI (lines 619-621), and LLM (line 404) — one at a time per phase"
    - "No module code changes needed (all toggle-driven)"
  pantheon_changes:
    - "terminus secrets:set for ILAS_VECTOR_SEARCH_ENABLED=1 on live"
    - "terminus secrets:set for ILAS_VOYAGE_ENABLED=1 on live"
  dns_cdn_changes:
    - "None"
  ci_cd_changes:
    - "None (quality gate already validates all configurations)"
  vendor_account_changes:
    - "Sentry: Apply for Sentry for Good"
    - "Google: Enroll in Google for Nonprofits"
    - "Azure: Enroll in Microsoft for Nonprofits (insurance for future sidecar)"
    - "Langfuse: Monitor Hobby tier usage; upgrade to Pro if exceeded"
    - "Pinecone: Monitor Starter tier usage; upgrade to Standard if exceeded"

decisions_needed_from_stakeholders:
  critical:
    - "Acceptance criteria for live LLM enablement (legal advice boundary review)"
    - "Budget ceiling for monthly AI service costs"
    - "Approval to apply for nonprofit programs (requires organizational info)"
  important:
    - "Prioritization of enablement sequence (vector search → Voyage → LLM)"
    - "Acceptable Langfuse trace loss rate threshold (recommended: 5%)"
    - "Whether to pursue Azure enrollment now as insurance"
  nice_to_have:
    - "Whether to investigate Cloudflare Project Galileo for CDN redundancy"
    - "Whether to formalize the weekly review loop process"

open_questions:
  blocking:
    - "What is the current Pantheon plan tier and monthly cost?"
    - "What is the actual monthly assistant conversation volume?"
    - "What is the current Langfuse queue loss rate on live?"
    - "What is the remaining timeline for TOVR-13 blocker resolution?"
  non_blocking:
    - "Does Pantheon offer nonprofit pricing?"
    - "Is ILAS already enrolled in Google for Nonprofits?"
    - "What is the current Pinecone vector count?"
    - "What is the current Sentry event volume and plan?"
    - "Is there a budget ceiling decision already in place?"

evidence_quality:
  confirmed:
    - "All code architecture findings (from direct source inspection)"
    - "All service integration points and API endpoints"
    - "All secret management patterns"
    - "All Pantheon coupling points"
    - "Langfuse ACTIVE in all environments (settings.php:532-536 + current-state.md:48)"
    - "Sentry ACTIVE in all environments"
    - "LLM/vector/Voyage hard-gated OFF on live (settings.php:404, 586, 619)"
    - "Zero Pantheon-specific code in ilas_site_assistant module"
    - "Azure Nonprofits $3,500/year automatic credits (nonprofit.microsoft.com)"
    - "Sentry for Good free Team plan"
  inferred:
    - "Cost estimates (based on published pricing + assumed ILAS workload)"
    - "Pantheon hosting cost ($50-200/mo range based on public plan tiers)"
    - "Traffic/conversation volume assumptions"
    - "Ops labor premium for full migration ($500-2,000/mo)"
  unconfirmed:
    - "Actual Pantheon plan tier and billing"
    - "Actual conversation/traffic volume"
    - "Actual Langfuse queue loss rate under live traffic"
    - "Actual Pinecone vector count"
    - "Whether Pantheon offers nonprofit pricing"
    - "Whether Google for Nonprofits provides Gemini API credits"
    - "AWS Imagine Grant approval likelihood and amount"
    - "Langfuse/Pinecone willingness to offer nonprofit pricing"

vendor_contacts:
  pantheon:
    program: "No confirmed nonprofit program (inquire)"
    contact: "pantheon.io/contact-us or account manager"
    topic: "Nonprofit pricing inquiry"
  aws:
    program: "AWS Imagine Grant ($1K-$100K credits)"
    contact: "aws.amazon.com/government-education/nonprofits/ or TechSoup"
    topic: "Imagine Grant application for AI/ML workloads"
  azure:
    program: "Microsoft for Nonprofits ($3,500/yr auto + $35K advanced grant)"
    contact: "nonprofit.microsoft.com"
    topic: "Nonprofit enrollment for Azure credits"
  digitalocean:
    program: "None confirmed"
    contact: "digitalocean.com/company/contact"
    topic: "Nonprofit pricing inquiry"
  sentry:
    program: "Sentry for Good (free Team plan)"
    contact: "sentry.io/for/good/"
    topic: "Nonprofit program application"
  google:
    program: "Google for Nonprofits (Workspace, Ad Grants, potential Cloud credits)"
    contact: "google.com/nonprofits/ via TechSoup"
    topic: "Enrollment + Gemini API nonprofit pricing"
  langfuse:
    program: "None confirmed (self-hosted OSS available)"
    contact: "hello@langfuse.com"
    topic: "Nonprofit pricing inquiry"
  pinecone:
    program: "None confirmed"
    contact: "pinecone.io/contact/"
    topic: "Nonprofit pricing + Starter plan limits"
  cloudflare:
    program: "Project Galileo (free Enterprise for public interest orgs)"
    contact: "cloudflare.com/galileo/"
    topic: "Project Galileo application"
  techsoup:
    program: "Central nonprofit tech discount portal"
    contact: "techsoup.org"
    topic: "Organization validation for vendor programs"
END_EXECUTION_HANDOFF
```

---

## Assessment Methodology Notes

### Sources used
- **Primary:** Direct source code inspection of all files in the `idaho-legal-aid-services` repository
- **Secondary:** `docs/aila/current-state.md` (evidence-backed audit with 250+ claims and file-line references)
- **Tertiary:** Authoritative public documentation for Pantheon, AWS, Azure, DigitalOcean, and individual SaaS providers (cited inline)

### What this assessment does NOT cover
- Performance benchmarking or load testing (requires live execution)
- Detailed Drupal configuration audit beyond AI-related modules
- Legal/compliance review of data handling practices
- Accessibility or UX assessment of the assistant widget
- SEO impact analysis of infrastructure changes
- Detailed network architecture or firewall analysis

### Key assumptions made explicit
1. ILAS is a small-to-moderate traffic nonprofit website (not high-scale)
2. The development team is small (1-3 people based on commit patterns)
3. DevOps expertise is limited (standard for small nonprofits)
4. Cost sensitivity is high (standard for nonprofits)
5. Stability and minimal disruption are prioritized over cutting-edge features
