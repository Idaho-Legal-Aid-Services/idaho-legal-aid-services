const { test, expect } = require('@playwright/test');

const {
  SELECTORS,
  attachJson,
  runWithAssistantDiagnostics,
  safeJsonParse,
} = require('../helpers/assistant-test-utils');

function isAssistantMessageRequest(request) {
  return request.method() === 'POST'
    && /\/assistant\/api\/message$/.test(request.url());
}

test.describe('journey: guides discovery', () => {
  test.describe('widget mode', () => {
    test('guides quick-action through category to sub-topic', async ({ page }, testInfo) => {
      await runWithAssistantDiagnostics(page, testInfo, {
        chatSelector: SELECTORS.widgetChat,
        attachmentPrefix: 'journey-guides-widget',
      }, async (assistant) => {
        await page.goto('/');

        const toggle = page.getByRole('button', { name: 'Open Aila Chat' });
        await expect(toggle).toBeVisible();
        await toggle.click();

        const panel = page.locator(SELECTORS.widgetPanel);
        await expect(panel).toBeVisible();
        await expect(toggle).toHaveAttribute('aria-expanded', 'true');
        await expect(page.locator(SELECTORS.widgetQuickActions)).toHaveCount(4);

        const welcomeTurn = await assistant.latestAssistantTurn();
        await expect(welcomeTurn).toContainText('Aila');

        // --- Turn 1: click Guides quick-action ---

        const guidesRequestPromise = page.waitForRequest((request) => {
          if (!isAssistantMessageRequest(request)) {
            return false;
          }

          const payload = safeJsonParse(request.postData() || '');
          return payload?.context?.quickAction === 'guides';
        });
        const guidesResponsePromise = page.waitForResponse((response) => {
          if (!response.ok() || !/\/assistant\/api\/message$/.test(response.url())) {
            return false;
          }

          const payload = safeJsonParse(response.request().postData() || '');
          return payload?.context?.quickAction === 'guides';
        });

        await page.locator(`${SELECTORS.widgetQuickActions}[data-action="guides"]`).click();

        const guidesRequest = await guidesRequestPromise;
        await guidesResponsePromise;

        const guidesPayload = safeJsonParse(guidesRequest.postData() || '');
        expect(guidesPayload?.context?.quickAction).toBe('guides');
        expect(guidesPayload?.context?.selection?.button_id).toBe('guides');
        expect(guidesPayload?.context?.selection?.label).toBe('Guides');

        const categoryTurn = await assistant.latestAssistantTurn();
        await assistant.assertAssistantTurnHealthy(categoryTurn, { requireButtons: true });

        const categoryLabels = await assistant.assertUniqueTurnButtonLabels(
          categoryTurn,
          SELECTORS.topicButtons,
          'widget guides category turn',
        );
        expect(categoryLabels).toEqual(expect.arrayContaining([
          'Housing & Eviction',
          'Family & Custody',
          'Consumer & Debt',
        ]));
        await assistant.captureTurnEvidence('widget-guides-category-turn', {
          turnLocator: categoryTurn,
          screenshotLocator: panel,
          attachmentPrefix: 'journey-guides-widget',
          buttonSelector: SELECTORS.topicButtons,
        });

        // --- Turn 2: click Family & Custody ---

        const familyRequestPromise = page.waitForRequest((request) => {
          if (!isAssistantMessageRequest(request)) {
            return false;
          }

          const payload = safeJsonParse(request.postData() || '');
          return payload?.context?.selection?.button_id === 'guides_family';
        });
        const familyResponsePromise = page.waitForResponse((response) => {
          if (!response.ok() || !/\/assistant\/api\/message$/.test(response.url())) {
            return false;
          }

          const payload = safeJsonParse(response.request().postData() || '');
          return payload?.context?.selection?.button_id === 'guides_family';
        });

        await categoryTurn.getByRole('button', { name: 'Family & Custody' }).click();

        const familyRequest = await familyRequestPromise;
        await familyResponsePromise;

        const familyPayload = safeJsonParse(familyRequest.postData() || '');
        expect(familyPayload?.context?.selection?.button_id).toBe('guides_family');
        expect(familyPayload?.context?.selection?.parent_button_id).toBe('guides');
        expect(familyPayload?.context?.selection?.label).toBe('Family & Custody');

        const childTurn = await assistant.latestAssistantTurn();
        await assistant.assertAssistantTurnHealthy(childTurn, { requireButtons: true });
        await assistant.assertUniqueTurnButtonLabels(
          childTurn,
          SELECTORS.topicButtons,
          'widget guides family clarify turn',
        );
        await assistant.captureTurnEvidence('widget-guides-family-turn', {
          turnLocator: childTurn,
          screenshotLocator: panel,
          attachmentPrefix: 'journey-guides-widget',
          buttonSelector: SELECTORS.topicButtons,
        });

        // --- Duplicate menu check ---

        const repeatedSignatures = assistant.findRepeatedTurnSignatures();
        if (repeatedSignatures.length) {
          await attachJson(testInfo, 'journey-guides-widget-repeated-menu-evidence', repeatedSignatures);
        }
      });
    });
  });

  test.describe('page mode', () => {
    test('guides quick-action through category to sub-topic', async ({ page }, testInfo) => {
      await runWithAssistantDiagnostics(page, testInfo, {
        chatSelector: SELECTORS.pageChat,
        attachmentPrefix: 'journey-guides-page',
      }, async (assistant) => {
        await page.goto('/assistant');

        await expect(page.locator(SELECTORS.pageChat)).toBeVisible();
        await expect(page.locator(SELECTORS.pageQuickActions)).toHaveCount(6);

        const welcomeTurn = await assistant.latestAssistantTurn();
        await expect(welcomeTurn).toContainText('Hello!');

        // --- Turn 1: click Find a Guide quick-action ---

        const guidesRequestPromise = page.waitForRequest((request) => {
          if (!isAssistantMessageRequest(request)) {
            return false;
          }

          const payload = safeJsonParse(request.postData() || '');
          return payload?.context?.quickAction === 'guides';
        });
        const guidesResponsePromise = page.waitForResponse((response) => {
          return response.request().method() === 'POST'
            && /\/assistant\/api\/message$/.test(response.url())
            && response.ok();
        });

        await page.getByRole('button', { name: 'Find a Guide' }).click();

        const guidesRequest = await guidesRequestPromise;
        await guidesResponsePromise;

        const guidesPayload = safeJsonParse(guidesRequest.postData() || '');
        expect(guidesPayload?.context?.quickAction).toBe('guides');
        expect(guidesPayload?.context?.selection?.button_id).toBe('guides');
        expect(guidesPayload?.context?.selection?.label).toBe('Find a Guide');

        const categoryTurn = await assistant.latestAssistantTurn();
        await assistant.assertAssistantTurnHealthy(categoryTurn, { requireButtons: true });

        const categoryLabels = await assistant.assertUniqueTurnButtonLabels(
          categoryTurn,
          SELECTORS.topicButtons,
          'page guides category turn',
        );
        expect(categoryLabels).toEqual(expect.arrayContaining([
          'Housing & Eviction',
          'Family & Custody',
          'Consumer & Debt',
        ]));

        // --- Turn 2: click Family & Custody ---

        const familyRequestPromise = page.waitForRequest((request) => {
          if (!isAssistantMessageRequest(request)) {
            return false;
          }

          const payload = safeJsonParse(request.postData() || '');
          return payload?.context?.selection?.button_id === 'guides_family';
        });
        const familyResponsePromise = page.waitForResponse((response) => {
          if (!response.ok() || !/\/assistant\/api\/message$/.test(response.url())) {
            return false;
          }

          const body = safeJsonParse(response.request().postData() || '');
          return body?.context?.selection?.button_id === 'guides_family';
        });

        await categoryTurn.getByRole('button', { name: 'Family & Custody' }).click();

        const familyRequest = await familyRequestPromise;
        await familyResponsePromise;

        const familyPayload = safeJsonParse(familyRequest.postData() || '');
        expect(familyPayload?.context?.selection?.button_id).toBe('guides_family');
        expect(familyPayload?.context?.selection?.parent_button_id).toBe('guides');
        expect(familyPayload?.context?.selection?.label).toBe('Family & Custody');

        const childTurn = await assistant.latestAssistantTurn();
        await assistant.assertAssistantTurnHealthy(childTurn, { requireButtons: true });
        await assistant.assertUniqueTurnButtonLabels(
          childTurn,
          SELECTORS.topicButtons,
          'page guides family clarify turn',
        );

        // --- Duplicate menu check ---

        const turnSignatures = await assistant.collectTurnSignatures();
        const repeatedSignatures = turnSignatures.filter((entry, index) => {
          return turnSignatures.findIndex((candidate) => candidate.signature === entry.signature) !== index;
        });

        if (repeatedSignatures.length) {
          await attachJson(testInfo, 'journey-guides-page-repeated-menu-evidence', repeatedSignatures);
        }
      });
    });
  });
});
