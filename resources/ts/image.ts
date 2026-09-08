/**
 * The attached image as POST /chat takes it: a base64 data URL. The picker hands over an
 * attachment URL (its `large` size where the site registers one, else the original file), and
 * the bytes are fetched and encoded here. They then travel twice inside a JSON body (the
 * optimistic user bubble, then the turn), and neither route caps them: past PHP's
 * post_max_size the body is dropped before WordPress sees it and the only answer is a bare
 * failure. So the cap is applied here, before encoding, with a message that says what to do.
 *
 * The cap is the site's own, shipped by Admin\Assets as `maxImageBytes` (the smaller of the
 * upload limit and post_max_size); the constant is the fallback for a payload without one, a
 * guess against a default 8M post_max_size.
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

/** Fetches the attachment and encodes it; rejects with ImageTooLarge past the cap. */
export async function fetchDataUrl(url: string, max = MAX_IMAGE_BYTES): Promise<string> {
  const res = await fetch(url, { credentials: 'same-origin' });
  if (!res.ok) throw new Error(`Fetching the image failed (${res.status}).`);
  return toDataUrl(await res.blob(), max);
}

/** Encodes in 32 KiB runs (String.fromCharCode over the whole buffer would overflow the argument list); no FileReader, so it runs where the tests do. */
export async function toDataUrl(blob: Blob, max = MAX_IMAGE_BYTES): Promise<string> {
  if (blob.size > max) throw new ImageTooLarge(blob.size, max);
  const bytes = new Uint8Array(await blob.arrayBuffer());
  let bin = '';
  for (let i = 0; i < bytes.length; i += 0x8000) bin += String.fromCharCode(...bytes.subarray(i, i + 0x8000));
  return `data:${blob.type};base64,${btoa(bin)}`;
}

/** A byte count as the media uploader prints one: binary units, at most one decimal, so "4 MB" and "1.5 MB". */
export function formatBytes(bytes: number): string {
  const units = ['B', 'KB', 'MB', 'GB', 'TB'];
  let n = Math.max(0, bytes);
  let i = 0;
  while (n >= 1024 && i < units.length - 1) { n /= 1024; i++; }
  const figure = i === 0 ? String(Math.round(n)) : (Math.round(n * 10) / 10).toString();
  return `${figure} ${units[i]}`;
}

/** The cap to use: the figure the payload carries, which wp_localize_script ships as a string, or the constant when it carries none (0 is "no figure": Assets::maxImageBytes()). */
export function imageLimit(raw: unknown): number {
  const n = typeof raw === 'string' || typeof raw === 'number' ? Number(raw) : NaN;
  return Number.isFinite(n) && n > 0 ? Math.floor(n) : MAX_IMAGE_BYTES;
}
