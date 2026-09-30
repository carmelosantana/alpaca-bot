import { test } from 'node:test';
import assert from 'node:assert/strict';
import { installDom } from './env.ts';

/**
 * resources/ts/mount.ts's URL helper, under both of core's REST URL forms: rest_url() gives
 * `/wp-json/…` with pretty permalinks and `/?rest_route=/…` without them, and the query the
 * drawer adds (conversation_id) must not cost the second form its route.
 */
test('withQuery adds its query under either permalink form, keeping a ?rest_route= route', async () => {
  installDom('');
  const { withQuery } = await import('../../resources/ts/mount.ts');
  assert.equal(withQuery('https://alpaca-bot.test/wp-json/alpaca-bot/v1/view/panel', { conversation_id: '5' }), 'https://alpaca-bot.test/wp-json/alpaca-bot/v1/view/panel?conversation_id=5');
  assert.equal(withQuery('https://alpaca-bot.test/?rest_route=/alpaca-bot/v1/view/panel', { conversation_id: '5' }), 'https://alpaca-bot.test/?rest_route=%2Falpaca-bot%2Fv1%2Fview%2Fpanel&conversation_id=5');
  // A value set twice is replaced, not repeated.
  assert.equal(withQuery('https://alpaca-bot.test/wp-json/x?conversation_id=1', { conversation_id: '0' }), 'https://alpaca-bot.test/wp-json/x?conversation_id=0');
});

/**
 * What the drawer's every fetch of GET /view/panel carries (resources/ts/drawer.ts): the first
 * mount, its retry, and "New chat" all build their query here, off the data attributes
 * Admin\Drawer::footer() prints on the drawer element.
 */
test('panelQuery names the conversation and what the drawer element says the chips show', async () => {
  installDom('<aside id="ab-drawer" data-conversation="7" data-screen-id="post" data-screen-title="Edit “Post” &amp; more" data-post="12"></aside><aside id="bare"></aside>');
  const { panelQuery, withQuery } = await import('../../resources/ts/mount.ts');
  const host = document.getElementById('ab-drawer') as HTMLElement;

  assert.deepEqual(panelQuery(host, '7'), { conversation_id: '7', post_id: '12', screen_id: 'post', screen_title: 'Edit “Post” & more' });
  assert.deepEqual(panelQuery(host, '0'), { conversation_id: '0', post_id: '12', screen_id: 'post', screen_title: 'Edit “Post” & more' });
  // An element without them (a page printed before this task) asks for no chips.
  assert.deepEqual(panelQuery(document.getElementById('bare') as HTMLElement, '0'), { conversation_id: '0', post_id: '0', screen_id: '', screen_title: '' });
  // The title survives the URL: withQuery encodes it, so the & does not start a parameter.
  const url = new URL(withQuery('https://alpaca-bot.test/wp-json/alpaca-bot/v1/view/panel', panelQuery(host, '0')));
  assert.equal(url.searchParams.get('screen_title'), 'Edit “Post” & more');
  assert.equal(url.searchParams.get('post_id'), '12');
});

/**
 * The two fetches of GET /view/panel a host makes after the mount, each taking some of the
 * fragment into the chat it already has, so the composer the chat bundle is bound to stays:
 * newChat(), "New chat" in place (the drawer's and the editor sidebar's), which takes the
 * transcript, the history and the chips, and postChip(), the post chip the editor sidebar adds
 * once its new post has been saved (resources/ts/editor.ts).
 * Every assertion compares a primitive, never a node (tests/ts/env.ts says why).
 */
const CFG = { panel: 'https://alpaca-bot.test/wp-json/alpaca-bot/v1/view/panel', prefs: '', htmx: '', htmxId: '', chat: '', css: '', title: 'Alpaca Bot', failed: 'failed' };
const chip = (kind: string, name: string, value: string, label: string): string =>
  `<span class="ab-chip" data-chip="${kind}"><input type="hidden" name="${name}" value="${value}"><span class="ab-chip__label">${label}</span><button type="button" class="ab-chip__remove" data-action="chip-remove" aria-label="Remove ${label}">x</button></span>`;
