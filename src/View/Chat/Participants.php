<?php

declare(strict_types=1);

namespace AlpacaBot\View\Chat;

use AlpacaBot\Settings\Store;

/**
 * Who a transcript shows: the current user's name and avatar, and the assistant's avatar (the
 * `chat.assistant_avatar` setting, else the plugin's own icon). Resolved once per render by
 * whoever builds a MessageList or a MessageBubble (the shell on load, the view routes for a
 * fragment), so the rule lives here and not in each of them.
 */
final class Participants
{
    public function __construct(public string $userName, public string $userAvatar, public string $assistantAvatar) {}

    public static function current(Store $store): self
    {
        $user = wp_get_current_user();
        $userAvatar = get_avatar_url($user->ID);
        $assistantAvatar = (string) $store->get('chat.assistant_avatar');
        if ($assistantAvatar === '') {
            $assistantAvatar = plugins_url('assets/img/icon-80.png', ALPACA_BOT_FILE);
        }
        return new self($user->display_name, is_string($userAvatar) ? $userAvatar : '', $assistantAvatar);
    }
}
