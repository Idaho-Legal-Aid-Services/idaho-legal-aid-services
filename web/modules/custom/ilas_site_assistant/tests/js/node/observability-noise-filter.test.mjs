/**
 * @file
 * observability.js Sentry event processor: the isExtensionNoise filter and
 * the envelope-aware scrubber (scrubEvent / scrubValue).
 *
 * Coverage:
 *  1. Site-owned frames pass through (idaholegalaid.org, /modules/, /themes/)
 *  2. Known noise patterns are dropped (runtime.sendMessage, ResizeObserver, etc.)
 *  3. All-masked webkit-masked-url://hidden/ frames with no site frames → dropped
 *  4. Mixed masked + site-owned frames → kept
 *  5. Empty stack trace with unknown message → kept
 *  6. Event with no exception → kept (unless message matches noise pattern)
 *  7. Template fields (message, logentry, breadcrumb messages, fingerprint)
 *     survive scrubbing while payload keys stay redacted (PHP-1M)
 *  8. Cyclic / over-deep input cannot blow the stack (PHP-AJ)
 */

import { test, describe } from 'node:test';
import assert from 'node:assert/strict';

import { loadObservability, assertPlainEqual } from './_harness.mjs';

async function bootstrap(userAgent) {
  let eventProcessor = null;
  await loadObservability({
    userAgent,
    settings: {
      environment: 'test',
      pantheonEnv: 'test',
      siteName: 'test',
      sentry: { browserEnabled: true },
      assistant: { name: 'aila' },
    },
    sentry: () => ({
      setTags: () => {},
      addEventProcessor: (fn) => { eventProcessor = fn; },
    }),
  });
  return eventProcessor;
}

const FB_IOS_UA = 'Mozilla/5.0 (iPhone; CPU iPhone OS 26_3_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148 [FBAN/FBIOS;FBAV/553.0.0.0.0;FBBV/000000000]';
const CHROME_IOS_UA = 'Mozilla/5.0 (iPhone; CPU iPhone OS 26_6_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) CriOS/152.0.7977.0 Mobile/15E148 Safari/604.1';
const GOOGLE_APP_UA = 'Mozilla/5.0 (iPhone; CPU iPhone OS 26_6_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) GSA/380.0.0 Mobile/15E148 Safari/604.1';
const SAFARI_IOS_UA = 'Mozilla/5.0 (iPhone; CPU iPhone OS 26_3_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.0 Mobile/15E148 Safari/604.1';

function makeEvent(opts) {
  opts = opts || {};
  const event = {};
  if (opts.message) {
    event.message = opts.message;
  }
  if (opts.frames || opts.errorValue || opts.errorType) {
    event.exception = {
      values: [{
        value: opts.errorValue || 'some error',
        type: opts.errorType !== undefined ? opts.errorType : 'TypeError',
        stacktrace: { frames: opts.frames || [] },
      }],
    };
  }
  return event;
}

