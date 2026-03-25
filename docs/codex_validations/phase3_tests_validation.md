# Phase 3 Tests Validation

## 1. Executive Summary

- Date: 2026-03-24
- Prompt: `AFRP-11`
- Verification level tag: `runtime-equivalent kernel + CI quality-gate`
- Blind spot closed: the repo had language-isolation unit coverage and lexical-index governance coverage, but it did not exercise `FaqIndex::search()`, `FaqIndex::getById()`, and `FaqIndex::getCategories()` against real paragraph/node/path-alias fixtures where the lexical hit language was `en` while the parent page scope was a different language. That let passing tests coexist with the audited wrong-language retrieval leak.
- Prior posture before editing:
  - `VC-PURE` subset passed: `FaqLanguageIsolationTest`, `VectorSearchMergeTest`, `RetrievalConfigurationServiceTest`, and `DependencyFailureDegradeContractTest`.
  - `VC-KERNEL` index-governance coverage passed: `RetrievalIndexUpdateHookKernelTest`.
  - `VC-FUNCTIONAL` FAQ endpoint coverage passed: `AssistantApiFunctionalTest --filter=FaqEndpoint`.
  - `npm run test:promptfoo:runtime` passed.
  - Those suites still missed the audited failure class because none bound a real lexical hit to real mixed-language parent metadata.
- Post-change posture:
  - Added `FaqSearchRuntimeRegressionKernelTest` to prove the fixed path excludes a foreign-language parent leak and to recreate the pre-fix leak with an equivalent broken fixture.
  - Added `VC-KERNEL` to the mandatory quality gate script and GitHub Actions workflow naming.
  - Updated contract tests so CI wiring is enforced at the script boundary that actually owns the kernel phase.

## 2. Source of Truth / Files inspected

- Required documents:
  - `web/modules/custom/ilas_site_assistant/HOSTILE_AUDIT_REPORT.md`
  - `web/modules/custom/ilas_site_assistant/PRODUCTION_AUDIT_2026.md`
  - `docs/aila/current-state.md`
  - `docs/aila/evidence-index.md`
  - `docs/aila/runbook.md`
  - `docs/aila/risk-register.md`
- Primary code/test surfaces:
  - `web/modules/custom/ilas_site_assistant/src/Service/FaqIndex.php`
  - `web/modules/custom/ilas_site_assistant/tests/src/Functional/AssistantApiFunctionalTest.php`
  - `web/modules/custom/ilas_site_assistant/tests/src/Kernel/RetrievalIndexUpdateHookKernelTest.php`
  - `web/modules/custom/ilas_site_assistant/tests/src/Unit/FaqLanguageIsolationTest.php`
  - `web/modules/custom/ilas_site_assistant/tests/src/Unit/VectorSearchMergeTest.php`
  - `web/modules/custom/ilas_site_assistant/tests/src/Unit/RetrievalConfigurationServiceTest.php`
  - `web/modules/custom/ilas_site_assistant/tests/run-quality-gate.sh`
  - `.github/workflows/quality-gate.yml`
  - `web/modules/custom/ilas_site_assistant/tests/src/Unit/QualityGateEnforcementContractTest.php`
  - `web/modules/custom/ilas_site_assistant/tests/src/Unit/PhaseOneQualityGateContractTest.php`

## 3. Findings

