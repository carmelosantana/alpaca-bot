/**
 * The chat's extension points for a companion plugin's script: `alpacaBot.*` filters and actions
 * over core's `wp-hooks` script (window.wp.hooks, @wordpress/hooks), which the three bundles
 * declare as a dependency. A companion enqueues its script with `wp-hooks` as a dependency and
 * listens with `wp.hooks.addAction()` / `addFilter()`; docs/hooks.md lists every name.
 *
 * window.wp.hooks is read when a hook fires, never at import, so a page without it (or a test)
 * runs the chat unchanged: a filter hands its value back, an action does nothing.
 *
 * `window.alpacaBotChat` (ready()) is the one global: `{ apiVersion, ready }`, where `ready` is a
 * promise for the first chat that boots on the page. A script that runs after the chat booted
 * awaits it instead of listening for `alpacaBot.ready`, which it would have missed. The entries
 * make it as they load (chat.ts, drawer.ts, editor.ts), so a companion that depends on one of
 * their handles finds it there; in the drawer and the editor sidebar the chat boots when it is
 * first opened, and the promise waits until then.
 */

/** The extension contract's version, Plugin::API_VERSION on the server; both move together. */
export const API_VERSION = 1;

/** The slice of @wordpress/hooks this module calls. */
interface WpHooks {
  applyFilters(name: string, value: unknown, ...args: unknown[]): unknown;
  doAction(name: string, ...args: unknown[]): void;
  addAction(name: string, namespace: string, callback: (...args: unknown[]) => void): void;
}

/** What `alpacaBot.ready` hands a listener, and `window.alpacaBotChat.ready` resolves with. */
export interface ChatApi {
  apiVersion: number;
  /** The composer form (#ab-form): where a companion adds a button or a hidden field. */
  form: HTMLFormElement;
  /** The chat's shell (`.ab-wrap`), or the document for a form outside one. */
  shell: ParentNode;
}

export interface AlpacaBotChat {
  apiVersion: number;
  ready: Promise<ChatApi>;
}

declare global {
  interface Window {
    alpacaBotChat?: AlpacaBotChat;
  }
}

function hooks(): WpHooks | undefined {
  return (window as unknown as { wp?: { hooks?: WpHooks } }).wp?.hooks;
}

/**
 * `name` applied to `value`. A listener's answer is kept only when it is of the value's own kind
 * (an object for an object, a string for a string), so a listener that forgot to return, or
 * returned something else, leaves the chat with what it had rather than breaking the turn.
 */
export function filter<T>(name: string, value: T, ...args: unknown[]): T {
  const h = hooks();
  if (!h) return value;
  const out = h.applyFilters(name, value, ...args);
  const object = (v: unknown): boolean => typeof v === 'object' && v !== null && !Array.isArray(v);
  if (object(value) ? object(out) : typeof out === typeof value) return out as T;
  return value;
}

/** Fires `name` with `args`, when the page has wp.hooks. */
export function action(name: string, ...args: unknown[]): void {
  hooks()?.doAction(name, ...args);
}

/** `window.alpacaBotChat`, made the first time it is asked for. */
export function ready(): AlpacaBotChat {
  window.alpacaBotChat ??= {
    apiVersion: API_VERSION,
    ready: new Promise<ChatApi>((resolve) => {
      hooks()?.addAction('alpacaBot.ready', 'alpaca-bot/ready', (api) => resolve(api as ChatApi));
    }),
  };
  return window.alpacaBotChat;
}
