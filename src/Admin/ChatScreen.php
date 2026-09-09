<?php

declare(strict_types=1);

namespace AlpacaBot\Admin;

use AlpacaBot\Chat\ConversationStore;
use AlpacaBot\Chat\UserPrefs;
use AlpacaBot\Provider\ModelCatalog;
use AlpacaBot\Settings\Store;
use AlpacaBot\View\Chat\Shell;

/**
 * The chat screen (`admin.php?page=alpaca-bot`): the menu's renderer, which echoes a Shell. The
 * bare screen is a new chat, as it was in 0.4 and as the header's "New chat" link, which points
 * at the bare screen, needs it to be. `?conversation={id}` opens one of the user's own instead
 * (anyone else's, or a missing one, is a new chat again, not an error), and `?post={id}` is the
 * post the screen was opened from, carried as the turn's context.
 *
 * The model the select and the composer start on is the user's effective model (UserPrefs), so
 * the screen opens on what the user last chose and the first turn runs on it.
 */
final class ChatScreen
{
    public function __construct(private Store $store, private ModelCatalog $catalog, private ConversationStore $conversations, private UserPrefs $prefs) {}

    public function render(): void
    {
        $userId = (int) get_current_user_id();
        $wanted = self::queryInt('conversation');
        $conversation = $wanted > 0 ? $this->conversations->load($wanted, $userId) : null;
        $history = $this->conversations->listFor($userId, max(1, (int) $this->store->get('chat.history_limit')));
        $shell = new Shell(
            $this->store,
            $this->catalog,
            $conversation,
            $history,
            self::queryInt('post'),
            null,
            $this->prefs->modelFor($userId, $this->catalog, $this->store),
        );
        // The shell's output is escaped where it is built (Component::tag(), Markdown's kses).
        echo $shell->render(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Component output, escaped where it is built (Component::tag() escapes every attribute, e() every text node, Markdown runs wp_kses).
    }

    /** A query value as a non-negative integer; anything that is not a scalar (an array) is 0. Read only, so no nonce. */
    private static function queryInt(string $key): int
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Read only: this selects which of the caller's own conversations to display and changes nothing, so it needs no nonce. The (int) cast on the next line is the sanitisation and also makes unslashing moot, since no backslash survives it; load() then scopes the id to $userId.
        $value = $_GET[$key] ?? null;
        return is_scalar($value) ? max(0, (int) $value) : 0;
    }
}
