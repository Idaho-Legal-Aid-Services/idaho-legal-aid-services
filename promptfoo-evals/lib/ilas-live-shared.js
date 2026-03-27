const crypto = require('node:crypto');

const DEFAULT_SITE_BASE_URL = 'https://idaholegalaid.org';
const STRUCTURED_ERROR_PREFIX = '[ilas_error]';
const DEFAULT_EXPECTED_REQUEST_TOTAL = 292;

const pacers = new Map();
let requestCount = 0;

function sleep(ms) {
  return new Promise((resolve) => setTimeout(resolve, ms));
}

function normalizeInteger(value, fallback) {
  const parsed = Number.parseInt(String(value ?? ''), 10);
  return Number.isFinite(parsed) ? parsed : fallback;
}

function readBoolean(value, fallback = false) {
  if (value === undefined || value === null || value === '') {
    return fallback;
  }

  const normalized = String(value).trim().toLowerCase();
  if (['1', 'true', 'yes', 'on'].includes(normalized)) {
    return true;
  }
  if (['0', 'false', 'no', 'off'].includes(normalized)) {
    return false;
  }

  return fallback;
}

function createSerializedPacer({ requestDelayMs, now = Date.now, sleepFn = sleep }) {
  let queue = Promise.resolve();
  let nextAvailableAt = 0;

  return async function paceRequest() {
    if (requestDelayMs <= 0) {
      return;
    }

    let releaseQueue = () => {};
    const slot = new Promise((resolve) => {
      releaseQueue = resolve;
    });
    const previous = queue;
    queue = queue.then(() => slot);

    await previous;
    const waitMs = Math.max(0, nextAvailableAt - now());
    if (waitMs > 0) {
      await sleepFn(waitMs);
    }
    nextAvailableAt = now() + requestDelayMs;
    releaseQueue();
  };
}

function getSharedPacer(requestDelayMs) {
  if (!pacers.has(requestDelayMs)) {
    pacers.set(requestDelayMs, createSerializedPacer({ requestDelayMs }));
  }
  return pacers.get(requestDelayMs);
}

function toAbsoluteUrl(input, siteBaseUrl = DEFAULT_SITE_BASE_URL) {
  if (!input) {
    return input;
  }

  try {
    // eslint-disable-next-line no-new
    new URL(input);
    return input;
  } catch (_) {
    try {
      return new URL(input, siteBaseUrl).toString();
    } catch (_) {
      return input;
    }
  }
}

