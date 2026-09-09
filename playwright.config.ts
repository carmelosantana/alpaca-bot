import { defineConfig, devices } from '@playwright/test';

/**
 * The end-to-end smoke test: one browser, one WordPress, one real turn through the chat screen.
 *
 * `WP_BASE_URL` is the wp-env *development* site (port 8888, the default below), not the tests
 * site on 8889: the PHPUnit integration suite reinstalls the database behind 8889 on every run,
 * so pointing the browser there would have the two suites overwrite each other.
 *
 * No retries, on purpose. A retry would let an intermittently missing reply pass on the second
 * attempt, which is exactly the failure this test exists to catch; a flake here is a bug report,
 * not something to paper over. `forbidOnly` keeps a stray `test.only` from silently shrinking the
 * suite to nothing in CI.
 */
export default defineConfig({
  testDir: './tests/e2e',
  fullyParallel: false,
  workers: 1,
  retries: 0,
  forbidOnly: !!process.env.CI,
  timeout: 60_000,
  expect: { timeout: 15_000 },
  reporter: process.env.CI ? [['github'], ['list']] : [['list']],
  use: {
    baseURL: process.env.WP_BASE_URL ?? 'http://localhost:8888',
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
  },
  projects: [{ name: 'chromium', use: { ...devices['Desktop Chrome'] } }],
});
