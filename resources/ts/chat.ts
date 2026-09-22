/**
 * The chat screen's browser layer. The markup is the server's (View\Chat\*), htmx swaps the
 * selects, and the bundle does what neither can: the streamed turn (POST /chat for a ticket,
 * then its stream_url read as server-sent events into a bubble), the keyboard, the copy and
 * edit actions, the image picker, the offline guard (Kanboard #473) and the nonce refresh
 * (Kanboard #302). It never writes an hx-* attribute: what a request needs changed at send
 * time (the nonce header, the history select's conversation id) is changed on the
 * htmx:configRequest event instead. Every fragment it inserts came from a /view/* route.
 *
 * All of that is boot.ts and the modules it imports, so node:test can reach each piece
 * (tests/ts/chat.test.ts, Kanboard #4334). This file is the entry esbuild builds and decides one
 * thing: whether this page has a shell to boot.
 */
import { boot } from './boot.ts';
import { $ } from './dom.ts';

const settings = window.alpacaBot;
const form = $<HTMLFormElement>('#ab-form');
if (settings && form) boot(settings, form);
