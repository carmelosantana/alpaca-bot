import { test } from 'node:test';
import assert from 'node:assert/strict';
import { installDom } from './env.ts';

/**
 * Every assertion here compares a primitive — a count, a string, a boolean — and never a DOM
 * node. That is not style. node:test renders a failed assertion by serialising both operands,
 * and a happy-dom element's graph reaches its parent, its document and its window, so a single
 * `assert.equal(el, null)` that fails allocates until the process is killed. One did: it took
 * ~49 GB and an OOM kill before it printed anything. `querySelectorAll(...).length` fails in
 * milliseconds and says the same thing.
 */

const BUBBLE = '<article class="ab-msg ab-msg--assistant" data-streaming="1"><div class="ab-msg__body"><div class="ab-msg__content" aria-live="polite"></div></div></article>';
const TOOL = 'Calling a tool…';

const tools = (): number => document.querySelectorAll('.ab-msg__tool').length;
const held = (): NodeListOf<HTMLElement> => document.querySelectorAll('span.ab-msg__held');

test('held text goes into a hidden span in its own place, with the calling-a-tool line showing, and plain text afterwards takes the line away', async () => {
  installDom(BUBBLE);
  const { appendText } = await import('../../resources/ts/held.ts');
  const content = document.querySelector('.ab-msg__content') as HTMLElement;

  appendText(content, 'Checking. ', false, TOOL);
  assert.equal(tools(), 0, 'unheld text alone raises no tool line');

  appendText(content, '<tool_call>{"name":', true, TOOL);
  appendText(content, ' "web_fetch"}</tool_call>', true, TOOL);

  // Consecutive held deltas join one span rather than fragmenting: the run is one hidden thing.
  assert.equal(held().length, 1);
  assert.equal(held()[0].textContent, '<tool_call>{"name": "web_fetch"}</tool_call>');
  assert.equal(held()[0].hidden, true);
  assert.equal(tools(), 1, 'held text shows the line');

  // The bytes are in the DOM in their place, only out of sight — merge point 3's "every byte
  // still ships" is visible here as text that is present and unmoved.
  assert.equal(content.textContent, 'Checking. <tool_call>{"name": "web_fetch"}</tool_call>');

  appendText(content, '\n\nThe page says hello.', false, TOOL);
  assert.equal(tools(), 0, 'the line tracks the latest delta, not whether a call ever happened');
  assert.equal(held().length, 1, 'the held run stays where it arrived');
  assert.equal(content.textContent, 'Checking. <tool_call>{"name": "web_fetch"}</tool_call>\n\nThe page says hello.');
});

test('a stream that ends without done shows every held run verbatim, in place, and drops the tool line', async () => {
  installDom(BUBBLE);
  const { appendText, releaseHeld } = await import('../../resources/ts/held.ts');
  const bubble = document.querySelector('.ab-msg') as HTMLElement;
  const content = document.querySelector('.ab-msg__content') as HTMLElement;

  appendText(content, 'Answer: ', false, TOOL);
  appendText(content, '<tool_call>{"name": "done", "argu', true, TOOL);
  assert.equal(held()[0].hidden, true);
  assert.equal(tools(), 1);

  releaseHeld(bubble);

  // Unclosed markup and all: the run is shown exactly as it arrived, and nothing moves.
  assert.equal(held().length, 1);
  assert.equal(held()[0].hidden, false);
  assert.equal(content.textContent, 'Answer: <tool_call>{"name": "done", "argu');
  assert.equal(tools(), 0);
});

test('an empty delta renders nothing, held or not: the heartbeat frame shows no tool line of its own', async () => {
  installDom(BUBBLE);
  const { appendText } = await import('../../resources/ts/held.ts');
  const content = document.querySelector('.ab-msg__content') as HTMLElement;

  appendText(content, '', true, TOOL);
  appendText(content, '', false, TOOL);

  assert.equal(content.childNodes.length, 0);
  assert.equal(held().length, 0);
  assert.equal(tools(), 0);
});

test('an empty delta leaves a tool line that is already up alone, whichever flag it carries', async () => {
  installDom(BUBBLE);
  const { appendText } = await import('../../resources/ts/held.ts');
  const content = document.querySelector('.ab-msg__content') as HTMLElement;

  // AgentStreamObserver pushes one empty Delta per `agent.tool_call`, flagged with whatever the
  // hold state is — `true` inside an open block, `false` once its closing marker has arrived and
  // before ordinary text resumes. Neither may be read as "ordinary text resumed", or the line
  // this feature exists to show goes off while the call is still running.
  appendText(content, '<tool_call>{"name": "web_fetch"}', true, TOOL);
  assert.equal(tools(), 1);
  appendText(content, '', true, TOOL);
  assert.equal(tools(), 1, 'a held heartbeat leaves the line up');
  appendText(content, '', false, TOOL);
  assert.equal(tools(), 1, 'so does an unheld one');
  // And nothing of the empty deltas landed in the text.
  assert.equal(content.textContent, '<tool_call>{"name": "web_fetch"}');
});
