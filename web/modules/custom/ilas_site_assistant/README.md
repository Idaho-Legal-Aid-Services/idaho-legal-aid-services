# ILAS Site Assistant

A site-scoped chatbot assistant for Idaho Legal Aid Services that helps users find resources, FAQs, and navigate to appropriate services **without providing legal advice**.

## Features

- **FAQ Search**: Searches and displays relevant FAQ content from paragraph-based FAQ sections
- **Resource Discovery**: Finds forms, guides, and resources by topic
- **Service Area Navigation**: Routes users to correct service area pages
- **Policy Enforcement**: Automatically detects and refuses legal advice requests and PII collection
- **Privacy-First Analytics**: Logs only aggregated, non-PII event metadata
- **Accessible UI**: WCAG 2.1 compliant with keyboard navigation, focus management, and ARIA labels
- **Vector Retrieval Plumbing** (Feature-gated): Pinecone embeddings are configured for Voyage `voyage-law-2`; live vector retrieval remains disabled until the indexes are rebuilt and backfilled at the new dimension
- **Request-Time LLM Classification** (Optional): Uses Cohere for bounded ambiguous-intent classification; greeting variation remains retired

## Hard Constraints (Non-Negotiable)

1. **No legal advice** - Never cites statutes, predicts outcomes, or provides legal opinions
2. **No PII collection** - Does not store or request identifying information
3. **Site-scope only** - No external web search; links only to idaholegalaid.org
4. **Escalation** - Directs uncertain queries to Hotline/Apply/Feedback

## Requirements

- Drupal 10.x or 11.x
- PHP 8.1+
- Required modules:
  - drupal:node
  - drupal:taxonomy
  - drupal:user
  - drupal:views
  - drupal:search_api
  - drupal:paragraphs

## Installation

### Via DDEV (Local Development)

```bash
# Enable the module
ddev drush en ilas_site_assistant -y

# Clear caches
ddev drush cr

# Verify installation
ddev drush pm:list --filter=ilas_site_assistant
```

### Via Drush (Production/Pantheon)

```bash
# Enable the module
drush en ilas_site_assistant -y

# Clear caches
drush cr
```

## Configuration

### Admin Settings

Navigate to **Admin > Configuration > ILAS > Site Assistant Settings**
(`/admin/config/ilas/site-assistant`)

Settings available:
- **Disclaimer text**: Shown to users at chat start
- **Welcome message**: Initial greeting
- **Enable features**: Toggle FAQ answers, resource search
- **Global widget**: Enable/disable floating chat button
- **Excluded paths**: Paths where widget shouldn't appear
- **Analytics logging**: Enable aggregated stats collection
- **Canonical URLs**: Configure destination URLs

### Menu Configuration

Add menu links for easy access:

```
Admin Menu:
- Configuration > ILAS > Site Assistant Settings
- Reports > ILAS Site Assistant
```

## LLM Enhancement (Optional)

Current runtime note: the assistant request path is deterministic-first.
Request-time LLM use is intentionally narrow: Cohere may classify ambiguous
`unknown` intents at request time, then control returns to the deterministic
intent pipeline. Pinecone embeddings and reranking stay on the Voyage side of
the stack, while residual Gemini config is retained only where Search API AI
still needs repo-proven vector/chat wiring.

### Features

When enabled, the LLM layer provides:

- **Intent Classification**: Improves detection of ambiguous queries that the rule-based router leaves as `unknown`

### Safety Constraints

The LLM is configured with strict system prompts that enforce:

- No legal advice, opinions, or case predictions
- No statute citations or legal interpretations
- No PII collection or storage
- Links only to idaholegalaid.org pages
- Automatic escalation for uncertain queries

### Runtime Controls

Use runtime-only inputs instead of Drupal-stored provider settings:

1. Provide `ILAS_COHERE_API_KEY` as a Pantheon runtime secret or local DDEV
   environment variable.
2. Enable request-time classification with `ILAS_LLM_ENABLED=1` only in the
   environments you intend to test.
3. Keep the admin form secretless. It exposes only non-secret knobs such as
   `enabled`, `max_tokens`, `temperature`, cache TTL, retry count, and safety
   threshold.
4. Keep `ILAS_VOYAGE_API_KEY` / `ILAS_VOYAGE_ENABLED` for embeddings and
   reranking.
5. Keep `ILAS_GEMINI_API_KEY` only where the Search API AI vector/chat path
   still proves a Gemini dependency.