1. `FaqIndex::search()` first filters lexical hits by `search_api_language`, then builds result items, then applies `filterItemsByCurrentLanguage()` against resolved parent metadata and URLs. The audited leak lived in that second gate, not in the raw lexical hit filter. Evidence: `FaqIndex.php` lines 323-352 and lines 193-239.
2. The existing FAQ functional coverage only asserted `200` plus response shape for `/assistant/api/faq`, and rate limiting for repeated GETs. It never seeded multilingual FAQ content or asserted returned URLs/categories. Evidence: `AssistantApiFunctionalTest.php` lines 433-487.
3. The existing kernel coverage only proved update hook `ilas_site_assistant_update_10009()` recreates missing lexical indexes from canonical config. It did not exercise retrieval output quality. Evidence: `RetrievalIndexUpdateHookKernelTest.php` lines 51-84.
4. The existing unit language-isolation coverage was real value, but it used array-backed doubles and URL-prefix filtering rather than real paragraph -> parent node -> alias resolution. That is why it passed while the runtime leak still existed. Evidence: `FaqLanguageIsolationTest.php` lines 20-118 and lines 149-214.
5. The existing vector merge coverage only guarded vector URL filtering and dedupe behavior; it did not cover lexical results whose paragraph language matched the request while the parent page language did not. Evidence: `VectorSearchMergeTest.php` lines 183-197.
6. The CI quality gate did not previously force a runtime-relevant kernel retrieval regression suite. The gate now records and executes a dedicated `vc_kernel` phase before golden transcript and promptfoo runtime checks. Evidence: `run-quality-gate.sh` lines 69-76 and lines 152-229.
7. New coverage added:
  - `FaqSearchRuntimeRegressionKernelTest::testRealFaqSearchFiltersForeignLanguageParentScope()` proves the fixed path keeps only the English FAQ result, rejects `getById()` for the foreign-parent FAQ item, and excludes the foreign-language category from browse mode. Evidence: `FaqSearchRuntimeRegressionKernelTest.php` lines 76-97.
  - `FaqSearchRuntimeRegressionKernelTest::testEquivalentPreFixBehaviorLeaksForeignLanguageResult()` recreates the old failure mode with the same mixed-language fixture by disabling the parent/item language gate and proving the leaked Spanish parent URL and category would surface. Evidence: `FaqSearchRuntimeRegressionKernelTest.php` lines 103-118 and lines 367-381.
  - The fixture is runtime-equivalent where it matters: it uses real Drupal paragraph entities, real parent nodes, real path aliases, and the real `FaqIndex` code path. The only double is the lexical-hit source, used to deterministically reproduce the audited condition inside kernel scope. Evidence: `FaqSearchRuntimeRegressionKernelTest.php` lines 175-240 and lines 388-503.
8. CI gating added:
  - `run-quality-gate.sh` now emits `vc_kernel_command`, runs `FaqSearchRuntimeRegressionKernelTest.php` through `scripts/ci/run-host-phpunit.sh`, and fails the gate on kernel regression failure. Evidence: `run-quality-gate.sh` lines 72-75 and lines 152-167.
  - `.github/workflows/quality-gate.yml` now labels the mandatory gate step as `VC-KERNEL + golden transcript + promptfoo runtime`, which matches the script contents. Evidence: `.github/workflows/quality-gate.yml` lines 45-52.
  - Contract tests now enforce the gate at the correct seam: the script must contain the explicit kernel test path, and the workflow must invoke the gate script with `VC-KERNEL` semantics. Evidence: `QualityGateEnforcementContractTest.php` lines 39-52 and lines 95-105; `PhaseOneQualityGateContractTest.php` line 124 onward.
9. Changed files:
  - `web/modules/custom/ilas_site_assistant/tests/src/Kernel/FaqSearchRuntimeRegressionKernelTest.php`
  - `web/modules/custom/ilas_site_assistant/tests/run-quality-gate.sh`
  - `.github/workflows/quality-gate.yml`
  - `web/modules/custom/ilas_site_assistant/tests/src/Unit/QualityGateEnforcementContractTest.php`
  - `web/modules/custom/ilas_site_assistant/tests/src/Unit/PhaseOneQualityGateContractTest.php`
  - `docs/codex_validations/phase3_tests_validation.md`