describe('observability.js noise filter', () => {
  test('event processor is installed', async () => {
    assert.equal(typeof (await bootstrap()), 'function');
  });

  // 1. Site-owned frames pass through.
  test('keeps errors with idaholegalaid.org frames', async () => {
    const result = (await bootstrap())(makeEvent({
      errorValue: 'Cannot read property x of undefined',
      frames: [{ filename: 'https://idaholegalaid.org/modules/custom/ilas_site_assistant/js/assistant-widget.js' }],
    }));
    assert.notEqual(result, null);
  });

  test('keeps errors with /modules/ frames', async () => {
    const result = (await bootstrap())(makeEvent({
      errorValue: 'Something broke',
      frames: [{ filename: '/modules/custom/ilas_site_assistant/js/observability.js' }],
    }));
    assert.notEqual(result, null);
  });

  test('keeps errors with /themes/ frames', async () => {
    const result = (await bootstrap())(makeEvent({
      errorValue: 'Something broke',
      frames: [{ filename: '/themes/custom/b5subtheme/js/some-script.js' }],
    }));
    assert.notEqual(result, null);
  });

  // 2. Known noise patterns are dropped.
  test('drops runtime.sendMessage errors', async () => {
    const result = (await bootstrap())(makeEvent({
      errorValue: 'runtime.sendMessage failed',
      frames: [{ filename: 'chrome-extension://abc123/content.js' }],
    }));
    assert.equal(result, null);
  });

  test('drops ResizeObserver loop errors', async () => {
    const result = (await bootstrap())(makeEvent({
      errorValue: 'ResizeObserver loop completed with undelivered notifications',
      frames: [],
    }));
    assert.equal(result, null);
  });

  test('drops "Script error." plain message events', async () => {
    assert.equal((await bootstrap())(makeEvent({ message: 'Script error.' })), null);
  });

  // 3. All webkit-masked-url://hidden/ frames → dropped as third-party noise.
  test('drops events where ALL frames are webkit-masked-url://hidden/', async () => {
    const result = (await bootstrap())(makeEvent({
      errorValue: "undefined is not an object (evaluating 'h.data')",
      errorType: 'TypeError',
      frames: [
        { filename: 'webkit-masked-url://hidden/', function: 's', lineno: 1, colno: 4812847 },
        { filename: 'webkit-masked-url://hidden/', function: 'r', lineno: 1, colno: 4812644 },
        { filename: 'webkit-masked-url://hidden/', function: 's', lineno: 1, colno: 4809372 },
        { filename: 'webkit-masked-url://hidden/', function: 'r', lineno: 1, colno: 4809165 },
        { filename: 'webkit-masked-url://hidden/', function: 's', lineno: 1, colno: 4786498 },
        { filename: 'webkit-masked-url://hidden/', function: '<anonymous>', lineno: 1, colno: 4785498 },
      ],
    }));
    assert.equal(result, null);
  });

  test('drops single webkit-masked-url frame', async () => {
    const result = (await bootstrap())(makeEvent({
      errorValue: 'some third-party error',
      frames: [{ filename: 'webkit-masked-url://hidden/' }],
    }));
    assert.equal(result, null);
  });

  test('drops Facebook WKWebView bridge errors with document and masked frames only', async () => {
    const result = (await bootstrap(FB_IOS_UA))(makeEvent({
      errorValue: "undefined is not an object (evaluating 'window.webkit.messageHandlers')",
      errorType: 'TypeError',
      frames: [
        { filename: 'https://idaholegalaid.org/', lineno: 1, colno: 1 },
        { filename: 'webkit-masked-url://hidden/', lineno: 1, colno: 4812847 },
      ],
    }));
    assert.equal(result, null);
  });

  // 4. Mixed masked + site-owned frames → kept.
  test('keeps events with mix of masked and site-owned frames', async () => {
    const result = (await bootstrap())(makeEvent({
      errorValue: 'real error in our code',
      frames: [
        { filename: 'webkit-masked-url://hidden/' },
        { filename: 'https://idaholegalaid.org/modules/custom/ilas_site_assistant/js/assistant-widget.js' },
        { filename: 'webkit-masked-url://hidden/' },
      ],
    }));
    assert.notEqual(result, null);
  });

  test('keeps Facebook WKWebView bridge errors when a first-party asset frame exists', async () => {
    const result = (await bootstrap(FB_IOS_UA))(makeEvent({
      errorValue: "undefined is not an object (evaluating 'window.webkit.messageHandlers')",
      errorType: 'TypeError',
      frames: [
        { filename: 'https://idaholegalaid.org/', lineno: 1, colno: 1 },
        { filename: '/themes/custom/b5subtheme/js/custom-scripts.js', lineno: 1, colno: 1 },
      ],
    }));
    assert.notEqual(result, null);
  });

  test('keeps WKWebView bridge errors outside Facebook in-app browser', async () => {
    const result = (await bootstrap(SAFARI_IOS_UA))(makeEvent({
      errorValue: "undefined is not an object (evaluating 'window.webkit.messageHandlers')",
      errorType: 'TypeError',
      frames: [{ filename: 'https://idaholegalaid.org/', lineno: 1, colno: 1 }],
    }));
    assert.notEqual(result, null);
  });

  // 5. Empty stack trace with unknown message → kept.
  // Chrome-for-iOS / Google-app injected inline scripts (Sentry PHP-AK/AB/
  // AC/37/AM/AN, PHP-AS): every frame is the page URL at a deep line number.
  const INJECTED_FRAMES = [
    { filename: 'https://idaholegalaid.org/legal-help/family', function: 'Rk', lineno: 232, colno: 63 },
    { filename: 'https://idaholegalaid.org/legal-help/family', function: 'Tk', lineno: 232, colno: 408 },
    { filename: 'https://idaholegalaid.org/legal-help/family', function: null, lineno: 196, colno: 41 },
  ];

  test('drops page-URL-only deep frames on Chrome for iOS', async () => {
    const fn = await bootstrap(CHROME_IOS_UA);
    const event = makeEvent({ errorType: 'RangeError', errorValue: 'Maximum call stack size exceeded.', frames: INJECTED_FRAMES });
    assert.equal(fn(event), null);
  });

  test('drops page-URL-only deep frames in the Google app', async () => {
    const fn = await bootstrap(GOOGLE_APP_UA);
    const event = makeEvent({ errorType: 'Error', errorValue: 'Ba', frames: INJECTED_FRAMES });
    assert.equal(fn(event), null);
  });

  test('keeps the same page-URL frames on Safari (no injected scripts there)', async () => {
    const fn = await bootstrap(SAFARI_IOS_UA);
    const event = makeEvent({ errorType: 'RangeError', errorValue: 'Maximum call stack size exceeded.', frames: INJECTED_FRAMES });
    assert.ok(fn(event));
  });

  test('keeps Chrome for iOS errors when a site asset frame is on the stack', async () => {
    const fn = await bootstrap(CHROME_IOS_UA);
    const frames = INJECTED_FRAMES.concat([{ filename: 'https://idaholegalaid.org/modules/custom/ilas_site_assistant/js/assistant-widget.js', lineno: 12 }]);
    const event = makeEvent({ errorType: 'TypeError', errorValue: 'x is not a function', frames });
    assert.ok(fn(event));
  });

  test('keeps Chrome for iOS errors from a same-site .js file', async () => {
    const fn = await bootstrap(CHROME_IOS_UA);
    const frames = [{ filename: 'https://idaholegalaid.org/sites/default/files/js/js_abc.js', lineno: 3 }];
    const event = makeEvent({ errorType: 'ReferenceError', errorValue: 'Drupal is not defined', frames });
    assert.ok(fn(event));
  });

  test('keeps errors with empty frames and non-noise message', async () => {
    const result = (await bootstrap())(makeEvent({ errorValue: 'Something unexpected happened', frames: [] }));
    assert.notEqual(result, null);
  });

  // 6. Event with no exception → kept (unless message matches noise pattern).
  test('keeps plain message events that are not noise', async () => {
    assert.notEqual((await bootstrap())(makeEvent({ message: 'AILA browser error' })), null);
  });

  test('drops plain message events that match noise pattern', async () => {
    assert.equal((await bootstrap())(makeEvent({ message: 'ResizeObserver loop completed' })), null);
  });
});

