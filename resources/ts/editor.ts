/**
 * The chat in the block editor (Admin\Drawer::enqueueEditor()). What it does is editor-start.ts's;
 * this is the entry esbuild builds, and runs when the page loads it.
 */
import { startEditor, type EditorWp } from './editor-start.ts';

const wp = (window as unknown as { wp?: Partial<EditorWp> & { editor?: { PluginSidebar?: unknown } } }).wp;
const cfg = window.alpacaBotMount;
if (cfg && wp?.plugins && wp.editor?.PluginSidebar && wp.element && wp.data) startEditor(cfg, wp as EditorWp);
