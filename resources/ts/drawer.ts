/**
 * The admin-wide drawer's loader (Admin\Drawer). What it does is drawer-start.ts's; this is the
 * entry esbuild builds, and runs when the page loads it.
 */
import { startDrawer } from './drawer-start.ts';

/**
 * Run once the document is parsed: this is a footer script, and a screen's media library may be
 * printed after it, so `wp.media` is read only once every footer script has run.
 */
function run(): void {
  const cfg = window.alpacaBotMount;
  const launcher = document.getElementById('ab-drawer-launcher');
  const host = document.getElementById('ab-drawer');
  if (cfg && launcher && host) startDrawer(cfg, launcher, host);
}
if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', run);
else run();
