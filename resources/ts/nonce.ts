/**
 * The REST nonce goes stale after a day of leaving the tab open (Kanboard #302), so the bundle
 * asks core's heartbeat for a fresh one: `alpaca_bot_nonce: 1` rides out on `heartbeat-send`,
 * and Admin\Assets answers on the `heartbeat_received` filter with `alpaca_bot_nonce` set to a
 * new `wp_rest` nonce, which arrives on `heartbeat-tick`. Core sends its own `rest_nonce` (the
 * same `wp_rest` nonce) once the heartbeat's nonce is in its second half or has expired, and
 * in the expired case it does so *instead* of running `heartbeat_received`, so that field is
 * honoured too. Heartbeat's events are jQuery events on the document, so they are only
 * reachable through jQuery.
 */
import type { Settings } from './boot.ts';

type HeartbeatHandler = (event: unknown, data: Record<string, unknown>) => void;

declare global {
  interface Window { jQuery?: (target: Document) => { on(event: string, handler: HeartbeatHandler): void } }
}

export function watchNonce(onNonce: (nonce: string) => void): void {
  const jq = window.jQuery;
  if (!jq) return;
  jq(document).on('heartbeat-send', (_e, data) => { data.alpaca_bot_nonce = 1; });
  jq(document).on('heartbeat-tick', (_e, data) => {
    const nonce = data.alpaca_bot_nonce ?? data.rest_nonce;
    if (typeof nonce === 'string' && nonce !== '') onNonce(nonce);
  });
}

/**
 * The page's REST nonce (`alpacaBot.nonce`) as a getter, kept fresh on the heartbeat from this
 * call on, for a script that puts the chat into a page before the chat bundle is there: the
 * drawer's loader (drawer-start.ts) and the block editor's sidebar (editor.ts). Each signs
 * requests of its own before the first open adds the bundle, and the bundle's own watchNonce()
 * starts only when it boots, so without this a tab left open past the nonce's life fails its
 * first open (Kanboard #4526).
 *
 * The fresh nonce is written into `alpacaBot` itself, not kept here, because that object is what
 * the bundle boots on (chat.ts hands `window.alpacaBot` to boot()), so the chat starts with it.
 * Once the bundle has booted, its watchNonce() writes the same tick's nonce into the same object,
 * and both ask with the same `alpaca_bot_nonce: 1`, so the second registration changes nothing.
 * `Settings` is imported as a type only, which esbuild erases, so the loaders do not carry boot.ts.
 */
export function pageNonce(): () => string {
  watchNonce((nonce) => {
    const settings: Settings | undefined = window.alpacaBot;
    if (settings) settings.nonce = nonce;
  });
  return () => window.alpacaBot?.nonce ?? '';
}
