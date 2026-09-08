import { build, context } from 'esbuild';
import { copyFile, mkdir } from 'node:fs/promises';
import { createRequire } from 'node:module';
const require = createRequire(import.meta.url);
const watch = process.argv.includes('--watch');
await mkdir('assets/js', { recursive: true });
await mkdir('assets/css', { recursive: true });
await copyFile(require.resolve('htmx.org/dist/htmx.min.js'), 'assets/js/htmx.min.js');
await copyFile('resources/css/alpaca-bot.css', 'assets/css/alpaca-bot.css');
await import('./scripts/icons.mjs');
const opts = { entryPoints: ['resources/ts/chat.ts'], bundle: true, minify: !watch, sourcemap: watch, target: ['es2022'], format: 'iife', outfile: 'assets/js/chat.js', logLevel: 'info' };
if (watch) { const ctx = await context(opts); await ctx.watch(); } else { await build(opts); }
