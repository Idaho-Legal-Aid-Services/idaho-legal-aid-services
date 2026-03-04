/**
 * ILAS Live Assistant — Promptfoo custom provider
 *
 * Handles Drupal CSRF token acquisition and POSTs questions to the
 * live assistant endpoint at /assistant/api/message.
 *
 * Environment:
 *   ILAS_ASSISTANT_URL — full URL to the message endpoint
 *     e.g. https://idaholegalaid.org/assistant/api/message
 *   ILAS_SITE_BASE_URL — optional base for turning relative URLs into absolute
 *     default: https://idaholegalaid.org
 *   ILAS_REQUEST_DELAY_MS — minimum ms between requests (default: 0)
 *     Set to 31000 for live site (120/hour limit → ~2/min pacing)
 *     Set to 0 for local DDEV (no rate limits)
 *   ILAS_429_MAX_RETRIES — max retries on 429 (default: 5)
 *   ILAS_429_BASE_WAIT_MS — base wait on first 429 retry (default: 65000)
 *
 * No external dependencies — uses Node.js 18+ built-in fetch and crypto.
 */

const crypto = require('node:crypto');

// --- Rate-limit pacing ---
const REQUEST_DELAY_MS = parseInt(process.env.ILAS_REQUEST_DELAY_MS || '0', 10);
const MAX_429_RETRIES = parseInt(process.env.ILAS_429_MAX_RETRIES || '5', 10);
const BASE_429_WAIT_MS = parseInt(process.env.ILAS_429_BASE_WAIT_MS || '65000', 10);

/** Shared timestamp of last successful request (across all provider instances). */
let lastRequestTime = 0;
let requestCount = 0;

function sleep(ms) {
  return new Promise((resolve) => setTimeout(resolve, ms));
}

/** Wait until the minimum inter-request delay has elapsed. */
async function paceRequest() {
  if (REQUEST_DELAY_MS <= 0) return;
  const now = Date.now();
  const elapsed = now - lastRequestTime;
  if (elapsed < REQUEST_DELAY_MS) {
    const waitMs = REQUEST_DELAY_MS - elapsed;
    await sleep(waitMs);
  }
  lastRequestTime = Date.now();
}

const DEFAULT_SITE_BASE_URL = 'https://idaholegalaid.org';
const SITE_BASE_URL = process.env.ILAS_SITE_BASE_URL || DEFAULT_SITE_BASE_URL;

function toAbsoluteUrl(u) {
  if (!u) return u;

  // Already absolute?
  try {
    // eslint-disable-next-line no-new
    new URL(u);
    return u;
  } catch (_) {
    // Relative -> absolute
    try {
      return new URL(u, SITE_BASE_URL).toString();
    } catch (e) {
      // If it's not a valid URL at all, return original string
      return u;
    }
  }
}

/**
 * Convert any "/relative/path" occurrences inside arbitrary text to absolute URLs.
 * This is what stops Promptfoo from trying to "sanitize" relative paths and
 * complaining that they're invalid URLs.
 */
