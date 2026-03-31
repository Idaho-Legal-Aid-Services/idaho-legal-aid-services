const { test, expect } = require('@playwright/test');

const {
  SELECTORS,
  attachJson,
  runWithAssistantDiagnostics,
  safeJsonParse,
} = require('./helpers/assistant-test-utils');

function isAssistantMessageRequest(request) {
  return request.method() === 'POST' && /\/assistant\/api\/message$/.test(request.url());
}

const TOPIC_ESCALATION_ACTIONS = ['Apply for legal help', 'Call Legal Advice Line'];
const BROAD_FORM_FALLBACK_ACTIONS = ['Housing & Eviction', 'Consumer & Debt', 'Seniors & Guardianship'];
const CUSTODY_TOPIC_CASE = {
  slug: 'custody',
  query: 'custody',
  topicLabel: 'Custody',
  formButtonId: 'forms_topic_family_custody',
  formParentButtonId: 'forms_family',
  guideButtonId: 'guides_topic_family_custody',
  guideParentButtonId: 'guides_family',
};
const CUSTODY_PRIMARY_ACTIONS = [
  buildTopicFormAction(CUSTODY_TOPIC_CASE),
  buildTopicGuideAction(CUSTODY_TOPIC_CASE),
];
const CUSTODY_ESCALATION_ACTIONS = TOPIC_ESCALATION_ACTIONS;
const CUSTODY_BROAD_FALLBACK_ACTIONS = BROAD_FORM_FALLBACK_ACTIONS;
const RUN_LIVE_WIDGET_TESTS = Boolean(process.env.ASSISTANT_WIDGET_LIVE);
const STABLE_WIDGET_TOPIC_CASES = [
  {
    slug: 'divorce',
    query: 'divorce',
    topicLabel: 'Divorce',
    formButtonId: 'forms_topic_family_divorce',
    formParentButtonId: 'forms_family',
    guideButtonId: 'guides_topic_family_divorce',
    guideParentButtonId: 'guides_family',
  },
  {
    slug: 'eviction',
    query: 'eviction',
    topicLabel: 'Eviction',
    formButtonId: 'forms_topic_housing_eviction',
    formParentButtonId: 'forms_housing',
    guideButtonId: 'guides_topic_housing_eviction',
    guideParentButtonId: 'guides_housing',
  },
  {
    slug: 'child-support',
    query: 'child support',
    topicLabel: 'Child support',
    formButtonId: 'forms_topic_family_child_support',
    formParentButtonId: 'forms_family',
    guideButtonId: 'guides_topic_family_child_support',
    guideParentButtonId: 'guides_family',
  },
];
const EXTENDED_WIDGET_TOPIC_CASES = [
  {
    slug: 'guardianship',
    query: 'guardianship',
    topicLabel: 'Guardianship',
    formButtonId: 'forms_seniors',
    formParentButtonId: 'forms',
    guideButtonId: 'guides_seniors',
    guideParentButtonId: 'guides',
  },
  {
    slug: 'debt',
    query: 'debt',
    topicLabel: 'Debt',
    formButtonId: 'forms_topic_consumer_debt_collection',
    formParentButtonId: 'forms_consumer',
    guideButtonId: 'guides_topic_consumer_debt_collection',
    guideParentButtonId: 'guides_consumer',
  },
];
const ADDITIONAL_WIDGET_TOPIC_CASES = RUN_LIVE_WIDGET_TESTS && process.env.ASSISTANT_WIDGET_LIVE_TOPIC_MATRIX
  ? (process.env.ASSISTANT_WIDGET_EXTENDED_TOPICS
    ? [...STABLE_WIDGET_TOPIC_CASES, ...EXTENDED_WIDGET_TOPIC_CASES]
    : STABLE_WIDGET_TOPIC_CASES)
  : [];

function buildTopicFormAction(topicCase) {
  return `Find ${topicCase.topicLabel} forms`;
}

