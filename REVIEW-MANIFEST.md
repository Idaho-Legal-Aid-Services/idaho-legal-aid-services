# Review Manifest — Custom Code Security Review

Generated: Pass 0 (Inventory). Scope per GLOBAL RULES: **custom code only**
(`web/modules/custom/`, `web/themes/custom/`). Core and contrib are trusted
upstream and out of scope except where custom code calls them unsafely or a
Pass-1 advisory applies.

Line counts below count **reviewable source only** (`.php`, `.module`,
`.install`, `.inc`, `.twig`, `.js`) unless noted. Test files are counted
separately because they are lower-priority for the injection/XSS passes.

---

## 1. Custom modules — per-directory line counts by file type

### web/modules/custom/

| Module | php | .module | .install | twig | js | yml | Reviewable total¹ | of which tests |
|---|---|---|---|---|---|---|---|---|
| employment_application | 4083 (6) | 193 (1) | 204 (1) | – | – | 205 (7) | 4480 | 823 |
| ilas_adept | 28 (1) | 253 (1) | 85 (1) | – | 597 (1) | 21 (2) | 963 | 0 |
| ilas_announcement_overlay | 178 (1) | 203 (1) | – | 119 (2) | 206 (1) | 523 (21) | 706 | 0 |
| ilas_donation_inquiry | 444 (1) | 46 (1) | – | – | – | 44 (4) | 490 | 0 |
| ilas_hotspot | 752 (4) | 106 (1) | – | – | 243 (1) | 59 (4) | 1101 | 311 |
| ilas_redirect_automation | 2416 (6) | 18 (1) | – | – | – | 138 (5) | 2434 | 0 |
| ilas_resources | 660 (4) | 34 (1) | 330 (1) | – | – | 14 (1) | 1062 | 197 |
| ilas_security | 449 (4) | – | – | – | – | 5 (1) | 449 | 449 (all tests) |
| ilas_seo | 2156 (10) | 667 (1) | – | – | 15 (1) | 35 (3) | 2838 | ~1173 |
| ilas_site_assistant | 127132 (377) | 354 (2) | 1179 (1) | 94 (2) | 9446 (15) | 8622 (27) | 138205 | 83341 |
| ilas_site_assistant_governance | 8332 (46) | 15 (1) | 589 (1) | – | – | 1892 (23) | 9068 | ~2490 |
| ilas_test | 1233 (4) | – | – | 61 (1) | – | 49 (4) | 1294 | 216 |
| ilas_voyage_ai_provider | 421 (2) | – | – | – | – | 16 (3) | 421 | 261 |

¹ Reviewable total = php + module + install + inc + twig + js. Parenthesized = file count.

`web/modules/custom/README.md` is documentation only — not reviewed.

### web/themes/custom/b5subtheme/

| Type | Files | Lines |
|---|---|---|
| .theme | 1 | 767 |
| twig | 90 | 6425 |
| js (excl. `*.min.js`) | 20 | 5720 |
| scss | 47 | 18869 (not reviewed — compiled) |
| css | 4 | 11951 (not reviewed — compiled) |
| yml (info/libraries/etc.) | 16 | 596 |
| **Reviewable total (theme/php/twig/js)** | | **12912** |

Twig by subdirectory: node 2560 (28), views 1457 (26), paragraph 1304 (17),
page 413 (2), includes 210 (3), layout 203 (1), navigation 93 (2), menu 74 (1),
field 69 (8), form 27 (1), block 15 (1).

JS by size (top): premium-application.js 1789, donation-inquiry.js 502,
smart-faq-enhanced.js 486, scripts.js 412, scroll-behaviors.js 339,
resources.js 310, dropdown-menu.js 244, lazy-loading.js 242, mobile-menu.js 230,
down-arrow-accordion.js 186, accordion-deeplink.js 182, sticky-jobs-bar.js 142,
hero-rotator.js 132, donation-form.js 127, content-tabs.js 98, topic-cards.js 97,
language-switcher.js 79, topic-nav.js 69, ga4-init.js 42, admin-toolbar-state.js 12.

---

## 2. Settings & services files (list only — not analyzed in Pass 0)

`web/sites/default/`
- default.settings.php
- settings.php
- settings.local.php
- settings.pantheon.php
- settings.ddev.php
- settings.ddev.redis.php
- settings.redis.php
- settings.solr.php
- default.services.yml
- default.services.pantheon.preproduction.yml
- services.yml
- services.redis.yml

`web/sites/`
- development.services.yml

_Note: no `settings.local.php` gitignore status verified in this pass; flagged for Pass 1._

---

## 3. config/sync inventory (filenames only)

Config directory: `config/` (repo root; no separate `config/sync`).

### user.role.*.yml — 4
- user.role.administrator.yml
- user.role.anonymous.yml
- user.role.authenticated.yml
- user.role.content_editor.yml

