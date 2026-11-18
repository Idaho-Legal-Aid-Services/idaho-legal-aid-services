#!/usr/bin/env node
/**
 * Site-wide post-deploy smoke.
 *
 * Dev and test receive no organic traffic, so nothing exercises a deploy
 * there unless a script does. assistant-smoke.mjs covers the assistant API;
 * this covers the rest of the public site: one request per kind of page, each
 * asserting the status code and a marker that only a correctly rendered page
 * contains.
 *
 * Status and markers carry the signal. Hosted environments hide PHP error
 * text (system.logging error_level: hide), so a broken page shows up as a
 * 5xx or as a page missing its content, not as a visible stack trace.
 *
 * Usage:
 *   SITE_BASE_URL=https://dev-idaho-legal-aid-services.pantheonsite.io \
 *     node scripts/smoke/site-smoke.mjs [--env dev|test|live|local]
 *
 * No dependencies; needs Node 20+ for fetch.
 */

const USER_AGENT = 'ilas-site-smoke/1.0';
const DEFAULT_TIMEOUT_MS = 30000;
const DEFAULT_RETRY_DELAY_MS = 5000;

// Signs of a PHP error rendered into the page. Secondary to the markers:
// only local development shows these.
const DEBUG_PATTERNS = [
  /\bFatal error\b/i,
  /\bParse error\b/i,
  /\bUncaught (?:Exception|Error)\b/i,
  /\bStack trace:/i,
  /The website encountered an unexpected error/i,
];

class SmokeFailure extends Error {}

function usage() {
  return [
    'Usage: SITE_BASE_URL=https://example.test node scripts/smoke/site-smoke.mjs [options]',
    '',
    'Options:',
    '  --base-url <url>        Site to check (or SITE_BASE_URL).',
    '  --env <name>            local, dev, test or live. Inferred from the host when omitted.',
    '  --timeout-ms <n>        Per-request timeout (default 30000).',
    '  --retry-delay-ms <n>    Wait before the single retry of a 5xx or timeout (default 5000).',
    '  --help                  Show this help.',
  ].join('\n');
}

function parseArgs(argv, env) {
  const options = {
    baseUrl: env.SITE_BASE_URL || '',
    env: env.SITE_SMOKE_ENV || '',
    timeoutMs: Number(env.SITE_SMOKE_TIMEOUT_MS || DEFAULT_TIMEOUT_MS),
    retryDelayMs: Number(env.SITE_SMOKE_RETRY_DELAY_MS || DEFAULT_RETRY_DELAY_MS),
    help: false,
  };
  for (let i = 0; i < argv.length; i += 1) {
    const arg = argv[i];
    const next = () => {
      i += 1;
      if (argv[i] === undefined) {
        throw new Error(`Missing value for ${arg}`);
      }
      return argv[i];
    };
    if (arg === '--base-url') options.baseUrl = next();
    else if (arg === '--env') options.env = next();
    else if (arg === '--timeout-ms') options.timeoutMs = Number(next());
    else if (arg === '--retry-delay-ms') options.retryDelayMs = Number(next());
    else if (arg === '--help' || arg === '-h') options.help = true;
    else throw new Error(`Unknown argument: ${arg}`);
  }
  return options;
}

function normalizeBaseUrl(value) {
  if (!value) {
    throw new Error('A base URL is required (--base-url or SITE_BASE_URL).');
  }
  const url = new URL(value);
  if (url.protocol !== 'http:' && url.protocol !== 'https:') {
    throw new Error(`Unsupported protocol: ${url.protocol}`);
  }
  return `${url.protocol}//${url.host}`;
}

function inferEnvironment(baseUrl) {
  const host = new URL(baseUrl).hostname;
  if (host === 'localhost' || host === '127.0.0.1' || host.endsWith('.ddev.site')) return 'local';
  if (host.startsWith('dev-')) return 'dev';
  if (host.startsWith('test-')) return 'test';
  if (host.startsWith('live-')) return 'live';
  return 'unknown';
}

