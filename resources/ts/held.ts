/**
 * A faked tool call's markup, kept out of sight while it streams (Kanboard #4329).
 *
 * The server marks every byte from an opening `<tool_call>` through its closing marker `held`
 * and still sends it (docs/api.md section 4), so this is where it is hidden rather than dropped:
 * each held run goes into a hidden span at its own place in the text, and a "calling a tool" line
 * shows under the text while the latest text is held. `done` replaces the bubble with the stored
 * reply, which is the parsed answer; a stream that ends any other way shows every held run
 * exactly where it arrived (releaseHeld()), so no text is lost and none moves.
 */
import { $, $$, el } from './dom.ts';

/**
 * Appends one delta's text to a streaming bubble's content.
 *
 * The tool line tracks the *latest* delta, not whether a call happened: held text puts it up,
 * the next unheld text takes it down again. A run of markup that ends and gives way to ordinary
 * prose therefore stops advertising a call that is over.
 *
 * An empty delta returns before either, and that is the point rather than an oversight. The
 * server sends an empty `text` for every `agent.tool_call` as a heartbeat (Delta's docblock says
 * why), carrying whatever the hold state is: `true` inside an open block, `false` once a closing
 * marker has arrived and before ordinary text resumes — which is the moment an unheld empty
 * delta would take the line down while the call it announces is still running. boot.ts filters
 * empty text before it calls this, so the return is a second guard rather than the only one; it
 * belongs here, where the line is put up and taken down, and not in the caller.
 */
export function appendText(content: HTMLElement, text: string, held: boolean, callingTool: string): void {
  if (text === '') return;
  const bubble = content.closest<HTMLElement>('.ab-msg') ?? content;
  if (!held) {
    content.append(text);
    $('.ab-msg__tool', bubble)?.remove();
    return;
  }
  const last = content.lastChild as Element | null;
  const open = last?.nodeType === 1 && last.matches('span.ab-msg__held') ? last as HTMLElement : content.appendChild(el('span', { class: 'ab-msg__held', hidden: '' }));
  open.append(text);
  if (!$('.ab-msg__tool', bubble)) content.after(el('p', { class: 'ab-msg__tool', role: 'status' }, callingTool));
}

/** A stream that ended without `done`: every held run shown as it arrived, and the tool line gone. */
export function releaseHeld(bubble: HTMLElement): void {
  for (const span of $$('span.ab-msg__held', bubble)) span.hidden = false;
  $('.ab-msg__tool', bubble)?.remove();
}
