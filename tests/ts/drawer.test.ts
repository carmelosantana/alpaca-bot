import { test, type TestContext } from 'node:test';
import assert from 'node:assert/strict';
import { installDom, until } from './env.ts';

/**
 * The drawer's REST nonce before the chat bundle is on the page (Kanboard #4526). The drawer's
 * loader signs three requests of its own with `alpacaBot.nonce` (the mount's GET /view/panel,
 * "New chat"'s, and every POST /view/drawer), and the chat bundle, once the first open adds it,
 * boots on that same object (chat.ts). A tab left open past the nonce's life, with the drawer
 * never opened, must sign all of them with the nonce core's heartbeat brought, not the page's.
 *
 * Heartbeat's events are jQuery events on the document (resources/ts/nonce.ts), so the page's
 * `jQuery` is a stub here that records the handlers and lets a case fire `heartbeat-send` and
 * `heartbeat-tick` itself. Every assertion compares a primitive, never a node (tests/ts/env.ts).
 */
type Handler = (event: unknown, data: Record<string, unknown>) => void;
const REST = 'https://alpaca-bot.test/wp-json/alpaca-bot/v1';
const MOUNT = { panel: `${REST}/view/panel`, prefs: `${REST}/view/drawer`, htmx: '', htmxId: 'alpaca-bot-htmx-js', chat: '', css: '', title: 'Alpaca Bot', failed: 'failed' };
const PAGE = `<script id="alpaca-bot-htmx-js"></script>
<button type="button" id="ab-drawer-launcher" aria-expanded="false">Alpaca Bot</button>
<aside id="ab-drawer" hidden data-conversation="0"></aside>`;

/** The page's jQuery, as far as heartbeat goes: each case fires the events it needs. */
function heartbeat(): (event: 'heartbeat-send' | 'heartbeat-tick', data: Record<string, unknown>) => void {
  const handlers = new Map<string, Handler[]>();
  (window as unknown as { jQuery: unknown }).jQuery = () => ({
    on(event: string, handler: Handler) { handlers.set(event, [...(handlers.get(event) ?? []), handler]); },
  });
  return (event, data) => { for (const handler of handlers.get(event) ?? []) handler(null, data); };
}

/** Every request the drawer makes, by URL path, with the nonce and the body it carried: fetch()'s and htmx.ajax()'s. */
function record(t: TestContext): { path: string; nonce: string; body: string }[] {
  const seen: { path: string; nonce: string; body: string }[] = [];
  const real = globalThis.fetch;
  t.after(() => { globalThis.fetch = real; });
  globalThis.fetch = (async (input: RequestInfo | URL, init?: RequestInit) => {
    seen.push({ path: new URL(String(input)).pathname, nonce: new Headers(init?.headers).get('X-WP-Nonce') ?? '', body: String(init?.body ?? '') });
    // A body that is no element, so "New chat" answers false and swaps nothing in.
    return new Response('', { headers: { 'content-type': 'text/html' } });
  }) as typeof fetch;
  // htmx is on the page (its tag is, PAGE's first line), and brings no fragment: the mount then
  // fails, which is all this needs, having made the request.
  (window as unknown as { htmx: unknown }).htmx = {
    ajax: async (_method: string, url: string, opts: { headers: Record<string, string> }) => { seen.push({ path: new URL(url).pathname, nonce: opts.headers['X-WP-Nonce'] ?? '', body: '' }); },
    process: () => {},
  };
  t.mock.method(console, 'error', () => {});
  return seen;
}

test('a nonce the heartbeat brings before the first open signs the mount, New chat and the saved preferences (Kanboard #4526)', async (t) => {
  installDom(PAGE);
  const fire = heartbeat();
  const seen = record(t);
  window.alpacaBot = { rest: REST, nonce: 'stale', offline: 'offline', i18n: {} };
  const { startDrawer } = await import('../../resources/ts/drawer-start.ts');
  const launcher = document.getElementById('ab-drawer-launcher') as HTMLElement;
  const host = document.getElementById('ab-drawer') as HTMLElement;
  startDrawer(MOUNT, launcher, host);

  // The drawer asks for a fresh nonce on every beat, before anything has opened it.
  const sent: Record<string, unknown> = {};
  fire('heartbeat-send', sent);
  assert.equal(sent.alpaca_bot_nonce, 1);
  fire('heartbeat-tick', { alpaca_bot_nonce: 'fresh' });

  launcher.click();
  await until(() => seen.some((r) => r.path.endsWith('/view/panel')) && seen.some((r) => r.path.endsWith('/view/drawer')));
  host.dispatchEvent(new CustomEvent('ab:new-chat', { bubbles: true, cancelable: true }));
  await until(() => seen.filter((r) => r.path.endsWith('/view/panel')).length === 2);

  assert.deepEqual(seen.map((r) => `${r.path.replace('/wp-json/alpaca-bot/v1', '')} ${r.nonce}`).sort(), [
    '/view/drawer fresh',
    '/view/panel fresh',
    '/view/panel fresh',
  ]);
  // What chat.ts hands boot() once the first open adds the bundle: the same object, now fresh.
  assert.equal(window.alpacaBot.nonce, 'fresh');
});