10. Validation summary:
  - Pre-edit baseline observed while the audited failure class still existed:
    - `ddev exec vendor/bin/phpunit -c /var/www/html/phpunit.pure.xml ...FaqLanguageIsolationTest.php ...VectorSearchMergeTest.php ...RetrievalConfigurationServiceTest.php ...DependencyFailureDegradeContractTest.php` -> `OK (54 tests, 252 assertions)`
    - `ddev exec vendor/bin/phpunit -c /var/www/html/phpunit.xml ...RetrievalIndexUpdateHookKernelTest.php` -> `OK (1 test, 32 assertions)`
    - `ddev exec vendor/bin/phpunit -c /var/www/html/phpunit.xml ...AssistantApiFunctionalTest.php --filter=FaqEndpoint` -> `OK, but there were issues! Tests: 2, Assertions: 25, PHPUnit Deprecations: 3`
    - `npm run test:promptfoo:runtime` -> `16/16` passed
  - Post-edit validation:
    - `ddev exec vendor/bin/phpunit -c /var/www/html/phpunit.pure.xml ...FaqLanguageIsolationTest.php ...VectorSearchMergeTest.php ...RetrievalConfigurationServiceTest.php ...DependencyFailureDegradeContractTest.php ...QualityGateEnforcementContractTest.php ...PhaseOneQualityGateContractTest.php` -> `OK (66 tests, 397 assertions)`
    - `ddev exec vendor/bin/phpunit --configuration /var/www/html/phpunit.xml --group ilas_site_assistant /var/www/html/web/modules/custom/ilas_site_assistant/tests/src/Unit` -> `OK, but there were issues! Tests: 2452, Assertions: 12401, Warnings: 1, Deprecations: 1, Skipped: 1`
    - `bash scripts/ci/run-host-phpunit.sh web/modules/custom/ilas_site_assistant/tests/src/Kernel/FaqSearchRuntimeRegressionKernelTest.php` -> `OK (2 tests, 39 assertions)`
    - `ddev exec vendor/bin/phpunit -c /var/www/html/phpunit.xml /var/www/html/web/modules/custom/ilas_site_assistant/tests/src/Functional/AssistantApiFunctionalTest.php --filter=FaqEndpoint` -> `OK, but there were issues! Tests: 2, Assertions: 25, PHPUnit Deprecations: 3`
    - `bash web/modules/custom/ilas_site_assistant/tests/run-quality-gate.sh --skip-phpunit` -> `Quality gate PASSED`

## 4. Recommendation

- Keep `FaqSearchRuntimeRegressionKernelTest` as the minimum blocking regression proof for this failure class; it is the first suite in the repo that checks effective FAQ retrieval behavior with real multilingual parent metadata.
- Keep `VC-KERNEL` mandatory in the quality gate. Without that phase, the repo can regress back to “pure/unit green, runtime retrieval broken.”
- If this class of bug reappears in hosted environments, rollback is low risk: revert the gate-script/workflow/test changes above. No production runtime code was changed by this remediation.

## 5. Scope boundaries

- No edits were made to `vendor`, `web/core`, or Pantheon upstream files.
- No production retrieval behavior was changed in `FaqIndex`; this change set adds coverage and enforcement only.
- Existing pure/unit suites were not weakened to make the new coverage fit.
- The new proof uses a deterministic runtime-equivalent lexical fixture inside kernel scope rather than a full Search API tracker/reindex lifecycle.

## 6. Risks / Unknowns

- Still-unverified surface: there is still no hosted smoke/eval that proves `/assistant/api/faq` returns the correct language-scoped content against a live indexed environment on `dev`/`test`/`live`.
- Still-unverified surface: the kernel proof recreates the lexical-hit condition with a query double. It does not prove Search API tracker/indexer behavior end to end.
- Residual noise remains in existing validation:
  - `AssistantApiFunctionalTest --filter=FaqEndpoint` still reports `3` PHPUnit deprecations.
  - The broader `VC-UNIT` run still reports `1` warning, `1` deprecation, and `1` skipped test unrelated to this remediation.
- The repo still relies on the workflow’s split execution model: `VC-PURE` and `VC-DRUPAL-UNIT` run directly in GitHub Actions, while `VC-KERNEL` is enforced inside `run-quality-gate.sh`.

## 7. Next approved step

- Add one hosted retrieval smoke check that targets `/assistant/api/faq` with a known multilingual fixture or fixture-like content contract, so the repo has both local deterministic proof and environment-level proof for this failure class.
