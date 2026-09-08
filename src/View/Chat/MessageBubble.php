<?php

declare(strict_types=1);

namespace AlpacaBot\View\Chat;

use AlpacaBot\Chat\ImageData;
use AlpacaBot\Chat\Message;
use AlpacaBot\View\Component;
use AlpacaBot\View\Icon;
use AlpacaBot\View\Markdown;

/**
 * One turn of the transcript. An assistant turn is markdown (already stripped and allowlisted by
 * Markdown, so nothing is re-escaped here); a user turn is the user's own text, escaped, with
 * its line breaks kept. A streaming bubble is the same article with no content yet: chat.ts
 * appends deltas into `.ab-msg__content`, which is a polite live region for that duration, and
 * replaces the whole article with a rendered one on `done`. A user turn's attached images are
 * shown above its text: `esc_url()` strips a `data:` URL (it is not in wp_allowed_protocols),
 * so each is held to a base64 image data URL by pattern (ImageData::PATTERN, the same contract
 * Pipeline::images() admits at intake) and attribute-escaped; anything else is not rendered.
 * Images ConversationStore::save() evicted to fit the transcript into the database's packet
 * limit leave a count under `meta['images_evicted']`; the row then ends with one line saying
 * how many are no longer stored, so the turn reads as it was, not as a turn that never had
 * them and not as a broken image.
 */
final class MessageBubble extends Component
{
    public function __construct(private Message $m, private Markdown $md, private string $userName, private string $userAvatar, private string $assistantAvatar, private bool $streaming = false) {}

    public function render(): string
    {
        $assistant = $this->m->role === 'assistant';
        $name = $assistant ? ($this->m->model !== '' ? $this->m->model : $this->t('Assistant')) : $this->userName;
        $content = $this->streaming ? '' : ($assistant ? $this->md->toHtml($this->m->content) : nl2br($this->e($this->m->content), false));
        $actions = $this->tag('button', ['type' => 'button', 'class' => 'ab-msg__action', 'data-action' => 'copy', 'aria-label' => $this->t('Copy message')], Icon::svg('copy'));
        if (!$assistant) {
            $actions .= $this->tag('button', ['type' => 'button', 'class' => 'ab-msg__action', 'data-action' => 'edit', 'aria-label' => $this->t('Edit and resend')], Icon::svg('square-pen'));
        }
        $receipt = $assistant && $this->m->usage !== null ? (new Receipt([
            'model' => $this->m->model,
            'total_tokens' => ($this->m->usage['prompt_tokens'] ?? 0) + ($this->m->usage['completion_tokens'] ?? 0),
            'duration_ms' => (int) ($this->m->meta['duration_ms'] ?? 0),
            'tool_calls' => is_array($this->m->meta['tool_calls'] ?? null) ? count($this->m->meta['tool_calls']) : 0,
        ]))->render() : '';
        $inner = $this->tag('img', ['class' => 'ab-msg__avatar', 'src' => $this->u($assistant ? $this->assistantAvatar : $this->userAvatar), 'alt' => ''])
            . $this->tag('div', ['class' => 'ab-msg__body'],
                $this->tag('header', ['class' => 'ab-msg__meta'], $this->tag('span', ['class' => 'ab-msg__name'], $this->e($name)) . $this->tag('span', ['class' => 'ab-msg__actions'], $actions))
                . ($assistant ? '' : $this->images())
                . $this->tag('div', ['class' => 'ab-msg__content', 'aria-live' => $this->streaming ? 'polite' : null], $content)
                . $receipt);
        return $this->tag('article', ['class' => 'ab-msg ab-msg--' . ($assistant ? 'assistant' : 'user'), 'data-role' => $this->m->role, 'data-streaming' => $this->streaming ? '1' : null], $inner);
    }

    private function images(): string
    {
        $imgs = '';
        foreach ($this->m->images as $src) {
            if (ImageData::isValid($src)) {
                $imgs .= $this->tag('img', ['class' => 'ab-msg__image', 'src' => $src, 'alt' => $this->t('Attached image')]);
            }
        }
        // The marker is read as stored (an int, or whatever a filter left there): only a count above zero says anything.
        $evicted = $this->m->meta['images_evicted'] ?? 0;
        $evicted = is_int($evicted) ? $evicted : 0;
        if ($evicted > 0) {
            /* translators: %s: how many of the turn's attached images the store let go to fit the transcript into the database */
            $imgs .= $this->tag('span', ['class' => 'ab-msg__evicted'], $this->e(sprintf(_n('%s attached image is no longer stored.', '%s attached images are no longer stored.', $evicted, 'alpaca-bot'), number_format_i18n($evicted))));
        }
        return $imgs === '' ? '' : $this->tag('div', ['class' => 'ab-msg__images'], $imgs);
    }
}
