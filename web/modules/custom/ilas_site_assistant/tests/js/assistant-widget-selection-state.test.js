/**
 * @jest-environment jsdom
 */

/* global Drupal, drupalSettings, once */
window._assistantWidgetSelectionTestDone = (async function () {
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

  async function waitForSelector(selector, attempts) {
    attempts = attempts || 10;
    for (var i = 0; i < attempts; i++) {
      var element = document.querySelector(selector);
      if (element) {
        return element;
      }
      await tick();
    }
    return null;
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

  function installFetchQueue(queue, calls) {
    window.fetch = function (url, options) {
      calls.push({
        url: String(url),
        options: options || {}
      });

      var next = queue.shift();
      if (!next) {
        throw new Error('Unexpected fetch: ' + url);
      }

      if (typeof next === 'function') {
        return Promise.resolve(next(url, options));
      }

      return Promise.resolve(next);
    };
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

  function getMessageCalls(calls) {
    return calls.filter(function (call) {
      return /\/assistant\/api\/message$/.test(call.url) && String((call.options && call.options.method) || '').toUpperCase() === 'POST';
    });
  }

  function attachWidget() {
    drupalSettings.ilasSiteAssistant = {
      apiBase: '/assistant/api',
      welcomeMessage: 'Welcome to Aila',
      canonicalUrls: {
        hotline: '/Legal-Advice-Line',
        apply: '/apply-for-help'
      }
    };
    Drupal.behaviors.ilasSiteAssistant.attach(document, drupalSettings);
  }

  async function click(element) {
    element.dispatchEvent(new window.MouseEvent('click', { bubbles: true, cancelable: true }));
    await tick();
    await tick();
    await tick();
    await tick();
  }

  console.log('\n=== assistant-widget structured selection ===');

  resetEnvironment();
  var calls = [];
  installFetchQueue([
    okText('csrf-token'),
    okJson({ ok: true }),
    okJson({
      request_id: '11111111-1111-4111-8111-111111111111',
      type: 'forms_inventory',
      message: 'We have forms and resources organized by legal topic. Choose a category:',
      topic_suggestions: [
        {
          label: 'Family & Custody',
          action: 'forms_family',
          selection: {
            button_id: 'forms_family',
            label: 'Family & Custody',
            parent_button_id: 'forms',
            source: 'response'
          }
        }
      ],
      primary_action: { label: 'Browse All Forms', url: '/forms' },
      active_selection: {
        button_id: 'forms',
        label: 'Forms',
        parent_button_id: '',
        source: 'selection'
      }
    }),
    okJson({ ok: true }),
    okJson({
      request_id: '22222222-2222-4222-8222-222222222222',
      type: 'form_finder_clarify',
      message: 'What type of family law issue are you dealing with?',
      primary_action: { label: 'Browse All Forms', url: '/forms' },
      active_selection: {
        button_id: 'forms_family',
        label: 'Family & Custody',
        parent_button_id: 'forms',
        source: 'selection'
      }
    })
  ], calls);

  attachWidget();
  await click(document.querySelector('.quick-action-btn[data-action="forms"]'));
  await waitFor(function () {
    return getMessageCalls(calls).length >= 1;
  }, 20);

  var firstBody = JSON.parse(String(getMessageCalls(calls)[0].options.body || '{}'));
  var savedState = JSON.parse(String(window.sessionStorage.getItem('ilas_assistant_state') || '{}'));
  var lastUserMessage = document.querySelectorAll('.chat-message--user .message-content');
  var lastUserText = lastUserMessage[lastUserMessage.length - 1].textContent.trim();

  assert(firstBody.context.quickAction === 'forms', 'top-level quick action still sends quickAction');
  assert(firstBody.context.selection.button_id === 'forms', 'top-level quick action sends structured selection button_id');
  assert(firstBody.context.selection.label === 'Forms', 'top-level quick action preserves clicked label');
  assert(savedState.v === 3, 'session state persists under v3 schema');
  assert(savedState.activeSelection.button_id === 'forms', 'session state persists active selection from response');
  assert(savedState.lastResponseRequestId === '11111111-1111-4111-8111-111111111111', 'session state persists the last assistant request ID after first reply');
  assert(savedState.messages[savedState.messages.length - 1].kind === 'response', 'assistant replies persist as structured response messages');
  assert(savedState.messages[savedState.messages.length - 1].response.type === 'forms_inventory', 'first assistant reply snapshot keeps response type');
  assert(lastUserText === 'Forms', 'visible user message preserves exact clicked label');
  var childSuggestion = await waitForSelector('.topic-suggestion-btn[data-action="forms_family"]', 20);
  await click(childSuggestion);
  await waitFor(function () {
    return getMessageCalls(calls).length >= 2;
  }, 20);

  var secondBody = JSON.parse(String(getMessageCalls(calls)[1].options.body || '{}'));
  savedState = JSON.parse(String(window.sessionStorage.getItem('ilas_assistant_state') || '{}'));
  lastUserMessage = document.querySelectorAll('.chat-message--user .message-content');
  lastUserText = lastUserMessage[lastUserMessage.length - 1].textContent.trim();

  assert(secondBody.context.selection.button_id === 'forms_family', 'rendered suggestion click sends structured child button_id');
  assert(secondBody.context.selection.parent_button_id === 'forms', 'rendered suggestion click preserves parent_button_id');
  assert(secondBody.context.selection.label === 'Family & Custody', 'rendered suggestion click preserves exact clicked label');
  assert(savedState.activeSelection.button_id === 'forms_family', 'session state updates to latest active child selection');
  assert(savedState.lastResponseRequestId === '22222222-2222-4222-8222-222222222222', 'session state updates to the latest assistant request ID');
  assert(savedState.messages[savedState.messages.length - 1].kind === 'response', 'latest assistant reply stays in structured response form');
  assert(savedState.messages[savedState.messages.length - 1].response.type === 'form_finder_clarify', 'follow-up assistant reply keeps its response type');
  assert(lastUserText === 'Family & Custody', 'child click displays exact suggestion label as the user turn');

  await tick();
  await tick();
  await tick();
  await tick();

  var restoredState = JSON.stringify({
    v: 2,
    conversationId: '44444444-4444-4444-8444-444444444444',
    lastResponseRequestId: '55555555-5555-4555-8555-555555555555',
    activeSelection: {
      button_id: 'forms_family',
      label: 'Family & Custody',
      parent_button_id: 'forms',
      source: 'selection'
    },
    messages: [
      { role: 'assistant', content: '<p>Restored <strong>conversation</strong></p>', isHtml: true }
    ],
    isOpen: false,
    savedAt: Date.now()
  });

  resetEnvironment();
  window.sessionStorage.setItem('ilas_assistant_state', restoredState);
  calls = [];
  installFetchQueue([
    okText('csrf-token'),
    okJson({ ok: true }),
    okJson({
      request_id: '66666666-6666-4666-8666-666666666666',
      type: 'forms_inventory',
      message: 'Restored turn',
      active_selection: {
        button_id: 'forms',
        label: 'Forms',
        parent_button_id: '',
        source: 'selection'
      }
    })
  ], calls);

  attachWidget();
  await waitFor(function () {
    return document.querySelectorAll('.chat-message--assistant .message-content').length > 0;
  }, 20);
  var restoredMessageText = document.querySelector('.chat-message--assistant .message-content').textContent.replace(/\s+/g, ' ').trim();
  savedState = JSON.parse(String(window.sessionStorage.getItem('ilas_assistant_state') || '{}'));
  assert(restoredMessageText === 'Restored conversation', 'legacy v2 rich assistant turn restores as readable text');
  assert(savedState.v === 3, 'legacy v2 state migrates in place to v3');
  assert(savedState.messages[0].kind === 'text', 'legacy rich assistant turn migrates to structured text');
  assert(savedState.messages[0].text === 'Restored conversation', 'legacy rich assistant turn strips markup during migration');
  assert(!Object.prototype.hasOwnProperty.call(savedState.messages[0], 'isHtml'), 'migrated v3 message no longer stores isHtml');
  await click(document.querySelector('.quick-action-btn[data-action="forms"]'));
  await waitFor(function () {
    return getMessageCalls(calls).length >= 1;
  }, 20);
  await waitFor(function () {
    var state = JSON.parse(String(window.sessionStorage.getItem('ilas_assistant_state') || '{}'));
    return state.lastResponseRequestId === '66666666-6666-4666-8666-666666666666';
  }, 20);

  var restoredBody = JSON.parse(String(getMessageCalls(calls)[0].options.body || '{}'));
  savedState = JSON.parse(String(window.sessionStorage.getItem('ilas_assistant_state') || '{}'));
  assert(restoredBody.conversation_id === '44444444-4444-4444-8444-444444444444', 'restored widget state preserves conversationId across reload');
  assert(Object.prototype.hasOwnProperty.call(savedState, 'lastResponseRequestId'), 'restored widget state keeps request ID tracking live after reload');
  assert(savedState.messages[savedState.messages.length - 1].kind === 'response', 'post-reload assistant reply persists as structured response message');

  window._assistantWidgetSelectionTestResults = results;
})();
