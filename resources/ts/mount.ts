/**
 * Puts the chat into a page that did not load it, the first time its host is opened: the admin-wide
 * drawer (drawer.ts) and the block editor's sidebar (editor.ts). What it is told comes from
 * Admin\Assets::mount() as `alpacaBotMount`.
 *
 * The order is the whole of it. The chat's stylesheet goes in first so the fragment lands styled.
 * htmx comes next, because the fragment is fetched through it: GET /view/panel is swapped in with
 * htmx.ajax(), which also wires the hx-* attributes the header's selects carry. The chat bundle
 * comes last, once the fragment is in the document, because chat.ts boots against the #ab-form it
 * finds when it runs and would find none if it ran first. A file that loaded is not added again,
 * and nor is htmx when the page already carries it under its handle (`htmxId`).
 * A host calls mountPanel() again only after a mount that failed; a mounted chat it shows and
 * hides (the sidebar moves it as well, and editor-start.ts says why), so a turn in flight is never
 * re-rendered.
 */
import { fromHtml } from './dom.ts';

/**
 * What Admin\Assets::mount() localises; `title` is the chat's name, for a host that titles the
 * panel it mounts the chat in. `conversation` is the editor sidebar's alone
 * (Admin\Drawer::enqueueEditor()): the conversation the drawer remembers, which the drawer reads
 * off its own element instead.
 */
export interface MountSettings { panel: string; prefs: string; htmx: string; htmxId: string; chat: string; css: string; title: string; failed: string; conversation?: string }

declare global {
  interface Window { alpacaBotMount?: MountSettings }
}

const added = new Map<string, Promise<void>>();

/** Adds a script once per page, resolving when it has run. */
export function script(src: string): Promise<void> {
  let loading = added.get(src);
  if (!loading) {
    loading = new Promise<void>((resolve, reject) => {
      const el = document.createElement('script');
      el.src = src;
      el.addEventListener('load', () => resolve());
      el.addEventListener('error', () => {
        // Forgotten, so the next open tries again rather than awaiting this failure for good.
        added.delete(src);
        el.remove();
        reject(new Error(`Alpaca Bot could not load ${src}`));
      });
      document.body.append(el);
    });
    added.set(src, loading);
  }
  return loading;
}

/** Adds a stylesheet once per page. */
export function style(href: string): void {
  for (const link of document.querySelectorAll<HTMLLinkElement>('link[data-ab-mounted]')) {
    if (link.dataset.abMounted === href) return;
  }
  const link = document.createElement('link');
  link.rel = 'stylesheet';
  link.href = href;
  link.dataset.abMounted = href;
  document.head.append(link);
}

/** `base` with `query` added, under either permalink form: a `?rest_route=` URL keeps its route. */
export function withQuery(base: string, query: Record<string, string>): string {
  const url = new URL(base, window.location.href);
  for (const [key, value] of Object.entries(query)) url.searchParams.set(key, value);
  return url.toString();
}

/**
 * The query every fetch of GET /view/panel into `host` carries: the conversation to open, and
 * what the composer's context chips name, off the host's data attributes: the ones
 * Admin\Drawer::footer() prints on the drawer element (the post on the classic editor, the
 * screen's id and page title), or the post editor-start.ts sets on the sidebar's. An element
 * without them asks for no chips.
 */
export function panelQuery(host: HTMLElement, conversation: string): Record<string, string> {
  return {
    conversation_id: conversation,
    post_id: host.dataset.post ?? '0',
    screen_id: host.dataset.screenId ?? '',
    screen_title: host.dataset.screenTitle ?? '',
  };
}

/**
 * The chat, in `host`: the file docblock says in what order. Any failure on the way (a file that
 * would not load, a refused or failed request for the fragment) leaves `cfg.failed` in `host` and
 * rejects, so the caller can let the next open try again from the start.
 */
