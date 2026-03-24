# Phase 1 Flows Audit

## 1. Executive Summary

The assistant has one dominant turn-handling entrypoint: `POST /assistant/api/message` in `web/modules/custom/ilas_site_assistant/ilas_site_assistant.routing.yml` dispatches to `AssistantApiController::message()`. The repo already contains several behaviors that act like multi-step flows, but the highest-value ones are still orchestrated directly inside `AssistantApiController` rather than through reusable definitions.

The clearest Phase 1 extraction targets are:
- Office follow-up slot-fill after the `apply` response.
- Clarify-loop prevention for repeated clarify-like responses.
- Repeated-message escalation for the same user text across recent turns.

These three are the smallest safe slice because they already have explicit thresholds or persisted state, bounded lifecycles, and direct test coverage. By contrast, pre-routing exits, fallback policy, and history-based service-area continuity are either already extracted into services or are still too heuristic and intertwined to move first.

**Recommendation:** Use **plain Drupal-native config + runner** as the primary architecture path, implemented as a code-managed typed Drupal config object plus a small runner service.

**Scope boundaries:** Do not introduce Symfony Workflow, do not start with a config entity, do not move the full `processIntent()` switch into config, and do not treat existing local success as proof that silent controller-side reconstruction is acceptable.

## 2. Source of Truth / Files inspected

- `web/modules/custom/ilas_site_assistant/ilas_site_assistant.routing.yml`
- `web/modules/custom/ilas_site_assistant/ilas_site_assistant.services.yml`
- `web/modules/custom/ilas_site_assistant/src/Controller/AssistantApiController.php`
- `web/modules/custom/ilas_site_assistant/src/Service/IntentRouter.php`
- `web/modules/custom/ilas_site_assistant/src/Service/TurnClassifier.php`
- `web/modules/custom/ilas_site_assistant/src/Service/HistoryIntentResolver.php`
- `web/modules/custom/ilas_site_assistant/src/Service/PreRoutingDecisionEngine.php`
- `web/modules/custom/ilas_site_assistant/src/Service/FallbackGate.php`
- `web/modules/custom/ilas_site_assistant/src/Service/FallbackTreeEvaluator.php`
- `web/modules/custom/ilas_site_assistant/src/Service/ResponseBuilder.php`
- `web/modules/custom/ilas_site_assistant/src/Service/OfficeLocationResolver.php`
- `web/modules/custom/ilas_site_assistant/src/Service/TopIntentsPack.php`
- `web/modules/custom/ilas_site_assistant/src/Service/DisambiguationPack.php`
- `web/modules/custom/ilas_site_assistant/src/Service/TopicRouter.php`
- `web/modules/custom/ilas_site_assistant/src/Service/NavigationIntent.php`
- `web/modules/custom/ilas_site_assistant/src/Form/AssistantSettingsForm.php`
- `web/modules/custom/ilas_site_assistant/config/install/ilas_site_assistant.settings.yml`
- `web/modules/custom/ilas_site_assistant/config/schema/ilas_site_assistant.schema.yml`
- `config/ilas_site_assistant.settings.yml`
- `web/modules/custom/ilas_site_assistant/config/intents/top_intents.yml`
- `web/modules/custom/ilas_site_assistant/config/routing/disambiguation.yml`
- `web/modules/custom/ilas_site_assistant/config/routing/topic_map.yml`
- `web/modules/custom/ilas_site_assistant/config/routing/navigation_pages.yml`
- `web/modules/custom/ilas_site_assistant/src/Plugin/KeyProvider/RuntimeSiteSettingKeyProvider.php`
- `web/modules/custom/ilas_site_assistant/src/Plugin/QueueWorker/LangfuseExportWorker.php`
- `web/modules/custom/ilas_site_assistant/tests/src/Unit/OfficeFollowupGuardContractTest.php`
- `web/modules/custom/ilas_site_assistant/tests/src/Unit/PostSanitizeAndLoopGuardContractTest.php`
- `web/modules/custom/ilas_site_assistant/tests/src/Unit/IntegrationFailureContractTest.php`
- `web/modules/custom/ilas_site_assistant/tests/src/Unit/DisambiguationOptionContractTest.php`
- `web/modules/custom/ilas_site_assistant/tests/src/Unit/PreRoutingDecisionEngineContractTest.php`
- `web/modules/custom/ilas_site_assistant/tests/src/Unit/ResponseBuilderPackTest.php`
- `web/modules/custom/ilas_site_assistant/tests/src/Unit/ConfigCompletenessDriftTest.php`
- `docs/aila/current-state.md`
- `docs/aila/evidence-index.md`
- `docs/aila/runbook.md`
- `docs/aila/risk-register.md`
- `docs/aila/system-map.mmd`

