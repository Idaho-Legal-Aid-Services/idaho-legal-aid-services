# Aila Conversation Kernel — Governance SOP

## Weekly Review Cadence

**When:** Every Monday (or first business day of the week).

**Who:** Site assistant maintainer(s) — Content Manager plus a developer for
lexicon changes.

**Where:** The gap review queue in Drupal:
`/admin/reports/ilas-assistant/gaps` (Reports → Gap Review Queue). Every item
is a deduplicated, PII-redacted user query with a **Trigger** column that says
why it is there:

| Trigger | Meaning | Usual fix |
|---------|---------|-----------|
| No-match fallback | True dead end (`no_match_fallback`) | FAQ/content, or out-of-scope |
| Generic clarify (unknown intent) | The assistant answered "could you describe your legal issue…" to a real question | Typo dictionary, topic alias, or pack synonym |
| Clarify loop | The user was asked to clarify repeatedly | Pack entry or alias |
| Grounding refusal | An answerable type had nothing to cite | Content (FAQ item / resource) |
| Not-helpful feedback | The user pressed thumbs-down on that answer | Read the transcript, then any of the above |
| Flagged by reviewer | A staff member flagged a transcript turn | As decided by the reviewer |

**What:**

1. **Queue tab** — work every item with a Next action. Use *Start review* to
   assign yourself, then resolve with a resolution outcome:
   - *FAQ created* / *Content updated*: reference the node or paragraph.
   - *Search tuned*: reference the lexicon change (see step 3).
   - *Expected out of scope* / *False positive* / *Test or eval traffic*: add
     a short suppression note.
2. **Clarify misses tab** (`/gaps/clarify`) — sorted by open occurrences.
   These are the "the bot did not understand me" cases. For each:
   - Misspelling → add it under its canonical word in
     `config/routing/typos.yml`, plus a case in
     `TypoCorrectorTest::typoCorrectionProvider`.
   - Wording the lexicon lacks → add a sub-topic alias or phrase in
     `config/routing/topic_map.yml` (and `pack_intent:` when the pack key
     differs), or a synonym on the pack entry in
     `config/intents/top_intents.yml`. Add a
     `TopicRouterTest::resolveTopicKeysProvider` or
     `IntentRouterTopIntentRequestTest::subTopicCarryThroughProvider` case.
   - Genuinely new topic → new pack entry (see *Intent Pack Update Process*)
     and, if content is missing, a stub in `config/kb-stubs/` for attorney
     authorship.
   - Out of scope → resolve as *Expected out of scope*.
3. **Top misses (30d) tab** (`/gaps/top-misses`) — the highest-volume open
   items regardless of trigger. Anything here with 3+ occurrences is this
   week's priority.
4. **Promote to evals.** After resolving items, export reviewed/resolved gaps
   as promptfoo candidates and promote the good ones by hand into
   `promptfoo-evals/tests/` (never write into `tests/` directly):
   `drush ilas:export-reviewed-gaps-to-promptfoo --days=90 --write`
   (output: `promptfoo-evals/output/reviewed-gaps.candidate.yaml`). Resolved
   items purge 30 days after resolution, so export before then.
5. **Langfuse sanity check** (optional): `dead_end_rate`, `clarify_rate`
   (healthy 5-15%), `history_fallback_rate` (10-20%).
6. **Fixture regression:** run
   `vendor/bin/phpunit --filter=ConversationIntentFixtureUnitTest`.

Deploy lexicon changes like any code change (`drush cr` afterwards: routing
YAML is cached for one hour).

## Intent Pack Update Process

1. Edit `config/intents/top_intents.yml`
2. Run unit tests: `vendor/bin/phpunit --group=ilas_site_assistant --no-configuration --bootstrap=vendor/autoload.php web/modules/custom/ilas_site_assistant/tests/src/Unit/`
3. If adding a new intent:
   - Add to `TopIntentsPackTest` if it represents a significant category
   - Add a conversation intent fixture turn if it covers new classifier,
     resolver, or chip behavior
4. Clear cache on deployment: `drush cr` (TopIntentsPack caches for 1 hour)

## Conversation Intent Fixture Maintenance

- **When to add a fixture:** When a new classifier, resolver, or chip pattern is
  discovered in production that is not covered by existing fixtures.
- **When to update:** When intent routing logic changes in a way that affects
  expected turn types or intent assignments.
- **File:** `tests/goldens/conversation-intent-fixtures.yml`
- **Test:** `tests/src/Unit/ConversationIntentFixtureUnitTest.php`
- **Boundary:** This is unit-level fixture coverage only. API-level golden
  conversation quality belongs in
  `tests/src/Functional/AssistantMessageRuntimeBehaviorFunctionalTest.php` and
  Promptfoo multi-turn suites.

## Threshold Tuning

Thresholds are configured in `ilas_site_assistant.settings`:

| Threshold | Default | Purpose |
|-----------|---------|---------|
| `intent_high_conf` | 0.85 | Above this: answer confidently |
| `intent_medium_conf` | 0.65 | Above this: answer with medium confidence |
| `intent_low_conf` | 0.40 | Above this: still route direct intents |
| `retrieval_high_score` | 0.75 | Above this: high retrieval confidence |
| `combined_high_conf` | 0.80 | Above this: combined answer confidence |
| `combined_fallback_threshold` | 0.50 | Below this: fallback to LLM |

Adjust thresholds based on weekly review metrics. If `clarify_rate` exceeds
15%, consider lowering `intent_medium_conf`. If `dead_end_rate` exceeds 0%,
check fallback tree levels and intent pack coverage.

## Metrics to Track

| Metric | Definition | Target |
|--------|-----------|--------|
| `dead_end_rate` | Responses with no suggestions/links / total responses | 0% |
| `fallback_L3_rate` | Level 3 fallbacks / total fallbacks | Should trend down |
| `clarify_rate` | CLARIFY gate decisions / total decisions | 5-15% |
| `history_fallback_rate` | History fallback source / total intents | 10-20% |
| `turn_type` distribution | NEW vs FOLLOW_UP vs INVENTORY vs RESET | Monitor |
| Top 10 intents | By volume | Validate pack coverage |
| Unresolved fallback intents | Queries hitting Level 3+ repeatedly | Triage weekly |

## Fallback Level Definitions

| Level | Trigger | Response |
|-------|---------|----------|
| 1 | First failure, known intent | Clarifier chips + contact links |
| 2 | Repeated same-area failure | Parent service area page + sub-topic chips |
| 3 | 2+ consecutive failures | Prominent contact info + nearby intents |
| 4 | 3+ consecutive failures | Direct human connection message |

**Invariant:** Every level includes >= 2 actionable links.
