/**
 * The chat in the block editor (Admin\Drawer::enqueueEditor()): a PluginSidebar, which the editor
 * opens from a pinned button in its top bar, mounting the fragment the admin-wide drawer mounts
 * (GET /view/panel through mount.ts), on the post being edited. Kanboard #4369 chose this over the
 * footer drawer, which Admin\Drawer does not print on a block editor screen because a panel fixed
 * to the viewport would sit over the block settings.
 *
 * Everything from WordPress is read off `window.wp` at run time and nothing is imported, so the
 * bundle carries no @wordpress/* code. The scripts this one is enqueued after set those globals
 * themselves (each of wp-includes/js/dist/plugins.js, editor.js, element.js and data.js ends by
 * assigning its package to `window.wp`). PluginSidebar is `wp.editor`'s; `wp.editPost.PluginSidebar`
 * is the same component behind a deprecation notice (edit-post.js's deprecateSlot()) and is not
 * used.
 *
 * A PluginSidebar renders its children only while it is open or animating shut (core's
 * ComplementaryArea), and the chat must outlive a close: a turn may be streaming into it, and the
 * chat bundle is bound to the composer it booted against. So the chat lives in one element made
 * here, once, which each mount of the sidebar's content takes into its slot, and which an unmount
 * puts back in the page, hidden, only if its slot still holds it. The chat follows each step,
 * however many an opening takes: with React's production build the content mounts once, and with
 * the development build, which WordPress loads when SCRIPT_DEBUG is on, it mounts, unmounts and
 * mounts again, because edit-post.js renders the editor inside React's StrictMode, which runs
 * effects twice in development. Back in the page and not merely detached, because the bundle and
 * htmx look for the chat in the document: the bundle finds the transcript with
 * document.querySelector() (boot.ts messages()) and would append a reply that arrives while the
 * sidebar is closed to the body instead, and htmx drops the history select's `ab:refresh from:body`
 * listener the first time it fires on an element that is not in the page. The chat is mounted the
 * first time the sidebar opens, and again only after a mount that failed; chat.js is added once
 * (mount.ts).
 *
 * The conversation is the drawer's (Kanboard #4527): the sidebar opens on the one the drawer
 * remembers, which Admin\Drawer::enqueueEditor() hands it as `alpacaBotMount.conversation`, and
 * the one it shows is remembered for both through POST /view/drawer (mount.ts
 * rememberConversation()): each the chat announces from inside the sidebar, and 0 after a New
 * chat. So each of the two opens on the conversation last shown in either. A mount that failed is
 * tried again on the conversation remembered since.
 *
 * The post is the editor's (`core/editor`'s getCurrentPostId()). On a new post that is an
 * auto-draft, and the panel renders no chip for an auto-draft (View\Chat\Shell), so a chat mounted
 * then names no post. Once the editor has saved or autosaved it, which takes the status off
 * `auto-draft`, the post chip is fetched into the composer the chat already has (mount.ts
 * postChip()), without a reload, since a reload would lose the editor's state, and without a
 * remount, which would lose the composer the bundle is bound to. The chip is asked for after a
 * mount or a New chat that brings none, and after each save the editor finishes, until one comes:
 * whether there is a chip is the server's answer, so a post the user may not edit is asked about
 * once per save and never named. A chip the user took off stays off through later saves, which
 * ask for none once one has come.
 *
 * "New chat" starts over in place, as it does in the drawer (mount.ts newChat()): the chat
 * screen's link would leave the editor, and is remembered as no conversation. It puts back the
 * chips the server renders for the post, a post chip the user took off included. The image button stays: the block editor loads the
 * media library (edit-form-blocks.php calls wp_enqueue_media()).
 *
 * Its own module, and not the entry's, so node:test can drive it (tests/ts/editor.test.ts): the
 * entry, editor.ts, reads the page as it loads, and no test imports an entry (tests/ts/env.ts).
 */
import { mountPanel, newChat, panelQuery, postChip, rememberConversation, type MountSettings } from './mount.ts';
import { pageNonce } from './nonce.ts';

export type CreateElement = (type: unknown, props: Record<string, unknown> | null, ...children: unknown[]) => unknown;
export interface EditorSelectors {
  getCurrentPostId(): unknown;
  getCurrentPostAttribute(name: string): unknown;
  isSavingPost(): boolean;
}
export interface EditorWp {
  plugins: { registerPlugin(name: string, settings: { icon?: string; render: () => unknown }): unknown };
  editor: { PluginSidebar: unknown };
  element: { createElement: CreateElement; useRef<T>(initial: T): { current: T }; useLayoutEffect(effect: () => (() => void) | void, deps: unknown[]): void };
  data: { select(store: 'core/editor'): EditorSelectors; subscribe(listener: () => void): unknown };
}

