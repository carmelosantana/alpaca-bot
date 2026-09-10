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
