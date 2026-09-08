/**
 * The attached image as POST /chat takes it: a base64 data URL. The picker hands over an
 * attachment URL (its `large` size where the site registers one, else the original file), and
 * the bytes are fetched and encoded here. They then travel twice inside a JSON body (the
 * optimistic user bubble, then the turn), and neither route caps them: past PHP's
 * post_max_size the body is dropped before WordPress sees it and the only answer is a bare
 * failure. So the cap is applied here, before encoding, with a message that says what to do.
 */
export const MAX_IMAGE_BYTES = 4 * 1024 * 1024;

export class ImageTooLarge extends Error {
  readonly size: number; // (not a parameter property: node --test strips types and cannot rewrite one)
  constructor(size: number) {
    super(`The image is ${size} bytes; at most ${MAX_IMAGE_BYTES} are sent.`);
    this.name = 'ImageTooLarge';
    this.size = size;
  }
}

/** Fetches the attachment and encodes it; rejects with ImageTooLarge past the cap. */
export async function fetchDataUrl(url: string): Promise<string> {
  const res = await fetch(url, { credentials: 'same-origin' });
  if (!res.ok) throw new Error(`Fetching the image failed (${res.status}).`);
  return toDataUrl(await res.blob());
}

/** Encodes in 32 KiB runs (String.fromCharCode over the whole buffer would overflow the argument list); no FileReader, so it runs where the tests do. */
export async function toDataUrl(blob: Blob): Promise<string> {
  if (blob.size > MAX_IMAGE_BYTES) throw new ImageTooLarge(blob.size);
  const bytes = new Uint8Array(await blob.arrayBuffer());
  let bin = '';
  for (let i = 0; i < bytes.length; i += 0x8000) bin += String.fromCharCode(...bytes.subarray(i, i + 0x8000));
  return `data:${blob.type};base64,${btoa(bin)}`;
}
