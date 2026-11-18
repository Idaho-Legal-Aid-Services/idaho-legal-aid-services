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
  buildContractMeta,
  buildIlasProviderMeta,
  classifyFetchError,
  createSerializedPacer,
  formatStructuredError,
  parseStructuredError,
  renderAssistantOutput,
  summarizeAssistantResponse,
} = require('../../lib/ilas-live-shared');
const {
  hasSupportedCitation,
  isLiveProviderMode,
} = require('../../lib/ilas-assertions');

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

test('IlasLiveTransport times out hung requests with a structured timeout error', async () => {
  const transport = new IlasLiveTransport({
    assistantUrl: 'https://example.test/assistant/api/message',
    pacer: async () => {},
    silent: true,
    requestTimeoutMs: 5,
    fetchImpl: async (url, options = {}) => {
      if (url.endsWith('/assistant/api/session/bootstrap')) {
        return createResponse({
          status: 200,
          body: 'csrf-token',
          headers: { 'set-cookie': 'SSESS=abc123; Path=/; HttpOnly' },
        });
      }

      if (url.endsWith('/assistant/api/message')) {
        await new Promise((_, reject) => {
          options.signal?.addEventListener(
            'abort',
            () => reject(options.signal.reason || Object.assign(new Error('AbortError'), { name: 'AbortError' })),
            { once: true }
          );
        });
      }

      throw new Error(`Unexpected URL: ${url}`);
    },
  });

  const result = await transport.runConnectivityPreflight('Where is your Boise office?');

  assert.equal(result.ok, false);
  assert.equal(result.error.kind, 'connectivity');
  assert.equal(result.error.code, 'timeout');
  assert.equal(result.error.request_timeout_ms, 5);
});

test('IlasLiveTransport bootstraps with sanitized transport metadata', async () => {
  const transport = new IlasLiveTransport({
    assistantUrl: 'https://example.test/assistant/api/message',
    pacer: async () => {},
    silent: true,
    fetchImpl: async (url) => {
      if (url.endsWith('/assistant/api/session/bootstrap')) {
        return createResponse({
          status: 200,
          body: 'csrf-token-secret',
          headers: { 'set-cookie': 'SSESS=private-cookie; Path=/; HttpOnly' },
        });
      }
      if (url.endsWith('/assistant/api/message')) {
        return createResponse({
          status: 200,
          body: { message: 'The Boise office is available for walk-ins.' },
        });
      }
      throw new Error(`Unexpected URL: ${url}`);
    },
  });

  const result = await transport.callMessageApi({
    question: 'Where is your Boise office?',
    conversationId: 'conversation-one',
    history: [{ role: 'user', content: 'Where is your Boise office?' }],
  });

  assert.equal(result.ok, true);
  assert.equal(result.transport.bootstrap.attempted, true);
  assert.equal(result.transport.bootstrap.success, true);
  assert.equal(result.transport.bootstrap.csrf_token_present, true);
  assert.equal(result.transport.bootstrap.session_cookie_present, true);
  const serialized = JSON.stringify(result.transport);
  assert.equal(serialized.includes('csrf-token-secret'), false);
  assert.equal(serialized.includes('private-cookie'), false);
});

test('IlasLiveTransport reuses cookies across message calls without exposing cookie values', async () => {
  const postCookies = [];
  let bootstrapCalls = 0;
  const transport = new IlasLiveTransport({
    assistantUrl: 'https://example.test/assistant/api/message',
    pacer: async () => {},
    silent: true,
    fetchImpl: async (url, options = {}) => {
      if (url.endsWith('/assistant/api/session/bootstrap')) {
        bootstrapCalls++;
        return createResponse({
          status: 200,
          body: 'csrf-token',
          headers: { 'set-cookie': 'SSESS=reused-cookie; Path=/; HttpOnly' },
        });
      }
      if (url.endsWith('/assistant/api/message')) {
        postCookies.push(options.headers?.Cookie || null);
        return createResponse({
          status: 200,
          body: { message: 'The Boise office is available for walk-ins.' },
        });
      }
      throw new Error(`Unexpected URL: ${url}`);
    },
  });

  await transport.callMessageApi({
    question: 'Where is your Boise office?',
    conversationId: 'conversation-one',
    history: [],
  });
  const second = await transport.callMessageApi({
    question: 'What are the hours?',
    conversationId: 'conversation-one',
    history: [],
  });

  assert.equal(bootstrapCalls, 1);
  assert.deepEqual(postCookies, ['SSESS=reused-cookie', 'SSESS=reused-cookie']);
  assert.equal(second.transport.bootstrap.cookie_reused, true);
  assert.equal(JSON.stringify(second.transport).includes('reused-cookie'), false);
});