### filter.format.*.yml — 6
- filter.format.basic_html.yml
- filter.format.content_format.yml
- filter.format.easy_email.yml
- filter.format.full_html.yml
- filter.format.plain_text.yml
- filter.format.webform_default.yml

### views.view.*.yml — 42
adept_lessons, assistant_gap_items, assistant_governance_conversations,
block_content, content, editoria11y_dismissals, editoria11y_results, events,
files, forms, forms_categories, guides, guides_categories, media, media_library,
moderated_content, news, office_locations_map, press_room_listing,
publishing_content, recent_content, redirect, redirect_404,
reports_publications_listing, resources_by_service, scheduler_scheduled_content,
scheduler_scheduled_media, scheduler_scheduled_taxonomy_term, search, site_search,
taxonomy_term, tmgmt_job_items, tmgmt_job_messages, tmgmt_job_overview,
tmgmt_local_manage_translate_task, tmgmt_local_task_items,
tmgmt_local_task_overview, tmgmt_translation_all_job_items, topics_by_service_area,
user_admin_people, watchdog, webform_submissions.

---

## 4. Custom composer packages & patches

### Non-Drupal `require` packages (composer.json)
- composer/installers ^2.3
- cweagans/composer-patches ^2.0
- dompdf/dompdf ^3.1 _(HTML→PDF; injection/SSRF surface — flag for Pass 1/2)_
- dropsolid/langfuse-php-sdk ^1.2
- drush/drush ^13.6
- pantheon-systems/drupal-integrations ^11
- pantheon-systems/search_api_pantheon ^8.5
- pantheon-upstreams/upstream-configuration dev-main
- setasign/fpdf ^1.8 _(PDF generation)_
- setasign/fpdi ^2.6 _(PDF import)_

### Repositories
- composer type: https://packages.drupal.org/8
- path type: upstream-configuration

### Patches (composer.json `extra.patches` → `patches/`)
- drupal/core → bigpipe-undefined-array-key-3194462.patch
- drupal/tmgmt → tmgmt-paragraphs-3134922-46.patch
- drupal/gemini_provider → gemini-provider-v1beta.patch
- drupal/ai_vdb_provider_pinecone → ai-vdb-provider-pinecone-query-timeouts.patch
- drupal/ai → ai-search-embedding-base-honor-convert-to-label.patch

No `composer.patches.json` file exists (patches declared inline in composer.json).
Patches touch contrib/core (out of scope) but are noted for Pass 1 advisory context.

---

## 5. Proposed pass plan

Threshold for sub-splitting Pass 2/3: any single module/theme exceeding
~2,000 lines of reviewable **non-test** source is broken into named sub-passes
with an explicit file list. Test directories are deferred to a dedicated pass.

### Pass 1 — Known advisories & dependency posture
- Cross-check contrib versions & the 5 patches against known Drupal SAs.
- dompdf/fpdf/fpdi usage advisories (custom callers only).
- Settings/services files (section 2) for insecure config, exposed secrets,
  trusted_host / reverse-proxy / permission-hardening gaps.

### Pass 2 — Injection (SQL / command / path / SSRF / unsafe deserialization / LDAP)
Grep-driven across all custom PHP. Sub-passes where non-test source > ~2,000 lines:

- **2A — ilas_site_assistant Controllers**
  - src/Controller/AssistantApiController.php (8423)
  - src/Controller/AssistantReportController.php (886)
  - src/Controller/AssistantPageController.php (125)
  - src/Controller/AssistantSessionBootstrapController.php (85)
- **2B — ilas_site_assistant Services group 1 (retrieval/ranking/routing)**
  - ResourceFinder.php (2443), IntentRouter.php (2075), FaqIndex.php (1965),
    SelectionRegistry.php (751), TopicRouter.php (562), RankingEnhancer.php (567),
    HardRouteRegistry.php (485), NavigationIntent.php (517),
    OfficeDirectory.php (463), OfficeLocationResolver.php (232),
    RetrievalAugmenter.php (241), RetrievalContract.php (262),
    RetrievalConfigurationService.php (629)
- **2C — ilas_site_assistant Services group 2 (LLM transport / providers / net I/O)**
  - LlmEnhancer.php (649), LlmAdmissionCoordinator.php (777),
    CohereLlmTransport.php (211), CohereGenerationProbe.php (191),
    VoyageReranker.php (434), LangfuseTracer.php (799),
    LangfuseTraceLookupService.php (339), ProviderHealthCheck.php (209),
    LlmRateLimiter.php (219), LlmCircuitBreaker.php (189),
    LlmRuntimeConfigResolver.php (105), VectorIndexHygieneService.php (1257),
    RuntimeTruthSnapshotBuilder.php (1353)
