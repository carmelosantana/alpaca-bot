/**
 * The attached image as POST /chat takes it: a base64 data URL. The picker hands over an
 * attachment URL (its `large` size where the site registers one, else the original file), and
 * the bytes are fetched and encoded here. They then travel twice inside a JSON body (the
 * optimistic user bubble, then the turn), and neither route caps them: past PHP's
 * post_max_size the body is dropped before WordPress sees it and the only answer is a bare
 * failure. So the cap is applied here, before encoding, with a message that says what to do.
 *
 * The cap is the site's own, shipped by Admin\Assets as `maxImageBytes`: post_max_size less
 * the rest of the body, scaled for the encoding (the upload limit has no say; this is not an
 * upload). The constant is the fallback for a payload without one, a conservative guess against
 * a default 8M post_max_size.
 */
export const MAX_IMAGE_BYTES = 4 * 1024 * 1024;

export class ImageTooLarge extends Error {
  readonly size: number; // (not parameter properties: node --test strips types and cannot rewrite one)
  readonly max: number;
  constructor(size: number, max: number) {
    super(`The image is ${size} bytes; at most ${max} are sent.`);
    this.name = 'ImageTooLarge';
    this.size = size;
    this.max = max;
  }
}

/**
 * The turn's images together are past the cap, though each fits on its own. Same {size, max}
 * shape as ImageTooLarge (`size` is the total), and a subclass of it, so a caller that only
 * knows the single-image error still formats a figure; one that checks for this first can say
 * "those images" rather than "that image".
 */
export class ImagesTooLarge extends ImageTooLarge {
  constructor(size: number, max: number) {
    super(size, max);
    this.name = 'ImagesTooLarge';
  }
}

/** Fetches the attachment and encodes it; rejects with ImageTooLarge past the cap, or ImagesTooLarge when it fits alone but not beside `attached` (see checkTotal). */
export async function fetchDataUrl(url: string, max = MAX_IMAGE_BYTES, attached: readonly string[] = []): Promise<string> {
  const res = await fetch(url, { credentials: 'same-origin' });
  if (!res.ok) throw new Error(`Fetching the image failed (${res.status}).`);
  return toDataUrl(await res.blob(), max, attached);
}

/** Encodes in 32 KiB runs (String.fromCharCode over the whole buffer would overflow the argument list); no FileReader, so it runs where the tests do. Both checks come before the encoding, so a refused image costs no base64. */
export async function toDataUrl(blob: Blob, max = MAX_IMAGE_BYTES, attached: readonly string[] = []): Promise<string> {
  if (blob.size > max) throw new ImageTooLarge(blob.size, max);
  checkTotal(attached, blob.size, max);
  const bytes = new Uint8Array(await blob.arrayBuffer());
  let bin = '';
  for (let i = 0; i < bytes.length; i += 0x8000) bin += String.fromCharCode(...bytes.subarray(i, i + 0x8000));
  return `data:${blob.type};base64,${btoa(bin)}`;
}

/**
 * A byte count as the media uploader prints one: binary units, at most one decimal, so "4 MB"
 * and "1.5 MB". 'nearest' is for a size; 'down' is for a ceiling, which must never print above
 * its real value: the cap a stock 8M yields is 5.95 MiB, and an image refused just over it also
 * rounds to "6 MB", so printed to nearest the message would say "6 MB" is over "6 MB".
 */
export function formatBytes(bytes: number, mode: 'nearest' | 'down' = 'nearest'): string {
  const units = ['B', 'KB', 'MB', 'GB', 'TB'];
  const fix = mode === 'down' ? Math.floor : Math.round;
  let n = Math.max(0, bytes);
  let i = 0;
  while (n >= 1024 && i < units.length - 1) { n /= 1024; i++; }
  const figure = i === 0 ? String(fix(n)) : (fix(n * 10) / 10).toString();
  return `${figure} ${units[i]}`;
}

/**
 * The cap to use: the figure the payload carries, which wp_localize_script ships as a string, or
 * the constant when it carries none.
 *
 * 0 conflates two things. Assets::maxImageBytes() answers 0 both when it has no figure and when
 * post_max_size is 0, PHP's own spelling of "no limit", and both land here on the constant: so a
 * site with no limit at all gets the tightest guess in the codebase. That is deliberate, and the
 * two halves are only visible together from here. A guard needs a figure; a PHP limit that is
 * genuinely absent still leaves the web server's (which PHP cannot see) and the provider's; and
 * 4 MiB is a floor no site is worse off for. The cost is that the message then names the floor
 * as the site's limit.
 */
export function imageLimit(raw: unknown): number {
  const n = typeof raw === 'string' || typeof raw === 'number' ? Number(raw) : NaN;
  return Number.isFinite(n) && n > 0 ? Math.floor(n) : MAX_IMAGE_BYTES;
}

/**
 * The decoded size of a base64 data URL's payload, from its length alone: four characters
 * carry three bytes and each '=' of padding stands for one byte fewer. This is the arithmetic
 * ImageData::decodedBytes() runs server-side, so the two sides count an attached image the same
 * way. 0 for anything that is not a base64 data URL.
 */
export function decodedBytes(dataUrl: string): number {
  const m = /^data:[^,;]+;base64,([A-Za-z0-9+/]+=*)$/.exec(dataUrl);
  if (!m) return 0;
  const payload = m[1];
  const padding = payload.length - payload.replace(/=+$/, '').length;
  return Math.max(0, Math.floor(payload.length / 4) * 3 - padding);
}

/**
 * The running total: the images already attached plus one more of `size` bytes, held to `max`.
 * Returns the total; throws ImagesTooLarge past the cap. The server holds a turn's images to
 * the same figure as a total (Pipeline::images(), against Assets::maxImageBytes()), so without
 * this a user could assemble, image by image, a turn the server then refuses as a whole. It is
 * a separate function from the single-image check so a lone image past the cap still reports
 * as ImageTooLarge with its own size, which is the more useful message for that case.
 */
export function checkTotal(attached: readonly string[], size: number, max: number): number {
  const total = attached.reduce((sum, url) => sum + decodedBytes(url), 0) + size;
  if (total > max) throw new ImagesTooLarge(total, max);
  return total;
}