describe('observability.js envelope scrubbing (scrubEvent)', () => {
  test('keeps the captureMessage title intact instead of blanking it (PHP-1M)', async () => {
    const result = (await bootstrap())({ message: 'AILA browser error: quick_action (network)' });
    assert.equal(result.message, 'AILA browser error: quick_action (network)');
  });

  test('pattern-scrubs PII inside a message without blanking the whole string', async () => {
    const result = (await bootstrap())({ message: 'Failed for jane@example.com with Bearer abc.def-123' });
    assert.equal(result.message, 'Failed for [REDACTED-EMAIL] with Bearer [REDACTED]');
  });

  test('keeps logentry.message while key-redacting its params', async () => {
    const result = (await bootstrap())({
      logentry: { message: 'Widget %s failed', params: [{ message: 'user text', feature: 'quick_action' }] },
    });
    assert.equal(result.logentry.message, 'Widget %s failed');
    assertPlainEqual(result.logentry.params, [{ message: '[REDACTED]', feature: 'quick_action' }]);
  });

  test('keeps breadcrumb messages readable while still redacting payload keys', async () => {
    const result = (await bootstrap())({
      message: 'AILA browser error: quick_action (network)',
      breadcrumbs: [
        { category: 'fetch', message: '', data: { method: 'POST', url: '/assistant/api/message?message=hello&x=1', status_code: 0 } },
        { category: 'ui.click', message: 'body > div#aila-widget > button.quick-action' },
        { category: 'console', message: 'ILAS Assistant API error: TypeError: Failed to fetch', data: { body: 'secret' } },
        null,
        'not-an-object',
      ],
      contexts: {
        assistant_error: { feature: 'quick_action', status: 0, message: 'user text', body: 'user text', content: 'x' },
      },
      request: { headers: { Cookie: 'SESS=abc', 'User-Agent': 'x' } },
    });

    assert.equal(result.breadcrumbs[0].message, '');
    assert.equal(result.breadcrumbs[0].data.url, '/assistant/api/message?message=[REDACTED]&x=1');
    assert.equal(result.breadcrumbs[0].data.status_code, 0);
    assert.equal(result.breadcrumbs[1].message, 'body > div#aila-widget > button.quick-action');
    assert.equal(result.breadcrumbs[2].message, 'ILAS Assistant API error: TypeError: Failed to fetch');
    assert.equal(result.breadcrumbs[2].data.body, '[REDACTED]');
    assert.equal(result.breadcrumbs[3], null);
    assert.equal(result.breadcrumbs[4], 'not-an-object');

    assertPlainEqual(result.contexts.assistant_error, {
      feature: 'quick_action',
      status: 0,
      message: '[REDACTED]',
      body: '[REDACTED]',
      content: '[REDACTED]',
    });
    assert.equal(result.request.headers.Cookie, '[REDACTED]');
    assert.equal(result.request.headers['User-Agent'], 'x');
  });

  test('passes an explicit fingerprint through untouched', async () => {
    const result = (await bootstrap())({
      message: 'AILA browser error: quick_action (network)',
      fingerprint: ['aila-browser-error', 'quick_action', 'network'],
    });
    assertPlainEqual(result.fingerprint, ['aila-browser-error', 'quick_action', 'network']);
  });

  test('still merges shared tags onto the event', async () => {
    const result = (await bootstrap())({ message: 'x', tags: { custom: 'kept' } });
    assert.equal(result.tags.custom, 'kept');
    assert.equal(result.tags.environment, 'test');
    assert.equal(result.tags.assistant_name, 'aila');
  });
});

