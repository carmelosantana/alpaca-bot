<?php

declare(strict_types=1);

namespace AlpacaBot\Settings;

/**
 * One-time move from the scattered 0.4 `alpaca_bot_*` options into the single settings option.
 *
 * Legacy options are left in place (P3 removes them); the FLAG option records that the move ran.
 */
final class Migrate04
{
    public const FLAG = 'alpaca_bot_migrated_04';

    private const LEGACY_PREFIX = 'alpaca_bot_';

    /**
     * Legacy option (without prefix) => new dotted key.
     *
     * Not carried over: `api_password`. 0.4 sent it as HTTP Basic alongside an
     * `api_username` that 1.0 has no field for, while `provider.api_key` goes out
     * as a Bearer token. Migrating it would hand every upgrading admin a credential
     * guaranteed to fail auth, silently; an empty field they must re-fill is
     * strictly better than a populated one that cannot work.
     *
     * @var array<string, string>
     */
    private const MAP = [
        'api_url' => 'provider.base_url',
        'ollama_timeout' => 'provider.timeout',
        'default_model' => 'models.default',
        'default_temperature' => 'models.temperature',
        'default_num_ctx' => 'models.num_ctx',
        'default_system' => 'chat.system_prompt',
        'default_assistant_welcome_message' => 'chat.welcome',
        'default_assistant_prompt_placeholder' => 'chat.placeholder',
        'user_can_change_model' => 'chat.user_can_change_model',
        // Semantic change: in 0.4 this was the number of *messages sent to the model*
        // (0 = all); in 1.0 it is the number of *conversations shown in history*.
        // The number is carried anyway: it yields a merely arbitrary list length,
        // visible in the UI and one setting away from fixed, whereas dropping it
        // would discard user intent for no safety gain.
        'chat_history_limit' => 'chat.history_limit',
        'spellcheck' => 'chat.spellcheck',
        'default_avatar' => 'chat.assistant_avatar',
        'chat_history_save' => 'privacy.save_history',
        'chat_response_log' => 'privacy.usage_log',
        'user_agent' => 'toolkits.user_agent',
    ];

    public function __construct(private Store $store) {}

    /**
     * True when 0.4 options exist and the move has not run yet.
     *
     * On a site that never had 0.4 the flag is written here, so the detection
     * (three non-autoloaded option reads) happens once instead of on every admin request.
     */
    public function needed(): bool
    {
        if (get_option(self::FLAG, false) !== false) {
            return false;
        }
        $legacy = get_option(self::LEGACY_PREFIX . 'api_url', null) !== null
            || get_option(self::LEGACY_PREFIX . 'default_model', null) !== null;
        if (!$legacy) {
            update_option(self::FLAG, '1', false);
        }
        return $legacy;
    }

    /** @return array<string, mixed> the migrated settings */
    public function run(): array
    {
        $next = $this->store->all();
        $fields = Schema::fields();
        foreach (self::MAP as $legacy => $key) {
            $value = get_option(self::LEGACY_PREFIX . $legacy, null);
            if ($value === null || $value === false) {
                continue;
            }
            // 0.4's Options::get() went through empty() and `$value ? $value : $default`,
            // so a stored '', '0' or 0 all meant "unset, use the default". For its
            // boolean radios the stored '' *was* the "No" value (they only ever wrote
            // '1' or ''), so only non-boolean keys fall back to the 1.0 default here.
            if ($fields[$key]['type'] !== 'boolean' && in_array($value, ['', '0', 0], true)) {
                continue;
            }
            if ($key === 'provider.base_url') {
                $value = rtrim(is_scalar($value) ? (string) $value : '', '/');
                $value = str_ends_with($value, '/v1') ? $value : $value . '/v1';
            }
            $next[$key] = $value;
        }
        $this->store->replace($next);
        update_option(self::FLAG, '1', false);
        return $this->store->all();
    }
}
