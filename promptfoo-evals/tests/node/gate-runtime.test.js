const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const test = require('node:test');

const {
  discoverNodeExtraCaCerts,
  listNodeExtraCaCandidates,
} = require('../../lib/node-ca-discovery');
const {
  classifyTargetUrl,
  inferPantheonEnvFromHost,
  validateTargetEnv,
} = require('../../lib/gate-target');
const {
  IlasLiveTransport,
  classifyFetchError,
  createSerializedPacer,
  formatStructuredError,
  parseStructuredError,
  summarizeAssistantResponse,
} = require('../../lib/ilas-live-shared');

function makeTempDir() {
  return fs.mkdtempSync(path.join(os.tmpdir(), 'ilas-promptfoo-gate-'));
}

function ensureFile(filePath, contents = 'test') {
  fs.mkdirSync(path.dirname(filePath), { recursive: true });
  fs.writeFileSync(filePath, contents, 'utf8');
}

function createHeaders(entries = {}) {
  const normalized = new Map();
  for (const [key, value] of Object.entries(entries)) {
    normalized.set(key.toLowerCase(), value);
  }

  return {
    get(name) {
      return normalized.get(String(name).toLowerCase()) ?? null;
    },
  };
}

function createResponse({ status = 200, body = '', headers = {} } = {}) {
  const textBody = typeof body === 'string' ? body : JSON.stringify(body);

  return {
    ok: status >= 200 && status < 300,
    status,
    statusText: `HTTP ${status}`,
    headers: createHeaders(headers),
    async text() {
      return textBody;
    },
    async json() {
      return typeof body === 'string' ? JSON.parse(body) : body;
    },
  };
}

test('listNodeExtraCaCandidates prioritizes env then mkcert roots', () => {
  const tempDir = makeTempDir();
  const envCert = path.join(tempDir, 'env-root.pem');
  const mkcertCaroot = path.join(tempDir, 'mkcert-caroot');
  const homeDir = path.join(tempDir, 'home');
  const localCert = path.join(homeDir, '.local', 'share', 'mkcert', 'rootCA.pem');
  const windowsCert = path.join(tempDir, 'windows-root.pem');

  ensureFile(envCert);
  ensureFile(path.join(mkcertCaroot, 'rootCA.pem'));
  ensureFile(localCert);
  ensureFile(windowsCert);

  const candidates = listNodeExtraCaCandidates({
    env: { NODE_EXTRA_CA_CERTS: envCert },
    homeDir,
    mkcertCaroot,
    windowsRoots: [windowsCert],
  });

  assert.deepEqual(
    candidates.map((candidate) => [candidate.source, candidate.path]),
    [
      ['env', envCert],
      ['mkcert_caroot', path.join(mkcertCaroot, 'rootCA.pem')],
      ['known_local', localCert],
      ['windows_mkcert', windowsCert],
    ]
  );
});

test('discoverNodeExtraCaCerts falls through to mkcert root when earlier candidates do not validate', () => {
  const tempDir = makeTempDir();
  const envCert = path.join(tempDir, 'env-root.pem');
  const mkcertCaroot = path.join(tempDir, 'mkcert-caroot');
  const mkcertRoot = path.join(mkcertCaroot, 'rootCA.pem');

  ensureFile(envCert);
  ensureFile(mkcertRoot);

  const attempted = [];
  const result = discoverNodeExtraCaCerts({
    assistantUrl: 'https://ilas-pantheon.ddev.site/assistant/api/message',
    env: { NODE_EXTRA_CA_CERTS: envCert },
    homeDir: path.join(tempDir, 'home'),
    execFileSync: () => mkcertCaroot,
    validateCandidate(candidatePath) {
      attempted.push(candidatePath);
      return { ok: candidatePath === mkcertRoot };
    },
  });

  assert.equal(result.ok, true);
  assert.equal(result.path, mkcertRoot);
  assert.equal(result.source, 'mkcert_caroot');
  assert.deepEqual(attempted, [envCert, mkcertRoot]);
});

