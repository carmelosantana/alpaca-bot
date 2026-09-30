import { Window } from 'happy-dom';

/**
 * A document for node:test, installed as globals because that is where resources/ts finds one in
 * a page: dom.ts reads `document` as the default root, and boot.ts reads `document`, `window`,
 * `location`, `navigator` and `getComputedStyle`. The DOM classes are installed beside them for
 * the tests' sake, so a case can write `new CustomEvent(...)` or an `instanceof` without reaching
 * back through the window; resources/ts names them in types only, which are erased.
 *
 * One thing boot.ts reads is not here at all: `document.execCommand`, which happy-dom 20.14.5
 * does not implement (the plan says it does; it does not). writeClipboard()'s fallback calls it
 * inside a try/catch of its own, so under this DOM the TypeError is swallowed and the fallback
 * quietly reports failure instead of throwing — which reads as a product bug and is not one. A
 * test of that fallback must stub `document.execCommand` itself.
 *
 * There is no teardown: the globals stay installed for the rest of the file. Two things make
 * that safe, and the second is the one to keep. node:test gives each test file its own process,
 * so nothing leaks between files; and no module a test imports from resources/ts captures a
 * global at import time — dom.ts's `root: ParentNode = document` is a default parameter,
 * evaluated per call, not at load — so a second installDom() in one file really does give the
 * next case a clean document. The entries esbuild builds (build.mjs's entryPoints) do read the
 * page as they load, which is what an entry is for, and no test imports one. An import-time
 * capture in a module a test does import would bind the first document into a module every later
 * case then shares, and this harness would stop being honest.
 *
 * Never assert on a node from this document. Compare a primitive: a count from
 * `querySelectorAll(...).length`, a `textContent`, a boolean. node:test renders a failed
 * assertion by serialising both operands, and a happy-dom element's graph reaches its parent,
 * its document and its window, so one `assert.equal(el, null)` that happens to fail allocates
 * until the process dies. One did, on 2026-09-22: ~49 GB resident and an OOM kill that took
 * the whole session with it, before the assertion printed anything. The same check written as
 * `assert.equal(document.querySelectorAll('.x').length, 0)` fails in milliseconds and says more.
 * The cost is paid only when a test fails, which is exactly when the output has to survive.
 *
 * Script evaluation and file loading stay off — the fixtures are markup, and a DOM that ran a
 * <script> would be running code no test reviewed. That switch is a narrowing, not a sandbox:
 * happy-dom's isolation has been weaker than it looked more than once (GHSA-qpm2-6cq5-7pq5), so
 * what makes the fixtures safe is that this repo wrote them, not that the library would contain
 * them. fetch and Response stay Node's own, which is what the bundle meets in a browser too.
 */
export function installDom(body: string, url = 'https://alpaca-bot.test/wp-admin/'): Window {
  const window = new Window({
    url,
    settings: { disableJavaScriptEvaluation: true, disableJavaScriptFileLoading: true, disableCSSFileLoading: true },
  });
  window.document.body.innerHTML = body;
  const source = window as unknown as Record<string, unknown>;
  for (const name of ['document', 'location', 'Element', 'Node', 'HTMLElement', 'HTMLFormElement', 'HTMLInputElement', 'HTMLTextAreaElement', 'HTMLDetailsElement', 'Event', 'CustomEvent', 'KeyboardEvent', 'MouseEvent', 'getComputedStyle']) {
    const value = source[name];
    // A method is bound so it keeps its window. The only one here, getComputedStyle, happens not
    // to need it in happy-dom 20.14.5, but the next lowercase name added to this list may; a
    // constructor must not be bound either way.
    Object.defineProperty(globalThis, name, { value: typeof value === 'function' && name[0] === name[0]!.toLowerCase() ? value.bind(window) : value, configurable: true, writable: true });
  }
  Object.defineProperty(globalThis, 'window', { value: window, configurable: true, writable: true });
  // Node has a navigator of its own with no onLine, and send() refuses to run while it is falsy.
  Object.defineProperty(globalThis, 'navigator', { value: window.navigator, configurable: true, writable: true });
  return window;
}

/** Waits for `check`, which the bundle's own awaits will make true a few microtasks from now. */
export async function until(check: () => boolean, tries = 400): Promise<void> {
  for (let i = 0; i < tries; i++) {
    if (check()) return;
    await new Promise((resolve) => setTimeout(resolve, 5));
  }
  throw new Error('the condition never held');
}
