import { expect, type Page } from '@playwright/test';

export const ADMIN_USER = process.env.WP_ADMIN_USER ?? 'admin';
export const ADMIN_PASSWORD = process.env.WP_ADMIN_PASSWORD ?? 'password';

/**
 * Log in through wp-login.php as the admin every spec uses.
 *
 * wp-login.php focuses the username field and selects its text from a 200 ms `setTimeout`
 * (`wp_attempt_focus()`). On a cold container that timer can fire after Playwright has focused
 * the password field and before it inserts the text, so the password lands in the username field,
 * replacing it, and the POST logs in as nobody: a CI run on WordPress 7.1 failed exactly so, its
 * last snapshot showing "password" in the username field and an empty password field. So this
 * waits for that focus before typing, and checks both values before it submits.
 */
export async function login(page: Page): Promise<void> {
  await page.goto('/wp-login.php');
  const user = page.locator('#user_login');
  const pass = page.locator('#user_pass');
  await expect(user).toBeFocused();
  await user.fill(ADMIN_USER);
  await pass.fill(ADMIN_PASSWORD);
  await expect(user).toHaveValue(ADMIN_USER);
  await expect(pass).toHaveValue(ADMIN_PASSWORD);
  await Promise.all([page.waitForURL(/wp-admin/), page.click('#wp-submit')]);
}