function assertHostAllowed(baseUrl) {
  const host = new URL(baseUrl).hostname;
  if (host === 'idaholegalaid.org' || host.endsWith('.idaholegalaid.org')) {
    // Cloudflare answers scripted clients on the public host with a 403
    // challenge, so every check would fail for a reason unrelated to the site.
    throw new Error(
      'Refusing to run against idaholegalaid.org: Cloudflare challenges scripted clients. '
      + 'Use the live-…pantheonsite.io host to check live.',
    );
  }
}

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

async function fetchOnce(url, timeoutMs) {
  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), timeoutMs);
  try {
    const response = await fetch(url, {
      redirect: 'manual',
      headers: { 'User-Agent': USER_AGENT, Accept: '*/*' },
      signal: controller.signal,
    });
    return {
      status: response.status,
      location: response.headers.get('location') || '',
      text: await response.text(),
    };
  }
  catch (error) {
    const reason = error?.name === 'AbortError' ? `timed out after ${timeoutMs}ms` : (error?.cause?.code || error?.message || 'failed');
    return { status: 0, location: '', text: '', error: reason };
  }
  finally {
    clearTimeout(timer);
  }
}

/**
 * Requests a path, retrying once on a 5xx or a transport failure.
 *
 * A sleeping Pantheon container answers its first request with HTTP 555 or
 * 503 while it starts; that is not a site failure. A second 5xx is.
 */
async function request(options, path) {
  // Pantheon's edge caches anonymous pages for 24 hours and a deploy does not
  // purge them, so an unmodified URL can return the page as it was rendered
  // before the deploy. A per-run query parameter makes every request a cache
  // miss that the new code has to render.
  const separator = path.includes('?') ? '&' : '?';
  const url = `${options.baseUrl}${path}${separator}smoke=${options.runId}`;
  let response = await fetchOnce(url, options.timeoutMs);
  let retried = false;
  if (response.status === 0 || response.status >= 500) {
    retried = true;
    await sleep(options.retryDelayMs);
    response = await fetchOnce(url, options.timeoutMs);
  }
  return { ...response, retried };
}

function expectStatus(response, allowed) {
  const list = Array.isArray(allowed) ? allowed : [allowed];
  if (response.status === 0) {
    throw new SmokeFailure(`request ${response.error}`);
  }
  if (!list.includes(response.status)) {
    throw new SmokeFailure(`expected HTTP ${list.join(' or ')}, got ${response.status}`);
  }
}

function expectMarker(response, pattern, description) {
  if (!pattern.test(response.text)) {
    throw new SmokeFailure(`missing ${description}`);
  }
}

function expectNoErrorOutput(response) {
  for (const pattern of DEBUG_PATTERNS) {
    if (pattern.test(response.text)) {
      throw new SmokeFailure(`page contains error output matching ${pattern}`);
    }
  }
}

const H1 = [/<h1[\s>]/i, 'a page heading (<h1>)'];

/**
 * An ordinary rendered page: 200, an <h1>, a closing </html>, no error text.
 */
function page(path, extra = []) {
  return {
    name: `GET ${path}`,
    path,
    run(response) {
      expectStatus(response, 200);
      expectNoErrorOutput(response);
      expectMarker(response, ...H1);
      expectMarker(response, /<\/html>/i, 'a complete document (</html>)');
      for (const [pattern, description] of extra) {
        expectMarker(response, pattern, description);
      }
    },
  };
}

