import { test, type TestContext } from 'node:test';
import assert from 'node:assert/strict';
import { installDom, until } from './env.ts';

/**
 * The units resources/ts/boot.ts is built from, and one whole refused turn through boot() itself
 * — the exit the first case of tests/e2e/composer.spec.ts was written for, a stream redemption
 * that answers with something other than an event stream, driven here through the modules rather
 * than the bundle (Kanboard #4334). The Playwright file stays: it drives the built bundle in a
 * real browser, and this drives the modules it is built from, which is the level at which a unit
 * can be given a case of its own.
 *
 * The markup is composer.spec.ts's fixture without its two <script> tags: the part of
 * View\Chat\Shell that send() reads, for the reason that file gives (a fixture reproducing the
 * whole shell would be a copy of it to keep in step).
 */
const REST = 'https://alpaca-bot.test/wp-json/alpaca-bot/v1';
const IMAGE = 'data:image/png;base64,iVBORw0KGgo=';
const CFG = { rest: REST, nonce: 'n', offline: 'offline', i18n: { failed: 'The request failed. Try again.', thinking: 'Thinking...', notSent: 'Your message was not sent. Its text: {text}', notSentImage: 'The image attached to it was not sent. Attach it again to retry.', dismiss: 'Dismiss' } };
const SHELL = `<div class="ab-wrap">
  <div id="ab-chat" data-conversation="0">
    <div id="ab-status" class="ab-status" role="status" aria-live="polite"></div>
    <div id="ab-messages" class="ab-messages" data-conversation="0"></div>
  </div>
  <form id="ab-form" class="ab-composer">
    <input type="hidden" name="conversation_id" value="0">
    <input type="hidden" name="model" value="llama3.2">
    <input type="hidden" name="images" value="">
    <textarea id="ab-message" name="message" rows="1"></textarea>
    <div class="ab-composer__buttons">
      <button type="button" data-action="image">image</button>
      <button type="button" data-action="image-remove" hidden>remove</button>
      <button type="button" data-action="send">send</button>
    </div>
  </form>
</div>`;

test('restoreDraft takes a turn that never ran back out of the transcript and puts both halves back in the box', async () => {
  installDom(SHELL);
  const { restoreDraft } = await import('../../resources/ts/composer.ts');
  const form = document.querySelector('#ab-form') as HTMLFormElement;
  const list = document.querySelector('#ab-messages') as HTMLElement;
  const user = document.createElement('article');
  const assistant = document.createElement('article');
  list.append(user, assistant);

  restoreDraft(form, { text: 'kept', image: IMAGE }, assistant, user, null);

  assert.equal((form.querySelector('#ab-message') as HTMLTextAreaElement).value, 'kept');
  assert.equal((form.elements.namedItem('images') as HTMLInputElement).value, IMAGE);
  assert.equal(list.children.length, 0);
  // The image buttons follow the field: the picker hides while an image is attached.
  assert.equal((form.querySelector('[data-action="image"]') as HTMLElement).hidden, true);
  assert.equal((form.querySelector('[data-action="image-remove"]') as HTMLElement).hidden, false);
  assert.equal(form.querySelectorAll('.ab-composer__preview').length, 1);
});

test('refusal names a stale nonce as an expired session, and otherwise shows the server its own words', async () => {
  const { refusal } = await import('../../resources/ts/refusal.ts');
  const t = (key: string): string => `[${key}]`;
  assert.deepEqual(refusal(403, { code: 'rest_cookie_invalid_nonce', message: 'Cookie check failed' }, t), { text: '[sessionExpired]', expired: true });
  assert.deepEqual(refusal(429, { code: 'alpaca_bot_rate_limited', message: 'Too many requests. Try again shortly.' }, t), { text: 'Too many requests. Try again shortly.', expired: false });
  assert.deepEqual(refusal(502, {}, t), { text: '[failed]', expired: false });
});

test('redeem hands back an event stream, and anything else as its status and its JSON body', async () => {
  const { redeem } = await import('../../resources/ts/redeem.ts');
  const stream = new Response('event: start\ndata: {}\n\n', { headers: { 'content-type': 'text/event-stream; charset=utf-8' } });

  const ok = await redeem(`${REST}/chat/1/stream?token=t`, 'n', async () => stream);
  assert.deepEqual(ok, { ok: true, stream });

  // The 429 this route answers is StreamBudget's concurrency refusal; the per-minute bucket is
  // spent on the POST that issued the ticket, not here (docs/api.md section 5).
  const refused = await redeem(`${REST}/chat/1/stream?token=t`, 'n', async () => Response.json({ code: 'alpaca_bot_stream_concurrency', message: 'You already have 3 streams open.' }, { status: 429 }));
  assert.deepEqual(refused, { ok: false, status: 429, error: { code: 'alpaca_bot_stream_concurrency', message: 'You already have 3 streams open.' } });

  // The cookie session and the nonce are the whole credential (docs/api.md section 2).
  let init: RequestInit | undefined;
  await redeem(`${REST}/chat/1/stream?token=t`, 'n2', async (_url, sent) => { init = sent; return stream; });
  assert.deepEqual(init, { credentials: 'same-origin', headers: { 'X-WP-Nonce': 'n2' } });

  // A body that is not JSON at all is an empty error, not a throw.
  const html = await redeem(`${REST}/chat/1/stream?token=t`, 'n', async () => new Response('<html>502</html>', { status: 502, headers: { 'content-type': 'text/html' } }));
  assert.deepEqual(html, { ok: false, status: 502, error: {} });
});

