const { test, expect } = require('@playwright/test');

const {
  SELECTORS,
  attachJson,
  normalizeWhitespace,
  runWithAssistantDiagnostics,
  safeJsonParse,
} = require('../helpers/assistant-test-utils');

function isAssistantMessageRequest(request) {
  return request.method() === 'POST'
    && /\/assistant\/api\/message$/.test(request.url());
}

test.describe('journey: ui regressions', () => {
  test('no duplicate greeting after widget open', async ({ page }, testInfo) => {
    await runWithAssistantDiagnostics(page, testInfo, {
      chatSelector: SELECTORS.widgetChat,
      attachmentPrefix: 'journey-regression-greeting',
    }, async (assistant) => {
      await page.goto('/');

      const toggle = page.getByRole('button', { name: 'Open Aila Chat' });
      await toggle.click();

      const panel = page.locator(SELECTORS.widgetPanel);
      await expect(panel).toBeVisible();

      const welcomeTurn = await assistant.latestAssistantTurn();
      await expect(welcomeTurn).toContainText('Aila');

      // Exactly one assistant turn (the greeting) should exist.
      const chatLocator = page.locator(SELECTORS.widgetChat);
      const turnCount = await chatLocator.locator(SELECTORS.assistantTurns).count();
      expect(turnCount, 'widget should show exactly one greeting turn on open').toBe(1);

      // No lingering typing indicator.
      const typingCount = await chatLocator.locator('.chat-message--typing').count();
      expect(typingCount, 'no typing indicator should be visible after greeting').toBe(0);

      await assistant.captureTurnEvidence('greeting-check', {
        turnLocator: welcomeTurn,
        screenshotLocator: panel,
        attachmentPrefix: 'journey-regression-greeting',
      });
    });
  });

  test('no duplicate buttons after button click and rerender', async ({ page }, testInfo) => {
    await runWithAssistantDiagnostics(page, testInfo, {
      chatSelector: SELECTORS.widgetChat,
      attachmentPrefix: 'journey-regression-buttons',
    }, async (assistant) => {
      await page.goto('/');

      const toggle = page.getByRole('button', { name: 'Open Aila Chat' });
      await toggle.click();

      const panel = page.locator(SELECTORS.widgetPanel);
      await expect(panel).toBeVisible();

      // --- Turn 1: forms quick-action ---

      const formsResponsePromise = page.waitForResponse((response) => {
        if (!response.ok() || !/\/assistant\/api\/message$/.test(response.url())) {
          return false;
        }

        const payload = safeJsonParse(response.request().postData() || '');
        return payload?.context?.quickAction === 'forms';
      });

      await page.locator(`${SELECTORS.widgetQuickActions}[data-action="forms"]`).click();
      await formsResponsePromise;

      const categoryTurn = await assistant.latestAssistantTurn();
      await assistant.assertAssistantTurnHealthy(categoryTurn, { requireButtons: true });
      await assistant.assertUniqueTurnButtonLabels(
        categoryTurn,
        SELECTORS.topicButtons,
        'forms category turn',
      );

      await assistant.captureTurnEvidence('regression-category-turn', {
        turnLocator: categoryTurn,
        screenshotLocator: panel,
        attachmentPrefix: 'journey-regression-buttons',
        buttonSelector: SELECTORS.topicButtons,
      });

      // --- Turn 2: click Family & Custody ---

      const familyResponsePromise = page.waitForResponse((response) => {
        if (!response.ok() || !/\/assistant\/api\/message$/.test(response.url())) {
          return false;
        }

        const payload = safeJsonParse(response.request().postData() || '');
        return payload?.context?.selection?.button_id === 'forms_family';
      });

      await categoryTurn.getByRole('button', { name: 'Family & Custody' }).click();
      await familyResponsePromise;

      const childTurn = await assistant.latestAssistantTurn();
      await assistant.assertAssistantTurnHealthy(childTurn, { requireButtons: true });
      await assistant.assertUniqueTurnButtonLabels(
        childTurn,
        SELECTORS.topicButtons,
        'family clarify turn',
      );

      // No identical menus across turns.
      const signatures = await assistant.collectTurnSignatures();
      const repeated = assistant.findRepeatedTurnSignatures();

      await attachJson(testInfo, 'journey-regression-turn-signatures', signatures);

      if (repeated.length) {
        await attachJson(testInfo, 'journey-regression-repeated-menus', repeated);
      }

      // Signatures should be unique across turns (category menu != child menu).
      expect(repeated, 'no two turns should share identical button menus').toHaveLength(0);
    });
  });

  test('no empty assistant message containers after button clicks', async ({ page }, testInfo) => {
    await runWithAssistantDiagnostics(page, testInfo, {
      chatSelector: SELECTORS.widgetChat,
      attachmentPrefix: 'journey-regression-empty',
    }, async (assistant) => {
      await page.goto('/');

      const toggle = page.getByRole('button', { name: 'Open Aila Chat' });
      await toggle.click();

      const panel = page.locator(SELECTORS.widgetPanel);
      await expect(panel).toBeVisible();

      // --- Interact: forms → Family & Custody ---

      const formsResponsePromise = page.waitForResponse((response) => {
        if (!response.ok() || !/\/assistant\/api\/message$/.test(response.url())) {
          return false;
        }

        const payload = safeJsonParse(response.request().postData() || '');
        return payload?.context?.quickAction === 'forms';
      });

      await page.locator(`${SELECTORS.widgetQuickActions}[data-action="forms"]`).click();
      await formsResponsePromise;

      const categoryTurn = await assistant.latestAssistantTurn();
      await assistant.assertAssistantTurnHealthy(categoryTurn, { requireButtons: true });

      const familyResponsePromise = page.waitForResponse((response) => {
        if (!response.ok() || !/\/assistant\/api\/message$/.test(response.url())) {
          return false;
        }

        const payload = safeJsonParse(response.request().postData() || '');
        return payload?.context?.selection?.button_id === 'forms_family';
      });

      await categoryTurn.getByRole('button', { name: 'Family & Custody' }).click();
      await familyResponsePromise;

      await assistant.latestAssistantTurn();

      // --- Check ALL assistant turns for empty content ---

      const chatLocator = page.locator(SELECTORS.widgetChat);
      const allTurns = chatLocator.locator(SELECTORS.assistantTurns);
      const turnCount = await allTurns.count();

      const emptyTurns = [];
      for (let i = 0; i < turnCount; i++) {
        const turn = allTurns.nth(i);
        const content = normalizeWhitespace(await turn.locator('.message-content').innerText());

        if (!content) {
          emptyTurns.push({ index: i });
        }
      }

      if (emptyTurns.length) {
        await attachJson(testInfo, 'journey-regression-empty-turns', emptyTurns);
      }

      expect(emptyTurns, 'no assistant turn should have empty message content').toHaveLength(0);
    });
  });

  test('widget open-close cycles do not corrupt state', async ({ page }, testInfo) => {
    await runWithAssistantDiagnostics(page, testInfo, {
      chatSelector: SELECTORS.widgetChat,
      attachmentPrefix: 'journey-regression-cycles',
    }, async (assistant) => {
      await page.goto('/');

      const toggle = page.getByRole('button', { name: 'Open Aila Chat' });
      const panel = page.locator(SELECTORS.widgetPanel);
      const chatLocator = page.locator(SELECTORS.widgetChat);

      // --- Cycle 1: open, verify greeting, close ---

      await toggle.click();
      await expect(panel).toBeVisible();

      const welcomeTurn = await assistant.latestAssistantTurn();
      await expect(welcomeTurn).toContainText('Aila');

      let turnCount = await chatLocator.locator(SELECTORS.assistantTurns).count();
      expect(turnCount, 'cycle 1: exactly one greeting turn').toBe(1);

      // Close.
      const closeBtn = panel.locator('.panel-close-btn');
      await closeBtn.click();
      await expect(panel).not.toBeVisible();
      await expect(toggle).toHaveAttribute('aria-expanded', 'false');

      // --- Cycle 2: reopen, verify still one greeting ---

      await toggle.click();
      await expect(panel).toBeVisible();

      turnCount = await chatLocator.locator(SELECTORS.assistantTurns).count();
      expect(turnCount, 'cycle 2: still exactly one greeting turn after reopen').toBe(1);

      // Close.
      await closeBtn.click();
      await expect(panel).not.toBeVisible();

      // --- Cycle 3: open, interact, close ---

      await toggle.click();
      await expect(panel).toBeVisible();

      const formsResponsePromise = page.waitForResponse((response) => {
        if (!response.ok() || !/\/assistant\/api\/message$/.test(response.url())) {
          return false;
        }

        const payload = safeJsonParse(response.request().postData() || '');
        return payload?.context?.quickAction === 'forms';
      });

      await page.locator(`${SELECTORS.widgetQuickActions}[data-action="forms"]`).click();
      await formsResponsePromise;

      const categoryTurn = await assistant.latestAssistantTurn();
      await assistant.assertAssistantTurnHealthy(categoryTurn, { requireButtons: true });

      // Should now have: welcome (1 assistant) + user message + category response (2 assistant).
      turnCount = await chatLocator.locator(SELECTORS.assistantTurns).count();
      expect(turnCount, 'cycle 3: two assistant turns after interaction').toBe(2);

      await closeBtn.click();
      await expect(panel).not.toBeVisible();

      // --- Cycle 4: reopen, verify conversation intact ---

      await toggle.click();
      await expect(panel).toBeVisible();

      turnCount = await chatLocator.locator(SELECTORS.assistantTurns).count();
      expect(turnCount, 'cycle 4: conversation intact after reopen').toBe(2);

      // Category turn buttons should still be unique.
      const reopenedCategoryTurn = await assistant.latestAssistantTurn();
      await assistant.assertUniqueTurnButtonLabels(
        reopenedCategoryTurn,
        SELECTORS.topicButtons,
        'cycle 4 reopened category turn',
      );

      // Verify sessionStorage is coherent.
      const state = safeJsonParse(
        await page.evaluate(() => sessionStorage.getItem('ilas_assistant_state')),
      );
      expect(state, 'sessionStorage should contain state').not.toBeNull();
      expect(state.v).toBe(2);
      // 3 display messages: welcome (assistant), user "Forms", category response (assistant).
      expect(state.messages.length, 'stored messages should match visible conversation').toBe(3);

      await assistant.captureTurnEvidence('cycle-4-intact', {
        screenshotLocator: panel,
        attachmentPrefix: 'journey-regression-cycles',
      });

      await attachJson(testInfo, 'journey-regression-cycles-final-state', {
        turnCount,
        storedMessageCount: state.messages.length,
        conversationId: state.conversationId,
        isOpen: state.isOpen,
      });
    });
  });
});
