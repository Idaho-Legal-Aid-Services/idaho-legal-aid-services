const { test, expect } = require('@playwright/test');

const {
  SELECTORS,
  installAssistantApiMocks,
  runWithAssistantDiagnostics,
} = require('./helpers/assistant-test-utils');

const CUSTODY_PRIMARY_ACTIONS = ['Find Custody forms', 'Read Custody guide'];
const CUSTODY_BROAD_FALLBACK_ACTIONS = ['Housing & Eviction', 'Family & Custody', 'Consumer & Debt', 'Seniors & Guardianship'];
const GENERIC_BROAD_FALLBACK_ACTIONS = ['Housing & Eviction', 'Family & Custody', 'Consumer & Debt', 'Seniors & Guardianship'];
const MOCKED_TYPED_TOPIC_CASES = [
  {
    slug: 'divorce',
    query: 'divorce',
    topicLabel: 'Divorce',
    formButtonId: 'forms_topic_family_divorce',
    formParentButtonId: 'forms_family',
    guideButtonId: 'guides_topic_family_divorce',
    guideParentButtonId: 'guides_family',
    resultTitles: [
      'Petition for Divorce',
      'Response to Divorce Petition',
    ],
  },
  {
    slug: 'eviction',
    query: 'eviction',
    topicLabel: 'Eviction',
    formButtonId: 'forms_topic_housing_eviction',
    formParentButtonId: 'forms_housing',
    guideButtonId: 'guides_topic_housing_eviction',
    guideParentButtonId: 'guides_housing',
    resultTitles: [
      'Answer to Eviction Complaint',
      'Motion to Stay Eviction',
    ],
  },
  {
    slug: 'child-support',
    query: 'child support',
    topicLabel: 'Child support',
    formButtonId: 'forms_topic_family_child_support',
    formParentButtonId: 'forms_family',
    guideButtonId: 'guides_topic_family_child_support',
    guideParentButtonId: 'guides_family',
    resultTitles: [
      'Petition to Establish Child Support',
      'Motion to Modify Child Support',
    ],
  },
  {
    slug: 'guardianship',
    query: 'guardianship',
    topicLabel: 'Guardianship',
    formButtonId: 'forms_seniors',
    formParentButtonId: 'forms',
    guideButtonId: 'guides_seniors',
    guideParentButtonId: 'guides',
    resultTitles: [
      'Petition for Adult Guardianship',
      'Temporary Guardianship Motion',
    ],
  },
  {
    slug: 'debt',
    query: 'debt',
    topicLabel: 'Debt',
    formButtonId: 'forms_topic_consumer_debt_collection',
    formParentButtonId: 'forms_consumer',
    guideButtonId: 'guides_topic_consumer_debt_collection',
    guideParentButtonId: 'guides_consumer',
    resultTitles: [
      'Answer to Debt Collection Complaint',
      'Exemption Claim Form',
    ],
  },
];

function buildTopicFormAction(topicCase) {
  return `Find ${topicCase.topicLabel} forms`;
}

function buildTopicGuideAction(topicCase) {
  return `Read ${topicCase.topicLabel} guide`;
}

function mockedFormsInventoryResponse(options = {}) {
  return {
    type: 'forms_inventory',
    message: options.message || 'Choose a forms category:',
    topic_suggestions: options.topicSuggestions || [
      {
        label: 'Family & Custody',
        action: 'forms_family',
        selection: {
          button_id: 'forms_family',
          label: 'Family & Custody',
          parent_button_id: 'forms',
          source: 'response',
        },
      },
      {
        label: 'Housing & Eviction',
        action: 'forms_housing',
        selection: {
          button_id: 'forms_housing',
          label: 'Housing & Eviction',
          parent_button_id: 'forms',
          source: 'response',
        },
      },
    ],
    primary_action: {
      label: 'Browse All Forms',
      url: '/forms',
    },
    active_selection: {
      button_id: 'forms',
      label: options.activeSelectionLabel || 'Forms',
      parent_button_id: '',
      source: 'selection',
    },
  };
}

function mockedFamilyClarifyResponse() {
  return {
    type: 'form_finder_clarify',
    message: 'What type of family law issue are you dealing with?',
    topic_suggestions: [
      {
        label: 'Divorce or separation',
        action: 'forms_topic_family_divorce',
        selection: {
          button_id: 'forms_topic_family_divorce',
          label: 'Divorce or separation',
          parent_button_id: 'forms_family',
          source: 'response',
        },
      },
      {
        label: 'Custody or parenting time',
        action: 'forms_topic_family_custody',
        selection: {
          button_id: 'forms_topic_family_custody',
          label: 'Custody or parenting time',
          parent_button_id: 'forms_family',
          source: 'response',
        },
      },
    ],
    primary_action: {
      label: 'Browse All Forms',
      url: '/forms',
    },
    active_selection: {
      button_id: 'forms_family',
      label: 'Family & Custody',
      parent_button_id: 'forms',
      source: 'selection',
    },
  };
}

