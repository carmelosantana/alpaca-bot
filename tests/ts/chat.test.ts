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
const CFG = { rest: REST, nonce: 'n', offline: 'offline', i18n: { failed: 'The request failed. Try again.', thinking: 'Thinking...' } };
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
