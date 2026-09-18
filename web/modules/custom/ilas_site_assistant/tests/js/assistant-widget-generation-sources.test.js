/**
 * @jest-environment jsdom
 */

/**
 * @file
 * Rendering contract for grounded-generation "Sources" (TOVR-18).
 *
 * Loads the real widget (see run-assistant-widget-generation-sources.mjs)
 * and drives one message through a stubbed fetch queue.
 */

/* global Drupal, drupalSettings */
window._assistantWidgetGenerationTestDone = (async function () {
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

  async function tick() {
    await new Promise(function (resolve) { setTimeout(resolve, 0); });
  }

  async function waitFor(check, attempts) {
    attempts = attempts || 20;
    for (var i = 0; i < attempts; i++) {
      if (check()) {
        return true;
      }
      await tick();
    }
    return false;
  }

  function resetEnvironment() {
    document.body.innerHTML = '';
    window.sessionStorage.clear();
    Drupal.behaviors = Drupal.behaviors || {};
  }

  function okJson(data) {
    return {
      ok: true,
      status: 200,
      headers: { get: function () { return null; } },
      json: function () { return Promise.resolve(data); },
      text: function () { return Promise.resolve(JSON.stringify(data)); }
    };
  }

  function okText(text) {
    return {
      ok: true,
      status: 200,
      headers: { get: function () { return null; } },
      text: function () { return Promise.resolve(text); },
      json: function () { return Promise.resolve({}); }
    };
  }

  // Route by URL rather than by call order: the widget may reuse a cached
  // bootstrap token across attaches, which would shift a positional queue.
  function installFetchRouter(messageResponse, calls) {
    window.fetch = function (url, options) {
      calls.push({ url: String(url), options: options || {} });
      var target = String(url);
      if (/\/session\/bootstrap$/.test(target)) {
        return Promise.resolve(okText('csrf-token'));
      }
      if (/\/assistant\/api\/message$/.test(target)) {
        return Promise.resolve(okJson(messageResponse));
      }
      return Promise.resolve(okJson({ ok: true }));
    };
  }

  function attachWidget() {
    drupalSettings.ilasSiteAssistant = {
      apiBase: '/assistant/api',
      welcomeMessage: 'Welcome to Aila',
      canonicalUrls: { hotline: '/Legal-Advice-Line', apply: '/apply-for-help' }
    };
    Drupal.behaviors.ilasSiteAssistant.attach(document, drupalSettings);
  }

  async function sendMessage() {
    // Drive the same code path as a real turn: a quick-action click posts to
    // /assistant/api/message after the bootstrap + track calls.
    var button = document.querySelector('.quick-action-btn[data-action="forms"]');
    button.dispatchEvent(new window.MouseEvent('click', { bubbles: true, cancelable: true }));
    for (var i = 0; i < 8; i++) {
      await tick();
    }
  }

  function faqResponse(extra) {
    return Object.assign({
      request_id: '11111111-1111-4111-8111-111111111111',
      type: 'faq',
      message: 'You can apply for help online or by calling the Legal Advice Line.',
      results: [
        { question: 'How do I apply?', answer: 'Apply online or call.', url: '/faq/apply#how' },
        { title: 'Intake hours', description: 'Mon-Wed.', url: '/resources/intake-hours' },
        { question: 'Third question', answer: 'x', url: '/faq/third' }
      ]
    }, extra || {});
  }

  console.log('\n=== assistant-widget grounded generation sources ===');

  // 1. Used generation renders a deduped, capped, text-only sources list.
  resetEnvironment();
  var calls = [];
  installFetchRouter(faqResponse({
      generation: {
        used: true,
        provider: 'cohere',
        model: 'command-a-03-2025',
        citations: [
          { result_index: 1, url: '/resources/intake-hours', title: 'Intake hours' },
          { result_index: 0, url: '/faq/apply#how', title: 'How do I apply?' },
          { result_index: 0, url: '/faq/apply#how', title: 'Duplicate' },
          { url: 'javascript:alert(1)', title: 'Unsafe <b>citation</b>' },
          { result_index: 99, url: '', title: '' },
          { url: 'https://example.org/external', title: 'Fallback <i>fields</i>' }
        ]
      }
    }), calls);
  attachWidget();
  await sendMessage();
  var appeared = await waitFor(function () {
    return document.querySelector('.assistant-sources') !== null;
  }, 40);
  assert(appeared, 'used generation renders an .assistant-sources block');
  var links = Array.prototype.slice.call(document.querySelectorAll('.assistant-sources__link'));
  assert(links.length === 3, 'links are deduped by URL and unsafe/invalid citations are skipped (got ' + links.length + ')');
  assert(links[0] && links[0].getAttribute('href') === '/resources/intake-hours', 'first citation resolves URL from results[result_index]');
  assert(links[0] && links[0].textContent === 'Intake hours', 'resource result title is used as the label');
  assert(links[1] && links[1].textContent === 'How do I apply?', 'FAQ result question is used as the label');
  assert(links[2] && links[2].textContent === 'Fallback <i>fields</i>', 'citation fallback title is rendered as literal text, not HTML');
  assert(links[2] && links[2].getAttribute('href') === 'https://example.org/external', 'citation fallback URL is used when result_index is absent');
  assert(document.querySelector('.assistant-sources__link i') === null, 'no HTML is injected through source titles');
  assert(links.every(function (link) { return link.getAttribute('data-assistant-track') === 'resource_click'; }), 'source links carry click tracking');
  var label = document.querySelector('.assistant-sources__label');
  assert(label && label.textContent === 'Sources:', 'sources label is rendered');
  var assistantMessages = document.querySelectorAll('.chat-message--assistant .message-content');
  var messageText = assistantMessages[assistantMessages.length - 1].textContent;
  assert(messageText.indexOf('You can apply for help online') !== -1, 'generated message text is still rendered from response.message');

  // 2. generation.used=false (fallback) renders no sources block.
  resetEnvironment();
  calls = [];
  installFetchRouter(faqResponse({ generation: { used: false, reason: 'no_citations' } }), calls);
  attachWidget();
  await sendMessage();
  await waitFor(function () {
    return document.querySelectorAll('.faq-results').length > 0;
  }, 40);
  assert(document.querySelector('.assistant-sources') === null, 'fallback generation renders no sources block');

  // 3. No generation key at all (generator absent) renders no sources block.
  resetEnvironment();
  calls = [];
  installFetchRouter(faqResponse(), calls);
  attachWidget();
  await sendMessage();
  await waitFor(function () {
    return document.querySelectorAll('.faq-results').length > 0;
  }, 40);
  assert(document.querySelector('.assistant-sources') === null, 'responses without generation render no sources block');
  assert(document.querySelector('.faq-results') !== null && document.querySelectorAll('.faq-result').length >= 1, 'FAQ result rendering is unchanged');

  window._assistantWidgetGenerationTestResults = results;
})();
