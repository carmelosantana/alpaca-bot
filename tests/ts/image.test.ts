import { test } from 'node:test';
import assert from 'node:assert/strict';
import { ImageTooLarge, MAX_IMAGE_BYTES, toDataUrl } from '../../resources/ts/image.ts';

test('an in-limit image becomes a base64 data URL carrying its mime', async () => {
  const url = await toDataUrl(new Blob([new Uint8Array([0x89, 0x50, 0x4e, 0x47])], { type: 'image/png' }));
  assert.equal(url, 'data:image/png;base64,iVBORw==');
});

test('an image over the cap is refused before it is encoded, with its size on the error', async () => {
  const blob = new Blob([new Uint8Array(MAX_IMAGE_BYTES + 1)], { type: 'image/jpeg' });
  await assert.rejects(toDataUrl(blob), (e: unknown) => e instanceof ImageTooLarge && e.size === MAX_IMAGE_BYTES + 1);
  // Exactly at the cap is still sent.
  await assert.doesNotReject(toDataUrl(new Blob([new Uint8Array(MAX_IMAGE_BYTES)], { type: 'image/jpeg' })));
});
