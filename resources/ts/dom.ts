/** The few DOM helpers chat.ts leans on, and the client-side twin of View\Chat\Notice. */
export const $ = <T extends Element = HTMLElement>(sel: string, root: ParentNode = document): T | null => root.querySelector<T>(sel);
export const $$ = <T extends Element = HTMLElement>(sel: string, root: ParentNode = document): T[] => Array.from(root.querySelectorAll<T>(sel));

export function el<K extends keyof HTMLElementTagNameMap>(tag: K, attrs: Record<string, string> = {}, ...children: (Node | string)[]): HTMLElementTagNameMap[K] {
  const node = document.createElement(tag);
  for (const [k, v] of Object.entries(attrs)) node.setAttribute(k, v);
  node.append(...children);
  return node;
}

/** A `<use>` into the Lucide sprite the shell inlines, as View\Icon writes it. */
export function icon(name: string): SVGSVGElement {
  const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
  svg.setAttribute('class', 'ab-icon');
  svg.setAttribute('aria-hidden', 'true');
  svg.setAttribute('focusable', 'false');
  const use = document.createElementNS('http://www.w3.org/2000/svg', 'use');
  use.setAttribute('href', '#lucide-' + name);
  svg.append(use);
  return svg;
}

/** Turns a chunk of trusted server HTML (a /view/* fragment) into its first element. */
export function fromHtml(html: string): HTMLElement | null {
  const tpl = document.createElement('template');
  tpl.innerHTML = html.trim();
  return tpl.content.firstElementChild as HTMLElement | null;
}

const KINDS = ['success', 'error', 'warning', 'info'];

/** Writes the markup View\Chat\Notice renders into #ab-status; an empty text clears it. */
export function notice(kind: string, text: string, iconName?: string): void {
  const status = $('#ab-status');
  if (!status) return;
  status.replaceChildren();
  if (text === '') return;
  const p = el('p', {}, text);
  if (iconName) p.prepend(icon(iconName), ' ');
  status.append(el('div', { class: 'notice notice-' + (KINDS.includes(kind) ? kind : 'info') + ' inline' }, p));
}