/**
 * Boots against SHELL with every request answered from here, the stream redemption answered with
 * `refusal`, and a turn sent the way a submit would: composer.spec.ts's shell() and send(), one
 * layer down. `seen` collects the paths asked for, so a case can say what did and did not happen.
 */
async function refusedRedemption(t: TestContext, message: string, refusal: { status: number; body: object }): Promise<{ form: HTMLFormElement; seen: string[] }> {
  const win = installDom(SHELL);
  const real = globalThis.fetch;
  t.after(() => { globalThis.fetch = real; });
  const seen: string[] = [];
  globalThis.fetch = (async (input: RequestInfo | URL, init?: RequestInit) => {
    const url = new URL(String(input));
    const method = init?.method ?? 'GET';
    seen.push(`${method} ${url.pathname}`);
    if (url.pathname.endsWith('/view/bubble')) {
      const role = method === 'POST' ? 'user' : 'assistant';
      return new Response(`<article class="ab-msg ab-msg--${role}"><div class="ab-msg__content">${role}</div></article>`, { headers: { 'content-type': 'text/html' } });
    }
    if (url.pathname.endsWith('/alpaca-bot/v1/chat')) return Response.json({ stream_url: `${REST}/chat/1/stream?token=t` });
    if (url.pathname.includes('/chat/1/stream')) return Response.json(refusal.body, { status: refusal.status });
    return new Response('', { status: 404 });
  }) as typeof fetch;

  const { boot } = await import('../../resources/ts/boot.ts');
  const form = document.querySelector('#ab-form') as HTMLFormElement;
  boot(CFG, form);
  (form.querySelector('#ab-message') as HTMLTextAreaElement).value = message;
  (form.elements.namedItem('images') as HTMLInputElement).value = IMAGE;
  form.dispatchEvent(new win.Event('submit', { cancelable: true }) as unknown as Event);
  return { form, seen };
}

test('a stream redemption that is not an event stream gives the message and the image back and leaves no turn in the transcript', async (t) => {
  const { form, seen } = await refusedRedemption(t, 'the message that must survive', {
    status: 403,
    body: { code: 'rest_forbidden', message: 'Invalid or expired stream token.', data: { status: 403 } },
  });

  await until(() => (document.querySelector('#ab-status')?.textContent ?? '').includes('Invalid or expired stream token'));
  // The composer has its content back, both halves...
  assert.equal((form.querySelector('#ab-message') as HTMLTextAreaElement).value, 'the message that must survive');
  assert.equal((form.elements.namedItem('images') as HTMLInputElement).value, IMAGE);
  // ...and the transcript shows no turn at all: neither the user bubble nor the empty streaming one.
  assert.equal(document.querySelectorAll('#ab-messages article').length, 0);
  // The redemption really was attempted, so this is the refusal path and not an earlier exit.
  assert.equal(seen.filter((path) => path.includes('/chat/1/stream')).length, 1);
  // The composer is usable again: a refused redemption is not the locked state a stale nonce is.
  assert.equal((form.querySelector('[data-action="send"]') as HTMLButtonElement).disabled, false);
  // A spent ticket is a failure, and is shown as one.
  assert.equal(document.querySelectorAll('#ab-status .notice-error').length, 1);
});

test('a redemption refused for concurrency is shown as a warning, not an error, because the ticket survives it', async (t) => {
  // The one refusal boot.ts colours differently: StreamBudget spends nothing on this 429, so it
  // is "not yet" rather than a failure and says so in the warning colour (Kanboard #4333). This
  // is the only assertion on the level anywhere under node:test, and the decision it pins —
  // boot.ts's `refused()` reading the code — is the one line of logic this task wrote.
  const { form } = await refusedRedemption(t, 'the message that waits its turn', {
    status: 429,
    body: { code: 'alpaca_bot_stream_concurrency', message: 'You already have 3 streams open. Wait for one to finish, then send again.', data: { status: 429, retry_after: 720, limit: 3 } },
  });

  await until(() => (document.querySelector('#ab-status')?.textContent ?? '').includes('You already have 3 streams open'));
  // notice() replaces the status region's children, so this one node is the whole of what is
  // shown: asserting it is warning says it is not also error, and a second assertion for that
  // could not fail on its own.
  assert.equal(document.querySelectorAll('#ab-status .notice-warning').length, 1);
  // The draft still comes back and the transcript is still clean: the colour is the only difference.
  assert.equal((form.querySelector('#ab-message') as HTMLTextAreaElement).value, 'the message that waits its turn');
  assert.equal(document.querySelectorAll('#ab-messages article').length, 0);
});

