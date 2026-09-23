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
 * - The composer's context chips: the screen's on the posts list, and the post's as well on a
 *   classic editor screen (an attachment's, which core never opens in the block editor), each in
 *   the turn's POST /chat body until it is taken off.
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

/**
 * Waits for the chat bundle to have booted in the drawer. The fragment (and its chips) is in the
 * page before the bundle is, since mount.ts adds chat.js only after the swap, and an Enter pressed
 * before boot() binds its keydown handler is a newline in the box, not a turn.
 *
 * The signal is the form's `novalidate`: View\Chat\Composer does not print it, and boot() sets
 * `form.noValidate`, which reflects to the attribute, in the same synchronous run that binds the
 * form's submit and the box's keydown handlers, so by the time anything else can see it, boot()
 * has finished. The box's focus is not the signal: a drawer that opens itself on a new screen
 * gives the focus back (drawer.ts giveFocusBack()), so the box is not focused when it has booted.
 */
async function booted(drawer: ReturnType<Page['locator']>): Promise<void> {
  await expect(drawer.locator('#ab-form')).toHaveAttribute('novalidate', '');
}

/** The `context` of the next POST /chat the page sends, once the request is made. */
function nextContext(page: Page): Promise<Record<string, unknown>> {
  return page.waitForRequest((req) => req.method() === 'POST' && /\/alpaca-bot\/v1\/chat(?:$|[?&])/.test(decodeURIComponent(req.url())))
    .then((req) => (req.postDataJSON() as { context: Record<string, unknown> }).context);
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
  await booted(drawer);
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
  await booted(drawer);
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

test('on the posts list the drawer\'s composer shows the screen as a chip, whose removal takes it off the next turn', async ({ page }) => {
  await login(page);
  await drawerState(page, { open: false, conversation_id: 0 });
  await page.goto('/wp-admin/edit.php');
  const drawer = page.locator('#ab-drawer');
  const panel = page.waitForRequest((req) => /view(\/|%2F)panel/.test(req.url()));
  await page.click('#ab-drawer-launcher');
  await booted(drawer);
  const query = new URL((await panel).url()).searchParams;
  expect([query.get('screen_id'), query.get('screen_title'), query.get('post_id')]).toEqual(['edit-post', 'Posts', '0']);

  const chip = drawer.locator('#ab-form .ab-chip[data-chip="screen"]');
  await expect(chip.locator('.ab-chip__label')).toHaveText('On: Posts');
  await expect(drawer.locator('#ab-form .ab-chip')).toHaveCount(1);

  // With the chip on, the turn names the screen.
  let sent = nextContext(page);
  await drawer.locator('#ab-message').fill('hello');
  await drawer.locator('#ab-message').press('Enter');
  expect(await sent).toEqual({ screen: { id: 'edit-post', title: 'Posts' } });
  await turnDone(drawer);

  // Taken off before sending: gone from the composer, and from the body.
  await chip.locator('[data-action="chip-remove"]').click();
  await expect(drawer.locator('#ab-form .ab-chip')).toHaveCount(0);
  await expect(drawer.locator('#ab-message')).toBeFocused();
  sent = nextContext(page);
  await drawer.locator('#ab-message').fill('hello again');
  await drawer.locator('#ab-message').press('Enter');
  expect(await sent).toEqual({});
  await turnDone(drawer);

  await drawerState(page, { open: false, conversation_id: 0 });
});

test('on a classic editor screen the drawer\'s composer names the post as well, and the turn carries both', async ({ page }) => {
  await login(page);
  await drawerState(page, { open: false, conversation_id: 0 });
  // An attachment to edit, found by its title or uploaded once: a 1x1 PNG.
  const id = await page.evaluate(async () => {
    const wp = window as unknown as { alpacaBot: { nonce: string; rest: string } };
    const media = wp.alpacaBot.rest.replace('alpaca-bot/v1', 'wp/v2/media');
    const headers = { 'X-WP-Nonce': wp.alpacaBot.nonce };
    const found = await (await fetch(`${media}${media.includes('?') ? '&' : '?'}search=ab-e2e-classic`, { credentials: 'same-origin', headers })).json() as { id: number }[];
    if (found.length > 0) return found[0]!.id;
    const png = Uint8Array.from(atob('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII='), (c) => c.charCodeAt(0));
    const res = await fetch(media, { method: 'POST', credentials: 'same-origin', headers: { ...headers, 'Content-Type': 'image/png', 'Content-Disposition': 'attachment; filename="ab-e2e-classic.png"' }, body: png });
    return (await res.json() as { id: number }).id;
  });
  expect(id).toBeGreaterThan(0);

  await page.goto(`/wp-admin/post.php?post=${id}&action=edit`);
  await expect(page.locator('body.block-editor-page')).toHaveCount(0);
  const drawer = page.locator('#ab-drawer');
  await expect(drawer).toHaveAttribute('data-post', String(id));
  await page.click('#ab-drawer-launcher');
  await booted(drawer);
  await expect(drawer.locator('#ab-form .ab-chip[data-chip="post"] .ab-chip__label')).toHaveText('Editing: ab-e2e-classic');
  await expect(drawer.locator('#ab-form .ab-chip[data-chip="screen"] .ab-chip__label')).toHaveText('On: Edit Media');

  const sent = nextContext(page);
  await drawer.locator('#ab-message').fill('hello');
  await drawer.locator('#ab-message').press('Enter');
  expect(await sent).toEqual({ post_id: id, screen: { id: 'attachment', title: 'Edit Media' } });
  await turnDone(drawer);

  await drawerState(page, { open: false, conversation_id: 0 });
});
