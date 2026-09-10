<?php

declare(strict_types=1);

namespace AlpacaBot\View\Chat;

use AlpacaBot\Settings\Store;

/**
 * Who a transcript shows: the current user's name and avatar, and the assistant's avatar (the
 * `chat.assistant_avatar` setting, else the plugin's own alpaca, assets/img/alpaca-bot-avatar.png).
 * Resolved once per render by whoever builds a MessageList or a MessageBubble (the shell on
 * load, the view routes for a fragment), so the rule lives here and not in each of them.
 *
 * The fallback is a URL like any configured one and nothing downstream is told which it was:
 * the illustration carries its cream ground in the image, so the round crop every avatar gets
 * is the whole treatment.
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
            $assistantAvatar = plugins_url('assets/img/alpaca-bot-avatar.png', ALPACA_BOT_FILE);
        }
        return new self($user->display_name, is_string($userAvatar) ? $userAvatar : '', $assistantAvatar);
    }
}