/**
 * SHELL with the two context chips View\Chat\Composer renders above the box when it has a post
 * and a screen: the drawer's composer on the classic editor.
 */
const SHELL_WITH_CHIPS = SHELL.replace('<textarea', `<div class="ab-composer__chips">
      <span class="ab-chip" data-chip="post"><input type="hidden" name="context[post_id]" value="12"><span class="ab-chip__label">Editing: Hello</span><button type="button" class="ab-chip__remove" data-action="chip-remove">x</button></span>
      <span class="ab-chip" data-chip="screen"><input type="hidden" name="context[screen][id]" value="post"><input type="hidden" name="context[screen][title]" value="Edit Post"><span class="ab-chip__label">On: Edit Post</span><button type="button" class="ab-chip__remove" data-action="chip-remove">x</button></span>
    </div>
    <textarea`);

/**
 * SHELL_WITH_CHIPS with the box in View\Chat\Composer's `.ab-composer__row`, which is where
 * mount.ts newChat() puts the fresh chips row (before it); without the row it swaps no chips.
 */
const SHELL_WITH_CHIPS_IN_ROW = SHELL_WITH_CHIPS.replace('<textarea', '<div class="ab-composer__row"><textarea').replace('</textarea>', '</textarea></div>');

/** The composer of the fake GET /view/panel's fragment: the server's chips, both of them, as a fresh render has them. */
const PANEL_CHIPS = `<form id="ab-form">${SHELL_WITH_CHIPS.slice(SHELL_WITH_CHIPS.indexOf('<div class="ab-composer__chips">'), SHELL_WITH_CHIPS.indexOf('<textarea'))}</form>`;

/** Boots against `shell`, answers the ticket request from here and records its body, and sends `message`. */
async function sentContext(t: TestContext, shell: string, before: (form: HTMLFormElement, win: ReturnType<typeof installDom>) => void): Promise<{ form: HTMLFormElement; body: Record<string, unknown> }> {
  const win = installDom(shell);
  const real = globalThis.fetch;
  t.after(() => { globalThis.fetch = real; });
  let body: Record<string, unknown> = {};
  globalThis.fetch = (async (input: RequestInfo | URL, init?: RequestInit) => {
    const url = new URL(String(input));
    if (url.pathname.endsWith('/view/bubble')) return new Response('<article class="ab-msg"><div class="ab-msg__content"></div></article>', { headers: { 'content-type': 'text/html' } });
    if (url.pathname.endsWith('/alpaca-bot/v1/chat')) {
      body = JSON.parse(String(init?.body)) as Record<string, unknown>;
      return Response.json({ stream_url: `${REST}/chat/1/stream?token=t` });
    }
    return Response.json({ code: 'alpaca_bot_rate_limited', message: 'Too many requests.' }, { status: 429 });
  }) as typeof fetch;

  const { boot } = await import('../../resources/ts/boot.ts');
  const form = document.querySelector('#ab-form') as HTMLFormElement;
  boot(CFG, form);
  before(form, win);
  (form.querySelector('#ab-message') as HTMLTextAreaElement).value = 'tighten this';
  form.dispatchEvent(new win.Event('submit', { cancelable: true }) as unknown as Event);
  await until(() => Object.keys(body).length > 0);
  return { form, body };
}

test('a turn sends the context its chips name', async (t) => {
  const { body } = await sentContext(t, SHELL_WITH_CHIPS, () => {});
  assert.deepEqual(body.context, { post_id: 12, screen: { id: 'post', title: 'Edit Post' } });
});

test('a chip removed before sending is not on the next turn, and the box has the focus back', async (t) => {
  const { form, body } = await sentContext(t, SHELL_WITH_CHIPS, (f, win) => {
    (f.querySelector('#ab-message') as HTMLTextAreaElement).blur();
    (f.querySelector('[data-chip="screen"] [data-action="chip-remove"]') as HTMLElement).dispatchEvent(new win.MouseEvent('click', { bubbles: true }) as unknown as MouseEvent);
    assert.equal(document.activeElement?.id, 'ab-message');
  });
  assert.deepEqual(body.context, { post_id: 12 });
  assert.equal(form.querySelectorAll('.ab-chip').length, 1);
  assert.equal(form.querySelectorAll('[data-chip="post"]').length, 1);
});

test('a composer with no chips sends an empty context, not a post of 0', async (t) => {
  const { body } = await sentContext(t, SHELL, () => {});
  assert.deepEqual(body.context, {});
});

