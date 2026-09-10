/**
 * @file
 * ilas_seo/js/sentry-filters.js: SDK-level denyUrls / ignoreErrors that
 * raven.js hands to Sentry.init() (section C of the 2026-09-09 Sentry review).
 */

import { test, describe } from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { JSDOM } from 'jsdom';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const SCRIPT = path.join(__dirname, '..', '..', '..', 'js', 'sentry-filters.js');
const source = fs.readFileSync(SCRIPT, 'utf8');

function load(drupalSettings) {
  const dom = new JSDOM('<!doctype html><html><body></body></html>', {
    url: 'https://idaholegalaid.org/',
    runScripts: 'outside-only',
  });
  dom.window.drupalSettings = drupalSettings;
  dom.window.eval(source);
  return dom.window.drupalSettings;
}

function matches(patterns, value) {
  // Patterns are created inside the jsdom realm, so instanceof RegExp is false here.
  return patterns.some((p) => (Object.prototype.toString.call(p) === '[object RegExp]' ? p.test(value) : value.includes(p)));
}

describe('sentry-filters.js', () => {
  test('is a no-op when raven settings are absent', () => {
    const ds = load({});
    assert.equal(ds.raven, undefined);
  });

  test('appends to existing arrays instead of replacing them', () => {
    const ds = load({ raven: { options: { ignoreErrors: ['keep me'], denyUrls: [/keep\.js/] } } });
    assert.equal(ds.raven.options.ignoreErrors[0], 'keep me');
    assert.ok(ds.raven.options.denyUrls[0].test('keep.js'));
    assert.ok(ds.raven.options.ignoreErrors.length > 1);
    assert.ok(ds.raven.options.denyUrls.length > 1);
  });

  test('denies the third-party script URLs from the noise clusters', () => {
    const { denyUrls } = load({ raven: { options: {} } }).raven.options;
    for (const url of [
      'https://static.cloudflareinsights.com/beacon.min.js/vcd15cbe7772f49c399c6a5babf22c1241717689176015',
      'chrome-extension://nkbihfbeogaeaoehlefnkodbefgpgknn/scripts/inpage.js',
      'moz-extension://1234/content.js',
      'safari-web-extension://abcd/content.js',
      'https://cdn.example.com/injectScriptAdjust.js',
      'iabjs://navigation_performance_logger_android',
    ]) {
      assert.ok(matches(denyUrls, url), `should deny ${url}`);
    }
  });

  test('does not deny site-owned script URLs', () => {
    const { denyUrls } = load({ raven: { options: {} } }).raven.options;
    for (const url of [
      'https://idaholegalaid.org/sites/default/files/js/js_abc.js?scope=footer&delta=1',
      'https://idaholegalaid.org/modules/custom/ilas_site_assistant/js/observability.js',
      'https://idaholegalaid.org/themes/custom/b5subtheme/js/main.js',
      'https://idaholegalaid.org/core/misc/drupal.js',
    ]) {
      assert.ok(!matches(denyUrls, url), `should keep ${url}`);
    }
  });

  test('ignores the injected-script and bot-only messages', () => {
    const { ignoreErrors } = load({ raven: { options: {} } }).raven.options;
    for (const msg of ['jQuery is not defined', 'La', 'Ba', 'Object Not Found Matching Id:3, MethodName:update, ParamCount:4']) {
      assert.ok(matches(ignoreErrors, msg), `should ignore "${msg}"`);
    }
  });

  test('does not ignore site-owned error messages', () => {
    const { ignoreErrors } = load({ raven: { options: {} } }).raven.options;
    for (const msg of ['Drupal is not defined', 'AILA browser error: quick_action (network)', 'Bad request', 'Label']) {
      assert.ok(!matches(ignoreErrors, msg), `should keep "${msg}"`);
    }
  });
});
