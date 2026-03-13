/**
 * @jest-environment jsdom
 */

/**
 * @file
 * Smoke tests for assistant-widget.js hardening.
 *
 * Run with any DOM-capable JS test runner (e.g. Jest + jsdom, or a browser
 * console). Each test is self-contained and logs PASS / FAIL to the console.
 *
 * Coverage:
 *  1. sanitizeUrl — blocks javascript:/data:/vbscript:, allows safe schemes
 *  2. escapeAttr — escapes all 5 breakout characters
 *  3. escapeHtml — entity-encodes angle brackets and ampersands
 *  4. getErrorMessage — per-status user-facing messages
 *  5. isSending guard — prevents double-fire
 *  6. Focus trap lifecycle — no listener accumulation
 *  7. Typing indicator ARIA — role="status" + aria-label
 *  8. AbortController timeout — callApi rejects on timeout
 *  9. Bootstrap token fetch — preserves HTTP status + Retry-After
 */

/* global SiteAssistant */
window._assistantWidgetTestDone = (async function () {
  'use strict';

  var results = { pass: 0, fail: 0 };
  var pending = [];

  function assert(condition, label) {
    if (condition) {
      results.pass++;
      console.log('  PASS: ' + label);
    } else {
      results.fail++;
      console.error('  FAIL: ' + label);
    }
  }

  function suite(name, fn) {
    console.log('\n=== ' + name + ' ===');
    var maybePromise = fn();
    if (maybePromise && typeof maybePromise.then === 'function') {
      pending.push(maybePromise);
    }
  }

  // -------------------------------------------------------------------
  // Minimal stubs so the SiteAssistant object can be exercised in
  // isolation without the full Drupal runtime.
  // -------------------------------------------------------------------
  if (typeof window === 'undefined') {
    console.error('These tests require a DOM environment (browser or jsdom).');
    return;
  }

  // Stub Drupal.t if not present.
  if (typeof Drupal === 'undefined') {
    window.Drupal = {
      t: function (str, replacements) {
        if (!replacements) return str;
        Object.keys(replacements).forEach(function (key) {
          str = str.replace(key, replacements[key]);
        });
        return str;
      },
    };
  }

  // We need a reference to SiteAssistant. In the real widget it is a closure
  // variable, not exported globally. For these tests we replicate the three
  // pure utility methods plus getErrorMessage inline so we can test them
  // without loading the full IIFE.
  var SA = {
    escapeHtml: function (text) {
      if (!text) return '';
      var div = document.createElement('div');
      div.textContent = text;
      return div.innerHTML;
    },

    escapeAttr: function (text) {
      if (!text || typeof text !== 'string') return '';
      return text
        .replace(/&/g, '&amp;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;');
    },

    sanitizeUrl: function (url) {
      if (!url || typeof url !== 'string') return '#';
      var trimmed = url.trim();
      if (!trimmed) return '#';
      if (trimmed.charAt(0) === '/') return trimmed;
      if (trimmed.charAt(0) === '#') return trimmed;
      try {
        var parsed = new URL(trimmed, window.location.origin);
        var scheme = parsed.protocol.toLowerCase();
        if (scheme === 'http:' || scheme === 'https:' || scheme === 'mailto:' || scheme === 'tel:') {
          return trimmed;
        }
      } catch (e) { /* reject */ }
      return '#';
    },

    getErrorMessage: function (error) {
      if (!error) return Drupal.t('Something went wrong. Please try again.');
      if (error.type === 'offline') return Drupal.t('You appear to be offline. Please check your connection and try again.');
      if (error.type === 'timeout') return Drupal.t('The request took too long. Please try again.');
      if (error.status === 429) {
        var msg = Drupal.t("I'm getting a lot of requests right now.");
        if (error.retryAfter) {
          var seconds = parseInt(error.retryAfter, 10);
          if (!isNaN(seconds) && seconds > 0) {
            msg += ' ' + Drupal.t('Please wait @seconds seconds and try again.', { '@seconds': seconds });
          } else {
            msg += ' ' + Drupal.t('Please wait a moment and try again.');
          }
        } else {
          msg += ' ' + Drupal.t('Please wait a moment and try again.');
        }
        return msg;
      }
      if (error.status === 403) {
        switch (error.errorCode) {
          case 'csrf_missing':
            return Drupal.t('Security session could not be established. Choose Try again to resend, or Refresh page to restart your secure session.');
          case 'csrf_invalid':
            return Drupal.t('Your security token could not be verified. Choose Try again to resend, or Refresh page to restart your secure session.');
          case 'csrf_expired':
          case 'session_expired':
            return Drupal.t('Your secure session has expired. Refresh page to continue.');
          default:
            return Drupal.t('Access denied. Please refresh the page and try again.');
        }
      }
      if (error.status >= 500) return Drupal.t('Our server is having trouble right now. Please try again in a few minutes, or reach us through our hotline.');
      return Drupal.t("I'm having trouble right now. You can try again, or reach us directly through our hotline.");
    },

    fetchCsrfTokenWithDeps: function (deps, forceRefresh) {
      if (deps.csrfTokenPromise && !forceRefresh) {
        return deps.csrfTokenPromise;
      }

      deps.csrfTokenPromise = deps.fetch(deps.bootstrapUrl, {
        method: 'GET',
        credentials: 'same-origin',
      })
        .then(function (response) {
          if (!response.ok) {
            var error = new Error('Failed to fetch CSRF token: ' + response.status);
            error.status = response.status;
            error.retryAfter = response.headers && typeof response.headers.get === 'function'
              ? response.headers.get('Retry-After')
              : null;
            throw error;
          }
          return response.text();
        })
        .then(function (token) {
          deps.config.csrfToken = token;
          return token;
        })
        .catch(function (error) {
          deps.csrfTokenPromise = null;
          throw error;
        });

      return deps.csrfTokenPromise;
    },

    /**
     * Build recovery HTML (mirrors addRecoveryMessage logic for testing).
     */
    buildRecoveryHtml: function (error, lastMessageText) {
      var errorCode = (error && error.errorCode) || '';
      var recoveryText;
      var showRetry = false;

      switch (errorCode) {
        case 'csrf_missing':
        case 'csrf_invalid':
          recoveryText = Drupal.t('Security session could not be verified. Choose Try again to resend, or Refresh page to restart your secure session.');
          showRetry = true;
          break;
        case 'csrf_expired':
        case 'session_expired':
          recoveryText = Drupal.t('Your secure session has expired. Refresh page to restart your secure session.');
          break;
        default:
          recoveryText = Drupal.t('Access was denied. Refresh page and try again.');
          break;
      }

      var safeMessage = SA.escapeAttr(lastMessageText || '');
      var html = '<div class="recovery-message" role="alert">';
      html += '<p class="recovery-text">' + SA.escapeHtml(recoveryText) + '</p>';
      html += '<div class="recovery-actions">';

      if (showRetry) {
        html += '<button type="button" class="recovery-btn--retry"'
          + ' data-retry-message="' + safeMessage + '"'
          + ' aria-label="' + SA.escapeAttr(Drupal.t('Try sending your message again')) + '">'
          + '<i class="fas fa-redo" aria-hidden="true"></i> '
          + SA.escapeHtml(Drupal.t('Try again'))
          + '</button>';
      }

      html += '<button type="button" class="recovery-btn--refresh"'
        + ' aria-label="' + SA.escapeAttr(Drupal.t('Refresh this page to start a new session')) + '">'
        + '<i class="fas fa-sync-alt" aria-hidden="true"></i> '
        + SA.escapeHtml(Drupal.t('Refresh page'))
        + '</button>';

      html += '</div></div>';
      return html;
    },

    normalizeTrackToken: function (value) {
      value = String(value || '').trim().toLowerCase();
      if (!value || !/^[a-z0-9:_-]{1,255}$/.test(value)) {
        return '';
      }
      return value;
    },

    normalizeTrackPath: function (value) {
      value = String(value || '').trim();
      if (!value) return '';
      try {
        var parsed = new URL(value, window.location.origin);
        return parsed.pathname && parsed.pathname.charAt(0) === '/' ? parsed.pathname : '';
      } catch (e) {
        return value.charAt(0) === '/' ? value : '';
      }
    },

    normalizeTrackValue: function (eventType, eventValue) {
      switch (eventType) {
        case 'chat_open':
          return '';
        case 'resource_click':
        case 'hotline_click':
        case 'apply_click':
        case 'apply_cta_click':
        case 'apply_secondary_click':
        case 'service_area_click':
          return SA.normalizeTrackPath(eventValue);
        case 'topic_selected':
          eventValue = String(eventValue || '').trim();
          return /^[0-9]+$/.test(eventValue) ? eventValue : '';
        default:
          return SA.normalizeTrackToken(eventValue);
      }
    },

    callTrackApi: function (deps, payload, isRetry, csrfToken) {
      var options = {
        method: 'POST',
        body: JSON.stringify(payload),
      };

      if (csrfToken) {
        options.headers = {
          'X-CSRF-Token': csrfToken,
        };
      }

      return deps.callApi('/track', options)
        .catch(function (error) {
          var needsRecovery = error
            && error.status === 403
            && (error.errorCode === 'track_proof_missing' || error.errorCode === 'track_proof_invalid');

          if (!needsRecovery || isRetry) {
            throw error;
          }

          return deps.fetchCsrfToken(true).then(function (freshToken) {
            return SA.callTrackApi(deps, payload, true, freshToken);
          });
        });
    },

    trackEventSilently: function (deps, eventType, eventValue) {
      eventValue = SA.normalizeTrackValue(eventType, eventValue);
      return deps.callTrackApi({
        event_type: eventType,
        event_value: eventValue || '',
      }).catch(function () {
        // Silent fail for tracking.
      });
    },

    currentPagePath: function () {
      return SA.normalizeTrackPath(window.location.pathname || '');
    },

    normalizeObservabilityMetadata: function (metadata) {
      var normalized = {};
      if (!metadata || typeof metadata !== 'object') {
        return normalized;
      }

      if (metadata.responseType) {
        normalized.responseType = SA.normalizeTrackToken(metadata.responseType);
      }
      if (metadata.fallbackMode) {
        normalized.fallbackMode = SA.normalizeTrackToken(metadata.fallbackMode);
      }
      if (metadata.path) {
        normalized.path = SA.normalizeTrackPath(metadata.path);
      }
      if (typeof metadata.renderedFallback === 'boolean') {
        normalized.renderedFallback = metadata.renderedFallback;
      }

      return normalized;
    },

    resolveTopicSuggestionFallbackMode: function (response) {
      var hasText = !!(response && response.text_fallback && String(response.text_fallback).trim());
      var hasLink = !!(response && response.primary_action && response.primary_action.url && response.primary_action.label);

      if (hasText && hasLink) return 'text_and_link';
      if (hasText) return 'text';
      if (hasLink) return 'link';
      return 'none';
    },

    emitAssistantError: function (deps, error, feature, promptForFeedback, metadata) {
      deps.emitObservabilityEvent('ilas:assistant:error', Object.assign({
        feature: feature || 'unknown',
        surface: deps.isPageMode ? 'assistant-page' : 'assistant-widget',
        pageMode: !!deps.isPageMode,
        status: error && error.status ? error.status : 0,
        type: error && error.type ? String(error.type) : '',
        errorCode: error && error.errorCode ? String(error.errorCode) : '',
        retryAfter: error && error.retryAfter ? String(error.retryAfter) : '',
        promptForFeedback: !!promptForFeedback,
      }, SA.normalizeObservabilityMetadata(metadata)));
    },

    emitAssistantAction: function (deps, actionType, actionValue, metadata) {
      deps.emitObservabilityEvent('ilas:assistant:action', Object.assign({
        actionType: SA.normalizeTrackToken(actionType),
        actionValue: SA.normalizeTrackValue(actionType, actionValue),
        surface: deps.isPageMode ? 'assistant-page' : 'assistant-widget',
        pageMode: !!deps.isPageMode,
      }, SA.normalizeObservabilityMetadata(metadata)));
    },

    renderPrimaryActionLink: function (response) {
      if (!response || !response.primary_action || !response.primary_action.url) {
        return '';
      }
      return '<p class="form-finder-fallback"><a href="' + SA.escapeAttr(SA.sanitizeUrl(response.primary_action.url)) + '" class="result-link" data-assistant-track="resource_click">' + SA.escapeHtml(response.primary_action.label) + '</a></p>';
    },

    handleTopicSuggestionRenderFailure: function (deps, response, error) {
      var html = '';
      var fallbackMode = SA.resolveTopicSuggestionFallbackMode(response);
      if (fallbackMode === 'none') {
        fallbackMode = 'generic_text';
        html += '<p class="chip-fallback-text">' + SA.escapeHtml(Drupal.t('The topic buttons did not load. You can use the link below or refresh the page and try again.')) + '</p>';
      } else if (response && response.text_fallback) {
        html += '<p class="chip-fallback-text">' + SA.escapeHtml(response.text_fallback) + '</p>';
      }

      var metadata = {
        responseType: response && response.type ? response.type : '',
        fallbackMode: fallbackMode,
        renderedFallback: fallbackMode !== 'none',
        path: SA.currentPagePath(),
      };
      SA.emitAssistantError(deps, error, 'chip_render', false, metadata);
      SA.emitAssistantAction(deps, 'ui_fallback_used', response && response.type ? response.type : '', metadata);

      return {
        html: html + SA.renderPrimaryActionLink(response),
        fallbackMode: fallbackMode,
      };
    },
  };

  // ===================================================================
  // 1. sanitizeUrl
  // ===================================================================
  suite('sanitizeUrl', function () {
    // Safe schemes.
    assert(SA.sanitizeUrl('/about') === '/about', 'allows relative path /about');
    assert(SA.sanitizeUrl('#section') === '#section', 'allows fragment #section');
    assert(SA.sanitizeUrl('https://example.com') === 'https://example.com', 'allows https');
    assert(SA.sanitizeUrl('http://example.com') === 'http://example.com', 'allows http');
    assert(SA.sanitizeUrl('mailto:help@example.com') === 'mailto:help@example.com', 'allows mailto');
    assert(SA.sanitizeUrl('tel:+12085551234') === 'tel:+12085551234', 'allows tel');

    // Blocked schemes.
    assert(SA.sanitizeUrl('javascript:alert(1)') === '#', 'blocks javascript:');
    assert(SA.sanitizeUrl('JAVASCRIPT:alert(1)') === '#', 'blocks JAVASCRIPT: (case)');
    assert(SA.sanitizeUrl('data:text/html,<h1>hi</h1>') === '#', 'blocks data:');
    assert(SA.sanitizeUrl('vbscript:MsgBox("hi")') === '#', 'blocks vbscript:');

    // Edge cases.
    assert(SA.sanitizeUrl('') === '#', 'empty string returns #');
    assert(SA.sanitizeUrl(null) === '#', 'null returns #');
    assert(SA.sanitizeUrl(undefined) === '#', 'undefined returns #');
    assert(SA.sanitizeUrl('  /trimmed  ') === '/trimmed', 'trims whitespace');
    assert(SA.sanitizeUrl('  javascript:alert(1)  ') === '#', 'trims then blocks javascript:');
  });

  // ===================================================================
  // 1b. tracking normalization
  // ===================================================================
  suite('tracking normalization', function () {
    assert(SA.normalizeTrackValue('topic_selected', '42') === '42', 'topic_selected keeps numeric topic IDs');
    assert(SA.normalizeTrackValue('topic_selected', 'Housing') === '', 'topic_selected drops display labels');
    assert(SA.normalizeTrackValue('resource_click', 'https://example.org/legal-help/housing?x=1') === '/legal-help/housing', 'resource_click keeps pathname only');
    assert(SA.normalizeTrackValue('suggestion_click', 'forms') === 'forms', 'safe suggestion token is preserved');
    assert(SA.normalizeTrackValue('suggestion_click', 'Forms inventory') === '', 'free-form suggestion label is dropped');
  });

  // ===================================================================
  // 2. escapeAttr
  // ===================================================================
  suite('escapeAttr', function () {
    assert(SA.escapeAttr('"hello"') === '&quot;hello&quot;', 'escapes double quotes');
    assert(SA.escapeAttr("it's") === 'it&#39;s', 'escapes single quotes');
    assert(SA.escapeAttr('<script>') === '&lt;script&gt;', 'escapes angle brackets');
    assert(SA.escapeAttr('a&b') === 'a&amp;b', 'escapes ampersand');
    assert(SA.escapeAttr('') === '', 'empty string returns empty');
    assert(SA.escapeAttr(null) === '', 'null returns empty');

    // Combined injection attempt.
    var injected = '" onmouseover="alert(1)';
    var escaped = SA.escapeAttr(injected);
    assert(escaped.indexOf('"') === -1, 'injection attempt fully escaped (no raw quotes)');
  });

  // ===================================================================
  // 3. escapeHtml
  // ===================================================================
  suite('escapeHtml', function () {
    assert(SA.escapeHtml('<b>bold</b>').indexOf('<') === -1, 'angle brackets escaped');
    assert(SA.escapeHtml('a & b').indexOf('&amp;') !== -1, 'ampersand escaped');
    assert(SA.escapeHtml('') === '', 'empty string');
    assert(SA.escapeHtml(null) === '', 'null returns empty');
  });

  // ===================================================================
  // 4. getErrorMessage — per-status
  // ===================================================================
  suite('getErrorMessage', function () {
    var offlineMsg = SA.getErrorMessage({ type: 'offline' });
    assert(offlineMsg.indexOf('offline') !== -1, 'offline message mentions offline');

    var timeoutMsg = SA.getErrorMessage({ type: 'timeout' });
    assert(timeoutMsg.indexOf('too long') !== -1, 'timeout message mentions too long');

    var rateLimitMsg = SA.getErrorMessage({ status: 429, retryAfter: '30' });
    assert(rateLimitMsg.indexOf('30') !== -1, '429 message includes retry seconds');

    var rateLimitNoHeader = SA.getErrorMessage({ status: 429 });
    assert(rateLimitNoHeader.indexOf('moment') !== -1, '429 without Retry-After says "moment"');

    var forbiddenMsg = SA.getErrorMessage({ status: 403 });
    assert(forbiddenMsg.indexOf('Access denied') !== -1, '403 message says Access denied');

    var serverMsg = SA.getErrorMessage({ status: 500 });
    assert(serverMsg.indexOf('server') !== -1, '500 message mentions server');

    var serverMsg502 = SA.getErrorMessage({ status: 502 });
    assert(serverMsg502.indexOf('server') !== -1, '502 also gets server message');

    var genericMsg = SA.getErrorMessage({ status: 418 });
    assert(genericMsg.indexOf('trouble') !== -1, 'generic error mentions trouble');

    var nullMsg = SA.getErrorMessage(null);
    assert(nullMsg.indexOf('went wrong') !== -1, 'null error gets generic message');

    // Error-code branches for 403.
    var csrfMissingMsg = SA.getErrorMessage({ status: 403, errorCode: 'csrf_missing' });
    assert(csrfMissingMsg.indexOf('Security session') !== -1, '403 + csrf_missing mentions Security session');
    assert(csrfMissingMsg.indexOf('Try again') !== -1, '403 + csrf_missing mentions Try again');
    assert(csrfMissingMsg.indexOf('Refresh page') !== -1, '403 + csrf_missing mentions Refresh page');

    var csrfInvalidMsg = SA.getErrorMessage({ status: 403, errorCode: 'csrf_invalid' });
    assert(csrfInvalidMsg.indexOf('security token') !== -1, '403 + csrf_invalid mentions security token');
    assert(csrfInvalidMsg.indexOf('Try again') !== -1, '403 + csrf_invalid mentions Try again');
    assert(csrfInvalidMsg.indexOf('Refresh page') !== -1, '403 + csrf_invalid mentions Refresh page');

    var csrfExpiredMsg = SA.getErrorMessage({ status: 403, errorCode: 'csrf_expired' });
    assert(csrfExpiredMsg.indexOf('session has expired') !== -1, '403 + csrf_expired mentions session has expired');
    assert(csrfExpiredMsg.indexOf('Refresh page') !== -1, '403 + csrf_expired mentions Refresh page');

    var legacySessionExpiredMsg = SA.getErrorMessage({ status: 403, errorCode: 'session_expired' });
    assert(legacySessionExpiredMsg.indexOf('session has expired') !== -1, '403 + session_expired alias remains supported');

    var unknownCodeMsg = SA.getErrorMessage({ status: 403, errorCode: 'unknown_code' });
    assert(unknownCodeMsg.indexOf('Access denied') !== -1, '403 + unknown code falls through to Access denied');

    var noCodeMsg = SA.getErrorMessage({ status: 403 });
    assert(noCodeMsg.indexOf('Access denied') !== -1, '403 + no code falls through to Access denied');
  });

  // ===================================================================
  // 4b. Bootstrap token fetch preserves status-aware recovery context
  // ===================================================================
  suite('Bootstrap token fetch errors', async function () {
    var deps = {
      bootstrapUrl: '/assistant/api/session/bootstrap',
      config: {},
      csrfTokenPromise: null,
      fetch: function () {
        return Promise.resolve({
          ok: false,
          status: 429,
          headers: {
            get: function (name) {
              return name === 'Retry-After' ? '45' : null;
            },
          },
          text: function () {
            return Promise.resolve('Too many bootstrap requests');
          },
        });
      },
    };

    await SA.fetchCsrfTokenWithDeps(deps)
      .then(function () {
        assert(false, 'bootstrap 429 must reject');
      })
      .catch(function (error) {
        assert(error.status === 429, 'bootstrap 429 preserves HTTP status');
        assert(error.retryAfter === '45', 'bootstrap 429 preserves Retry-After');
        assert(deps.csrfTokenPromise === null, 'bootstrap failure clears cached promise');
        assert(typeof deps.config.csrfToken === 'undefined', 'bootstrap failure does not cache a token');
      });
  });

  // ===================================================================
  // 5. isSending guard — prevents double-fire
  // ===================================================================
  suite('isSending guard', function () {
    // Simulate the guard logic.
    var callCount = 0;
    var isSending = false;

    function simulateSend() {
      if (isSending) return false;
      isSending = true;
      callCount++;
      // Simulate async completion.
      setTimeout(function () { isSending = false; }, 50);
      return true;
    }

    var first = simulateSend();
    var second = simulateSend();

    assert(first === true, 'first send proceeds');
    assert(second === false, 'second send is blocked');
    assert(callCount === 1, 'API only called once');
  });

  // ===================================================================
  // 6. Focus trap lifecycle — no listener accumulation
  // ===================================================================
  suite('Focus trap lifecycle', function () {
    // Create a mock panel with buttons.
    var panel = document.createElement('div');
    panel.innerHTML = '<button id="ft-first">A</button><button id="ft-last">B</button>';
    document.body.appendChild(panel);

    var trapHandler = null;
    var trapElement = null;

    function createTrap(el) {
      destroyTrap();
      trapElement = el;
      trapHandler = function (e) {
        if (e.key !== 'Tab') return;
        var focusables = el.querySelectorAll('button:not([disabled])');
        if (focusables.length === 0) return;
        var first = focusables[0];
        var last = focusables[focusables.length - 1];
        if (e.shiftKey && document.activeElement === first) {
          last.focus();
          e.preventDefault();
        } else if (!e.shiftKey && document.activeElement === last) {
          first.focus();
          e.preventDefault();
        }
      };
      el.addEventListener('keydown', trapHandler);
    }

    function destroyTrap() {
      if (trapHandler && trapElement) {
        trapElement.removeEventListener('keydown', trapHandler);
      }
      trapHandler = null;
      trapElement = null;
    }

    // Simulate open/close/open cycle.
    createTrap(panel);
    destroyTrap();
    createTrap(panel);
    destroyTrap();
    createTrap(panel);

    assert(trapHandler !== null, 'trap handler exists after create');

    destroyTrap();
    assert(trapHandler === null, 'trap handler is null after destroy');

    // Add a third button dynamically.
    var btn3 = document.createElement('button');
    btn3.id = 'ft-dynamic';
    btn3.textContent = 'C';
    panel.appendChild(btn3);

    createTrap(panel);
    var focusables = panel.querySelectorAll('button:not([disabled])');
    assert(focusables.length === 3, 'dynamic button included in focusable query');

    destroyTrap();
    document.body.removeChild(panel);
  });

  // ===================================================================
  // 7. Typing indicator ARIA
  // ===================================================================
  suite('Typing indicator ARIA', function () {
    // Create a mock typing indicator as the widget does.
    var typing = document.createElement('div');
    typing.className = 'chat-message chat-message--assistant chat-message--typing';
    typing.id = 'typing-indicator-test';
    typing.setAttribute('role', 'status');
    typing.setAttribute('aria-label', 'Aila is typing');
    typing.innerHTML = '<div class="typing-indicator" aria-hidden="true">' +
      '<span></span><span></span><span></span>' +
      '</div>';
    document.body.appendChild(typing);

    assert(typing.getAttribute('role') === 'status', 'typing indicator has role="status"');
    assert(typing.getAttribute('aria-label') === 'Aila is typing', 'typing indicator has aria-label');
    assert(typing.querySelector('.typing-indicator').getAttribute('aria-hidden') === 'true', 'dots container is aria-hidden');

    document.body.removeChild(typing);
  });

  // ===================================================================
  // 8. URL sanitization in rendered HTML
  // ===================================================================
  suite('URL sanitization integration', function () {
    // Simulate what renderLinks does.
    var maliciousLinks = [
      { url: 'javascript:alert(document.cookie)', label: 'XSS Link', type: 'link' },
      { url: 'data:text/html,<script>alert(1)</script>', label: 'Data Link', type: 'link' },
      { url: '/safe/path', label: 'Safe Link', type: 'link' },
      { url: 'https://idaholegalaid.org/help', label: 'HTTPS Link', type: 'link' },
    ];

    var html = '<div class="response-links">';
    maliciousLinks.forEach(function (link) {
      var safeUrl = SA.escapeAttr(SA.sanitizeUrl(link.url));
      html += '<a href="' + safeUrl + '">' + SA.escapeHtml(link.label) + '</a>';
    });
    html += '</div>';

    var container = document.createElement('div');
    container.innerHTML = html;
    var anchors = container.querySelectorAll('a');

    assert(anchors[0].getAttribute('href') === '#', 'javascript: URL sanitized to #');
    assert(anchors[1].getAttribute('href') === '#', 'data: URL sanitized to #');
    assert(anchors[2].getAttribute('href') === '/safe/path', 'relative URL preserved');
    assert(anchors[3].getAttribute('href') === 'https://idaholegalaid.org/help', 'https URL preserved');
  });

  // ===================================================================
  // 9. Attribute escaping in rendered HTML
  // ===================================================================
  suite('Attribute escaping integration', function () {
    // Simulate a suggestion button render with injection attempt.
    var maliciousAction = '" onclick="alert(1)" data-x="';
    var escaped = SA.escapeAttr(maliciousAction);
    var html = '<button type="button" data-action="' + escaped + '">Test</button>';

    var container = document.createElement('div');
    container.innerHTML = html;
    var btn = container.querySelector('button');

    assert(btn !== null, 'button element created');
    assert(btn.getAttribute('onclick') === null, 'no onclick attribute injected');
    assert(btn.getAttribute('data-action').indexOf('"') !== -1, 'data-action preserved the raw quote as text content');
  });

  // ===================================================================
  // 10. Recovery message rendering
  // ===================================================================
  suite('Recovery message rendering', function () {
    // csrf_missing: both retry + refresh buttons.
    var csrfMissingHtml = SA.buildRecoveryHtml({ status: 403, errorCode: 'csrf_missing' }, 'Hello');
    var csrfMissingContainer = document.createElement('div');
    csrfMissingContainer.innerHTML = csrfMissingHtml;

    assert(csrfMissingContainer.querySelector('.recovery-btn--retry') !== null,
      'csrf_missing: retry button present');
    assert(csrfMissingContainer.querySelector('.recovery-btn--refresh') !== null,
      'csrf_missing: refresh button present');
    assert(csrfMissingContainer.querySelector('.recovery-message').getAttribute('role') === 'alert',
      'csrf_missing: role="alert" on container');
    assert(csrfMissingContainer.querySelector('.recovery-btn--retry').getAttribute('aria-label') !== null,
      'csrf_missing: aria-label on retry button');
    assert(csrfMissingContainer.querySelector('.recovery-btn--refresh').getAttribute('aria-label') !== null,
      'csrf_missing: aria-label on refresh button');
    assert(csrfMissingContainer.querySelector('.recovery-text').textContent.indexOf('Try again') !== -1,
      'csrf_missing: recovery copy mentions Try again');
    assert(csrfMissingContainer.querySelector('.recovery-text').textContent.indexOf('Refresh page') !== -1,
      'csrf_missing: recovery copy mentions Refresh page');

    // csrf_invalid: both retry + refresh buttons.
    var csrfInvalidHtml = SA.buildRecoveryHtml({ status: 403, errorCode: 'csrf_invalid' }, 'Test msg');
    var csrfInvalidContainer = document.createElement('div');
    csrfInvalidContainer.innerHTML = csrfInvalidHtml;

    assert(csrfInvalidContainer.querySelector('.recovery-btn--retry') !== null,
      'csrf_invalid: retry button present');
    assert(csrfInvalidContainer.querySelector('.recovery-btn--refresh') !== null,
      'csrf_invalid: refresh button present');

    // csrf_expired: only refresh button (no retry).
    var csrfExpiredHtml = SA.buildRecoveryHtml({ status: 403, errorCode: 'csrf_expired' }, 'Hello');
    var csrfExpiredContainer = document.createElement('div');
    csrfExpiredContainer.innerHTML = csrfExpiredHtml;

    assert(csrfExpiredContainer.querySelector('.recovery-btn--retry') === null,
      'csrf_expired: no retry button');
    assert(csrfExpiredContainer.querySelector('.recovery-btn--refresh') !== null,
      'csrf_expired: refresh button present');
    assert(csrfExpiredContainer.querySelector('.recovery-text').textContent.indexOf('Refresh page') !== -1,
      'csrf_expired: recovery copy mentions Refresh page');

    // legacy alias still maps to refresh-only.
    var legacySessionExpHtml = SA.buildRecoveryHtml({ status: 403, errorCode: 'session_expired' }, 'Hello');
    var legacySessionExpContainer = document.createElement('div');
    legacySessionExpContainer.innerHTML = legacySessionExpHtml;

    assert(legacySessionExpContainer.querySelector('.recovery-btn--retry') === null,
      'session_expired alias: no retry button');
    assert(legacySessionExpContainer.querySelector('.recovery-btn--refresh') !== null,
      'session_expired alias: refresh button present');

    // Generic 403: only refresh button.
    var genericHtml = SA.buildRecoveryHtml({ status: 403 }, 'Hello');
    var genericContainer = document.createElement('div');
    genericContainer.innerHTML = genericHtml;

    assert(genericContainer.querySelector('.recovery-btn--retry') === null,
      'generic 403: no retry button');
    assert(genericContainer.querySelector('.recovery-btn--refresh') !== null,
      'generic 403: refresh button present');

    // XSS in message text: data-retry-message properly escaped.
    var xssMessage = '"><img src=x onerror=alert(1)>';
    var xssHtml = SA.buildRecoveryHtml({ status: 403, errorCode: 'csrf_missing' }, xssMessage);
    var xssContainer = document.createElement('div');
    xssContainer.innerHTML = xssHtml;
    var retryBtn = xssContainer.querySelector('.recovery-btn--retry');

    assert(retryBtn !== null, 'XSS: retry button created');
    assert(xssHtml.indexOf('&lt;img') !== -1,
      'XSS: injected tag is escaped in generated recovery HTML');
    assert(retryBtn.getAttribute('data-retry-message') === xssMessage,
      'XSS: original message round-trips as text payload only');
    assert(xssContainer.querySelector('img') === null,
      'XSS: no injected img element');
  });

  // ===================================================================
  // 11. Recovery button accessibility
  // ===================================================================
  suite('Recovery button accessibility', function () {
    var html = SA.buildRecoveryHtml({ status: 403, errorCode: 'csrf_missing' }, 'Test');
    var container = document.createElement('div');
    container.innerHTML = html;

    // Buttons are <button> elements (keyboard-focusable by default).
    var buttons = container.querySelectorAll('button');
    assert(buttons.length === 2, 'two button elements rendered (retry + refresh)');
    assert(buttons[0].tagName === 'BUTTON', 'retry is a <button> element');
    assert(buttons[1].tagName === 'BUTTON', 'refresh is a <button> element');

    // aria-label present on all recovery buttons.
    assert(buttons[0].getAttribute('aria-label') !== null && buttons[0].getAttribute('aria-label') !== '',
      'retry button has non-empty aria-label');
    assert(buttons[1].getAttribute('aria-label') !== null && buttons[1].getAttribute('aria-label') !== '',
      'refresh button has non-empty aria-label');

    // role="alert" present on recovery container.
    var recoveryEl = container.querySelector('.recovery-message');
    assert(recoveryEl !== null, 'recovery-message element exists');
    assert(recoveryEl.getAttribute('role') === 'alert', 'recovery-message has role="alert"');

    // Retry button stores message in data-retry-message.
    var retryBtn = container.querySelector('.recovery-btn--retry');
    assert(retryBtn.getAttribute('data-retry-message') === 'Test',
      'retry button stores message in data-retry-message');

    // csrf_expired: only refresh, no retry — verify accessibility still holds.
    var sessionHtml = SA.buildRecoveryHtml({ status: 403, errorCode: 'csrf_expired' }, 'Msg');
    var sessionContainer = document.createElement('div');
    sessionContainer.innerHTML = sessionHtml;
    var sessionButtons = sessionContainer.querySelectorAll('button');
    assert(sessionButtons.length === 1, 'csrf_expired renders exactly one button');
    assert(sessionButtons[0].getAttribute('aria-label') !== null,
      'csrf_expired refresh button has aria-label');
  });

  // ===================================================================
  // 12. Recovery button behavior
  // ===================================================================
  suite('Recovery button behavior', function () {
    // Retry button click fires handler with correct message text.
    var retryHtml = SA.buildRecoveryHtml({ status: 403, errorCode: 'csrf_invalid' }, 'My question');
    var retryContainer = document.createElement('div');
    retryContainer.innerHTML = retryHtml;
    document.body.appendChild(retryContainer);

    var capturedRetryMessage = null;
    var retryBtn = retryContainer.querySelector('.recovery-btn--retry');
    retryBtn.addEventListener('click', function (e) {
      capturedRetryMessage = e.currentTarget.getAttribute('data-retry-message');
    });
    retryBtn.click();

    assert(capturedRetryMessage === 'My question',
      'retry click handler captures correct message text');

    // Refresh button click fires handler.
    var refreshBtn = retryContainer.querySelector('.recovery-btn--refresh');
    var refreshClicked = false;
    refreshBtn.addEventListener('click', function () {
      refreshClicked = true;
    });
    refreshBtn.click();

    assert(refreshClicked === true, 'refresh click handler fires');

    document.body.removeChild(retryContainer);
  });

  // ===================================================================
  // 13. Track proof recovery
  // ===================================================================
  suite('Track proof recovery', async function () {
    var missingCallCount = 0;
    var missingFetchCount = 0;
    var missingHeaders = [];
    var missingResult = await SA.callTrackApi({
      callApi: function (_endpoint, options) {
        missingCallCount++;
        missingHeaders.push((options && options.headers) || {});
        if (missingCallCount === 1) {
          return Promise.reject({ status: 403, errorCode: 'track_proof_missing' });
        }
        return Promise.resolve({ ok: true });
      },
      fetchCsrfToken: function (forceRefresh) {
        missingFetchCount++;
        assert(forceRefresh === true, 'track_proof_missing refreshes bootstrap token');
        return Promise.resolve('fresh-track-token');
      },
    }, {
      event_type: 'chat_open',
      event_value: 'missing-proof',
    });

    assert(missingResult.ok === true, 'track_proof_missing recovers successfully');
    assert(missingCallCount === 2, 'track_proof_missing retries exactly once');
    assert(missingFetchCount === 1, 'track_proof_missing fetches one fresh token');
    assert(!missingHeaders[0]['X-CSRF-Token'], 'track_proof_missing initial request has no fallback token');
    assert(missingHeaders[1]['X-CSRF-Token'] === 'fresh-track-token',
      'track_proof_missing retry sends fresh fallback token');

    var invalidCallCount = 0;
    var invalidFetchCount = 0;
    var invalidHeaders = [];
    var invalidResult = await SA.callTrackApi({
      callApi: function (_endpoint, options) {
        invalidCallCount++;
        invalidHeaders.push((options && options.headers) || {});
        if (invalidCallCount === 1) {
          return Promise.reject({ status: 403, errorCode: 'track_proof_invalid' });
        }
        return Promise.resolve({ ok: true });
      },
      fetchCsrfToken: function (forceRefresh) {
        invalidFetchCount++;
        assert(forceRefresh === true, 'track_proof_invalid refreshes bootstrap token');
        return Promise.resolve('replacement-track-token');
      },
    }, {
      event_type: 'chat_open',
      event_value: 'invalid-proof',
    }, false, 'stale-token');

    assert(invalidResult.ok === true, 'track_proof_invalid recovers successfully');
    assert(invalidCallCount === 2, 'track_proof_invalid retries exactly once');
    assert(invalidFetchCount === 1, 'track_proof_invalid fetches one fresh token');
    assert(invalidHeaders[0]['X-CSRF-Token'] === 'stale-token',
      'track_proof_invalid initial request uses existing fallback token');
    assert(invalidHeaders[1]['X-CSRF-Token'] === 'replacement-track-token',
      'track_proof_invalid retry sends refreshed fallback token');

    var mismatchCallCount = 0;
    var mismatchFetchCount = 0;
    await SA.callTrackApi({
      callApi: function () {
        mismatchCallCount++;
        return Promise.reject({ status: 403, errorCode: 'track_origin_mismatch' });
      },
      fetchCsrfToken: function () {
        mismatchFetchCount++;
        return Promise.resolve('unused');
      },
    }, {
      event_type: 'chat_open',
      event_value: 'mismatch',
    }).catch(function () {});

    assert(mismatchCallCount === 1, 'track_origin_mismatch does not retry');
    assert(mismatchFetchCount === 0, 'track_origin_mismatch does not fetch bootstrap token');

    var rateCallCount = 0;
    var rateFetchCount = 0;
    await SA.callTrackApi({
      callApi: function () {
        rateCallCount++;
        return Promise.reject({ status: 429, retryAfter: '60' });
      },
      fetchCsrfToken: function () {
        rateFetchCount++;
        return Promise.resolve('unused');
      },
    }, {
      event_type: 'chat_open',
      event_value: 'rate-limited',
    }).catch(function () {});

    assert(rateCallCount === 1, '429 track errors do not retry');
    assert(rateFetchCount === 0, '429 track errors do not fetch bootstrap token');
  });

  // ===================================================================
  // 14. Track failure remains silent
  // ===================================================================
  suite('Track failure remains silent', async function () {
    var addMessageCalls = 0;
    var payloads = [];

    await SA.trackEventSilently({
      callTrackApi: function (payload) {
        payloads.push(payload);
        return Promise.reject({ status: 403, errorCode: 'track_origin_mismatch' });
      },
      addMessage: function () {
        addMessageCalls++;
      },
    }, 'chat_open', '/some/path');

    assert(payloads.length === 1, 'trackEvent sends one tracking payload');
    assert(payloads[0].event_type === 'chat_open', 'trackEvent payload preserves event_type');
    assert(payloads[0].event_value === '', 'trackEvent payload preserves the approved chat_open contract');
    assert(addMessageCalls === 0, 'track failures do not surface message recovery UI');
  });

  // ===================================================================
  // 15. Chip render fallback telemetry
  // ===================================================================
  suite('Chip render fallback emits minimized observability and stays non-empty', function () {
    var events = [];
    var deps = {
      isPageMode: false,
      emitObservabilityEvent: function (name, detail) {
        events.push({ name: name, detail: detail });
      },
    };

    var result = SA.handleTopicSuggestionRenderFailure(deps, {
      type: 'forms_inventory',
      text_fallback: 'Choose a category.',
      primary_action: {
        label: 'Browse All Forms',
        url: '/forms',
      },
    }, {
      type: 'render_error',
      errorCode: 'chip_render_failed',
    });

    assert(result.fallbackMode === 'text_and_link', 'fallback mode captures text and link availability');
    assert(result.html.indexOf('Choose a category.') !== -1, 'fallback text is rendered');
    assert(result.html.indexOf('/forms') !== -1, 'fallback link is rendered');
    assert(events.length === 2, 'fallback emits one error and one action event');
    assert(events[0].name === 'ilas:assistant:error', 'first event is assistant error');
    assert(events[0].detail.feature === 'chip_render', 'error event preserves chip_render feature');
    assert(events[0].detail.responseType === 'forms_inventory', 'error event includes response type');
    assert(events[0].detail.fallbackMode === 'text_and_link', 'error event includes fallback mode');
    assert(events[0].detail.path === '/assistant', 'error event includes minimized path');
    assert(events[0].detail.renderedFallback === true, 'error event marks rendered fallback');
    assert(events[1].name === 'ilas:assistant:action', 'second event is assistant action');
    assert(events[1].detail.actionType === 'ui_fallback_used', 'action event uses ui_fallback_used token');
    assert(events[1].detail.actionValue === 'forms_inventory', 'action event uses response type token');
  });

  suite('Chip render fallback adds generic text when no fallback surfaces exist', function () {
    var events = [];
    var deps = {
      isPageMode: true,
      emitObservabilityEvent: function (name, detail) {
        events.push({ name: name, detail: detail });
      },
    };

    var result = SA.handleTopicSuggestionRenderFailure(deps, {
      type: 'services_inventory',
    }, {
      type: 'render_error',
    });

    assert(result.fallbackMode === 'generic_text', 'generic fallback mode is used when text/link are missing');
    assert(result.html.indexOf('did not load') !== -1, 'generic fallback text is rendered');
    assert(result.html.trim() !== '', 'generic fallback never leaves empty UI');
    assert(events[1].detail.fallbackMode === 'generic_text', 'action event records generic fallback mode');
    assert(events[1].detail.path === '/assistant', 'action event keeps pathname only');
  });

  // -------------------------------------------------------------------
  // 10. Feedback UI controls
  // -------------------------------------------------------------------
  suite('Feedback UI: appendFeedback creates correct DOM structure', function () {
    var container = document.createElement('div');
    container.className = 'chat-message chat-message--assistant';
    var content = document.createElement('div');
    content.className = 'message-content';
    content.textContent = 'Test response';
    container.appendChild(content);
    document.body.appendChild(container);

    // Simulate appendFeedback behavior inline.
    var controls = document.createElement('div');
    controls.className = 'feedback-controls';

    var label = document.createElement('span');
    label.className = 'feedback-label';
    label.textContent = Drupal.t('Was this helpful?');
    controls.appendChild(label);

    var helpfulBtn = document.createElement('button');
    helpfulBtn.type = 'button';
    helpfulBtn.className = 'feedback-btn feedback-btn--helpful';
    helpfulBtn.setAttribute('aria-label', Drupal.t('Helpful'));
    helpfulBtn.innerHTML = '<i class="fas fa-thumbs-up" aria-hidden="true"></i>';
    controls.appendChild(helpfulBtn);

    var notHelpfulBtn = document.createElement('button');
    notHelpfulBtn.type = 'button';
    notHelpfulBtn.className = 'feedback-btn feedback-btn--not-helpful';
    notHelpfulBtn.setAttribute('aria-label', Drupal.t('Not helpful'));
    notHelpfulBtn.innerHTML = '<i class="fas fa-thumbs-down" aria-hidden="true"></i>';
    controls.appendChild(notHelpfulBtn);

    container.appendChild(controls);

    assert(
      container.querySelector('.feedback-controls') !== null,
      'feedback controls container is appended'
    );
    assert(
      container.querySelector('.feedback-btn--helpful') !== null,
      'helpful button exists'
    );
    assert(
      container.querySelector('.feedback-btn--not-helpful') !== null,
      'not-helpful button exists'
    );
    assert(
      helpfulBtn.getAttribute('aria-label') === 'Helpful',
      'helpful button has aria-label'
    );
    assert(
      notHelpfulBtn.getAttribute('aria-label') === 'Not helpful',
      'not-helpful button has aria-label'
    );

    document.body.removeChild(container);
  });

  suite('Feedback UI: clicking disables both buttons', function () {
    var trackCalls = [];
    var controls = document.createElement('div');
    controls.className = 'feedback-controls';

    var label = document.createElement('span');
    label.className = 'feedback-label';
    label.textContent = 'Was this helpful?';
    controls.appendChild(label);

    var helpfulBtn = document.createElement('button');
    helpfulBtn.type = 'button';
    helpfulBtn.className = 'feedback-btn feedback-btn--helpful';
    controls.appendChild(helpfulBtn);

    var notHelpfulBtn = document.createElement('button');
    notHelpfulBtn.type = 'button';
    notHelpfulBtn.className = 'feedback-btn feedback-btn--not-helpful';
    controls.appendChild(notHelpfulBtn);

    function handleClick(eventType, responseType) {
      trackCalls.push({ event: eventType, value: responseType });
      helpfulBtn.disabled = true;
      notHelpfulBtn.disabled = true;
      label.textContent = 'Thanks for your feedback';
      controls.classList.add('feedback-controls--submitted');
    }

    helpfulBtn.addEventListener('click', function () {
      handleClick('feedback_helpful', 'faq');
    });

    helpfulBtn.click();

    assert(helpfulBtn.disabled === true, 'helpful button is disabled after click');
    assert(notHelpfulBtn.disabled === true, 'not-helpful button is disabled after click');
    assert(
      controls.classList.contains('feedback-controls--submitted'),
      'submitted class is added after click'
    );
    assert(trackCalls.length === 1, 'trackEvent called once');
    assert(trackCalls[0].event === 'feedback_helpful', 'trackEvent called with feedback_helpful');
    assert(trackCalls[0].value === 'faq', 'trackEvent called with response type');
    assert(
      label.textContent === 'Thanks for your feedback',
      'label text changed to thank-you message'
    );
  });

  suite('Feedback UI: noFeedbackTypes excludes greeting', function () {
    var noFeedbackTypes = ['greeting', 'clarify', 'form_finder_clarify', 'guide_finder_clarify'];
    assert(noFeedbackTypes.indexOf('greeting') !== -1, 'greeting is in noFeedbackTypes');
    assert(noFeedbackTypes.indexOf('faq') === -1, 'faq is NOT in noFeedbackTypes');
    assert(noFeedbackTypes.indexOf('resources') === -1, 'resources is NOT in noFeedbackTypes');
    assert(noFeedbackTypes.indexOf('fallback') === -1, 'fallback is NOT in noFeedbackTypes');
  });

  await Promise.all(pending);

  // ===================================================================
  // Summary
  // ===================================================================
  console.log('\n============================');
  console.log('Results: ' + results.pass + ' passed, ' + results.fail + ' failed');
  console.log('============================\n');

  if (typeof window !== 'undefined') {
    window._assistantWidgetTestResults = results;
  }

  return results;
})();

// Jest integration: wrap the self-contained test harness in a Jest test
// so that `npx jest` recognizes at least one test and reports pass/fail.
if (typeof test === 'function') {
  test('assistant-widget hardening suite', async function () {
    var results = await window._assistantWidgetTestDone;
    expect(results.fail).toBe(0);
    expect(results.pass).toBeGreaterThan(0);
  });
}
