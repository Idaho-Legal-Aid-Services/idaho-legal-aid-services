# ILAS Site Assistant — Promptfoo Evaluation Harness

Evaluation scaffolding for the Idaho Legal Aid Services site assistant.
Uses [Promptfoo](https://promptfoo.dev) for multi-turn conversation evaluation
with simulated-user support.

## Status

**Live-wired.** The default provider is the custom JS provider at
`providers/ilas-live.js`, which handles Drupal CSRF tokens automatically.
Run `npm run eval:promptfoo:live` to evaluate against the production assistant.

## Directory layout

```
promptfoo-evals/
├── promptfooconfig.yaml          # Main config — providers, tests, options
├── package.json                  # js-yaml dependency for generator script
├── providers/
│   └── ilas-live.js              # Custom provider (CSRF + live endpoint + multi-turn)
├── prompts/
│   └── ilas-thread.yaml          # Chat prompt template (Nunjucks + YAML)
├── tests/
│   ├── live-50.sanitized.yaml    # 50 single-turn source questions
│   ├── live-10convos.yaml        # Generated: 10 convos x 5 turns (from generator)
│   └── simulated-user-smoke.yaml # Smoke test suite (5 tests)
├── scripts/
│   ├── adjudicate-failures.mjs   # Classifies failed eval cases for audit
│   ├── lint-javascript-assertions.mjs # Fails on multiline JS asserts without return
│   ├── generate-multiturn.js     # Converts 50 single-turn → 10 multi-turn convos
│   ├── run-promptfoo.sh          # Bash wrapper (Linux/macOS)
│   └── run-promptfoo.ps1         # PowerShell wrapper (Windows)
├── output/                       # Generated results (git-ignored)
└── README.md                     # This file
```

## Quick start

### Prerequisites

- Node.js >= 18 (for `npx`)
- No global install needed — scripts use `npx promptfoo@latest`

### Run evaluation against LIVE endpoint

From the **repo root**:

```bash
npm run eval:promptfoo:live
```

This sets `ILAS_ASSISTANT_URL` inline and runs the 5-test smoke suite against
the production assistant. The custom provider at `providers/ilas-live.js`
automatically fetches a Drupal CSRF token before the first request and retries
once on 403 (token expiry).

For deterministic, cache-safe reruns of the same scenarios, set a run salt:

```bash
ILAS_EVAL_RUN_ID=eval-remediate-1 npm run eval:promptfoo:live
```

When `ILAS_EVAL_RUN_ID` is set, conversation IDs are deterministic within the
run and isolated across runs, preventing cross-run cache bleed.

**Safety notes:**
- Keep the test set small (5 tests at concurrency 1 = ~5 requests)
- Do not run simulated-user/redteam against production — use a Pantheon multidev
- No PII in test questions
- Rate limits: 15 req/min, 120 req/hr per IP

To run manually or against a different environment:

```bash
export ILAS_ASSISTANT_URL=https://your-multidev.idaholegalaid.org/assistant/api/message
bash promptfoo-evals/scripts/run-promptfoo.sh eval
```

### Run evaluation (dry-run / offline)

To run offline without hitting the live endpoint, edit `promptfooconfig.yaml`
to uncomment the `echo` provider and comment out the `file://` provider:

```bash
# Linux / macOS
npm run eval:promptfoo

# Windows PowerShell
npm run eval:promptfoo:win
```

### View results

```bash
npm run view:promptfoo
```

This starts the Promptfoo web UI as a **foreground process**. The viewer
auto-selects port 15500 (or the next free port up to 15510). The URL is only
accessible while the process is running — press `Ctrl+C` to stop it.

To use a custom starting port:

```bash
PROMPTFOO_PORT=16000 npm run view:promptfoo
```

## Privacy & offline defaults

All wrapper scripts automatically set these environment variables:

| Variable                             | Value  | Purpose                          |
|--------------------------------------|--------|----------------------------------|
| `PROMPTFOO_DISABLE_TELEMETRY`        | `1`    | No usage analytics               |
| `PROMPTFOO_DISABLE_UPDATE`           | `1`    | No update checks                 |
| `PROMPTFOO_DISABLE_REMOTE_GENERATION`| `true` | No remote test generation        |
| `PROMPTFOO_DISABLE_SHARING`          | `1`    | No result sharing                |
| `PROMPTFOO_SELF_HOSTED`             | `1`    | Self-hosted mode                 |
| `PROMPTFOO_DISABLE_ADAPTIVE_SCHEDULER` | `1` | Deterministic runtime (no long retry loops) |

You can also add these to a `.env` file in `promptfoo-evals/` or export them
in your shell profile.

## How the custom provider works

The custom provider (`providers/ilas-live.js`) handles the Drupal assistant
API contract:

1. **CSRF token** — on first request, fetches a token from
   `${baseUrl}/session/token` (derived from `ILAS_ASSISTANT_URL`)
2. **POST** — sends `{"message": "<question>", "conversation_id": "<uuid>"}` to
   `/assistant/api/message` with the token in `X-CSRF-Token` header
3. **Response** — extracts `response.message` as the output
4. **Error handling** — retries once on 403 (CSRF expiry), reports 429 rate
   limits, returns descriptive errors for network failures

No cookies or authentication needed — the endpoint accepts anonymous sessions.

### Enable simulated-user multi-turn (optional)

Uncomment the `redteam` block in `promptfooconfig.yaml` and set
`OPENAI_API_KEY` (or another LLM provider key) so the simulated user can
drive conversations. Only run this against a Pantheon multidev, not production.

## Multi-turn test generation

The default eval suite uses 10 multi-turn conversations (5 turns each, 50 tests
total) to detect quality degradation as conversation context grows.

### One-time setup

```bash
cd promptfoo-evals && npm install && cd ..
```

### Generate multi-turn tests

```bash
npm run generate:multiturn --prefix promptfoo-evals
# or: node promptfoo-evals/scripts/generate-multiturn.js
```

This reads `tests/live-50.sanitized.yaml` (50 single-turn questions), splits
them into 10 sequential groups of 5, and writes `tests/live-10convos.yaml`.

Each turn carries the prior user messages in `vars.history`, which the custom
provider sends as `context.history` in the API request body. Turn 1 of each
conversation has an empty history; turn 5 has 4 prior messages.

Re-run the generator after editing the source questions in
`live-50.sanitized.yaml`.

## Harness integrity checks

Run the JS assertion linter before gate/eval runs:

```bash
node promptfoo-evals/scripts/lint-javascript-assertions.mjs
```

This catches promptfoo custom JS assertions that would otherwise return
`undefined` in multiline blocks and create false negatives.

Generate a failure adjudication artifact from current result files:

```bash
node promptfoo-evals/scripts/adjudicate-failures.mjs
```

This writes `output/failure-adjudication.json` with per-case classification:
`harness_false_negative`, `rubric_false_negative`, or `product_defect`.

## Adding new test suites

Create YAML files in `tests/` and reference them in `promptfooconfig.yaml`:

```yaml
tests:
  - tests/simulated-user-smoke.yaml
  - tests/your-new-suite.yaml
```

Each test case should include:
- `vars.question` — the user message
- `vars.goal` — conversation goal (for documentation / simulated user)
- `assert` — one or more assertions
- `metadata.conversationId` — groups turns in the same conversation

## Troubleshooting

- **"Cannot find module promptfoo"** — ensure Node.js >= 18 is installed.
  Scripts use `npx promptfoo@latest` so no global install is needed.
- **Network errors** — the default `echo` provider makes no network calls.
  If you see connection errors, you may have uncommented the HTTP provider
  without setting `ILAS_ASSISTANT_URL`.
- **Telemetry warnings** — the wrapper scripts disable telemetry by default.
  If running `npx promptfoo` directly, set the env vars listed above.

### Viewer troubleshooting (WSL2)

- **Viewer lifecycle** — the URL (`http://localhost:<PORT>`) only works while
  `npm run view:promptfoo` is running. Deep-link URLs (e.g. `/eval-...`)
  require the viewer to be running with access to that eval's data; always
  start from the root URL.
- **Check if a port is in use:**
  ```bash
  ss -ltnp | grep 15500
  ```
- **Kill a stuck viewer process:**
  ```bash
  fuser -k 15500/tcp
  # or
  kill $(ss -ltnp | grep ':15500 ' | grep -oP 'pid=\K\d+')
  ```
- **Custom port:**
  ```bash
  PROMPTFOO_PORT=16000 npm run view:promptfoo
  ```
- **No evals visible?** — run `npm run eval:promptfoo` first. The viewer reads
  from the project-local DB at `promptfoo-evals/.promptfoo/`.
