# Fallback Gate Specification

The Fallback Gate is a confidence-based decision system that determines when to use built-in answers vs. LLM (Gemini) fallback for chatbot responses.

## Overview

The gate evaluates three main confidence signals:
1. **Intent Confidence** - How confident we are in the detected intent
2. **Retrieval Confidence** - Quality of FAQ/resource search results
3. **Safety Flags** - Urgent situations requiring hard routing

Based on these signals, the gate makes one of four decisions:
- `ANSWER` - Respond with built-in logic
- `CLARIFY` - Ask the user for more information
- `FALLBACK_LLM` - Route to Gemini for classification/response
- `HARD_ROUTE` - Force routing to specific resource (safety override)

## Decision Flow

```
┌─────────────────────────────────────────────────────────────────┐
│                        User Message                             │
└─────────────────────────────────────────────────────────────────┘
                                │
                                ▼
                   ┌─────────────────────────┐
                   │   Policy Violation?     │───Yes───▶ HARD_ROUTE (POLICY_VIOLATION)
                   └─────────────────────────┘
                                │ No
                                ▼
                   ┌─────────────────────────┐
                   │   Safety Flags Set?     │───Yes───▶ HARD_ROUTE (SAFETY_URGENT)
                   └─────────────────────────┘
                                │ No
                                ▼
                   ┌─────────────────────────┐
                   │   High-Risk Intent?     │───Yes───▶ HARD_ROUTE (SAFETY_URGENT)
                   └─────────────────────────┘
                                │ No
                                ▼
                   ┌─────────────────────────┐
                   │   Out-of-Scope?         │───Yes───▶ HARD_ROUTE (OUT_OF_SCOPE)
                   └─────────────────────────┘
                                │ No
                                ▼
                   ┌─────────────────────────┐
                   │   Greeting?             │───Yes───▶ ANSWER (GREETING)
                   └─────────────────────────┘
                                │ No
                                ▼
                   ┌─────────────────────────┐
                   │   Unknown Intent?       │───Yes───┐
                   └─────────────────────────┘         │
                                │ No                    │
                                ▼                       ▼
                   ┌─────────────────────────┐  ┌──────────────────────┐
                   │ Direct Route Intent?    │  │ High Retrieval Conf? │
                   │ (apply, hotline, etc.)  │  └──────────────────────┘
                   └─────────────────────────┘           │
                                │                         ▼
                                │ Yes            Yes ───▶ ANSWER (HIGH_CONF_RETRIEVAL)
                                │                  │
                                ▼                  │ No
                   ┌─────────────────────────┐     │
                   │ Intent Conf >= 0.65?    │     ▼
                   └─────────────────────────┘  ┌──────────────────────┐
                                │               │ Very Short Message?  │───Yes───▶ CLARIFY
                                │               └──────────────────────┘
                                │                         │ No
                                │                         ▼
                    Yes ────────┤              ┌──────────────────────┐
                                │              │ LLM Enabled?         │
                                ▼              └──────────────────────┘
                   ANSWER (HIGH_CONF_INTENT)              │
                                                   Yes ───┤
                                                          ▼
                                               FALLBACK_LLM (LOW_INTENT_CONF)
```

## Reason Codes

Each decision includes a reason code explaining why that decision was made:

| Code | Description |
|------|-------------|
| `HIGH_CONF_INTENT` | High confidence intent match from rule-based patterns |
| `HIGH_CONF_RETRIEVAL` | High quality retrieval results support the answer |
| `LOW_INTENT_CONF` | Intent detection confidence too low for reliable answer |
| `LOW_RETRIEVAL_SCORE` | Retrieval results not confident enough |
| `AMBIGUOUS_MULTI_INTENT` | Message appears to contain multiple intents |
| `SAFETY_URGENT` | Safety flags detected requiring urgent routing |
| `OUT_OF_SCOPE` | Request is outside scope of ILAS services |
| `POLICY_VIOLATION` | Message triggered policy filter |
| `NO_RESULTS` | No retrieval results found |
| `LARGE_SCORE_GAP` | Large score gap between top results indicates clear match |
| `BORDERLINE_CONF` | Borderline confidence - answer may benefit from enhancement |
| `GREETING` | Simple greeting detected |
| `LLM_DISABLED` | LLM fallback unavailable - using clarification |

## Confidence Thresholds

Default thresholds (configurable in `ilas_site_assistant.settings`):

### Intent Confidence
```yaml
intent_high_conf: 0.85     # High confidence = answer directly
intent_medium_conf: 0.65   # Medium confidence = answer with lower certainty
intent_low_conf: 0.40      # Below this = consider fallback
```

### Retrieval Confidence
```yaml
retrieval_high_score: 0.75   # High retrieval = confident answer
retrieval_medium_score: 0.50 # Medium retrieval = may need enhancement
retrieval_low_score: 0.30    # Below this = low confidence in results
```

