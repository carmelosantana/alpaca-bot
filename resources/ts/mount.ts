/**
 * Puts the chat into a page that did not load it: the admin-wide drawer on its first open
 * (drawer.ts). What it is told comes from Admin\Assets::mount() as `alpacaBotMount`.
 *
 * The order is the whole of it. The chat's stylesheet goes in first so the fragment lands styled.
 * htmx comes next, because the fragment is fetched through it: GET /view/panel is swapped in with
 * htmx.ajax(), which also wires the hx-* attributes the header's selects carry. The chat bundle
 * comes last, once the fragment is in the document, because chat.ts boots against the #ab-form it
 * finds when it runs and would find none if it ran first. A file that loaded is not added again.
 * drawer.ts calls mountPanel() again only after a mount that failed; a mounted chat it shows and
 * hides, so a turn in flight is never re-rendered.
 */
/** What Admin\Assets::mount() localises; `title` is the chat's name, for a host that titles the panel it mounts the chat in. */
export interface MountSettings { panel: string; prefs: string; htmx: string; chat: string; css: string; title: string; failed: string }

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
 * what the composer's context chips name, off the data attributes Admin\Drawer::footer() prints
 * on the drawer element (the post on the classic editor, the screen's id and page title). An
 * element without them asks for no chips.
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
    await script(cfg.htmx);
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