function mockedCustodyMenuResponse() {
  return {
    type: 'fallback',
    response_mode: 'clarify',
    message: 'I can help with Custody. What would you like to do?',
    topic_suggestions: [
      {
        label: 'Find Custody forms',
        action: 'forms_topic_family_custody',
        selection: {
          button_id: 'forms_topic_family_custody',
          label: 'Find Custody forms',
          parent_button_id: 'forms_family',
          source: 'response',
        },
      },
      {
        label: 'Read Custody guide',
        action: 'guides_topic_family_custody',
        selection: {
          button_id: 'guides_topic_family_custody',
          label: 'Read Custody guide',
          parent_button_id: 'guides_family',
          source: 'response',
        },
      },
    ],
    primary_action: {
      label: 'Browse All Forms',
      url: '/forms',
    },
    active_selection: null,
  };
}

function mockedCustodyResultsResponse() {
  return {
    type: 'resources',
    response_mode: 'navigate',
    message: 'Here are some custody forms that might help:',
    results: [
      {
        title: 'Petition for Custody and Parenting Time',
        url: '/forms/custody/petition',
        description: 'Start a custody or parenting-time case.',
        type: 'form',
        has_file: true,
      },
      {
        title: 'Temporary Custody Motion',
        url: '/forms/custody/temporary-motion',
        description: 'Ask the court for temporary custody orders.',
        type: 'form',
        has_file: true,
      },
    ],
    fallback_url: '/forms',
    fallback_label: 'Browse all forms',
    active_selection: {
      button_id: 'forms_topic_family_custody',
      label: 'Find Custody forms',
      parent_button_id: 'forms_family',
      source: 'selection',
    },
  };
}

function mockedCustodyGuideResultsResponse() {
  return {
    type: 'resources',
    response_mode: 'navigate',
    message: 'Here are some custody guides that might help:',
    results: [
      {
        title: 'Custody Basics Guide',
        url: '/guides/custody/basics',
        description: 'Overview of custody and parenting-time rules.',
        type: 'guide',
        has_file: true,
      },
      {
        title: 'Custody Court Process Guide',
        url: '/guides/custody/court-process',
        description: 'What to expect during a custody case.',
        type: 'guide',
        has_file: true,
      },
    ],
    fallback_url: '/guides',
    fallback_label: 'Browse all guides',
    active_selection: {
      button_id: 'guides_topic_family_custody',
      label: 'Read Custody guide',
      parent_button_id: 'guides_family',
      source: 'selection',
    },
  };
}

function mockedCustodyBroadFallbackResponse() {
  return {
    type: 'form_finder_clarify',
    response_mode: 'clarify',
    message: 'Find Custody forms. I could not find matching forms for that query. Try a different keyword, or pick a topic:',
    topic_suggestions: [
      {
        label: 'Housing & Eviction',
        action: 'forms_housing',
        selection: {
          button_id: 'forms_housing',
          label: 'Housing & Eviction',
          parent_button_id: 'forms',
          source: 'response',
        },
      },
      {
        label: 'Family & Custody',
        action: 'forms_family',
        selection: {
          button_id: 'forms_family',
          label: 'Family & Custody',
          parent_button_id: 'forms',
          source: 'response',
        },
      },
      {
        label: 'Consumer & Debt',
        action: 'forms_consumer',
        selection: {
          button_id: 'forms_consumer',
          label: 'Consumer & Debt',
          parent_button_id: 'forms',
          source: 'response',
        },
      },
      {
        label: 'Seniors & Guardianship',
        action: 'forms_seniors',
        selection: {
          button_id: 'forms_seniors',
          label: 'Seniors & Guardianship',
          parent_button_id: 'forms',
          source: 'response',
        },
      },
    ],
    primary_action: {
      label: 'Browse All Forms',
      url: '/forms',
    },
    active_selection: {
      button_id: 'forms_topic_family_custody',
      label: 'Find Custody forms',
      parent_button_id: 'forms_family',
      source: 'selection',
    },
  };
}

