# Phase 1 Flows Plan

## Executive Summary
Implement the smallest safe config-backed flow framework by extending the existing typed config object [ilas_site_assistant.settings.yml](/home/evancurry/idaho-legal-aid-services/web/modules/custom/ilas_site_assistant/config/install/ilas_site_assistant.settings.yml) with one code-managed `flows` subtree and adding one required runner service at [web/modules/custom/ilas_site_assistant/src/Service/AssistantFlowRunner.php](/home/evancurry/idaho-legal-aid-services/web/modules/custom/ilas_site_assistant/src/Service/AssistantFlowRunner.php). In this phase, migrate only the office follow-up slot-fill flow that is currently split across [AssistantApiController::message()](/home/evancurry/idaho-legal-aid-services/web/modules/custom/ilas_site_assistant/src/Controller/AssistantApiController.php#L1356) and [AssistantApiController::processIntent()](/home/evancurry/idaho-legal-aid-services/web/modules/custom/ilas_site_assistant/src/Controller/AssistantApiController.php#L3424).

Keep the public route, cache keys, response payloads, PII redaction, analytics logging, and monitored response assembly unchanged. The controller remains the owner of response construction through [handleOfficeFollowUp()](/home/evancurry/idaho-legal-aid-services/web/modules/custom/ilas_site_assistant/src/Controller/AssistantApiController.php#L4534) and [handleOfficeFollowUpClarify()](/home/evancurry/idaho-legal-aid-services/web/modules/custom/ilas_site_assistant/src/Controller/AssistantApiController.php#L4658); the new runner only owns config parsing and office-flow decisions.

## Source of Truth / Files Inspected
- [docs/codex_audits/phase1_flows_audit.md](/home/evancurry/idaho-legal-aid-services/docs/codex_audits/phase1_flows_audit.md)
- [AssistantApiController.php](/home/evancurry/idaho-legal-aid-services/web/modules/custom/ilas_site_assistant/src/Controller/AssistantApiController.php#L73)
- [ilas_site_assistant.routing.yml](/home/evancurry/idaho-legal-aid-services/web/modules/custom/ilas_site_assistant/ilas_site_assistant.routing.yml#L8)
- [ilas_site_assistant.services.yml](/home/evancurry/idaho-legal-aid-services/web/modules/custom/ilas_site_assistant/ilas_site_assistant.services.yml)
- [install settings config](/home/evancurry/idaho-legal-aid-services/web/modules/custom/ilas_site_assistant/config/install/ilas_site_assistant.settings.yml)
- [active settings export](/home/evancurry/idaho-legal-aid-services/config/ilas_site_assistant.settings.yml)
- [schema](/home/evancurry/idaho-legal-aid-services/web/modules/custom/ilas_site_assistant/config/schema/ilas_site_assistant.schema.yml)
- [ConfigCompletenessDriftTest.php](/home/evancurry/idaho-legal-aid-services/web/modules/custom/ilas_site_assistant/tests/src/Unit/ConfigCompletenessDriftTest.php#L12)
- [TopIntentsPack.php](/home/evancurry/idaho-legal-aid-services/web/modules/custom/ilas_site_assistant/src/Service/TopIntentsPack.php#L178)
- [DisambiguationPack.php](/home/evancurry/idaho-legal-aid-services/web/modules/custom/ilas_site_assistant/src/Service/DisambiguationPack.php#L72)
- [OfficeFollowupGuardContractTest.php](/home/evancurry/idaho-legal-aid-services/web/modules/custom/ilas_site_assistant/tests/src/Unit/OfficeFollowupGuardContractTest.php#L31)
- [PostSanitizeAndLoopGuardContractTest.php](/home/evancurry/idaho-legal-aid-services/web/modules/custom/ilas_site_assistant/tests/src/Unit/PostSanitizeAndLoopGuardContractTest.php#L186)
- [IntegrationFailureContractTest.php](/home/evancurry/idaho-legal-aid-services/web/modules/custom/ilas_site_assistant/tests/src/Unit/IntegrationFailureContractTest.php#L640)

Baseline verification run on 2026-03-24:
- `vendor/bin/phpunit -c phpunit.pure.xml web/modules/custom/ilas_site_assistant/tests/src/Unit/OfficeFollowupGuardContractTest.php web/modules/custom/ilas_site_assistant/tests/src/Unit/PostSanitizeAndLoopGuardContractTest.php` passed (`13` tests, `41` assertions).
- `vendor/bin/phpunit -c phpunit.pure.xml --filter 'testClarifyLoopBreaksOnThirdRepeat|testMessageRepeatedEscalationRecordsShortcutSuccessOnce' web/modules/custom/ilas_site_assistant/tests/src/Unit/IntegrationFailureContractTest.php` passed (`2` tests, `18` assertions).

## Findings
- The approved primary architecture is confirmed by the repo: schema-backed exported config already exists in [install settings](/home/evancurry/idaho-legal-aid-services/web/modules/custom/ilas_site_assistant/config/install/ilas_site_assistant.settings.yml) and [active export](/home/evancurry/idaho-legal-aid-services/config/ilas_site_assistant.settings.yml), and parity is already enforced by [ConfigCompletenessDriftTest.php](/home/evancurry/idaho-legal-aid-services/web/modules/custom/ilas_site_assistant/tests/src/Unit/ConfigCompletenessDriftTest.php#L54).
- Lightweight YAML-backed loader services already exist in [TopIntentsPack.php](/home/evancurry/idaho-legal-aid-services/web/modules/custom/ilas_site_assistant/src/Service/TopIntentsPack.php#L178) and [DisambiguationPack.php](/home/evancurry/idaho-legal-aid-services/web/modules/custom/ilas_site_assistant/src/Service/DisambiguationPack.php#L72), so a small config-reading runner matches current module practice.
- The repo does not establish a config-entity or Workflow baseline. Repo search found no `@ConfigEntityType`, `ConfigEntityBase`, or `symfony/workflow` usage in `web/modules/custom/ilas_site_assistant`, `composer.json`, or `composer.lock`.
- The public contract to preserve is [POST /assistant/api/message](/home/evancurry/idaho-legal-aid-services/web/modules/custom/ilas_site_assistant/ilas_site_assistant.routing.yml#L8), handled by [AssistantApiController::message()](/home/evancurry/idaho-legal-aid-services/web/modules/custom/ilas_site_assistant/src/Controller/AssistantApiController.php#L339).
- Office follow-up is the first real flow to migrate. It is armed by the `apply` path at [AssistantApiController.php#L3424](/home/evancurry/idaho-legal-aid-services/web/modules/custom/ilas_site_assistant/src/Controller/AssistantApiController.php#L3424) and [AssistantApiController.php#L1852](/home/evancurry/idaho-legal-aid-services/web/modules/custom/ilas_site_assistant/src/Controller/AssistantApiController.php#L1852), consumed at [AssistantApiController.php#L1356](/home/evancurry/idaho-legal-aid-services/web/modules/custom/ilas_site_assistant/src/Controller/AssistantApiController.php#L1356), stored via [AssistantApiController.php#L4170](/home/evancurry/idaho-legal-aid-services/web/modules/custom/ilas_site_assistant/src/Controller/AssistantApiController.php#L4170), and already has dedicated contract coverage in [OfficeFollowupGuardContractTest.php](/home/evancurry/idaho-legal-aid-services/web/modules/custom/ilas_site_assistant/tests/src/Unit/OfficeFollowupGuardContractTest.php#L31).
- Clarify-loop and repeated-message escalation remain verified later candidates, not this slice. Their current contracts are already green in [PostSanitizeAndLoopGuardContractTest.php](/home/evancurry/idaho-legal-aid-services/web/modules/custom/ilas_site_assistant/tests/src/Unit/PostSanitizeAndLoopGuardContractTest.php#L186) and [IntegrationFailureContractTest.php](/home/evancurry/idaho-legal-aid-services/web/modules/custom/ilas_site_assistant/tests/src/Unit/IntegrationFailureContractTest.php#L640).

## Recommendation
Use the existing typed config object `ilas_site_assistant.settings`, not a second config object, config entity, or Symfony Workflow. Add a code-managed `flows` subtree and one required service `Drupal\ilas_site_assistant\Service\AssistantFlowRunner`.

Lock the internal additions to:
- Typed config subtree `ilas_site_assistant.settings.flows.office_followup`.
- Required service `AssistantFlowRunner` with two methods: `evaluatePending(array $context): array` and `evaluatePostResponse(array $context): array`.
- Decision array contract: `status`, `flow_id`, `action`, `state_operation`, `state_payload`, and optional `office`.

No public API, route, response-field, or cache-key changes are allowed in this phase.

## Scope Boundaries
- In scope: typed `flows` config; `AssistantFlowRunner`; migrating only the office follow-up consume path at [AssistantApiController.php#L1356](/home/evancurry/idaho-legal-aid-services/web/modules/custom/ilas_site_assistant/src/Controller/AssistantApiController.php#L1356) and the office follow-up arm path at [AssistantApiController.php#L1852](/home/evancurry/idaho-legal-aid-services/web/modules/custom/ilas_site_assistant/src/Controller/AssistantApiController.php#L1852); additive state compatibility for configured TTL; targeted test updates; validation through `VC-PURE`, `VC-UNIT`, and `VC-DRUPAL-UNIT`.
- Preserve unchanged: cache keys `ilas_conv:<uuid>`, `ilas_conv_followup:<uuid>`, and `ilas_conv_meta:<uuid>`; `request_id`; `X-Correlation-ID`; PII redaction; analytics logging; conversation logging; monitored response paths; response types `office_location` and `office_location_clarify`.
- Preserve unchanged: [AssistantSettingsForm.php](/home/evancurry/idaho-legal-aid-services/web/modules/custom/ilas_site_assistant/src/Form/AssistantSettingsForm.php#L22) remains untouched. Flow config is code-managed only in Phase 1.

## Implementation Scope
- In scope: the thin framework and the first real flow migration only.
- Out of scope for this phase: router redesign, retrieval redesign, response-builder redesign, or admin CRUD for flows.
- Preserve current safety, privacy, and public response contracts.
- Keep the current cache-backed state model in place for this phase.

## Step-by-Step Plan
1. Extend [install settings](/home/evancurry/idaho-legal-aid-services/web/modules/custom/ilas_site_assistant/config/install/ilas_site_assistant.settings.yml), [active export](/home/evancurry/idaho-legal-aid-services/config/ilas_site_assistant.settings.yml), and [schema](/home/evancurry/idaho-legal-aid-services/web/modules/custom/ilas_site_assistant/config/schema/ilas_site_assistant.schema.yml) with this exact subtree:

   ```yaml
   flows:
     enabled: true
     office_followup:
       enabled: true
       handler: office_followup
       trigger_intents:
         - apply
       require_followup_prompt: true
       max_turns: 2
       ttl_seconds: 1800
   ```

2. Add [AssistantFlowRunner.php](/home/evancurry/idaho-legal-aid-services/web/modules/custom/ilas_site_assistant/src/Service/AssistantFlowRunner.php) and register it in [ilas_site_assistant.services.yml](/home/evancurry/idaho-legal-aid-services/web/modules/custom/ilas_site_assistant/ilas_site_assistant.services.yml). Inject only `config.factory` and [OfficeLocationResolver](/home/evancurry/idaho-legal-aid-services/web/modules/custom/ilas_site_assistant/src/Service/OfficeLocationResolver.php#L1). The runner must not build `JsonResponse` objects, touch route handling, or own cache persistence.
3. Move the office-followup decision matrix out of [AssistantApiController::message()](/home/evancurry/idaho-legal-aid-services/web/modules/custom/ilas_site_assistant/src/Controller/AssistantApiController.php#L1356) into `AssistantFlowRunner::evaluatePending()` with the current behavior preserved exactly:
   - If there is no pending office-followup state or the flow is disabled, return `status=continue`.
   - If the current message resolves to an office through the injected resolver, return `status=handled`, `action=resolve`, `state_operation=clear`, and the resolved office payload.
   - If the current message is location-like or an explicit office-followup turn, return `status=handled`, `action=clarify`, `state_operation=clear`.
   - Otherwise return `status=continue`, `state_operation=decrement`.
4. Replace the arm logic at [AssistantApiController.php#L1852](/home/evancurry/idaho-legal-aid-services/web/modules/custom/ilas_site_assistant/src/Controller/AssistantApiController.php#L1852) with `AssistantFlowRunner::evaluatePostResponse()`. The arm trigger must stay exactly `normalized_intent === 'apply' && !empty($response['followup'])`; do not widen it to a generic `apply` response. For `arm`, the runner returns `origin_intent`, `remaining_turns`, `created_at`, and `ttl_seconds`.
5. Keep [loadOfficeFollowupState()](/home/evancurry/idaho-legal-aid-services/web/modules/custom/ilas_site_assistant/src/Controller/AssistantApiController.php#L4172), [saveOfficeFollowupState()](/home/evancurry/idaho-legal-aid-services/web/modules/custom/ilas_site_assistant/src/Controller/AssistantApiController.php#L4206), and [clearOfficeFollowupState()](/home/evancurry/idaho-legal-aid-services/web/modules/custom/ilas_site_assistant/src/Controller/AssistantApiController.php#L4229) in the controller for this slice so the cache keys stay unchanged. Make the state payload additive by persisting `ttl_seconds`, and default missing `ttl_seconds` to `self::CONVERSATION_STATE_TTL` so in-flight pre-deploy conversations still expire correctly.
6. Make `AssistantFlowRunner` a required constructor dependency in [AssistantApiController::__construct()](/home/evancurry/idaho-legal-aid-services/web/modules/custom/ilas_site_assistant/src/Controller/AssistantApiController.php#L339) and [AssistantApiController::create()](/home/evancurry/idaho-legal-aid-services/web/modules/custom/ilas_site_assistant/src/Controller/AssistantApiController.php#L414). Do the matching sweep across every unit test builder that directly instantiates `AssistantApiController` or subclasses; use the already-verified search surface `rg -n 'new AssistantApiController\\(|extends AssistantApiController' web/modules/custom/ilas_site_assistant/tests/src/Unit`.
7. Extend tests in three places:
   - [ConfigCompletenessDriftTest.php](/home/evancurry/idaho-legal-aid-services/web/modules/custom/ilas_site_assistant/tests/src/Unit/ConfigCompletenessDriftTest.php#L54) gets explicit nested coverage for `flows.office_followup` install/active/schema parity.
   - [OfficeFollowupGuardContractTest.php](/home/evancurry/idaho-legal-aid-services/web/modules/custom/ilas_site_assistant/tests/src/Unit/OfficeFollowupGuardContractTest.php#L31) becomes the primary contract for config-backed office-flow arm, consume, clarify, decrement, and TTL behavior.
   - [IntegrationFailureContractTest.php](/home/evancurry/idaho-legal-aid-services/web/modules/custom/ilas_site_assistant/tests/src/Unit/IntegrationFailureContractTest.php#L640) gains one request-level proof that an `apply` response arms the flow and the next turn is handled through the runner-backed office-followup path without changing status code or response contract fields.
8. Validate with the repo’s real commands:
   - `VC-PURE`: `vendor/bin/phpunit --configuration /home/evancurry/idaho-legal-aid-services/phpunit.pure.xml`
   - `VC-UNIT`: `ddev exec vendor/bin/phpunit --configuration /var/www/html/phpunit.xml --group ilas_site_assistant /var/www/html/web/modules/custom/ilas_site_assistant/tests/src/Unit`
   - `VC-DRUPAL-UNIT`: `vendor/bin/phpunit --configuration /home/evancurry/idaho-legal-aid-services/phpunit.xml --testsuite drupal-unit`

## Files to Add / Change
- Add [web/modules/custom/ilas_site_assistant/src/Service/AssistantFlowRunner.php](/home/evancurry/idaho-legal-aid-services/web/modules/custom/ilas_site_assistant/src/Service/AssistantFlowRunner.php)
- Change [web/modules/custom/ilas_site_assistant/ilas_site_assistant.services.yml](/home/evancurry/idaho-legal-aid-services/web/modules/custom/ilas_site_assistant/ilas_site_assistant.services.yml)
- Change [web/modules/custom/ilas_site_assistant/src/Controller/AssistantApiController.php](/home/evancurry/idaho-legal-aid-services/web/modules/custom/ilas_site_assistant/src/Controller/AssistantApiController.php)
- Change [web/modules/custom/ilas_site_assistant/config/install/ilas_site_assistant.settings.yml](/home/evancurry/idaho-legal-aid-services/web/modules/custom/ilas_site_assistant/config/install/ilas_site_assistant.settings.yml)
- Change [config/ilas_site_assistant.settings.yml](/home/evancurry/idaho-legal-aid-services/config/ilas_site_assistant.settings.yml)
- Change [web/modules/custom/ilas_site_assistant/config/schema/ilas_site_assistant.schema.yml](/home/evancurry/idaho-legal-aid-services/web/modules/custom/ilas_site_assistant/config/schema/ilas_site_assistant.schema.yml)
- Change [web/modules/custom/ilas_site_assistant/tests/src/Unit/ConfigCompletenessDriftTest.php](/home/evancurry/idaho-legal-aid-services/web/modules/custom/ilas_site_assistant/tests/src/Unit/ConfigCompletenessDriftTest.php)
- Change [web/modules/custom/ilas_site_assistant/tests/src/Unit/OfficeFollowupGuardContractTest.php](/home/evancurry/idaho-legal-aid-services/web/modules/custom/ilas_site_assistant/tests/src/Unit/OfficeFollowupGuardContractTest.php)
- Change [web/modules/custom/ilas_site_assistant/tests/src/Unit/IntegrationFailureContractTest.php](/home/evancurry/idaho-legal-aid-services/web/modules/custom/ilas_site_assistant/tests/src/Unit/IntegrationFailureContractTest.php)
- Change every direct controller-instantiation unit builder found by the `rg` sweep in `web/modules/custom/ilas_site_assistant/tests/src/Unit`

## Regression Risks
- Legacy `ilas_conv_followup:<uuid>` entries do not currently store `ttl_seconds`. If load/save logic assumes the new field is always present, in-flight conversations will regress.
- The arm trigger must remain tied to the current `apply` response’s `followup` field, not to a broader response type check.
- Moving the office-followup reply predicates out of the controller must preserve their exact regex behavior or unrelated turns will start qualifying as office followups.
- Required runner injection will break unit builders immediately unless the constructor sweep lands in the same slice.
- This phase does not remove all controller-side helper instantiation. Non-followup office-detail branches still instantiate [OfficeLocationResolver](/home/evancurry/idaho-legal-aid-services/web/modules/custom/ilas_site_assistant/src/Controller/AssistantApiController.php#L2957) directly and must not be reported as solved by this change.

## Risks / Unknowns
- Reachability of every remaining controller-side helper construction outside the office follow-up path still needs separate proof.
- Any future need for individually listable, translatable, or permissioned flows would reopen the config-entity question, but the current repo does not justify that complexity.
- Existing cache state from pre-deploy follow-up sessions must remain backward compatible when `ttl_seconds` is introduced.
- This plan does not yet prove clarify-loop and repeated-message flow extraction; it explicitly defers them.

## Out-of-Scope Items
- Clarify-loop migration from [AssistantApiController.php#L1817](/home/evancurry/idaho-legal-aid-services/web/modules/custom/ilas_site_assistant/src/Controller/AssistantApiController.php#L1817) and [AssistantApiController.php#L3976](/home/evancurry/idaho-legal-aid-services/web/modules/custom/ilas_site_assistant/src/Controller/AssistantApiController.php#L3976)
- Repeated-message escalation migration from [AssistantApiController.php#L983](/home/evancurry/idaho-legal-aid-services/web/modules/custom/ilas_site_assistant/src/Controller/AssistantApiController.php#L983)
- General `processIntent()` switch extraction, including [new ResponseBuilder()](/home/evancurry/idaho-legal-aid-services/web/modules/custom/ilas_site_assistant/src/Controller/AssistantApiController.php#L2904)
- History/service-area continuity, [PreRoutingDecisionEngine](/home/evancurry/idaho-legal-aid-services/web/modules/custom/ilas_site_assistant/src/Service/PreRoutingDecisionEngine.php), [FallbackGate](/home/evancurry/idaho-legal-aid-services/web/modules/custom/ilas_site_assistant/src/Service/FallbackGate.php), and [FallbackTreeEvaluator](/home/evancurry/idaho-legal-aid-services/web/modules/custom/ilas_site_assistant/src/Service/FallbackTreeEvaluator.php)
- Admin flow CRUD, config entities, Symfony Workflow, or any new editor-facing UI

## Implementation Stop Points
- Stop Point 1: add the `flows` config subtree, schema/export parity updates, `AssistantFlowRunner` service registration, required controller injection, and unit-builder sweep; runner returns `continue` only so behavior is unchanged.
- Stop Point 2: switch the pending office-followup consume path to runner decisions while keeping [handleOfficeFollowUp()](/home/evancurry/idaho-legal-aid-services/web/modules/custom/ilas_site_assistant/src/Controller/AssistantApiController.php#L4534) and [handleOfficeFollowUpClarify()](/home/evancurry/idaho-legal-aid-services/web/modules/custom/ilas_site_assistant/src/Controller/AssistantApiController.php#L4658) untouched.
- Stop Point 3: switch the `apply` arm path to runner/config-backed state payloads, add backward-compatible `ttl_seconds` handling, and run `VC-PURE`, `VC-UNIT`, and `VC-DRUPAL-UNIT`.
- Hard stop after Stop Point 3: do not start clarify-loop or repeated-message extraction in this phase.

## Next Approved Step
Implement Stop Point 1 exactly: extend `ilas_site_assistant.settings` with the `flows.office_followup` subtree, update install/active/schema parity coverage, add `AssistantFlowRunner` as a required service and controller dependency, sweep direct controller test builders, and stop before moving any office-followup behavior.
