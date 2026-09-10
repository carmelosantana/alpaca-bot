import { expect, test } from '@playwright/test';
import { readFile } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';

/**
 * What the composer does when a send does not run: the typed message and any attached image come
 * back, and the transcript is left with no record of a turn that never happened.
 *
 * This drives the real bundle (assets/js/chat.js, the `pnpm build` output of resources/ts/) in a
 * real browser, and nothing else: the page, the REST calls and the stream redemption are all
 * fulfilled by page.route(), so no WordPress and no dev server are involved. chat.spec.ts is the
 * one that needs those; it asserts a whole turn end to end and cannot be made to answer a
 * redemption with a 429 without changing the server. Run it on its own with `pnpm e2e:offline`,
 * which needs only `pnpm build` and a Chromium — `pnpm e2e` runs both files and the other one
 * wants a wp-env.
 *
 * This file is also, for now, the whole of resources/ts/chat.ts's error-path coverage. chat.ts
 * exports nothing, so nothing in tests/ts/ can reach it, and giving it exports would mean a DOM
 * for node:test to run against — a new dependency, which is not a release-week decision. Driving
 * the built bundle in a real browser is in any case the stronger test of the two: it exercises
 * the artifact the zip ships rather than the module the artifact is compiled from. Making chat.ts
 * unit-testable is 0.6 work; until then, an error path added to it belongs here.
 *
 * The markup is the part of View\Chat\Shell that send() actually reads -- the form, the four
 * hidden fields, the composer buttons, the status region and the transcript -- rather than the
 * whole shell, because a fixture that reproduced the shell would be a copy of it to keep in step.
 * If a selector here stops matching what Shell emits, that is the bundle failing to find it too.
 *
 * The three refusals are the three exits before the stream is consumed. The last one is the one
 * this file was written for: a stream redemption that comes back as something other than
 * text/event-stream (StreamBudget's concurrency 429, an expired or replayed ticket) used to
 * remove the empty assistant bubble and return, leaving the typed message and the attached image
 * nowhere at all while the ticket stayed valid for a retry the user could no longer make.
 */

const ORIGIN = 'https://alpaca-bot.test';
const REST = `${ORIGIN}/wp-json/alpaca-bot/v1`;
const IMAGE = 'data:image/png;base64,iVBORw0KGgo=';

const SHELL = `<!doctype html>
<html><head><meta charset="utf-8"><title>shell</title></head><body>
<div class="ab-wrap">
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
</div>
<script>window.alpacaBot = { rest: ${JSON.stringify(REST)}, nonce: 'n', offline: 'offline', i18n: { failed: 'The request failed. Try again.', thinking: 'Thinking...' } };</script>
<script src="/chat.js"></script>
</body></html>`;

/**
 * Serves the fixture and the bundle, answers the two /view/bubble renders and POST /chat, and
 * gives the stream redemption whatever `stream` says -- a response, or 'abort' for a connection
 * that drops. `seen` collects the paths that were asked for, so a test can say what did and did
 * not happen.
 */
async function shell(page: import('@playwright/test').Page, stream: { status: number; contentType: string; body: string } | 'abort'): Promise<string[]> {
  const bundle = await readFile(fileURLToPath(new URL('../../assets/js/chat.js', import.meta.url)), 'utf8');
  const seen: string[] = [];
  await page.route(`${ORIGIN}/**`, async (route) => {
    const url = new URL(route.request().url());
    const path = url.pathname + (url.searchParams.get('role') ? `?role=${url.searchParams.get('role')}` : '');
    seen.push(path);
    if (path === '/') return route.fulfill({ contentType: 'text/html', body: SHELL });
    if (path === '/chat.js') return route.fulfill({ contentType: 'application/javascript', body: bundle });
    if (path.startsWith('/wp-json/alpaca-bot/v1/view/bubble')) {
      const role = route.request().method() === 'POST' ? 'user' : 'assistant';
      return route.fulfill({ contentType: 'text/html', body: `<article class="ab-msg ab-msg--${role}"><div class="ab-msg__content">${role}</div></article>` });
    }
    if (path === '/wp-json/alpaca-bot/v1/chat') {
      return route.fulfill({ contentType: 'application/json', body: JSON.stringify({ stream_url: `${ORIGIN}/wp-json/alpaca-bot/v1/chat/1/stream?token=t` }) });
    }
    if (path.startsWith('/wp-json/alpaca-bot/v1/chat/')) {
      // 'abort' makes the page's fetch() reject rather than answer, which is a dropped
      // connection: the one exit that runs after the streaming bubble is already in the DOM.
      if (stream === 'abort') return route.abort('connectionfailed');
      return route.fulfill({ status: stream.status, contentType: stream.contentType, body: stream.body });
    }
    return route.fulfill({ status: 404, body: '' });
  });
  await page.goto(`${ORIGIN}/`);
  await expect(page.locator('#ab-form')).toBeVisible();
  return seen;
}

