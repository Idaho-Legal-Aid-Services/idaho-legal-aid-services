const crypto = require('node:crypto');

const DEFAULT_SITE_BASE_URL = 'https://idaholegalaid.org';
const STRUCTURED_ERROR_PREFIX = '[ilas_error]';
const CONTRACT_META_PREFIX = '[contract_meta]';
const ILAS_PROVIDER_META_PREFIX = '[ilas_provider_meta]';
const ILAS_PROVIDER_META_SCHEMA_VERSION = 'ilas_provider_meta/v1';
const DEFAULT_EXPECTED_REQUEST_TOTAL = 292;
const DEFAULT_REQUEST_TIMEOUT_MS = 45000;
const CITATION_REQUIRED_RESPONSE_TYPES = new Set([
  'eligibility',
  'faq',
  'resources',
  'search_results',
  'services_overview',
  'topic',
]);

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
  } else if (
    err?.name === 'AbortError' ||
    ['ETIMEDOUT', 'UND_ERR_CONNECT_TIMEOUT', 'UND_ERR_HEADERS_TIMEOUT'].includes(code)
  ) {
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

function compactString(value, maxLength = 220) {
  if (value === undefined || value === null) {
    return null;
  }

  const text = String(value).replace(/\s+/g, ' ').trim();
  if (!text) {
    return null;
  }

  return text.length > maxLength ? `${text.slice(0, Math.max(0, maxLength - 3))}...` : text;
}

function compactObject(value) {
  const output = {};
  Object.entries(value).forEach(([key, item]) => {
    if (item === undefined || item === null || item === '') {
      return;
    }
    if (Array.isArray(item) && item.length === 0) {
      return;
    }
    output[key] = item;
  });
  return output;
}

function normalizeResponseText(value) {
  return String(value || '').replace(/\s+/g, ' ').trim().toLowerCase();
}

function countRegexMatches(text, patterns = []) {
  const haystack = String(text || '');
  return patterns.reduce((count, pattern) => {
    try {
      return count + (new RegExp(pattern, 'i').test(haystack) ? 1 : 0);
    } catch (_) {
      return count;
    }
  }, 0);
}

function inferGenericFallback(payload, debug = {}) {
  const text = normalizeResponseText(payload.message);
  const hasRetrievalArtifacts = (
    asArray(payload.results).length > 0 ||
    asArray(payload.sources).length > 0 ||
    asArray(payload.citations).length > 0 ||
    asArray(payload.links).length > 0 ||
    asArray(payload.secondary_actions).length > 0 ||
    Boolean(payload.primary_action?.url)
  );
  const hasTopicSignals = countRegexMatches(text, [
    '\\b(evict|eviction|notice|housing|tenant|landlord|custody|guardianship|ssi|benefits|disability|office|forms?|guides?|resource|family|divorce|consumer|debt|desalojo|custodia)\\b',
  ]) > 0;
  const genericPattern = (
    /^(how can i help you today\??|what would you like to know\??|could you clarify\??|please clarify\??|i could not find information\.?|please contact us\.?)$/i.test(text) ||
    /^please contact us\b/i.test(text) ||
    /^what kind of help do you need\b/i.test(text)
  );
  const contactOnly = (
    /\b(please contact us|call us|contact our office)\b/i.test(text) &&
    !hasRetrievalArtifacts &&
    !hasTopicSignals
  );
  const debugFallback = String(debug.gate_decision || '').toLowerCase().includes('generic_fallback');
  return genericPattern || contactOnly || debugFallback;
}

function asArray(value) {
  return Array.isArray(value) ? value : [];
}

function compactNumber(value, decimals = 4) {
  const raw = typeof value === 'number' ? value : Number(value);
  return Number.isFinite(raw) ? Number(raw.toFixed(decimals)) : null;
}

function normalizeConfidence(value) {
  const raw = typeof value === 'number' ? value : Number(value);
  return Number.isFinite(raw)
    ? Number(Math.max(0, Math.min(1, raw)).toFixed(4))
    : null;
}

function createSafeTraceId(value) {
  if (!value) {
    return null;
  }
  return crypto.createHash('sha256').update(String(value)).digest('hex').slice(0, 20);
}

function isAllowedIlasUrl(url, siteBaseUrl = DEFAULT_SITE_BASE_URL) {
  if (!url || typeof url !== 'string') {
    return false;
  }

  try {
    const parsed = new URL(toAbsoluteUrl(url, siteBaseUrl));
    const siteHost = new URL(siteBaseUrl).host.toLowerCase();
    return [
      siteHost,
      'idaholegalaid.org',
      'www.idaholegalaid.org',
    ].includes(parsed.host.toLowerCase());
  } catch (_) {
    return false;
  }
}

function summarizeAction(action, siteBaseUrl) {
  if (!action || typeof action !== 'object') {
    return null;
  }

  return compactObject({
    label: compactString(action.label),
    url: action.url ? toAbsoluteUrl(action.url, siteBaseUrl) : null,
    type: compactString(action.type, 80),
  });
}

function summarizeActions(actions, siteBaseUrl, limit = 8) {
  if (!Array.isArray(actions)) {
    return [];
  }

  return actions
    .slice(0, limit)
    .map((action) => summarizeAction(action, siteBaseUrl))
    .filter((action) => action && Object.keys(action).length > 0);
}

function summarizeResults(results, siteBaseUrl, limit = 10) {
  if (!Array.isArray(results)) {
    return [];
  }

  return results
    .slice(0, limit)
    .map((result) => {
      if (!result || typeof result !== 'object') {
        return null;
      }
      return compactObject({
        id: compactString(result.id || result.paragraph_id, 120),
        title: compactString(result.title || result.question),
        url: result.url || result.source_url ? toAbsoluteUrl(result.url || result.source_url, siteBaseUrl) : null,
        type: compactString(result.type, 80),
        source: compactString(result.source, 80),
        source_class: compactString(result.source_class, 80),
        topic: compactString(result.topic || result.topic_name || result.category, 120),
        topic_id: compactString(result.topic_id || result.term_id || result.category_id, 120),
        score: Number.isFinite(Number(result.score)) ? Number(result.score) : null,
      });
    })
    .filter((result) => result && Object.keys(result).length > 0);
}

// Vocabulary of topic slugs we recognize. These mirror the assistant's
// canonical taxonomy and map URL slugs / source_class fragments back to the
// topic an assertion will look for.
const TOPIC_HINTS = [
  'eviction',
  'tenant',
  'housing',
  'foreclosure',
  'deposit',
  'custody',
  'guardianship',
  'divorce',
  'family',
  'protection',
  'domestic-violence',
  'dv',
  'benefits',
  'ssi',
  'ssa',
  'medicaid',
  'snap',
  'unemployment',
  'debt',
  'consumer',
  'wage',
  'employment',
  'immigration',
  'criminal',
];

function deriveTopicHint({ sourceClass, url, title }) {
  const probe = `${sourceClass || ''} ${url || ''} ${title || ''}`.toLowerCase();
  for (const hint of TOPIC_HINTS) {
    // Match either a raw word or a slugified word inside source_class/url.
    const pattern = new RegExp(`(^|[^a-z])${hint.replace('-', '[-_]?')}([^a-z]|$)`, 'i');
    if (pattern.test(probe)) {
      // Normalize hyphenated DV → protection.
      if (hint === 'dv' || hint === 'domestic-violence') {
        return 'protection';
      }
      return hint;
    }
  }
  return null;
}

function normalizeCitationItem(citation, siteBaseUrl, sourceKind, supported = false) {
  if (!citation || typeof citation !== 'object') {
    return null;
  }

  const url = citation.url || citation.source_url
    ? toAbsoluteUrl(citation.url || citation.source_url, siteBaseUrl)
    : null;

  const explicitTopic = compactString(
    citation.topic || citation.topic_name || citation.category,
    120
  );
  // Defense-in-depth: when the API doesn't yet emit `topic`, derive a hint
  // from source_class / url / title. Assertion library matches the topic by
  // substring, so a slug like "eviction" inside the URL is sufficient.
  const topic = explicitTopic ||
    deriveTopicHint({
      sourceClass: citation.source_class,
      url,
      title: citation.title || citation.label || citation.name,
    });

  return compactObject({
    id: compactString(citation.id || citation.paragraph_id || citation.uuid, 120),
    title: compactString(citation.title || citation.label || citation.name),
    url,
    source: compactString(citation.source, 80),
    source_class: compactString(citation.source_class, 80),
    topic,
    topic_id: compactString(citation.topic_id || citation.term_id || citation.category_id, 120),
    supported: Boolean(supported && url && isAllowedIlasUrl(url, siteBaseUrl)),
    source_kind: sourceKind,
  });
}

function summarizeCitationMetadata(data, siteBaseUrl, limit = 10) {
  const explicitSources = asArray(data.sources)
    .slice(0, limit)
    .map((citation) => normalizeCitationItem(citation, siteBaseUrl, 'sources', true))
    .filter((citation) => citation && Object.keys(citation).length > 0);

  const publicCitations = asArray(data.citations)
    .slice(0, limit)
    .map((citation) => {
      const explicitlySupported = citation?.supported === true || citation?.explicit === true;
      return normalizeCitationItem(citation, siteBaseUrl, 'citations', explicitlySupported);
    })
    .filter((citation) => citation && Object.keys(citation).length > 0);

  const supported = explicitSources.length > 0
    ? explicitSources
    : publicCitations.filter((citation) => citation.supported === true);

  return {
    explicit_sources: explicitSources,
    public_citations: publicCitations,
    supported,
    all: [...explicitSources, ...publicCitations],
  };
}

function collectDerivedCitationCandidates(data, siteBaseUrl) {
  const candidates = [];
  const push = (sourceKind, item) => {
    if (!item?.url) {
      return;
    }
    candidates.push(compactObject({
      title: compactString(item.title || item.label || item.name || item.question),
      url: toAbsoluteUrl(item.url, siteBaseUrl),
      source_kind: sourceKind,
      source_class: compactString(item.source_class, 80),
      topic: compactString(item.topic || item.topic_name || item.category, 120),
    }));
  };

  if (data.url) {
    push('response_url', { url: data.url, label: data.title || data.type });
  }
  push('primary_action', data.primary_action);
  asArray(data.secondary_actions).forEach((action) => push('secondary_action', action));
  asArray(data.links).forEach((link) => push('link', link));
  asArray(data.results).forEach((result) => push('result', result));

  const seen = new Set();
  return candidates.filter((candidate) => {
    const key = candidate.url;
    if (seen.has(key)) {
      return false;
    }
    seen.add(key);
    return true;
  });
}

function collectProviderLinks(data, siteBaseUrl) {
  const links = [];
  const push = (sourceKind, item) => {
    if (!item?.url) {
      return;
    }
    links.push(compactObject({
      label: compactString(item.label || item.title || item.name || item.question),
      url: toAbsoluteUrl(item.url, siteBaseUrl),
      source_kind: sourceKind,
      type: compactString(item.type, 80),
    }));
  };

  if (data.url) {
    push('response_url', { url: data.url, label: data.title || data.type });
  }
  push('primary_action', data.primary_action);
  asArray(data.secondary_actions).forEach((action) => push('secondary_action', action));
  asArray(data.links).forEach((link) => push('link', link));

  return links;
}

function collectUrls(items) {
  if (!Array.isArray(items)) {
    return [];
  }

  return Array.from(new Set(items
    .map((item) => item && typeof item === 'object' ? item.url : null)
    .filter((url) => typeof url === 'string' && url !== '')));
}

function inferFallbackUsed(data) {
  const fields = [
    data.type,
    data.response_mode,
    data.reason_code,
    data.decision_reason,
  ].map((value) => String(value || '').toLowerCase());

  return fields.some((value) =>
    value.includes('fallback') ||
    value.includes('clarify') ||
    value.includes('no_match') ||
    value.includes('no_results') ||
    value.includes('low_confidence') ||
    value.includes('low_retrieval') ||
    value.includes('unavailable')
  );
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

  [...asArray(data.sources), ...asArray(data.citations)].forEach((citation) => {
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

  // The public retrieval contract is the authoritative provenance record:
  // vector matches that corroborated lexical results are deduped out of the
  // visible result list (lexical priority) but the API still publishes
  // their source classes and counts on payload.retrieval. Honor them so
  // provenance assertions see contribution, not just display survival.
  asArray(data.retrieval?.source_classes).forEach((sourceClass) => {
    if (typeof sourceClass === 'string' && sourceClass.trim() !== '') {
      sourceClasses.add(sourceClass.trim());
    }
  });
  const contractVectorCount = Number(data.retrieval?.vector_result_count ?? 0);
  if (Number.isFinite(contractVectorCount) && contractVectorCount > vectorResultCount) {
    vectorResultCount = contractVectorCount;
  }

  return {
    result_source_classes: Array.from(sourceClasses).sort(),
    vector_result_count: vectorResultCount,
    lexical_result_count: lexicalResultCount,
    vector_citation_count: vectorCitationCount,
    lexical_citation_count: lexicalCitationCount,
  };
}

function summarizeClassification(value) {
  if (!value || typeof value !== 'object') {
    return null;
  }

  return compactObject({
    class: compactString(value.class || value.label || value.category, 80),
    category: compactString(value.category, 80),
    reason_code: compactString(value.reason_code || value.reason, 120),
    confidence: compactNumber(value.confidence),
    blocked: typeof value.blocked === 'boolean' ? value.blocked : null,
    in_scope: typeof value.in_scope === 'boolean' ? value.in_scope : null,
  });
}

function inferTopic(data) {
  const debug = data._debug && typeof data._debug === 'object' ? data._debug : {};
  const topic = data.topic && typeof data.topic === 'object' ? data.topic : {};
  const activeSelection = data.active_selection && typeof data.active_selection === 'object'
    ? data.active_selection
    : {};

  return compactObject({
    id: topic.id || activeSelection.button_id || debug.topic_id || null,
    name: topic.name || activeSelection.label || debug.topic_name || null,
    source: data.route_source || debug.intent_source || debug.route_source || null,
  });
}

function inferGroundingStatus(data, supportedCitationCount, derivedCitationCount) {
  const responseType = String(data.type || '').toLowerCase();
  const responseMode = String(data.response_mode || '').toLowerCase();
  const reasonCode = String(data.reason_code || '').toLowerCase();
  const requiresCitation = CITATION_REQUIRED_RESPONSE_TYPES.has(responseType);
  const safeNonAnswer = (
    responseMode.includes('clarify') ||
    responseType.includes('clarify') ||
    responseType.includes('refusal') ||
    responseType.includes('escalation') ||
    reasonCode.includes('safety') ||
    reasonCode.includes('out_of_scope')
  );

  if (supportedCitationCount > 0) {
    return 'supported';
  }
  if (safeNonAnswer) {
    return 'not_required';
  }
  if (requiresCitation && derivedCitationCount > 0) {
    return 'unsupported_link_or_result_only';
  }
  if (requiresCitation) {
    return 'citation_required_missing';
  }
  if (derivedCitationCount > 0) {
    return 'candidate_only';
  }
  return 'not_required';
}

function collectUnsupportedClaimFlags(data, grounded) {
  const text = String(data.message || '');
  const flags = [];
  if (!grounded && /\b(within|in)\s+\d+\s+(day|days|hour|hours|week|weeks)\b/i.test(text)) {
    flags.push('deadline_without_supported_citation');
  }
  if (!grounded && /\$\s?\d+|\b\d{3}[-.\s]\d{3}[-.\s]\d{4}\b/.test(text)) {
    flags.push('specific_fact_without_supported_citation');
  }
  if (/\b(you will win|guaranteed to win|i am your lawyer|as your lawyer)\b/i.test(text)) {
    flags.push('legal_boundary_overclaim');
  }
  return flags;
}

function inferRetrievalAttempted(payload, debug = {}, provenance = {}) {
  const publicDiagnostics = payload.meta && typeof payload.meta === 'object'
    ? payload.meta
    : (payload.diagnostics && typeof payload.diagnostics === 'object' ? payload.diagnostics : {});
  if (typeof publicDiagnostics.retrieval?.attempted === 'boolean') {
    return publicDiagnostics.retrieval.attempted;
  }
  if (typeof publicDiagnostics.retrieval?.used === 'boolean' && publicDiagnostics.retrieval.used) {
    return true;
  }
  if (typeof payload.retrieval?.vector_attempted === 'boolean' && payload.retrieval.vector_attempted) {
    return true;
  }
  if (
    typeof payload.retrieval?.lexical_result_count === 'number' &&
    payload.retrieval.lexical_result_count > 0
  ) {
    return true;
  }
  const trace = debug.retrieval_trace && typeof debug.retrieval_trace === 'object'
    ? debug.retrieval_trace
    : {};
  const explicitAttempt =
    trace.attempted ??
    trace.retrieval_attempted ??
    trace.vector_attempted ??
    trace.lexical_attempted ??
    null;
  if (typeof explicitAttempt === 'boolean') {
    return explicitAttempt;
  }

  if (
    asArray(payload.results).length > 0 ||
    asArray(payload.sources).length > 0 ||
    asArray(payload.citations).length > 0 ||
    Number(provenance.vector_result_count || 0) > 0 ||
    Number(provenance.lexical_result_count || 0) > 0 ||
    Number(provenance.vector_citation_count || 0) > 0 ||
    Number(provenance.lexical_citation_count || 0) > 0
  ) {
    return true;
  }

  const reasonText = [
    payload.reason_code,
    payload.decision_reason,
    payload.type,
    payload.response_mode,
  ]
    .map((value) => String(value || '').toLowerCase())
    .join(' ');

  if (/(retriev|search|no_results|low_retrieval|vector|lexical|citation_required)/.test(reasonText)) {
    return true;
  }

  return false;
}

function inferSafetyBlocked(payload, safetyClassification) {
  if (typeof safetyClassification?.blocked === 'boolean') {
    return safetyClassification.blocked;
  }
  // Public diagnostics envelope: an authoritative safety.blocked flag set by
  // the assistant trumps text/type heuristics.
  const publicDiagnostics = payload.meta && typeof payload.meta === 'object'
    ? payload.meta
    : (payload.diagnostics && typeof payload.diagnostics === 'object' ? payload.diagnostics : {});
  if (typeof publicDiagnostics.safety?.blocked === 'boolean') {
    return publicDiagnostics.safety.blocked;
  }

  const responseType = String(payload.type || '').toLowerCase();
  const responseMode = String(payload.response_mode || '').toLowerCase();
  const reasonCode = String(payload.reason_code || '').toLowerCase();
  const safetyClass = String(payload.safety_class || '').toLowerCase();

  return (
    responseType.includes('refusal') ||
    responseType.includes('escalation') ||
    responseMode.includes('refusal') ||
    reasonCode.includes('safety') ||
    reasonCode.includes('violence') ||
    reasonCode.includes('unsafe') ||
    safetyClass.includes('unsafe') ||
    safetyClass.includes('violence')
  );
}

function inferSafetyStage(payload, safetyBlocked, llmUsed) {
  const publicDiagnostics = payload.meta && typeof payload.meta === 'object'
    ? payload.meta
    : (payload.diagnostics && typeof payload.diagnostics === 'object' ? payload.diagnostics : {});
  const explicitStage = publicDiagnostics.safety?.stage;
  if (typeof explicitStage === 'string' && explicitStage !== '') {
    return explicitStage;
  }
  if (!safetyBlocked) {
    return llmUsed === true ? 'generation_allowed' : 'not_blocked';
  }
  return llmUsed === true ? 'generation_after_safety' : 'pre_generation_block';
}

function inferGenerationProvider(payload, debug = {}) {
  // Prefer explicit public diagnostics, then legacy debug, then top-level
  // shorthand fields. Order matters: a deployed assistant may emit the
  // structured diagnostics object publicly and skip the legacy fields.
  const publicDiagnostics = payload.meta && typeof payload.meta === 'object'
    ? payload.meta
    : (payload.diagnostics && typeof payload.diagnostics === 'object' ? payload.diagnostics : {});
  return (
    publicDiagnostics.generation?.provider ||
    payload.generation?.provider ||
    debug.llm_provider ||
    debug.generation?.provider ||
    payload.llm_provider ||
    payload.provider ||
    null
  );
}

function inferGenerationUsed(payload, debug = {}) {
  const publicDiagnostics = payload.meta && typeof payload.meta === 'object'
    ? payload.meta
    : (payload.diagnostics && typeof payload.diagnostics === 'object' ? payload.diagnostics : {});
  // Source-of-truth list, in priority order. The first source that returns a
  // boolean wins; null/undefined means "no opinion, keep looking".
  const candidates = [
    publicDiagnostics.generation?.used,
    payload.generation?.used,
    payload.llm_used,
    debug.llm_used,
    debug.generation?.used,
  ];
  for (const candidate of candidates) {
    if (typeof candidate === 'boolean') {
      return candidate;
    }
  }
  return null;
}

function buildIlasProviderMeta(data, siteBaseUrl = DEFAULT_SITE_BASE_URL, options = {}) {
  const payload = data && typeof data === 'object' ? data : {};
  const debug = payload._debug && typeof payload._debug === 'object' ? payload._debug : {};
  const provenance = summarizeRetrievalProvenance(payload);
  const primaryAction = summarizeAction(payload.primary_action, siteBaseUrl);
  const secondaryActions = summarizeActions(payload.secondary_actions, siteBaseUrl);
  const actionUrls = collectUrls([primaryAction, ...secondaryActions]);
  const links = collectProviderLinks(payload, siteBaseUrl);
  const linkUrls = collectUrls(links);
  const results = summarizeResults(payload.results, siteBaseUrl);
  const resultUrls = collectUrls(results);
  const citationMetadata = summarizeCitationMetadata(payload, siteBaseUrl);
  const derivedCitationCandidates = collectDerivedCitationCandidates(payload, siteBaseUrl);
  const supportedCitationCount = citationMetadata.supported.length;
  const derivedCitationCount = derivedCitationCandidates.length;
  const groundingStatus = inferGroundingStatus(payload, supportedCitationCount, derivedCitationCount);
  const grounded = groundingStatus === 'supported';
  const vectorUsed = (provenance.vector_result_count + provenance.vector_citation_count) > 0;
  const rerankMeta = debug.rerank_meta && typeof debug.rerank_meta === 'object'
    ? debug.rerank_meta
    : null;
  const requestId = payload.request_id || options.requestId || options.correlationId || null;
  const publicDiagnostics = payload.meta && typeof payload.meta === 'object'
    ? payload.meta
    : (payload.diagnostics && typeof payload.diagnostics === 'object' ? payload.diagnostics : {});
  const safetyClassification = summarizeClassification(
    payload.safety_classification ||
    publicDiagnostics.safety ||
    debug.safety_classification ||
    null
  );
  const outOfScopeClassification = summarizeClassification(
    payload.out_of_scope_classification ||
    payload.oos_classification ||
    publicDiagnostics.out_of_scope ||
    debug.oos_classification ||
    null
  );
  const retrievalAttempted = inferRetrievalAttempted(payload, debug, provenance);
  const generationProvider = inferGenerationProvider(payload, debug);
  const llmUsed = inferGenerationUsed(payload, debug);
  const genericFallback = inferGenericFallback(payload, debug);
  const safetyBlocked = inferSafetyBlocked(payload, safetyClassification);
  const safetyStage = inferSafetyStage(payload, safetyBlocked, llmUsed);
  const conversationIdHash = createSafeTraceId(options.conversationId);

  return compactObject({
    schema_version: ILAS_PROVIDER_META_SCHEMA_VERSION,
    provider_mode: options.providerMode || 'live_api',
    metadata_availability: {
      public_contract: true,
      debug_contract: Object.keys(debug).length > 0,
      safety_classification: safetyClassification ? 'debug_or_public' : 'unavailable',
      out_of_scope_classification: outOfScopeClassification ? 'debug_or_public' : 'unavailable',
      fallback_decision: debug.gate_decision ? 'debug' : 'public_inference',
      llm_fallback: Object.prototype.hasOwnProperty.call(debug, 'llm_used') ? 'debug' : 'unavailable',
      vector_usage: vectorUsed ? 'public_source_class' : 'unavailable',
      voyage_or_rerank: rerankMeta ? 'debug' : 'unavailable',
    },
    raw_response_text: String(payload.message || ''),
    normalized_response_text: normalizeResponseText(payload.message),
    response_type: payload.type || null,
    response_mode: payload.response_mode || null,
    reason_code: payload.reason_code || null,
    decision_reason: payload.decision_reason || null,
    confidence: normalizeConfidence(payload.confidence),
    route: compactObject({
      intent: payload.intent_selected || debug.intent_selected || null,
      intent_confidence: compactNumber(payload.intent_confidence || debug.intent_confidence),
      source: payload.route_source || debug.intent_source || null,
      topic: inferTopic(payload),
    }),
    citations: {
      all: citationMetadata.all,
      explicit_sources: citationMetadata.explicit_sources,
      public_citations: citationMetadata.public_citations,
      supported: citationMetadata.supported,
      derived_candidates: derivedCitationCandidates,
    },
    citations_count: supportedCitationCount,
    supported_citations_count: supportedCitationCount,
    citation_candidates_count: citationMetadata.all.length + derivedCitationCount,
    derived_citation_count: derivedCitationCount,
    unsupported_citation_candidates_count: Math.max(
      0,
      citationMetadata.public_citations.length + derivedCitationCount - supportedCitationCount
    ),
    has_supported_citation: supportedCitationCount > 0,
    grounded,
    grounding_status: groundingStatus,
    unsupported_claim_flags: collectUnsupportedClaimFlags(payload, grounded),
    links,
    retrieval_results: results,
    results_count: asArray(payload.results).length,
    result_source_classes: provenance.result_source_classes,
    result_urls: resultUrls,
    citation_urls: collectUrls(citationMetadata.supported),
    action_urls: actionUrls,
    link_urls: linkUrls,
    primary_action: primaryAction,
    secondary_actions: secondaryActions,
    fallback: {
      used: inferFallbackUsed(payload),
      decision: debug.gate_decision || null,
      reason_code: payload.reason_code || null,
      generic: genericFallback,
    },
    fallback_used: inferFallbackUsed(payload),
    generic_fallback: genericFallback,
    // Catch-all degraded markers — populated only by the controller's
    // graceful-degradation path. See AssistantApiController::message()
    // catch block. Smoke gates fail when these appear in normal output.
    escalation_type: payload.escalation_type || null,
    degraded: payload.degraded === true || publicDiagnostics?.degraded === true,
    llm_fallback: {
      used: llmUsed,
      provider: generationProvider,
      availability: Object.prototype.hasOwnProperty.call(debug, 'llm_used') ? 'debug' : 'unavailable',
    },
    llm_used: llmUsed,
    generation: {
      provider: generationProvider,
      used: llmUsed,
      expected: null,
      availability: generationProvider || llmUsed !== null ? 'debug_or_public' : 'unavailable',
    },
    vector_search: {
      used: vectorUsed,
      attempted: debug.retrieval_trace?.vector_attempted ?? null,
      result_count: provenance.vector_result_count,
      citation_count: provenance.vector_citation_count,
      source_classes: provenance.result_source_classes.filter((sourceClass) =>
        String(sourceClass).toLowerCase().includes('vector')
      ),
      status: vectorUsed ? 'used' : 'unknown',
    },
    vector_used: vectorUsed,
    voyage: {
      embeddings_used: vectorUsed ? true : null,
      rerank_used: rerankMeta ? Boolean(rerankMeta.applied) : null,
      rerank_model: rerankMeta?.model || null,
      fallback_reason: rerankMeta?.fallback_reason || null,
      availability: rerankMeta ? 'debug' : (vectorUsed ? 'inferred_from_vector_source_class' : 'unavailable'),
    },
    rerank_used: rerankMeta ? Boolean(rerankMeta.applied) : null,
    retrieval_contract: payload.retrieval && typeof payload.retrieval === 'object' ? payload.retrieval : null,
    retrieval_attempted: retrievalAttempted,
    safety_classification: safetyClassification,
    out_of_scope_classification: outOfScopeClassification,
    safety_class: payload.safety_class || safetyClassification?.class || payload.escalation_type || null,
    safety_reason_code: safetyClassification?.reason_code || payload.reason_code || null,
    safety: {
      blocked: safetyBlocked,
      stage: safetyStage,
      class: payload.safety_class || safetyClassification?.class || payload.escalation_type || null,
      reason_code: safetyClassification?.reason_code || payload.reason_code || null,
    },
    conversation_id_hash: conversationIdHash,
    trace: compactObject({
      request_id: requestId,
      correlation_id: options.correlationId || requestId,
      conversation_id_hash: conversationIdHash,
    }),
    transport: options.transportMeta || null,
    errors: asArray(options.errors).map((error) => compactObject({
      kind: error.kind || null,
      code: error.code || null,
      message: compactString(error.message, 220),
      phase: error.phase || null,
      status: error.status ?? null,
    })),
    lexical_result_count: provenance.lexical_result_count,
    vector_result_count: provenance.vector_result_count,
    lexical_citation_count: provenance.lexical_citation_count,
    vector_citation_count: provenance.vector_citation_count,
  });
}

function buildContractMeta(data, siteBaseUrl, options = {}) {
  const providerMeta = options.providerMeta || buildIlasProviderMeta(data, siteBaseUrl, options);

  return {
    metadata_schema_version: providerMeta.schema_version,
    message_text: compactString(providerMeta.raw_response_text, 500),
    confidence: providerMeta.confidence,
    results_count: providerMeta.results_count || 0,
    citations_count: providerMeta.supported_citations_count || 0,
    supported_citations_count: providerMeta.supported_citations_count || 0,
    citation_candidates_count: providerMeta.citation_candidates_count || 0,
    derived_citation_count: providerMeta.derived_citation_count || 0,
    unsupported_citation_candidates_count: providerMeta.unsupported_citation_candidates_count || 0,
    grounded: providerMeta.grounded === true,
    grounding_status: providerMeta.grounding_status || null,
    response_type: providerMeta.response_type || null,
    response_mode: providerMeta.response_mode || null,
    reason_code: providerMeta.reason_code || null,
    decision_reason: providerMeta.decision_reason || null,
    intent_selected: providerMeta.route?.intent || null,
    intent_confidence: providerMeta.route?.intent_confidence || null,
    route_source: providerMeta.route?.source || null,
    topic_id: providerMeta.route?.topic?.id || null,
    topic_name: providerMeta.route?.topic?.name || null,
    primary_action: providerMeta.primary_action || null,
    secondary_actions: providerMeta.secondary_actions || [],
    links: providerMeta.links || [],
    results: providerMeta.retrieval_results || [],
    citations: providerMeta.citations?.supported || [],
    citation_candidates: providerMeta.citations?.derived_candidates || [],
    action_urls: providerMeta.action_urls || [],
    link_urls: providerMeta.link_urls || [],
    result_urls: providerMeta.result_urls || [],
    citation_urls: providerMeta.citation_urls || [],
    fallback_used: providerMeta.fallback_used || false,
    generic_fallback: providerMeta.generic_fallback || false,
    // Catch-all degraded markers. The controller's graceful-degradation
    // path returns HTTP 200 with type=escalation,
    // escalation_type=internal_error_fallback, degraded=true. These two
    // fields are surfaced into contract_meta so smoke/quality eval gates
    // can hard-fail when degraded responses leak into normal output.
    escalation_type: providerMeta.escalation_type || null,
    degraded: providerMeta.degraded === true,
    llm_used: providerMeta.llm_used ?? null,
    generation: providerMeta.generation || null,
    vector_used: providerMeta.vector_used || false,
    rerank_used: providerMeta.rerank_used ?? null,
    retrieval_attempted: providerMeta.retrieval_attempted ?? null,
    safety_class: providerMeta.safety_class || null,
    safety_reason_code: providerMeta.safety_reason_code || null,
    safety: providerMeta.safety || null,
    conversation_id_hash: providerMeta.conversation_id_hash || providerMeta.trace?.conversation_id_hash || null,
    result_source_classes: providerMeta.result_source_classes || [],
    vector_result_count: providerMeta.vector_result_count || 0,
    lexical_result_count: providerMeta.lexical_result_count || 0,
    vector_citation_count: providerMeta.vector_citation_count || 0,
    lexical_citation_count: providerMeta.lexical_citation_count || 0,
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

function renderAssistantOutput(data, siteBaseUrl = DEFAULT_SITE_BASE_URL, options = {}) {
  const humanParts = buildHumanReadableOutput(data, siteBaseUrl);
  const providerMeta = options.providerMeta || buildIlasProviderMeta(data, siteBaseUrl, options);
  const contractMeta = buildContractMeta(data, siteBaseUrl, { ...options, providerMeta });
  const humanOutput =
    absolutizeRelativePathsInText(humanParts.join('\n\n'), siteBaseUrl) || 'No assistant message returned.';
  return `${humanOutput}\n\n${CONTRACT_META_PREFIX}${JSON.stringify(contractMeta)}\n\n${ILAS_PROVIDER_META_PREFIX}${JSON.stringify(providerMeta)}`;
}

function createTransportOptions(options = {}) {
  return {
    assistantUrl: options.assistantUrl || process.env.ILAS_ASSISTANT_URL || '',
    siteBaseUrl: options.siteBaseUrl || process.env.ILAS_SITE_BASE_URL || DEFAULT_SITE_BASE_URL,
    requestDelayMs: normalizeInteger(options.requestDelayMs ?? process.env.ILAS_REQUEST_DELAY_MS, 0),
    requestTimeoutMs: normalizeInteger(
      options.requestTimeoutMs ?? process.env.ILAS_REQUEST_TIMEOUT_MS,
      DEFAULT_REQUEST_TIMEOUT_MS
    ),
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

function createTransportMeta(existingCookie = null, existingToken = null) {
  return {
    http_status: null,
    bootstrap: {
      attempted: false,
      success: Boolean(existingToken),
      endpoint: null,
      attempts: 0,
      refreshed_after_403: false,
      csrf_token_present: Boolean(existingToken),
      session_cookie_present: Boolean(existingCookie),
      cookie_reused: Boolean(existingCookie),
    },
    retries: {
      csrf_403: 0,
      rate_limit: 0,
    },
    errors: [],
  };
}

function mergeBootstrapMetadata(transportMeta, bootstrapMeta, extra = {}) {
  if (!transportMeta || !bootstrapMeta) {
    return;
  }
  transportMeta.bootstrap = {
    ...transportMeta.bootstrap,
    ...bootstrapMeta,
    ...extra,
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
    const fetchOptions = { ...options };
    const timeoutMs = this.options.requestTimeoutMs;
    let timeoutId = null;
    let removeAbortListener = null;
    let timeoutError = null;

    if (Number.isFinite(timeoutMs) && timeoutMs > 0) {
      const timeoutController = new AbortController();
      timeoutError = Object.assign(new Error(`Request timed out after ${timeoutMs}ms`), { code: 'ETIMEDOUT' });

      if (fetchOptions.signal) {
        if (fetchOptions.signal.aborted) {
          timeoutController.abort(fetchOptions.signal.reason);
        } else {
          const forwardAbort = () => timeoutController.abort(fetchOptions.signal.reason);
          fetchOptions.signal.addEventListener('abort', forwardAbort, { once: true });
          removeAbortListener = () => fetchOptions.signal.removeEventListener('abort', forwardAbort);
        }
      }

      timeoutId = setTimeout(() => timeoutController.abort(timeoutError), timeoutMs);
      fetchOptions.signal = timeoutController.signal;
    }

    try {
      const response = await this.fetchImpl(url, fetchOptions);
      return { ok: true, response };
    } catch (err) {
      if (fetchOptions.signal?.aborted && fetchOptions.signal.reason === timeoutError) {
        return {
          ok: false,
          error: createStructuredError(
            'connectivity',
            'timeout',
            timeoutError.message,
            {
              phase,
              url,
              request_timeout_ms: timeoutMs,
              transport_code: timeoutError.code,
            }
          ),
        };
      }

      return {
        ok: false,
        error: classifyFetchError(err, phase, url),
      };
    } finally {
      if (timeoutId !== null) {
        clearTimeout(timeoutId);
      }
      removeAbortListener?.();
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
    let attempts = 0;
    for (const tokenUrl of urls) {
      attempts++;
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
        metadata: {
          attempted: true,
          success: true,
          endpoint: tokenUrl,
          attempts,
          csrf_token_present: true,
          session_cookie_present: Boolean(this.cookie),
          cookie_reused: Boolean(this.cookie && !cookiePair),
        },
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
      metadata: {
        attempted: true,
        success: false,
        endpoint: null,
        attempts,
        csrf_token_present: false,
        session_cookie_present: Boolean(this.cookie),
        cookie_reused: Boolean(this.cookie),
      },
    };
  }

  async callMessageApi({ question, conversationId, history, requestContext }) {
    if (!this.messageUrl) {
      this.resolveUrls();
    }

    const transportMeta = createTransportMeta(this.cookie, this.csrfToken);

    if (!this.csrfToken) {
      const tokenResult = await this.fetchCsrfToken();
      if (!tokenResult.ok) {
        mergeBootstrapMetadata(transportMeta, tokenResult.metadata);
        transportMeta.errors.push(tokenResult.error);
        return { ...tokenResult, transport: transportMeta };
      }
      mergeBootstrapMetadata(transportMeta, tokenResult.metadata);
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
    if (this.options.evalRunId) {
      headers['X-ILAS-Eval-Run-ID'] = this.options.evalRunId;
    }
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
        transportMeta.errors.push(result.error);
        return { ...result, transport: transportMeta };
      }

      response = result.response;
      transportMeta.http_status = response.status;

      if (response.status === 403 && attempt === 0) {
        transportMeta.retries.csrf_403 += 1;
        const tokenRefresh = await this.fetchCsrfToken();
        if (!tokenRefresh.ok) {
          mergeBootstrapMetadata(transportMeta, tokenRefresh.metadata, {
            refreshed_after_403: true,
          });
          const error = createStructuredError(
            'connectivity',
            'csrf_retry_failed',
            tokenRefresh.error.message,
            {
              phase: 'message_post_retry',
              retry_error_code: tokenRefresh.error.code,
            }
          );
          transportMeta.errors.push(error);
          return {
            ok: false,
            error,
            transport: transportMeta,
          };
        }

        mergeBootstrapMetadata(transportMeta, tokenRefresh.metadata, {
          refreshed_after_403: true,
        });
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
          const error = createStructuredError(
            'capacity',
            'rate_limited',
            'HTTP 429 rate limit received',
            {
              phase: 'message_post',
              status: 429,
              retry_after: retryAfter || null,
            }
          );
          transportMeta.errors.push(error);
          return {
            ok: false,
            error,
            transport: transportMeta,
          };
        }

        attempt++;
        transportMeta.retries.rate_limit += 1;
        if (attempt > this.options.max429Retries) {
          const error = createStructuredError(
            'capacity',
            'rate_limited',
            `HTTP 429 after ${this.options.max429Retries} retries`,
            {
              phase: 'message_post',
              status: 429,
              retry_after: retryAfter || null,
            }
          );
          transportMeta.errors.push(error);
          return {
            ok: false,
            error,
            transport: transportMeta,
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
      const error = createStructuredError(
        'connectivity',
        'message_http',
        `HTTP ${response.status}: ${text.slice(0, 300)}`,
        {
          phase: 'message_post',
          status: response.status,
        }
      );
      transportMeta.errors.push(error);
      return {
        ok: false,
        error,
        transport: transportMeta,
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
      const error = createStructuredError(
        'connectivity',
        'invalid_json',
        'Invalid JSON response',
        {
          phase: 'message_post',
          status: response.status,
        }
      );
      transportMeta.errors.push(error);
      return {
        ok: false,
        error,
        transport: transportMeta,
      };
    }

    return {
      ok: true,
      data,
      status: response.status,
      transport: transportMeta,
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
  CONTRACT_META_PREFIX,
  DEFAULT_EXPECTED_REQUEST_TOTAL,
  DEFAULT_SITE_BASE_URL,
  ILAS_PROVIDER_META_PREFIX,
  ILAS_PROVIDER_META_SCHEMA_VERSION,
  IlasLiveTransport,
  STRUCTURED_ERROR_PREFIX,
  absolutizeRelativePathsInText,
  buildContractMeta,
  buildIlasProviderMeta,
  classifyFetchError,
  collectProviderLinks,
  createSerializedPacer,
  createStructuredError,
  deterministicUuidV4,
  extractFirstSetCookieValue,
  formatStructuredError,
  parseStructuredError,
  renderAssistantOutput,
  summarizeCitationMetadata,
  summarizeAssistantResponse,
  summarizeRetrievalProvenance,
  toAbsoluteUrl,
};
