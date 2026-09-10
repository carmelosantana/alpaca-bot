<?php

declare(strict_types=1);

namespace AlpacaBot\View\Chat;

use AlpacaBot\Settings\Store;

/**
 * Who a transcript shows: the current user's name and avatar, and the assistant's avatar (the
 * `chat.assistant_avatar` setting, else the alpaca logo, assets/img/alpaca-bot.svg). Resolved
 * once per render by whoever builds a MessageList or a MessageBubble (the shell on load, the
 * view routes for a fragment), so the rule lives here and not in each of them.
 *
 * The logo is black line art on a transparent ground whose ears clip in a bare round crop, so
 * the stylesheet seats it on a padded off-white disc (`.ab-avatar--default`). A site's own
 * avatar is a photo that must keep filling its circle, and the components cannot tell the two
 * URLs apart, so `$assistantAvatarIsDefault` travels with the URL and is what they scope the
 * disc with.
 */
final class Participants
{
    public function __construct(public string $userName, public string $userAvatar, public string $assistantAvatar, public bool $assistantAvatarIsDefault = false) {}

    public static function current(Store $store): self
    {
        $user = wp_get_current_user();
        $userAvatar = get_avatar_url($user->ID);
        $assistantAvatar = (string) $store->get('chat.assistant_avatar');
        $default = $assistantAvatar === '';
        if ($default) {
            $assistantAvatar = plugins_url('assets/img/alpaca-bot.svg', ALPACA_BOT_FILE);
        }
        return new self($user->display_name, is_string($userAvatar) ? $userAvatar : '', $assistantAvatar, $default);
    }
}
