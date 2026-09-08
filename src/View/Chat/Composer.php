<?php

declare(strict_types=1);

namespace AlpacaBot\View\Chat;

use AlpacaBot\Settings\Store;
use AlpacaBot\View\Component;
use AlpacaBot\View\Icon;

/**
 * The message form at the foot of the screen. It carries no htmx: sending is a streamed
 * request that chat.ts drives (POST /chat, then the SSE ticket), so the form's job is to hold
 * the fields chat.ts reads — the text, the conversation and model, the attached image as a
 * data URL, the post being edited — and the nonce the request is signed with.
 */
final class Composer extends Component
{
    public function __construct(private Store $store, private string $nonce, private int $conversationId, private string $model, private int $postId = 0) {}

    public function render(): string
    {
        $textarea = $this->tag('textarea', ['name' => 'message', 'id' => 'ab-message', 'rows' => '1', 'required' => 'required', 'placeholder' => (string) $this->store->get('chat.placeholder'), 'spellcheck' => $this->store->get('chat.spellcheck') ? 'true' : 'false', 'aria-label' => $this->t('Message')]);
        $hidden = $this->tag('input', ['type' => 'hidden', 'name' => 'conversation_id', 'value' => (string) $this->conversationId])
            . $this->tag('input', ['type' => 'hidden', 'name' => 'model', 'value' => $this->model])
            . $this->tag('input', ['type' => 'hidden', 'name' => 'images', 'value' => ''])
            . $this->tag('input', ['type' => 'hidden', 'name' => 'context[post_id]', 'value' => (string) $this->postId])
            . $this->tag('input', ['type' => 'hidden', 'name' => '_wpnonce', 'value' => $this->nonce]);
        $buttons = $this->tag('button', ['type' => 'button', 'class' => 'ab-btn ab-btn--icon', 'data-action' => 'image', 'aria-label' => $this->t('Attach an image')], Icon::svg('image'))
            . $this->tag('button', ['type' => 'button', 'class' => 'ab-btn ab-btn--icon', 'data-action' => 'image-remove', 'aria-label' => $this->t('Remove image'), 'hidden' => 'hidden'], Icon::svg('image-off'))
            . $this->tag('button', ['type' => 'submit', 'class' => 'ab-btn ab-btn--send', 'data-action' => 'send', 'aria-label' => $this->t('Send')], Icon::svg('send-horizontal'));
        return $this->tag('form', ['id' => 'ab-form', 'class' => 'ab-composer', 'autocomplete' => 'off'], $hidden . $this->tag('div', ['class' => 'ab-composer__row'], $textarea . $this->tag('div', ['class' => 'ab-composer__buttons'], $buttons)));
    }
}