test('core\'s own rest_nonce, sent instead of the answer once the heartbeat nonce has expired, is taken too', async (t) => {
  installDom(PAGE);
  const fire = heartbeat();
  record(t);
  window.alpacaBot = { rest: REST, nonce: 'stale', offline: 'offline', i18n: {} };
  const { startDrawer } = await import('../../resources/ts/drawer-start.ts');
  startDrawer(MOUNT, document.getElementById('ab-drawer-launcher') as HTMLElement, document.getElementById('ab-drawer') as HTMLElement);

  fire('heartbeat-tick', { rest_nonce: 'from-core' });
  assert.equal(window.alpacaBot.nonce, 'from-core');
  // A beat with neither leaves the nonce as it was.
  fire('heartbeat-tick', { server_time: 1 });
  assert.equal(window.alpacaBot.nonce, 'from-core');
});

/**
 * Once the first open has added the bundle, chat.ts boots on `window.alpacaBot` and registers a
 * refresh of its own beside the drawer's. Both answer one tick with one nonce into one object, and
 * both ask with the same field. And the chat starts with the nonce the drawer already has, since
 * it is the object the drawer wrote into.
 */
test('the booted chat and the drawer both on the heartbeat: the chat starts with the drawer\'s fresh nonce, and one tick sets one nonce', async (t) => {
  installDom(PAGE.replace('<aside id="ab-drawer" hidden data-conversation="0"></aside>', `<aside id="ab-drawer" data-conversation="0"><div class="ab-wrap">
    <div id="ab-chat" data-conversation="0"><div id="ab-status" class="ab-status"></div><div id="ab-messages" class="ab-messages" data-conversation="0"></div></div>
    <form id="ab-form" class="ab-composer">
      <input type="hidden" name="conversation_id" value="0"><input type="hidden" name="model" value="m"><input type="hidden" name="images" value="">
      <textarea id="ab-message" name="message" rows="1"></textarea>
      <div class="ab-composer__buttons"><button type="button" data-action="image">image</button><button type="button" data-action="image-remove" hidden>remove</button><button type="button" data-action="send">send</button></div>
    </form></div></aside>`));
  const fire = heartbeat();
  const seen = record(t);
  window.alpacaBot = { rest: REST, nonce: 'stale', offline: 'offline', i18n: {} };
  const { startDrawer } = await import('../../resources/ts/drawer-start.ts');
  const { boot } = await import('../../resources/ts/boot.ts');
  startDrawer(MOUNT, document.getElementById('ab-drawer-launcher') as HTMLElement, document.getElementById('ab-drawer') as HTMLElement);
  fire('heartbeat-tick', { alpaca_bot_nonce: 'fresh' });
  // What chat.ts does when the bundle arrives.
  const form = document.querySelector('#ab-form') as HTMLFormElement;
  boot(window.alpacaBot, form);

  // The chat starts with the drawer's fresh nonce, before any tick of its own.
  (form.querySelector('#ab-message') as HTMLTextAreaElement).value = 'hello';
  form.dispatchEvent(new CustomEvent('submit', { cancelable: true }));
  await until(() => seen.some((r) => r.path.endsWith('/alpaca-bot/v1/chat')));
  assert.equal(seen.find((r) => r.path.endsWith('/alpaca-bot/v1/chat'))?.nonce, 'fresh');

  // Two registrations now, and one question and one answer between them.
  const sent: Record<string, unknown> = {};
  fire('heartbeat-send', sent);
  assert.deepEqual(sent, { alpaca_bot_nonce: 1 });
  fire('heartbeat-tick', { alpaca_bot_nonce: 'fresher' });
  assert.equal(window.alpacaBot.nonce, 'fresher');
});

/**
 * Two tabs, one memory (Kanboard #4693). Tab A loads on conversation 5, and tab B then moves to 7,
 * so the stored conversation is 7 while tab A's drawer element still says 5, the value it was
 * printed with. Tab A goes on chatting in 5. Each of its announcements of 5 must reach POST
 * /view/drawer the first time, or the next screen opens 7 though the user's last activity was in
 * 5: the drawer dedups against what this tab last wrote, which before its first write is nothing,
 * and not against the page-load value. Tab B is not in this document; its only trace is the 7 the
 * server holds, which tab A cannot see, and that is the point. A mere open writes no conversation.
 */
test('a tab writes its first announcement even when it is the conversation the page loaded on, then only changes (Kanboard #4693)', async (t) => {
  installDom(PAGE.replace('data-conversation="0"', 'data-conversation="5"'));
  heartbeat();
  const seen = record(t);
  window.alpacaBot = { rest: REST, nonce: 'n', offline: 'offline', i18n: {} };
  const { startDrawer } = await import('../../resources/ts/drawer-start.ts');
  const launcher = document.getElementById('ab-drawer-launcher') as HTMLElement;
  const host = document.getElementById('ab-drawer') as HTMLElement;
  startDrawer(MOUNT, launcher, host);
  const written = (): string[] => seen.filter((r) => r.path.endsWith('/view/drawer')).map((r) => r.body);

  launcher.click();
  await until(() => seen.some((r) => r.path.endsWith('/view/panel')));
  // Opening saves the open flag and mounts; it announces no conversation, so it writes none.
  assert.deepEqual(written(), ['{"open":true}']);

  // Tab A's turn in 5: its start frame and its done frame each announce 5.
  const announce = (id: number): void => { host.dispatchEvent(new CustomEvent('ab:conversation', { bubbles: true, detail: { id } })); };
  announce(5);
  announce(5);
  // Then the user switches to 8 in this tab, and back to 5.
  announce(8);
  announce(5);
  assert.deepEqual(written(), ['{"open":true}', '{"conversation_id":5}', '{"conversation_id":8}', '{"conversation_id":5}']);
  // What the drawer element says it shows is the last announced, for a mount tried again.
  assert.equal(host.dataset.conversation, '5');
});
