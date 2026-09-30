import { execFileSync } from 'node:child_process';
import { expect, test, type Locator, type Page, type Request } from '@playwright/test';

/**
 * The chat in the block editor (Admin\Drawer::enqueueEditor(), resources/ts/editor-start.ts): a
 * PluginSidebar mounting the fragment the drawer mounts, against the wp-env development site and
 * its fake provider (tests/e2e/chat.spec.ts says what that is and why its reply text is the
 * assertion).
 *
 * What it pins that no unit test can:
 *
 * - The editor has the sidebar and not the footer drawer, and none of the chat until the sidebar
 *   is opened. Opened on a saved post, the composer names that post and a whole turn carries it.
 * - The image button stays, because the block editor loads the media library, and it opens it.
 * - Closing and reopening the sidebar keeps the chat, a turn that finishes while it is closed
 *   included: a PluginSidebar unmounts its children when it closes, and the chat must not go with
 *   them. Reopening fetches no fragment and adds no second chat bundle.
 * - "New chat" starts over in the sidebar and does not leave the editor.
 * - The sidebar and the drawer share one memory of the conversation last shown (Kanboard #4527):
 *   the sidebar reopens the conversation it last showed, the drawer opens on it, and a New chat
 *   in the sidebar leaves both on none. So every test starts from none (login()).
 * - On a new post the composer names no post until the post is first saved or autosaved, and
 *   then names it without a reload or a remount: the first turn carries no `post_id`, a turn after
 *   the save carries the post's. A post chip taken off stays off through the next save, and
 *   "New chat" puts it back, even when its fragment was rendered before the save.
 */
const REPLY = 'Hello from the Alpaca Bot end-to-end fake provider.';
const ADMIN_USER = process.env.WP_ADMIN_USER ?? 'admin';
const ADMIN_PASSWORD = process.env.WP_ADMIN_PASSWORD ?? 'password';

type EditorGlobals = {
  wp: {
    data: {
      dispatch(store: string): Record<string, (...args: unknown[]) => unknown>;
      select(store: string): Record<string, (...args: unknown[]) => unknown>;
    };
    media?: unknown;
  };
  alpacaBot: { nonce: string; rest: string };
  alpacaBotMount: { prefs: string };
};

/** The start of the title this spec gives each post it saves. */
const TITLE = 'ab-e2e-editor-';

/**
 * The ids of what this spec makes on the development site, for the afterAll hook to delete and
 * nothing else: the draft draft() creates, the post each editor it opens is on (an Add New
 * screen's auto-draft, saved or not), and each turn's conversation (chat_history) and usage
 * receipt (chat_log), which track() reads off the turn's stream. Kept per worker, and a failing
 * test ends its worker, which runs the hook for what that worker made.
 */
const made = new Set<number>();

/**
 * Collects the conversation and the receipt of every turn the page streams: the `start` frame
 * names the conversation and the `done` frame its receipt's `log_id` (docs/api.md, section 4).
 * The stream is read where the page reads it, through a tee of the body its fetch() returns,
 * because the browser keeps no copy of a streamed body for Playwright to ask for afterwards
 * (Network.getResponseBody answers "No data found for resource with given identifier"); the chat
 * reads the other branch as it streams. A turn cut off before `done` leaves its receipt unnamed.
 */
async function track(page: Page): Promise<void> {
  await page.exposeFunction('abE2eMade', (id: number) => { made.add(id); });
  await page.addInitScript(() => {
    const report = (window as unknown as { abE2eMade: (id: number) => void }).abE2eMade;
    const real = window.fetch.bind(window);
    window.fetch = async (input: RequestInfo | URL, init?: RequestInit): Promise<Response> => {
      const res = await real(input, init);
      const url = decodeURIComponent(input instanceof Request ? input.url : String(input));
      if (!/\/chat\/\d+\/stream(?:$|[?&])/.test(url) || !res.body) return res;
      const [page, spec] = res.body.tee();
      void new Response(spec).text().then((body) => {
        for (const line of body.split('\n')) {
          if (!line.startsWith('data: ')) continue;
          try {
            const frame = JSON.parse(line.slice(6)) as { conversation_id?: unknown; receipt?: { log_id?: unknown } };
            for (const id of [frame.conversation_id, frame.receipt?.log_id]) if (typeof id === 'number' && id > 0) void report(id);
          } catch { /* not a JSON frame */ }
        }
      });
      return new Response(page, { status: res.status, statusText: res.statusText, headers: res.headers });
    };
  });
}

