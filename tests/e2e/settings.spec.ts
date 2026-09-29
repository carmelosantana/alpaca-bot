import { expect, test, type Locator, type Page } from '@playwright/test';

/**
 * A refused Discover says so in the server's approvals cell (M-3, Task 26 review). htmx swaps no
 * 4xx response, so without the Discover button's response-error handler (Admin\SettingsPage) a
 * 403 or a 429 left the button doing nothing visible.
 *
 * Against the wp-env development site, as chat.spec.ts is, because the settings screen is a real
 * WordPress admin page with core's htmx enqueue and REST nonce; composer.spec.ts's fixture page
 * covers only the chat bundle. Run it on its own: `pnpm exec playwright test tests/e2e/settings.spec.ts`.
 *
 * No MCP server is contacted. The server this test saves has a public IP literal for its address,
 * so saving it looks nothing up. The 403 is core's own answer to a nonce it does not recognise,
 * given before the route's callback runs; the 429 is answered by page.route(), so that request
 * never leaves the browser. The server is removed again at the end.
 */

const ADMIN_USER = process.env.WP_ADMIN_USER ?? 'admin';
const ADMIN_PASSWORD = process.env.WP_ADMIN_PASSWORD ?? 'password';
const TOOLS = '/wp-admin/admin.php?page=alpaca-bot-settings&tab=toolkits';
const PREFIX = 'efour';
const REFUSED = "Discover tools got no list back: this page's session may have expired. Reload the page and try again.";
const BUSY = 'Too many requests for now. Wait a minute, then press Discover tools again.';

const isDiscover = (url: URL): boolean => decodeURIComponent(url.toString()).includes('/view/mcp-tools/');

async function login(page: Page): Promise<void> {
  await page.goto('/wp-login.php');
  await page.fill('#user_login', ADMIN_USER);
  await page.fill('#user_pass', ADMIN_PASSWORD);
  await Promise.all([page.waitForURL(/wp-admin/), page.click('#wp-submit')]);
}

/** The Tools tab's row for the server this test saved. */
function row(page: Page): Locator {
  return page.locator('#ab-mcp-servers tbody tr').filter({ has: page.locator(`input[name$="[prefix]"][value="${PREFIX}"]`) });
}

async function save(page: Page): Promise<void> {
  await Promise.all([page.waitForURL(/settings-updated=true/), page.click('#submit')]);
}

test.afterEach(async ({ page }) => {
  await page.unroute(isDiscover);
  await page.goto(TOOLS);
  if (await row(page).count() > 0) {
    await row(page).locator('input[name$="[remove]"]').check();
    await save(page);
  }
  await expect(row(page)).toHaveCount(0);
});

test('a Discover refused as forbidden, then as rate limited, says so in the approvals cell and keeps what the cell held', async ({ page }) => {
  await login(page);
  await page.goto(TOOLS);
  const blank = page.locator('#ab-mcp-servers tbody tr').last();
  await blank.locator('input[name$="[prefix]"]').fill(PREFIX);
  await blank.locator('input[name$="[url]"]').fill('https://93.184.216.34/mcp');
  await save(page);

  const cell = row(page).locator('div[id^="ab-mcp-tools-"]');
  const discover = row(page).getByRole('button', { name: 'Discover tools' });
  await expect(cell).toContainText('0 tools approved.');

  // A nonce core does not recognise: its cookie check refuses the request with a 403.
  await page.locator('#ab-mcp-servers').evaluate((el) => el.setAttribute('hx-headers', JSON.stringify({ 'X-WP-Nonce': 'not-a-nonce' })));
  const forbidden = page.waitForResponse((res) => isDiscover(new URL(res.url())));
  await discover.click();
  expect((await forbidden).status()).toBe(403);
  const notice = cell.locator('.ab-mcp-refused');
  await expect(notice).toHaveText(REFUSED);
  await expect(notice).toHaveClass(/notice-error/);
  await expect(cell).toContainText('0 tools approved.');

  // The rate limit, answered here rather than spent: one notice, now the other sentence.
  await page.route(isDiscover, (route) => route.fulfill({ status: 429, contentType: 'application/json', headers: { 'Retry-After': '30' }, body: '{"code":"alpaca_bot_rate_limited","message":"Too many requests. Try again shortly.","data":{"status":429,"retry_after":30}}' }));
  await discover.click();
  await expect(notice).toHaveText(BUSY);
  await expect(notice).toHaveClass(/notice-warning/);
  await expect(cell.locator('.ab-mcp-refused')).toHaveCount(1);
  await expect(cell).toContainText('0 tools approved.');
});
