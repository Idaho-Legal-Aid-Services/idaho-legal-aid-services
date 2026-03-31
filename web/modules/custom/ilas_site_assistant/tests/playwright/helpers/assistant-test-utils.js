const { expect } = require('@playwright/test');

const TRACKED_ENDPOINT_PATHS = new Set([
  '/assistant/api/session/bootstrap',
  '/assistant/api/message',
  '/assistant/api/track',
]);

const KNOWN_THIRD_PARTY_NOISE_PATTERNS = [
  /replayintegration\(\).*does not include replay/i,
  /sentry\.io\/api\/embed\/error-page/i,
  /content-security-policy: .*error-page/i,
  /cross-origin request blocked: .*error-page/i,
  /source uri is not allowed in this document: .*error-page/i,
];

const BUTTON_SELECTOR = '.topic-suggestion-btn, .inline-suggestion-btn';

const SELECTORS = {
  pageChat: '#assistant-chat',
  widgetPanel: '.assistant-panel',
  widgetToggle: '#assistant-toggle',
  widgetChat: '.assistant-panel .assistant-chat',
  assistantTurns: '.chat-message--assistant:not(.chat-message--typing)',
  userTurns: '.chat-message--user',
  pageQuickActions: '.assistant-suggestions .suggestion-btn[data-action]',
  widgetQuickActions: '.assistant-panel .quick-action-btn[data-action]',
  topicButtons: '.topic-suggestion-btn[data-selection-button-id]',
  inlineButtons: '.inline-suggestion-btn[data-selection-button-id]',
  resultLinks: '.resource-link, .result-link, .faq-link, .response-link-btn, .cta-button',
};

function normalizeWhitespace(value) {
  return String(value || '').replace(/\s+/g, ' ').trim();
}

function uniqueTexts(values) {
  return Array.from(new Set((values || []).filter(Boolean)));
}

function normalizeLabelKey(value) {
  return normalizeWhitespace(value).toLowerCase();
}

function findDuplicateLabels(values) {
  const seen = new Set();
  const duplicates = [];

  (values || []).forEach((value) => {
    const key = normalizeLabelKey(value);
    if (!key) {
      return;
    }

    if (seen.has(key)) {
      if (!duplicates.some((entry) => normalizeLabelKey(entry) === key)) {
        duplicates.push(value);
      }
      return;
    }

    seen.add(key);
  });

  return duplicates;
}

function findPresentLabels(values, candidateLabels) {
  const normalizedValues = new Set((values || []).map(normalizeLabelKey).filter(Boolean));

  return uniqueTexts((candidateLabels || []).filter((label) => normalizedValues.has(normalizeLabelKey(label))));
}

function slugifyAttachmentName(value) {
  return String(value || 'attachment')
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '')
    .replace(/-{2,}/g, '-')
    || 'attachment';
}

function safeJsonParse(value) {
  if (!value || typeof value !== 'string') {
    return null;
  }

  try {
    return JSON.parse(value);
  } catch (error) {
    return null;
  }
}

function tryGetPathname(value) {
  try {
    return new URL(value).pathname;
  } catch (error) {
    return '';
  }
}

function isTrackedEndpoint(url) {
  return TRACKED_ENDPOINT_PATHS.has(tryGetPathname(url));
}

function isAssistantAsset(url) {
  return /\/modules\/custom\/ilas_site_assistant\/|assistant-widget\.js/i.test(String(url || ''));
}

function isKnownThirdPartyNoise(entry) {
  const haystack = [
    entry.text,
    entry.url,
    entry.message,
    entry.stack,
  ]
    .filter(Boolean)
    .join(' ');

  return KNOWN_THIRD_PARTY_NOISE_PATTERNS.some((pattern) => pattern.test(haystack));
}

function isAssistantOwnedConsole(entry) {
  return isAssistantAsset(entry.url)
    || /ILAS Assistant/i.test(entry.text)
    || /assistant\/api\//i.test(entry.text);
}