function buildTopicGuideAction(topicCase) {
  return `Read ${topicCase.topicLabel} guide`;
}

async function openWidget(page) {
  const toggle = page.getByRole('button', { name: 'Open Aila Chat' });
  await expect(toggle).toBeVisible();
  await toggle.click();

  const panel = page.locator(SELECTORS.widgetPanel);
  await expect(panel).toBeVisible();
  await expect(toggle).toHaveAttribute('aria-expanded', 'true');
  await expect(page.locator(SELECTORS.widgetQuickActions)).toHaveCount(4);

  return {
    panel,
    toggle,
  };
}

async function runTypedTopicFlow(page, assistant, topicCase, options = {}) {
  const responseTimeoutMs = options.responseTimeoutMs || 20000;
  const formAction = options.formAction || buildTopicFormAction(topicCase);
  const guideAction = options.guideAction || buildTopicGuideAction(topicCase);
  const formButtonId = options.formButtonId || topicCase.formButtonId || 'forms_finder';
  const formParentButtonId = options.formParentButtonId
    || topicCase.formParentButtonId
    || '';

  await page.goto('/');

  const { panel } = await openWidget(page);
  const welcomeTurn = await assistant.latestAssistantTurn();
  await expect(welcomeTurn).toContainText('Aila');

  const turns = page.locator(`${SELECTORS.widgetChat} ${SELECTORS.assistantTurns}`);
  const input = panel.locator('.assistant-input');
  await expect(input).toBeVisible();

  const firstTurnCount = await turns.count();
  const firstRequestPromise = page.waitForRequest((request) => {
    if (!isAssistantMessageRequest(request)) {
      return false;
    }

    const payload = safeJsonParse(request.postData() || '');
    return payload?.message === topicCase.query
      && !payload?.context?.quickAction
      && !payload?.context?.selection;
  });

  await input.fill(topicCase.query);
  await input.press('Enter');

  const firstRequest = await firstRequestPromise;
  await expect(turns).toHaveCount(firstTurnCount + 1, { timeout: responseTimeoutMs });

  const firstRequestPayload = safeJsonParse(firstRequest.postData() || '');
  const firstTranscriptEntry = await assistant.waitForMessageTranscriptEntry(
    (payload) => payload?.message === topicCase.query
      && !payload?.context?.quickAction
      && !payload?.context?.selection,
    `${topicCase.slug} first turn`,
    responseTimeoutMs,
  );
  const firstResponseBody = firstTranscriptEntry.responseBodyJson || {};
  expect(firstRequestPayload?.message).toBe(topicCase.query);
  expect(firstRequestPayload?.context).toBeFalsy();

  const firstTurn = await assistant.latestAssistantTurn();
  await assistant.assertAssistantTurnHealthy(firstTurn, { requireButtons: true });
  const firstLabels = await assistant.assertUniqueTurnButtonLabels(
    firstTurn,
    SELECTORS.topicButtons,
    `typed ${topicCase.slug} first turn`,
  );
  expect(firstLabels).toEqual(expect.arrayContaining([formAction, guideAction]));
  const firstTurnEvidence = await assistant.captureTurnEvidence(
    options.firstTurnStepName || `widget-${topicCase.slug}-first-turn`,
    {
      turnLocator: firstTurn,
      screenshotLocator: panel,
      attachmentPrefix: options.attachmentPrefix || `assistant-widget-${topicCase.slug}`,
      buttonSelector: SELECTORS.topicButtons,
      forbiddenLabels: options.firstTurnForbiddenLabels || TOPIC_ESCALATION_ACTIONS,
    },
  );

  if (options.stopAfterFirstTurn) {
    await assistant.captureTopicFlowEvidence(
      options.flowEvidenceName || `assistant-widget-${topicCase.slug}-flow`,
      {
        topicKey: topicCase.slug,
        topicLabel: topicCase.topicLabel,
        typedMessage: topicCase.query,
        expectedFormAction: formAction,
        expectedGuideAction: guideAction,
        firstRequestPayload,
        firstResponseBody,
        firstTurnEvidence,
      },
    );

    return {
      panel,
      firstTurn,
      firstTurnEvidence,
      firstRequestPayload,
      firstResponseBody,
    };
  }

  const secondTurnCount = await turns.count();
  const secondRequestPromise = page.waitForRequest((request) => {
    if (!isAssistantMessageRequest(request)) {
      return false;
    }

    const payload = safeJsonParse(request.postData() || '');
    return payload?.context?.selection?.button_id === formButtonId
      && payload?.context?.selection?.label === formAction;
  });
  await firstTurn.getByRole('button', { name: formAction }).click();

  const secondRequest = await secondRequestPromise;
  await expect(turns).toHaveCount(secondTurnCount + 1, { timeout: responseTimeoutMs });

  const secondRequestPayload = safeJsonParse(secondRequest.postData() || '');
  const secondTranscriptEntry = await assistant.waitForMessageTranscriptEntry(
    (payload) => payload?.context?.selection?.button_id === formButtonId
      && payload?.context?.selection?.label === formAction,
    `${topicCase.slug} forms click`,
    responseTimeoutMs,
  );
  const secondResponseBody = secondTranscriptEntry.responseBodyJson || {};
  expect(secondRequestPayload?.message).toBe(formAction);
  expect(secondRequestPayload?.context?.selection?.button_id).toBe(formButtonId);
  expect(secondRequestPayload?.context?.selection?.label).toBe(formAction);
  expect(secondRequestPayload?.context?.selection?.parent_button_id).toBe(formParentButtonId);
  expect(secondRequestPayload?.context?.selection?.source).toBe('response');

  const secondTurn = await assistant.latestAssistantTurn();
  await assistant.assertAssistantTurnHealthy(secondTurn);
  const secondTurnEvidence = await assistant.captureTurnEvidence(
    options.secondTurnStepName || `widget-${topicCase.slug}-post-click-turn`,
    {
      turnLocator: secondTurn,
      screenshotLocator: panel,
      attachmentPrefix: options.attachmentPrefix || `assistant-widget-${topicCase.slug}`,
      forbiddenLabels: options.secondTurnForbiddenLabels || BROAD_FORM_FALLBACK_ACTIONS,
    },
  );

  await assistant.captureTopicFlowEvidence(
    options.flowEvidenceName || `assistant-widget-${topicCase.slug}-flow`,
    {
      topicKey: topicCase.slug,
      topicLabel: topicCase.topicLabel,
      typedMessage: topicCase.query,
      expectedFormAction: formAction,
      expectedGuideAction: guideAction,
      clickedButton: {
        label: formAction,
        selection: secondRequestPayload?.context?.selection || null,
      },
      firstRequestPayload,
      firstResponseBody,
      secondRequestPayload,
      secondResponseBody,
      firstTurnEvidence,
      secondTurnEvidence,
    },
  );

  return {
    panel,
    topicCase,
    formAction,
    guideAction,
    firstTurn,
    secondTurn,
    firstTurnEvidence,
    secondTurnEvidence,
    firstRequestPayload,
    firstResponseBody,
    secondRequestPayload,
    secondResponseBody,
  };
}