test('IlasLiveTransport retries once after CSRF 403 and records recovery metadata', async () => {
  let bootstrapCalls = 0;
  let postCalls = 0;
  const transport = new IlasLiveTransport({
    assistantUrl: 'https://example.test/assistant/api/message',
    pacer: async () => {},
    silent: true,
    fetchImpl: async (url) => {
      if (url.endsWith('/assistant/api/session/bootstrap')) {
        bootstrapCalls++;
        return createResponse({
          status: 200,
          body: `csrf-token-${bootstrapCalls}`,
          headers: { 'set-cookie': `SSESS=cookie-${bootstrapCalls}; Path=/; HttpOnly` },
        });
      }
      if (url.endsWith('/assistant/api/message')) {
        postCalls++;
        if (postCalls === 1) {
          return createResponse({ status: 403, body: { message: 'Forbidden' } });
        }
        return createResponse({
          status: 200,
          body: { message: 'The Boise office is available for walk-ins.' },
        });
      }
      throw new Error(`Unexpected URL: ${url}`);
    },
  });

  const result = await transport.callMessageApi({
    question: 'Where is your Boise office?',
    conversationId: 'conversation-one',
    history: [],
  });

  assert.equal(result.ok, true);
  assert.equal(bootstrapCalls, 2);
  assert.equal(postCalls, 2);
  assert.equal(result.transport.retries.csrf_403, 1);
  assert.equal(result.transport.bootstrap.refreshed_after_403, true);
});

test('IlasLiveTransport handles invalid JSON as structured failure', async () => {
  const transport = new IlasLiveTransport({
    assistantUrl: 'https://example.test/assistant/api/message',
    pacer: async () => {},
    silent: true,
    fetchImpl: async (url) => {
      if (url.endsWith('/assistant/api/session/bootstrap')) {
        return createResponse({
          status: 200,
          body: 'csrf-token',
          headers: { 'set-cookie': 'SSESS=abc123; Path=/; HttpOnly' },
        });
      }
      if (url.endsWith('/assistant/api/message')) {
        return createResponse({ status: 200, body: 'not-json' });
      }
      throw new Error(`Unexpected URL: ${url}`);
    },
  });

  const result = await transport.callMessageApi({
    question: 'Where is your Boise office?',
    conversationId: 'conversation-one',
    history: [],
  });

  assert.equal(result.ok, false);
  assert.equal(result.error.kind, 'connectivity');
  assert.equal(result.error.code, 'invalid_json');
  assert.equal(result.error.message.includes('not-json'), false);
  assert.equal(result.transport.errors[0].code, 'invalid_json');
  // What came back is described by shape only; the body text is never kept.
  assert.equal(result.error.body_kind, 'text');
  assert.equal(result.error.body_bytes, 8);
  assert.equal(JSON.stringify(result.error).includes('not-json'), false);
});