Supplementary repo truth confirmed during the audit:
- `AssistantApiController::message()` is the main turn pipeline.
- `AssistantApiController::processIntent()` still contains branch-specific micro-flows.
- `TopIntentsPack`, `DisambiguationPack`, `TopicRouter`, and `NavigationIntent` already use code-managed YAML catalogs.
- `ilas_site_assistant.settings` already uses install config, active export, schema coverage, and a config form.
- No `@ConfigEntityType`, `ConfigEntityBase`, or `symfony/workflow` usage was found in the custom module or root Composer manifests.

Non-mutating verification run during the audit:
- `vendor/bin/phpunit -c phpunit.pure.xml --filter 'ConversationContextTest|OfficeFollowupGuardContractTest|FallbackGateTest|PreRoutingDecisionEngineContractTest|PostSanitizeAndLoopGuardContractTest|HardRouteRegistryTest|DisambiguationOptionContractTest|FormFinderTest|ResponseBuilderPackTest'` -> `OK (194 tests, 492 assertions)`
- `vendor/bin/phpunit -c phpunit.pure.xml --filter 'IntegrationFailureContractTest::testClarifyLoopBreaksOnThirdRepeat|IntegrationFailureContractTest::testMessageRepeatedEscalationRecordsShortcutSuccessOnce'` -> `OK (2 tests, 18 assertions)`

## 3. Candidate Flow Inventory

**Findings**

### A. Office follow-up slot-fill

This is the clearest existing multi-step flow and the best first extraction candidate.

Evidence:
- `processIntent()` emits a follow-up prompt in the `apply` branch and invites the user to provide a city or county.
- `message()` persists bounded follow-up state in the `ilas_conv_followup:<conversation_id>` cache entry.
- The next turn consumes that state, resolves a specific office, asks for clarification, or decrements remaining turns.
- Dedicated helpers already exist for `loadOfficeFollowupState()`, `saveOfficeFollowupState()`, `clearOfficeFollowupState()`, `resolveOfficeFromMessageOrHistory()`, `handleOfficeFollowUp()`, and `handleOfficeFollowUpClarify()`.
- `OfficeFollowupGuardContractTest` already locks bounded turn count, expiry, location matching, office-detail resolution, and urgency interaction.

Why it behaves like a flow:
- It has explicit entry criteria, persisted state, bounded lifetime, and multiple outcome branches.

Phase 1 fit:
- Best first candidate.

### B. Clarify-loop prevention

This is a reusable guard flow and the second-best first extraction candidate.

Evidence:
- `applyClarifyLoopGuard()` tracks `clarify_count` and `prior_question_hash`.
- `CLARIFY_LOOP_THRESHOLD` is explicit and currently set to `3`.
- When the threshold is hit, the controller emits `clarify_loop_break` with deterministic `topic_suggestions` and escalation actions.
- `PostSanitizeAndLoopGuardContractTest` and `IntegrationFailureContractTest::testClarifyLoopBreaksOnThirdRepeat()` already cover the contract.

Why it behaves like a flow:
- It evaluates repeated response state across turns and deterministically changes the next response once a threshold is crossed.

Phase 1 fit:
- Best first candidate.

### C. Repeated-message escalation

This is the third strong Phase 1 candidate.

Evidence:
- `message()` inspects the last three cached user turns before normal routing.
- If all three recent messages are identical to the current redacted input, the controller returns an immediate escalation payload.
- That payload uses shared escalation actions from `getEscalationActions()`.
- `IntegrationFailureContractTest::testMessageRepeatedEscalationRecordsShortcutSuccessOnce()` covers the behavior.

