<?php

declare(strict_types=1);

namespace AlpacaBot\View\Chat;

use AlpacaBot\Settings\Store;
use AlpacaBot\View\Component;
use AlpacaBot\View\Icon;

/**
 * The message form at the foot of the screen. It carries no htmx: sending is a streamed
 * request that chat.ts drives (POST /chat, then the SSE ticket), so the form's job is to hold
 * the fields chat.ts reads: the text, the conversation and model, the attached image as a
 * data URL, and the context chips (chip()), the post being edited and the screen the drawer
 * is on, each carrying its own hidden fields so that taking a chip off takes its key off the
 * turn. The nonce is not among them: every request carries it as the X-WP-Nonce header from
 * the localised settings (Admin\Assets), which nonce.ts refreshes.
 */
final class Composer extends Component
{
    /**
     * @param int $postId the post being edited, else 0 for no post chip; the caller has decided the user may see it
     * @param string $postTitle the post's title for its chip, as text; '' is shown as "(no title)"
     * @param array{id: string, title: string}|null $screen the screen the chat was opened on, as Context\CurrentScreenSource::screenFrom() cleaned it; null renders no screen chip
     */
    public function __construct(private Store $store, private int $conversationId, private string $model, private int $postId = 0, private string $postTitle = '', private ?array $screen = null) {}

    public function render(): string
    {
        $textarea = $this->tag('textarea', ['name' => 'message', 'id' => 'ab-message', 'rows' => '1', 'required' => 'required', 'placeholder' => (string) $this->store->get('chat.placeholder'), 'spellcheck' => $this->store->get('chat.spellcheck') ? 'true' : 'false', 'aria-label' => __('Message', 'alpaca-bot')]);
        $hidden = $this->tag('input', ['type' => 'hidden', 'name' => 'conversation_id', 'value' => (string) $this->conversationId])
            . $this->tag('input', ['type' => 'hidden', 'name' => 'model', 'value' => $this->model])
            . $this->tag('input', ['type' => 'hidden', 'name' => 'images', 'value' => '']);
        $chips = '';
        if ($this->postId > 0) {
            /* translators: %s: the title of the post being edited */
            $chips .= $this->chip('post', sprintf(__('Editing: %s', 'alpaca-bot'), $this->postTitle !== '' ? $this->postTitle : __('(no title)', 'alpaca-bot')), ['context[post_id]' => (string) $this->postId]);
        }
        if ($this->screen !== null) {
            /* translators: %s: the title of the admin screen the chat was opened on, such as "Posts" */
            $chips .= $this->chip('screen', sprintf(__('On: %s', 'alpaca-bot'), $this->screen['title']), ['context[screen][id]' => $this->screen['id'], 'context[screen][title]' => $this->screen['title']]);
        }
        if ($chips !== '') {
            $chips = $this->tag('div', ['class' => 'ab-composer__chips', 'role' => 'group', 'aria-label' => __('What this chat can see', 'alpaca-bot')], $chips);
        }
        $buttons = $this->tag('button', ['type' => 'button', 'class' => 'ab-btn ab-btn--icon', 'data-action' => 'image', 'aria-label' => __('Attach an image', 'alpaca-bot')], Icon::svg('image'))
            . $this->tag('button', ['type' => 'button', 'class' => 'ab-btn ab-btn--icon', 'data-action' => 'image-remove', 'aria-label' => __('Remove image', 'alpaca-bot'), 'hidden' => 'hidden'], Icon::svg('image-off'))
            . $this->tag('button', ['type' => 'submit', 'class' => 'ab-btn ab-btn--send', 'data-action' => 'send', 'aria-label' => __('Send', 'alpaca-bot')], Icon::svg('send-horizontal'));
        return $this->tag('form', ['id' => 'ab-form', 'class' => 'ab-composer', 'autocomplete' => 'off'], $hidden . $chips . $this->tag('div', ['class' => 'ab-composer__row'], $textarea . $this->tag('div', ['class' => 'ab-composer__buttons'], $buttons)));
    }

    /**
     * One context chip: its label, the hidden fields that carry its key on the turn, and a button
     * that takes the chip off. The fields are inside the chip, so removing the chip removes them,
     * and the turn's `context` is read from the fields the form still holds
     * (resources/ts/context.ts): a removed chip is gone from the page, not remembered anywhere,
     * and the next render of the composer has it again.
     *
     * @param array<string, string> $fields input name => value
     */
    private function chip(string $kind, string $label, array $fields): string
    {
        $inputs = '';
        foreach ($fields as $name => $value) {
            $inputs .= $this->tag('input', ['type' => 'hidden', 'name' => $name, 'value' => $value]);
        }
        /* translators: %s: a context chip's label, such as "On: Posts" */
        $remove = $this->tag('button', ['type' => 'button', 'class' => 'ab-chip__remove', 'data-action' => 'chip-remove', 'aria-label' => sprintf(__('Remove %s', 'alpaca-bot'), $label)], Icon::svg('x'));
        return $this->tag('span', ['class' => 'ab-chip', 'data-chip' => $kind], $inputs . $this->tag('span', ['class' => 'ab-chip__label'], $this->e($label)) . $remove);
    }
}
