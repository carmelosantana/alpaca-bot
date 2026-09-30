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
