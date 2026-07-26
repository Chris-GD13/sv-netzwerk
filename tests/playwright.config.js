const { defineConfig } = require('@playwright/test');

module.exports = defineConfig({
  testDir: '.',
  testMatch: 'portal_screenshot_test.js',
  timeout: 120000,
  use: {
    baseURL: process.env.PORTAL_URL || 'https://www.sv-netzwerk.eu',
    screenshot: 'on',
    trace: 'on-first-retry',
  },
  reporter: [['html', { open: 'never' }], ['list']],
  outputDir: '../analysis_export/screenshots',
});