/**
 * Logs in, and leaves the drawer closed and on no conversation, which the sidebar would otherwise
 * reopen from whatever spec ran before (Kanboard #4527): stored through POST /view/drawer from the
 * dashboard, which carries the drawer's settings.
 */
async function login(page: Page): Promise<void> {
  await page.goto('/wp-login.php');
  await page.fill('#user_login', ADMIN_USER);
  await page.fill('#user_pass', ADMIN_PASSWORD);
  await Promise.all([page.waitForURL(/wp-admin/), page.click('#wp-submit')]);
  await page.goto('/wp-admin/index.php');
  const status = await page.evaluate(async () => {
    const wp = window as unknown as EditorGlobals;
    const res = await fetch(wp.alpacaBotMount.prefs, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'X-WP-Nonce': wp.alpacaBot.nonce, 'Content-Type': 'application/json' },
      body: JSON.stringify({ open: false, conversation_id: 0 }),
    });
    return res.status;
  });
  expect(status).toBe(200);
}

/** A new draft post with this title, made through the REST API from a screen that carries the chat's settings. */
async function draft(page: Page, title: string): Promise<number> {
  await page.goto('/wp-admin/index.php');
  const id = await page.evaluate(async (title) => {
    const wp = window as unknown as EditorGlobals;
    const posts = wp.alpacaBot.rest.replace('alpaca-bot/v1', 'wp/v2/posts');
    const headers = { 'X-WP-Nonce': wp.alpacaBot.nonce, 'Content-Type': 'application/json' };
    const sep = posts.includes('?') ? '&' : '?';
    const res = await fetch(posts, { method: 'POST', credentials: 'same-origin', headers, body: JSON.stringify({ title, status: 'draft', content: 'Draft body.' }) });
    return (await res.json() as { id: number }).id;
  }, title);
  expect(id).toBeGreaterThan(0);
  made.add(id);
  return id;
}

/** Opens the block editor at `path`, once core/editor has its post, with the first-visit welcome guide put away. */
async function openEditor(page: Page, path: string): Promise<Locator> {
  await page.goto(path);
  await expect(page.locator('body.block-editor-page')).toHaveCount(1);
  await page.waitForFunction(() => {
    const wp = (window as unknown as Partial<EditorGlobals>).wp;
    return typeof wp?.data?.select('core/editor').getCurrentPostId() === 'number';
  });
  made.add(await postId(page));
  await page.evaluate(() => {
    (window as unknown as EditorGlobals).wp.data.dispatch('core/preferences').set!('core/edit-post', 'welcomeGuide', false);
  });
  // The sidebar's pinned toggle, in the editor's top bar.
  const toggle = page.getByRole('region', { name: 'Editor top bar' }).getByRole('button', { name: 'Alpaca Bot', exact: true });
  await expect(toggle).toBeVisible();
  return toggle;
}

const postId = (page: Page): Promise<number> => page.evaluate(() => (window as unknown as EditorGlobals).wp.data.select('core/editor').getCurrentPostId() as number);
const postStatus = (page: Page): Promise<string> => page.evaluate(() => (window as unknown as EditorGlobals).wp.data.select('core/editor').getCurrentPostAttribute('status') as string);

/** Waits for the chat bundle to have booted in the sidebar: tests/e2e/drawer.spec.ts booted() says why the form's `novalidate` is the signal. */
async function booted(sidebar: Locator): Promise<void> {
  await expect(sidebar.locator('#ab-form')).toHaveAttribute('novalidate', '');
}

/** A turn in the sidebar run to its end: the reply, its receipt, and the composer free again. */
async function turnDone(sidebar: Locator): Promise<void> {
  const assistant = sidebar.locator('article.ab-msg--assistant').last();
  await expect(assistant.locator('.ab-msg__content')).toContainText(REPLY, { timeout: 30_000 });
  await expect(assistant.locator('footer.ab-receipt')).toContainText('18 tokens');
  await expect(sidebar.locator('#ab-form [data-action="send"]')).toBeEnabled();
}

const isChat = (req: Request): boolean => req.method() === 'POST' && /\/alpaca-bot\/v1\/chat(?:$|[?&])/.test(decodeURIComponent(req.url()));

