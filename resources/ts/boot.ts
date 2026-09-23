/**
 * The chat screen's browser layer. The markup is the server's (View\Chat\*), htmx swaps the
 * selects, and this file does what neither can: the streamed turn (POST /chat for a ticket,
 * then its stream_url read as server-sent events into a bubble), the keyboard, the copy and
 * edit actions, the image picker, the offline guard (Kanboard #473) and the nonce refresh
 * (Kanboard #302). It never writes an hx-* attribute: what a request needs changed at send
 * time (the nonce header, the history select's conversation id) is changed on the
 * htmx:configRequest event instead. Every fragment it inserts came from a /view/* route.
 *
 * Its listeners on the document and the body hear the whole page, but what they act on is the
 * shell, the `.ab-wrap` around the form: a click on a `data-action` button inside it, an htmx
 * request, swap or error whose element is inside it, a code block inside it, and a change of
 * `#ab-model`, an id only View\Chat\ModelSelect prints, which a page gets in the shell's header.
 * The admin-wide drawer (Admin\Drawer) puts the shell on wp-admin screens whose own buttons carry
 * `data-action` too (the comments list's Quick Edit is `data-action="edit"`), whose pages may hold
 * code blocks, and where another plugin's htmx must not have this chat's REST nonce added to its
 * requests or its errors shown in this chat's status line. One thing outside the shell is the
 * chat's too: a prompt answer (`.alpaca-bot-answer`, Shortcodes\Chat) on a page that also carries
 * a shell, whose code blocks are decorated and whose copy buttons copy, as they were when this
 * decorated the whole page. And it tells whatever hosts the shell two things, as events on the
 * form: `ab:conversation` (`detail.id`) whenever the conversation the transcript shows is set, and
 * `ab:new-chat`, cancelable, before the history select's "New chat" leaves the page.
 * resources/ts/drawer.ts listens for both.
 *
 * boot() is exported rather than run on import, so node:test can drive it against a document of
 * its own (tests/ts/chat.test.ts, Kanboard #4334); chat.ts is the entry that finds the shell and
 * calls it. The pieces with a decision of their own to test are composer.ts, redeem.ts and
 * refusal.ts.
 */
import { decorate } from './highlight.ts';
import { readSse } from './stream.ts';
import { watchNonce } from './nonce.ts';
import { ImageTooLarge, ImagesTooLarge, fetchDataUrl, formatBytes, imageLimit } from './image.ts';
import { $, $$, asId, el, fromHtml, icon, notice } from './dom.ts';
import { grow, restoreDraft, setImage } from './composer.ts';
import { redeem, restError } from './redeem.ts';
import { refusal } from './refusal.ts';
import { appendText, releaseHeld } from './held.ts';
import type { RestError } from './redeem.ts';

// wp_localize_script() ships every scalar as a string, so the byte figure arrives as one; imageLimit() reads it.
export interface Settings { rest: string; nonce: string; i18n: Record<string, string>; offline: string; maxImageBytes?: string | number }
interface Attachment { url: string; sizes?: Record<string, { url: string }> }
interface MediaFrame { on(event: string, cb: () => void): void; open(): void; state(): { get(key: string): { first(): { toJSON(): Attachment } } } }
interface HtmxDetail { path: string; headers: Record<string, string>; xhr?: XMLHttpRequest }
type Json = Record<string, unknown>;

/** The part of htmx's API this bundle and the drawer's loader call (resources/ts/mount.ts, drawer.ts). */
interface Htmx {
  trigger(target: Element, name: string): void;
  ajax(verb: string, path: string, context: { target: Element; swap: string; headers?: Record<string, string> }): Promise<void>;
  process(target: Element): void;
}

declare global {
  interface Window {
    alpacaBot?: Settings;
    htmx?: Htmx;
    wp?: { media?: (options: object) => MediaFrame };
  }
}