function mockedTypedTopicMenuResponse(topicCase) {
  const formAction = buildTopicFormAction(topicCase);
  const guideAction = buildTopicGuideAction(topicCase);

  return {
    type: 'fallback',
    response_mode: 'clarify',
    message: `I can help with ${topicCase.topicLabel}. What would you like to do?`,
    topic_suggestions: [
      {
        label: formAction,
        action: topicCase.formButtonId,
        selection: {
          button_id: topicCase.formButtonId,
          label: formAction,
          parent_button_id: topicCase.formParentButtonId,
          source: 'response',
        },
      },
      {
        label: guideAction,
        action: topicCase.guideButtonId,
        selection: {
          button_id: topicCase.guideButtonId,
          label: guideAction,
          parent_button_id: topicCase.guideParentButtonId,
          source: 'response',
        },
      },
    ],
    primary_action: {
      label: 'Browse All Forms',
      url: '/forms',
    },
    active_selection: null,
  };
}

function mockedTypedTopicResultsResponse(topicCase) {
  const formAction = buildTopicFormAction(topicCase);
  const topicSlug = topicCase.slug.replace(/[^a-z0-9]+/g, '-');
  const topicText = topicCase.topicLabel.toLowerCase();

  return {
    type: 'resources',
    response_mode: 'navigate',
    message: `Here are some ${topicText} forms that might help:`,
    results: topicCase.resultTitles.map((title, index) => ({
      title,
      url: `/forms/${topicSlug}/${index + 1}`,
      description: `${title} for ${topicText} matters.`,
      type: 'form',
      has_file: true,
    })),
    fallback_url: '/forms',
    fallback_label: 'Browse all forms',
    active_selection: {
      button_id: topicCase.formButtonId,
      label: formAction,
      parent_button_id: topicCase.formParentButtonId,
      source: 'selection',
    },
  };
}

async function openWidget(page) {
  const toggle = page.getByRole('button', { name: 'Open Aila Chat' });
  await expect(toggle).toBeVisible();
  await toggle.click();
  await expect(page.locator(SELECTORS.widgetPanel)).toBeVisible();
}

async function sendTypedWidgetMessage(page, message) {
  const input = page.locator(`${SELECTORS.widgetPanel} .assistant-input`);
  await expect(input).toBeVisible();
  await input.fill(message);
  await input.press('Enter');
}