async function runTypedCustodyFlow(page, assistant, options = {}) {
  return runTypedTopicFlow(page, assistant, CUSTODY_TOPIC_CASE, options);
}

test.describe('assistant widget', () => {
  test.skip(!RUN_LIVE_WIDGET_TESTS, 'Enable ASSISTANT_WIDGET_LIVE=1 to run live widget/backend diagnostics.');

  test('widget forms conversation keeps payloads, menus, and results coherent', async ({ page }, testInfo) => {
    await runWithAssistantDiagnostics(page, testInfo, {
      chatSelector: SELECTORS.widgetChat,
      attachmentPrefix: 'assistant-widget',
    }, async (assistant) => {
      await page.goto('/');

      const { panel, toggle } = await openWidget(page);

      const welcomeTurn = await assistant.latestAssistantTurn();
      await expect(welcomeTurn).toContainText('Aila');
      await assistant.captureTurnEvidence('widget-open-welcome-turn', {
        turnLocator: welcomeTurn,
        screenshotLocator: panel,
        attachmentPrefix: 'assistant-widget',
      });
      const turns = page.locator(`${SELECTORS.widgetChat} ${SELECTORS.assistantTurns}`);

      const categoryTurnCount = await turns.count();
      const formsRequestPromise = page.waitForRequest((request) => {
        if (!isAssistantMessageRequest(request)) {
          return false;
        }

        const payload = safeJsonParse(request.postData() || '');
        return payload?.context?.quickAction === 'forms';
      });

      await page.locator(`${SELECTORS.widgetQuickActions}[data-action="forms"]`).click();

      const formsRequest = await formsRequestPromise;
      await expect(turns).toHaveCount(categoryTurnCount + 1, { timeout: 20000 });
      await assistant.waitForMessageTranscriptEntry(
        (payload) => payload?.context?.quickAction === 'forms',
        'widget forms quick action',
        20000,
      );

      const formsPayload = safeJsonParse(formsRequest.postData() || '');
      expect(formsPayload?.context?.quickAction).toBe('forms');
      expect(formsPayload?.context?.selection?.button_id).toBe('forms');
      expect(formsPayload?.context?.selection?.label).toBe('Forms');

      const categoryTurn = await assistant.latestAssistantTurn();
      await assistant.assertAssistantTurnHealthy(categoryTurn, { requireButtons: true });

      const categoryLabels = await assistant.assertUniqueTurnButtonLabels(
        categoryTurn,
        SELECTORS.topicButtons,
        'widget forms category turn',
      );
      await assistant.assertNoWidgetQuickActionOverlap(
        categoryTurn,
        SELECTORS.topicButtons,
        'widget forms category turn',
      );
      expect(categoryLabels).toEqual(expect.arrayContaining([
        'Housing & Eviction',
        'Family & Custody',
        'Consumer & Debt',
      ]));
      await assistant.captureTurnEvidence('widget-forms-category-turn', {
        turnLocator: categoryTurn,
        screenshotLocator: panel,
        attachmentPrefix: 'assistant-widget',
        buttonSelector: SELECTORS.topicButtons,
      });

      const childTurnCount = await turns.count();
      const familyRequestPromise = page.waitForRequest((request) => {
        if (!isAssistantMessageRequest(request)) {
          return false;
        }

        const payload = safeJsonParse(request.postData() || '');
        return payload?.context?.selection?.button_id === 'forms_family';
      });

      await categoryTurn.getByRole('button', { name: 'Family & Custody' }).click();

      const familyRequest = await familyRequestPromise;
      await expect(turns).toHaveCount(childTurnCount + 1, { timeout: 20000 });
      await assistant.waitForMessageTranscriptEntry(
        (payload) => payload?.context?.selection?.button_id === 'forms_family',
        'widget family selection',
        20000,
      );

      const familyPayload = safeJsonParse(familyRequest.postData() || '');
      expect(familyPayload?.context?.selection?.button_id).toBe('forms_family');
      expect(familyPayload?.context?.selection?.parent_button_id).toBe('forms');
      expect(familyPayload?.context?.selection?.label).toBe('Family & Custody');

      const childTurn = await assistant.latestAssistantTurn();
      await assistant.assertAssistantTurnHealthy(childTurn, { requireButtons: true });
      await expect(childTurn).toContainText('What type of family law issue are you dealing with?');

      const childLabels = await assistant.assertUniqueTurnButtonLabels(
        childTurn,
        SELECTORS.topicButtons,
        'widget family clarify turn',
      );
      await assistant.assertNoWidgetQuickActionOverlap(
        childTurn,
        SELECTORS.topicButtons,
        'widget family clarify turn',
      );
      expect(childLabels).toEqual(expect.arrayContaining([
        'Custody or parenting time',
        'Divorce or separation',
      ]));
      await assistant.captureTurnEvidence('widget-family-clarify-turn', {
        turnLocator: childTurn,
        screenshotLocator: panel,
        attachmentPrefix: 'assistant-widget',
        buttonSelector: SELECTORS.topicButtons,
      });

      const resultTurnCount = await turns.count();
      const divorceRequestPromise = page.waitForRequest((request) => {
        if (!isAssistantMessageRequest(request)) {
          return false;
        }

        const payload = safeJsonParse(request.postData() || '');
        return payload?.context?.selection?.button_id === 'forms_topic_family_divorce';
      });

      await childTurn.getByRole('button', { name: 'Divorce or separation' }).click();

      const divorceRequest = await divorceRequestPromise;
      await expect(turns).toHaveCount(resultTurnCount + 1, { timeout: 20000 });
      await assistant.waitForMessageTranscriptEntry(
        (payload) => payload?.context?.selection?.button_id === 'forms_topic_family_divorce',
        'widget divorce selection',
        20000,
      );

      const divorcePayload = safeJsonParse(divorceRequest.postData() || '');
      expect(divorcePayload?.context?.selection?.button_id).toBe('forms_topic_family_divorce');
      expect(divorcePayload?.context?.selection?.parent_button_id).toBe('forms_family');
      expect(divorcePayload?.context?.selection?.label).toBe('Divorce or separation');

      const resultTurn = await assistant.latestAssistantTurn();
      await assistant.assertAssistantTurnHealthy(resultTurn);
      await expect(resultTurn).toContainText('Divorce or separation.');
      const firstResultLink = resultTurn.locator(SELECTORS.resultLinks).first();
      await firstResultLink.scrollIntoViewIfNeeded();
      await expect(firstResultLink).toBeVisible();
      await assistant.captureTurnEvidence('widget-divorce-results-turn', {
        turnLocator: resultTurn,
        screenshotLocator: panel,
        attachmentPrefix: 'assistant-widget',
      });

      const repeatedTurnSignatures = assistant.findRepeatedTurnSignatures();
      if (repeatedTurnSignatures.length) {
        await attachJson(testInfo, 'assistant-widget-repeated-menu-evidence', repeatedTurnSignatures);
      }
    });
  });

  test('typed custody widget flow captures the current button and follow-up evidence', async ({ page }, testInfo) => {
    await runWithAssistantDiagnostics(page, testInfo, {
      chatSelector: SELECTORS.widgetChat,
      attachmentPrefix: 'assistant-widget-custody',
    }, async (assistant) => {
      const flow = await runTypedCustodyFlow(page, assistant, {
        attachmentPrefix: 'assistant-widget-custody',
        flowEvidenceName: 'assistant-widget-custody-flow',
      });

      expect(flow.firstResponseBody?.type).toBeTruthy();
      expect(flow.secondResponseBody?.type).toBeTruthy();
      expect(flow.firstTurnEvidence.labels).toEqual(expect.arrayContaining(CUSTODY_PRIMARY_ACTIONS));
      expect(flow.secondRequestPayload?.context?.selection?.button_id).toBe(CUSTODY_TOPIC_CASE.formButtonId);
      expect(typeof flow.secondTurnEvidence.resultLinkCount).toBe('number');
    });
  });

  test('bare custody shows only custody-specific actions', async ({ page }, testInfo) => {
    await runWithAssistantDiagnostics(page, testInfo, {
      chatSelector: SELECTORS.widgetChat,
      attachmentPrefix: 'assistant-widget-custody-contract',
    }, async (assistant) => {
      const flow = await runTypedCustodyFlow(page, assistant, {
        attachmentPrefix: 'assistant-widget-custody-contract',
        flowEvidenceName: 'assistant-widget-custody-contract-first-turn',
        stopAfterFirstTurn: true,
        firstTurnForbiddenLabels: CUSTODY_ESCALATION_ACTIONS,
      });

      await assistant.assertTurnButtonLabelsExactly(
        flow.firstTurn,
        CUSTODY_PRIMARY_ACTIONS,
        SELECTORS.topicButtons,
        'typed custody first-turn contract',
      );
      await assistant.assertTurnButtonLabelsExclude(
        flow.firstTurn,
        CUSTODY_ESCALATION_ACTIONS,
        SELECTORS.topicButtons,
        'typed custody first-turn contract',
      );
    });
  });

  test('find custody forms returns custody-specific results without broad fallback categories', async ({ page }, testInfo) => {
    await runWithAssistantDiagnostics(page, testInfo, {
      chatSelector: SELECTORS.widgetChat,
      attachmentPrefix: 'assistant-widget-custody-results-contract',
    }, async (assistant) => {
      const flow = await runTypedCustodyFlow(page, assistant, {
        attachmentPrefix: 'assistant-widget-custody-results-contract',
        flowEvidenceName: 'assistant-widget-custody-results-contract-flow',
        secondTurnForbiddenLabels: CUSTODY_BROAD_FALLBACK_ACTIONS,
      });

      await assistant.assertTurnButtonLabelsExclude(
        flow.secondTurn,
        CUSTODY_BROAD_FALLBACK_ACTIONS,
        SELECTORS.topicButtons,
        'typed custody forms follow-up contract',
      );
      expect(flow.secondRequestPayload?.context?.selection?.button_id).toBe(CUSTODY_TOPIC_CASE.formButtonId);
      expect(
        flow.secondTurnEvidence.resultLinkCount,
        'Find Custody forms should render visible custody form results',
      ).toBeGreaterThan(0);
    });
  });

  ADDITIONAL_WIDGET_TOPIC_CASES.forEach((topicCase) => {
    test(`typed topic diagnostic captures live widget flow for ${topicCase.query}`, async ({ page }, testInfo) => {
      await runWithAssistantDiagnostics(page, testInfo, {
        chatSelector: SELECTORS.widgetChat,
        attachmentPrefix: `assistant-widget-${topicCase.slug}`,
      }, async (assistant) => {
        const flow = await runTypedTopicFlow(page, assistant, topicCase, {
          attachmentPrefix: `assistant-widget-${topicCase.slug}`,
          flowEvidenceName: `assistant-widget-${topicCase.slug}-flow`,
          firstTurnForbiddenLabels: TOPIC_ESCALATION_ACTIONS,
          secondTurnForbiddenLabels: BROAD_FORM_FALLBACK_ACTIONS,
        });

        expect(flow.firstResponseBody?.type).toBeTruthy();
        expect(flow.secondResponseBody?.type).toBeTruthy();
        expect(flow.firstTurnEvidence.labels).toEqual(expect.arrayContaining([
          flow.formAction,
          flow.guideAction,
        ]));
        expect(flow.secondRequestPayload?.context?.selection?.button_id).toBe(topicCase.formButtonId);
        expect(flow.secondRequestPayload?.context?.selection?.label).toBe(flow.formAction);
        expect(
          flow.secondTurnEvidence.hasButtons || flow.secondTurnEvidence.resultLinkCount > 0,
          `${topicCase.query} follow-up should render either refinement buttons or visible results`,
        ).toBeTruthy();

        const repeatedTurnSignatures = assistant.findRepeatedTurnSignatures([
          flow.firstTurnEvidence,
          flow.secondTurnEvidence,
        ]);
        if (repeatedTurnSignatures.length) {
          await attachJson(
            testInfo,
            `assistant-widget-${topicCase.slug}-repeated-menu-evidence`,
            repeatedTurnSignatures,
          );
        }
      });
    });
  });
});
