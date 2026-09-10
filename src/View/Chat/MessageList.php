<?php

declare(strict_types=1);

namespace AlpacaBot\View\Chat;

use AlpacaBot\Chat\Message;
use AlpacaBot\Settings\Store;
use AlpacaBot\View\Component;
use AlpacaBot\View\Markdown;

/**
 * The transcript: #ab-messages, which the history select swaps whole and chat.ts appends to.
 * Only user and assistant turns are shown; a system turn is the site's prompt and a tool turn
 * is machinery, neither of which is the conversation as the user sees it. An empty transcript
 * shows the welcome block instead, as 0.4 did.
 */
final class MessageList extends Component
{
    /** @param Message[] $messages */
    public function __construct(private array $messages, private Markdown $md, private Store $store, private string $userName, private string $userAvatar, private string $assistantAvatar, private int $conversationId = 0, private bool $defaultAssistantAvatar = false) {}

    public function render(): string
    {
        $inner = '';
        foreach ($this->messages as $m) {
            if ($m->role === 'user' || $m->role === 'assistant') {
                $inner .= (new MessageBubble($m, $this->md, $this->userName, $this->userAvatar, $this->assistantAvatar, defaultAssistantAvatar: $this->defaultAssistantAvatar))->render();
            }
        }
        if ($inner === '') {
            // The welcome avatar is the assistant's, so it carries the default's disc class on the same terms as a bubble's (MessageBubble).
            $inner = $this->tag('div', ['class' => 'ab-welcome'],
                $this->tag('img', ['class' => 'ab-welcome__avatar' . ($this->defaultAssistantAvatar ? ' ab-avatar--default' : ''), 'src' => $this->u($this->assistantAvatar), 'alt' => ''])
                . $this->tag('p', ['class' => 'ab-welcome__text'], $this->e((string) $this->store->get('chat.welcome'))));
        }
        return $this->tag('div', ['id' => 'ab-messages', 'class' => 'ab-messages', 'data-conversation' => (string) $this->conversationId], $inner);
    }
}
