#!/usr/bin/env node

import fs from 'node:fs';
import path from 'node:path';
import process from 'node:process';
import { fileURLToPath } from 'node:url';

import { JSDOM } from 'jsdom';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const widgetFile = path.resolve(__dirname, '../../js/assistant-widget.js');
const testFile = path.join(__dirname, 'assistant-widget-selection-state.test.js');

if (!fs.existsSync(widgetFile)) {
  console.error(`ERROR: Widget source not found: ${widgetFile}`);
  process.exit(1);
}
if (!fs.existsSync(testFile)) {
  console.error(`ERROR: JS selection-state suite not found: ${testFile}`);
  process.exit(1);
}

const widgetSource = fs.readFileSync(widgetFile, 'utf8');
const testSource = fs.readFileSync(testFile, 'utf8');

const dom = new JSDOM('<!doctype html><html><body></body></html>', {
  url: 'https://idaholegalaid.org/assistant',
  runScripts: 'outside-only',
  pretendToBeVisual: true,
});

const { window } = dom;
window.console = console;
window.setTimeout = setTimeout;
window.clearTimeout = clearTimeout;
window.requestAnimationFrame = window.requestAnimationFrame || ((cb) => setTimeout(cb, 16));
window.cancelAnimationFrame = window.cancelAnimationFrame || ((id) => clearTimeout(id));
window.Drupal = {
  t(str, replacements) {
    if (!replacements) return str;
    Object.keys(replacements).forEach((key) => {
      str = str.replace(key, replacements[key]);
    });
    return str;
  },
  behaviors: {},
};
window.drupalSettings = {};
window.once = (_id, selector, context) => Array.from((context || window.document).querySelectorAll(selector));
window.fetch = () => Promise.reject(new Error('fetch not stubbed'));

window.eval(widgetSource);
window.eval(testSource);

if (window._assistantWidgetSelectionTestDone && typeof window._assistantWidgetSelectionTestDone.then === 'function') {
  await window._assistantWidgetSelectionTestDone;
}

const results = window._assistantWidgetSelectionTestResults;
if (!results || typeof results.pass !== 'number' || typeof results.fail !== 'number') {
  console.error('ERROR: JS selection-state suite did not publish results.');
  process.exit(1);
}

console.log(`assistant-widget-selection-state: pass=${results.pass} fail=${results.fail}`);
if (results.fail > 0) {
  process.exit(1);
}

process.exit(0);