export function boot(cfg: Settings, form: HTMLFormElement): void {
  const t = (key: string): string => cfg.i18n[key] ?? key;
  const textarea = $<HTMLTextAreaElement>('#ab-message', form) as HTMLTextAreaElement;
  const sendButton = $<HTMLButtonElement>('[data-action="send"]', form) as HTMLButtonElement;
  const field = (name: string): HTMLInputElement => form.elements.namedItem(name) as HTMLInputElement;
  // What this boot acts on: the shell (the file docblock says why), or the document for a form
  // that is not in one.
  const shell: ParentNode = form.closest('.ab-wrap') ?? document;
  /** Whether an event's target is the shell's: the htmx listeners below ask this. */
  const ours = (target: EventTarget | null): boolean => target instanceof Node && shell.contains(target);
  /** The shell's code blocks, and a prompt answer's on the same page (the file docblock). */
  function decorateOwn(): void {
    decorate(shell, t('copyCode'));
    for (const answer of $$('.alpaca-bot-answer')) decorate(answer, t('copyCode'));
  }
  let busy = false;
  let expired = false;
  let offlineShown = false;

  // ---- requests -------------------------------------------------------------------------

  /** A route under the REST root, whichever permalink form the site uses. */
  function api(path: string, query: Record<string, string> = {}): string {
    const u = new URL(cfg.rest);
    const route = u.searchParams.get('rest_route');
    if (route !== null) u.searchParams.set('rest_route', route + path);
    else u.pathname = u.pathname.replace(/\/$/, '') + path;
    for (const [k, v] of Object.entries(query)) u.searchParams.set(k, v);
    return u.toString();
  }
  function request(method: string, url: string, body?: Json): Promise<Response> {
    const headers: Record<string, string> = { 'X-WP-Nonce': cfg.nonce };
    if (body) headers['Content-Type'] = 'application/json';
    return fetch(url, { method, credentials: 'same-origin', headers, body: body ? JSON.stringify(body) : undefined });
  }
  /**
   * Shows a refused request. What it says and whether it locks the composer are refusal()'s
   * (a stale nonce locks it until a reload, Kanboard #302); the colour is decided here.
   *
   * The concurrency refusal is "not yet", not a failure: the ticket is unspent, and send()'s
   * finally gives the message and the image back as soon as this returns, so it says why and
   * stays out of the error colour. The server's message already names how many streams are
   * open, so it is shown as it stands; `data.limit` is the same number for a client that words
   * its own.
   */
  function refused(status: number, error: RestError): void {
    const decision = refusal(status, error, t);
    if (decision.expired) {
      expired = true;
      textarea.disabled = true;
      sendButton.disabled = true;
    }
    notice(error.code === 'alpaca_bot_stream_concurrency' ? 'warning' : 'error', decision.text);
  }

  // ---- transcript -----------------------------------------------------------------------

  const messages = (): HTMLElement => $('#ab-messages') ?? document.body;
  /** Records the conversation the transcript shows; a frame or a list without a usable id leaves it as it was, so the field never holds "NaN". */
  function setConversation(value: unknown): void {
    const id = asId(value);
    if (id === null) return;
    field('conversation_id').value = String(id);
    for (const node of $$('#ab-chat, #ab-messages')) node.dataset.conversation = String(id);
    form.dispatchEvent(new CustomEvent('ab:conversation', { bubbles: true, detail: { id } }));
  }
  /** Runs a change to the transcript and keeps the bottom in view unless the user scrolled up. */
  function withScroll(mutate: () => void): void {
    const list = messages();
    const s = /auto|scroll/.test(getComputedStyle(list).overflowY) ? list : (document.scrollingElement ?? document.documentElement);
    const stuck = s.scrollHeight - s.scrollTop - s.clientHeight < 48;
    mutate();
    if (stuck) s.scrollTop = s.scrollHeight;
  }
  function append(node: HTMLElement): void {
    withScroll(() => {
      $('.ab-welcome', messages())?.remove();
      messages().append(node);
    });
  }

  async function send(): Promise<void> {
    const text = textarea.value.trim();
    const image = field('images').value;
    const images = image ? [image] : [];
    if (busy || expired || !navigator.onLine || (text === '' && image === '')) return;
    busy = true;
    sendButton.disabled = true;
    notice('info', '');
    textarea.value = '';
    grow(textarea);
    setImage(form, '');
    textarea.focus();
    const draft = { text, image };
    let user: HTMLElement | null = null;
    let assistant: HTMLElement | null = null;
    // Whether the turn reached the model. Until it does, what was typed still belongs to the
    // composer: every exit before that point has to take both bubbles back out of the
    // transcript and put the text and the image back in the box, or they are gone with no
    // record of the turn anywhere. Done once in the finally rather than at each `return`,
    // because the exits kept forgetting one of the two -- the stream redemption that comes
    // back as something other than an event stream (StreamBudget's 429, an expired or replayed
    // ticket) took the assistant bubble out and left the typed message nowhere, while a
    // fetch() that rejects after the assistant bubble is appended did the opposite and left an
    // empty streaming bubble in the transcript. One place that runs on every exit is the only
    // shape that cannot forget half of it.
    let sent = false;
    try {
      const [userRes, emptyRes] = await Promise.all([
        request('POST', api('/view/bubble'), { role: 'user', content: text, images }),
        request('GET', api('/view/bubble', { role: 'assistant', streaming: '1' })),
      ]);
      const bad = userRes.ok ? (emptyRes.ok ? null : emptyRes) : userRes;
      if (bad) return refused(bad.status, await restError(bad));
      user = fromHtml(await userRes.text());
      if (user) append(user);
      const ticketRes = await request('POST', api('/chat'), {
        message: text, conversation_id: asId(field('conversation_id').value) ?? 0, model: field('model').value, images, context: { post_id: asId(field('context[post_id]').value) ?? 0 }, stream: true,
      });
      if (!ticketRes.ok) return refused(ticketRes.status, await restError(ticketRes));
      const ticket = await ticketRes.json() as { stream_url: string };
      const bubble = fromHtml(await emptyRes.text());
      if (!bubble) throw new Error('No streaming bubble.');
      append(bubble);
      assistant = bubble;
      const redeemed = await redeem(ticket.stream_url, cfg.nonce);
      if (!redeemed.ok) return refused(redeemed.status, redeemed.error);
      // From here the turn is the server's: a stream that then drops mid-reply is stored as a
      // partial reply, and the composer must not offer the message back as if nothing ran.
      sent = true;
      await consume(redeemed.stream, bubble);
    } catch (e) {
      console.error(e);
      notice('error', t('failed'));
    } finally {
      if (!sent) restoreDraft(form, draft, assistant, user);
      busy = false;
      if (!expired && navigator.onLine) sendButton.disabled = false;
      textarea.focus();
    }
  }

  /** Reads one turn's frames into the streaming bubble (docs/api.md section 4). */
  async function consume(res: Response, bubble: HTMLElement): Promise<void> {
    const content = $('.ab-msg__content', bubble) as HTMLElement;
    let reasoning: HTMLElement | null = null;
    let answered = false;
    try {
      for await (const { event, data } of readSse(res)) {
        const d = data as Json;
        if (event === 'start') {
          setConversation(d.conversation_id);
        } else if (event === 'delta') {
          withScroll(() => {
            if (typeof d.reasoning === 'string' && d.reasoning !== '') {
              if (!reasoning) {
                reasoning = el('div', { class: 'ab-msg__reasoning-text' });
                content.append(el('details', { class: 'ab-msg__reasoning', open: '' }, el('summary', {}, icon('brain'), ' ' + t('thinking')), reasoning));
              }
              reasoning.append(d.reasoning);
            }
            if (typeof d.text === 'string' && d.text !== '') {
              if (!answered && reasoning) (reasoning.parentElement as HTMLDetailsElement).open = false;
              answered = true;
              appendText(content, d.text, d.held === true, t('callingTool'));
            }
          });
        } else if (event === 'done') {
          return finish(d, bubble);
        } else if (event === 'error') {
          notice('error', typeof d.message === 'string' && d.message ? d.message : t('failed'));
          return settle(bubble, answered);
        }
      }
      notice('error', t('failed'));
    } catch (e) {
      // The connection dropped mid-stream (the server stores what it sent as a partial reply).
      settle(bubble, answered);
      throw e;
    }
    settle(bubble, answered);
  }
  /** A stream that ended without `done`: keep what arrived as a partial reply, drop an empty bubble. */
  function settle(bubble: HTMLElement, answered: boolean): void {
    if (answered) partial(bubble);
    else bubble.remove();
  }
  /** Leaves the streamed text as it is for good: still pre-wrapped (data-partial), without the caret (data-streaming) and no longer announced (aria-live). */
  function partial(bubble: HTMLElement): void {
    // The turn is over and nothing will replace this bubble: what was held is shown as it came.
    releaseHeld(bubble);
    delete bubble.dataset.streaming;
    bubble.dataset.partial = '1';
    $('.ab-msg__content', bubble)?.removeAttribute('aria-live');
  }
  /** Swaps the streamed text for the server's rendering of the finished reply and reloads the history. */
  async function finish(d: Json, bubble: HTMLElement): Promise<void> {
    const m = (d.message ?? {}) as Json;
    const receipt = (d.receipt ?? {}) as Json;
    setConversation(d.conversation_id);
    const meta = (m.meta ?? {}) as Json;
    // The receipt's tool badge counts the calls the reply recorded (message.meta.tool_calls).
    const res = await request('POST', api('/view/bubble'), { role: 'assistant', content: m.content ?? '', model: m.model ?? '', usage: m.usage ?? null, duration_ms: receipt.duration_ms ?? 0, tool_calls: Array.isArray(meta.tool_calls) ? meta.tool_calls : [] });
    const rendered = res.ok ? fromHtml(await res.text()) : null;
    if (!res.ok) refused(res.status, await restError(res));
    withScroll(() => {
      if (rendered) {
        bubble.replaceWith(rendered);
        decorate(rendered, t('copyCode'));
      } else {
        partial(bubble);
      }
    });
    window.htmx?.trigger(document.body, 'ab:refresh');
  }

  // ---- composer -------------------------------------------------------------------------

  /** The media library picker; the chosen image goes into the hidden field as a data URL, which is what POST /chat takes. */
  function pickImage(): void {
    const media = window.wp?.media;
    if (!media) return;
    const frame = media({ title: t('imageTitle'), button: { text: t('imageButton') }, multiple: false, library: { type: 'image' } });
    frame.on('select', () => {
      const a = frame.state().get('selection').first().toJSON();
      void attach(a.sizes?.large?.url ?? a.url);
    });
    frame.open();
  }
  /**
   * An image past the cap is refused with a message that names its size and the site's limit,
   * not a bare failure. The limit is the site's own where the payload carries one
   * (Assets::maxImageBytes()), else image.ts's constant. The server holds the turn's images to
   * that figure as a total (Pipeline::images()), so the picked image is checked beside the ones
   * already on the turn: the composer holds one image today and a new pick replaces it, so that
   * list is empty here, but the check is written for the list so a multi-image composer cannot
   * assemble a turn the server then refuses. The total's refusal has its own message.
   */
  async function attach(url: string): Promise<void> {
    const max = imageLimit(cfg.maxImageBytes);
    const attached: string[] = [];
    try {
      setImage(form, await fetchDataUrl(url, max, attached));
    } catch (e) {
      const sized = (key: string, err: ImageTooLarge): string => t(key).replace('{size}', formatBytes(err.size)).replace('{max}', formatBytes(err.max, 'down'));
      notice('error', e instanceof ImagesTooLarge ? sized('imagesTooLarge', e) : e instanceof ImageTooLarge ? sized('imageTooLarge', e) : t('failed'));
    }
  }
  async function copy(button: HTMLElement, text: string): Promise<void> {
    if (!(await writeClipboard(text))) {
      notice('error', t('copyFailed'));
      return;
    }
    const label = button.getAttribute('aria-label') ?? '';
    $('svg', button)?.replaceWith(icon('check'));
    button.setAttribute('aria-label', t('copied'));
    setTimeout(() => { $('svg', button)?.replaceWith(icon('copy')); button.setAttribute('aria-label', label); }, 1500);
  }
  /** The async clipboard first; where it is denied (no permission, an older browser), the selection command. */
  async function writeClipboard(text: string): Promise<boolean> {
    try {
      await navigator.clipboard.writeText(text);
      return true;
    } catch {
      const scratch = el('textarea', { readonly: '', 'aria-hidden': 'true', style: 'position:fixed;top:0;left:0;opacity:0' });
      scratch.value = text;
      document.body.append(scratch);
      scratch.select();
      let ok = false;
      try { ok = document.execCommand('copy'); } catch { /* unsupported */ }
      scratch.remove();
      textarea.focus();
      return ok;
    }
  }
  function connectivity(): void {
    if (navigator.onLine) {
      // The offline notice took the status line; hand it back to the session-expired notice if that is what it displaced.
      if (offlineShown) notice(expired ? 'error' : 'info', expired ? t('sessionExpired') : '');
      offlineShown = false;
      if (!busy && !expired) sendButton.disabled = false;
    } else {
      notice('warning', cfg.offline, 'wifi-off');
      offlineShown = true;
      sendButton.disabled = true;
    }
  }

  // ---- wiring ---------------------------------------------------------------------------

  form.noValidate = true; // an images-only turn is a turn; send() has its own empty check
  form.addEventListener('submit', (e) => { e.preventDefault(); void send(); });
  textarea.addEventListener('input', () => grow(textarea));
  textarea.addEventListener('keydown', (e) => {
    if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) { e.preventDefault(); void send(); }
    else if (e.key === 'Escape') { textarea.value = ''; grow(textarea); }
  });
  document.addEventListener('click', (e) => {
    const button = (e.target as Element).closest<HTMLElement>('[data-action]');
    if (!button) return;
    const answersCopy = button.dataset.action === 'copy-code' && button.closest('.alpaca-bot-answer') !== null;
    if (!shell.contains(button) && !answersCopy) return;
    const turn = button.closest('.ab-msg');
    switch (button.dataset.action) {
      case 'copy': void copy(button, turn ? ($('.ab-msg__content', turn)?.innerText ?? '') : ''); break;
      case 'copy-code': void copy(button, $('code', button.closest('pre') ?? button)?.textContent ?? ''); break;
      case 'edit': textarea.value = turn ? ($('.ab-msg__content', turn)?.innerText ?? '') : ''; grow(textarea); textarea.focus(); break;
      case 'image': pickImage(); break;
      case 'image-remove': setImage(form, ''); break;
    }
  });
  document.addEventListener('change', (e) => {
    const target = e.target as HTMLSelectElement;
    if (target.id === 'ab-model') field('model').value = target.value;
  });
  document.body.addEventListener('htmx:configRequest', (e) => {
    const ev = e as CustomEvent<HtmxDetail>;
    const target = ev.target as HTMLElement;
    if (!ours(target)) return;
    ev.detail.headers['X-WP-Nonce'] = cfg.nonce;
    if (target.id !== 'ab-history-select') return;
    // HistorySelect asks for /messages/0; the chosen option's data-id replaces the 0 here.
    const id = (target as HTMLSelectElement).selectedOptions[0]?.dataset.id ?? '0';
    if (id === '0') {
      ev.preventDefault();
      // "New chat" is a page to go to on the chat screen and something a host may do in place
      // (the drawer swaps a fresh transcript in), so the host is asked first.
      if (form.dispatchEvent(new CustomEvent('ab:new-chat', { bubbles: true, cancelable: true }))) {
        location.assign($<HTMLAnchorElement>('.page-title-action', shell)?.href ?? location.href);
      }
      return;
    }
    ev.detail.path = ev.detail.path.replace(/(messages(?:\/|%2F))0(?=$|[?&#])/i, '$1' + id);
  });
  document.body.addEventListener('htmx:afterSwap', (e) => {
    if (!ours(e.target)) return;
    const list = $('#ab-messages');
    if (list) setConversation(list.dataset.conversation);
    decorateOwn();
  });
  document.body.addEventListener('htmx:responseError', (e) => {
    if (!ours(e.target)) return;
    const xhr = (e as CustomEvent<HtmxDetail>).detail.xhr;
    void restError(null, xhr?.responseText ?? '').then((error) => refused(xhr?.status ?? 0, error));
  });
  window.addEventListener('online', connectivity);
  window.addEventListener('offline', connectivity);
  watchNonce((nonce) => { cfg.nonce = nonce; });

  decorateOwn();
  connectivity();
  grow(textarea);
  textarea.focus();
}
