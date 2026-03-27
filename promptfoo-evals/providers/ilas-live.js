/**
 * ILAS Live Assistant — Promptfoo custom provider
 *
 * Shared transport/runtime lives in `promptfoo-evals/lib/ilas-live-shared.js`.
 * That module retains the assistant session bootstrap fallback chain:
 *   - /assistant/api/session/bootstrap
 *   - /session/token
 * and appends `[contract_meta]` to rendered provider output.
 */

const {
  DEFAULT_EXPECTED_REQUEST_TOTAL,
  IlasLiveTransport,
  deterministicUuidV4,
  formatStructuredError,
  renderAssistantOutput,
} = require('../lib/ilas-live-shared');
const crypto = require('node:crypto');

class IlasLiveProvider {
  constructor(options = {}) {
    this.providerId = options.id || 'ilas-live';
    this.transport = new IlasLiveTransport({
      ...options,
      expectedRequestTotal: process.env.ILAS_EXPECTED_REQUEST_TOTAL || DEFAULT_EXPECTED_REQUEST_TOTAL,
      silent: true,
    });
    this.evalRunId = (process.env.ILAS_EVAL_RUN_ID || '').trim();
  }

  id() {
    return this.providerId;
  }

  getConversationId(prompt, context) {
    const vars = context?.vars || {};
    const metadata =
      context?.metadata ||
      context?.test?.metadata ||
      context?.testCase?.metadata ||
      vars?.metadata ||
      {};

    const explicitConversationId =
      metadata.conversationId ||
      vars.conversation_id ||
      vars.conversationId ||
      null;

    if (!this.evalRunId) {
      return explicitConversationId || crypto.randomUUID();
    }

    const stableFallbackKey = [
      vars.scenario_id,
      metadata.conversationName,
      metadata.turn,
      context?.testIdx,
      context?.promptIdx,
      vars.question || prompt,
    ]
      .filter((value) => value !== null && value !== undefined && value !== '')
      .join('|');

    const baseKey = explicitConversationId || stableFallbackKey || crypto.randomUUID();
    return deterministicUuidV4(`${this.evalRunId}:${baseKey}`);
  }

  logProgress(question, context) {
    const meta = context?.test?.metadata || context?.metadata || {};
    const turnInfo = meta.turn && meta.totalTurns ? ` T${meta.turn}/${meta.totalTurns}` : '';
    const convoName = meta.conversationName || '';
    const shortQ = question.length > 50 ? `${question.slice(0, 50)}...` : question;
    if (convoName || turnInfo) {
      console.log(`[promptfoo] ${convoName}${turnInfo} - "${shortQ}"`);
    }
  }

  async callApi(prompt, context) {
    this.transport.resolveUrls();

    const question = context?.vars?.question || prompt;
    const conversationId = this.getConversationId(prompt, context);
    const priorHistory = Array.isArray(context?.vars?.history) ? context.vars.history : [];
    const requestContext =
      context?.vars?.request_context && typeof context.vars.request_context === 'object'
        ? context.vars.request_context
        : undefined;
    const history = [...priorHistory, { role: 'user', content: question }];

    this.logProgress(question, context);

    const result = await this.transport.callMessageApi({
      question,
      conversationId,
      history,
      requestContext,
    });

    if (!result.ok) {
      return { error: formatStructuredError(result.error) };
    }

    return {
      output: renderAssistantOutput(result.data, this.transport.options.siteBaseUrl),
      tokenUsage: {},
    };
  }
}

module.exports = IlasLiveProvider;