/** The `context` of the next POST /chat the page sends. */
function nextContext(page: Page): Promise<Record<string, unknown>> {
  return page.waitForRequest(isChat).then((req) => (req.postDataJSON() as { context: Record<string, unknown> }).context);
}

/** Counts the page's requests for GET /view/panel from now on. */
function panelRequests(page: Page): { count: number } {
  const seen = { count: 0 };
  page.on('request', (req) => { if (/view(\/|%2F)panel/.test(req.url())) seen.count++; });
  return seen;
}

/** Gives the post this title and saves it, resolving once the editor has finished the save. */
async function save(page: Page, title: string): Promise<void> {
  await page.evaluate(async (title) => {
    const editor = (window as unknown as EditorGlobals).wp.data.dispatch('core/editor');
    await editor.editPost!({ title });
    await editor.savePost!();
  }, title);
}

async function send(sidebar: Locator, text: string): Promise<void> {
  await sidebar.locator('#ab-message').fill(text);
  await sidebar.locator('#ab-message').press('Enter');
}

test.beforeEach(async ({ page }) => { await track(page); });

/**
 * Deletes, for good, exactly the posts in `made`, with wp-cli in wp-env's development container,
 * since a usage receipt has no REST route to delete it by; `wp post delete --force` takes a post's
 * revisions with it. Then checks that none of them is left.
 */
test.afterAll(async () => {
  const ids = [...made].map(String);
  if (ids.length === 0) return;
  const wpCli = (...args: string[]): string => execFileSync('pnpm', ['exec', 'wp-env', 'run', 'cli', 'wp', ...args], { encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] });
  wpCli('post', 'delete', ...ids, '--force');
  const left = wpCli('eval', `echo 'left:' . count(array_filter(array_map('get_post', [${ids.join(',')}])));`);
  expect(left).toContain('left:0');
});

