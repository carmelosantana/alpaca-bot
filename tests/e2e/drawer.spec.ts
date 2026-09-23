import { expect, test, type Page } from '@playwright/test';

/**
 * The admin-wide drawer (Admin\Drawer, resources/ts/drawer.ts) in a browser, against the wp-env
 * development site and its fake provider (tests/e2e/chat.spec.ts says what that is and why its
 * reply text is the assertion).
 *
 * What this pins that no unit test can:
 *
 * - Before the launcher is pressed, a screen carries none of the chat: no chat bundle, no htmx, no
 *   chat stylesheet, no form. One press mounts it, and a whole turn runs inside it to its receipt.
 * - The next screen reopens the drawer on the same conversation, which is the user meta going out
 *   through POST /view/drawer and coming back as the drawer's data attributes.
 * - The image button is hidden where the screen has not loaded the media library, and stays
 *   hidden through the two things that un-hide it (composer.ts setImage(form, ''), called when a
 *   turn is sent and when an image is removed), while a screen that has loaded the library, and
 *   the chat screen itself, keep it.
 * - "New chat" inside the drawer swaps a fresh transcript in rather than leaving the page, and
 *   closing is remembered.
 *
 * Each test puts the drawer's state where it needs it through the route rather than relying on
 * what an earlier test or run left behind.
 */
const REPLY = 'Hello from the Alpaca Bot end-to-end fake provider.';
const ADMIN_USER = process.env.WP_ADMIN_USER ?? 'admin';
const ADMIN_PASSWORD = process.env.WP_ADMIN_PASSWORD ?? 'password';

type DrawerGlobals = { alpacaBot: { nonce: string }; alpacaBotMount: { prefs: string } };

async function login(page: Page): Promise<void> {
  await page.goto('/wp-login.php');
  await page.fill('#user_login', ADMIN_USER);
  await page.fill('#user_pass', ADMIN_PASSWORD);
  await Promise.all([page.waitForURL(/wp-admin/), page.click('#wp-submit')]);
}

/** Stores the drawer's state through its own route, from an admin screen that carries the loader, and reloads. */
async function drawerState(page: Page, state: { open?: boolean; conversation_id?: number }): Promise<void> {
  await page.goto('/wp-admin/index.php');
  const status = await page.evaluate(async (body) => {
    const wp = window as unknown as DrawerGlobals;
    const res = await fetch(wp.alpacaBotMount.prefs, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'X-WP-Nonce': wp.alpacaBot.nonce, 'Content-Type': 'application/json' },
      body: JSON.stringify(body),
    });
    return res.status;
  }, state);
  expect(status).toBe(200);
  await page.reload();
}

/**
 * A turn in the drawer run to its end: the reply streamed, the finished bubble with its receipt
 * swapped in, and the composer free again. The reply text alone is not the end: it streams in
 * before the `done` frame, and the bundle takes no new turn until send() has finished with this one.
 */
async function turnDone(drawer: ReturnType<Page['locator']>): Promise<void> {
  const assistant = drawer.locator('article.ab-msg--assistant').last();
  await expect(assistant.locator('.ab-msg__content')).toContainText(REPLY, { timeout: 30_000 });
  await expect(assistant.locator('footer.ab-receipt')).toContainText('18 tokens');
  await expect(drawer.locator('#ab-form [data-action="send"]')).toBeEnabled();
}

/** Whether core's media library is on this page: what drawer.ts reads to keep or hide the image button. */
const media = (page: Page): Promise<string> => page.evaluate(() => typeof (window as unknown as { wp?: { media?: unknown } }).wp?.media);

test('the drawer loads the chat on first open, runs a turn in it with no image button, and reopens on the next screen on the same conversation', async ({ page }) => {
  await login(page);
  await drawerState(page, { open: false, conversation_id: 0 });

  // Closed, and nothing of the chat on the page.
  await expect(page.locator('#ab-drawer-launcher')).toBeVisible();
  await expect(page.locator('#ab-drawer')).toBeHidden();
  await expect(page.locator('#ab-form')).toHaveCount(0);
  await expect(page.locator('script[src*="assets/js/chat.js"]')).toHaveCount(0);
  await expect(page.locator('script[src*="htmx.min.js"]')).toHaveCount(0);
  await expect(page.locator('link[href*="assets/css/alpaca-bot.css"]')).toHaveCount(0);
  // The dashboard does not load the media library, which is what the image button needs.
  expect(await media(page)).toBe('undefined');

  const drawer = page.locator('#ab-drawer');
  const saved = page.waitForResponse((res) => /view(\/|%2F)drawer/.test(res.url()) && (res.request().postData() ?? '').includes('conversation_id'));
  await page.click('#ab-drawer-launcher');
  await expect(drawer.locator('#ab-form')).toBeVisible();
  await expect(page.locator('#ab-drawer-launcher')).toHaveAttribute('aria-expanded', 'true');
  await expect(page.locator('script[src*="assets/js/chat.js"]')).toHaveCount(1);
  await expect(page.locator('script[src*="htmx.min.js"]')).toHaveCount(1);
  await expect(page.locator('link[href*="assets/css/alpaca-bot.css"]')).toHaveCount(1);

  const image = drawer.locator('#ab-form [data-action="image"]');
  await expect(image).toHaveCount(1);
  await expect(image).toBeHidden();

  await drawer.locator('#ab-message').fill('hello');
  await drawer.locator('#ab-message').press('Enter');
  await turnDone(drawer);
  await expect(drawer.locator('#ab-status')).toBeEmpty();
  const conversation = await drawer.locator('#ab-form input[name="conversation_id"]').inputValue();
  expect(Number(conversation)).toBeGreaterThan(0);
  await saved;

  // The send ran setImage(form, ''), which sets the button's `hidden` to false; still hidden.
  expect(await image.evaluate((el) => (el as HTMLElement).hidden)).toBe(false);
  await expect(image).toBeHidden();
  // And the other caller, the remove button, clicked as the bundle's own handler takes it.
  await drawer.locator('#ab-form [data-action="image-remove"]').evaluate((el) => (el as HTMLElement).click());
  await expect(image).toBeHidden();

  // The next screen: open again by itself, on the same conversation, without taking the focus.
  await page.goto('/wp-admin/edit.php');
  await expect(page.locator('#ab-drawer')).toHaveAttribute('data-conversation', conversation);
  await expect(page.locator('#ab-drawer #ab-messages')).toContainText(REPLY);
  await expect(page.locator('#ab-drawer #ab-message')).not.toBeFocused();
  await expect(page.locator('#ab-drawer #ab-form [data-action="image"]')).toBeHidden();

  // A screen that loads the media library itself keeps the button.
  await page.goto('/wp-admin/upload.php?mode=grid');
  expect(await media(page)).toBe('function');
  await expect(page.locator('#ab-drawer #ab-form [data-action="image"]')).toBeVisible();

  // Not on the chat screen, where it would be the chat twice over; the screen's own button stays.
  await page.goto('/wp-admin/admin.php?page=alpaca-bot');
  await expect(page.locator('#ab-form')).toBeVisible();
  await expect(page.locator('#ab-drawer-launcher')).toHaveCount(0);
  await expect(page.locator('#ab-form [data-action="image"]')).toBeVisible();

  // Nor in the block editor.
  await page.goto('/wp-admin/post-new.php');
  await expect(page.locator('body.block-editor-page')).toHaveCount(1);
  await expect(page.locator('#ab-drawer-launcher')).toHaveCount(0);
});