/** The sidebar's behaviour, registered as a block editor plugin on the `window.wp` the entry found. */
export function startEditor(cfg: MountSettings, wp: EditorWp): void {
  const editor = (): EditorSelectors => wp.data.select('core/editor');
  // Fresh from the heartbeat before the first open too (nonce.ts pageNonce(), Kanboard #4526).
  const nonce = pageNonce();
  const host = document.createElement('div');
  host.className = 'ab-sidebar';
  // The conversation the drawer remembers, which the sidebar opens on and keeps (the file docblock).
  host.dataset.conversation = cfg.conversation ?? '0';
  const remember = rememberConversation(host, cfg, nonce);
  let mounted: Promise<void> | null = null;
  // Whether the composer is without the post chip a save may yet bring: set by rendered(), cleared
  // once postChip() answers that there is one (the file docblock).
  let waiting = false;
  let asking = false;

  /** Puts the chat back in the page, hidden, while the sidebar is closed (the file docblock says why). */
  function park(): void {
    host.hidden = true;
    document.body.append(host);
  }

  /** The post for the panel's query, as panelQuery() reads it: a post id, or 0 for none. */
  function post(): string {
    const id = editor().getCurrentPostId();
    return typeof id === 'number' && Number.isInteger(id) && id > 0 ? String(id) : '0';
  }

  /** Asks for the post chip, when the chat is without one and the editor has saved the post. */
  function askForChip(): void {
    const status = editor().getCurrentPostAttribute('status');
    if (!waiting || asking || typeof status !== 'string' || status === 'auto-draft' || editor().isSavingPost()) return;
    asking = true;
    host.dataset.post = post();
    postChip(host, cfg, nonce()).then(
      (has) => { if (has) waiting = false; },
      (e: unknown) => { console.error(e); },
    ).finally(() => { asking = false; });
  }

  function open(slot: HTMLElement): void {
    slot.append(host);
    host.hidden = false;
    if (mounted) return;
    host.dataset.post = post();
    mounted = mountPanel(host, cfg, nonce(), panelQuery(host, host.dataset.conversation ?? '0')).then(
      rendered,
      (e: unknown) => { console.error(e); mounted = null; },
    );
  }

  /**
   * After the composer's chips have come from the server (the mount, a New chat): whether they
   * came without a post chip, and a request for one if so, since the post may have been saved
   * while they were on their way: a save the mount's chips predate, or one whose chip a New chat's
   * then replaced.
   */
  function rendered(): void {
    waiting = host.querySelector('#ab-form .ab-chip[data-chip="post"]') === null;
    askForChip();
  }

  /** "New chat" in place (the file docblock). */
  function startOver(): void {
    void newChat(host, cfg, nonce(), panelQuery(host, '0')).then((swapped) => {
      if (!swapped) return;
      remember(0);
      rendered();
    });
  }

  const h = wp.element.createElement;
  /** The sidebar's content: an element that holds the chat while it is mounted, and gives it back when it is not. */
  function Slot(): unknown {
    const ref = wp.element.useRef<HTMLElement | null>(null);
    wp.element.useLayoutEffect(() => {
      const slot = ref.current;
      if (!slot) return;
      open(slot);
      return () => { if (host.parentElement === slot) park(); };
    }, []);
    return h('div', { className: 'ab-sidebar__slot', ref });
  }

  park();
  let saving = false;
  wp.data.subscribe(() => {
    const now = editor().isSavingPost();
    const finished = saving && !now;
    saving = now;
    if (finished) askForChip();
  });
  host.addEventListener('click', (e) => {
    // The header's own "New chat" link, which would otherwise leave for the chat screen.
    if (!(e.target as Element).closest('.page-title-action')) return;
    e.preventDefault();
    startOver();
  });
  host.addEventListener('ab:new-chat', (e) => {
    e.preventDefault();
    startOver();
  });
  wp.plugins.registerPlugin('alpaca-bot', {
    icon: 'format-chat',
    render: () => h(wp.editor.PluginSidebar, { name: 'alpaca-bot-chat', title: cfg.title, className: 'ab-sidebar-panel' }, h(Slot, null)),
  });
}