test('the block editor has the chat as its own sidebar, on the post being edited, with the image button and no footer drawer, and keeps it through a close', async ({ page }) => {
  await login(page);
  const id = await draft(page, `${TITLE}draft`);
  const panels = panelRequests(page);
  const toggle = await openEditor(page, `/wp-admin/post.php?post=${id}&action=edit`);

  // No drawer here, and none of the chat until the sidebar is opened.
  await expect(page.locator('#ab-drawer-launcher')).toHaveCount(0);
  await expect(page.locator('script[src*="assets/js/drawer.js"]')).toHaveCount(0);
  await expect(page.locator('script[src*="assets/js/editor.js"]')).toHaveCount(1);
  await expect(page.locator('script[src*="assets/js/chat.js"]')).toHaveCount(0);
  await expect(page.locator('#ab-form')).toHaveCount(0);
  expect(panels.count).toBe(0);

  await toggle.click();
  const sidebar = page.locator('.ab-sidebar');
  await expect(sidebar.locator('#ab-form')).toBeVisible();
  await booted(sidebar);
  await expect(page.locator('script[src*="assets/js/chat.js"]')).toHaveCount(1);
  await expect(sidebar.locator('#ab-form .ab-chip[data-chip="post"] .ab-chip__label')).toHaveText(`Editing: ${TITLE}draft`);
  await expect(sidebar.locator('#ab-form input[name="context[post_id]"]')).toHaveValue(String(id));
  // The editor names no screen: the post is its context.
  await expect(sidebar.locator('#ab-form .ab-chip')).toHaveCount(1);
  // The sidebar has its own close button, so the drawer's is not shown; and the chat fills the
  // sidebar under its header, the composer at the foot rather than under the transcript's end.
  await expect(sidebar.locator('[data-action="drawer-close"]')).toBeHidden();
  const gap = await page.evaluate(() => {
    const area = document.querySelector('.ab-sidebar')!.closest('.interface-complementary-area')!.getBoundingClientRect();
    return Math.round(area.bottom - document.querySelector('.ab-sidebar #ab-form')!.getBoundingClientRect().bottom);
  });
  expect(gap).toBeLessThan(40);

  // The block editor loads the media library, so the image button stays, and it opens the library.
  expect(await page.evaluate(() => typeof (window as unknown as EditorGlobals).wp.media)).toBe('function');
  const image = sidebar.locator('#ab-form [data-action="image"]');
  await expect(image).toBeVisible();
  await image.click();
  await expect(page.locator('.media-modal')).toBeVisible();
  await page.keyboard.press('Escape');
  await expect(page.locator('.media-modal')).toBeHidden();

  const sent = nextContext(page);
  await send(sidebar, 'hello');
  expect(await sent).toEqual({ post_id: id });
  await turnDone(sidebar);
  expect(Number(await sidebar.locator('#ab-form input[name="conversation_id"]').inputValue())).toBeGreaterThan(0);

  expect(panels.count).toBe(1);

  // "New chat" starts over here, and the editor stays.
  const url = page.url();
  await sidebar.locator('.page-title-action').click();
  await expect(sidebar.locator('#ab-messages article')).toHaveCount(0);
  await expect(sidebar.locator('#ab-form input[name="conversation_id"]')).toHaveValue('0');
  await expect(sidebar.locator('#ab-form .ab-chip[data-chip="post"]')).toHaveCount(1);
  expect(page.url()).toBe(url);

  // A turn that runs while the sidebar is closed: POST /chat is held until the close, so the
  // streaming bubble, the finished reply and the history's refresh (the new conversation listed)
  // all land in a closed sidebar.
  const route = (u: URL): boolean => /\/alpaca-bot\/v1\/chat(?:$|[?&])/.test(decodeURIComponent(u.toString()));
  let release: () => void = () => {};
  const closed = new Promise<void>((resolve) => { release = resolve; });
  await page.route(route, async (r) => {
    if (r.request().method() === 'POST') await closed;
    await r.continue();
  });
  const held = page.waitForRequest(isChat);
  await send(sidebar, 'hello again');
  await held;
  await toggle.click();
  await expect(page.locator('.ab-sidebar #ab-form')).toBeHidden();
  const refreshed = page.waitForResponse((res) => /view(\/|%2F)history/.test(res.url()));
  release();
  await refreshed;
  await page.unroute(route);

  await toggle.click();
  await expect(sidebar.locator('#ab-form')).toBeVisible();
  await expect(sidebar.locator('article.ab-msg--user')).toHaveCount(1);
  await turnDone(sidebar);
  const conversation = await sidebar.locator('#ab-form input[name="conversation_id"]').inputValue();
  expect(Number(conversation)).toBeGreaterThan(0);
  await expect(sidebar.locator(`#ab-history-select option[data-id="${conversation}"]`)).toHaveCount(1);
  // Nothing of the chat went anywhere but the sidebar.
  await expect(page.locator('.ab-msg')).toHaveCount(2);
  // No fragment but New chat's, and one chat bundle for the page, whatever the sidebar did.
  expect(panels.count).toBe(2);
  await expect(page.locator('script[src*="assets/js/chat.js"]')).toHaveCount(1);

  // The history select's own "New chat" starts over here too.
  await sidebar.locator('#ab-history-select').selectOption({ index: 0 });
  await expect(sidebar.locator('#ab-messages article')).toHaveCount(0);
  await expect(sidebar.locator('#ab-form input[name="conversation_id"]')).toHaveValue('0');
  expect(page.url()).toBe(url);
});

