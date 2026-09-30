<?php

declare(strict_types=1);

namespace AlpacaBot\Chat;

use AlpacaBot\Provider\ModelCatalog;
use AlpacaBot\Settings\Store;

/**
 * What a user has chosen for themselves, kept as user meta: the default model (Kanboard #565),
 * which the chat screen's model select stores on change and which a turn that names no model runs
 * on; and the admin-wide drawer's two (Admin\Drawer), whether it was left open and which
 * conversation it last showed, which POST /view/drawer stores so the drawer comes back on the
 * next admin screen the way it was left.
 *
 * modelFor() is the one rule for when the preference applies, shared by the pipeline (the turn),
 * the chat screen (the select and the composer's hidden field on load) and the model-select
 * fragment, so the model the screen shows is the model the turn runs on. The preference is a
 * user's choice, so it counts only while `chat.user_can_change_model` lets users choose, and it
 * is checked against the catalog the way a requested model is: a stored model the provider has
 * since dropped falls back to the site default rather than refusing every turn, and an empty
 * catalog (an unreachable provider) refuses nothing, as Pipeline::model() explains.
 */
final class UserPrefs
{
    public const META_DEFAULT_MODEL = 'alpaca_bot_default_model';

    /** Whether the admin-wide drawer was left open: '1' is open, and anything else reads as closed. */
    public const META_DRAWER_OPEN = 'alpaca_bot_drawer_open';

    /** The conversation the drawer last showed, as a string of digits; '0', or anything unreadable, is a new chat. */
    public const META_DRAWER_CONVERSATION = 'alpaca_bot_drawer_conversation';

    public function defaultModel(int $userId): string
    {
        $model = get_user_meta($userId, self::META_DEFAULT_MODEL, true);
        return is_string($model) ? $model : '';
    }

    public function setDefaultModel(int $userId, string $model): void
    {
        // update_user_meta() unslashes what it is given. A provider's model id has no backslash
        // in it today, but nothing here enforces that and the cost of being sure is one call.
        update_user_meta($userId, self::META_DEFAULT_MODEL, wp_slash($model));
    }

    public function drawerOpen(int $userId): bool
    {
        return get_user_meta($userId, self::META_DRAWER_OPEN, true) === '1';
    }

    public function setDrawerOpen(int $userId, bool $open): void
    {
        update_user_meta($userId, self::META_DRAWER_OPEN, $open ? '1' : '0');
    }

    /**
     * The conversation the drawer should open on. Only a string of digits counts, because the row
     * is one any user who can chat writes through the REST route and a filter may rewrite: anything
     * else (a negative, a word, an array) is a new chat rather than a post id nobody named. Whether
     * the id is the user's is not decided here: `GET /view/panel` loads it through
     * ConversationStore::load(), which answers null for anyone else's.
     */
    public function drawerConversation(int $userId): int
    {
        $stored = get_user_meta($userId, self::META_DRAWER_CONVERSATION, true);
        return is_string($stored) && ctype_digit($stored) ? (int) $stored : 0;
    }

    public function setDrawerConversation(int $userId, int $id): void
    {
        update_user_meta($userId, self::META_DRAWER_CONVERSATION, (string) max(0, $id));
    }

    /** The model a turn of `$userId`'s runs on when the request names none: see the class docblock. */
    public function modelFor(int $userId, ModelCatalog $catalog, Store $store): string
    {
        $preferred = $this->defaultModel($userId);
        if ($preferred !== '' && (bool) $store->get('chat.user_can_change_model')) {
            if ($catalog->all() === [] || $catalog->find($preferred) !== null) {
                return $preferred;
            }
        }
        return $catalog->defaultId($store);
    }
}
