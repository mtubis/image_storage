import { defineConfig, devices } from '@playwright/test';

const isCi = process.env.CI !== undefined && process.env.CI !== '';

export default defineConfig({
  testDir: './tests',
  // All tests share one seeded database (make e2e), so they run one after another.
  fullyParallel: false,
  workers: 1,
  forbidOnly: isCi,
  // No retries, in CI either: a retry would run against the data the failed attempt changed,
  // and would hide the flakiness the suite must not have.
  retries: 0,
  reporter: [['list'], ['html', { open: 'never' }]],
  use: {
    // Set by docker-compose.e2e.yml; the default is the same stack seen from the host.
    baseURL: process.env.E2E_BASE_URL ?? 'http://localhost:5174',
    trace: 'retain-on-failure',
  },
  projects: [{ name: 'chromium', use: { ...devices['Desktop Chrome'] } }],
});