test('taking the last chip off takes the chips row with it, and not before', async () => {
  const win = installDom(SHELL_WITH_CHIPS);
  const { boot } = await import('../../resources/ts/boot.ts');
  const form = document.querySelector('#ab-form') as HTMLFormElement;
  boot(CFG, form);
  const remove = (chip: string): void => {
    (form.querySelector(`[data-chip="${chip}"] [data-action="chip-remove"]`) as HTMLElement).dispatchEvent(new win.MouseEvent('click', { bubbles: true }) as unknown as MouseEvent);
  };
  remove('post');
  assert.equal(form.querySelectorAll('.ab-chip').length, 1);
  assert.equal(form.querySelectorAll('.ab-composer__chips').length, 1);
  remove('screen');
  assert.equal(form.querySelectorAll('.ab-chip').length, 0);
  assert.equal(form.querySelectorAll('.ab-composer__chips').length, 0);
});

/**
 * A turn whose stream this case feeds by hand, so a conversation switch can land between its
 * frames (Kanboard #4525). Every request is answered from here: the streaming bubble, the
 * finished reply's rendering (marked `.ab-rendered`, so a case can count whether one was swapped
 * in), the ticket, the stream itself, and GET /view/panel for mount.ts newChat(), whose fresh
 * transcript is on conversation 0. The two bubble requests are answered once `hold.bubbles`
 * settles and the ticket once `hold.ticket` does, so a case can switch while either is
 * outstanding; this returns once what is held has been asked for (the bubbles when they are
 * held, else the ticket). `window.htmx` is a stub that records what boot() triggers.
 * `ids` collects every `ab:conversation` the form announces, in order, and `chat()` is the
 * POST /chat body, once there is one. `composer.shell` replaces SHELL, and `composer.before` runs
 * against the booted form before the message is sent (a chip taken off, say). The fake panel's
 * composer has both context chips, which newChat() swaps in only for a shell whose form has an
 * `.ab-composer__row` (SHELL has none, so its cases see no chips from it).
 */
async function heldTurn(t: TestContext, conversation: string, hold: { ticket?: Promise<void>; bubbles?: Promise<void> } = {}, composer: { shell?: string; before?: (form: HTMLFormElement) => void } = {}): Promise<{
  form: HTMLFormElement;
  chat: () => Record<string, unknown> | null;
  push: (event: string, data: object) => void;
  close: () => void;
  drop: () => void;
  ids: number[];
  seen: string[];
  triggers: string[];
  finished: () => Promise<void>;
}> {
  const win = installDom((composer.shell ?? SHELL).replaceAll('data-conversation="0"', `data-conversation="${conversation}"`).replace('name="conversation_id" value="0"', `name="conversation_id" value="${conversation}"`));
  const real = globalThis.fetch;
  t.after(() => { globalThis.fetch = real; });
  let controller!: ReadableStreamDefaultController<Uint8Array>;
  const body = new ReadableStream<Uint8Array>({ start(c) { controller = c; } });
  const encoder = new TextEncoder();
  const seen: string[] = [];
  let chat: Record<string, unknown> | null = null;
  globalThis.fetch = (async (input: RequestInfo | URL, init?: RequestInit) => {
    const url = new URL(String(input));
    const method = init?.method ?? 'GET';
    seen.push(`${method} ${url.pathname}`);
    const html = (markup: string): Response => new Response(markup, { headers: { 'content-type': 'text/html' } });
    if (url.pathname.endsWith('/view/bubble')) {
      // Only the turn's own two: finish()'s rendering of the reply comes after them.
      if (chat === null) await hold.bubbles;
      if (method === 'GET') return html('<article class="ab-msg ab-msg--assistant" data-streaming="1"><div class="ab-msg__content"></div></article>');
      const role = (JSON.parse(String(init?.body)) as { role: string }).role;
      return html(`<article class="ab-msg ab-msg--${role}${role === 'assistant' ? ' ab-rendered' : ''}"><div class="ab-msg__content">${role}</div></article>`);
    }
    if (url.pathname.endsWith('/view/panel')) {
      return html(`<div><div id="ab-history"><select id="ab-history-select"><option data-id="0">New chat</option></select></div><div id="ab-messages" class="ab-messages" data-conversation="0"></div>${PANEL_CHIPS}</div>`);
    }
    if (url.pathname.endsWith('/alpaca-bot/v1/chat')) {
      chat = JSON.parse(String(init?.body)) as Record<string, unknown>;
      await hold.ticket;
      return Response.json({ stream_url: `${REST}/chat/1/stream?token=t` });
    }
    if (url.pathname.includes('/chat/1/stream')) return new Response(body, { headers: { 'content-type': 'text/event-stream; charset=utf-8' } });
    return new Response('', { status: 404 });
  }) as typeof fetch;
  const triggers: string[] = [];
  (win as unknown as Record<string, unknown>).htmx = { trigger: (_: Element, name: string) => { triggers.push(name); }, ajax: async () => {}, process: () => {} };

  const { boot } = await import('../../resources/ts/boot.ts');
  const form = document.querySelector('#ab-form') as HTMLFormElement;
  const ids: number[] = [];
  form.addEventListener('ab:conversation', (e) => { ids.push((e as CustomEvent<{ id: number }>).detail.id); });
  boot(CFG, form);
  composer.before?.(form);
  const send = form.querySelector('[data-action="send"]') as HTMLButtonElement;
  (form.querySelector('#ab-message') as HTMLTextAreaElement).value = 'hello';
  form.dispatchEvent(new win.Event('submit', { cancelable: true }) as unknown as Event);
  await until(() => hold.bubbles
    ? seen.filter((path) => path.endsWith('/view/bubble')).length === 2
    : seen.includes('POST /wp-json/alpaca-bot/v1/chat'));
  return {
    form,
    chat: () => chat,
    push: (event, data) => controller.enqueue(encoder.encode(`event: ${event}\ndata: ${JSON.stringify(data)}\n\n`)),
    close: () => controller.close(),
    // The connection failing mid-stream: the body's read rejects.
    drop: () => controller.error(new TypeError('network error')),
    ids,
    seen,
    triggers,
    // send()'s finally gives the send button back once the turn is over, whichever way it ended.
    finished: () => until(() => !send.disabled),
  };
}