test('classifyTargetUrl marks DDEV hosts distinctly from remote hosts', () => {
  const ddevTarget = classifyTargetUrl(
    'https://ilas-pantheon.ddev.site/assistant/api/message',
    'https://ilas-pantheon.ddev.site'
  );
  const remoteTarget = classifyTargetUrl(
    'https://dev-idaho-legal-aid-services.pantheonsite.io/assistant/api/message',
    'https://ilas-pantheon.ddev.site'
  );

  assert.equal(ddevTarget.targetKind, 'ddev');
  assert.equal(ddevTarget.host, 'ilas-pantheon.ddev.site');
  assert.equal(ddevTarget.pantheonEnv, '');
  assert.equal(remoteTarget.targetKind, 'remote');
  assert.equal(remoteTarget.host, 'dev-idaho-legal-aid-services.pantheonsite.io');
  assert.equal(remoteTarget.pantheonEnv, 'dev');
});

test('inferPantheonEnvFromHost recognizes Pantheon dev/test/live hosts', () => {
  assert.equal(inferPantheonEnvFromHost('dev-idaho-legal-aid-services.pantheonsite.io'), 'dev');
  assert.equal(inferPantheonEnvFromHost('test-idaho-legal-aid-services.pantheonsite.io'), 'test');
  assert.equal(inferPantheonEnvFromHost('live-idaho-legal-aid-services.pantheonsite.io'), 'live');
  assert.equal(inferPantheonEnvFromHost('example.invalid'), '');
});

test('validateTargetEnv detects Pantheon target mismatches without changing DDEV behavior', () => {
  const matchedRemote = validateTargetEnv(
    'https://dev-idaho-legal-aid-services.pantheonsite.io/assistant/api/message',
    'dev'
  );
  const mismatchedRemote = validateTargetEnv(
    'https://test-idaho-legal-aid-services.pantheonsite.io/assistant/api/message',
    'dev'
  );
  const ddevTarget = validateTargetEnv(
    'https://ilas-pantheon.ddev.site/assistant/api/message',
    'dev',
    'https://ilas-pantheon.ddev.site'
  );

  assert.equal(matchedRemote.targetValidationStatus, 'matched');
  assert.equal(matchedRemote.resolvedTargetEnv, 'dev');

  assert.equal(mismatchedRemote.targetValidationStatus, 'target_env_mismatch');
  assert.equal(mismatchedRemote.resolvedTargetEnv, 'test');

  assert.equal(ddevTarget.targetKind, 'ddev');
  assert.equal(ddevTarget.targetValidationStatus, 'not_applicable');
  assert.equal(ddevTarget.resolvedTargetEnv, '');
});

test('createSerializedPacer spaces concurrent requests deterministically', async () => {
  let nowMs = 0;
  const waits = [];
  const pacer = createSerializedPacer({
    requestDelayMs: 100,
    now: () => nowMs,
    async sleepFn(ms) {
      waits.push(ms);
      nowMs += ms;
    },
  });

  await Promise.all([pacer(), pacer(), pacer()]);

  assert.deepEqual(waits, [100, 100]);
});

test('classifyFetchError recognizes TLS failures and structured errors round-trip cleanly', () => {
  const structured = classifyFetchError(
    { message: 'certificate verify failed', cause: { code: 'UNABLE_TO_VERIFY_LEAF_SIGNATURE' } },
    'bootstrap_get',
    'https://ilas-pantheon.ddev.site/assistant/api/session/bootstrap'
  );

  assert.equal(structured.kind, 'connectivity');
  assert.equal(structured.code, 'tls_untrusted');
  assert.equal(structured.phase, 'bootstrap_get');

  const parsed = parseStructuredError(formatStructuredError(structured));
  assert.deepEqual(parsed, structured);
});

