/**
 * The turn's `context` (docs/api.md, POST /chat), read off the composer's chips
 * (View\Chat\Composer): a key is sent while its chip's hidden fields are in the form, and not
 * once the chip is taken off, which is the whole of "removable before sending". A post id that
 * is not a positive integer, and a screen missing its id or its title, are not sent. Nothing here
 * decides what the model may see: Context\CurrentScreenSource does that on the server, for the
 * turn's user, whatever arrives.
 */
import { asId } from './dom.ts';

export function contextFrom(form: HTMLFormElement): Record<string, unknown> {
  const value = (name: string): string | null => (form.elements.namedItem(name) as HTMLInputElement | null)?.value ?? null;
  const context: Record<string, unknown> = {};
  const post = asId(value('context[post_id]'));
  if (post !== null && post > 0) context.post_id = post;
  const id = value('context[screen][id]');
  const title = value('context[screen][title]');
  if (id !== null && title !== null) context.screen = { id, title };
  return context;
}
