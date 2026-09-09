import { build, context } from 'esbuild';
import { copyFile, mkdir } from 'node:fs/promises';
import { watch as watchDir } from 'node:fs';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';
import { buildIcons } from './scripts/icons.mjs';
const require = createRequire(import.meta.url);
const here = (p) => fileURLToPath(new URL(p, import.meta.url));
const watch = process.argv.includes('--watch');
async function copyCss() {
  for (const name of ['alpaca-bot.css', 'alpaca-bot-shortcode.css']) {
    await copyFile(here(`resources/css/${name}`), here(`assets/css/${name}`));
    console.log(`css: assets/css/${name}`);
  }
}
await mkdir(here('assets/js'), { recursive: true });
await mkdir(here('assets/css'), { recursive: true });
await copyFile(require.resolve('htmx.org/dist/htmx.min.js'), here('assets/js/htmx.min.js'));
await copyCss();
await buildIcons();
const opts = { entryPoints: [here('resources/ts/chat.ts')], bundle: true, minify: !watch, sourcemap: watch, target: ['es2022'], format: 'iife', outfile: here('assets/js/chat.js'), logLevel: 'info' };
if (watch) {
  const ctx = await context(opts);
  await ctx.watch();
  // esbuild only watches the TS graph; the CSS copy and the sprite live outside it.
  const rerun = (task) => { let t; return () => { clearTimeout(t); t = setTimeout(() => task().catch(console.error), 50); }; };
  const css = rerun(copyCss), icons = rerun(buildIcons);
  watchDir(here('resources/css'), (_, f) => { if (f?.endsWith('.css')) css(); });
  watchDir(here('resources'), (_, f) => { if (f === 'icons.json') icons(); });
} else {
  await build(opts);
}