test('IlasLiveTransport fails fast on HTTP 429 in gate mode', async () => {
  const calls = [];
  const transport = new IlasLiveTransport({
    assistantUrl: 'https://example.test/assistant/api/message',
    pacer: async () => {},
    silent: true,
    gateMode: true,
    failFast429: true,
    fetchImpl: async (url) => {
      calls.push(url);
      if (url.endsWith('/assistant/api/session/bootstrap')) {
        return createResponse({
          status: 200,
          body: 'csrf-token',
          headers: { 'set-cookie': 'SSESS=abc123; Path=/; HttpOnly' },
        });
      }
      if (url.endsWith('/assistant/api/message')) {
        return createResponse({
          status: 429,
          body: { message: 'Too Many Requests' },
          headers: { 'Retry-After': '60' },
        });
      }
      throw new Error(`Unexpected URL: ${url}`);
    },
  });

  const result = await transport.runConnectivityPreflight('Where is your Boise office?');

  assert.equal(result.ok, false);
  assert.equal(result.error.kind, 'capacity');
  assert.equal(result.error.code, 'rate_limited');
  assert.equal(calls.filter((url) => url.endsWith('/assistant/api/message')).length, 1);
});

test('IlasLiveTransport retries HTTP 429 when failFast429 is disabled', async () => {
  const calls = [];
  let messageAttempts = 0;
  const transport = new IlasLiveTransport({
    assistantUrl: 'https://example.test/assistant/api/message',
    pacer: async () => {},
    silent: true,
    gateMode: true,
    failFast429: false,
    max429Retries: 2,
    base429WaitMs: 1,
    max429WaitMs: 1,
    fetchImpl: async (url) => {
      calls.push(url);
      if (url.endsWith('/assistant/api/session/bootstrap')) {
        return createResponse({
          status: 200,
          body: 'csrf-token',
          headers: { 'set-cookie': 'SSESS=abc123; Path=/; HttpOnly' },
        });
      }
      if (url.endsWith('/assistant/api/message')) {
        messageAttempts++;
        if (messageAttempts === 1) {
          return createResponse({
            status: 429,
            body: { message: 'Too Many Requests' },
            headers: { 'Retry-After': '0' },
          });
        }
        return createResponse({
          status: 200,
          body: { message: 'The Boise office is available for walk-ins.' },
        });
      }
      throw new Error(`Unexpected URL: ${url}`);
    },
  });

  const result = await transport.runConnectivityPreflight('Where is your Boise office?');

  assert.equal(result.ok, true);
  assert.equal(calls.filter((url) => url.endsWith('/assistant/api/message')).length, 2);
});

test('IlasLiveTransport normalizes accidental double slashes in assistant URLs', () => {
  const transport = new IlasLiveTransport({
    assistantUrl: 'https://example.test//assistant/api/message',
    silent: true,
  });

  const resolved = transport.resolveUrls();

  assert.deepEqual(resolved, {
    baseUrl: 'https://example.test',
    messageUrl: 'https://example.test/assistant/api/message',
  });
});

test('summarizeAssistantResponse captures lexical and vector provenance counts', () => {
  const summary = summarizeAssistantResponse({
    response_mode: 'answer',
    reason_code: 'retrieval_match',
    confidence: 0.81,
    results: [
      { source_class: 'faq_lexical' },
      { source_class: 'faq_vector' },
      { source_class: 'resource_vector' },
    ],
    citations: [
      { source: 'lexical' },
      { source: 'vector' },
      { source: 'vector' },
    ],
  });

  assert.deepEqual(summary, {
    response_mode: 'answer',
    reason_code: 'retrieval_match',
    confidence: 0.81,
    results_count: 3,
    citations_count: 3,
    source_classes: ['faq_lexical', 'faq_vector', 'resource_vector'],
    vector_result_count: 2,
    lexical_result_count: 1,
    vector_citation_count: 2,
    lexical_citation_count: 1,
  });
});
