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

  function scrubValue(value) {
    if (typeof value === 'string') {
      return scrubString(value);
    }

    if (!value || typeof value !== 'object') {
      return value;
    }

    if (Array.isArray(value)) {
      return value.map(scrubValue);
    }

    var scrubbed = {};
    Object.keys(value).forEach(function (key) {
      var normalizedKey = key.toLowerCase();
      if (normalizedKey === 'authorization' || normalizedKey === 'cookie' || normalizedKey === 'set-cookie' || normalizedKey === 'prompt' || normalizedKey === 'message' || normalizedKey === 'body' || normalizedKey === 'content') {
        scrubbed[key] = '[REDACTED]';
        return;
      }
      scrubbed[key] = scrubValue(value[key]);
    });

    return scrubbed;
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

  function isExtensionNoise(event) {
    var message = '';
    if (event.exception && event.exception.values && event.exception.values.length) {
      var exc = event.exception.values[0];
      message = (exc.value || '') + ' ' + (exc.type || '');
      var frames = exc.stacktrace && exc.stacktrace.frames ? exc.stacktrace.frames : [];
      for (var i = 0; i < frames.length; i++) {
        var filename = frames[i].filename || '';
        if (filename.indexOf('idaholegalaid.org') !== -1 || filename.indexOf('/modules/') !== -1 || filename.indexOf('/themes/') !== -1) {
          return false;
        }
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
        var scrubbed = scrubValue(event || {});
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

  function configureNewRelic() {
    if (!settings.newRelic || !settings.newRelic.browserEnabled || !window.newrelic) {
      return;
    }

    if (typeof window.newrelic.setCustomAttribute === 'function') {
      Object.keys(sharedTags()).forEach(function (key) {
        if (sharedTags()[key]) {
          window.newrelic.setCustomAttribute(key, sharedTags()[key]);
        }
      });
    }
  }

  function emitAssistantError(detail) {
    var payload = scrubValue(detail || {});
    var tags = withCompactTags(Object.assign(sharedTags(), {
      assistant_surface: payload.surface || '',
      assistant_mode: payload.pageMode ? 'page' : 'widget',
      assistant_feature: payload.feature || 'unknown',
      assistant_route: settings.assistant && settings.assistant.apiBase ? settings.assistant.apiBase : '/assistant/api',
      error_code: payload.errorCode || payload.type || payload.status || 'unknown',
    }));

    if (window.Sentry && settings.sentry && settings.sentry.browserEnabled && typeof window.Sentry.withScope === 'function') {
      window.Sentry.withScope(function (scope) {
        if (typeof scope.setTags === 'function') {
          scope.setTags(tags);
        }
        if (typeof scope.setContext === 'function') {
          scope.setContext('assistant_error', payload);
        }

        var eventId = null;
        if (typeof window.Sentry.captureMessage === 'function') {
          eventId = window.Sentry.captureMessage('AILA browser error', 'error');
        }

        if (payload.promptForFeedback && eventId && typeof window.Sentry.showReportDialog === 'function') {
          window.Sentry.showReportDialog({ eventId: eventId });
        }
      });
    }

    if (window.newrelic && typeof window.newrelic.noticeError === 'function') {
      window.newrelic.noticeError(new Error('AILA browser error'), payload);
    }
  }

  function emitAssistantAction(detail) {
    if (!window.newrelic || typeof window.newrelic.addPageAction !== 'function') {
      return;
    }

    var payload = scrubValue(detail || {});
    window.newrelic.addPageAction('aila_action', Object.assign({}, withCompactTags(sharedTags()), payload));
  }

  function scheduleProviders() {
    configureSentry();
    configureNewRelic();

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
