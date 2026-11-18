import assert from 'node:assert/strict';
import { execFile } from 'node:child_process';
import http from 'node:http';
import path from 'node:path';
import { test } from 'node:test';
import { fileURLToPath } from 'node:url';

const SCRIPT = path.join(path.dirname(fileURLToPath(import.meta.url)), '..', 'site-smoke.mjs');

const html = (body, lang = 'en') => `<!doctype html><html lang="${lang}"><body><h1>Title</h1>${body}</body></html>`;

/**
 * Responses of a healthy site, keyed by request URL.
 */
function healthyRoutes() {
  const sitemap = `<urlset>${'<url><loc>https://example.test/x</loc></url>'.repeat(60)}</urlset>`;
  return {
    '/': [200, html('')],
    '/legal-help/housing': [200, html('')],
    '/donate': [200, html('')],
    '/apply-for-help': [200, html('')],
    '/contact/offices/boise-office': [200, html('')],
    '/forms': [200, html('<div class="view forms-view forms-landing"></div>')],
    '/guides': [200, html('')],
    '/search?keys=eviction': [200, html('<div class="search-results__item"></div>')],
    '/es': [200, html('', 'es')],
    '/assistant': [200, html('<div class="ilas-assistant-page"></div>')],
    '/user/login': [200, html('<form data-drupal-selector="user-login-form"></form>')],
    '/admin': [403, html('')],
    '/employment': [301, '', { Location: '/careers/employment-opportunities?smoke=abc' }],
    '/sitemap.xml': [200, sitemap],
    '/robots.txt': [200, 'User-agent: *\nDisallow: /admin/\n'],
    '/this-page-does-not-exist-ilas-probe': [404, html('')],
    '/?page[limit]=5': [200, html('')],
    '/employment-application/token': [200, JSON.stringify({ token: 'abc', nonce: 'def' })],
  };
}

/**
 * Runs the smoke against a local server and returns its exit code and output.
 *
 * @param {object} overrides
 *   Routes replacing the healthy ones. A value may be a function of the hit
 *   count for that URL, to model a response that changes between attempts.
 */
async function runSmoke(overrides = {}, args = []) {
  const routes = { ...healthyRoutes(), ...overrides };
  const hits = {};
  const requested = [];
  const busters = [];
  const server = http.createServer((req, res) => {
    const raw = decodeURIComponent(req.url);
    busters.push((raw.match(/[?&]smoke=([a-z0-9]+)$/) || [])[1]);
    // Routes are keyed without the per-run cache-busting parameter.
    const url = raw.replace(/[?&]smoke=[a-z0-9]+$/, '');
    requested.push(url);
    hits[url] = (hits[url] || 0) + 1;
    let route = routes[url];
    if (typeof route === 'function') {
      route = route(hits[url]);
    }
    const [status, body, headers = {}] = route || [500, 'unexpected request'];
    res.writeHead(status, headers);
    res.end(body);
  });
  await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
  const baseUrl = `http://127.0.0.1:${server.address().port}`;

  try {
    return await new Promise((resolve) => {
      execFile(
        process.execPath,
        [SCRIPT, '--base-url', baseUrl, '--retry-delay-ms', '10', ...args],
        { env: { PATH: process.env.PATH } },
        (error, stdout, stderr) => resolve({ code: error ? error.code : 0, stdout, stderr, requested, hits, busters }),
      );
    });
  }
  finally {
    await new Promise((resolve) => server.close(resolve));
  }
}

test('a healthy site passes every check', async () => {
  const result = await runSmoke();

  assert.equal(result.code, 0, result.stdout);
  assert.match(result.stdout, /Summary: 18 passed, 0 failed/);
  assert.doesNotMatch(result.stdout, /\[FAIL\]/);
});

test('every request carries the same per-run cache-busting parameter', async () => {
  const result = await runSmoke();

  assert.equal(result.busters.length, 18);
  assert.ok(result.busters[0], 'first request has a smoke= parameter');
  assert.equal(new Set(result.busters).size, 1);
});

test('a server error fails the run after exactly one retry', async () => {
  const result = await runSmoke({ '/donate': [500, 'boom'] });

  assert.equal(result.code, 1);
  assert.match(result.stdout, /\[FAIL\] GET \/donate HTTP 500 - expected HTTP 200, got 500 \(after one retry\)/);
  assert.equal(result.hits['/donate'], 2);
  assert.match(result.stdout, /Summary: 17 passed, 1 failed/);
});

test('a waking container (555 then 200) is retried and passes', async () => {
  const result = await runSmoke({
    '/': (hit) => (hit === 1 ? [555, 'starting'] : [200, html('')]),
  });

  assert.equal(result.code, 0, result.stdout);
  assert.match(result.stdout, /\[PASS\] GET \/ HTTP 200 \(after one retry\)/);
});

test('a 200 page without its content marker fails', async () => {
  const result = await runSmoke({ '/search?keys=eviction': [200, html('<p>Enter keywords above</p>')] });

  assert.equal(result.code, 1);
  assert.match(result.stdout, /\[FAIL\] GET \/search\?keys=eviction HTTP 200 - missing at least one search result/);
});

test('a client error is not retried', async () => {
  const result = await runSmoke({ '/?page[limit]=5': [400, 'Bad Request'] });

  assert.equal(result.code, 1);
  assert.equal(result.hits['/?page[limit]=5'], 1);
  assert.match(result.stdout, /array page parameter\) HTTP 400 - expected HTTP 200, got 400/);
});

test('a redirect to the wrong place and a thin sitemap fail', async () => {
  const result = await runSmoke({
    '/employment': [301, '', { Location: '/somewhere-else' }],
    '/sitemap.xml': [200, '<urlset><url><loc>x</loc></url></urlset>'],
  });

  assert.equal(result.code, 1);
  assert.match(result.stdout, /\[FAIL\] GET \/employment \(redirect\)/);
  assert.match(result.stdout, /sitemap lists 1 URLs, expected at least 50/);
});

test('rendered PHP error output fails even with HTTP 200', async () => {
  const result = await runSmoke({ '/guides': [200, html('Fatal error: Uncaught Error in /code/web/index.php')] });

  assert.equal(result.code, 1);
  assert.match(result.stdout, /\[FAIL\] GET \/guides HTTP 200 - page contains error output/);
});

test('live skips the session-creating token check', async () => {
  const result = await runSmoke({}, ['--env', 'live']);

  assert.equal(result.code, 0, result.stdout);
  assert.match(result.stdout, /Summary: 17 passed, 0 failed/);
  assert.ok(!result.requested.includes('/employment-application/token'));
});

test('the public Cloudflare host is refused before any request', async () => {
  const result = await new Promise((resolve) => {
    execFile(process.execPath, [SCRIPT, '--base-url', 'https://www.idaholegalaid.org'], (error, stdout, stderr) => {
      resolve({ code: error ? error.code : 0, stderr });
    });
  });

  assert.equal(result.code, 1);
  assert.match(result.stderr, /Refusing to run against idaholegalaid\.org/);
});