function isAssistantOwnedPageError(entry) {
  return isAssistantAsset(entry.stack)
    || isAssistantAsset(entry.message)
    || /ILAS Assistant/i.test(entry.message);
}

function isAssistantOwnedFailedRequest(entry) {
  return isTrackedEndpoint(entry.url) || isAssistantAsset(entry.url);
}

async function locatorTexts(locator) {
  const count = await locator.count();
  const texts = [];

  for (let index = 0; index < count; index += 1) {
    const text = normalizeWhitespace(await locator.nth(index).innerText());
    if (text) {
      texts.push(text);
    }
  }

  return texts;
}

async function attachJson(testInfo, name, data) {
  await testInfo.attach(name, {
    body: Buffer.from(`${JSON.stringify(data, null, 2)}\n`, 'utf8'),
    contentType: 'application/json',
  });
}

async function createAssistantHarness(page, testInfo, options = {}) {
  const chatSelector = options.chatSelector || SELECTORS.pageChat;
  const transcript = [];
  const consoleEntries = [];
  const pageErrors = [];
  const failedRequests = [];
  const turnEvidence = [];
  const requestEntries = new Map();

  page.on('console', (message) => {
    if (!['warning', 'error'].includes(message.type())) {
      return;
    }

    const location = message.location();
    consoleEntries.push({
      type: message.type(),
      text: message.text(),
      url: location?.url || '',
      lineNumber: location?.lineNumber || 0,
      columnNumber: location?.columnNumber || 0,
    });
  });

  page.on('pageerror', (error) => {
    pageErrors.push({
      message: String(error?.message || ''),
      stack: String(error?.stack || ''),
    });
  });

  page.on('requestfailed', (request) => {
    failedRequests.push({
      url: request.url(),
      method: request.method(),
      failure: request.failure(),
    });
  });

  page.on('request', (request) => {
    if (!isTrackedEndpoint(request.url())) {
      return;
    }

    const entry = {
      url: request.url(),
      path: tryGetPathname(request.url()),
      method: request.method(),
      requestHeaders: request.headers(),
      requestBodyText: request.postData() || '',
      requestBodyJson: safeJsonParse(request.postData() || ''),
      responseStatus: null,
      responseOk: null,
      responseHeaders: {},
      responseBodyText: '',
      responseBodyJson: null,
    };

    transcript.push(entry);
    requestEntries.set(request, entry);
  });

  page.on('response', async (response) => {
    const request = response.request();
    const entry = requestEntries.get(request);
    if (!entry) {
      return;
    }

    entry.responseStatus = response.status();
    entry.responseOk = response.ok();
    entry.responseHeaders = await response.allHeaders();

    try {
      entry.responseBodyText = await response.text();
      entry.responseBodyJson = safeJsonParse(entry.responseBodyText);
    } catch (error) {
      entry.responseBodyText = '';
      entry.responseBodyJson = null;
    }
  });

  async function latestAssistantTurn() {
    const turns = page.locator(`${chatSelector} ${SELECTORS.assistantTurns}`);
    await expect(turns.last()).toBeVisible();
    return turns.last();
  }

  async function latestUserTurn() {
    const turns = page.locator(`${chatSelector} ${SELECTORS.userTurns}`);
    await expect(turns.last()).toBeVisible();
    return turns.last();
  }

  function findMessageTranscriptEntry(predicate) {
    return transcript.find((entry) => {
      if (entry.path !== '/assistant/api/message') {
        return false;
      }

      return predicate(entry.requestBodyJson || {});
    }) || null;
  }

  async function waitForMessageTranscriptEntry(predicate, label = 'assistant message', timeoutMs = 10000) {
    await expect.poll(() => {
      const entry = findMessageTranscriptEntry(predicate);
      if (!entry || entry.responseOk === null) {
        return null;
      }

      return entry.responseOk ? 'ready' : `status:${entry.responseStatus}`;
    }, {
      timeout: timeoutMs,
    }).toBe('ready');

    const entry = findMessageTranscriptEntry(predicate);
    expect(entry, `${label} transcript entry should exist`).not.toBeNull();
    return entry;
  }

  async function getTurnButtonLabels(turnLocator, selector = BUTTON_SELECTOR) {
    return locatorTexts(turnLocator.locator(selector));
  }

  async function getVisibleWidgetQuickActionLabels() {
    return locatorTexts(page.locator(`${SELECTORS.widgetQuickActions}:visible`));
  }

  async function getTurnOverlapWithWidgetQuickActions(turnLocator, selector = BUTTON_SELECTOR) {
    const labels = await getTurnButtonLabels(turnLocator, selector);
    const widgetQuickActionLabels = await getVisibleWidgetQuickActionLabels();
    const widgetQuickActionKeys = new Set(widgetQuickActionLabels.map(normalizeLabelKey).filter(Boolean));
    const overlaps = uniqueTexts(
      labels.filter((label) => widgetQuickActionKeys.has(normalizeLabelKey(label))),
    );

    return {
      labels,
      widgetQuickActionLabels,
      overlaps,
    };
  }

  async function assertUniqueTurnButtonLabels(turnLocator, selector = BUTTON_SELECTOR, label = 'assistant turn') {
    const labels = await getTurnButtonLabels(turnLocator, selector);
    const duplicates = findDuplicateLabels(labels);

    expect.soft(labels.length, `${label} should render at least one option button`).toBeGreaterThan(0);
    expect(duplicates, `${label} rendered duplicate option labels within the same turn`).toEqual([]);

    return labels;
  }

  async function assertNoWidgetQuickActionOverlap(turnLocator, selector = BUTTON_SELECTOR, label = 'assistant turn') {
    const comparison = await getTurnOverlapWithWidgetQuickActions(turnLocator, selector);

    expect(
      comparison.overlaps,
      `${label} rendered option labels that duplicate persistent widget quick actions`,
    ).toEqual([]);

    return comparison;
  }

  async function assertTurnButtonLabelsExactly(turnLocator, expectedLabels, selector = BUTTON_SELECTOR, label = 'assistant turn') {
    const labels = await assertUniqueTurnButtonLabels(turnLocator, selector, label);

    expect(labels, `${label} should render exactly the expected option labels`).toEqual(expectedLabels);

    return labels;
  }

  async function assertTurnButtonLabelsExclude(turnLocator, forbiddenLabels, selector = BUTTON_SELECTOR, label = 'assistant turn') {
    const labels = await getTurnButtonLabels(turnLocator, selector);
    const presentForbiddenLabels = findPresentLabels(labels, forbiddenLabels);

    expect(presentForbiddenLabels, `${label} rendered forbidden option labels`).toEqual([]);

    return {
      labels,
      presentForbiddenLabels,
    };
  }

  async function assertAssistantTurnHealthy(turnLocator, options = {}) {
    const requireButtons = options.requireButtons || false;
    const content = normalizeWhitespace(await turnLocator.locator('.message-content').innerText());

    expect(content, 'assistant turn should contain visible text').not.toBe('');
    expect(content, 'assistant turn should not render [object Object]').not.toContain('[object Object]');
    expect(content, 'assistant turn should not render undefined').not.toContain('undefined');
    expect(content, 'assistant turn should not render bare null').not.toMatch(/\bnull\b/i);

    if (requireButtons) {
      await assertUniqueTurnButtonLabels(turnLocator, BUTTON_SELECTOR, 'assistant turn');
    }
  }

  async function summarizeTurn(turnLocator, options = {}) {
    const buttonSelector = options.buttonSelector || BUTTON_SELECTOR;
    const resultLinkSelector = options.resultLinkSelector || SELECTORS.resultLinks;
    const forbiddenLabels = Array.isArray(options.forbiddenLabels) ? options.forbiddenLabels : [];
    const content = normalizeWhitespace(await turnLocator.locator('.message-content').innerText());
    const labels = await getTurnButtonLabels(turnLocator, buttonSelector);
    const duplicateLabels = findDuplicateLabels(labels);
    const widgetQuickActionLabels = await getVisibleWidgetQuickActionLabels();
    const widgetQuickActionKeys = new Set(widgetQuickActionLabels.map(normalizeLabelKey).filter(Boolean));
    const overlapWithWidgetQuickActions = uniqueTexts(
      labels.filter((label) => widgetQuickActionKeys.has(normalizeLabelKey(label))),
    );
    const presentForbiddenLabels = findPresentLabels(labels, forbiddenLabels);
    const resultLinkCount = await turnLocator.locator(resultLinkSelector).count();

    return {
      content,
      labels,
      duplicateLabels,
      hasButtons: labels.length > 0,
      widgetQuickActionLabels,
      overlapWithWidgetQuickActions,
      presentForbiddenLabels,
      resultLinkCount,
      signature: labels.join(' | '),
    };
  }

  async function collectVisibleButtonInventory() {
    return locatorTexts(page.locator(`${chatSelector} button:visible`));
  }

  async function collectTurnSignatures() {
    const turns = page.locator(`${chatSelector} ${SELECTORS.assistantTurns}`);
    const count = await turns.count();
    const signatures = [];

    for (let index = 0; index < count; index += 1) {
      const labels = await getTurnButtonLabels(turns.nth(index));
      if (!labels.length) {
        continue;
      }

      signatures.push({
        index,
        labels,
        signature: labels.join(' | '),
      });
    }

    return signatures;
  }

  async function attachLocatorScreenshot(name, locator, options = {}) {
    const target = locator || page.locator(chatSelector).first();
    await expect(target).toBeVisible();

    await testInfo.attach(name, {
      body: await target.screenshot({
        animations: 'disabled',
        ...options,
      }),
      contentType: 'image/png',
    });
  }

  async function captureTurnEvidence(stepName, options = {}) {
    const turnLocator = options.turnLocator || await latestAssistantTurn();
    const screenshotLocator = options.screenshotLocator || page.locator(chatSelector).first();
    const summary = await summarizeTurn(turnLocator, {
      buttonSelector: options.buttonSelector,
      resultLinkSelector: options.resultLinkSelector,
      forbiddenLabels: options.forbiddenLabels,
    });
    const screenshotName = options.captureScreenshot === false
      ? null
      : `${options.attachmentPrefix || 'assistant'}-${slugifyAttachmentName(stepName)}.png`;

    if (screenshotName) {
      await attachLocatorScreenshot(screenshotName, screenshotLocator, options.screenshotOptions || {});
    }

    const evidence = {
      step: stepName,
      ...summary,
      screenshot: screenshotName,
    };

    turnEvidence.push(evidence);
    return evidence;
  }

  async function captureTopicFlowEvidence(name, options = {}) {
    const firstTurn = options.firstTurnEvidence || (options.firstTurnLocator
      ? await summarizeTurn(options.firstTurnLocator, {
        buttonSelector: options.firstTurnButtonSelector,
        forbiddenLabels: options.firstTurnForbiddenLabels,
      })
      : null);
    const secondTurn = options.secondTurnEvidence || (options.secondTurnLocator
      ? await summarizeTurn(options.secondTurnLocator, {
        buttonSelector: options.secondTurnButtonSelector,
        forbiddenLabels: options.secondTurnForbiddenLabels,
      })
      : null);
    const evidence = {
      topicKey: options.topicKey || null,
      topicLabel: options.topicLabel || null,
      typedMessage: options.typedMessage || null,
      expectedFormAction: options.expectedFormAction || null,
      expectedGuideAction: options.expectedGuideAction || null,
      clickedButton: options.clickedButton || null,
      firstRequestPayload: options.firstRequestPayload || null,
      firstResponse: options.firstResponseBody ? {
        type: options.firstResponseBody.type || null,
        message: normalizeWhitespace(options.firstResponseBody.message || ''),
        topicSuggestions: Array.isArray(options.firstResponseBody.topic_suggestions)
          ? options.firstResponseBody.topic_suggestions.map((suggestion) => ({
            label: normalizeWhitespace(suggestion?.label || ''),
            action: suggestion?.action || null,
            selection: suggestion?.selection || null,
          }))
          : [],
        activeSelection: options.firstResponseBody.active_selection || null,
        resultCount: Array.isArray(options.firstResponseBody.results) ? options.firstResponseBody.results.length : 0,
      } : null,
      secondRequestPayload: options.secondRequestPayload || null,
      secondResponse: options.secondResponseBody ? {
        type: options.secondResponseBody.type || null,
        message: normalizeWhitespace(options.secondResponseBody.message || ''),
        topicSuggestions: Array.isArray(options.secondResponseBody.topic_suggestions)
          ? options.secondResponseBody.topic_suggestions.map((suggestion) => ({
            label: normalizeWhitespace(suggestion?.label || ''),
            action: suggestion?.action || null,
            selection: suggestion?.selection || null,
          }))
          : [],
        activeSelection: options.secondResponseBody.active_selection || null,
        resultCount: Array.isArray(options.secondResponseBody.results) ? options.secondResponseBody.results.length : 0,
        resultTitles: Array.isArray(options.secondResponseBody.results)
          ? options.secondResponseBody.results.map((result) => normalizeWhitespace(result?.title || ''))
          : [],
      } : null,
      firstTurn,
      secondTurn,
    };

    await attachJson(testInfo, name, evidence);

    return evidence;
  }

  async function captureCustodyFlowEvidence(name, options = {}) {
    return captureTopicFlowEvidence(name, options);
  }

  function findRepeatedTurnSignatures(entries = turnEvidence) {
    const grouped = new Map();

    entries.forEach((entry, index) => {
      if (!entry.signature) {
        return;
      }

      if (!grouped.has(entry.signature)) {
        grouped.set(entry.signature, []);
      }

      grouped.get(entry.signature).push({
        index,
        step: entry.step,
        labels: entry.labels,
        content: entry.content,
        screenshot: entry.screenshot,
      });
    });

    return Array.from(grouped.entries())
      .filter(([, occurrences]) => occurrences.length > 1)
      .map(([signature, occurrences]) => ({
        signature,
        occurrences,
      }));
  }

  async function attachTurnEvidence(name = 'assistant-turn-evidence') {
    if (!turnEvidence.length) {
      return;
    }

    await attachJson(testInfo, name, turnEvidence);
  }

  async function attachFailureArtifacts(prefix = 'assistant') {
    const chatLocator = page.locator(chatSelector).first();
    const chatHtml = await chatLocator.evaluate((node) => node.outerHTML);
    const buttonInventory = await collectVisibleButtonInventory();
    const widgetQuickActionLabels = await getVisibleWidgetQuickActionLabels();

    await testInfo.attach(`${prefix}-chat-dom.html`, {
      body: Buffer.from(chatHtml, 'utf8'),
      contentType: 'text/html',
    });
    await attachJson(testInfo, `${prefix}-button-inventory`, buttonInventory);
    await attachJson(testInfo, `${prefix}-widget-quick-action-labels`, widgetQuickActionLabels);
    await attachJson(testInfo, `${prefix}-console`, consoleEntries);
    await attachJson(testInfo, `${prefix}-page-errors`, pageErrors);
    await attachJson(testInfo, `${prefix}-requestfailed`, failedRequests);
    await attachJson(testInfo, `${prefix}-network-transcript`, transcript);
    await attachJson(testInfo, `${prefix}-turn-evidence`, turnEvidence);
  }

  async function assertNoAssistantSideErrors(prefix = 'assistant') {
    const assistantConsole = consoleEntries.filter((entry) => isAssistantOwnedConsole(entry) && !isKnownThirdPartyNoise(entry));
    const assistantPageErrors = pageErrors.filter((entry) => isAssistantOwnedPageError(entry) && !isKnownThirdPartyNoise(entry));
    const assistantFailedRequests = failedRequests.filter((entry) => isAssistantOwnedFailedRequest(entry));

    if (!assistantConsole.length && !assistantPageErrors.length && !assistantFailedRequests.length) {
      return;
    }

    await attachFailureArtifacts(prefix);

    expect({
      console: assistantConsole,
      pageErrors: assistantPageErrors,
      requestfailed: assistantFailedRequests,
    }, 'assistant-owned browser errors should not occur').toEqual({
      console: [],
      pageErrors: [],
      requestfailed: [],
    });
  }

  return {
    chatSelector,
    transcript,
    consoleEntries,
    pageErrors,
    failedRequests,
    latestAssistantTurn,
    latestUserTurn,
    findMessageTranscriptEntry,
    waitForMessageTranscriptEntry,
    getTurnButtonLabels,
    getVisibleWidgetQuickActionLabels,
    getTurnOverlapWithWidgetQuickActions,
    assertUniqueTurnButtonLabels,
    assertTurnButtonLabelsExactly,
    assertTurnButtonLabelsExclude,
    assertNoWidgetQuickActionOverlap,
    assertAssistantTurnHealthy,
    summarizeTurn,
    collectTurnSignatures,
    collectVisibleButtonInventory,
    attachLocatorScreenshot,
    captureTurnEvidence,
    captureTopicFlowEvidence,
    captureCustodyFlowEvidence,
    findRepeatedTurnSignatures,
    attachTurnEvidence,
    attachFailureArtifacts,
    assertNoAssistantSideErrors,
    turnEvidence,
  };
}