test.describe('assistant mocked widget flows', () => {
  test('rapid double click sends one widget message request and renders one menu turn', async ({ page }, testInfo) => {
    await runWithAssistantDiagnostics(page, testInfo, {
      chatSelector: SELECTORS.widgetChat,
      attachmentPrefix: 'assistant-mocked-widget-race',
    }, async (assistant) => {
      const mocked = await installAssistantApiMocks(page, {
        messageResponses: [
          {
            delayMs: 250,
            body: mockedFormsInventoryResponse(),
          },
        ],
      });

      await page.goto('/');
      await openWidget(page);

      const quickAction = page.locator(`${SELECTORS.widgetQuickActions}[data-action="forms"]`);
      await quickAction.dblclick();

      await expect.poll(() => mocked.messageRequests.length).toBe(1);
      await expect(page.locator(`${SELECTORS.widgetChat} ${SELECTORS.assistantTurns}`)).toHaveCount(2);

      const categoryTurn = await assistant.latestAssistantTurn();
      await expect(categoryTurn.locator(SELECTORS.topicButtons)).toHaveCount(2);
      await assistant.assertAssistantTurnHealthy(categoryTurn, { requireButtons: true });
      await assistant.captureTurnEvidence('mocked-widget-forms-category-turn', {
        turnLocator: categoryTurn,
        screenshotLocator: page.locator(SELECTORS.widgetPanel),
        attachmentPrefix: 'assistant-mocked-widget-race',
        buttonSelector: SELECTORS.topicButtons,
      });

      const labels = await assistant.assertUniqueTurnButtonLabels(
        categoryTurn,
        SELECTORS.topicButtons,
        'mocked widget forms category turn',
      );
      await assistant.assertNoWidgetQuickActionOverlap(
        categoryTurn,
        SELECTORS.topicButtons,
        'mocked widget forms category turn',
      );
      expect(labels).toEqual(['Family & Custody', 'Housing & Eviction']);

      expect(mocked.messageRequests[0]?.context?.quickAction).toBe('forms');
      expect(mocked.messageRequests[0]?.context?.selection?.button_id).toBe('forms');
      expect(mocked.messageRequests[0]?.context?.selection?.label).toBe('Forms');
    });
  });

  test('widget assistant buttons keep data-selection attributes and send the expected payload', async ({ page }, testInfo) => {
    await runWithAssistantDiagnostics(page, testInfo, {
      chatSelector: SELECTORS.widgetChat,
      attachmentPrefix: 'assistant-mocked-widget-selection',
    }, async (assistant) => {
      const mocked = await installAssistantApiMocks(page, {
        messageResponses: [
          mockedFormsInventoryResponse(),
          mockedFamilyClarifyResponse(),
        ],
      });

      await page.goto('/');
      await openWidget(page);
      await page.locator(`${SELECTORS.widgetQuickActions}[data-action="forms"]`).click();

      const categoryTurn = await assistant.latestAssistantTurn();
      await assistant.assertAssistantTurnHealthy(categoryTurn, { requireButtons: true });
      await assistant.captureTurnEvidence('mocked-widget-selection-category-turn', {
        turnLocator: categoryTurn,
        screenshotLocator: page.locator(SELECTORS.widgetPanel),
        attachmentPrefix: 'assistant-mocked-widget-selection',
        buttonSelector: SELECTORS.topicButtons,
      });

      const familyButton = categoryTurn.locator('.topic-suggestion-btn[data-selection-button-id="forms_family"]');
      await expect(familyButton).toBeVisible();
      await expect(familyButton).toHaveAttribute('data-selection-label', 'Family & Custody');
      await expect(familyButton).toHaveAttribute('data-selection-parent-id', 'forms');
      await expect(familyButton).toHaveAttribute('data-selection-source', 'response');

      await familyButton.click();

      await expect.poll(() => mocked.messageRequests.length).toBe(2);
      expect(mocked.messageRequests[1]).toMatchObject({
        message: 'Family & Custody',
        context: {
          selection: {
            button_id: 'forms_family',
            label: 'Family & Custody',
            parent_button_id: 'forms',
            source: 'response',
          },
        },
      });

      const childTurn = await assistant.latestAssistantTurn();
      await assistant.assertAssistantTurnHealthy(childTurn, { requireButtons: true });
      await expect(childTurn).toContainText('What type of family law issue are you dealing with?');
      await assistant.captureTurnEvidence('mocked-widget-selection-child-turn', {
        turnLocator: childTurn,
        screenshotLocator: page.locator(SELECTORS.widgetPanel),
        attachmentPrefix: 'assistant-mocked-widget-selection',
        buttonSelector: SELECTORS.topicButtons,
      });

      const childLabels = await assistant.assertUniqueTurnButtonLabels(
        childTurn,
        SELECTORS.topicButtons,
        'mocked widget family clarify turn',
      );
      await assistant.assertNoWidgetQuickActionOverlap(
        childTurn,
        SELECTORS.topicButtons,
        'mocked widget family clarify turn',
      );
      expect(childLabels).toEqual(['Divorce or separation', 'Custody or parenting time']);
    });
  });

  test('minimal mocked widget response remains non-empty and uncorrupted after a button click', async ({ page }, testInfo) => {
    await runWithAssistantDiagnostics(page, testInfo, {
      chatSelector: SELECTORS.widgetChat,
      attachmentPrefix: 'assistant-mocked-widget-mangled',
    }, async (assistant) => {
      await installAssistantApiMocks(page, {
        messageResponses: [
          mockedFormsInventoryResponse({
            message: 'Choose one category to keep going.',
            topicSuggestions: [
              {
                label: 'Family & Custody',
                action: 'forms_family',
                selection: {
                  button_id: 'forms_family',
                  label: 'Family & Custody',
                  parent_button_id: 'forms',
                  source: 'response',
                },
              },
            ],
          }),
        ],
      });

      await page.goto('/');
      await openWidget(page);
      await page.locator(`${SELECTORS.widgetQuickActions}[data-action="forms"]`).click();

      const assistantTurn = await assistant.latestAssistantTurn();
      await assistant.assertAssistantTurnHealthy(assistantTurn, { requireButtons: true });
      await expect(assistantTurn).toContainText('Choose one category to keep going.');
      await assistant.captureTurnEvidence('mocked-widget-minimal-response-turn', {
        turnLocator: assistantTurn,
        screenshotLocator: page.locator(SELECTORS.widgetPanel),
        attachmentPrefix: 'assistant-mocked-widget-mangled',
        buttonSelector: SELECTORS.topicButtons,
      });

      const buttonLabels = await assistant.assertUniqueTurnButtonLabels(
        assistantTurn,
        SELECTORS.topicButtons,
        'mocked minimal widget assistant turn',
      );
      await assistant.assertNoWidgetQuickActionOverlap(
        assistantTurn,
        SELECTORS.topicButtons,
        'mocked minimal widget assistant turn',
      );
      expect(buttonLabels).toEqual(['Family & Custody']);
      await expect(assistantTurn.locator('.message-content')).not.toContainText('[object Object]');
      await expect(assistantTurn.locator('.message-content')).not.toContainText('undefined');
    });
  });

  test('typed custody mock renders only custody-specific next-step buttons', async ({ page }, testInfo) => {
    await runWithAssistantDiagnostics(page, testInfo, {
      chatSelector: SELECTORS.widgetChat,
      attachmentPrefix: 'assistant-mocked-widget-custody-menu',
    }, async (assistant) => {
      const custodyMenuResponse = mockedCustodyMenuResponse();
      const mocked = await installAssistantApiMocks(page, {
        messageResponses: [custodyMenuResponse],
      });

      await page.goto('/');
      await openWidget(page);
      await sendTypedWidgetMessage(page, 'custody');

      await expect.poll(() => mocked.messageRequests.length).toBe(1);
      expect(mocked.messageRequests[0]?.message).toBe('custody');
      expect(mocked.messageRequests[0]?.context).toBeUndefined();

      const custodyTurn = await assistant.latestAssistantTurn();
      await assistant.assertAssistantTurnHealthy(custodyTurn, { requireButtons: true });
      const custodyTurnEvidence = await assistant.captureTurnEvidence('mocked-widget-custody-menu-turn', {
        turnLocator: custodyTurn,
        screenshotLocator: page.locator(SELECTORS.widgetPanel),
        attachmentPrefix: 'assistant-mocked-widget-custody-menu',
        buttonSelector: SELECTORS.topicButtons,
      });

      await assistant.assertTurnButtonLabelsExactly(
        custodyTurn,
        CUSTODY_PRIMARY_ACTIONS,
        SELECTORS.topicButtons,
        'mocked typed custody menu',
      );
      await assistant.captureCustodyFlowEvidence('assistant-mocked-widget-custody-menu-flow', {
        typedMessage: 'custody',
        firstRequestPayload: mocked.messageRequests[0],
        firstResponseBody: custodyMenuResponse,
        firstTurnEvidence: custodyTurnEvidence,
      });
    });
  });

  test('typed custody forms click keeps the payload intact and renders custody-specific results', async ({ page }, testInfo) => {
    await runWithAssistantDiagnostics(page, testInfo, {
      chatSelector: SELECTORS.widgetChat,
      attachmentPrefix: 'assistant-mocked-widget-custody-results',
    }, async (assistant) => {
      const custodyMenuResponse = mockedCustodyMenuResponse();
      const custodyResultsResponse = mockedCustodyResultsResponse();
      const mocked = await installAssistantApiMocks(page, {
        messageResponses: [
          custodyMenuResponse,
          custodyResultsResponse,
        ],
      });

      await page.goto('/');
      await openWidget(page);
      await sendTypedWidgetMessage(page, 'custody');

      await expect.poll(() => mocked.messageRequests.length).toBe(1);
      const custodyTurn = await assistant.latestAssistantTurn();
      await assistant.assertAssistantTurnHealthy(custodyTurn, { requireButtons: true });
      const custodyTurnEvidence = await assistant.captureTurnEvidence('mocked-widget-custody-results-menu-turn', {
        turnLocator: custodyTurn,
        screenshotLocator: page.locator(SELECTORS.widgetPanel),
        attachmentPrefix: 'assistant-mocked-widget-custody-results',
        buttonSelector: SELECTORS.topicButtons,
      });

      await custodyTurn.getByRole('button', { name: 'Find Custody forms' }).click();

      await expect.poll(() => mocked.messageRequests.length).toBe(2);
      expect(mocked.messageRequests[1]).toMatchObject({
        message: 'Find Custody forms',
        context: {
          selection: {
            button_id: 'forms_topic_family_custody',
            label: 'Find Custody forms',
            parent_button_id: 'forms_family',
            source: 'response',
          },
        },
      });

      const resultsTurn = await assistant.latestAssistantTurn();
      await assistant.assertAssistantTurnHealthy(resultsTurn);
      await assistant.captureTurnEvidence('mocked-widget-custody-results-turn', {
        turnLocator: resultsTurn,
        screenshotLocator: page.locator(SELECTORS.widgetPanel),
        attachmentPrefix: 'assistant-mocked-widget-custody-results',
      });
      await assistant.assertTurnButtonLabelsExclude(
        resultsTurn,
        CUSTODY_BROAD_FALLBACK_ACTIONS,
        SELECTORS.topicButtons,
        'mocked custody results turn',
      );
      await expect(resultsTurn.locator(SELECTORS.resultLinks)).toHaveCount(2);
      await expect(resultsTurn.locator(SELECTORS.resultLinks).first()).toBeVisible();

      await assistant.captureCustodyFlowEvidence('assistant-mocked-widget-custody-results-flow', {
        typedMessage: 'custody',
        clickedButton: {
          label: 'Find Custody forms',
          selection: mocked.messageRequests[1]?.context?.selection || null,
        },
        firstRequestPayload: mocked.messageRequests[0],
        firstResponseBody: custodyMenuResponse,
        secondRequestPayload: mocked.messageRequests[1],
        secondResponseBody: custodyResultsResponse,
        firstTurnEvidence: custodyTurnEvidence,
        secondTurnLocator: resultsTurn,
      });
    });
  });

  test('typed custody guide click keeps the payload intact and renders custody-specific guides', async ({ page }, testInfo) => {
    await runWithAssistantDiagnostics(page, testInfo, {
      chatSelector: SELECTORS.widgetChat,
      attachmentPrefix: 'assistant-mocked-widget-custody-guides',
    }, async (assistant) => {
      const custodyMenuResponse = mockedCustodyMenuResponse();
      const custodyGuideResultsResponse = mockedCustodyGuideResultsResponse();
      const mocked = await installAssistantApiMocks(page, {
        messageResponses: [
          custodyMenuResponse,
          custodyGuideResultsResponse,
        ],
      });

      await page.goto('/');
      await openWidget(page);
      await sendTypedWidgetMessage(page, 'custody');

      await expect.poll(() => mocked.messageRequests.length).toBe(1);
      const custodyTurn = await assistant.latestAssistantTurn();
      await assistant.assertAssistantTurnHealthy(custodyTurn, { requireButtons: true });

      await custodyTurn.getByRole('button', { name: 'Read Custody guide' }).click();

      await expect.poll(() => mocked.messageRequests.length).toBe(2);
      expect(mocked.messageRequests[1]).toMatchObject({
        message: 'Read Custody guide',
        context: {
          selection: {
            button_id: 'guides_topic_family_custody',
            label: 'Read Custody guide',
            parent_button_id: 'guides_family',
            source: 'response',
          },
        },
      });

      const guideResultsTurn = await assistant.latestAssistantTurn();
      await assistant.assertAssistantTurnHealthy(guideResultsTurn);
      await expect(guideResultsTurn).toContainText('custody guides');
      await expect(guideResultsTurn.locator(SELECTORS.resultLinks)).toHaveCount(2);
      await expect(guideResultsTurn.locator(SELECTORS.resultLinks).first()).toBeVisible();
    });
  });

  test('bad broad custody fallback payload is rendered verbatim after a correct widget click', async ({ page }, testInfo) => {
    await runWithAssistantDiagnostics(page, testInfo, {
      chatSelector: SELECTORS.widgetChat,
      attachmentPrefix: 'assistant-mocked-widget-custody-broad-fallback',
    }, async (assistant) => {
      const custodyMenuResponse = mockedCustodyMenuResponse();
      const broadFallbackResponse = mockedCustodyBroadFallbackResponse();
      const mocked = await installAssistantApiMocks(page, {
        messageResponses: [
          custodyMenuResponse,
          broadFallbackResponse,
        ],
      });

      await page.goto('/');
      await openWidget(page);
      await sendTypedWidgetMessage(page, 'custody');

      await expect.poll(() => mocked.messageRequests.length).toBe(1);
      const custodyTurn = await assistant.latestAssistantTurn();
      const custodyTurnEvidence = await assistant.captureTurnEvidence('mocked-widget-custody-broad-fallback-menu-turn', {
        turnLocator: custodyTurn,
        screenshotLocator: page.locator(SELECTORS.widgetPanel),
        attachmentPrefix: 'assistant-mocked-widget-custody-broad-fallback',
        buttonSelector: SELECTORS.topicButtons,
      });

      await custodyTurn.getByRole('button', { name: 'Find Custody forms' }).click();

      await expect.poll(() => mocked.messageRequests.length).toBe(2);
      expect(mocked.messageRequests[1]).toMatchObject({
        message: 'Find Custody forms',
        context: {
          selection: {
            button_id: 'forms_topic_family_custody',
            label: 'Find Custody forms',
            parent_button_id: 'forms_family',
            source: 'response',
          },
        },
      });

      const broadFallbackTurn = await assistant.latestAssistantTurn();
      await assistant.assertAssistantTurnHealthy(broadFallbackTurn, { requireButtons: true });
      const broadFallbackEvidence = await assistant.captureTurnEvidence('mocked-widget-custody-broad-fallback-turn', {
        turnLocator: broadFallbackTurn,
        screenshotLocator: page.locator(SELECTORS.widgetPanel),
        attachmentPrefix: 'assistant-mocked-widget-custody-broad-fallback',
        buttonSelector: SELECTORS.topicButtons,
      });

      await assistant.assertTurnButtonLabelsExactly(
        broadFallbackTurn,
        CUSTODY_BROAD_FALLBACK_ACTIONS,
        SELECTORS.topicButtons,
        'mocked broad custody fallback turn',
      );
      await assistant.captureCustodyFlowEvidence('assistant-mocked-widget-custody-broad-fallback-flow', {
        typedMessage: 'custody',
        clickedButton: {
          label: 'Find Custody forms',
          selection: mocked.messageRequests[1]?.context?.selection || null,
        },
        firstRequestPayload: mocked.messageRequests[0],
        firstResponseBody: custodyMenuResponse,
        secondRequestPayload: mocked.messageRequests[1],
        secondResponseBody: broadFallbackResponse,
        firstTurnEvidence: custodyTurnEvidence,
        secondTurnEvidence: broadFallbackEvidence,
      });
    });
  });

  MOCKED_TYPED_TOPIC_CASES.forEach((topicCase) => {
    test(`typed ${topicCase.query} mock renders topic-specific next steps and results`, async ({ page }, testInfo) => {
      await runWithAssistantDiagnostics(page, testInfo, {
        chatSelector: SELECTORS.widgetChat,
        attachmentPrefix: `assistant-mocked-widget-${topicCase.slug}`,
      }, async (assistant) => {
        const formAction = buildTopicFormAction(topicCase);
        const guideAction = buildTopicGuideAction(topicCase);
        const menuResponse = mockedTypedTopicMenuResponse(topicCase);
        const resultsResponse = mockedTypedTopicResultsResponse(topicCase);
        const mocked = await installAssistantApiMocks(page, {
          messageResponses: [
            menuResponse,
            resultsResponse,
          ],
        });

        await page.goto('/');
        await openWidget(page);
        await sendTypedWidgetMessage(page, topicCase.query);

        await expect.poll(() => mocked.messageRequests.length).toBe(1);
        expect(mocked.messageRequests[0]?.message).toBe(topicCase.query);
        expect(mocked.messageRequests[0]?.context).toBeUndefined();

        const menuTurn = await assistant.latestAssistantTurn();
        await assistant.assertAssistantTurnHealthy(menuTurn, { requireButtons: true });
        const menuTurnEvidence = await assistant.captureTurnEvidence(`mocked-widget-${topicCase.slug}-menu-turn`, {
          turnLocator: menuTurn,
          screenshotLocator: page.locator(SELECTORS.widgetPanel),
          attachmentPrefix: `assistant-mocked-widget-${topicCase.slug}`,
          buttonSelector: SELECTORS.topicButtons,
        });

        await assistant.assertTurnButtonLabelsExactly(
          menuTurn,
          [formAction, guideAction],
          SELECTORS.topicButtons,
          `mocked typed ${topicCase.query} menu`,
        );
        await assistant.assertNoWidgetQuickActionOverlap(
          menuTurn,
          SELECTORS.topicButtons,
          `mocked typed ${topicCase.query} menu`,
        );

        await menuTurn.getByRole('button', { name: formAction }).click();

        await expect.poll(() => mocked.messageRequests.length).toBe(2);
        expect(mocked.messageRequests[1]).toMatchObject({
          message: formAction,
          context: {
            selection: {
              button_id: topicCase.formButtonId,
              label: formAction,
              parent_button_id: topicCase.formParentButtonId,
              source: 'response',
            },
          },
        });

        const resultsTurn = await assistant.latestAssistantTurn();
        await assistant.assertAssistantTurnHealthy(resultsTurn);
        const resultsTurnEvidence = await assistant.captureTurnEvidence(`mocked-widget-${topicCase.slug}-results-turn`, {
          turnLocator: resultsTurn,
          screenshotLocator: page.locator(SELECTORS.widgetPanel),
          attachmentPrefix: `assistant-mocked-widget-${topicCase.slug}`,
          forbiddenLabels: GENERIC_BROAD_FALLBACK_ACTIONS,
        });

        await assistant.assertTurnButtonLabelsExclude(
          resultsTurn,
          GENERIC_BROAD_FALLBACK_ACTIONS,
          SELECTORS.topicButtons,
          `mocked typed ${topicCase.query} results turn`,
        );
        await expect(resultsTurn).toContainText(`${topicCase.topicLabel.toLowerCase()} forms`);
        await expect(resultsTurn.locator(SELECTORS.resultLinks)).toHaveCount(topicCase.resultTitles.length);
        await expect(resultsTurn.locator(SELECTORS.resultLinks).first()).toBeVisible();

        await assistant.captureTopicFlowEvidence(`assistant-mocked-widget-${topicCase.slug}-flow`, {
          topicKey: topicCase.slug,
          topicLabel: topicCase.topicLabel,
          typedMessage: topicCase.query,
          expectedFormAction: formAction,
          expectedGuideAction: guideAction,
          clickedButton: {
            label: formAction,
            selection: mocked.messageRequests[1]?.context?.selection || null,
          },
          firstRequestPayload: mocked.messageRequests[0],
          firstResponseBody: menuResponse,
          secondRequestPayload: mocked.messageRequests[1],
          secondResponseBody: resultsResponse,
          firstTurnEvidence: menuTurnEvidence,
          secondTurnEvidence: resultsTurnEvidence,
        });
      });
    });
  });

  test('diagnostic proof: duplicate backend widget labels fail with artifact capture', async ({ page }, testInfo) => {
    test.skip(!process.env.ASSISTANT_WIDGET_DUPLICATE_PROOF, 'Enable ASSISTANT_WIDGET_DUPLICATE_PROOF=1 to run the intentional failure proof path.');

    await runWithAssistantDiagnostics(page, testInfo, {
      chatSelector: SELECTORS.widgetChat,
      attachmentPrefix: 'assistant-mocked-widget-duplicate-proof',
    }, async (assistant) => {
      await installAssistantApiMocks(page, {
        messageResponses: [
          mockedFormsInventoryResponse({
            topicSuggestions: [
              {
                label: 'Family & Custody',
                action: 'forms_family',
                selection: {
                  button_id: 'forms_family',
                  label: 'Family & Custody',
                  parent_button_id: 'forms',
                  source: 'response',
                },
              },
              {
                label: 'Family & Custody',
                action: 'forms_family_duplicate',
                selection: {
                  button_id: 'forms_family_duplicate',
                  label: 'Family & Custody',
                  parent_button_id: 'forms',
                  source: 'response',
                },
              },
            ],
          }),
        ],
      });

      await page.goto('/');
      await openWidget(page);
      await page.locator(`${SELECTORS.widgetQuickActions}[data-action="forms"]`).click();

      const duplicateTurn = await assistant.latestAssistantTurn();
      await assistant.captureTurnEvidence('mocked-widget-duplicate-payload-turn', {
        turnLocator: duplicateTurn,
        screenshotLocator: page.locator(SELECTORS.widgetPanel),
        attachmentPrefix: 'assistant-mocked-widget-duplicate-proof',
        buttonSelector: SELECTORS.topicButtons,
      });

      await assistant.assertUniqueTurnButtonLabels(
        duplicateTurn,
        SELECTORS.topicButtons,
        'mocked duplicate widget payload turn',
      );
    });
  });

  test('diagnostic proof: widget buttons that overlap persistent quick actions fail with artifact capture', async ({ page }, testInfo) => {
    test.skip(!process.env.ASSISTANT_WIDGET_DUPLICATE_PROOF, 'Enable ASSISTANT_WIDGET_DUPLICATE_PROOF=1 to run the intentional failure proof path.');

    await runWithAssistantDiagnostics(page, testInfo, {
      chatSelector: SELECTORS.widgetChat,
      attachmentPrefix: 'assistant-mocked-widget-static-overlap-proof',
    }, async (assistant) => {
      await installAssistantApiMocks(page, {
        messageResponses: [
          mockedFormsInventoryResponse({
            topicSuggestions: [
              {
                label: 'Forms',
                action: 'forms_duplicate_static',
                selection: {
                  button_id: 'forms_duplicate_static',
                  label: 'Forms',
                  parent_button_id: 'forms',
                  source: 'response',
                },
              },
            ],
          }),
        ],
      });

      await page.goto('/');
      await openWidget(page);
      await page.locator(`${SELECTORS.widgetQuickActions}[data-action="forms"]`).click();

      const overlapTurn = await assistant.latestAssistantTurn();
      await assistant.captureTurnEvidence('mocked-widget-static-overlap-turn', {
        turnLocator: overlapTurn,
        screenshotLocator: page.locator(SELECTORS.widgetPanel),
        attachmentPrefix: 'assistant-mocked-widget-static-overlap-proof',
        buttonSelector: SELECTORS.topicButtons,
      });

      await assistant.assertNoWidgetQuickActionOverlap(
        overlapTurn,
        SELECTORS.topicButtons,
        'mocked widget static-overlap turn',
      );
    });
  });
});