const row = (chips: string): string => `<div class="ab-composer__chips" role="group" aria-label="What this chat can see">${chips}</div>`;
const panel = (opts: { conversation: string; messages: string; history: string; chips: string }): string => `<div class="ab-drawer__panel"><div class="ab-wrap ab-wrap--drawer">
  <div id="ab-history"><select id="ab-history-select"><option data-id="0">New chat</option>${opts.history}</select></div>
  <div id="ab-chat" data-conversation="${opts.conversation}"><div id="ab-status" class="ab-status">${opts.conversation === '0' ? '' : '<div class="notice">old</div>'}</div><div id="ab-messages" class="ab-messages" data-conversation="${opts.conversation}">${opts.messages}</div></div>
  <form id="ab-form" class="ab-composer"><input type="hidden" name="conversation_id" value="${opts.conversation}"><input type="hidden" name="model" value="m"><input type="hidden" name="images" value="">${opts.chips}<div class="ab-composer__row"><textarea id="ab-message" name="message"></textarea></div></form>
</div></div>`;

/** Answers every fetch with `body` (or `status`), recording the URL and the nonce header each was sent with. */
function serve(t: { after(fn: () => void): void }, body: string, status = 200): { url: string; nonce: string | null }[] {
  const real = globalThis.fetch;
  t.after(() => { globalThis.fetch = real; });
  const seen: { url: string; nonce: string | null }[] = [];
  globalThis.fetch = (async (input: RequestInfo | URL, init?: RequestInit) => {
    seen.push({ url: String(input), nonce: new Headers(init?.headers).get('X-WP-Nonce') });
    return new Response(body, { status, headers: { 'Content-Type': 'text/html' } });
  }) as typeof fetch;
  return seen;
}

test('newChat swaps a fresh transcript, history and chips row into the chat it has, and keeps its composer and what is typed', async (t) => {
  // The user took the post chip off; the screen chip is still on.
  installDom(`<aside id="host" data-post="12" data-screen-id="post" data-screen-title="Edit Post">${panel({ conversation: '5', messages: '<article class="ab-msg">old turn</article>', history: '<option data-id="5" selected>Old</option>', chips: row(chip('screen', 'context[screen][id]', 'post', 'On: Edit Post')) })}</aside>`);
  const { newChat, panelQuery } = await import('../../resources/ts/mount.ts');
  const host = document.getElementById('host') as HTMLElement;
  const seen = serve(t, panel({ conversation: '0', messages: '<div class="ab-welcome">new</div>', history: '<option data-id="5">Old</option>', chips: row(chip('post', 'context[post_id]', '12', 'Editing: Hello &amp; &lt;b&gt;') + chip('screen', 'context[screen][id]', 'post', 'On: Edit Post')) }));
  host.querySelector<HTMLTextAreaElement>('#ab-message')!.value = 'typed';
  // Marks the composer as it is, to tell it apart from a replaced one.
  (host.querySelector('#ab-form') as HTMLFormElement & { abMark?: number }).abMark = 1;

  assert.equal(await newChat(host, CFG, 'n1', panelQuery(host, '0')), true);
  assert.equal(seen.length, 1);
  assert.equal(new URL(seen[0]!.url).searchParams.get('conversation_id'), '0');
  assert.equal(new URL(seen[0]!.url).searchParams.get('post_id'), '12');
  assert.equal(seen[0]!.nonce, 'n1');
  assert.equal(host.querySelectorAll('#ab-messages article').length, 0);
  assert.equal(host.querySelector('#ab-messages')?.textContent, 'new');
  assert.equal(host.querySelectorAll('#ab-history-select option[selected]').length, 0);
  assert.equal(host.querySelector<HTMLInputElement>('#ab-form [name="conversation_id"]')?.value, '0');
  assert.equal(host.querySelector('#ab-chat')?.getAttribute('data-conversation'), '0');
  assert.equal(host.querySelector('#ab-status')?.childElementCount, 0);
  // The composer is the one it had, and the box keeps what was typed.
  assert.equal((host.querySelector('#ab-form') as HTMLFormElement & { abMark?: number }).abMark, 1);
  assert.equal(host.querySelector<HTMLTextAreaElement>('#ab-message')?.value, 'typed');
  // The chip the user took off is back: the fragment's chips row, in the one row, where Composer
  // puts it (before the box), with the server's labels and fields.
  assert.deepEqual(Array.from(host.querySelectorAll<HTMLElement>('#ab-form .ab-chip')).map((c) => c.dataset.chip), ['post', 'screen']);
  assert.equal(host.querySelectorAll('#ab-form .ab-composer__chips').length, 1);
  assert.equal(host.querySelector('#ab-form > .ab-composer__chips[role="group"] + .ab-composer__row') !== null, true);
  assert.equal(host.querySelector('#ab-form .ab-chip[data-chip="post"] .ab-chip__label')?.textContent, 'Editing: Hello & <b>');
  assert.equal(host.querySelector<HTMLInputElement>('#ab-form [name="context[post_id]"]')?.value, '12');
  assert.equal(host.querySelector<HTMLInputElement>('#ab-form [name="context[screen][id]"]')?.value, 'post');
});