export async function mountPanel(host: HTMLElement, cfg: MountSettings, nonce: string, query: Record<string, string>): Promise<void> {
  try {
    style(cfg.css);
    // A page that enqueued htmx itself (the settings screen does) carries it under the id core
    // prints on the handle's tag. Its URL can differ from cfg.htmx (a site may rewrite or strip
    // `?ver=`), and the id does not. The tag being there is taken as the script having run, which
    // holds once DOMContentLoaded has fired (a plain footer script runs before it), and the drawer
    // mounts no earlier than that (drawer.ts waits for it), so htmx is not loaded again.
    if (!document.getElementById(cfg.htmxId)) {
      await script(cfg.htmx);
    }
    const htmx = window.htmx;
    if (!htmx) throw new Error('Alpaca Bot: htmx did not load.');
    // The nonce by hand. The chat bundle's htmx:configRequest listener signs only requests whose
    // element is inside the chat's shell (boot.ts), and htmx.ajax() with no source runs with the
    // body as its element, so this request would go out unsigned whether or not the bundle is on
    // the page.
    await htmx.ajax('GET', withQuery(cfg.panel, query), { target: host, swap: 'innerHTML', headers: { 'X-WP-Nonce': nonce } });
    if (!host.querySelector('#ab-form')) throw new Error('Alpaca Bot: the panel fragment did not arrive.');
    await script(cfg.chat);
  } catch (e) {
    host.textContent = cfg.failed;
    throw e;
  }
}

/**
 * The user's drawer preferences, to POST /view/drawer (`cfg.prefs`): whether the drawer is open,
 * and the conversation last shown. Fire and forget: a preference that fails to save is not worth
 * a notice over the chat. It answers whether the write was taken (a 2xx), and never rejects, so a
 * caller that ignores it is left no unhandled rejection; rememberConversation() is the one that
 * reads it.
 */