/** The history select's switch on the chat screen: htmx swaps #ab-messages whole (outerHTML) and fires htmx:afterSwap. */
function historySwap(win: typeof globalThis, id: string): void {
  const next = document.createElement('div');
  next.id = 'ab-messages';
  next.className = 'ab-messages';
  next.dataset.conversation = id;
  next.innerHTML = `<article class="ab-msg ab-msg--user"><div class="ab-msg__content">conversation ${id}</div></article>`;
  (document.querySelector('#ab-messages') as HTMLElement).replaceWith(next);
  next.dispatchEvent(new win.CustomEvent('htmx:afterSwap', { bubbles: true }));
}

/** mount.ts newChat() against the fake GET /view/panel: the drawer's "New chat". */
async function drawerNewChat(): Promise<void> {
  const { newChat } = await import('../../resources/ts/mount.ts');
  const host = document.querySelector('.ab-wrap') as HTMLElement;
  assert.equal(await newChat(host, { panel: `${REST}/view/panel`, prefs: '', htmx: '', htmxId: '', chat: '', css: '', title: '', failed: '' }, 'n', {}), true);
}

const field = (form: HTMLFormElement): string => (form.elements.namedItem('conversation_id') as HTMLInputElement).value;

test('a new chat\'s first turn records the id its start frame names, and its reply replaces the streaming bubble', async (t) => {
  const turn = await heldTurn(t, '0');
  turn.push('start', { conversation_id: 42 });
  turn.push('done', { conversation_id: 42, message: { content: 'hi', model: 'llama3.2' }, receipt: {} });
  turn.close();
  await turn.finished();

  assert.equal(field(turn.form), '42');
  assert.equal((document.querySelector('#ab-messages') as HTMLElement).dataset.conversation, '42');
  assert.equal((document.querySelector('#ab-chat') as HTMLElement).dataset.conversation, '42');
  assert.deepEqual(turn.ids, [42, 42]);
  assert.equal(document.querySelectorAll('#ab-messages .ab-rendered').length, 1);
  assert.equal(document.querySelectorAll('#ab-messages [data-streaming]').length, 0);
  assert.deepEqual(turn.triggers, ['ab:refresh']);
});

test('switching conversation through the history select mid-turn: the turn\'s done frame does not take the field back (Kanboard #4525)', async (t) => {
  const turn = await heldTurn(t, '7');
  turn.push('start', { conversation_id: 7 });
  await until(() => turn.ids.length === 1);
  historySwap(globalThis, '9');
  assert.equal(field(turn.form), '9');
  turn.push('done', { conversation_id: 7, message: { content: 'the reply to 7', model: 'llama3.2' }, receipt: {} });
  turn.close();
  await turn.finished();

  // The field, the data attributes and the host all stay on the conversation now shown.
  assert.equal(field(turn.form), '9');
  assert.equal((document.querySelector('#ab-messages') as HTMLElement).dataset.conversation, '9');
  assert.equal((document.querySelector('#ab-chat') as HTMLElement).dataset.conversation, '9');
  assert.deepEqual(turn.ids, [7, 9]);
  // Conversation 7's reply is not rendered into conversation 9's transcript, nor asked for.
  assert.equal(document.querySelectorAll('.ab-msg--assistant').length, 0);
  assert.equal(turn.seen.filter((path) => path === 'POST /wp-json/alpaca-bot/v1/view/bubble').length, 1);
  // The history is still reloaded: conversation 7 has a new reply, and the reload asks with the field (9).
  assert.deepEqual(turn.triggers, ['ab:refresh']);
});

