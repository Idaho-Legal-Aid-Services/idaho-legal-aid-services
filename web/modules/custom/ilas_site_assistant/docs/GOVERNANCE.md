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
   - **Fast path, no deploy:** on the gap item's review page use *Add
     exemplar as topic alias* (routes that wording to a pack answer at once
     and can resolve the item as search-tuned), or add typo / alias lines
     under *Lexicon overrides* in Site Assistant settings. These live in
     config and are merged over the shipped lexicon; promote the keepers
     into the YAML files below with fixtures so CI covers them.
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
   Two triggers come from people rather than the router: **Not-helpful
   feedback** (a thumbs-down in the widget is tied to the exact answer, and
   the user's question enters the queue) and **Flagged by reviewer** (on any
   transcript in Reports → Conversation Logs, the *Flag for review* link next
   to a user turn creates an item; needs the *Flag assistant conversation
   turns* permission). Both show the conversation so you can read what was
   asked and what the assistant said.
3. **Top misses (30d) tab** (`/gaps/top-misses`) — the highest-volume open
   items regardless of trigger. Anything here with 3+ occurrences is this
   week's priority.
4. **Promote to evals.** Reviewed/resolved gaps are exported as promptfoo
   *candidate* cases automatically once a week (cron) into the private file
   system. Open the **Eval export** tab on the gap queue to download the
   YAML or run the export now, review each case for privacy and overfitting,
   and promote the good ones by hand into `promptfoo-evals/tests/` (nothing
   is ever written into `tests/` automatically). The drush equivalent is
   `drush ilas:export-reviewed-gaps-to-promptfoo --days=90 --write`
   (output: `promptfoo-evals/output/reviewed-gaps.candidate.yaml`). Resolved
   items purge 30 days after resolution, so promote within that window.
5. **Langfuse sanity check** (optional): `dead_end_rate`, `clarify_rate`
   (healthy 5-15%), `history_fallback_rate` (10-20%).
6. **Fixture regression:** run
   `vendor/bin/phpunit --filter=ConversationIntentFixtureUnitTest`.

Deploy lexicon changes like any code change (`drush cr` afterwards: routing
YAML is cached for one hour).

## Content authoring path (gap → stub → attorney → live)

Routing fixes make the assistant *understand* a question; only content lets it
*answer* one. When a gap item's real cause is missing content:

1. **Measure before you write.** Run `ddev drush ilas:kb-coverage`
   (`--gaps-only` for just the problems). For every `topic_map.yml` sub-topic
   it counts distinct published `faq_item` and `accordion_item` content whose
   heading (strong) or body (weak) mentions the sub-topic, whether a pack
   answer exists, and how many stubs are pending. Check **both** bundles: the
   site's legal content lives in `accordion_item`; the `faq_item` bundle holds
   organisational FAQs. A topic with zero FAQ items can still be well covered.
   Counts are of *distinct* content: every page exists in four languages and
   each language version owns its own copy of the page's accordion items, so
   raw paragraph counts are about four times higher.
2. **Flag the gap item** `Needs content update` or `Potential content gap` so
   the request is visible in the queue, and put the stub id in *Resolution
   reference* when you resolve it.
3. **Write a stub** in `config/kb-stubs/` with the question and a
   `TODO (attorney review)` answer, `review_status: unreviewed`. Do not draft
   the legal answer yourself.
4. **Reviewer authors and signs off** on each stub. The review instrument is
   the *ILAS FAQ Sign-off Sheet* artifact (one entry per drafted answer with
   the points to check, three decisions, and a free-text box for what must
   change); Claude transcribes the decisions into the YAML:

   ```yaml
   review_status: approved          # unreviewed | needs_changes | approved
   reviewed_by: "Reviewer name"
   reviewed_on: "2026-10-01"        # quoted YYYY-MM-DD
   ```

   A stub the reviewer sends back is marked `needs_changes` with a
   `review_notes` list saying what to change. Once the answer has been
   rewritten from the reviewer's own correction notes (typed into the
   sheet), it is marked `revised` — still blocked — until the reviewer
   re-approves it in the next round. The legal content of a revision comes
   from the reviewer's notes, never from Claude's own drafting.

5. **Import:** `ddev drush ilas:kb-import <file> --dry-run`, then with
   `--node-id=<nid>`. The command imports the stubs that carry a finished
   answer and a valid sign-off and **reports and skips** the rest
   (`KbStubValidator::partition()`), so approved answers ship while others
   are still under review. It also skips a stub whose `anchor_id` already
   exists on a `faq_item` paragraph, so re-running after later approvals does
   not duplicate the earlier batch. `drush ilas:kb-list` shows pending vs
   importable counts.
   `KbStubValidatorTest::testShippedStubSignOffsMatchRecordedDecisions` pins
   exactly which stubs are approved — update its list in the same change
   that records a sign-off.
6. Attach the created paragraphs to the host node in the node edit form
   (the importer does not attach them), then re-run `ilas:kb-coverage`.

**Reviewer of record (decided 2026-09-18):** Evan Curry signs off both the
pack `answer_text` sentences and the kb-stub FAQ answers. Sign-off is per
answer, never in bulk: a stub is marked `approved` only after the reviewer has
read that answer and said so, and `reviewed_by` / `reviewed_on` record who and
when. An answer the reviewer wants a second opinion on stays `unreviewed`
(blocked) until an attorney has looked at it. Rounds 1 and 2 were recorded on
2026-09-22: 11 stubs approved as written, 11 sent back with the reviewer's
correction notes (`docs/faq-signoff-round1-notes.md`), revised from those
notes and approved the same day; all 35 pack sentences approved. One point
was referred onward: the credit-card limitation period in
`faq_debt_statute_limitations` is worded as "4 or 5 years" and awaits an
attorney's view (see its `review_notes`).

**Pack answer text is content too.** `answer_text` in
`config/intents/top_intents.yml` is served verbatim and cites the pack's own
page (`source: curated_pack`) when retrieval finds nothing, so it must only
name subjects that page actually covers — confirm with `ilas:kb-coverage`
before naming a subject — and must never state the law itself. Its sign-off
is recorded in the file's `answer_text_review` block (`reviewed_by`,
`reviewed_on`, `digest`). The digest is
`TopIntentsPack::answerTextDigest()` over every intent's `answer_text`, and
`TopIntentsPackTest::testAnswerTextMatchesRecordedSignOff` fails when any
sentence changes without a new sign-off: after the reviewer approves the new
wording, set `reviewed_on` and paste the recomputed digest (the recipe is in
the YAML comment above the block).

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