/** Types `text`, attaches `IMAGE` the way the media picker would, and sends with Enter. */
async function send(page: import('@playwright/test').Page, text: string): Promise<void> {
  await page.fill('#ab-message', text);
  await page.locator('#ab-form input[name="images"]').evaluate((el, value) => { (el as HTMLInputElement).value = value; }, IMAGE);
  await page.locator('#ab-message').press('Enter');
}

test('a stream redemption that is not an event stream gives the message and the image back and leaves no turn in the transcript', async ({ page }) => {
  const seen = await shell(page, {
    status: 429,
    contentType: 'application/json',
    body: JSON.stringify({ code: 'alpaca_bot_rate_limited', message: 'Too many requests. Try again shortly.', data: { status: 429, retry_after: 30 } }),
  });

  await send(page, 'the message that must survive');

  // The refusal is shown...
  await expect(page.locator('#ab-status')).toContainText('Too many requests');
  // ...the composer has its content back, both halves...
  await expect(page.locator('#ab-message')).toHaveValue('the message that must survive');
  await expect(page.locator('#ab-form input[name="images"]')).toHaveValue(IMAGE);
  // ...and the transcript shows no turn at all: neither the user bubble nor the empty streaming one.
  await expect(page.locator('#ab-messages article')).toHaveCount(0);
  // The redemption really was attempted, so this is the refusal path and not an earlier exit.
  expect(seen.filter((p) => p.startsWith('/wp-json/alpaca-bot/v1/chat/'))).toHaveLength(1);
  // The composer is usable again for the retry the still-valid ticket allows.
  await expect(page.locator('[data-action="send"]')).toBeEnabled();
});

test('a stream connection that drops leaves no orphaned bubble, and still gives the message back', async ({ page }) => {
  const seen = await shell(page, 'abort');

  await send(page, 'the message that must survive a dropped connection');

  // fetch() rejects rather than answering, so this lands in the catch, not the refusal path.
  await expect(page.locator('#ab-status')).toContainText('The request failed');
  // The composer has both halves back...
  await expect(page.locator('#ab-message')).toHaveValue('the message that must survive a dropped connection');
  await expect(page.locator('#ab-form input[name="images"]')).toHaveValue(IMAGE);
  // ...and neither bubble is left behind. Before the fix the assistant bubble was appended just
  // before the fetch, and only the user's was taken back out, so an empty streaming bubble sat
  // in the transcript for a turn that never reached the model.
  await expect(page.locator('#ab-messages article')).toHaveCount(0);
  // The redemption was attempted, so this is the post-append exit and not an earlier one.
  expect(seen.filter((p) => p.startsWith('/wp-json/alpaca-bot/v1/chat/'))).toHaveLength(1);
  await expect(page.locator('[data-action="send"]')).toBeEnabled();
});

test('a refused ticket gives the message and the image back too, which is the behaviour the stream refusal now matches', async ({ page }) => {
  await shell(page, { status: 200, contentType: 'text/event-stream', body: '' });
  await page.route(`${ORIGIN}/wp-json/alpaca-bot/v1/chat`, (route) => route.fulfill({
    status: 402,
    contentType: 'application/json',
    body: JSON.stringify({ code: 'alpaca_bot_cap_exceeded', message: 'Your monthly token cap has been reached.', data: { status: 402 } }),
  }));

  await send(page, 'capped');

  await expect(page.locator('#ab-status')).toContainText('monthly token cap');
  await expect(page.locator('#ab-message')).toHaveValue('capped');
  await expect(page.locator('#ab-form input[name="images"]')).toHaveValue(IMAGE);
  await expect(page.locator('#ab-messages article')).toHaveCount(0);
});

test('a bubble render that fails gives the message and the image back before any turn is asked for', async ({ page }) => {
  const seen = await shell(page, { status: 200, contentType: 'text/event-stream', body: '' });
  await page.route(`${ORIGIN}/wp-json/alpaca-bot/v1/view/bubble**`, (route) => route.fulfill({
    status: 403,
    contentType: 'application/json',
    body: JSON.stringify({ code: 'rest_forbidden', message: 'You are not allowed to do that.', data: { status: 403 } }),
  }));

  await send(page, 'forbidden');

  await expect(page.locator('#ab-status')).toContainText('not allowed');
  await expect(page.locator('#ab-message')).toHaveValue('forbidden');
  await expect(page.locator('#ab-form input[name="images"]')).toHaveValue(IMAGE);
  await expect(page.locator('#ab-messages article')).toHaveCount(0);
  expect(seen.filter((p) => p === '/wp-json/alpaca-bot/v1/chat')).toHaveLength(0);
});
