/**
 * @file
 * Shared jsdom harness for the observability.js node:test suites.
 *
 * observability.js is a plain IIFE that reads `Drupal` / `drupalSettings`
 * from the global scope and installs itself on `window`, so each test loads
 * a fresh jsdom window, seeds the globals and stubs, and evals the source
 * inside that window (same approach as run-assistant-widget-hardening.mjs).
 */

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

import assert from 'node:assert/strict';
import { JSDOM } from 'jsdom';

const __dirname = path.dirname(fileURLToPath(import.meta.url));

export const OBSERVABILITY_JS = path.join(__dirname, '..', '..', '..', 'js', 'observability.js');

const source = fs.readFileSync(OBSERVABILITY_JS, 'utf8');

export const DEFAULT_SETTINGS = {
  environment: 'pantheon-test',
  pantheonEnv: 'test',
  multidevName: '',
  release: 'test_155',
  gitSha: '',
  siteName: 'idaho-legal-aid-services',
  siteId: '',
  publicSiteUrl: '',
  routeName: 'ilas_site_assistant.page',
  path: '/assistant',
  assistant: {
    name: 'aila',
    apiBase: '/assistant/api',
  },
  sentry: {
    browserEnabled: true,
    showReportDialog: true,
    replayEnabled: true,
    replaySessionSampleRate: 0.05,
    replayOnErrorSampleRate: 1,
  },
};

/**
 * Loads observability.js into a fresh jsdom window.
 *
 * @param {object} options
 * @param {object} [options.settings] Overrides merged onto DEFAULT_SETTINGS.
 * @param {function(Window): object} options.sentry Factory for the Sentry stub.
 * @param {string} [options.userAgent]
 * @returns {Promise<Window>}
 */
export async function loadObservability(options) {
  const dom = new JSDOM('<!doctype html><html><body></body></html>', {
    url: 'https://idaholegalaid.org/assistant',
    runScripts: 'outside-only',
    pretendToBeVisual: true,
  });
  const { window } = dom;

  // jsdom 29 ignores the constructor userAgent option; override the getter.
  Object.defineProperty(window.navigator, 'userAgent', {
    value: options.userAgent || 'Mozilla/5.0 (jsdom)',
    configurable: true,
  });

  window.Drupal = { t: (s) => s };
  window.drupalSettings = {
    ilasObservability: Object.assign({}, DEFAULT_SETTINGS, options.settings || {}),
  };
  window.Sentry = options.sentry(window);

  // jsdom starts at readyState 'loading'; observability.js defers provider
  // setup to DOMContentLoaded in that state, so wait for it to settle first.
  if (window.document.readyState === 'loading') {
    await new Promise((resolve) => {
      window.addEventListener('DOMContentLoaded', resolve, { once: true });
    });
  }

  window.eval(source);
  return window;
}

/** Flushes the microtask queue a couple of times (replay lazy-load chain). */
export async function flush() {
  await Promise.resolve();
  await Promise.resolve();
  await Promise.resolve();
}

/**
 * Deep-equality that ignores realm: values built inside the jsdom window
 * have jsdom's Array/Object prototypes, which deepStrictEqual rejects.
 */
export function assertPlainEqual(actual, expected, message) {
  assert.deepEqual(JSON.parse(JSON.stringify(actual)), JSON.parse(JSON.stringify(expected)), message);
}
