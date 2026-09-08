/**
 * Server-sent events off a fetch() body. EventSource cannot send the X-WP-Nonce header, so the
 * stream is read by hand: bytes are decoded as they arrive, split into frames on the blank
 * line, and each frame's `event:` and `data:` lines are picked out. A frame that has not
 * finished arriving is carried into the next chunk.
 */
export interface Frame { event: string; data: string }

export function parseFrames(chunk: string, carry: string): { frames: Frame[]; carry: string } {
  const parts = (carry + chunk).replace(/\r\n/g, '\n').split('\n\n');
  const rest = parts.pop() ?? '';
  const frames: Frame[] = [];
  for (const part of parts) {
    let event = 'message';
    const data: string[] = [];
    for (const line of part.split('\n')) {
      if (line.startsWith('event:')) event = line.slice(6).trim();
      else if (line.startsWith('data:')) data.push(line.slice(5).replace(/^ /, ''));
    }
    if (data.length > 0) frames.push({ event, data: data.join('\n') });
  }
  return { frames, carry: rest };
}

export async function* readSse(res: Response): AsyncGenerator<{ event: string; data: unknown }> {
  if (!res.body) throw new Error('The stream has no body.');
  const reader = res.body.getReader();
  const decoder = new TextDecoder();
  let carry = '';
  for (;;) {
    const { value, done } = await reader.read();
    if (done) break;
    const parsed = parseFrames(decoder.decode(value, { stream: true }), carry);
    carry = parsed.carry;
    for (const frame of parsed.frames) yield { event: frame.event, data: JSON.parse(frame.data) };
  }
  // A last frame the server closed on without its blank line.
  for (const frame of parseFrames('\n\n', carry).frames) yield { event: frame.event, data: JSON.parse(frame.data) };
}
