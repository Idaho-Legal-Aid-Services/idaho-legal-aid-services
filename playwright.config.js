const { execSync } = require('node:child_process');

const { defineConfig } = require('@playwright/test');

function resolveBaseUrl() {
  if (process.env.PLAYWRIGHT_BASE_URL) {
    return process.env.PLAYWRIGHT_BASE_URL.trim();
  }

  try {
    const raw = execSync('ddev describe -j', {
      encoding: 'utf8',
      stdio: ['ignore', 'pipe', 'ignore'],
    });
    const data = JSON.parse(raw);
    const primaryUrl = String(data?.raw?.primary_url || '').trim();
    if (primaryUrl) {
      return primaryUrl;
    }
  } catch (error) {
    // Leave undefined so the test runner surfaces a clear error with the docs.
  }

  return undefined;
}

const baseURL = resolveBaseUrl();

module.exports = defineConfig({
  testDir: './web/modules/custom/ilas_site_assistant/tests/playwright',
  fullyParallel: false,
  forbidOnly: !!process.env.CI,
  retries: 0,
  workers: 1,
  reporter: [
    ['list'],
    ['html', { open: 'never', outputFolder: 'output/playwright/report' }],
  ],
  outputDir: 'output/playwright/test-results',
  use: {
    baseURL,
    browserName: 'chromium',
    headless: true,
    ignoreHTTPSErrors: true,
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
    video: 'retain-on-failure',
    actionTimeout: 10000,
    navigationTimeout: 30000,
  },
});
