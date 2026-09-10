import { test } from 'node:test';
import assert from 'node:assert/strict';
import { parseFrames, readSse } from '../../resources/ts/stream.ts';

test('parses complete frames and carries partials', () => {
  const a = parseFrames('event: delta\ndata: {"text":"a"}\n\nevent: de', '');
  assert.deepEqual(a.frames, [{ event: 'delta', data: '{"text":"a"}' }]);
  assert.equal(a.carry, 'event: de');
  const b = parseFrames('lta\ndata: {"text":"b"}\n\n', a.carry);
  assert.deepEqual(b.frames, [{ event: 'delta', data: '{"text":"b"}' }]);
  assert.equal(b.carry, '');
});

test('readSse yields each frame as parsed JSON, including one the server closed on without a blank line', async () => {
  const body = new ReadableStream<Uint8Array>({
    start(controller) {
      const enc = new TextEncoder();
      controller.enqueue(enc.encode('event: start\ndata: {"conversation_id":7}\n\nevent: del'));
      controller.enqueue(enc.encode('ta\ndata: {"text":"hi"}\n\nevent: done\ndata: {"ok":true}'));
      controller.close();
    },
  });
  const seen: unknown[] = [];
  for await (const frame of readSse(new Response(body))) seen.push(frame);
  assert.deepEqual(seen, [
    { event: 'start', data: { conversation_id: 7 } },
    { event: 'delta', data: { text: 'hi' } },
    { event: 'done', data: { ok: true } },
  ]);
});

test('parseFrames edge cases', () => {
  const cases: [name: string, chunk: string, carry: string, frames: { event: string; data: string }[], rest: string][] = [
    ['a data value holding colons', 'event: delta\ndata: {"text":"a: b: c"}\n\n', '', [{ event: 'delta', data: '{"text":"a: b: c"}' }], ''],
    ['multiple data lines join with a newline', 'data: one\ndata: two\n\n', '', [{ event: 'message', data: 'one\ntwo' }], ''],
    ['CRLF line endings', 'event: done\r\ndata: {"ok":true}\r\n\r\n', '', [{ event: 'done', data: '{"ok":true}' }], ''],
    ['no event line defaults to message', 'data: x\n\n', '', [{ event: 'message', data: 'x' }], ''],
    ['a frame with no data line is skipped', 'event: ping\n\n: comment\n\ndata: y\n\n', '', [{ event: 'message', data: 'y' }], ''],
    ['only the first space after data: is stripped', 'data:  two spaces\n\n', '', [{ event: 'message', data: ' two spaces' }], ''],
    ['no space after data:', 'data:tight\n\n', '', [{ event: 'message', data: 'tight' }], ''],
    ['a split inside the blank line carries one newline', 'data: a\n', '', [], 'data: a\n'],
    ['the carried newline completes the frame on the next chunk', '\ndata: b', 'data: a\n', [{ event: 'message', data: 'a' }], 'data: b'],
    ['a split inside "data:" itself', 'ta: z\n\n', 'event: delta\nda', [{ event: 'delta', data: 'z' }], ''],
    ['a CRLF split between CR and LF', '\n\r\ndata: q', 'data: p\r', [{ event: 'message', data: 'p' }], 'data: q'],
    ['empty chunk with empty carry', '', '', [], ''],
  ];
  for (const [name, chunk, carry, frames, rest] of cases) {
    const got = parseFrames(chunk, carry);
    assert.deepEqual(got.frames, frames, name);
    assert.equal(got.carry, rest, name + ' (carry)');
  }
});

test('readSse cancels the body when the consumer returns early', async () => {
  let cancelled = false;
  const enc = new TextEncoder();
  const body = new ReadableStream<Uint8Array>({
    start(controller) {
      controller.enqueue(enc.encode('event: done\ndata: {"ok":true}\n\nevent: delta\ndata: {"text":"late"}\n\n'));
      // Never closed: a server that holds the connection open after done.
    },
    cancel() { cancelled = true; },
  });
  for await (const frame of readSse(new Response(body))) {
    if (frame.event === 'done') break;
  }
  assert.equal(cancelled, true);
});