test('"New chat" in the drawer starts a fresh transcript in place, from the header link and from the history select, and closing is remembered', async ({ page }) => {
  await login(page);
  await drawerState(page, { open: true, conversation_id: 0 });
  const drawer = page.locator('#ab-drawer');
  await expect(drawer.locator('#ab-form')).toBeVisible();
  await drawer.locator('#ab-message').fill('hello');
  await drawer.locator('#ab-message').press('Enter');
  await turnDone(drawer);
  await expect(drawer.locator('#ab-form input[name="conversation_id"]')).not.toHaveValue('0');
  const url = page.url();

  // The header's link: the page stays, the transcript and the composer start over.
  const reset = page.waitForResponse((res) => /view(\/|%2F)drawer/.test(res.url()) && (res.request().postData() ?? '').includes('"conversation_id":0'));
  await drawer.locator('.page-title-action').click();
  await expect(drawer.locator('#ab-messages article')).toHaveCount(0);
  await expect(drawer.locator('#ab-form input[name="conversation_id"]')).toHaveValue('0');
  expect(page.url()).toBe(url);
  await reset;

  // A second turn, then the history select's own "New chat" option.
  await drawer.locator('#ab-message').fill('hello again');
  await drawer.locator('#ab-message').press('Enter');
  await turnDone(drawer);
  await expect(drawer.locator('#ab-form input[name="conversation_id"]')).not.toHaveValue('0');
  await drawer.locator('#ab-history-select').selectOption({ index: 0 });
  await expect(drawer.locator('#ab-messages article')).toHaveCount(0);
  await expect(drawer.locator('#ab-form input[name="conversation_id"]')).toHaveValue('0');
  expect(page.url()).toBe(url);

  // Closing hides the drawer and is stored: the next screen neither opens it nor loads the chat.
  const closed = page.waitForResponse((res) => /view(\/|%2F)drawer/.test(res.url()) && (res.request().postData() ?? '').includes('"open":false'));
  await drawer.locator('[data-action="drawer-close"]').click();
  await expect(drawer).toBeHidden();
  await expect(page.locator('#ab-drawer-launcher')).toHaveAttribute('aria-expanded', 'false');
  await closed;
  await page.goto('/wp-admin/edit.php');
  await expect(page.locator('#ab-drawer-launcher')).toBeVisible();
  await expect(page.locator('#ab-drawer')).toBeHidden();
  await expect(page.locator('#ab-drawer')).toHaveAttribute('data-conversation', '0');
  await expect(page.locator('script[src*="assets/js/chat.js"]')).toHaveCount(0);
});

test('an iframe screen gets no drawer: core defines IFRAME_REQUEST for it and it is someone else\'s modal', async ({ page }) => {
  // media-upload.php is core's legacy upload modal, loaded into a thickbox iframe, and it defines
  // IFRAME_REQUEST, as plugin-install.php's details modal and update.php's update and activate actions do. Its
  // wp_iframe() fires admin_enqueue_scripts, so without the gate the loader was enqueued here.
  // (It fires no admin_footer, so the launcher's absence here is not the gate's; the loader's is.)
  await login(page);
  await drawerState(page, { open: true, conversation_id: 0 });
  await expect(page.locator('script[src*="assets/js/drawer.js"]')).toHaveCount(1);

  await page.goto('/wp-admin/media-upload.php?type=image');
  await expect(page.locator('#media-upload-header, #media-upload')).not.toHaveCount(0);
  await expect(page.locator('script[src*="assets/js/drawer.js"]')).toHaveCount(0);
  await expect(page.locator('link[href*="alpaca-bot-drawer.css"]')).toHaveCount(0);
  await expect(page.locator('#ab-drawer-launcher')).toHaveCount(0);
  await expect(page.locator('script[src*="assets/js/chat.js"]')).toHaveCount(0);

  await drawerState(page, { open: false, conversation_id: 0 });
});
