import { test, type TestContext } from 'node:test';
import assert from 'node:assert/strict';
import { installDom, until } from './env.ts';
import type { EditorWp } from '../../resources/ts/editor-start.ts';

/**
 * The block editor's sidebar (resources/ts/editor-start.ts), driven through a `window.wp` of its
 * own: registerPlugin() keeps the render, and opening the sidebar is rendering it and running the
 * Slot's layout effect on an element of the page, which is what core's ComplementaryArea does when
 * the sidebar opens. The editor's store answers a saved post, 12.
 *
 * Heartbeat's events are jQuery events on the document (resources/ts/nonce.ts), so the page's
 * `jQuery` is a stub that records the handlers and lets a case fire `heartbeat-tick`. Every
 * assertion compares a primitive, never a node (tests/ts/env.ts).
 */
type Handler = (event: unknown, data: Record<string, unknown>) => void;
type Node_ = { type: unknown; props: Record<string, unknown> | null; children: unknown[] };
const REST = 'https://alpaca-bot.test/wp-json/alpaca-bot/v1';
const MOUNT = { panel: `${REST}/view/panel`, prefs: `${REST}/view/drawer`, htmx: '', htmxId: 'alpaca-bot-htmx-js', chat: '', css: '', title: 'Alpaca Bot', failed: 'failed' };
const PAGE = '<script id="alpaca-bot-htmx-js"></script><div id="slot"></div>';
/** What GET /view/panel answers "New chat" with: a fresh transcript and history, and no chips. */
const FRAGMENT = '<div class="ab-wrap"><div id="ab-history"></div><div id="ab-chat" data-conversation="0"><div id="ab-status"></div><div id="ab-messages" data-conversation="0"></div></div><form id="ab-form"><input type="hidden" name="conversation_id" value="0"><div class="ab-composer__row"></div></form></div>';

/** The page's jQuery, as far as heartbeat goes. */
function heartbeat(): (event: 'heartbeat-send' | 'heartbeat-tick', data: Record<string, unknown>) => void {
  const handlers = new Map<string, Handler[]>();
  (window as unknown as { jQuery: unknown }).jQuery = () => ({
    on(event: string, handler: Handler) { handlers.set(event, [...(handlers.get(event) ?? []), handler]); },
  });
  return (event, data) => { for (const handler of handlers.get(event) ?? []) handler(null, data); };
}

interface Seen { method: string; path: string; query: string; nonce: string; body: string }

/**
 * Every request the sidebar makes, fetch()'s and htmx.ajax()'s. The mount's htmx.ajax() swaps in
 * the fragment and the chat bundle then fails to load (no file loading here), which is as far as
 * a case needs it to go; a GET of the panel by fetch() ("New chat") answers FRAGMENT.
 */
function record(t: TestContext): Seen[] {
  const seen: Seen[] = [];
  const real = globalThis.fetch;
  t.after(() => { globalThis.fetch = real; });
  globalThis.fetch = (async (input: RequestInfo | URL, init?: RequestInit) => {
    const url = new URL(String(input));
    seen.push({ method: init?.method ?? 'GET', path: url.pathname.replace('/wp-json/alpaca-bot/v1', ''), query: url.searchParams.get('conversation_id') ?? '', nonce: new Headers(init?.headers).get('X-WP-Nonce') ?? '', body: String(init?.body ?? '') });
    return new Response(url.pathname.endsWith('/view/panel') ? FRAGMENT : '', { headers: { 'content-type': 'text/html' } });
  }) as typeof fetch;
  (window as unknown as { htmx: unknown }).htmx = {
    ajax: async (_method: string, url: string, opts: { headers: Record<string, string> }) => {
      const u = new URL(url);
      seen.push({ method: 'GET', path: u.pathname.replace('/wp-json/alpaca-bot/v1', ''), query: u.searchParams.get('conversation_id') ?? '', nonce: opts.headers['X-WP-Nonce'] ?? '', body: '' });
    },
    process: () => {},
  };
  t.mock.method(console, 'error', () => {});
  return seen;
}

/** A `window.wp` for startEditor(), and a way to open the sidebar it registers. */
function editorWp(): { wp: EditorWp; open: () => void } {
  let render: (() => unknown) | null = null;
  const slot = document.getElementById('slot') as HTMLElement;
  const wp: EditorWp = {
    plugins: { registerPlugin: (_name, settings) => { render = settings.render; } },
    editor: { PluginSidebar: 'PluginSidebar' },
    element: {
      createElement: (type, props, ...children) => ({ type, props, children }),
      useRef: <T>() => ({ current: slot as unknown as T }),
      useLayoutEffect: (effect) => { effect(); },
    },
    data: {
      select: () => ({ getCurrentPostId: () => 12, getCurrentPostAttribute: () => 'publish', isSavingPost: () => false }),
      subscribe: () => undefined,
    },
  };
  return {
    wp,
    open: () => {
      // The sidebar's content is its one child, the Slot, which core renders as a component.
      const sidebar = (render as unknown as () => Node_)();
      ((sidebar.children[0] as Node_).type as () => unknown)();
    },
  };
}