function absolutizeRelativePathsInText(text) {
  if (!text || typeof text !== 'string') return text;

  // Replace occurrences of /something (not //something) that are word-ish paths.
  // We keep the preceding delimiter (space, punctuation, start-of-string) so
  // we don't glue words together.
  //
  // Examples converted:
  //   "Apply here: /apply-for-help" -> "Apply here: https://idaholegalaid.org/apply-for-help"
  //   "(See /services.)" -> "(See https://idaholegalaid.org/services.)"
  //
  // Not converted:
  //   "//cdn.example.com" (protocol-relative)
  //   "/ " (bare slash)
  return text.replace(
    /(^|[\s([{"'`])\/(?!\/)([A-Za-z0-9][A-Za-z0-9/_\-]*)(?=($|[\s)\]}",'`.!?;:]))/g,
    (match, prefix, path) => `${prefix}${toAbsoluteUrl('/' + path)}`
  );
}

function extractFirstSetCookieValue(setCookieHeader) {
  // Node fetch returns a single "set-cookie" header string (often only one cookie in our case).
  // We only need the first cookie key=value portion before ';'.
  if (!setCookieHeader) return null;
  const first = setCookieHeader.split('\n')[0] || setCookieHeader;
  const cookiePair = first.split(';')[0]?.trim();
  return cookiePair || null;
}

class IlasLiveProvider {
  constructor(options = {}) {
    this.providerId = options.id || 'ilas-live';
    this.baseUrl = null;
    this.messageUrl = null;
    this.csrfToken = null;

    // For anonymous session continuity (Drupal CSRF tokens are session-scoped)
    this.cookie = null;
  }

  id() {
    return this.providerId;
  }

  /** Derive base URL and message URL from the environment variable. */
  _resolveUrls() {
    const envUrl = process.env.ILAS_ASSISTANT_URL;
    if (!envUrl) {
      throw new Error(
        'ILAS_ASSISTANT_URL is not set. ' +
          'Export it before running, e.g.: export ILAS_ASSISTANT_URL=https://idaholegalaid.org/assistant/api/message'
      );
    }
    const parsed = new URL(envUrl);
    this.baseUrl = `${parsed.protocol}//${parsed.host}`;
    this.messageUrl = envUrl;
  }

  /** Fetch a fresh CSRF token from /session/token (captures anonymous session cookie). */
  async _fetchCsrfToken() {
    const tokenUrl = `${this.baseUrl}/session/token`;
    const res = await fetch(tokenUrl, {
      method: 'GET',
      headers: { Accept: 'text/plain' },
    });

    if (!res.ok) {
      throw new Error(`CSRF token fetch failed: ${res.status} ${res.statusText}`);
    }

    // Capture session cookie if present (common for Drupal)
    const setCookie = res.headers.get('set-cookie');
    const cookiePair = extractFirstSetCookieValue(setCookie);
    if (cookiePair) {
      this.cookie = cookiePair;
    }

    this.csrfToken = (await res.text()).trim();
  }

  _getConversationId(prompt, context) {
    // Try common locations promptfoo may pass metadata/vars in.
    const v = context?.vars || {};
    const m =
      context?.metadata ||
      context?.test?.metadata ||
      context?.testCase?.metadata ||
      v?.metadata ||
      {};

    return (
      m.conversationId ||
      v.conversation_id ||
      v.conversationId ||
      crypto.randomUUID()
    );
  }

  async callApi(prompt, context) {
    // Lazy-init URLs on first call.
    if (!this.messageUrl) {
      this._resolveUrls();
    }

    // Fetch CSRF token on first call.
    if (!this.csrfToken) {
      await this._fetchCsrfToken();
    }

    // Use the raw question variable (not the rendered chat prompt).
    const question = context?.vars?.question || prompt;

    const conversationId = this._getConversationId(prompt, context);

    // Use vars.history if present (multi-turn), otherwise start fresh.
    const priorHistory = Array.isArray(context?.vars?.history)
      ? context.vars.history
      : [];
    const history = [...priorHistory, { role: 'user', content: question }];

    const body = JSON.stringify({
      message: question,
      conversation_id: conversationId,
      context: { history },
    });

    // --- Pace request to respect rate limits ---
    await paceRequest();

    const headers = {
      'Content-Type': 'application/json',
      Accept: 'application/json',
      'X-CSRF-Token': this.csrfToken,
    };

    // If we captured an anonymous session cookie from /session/token, send it
    if (this.cookie) {
      headers.Cookie = this.cookie;
    }

    let res;
    let attempt = 0;

    // eslint-disable-next-line no-constant-condition
    while (true) {
      try {
        res = await fetch(this.messageUrl, { method: 'POST', headers, body });
      } catch (err) {
        return { error: `Network error: ${err?.message || String(err)}` };
      }

      // On 403 (CSRF expired), refresh token and retry once.
      if (res.status === 403 && attempt === 0) {
        try {
          await this._fetchCsrfToken();
          headers['X-CSRF-Token'] = this.csrfToken;
          if (this.cookie) headers.Cookie = this.cookie;
          res = await fetch(this.messageUrl, { method: 'POST', headers, body });
        } catch (retryErr) {
          return { error: `CSRF retry failed: ${retryErr?.message || String(retryErr)}` };
        }
      }

      // Rate limit — backoff and retry.
      if (res.status === 429) {
        attempt++;
        if (attempt > MAX_429_RETRIES) {
          return { error: `Rate limited (429) after ${MAX_429_RETRIES} retries. Giving up.` };
        }
        const retryAfter = res.headers.get('Retry-After');
        const waitMs = retryAfter
          ? parseInt(retryAfter, 10) * 1000
          : BASE_429_WAIT_MS * Math.pow(1.5, attempt - 1);
        requestCount++;
        const shortQ = question.length > 40 ? question.slice(0, 40) + '...' : question;
        console.log(
          `[${requestCount}] 429 on "${shortQ}" — retry ${attempt}/${MAX_429_RETRIES}, waiting ${Math.round(waitMs / 1000)}s`
        );
        await sleep(waitMs);
        lastRequestTime = Date.now();
        continue;
      }

      break; // Not a 429 — proceed
    }

    // Other HTTP errors.
    if (!res.ok) {
      const text = await res.text().catch(() => '');
      return { error: `HTTP ${res.status}: ${text.slice(0, 300)}` };
    }

    // Log progress.
    requestCount++;
    const shortQ = question.length > 50 ? question.slice(0, 50) + '...' : question;
    const meta = context?.test?.metadata || context?.metadata || {};
    const turnInfo = meta.turn && meta.totalTurns ? ` T${meta.turn}/${meta.totalTurns}` : '';
    const convoName = meta.conversationName || '';
    console.log(`[${requestCount}/292] ${convoName}${turnInfo} — "${shortQ}"`);


    let data;
    try {
      data = await res.json();
    } catch (err) {
      const text = await res.text().catch(() => '');
      return {
        error: `Invalid JSON response: ${err?.message || String(err)} — ${text.slice(0, 200)}`,
      };
    }

    // Build rich output: message + result titles/answers + actions + caveat.
    // The assistant returns structured data across multiple fields; concatenating
    // them gives assertions access to the full response content.
    const parts = [];

    if (data.message) {
      parts.push(absolutizeRelativePathsInText(data.message));
    }

    if (data.url) {
      parts.push(`[Navigate: ${toAbsoluteUrl(data.url)}]`);
    }

    if (Array.isArray(data.results)) {
      for (const r of data.results) {
        if (r.title) parts.push(absolutizeRelativePathsInText(`Result: ${r.title}`));
        if (r.question && r.question !== r.title) {
          parts.push(absolutizeRelativePathsInText(`Q: ${r.question}`));
        }
        if (r.answer) parts.push(absolutizeRelativePathsInText(r.answer));
        if (r.url) parts.push(`[Result URL: ${toAbsoluteUrl(r.url)}]`);
      }
    }

    if (Array.isArray(data.secondary_actions)) {
      for (const a of data.secondary_actions) {
        if (a.label) {
          parts.push(`Action: ${absolutizeRelativePathsInText(a.label)} (${toAbsoluteUrl(a.url)})`);
        }
      }
    }

    if (Array.isArray(data.links)) {
      for (const link of data.links) {
        if (link.label) {
          parts.push(`${absolutizeRelativePathsInText(link.label)} (${toAbsoluteUrl(link.url)})`);
        }
      }
    }

    if (data.primary_action?.label) {
      parts.push(
        `Primary: ${absolutizeRelativePathsInText(data.primary_action.label)} (${toAbsoluteUrl(
          data.primary_action.url
        )})`
      );
    }

    if (data.caveat) {
      parts.push(absolutizeRelativePathsInText(data.caveat));
    }

    const contractMeta = {
      confidence: typeof data.confidence === 'number' ? data.confidence : null,
      citations_count: Array.isArray(data.citations)
        ? data.citations.length
        : (Array.isArray(data.sources) ? data.sources.length : 0),
      response_type: data.type || null,
      response_mode: data.response_mode || null,
      reason_code: data.reason_code || null,
      decision_reason: data.decision_reason || null,
    };

    // Final safety pass for human-readable content only.
    const humanOutput = absolutizeRelativePathsInText(parts.join('\n\n')) || JSON.stringify(data);
    const output = `${humanOutput}\n\n[contract_meta]${JSON.stringify(contractMeta)}`;

    return {
      output,
      tokenUsage: {},
    };
  }
}

module.exports = IlasLiveProvider;