test('the drawer\'s New chat mid-turn: the done frame for the old conversation leaves the field on 0 (Kanboard #4525)', async (t) => {
  const turn = await heldTurn(t, '0');
  turn.push('start', { conversation_id: 42 });
  await until(() => turn.ids.length === 1);
  await drawerNewChat();
  assert.equal(field(turn.form), '0');
  turn.push('done', { conversation_id: 42, message: { content: 'the reply to 42', model: 'llama3.2' }, receipt: {} });
  turn.close();
  await turn.finished();

  assert.equal(field(turn.form), '0');
  assert.equal((document.querySelector('#ab-chat') as HTMLElement).dataset.conversation, '0');
  assert.deepEqual(turn.ids, [42]);
  assert.equal(document.querySelectorAll('.ab-msg--assistant').length, 0);
});

test('the drawer\'s New chat before the start frame: a new chat left for another new chat does not take the first one\'s id', async (t) => {
  // Both conversations are "0" when the turn is sent and when the start frame arrives, so a guard
  // that compared ids would let this through; the transcript the turn was sent from is gone.
  const turn = await heldTurn(t, '0');
  await drawerNewChat();
  turn.push('start', { conversation_id: 42 });
  turn.push('done', { conversation_id: 42, message: { content: 'the reply to 42', model: 'llama3.2' }, receipt: {} });
  turn.close();
  await turn.finished();

  assert.equal(field(turn.form), '0');
  assert.deepEqual(turn.ids, []);
  assert.equal(document.querySelectorAll('.ab-msg--assistant').length, 0);
});

test('switching while the ticket is outstanding: the turn\'s streaming bubble stays out of the transcript now shown', async (t) => {
  // The streaming bubble is appended after the ticket answers, which is after the switch here; it
  // belongs to the transcript the turn was sent from, not the one on screen, where nothing would
  // ever finish it. The user bubble was appended before the ticket was asked for, so it left with
  // the old transcript; the case that switches before it arrives is the next one.
  let answer!: () => void;
  const turn = await heldTurn(t, '7', { ticket: new Promise<void>((resolve) => { answer = resolve; }) });
  historySwap(globalThis, '9');
  answer();
  await until(() => turn.seen.some((path) => path.includes('/chat/1/stream')));
  turn.push('start', { conversation_id: 7 });
  turn.push('done', { conversation_id: 7, message: { content: 'the reply to 7', model: 'llama3.2' }, receipt: {} });
  turn.close();
  await turn.finished();

  assert.equal(field(turn.form), '9');
  assert.deepEqual(turn.ids, [9]);
  // Only conversation 9's own message: not this turn's streaming bubble.
  assert.equal(document.querySelectorAll('#ab-messages .ab-msg').length, 1);
  assert.equal(document.querySelectorAll('[data-streaming]').length, 0);
});

/**
 * A switch while the turn's two bubble requests are outstanding, which is before the ticket is
 * asked for: the turn still belongs to the transcript it was sent from, so its ticket names that
 * transcript's conversation, not the one opened meanwhile (or 0, which would start a conversation
 * no transcript shows), and none of its bubbles lands in the transcript now shown.
 */
async function switchedBeforeTicket(t: TestContext, from: string, to: () => Promise<void> | void): Promise<Awaited<ReturnType<typeof heldTurn>>> {
  let answer!: () => void;
  const turn = await heldTurn(t, from, { bubbles: new Promise<void>((resolve) => { answer = resolve; }) });
  await to();
  answer();
  await until(() => turn.chat() !== null);
  turn.push('start', { conversation_id: Number(from) });
  turn.push('done', { conversation_id: Number(from), message: { content: 'the reply', model: 'llama3.2' }, receipt: {} });
  turn.close();
  await turn.finished();
  return turn;
}

test('switching through the history select before the ticket: the turn still goes to the conversation it was sent from', async (t) => {
  const turn = await switchedBeforeTicket(t, '7', () => historySwap(globalThis, '9'));
  assert.equal(turn.chat()?.conversation_id, 7);
  assert.equal(field(turn.form), '9');
  // Conversation 9's own message only: this turn's user bubble went to conversation 7's transcript.
  assert.equal(document.querySelectorAll('#ab-messages .ab-msg').length, 1);
  assert.equal(document.querySelectorAll('.ab-msg--assistant').length, 0);
  // The turn ran, so the box is not given the message back.
  assert.equal((turn.form.querySelector('#ab-message') as HTMLTextAreaElement).value, '');
});

test('the drawer\'s New chat before the ticket: the turn is not sent as a new conversation', async (t) => {
  const turn = await switchedBeforeTicket(t, '7', drawerNewChat);
  assert.equal(turn.chat()?.conversation_id, 7);
  assert.equal(field(turn.form), '0');
  assert.equal(document.querySelectorAll('#ab-messages .ab-msg').length, 0);
});

const status = (): string => (document.querySelector('#ab-status')?.textContent ?? '').trim();

/**
 * How a turn can end without `done`, each fed after the turn's `start`: an `error` frame, a
 * stream the server closed early, and a connection that failed mid-read.
 */
