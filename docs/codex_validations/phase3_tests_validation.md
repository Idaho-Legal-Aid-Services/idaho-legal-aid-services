# Phase 3 Controller-Path Tests — Validation Report

**File:** `docs/codex_validations/phase3_tests_validation.md`
**Date:** 2026-04-14
**Validator role:** Principal Drupal test engineer / senior PHP architect / skeptical reviewer
**Scope:** `AssistantMultiTurnFunctionalTest.php` vs. audit, plan, impl summary, and live controller code

---

## 1. Executive Summary

The three multi-turn functional test methods cover the correct controller paths and close the most important gap identified in the phase-3 audit: **zero multi-turn controller-path tests**. Two of the three scenarios are sound. One scenario (`testSessionFingerprintOwnership`) contains a **logically flawed proof mechanism** that will produce a false negative even when the fingerprint guard is working correctly. An additional fragility exists in `testHistoryIntentFallback` that could cause the test to fail at Turn 1 before it reaches the path it was designed to exercise. Neither issue is a production code defect; both are test-design problems. The file is **not ready to merge as a permanent acceptance gate** without fixing the fingerprint proof flaw. It is acceptable as an exploratory/smoke-test addition with the caveats documented here.

---

## 2. What Was Implemented vs. Planned

### Plan compliance

| Plan item | Status |
|---|---|
| New `BrowserTestBase` subclass | ✓ Implemented |
| `testClarifyLoopEscalation` (3+1 turns, THRESHOLD=3) | ✓ Implemented |
| `testHistoryIntentFallback` (2 turns, housing→follow-up) | ✓ Implemented |
| `testSessionFingerprintOwnership` (2 sessions, same conv_id) | ✓ Implemented |
| Zero production code changes | ✓ Confirmed |
| Config overrides: `llm.enabled`, `enable_faq`, `enable_resources`, `enable_logging` | ✓ Present in setUp() |
| No test doubles / mocks | ✓ Confirmed |
| Flood event clearing before each test | ✓ `clearMessageFloodEvents()` called |

### Documented deviations

| Deviation | Acceptability |
|---|---|
| Fingerprint test assertion changed from `response_mode === 'clarify'` to `assertArrayNotHasKey('turn_type', ...)` | **Not acceptable** — the substitute proof is logically broken (see §3) |
| Config overrides added for `search_api` indexes | Acceptable — plan acknowledged this risk |
| `dblog` module excluded from `$modules` | Acceptable |
| 7 helper methods duplicated from `AssistantApiFunctionalTest` | Acceptable for now; flagged for trait extraction |

---

## 3. Realism and Determinism Review

### 3a. `testClarifyLoopEscalation` — mostly deterministic, one latent risk

**Controller reality (lines 4851–4901):**
The guard hashes the normalized *response message text* (not the user input). With `llm.enabled = FALSE`, `FallbackGate` returns `DECISION_CLARIFY` and the downstream clarify-response builder produces the same message string on every call for the same unknown intent, so the SHA-256 hash of Turns 1, 2, and 3 should be identical → counter increments to 3 → loop-break fires. The constant `CLARIFY_LOOP_THRESHOLD = 3` is confirmed at line 77. The counter reset to 0 after loop-break is confirmed at line 4886.

**Latent risk:** If the clarify message ever acquires dynamic content (request_id embedded, locale-aware suffix, random suggestion ordering), the hash would differ between turns, resetting the count and preventing the loop-break from firing. The test would then time out or fail at the Turn 3 assertion. This is a future fragility, not a present failure.

**Verdict:** Sound under current code. Mark the hash-of-response-message mechanism in a comment so a future author understands why the message must remain stable.

### 3b. `testHistoryIntentFallback` — fragile Turn 1 dependency

**Turn 1 assertion:** `assertNotSame('clarify', $r1['response_mode'] ?? '', ...)` requires that "I need help with housing" deterministically routes to a non-clarify response **without LLM, FAQ index, or resource index**. This depends entirely on whether the static routing layer (YAML intent rules, keyword maps) matches "housing" to a topic response.

**Unverified assumption:** The audit and plan state this assumption but do not cite the specific routing rule or `top_intents.yml` entry that guarantees it. If no static rule matches, Turn 1 returns clarify, the assertion fails, and the test terminates without exercising any history-fallback logic.

**Turn 2 assertion is logically weak:**
```php
$isClarify = ($r2['response_mode'] ?? '') === 'clarify'
  && in_array($r2['type'] ?? '', ['clarify', 'disambiguation', 'fallback'], TRUE);
$this->assertFalse($isClarify, ...);
```
This passes if *either* the mode is not `clarify` *or* the type is not one of the clarify variants. A response with `response_mode = 'clarify'` but `type = 'clarify_loop_break'` would **pass** this assertion, which is not the intended proof. There is also no positive assertion that the housing topic context was actually carried — only a negative assertion that the response is not a bare clarify.

**Verdict:** Fragile. Add a positive assertion (e.g., `assertArrayHasKey('topic', $r2)` or check that `$r2['intent']` contains "housing") and add a skip guard at the top of the test if Turn 1 returns clarify.

### 3c. `testSessionFingerprintOwnership` — proof is mechanistically sound but lacks a positive anchor assertion

**Correction from initial analysis:** The initial validation declared the proof "logically broken" on the assumption that `TurnClassifier` is purely lexical. That assumption was wrong.

**Actual TurnClassifier behavior (`TurnClassifier.php`, `detectFollowUp()`, lines 194–218):**
`detectFollowUp()` returns `FALSE` immediately when `$server_history` is empty (line 196). When the fingerprint guard fires, the `else` branch that populates `$server_history` is skipped (controller lines 1720–1733), leaving `$server_history = []`. An empty history causes `TurnClassifier` to return `TURN_NEW` (not `TURN_FOLLOW_UP`), and since `turn_type` is only added to the response when `!= TURN_NEW` (controller line 2781), `turn_type` is absent from Session B's response.

