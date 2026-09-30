/** The stream redemption, and the JSON body a refused request answers with (docs/api.md section 5). */
export interface RestError { code?: string; message?: string; data?: Record<string, unknown> }
export type Redemption = { ok: true; stream: Response } | { ok: false; status: number; error: RestError };
export type Fetcher = (url: string, init: RequestInit) => Promise<Response>;

/** A refusal's body off a response, or off the text an XHR carried; `{}` for anything that is not JSON. */
export async function restError(res: Response | null, text = ''): Promise<RestError> {
  try {
    return res ? await res.json() as RestError : JSON.parse(text) as RestError;
  } catch {
    return {};
  }
}

/**
 * Redeems a stream ticket. Anything that is not `text/event-stream` is a refusal — StreamBudget's
 * concurrency 429, an expired or replayed ticket's 403 — and comes back with its status and its
 * JSON body, because the caller has to say which it was and to give the draft back (boot.ts
 * giveBack() says where) for the retry the ticket allows where it survives the refusal: it does on
 * the concurrency 429, which spends nothing, and it does not on the 403, which is a token that is
 * spent, expired, or not this user's (docs/api.md section 5) — none of the three will redeem on a
 * second try. A fetch that rejects is a dropped connection and is left to throw, which is the
 * other exit send() has to undo.
 *
 * `fetcher` defaults to the page's fetch, resolved per call so a test can stand one in.
 */
export async function redeem(streamUrl: string, nonce: string, fetcher: Fetcher = (url, init) => fetch(url, init)): Promise<Redemption> {
  const res = await fetcher(streamUrl, { credentials: 'same-origin', headers: { 'X-WP-Nonce': nonce } });
  if (res.headers.get('content-type')?.startsWith('text/event-stream')) return { ok: true, stream: res };
  return { ok: false, status: res.status, error: await restError(res) };
}
