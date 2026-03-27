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
  }

  console.log('\n=== assistant-widget structured selection ===');

  resetEnvironment();
  var calls = [];
  installFetchQueue([
    okText('csrf-token'),
    okJson({
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
    })
  ], calls);

  attachWidget();
  await click(document.querySelector('.quick-action-btn[data-action="forms"]'));

  var firstBody = JSON.parse(String(getMessageCalls(calls)[0].options.body || '{}'));
  var savedState = JSON.parse(String(window.sessionStorage.getItem('ilas_assistant_state') || '{}'));
  var lastUserMessage = document.querySelectorAll('.chat-message--user .message-content');
  var lastUserText = lastUserMessage[lastUserMessage.length - 1].textContent.trim();

  assert(firstBody.context.quickAction === 'forms', 'top-level quick action still sends quickAction');
  assert(firstBody.context.selection.button_id === 'forms', 'top-level quick action sends structured selection button_id');
  assert(firstBody.context.selection.label === 'Forms', 'top-level quick action preserves clicked label');
  assert(savedState.activeSelection.button_id === 'forms', 'session state persists active selection from response');
  assert(lastUserText === 'Forms', 'visible user message preserves exact clicked label');

  resetEnvironment();
  calls = [];
  installFetchQueue([
    okText('csrf-token'),
    okJson({
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
    okJson({
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
  await click(document.querySelector('.topic-suggestion-btn[data-action="forms_family"]'));

  var secondBody = JSON.parse(String(getMessageCalls(calls)[1].options.body || '{}'));
  savedState = JSON.parse(String(window.sessionStorage.getItem('ilas_assistant_state') || '{}'));
  lastUserMessage = document.querySelectorAll('.chat-message--user .message-content');
  lastUserText = lastUserMessage[lastUserMessage.length - 1].textContent.trim();

  assert(secondBody.context.selection.button_id === 'forms_family', 'rendered suggestion click sends structured child button_id');
  assert(secondBody.context.selection.parent_button_id === 'forms', 'rendered suggestion click preserves parent_button_id');
  assert(secondBody.context.selection.label === 'Family & Custody', 'rendered suggestion click preserves exact clicked label');
  assert(savedState.activeSelection.button_id === 'forms_family', 'session state updates to latest active child selection');
  assert(lastUserText === 'Family & Custody', 'child click displays exact suggestion label as the user turn');

  window._assistantWidgetSelectionTestResults = results;
})();
