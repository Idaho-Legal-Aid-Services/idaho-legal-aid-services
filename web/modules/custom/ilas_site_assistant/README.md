# ILAS Site Assistant

A site-scoped chatbot assistant for Idaho Legal Aid Services that helps users find resources, FAQs, and navigate to appropriate services **without providing legal advice**.

## Features

- **FAQ Search**: Searches and displays relevant FAQ content from paragraph-based FAQ sections
- **Resource Discovery**: Finds forms, guides, and resources by topic
- **Service Area Navigation**: Routes users to correct service area pages
- **Policy Enforcement**: Automatically detects and refuses legal advice requests and PII collection
- **Privacy-First Analytics**: Logs only aggregated, non-PII event metadata
- **Accessible UI**: WCAG 2.1 compliant with keyboard navigation, focus management, and ARIA labels
- **LLM Enhancement** (Optional): Uses Google Gemini/Vertex AI to enhance responses and improve intent classification

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

The assistant can optionally use Google Gemini or Vertex AI to enhance responses. This is **disabled by default** and requires configuration.

### Features

When enabled, the LLM layer provides:

- **Response Summarization**: Condenses FAQ answers and resource lists into concise, conversational responses
- **Intent Classification**: Improves detection of ambiguous queries that rule-based routing misses
- **Greeting Enhancement**: Optionally generates personalized welcome messages

### Safety Constraints

The LLM is configured with strict system prompts that enforce:

- No legal advice, opinions, or case predictions
- No statute citations or legal interpretations
- No PII collection or storage
- Links only to idaholegalaid.org pages
- Automatic escalation for uncertain queries

### Provider Options

#### Option 1: Gemini API (Recommended for Nonprofits)

Uses the free tier of Google Gemini API, available through Google for Nonprofits:

1. Sign up at [Google for Nonprofits](https://www.google.com/nonprofits/)
2. Enable the Gemini API in Google AI Studio
3. Generate an API key
4. Configure in Admin > Config > ILAS > Site Assistant Settings:
   - Provider: `Gemini API`
   - API Key: `your-api-key`
   - Model: `gemini-1.5-flash` (recommended)

**Cost**: Free tier includes 60 queries/minute, 1M tokens/month. Generally sufficient for most site traffic.

#### Option 2: Vertex AI

For higher volume or enterprise needs:

1. Enable Vertex AI API in Google Cloud Console
2. Create a service account with Vertex AI User role
3. Download the service account JSON key
4. Configure in settings:
   - Provider: `Vertex AI`
   - Project ID: `your-gcp-project-id`
   - Location: `us-central1` (or nearest region)
   - Service Account JSON: Paste the JSON key contents

**Cost**: ~$0.075/1M input tokens, ~$0.30/1M output tokens for gemini-1.5-flash

### Configuration Options

| Setting | Default | Description |
|---------|---------|-------------|
| `enabled` | `false` | Master toggle for LLM enhancement |
| `provider` | `gemini_api` | `gemini_api` or `vertex_ai` |
| `model` | `gemini-1.5-flash` | Model to use (flash is faster/cheaper) |
| `max_tokens` | `150` | Maximum response length |
| `temperature` | `0.3` | Lower = more focused, higher = more creative |
| `enhance_faq` | `true` | Summarize FAQ search results |
| `enhance_resources` | `true` | Summarize resource search results |
| `enhance_greetings` | `false` | Generate personalized greetings |
| `fallback_on_error` | `true` | Use rule-based response if LLM fails |

### Response Format

When LLM enhancement is active, responses include:

```json
{
  "type": "faq",
  "message": "I found some FAQs that might help:",
  "results": [...],
  "llm_enhanced": true,
  "llm_summary": "Based on your question about eviction notices, you typically have 3 days to respond..."
}
```

### Testing LLM Integration

After configuration:

1. Enable LLM in admin settings
2. Visit `/assistant`
3. Try queries like:
   - "What happens if I get evicted?" → Should get summarized FAQ response
   - "Help me find housing forms" → Should get summarized resource list
4. Check logs: `drush watchdog:show --filter=ilas_site_assistant`

### Troubleshooting LLM

**LLM not working:**
1. Check that `enabled` is TRUE in settings
2. Verify API key/credentials are correct
3. Check watchdog logs for API errors
4. Ensure `fallback_on_error` is TRUE to see rule-based responses

**Responses too long/short:**
- Adjust `max_tokens` setting (150 is good for concise answers)

**Responses too creative/off-topic:**
- Lower the `temperature` setting (0.1-0.3 recommended)

**Rate limiting errors:**
- Gemini free tier: 60 queries/minute
- Consider caching responses or upgrading to paid tier

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

### DataLayer Integration

Events push to `window.dataLayer` for GA4:
```javascript
window.dataLayer.push({
  event: 'ilas_assistant_chat_open',
  event_category: 'Site Assistant',
  event_action: 'chat_open',
  event_label: ''
});
```

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
  "message": "I need help with eviction",
  "context": {
    "history": []
  }
}
```

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

### Post-deployment

```bash
# On Pantheon (via Terminus or Dashboard)
terminus drush <site>.<env> -- updb -y
terminus drush <site>.<env> -- cim -y
terminus drush <site>.<env> -- cr
```

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

Adjust keywords in configuration:
- Admin > Config > ILAS > Site Assistant Settings
- Modify `policy_keywords.legal_advice` array

## Security Considerations

- All API endpoints require `access content` permission
- POST endpoint requires CSRF token (handled by Drupal core)
- User input is sanitized before processing
- PII detection prevents accidental data exposure
- No chat transcripts are stored
- "No answer" queries are sanitized and truncated before storage

## License

Proprietary - Idaho Legal Aid Services