test('IlasLiveTransport says what kind of non-JSON body came back', async () => {
  const bodyKindFor = async (response) => {
    const transport = new IlasLiveTransport({
      assistantUrl: 'https://example.test/assistant/api/message',
      pacer: async () => {},
      silent: true,
      fetchImpl: async (url) => {
        if (url.endsWith('/assistant/api/session/bootstrap')) {
          return createResponse({
            status: 200,
            body: 'csrf-token',
            headers: { 'set-cookie': 'SSESS=abc123; Path=/; HttpOnly' },
          });
        }
        return response;
      },
    });
    const result = await transport.callMessageApi({
      question: 'Where is your Boise office?',
      conversationId: 'conversation-one',
      history: [],
    });
    assert.equal(result.error.code, 'invalid_json');
    return result.error;
  };

  const html = await bodyKindFor(createResponse({
    status: 200,
    body: '<!DOCTYPE html><html><body>Site under maintenance</body></html>',
    headers: { 'content-type': 'text/html; charset=UTF-8' },
  }));
  assert.equal(html.body_kind, 'html');
  assert.equal(html.content_type, 'text/html; charset=UTF-8');
  assert.equal(JSON.stringify(html).includes('maintenance'), false);

  assert.equal((await bodyKindFor(createResponse({ status: 200, body: '' }))).body_kind, 'empty');
  assert.equal((await bodyKindFor(createResponse({ status: 200, body: '{"type":"faq","mess' }))).body_kind, 'truncated_json');

  // A connection cut while the body is being read.
  const cut = createResponse({ status: 200, body: '' });
  cut.text = async () => {
    const err = new TypeError('terminated');
    err.cause = { code: 'UND_ERR_SOCKET' };
    throw err;
  };
  assert.equal((await bodyKindFor(cut)).body_kind, 'unreadable:UND_ERR_SOCKET');
});

test('citation metadata is parsed as supported grounding only when explicit source metadata exists', () => {
  const response = {
    message: 'Read the Idaho eviction notice guide.',
    type: 'search_results',
    response_mode: 'grounded_answer',
    reason_code: 'retrieval_match',
    confidence: 0.82,
    sources: [
      {
        id: 'resource-1',
        title: 'Eviction notices',
        url: '/node/123',
        source: 'vector',
        source_class: 'resource_vector',
        topic: 'eviction',
      },
    ],
    results: [
      {
        title: 'Eviction notices',
        url: '/node/123',
        source_class: 'resource_vector',
        topic: 'eviction',
      },
    ],
  };

  const meta = buildIlasProviderMeta(response, 'https://idaholegalaid.org');
  const output = renderAssistantOutput(response, 'https://idaholegalaid.org', { providerMeta: meta });

  assert.equal(meta.grounded, true);
  assert.equal(meta.supported_citations_count, 1);
  assert.equal(buildContractMeta(response, 'https://idaholegalaid.org', { providerMeta: meta }).citations_count, 1);
  assert.equal(hasSupportedCitation(output, { vars: { expected_topic: 'eviction' } }).pass, true);
});

test('links and results without explicit citation metadata do not silently pass as grounded', () => {
  const response = {
    message: 'You can review Idaho family-law forms here.',
    type: 'search_results',
    response_mode: 'grounded_answer',
    reason_code: 'retrieval_match',
    confidence: 0.82,
    results: [
      { title: 'Family law overview', url: '/issues/family-law' },
      { title: 'Court forms', url: '/forms/court-assistance' },
    ],
    links: [{ label: 'Court Assistance Office', url: '/forms/court-assistance' }],
  };

  const meta = buildIlasProviderMeta(response, 'https://idaholegalaid.org');
  const output = renderAssistantOutput(response, 'https://idaholegalaid.org', { providerMeta: meta });

  assert.equal(meta.grounded, false);
  assert.equal(meta.supported_citations_count, 0);
  assert.equal(meta.derived_citation_count, 2);
  assert.equal(hasSupportedCitation(output).pass, false);
});

test('simulated provider metadata cannot pass live-mode assertion', () => {
  const response = { message: 'Simulated assistant response.' };
  const providerMeta = buildIlasProviderMeta(response, 'https://idaholegalaid.org', {
    providerMode: 'simulated',
  });
  const output = renderAssistantOutput(response, 'https://idaholegalaid.org', { providerMeta });

  assert.equal(isLiveProviderMode(output).pass, false);
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
    sources: [
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
