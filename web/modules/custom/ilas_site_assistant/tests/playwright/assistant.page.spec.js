const { test, expect } = require('@playwright/test');

const {
  SELECTORS,
  attachJson,
  installAssistantApiMocks,
  runWithAssistantDiagnostics,
  safeJsonParse,
} = require('./helpers/assistant-test-utils');

function isAssistantMessageRequest(request) {
  return request.method() === 'POST' && /\/assistant\/api\/message$/.test(request.url());
}

function mockedFormsInventoryResponse() {
  return {
    type: 'forms_inventory',
    message: 'Choose a forms category:',
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
    ],
    primary_action: {
      label: 'Browse All Forms',
      url: '/forms',
    },
    active_selection: {
      button_id: 'forms',
      label: 'Find a Form',
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
        label: 'Custody or parenting time',
        action: 'forms_topic_family_custody',
        selection: {
          button_id: 'forms_topic_family_custody',
          label: 'Custody or parenting time',
          parent_button_id: 'forms_family',
          source: 'response',
        },
      },
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

function mockedDivorceResultsResponse() {
  return {
    type: 'resources',
    response_mode: 'navigate',
    message: 'Here are divorce or separation forms that might help.',
    results: [
      {
        title: 'Petition for Divorce',
        url: '/forms/family/divorce/petition',
        description: 'Start a divorce or legal separation case.',
        type: 'form',
        has_file: true,
      },
      {
        title: 'Response to Divorce Petition',
        url: '/forms/family/divorce/response',
        description: 'Respond after the other side files.',
        type: 'form',
        has_file: true,
      },
    ],
    fallback_url: '/forms',
    fallback_label: 'Browse all forms',
    active_selection: {
      button_id: 'forms_topic_family_divorce',
      label: 'Divorce or separation',
      parent_button_id: 'forms_family',
      source: 'selection',
    },
  };
}

test.describe('assistant page', () => {
  test('forms page-mode contract keeps payloads and rendered options coherent', async ({ page }, testInfo) => {
    await runWithAssistantDiagnostics(page, testInfo, {
      chatSelector: SELECTORS.pageChat,
      attachmentPrefix: 'assistant-page',
    }, async (assistant) => {
      await installAssistantApiMocks(page, {
        messageResponses: [
          mockedFormsInventoryResponse(),
          mockedFamilyClarifyResponse(),
          mockedDivorceResultsResponse(),
        ],
      });

      await page.goto('/assistant');

      await expect(page.locator(SELECTORS.pageChat)).toBeVisible();
      await expect(page.locator(SELECTORS.pageQuickActions)).toHaveCount(6);

      const welcomeTurn = await assistant.latestAssistantTurn();
      await expect(welcomeTurn).toContainText('Hello!');
      const turns = page.locator(`${SELECTORS.pageChat} ${SELECTORS.assistantTurns}`);

      const categoryTurnCount = await turns.count();
      const formsRequestPromise = page.waitForRequest((request) => {
        if (!isAssistantMessageRequest(request)) {
          return false;
        }

        const payload = safeJsonParse(request.postData() || '');
        return payload?.context?.quickAction === 'forms';
      });

      await page.getByRole('button', { name: 'Find a Form' }).click();

      const formsRequest = await formsRequestPromise;
      await expect(turns).toHaveCount(categoryTurnCount + 1, { timeout: 20000 });
      await assistant.waitForMessageTranscriptEntry(
        (payload) => payload?.context?.quickAction === 'forms',
        'assistant page forms quick action',
        20000,
      );

      const formsPayload = safeJsonParse(formsRequest.postData() || '');
      expect(formsPayload?.context?.quickAction).toBe('forms');
      expect(formsPayload?.context?.selection?.button_id).toBe('forms');
      expect(formsPayload?.context?.selection?.label).toBe('Find a Form');

      const categoryTurn = await assistant.latestAssistantTurn();
      await assistant.assertAssistantTurnHealthy(categoryTurn, { requireButtons: true });

      const categoryLabels = await assistant.assertUniqueTurnButtonLabels(
        categoryTurn,
        SELECTORS.topicButtons,
        'forms category turn',
      );
      expect(categoryLabels).toEqual(expect.arrayContaining([
        'Housing & Eviction',
        'Family & Custody',
        'Consumer & Debt',
      ]));

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
        'assistant page family selection',
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
        'family clarify turn',
      );
      expect(childLabels).toEqual(expect.arrayContaining([
        'Custody or parenting time',
        'Divorce or separation',
      ]));

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
        'assistant page divorce selection',
        20000,
      );

      const divorcePayload = safeJsonParse(divorceRequest.postData() || '');
      expect(divorcePayload?.context?.selection?.button_id).toBe('forms_topic_family_divorce');
      expect(divorcePayload?.context?.selection?.parent_button_id).toBe('forms_family');
      expect(divorcePayload?.context?.selection?.label).toBe('Divorce or separation');

      const resultTurn = await assistant.latestAssistantTurn();
      await assistant.assertAssistantTurnHealthy(resultTurn);
      await expect(resultTurn).toContainText('divorce or separation forms');
      await expect(resultTurn.locator(SELECTORS.resultLinks).first()).toBeVisible();

      const turnSignatures = await assistant.collectTurnSignatures();
      const repeatedTurnSignatures = turnSignatures.filter((entry, index) => {
        return turnSignatures.findIndex((candidate) => candidate.signature === entry.signature) !== index;
      });

      if (repeatedTurnSignatures.length) {
        await attachJson(testInfo, 'assistant-page-repeated-menu-evidence', repeatedTurnSignatures);
      }
    });
  });
});
