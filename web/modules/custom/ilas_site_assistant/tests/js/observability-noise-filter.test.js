/**
 * @jest-environment jsdom
 */

/**
 * @file
 * Tests for the observability.js isExtensionNoise filter, exercised through
 * the Sentry event processor that observability.js installs.
 *
 * Coverage:
 *  1. Site-owned frames pass through (idaholegalaid.org, /modules/, /themes/)
 *  2. Known noise patterns are dropped (runtime.sendMessage, ResizeObserver, etc.)
 *  3. All-masked webkit-masked-url://hidden/ frames with no site frames → dropped
 *  4. Mixed masked + site-owned frames → kept
 *  5. Empty stack trace with unknown message → kept
 *  6. Event with no exception → kept (unless message matches noise pattern)
 */

describe('observability.js noise filter', function () {
  var eventProcessor;

  beforeEach(function () {
    // Reset module state by clearing any prior load.
    eventProcessor = null;

    // Stub Drupal global.
    window.Drupal = { t: function (s) { return s; } };

    // Stub drupalSettings with observability config.
    window.drupalSettings = {
      ilasObservability: {
        environment: 'test',
        pantheonEnv: 'test',
        siteName: 'test',
        sentry: {
          browserEnabled: true,
        },
        assistant: { name: 'aila' },
      },
    };

    // Stub Sentry — capture the event processor callback.
    window.Sentry = {
      setTags: jest.fn(),
      addEventProcessor: jest.fn(function (fn) {
        eventProcessor = fn;
      }),
    };

    // Load observability.js (it executes the IIFE immediately).
    jest.resetModules();
    require('../../js/observability.js');
  });

  afterEach(function () {
    delete window.Drupal;
    delete window.drupalSettings;
    delete window.Sentry;
  });

  function makeEvent(opts) {
    opts = opts || {};
    var event = {};
    if (opts.message) {
      event.message = opts.message;
    }
    if (opts.frames || opts.errorValue || opts.errorType) {
      event.exception = {
        values: [{
          value: opts.errorValue || 'some error',
          type: opts.errorType !== undefined ? opts.errorType : 'TypeError',
          stacktrace: {
            frames: opts.frames || [],
          },
        }],
      };
    }
    return event;
  }

  test('event processor is installed', function () {
    expect(eventProcessor).toBeInstanceOf(Function);
  });

  // 1. Site-owned frames pass through.
  test('keeps errors with idaholegalaid.org frames', function () {
    var event = makeEvent({
      errorValue: 'Cannot read property x of undefined',
      frames: [
        { filename: 'https://idaholegalaid.org/modules/custom/ilas_site_assistant/js/assistant-widget.js' },
      ],
    });
    var result = eventProcessor(event);
    expect(result).not.toBeNull();
  });

  test('keeps errors with /modules/ frames', function () {
    var event = makeEvent({
      errorValue: 'Something broke',
      frames: [
        { filename: '/modules/custom/ilas_site_assistant/js/observability.js' },
      ],
    });
    var result = eventProcessor(event);
    expect(result).not.toBeNull();
  });

  test('keeps errors with /themes/ frames', function () {
    var event = makeEvent({
      errorValue: 'Something broke',
      frames: [
        { filename: '/themes/custom/b5subtheme/js/some-script.js' },
      ],
    });
    var result = eventProcessor(event);
    expect(result).not.toBeNull();
  });

  // 2. Known noise patterns are dropped.
  test('drops runtime.sendMessage errors', function () {
    var event = makeEvent({
      errorValue: 'runtime.sendMessage failed',
      frames: [{ filename: 'chrome-extension://abc123/content.js' }],
    });
    var result = eventProcessor(event);
    expect(result).toBeNull();
  });

  test('drops ResizeObserver loop errors', function () {
    var event = makeEvent({
      errorValue: 'ResizeObserver loop completed with undelivered notifications',
      frames: [],
    });
    var result = eventProcessor(event);
    expect(result).toBeNull();
  });

  test('drops "Script error." plain message events', function () {
    var event = makeEvent({
      message: 'Script error.',
    });
    var result = eventProcessor(event);
    expect(result).toBeNull();
  });

  // 3. All webkit-masked-url://hidden/ frames → dropped as third-party noise.
  test('drops events where ALL frames are webkit-masked-url://hidden/', function () {
    var event = makeEvent({
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
    });
    var result = eventProcessor(event);
    expect(result).toBeNull();
  });

  test('drops single webkit-masked-url frame', function () {
    var event = makeEvent({
      errorValue: 'some third-party error',
      frames: [
        { filename: 'webkit-masked-url://hidden/' },
      ],
    });
    var result = eventProcessor(event);
    expect(result).toBeNull();
  });

  // 4. Mixed masked + site-owned frames → kept.
  test('keeps events with mix of masked and site-owned frames', function () {
    var event = makeEvent({
      errorValue: 'real error in our code',
      frames: [
        { filename: 'webkit-masked-url://hidden/' },
        { filename: 'https://idaholegalaid.org/modules/custom/ilas_site_assistant/js/assistant-widget.js' },
        { filename: 'webkit-masked-url://hidden/' },
      ],
    });
    var result = eventProcessor(event);
    expect(result).not.toBeNull();
  });

  // 5. Empty stack trace with unknown message → kept.
  test('keeps errors with empty frames and non-noise message', function () {
    var event = makeEvent({
      errorValue: 'Something unexpected happened',
      frames: [],
    });
    var result = eventProcessor(event);
    expect(result).not.toBeNull();
  });

  // 6. Event with no exception → kept (unless message matches noise pattern).
  test('keeps plain message events that are not noise', function () {
    var event = makeEvent({
      message: 'AILA browser error',
    });
    var result = eventProcessor(event);
    expect(result).not.toBeNull();
  });

  test('drops plain message events that match noise pattern', function () {
    var event = makeEvent({
      message: 'ResizeObserver loop completed',
    });
    var result = eventProcessor(event);
    expect(result).toBeNull();
  });
});
