# Urgency Detection for Legal Deadlines

This document describes the current deadline-urgency contract for the ILAS Site Assistant.

## Overview

Deadline urgency is no longer detected inside `IntentRouter` or `KeywordExtractor`.
The authoritative flow is:

1. Normalize the message
2. Evaluate `PreRoutingDecisionEngine`
3. If the engine returns `continue`, pass the message to `IntentRouter`
4. If the engine emits `routing_override_intent=high_risk_deadline`, hard-route to `/Legal-Advice-Line`

## Authoritative Source

### PreRoutingDecisionEngine
**File:** `src/Service/PreRoutingDecisionEngine.php`

The engine is the single source of truth for:
- deadline urgency signals
- urgency dampeners
- overlap precedence between safety, out-of-scope, policy, and urgency override

Deadline-only overlaps must produce:

```php
[
  'decision_type' => 'continue',
  'winner_source' => 'urgency',
  'urgency_signals' => ['deadline_pressure'],
  'routing_override_intent' => [
    'type' => 'high_risk',
    'risk_category' => 'high_risk_deadline',
  ],
]
```

Eviction emergencies, DV emergencies, scams, crisis, and refusal cases must not downgrade into deadline overrides. Those remain hard-stop exits.

## Decision Flow

```
User Message
     |
     v
InputNormalizer
     |
     v
PreRoutingDecisionEngine
  - SafetyClassifier
  - OutOfScopeClassifier
  - PolicyFilter
  - deadline urgency evaluator
     |
     +--> safety_exit / oos_exit / policy_exit
     |
     +--> continue + routing_override_intent=high_risk_deadline
                    |
                    v
                IntentRouter
```

## Deadline Signals

Representative phrases handled by the engine include:
- `deadline tomorrow`, `deadline friday`, `due tomorrow`
- `court date tomorrow`, `hearing monday`
- `respond to lawsuit`, `served with papers`
- `have to file by monday`, `respond in 48 hours`
- `fecha limite hoy`, `tengo que responder`, `corte manana`
- `tengo una corte date manana`

## Dampeners

Informational queries must not emit deadline overrides. Examples:
- `how long do i have`
- `what is the deadline`
- `typical deadline`
- `deadline information`
- `cuanto tiempo tengo`
- `general information about court dates`

## Contract Tests

Primary coverage now lives in:
- `tests/src/Unit/UrgencyDetectionTest.php`
- `tests/src/Unit/PreRoutingDecisionEngineContractTest.php`

These tests lock:
- golden deadline utterances -> `deadline_pressure` + `high_risk_deadline`
- informational dampeners -> no urgency override
- eviction emergencies -> `safety_exit`, not deadline override