function buildChecks(environment) {
  const checks = [
    page('/'),
    // Content pages recorded as 200 on dev, test and live
    // (docs/evidence/browser-language-cacheability/).
    page('/legal-help/housing'),
    page('/donate'),
    page('/apply-for-help'),
    page('/contact/offices/boise-office'),
    // Views.
    page('/forms', [[/class="[^"]*\bforms-view\b/, 'the forms view']]),
    page('/guides'),
    // Search runs against Solr; a page with no result rows means the index
    // is empty, unreachable, or out of step with the database.
    page('/search?keys=eviction', [[/class="search-results__item"/, 'at least one search result for "eviction"']]),
    // Interface translation.
    page('/es', [[/<html[^>]*\blang="es"/, 'lang="es" on <html>']]),
    page('/assistant', [[/ilas-assistant-page/, 'the assistant page mount']]),
    {
      name: 'GET /user/login',
      path: '/user/login',
      run(response) {
        expectStatus(response, 200);
        expectNoErrorOutput(response);
        expectMarker(response, /data-drupal-selector="user-login-form"/, 'the login form');
      },
    },
    {
      name: 'GET /admin (anonymous is refused)',
      path: '/admin',
      run(response) {
        expectStatus(response, [403, 302, 303]);
      },
    },
    {
      name: 'GET /employment (redirect)',
      path: '/employment',
      run(response) {
        expectStatus(response, [301, 302]);
        // The redirect module carries the query string over.
        if (!response.location.split('?')[0].endsWith('/careers/employment-opportunities')) {
          throw new SmokeFailure(`redirects to "${response.location}", expected …/careers/employment-opportunities`);
        }
      },
    },
    {
      name: 'GET /sitemap.xml',
      path: '/sitemap.xml',
      run(response) {
        expectStatus(response, 200);
        expectMarker(response, /<urlset[\s>]/, '<urlset>');
        const count = (response.text.match(/<loc>/g) || []).length;
        if (count < 50) {
          throw new SmokeFailure(`sitemap lists ${count} URLs, expected at least 50`);
        }
        return `${count} URLs`;
      },
    },
    {
      name: 'GET /robots.txt',
      path: '/robots.txt',
      run(response) {
        expectStatus(response, 200);
        expectMarker(response, /^User-agent:/mi, 'a User-agent rule');
      },
    },
    {
      name: 'GET missing page (404 page renders)',
      path: '/this-page-does-not-exist-ilas-probe',
      run(response) {
        expectStatus(response, 404);
        expectNoErrorOutput(response);
        expectMarker(response, ...H1);
      },
    },
    {
      // Sentry PHP-C4: an array "page" parameter threw in ilas_seo and
      // turned any page into a 400.
      name: 'GET /?page[limit]=5 (array page parameter)',
      path: '/?page[limit]=5',
      run(response) {
        expectStatus(response, 200);
        expectMarker(response, ...H1);
      },
    },
  ];

  if (environment !== 'live') {
    // Starts a session and writes a nonce, so it is not run against live.
    checks.push({
      name: 'GET /employment-application/token',
      path: '/employment-application/token',
      run(response) {
        expectStatus(response, 200);
        let data;
        try {
          data = JSON.parse(response.text);
        }
        catch {
          throw new SmokeFailure('response is not JSON');
        }
        if (typeof data?.token !== 'string' || data.token === '') {
          throw new SmokeFailure('JSON has no token');
        }
      },
    });
  }

  return checks;
}

async function main() {
  let options;
  try {
    options = parseArgs(process.argv.slice(2), process.env);
    if (options.help) {
      console.log(usage());
      return 0;
    }
    options.baseUrl = normalizeBaseUrl(options.baseUrl);
    assertHostAllowed(options.baseUrl);
  }
  catch (error) {
    console.error(`${error.message}\n\n${usage()}`);
    return 1;
  }

  options.runId = `${Date.now().toString(36)}${Math.random().toString(36).slice(2, 8)}`;
  const environment = options.env || inferEnvironment(options.baseUrl);
  console.log(`Site smoke: ${options.baseUrl} (env: ${environment})`);

  let failed = 0;
  const checks = buildChecks(environment);
  for (const check of checks) {
    const response = await request(options, check.path);
    const suffix = response.retried ? ' (after one retry)' : '';
    try {
      const detail = check.run(response);
      console.log(`[PASS] ${check.name} HTTP ${response.status}${detail ? ` - ${detail}` : ''}${suffix}`);
    }
    catch (error) {
      if (!(error instanceof SmokeFailure)) {
        throw error;
      }
      failed += 1;
      console.log(`[FAIL] ${check.name} HTTP ${response.status} - ${error.message}${suffix}`);
    }
  }

  console.log(`Summary: ${checks.length - failed} passed, ${failed} failed`);
  return failed === 0 ? 0 : 1;
}

main().then(
  (code) => {
    process.exitCode = code;
  },
  (error) => {
    console.error(error?.stack || String(error));
    process.exitCode = 1;
  },
);
