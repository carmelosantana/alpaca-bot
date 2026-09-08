import { test } from 'node:test';
import assert from 'node:assert/strict';
import { ImageTooLarge, ImagesTooLarge, MAX_IMAGE_BYTES, checkTotal, decodedBytes, formatBytes, imageLimit, toDataUrl } from '../../resources/ts/image.ts';

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

test('the cap the server ships wins over the constant, and the error carries both figures', async () => {
  const cap = 1024;
  await assert.rejects(toDataUrl(new Blob([new Uint8Array(cap + 1)], { type: 'image/png' }), cap), (e: unknown) => e instanceof ImageTooLarge && e.size === cap + 1 && e.max === cap);
  await assert.doesNotReject(toDataUrl(new Blob([new Uint8Array(cap)], { type: 'image/png' }), cap));
  // Without a figure from the server, the constant still guards.
  const e = await toDataUrl(new Blob([new Uint8Array(MAX_IMAGE_BYTES + 1)], { type: 'image/png' })).catch((e: unknown) => e);
  assert.ok(e instanceof ImageTooLarge && e.max === MAX_IMAGE_BYTES);
});

test('formatBytes reads as the media uploader does: binary units, one decimal at most, no trailing zero', () => {
  assert.equal(formatBytes(0), '0 B');
  assert.equal(formatBytes(750), '750 B');
  assert.equal(formatBytes(1024), '1 KB');
  assert.equal(formatBytes(768 * 1024), '768 KB');
  assert.equal(formatBytes(4 * 1024 * 1024), '4 MB');
  assert.equal(formatBytes(1.5 * 1024 * 1024), '1.5 MB');
  assert.equal(formatBytes(4_500_000), '4.3 MB');
  assert.equal(formatBytes(2 * 1024 ** 3), '2 GB');
});

test("formatBytes 'down' truncates, so a stated ceiling is never above the real one (the 8M cap is 5.95 MiB: 'nearest' says 6, 'down' says 5.9)", () => {
  assert.equal(formatBytes(6242304), '6 MB');
  assert.equal(formatBytes(6242304, 'down'), '5.9 MB');
  assert.equal(formatBytes(4 * 1024 * 1024, 'down'), '4 MB');
  assert.equal(formatBytes(1023, 'down'), '1023 B');
  // An image refused just over the cap rounds to the same "6 MB" the cap would; with 'down' on the cap the two figures differ.
  assert.equal(formatBytes(6300000), '6 MB');
  assert.notEqual(formatBytes(6300000), formatBytes(6242304, 'down'));
});

test('imageLimit reads the figure wp_localize_script ships (a string), and falls back to the constant for anything that is not a positive number', () => {
  assert.equal(imageLimit('2097152'), 2097152);
  assert.equal(imageLimit(1024), 1024);
  for (const raw of ['0', 0, '', undefined, null, 'abc', -5, NaN, Infinity]) assert.equal(imageLimit(raw), MAX_IMAGE_BYTES, String(raw));
});

// ---- the running total: a turn's images together are held to the same cap the server holds them to

test('decodedBytes reads a data URL\'s payload size from its base64 length, as ImageData::decodedBytes() does server-side', () => {
  assert.equal(decodedBytes('data:image/png;base64,AAAA'), 3);
  assert.equal(decodedBytes('data:image/png;base64,AAA='), 2);
  assert.equal(decodedBytes('data:image/png;base64,AA=='), 1);
  assert.equal(decodedBytes('data:image/png;base64,iVBORw=='), 4);
  for (const bad of ['', 'data:image/png,%89PNG', 'https://example.com/x.png']) assert.equal(decodedBytes(bad), 0, bad);
});

test('checkTotal accepts a running total at the cap and refuses one byte over, naming the total and the cap', () => {
  const cap = 300;
  const atCapLessOne = 'data:image/png;base64,' + 'A'.repeat(4 * 100 - 4) + 'AAA=';
  assert.equal(decodedBytes(atCapLessOne), cap - 1);
  assert.equal(checkTotal([atCapLessOne], 1, cap), cap);
  assert.equal(checkTotal([], cap, cap), cap);
  assert.throws(() => checkTotal([atCapLessOne], 2, cap), (e: unknown) => e instanceof ImagesTooLarge && e.size === cap + 1 && e.max === cap);
  // The sibling carries the single-image error's shape, so one message template formats either.
  assert.throws(() => checkTotal([atCapLessOne], 2, cap), (e: unknown) => e instanceof ImageTooLarge);
});

test('toDataUrl holds a second image to the running total before encoding it, and still reports a lone oversized image as its own size', async () => {
  const cap = 1024;
  const first = await toDataUrl(new Blob([new Uint8Array(cap - 1)], { type: 'image/png' }), cap);
  await assert.doesNotReject(toDataUrl(new Blob([new Uint8Array(1)], { type: 'image/png' }), cap, [first]));
  await assert.rejects(toDataUrl(new Blob([new Uint8Array(2)], { type: 'image/png' }), cap, [first]), (e: unknown) => e instanceof ImagesTooLarge && e.size === cap + 1 && e.max === cap);
  // Past the cap on its own it is the single-image refusal, whatever is already attached.
  await assert.rejects(toDataUrl(new Blob([new Uint8Array(cap + 1)], { type: 'image/png' }), cap, [first]), (e: unknown) => e instanceof ImageTooLarge && !(e instanceof ImagesTooLarge) && e.size === cap + 1);
});