### Configuration Options

| Setting | Default | Description |
|---------|---------|-------------|
| `enabled` | `false` | Master toggle for LLM enhancement |
| `provider` | code-owned | Cohere is the request-time provider; not editable in Drupal |
| `model` | code-owned | Request-time model is owned by the transport, not the admin form |
| `max_tokens` | `150` | Maximum response length |
| `temperature` | `0.3` | Lower = more focused, higher = more creative |
| `fallback_on_error` | `true` | Use rule-based response if LLM fails |

### Testing LLM Integration

After configuration:

1. Provide `ILAS_COHERE_API_KEY`
2. Set `ILAS_LLM_ENABLED=1`
3. Visit `/assistant`
3. Try queries like:
   - "faq" or another ambiguous short ask that the router currently leaves as `unknown`
   - "show me your guides" for an ambiguous browse request
4. Check logs: `drush watchdog:show --filter=ilas_site_assistant`

### Troubleshooting LLM

**LLM not working:**
1. Check that `ILAS_LLM_ENABLED=1`
2. Verify `ILAS_COHERE_API_KEY` is present at runtime
3. Check watchdog logs for API errors
4. Ensure `fallback_on_error` is TRUE to see rule-based responses

**Responses too long/short:**
- Adjust `max_tokens` setting (150 is good for concise answers)

**Responses too creative/off-topic:**
- Lower the `temperature` setting (0.1-0.3 recommended)

**Rate limiting errors:**
- Keep cache enabled for repeated ambiguous-intent lookups
- Review request-time retry and timeout settings before widening rollout

## Usage

### Dedicated Page

Access the full-page assistant at: `/assistant`

### Floating Widget

When enabled globally, a floating "Help" button appears on all pages (except excluded paths). The button:
- Positioned at bottom-right, above back-to-top button
- Opens a chat panel
- Accessible via keyboard (Tab, Enter, Escape)

### Canonical URLs

The assistant directs users to these pages:

| Action | URL |
|--------|-----|
| Apply for Help | `/apply-for-help` |
| Legal Advice Line | `/Legal-Advice-Line` |
| Offices | `/contact/offices` |
| Donate | `/donate` |
| Feedback | `/get-involved/feedback` |
| Resources | `/what-we-do/resources` |
| Forms | `/forms` |
| Guides | `/guides` |
| FAQ | `/faq` |
| Services | `/services` |
| Housing | `/legal-help/housing` |
| Family | `/legal-help/family` |
| Seniors | `/legal-help/seniors` |
| Health | `/legal-help/health` |
| Consumer | `/legal-help/consumer` |
| Civil Rights | `/legal-help/civil-rights` |

## Analytics

### Events Tracked

| Event | Description |
|-------|-------------|
| `chat_open` | User opened the assistant |
| `topic_selected` | User selected a topic |
| `resource_click` | User clicked a resource link |
| `apply_click` | User clicked Apply for Help |
| `hotline_click` | User clicked Hotline link |
| `donate_click` | User clicked Donate |
| `no_answer` | Query returned no results |
| `policy_violation` | Detected legal advice/PII request |

### Browser Telemetry Boundary

Assistant-originated events do not push to `window.dataLayer` or GA4.
The widget keeps telemetry inside Drupal-owned analytics by POSTing normalized
`event_type` / `event_value` pairs to `/assistant/api/track`, and it emits
minimized `ilas:assistant:action` / `ilas:assistant:error` browser events for
the local observability helper.

### Admin Reports

View analytics at: `/admin/reports/ilas-assistant`

Reports show:
- Total chats opened (7/30 days)
- Top topics selected
- Top clicked destinations
- Content gaps (no-answer queries)

## API Endpoints

### POST `/assistant/api/message`

Send a chat message.

**Request:**
```json
{
  "message": "I need help with eviction"
}
```

`context` is optional. When provided, the only supported key is
`context.quickAction` with one of: `apply`, `hotline`, `forms`, `guides`,
`faq`, or `topics`.

**Response:**
```json
{
  "type": "service_area",
  "message": "Here's our Housing legal help page:",
  "url": "/legal-help/housing"
}
```

### GET `/assistant/api/suggest?q=evict`

Get autocomplete suggestions.

May return `429 Too Many Requests` with a `Retry-After` header under the
endpoint's per-IP abuse controls. Throttled responses keep the `suggestions`
key and include `error`, `type = "rate_limit"`, and `request_id`.

