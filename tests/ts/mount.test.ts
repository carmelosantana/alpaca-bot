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