test('newChat leaves no chip the fragment does not have, and no chips row when it has none', async (t) => {
  installDom(`<aside id="host" data-post="12">${panel({ conversation: '5', messages: '', history: '', chips: row(chip('post', 'context[post_id]', '12', 'Editing: Hello') + chip('screen', 'context[screen][id]', 'post', 'On: Edit Post')) })}</aside>`);
  const { newChat, panelQuery } = await import('../../resources/ts/mount.ts');
  const host = document.getElementById('host') as HTMLElement;
  // An auto-draft, say, on a host that names no screen: the server renders no chip at all.
  serve(t, panel({ conversation: '0', messages: '', history: '', chips: '' }));
  assert.equal(await newChat(host, CFG, 'n', panelQuery(host, '0')), true);
  assert.equal(host.querySelectorAll('#ab-form .ab-chip, #ab-form .ab-composer__chips, #ab-form [name^="context["]').length, 0);
  assert.equal(host.querySelectorAll('#ab-form .ab-composer__row').length, 1);
});

test('newChat changes nothing when the fragment is refused', async (t) => {
  installDom(`<aside id="host">${panel({ conversation: '5', messages: '<article class="ab-msg">old turn</article>', history: '', chips: '' })}</aside>`);
  const { newChat, panelQuery } = await import('../../resources/ts/mount.ts');
  const host = document.getElementById('host') as HTMLElement;
  // A body that would parse, so the status is what refuses it.
  serve(t, panel({ conversation: '0', messages: '', history: '', chips: row(chip('post', 'context[post_id]', '12', 'Editing: Hello')) }), 403);
  assert.equal(await newChat(host, CFG, 'n', panelQuery(host, '0')), false);
  assert.equal(host.querySelectorAll('#ab-messages article').length, 1);
  assert.equal(host.querySelectorAll('#ab-form .ab-chip').length, 0);
  assert.equal(host.querySelector<HTMLInputElement>('#ab-form [name="conversation_id"]')?.value, '5');
});

test('newChat answers false and changes nothing when the request fails outright', async (t) => {
  installDom(`<aside id="host">${panel({ conversation: '5', messages: '<article class="ab-msg">old turn</article>', history: '', chips: '' })}</aside>`);
  const { newChat, panelQuery } = await import('../../resources/ts/mount.ts');
  const host = document.getElementById('host') as HTMLElement;
  const real = globalThis.fetch;
  const log = console.error;
  t.after(() => { globalThis.fetch = real; console.error = log; });
  globalThis.fetch = (async () => { throw new TypeError('Failed to fetch'); }) as typeof fetch;
  const logged: string[] = [];
  console.error = (e: unknown) => { logged.push(String(e)); };
  assert.equal(await newChat(host, CFG, 'n', panelQuery(host, '0')), false);
  assert.deepEqual(logged, ['TypeError: Failed to fetch']);
  assert.equal(host.querySelectorAll('#ab-messages article').length, 1);
  assert.equal(host.querySelector<HTMLInputElement>('#ab-form [name="conversation_id"]')?.value, '5');
});