test('a nonce the heartbeat brings before the first open signs the sidebar\'s mount (Kanboard #4526)', async (t) => {
  installDom(PAGE);
  const fire = heartbeat();
  const seen = record(t);
  window.alpacaBot = { rest: REST, nonce: 'stale', offline: 'offline', i18n: {} };
  const { startEditor } = await import('../../resources/ts/editor-start.ts');
  const { wp, open } = editorWp();
  startEditor(MOUNT, wp);

  const sent: Record<string, unknown> = {};
  fire('heartbeat-send', sent);
  assert.equal(sent.alpaca_bot_nonce, 1);
  fire('heartbeat-tick', { alpaca_bot_nonce: 'fresh' });

  open();
  await until(() => seen.some((r) => r.path === '/view/panel'));
  assert.equal(seen.find((r) => r.path === '/view/panel')?.nonce, 'fresh');
  assert.equal(window.alpacaBot.nonce, 'fresh');
});

/**
 * The sidebar shares the drawer's memory (Kanboard #4527): it opens on the conversation
 * Admin\Drawer::enqueueEditor() hands it as `alpacaBotMount.conversation`, the one the drawer
 * remembers, and records the conversation it shows through POST /view/drawer as the drawer does,
 * never the drawer's open flag.
 */
test('the sidebar opens on the conversation the drawer remembers, and remembers the one it shows (Kanboard #4527)', async (t) => {
  installDom(PAGE);
  heartbeat();
  const seen = record(t);
  window.alpacaBot = { rest: REST, nonce: 'n', offline: 'offline', i18n: {} };
  const { startEditor } = await import('../../resources/ts/editor-start.ts');
  const { wp, open } = editorWp();
  startEditor({ ...MOUNT, conversation: '42' }, wp);

  open();
  await until(() => seen.some((r) => r.path === '/view/panel'));
  assert.equal(seen.find((r) => r.path === '/view/panel')?.query, '42');

  // Opening announces nothing, so it writes nothing.
  assert.deepEqual(seen.filter((r) => r.method === 'POST').map((r) => `${r.path} ${r.body}`), []);

  // The chat announces the conversation its transcript shows, from inside the sidebar. The
  // first announcement is saved even when it is the one the sidebar opened on, since another
  // tab may have moved the memory on since this page loaded (Kanboard #4693); after that, only a
  // change is, and one from outside the sidebar is not.
  const inside = document.querySelector('.ab-sidebar') as HTMLElement;
  const announce = (from: Element, id: number): void => { from.dispatchEvent(new CustomEvent('ab:conversation', { bubbles: true, detail: { id } })); };
  announce(inside, 42);
  announce(inside, 42);
  announce(inside, 7);
  announce(inside, 7);
  announce(document.body, 9);
  assert.deepEqual(seen.filter((r) => r.method === 'POST').map((r) => `${r.path} ${r.body}`), ['/view/drawer {"conversation_id":42}', '/view/drawer {"conversation_id":7}']);

  // A mount that failed (this one did: no chat bundle loads here) is tried again on the next
  // open, on the conversation remembered since.
  await until(() => inside.textContent === 'failed');
  open();
  await until(() => seen.filter((r) => r.path === '/view/panel').length === 2);
  assert.equal(seen.filter((r) => r.path === '/view/panel')[1]?.query, '7');
});

test('New chat in the sidebar remembers that it is on no conversation (Kanboard #4527)', async (t) => {
  installDom(PAGE);
  heartbeat();
  const seen = record(t);
  window.alpacaBot = { rest: REST, nonce: 'n', offline: 'offline', i18n: {} };
  const { startEditor } = await import('../../resources/ts/editor-start.ts');
  const { wp, open } = editorWp();
  startEditor({ ...MOUNT, conversation: '42' }, wp);
  open();
  await until(() => seen.some((r) => r.path === '/view/panel'));

  (document.querySelector('.ab-sidebar') as HTMLElement).dispatchEvent(new CustomEvent('ab:new-chat', { bubbles: true, cancelable: true }));
  await until(() => seen.some((r) => r.method === 'POST'));
  // New chat fetched the panel on no conversation, then saved that.
  assert.deepEqual(seen.map((r) => `${r.method} ${r.path} ${r.query}${r.body}`), [
    'GET /view/panel 42',
    'GET /view/panel 0',
    'POST /view/drawer {"conversation_id":0}',
  ]);
});
