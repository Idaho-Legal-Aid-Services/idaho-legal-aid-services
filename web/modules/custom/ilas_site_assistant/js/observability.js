(function (Drupal, drupalSettings) {
  'use strict';

  var settings = drupalSettings && drupalSettings.ilasObservability;
  if (!settings) {
    return;
  }

  var sentryConfigured = false;
  var replayRequested = false;

  function scrubString(value) {
    if (!value || typeof value !== 'string') {
      return value;
    }

    return value
      .replace(/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/ig, '[REDACTED-EMAIL]')
      .replace(/\bBearer\s+[A-Za-z0-9._-]+\b/ig, 'Bearer [REDACTED]')
      .replace(/\b[0-9a-f]{8}-[0-9a-f-]{27}\b/ig, '[REDACTED-UUID]')
      .replace(/\b\d{3}-\d{2}-\d{4}\b/g, '[REDACTED-SSN]')
      .replace(/([?&](?:message|prompt|content|body|query|text)=)[^&]+/ig, '$1[REDACTED]');
  }

  // Depth cap for the recursive scrubber. Sentry event envelopes are ~6 deep
  // (exception.values[].stacktrace.frames[].vars); anything deeper is either
  // extension-injected junk or a cycle (PHP-AJ).
  var MAX_SCRUB_DEPTH = 10;

  function scrubValue(value, ancestors, depth) {
    ancestors = ancestors || [];
    depth = depth || 0;

    if (typeof value === 'string') {
      return scrubString(value);
    }

    if (!value || typeof value !== 'object') {
      return value;
    }

    // Cycle guard: only true ancestors count, so shared (DAG) references are
    // still scrubbed normally instead of being flagged as circular.
    if (ancestors.indexOf(value) !== -1) {
      return '[Circular]';
    }

    if (depth >= MAX_SCRUB_DEPTH) {
      return '[Truncated]';
    }

    var nextAncestors = ancestors.concat([value]);

    if (Array.isArray(value)) {
      return value.map(function (item) {
        return scrubValue(item, nextAncestors, depth + 1);
      });
    }

    var scrubbed = {};
    Object.keys(value).forEach(function (key) {
      var normalizedKey = key.toLowerCase();
      if (normalizedKey === 'authorization' || normalizedKey === 'cookie' || normalizedKey === 'set-cookie' || normalizedKey === 'prompt' || normalizedKey === 'message' || normalizedKey === 'body' || normalizedKey === 'content') {
        scrubbed[key] = '[REDACTED]';
        return;
      }
      scrubbed[key] = scrubValue(value[key], nextAncestors, depth + 1);
    });

    return scrubbed;
  }

  /**
   * Scrubs a Sentry event envelope.
   *
   * scrubValue() is a key-name redactor meant for user-supplied payloads. Run
   * over a whole Sentry event it also blanks the *template* fields Sentry uses
   * for titling and grouping (event.message, logentry.message,
   * breadcrumbs[].message), which is how PHP-1M ended up titled "[REDACTED]".
   * Those fields are code-owned constants (captureMessage titles, breadcrumb
   * categories, DOM selectors, console prefixes) so they get pattern
   * scrubbing only; everything else (contexts, extra, request, exception)
   * keeps the key-based redaction.
   */
  function scrubEvent(event) {
    var scrubbed = scrubValue(event || {});

    if (typeof event.message === 'string') {
      scrubbed.message = scrubString(event.message);
    }

    if (event.logentry && typeof event.logentry === 'object') {
      scrubbed.logentry = Object.assign({}, scrubbed.logentry || {}, {
        message: scrubString(event.logentry.message),
      });
    }

    if (Array.isArray(event.breadcrumbs)) {
      scrubbed.breadcrumbs = event.breadcrumbs.map(function (crumb, index) {
        var scrubbedCrumb = scrubbed.breadcrumbs[index];
        if (!crumb || typeof crumb !== 'object' || !scrubbedCrumb || typeof scrubbedCrumb !== 'object') {
          return scrubbedCrumb;
        }
        if (typeof crumb.message === 'string') {
          scrubbedCrumb.message = scrubString(crumb.message);
        }
        return scrubbedCrumb;
      });
    }

    return scrubbed;
  }

  var SAFE_TOKEN_PATTERN = /^[a-z0-9_]{1,64}$/i;

  function safeToken(value) {
    if (typeof value !== 'string' || !SAFE_TOKEN_PATTERN.test(value)) {
      return '';
    }
    return value;
  }

  var KNOWN_ERROR_TYPES = ['offline', 'timeout'];

  /**
   * Derives a bounded failure class from the widget error payload.
   *
   * Precedence: server error code, then the transport types the widget itself
   * sets (offline/timeout), then HTTP status, then "network" for a status-0
   * request that never got a response (the PHP-1M case). Only code-owned
   * tokens can reach the title/fingerprint.
   */
  function classifyAssistantError(payload) {
    var errorCode = safeToken(payload.errorCode);
    if (errorCode) {
      return errorCode;
    }

    var type = safeToken(payload.type).toLowerCase();
    if (KNOWN_ERROR_TYPES.indexOf(type) !== -1) {
      return type;
    }

    var status = Number(payload.status);
    if (status > 0 && isFinite(status)) {
      return 'http_' + Math.floor(status);
    }

    return 'network';
  }

  function sharedTags() {
    return {
      environment: settings.environment || 'local',
      pantheon_env: settings.pantheonEnv || '',
      multidev_name: settings.multidevName || '',
      site_name: settings.siteName || 'local',
      site_id: settings.siteId || '',
      assistant_name: settings.assistant && settings.assistant.name ? settings.assistant.name : 'aila',
      release: settings.release || '',
      git_sha: settings.gitSha || '',
      route_name: settings.routeName || '',
    };
  }

  function withCompactTags(tags) {
    var compact = {};
    Object.keys(tags).forEach(function (key) {
      if (tags[key]) {
        compact[key] = String(tags[key]).slice(0, 255);
      }
    });
    return compact;
  }

  var EXTENSION_NOISE_PATTERNS = [
    /runtime\.sendMessage/i,
    /runtime\.connect/i,
    /invalid origin/i,
    /ResizeObserver loop/i,
    /^Script error\.?$/i
  ];

  function hasFirstPartyFrame(frames) {
    for (var i = 0; i < frames.length; i++) {
      var filename = frames[i].filename || '';
      if (filename.indexOf('/modules/') !== -1 || filename.indexOf('/themes/') !== -1) {
        return true;
      }

      var path = extractSameSitePath(filename);
      if (path && /\.js(?:[?#].*)?$/i.test(path)) {
        return true;
      }
    }

    return false;
  }

  function isMaskedFrame(frame) {
    return (frame.filename || '') === 'webkit-masked-url://hidden/';
  }

  function isFacebookInAppBrowser() {
    var userAgent = window.navigator && window.navigator.userAgent ? window.navigator.userAgent : '';
    return /FBAN|FBAV/i.test(userAgent);
  }

  function isWkWebViewBridgeMessage(message) {
    return /window\.webkit\.messageHandlers/i.test(message) && /undefined is not an object/i.test(message);
  }

  function extractSameSitePath(filename) {
    if (!filename) {
      return null;
    }

    if (filename.charAt(0) === '/') {
      return filename;
    }

    if (!/^https?:\/\//i.test(filename)) {
      return null;
    }

    var parser = document.createElement('a');
    parser.href = filename;

    var currentHost = window.location && window.location.hostname ? window.location.hostname : '';
    if (parser.hostname !== 'idaholegalaid.org' && (!currentHost || parser.hostname !== currentHost)) {
      return null;
    }

    return (parser.pathname || '/') + (parser.search || '') + (parser.hash || '');
  }

  function isDocumentFrame(frame) {
    var filename = frame.filename || '';
    var path = extractSameSitePath(filename);
    if (!path) {
      return false;
    }

    if (path.indexOf('/modules/') !== -1 || path.indexOf('/themes/') !== -1) {
      return false;
    }

    if (frame.lineno && frame.lineno !== 1) {
      return false;
    }

    var cleanPath = path.split('#')[0].split('?')[0];
    var segments = cleanPath.split('/');
    var lastSegment = segments[segments.length - 1];

    return lastSegment === '' || lastSegment.indexOf('.') === -1;
  }

  function allFramesMatch(frames, predicate) {
    if (!frames.length) {
      return false;
    }

    for (var i = 0; i < frames.length; i++) {
      if (!predicate(frames[i])) {
        return false;
      }
    }

    return true;
  }

  function isExtensionNoise(event) {
    var message = '';
    if (event.exception && event.exception.values && event.exception.values.length) {
      var exc = event.exception.values[0];
      message = (exc.value || '') + ' ' + (exc.type || '');
      var frames = exc.stacktrace && exc.stacktrace.frames ? exc.stacktrace.frames : [];
      if (hasFirstPartyFrame(frames)) {
        return false;
      }
      // Safari ITP masks cross-origin script URLs as webkit-masked-url://hidden/.
      // If every frame is masked, the error is from a third-party script (e.g.
      // GA4/GTM) and not from site-owned code — treat it as noise.
      // Safety: This cannot drop real AILA errors because:
      // (a) Site-owned asset frames (/modules/, /themes/, same-site .js URLs)
      //     exit early above
      // (b) AILA errors use Sentry.captureMessage() — no exception.stacktrace to inspect
      // (c) Safari ITP only masks cross-origin scripts, not same-origin
      if (allFramesMatch(frames, isMaskedFrame)) {
        return true;
      }

      // Facebook's iOS in-app browser sometimes throws page-level TypeErrors
      // when third-party/browser code probes a WKWebView bridge that is absent.
      // Drop only the exact bridge signature when frames are limited to the
      // current document URL and/or Safari-masked third-party URLs.
      if (isFacebookInAppBrowser() &&
        isWkWebViewBridgeMessage(message) &&
        allFramesMatch(frames, function (frame) {
          return isMaskedFrame(frame) || isDocumentFrame(frame);
        })) {
        return true;
      }
    }
    else if (event.message) {
      message = event.message;
    }

    if (!message) {
      return false;
    }

    for (var j = 0; j < EXTENSION_NOISE_PATTERNS.length; j++) {
      if (EXTENSION_NOISE_PATTERNS[j].test(message)) {
        return true;
      }
    }
    return false;
  }

  function configureSentry() {
    if (sentryConfigured || !window.Sentry || !settings.sentry || !settings.sentry.browserEnabled) {
      return;
    }

    sentryConfigured = true;

    if (typeof window.Sentry.setTags === 'function') {
      window.Sentry.setTags(withCompactTags(sharedTags()));
    }

    if (typeof window.Sentry.addEventProcessor === 'function') {
      window.Sentry.addEventProcessor(function (event) {
        if (isExtensionNoise(event)) {
          return null;
        }
        var scrubbed = scrubEvent(event || {});
        scrubbed.tags = Object.assign({}, scrubbed.tags || {}, withCompactTags(sharedTags()));
        return scrubbed;
      });
    }

    if (!settings.sentry.replayEnabled || replayRequested || typeof window.Sentry.lazyLoadIntegration !== 'function') {
      return;
    }

    replayRequested = true;
    window.Sentry.lazyLoadIntegration('replayIntegration')
      .then(function (replayIntegrationFactory) {
        if (typeof replayIntegrationFactory !== 'function') {
          return;
        }

        var integration = replayIntegrationFactory({
          maskAllText: true,
          blockAllMedia: true,
          maskAllInputs: true,
          sessionSampleRate: settings.sentry.replaySessionSampleRate || 0,
          errorSampleRate: settings.sentry.replayOnErrorSampleRate || 0,
        });

        if (typeof window.Sentry.addIntegration === 'function') {
          window.Sentry.addIntegration(integration);
          return;
        }

        var client = typeof window.Sentry.getClient === 'function' ? window.Sentry.getClient() : null;
        if (client && typeof client.addIntegration === 'function') {
          client.addIntegration(integration);
        }
      })
      .catch(function () {
        replayRequested = false;
      });
  }

  function emitAssistantError(detail) {
    var payload = scrubValue(detail || {});
    var feature = safeToken(payload.feature) || 'unknown';
    var errorClass = classifyAssistantError(payload);
    // Constant template + bounded tokens only: never user or request content.
    var title = 'AILA browser error: ' + feature + ' (' + errorClass + ')';
    var tags = withCompactTags(Object.assign(sharedTags(), {
      assistant_surface: payload.surface || '',
      assistant_mode: payload.pageMode ? 'page' : 'widget',
      assistant_feature: feature,
      assistant_route: settings.assistant && settings.assistant.apiBase ? settings.assistant.apiBase : '/assistant/api',
      // Stringified so a status of 0 survives withCompactTags' falsy filter.
      assistant_status: String(Number(payload.status) || 0),
      error_code: errorClass,
    }));

    if (window.Sentry && settings.sentry && settings.sentry.browserEnabled && typeof window.Sentry.withScope === 'function') {
      window.Sentry.withScope(function (scope) {
        if (typeof scope.setTags === 'function') {
          scope.setTags(tags);
        }
        if (typeof scope.setContext === 'function') {
          scope.setContext('assistant_error', payload);
        }
        // One Sentry issue per feature x failure class (mirrors the fixed
        // fingerprint convention in SentryProbeCommands.php).
        if (typeof scope.setFingerprint === 'function') {
          scope.setFingerprint(['aila-browser-error', feature, errorClass]);
        }

        var eventId = null;
        if (typeof window.Sentry.captureMessage === 'function') {
          eventId = window.Sentry.captureMessage(title, 'error');
        }

        if (payload.promptForFeedback && eventId && typeof window.Sentry.showReportDialog === 'function') {
          window.Sentry.showReportDialog({ eventId: eventId });
        }
      });
    }

  }

  function emitAssistantAction() {
    // No-op: New Relic retired (TOVR-06). Stub preserved for event contract
    // compatibility — assistant-widget.js dispatches ilas:assistant:action.
  }

  function scheduleProviders() {
    configureSentry();

    if (!sentryConfigured && settings.sentry && settings.sentry.browserEnabled) {
      window.setTimeout(scheduleProviders, 1000);
    }
  }

  window.addEventListener('ilas:assistant:error', function (event) {
    emitAssistantError(event.detail || {});
  });

  window.addEventListener('ilas:assistant:action', function (event) {
    emitAssistantAction(event.detail || {});
  });

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', scheduleProviders, { once: true });
  }
  else {
    scheduleProviders();
  }
})(Drupal, drupalSettings);