const endings: [string, (turn: Awaited<ReturnType<typeof heldTurn>>) => void][] = [
  ['an error frame', (turn) => { turn.push('error', { message: 'The model went away.' }); turn.close(); }],
  ['a stream closed without done', (turn) => turn.close()],
  ['a dropped connection', (turn) => turn.drop()],
];

for (const [ending, end] of endings) {
  test(`${ending} on the conversation still open is shown in the status line`, async (t) => {
    const turn = await heldTurn(t, '7');
    t.mock.method(console, 'error', () => {});
    turn.push('start', { conversation_id: 7 });
    await until(() => turn.ids.length === 1);
    end(turn);
    await turn.finished();
    assert.notEqual(status(), '');
    assert.equal(document.querySelectorAll('#ab-status .notice-error').length, 1);
  });

  test(`${ending} on a turn the user switched away from is not shown as the open conversation's error`, async (t) => {
    const turn = await heldTurn(t, '7');
    t.mock.method(console, 'error', () => {});
    turn.push('start', { conversation_id: 7 });
    await until(() => turn.ids.length === 1);
    historySwap(globalThis, '9');
    end(turn);
    await turn.finished();
    assert.equal(status(), '');
    assert.equal(field(turn.form), '9');
  });
}

/**
 * A turn that never ran gives its draft back only to the composer it came from, and only while
 * that composer is empty (Kanboard #4692). Otherwise what is in the box is the user's since, and
 * the draft's text goes into #ab-unsent, a slot beside the status line that no notice replaces,
 * until the user dismisses it.
 */
const unsent = (): string => (document.querySelector('#ab-unsent')?.textContent ?? '').trim();

test('a turn that never ran after a switch leaves the composer on screen alone, and its text is kept beside the status line', async (t) => {
  let fail!: (e: Error) => void;
  const turn = await heldTurn(t, '7', { ticket: new Promise<void>((_, reject) => { fail = reject; }) });
  t.mock.method(console, 'error', () => {});
  historySwap(globalThis, '9');
  fail(new TypeError('network error'));
  await turn.finished();
  assert.equal((turn.form.querySelector('#ab-message') as HTMLTextAreaElement).value, '');
  assert.equal(status(), 'The request failed. Try again.');
  assert.equal(unsent(), 'Your message was not sent. Its text: hello');
  assert.equal(field(turn.form), '9');
});

test('a turn that never ran does not overwrite what was typed since, in the same conversation either', async (t) => {
  let fail!: (e: Error) => void;
  const turn = await heldTurn(t, '7', { ticket: new Promise<void>((_, reject) => { fail = reject; }) });
  t.mock.method(console, 'error', () => {});
  (turn.form.querySelector('#ab-message') as HTMLTextAreaElement).value = 'a new thought';
  fail(new TypeError('network error'));
  await turn.finished();
  assert.equal((turn.form.querySelector('#ab-message') as HTMLTextAreaElement).value, 'a new thought');
  assert.equal(unsent(), 'Your message was not sent. Its text: hello');
  // Its user bubble still leaves the transcript: the turn never happened.
  assert.equal(document.querySelectorAll('#ab-messages .ab-msg').length, 0);
});

test('a turn that never ran does not overwrite an image picked since, and says its own image was not sent', async (t) => {
  const OTHER = 'data:image/png;base64,T1RIRVI=';
  const { setImage } = await import('../../resources/ts/composer.ts');
  let fail!: (e: Error) => void;
  const turn = await heldTurn(t, '7', { ticket: new Promise<void>((_, reject) => { fail = reject; }) }, { before: (form) => setImage(form, IMAGE) });
  t.mock.method(console, 'error', () => {});
  setImage(turn.form, OTHER);
  fail(new TypeError('network error'));
  await turn.finished();
  assert.equal((turn.form.elements.namedItem('images') as HTMLInputElement).value, OTHER);
  assert.equal((turn.form.querySelector('#ab-message') as HTMLTextAreaElement).value, '');
  assert.equal(unsent(), 'Your message was not sent. Its text: helloThe image attached to it was not sent. Attach it again to retry.');
});

/**
 * The drop that usually makes the ticket fail also fires `offline` and `online`, and connectivity()
 * rewrites the status line for each: the kept text is outside it, so it survives either order.
 */
for (const order of ['offline, then the ticket fails, then online', 'the ticket fails, then offline, then online']) {
  test(`a never-ran turn's kept text survives the connection dropping around it: ${order}`, async (t) => {
    let fail!: (e: Error) => void;
    const turn = await heldTurn(t, '7', { ticket: new Promise<void>((_, reject) => { fail = reject; }) });
    t.mock.method(console, 'error', () => {});
    let online = true;
    Object.defineProperty(navigator, 'onLine', { get: () => online, configurable: true });
    const go = (up: boolean): void => { online = up; window.dispatchEvent(new Event(up ? 'online' : 'offline')); };
    historySwap(globalThis, '9');
    if (order.startsWith('offline')) {
      go(false);
      fail(new TypeError('network error'));
      await until(() => unsent() !== '');
    } else {
      fail(new TypeError('network error'));
      await until(() => unsent() !== '');
      go(false);
      // The offline notice took the status line, which is what a note kept in it would lose.
      assert.equal(status(), 'offline');
    }
    go(true);
    await turn.finished();
    assert.equal(status(), '');
    assert.equal(unsent(), 'Your message was not sent. Its text: hello');
  });
}

