<?php

declare(strict_types=1);

namespace AlpacaBot\Chat;

use AlpacaBot\Provider\ModelCatalog;
use AlpacaBot\Settings\Store;

/**
 * What a user has chosen for themselves, kept as user meta. One preference so far: the default
 * model (Kanboard #565), which the chat screen's model select stores on change and which a turn
 * that names no model runs on.
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

    public function defaultModel(int $userId): string
    {
        $model = get_user_meta($userId, self::META_DEFAULT_MODEL, true);
        return is_string($model) ? $model : '';
    }

    public function setDefaultModel(int $userId, string $model): void
    {
        update_user_meta($userId, self::META_DEFAULT_MODEL, $model);
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