test('on a new post the sidebar names no post until the post is saved, and then names it in the chat it has', async ({ page }) => {
  await login(page);
  const panels = panelRequests(page);
  const toggle = await openEditor(page, '/wp-admin/post-new.php');
  expect(await postStatus(page)).toBe('auto-draft');
  await toggle.click();
  const sidebar = page.locator('.ab-sidebar');
  await booted(sidebar);

  // The auto-draft is no post yet: no chip, and the turn names none.
  await expect(sidebar.locator('#ab-form .ab-chip')).toHaveCount(0);
  let sent = nextContext(page);
  await send(sidebar, 'hello');
  expect(await sent).toEqual({});
  await turnDone(sidebar);
  // Marks the chat as it is, to tell it apart from a remounted one.
  await sidebar.locator('#ab-form').evaluate((form) => { (form as HTMLFormElement & { abMark?: number }).abMark = 1; });

  // Saved: the chip comes, without a reload and without a remount.
  await save(page, `${TITLE}new`);
  expect(await postStatus(page)).toBe('draft');
  const id = await postId(page);
  await expect(sidebar.locator('#ab-form .ab-chip[data-chip="post"] .ab-chip__label')).toHaveText(`Editing: ${TITLE}new`);
  await expect(sidebar.locator('#ab-form input[name="context[post_id]"]')).toHaveValue(String(id));
  expect(await sidebar.locator('#ab-form').evaluate((form) => (form as HTMLFormElement & { abMark?: number }).abMark)).toBe(1);
  await expect(sidebar.locator('article.ab-msg--assistant')).toHaveCount(1);
  await expect(page.locator('script[src*="assets/js/chat.js"]')).toHaveCount(1);
  expect(panels.count).toBe(2);

  sent = nextContext(page);
  await send(sidebar, 'hello again');
  expect(await sent).toEqual({ post_id: id });
  await turnDone(sidebar);

  // Taken off, the chip stays off through the next save: the sidebar asks for a chip only until
  // one has come, so the post is not put back on the turn behind the user's back.
  await sidebar.locator('#ab-form .ab-chip[data-chip="post"] [data-action="chip-remove"]').click();
  await expect(sidebar.locator('#ab-form .ab-chip')).toHaveCount(0);
  await save(page, `${TITLE}new again`);
  // Time for a fetch the save should not start to be made, since what is asserted is its absence.
  await page.waitForTimeout(1000);
  await expect(sidebar.locator('#ab-form .ab-chip')).toHaveCount(0);
  expect(panels.count).toBe(2);
  sent = nextContext(page);
  await send(sidebar, 'hello once more');
  expect(await sent).toEqual({});
  await turnDone(sidebar);

  // New chat puts it back, as the server renders it for the saved post.
  await sidebar.locator('.page-title-action').click();
  await expect(sidebar.locator('#ab-messages article')).toHaveCount(0);
  await expect(sidebar.locator('#ab-form .ab-chip[data-chip="post"] .ab-chip__label')).toHaveText(`Editing: ${TITLE}new again`);
  await expect(sidebar.locator('#ab-form input[name="context[post_id]"]')).toHaveValue(String(id));
  expect(panels.count).toBe(3);
});

test('a New chat whose fragment was rendered before the first save, and arrives after it, still leaves the post named', async ({ page }) => {
  await login(page);
  const panels = panelRequests(page);
  const toggle = await openEditor(page, '/wp-admin/post-new.php');
  await toggle.click();
  const sidebar = page.locator('.ab-sidebar');
  await booted(sidebar);
  expect(panels.count).toBe(1);

  // New chat's fragment is rendered while the post is an auto-draft, so it has no post chip, and
  // is held from the page until the save has brought the chip; later requests go straight through.
  const isPanel = (u: URL): boolean => /view(\/|%2F)panel/.test(u.toString());
  let release: () => void = () => {};
  const saved = new Promise<void>((resolve) => { release = resolve; });
  let rendered: () => void = () => {};
  const fragment = new Promise<void>((resolve) => { rendered = resolve; });
  let held = false;
  await page.route(isPanel, async (r) => {
    if (held) { await r.continue(); return; }
    held = true;
    const response = await r.fetch();
    rendered();
    await saved;
    await r.fulfill({ response });
  });
  await sidebar.locator('.page-title-action').click();
  await fragment;
  await save(page, `${TITLE}raced`);
  await expect(sidebar.locator('#ab-form .ab-chip[data-chip="post"]')).toHaveCount(1);
  expect(panels.count).toBe(3);
  release();

  // The held fragment's chips, none, replace the chip the save brought, and the sidebar asks again.
  await expect.poll(() => panels.count).toBe(4);
  await page.unroute(isPanel);
  await expect(sidebar.locator('#ab-form .ab-chip[data-chip="post"] .ab-chip__label')).toHaveText(`Editing: ${TITLE}raced`);
  await expect(sidebar.locator('#ab-form input[name="context[post_id]"]')).toHaveValue(String(await postId(page)));
  await expect(sidebar.locator('#ab-form .ab-chip')).toHaveCount(1);
});

test('on a new post an autosave is enough for the sidebar to name it', async ({ page }) => {
  await login(page);
  const toggle = await openEditor(page, '/wp-admin/post-new.php');
  await toggle.click();
  const sidebar = page.locator('.ab-sidebar');
  await booted(sidebar);
  await expect(sidebar.locator('#ab-form .ab-chip')).toHaveCount(0);

  await page.evaluate(async (title) => {
    const editor = (window as unknown as EditorGlobals).wp.data.dispatch('core/editor');
    await editor.editPost!({ title });
    await editor.autosave!();
  }, `${TITLE}autosaved`);
  expect(await postStatus(page)).toBe('draft');
  const id = await postId(page);
  await expect(sidebar.locator('#ab-form .ab-chip[data-chip="post"] .ab-chip__label')).toHaveText(`Editing: ${TITLE}autosaved`);
  await expect(sidebar.locator('#ab-form input[name="context[post_id]"]')).toHaveValue(String(id));
});

