import { test } from 'node:test';
import assert from 'node:assert/strict';
import { installDom, until } from './env.ts';

/**
 * boot() as a guest on someone else's page, which is what the admin-wide drawer makes it
 * (Admin\Drawer, resources/ts/drawer.ts): the shell is one element among a wp-admin screen's own,
 * and the page around it has buttons and code blocks of its own. The comments list's Quick Edit is
 * a `<button data-action="edit">` (WP_Comments_List_Table), the action name the transcript's
 * "Edit and resend" uses. Another plugin's screen may run htmx of its own, whose requests must not
 * carry this chat's REST nonce. And a front-end page may carry a `[alpacabot prompt="…"]` answer
 * (`.alpaca-bot-answer`, Shortcodes\Chat) beside the shell, whose code blocks the chat decorates.
 *
 * And what boot() tells its host: `ab:conversation` whenever the conversation the transcript shows
 * changes, and the cancelable `ab:new-chat` before the history select's "New chat" leaves the page.
 *
 * Every assertion compares a primitive, never a node (tests/ts/env.ts says why).
 */
const CFG = { rest: 'https://alpaca-bot.test/wp-json/alpaca-bot/v1', nonce: 'n', offline: 'offline', i18n: {} };
const PAGE = `<div id="wpbody">
  <a class="page-title-action" href="https://alpaca-bot.test/wp-admin/post-new.php">Add New Post</a>
  <div id="host-hx">another plugin's htmx element</div>
  <div class="alpaca-bot-answer"><pre id="answer-pre"><code class="language-js">const answer = 1;</code></pre><button type="button" id="answer-edit" data-action="edit">edit</button></div>
  <button type="button" id="host-edit" data-action="edit">Quick Edit</button>
  <button type="button" id="host-remove" data-action="image-remove">host remove</button>
  <pre id="host-pre"><code class="language-js">const host = 1;</code></pre>
</div>
<div class="ab-wrap">
  <a class="page-title-action" href="https://alpaca-bot.test/wp-admin/admin.php?page=alpaca-bot">New chat</a>
  <div id="ab-history"><select id="ab-history-select"><option data-id="0">New chat</option><option data-id="12" selected>Old</option></select></div>
  <div id="ab-chat" data-conversation="0">
    <div id="ab-status" class="ab-status"></div>
    <div id="ab-messages" class="ab-messages" data-conversation="0">
      <article class="ab-msg"><div class="ab-msg__content">typed before</div><button type="button" id="own-edit" data-action="edit">edit</button></article>
      <pre id="own-pre"><code class="language-js">const own = 1;</code></pre>
    </div>
  </div>
  <form id="ab-form" class="ab-composer">
    <input type="hidden" name="conversation_id" value="0">
    <input type="hidden" name="model" value="llama3.2">
    <input type="hidden" name="context[post_id]" value="0">
    <input type="hidden" name="images" value="data:image/png;base64,iVBORw0KGgo=">
    <textarea id="ab-message" name="message" rows="1"></textarea>
    <div class="ab-composer__buttons">
      <button type="button" data-action="image" hidden>image</button>
      <button type="button" data-action="image-remove">remove</button>
      <button type="button" data-action="send">send</button>
    </div>
  </form>
</div>`;

async function hosted(): Promise<{ form: HTMLFormElement; textarea: HTMLTextAreaElement }> {
  installDom(PAGE);
  const { boot } = await import('../../resources/ts/boot.ts');
  const form = document.querySelector('#ab-form') as HTMLFormElement;
  boot(CFG, form);
  return { form, textarea: form.querySelector('#ab-message') as HTMLTextAreaElement };
}

test('a button of the host page is left to the host, even one named like the shell\'s own', async () => {
  const { form, textarea } = await hosted();
  textarea.value = 'half a question';

  (document.querySelector('#host-edit') as HTMLElement).click();
  (document.querySelector('#host-remove') as HTMLElement).click();
  // Quick Edit did not empty the composer, and the host's "remove" did not drop the attached image.
  assert.equal(textarea.value, 'half a question');
  assert.equal((form.elements.namedItem('images') as HTMLInputElement).value, 'data:image/png;base64,iVBORw0KGgo=');

  // The shell's own buttons still act: "Edit and resend" puts the message back in the box.
  (document.querySelector('#own-edit') as HTMLElement).click();
  assert.equal(textarea.value, 'typed before');
});

test('only the shell\'s code blocks are decorated, not the host page\'s', async () => {
  await hosted();
  assert.equal((document.querySelector('#own-pre') as HTMLElement).dataset.highlighted, '1');
  assert.equal((document.querySelector('#host-pre') as HTMLElement).dataset.highlighted, undefined);
  assert.equal(document.querySelectorAll('#host-pre .ab-code__copy').length, 0);

  // And after an htmx swap in the shell, which re-decorates: the host's block is still the host's.
  document.querySelector('#ab-messages')!.dispatchEvent(new CustomEvent('htmx:afterSwap', { bubbles: true }));
  assert.equal((document.querySelector('#host-pre') as HTMLElement).dataset.highlighted, undefined);
});