async function runWithAssistantDiagnostics(page, testInfo, options, callback) {
  const harness = await createAssistantHarness(page, testInfo, options);
  const prefix = options?.attachmentPrefix || 'assistant';

  try {
    await callback(harness);
    if (harness.turnEvidence.length) {
      await harness.attachTurnEvidence(`${prefix}-turn-evidence`);
    }
    await harness.assertNoAssistantSideErrors(prefix);
  } catch (error) {
    await harness.attachFailureArtifacts(prefix);
    throw error;
  }
}

async function installAssistantApiMocks(page, options = {}) {
  const messageRequests = [];
  const trackRequests = [];
  const messageResponses = Array.isArray(options.messageResponses) ? [...options.messageResponses] : [];

  await page.route('**/assistant/api/session/bootstrap', async (route) => {
    await route.fulfill({
      status: 200,
      contentType: 'text/plain; charset=UTF-8',
      body: options.bootstrapToken || 'playwright-csrf-token',
    });
  });

  await page.route('**/assistant/api/track', async (route) => {
    const request = route.request();
    trackRequests.push({
      url: request.url(),
      body: safeJsonParse(request.postData() || ''),
    });

    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({ status: 'ok' }),
    });
  });

  await page.route('**/assistant/api/message', async (route) => {
    const request = route.request();
    const payload = safeJsonParse(request.postData() || '') || {};
    messageRequests.push(payload);

    const next = messageResponses.shift();
    if (!next) {
      await route.fulfill({
        status: 500,
        contentType: 'application/json',
        body: JSON.stringify({
          error: 'Unexpected mocked assistant request',
        }),
      });
      return;
    }

    const resolved = typeof next === 'function'
      ? await next({ payload, request, index: messageRequests.length })
      : next;

    if (resolved.delayMs) {
      await page.waitForTimeout(resolved.delayMs);
    }

    await route.fulfill({
      status: resolved.status || 200,
      contentType: resolved.contentType || 'application/json',
      body: JSON.stringify(resolved.body || resolved),
    });
  });

  return {
    messageRequests,
    trackRequests,
  };
}

module.exports = {
  BUTTON_SELECTOR,
  SELECTORS,
  attachJson,
  createAssistantHarness,
  installAssistantApiMocks,
  normalizeWhitespace,
  runWithAssistantDiagnostics,
  safeJsonParse,
};
