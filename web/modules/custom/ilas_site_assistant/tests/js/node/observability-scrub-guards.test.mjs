/**
 * @file
 * Recursion-guard coverage for the second scrubValue() call site (PHP-AJ).
 *
 * observability-noise-filter.test.mjs proves the Sentry event processor
 * survives cyclic / over-deep envelopes. This file proves the same guards
 * protect emitAssistantError(), which scrubs the ilas:assistant:error detail
 * before capture, and that wide-but-shallow input is bounded too.
 *
 * Run: npm run test:assistant:js
 */

import assert from 'node:assert';
import { describe, test } from 'node:test';

import { loadObservability } from './_harness.mjs';

function makeSentry() {
  const recorded = {
    tags: null,
    context: null,
    captureArgs: null,
    processor: null,
  };

  const stub = {
    setTags() {},
    addEventProcessor(fn) {
      recorded.processor = fn;
    },
    withScope(callback) {
      callback({
        setTags(tags) {
          recorded.tags = tags;
        },
        setContext(name, payload) {
          recorded.context = { name, payload };
        },
        setFingerprint() {},
      });
    },
    captureMessage(message, level) {
      recorded.captureArgs = [message, level];
      return 'browser-event-1';
    },
  };

  return { stub, recorded };
}

async function bootstrap() {
  const { stub, recorded } = makeSentry();
  const window = await loadObservability({
    settings: { sentry: { browserEnabled: true, replayEnabled: false } },
    sentry: () => stub,
  });
  return { window, recorded };
}

describe('observability.js scrubber recursion guards on the assistant error path (PHP-AJ)', () => {
  test('cyclic ilas:assistant:error detail is captured with [Circular] instead of throwing', async () => {
    const { window, recorded } = await bootstrap();

    const detail = { feature: 'quick_action', surface: 'assistant-widget', status: 0, contact: 'jane@example.com' };
    detail.self = detail;
    detail.trail = [detail, { back: detail }];

    window.dispatchEvent(new window.CustomEvent('ilas:assistant:error', { detail }));

    assert.deepEqual(recorded.captureArgs, ['AILA browser error: quick_action (network)', 'error']);
    assert.equal(recorded.context.name, 'assistant_error');
    assert.equal(recorded.context.payload.feature, 'quick_action');
    assert.equal(recorded.context.payload.contact, '[REDACTED-EMAIL]');
    assert.equal(recorded.context.payload.self, '[Circular]');
    assert.deepEqual(recorded.context.payload.trail, ['[Circular]', { back: '[Circular]' }]);
    assert.equal(recorded.tags.assistant_feature, 'quick_action');
    assert.equal(recorded.tags.error_code, 'network');
  });

  test('over-deep ilas:assistant:error detail is truncated, not recursed forever', async () => {
    const { window, recorded } = await bootstrap();

    let deep = { leaf: 'end' };
    for (let i = 0; i < 20; i++) {
      deep = { child: deep };
    }
    window.dispatchEvent(new window.CustomEvent('ilas:assistant:error', {
      detail: { feature: 'message_send', status: 500, deep },
    }));

    assert.deepEqual(recorded.captureArgs, ['AILA browser error: message_send (http_500)', 'error']);
    let cursor = recorded.context.payload.deep;
    let hops = 0;
    while (cursor && typeof cursor === 'object') {
      cursor = cursor.child;
      hops++;
    }
    assert.equal(cursor, '[Truncated]');
    assert.ok(hops > 0 && hops < 20, `truncated after ${hops} hops`);
  });

  test('wide but shallow input is scrubbed in full without throwing', async () => {
    const { window, recorded } = await bootstrap();
    const wide = Array.from({ length: 6000 }, (_, i) => ({ i, who: `u${i}@example.com` }));

    window.dispatchEvent(new window.CustomEvent('ilas:assistant:error', {
      detail: { feature: 'quick_action', status: 0, wide },
    }));

    assert.equal(recorded.context.payload.wide.length, 6000);
    assert.deepEqual(recorded.context.payload.wide[0], { i: 0, who: '[REDACTED-EMAIL]' });
    assert.deepEqual(recorded.context.payload.wide[5999], { i: 5999, who: '[REDACTED-EMAIL]' });

    // The event processor sees the same shape via breadcrumbs; it must not throw either.
    const processed = recorded.processor({ breadcrumbs: [{ category: 'console', data: { arguments: [wide] } }] });
    assert.equal(processed.breadcrumbs[0].data.arguments[0].length, 6000);
  });
});
