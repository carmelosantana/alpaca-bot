import { test } from 'node:test';
import assert from 'node:assert/strict';
import { installDom } from './env.ts';

/**
 * resources/ts/context.ts: the turn's `context`, read off the composer's chips. The form is the
 * one View\Chat\Composer renders with both chips; every assertion compares plain objects (the
 * return value), never a node (tests/ts/env.ts says why).
 */
const FORM = `<form id="ab-form">
  <input type="hidden" name="conversation_id" value="0">
  <div class="ab-composer__chips">
    <span class="ab-chip" data-chip="post"><input type="hidden" name="context[post_id]" value="12"><span class="ab-chip__label">Editing: Hello</span><button type="button" data-action="chip-remove"></button></span>
    <span class="ab-chip" data-chip="screen"><input type="hidden" name="context[screen][id]" value="edit-post"><input type="hidden" name="context[screen][title]" value="Posts"><span class="ab-chip__label">On: Posts</span><button type="button" data-action="chip-remove"></button></span>
  </div>
</form>`;

test('contextFrom sends a key for every chip the form still holds, and nothing for one taken off', async () => {
  installDom(FORM);
  const { contextFrom } = await import('../../resources/ts/context.ts');
  const form = document.querySelector('#ab-form') as HTMLFormElement;

  assert.deepEqual(contextFrom(form), { post_id: 12, screen: { id: 'edit-post', title: 'Posts' } });

  form.querySelector('[data-chip="screen"]')?.remove();
  assert.deepEqual(contextFrom(form), { post_id: 12 });

  form.querySelector('[data-chip="post"]')?.remove();
  assert.deepEqual(contextFrom(form), {});
});

test('contextFrom sends no post for an id that is not a positive integer, and no screen missing half of itself', async () => {
  installDom(FORM);
  const { contextFrom } = await import('../../resources/ts/context.ts');
  const form = document.querySelector('#ab-form') as HTMLFormElement;
  const post = form.elements.namedItem('context[post_id]') as HTMLInputElement;
  for (const value of ['0', '', 'abc', '-3', '1.5']) {
    post.value = value;
    assert.deepEqual(contextFrom(form), { screen: { id: 'edit-post', title: 'Posts' } }, `post_id "${value}"`);
  }
  (form.elements.namedItem('context[screen][title]') as HTMLInputElement).remove();
  assert.deepEqual(contextFrom(form), {});
});
