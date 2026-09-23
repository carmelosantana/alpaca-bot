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
