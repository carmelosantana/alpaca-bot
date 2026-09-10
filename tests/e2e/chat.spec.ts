import { expect, test } from '@playwright/test';

/**
 * One real turn through the admin chat screen, in a browser, against a real WordPress.
 *
 * The provider is tests/e2e/mu-plugins/alpaca-bot-e2e-provider.php, mapped into the wp-env
 * development site's mu-plugins directory, so nothing here reaches a model server: the reply is a
 * fixed string this file knows. That is what makes the assertions worth making -- the test asserts
 * the *exact* reply text, so a turn that never arrived, one that arrived empty, and one answered
 * by something else all fail.
 *
 * Why these three assertions and not "the page loaded":
 *
 * - The model select stands on `e2e-fake-model`. That only happens if ModelCatalog::all() built a
 *   provider through the `alpaca_bot/provider` filter and read models() off it, so it proves the
 *   fake is wired in before a turn is ever sent. Without it, a later failure could not tell a
 *   broken chat screen from a fake provider that never loaded.
 * - The assistant bubble contains REPLY. chat.ts only puts text there from `delta` frames of the
 *   SSE stream, so this is the whole path: POST /chat for a ticket, GET the stream, frames read,
 *   text appended.
 * - The bubble ends in a receipt reading the model and `18 tokens`. MessageBubble renders the
 *   receipt only for a turn with usage, and chat.ts only asks the server to render that finished
 *   bubble on the `done` frame -- so the receipt is the proof the turn *completed*, not just that
 *   some text streamed in. A stream that dropped mid-reply leaves the bubble marked
 *   `data-partial` with no receipt, which fails here.
 *
 * Both negative controls were run by hand before this was committed, and both fail:
 *
 * - Change the fake's reply text and the run stops at the assistant-bubble expectation, reporting
 *   the substituted string it received instead.
 * - Take the `alpaca_bot/provider` filter out altogether and the run stops earlier still, at the
 *   model select: with no provider the catalog is empty, #ab-model has no options and no value.
 *
 * So neither "no reply arrived" nor "something else answered" can pass here.
 */

const REPLY = 'Hello from the Alpaca Bot end-to-end fake provider.';
const MODEL = 'e2e-fake-model';
const ADMIN_USER = process.env.WP_ADMIN_USER ?? 'admin';
const ADMIN_PASSWORD = process.env.WP_ADMIN_PASSWORD ?? 'password';

test('a turn on the admin chat screen streams the provider reply into an assistant bubble with a receipt', async ({ page }) => {
  // wp-env's own administrator. wp-login.php rather than a cookie: the screen is capability
  // gated and the REST calls the turn makes are nonce + cookie authenticated, so the test has to
  // hold the same session a person would.
  await page.goto('/wp-login.php');
  await page.fill('#user_login', ADMIN_USER);
  await page.fill('#user_pass', ADMIN_PASSWORD);
  await Promise.all([page.waitForURL(/wp-admin/), page.click('#wp-submit')]);

  await page.goto('/wp-admin/admin.php?page=alpaca-bot');
  await expect(page.locator('#ab-form')).toBeVisible();
  await expect(page.locator('#ab-model')).toHaveValue(MODEL);

  const messages = page.locator('#ab-messages');
  await page.fill('#ab-message', 'hello');
  await page.locator('#ab-message').press('Enter');

  await expect(messages.locator('article.ab-msg--user .ab-msg__content')).toHaveText('hello');

  const assistant = messages.locator('article.ab-msg--assistant').last();
  await expect(assistant.locator('.ab-msg__content')).toContainText(REPLY, { timeout: 30_000 });

  // The finished turn: the server re-rendered the bubble on `done`, so the streaming markers are
  // gone and the receipt is there with the fake's usage on it.
  const receipt = assistant.locator('footer.ab-receipt');
  await expect(receipt).toBeVisible();
  await expect(receipt).toContainText(MODEL);
  await expect(receipt).toContainText('18 tokens');
  await expect(assistant).toHaveAttribute('data-role', 'assistant');
  await expect(assistant).not.toHaveAttribute('data-streaming', '1');
  await expect(assistant).not.toHaveAttribute('data-partial', '1');

  // Nothing went wrong quietly: chat.ts writes every refusal and every dropped stream into
  // #ab-status, so an empty status region is the assertion that the turn took the happy path.
  await expect(page.locator('#ab-status')).toBeEmpty();
});