- **2D — ilas_site_assistant Services group 3 (remaining) + EventSubscribers + Form + Access + Plugin + Commands + .module/.install**
  - all remaining src/Service/*.php not in 2B/2C
  - src/EventSubscriber/*.php (SentryOptionsSubscriber 1233 + 3 others)
  - src/Form/AssistantSettingsForm.php (831), src/Access/*.php (190),
    src/Plugin/**, src/Commands/*.php (1695),
    ilas_site_assistant.module (304), ilas_site_assistant.install (1179)
- **2E — ilas_redirect_automation** (non-test 2434)
  - PathMatcherService.php (671), FileMatcherService.php (382),
    RedirectAnalyzerService.php (366), RedirectApplierService.php (329),
    CsvExportService.php (256), Commands/RedirectAutomationCommands.php (412),
    ilas_redirect_automation.module (18)
- **2F — ilas_seo** (non-test 2838)
  - ilas_seo.module (667), StructuredData/GraphBuilder.php (612),
    EventSubscriber/CspEnhancementSubscriber.php (79),
    EventSubscriber/ResponseSubscriber.php (65), remaining src PHP
- **2G — employment_application** (non-test ~3657)
  - Controller/EmploymentApplicationController.php (2526),
    Service/ApplicationValidator.php (375), Form/ApplicationDeleteForm.php (193),
    Commands/EmploymentApplicationCommands.php (166),
    employment_application.module (193), employment_application.install (204)
- **2H — ilas_site_assistant_governance** (non-test ~5842)
  - Entity/AssistantGapItem.php (941), post_update.php (717),
    Service/ReviewedGapPromptfooCandidateExporter.php (669),
    Form/*.php (741), Controller/*.php (525), Service/* (remaining),
    Plugin/Action/*, ilas_site_assistant_governance.install (589)
- **2I — small modules bundle** (each < 2000, grouped)
  - ilas_adept (module+install+post_update), ilas_announcement_overlay
    (Block+module), ilas_donation_inquiry (DonationInquiryController 444+module),
    ilas_hotspot (IlasHotspotBlock 278, HotspotSettingsForm 163, module 106),
    ilas_resources (views filter/argument + install + post_update),
    ilas_voyage_ai_provider (VoyageAiProvider 160), ilas_test
    (TestRunner 591, TestDashboardController 233, ExecuteTestsForm 193)

### Pass 3 — XSS / output encoding (Twig autoescape, `|raw`, `Markup::create`, `#markup`, `t()` args, JS DOM sinks)
Sub-passes where reviewable source > ~2,000 lines:

- **3A — b5subtheme Twig** (6425 lines / 90 files)
  - Priority: node/ (2560), paragraph/ (1304), views/ (1457), page/ (413),
    includes/ (210), layout/ (203); then menu/navigation/field/form/block.
- **3B — b5subtheme JS** (5720 lines / 20 files)
  - DOM-sink audit (innerHTML, insertAdjacentHTML, document.write, eval,
    location assignment). Priority: premium-application.js (1789),
    donation-inquiry.js (502), smart-faq-enhanced.js (486), scripts.js (412),
    resources.js (310), dropdown-menu.js (244), mobile-menu.js (230).
- **3C — b5subtheme .theme + module preprocess / render output**
  - b5subtheme.theme (767) + `#markup`/`Markup::create`/`->render()` sinks
    surfaced by grep across all custom modules (esp. ilas_seo GraphBuilder,
    ilas_hotspot block, ilas_announcement_overlay, ilas_site_assistant JS
    widgets assistant-widget.js 2844 & observability.js 336).
- **3D — ilas_site_assistant JS widgets** (3180 lines)
  - js/assistant-widget.js (2844), js/observability.js (336) — DOM-sink audit
    for assistant response rendering.

### Pass 4 — Access control / authN / authZ / CSRF / routing
- All `*.routing.yml` `_permission`/`_access`/`_csrf` declarations.
- Controllers reading request without access checks; custom Access checks
  (StrictCsrfRequestHeaderAccessCheck, AssistantDiagnosticsAccessCheck,
  AssistantReadEndpointGuard, RequestTrustInspector).
- employment_application token/nonce/flood pipeline; ilas_test dashboard
  exposure; governance review controllers.

### Pass 5 — Secrets / sensitive-data handling / logging / PII
- Key providers (RuntimeSiteSettingKeyProvider), API key resolution,
  PiiRedactor, ObservabilityPayloadMinimizer, ConversationLogger,
  AnalyticsLogger, Langfuse export — verify no secret/PII leakage to logs
  or external services.

### Pass 6 — Test-directory sweep (deferred, lower priority)
- Verify no real secrets in fixtures; no test-only endpoints reachable in prod
  (ilas_test, ilas_security tests, assistant tests/goldens/fixtures 83k lines).

Passes run one at a time. Each pass ends with the rubric checklist table and
"PASS N COMPLETE" per GLOBAL RULES.