test('postChip takes the post chip the server renders for the host\'s post into a composer that had no chips', async (t) => {
  installDom(`<div id="host" data-post="12">${panel({ conversation: '5', messages: '<article class="ab-msg">a turn</article>', history: '', chips: '' })}</div>`);
  const { postChip } = await import('../../resources/ts/mount.ts');
  const host = document.getElementById('host') as HTMLElement;
  const seen = serve(t, panel({ conversation: '0', messages: '', history: '', chips: row(chip('post', 'context[post_id]', '12', 'Editing: Hello &amp; &lt;b&gt;')) }));

  assert.equal(await postChip(host, CFG, 'n2'), true);
  assert.equal(seen.length, 1);
  const query = new URL(seen[0]!.url).searchParams;
  assert.deepEqual([query.get('post_id'), query.get('conversation_id'), query.get('screen_id'), query.get('screen_title')], ['12', '0', '', '']);
  assert.equal(seen[0]!.nonce, 'n2');
  // In its row, the row where Composer puts it (before the box), with the server's label and field.
  assert.equal(host.querySelectorAll('#ab-form > .ab-composer__chips[role="group"] > .ab-chip[data-chip="post"]').length, 1);
  assert.equal(host.querySelector('#ab-form > .ab-composer__chips + .ab-composer__row') !== null, true);
  assert.equal(host.querySelector('#ab-form .ab-chip__label')?.textContent, 'Editing: Hello & <b>');
  assert.equal(host.querySelector<HTMLInputElement>('#ab-form [name="context[post_id]"]')?.value, '12');
  // Nothing else of the fragment came with it: the transcript and the conversation are the chat's own.
  assert.equal(host.querySelectorAll('#ab-messages article').length, 1);
  assert.equal(host.querySelector<HTMLInputElement>('#ab-form [name="conversation_id"]')?.value, '5');
  assert.equal(host.querySelectorAll('.ab-drawer__panel').length, 1);
});

test('postChip puts the post chip first in a row that has other chips, as Composer orders them', async (t) => {
  installDom(`<div id="host" data-post="12">${panel({ conversation: '0', messages: '', history: '', chips: row(chip('screen', 'context[screen][id]', 'post', 'On: Edit Post')) })}</div>`);
  const { postChip } = await import('../../resources/ts/mount.ts');
  const host = document.getElementById('host') as HTMLElement;
  serve(t, panel({ conversation: '0', messages: '', history: '', chips: row(chip('post', 'context[post_id]', '12', 'Editing: Hello')) }));
  assert.equal(await postChip(host, CFG, 'n'), true);
  assert.equal(host.querySelectorAll('#ab-form .ab-composer__chips').length, 1);
  assert.deepEqual(Array.from(host.querySelectorAll<HTMLElement>('#ab-form .ab-chip')).map((c) => c.dataset.chip), ['post', 'screen']);
});

test('postChip takes only the post chip: a chip the user took off stays off', async (t) => {
  // A host whose query names a screen too, whose chip was taken off along with its row.
  installDom(`<div id="host" data-post="12" data-screen-id="post" data-screen-title="Edit Post">${panel({ conversation: '0', messages: '', history: '', chips: '' })}</div>`);
  const { postChip } = await import('../../resources/ts/mount.ts');
  const host = document.getElementById('host') as HTMLElement;
  serve(t, panel({ conversation: '0', messages: '', history: '', chips: row(chip('post', 'context[post_id]', '12', 'Editing: Hello') + chip('screen', 'context[screen][id]', 'post', 'On: Edit Post')) }));
  assert.equal(await postChip(host, CFG, 'n'), true);
  assert.deepEqual(Array.from(host.querySelectorAll<HTMLElement>('#ab-form .ab-chip')).map((c) => c.dataset.chip), ['post']);
});

test('postChip adds nothing when the server renders no post chip, refuses the fragment, or the composer already has one', async (t) => {
  // An auto-draft, or a post the user may not edit: the fragment has no post chip.
  installDom(`<div id="host" data-post="12">${panel({ conversation: '0', messages: '', history: '', chips: '' })}</div>`);
  const { postChip } = await import('../../resources/ts/mount.ts');
  let host = document.getElementById('host') as HTMLElement;
  serve(t, panel({ conversation: '0', messages: '', history: '', chips: '' }));
  assert.equal(await postChip(host, CFG, 'n'), false);
  assert.equal(host.querySelectorAll('#ab-form .ab-chip, #ab-form .ab-composer__chips').length, 0);

  installDom(`<div id="host" data-post="12">${panel({ conversation: '0', messages: '', history: '', chips: '' })}</div>`);
  host = document.getElementById('host') as HTMLElement;
  serve(t, panel({ conversation: '0', messages: '', history: '', chips: row(chip('post', 'context[post_id]', '12', 'Editing: Hello')) }), 403);
  assert.equal(await postChip(host, CFG, 'n'), false);
  assert.equal(host.querySelectorAll('#ab-form .ab-chip').length, 0);

  // Already there: nothing is fetched and the chip is not doubled.
  installDom(`<div id="host" data-post="12">${panel({ conversation: '0', messages: '', history: '', chips: row(chip('post', 'context[post_id]', '12', 'Editing: Hello')) })}</div>`);
  host = document.getElementById('host') as HTMLElement;
  const seen = serve(t, panel({ conversation: '0', messages: '', history: '', chips: row(chip('post', 'context[post_id]', '12', 'Editing: Hello')) }));
  assert.equal(await postChip(host, CFG, 'n'), true);
  assert.equal(seen.length, 0);
  assert.equal(host.querySelectorAll('#ab-form .ab-chip').length, 1);
});

