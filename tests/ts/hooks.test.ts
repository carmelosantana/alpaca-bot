import { test } from 'node:test';
import assert from 'node:assert/strict';
import { installDom } from './env.ts';

/**
 * resources/ts/hooks.ts: the chat's `alpacaBot.*` extension points over core's `wp-hooks`
 * (window.wp.hooks). The fake below is the slice of @wordpress/hooks the module calls, with
 * core's semantics: filters run in the order added, each handed the previous one's value.
 */
type Fn = (...args: unknown[]) => unknown;
function fakeHooks(): { applyFilters: Fn; doAction: Fn; addFilter: (name: string, ns: string, fn: Fn) => void; addAction: (name: string, ns: string, fn: Fn) => void; done: string[] } {
  const filters: Record<string, Fn[]> = {};
  const actions: Record<string, Fn[]> = {};
  const done: string[] = [];
  return {
    done,
    addFilter: (name, _ns, fn) => { (filters[name] ??= []).push(fn); },
    addAction: (name, _ns, fn) => { (actions[name] ??= []).push(fn); },
    applyFilters: (name, value, ...args) => (filters[name as string] ?? []).reduce((v, fn) => fn(v, ...args), value),
    doAction: (name, ...args) => { done.push(name as string); for (const fn of actions[name as string] ?? []) fn(...args); },
  };
}

test('filter() hands the value through every listener, and keeps the original when a listener returns another type or there is no wp.hooks', async () => {
  const win = installDom('');
  const { filter } = await import('../../resources/ts/hooks.ts');
  assert.deepEqual(filter('alpacaBot.chat.request', { message: 'hi' }), { message: 'hi' });
  const hooks = fakeHooks();
  (win as unknown as Record<string, unknown>).wp = { hooks };
  hooks.addFilter('alpacaBot.chat.request', 'test', (body) => ({ ...(body as object), context: { kb: 1 } }));
  assert.deepEqual(filter('alpacaBot.chat.request', { message: 'hi' }), { message: 'hi', context: { kb: 1 } });
  hooks.addFilter('alpacaBot.chat.request', 'broken', () => 'oops');
  assert.deepEqual(filter('alpacaBot.chat.request', { message: 'hi' }), { message: 'hi' });
});

test('action() fires through wp.hooks and is a no-op without it', async () => {
  const win = installDom('');
  const { action } = await import('../../resources/ts/hooks.ts');
  action('alpacaBot.turn.started', 1);
  const hooks = fakeHooks();
  (win as unknown as Record<string, unknown>).wp = { hooks };
  const heard: unknown[] = [];
  hooks.addAction('alpacaBot.turn.started', 'test', (...args) => { heard.push(args); });
  action('alpacaBot.turn.started', 42, 'hello');
  assert.deepEqual(heard, [[42, 'hello']]);
});

test('ready() makes window.alpacaBotChat once, with the API version, and its promise resolves with what the first alpacaBot.ready action hands over', async () => {
  const win = installDom('');
  const hooks = fakeHooks();
  (win as unknown as Record<string, unknown>).wp = { hooks };
  const { ready, action, API_VERSION } = await import('../../resources/ts/hooks.ts');
  const chat = ready();
  assert.equal(ready(), chat);
  assert.equal(chat.apiVersion, API_VERSION);
  assert.equal(API_VERSION, 1);
  const api = { apiVersion: 1, marker: 'first' };
  action('alpacaBot.ready', api);
  assert.equal((await chat.ready as unknown as { marker: string }).marker, 'first');
});