export function savePrefs(cfg: MountSettings, nonce: string, body: { open?: boolean; conversation_id?: number }): Promise<boolean> {
  return fetch(cfg.prefs, {
    method: 'POST',
    credentials: 'same-origin',
    headers: { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json' },
    body: JSON.stringify(body),
  }).then((res) => res.ok, () => false);
}

/**
 * The conversation `host` shows, remembered for the user: the drawer's, and the editor sidebar's,
 * which share the one memory (Kanboard #4527), so either reopens on the conversation last shown in
 * either. It is set on each `ab:conversation` the chat announces from inside the host
 * (resources/ts/boot.ts: a turn's `start` and `done` frames, and each htmx swap inside the chat,
 * which re-announces the transcript shown, a switch in the history select among them) and on the
 * id the returned function is called with (the host's New chat, with 0).
 *
 * The rule across tabs is that the last activity wins (Kanboard #4693): whichever tab last
 * announced a conversation owns the memory, since that is where the user was last. So a write is
 * skipped only when it is the value this tab last wrote, which before its first write is nothing,
 * and never because it is the host's `data-conversation`. That attribute starts at what the page
 * was printed with (Admin\Drawer::footer() for the drawer, `alpacaBotMount.conversation` for the
 * sidebar), which another tab may have moved the memory on from since: tab A loads on 5, tab B
 * moves to 7, and tab A's turns in 5 must still write 5, or the next screen opens 7. Each tab
 * then writes once for its first announcement and once per change of what it shows, however
 * many times a turn announces the same id. A mount or an open announces nothing (the chat bundle
 * binds its listeners after the mount's swap), so it writes no conversation.
 *
 * The value counts as written from the moment it is sent, so a turn's `done` that arrives while
 * its `start`'s write is in flight sends nothing, and it is forgotten if that write fails (refused,
 * or never arriving), so the next announcement of it tries again; a failure that lands after a
 * later write was sent forgets nothing of that later one. `data-conversation` still means the
 * conversation the host shows, updated on every announcement, written or not: a mount that failed
 * is tried again on it. The drawer's open flag is not touched.
 */
export function rememberConversation(host: HTMLElement, cfg: MountSettings, nonce: () => string): (id: number) => void {
  let written: number | null = null;
  function remember(id: number): void {
    host.dataset.conversation = String(id);
    if (id === written) return;
    written = id;
    void savePrefs(cfg, nonce(), { conversation_id: id }).then((ok) => {
      if (!ok && written === id) written = null;
    });
  }
  document.addEventListener('ab:conversation', (e) => {
    if (host.contains(e.target as Node)) remember((e as CustomEvent<{ id: number }>).detail.id);
  });
  return remember;
}

/**
 * "New chat" in place, for a host that keeps the chat on a page the chat screen's link would leave
 * (the drawer, the editor sidebar): a fresh transcript and history from GET /view/panel swapped
 * in, and the conversation set back to 0. The composer stays, because the bundle's listeners are
 * bound to it and a replaced form would have none, and so does what is typed in it. Its context
 * chips are the fragment's: the fragment's chips row takes the place of the composer's, before the
 * box, where Composer puts it, so a chip the user took off comes back and a chip the fragment
 * lacks goes, and a fragment with no chips leaves no row. Which chips there are, their labels and
 * their fields are the server's, as they are at the mount. Answers true for a 2xx response whose
 * body parses to an element, once it has swapped in the transcript and the history that element
 * holds (one it lacks is left as it was) and its chips, and set the conversation back to 0.
 * A request that is refused, a body that is not an element, or a request that fails outright
 * answers false and changes nothing; the outright failure (a network error, which fetch() rejects
 * with) is caught and logged to the console. So neither the request nor its parse rejects the
 * promise, and a caller that does not wait on it is left no unhandled rejection by them.
 */
export async function newChat(host: HTMLElement, cfg: MountSettings, nonce: string, query: Record<string, string>): Promise<boolean> {
  let fresh: HTMLElement | null;
  try {
    const res = await fetch(withQuery(cfg.panel, query), { credentials: 'same-origin', headers: { 'X-WP-Nonce': nonce } });
    fresh = res.ok ? fromHtml(await res.text()) : null;
  } catch (e) {
    console.error(e);
    return false;
  }
  if (!fresh) return false;
  for (const id of ['ab-messages', 'ab-history']) {
    const next = fresh.querySelector('#' + id);
    if (next) host.querySelector('#' + id)?.replaceWith(next);
  }
  const box = host.querySelector('#ab-form .ab-composer__row');
  if (box) {
    host.querySelector('#ab-form .ab-composer__chips')?.remove();
    const chips = fresh.querySelector('#ab-form .ab-composer__chips');
    if (chips) box.before(chips);
  }
  const field = host.querySelector<HTMLInputElement>('#ab-form [name="conversation_id"]');
  if (field) field.value = '0';
  host.querySelector('#ab-chat')?.setAttribute('data-conversation', '0');
  host.querySelector('#ab-status')?.replaceChildren();
  window.htmx?.process(host);
  return true;
}

/**
 * The post chip for the host's post (`data-post`, panelQuery()), taken from a fresh GET
 * /view/panel into the composer the chat already has, for a chat mounted before its post could
 * have one: the editor sidebar on a new post, whose auto-draft gets no chip until it is first
 * saved or autosaved (resources/ts/editor-start.ts). The chip is the server's whole: its label, its
 * hidden field and its escaping are View\Chat\Composer's, and whether there is one at all is
 * View\Chat\Shell's rule, so a post the user may not edit, or one still an auto-draft, comes back
 * with none and none is added. It goes first in the chips row, where Composer puts it, and a
 * composer with no row gets the fragment's. Nothing else of the fragment is taken.
 *
 * Answers whether the composer has a post chip now: true without a request when it has one
 * already, true without adding one when one arrived while it asked (a New chat whose fragment had
 * one, newChat()), false when the fragment was refused or had none. A request that fails outright
 * (a network error) rejects, as fetch() does; editor-start.ts, its caller, logs that and asks again
 * after the next save.
 */
export async function postChip(host: HTMLElement, cfg: MountSettings, nonce: string): Promise<boolean> {
  const form = host.querySelector<HTMLFormElement>('#ab-form');
  if (!form) return false;
  if (form.querySelector('.ab-chip[data-chip="post"]')) return true;
  const res = await fetch(withQuery(cfg.panel, panelQuery(host, '0')), { credentials: 'same-origin', headers: { 'X-WP-Nonce': nonce } });
  const chip = (res.ok ? fromHtml(await res.text()) : null)?.querySelector('#ab-form .ab-chip[data-chip="post"]');
  // A New chat that answered while this was asking may have brought the chip back already.
  if (form.querySelector('.ab-chip[data-chip="post"]')) return true;
  const box = form.querySelector('.ab-composer__row');
  if (!chip || !box) return false;
  const row = form.querySelector('.ab-composer__chips');
  if (row) {
    row.prepend(chip);
  } else {
    const fresh = chip.parentElement as HTMLElement;
    fresh.replaceChildren(chip);
    box.before(fresh);
  }
  return true;
}