test('postChip does not double a post chip that New chat brought back while it was asking', async (t) => {
  // The editor on a post saved a moment ago: its save asks for the chip, and New chat is pressed
  // before that answer arrives, bringing the chip back with the fresh fragment first.
  installDom(`<div id="host" data-post="12">${panel({ conversation: '5', messages: '', history: '', chips: '' })}</div>`);
  const { newChat, panelQuery, postChip } = await import('../../resources/ts/mount.ts');
  const host = document.getElementById('host') as HTMLElement;
  const body = panel({ conversation: '0', messages: '', history: '', chips: row(chip('post', 'context[post_id]', '12', 'Editing: Hello')) });
  const real = globalThis.fetch;
  t.after(() => { globalThis.fetch = real; });
  let release: () => void = () => {};
  const held = new Promise<void>((resolve) => { release = resolve; });
  let calls = 0;
  globalThis.fetch = (async () => {
    if (calls++ === 0) await held;
    return new Response(body, { status: 200, headers: { 'Content-Type': 'text/html' } });
  }) as typeof fetch;

  const asking = postChip(host, CFG, 'n');
  assert.equal(await newChat(host, CFG, 'n', panelQuery(host, '0')), true);
  release();
  assert.equal(await asking, true);
  assert.equal(calls, 2);
  assert.equal(host.querySelectorAll('#ab-form .ab-chip[data-chip="post"]').length, 1);
});

/**
 * The settings screen enqueues htmx itself (Admin\Assets, for the Tools tab's Discover button), so
 * the drawer opened there must not run a second copy. The page's copy is known by the id core
 * prints on the handle's tag, `alpaca-bot-htmx-js`, which a site that rewrites the URL (a
 * `script_loader_src` filter stripping `?ver=`) leaves as it is. happy-dom loads no file, so a
 * tag the mount adds fails at once: the htmx.ajax() count says whether the mount got past htmx.
 */
test('mountPanel uses the htmx the page carries under its handle id, whatever its URL, and adds no copy', async () => {
  const base = 'https://alpaca-bot.test/wp-content/plugins/alpaca-bot/assets/js';
  const cfg = { ...CFG, htmx: `${base}/htmx.min.js?ver=2.0.10`, htmxId: 'alpaca-bot-htmx-js', chat: `${base}/chat.js?ver=1` };
  const asked: string[] = [];
  const fake = { ajax: async (_method: string, url: string, opts: { target: HTMLElement }) => { asked.push(url); opts.target.innerHTML = '<form id="ab-form"></form>'; } };

  installDom(`<script id="alpaca-bot-htmx-js" src="${base}/htmx.min.js"></script><aside id="host"></aside>`);
  (window as unknown as { htmx: unknown }).htmx = fake;
  const { mountPanel } = await import('../../resources/ts/mount.ts');
  const outcome = await mountPanel(document.getElementById('host') as HTMLElement, cfg, 'n', {}).then(() => 'mounted', () => 'failed');
  // It got as far as the chat bundle, whose tag is the one that failed.
  assert.equal(outcome, 'failed');
  assert.equal(asked.length, 1);
  assert.equal(document.querySelectorAll('script[src*="htmx"]').length, 1);

  // Without the tag, the loader adds htmx itself, and here that is where it stops.
  installDom('<aside id="host"></aside>');
  (window as unknown as { htmx: unknown }).htmx = fake;
  const second = await mountPanel(document.getElementById('host') as HTMLElement, cfg, 'n', {}).then(() => 'mounted', () => 'failed');
  assert.equal(second, 'failed');
  assert.equal(asked.length, 1);
});
