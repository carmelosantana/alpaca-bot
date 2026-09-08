import { test } from 'node:test';
import assert from 'node:assert/strict';
import { parseFrames } from '../../resources/ts/stream.ts';

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
  const { readSse } = await import('../../resources/ts/stream.ts');
  const seen: unknown[] = [];
  for await (const frame of readSse(new Response(body))) seen.push(frame);
  assert.deepEqual(seen, [
    { event: 'start', data: { conversation_id: 7 } },
    { event: 'delta', data: { text: 'hi' } },
    { event: 'done', data: { ok: true } },
  ]);
});
