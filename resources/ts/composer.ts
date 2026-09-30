/** The composer's half of a turn: the box, the attached image, and putting both back when the turn never ran. */
import { $, el } from './dom.ts';

export interface Draft { text: string; image: string }

/** Fits the textarea to its text. */
export function grow(textarea: HTMLTextAreaElement): void {
  textarea.style.height = 'auto';
  textarea.style.height = textarea.scrollHeight + 'px';
}

/** Puts a data URL in the hidden images field and shows the buttons and preview that go with it; '' clears it. */
export function setImage(form: HTMLFormElement, dataUrl: string): void {
  (form.elements.namedItem('images') as HTMLInputElement).value = dataUrl;
  ($('[data-action="image"]', form) as HTMLElement).hidden = dataUrl !== '';
  ($('[data-action="image-remove"]', form) as HTMLElement).hidden = dataUrl === '';
  $('.ab-composer__preview', form)?.remove();
  if (dataUrl !== '') $('.ab-composer__buttons', form)?.prepend(el('img', { class: 'ab-composer__preview', src: dataUrl, alt: '', height: '40' }));
}

/**
 * A turn that never reached the model, undone: its bubbles out of the transcript and what was
 * typed and attached back in the box. send() reaches this from its `finally`, once, for an exit
 * before the stream is redeemed, through boot.ts giveBack(), which calls it only while the box is
 * still the turn's and empty (Kanboard #4692); the comment in send() says why one place rather
 * than one per exit. A null bubble is one that was never appended, which is why the caller may pass both without
 * checking either.
 */
export function restoreDraft(form: HTMLFormElement, draft: Draft, ...bubbles: (HTMLElement | null)[]): void {
  for (const bubble of bubbles) bubble?.remove();
  const textarea = $<HTMLTextAreaElement>('#ab-message', form) as HTMLTextAreaElement;
  textarea.value = draft.text;
  grow(textarea);
  setImage(form, draft.image);
}
