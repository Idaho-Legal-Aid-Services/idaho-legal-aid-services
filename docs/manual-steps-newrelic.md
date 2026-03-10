# Manual Steps: New Relic

This repository uses New Relic for four things:

1. Pantheon APM visibility
2. Browser monitoring and Core Web Vitals
3. Deploy change tracking
4. Alerts, workflows, SLOs, and synthetics

This repo does not install a New Relic PHP agent on Pantheon. Pantheon must
attach the site to your New Relic account.

## What This Repo Actually Reads

Drupal/Pantheon runtime only consumes these New Relic secrets:

- `NEW_RELIC_BROWSER_SNIPPET`
- `NEW_RELIC_API_KEY`
- `NEW_RELIC_ENTITY_GUID_APM`
- `NEW_RELIC_ENTITY_GUID_BROWSER`

These values are read in [settings.php](/home/evancurry/idaho-legal-aid-services/web/sites/default/settings.php), injected into HTML by [html.html.twig](/home/evancurry/idaho-legal-aid-services/web/themes/custom/b5subtheme/templates/page/html.html.twig), and used by [new-relic-change-tracking.php](/home/evancurry/idaho-legal-aid-services/scripts/quicksilver/new-relic-change-tracking.php).

`NEW_RELIC_ACCOUNT_ID` and `NEW_RELIC_LICENSE_KEY` are still needed, but only
for Pantheon support and optional local DDEV work. Drupal does not read them at
runtime on Pantheon.

## Step 1: Gather New Relic Values

Before touching Pantheon, gather these values from New Relic.

### Required for Pantheon support

- `NEW_RELIC_ACCOUNT_ID`
  - Not secret.
  - Find it in New Relic account settings or the account switcher.
- `NEW_RELIC_LICENSE_KEY`
  - Secret.
  - In New Relic, open `API keys`.
  - Create or copy a `License key` for the target account.

### Required for repo-driven change tracking

- `NEW_RELIC_API_KEY`
  - Secret.
  - In New Relic, open `API keys`.
  - Create a `User key`.
  - Name it something like `ilas-pantheon-change-tracking`.
  - This key is used by Pantheon Quicksilver to call NerdGraph.

### Required for browser monitoring

- `NEW_RELIC_BROWSER_SNIPPET`
  - Secret-ish runtime payload.
  - This is the full copy/paste Browser script from New Relic, including the
    `<script>...</script>` wrapper.
- `NEW_RELIC_ENTITY_GUID_BROWSER`
  - Not secret.
  - Copy this from the Browser entity after the Browser app exists.

### Required after Pantheon APM is attached

- `NEW_RELIC_ENTITY_GUID_APM`
  - Not secret.
  - Copy this from the APM entity that Pantheon support links to your site.

Do not put the license key, user key, or browser snippet into committed docs.

## Step 2: Open Pantheon BYO New Relic Request

Pantheon needs to attach your site to your New Relic account.

Use these repo values:

- site name: `idaho-legal-aid-services`
- site ID: `0bbb0799-c3de-441d-8d26-5caed25eba3f`

Tell Pantheon support:

- you want the site attached to your own New Relic account
- the hookup should cover `dev`, `test`, and `live`
- you are not installing a PHP agent from the repo

Give Pantheon support:

- site name
- site ID
- New Relic account ID
- New Relic license key

Ask Pantheon support to confirm:

- the APM entity name for each Pantheon environment
- whether they created a single APM entity or separate entities
- when the hookup is complete and ready for verification

## Step 3: Create the Browser App in New Relic

1. In New Relic, open `Browser` or search for `Browser monitoring`.
2. Create a new Browser app.
3. Choose the copy/paste JavaScript snippet install path.
4. Name the app something explicit, for example:
   - `idaho-legal-aid-services browser`
5. If New Relic asks for domains, add:
   - the live public site URL
   - Pantheon domains you care about
   - any Multidev domains you explicitly want monitored

Do not choose an npm or package-manager install path. This repo expects the
full snippet to be stored as `NEW_RELIC_BROWSER_SNIPPET`.

## Step 4: Turn On Privacy Controls Before Replay

Before copying the Browser snippet, configure Browser privacy settings.

Enable or tighten:

- form input masking
- text masking/blocking for session replay
- media blocking where available
- obfuscation for emails
- obfuscation for bearer tokens
- obfuscation for cookies and session IDs
- obfuscation for user-submitted form content

If New Relic allows selector-level replay masking or blocking, use these AILA
selectors from this repo:

- `#assistant-input`
- `.assistant-input`
- `#assistant-chat`
- `.assistant-chat`
- `#assistant-panel`
- `#ilas-assistant-widget`
- `#ilas-assistant-page`
- `.assistant-container`

Goal: no assistant prompt text, no chat transcript text, and no user-entered
form content should be readable in replay.

## Step 5: Store the Browser Snippet in Pantheon

After privacy controls are configured:

1. Copy the entire Browser snippet from New Relic.
2. In Pantheon, open `Site Settings` -> `Secrets`.
3. Create this secret:
   - key: `NEW_RELIC_BROWSER_SNIPPET`
   - type: `runtime`
   - scope: `web`
   - value: paste the full snippet, including `<script>...</script>`

This repo injects that secret into the page `<head>`. Do not paste the snippet
into Twig, theme files, settings, or docs.