test('two saves that finish while the post chip is still being fetched ask for it once, and add it once', async ({ page }) => {
  await login(page);
  const panels = panelRequests(page);
  const toggle = await openEditor(page, '/wp-admin/post-new.php');
  await toggle.click();
  const sidebar = page.locator('.ab-sidebar');
  await booted(sidebar);
  expect(panels.count).toBe(1);

  // Every later GET /view/panel is held until both saves have finished.
  const isPanel = (u: URL): boolean => /view(\/|%2F)panel/.test(u.toString());
  let release: () => void = () => {};
  const saved = new Promise<void>((resolve) => { release = resolve; });
  await page.route(isPanel, async (r) => { await saved; await r.continue(); });
  await save(page, `${TITLE}twice`);
  await save(page, `${TITLE}twice again`);
  // Time for a second fetch the second save should not start, since what is asserted is its absence.
  await page.waitForTimeout(1000);
  expect(panels.count).toBe(2);
  release();

  await expect(sidebar.locator('#ab-form .ab-chip[data-chip="post"] .ab-chip__label')).toHaveText(`Editing: ${TITLE}twice again`);
  await page.unroute(isPanel);
  await expect(sidebar.locator('#ab-form .ab-chip')).toHaveCount(1);
});

test('the sidebar reopens the conversation it last showed, the drawer opens on it, and a New chat in the sidebar leaves both on none (Kanboard #4527)', async ({ page }) => {
  await login(page);
  const id = await draft(page, `${TITLE}remembered`);
  const path = `/wp-admin/post.php?post=${id}&action=edit`;
  const sidebar = page.locator('.ab-sidebar');
  const stored = (): Promise<Request> => page.waitForRequest((req) => req.method() === 'POST' && /view(\/|%2F)drawer/.test(req.url()));
  /** Opens the sidebar, unless the editor opened it itself, and waits for the chat. */
  const open = async (): Promise<void> => {
    const toggle = await openEditor(page, path);
    if ((await toggle.getAttribute('aria-pressed')) !== 'true') await toggle.click();
    await booted(sidebar);
  };

  await open();
  await expect(sidebar.locator('#ab-form input[name="conversation_id"]')).toHaveValue('0');
  let saved = stored();
  await send(sidebar, 'hello');
  await turnDone(sidebar);
  const conversation = await sidebar.locator('#ab-form input[name="conversation_id"]').inputValue();
  expect(Number(conversation)).toBeGreaterThan(0);
  // The conversation, and not the drawer's open flag. Stored before the page is left: the POST is
  // fire and forget, and a navigation could cancel it.
  let request = await saved;
  expect(request.postDataJSON()).toEqual({ conversation_id: Number(conversation) });
  expect((await request.response())?.status()).toBe(200);

  // Back in the editor, the sidebar opens on it, with its turn.
  await open();
  await expect(sidebar.locator('#ab-form input[name="conversation_id"]')).toHaveValue(conversation);
  await expect(sidebar.locator('article.ab-msg--assistant')).toHaveCount(1);
  await expect(sidebar.locator('#ab-form .ab-chip[data-chip="post"] .ab-chip__label')).toHaveText(`Editing: ${TITLE}remembered`);

  // On another screen, the drawer holds it too, still closed.
  await page.goto('/wp-admin/index.php');
  await expect(page.locator('#ab-drawer')).toHaveAttribute('data-conversation', conversation);
  await expect(page.locator('#ab-drawer')).toHaveAttribute('data-open', '0');

  // A New chat in the sidebar is remembered as none, for both.
  await open();
  saved = stored();
  await sidebar.locator('.page-title-action').click();
  await expect(sidebar.locator('#ab-form input[name="conversation_id"]')).toHaveValue('0');
  request = await saved;
  expect(request.postDataJSON()).toEqual({ conversation_id: 0 });
  expect((await request.response())?.status()).toBe(200);
  await page.goto('/wp-admin/index.php');
  await expect(page.locator('#ab-drawer')).toHaveAttribute('data-conversation', '0');
});
