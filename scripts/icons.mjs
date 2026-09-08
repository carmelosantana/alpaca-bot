import { readFile, writeFile, mkdir } from 'node:fs/promises';
import { createRequire } from 'node:module';
const require = createRequire(import.meta.url);
const names = JSON.parse(await readFile(new URL('../resources/icons.json', import.meta.url), 'utf8'));
let symbols = '';
for (const name of names) {
  const svg = await readFile(require.resolve(`lucide-static/icons/${name}.svg`), 'utf8');
  const inner = svg.replace(/^[\s\S]*?<svg[^>]*>/, '').replace(/<\/svg>\s*$/, '').trim();
  symbols += `<symbol id="lucide-${name}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">${inner}</symbol>`;
}
await mkdir(new URL('../assets/img/', import.meta.url), { recursive: true });
await writeFile(new URL('../assets/img/icons.svg', import.meta.url), `<svg xmlns="http://www.w3.org/2000/svg" style="display:none">${symbols}</svg>\n`);
console.log(`icons: ${names.length} symbols`);
