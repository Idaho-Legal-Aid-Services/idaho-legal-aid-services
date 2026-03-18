#!/usr/bin/env node

const {
  IlasLiveTransport,
  deterministicUuidV4,
  formatStructuredError,
  summarizeAssistantResponse,
} = require('../../promptfoo-evals/lib/ilas-live-shared');

const DEFAULT_QUERIES = [
  'what are idaho tenant rights for eviction notices',
  'im raising my granddaughter because my daughter is on drugs... what are my legal options',
  'is there any way to get my car back',
];

function parseArgs(argv) {
  const options = {
    environment: process.env.ILAS_TARGET_ENV || '',
    assistantUrl: process.env.ILAS_ASSISTANT_URL || '',
    siteBaseUrl: process.env.ILAS_SITE_BASE_URL || '',
    queries: [],
  };

  for (let index = 0; index < argv.length; index += 1) {
    const arg = argv[index];
    if (arg === '--environment' && argv[index + 1]) {
      options.environment = argv[++index];
      continue;
    }
    if (arg === '--assistant-url' && argv[index + 1]) {
      options.assistantUrl = argv[++index];
      continue;
    }
    if (arg === '--site-base-url' && argv[index + 1]) {
      options.siteBaseUrl = argv[++index];
      continue;
    }
    if (arg === '--query' && argv[index + 1]) {
      options.queries.push(argv[++index]);
    }
  }

  if (options.queries.length === 0) {
    options.queries = DEFAULT_QUERIES.slice();
  }

  return options;
}

function inferEnvironment(assistantUrl, fallback = '') {
  if (fallback) {
    return fallback;
  }

  try {
    const host = new URL(assistantUrl).hostname.toLowerCase();
    if (host.includes('.ddev.site')) {
      return 'local';
    }
    if (host.startsWith('dev-')) {
      return 'dev';
    }
    if (host.startsWith('test-')) {
      return 'test';
    }
    if (host.startsWith('live-')) {
      return 'live';
    }
  } catch (_) {
    return '';
  }

  return '';
}

async function main() {
  const options = parseArgs(process.argv.slice(2));
  const transport = new IlasLiveTransport({
    assistantUrl: options.assistantUrl,
    siteBaseUrl: options.siteBaseUrl,
    expectedRequestTotal: options.queries.length,
    silent: true,
  });

  transport.resolveUrls();
  const bootstrapResult = await transport.fetchCsrfToken({
    tokenUrls: [`${transport.baseUrl}/assistant/api/session/bootstrap`],
    requireSessionCookie: true,
  });
  if (!bootstrapResult.ok) {
    console.error(formatStructuredError(bootstrapResult.error));
    process.exitCode = 1;
    return;
  }

  const environment = inferEnvironment(options.assistantUrl || transport.messageUrl, options.environment);

  for (const query of options.queries) {
    const result = await transport.callMessageApi({
      question: query,
      conversationId: deterministicUuidV4(`tovr-12:${environment}:${query}`),
      history: [{ role: 'user', content: query }],
    });

    if (!result.ok) {
      console.error(formatStructuredError(result.error));
      process.exitCode = 1;
      return;
    }

    const summary = summarizeAssistantResponse(result.data, transport.options.siteBaseUrl);
    process.stdout.write(
      `${JSON.stringify({
        environment,
        query,
        ...summary,
      })}\n`
    );
  }
}

main().catch((error) => {
  console.error(error instanceof Error ? error.stack || error.message : String(error));
  process.exit(1);
});