Why it behaves like a flow:
- It is a bounded multi-turn shortcut based on recent turn history and a fixed threshold.

Phase 1 fit:
- Best first candidate.

### D. History-aware follow-up continuity

This is a real flow-like subsystem, but not a safe first extraction.

Evidence:
- `TurnClassifier::classifyTurn()` labels follow-up vs inventory vs reset.
- `HistoryIntentResolver::resolveFromHistory()` and `extractTopicContext()` feed continuity logic.
- `message()` contains office-detail continuity, service-area drift suppression, and complaint continuity rewrites.

Why it behaves like a flow:
- It preserves prior topic context and modifies downstream routing based on recent conversation history.

Phase 1 fit:
- Later candidate, not first. It is too heuristic and intertwined with routing semantics.

### E. Finder/disambiguation clarification

This is partially config-backed already, but still a later extraction candidate.

Evidence:
- `processIntent()` emits `form_finder_clarify` and `guide_finder_clarify` when the query is too vague or yields no results.
- Disambiguation output is built from options and question payloads.
- `TopIntentsPack` already supplies clarifiers and chips, and `DisambiguationPack` already supplies confusable-topic data.
- `DisambiguationOptionContractTest` verifies current disambiguation output assumptions.

Why it behaves like a flow:
- It asks the user to narrow or choose before routing can continue.

Phase 1 fit:
- Later candidate, after the thin runner exists.

### F. Pre-routing exit precedence

This behaves like an ordered decision tree, but it is already extracted well enough.

Evidence:
- `PreRoutingDecisionEngine::evaluate()` resolves safety, out-of-scope, policy, urgency, and route override precedence before normal routing.
- `message()` applies the resulting exit or override.
- `PreRoutingDecisionEngineContractTest` already verifies the current precedence contract.

Phase 1 fit:
- Reuse it. Do not pull it into the first flow-extraction slice.

### G. Fallback ladder

This is flow-like, but already more modular than the controller-local branches.

Evidence:
- `FallbackGate::evaluate()` decides answer vs clarify vs fallback vs hard-route.
- `FallbackTreeEvaluator::evaluateLevel()` already models a four-level no-dead-end ladder.

Phase 1 fit:
- Not the first extraction target.

## 4. Ranked Architecture Recommendation

**Recommendation**

### 1. Primary: plain Drupal-native config + runner

This is the recommended first path.

Why:
- It is the least complex option that still removes imperative controller branching.
- It matches existing repo patterns: schema-backed exported config, a config form, and lightweight code-managed YAML packs.
- The Phase 1 candidates need thresholds, trigger rules, and handler selection more than they need entity CRUD or workflow graphs.
- It allows incremental adoption without redesigning existing services.

Concrete shape:
- One code-managed typed Drupal config object for flow definitions.
- One small runner service that evaluates flow definitions in order and either returns `continue` or `handled`.
- Existing helper services remain in code and are called by the runner rather than duplicated into config.

### 2. Secondary: Drupal config entity + runner

This is viable later, but should not be chosen first.

Why not first:
- The module does not currently establish a config-entity pattern.
- It would add entity type definitions, storage, forms, permissions, handlers, and more tests before the immediate controller problem is solved.
- There is no repo evidence yet that Phase 1 flows must be admin-curated, listable, translatable, or individually toggled in UI.

When it becomes reasonable:
- If flows must become editor-managed records rather than code-managed definitions.

### 3. Defer: Symfony Workflow integrated into Drupal

This should not be chosen first.

Why not first:
- The repo does not currently use `symfony/workflow`.
- The Phase 1 flows are short deterministic guard flows with cache-backed conversation state, not durable place/transition graphs.
- Introducing Workflow now would add framework and conceptual weight without repo evidence that simpler approaches are insufficient.

When it becomes reasonable:
- Only if later phases prove a need for durable graph state, transition events, and richer workflow governance that cannot be met by config + runner.

## 5. Proposed Minimal Phase 1 Slice

The thinnest safe Phase 1 slice is:
- Extract office follow-up slot-fill.
- Extract clarify-loop prevention.
- Extract repeated-message escalation.

