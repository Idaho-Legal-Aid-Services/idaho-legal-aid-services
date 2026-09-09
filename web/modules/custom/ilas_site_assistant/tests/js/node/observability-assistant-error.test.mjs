/**
 * @file
 * observability.js assistant error capture: title, fingerprint, tags and
 * payload scrubbing for the ilas:assistant:error CustomEvent (PHP-1M).
 */

import { test, describe, mock } from 'node:test';
import assert from 'node:assert/strict';

import { loadObservability, flush, assertPlainEqual } from './_harness.mjs';

async function bootstrap(settingsOverrides) {
  const recorded = {
    globalTags: null,
    scopeContext: null,
    scopeTags: null,
    fingerprint: null,
    replayOptions: null,
    integration: null,
    reportDialogArgs: null,
    captureMessageArgs: null,
  };

  const window = await loadObservability({
    settings: settingsOverrides,
    sentry: () => ({
      setTags: mock.fn((tags) => { recorded.globalTags = tags; }),
      addEventProcessor: mock.fn(),
      lazyLoadIntegration: mock.fn(() => Promise.resolve((options) => {
        recorded.replayOptions = options;
        return { name: 'replayIntegration', options };
      })),
      addIntegration: mock.fn((integration) => { recorded.integration = integration; }),
      withScope: mock.fn((callback) => {
        callback({
          setTags: mock.fn((tags) => { recorded.scopeTags = tags; }),
          setContext: mock.fn((name, value) => {
            if (name === 'assistant_error') {
              recorded.scopeContext = value;
            }
          }),
          setFingerprint: mock.fn((fingerprint) => { recorded.fingerprint = fingerprint; }),
        });
      }),
      captureMessage: mock.fn(() => {
        recorded.captureMessageArgs = Array.prototype.slice.call(arguments);
        return 'browser-event-123';
      }),
      showReportDialog: mock.fn((args) => { recorded.reportDialogArgs = args; }),
      getClient: mock.fn(() => ({
        addIntegration: mock.fn((integration) => { recorded.integration = integration; }),
      })),
    }),
  });

  // node:test mock.fn with an arrow impl cannot see `arguments`; read the
  // recorded call list instead.
  const captureArgs = () => {
    const calls = window.Sentry.captureMessage.mock.calls;
    return calls.length ? calls[calls.length - 1].arguments : null;
  };

  return { window, recorded, captureArgs };
}

function emitError(window, detail) {
  window.dispatchEvent(new window.CustomEvent('ilas:assistant:error', { detail }));
}

