<?php

declare(strict_types=1);

namespace AlpacaBot\View\Chat;

use AlpacaBot\Chat\Conversation;
use AlpacaBot\Provider\ModelCatalog;
use AlpacaBot\Settings\Store;
use AlpacaBot\View\Component;

/**
 * The chat as the admin-wide drawer shows it, and the whole of what `GET /view/panel` answers: a
 * panel holding a close button and the Shell the chat screen renders, in the shell's drawer
 * layout (Shell's `$drawer`).
 *
 * It is the chat screen's Shell rather than a second rendering of its pieces, so the header, the
 * transcript and the composer are the screen's own. A conversation opened here is one of the same
 * user's conversations in the same ConversationStore, so the chat screen opens it too
 * (`?conversation={id}`), which is the shared store Kanboard #4369 settled. What this class adds
 * is the panel and the button.
 *
 * The button is marked `data-action="drawer-close"`, and nothing here acts on it: the chat
 * bundle's click handler (resources/ts/boot.ts) has no case for that action, so closing is left
 * to whatever script owns the drawer.
 */
final class Drawer extends Component
{
    /**
     * @param list<array{id: int, title: string, created: int}> $history as ConversationStore::listFor() returns them
     * @param int $postId the post being edited when the drawer was opened, else 0
     * @param string|null $model the user's effective model (Chat\UserPrefs::modelFor()); null means the catalog's default
     * @param string|null $sprite path to the icon sprite, as Shell takes it; null means the plugin's own
     * @param array{id: string, title: string}|null $screen the screen the drawer is on, as Shell takes it
     */
    public function __construct(private Store $store, private ModelCatalog $catalog, private ?Conversation $conversation, private array $history, private int $postId = 0, private ?string $model = null, private ?string $sprite = null, private ?array $screen = null) {}

    public function render(): string
    {
        $close = $this->tag('button', [
            'type' => 'button',
            'class' => 'ab-btn ab-btn--icon ab-drawer__close',
            'data-action' => 'drawer-close',
            'aria-label' => __('Close the chat', 'alpaca-bot'),
        ], $this->e('×'));
        $shell = new Shell($this->store, $this->catalog, $this->conversation, $this->history, $this->postId, $this->sprite, $this->model, null, true, $this->screen);
        return $this->tag('div', ['class' => 'ab-drawer__panel'], $close . $shell->render());
    }
}