**Response:**
```json
{
  "suggestions": [
    {"type": "topic", "label": "Eviction", "id": 123},
    {"type": "faq", "label": "What do I do if I get an eviction notice?", "id": "faq_45"}
  ]
}
```

### GET `/assistant/api/faq?q=eviction`

Search FAQs.

May return `429 Too Many Requests` with a `Retry-After` header under the
endpoint's per-IP abuse controls. Throttled responses preserve the active body
shape (`results` / `count`, `categories`, or `faq: null`) and include `error`,
`type = "rate_limit"`, and `request_id`.

Successful search results expose only `id`, `question`, `answer`, and `url`.
Browse mode (`GET /assistant/api/faq`) returns `categories` items with only
`name` and `count`.

**Response:**
```json
{
  "results": [
    {
      "id": "faq_45",
      "question": "What do I do if I get an eviction notice?",
      "answer": "You have options...",
      "url": "/faq"
    }
  ],
  "count": 1
}
```

## Development

### Local Testing

```bash
# Start DDEV
ddev start

# Enable module
ddev drush en ilas_site_assistant -y

# Clear caches
ddev drush cr

# Watch logs
ddev drush watchdog:show --filter=ilas_site_assistant
```

### Cache Management

The module caches FAQ and topic data for performance:

```bash
# Clear all caches
ddev drush cr

# Or clear specific cache bins
ddev drush cache:rebuild
```

### Testing Intent Detection

Visit `/assistant` and try:
- "I need a form for eviction"
- "How do I apply for help?"
- "What are the office hours?" → offices
- "Can I sue my landlord?" → policy violation (escalation)

### CSS Customization

Override CSS custom properties in your theme:

```css
:root {
  --assistant-primary: #1263a0;
  --assistant-primary-dark: #0e4f80;
  --assistant-radius: 12px;
}
```

## Deployment to Pantheon

### Pre-deployment Checklist

1. Commit module to repository
2. Export configuration: `ddev drush cex -y`
3. Commit config changes
4. Push to Pantheon

### Post-deployment (standardized)

Use the repo-managed deploy wrapper so update hooks are never skipped:

```bash
# Dev
bash scripts/deploy/pantheon-deploy.sh --env dev

# Test
bash scripts/deploy/pantheon-deploy.sh --env test

# Live (backup + confirmation required by default)
bash scripts/deploy/pantheon-deploy.sh --env live
```

Optional flags:
- `--dry-run` prints the exact commands without executing remote operations.
- `--site <machine-name>` overrides the default site machine name.
- `--yes-live` bypasses live confirmation prompt.
- `--skip-live-backup` skips automatic live backup (not recommended).

This wrapper runs Drush deploy on Pantheon (`updatedb -> config:import -> cache:rebuild -> deploy:hook`) and then checks `updatedb:status` again.  
These deploy commands affect **remote database/runtime state** only; they do not modify git commits or local tracked files.

### Environment-specific Settings

For production, consider:
- Setting `enable_logging: true` for analytics
- Reviewing `excluded_paths` for admin areas
- Verifying canonical URLs match production paths

## Troubleshooting

### Widget Not Appearing

1. Check if `enable_global_widget` is TRUE
2. Check if current path is in `excluded_paths`
3. Clear caches: `drush cr`
4. Check browser console for JS errors

### FAQ Not Finding Content

1. Verify FAQ paragraphs exist with `field_faq_question` and `field_faq_answer`
2. Clear FAQ cache: The cache auto-expires after 1 hour
3. Check paragraph types are `faq_item` within `faq_smart_section`

### Resources Not Finding Content

1. Verify `resource` nodes have `field_topics` populated
2. Check topic → service area mapping via `field_service_areas`
3. Clear resource cache

### Policy Filter Too Aggressive

Fallback policy keywords are code-owned for governance review:
- Update the governed keyword lists in `src/Service/PolicyFilter.php`
- Add or adjust PHPUnit coverage that documents the intended change
- Deploy the code change instead of editing runtime configuration

## Security Considerations

- All API endpoints require `access content` permission
- POST endpoint requires CSRF token (handled by Drupal core)
- User input is sanitized before processing
- PII detection prevents accidental data exposure
- No chat transcripts are stored
- "No answer" queries are sanitized and truncated before storage

## License

Proprietary - Idaho Legal Aid Services