describe('observability.js assistant error capture', () => {
  test('scrubs assistant error payload and emits bounded tags', async () => {
    const { window, recorded, captureArgs } = await bootstrap();
    emitError(window, {
      surface: 'page',
      pageMode: true,
      feature: 'browser_probe',
      errorCode: 'synthetic_browser_probe',
      status: 503,
      promptForFeedback: false,
      prompt: 'Need help from jane@example.com',
      body: 'SSN 123-45-6789',
      content: 'Bearer secret-token',
      message: 'Call me at test@example.com',
      custom: 'uuid 123e4567-e89b-12d3-a456-426614174000',
    });
    await flush();

    // Server error code wins over HTTP status in the title.
    assertPlainEqual(captureArgs(), ['AILA browser error: browser_probe (synthetic_browser_probe)', 'error']);
    assertPlainEqual(recorded.fingerprint, ['aila-browser-error', 'browser_probe', 'synthetic_browser_probe']);
    assertPlainEqual(recorded.scopeContext, {
      surface: 'page',
      pageMode: true,
      feature: 'browser_probe',
      errorCode: 'synthetic_browser_probe',
      status: 503,
      promptForFeedback: false,
      prompt: '[REDACTED]',
      body: '[REDACTED]',
      content: '[REDACTED]',
      message: '[REDACTED]',
      custom: 'uuid [REDACTED-UUID]',
    });
    const serialized = JSON.stringify(recorded.scopeContext);
    assert.ok(!serialized.includes('jane@example.com'));
    assert.ok(!serialized.includes('123-45-6789'));
    assert.ok(!serialized.includes('secret-token'));

    const expectedTags = {
      environment: 'pantheon-test',
      pantheon_env: 'test',
      site_name: 'idaho-legal-aid-services',
      assistant_name: 'aila',
      release: 'test_155',
      route_name: 'ilas_site_assistant.page',
      assistant_surface: 'page',
      assistant_mode: 'page',
      assistant_feature: 'browser_probe',
      assistant_route: '/assistant/api',
      assistant_status: '503',
      error_code: 'synthetic_browser_probe',
    };
    for (const [key, value] of Object.entries(expectedTags)) {
      assert.equal(recorded.scopeTags[key], value, `tag ${key}`);
    }
    assert.equal(window.Sentry.showReportDialog.mock.calls.length, 0);
  });

  test('network drop (status 0, no response) gets a readable title and network class (PHP-1M)', async () => {
    const { window, recorded, captureArgs } = await bootstrap();
    // Exact shape of the live PHP-1M event context.
    emitError(window, {
      surface: 'assistant-widget',
      pageMode: false,
      feature: 'quick_action',
      status: 0,
      type: 'default',
      errorCode: '',
      retryAfter: '',
      promptForFeedback: false,
    });
    await flush();

    assertPlainEqual(captureArgs(), ['AILA browser error: quick_action (network)', 'error']);
    assertPlainEqual(recorded.fingerprint, ['aila-browser-error', 'quick_action', 'network']);
    assert.equal(recorded.scopeTags.error_code, 'network');
    assert.equal(recorded.scopeTags.assistant_status, '0');
    assert.equal(recorded.scopeTags.assistant_feature, 'quick_action');
    assert.equal(recorded.scopeTags.assistant_mode, 'widget');
    // Raw type is kept in context for diagnosis even though it is not a class.
    assert.equal(recorded.scopeContext.type, 'default');
  });

  test('classifies HTTP status and transport types when no server code is present', async () => {
    const cases = [
      [{ feature: 'message_send', status: 503 }, 'http_503'],
      [{ feature: 'message_send', status: 429, retryAfter: '30' }, 'http_429'],
      [{ feature: 'message_retry', status: 0, type: 'timeout' }, 'timeout'],
      [{ feature: 'message_send', status: 0, type: 'offline' }, 'offline'],
      [{ feature: 'message_send', status: 403, errorCode: 'csrf_expired' }, 'csrf_expired'],
      [{ feature: 'chip_render', status: 0, type: '' }, 'network'],
    ];

    for (const [detail, expectedClass] of cases) {
      const { window, recorded, captureArgs } = await bootstrap();
      emitError(window, detail);
      await flush();
      assertPlainEqual(
        captureArgs(),
        ['AILA browser error: ' + detail.feature + ' (' + expectedClass + ')', 'error'],
        JSON.stringify(detail),
      );
      assertPlainEqual(recorded.fingerprint, ['aila-browser-error', detail.feature, expectedClass]);
      assert.equal(recorded.scopeTags.error_code, expectedClass);
    }
  });

  test('never lets unsafe feature or error code tokens reach the title or fingerprint', async () => {
    const cases = [
      { feature: 'quick action <script>alert(1)</script>', status: 0 },
      { feature: 'jane@example.com', status: 0 },
      { feature: '', status: 0 },
      { feature: 42, status: 0 },
    ];

    for (const detail of cases) {
      const { window, recorded, captureArgs } = await bootstrap();
      emitError(window, detail);
      await flush();
      assertPlainEqual(captureArgs(), ['AILA browser error: unknown (network)', 'error'], JSON.stringify(detail));
      assertPlainEqual(recorded.fingerprint, ['aila-browser-error', 'unknown', 'network']);
      assert.equal(recorded.scopeTags.assistant_feature, 'unknown');
    }

    // A free-text server error code is not a token either: falls back to status.
    const { window, recorded, captureArgs } = await bootstrap();
    emitError(window, { feature: 'message_send', status: 500, errorCode: 'Something broke for bob@example.com' });
    await flush();
    assertPlainEqual(captureArgs(), ['AILA browser error: message_send (http_500)', 'error']);
    assertPlainEqual(recorded.fingerprint, ['aila-browser-error', 'message_send', 'http_500']);
    assert.ok(!JSON.stringify(recorded.scopeContext).includes('bob@example.com'));
  });

  test('opens the report dialog only when feedback is requested', async () => {
    const { window, recorded } = await bootstrap();
    emitError(window, {
      surface: 'widget',
      pageMode: false,
      feature: 'browser_probe',
      errorCode: 'feedback_probe',
      promptForFeedback: true,
    });
    await flush();

    assert.equal(window.Sentry.showReportDialog.mock.calls.length, 1);
    assertPlainEqual(recorded.reportDialogArgs, { eventId: 'browser-event-123' });
  });

  test('loads replay with privacy-safe options when replay is enabled', async () => {
    const { window, recorded } = await bootstrap();
    await flush();

    assert.equal(window.Sentry.lazyLoadIntegration.mock.calls.length, 1);
    assert.equal(window.Sentry.lazyLoadIntegration.mock.calls[0].arguments[0], 'replayIntegration');
    assertPlainEqual(recorded.replayOptions, {
      maskAllText: true,
      blockAllMedia: true,
      maskAllInputs: true,
      sessionSampleRate: 0.05,
      errorSampleRate: 1,
    });
    assertPlainEqual(recorded.integration, {
      name: 'replayIntegration',
      options: recorded.replayOptions,
    });
  });

  test('does not request replay when replay is disabled', async () => {
    const { window, recorded } = await bootstrap({
      sentry: {
        browserEnabled: true,
        showReportDialog: true,
        replayEnabled: false,
        replaySessionSampleRate: 0,
        replayOnErrorSampleRate: 0,
      },
    });
    await flush();

    assert.equal(window.Sentry.lazyLoadIntegration.mock.calls.length, 0);
    assert.equal(recorded.replayOptions, null);
    assert.equal(recorded.integration, null);
  });
});
