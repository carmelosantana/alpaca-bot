import type { RestError } from './redeem.ts';

/** What a refused request says in #ab-status, and whether it locks the composer until a reload. */
export interface Refusal { text: string; expired: boolean }

/**
 * A refusal read: the server's own message wherever it has one, and the one case the client
 * knows better than the server — a stale REST nonce (Kanboard #302), whose message is about
 * cookies and whose remedy is a reload, so it gets the plugin's wording and locks the composer.
 *
 * What is said, not how loudly. The concurrency refusal is shown in the warning colour rather
 * than the error one, and that stays with the caller (boot.ts's `refused()`): it turns on the
 * code alone, and this answers with a text and a lock, which is nowhere to put a colour.
 */
export function refusal(status: number, error: RestError, t: (key: string) => string): Refusal {
  if (status === 403 && error.code === 'rest_cookie_invalid_nonce') return { text: t('sessionExpired'), expired: true };
  return { text: error.message || t('failed'), expired: false };
}
