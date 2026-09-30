/**
 * The admin-wide drawer's loader (Admin\Drawer): a small script on each screen that prints the
 * launcher, which does nothing until the launcher is pressed. The first open mounts the chat
 * (mount.ts), and a later open mounts it only if that failed; once it is mounted, opening and
 * closing only show and hide it, so a turn streaming into the drawer is never re-rendered and the
 * chat bundle's listeners are never bound twice.
 *
 * What the drawer remembers goes to POST /view/drawer and comes back on the next screen as the
 * drawer element's data attributes, where a drawer left open opens itself. That open does not
 * take the focus: the chat bundle focuses its box when it boots, which is right when the user
 * pressed the launcher and wrong on a screen they have just navigated to, so the focus is given
 * back.
 *
 * The image button (Carmelo, 2026-09-15): the drawer never loads the media library, the whole of
 * which the picker needs (Admin\Assets::enqueueFront() says what it costs), so the button is
 * hidden on a screen that has not loaded it, which is most of them. The hiding is the drawer
 * stylesheet's rule over `data-media="0"`, set here once the page's own scripts have run, and not
 * the button's `hidden`: composer.ts setImage() writes that property on every send and every
 * image removal, and would show the button again, dead, since pickImage() returns without
 * `wp.media`.
 *
 * Its own module, and not the entry's, so node:test can drive it (tests/ts/drawer.test.ts): the
 * entry, drawer.ts, reads the page as it loads, and no test imports an entry (tests/ts/env.ts).
 */
import { mountPanel, newChat, panelQuery, type MountSettings } from './mount.ts';

/** The drawer's behaviour, on the launcher and the drawer element Admin\Drawer::footer() prints. */
export function startDrawer(cfg: MountSettings, launcher: HTMLElement, host: HTMLElement): void {
  let mounted: Promise<void> | null = null;
  let remembered = host.dataset.conversation ?? '0';
  const nonce = (): string => window.alpacaBot?.nonce ?? '';

  host.dataset.media = window.wp?.media ? '1' : '0';

  /** Fire and forget: a preference that fails to save is not worth a notice over the chat. */
  function save(body: { open?: boolean; conversation_id?: number }): void {
    void fetch(cfg.prefs, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'X-WP-Nonce': nonce(), 'Content-Type': 'application/json' },
      body: JSON.stringify(body),
    }).catch(() => {});
  }

  /** The conversation the drawer shows, saved when it changes rather than on every announcement of it. */
  function remember(id: number): void {
    const value = String(id);
    if (value === remembered) return;
    remembered = value;
    host.dataset.conversation = value;
    save({ conversation_id: id });
  }

  /** Where the focus was before the chat booted, unless it was nowhere in particular. */
  function giveFocusBack(before: Element | null): void {
    const now = document.activeElement;
    if (!(now instanceof HTMLElement) || !host.contains(now)) return;
    if (before instanceof HTMLElement && before !== document.body && !host.contains(before)) before.focus();
    else now.blur();
  }

  function setOpen(open: boolean, byUser: boolean): void {
    host.hidden = !open;
    launcher.setAttribute('aria-expanded', String(open));
    if (byUser) save({ open });
    if (!open) {
      if (byUser) launcher.focus();
      return;
    }
    if (!mounted) {
      const before = document.activeElement;
      mounted = mountPanel(host, cfg, nonce(), panelQuery(host, host.dataset.conversation ?? '0')).then(
        () => { if (!byUser) giveFocusBack(before); },
        (e: unknown) => { console.error(e); mounted = null; },
      );
    }
    if (byUser) void mounted.then(() => host.querySelector<HTMLTextAreaElement>('#ab-message')?.focus());
  }

  /**
   * "New chat" in the drawer, where the chat screen goes to a new page (mount.ts newChat() says
   * what it swaps and what it keeps). The mount's query, so this is the fragment the drawer would
   * mount; the drawer then remembers that it is on no conversation.
   */
  async function startOver(): Promise<void> {
    if (await newChat(host, cfg, nonce(), panelQuery(host, '0'))) remember(0);
  }

  launcher.addEventListener('click', () => setOpen(host.hidden, true));
  host.addEventListener('click', (e) => {
    const target = e.target as Element;
    if (target.closest('[data-action="drawer-close"]')) {
      setOpen(false, true);
    } else if (target.closest('.page-title-action')) {
      // The header's own "New chat" link, which would otherwise leave for the chat screen.
      e.preventDefault();
      void startOver();
    }
  });
  document.addEventListener('ab:conversation', (e) => {
    if (host.contains(e.target as Node)) remember((e as CustomEvent<{ id: number }>).detail.id);
  });
  document.addEventListener('ab:new-chat', (e) => {
    if (!host.contains(e.target as Node)) return;
    e.preventDefault();
    void startOver();
  });
  if (host.dataset.open === '1') setOpen(true, false);
}
