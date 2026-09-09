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
 * data URL, the post being edited. The nonce is not among them: every request carries it as
 * the X-WP-Nonce header from the localised settings (Admin\Assets), which nonce.ts refreshes.
 */
final class Composer extends Component
{
    public function __construct(private Store $store, private int $conversationId, private string $model, private int $postId = 0) {}

    public function render(): string
    {
        $textarea = $this->tag('textarea', ['name' => 'message', 'id' => 'ab-message', 'rows' => '1', 'required' => 'required', 'placeholder' => (string) $this->store->get('chat.placeholder'), 'spellcheck' => $this->store->get('chat.spellcheck') ? 'true' : 'false', 'aria-label' => __('Message', 'alpaca-bot')]);
        $hidden = $this->tag('input', ['type' => 'hidden', 'name' => 'conversation_id', 'value' => (string) $this->conversationId])
            . $this->tag('input', ['type' => 'hidden', 'name' => 'model', 'value' => $this->model])
            . $this->tag('input', ['type' => 'hidden', 'name' => 'images', 'value' => ''])
            . $this->tag('input', ['type' => 'hidden', 'name' => 'context[post_id]', 'value' => (string) $this->postId]);
        $buttons = $this->tag('button', ['type' => 'button', 'class' => 'ab-btn ab-btn--icon', 'data-action' => 'image', 'aria-label' => __('Attach an image', 'alpaca-bot')], Icon::svg('image'))
            . $this->tag('button', ['type' => 'button', 'class' => 'ab-btn ab-btn--icon', 'data-action' => 'image-remove', 'aria-label' => __('Remove image', 'alpaca-bot'), 'hidden' => 'hidden'], Icon::svg('image-off'))
            . $this->tag('button', ['type' => 'submit', 'class' => 'ab-btn ab-btn--send', 'data-action' => 'send', 'aria-label' => __('Send', 'alpaca-bot')], Icon::svg('send-horizontal'));
        return $this->tag('form', ['id' => 'ab-form', 'class' => 'ab-composer', 'autocomplete' => 'off'], $hidden . $this->tag('div', ['class' => 'ab-composer__row'], $textarea . $this->tag('div', ['class' => 'ab-composer__buttons'], $buttons)));
    }
}