test('the kept text stays through the next notice and a New chat, and goes when the user dismisses it', async (t) => {
  let fail!: (e: Error) => void;
  const turn = await heldTurn(t, '7', { ticket: new Promise<void>((_, reject) => { fail = reject; }) });
  t.mock.method(console, 'error', () => {});
  historySwap(globalThis, '9');
  fail(new TypeError('network error'));
  await turn.finished();
  const { notice } = await import('../../resources/ts/dom.ts');
  notice('error', 'Copying failed.');
  await drawerNewChat();
  assert.equal(status(), '');
  assert.equal(unsent(), 'Your message was not sent. Its text: hello');
  (document.querySelector('#ab-unsent [data-action="unsent-dismiss"]') as HTMLElement).dispatchEvent(new MouseEvent('click', { bubbles: true }));
  assert.equal(document.querySelectorAll('#ab-unsent').length, 0);
  assert.equal(document.activeElement?.id, 'ab-message');
});

test('unsentLines fills {text} literally, and a translation without {text} still carries the text', async () => {
  const { unsentLines } = await import('../../resources/ts/composer.ts');
  const t = (strings: Record<string, string>) => (key: string): string => strings[key] ?? key;
  assert.deepEqual(unsentLines({ text: 'a $& b', image: '' }, t({ notSent: 'Not sent: {text}' })), ['Not sent: a $& b']);
  assert.deepEqual(unsentLines({ text: 'kept', image: '' }, t({ notSent: 'Not sent.' })), ['Not sent. kept']);
  assert.deepEqual(unsentLines({ text: '', image: IMAGE }, t({ notSentImage: 'Image not sent.' })), ['Image not sent.']);
  assert.deepEqual(unsentLines({ text: 'both', image: IMAGE }, t({ notSent: '{text}', notSentImage: 'Image not sent.' })), ['both', 'Image not sent.']);
});

test('a turn that never ran still gives its draft back to its own empty composer', async (t) => {
  let fail!: (e: Error) => void;
  const turn = await heldTurn(t, '7', { ticket: new Promise<void>((_, reject) => { fail = reject; }) });
  t.mock.method(console, 'error', () => {});
  fail(new TypeError('network error'));
  await turn.finished();
  assert.equal((turn.form.querySelector('#ab-message') as HTMLTextAreaElement).value, 'hello');
  assert.equal(status(), 'The request failed. Try again.');
  assert.equal(document.querySelectorAll('#ab-unsent').length, 0);
});

/**
 * Every per-turn input a ticket carries is read once, when the turn is sent (Kanboard #4691): a
 * New chat or a model change while the turn's bubble requests are out must not reach the
 * POST /chat that follows them.
 */
test('the drawer\'s New chat before the ticket: the turn sends the chips it was sent with, not the fresh ones', async (t) => {
  let answer!: () => void;
  const turn = await heldTurn(t, '7', { bubbles: new Promise<void>((resolve) => { answer = resolve; }) }, {
    shell: SHELL_WITH_CHIPS_IN_ROW,
    before: (form) => (form.querySelector('[data-chip="screen"] [data-action="chip-remove"]') as HTMLElement).dispatchEvent(new MouseEvent('click', { bubbles: true })),
  });
  await drawerNewChat();
  // The fresh composer has the screen chip back, so a context read now would name it.
  assert.equal(turn.form.querySelectorAll('[data-chip="screen"]').length, 1);
  answer();
  await until(() => turn.chat() !== null);
  assert.deepEqual(turn.chat()?.context, { post_id: 12 });
  turn.close();
  await turn.finished();
});

test('a model chosen before the ticket: the turn sends the model it was sent with', async (t) => {
  let answer!: () => void;
  const turn = await heldTurn(t, '7', { bubbles: new Promise<void>((resolve) => { answer = resolve; }) });
  const select = document.createElement('select');
  select.id = 'ab-model';
  select.innerHTML = '<option value="llama3.2">llama3.2</option><option value="qwen3">qwen3</option>';
  (document.querySelector('.ab-wrap') as HTMLElement).append(select);
  select.value = 'qwen3';
  select.dispatchEvent(new Event('change', { bubbles: true }));
  assert.equal((turn.form.elements.namedItem('model') as HTMLInputElement).value, 'qwen3');
  answer();
  await until(() => turn.chat() !== null);
  assert.equal(turn.chat()?.model, 'llama3.2');
  turn.close();
  await turn.finished();
});
