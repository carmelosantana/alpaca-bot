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
 * The two fetches of GET /view/panel a host makes after the mount, each taking one part of the
 * fragment into the chat it already has, so the composer the chat bundle is bound to stays:
 * newChat(), "New chat" in place (the drawer's and the editor sidebar's), and postChip(), the
 * post chip the editor sidebar adds once its new post has been saved (resources/ts/editor.ts).
 * Every assertion compares a primitive, never a node (tests/ts/env.ts says why).
 */
const CFG = { panel: 'https://alpaca-bot.test/wp-json/alpaca-bot/v1/view/panel', prefs: '', htmx: '', chat: '', css: '', title: 'Alpaca Bot', failed: 'failed' };
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

test('newChat swaps a fresh transcript and history into the chat it has, and keeps its composer, chips and all', async (t) => {
  installDom(`<aside id="host" data-post="12">${panel({ conversation: '5', messages: '<article class="ab-msg">old turn</article>', history: '<option data-id="5" selected>Old</option>', chips: row(chip('post', 'context[post_id]', '12', 'Editing: Hello')) })}</aside>`);
  const { newChat, panelQuery } = await import('../../resources/ts/mount.ts');
  const host = document.getElementById('host') as HTMLElement;
  const seen = serve(t, panel({ conversation: '0', messages: '<div class="ab-welcome">new</div>', history: '<option data-id="5">Old</option>', chips: '' }));
  host.querySelector<HTMLTextAreaElement>('#ab-message')!.value = 'typed';

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
  // The composer is the one it had: the box keeps what was typed, and the chip is still on.
  assert.equal(host.querySelector<HTMLTextAreaElement>('#ab-message')?.value, 'typed');
  assert.equal(host.querySelectorAll('#ab-form .ab-chip[data-chip="post"]').length, 1);
});

test('newChat changes nothing when the fragment is refused', async (t) => {
  installDom(`<aside id="host">${panel({ conversation: '5', messages: '<article class="ab-msg">old turn</article>', history: '', chips: '' })}</aside>`);
  const { newChat, panelQuery } = await import('../../resources/ts/mount.ts');
  const host = document.getElementById('host') as HTMLElement;
  // A body that would parse, so the status is what refuses it.
  serve(t, panel({ conversation: '0', messages: '', history: '', chips: '' }), 403);
  assert.equal(await newChat(host, CFG, 'n', panelQuery(host, '0')), false);
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