### Score Gap Thresholds
```yaml
retrieval_score_gap_high: 0.25  # Large gap = clear best result
retrieval_score_gap_low: 0.10   # Small gap = ambiguous results
```

### Combined Confidence
```yaml
combined_high_conf: 0.80         # High combined = confident answer
combined_fallback_threshold: 0.50 # Below this = consider LLM fallback
```

## Intent Confidence Calculation

Intent confidence is calculated based on:

1. **Base confidence** by intent source:
   - Unknown intent: 0.15
   - LLM-classified: 0.55
   - Rule-based: 0.70

2. **Message characteristics** (adjustments):
   - Very short (<3 words): -0.15
   - Medium length (3-15 words): +0.15
   - Very long (>20 words): -0.05

3. **Extraction quality** (bonuses):
   - Multiple keywords found: +0.05
   - Phrase matches found: +0.10
   - Synonyms normalized: +0.05

Maximum confidence: 1.0

## Retrieval Confidence Calculation

Retrieval confidence is based on:

1. **Top score** from search results (normalized to 0-1)
2. **Score gap** between top and second result
3. **Result count** (more results = more confidence)

For results without explicit scores:
- 3+ results: 0.70
- 1-2 results: 0.50
- No results: 0.00

## Combined Confidence

When both intent and retrieval are evaluated:

```
combined_conf = (intent_conf * 0.55) + (retrieval_conf * 0.45)
```

Intent is weighted slightly higher since it reflects understanding of user request.

## Safety Overrides

The following safety flags trigger hard routing regardless of confidence:
- `dv_indicator` - Domestic violence indicators
- `eviction_imminent` - Imminent eviction
- `crisis_emergency` - Crisis or emergency situation
- `identity_theft` - Identity theft reported
- `deadline_pressure` - Urgent legal deadline

## Metrics

The gate tracks these metrics for evaluation:

| Metric | Description |
|--------|-------------|
| `answer_rate` | Percentage of queries answered with built-in logic |
| `clarify_rate` | Percentage of queries requesting clarification |
| `fallback_rate` | Percentage of queries routed to LLM |
| `hard_route_rate` | Percentage of safety/policy overrides |
| `misroute_rate` | Confident answers that were wrong |
| `bad_answer_rate` | Low-grounding answers that passed |
| `avg_confidence` | Average confidence across all decisions |

## Tuning Thresholds

### Reducing Unnecessary LLM Fallbacks
If fallback rate is too high:
1. Lower `intent_high_conf` to 0.75-0.80
2. Increase `combined_fallback_threshold` to 0.55-0.60
3. Add more patterns to IntentRouter

### Reducing Wrong Confident Answers
If misroute rate is too high:
1. Raise `intent_high_conf` to 0.90+
2. Lower `combined_high_conf` to 0.75
3. Enable more clarification triggers

### Increasing Clarification
If clarification rate is too low and accuracy is poor:
1. Increase `ambiguous_message_words` threshold
2. Raise `intent_medium_conf` threshold
3. Lower `retrieval_score_gap_low`

## Configuration

Thresholds are configured in `config/install/ilas_site_assistant.settings.yml`:

```yaml
fallback_gate:
  thresholds:
    intent_high_conf: 0.85
    intent_medium_conf: 0.65
    retrieval_high_score: 0.75
    retrieval_score_gap_high: 0.25
    combined_high_conf: 0.80
    combined_fallback_threshold: 0.50
```

## Testing

Run the evaluation harness to measure gate performance:

```bash
cd scripts/chatbot-eval
php run-eval.php --fixture=../../chatbot-golden-dataset.csv --verbose
```

Test suites available:
- `tests/fixtures/safety-suite.csv` - High-risk safety scenarios
- `tests/fixtures/confusable-intents-suite.csv` - Ambiguous queries

## API Usage

The FallbackGate service can be used directly:

```php
$gate = \Drupal::service('ilas_site_assistant.fallback_gate');

$decision = $gate->evaluate(
  $intent,            // From IntentRouter
  $retrieval_results, // From FAQ/resource search
  $routing_override_intent, // From PreRoutingDecisionEngine
  ['message' => $user_message]
);

// $decision contains:
// - 'decision': ANSWER|CLARIFY|FALLBACK_LLM|HARD_ROUTE
// - 'reason_code': Why this decision was made
// - 'confidence': 0.0-1.0 score
// - 'details': Debug information
```

## Debug Output

When debug mode is enabled, the API response includes gate info:

```json
{
  "_debug": {
    "gate_decision": "answer",
    "gate_reason_code": "HIGH_CONF_INTENT",
    "gate_confidence": 0.87,
    "intent_confidence": 0.85,
    "retrieval_confidence": 0.72
  }
}
```