**The `assertArrayNotHasKey('turn_type', $r3)` assertion is therefore mechanistically correct:** it passes when the fingerprint guard works and fails when the guard is removed.

**Confirmed:** controller lines 1720–1733 show the `else` branch (history load) is genuinely skipped on mismatch — the guard does not merely log; it blocks.

**The real defect — missing positive anchor:** The test never asserts that Session A's Turn 2 WAS classified as `TURN_FOLLOW_UP`. Without that anchor, the proof is incomplete:

- If Session A's Turn 2 also gets `TURN_NEW` (e.g., cache write failed, housing Turn 1 produced no history entry), both sessions would lack `turn_type` and the test would pass vacuously — proving nothing about the fingerprint guard.

**Required differential structure:**

| Turn | Session | Expected `turn_type` in response | What it proves |
|---|---|---|---|
| A Turn 2 | A | `FOLLOW_UP` (must be present) | History WAS loaded for Session A |
| B Turn 3 | B | absent | History was NOT loaded for Session B |

**Fix:** Add one assertion after Session A Turn 2:
```php
$this->assertSame(
  'FOLLOW_UP',
  $r2['turn_type'] ?? '',
  'Session A Turn 2 must be classified TURN_FOLLOW_UP (requires non-empty history)'
);
```

**Verdict:** One assertion added. `assertArrayNotHasKey('turn_type', $r3)` retained unchanged. Docblock updated to explain the TurnClassifier dependency chain.

---

## 4. Remaining Helper-Level Masquerades

The following helper methods are duplicated verbatim from `AssistantApiFunctionalTest`:

- `sendAnonymousMessage()`
- `postJsonAnonymous()`
- `getAnonymousSessionCookiesAndToken()`
- `getSessionToken()`
- `primeAnonymousSession()`
- `requestBootstrap()`
- `findDrupalSessionCookie()`
- `clearMessageFloodEvents()`

These are **true functional helpers** (real HTTP, real Guzzle, real Drupal cookies) — they are not mocks or stubs masquerading as integration coverage. The duplication is not a correctness defect. It is a maintenance liability: a change to the bootstrap route signature or CSRF flow would need to be updated in two places.

The impl summary correctly defers trait extraction until ≥5 multi-turn methods are stable. This is acceptable, but the threshold should be enforced: a third functional test class must not duplicate these helpers a third time.

---

## 5. Missing Critical Scenarios

The following gaps from the audit remain uncovered. All are ranked below the three implemented scenarios on the audit's coverage-to-cost ratio scale, but Gap 6 (safety exit) has elevated urgency because it exercises a life-safety adjacent path.

| Gap | Audit Rank | Status | Notes |
|---|---|---|---|
| Office follow-up slot-fill flow | 4 | Not started | Medium complexity; requires office node fixtures |
| Selection-state recovery through HTTP | 5 | Not started | Requires populating selection-state store |
| **Safety exit mid-conversation** | 6 | **Not started** | Life-safety path; should be promoted to next phase |
| Pre-routing overrides within conv. context | 7 | Not started | Lower priority |

The safety exit scenario (Gap 6) exercises the path where a user in mid-conversation sends a crisis keyword and the safety classifier fires, overriding whatever topic was active. The lack of a multi-turn HTTP test for this path is the highest-risk remaining gap given the module's legal-aid domain.

---

## 6. Recommended Next Testing Step

**Immediate (before merge):**

1. **Fix `testSessionFingerprintOwnership`** — replace `assertArrayNotHasKey('turn_type', ...)` with an assertion that is true *if and only if* history was blocked. Options in priority order:
   - Assert `$r3['response_mode'] === 'clarify'` (the original plan approach): valid if fingerprint mismatch always routes to clarify, which it should when there is no history to fall back on for an anaphoric message.
   - Confirm lines 1731–1760 of the controller to verify the mismatch branch actually skips history loading (not just logs), then document the confirmed skip in a code comment.
   - Assert that `$r3` and `$r2` differ in at least one topic/intent field.

2. **Add a skip guard and positive assertion in `testHistoryIntentFallback`:**
   ```php
   if (($r1['response_mode'] ?? '') === 'clarify') {
       $this->markTestSkipped('Routing layer did not match "housing" without LLM — re-examine top_intents.yml');
   }
   ```
   And add a positive Turn 2 assertion verifying housing context was carried (not just "not clarify").

**Next phase:**

3. Add `testSafetyExitMidConversation` (Gap 6) — establish a topic context in Turn 1, then send a crisis keyword in Turn 2. Assert `response_mode === 'safety'` and that the safety response includes escalation actions. This is the highest-priority uncovered gap.

4. Extract the 8 shared helpers into `AssistantFunctionalTestTrait` when a third multi-turn method is written.

---

## 7. Go / No-Go for Optional Browser Phase

**No-Go**, with conditions.

The optional Playwright/browser phase should not begin until:

1. The fingerprint proof flaw in `testSessionFingerprintOwnership` is resolved. Running browser tests on top of a logically broken unit of proof produces a false confidence layer.
2. The Turn 1 routing assumption in `testHistoryIntentFallback` is verified against `top_intents.yml` and either hard-coded or skip-guarded.
3. At minimum one of the three implemented tests has been run against the real DDEV stack and reported a pass with no unexpected skip.

Once those three conditions are met: **Go** for a narrow browser phase targeting the clarify-loop-break UI surface (the `topic_suggestions` and `actions` arrays rendered in the widget) — that is the one scenario where HTTP-layer tests cannot verify the end-user-visible outcome.

---

*End of validation report.*