test('the conversation the transcript shows is announced to the host as ab:conversation', async () => {
  const { form } = await hosted();
  const ids: number[] = [];
  let fromForm = 0;
  document.addEventListener('ab:conversation', (e) => {
    ids.push((e as CustomEvent<{ id: number }>).detail.id);
    if (e.target === form) fromForm++;
  });
  (document.querySelector('#ab-messages') as HTMLElement).dataset.conversation = '12';
  // A swap elsewhere on the page is not the transcript's: nothing is announced for it.
  document.querySelector('#host-hx')!.dispatchEvent(new CustomEvent('htmx:afterSwap', { bubbles: true }));
  assert.deepEqual(ids, []);
  // htmx fires afterSwap on what it swapped in, here the transcript.
  document.querySelector('#ab-messages')!.dispatchEvent(new CustomEvent('htmx:afterSwap', { bubbles: true }));

  assert.deepEqual(ids, [12]);
  assert.equal(fromForm, 1);
  assert.equal((form.elements.namedItem('conversation_id') as HTMLInputElement).value, '12');
});

test('"New chat" in the history select asks the host first: a host that cancels ab:new-chat keeps the page, and one that does not lets it go to the link', async () => {
  await hosted();
  const went: string[] = [];
  Object.defineProperty(window.location, 'assign', { value: (url: string) => { went.push(url); }, configurable: true });
  const select = document.querySelector('#ab-history-select') as HTMLSelectElement;
  select.selectedIndex = 0;
  const ask = (): CustomEvent<{ path: string; headers: Record<string, string> }> => {
    const ev = new CustomEvent('htmx:configRequest', { bubbles: true, cancelable: true, detail: { path: '/wp-json/alpaca-bot/v1/view/messages/0', headers: {} } });
    select.dispatchEvent(ev);
    return ev;
  };

  const cancel = (e: Event): void => e.preventDefault();
  document.addEventListener('ab:new-chat', cancel);
  const kept = ask();
  assert.equal(kept.defaultPrevented, true);
  assert.equal(went.length, 0);

  // Nobody cancels: the chat screen's behaviour, the page goes to the header's "New chat" link,
  // the shell's own, not the first `.page-title-action` on the page (the host's "Add New Post").
  document.removeEventListener('ab:new-chat', cancel);
  ask();
  await until(() => went.length > 0);
  assert.deepEqual(went, ['https://alpaca-bot.test/wp-admin/admin.php?page=alpaca-bot']);
});

test('a prompt answer beside the shell is decorated, and its copy button copies, as when the bundle decorated the whole page', async () => {
  await hosted();
  assert.equal((document.querySelector('#answer-pre') as HTMLElement).dataset.highlighted, '1');
  const copied: string[] = [];
  Object.defineProperty(navigator, 'clipboard', { value: { writeText: async (text: string) => { copied.push(text); } }, configurable: true });
  (document.querySelector('#answer-pre .ab-code__copy') as HTMLElement).click();
  await until(() => copied.length > 0);
  assert.deepEqual(copied, ['const answer = 1;']);

  // Only its copy buttons: any other action inside an answer is not the chat's to act on.
  const textarea = document.querySelector('#ab-message') as HTMLTextAreaElement;
  textarea.value = 'half a question';
  (document.querySelector('#answer-edit') as HTMLElement).click();
  assert.equal(textarea.value, 'half a question');
});

test('htmx on the host page is left alone: no nonce on its requests, no refusal from its errors', async () => {
  await hosted();
  const configure = (id: string): Record<string, string> => {
    const detail = { path: '/wp-admin/admin-ajax.php', headers: {} as Record<string, string> };
    document.querySelector(id)!.dispatchEvent(new CustomEvent('htmx:configRequest', { bubbles: true, cancelable: true, detail }));
    return detail.headers;
  };
  // Another plugin's request carries no X-WP-Nonce of ours...
  assert.equal(configure('#host-hx')['X-WP-Nonce'], undefined);
  // ...and neither does one whose element is the body, which is what htmx.ajax() without a
  // source runs with: mount.ts signs its panel request by hand for that reason.
  assert.equal(configure('body')['X-WP-Nonce'], undefined);
  // ...while the chat's own requests are signed.
  assert.equal(configure('#ab-history')['X-WP-Nonce'], 'n');

  const fail = (id: string): void => {
    document.querySelector(id)!.dispatchEvent(new CustomEvent('htmx:responseError', { bubbles: true, detail: { xhr: { status: 403, responseText: JSON.stringify({ code: 'rest_forbidden', message: 'Their refusal.' }) } } }));
  };
  fail('#host-hx');
  await new Promise((resolve) => setTimeout(resolve, 20));
  assert.equal(document.querySelector('#ab-status')?.textContent ?? '', '');
  fail('#ab-history');
  await until(() => (document.querySelector('#ab-status')?.textContent ?? '').includes('Their refusal.'));
});