describe('observability.js scrubber recursion guards (PHP-AJ)', () => {
  test('does not overflow the stack on a cyclic breadcrumb payload', async () => {
    const cyclic = { name: 'Affirm Extension', nested: {} };
    cyclic.nested.self = cyclic;
    cyclic.list = [cyclic];

    const event = {
      message: 'AILA browser error: quick_action (network)',
      breadcrumbs: [{ category: 'console', message: 'ext', data: { arguments: [cyclic] } }],
    };

    const processor = await bootstrap();
    let result;
    assert.doesNotThrow(() => { result = processor(event); });
    assert.notEqual(result, null);
    assert.equal(result.message, 'AILA browser error: quick_action (network)');
    const arg = result.breadcrumbs[0].data.arguments[0];
    assert.equal(arg.name, 'Affirm Extension');
    assert.equal(arg.nested.self, '[Circular]');
    assertPlainEqual(arg.list, ['[Circular]']);
  });

  test('scrubs shared (non-cyclic) references normally', async () => {
    const shared = { email: 'jane@example.com' };
    const result = (await bootstrap())({ extra: { a: shared, b: shared } });
    assertPlainEqual(result.extra.a, { email: '[REDACTED-EMAIL]' });
    assertPlainEqual(result.extra.b, { email: '[REDACTED-EMAIL]' });
  });

  test('truncates objects nested deeper than the cap instead of recursing forever', async () => {
    let deep = { leaf: 'jane@example.com' };
    for (let i = 0; i < 20; i++) {
      deep = { child: deep };
    }
    const result = (await bootstrap())({ extra: { deep } });

    let cursor = result.extra.deep;
    let depth = 0;
    while (cursor && typeof cursor === 'object') {
      cursor = cursor.child;
      depth += 1;
    }
    assert.equal(cursor, '[Truncated]');
    assert.ok(depth < 20, `expected truncation before 20 levels, walked ${depth}`);
    assert.ok(!JSON.stringify(result).includes('jane@example.com'));
  });
});