## Step 6: Store the Change-Tracking Values in Pantheon

After Pantheon APM is linked and the Browser app exists:

1. Copy the APM entity GUID from the New Relic APM entity.
2. Copy the Browser entity GUID from the Browser app entity.
3. In Pantheon `Site Settings` -> `Secrets`, create:
   - `NEW_RELIC_API_KEY`
     - type: `runtime`
     - scope: `web`
     - value: the New Relic User key
   - `NEW_RELIC_ENTITY_GUID_APM`
     - type: `runtime`
     - scope: `web`
     - value: the APM entity GUID
   - `NEW_RELIC_ENTITY_GUID_BROWSER`
     - type: `runtime`
     - scope: `web`
     - value: the Browser entity GUID

These are used by the Quicksilver deploy hook in
[pantheon.yml](/home/evancurry/idaho-legal-aid-services/pantheon.yml).

## Step 7: Deploy and Verify on Pantheon Dev

Deploy the observability branch to Pantheon `dev` before testing Browser or
change tracking. Without the deployed code:

- the Browser snippet will not render
- the Quicksilver deploy hook will not run

After deploy, wait a few minutes for Pantheon runtime secrets to propagate.

## Step 8: Verify Browser Monitoring

1. Open the Pantheon `dev` site in a browser.
2. View page source.
3. Confirm the New Relic snippet appears near the top of `<head>`.
4. In New Relic Browser, confirm the app begins receiving:
   - `PageView`
   - `AjaxRequest`
   - `JavaScriptError`
   - Core Web Vitals / timing data
5. Visit the AILA page and widget surfaces.
6. Confirm Browser monitoring sees activity without exposing raw assistant text
   in replay.

If the snippet does not appear in HTML, first check `NEW_RELIC_BROWSER_SNIPPET`
in Pantheon secrets before debugging Drupal.

## Step 9: Verify Pantheon APM

In New Relic APM:

1. Open the entity Pantheon support linked to this site.
2. Confirm web transactions are arriving from `dev`.
3. Verify that deploy/environment naming makes sense for Pantheon.

If Pantheon support said the hookup is complete but APM data is absent, go back
to Pantheon support. Drupal code cannot repair a missing Pantheon APM link.

## Step 10: Verify Deploy Change Tracking

After Browser and APM are both working:

1. Deploy again to Pantheon `dev`.
2. Check Pantheon workflow logs for Quicksilver output from
   `new-relic-change-tracking.php`.
3. In New Relic, open the APM entity.
4. Confirm a deployment marker/change-tracking event appears for the new deploy.
5. Repeat the same verification for the Browser entity if you are sending both
   entity GUIDs.

## Step 11: Add Alerts, Workflows, and Synthetics

Add these after telemetry is healthy.

### Workflows and destinations

Create one production-focused alert workflow first. Start with email or your
team's standard destination.

### Synthetics

Start small:

- one live homepage uptime monitor
- one anonymous critical path
- one assistant/API path only after normal Browser/APM data looks good

### Useful starter NRQL

- p95 and p99 latency by environment:
  `FROM Transaction SELECT percentile(duration, 95, 99) FACET environment SINCE 1 hour ago`
- error rate by environment:
  `FROM Transaction SELECT percentage(count(*), WHERE error IS true) FACET environment SINCE 1 hour ago`
- browser JavaScript errors:
  `FROM JavaScriptError SELECT count(*) FACET pageUrl SINCE 1 hour ago`
- Core Web Vitals:
  `FROM PageViewTiming SELECT percentile(largestContentfulPaint, 75), percentile(interactionToNextPaint, 75), percentile(cumulativeLayoutShift, 75) FACET pageUrl SINCE 1 day ago`
- assistant endpoint latency:
  `FROM AjaxRequest SELECT percentile(duration, 95) WHERE requestUrl LIKE '%/assistant/api/%' FACET requestUrl SINCE 1 hour ago`

## Step 12: Leave Local DDEV for Later

This repo includes optional local DDEV scaffolding for a New Relic PHP agent,
but it is not required for hosted setup.

Ignore it until Pantheon Browser, APM, and change tracking are working.

Only enable local DDEV New Relic if you specifically want local APM traces.

## Common Mistakes

- putting the Browser snippet in a committed file instead of Pantheon secrets
- using a License key where a User key is required
- expecting Drupal to read `NEW_RELIC_ACCOUNT_ID` or `NEW_RELIC_LICENSE_KEY`
- trying to install a PHP agent on Pantheon from Composer, PECL, or apt
- testing Browser monitoring before the observability branch is deployed
- enabling replay without blocking the assistant selectors listed above

## Completion Checklist

- Pantheon support confirmed the site is attached to your New Relic account
- `NEW_RELIC_BROWSER_SNIPPET` exists as a Pantheon runtime secret
- `NEW_RELIC_API_KEY` exists as a Pantheon runtime secret
- `NEW_RELIC_ENTITY_GUID_APM` exists as a Pantheon runtime secret
- `NEW_RELIC_ENTITY_GUID_BROWSER` exists as a Pantheon runtime secret
- Browser snippet renders on Pantheon `dev`
- Browser app shows page views, Ajax, JS errors, and Web Vitals
- Pantheon-linked APM entity shows `dev` transactions
- deploy change tracking appears after a new Pantheon deploy
- assistant prompts/chat text are not readable in replay
