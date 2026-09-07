import { defineConfig, devices } from '@playwright/test';
export default defineConfig({
  testDir: 'tests/browser', timeout: 30_000, fullyParallel: false, workers: 2, retries: 0,
  reporter: [['list'], ['json', { outputFile: 'test-results/browser.json' }], ['html', { open: 'never' }]],
  use: { baseURL: 'http://127.0.0.1:8099', trace: 'retain-on-failure', screenshot: 'only-on-failure' },
  projects: [{ name: 'chromium', use: { ...devices['Desktop Chrome'] } }, { name: 'firefox', use: { ...devices['Desktop Firefox'] } }],
  webServer: { command: 'go run ./tests/fixture', url: 'http://127.0.0.1:8099/healthz', timeout: 120_000, reuseExistingServer: false },
});
