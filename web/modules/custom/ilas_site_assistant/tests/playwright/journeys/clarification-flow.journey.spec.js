const { test, expect } = require('@playwright/test');

const {
  BUTTON_SELECTOR,
  SELECTORS,
  attachJson,
  runWithAssistantDiagnostics,
  safeJsonParse,
} = require('../helpers/assistant-test-utils');

function isAssistantMessageRequest(request) {
  return request.method() === 'POST'
    && /\/assistant\/api\/message$/.test(request.url());
}

test.describe('journey: clarification flow', () => {
  test.describe('widget mode', () => {
    test('typed "help" triggers disambiguation with clickable options', async ({ page }, testInfo) => {
      await runWithAssistantDiagnostics(page, testInfo, {
        chatSelector: SELECTORS.widgetChat,
        attachmentPrefix: 'journey-clarify-widget',
      }, async (assistant) => {
        await page.goto('/');

        const toggle = page.getByRole('button', { name: 'Open Aila Chat' });
        await expect(toggle).toBeVisible();
        await toggle.click();

        const panel = page.locator(SELECTORS.widgetPanel);
        await expect(panel).toBeVisible();

        const welcomeTurn = await assistant.latestAssistantTurn();
        await expect(welcomeTurn).toContainText('Aila');

        // --- Type "help" and submit ---

        const input = panel.locator('.assistant-input');
        await input.fill('help');

        const helpRequestPromise = page.waitForRequest((request) => {
          if (!isAssistantMessageRequest(request)) {
            return false;
          }

          const payload = safeJsonParse(request.postData() || '');
          return payload?.message === 'help';
        });
        const helpResponsePromise = page.waitForResponse((response) => {
          if (!response.ok() || !/\/assistant\/api\/message$/.test(response.url())) {
            return false;
          }

          const payload = safeJsonParse(response.request().postData() || '');
          return payload?.message === 'help';
        });

        await panel.locator('.assistant-send-btn').click();

        const helpRequest = await helpRequestPromise;
        await helpResponsePromise;

        // Verify payload: typed message, no quickAction shortcut.
        const helpPayload = safeJsonParse(helpRequest.postData() || '');
        expect(helpPayload?.message).toBe('help');
        expect(helpPayload?.context?.quickAction).toBeUndefined();

        // Verify user turn rendered.
        const userTurn = await assistant.latestUserTurn();
        await expect(userTurn).toContainText('help');

        // Verify disambiguation response.
        const disambigTurn = await assistant.latestAssistantTurn();
        await assistant.assertAssistantTurnHealthy(disambigTurn);

        // Should have at least 2 clickable options.
        const buttonCount = await disambigTurn.locator(BUTTON_SELECTOR).count();
        expect(buttonCount, 'disambiguation turn should offer at least 2 options').toBeGreaterThanOrEqual(2);

        await assistant.assertUniqueTurnButtonLabels(
          disambigTurn,
          BUTTON_SELECTOR,
          'widget disambiguation turn',
        );
        await assistant.captureTurnEvidence('widget-clarify-disambiguation', {
          turnLocator: disambigTurn,
          screenshotLocator: panel,
          attachmentPrefix: 'journey-clarify-widget',
        });
      });
    });

    test('button click after disambiguation narrows correctly', async ({ page }, testInfo) => {
      await runWithAssistantDiagnostics(page, testInfo, {
        chatSelector: SELECTORS.widgetChat,
        attachmentPrefix: 'journey-clarify-narrowed-widget',
      }, async (assistant) => {
        await page.goto('/');

        const toggle = page.getByRole('button', { name: 'Open Aila Chat' });
        await toggle.click();

        const panel = page.locator(SELECTORS.widgetPanel);
        await expect(panel).toBeVisible();

        // Type "help" and submit.
        const input = panel.locator('.assistant-input');
        await input.fill('help');

        const helpResponsePromise = page.waitForResponse((response) => {
          if (!response.ok() || !/\/assistant\/api\/message$/.test(response.url())) {
            return false;
          }

          const payload = safeJsonParse(response.request().postData() || '');
          return payload?.message === 'help';
        });

        await panel.locator('.assistant-send-btn').click();
        await helpResponsePromise;

        const disambigTurn = await assistant.latestAssistantTurn();
        await assistant.assertAssistantTurnHealthy(disambigTurn);

        await assistant.captureTurnEvidence('narrowed-disambiguation', {
          turnLocator: disambigTurn,
          screenshotLocator: panel,
          attachmentPrefix: 'journey-clarify-narrowed-widget',
        });

        // --- Click a disambiguation option ---

        const firstButton = disambigTurn.locator(BUTTON_SELECTOR).first();
        await expect(firstButton).toBeVisible();
        const buttonLabel = await firstButton.innerText();

        const narrowRequestPromise = page.waitForRequest((request) => {
          return isAssistantMessageRequest(request);
        });
        const narrowResponsePromise = page.waitForResponse((response) => {
          return response.request().method() === 'POST'
            && /\/assistant\/api\/message$/.test(response.url())
            && response.ok();
        });

        await firstButton.click();

        await narrowRequestPromise;
        await narrowResponsePromise;

        // Verify the conversation narrowed to a coherent next turn.
        const narrowedTurn = await assistant.latestAssistantTurn();
        await assistant.assertAssistantTurnHealthy(narrowedTurn);

        await assistant.captureTurnEvidence('narrowed-follow-up', {
          turnLocator: narrowedTurn,
          screenshotLocator: panel,
          attachmentPrefix: 'journey-clarify-narrowed-widget',
        });

        // Verify no duplicate menus across the conversation.
        const repeatedSignatures = assistant.findRepeatedTurnSignatures();
        if (repeatedSignatures.length) {
          await attachJson(testInfo, 'journey-clarify-narrowed-repeated-evidence', repeatedSignatures);
        }

        // Attach the button label chosen for diagnostic traceability.
        await attachJson(testInfo, 'journey-clarify-narrowed-button-chosen', {
          label: buttonLabel,
        });
      });
    });
  });

  test.describe('page mode', () => {
    test('typed "help" triggers disambiguation with clickable options', async ({ page }, testInfo) => {
      await runWithAssistantDiagnostics(page, testInfo, {
        chatSelector: SELECTORS.pageChat,
        attachmentPrefix: 'journey-clarify-page',
      }, async (assistant) => {
        await page.goto('/assistant');

        await expect(page.locator(SELECTORS.pageChat)).toBeVisible();

        const welcomeTurn = await assistant.latestAssistantTurn();
        await expect(welcomeTurn).toContainText('Hello!');

        // --- Type "help" and submit ---

        const input = page.locator('#assistant-input');
        await input.fill('help');

        const helpRequestPromise = page.waitForRequest((request) => {
          if (!isAssistantMessageRequest(request)) {
            return false;
          }

          const payload = safeJsonParse(request.postData() || '');
          return payload?.message === 'help';
        });
        const helpResponsePromise = page.waitForResponse((response) => {
          if (!response.ok() || !/\/assistant\/api\/message$/.test(response.url())) {
            return false;
          }

          const payload = safeJsonParse(response.request().postData() || '');
          return payload?.message === 'help';
        });

        await page.locator('.assistant-send-btn').click();

        const helpRequest = await helpRequestPromise;
        await helpResponsePromise;

        const helpPayload = safeJsonParse(helpRequest.postData() || '');
        expect(helpPayload?.message).toBe('help');
        expect(helpPayload?.context?.quickAction).toBeUndefined();

        const userTurn = await assistant.latestUserTurn();
        await expect(userTurn).toContainText('help');

        const disambigTurn = await assistant.latestAssistantTurn();
        await assistant.assertAssistantTurnHealthy(disambigTurn);

        const buttonCount = await disambigTurn.locator(BUTTON_SELECTOR).count();
        expect(buttonCount, 'disambiguation turn should offer at least 2 options').toBeGreaterThanOrEqual(2);

        await assistant.assertUniqueTurnButtonLabels(
          disambigTurn,
          BUTTON_SELECTOR,
          'page disambiguation turn',
        );
        await assistant.captureTurnEvidence('page-clarify-disambiguation', {
          turnLocator: disambigTurn,
          attachmentPrefix: 'journey-clarify-page',
        });
      });
    });
  });
});
