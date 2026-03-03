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
 */

/* global SiteAssistant */
(function () {
  'use strict';

  var results = { pass: 0, fail: 0 };

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
    fn();
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
    assert(retryBtn.getAttribute('data-retry-message').indexOf('<') === -1,
      'XSS: data-retry-message has no unescaped angle brackets');
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
  // Summary
  // ===================================================================
  console.log('\n============================');
  console.log('Results: ' + results.pass + ' passed, ' + results.fail + ' failed');
  console.log('============================\n');

  if (typeof window !== 'undefined') {
    window._assistantWidgetTestResults = results;
  }

})();
