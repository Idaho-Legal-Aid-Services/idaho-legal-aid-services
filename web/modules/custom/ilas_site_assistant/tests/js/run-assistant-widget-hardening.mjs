#!/usr/bin/env node

import fs from 'node:fs';
import path from 'node:path';
import process from 'node:process';
import { fileURLToPath } from 'node:url';

import { JSDOM } from 'jsdom';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const testFile = path.join(__dirname, 'assistant-widget-hardening.test.js');

if (!fs.existsSync(testFile)) {
  console.error(`ERROR: JS hardening suite not found: ${testFile}`);
  process.exit(1);
}

const source = fs.readFileSync(testFile, 'utf8');

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

window.eval(source);

if (window._assistantWidgetTestDone && typeof window._assistantWidgetTestDone.then === 'function') {
  await window._assistantWidgetTestDone;
}

const results = window._assistantWidgetTestResults;
if (!results || typeof results.pass !== 'number' || typeof results.fail !== 'number') {
  console.error('ERROR: JS hardening suite did not publish window._assistantWidgetTestResults.');
  process.exit(1);
}

console.log(`assistant-widget-hardening: pass=${results.pass} fail=${results.fail}`);
if (results.fail > 0) {
  process.exit(1);
}

process.exit(0);
