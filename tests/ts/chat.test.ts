import { test } from 'node:test';
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
    <input type="hidden" name="context[post_id]" value="0">
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

test('a stream redemption that is not an event stream gives the message and the image back and leaves no turn in the transcript', async (t) => {
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
    if (url.pathname.includes('/chat/1/stream')) {
      return Response.json({ code: 'rest_forbidden', message: 'Invalid or expired stream token.', data: { status: 403 } }, { status: 403 });
    }
    return new Response('', { status: 404 });
  }) as typeof fetch;

  const { boot } = await import('../../resources/ts/boot.ts');
  const form = document.querySelector('#ab-form') as HTMLFormElement;
  boot(CFG, form);
  (form.querySelector('#ab-message') as HTMLTextAreaElement).value = 'the message that must survive';
  (form.elements.namedItem('images') as HTMLInputElement).value = IMAGE;
  form.dispatchEvent(new win.Event('submit', { cancelable: true }) as unknown as Event);

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
});