function absolutizeRelativePathsInText(text, siteBaseUrl = DEFAULT_SITE_BASE_URL) {
  if (!text || typeof text !== 'string') {
    return text;
  }

  return text.replace(
    /(^|[\s([{"'`])\/(?!\/)([A-Za-z0-9][A-Za-z0-9/_\-]*)(?=($|[\s)\]}",'`.!?;:]))/g,
    (match, prefix, path) => `${prefix}${toAbsoluteUrl('/' + path, siteBaseUrl)}`
  );
}

function extractFirstSetCookieValue(setCookieHeader) {
  if (!setCookieHeader) {
    return null;
  }

  const first = setCookieHeader.split('\n')[0] || setCookieHeader;
  const cookiePair = first.split(';')[0]?.trim();
  return cookiePair || null;
}

function deterministicUuidV4(seed) {
  const digest = crypto.createHash('sha256').update(String(seed)).digest();
  const bytes = Buffer.from(digest.subarray(0, 16));

  bytes[6] = (bytes[6] & 0x0f) | 0x40;
  bytes[8] = (bytes[8] & 0x3f) | 0x80;

  const hex = bytes.toString('hex');
  return (
    `${hex.slice(0, 8)}-` +
    `${hex.slice(8, 12)}-` +
    `${hex.slice(12, 16)}-` +
    `${hex.slice(16, 20)}-` +
    `${hex.slice(20, 32)}`
  );
}

function createStructuredError(kind, code, message, extra = {}) {
  return {
    kind,
    code,
    message,
    ...extra,
  };
}

function formatStructuredError(error) {
  return `${STRUCTURED_ERROR_PREFIX}${JSON.stringify(error)}`;
}

function parseStructuredError(value) {
  const text = String(value || '').trim();
  if (!text.startsWith(STRUCTURED_ERROR_PREFIX)) {
    return null;
  }

  try {
    return JSON.parse(text.slice(STRUCTURED_ERROR_PREFIX.length));
  } catch (_) {
    return null;
  }
}

function classifyFetchError(err, phase, url) {
  const code = err?.code || err?.cause?.code || '';
  let errorCode = 'network_error';

  if (
    [
      'UNABLE_TO_VERIFY_LEAF_SIGNATURE',
      'SELF_SIGNED_CERT_IN_CHAIN',
      'DEPTH_ZERO_SELF_SIGNED_CERT',
      'CERT_HAS_EXPIRED',
      'ERR_TLS_CERT_ALTNAME_INVALID',
    ].includes(code)
  ) {
    errorCode = 'tls_untrusted';
  } else if (['ENOTFOUND', 'EAI_AGAIN'].includes(code)) {
    errorCode = 'dns_resolution_failed';
  } else if (['ECONNREFUSED', 'ECONNRESET'].includes(code)) {
    errorCode = 'connection_failed';
  } else if (['ETIMEDOUT', 'UND_ERR_CONNECT_TIMEOUT', 'UND_ERR_HEADERS_TIMEOUT'].includes(code)) {
    errorCode = 'timeout';
  }

  return createStructuredError(
    'connectivity',
    errorCode,
    err?.message || String(err),
    {
      phase,
      url,
      transport_code: code || null,
    }
  );
}

function buildHumanReadableOutput(data, siteBaseUrl) {
  const parts = [];

  if (data.message) {
    parts.push(absolutizeRelativePathsInText(data.message, siteBaseUrl));
  }

  if (data.url) {
    parts.push(`[Navigate: ${toAbsoluteUrl(data.url, siteBaseUrl)}]`);
  }

  if (Array.isArray(data.results)) {
    for (const result of data.results) {
      if (result.title) {
        parts.push(absolutizeRelativePathsInText(`Result: ${result.title}`, siteBaseUrl));
      }
      if (result.question && result.question !== result.title) {
        parts.push(absolutizeRelativePathsInText(`Q: ${result.question}`, siteBaseUrl));
      }
      if (result.answer) {
        parts.push(absolutizeRelativePathsInText(result.answer, siteBaseUrl));
      }
      if (result.url) {
        parts.push(`[Result URL: ${toAbsoluteUrl(result.url, siteBaseUrl)}]`);
      }
    }
  }

  if (Array.isArray(data.secondary_actions)) {
    for (const action of data.secondary_actions) {
      if (action.label) {
        parts.push(
          `Action: ${absolutizeRelativePathsInText(action.label, siteBaseUrl)} (${toAbsoluteUrl(action.url, siteBaseUrl)})`
        );
      }
    }
  }

  if (Array.isArray(data.links)) {
    for (const link of data.links) {
      if (link.label) {
        parts.push(
          `${absolutizeRelativePathsInText(link.label, siteBaseUrl)} (${toAbsoluteUrl(link.url, siteBaseUrl)})`
        );
      }
    }
  }

  if (Array.isArray(data.topic_suggestions)) {
    for (const suggestion of data.topic_suggestions) {
      if (suggestion?.label) {
        parts.push(`Option: ${absolutizeRelativePathsInText(String(suggestion.label), siteBaseUrl)}`);
      }
    }
  }

  if (data.office && typeof data.office === 'object') {
    if (data.office.name) {
      parts.push(`Office: ${absolutizeRelativePathsInText(String(data.office.name), siteBaseUrl)}`);
    }
    if (data.office.address) {
      parts.push(`Address: ${absolutizeRelativePathsInText(String(data.office.address), siteBaseUrl)}`);
    }
    if (data.office.phone) {
      parts.push(`Phone: ${absolutizeRelativePathsInText(String(data.office.phone), siteBaseUrl)}`);
    }
    if (data.office.hours) {
      parts.push(`Hours: ${absolutizeRelativePathsInText(String(data.office.hours), siteBaseUrl)}`);
    }
  }

  if (data.primary_action?.label) {
    parts.push(
      `Primary: ${absolutizeRelativePathsInText(data.primary_action.label, siteBaseUrl)} (${toAbsoluteUrl(
        data.primary_action.url,
        siteBaseUrl
      )})`
    );
  }

  if (data.caveat) {
    parts.push(absolutizeRelativePathsInText(data.caveat, siteBaseUrl));
  }
  if (data.disclaimer) {
    parts.push(absolutizeRelativePathsInText(data.disclaimer, siteBaseUrl));
  }
  if (data.followup) {
    parts.push(absolutizeRelativePathsInText(data.followup, siteBaseUrl));
  }

  return parts;
}

function summarizeRetrievalProvenance(data) {
  const sourceClasses = new Set();
  let vectorResultCount = 0;
  let lexicalResultCount = 0;
  let vectorCitationCount = 0;
  let lexicalCitationCount = 0;

  if (Array.isArray(data.results)) {
    data.results.forEach((result) => {
      const sourceClass =
        typeof result?.source_class === 'string' ? result.source_class.trim() : '';
      if (!sourceClass) {
        return;
      }

      sourceClasses.add(sourceClass);
      const normalized = sourceClass.toLowerCase();
      if (normalized.includes('vector')) {
        vectorResultCount += 1;
      }
      if (normalized.includes('lexical')) {
        lexicalResultCount += 1;
      }
    });
  }

  if (Array.isArray(data.citations)) {
    data.citations.forEach((citation) => {
      const source = typeof citation?.source === 'string' ? citation.source.trim().toLowerCase() : '';
      if (!source) {
        return;
      }

      if (source.includes('vector')) {
        vectorCitationCount += 1;
      }
      if (source.includes('lexical')) {
        lexicalCitationCount += 1;
      }
    });
  }

  return {
    result_source_classes: Array.from(sourceClasses).sort(),
    vector_result_count: vectorResultCount,
    lexical_result_count: lexicalResultCount,
    vector_citation_count: vectorCitationCount,
    lexical_citation_count: lexicalCitationCount,
  };
}

function buildContractMeta(data, siteBaseUrl) {
  const derivedCitationUrls = new Set();
  if (data.url) {
    derivedCitationUrls.add(toAbsoluteUrl(data.url, siteBaseUrl));
  }
  if (data.primary_action?.url) {
    derivedCitationUrls.add(toAbsoluteUrl(data.primary_action.url, siteBaseUrl));
  }
  if (Array.isArray(data.secondary_actions)) {
    data.secondary_actions.forEach((action) => {
      if (action?.url) {
        derivedCitationUrls.add(toAbsoluteUrl(action.url, siteBaseUrl));
      }
    });
  }
  if (Array.isArray(data.links)) {
    data.links.forEach((link) => {
      if (link?.url) {
        derivedCitationUrls.add(toAbsoluteUrl(link.url, siteBaseUrl));
      }
    });
  }
  if (Array.isArray(data.results)) {
    data.results.forEach((result) => {
      if (result?.url) {
        derivedCitationUrls.add(toAbsoluteUrl(result.url, siteBaseUrl));
      }
    });
  }

  const rawConfidence =
    typeof data.confidence === 'number' ? data.confidence : Number(data.confidence);
  const normalizedConfidence = Number.isFinite(rawConfidence)
    ? Number(Math.max(0, Math.min(1, rawConfidence)).toFixed(4))
    : null;

  const explicitCitationCount = Array.isArray(data.citations)
    ? data.citations.length
    : (Array.isArray(data.sources) ? data.sources.length : 0);
  const fallbackCitationCount = derivedCitationUrls.size;
  const provenance = summarizeRetrievalProvenance(data);

  return {
    confidence: normalizedConfidence,
    citations_count: explicitCitationCount > 0 ? explicitCitationCount : fallbackCitationCount,
    response_type: data.type || null,
    response_mode: data.response_mode || null,
    reason_code: data.reason_code || null,
    decision_reason: data.decision_reason || null,
    result_source_classes: provenance.result_source_classes,
    vector_result_count: provenance.vector_result_count,
    lexical_result_count: provenance.lexical_result_count,
    vector_citation_count: provenance.vector_citation_count,
    lexical_citation_count: provenance.lexical_citation_count,
  };
}

function summarizeAssistantResponse(data, siteBaseUrl = DEFAULT_SITE_BASE_URL) {
  const contractMeta = buildContractMeta(data, siteBaseUrl);

  return {
    response_mode: data.response_mode || null,
    reason_code: data.reason_code || null,
    confidence: contractMeta.confidence,
    results_count: Array.isArray(data.results) ? data.results.length : 0,
    citations_count: contractMeta.citations_count,
    source_classes: contractMeta.result_source_classes,
    vector_result_count: contractMeta.vector_result_count,
    lexical_result_count: contractMeta.lexical_result_count,
    vector_citation_count: contractMeta.vector_citation_count,
    lexical_citation_count: contractMeta.lexical_citation_count,
  };
}

function renderAssistantOutput(data, siteBaseUrl = DEFAULT_SITE_BASE_URL) {
  const humanParts = buildHumanReadableOutput(data, siteBaseUrl);
  const contractMeta = buildContractMeta(data, siteBaseUrl);
  const humanOutput =
    absolutizeRelativePathsInText(humanParts.join('\n\n'), siteBaseUrl) || JSON.stringify(data);
  return `${humanOutput}\n\n[contract_meta]${JSON.stringify(contractMeta)}`;
}

function createTransportOptions(options = {}) {
  return {
    assistantUrl: options.assistantUrl || process.env.ILAS_ASSISTANT_URL || '',
    siteBaseUrl: options.siteBaseUrl || process.env.ILAS_SITE_BASE_URL || DEFAULT_SITE_BASE_URL,
    requestDelayMs: normalizeInteger(options.requestDelayMs ?? process.env.ILAS_REQUEST_DELAY_MS, 0),
    max429Retries: normalizeInteger(options.max429Retries ?? process.env.ILAS_429_MAX_RETRIES, 5),
    base429WaitMs: normalizeInteger(options.base429WaitMs ?? process.env.ILAS_429_BASE_WAIT_MS, 65000),
    max429WaitMs: normalizeInteger(options.max429WaitMs ?? process.env.ILAS_429_MAX_WAIT_MS, 180000),
    failFast429: readBoolean(
      options.failFast429 ?? process.env.ILAS_429_FAIL_FAST,
      readBoolean(process.env.ILAS_GATE_MODE, false)
    ),
    gateMode: readBoolean(options.gateMode ?? process.env.ILAS_GATE_MODE, false),
    expectedRequestTotal: normalizeInteger(
      options.expectedRequestTotal ?? process.env.ILAS_EXPECTED_REQUEST_TOTAL,
      DEFAULT_EXPECTED_REQUEST_TOTAL
    ),
    evalRunId: (options.evalRunId ?? process.env.ILAS_EVAL_RUN_ID ?? '').trim(),
    fetchImpl: options.fetchImpl || global.fetch,
    pacer: options.pacer,
    silent: readBoolean(options.silent, false),
  };
}

class IlasLiveTransport {
  constructor(options = {}) {
    this.options = createTransportOptions(options);
    this.baseUrl = null;
    this.messageUrl = null;
    this.csrfToken = null;
    this.cookie = null;
    this.fetchImpl = this.options.fetchImpl;
    this.pacer = this.options.pacer || getSharedPacer(this.options.requestDelayMs);
  }

  resolveUrls() {
    if (!this.options.assistantUrl) {
      throw new Error(
        'ILAS_ASSISTANT_URL is not set. Export it before running, e.g.: ' +
          'export ILAS_ASSISTANT_URL=https://idaholegalaid.org/assistant/api/message'
      );
    }

    const parsed = new URL(this.options.assistantUrl);
    parsed.pathname = parsed.pathname.replace(/\/{2,}/g, '/');
    this.baseUrl = `${parsed.protocol}//${parsed.host}`;
    this.messageUrl = parsed.toString();
    return {
      baseUrl: this.baseUrl,
      messageUrl: this.messageUrl,
    };
  }

  async fetchText(url, options, phase) {
    try {
      const response = await this.fetchImpl(url, options);
      return { ok: true, response };
    } catch (err) {
      return {
        ok: false,
        error: classifyFetchError(err, phase, url),
      };
    }
  }

  async fetchCsrfToken({
    tokenUrls,
    requireSessionCookie = true,
  } = {}) {
    if (!this.baseUrl) {
      this.resolveUrls();
    }

    const urls = tokenUrls || [
      `${this.baseUrl}/assistant/api/session/bootstrap`,
      `${this.baseUrl}/session/token`,
    ];

    let lastError = null;
    for (const tokenUrl of urls) {
      const result = await this.fetchText(
        tokenUrl,
        {
          method: 'GET',
          headers: { Accept: 'text/plain' },
        },
        'bootstrap_get'
      );
      if (!result.ok) {
        lastError = result.error;
        continue;
      }

      const { response } = result;
      if (!response.ok) {
        lastError = createStructuredError(
          'connectivity',
          'bootstrap_http',
          `CSRF token fetch failed (${tokenUrl}): ${response.status} ${response.statusText}`,
          {
            phase: 'bootstrap_get',
            url: tokenUrl,
            status: response.status,
          }
        );
        continue;
      }

      const setCookie = response.headers.get('set-cookie');
      const cookiePair = extractFirstSetCookieValue(setCookie);
      const token = (await response.text()).trim();
      if (!token) {
        lastError = createStructuredError(
          'connectivity',
          'bootstrap_empty_token',
          `CSRF token fetch returned empty token (${tokenUrl})`,
          {
            phase: 'bootstrap_get',
            url: tokenUrl,
          }
        );
        continue;
      }

      if (cookiePair) {
        this.cookie = cookiePair;
      }

      if (requireSessionCookie && !this.cookie) {
        lastError = createStructuredError(
          'connectivity',
          'bootstrap_missing_cookie',
          `CSRF bootstrap did not issue a session cookie (${tokenUrl})`,
          {
            phase: 'bootstrap_get',
            url: tokenUrl,
          }
        );
        continue;
      }

      this.csrfToken = token;
      return {
        ok: true,
        token,
        cookie: this.cookie,
        url: tokenUrl,
      };
    }

    return {
      ok: false,
      error:
        lastError ||
        createStructuredError(
          'connectivity',
          'bootstrap_unavailable',
          'CSRF token fetch failed: no token endpoint succeeded',
          { phase: 'bootstrap_get' }
        ),
    };
  }

  async callMessageApi({ question, conversationId, history, requestContext }) {
    if (!this.messageUrl) {
      this.resolveUrls();
    }

    if (!this.csrfToken) {
      const tokenResult = await this.fetchCsrfToken();
      if (!tokenResult.ok) {
        return tokenResult;
      }
    }

    await this.pacer();

    const context = {
      history,
      ...(requestContext && typeof requestContext === 'object' ? requestContext : {}),
    };

    const body = JSON.stringify({
      message: question,
      conversation_id: conversationId,
      context,
    });

    const headers = {
      'Content-Type': 'application/json',
      Accept: 'application/json',
      'X-CSRF-Token': this.csrfToken,
    };
    if (this.cookie) {
      headers.Cookie = this.cookie;
    }

    let response;
    let attempt = 0;

    // eslint-disable-next-line no-constant-condition
    while (true) {
      const result = await this.fetchText(
        this.messageUrl,
        { method: 'POST', headers, body },
        attempt === 0 ? 'message_post' : 'message_post_retry'
      );
      if (!result.ok) {
        return result;
      }

      response = result.response;

      if (response.status === 403 && attempt === 0) {
        const tokenRefresh = await this.fetchCsrfToken();
        if (!tokenRefresh.ok) {
          return {
            ok: false,
            error: createStructuredError(
              'connectivity',
              'csrf_retry_failed',
              tokenRefresh.error.message,
              {
                phase: 'message_post_retry',
                retry_error_code: tokenRefresh.error.code,
              }
            ),
          };
        }

        headers['X-CSRF-Token'] = this.csrfToken;
        if (this.cookie) {
          headers.Cookie = this.cookie;
        }
        attempt++;
        continue;
      }

      if (response.status === 429) {
        const retryAfter = response.headers.get('Retry-After');
        if (this.options.failFast429 || this.options.max429Retries <= 0) {
          return {
            ok: false,
            error: createStructuredError(
              'capacity',
              'rate_limited',
              'HTTP 429 rate limit received',
              {
                phase: 'message_post',
                status: 429,
                retry_after: retryAfter || null,
              }
            ),
          };
        }

        attempt++;
        if (attempt > this.options.max429Retries) {
          return {
            ok: false,
            error: createStructuredError(
              'capacity',
              'rate_limited',
              `HTTP 429 after ${this.options.max429Retries} retries`,
              {
                phase: 'message_post',
                status: 429,
                retry_after: retryAfter || null,
              }
            ),
          };
        }

        const rawWaitMs = retryAfter
          ? Number.parseInt(retryAfter, 10) * 1000
          : this.options.base429WaitMs * Math.pow(1.5, attempt - 1);
        const waitMs = Number.isFinite(this.options.max429WaitMs) && this.options.max429WaitMs > 0
          ? Math.min(rawWaitMs, this.options.max429WaitMs)
          : rawWaitMs;
        if (!this.options.silent) {
          requestCount++;
          const shortQ = question.length > 40 ? `${question.slice(0, 40)}...` : question;
          console.log(
            `[${requestCount}] 429 on "${shortQ}" - retry ${attempt}/${this.options.max429Retries}, waiting ${Math.round(waitMs / 1000)}s`
          );
        }
        await sleep(waitMs);
        continue;
      }

      break;
    }

    if (!response.ok) {
      const text = await response.text().catch(() => '');
      return {
        ok: false,
        error: createStructuredError(
          'connectivity',
          'message_http',
          `HTTP ${response.status}: ${text.slice(0, 300)}`,
          {
            phase: 'message_post',
            status: response.status,
          }
        ),
      };
    }

    if (!this.options.silent) {
      requestCount++;
      const shortQ = question.length > 50 ? `${question.slice(0, 50)}...` : question;
      console.log(`[${requestCount}/${this.options.expectedRequestTotal}] "${shortQ}"`);
    }

    let data;
    try {
      data = await response.json();
    } catch (err) {
      const text = await response.text().catch(() => '');
      return {
        ok: false,
        error: createStructuredError(
          'connectivity',
          'invalid_json',
          `Invalid JSON response: ${err?.message || String(err)} - ${text.slice(0, 200)}`,
          {
            phase: 'message_post',
            status: response.status,
          }
        ),
      };
    }

    return {
      ok: true,
      data,
      status: response.status,
    };
  }

  async runConnectivityPreflight(question = 'Where is your Boise office?') {
    if (!this.baseUrl) {
      this.resolveUrls();
    }

    const bootstrapResult = await this.fetchCsrfToken({
      tokenUrls: [`${this.baseUrl}/assistant/api/session/bootstrap`],
      requireSessionCookie: true,
    });
    if (!bootstrapResult.ok) {
      return bootstrapResult;
    }

    const conversationId = deterministicUuidV4(`preflight:${question}`);
    const messageResult = await this.callMessageApi({
      question,
      conversationId,
      history: [{ role: 'user', content: question }],
    });
    if (!messageResult.ok) {
      return messageResult;
    }

    const assistantMessage = String(messageResult.data?.message || '').trim();
    if (!assistantMessage) {
      return {
        ok: false,
        error: createStructuredError(
          'connectivity',
          'preflight_empty_message',
          'Preflight POST returned an empty assistant message',
          {
            phase: 'message_post',
            status: messageResult.status,
          }
        ),
      };
    }

    return {
      ok: true,
      bootstrap_url: bootstrapResult.url,
      post_status: messageResult.status,
      message_preview: assistantMessage.slice(0, 160),
      response_type: messageResult.data?.type || null,
    };
  }
}

module.exports = {
  DEFAULT_EXPECTED_REQUEST_TOTAL,
  DEFAULT_SITE_BASE_URL,
  IlasLiveTransport,
  STRUCTURED_ERROR_PREFIX,
  absolutizeRelativePathsInText,
  buildContractMeta,
  classifyFetchError,
  createSerializedPacer,
  createStructuredError,
  deterministicUuidV4,
  extractFirstSetCookieValue,
  formatStructuredError,
  parseStructuredError,
  renderAssistantOutput,
  summarizeAssistantResponse,
  summarizeRetrievalProvenance,
  toAbsoluteUrl,
};