Keep the following as-is for Phase 1:
- The public route and controller entrypoint.
- Existing cache-backed conversation state.
- `ResponseBuilder` for response skeleton and URL consistency.
- `OfficeLocationResolver` for office lookup.
- `PreRoutingDecisionEngine`, `FallbackGate`, and `FallbackTreeEvaluator`.
- Existing escalation action payload creation in `getEscalationActions()`.

Recommended behavior-level shape:
- Evaluate flow definitions early in `AssistantApiController::message()`.
- Let each definition express trigger conditions, thresholds, and a target handler.
- Return either `continue` or a handled response/state mutation instruction.
- Keep office catalog data, canonical URLs, and response assembly in code, not in the config definition.

What to leave out of Phase 1:
- Service-area continuity heuristics.
- Finder clarify/disambiguation flows.
- Full switch extraction from `processIntent()`.
- Any new admin UI for flow management.

## 6. File-by-File Impact Estimate

- `web/modules/custom/ilas_site_assistant/src/Controller/AssistantApiController.php`
  Impact: high.
  Reason: the repeated-message branch, office follow-up branch, clarify-loop application, and follow-up state save points are the main reduction targets.

- `web/modules/custom/ilas_site_assistant/src/Service/ResponseBuilder.php`
  Impact: low or reuse-only.
  Reason: it should remain the canonical response skeleton and URL resolver.

- `web/modules/custom/ilas_site_assistant/src/Service/OfficeLocationResolver.php`
  Impact: low or reuse-only.
  Reason: the runner should call it rather than duplicate office data into config.

- `web/modules/custom/ilas_site_assistant/config/install/ilas_site_assistant.settings.yml`
  Impact: low-to-moderate if a typed config subtree is used.

- `web/modules/custom/ilas_site_assistant/config/schema/ilas_site_assistant.schema.yml`
  Impact: low-to-moderate if a new typed flow-definition block is introduced.

- `config/ilas_site_assistant.settings.yml`
  Impact: low-to-moderate if active export must track the new typed config structure.

- `web/modules/custom/ilas_site_assistant/tests/src/Unit/OfficeFollowupGuardContractTest.php`
  Impact: moderate.
  Reason: should remain the main lock for the office follow-up flow.

- `web/modules/custom/ilas_site_assistant/tests/src/Unit/PostSanitizeAndLoopGuardContractTest.php`
  Impact: moderate.
  Reason: should remain the main lock for clarify-loop behavior.

- `web/modules/custom/ilas_site_assistant/tests/src/Unit/IntegrationFailureContractTest.php`
  Impact: moderate.
  Reason: should continue proving repeated-message escalation and clarify-loop break behavior.

- `web/modules/custom/ilas_site_assistant/src/Service/*`
  Impact: moderate for one or two new files.
  Reason: a thin runner service and optional definition loader are justified.

- Plugin files, config-entity files, and workflow config
  Impact: none expected in Phase 1.

## 7. Risks and Unknowns

**Risks / Unknowns**

- `processIntent()` still mixes response enrichment with branch logic. If Phase 1 expands beyond the three recommended flows, the runner will start absorbing response-formatting responsibilities.
- Office follow-up currently depends on the hardcoded office catalog in `OfficeLocationResolver::OFFICES`. The safe move is to reference that service from the runner, not migrate office data into flow config.
- Escalation CTAs are currently controller-owned in `getEscalationActions()`. If later work wants flow-owned CTA payloads, that should be a separate deliberate refactor.
- History continuity behavior may belong in a policy service rather than the Phase 1 runner.
- The precise config placement is still an implementation detail. The smallest safe choice is a dedicated typed Drupal config object, but a carefully namespaced typed subtree would still fit the recommended primary architecture.
- The current audit did not prove a need for editor-managed flow records or workflow-engine semantics.

## 8. Next Approved Step

Produce `docs/codex_plans/phase1_flows_plan.md` as the implementation plan.

That plan should:
- Lock the exact typed config shape.
- Define the runner contract and controller call sites.
- Limit Phase 1 to office follow-up, clarify-loop prevention, and repeated-message escalation.
- Preserve the current test-backed behavior while reducing controller-local orchestration.
