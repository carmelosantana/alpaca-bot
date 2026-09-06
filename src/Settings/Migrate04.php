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

    /** @var array<string, string> legacy option (without prefix) => new dotted key */
    private const MAP = [
        'api_url' => 'provider.base_url',
        'api_password' => 'provider.api_key',
        'ollama_timeout' => 'provider.timeout',
        'default_model' => 'models.default',
        'default_temperature' => 'models.temperature',
        'default_num_ctx' => 'models.num_ctx',
        'default_system' => 'chat.system_prompt',
        'default_assistant_welcome_message' => 'chat.welcome',
        'default_assistant_prompt_placeholder' => 'chat.placeholder',
        'user_can_change_model' => 'chat.user_can_change_model',
        'chat_history_limit' => 'chat.history_limit',
        'spellcheck' => 'chat.spellcheck',
        'default_avatar' => 'chat.assistant_avatar',
        'chat_history_save' => 'privacy.save_history',
        'chat_response_log' => 'privacy.usage_log',
        'user_agent' => 'toolkits.user_agent',
    ];

    public function __construct(private Store $store) {}

    public function needed(): bool
    {
        if (get_option(self::FLAG, false) !== false) {
            return false;
        }
        return get_option(self::LEGACY_PREFIX . 'api_url', null) !== null
            || get_option(self::LEGACY_PREFIX . 'default_model', null) !== null;
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
            // 0.4 stored blank inputs as '' and Options::get() treated '' as "unset,
            // use the default". For its boolean radios the stored '' *was* the "No"
            // value, so only non-boolean keys fall back to the 1.0 default here.
            if ($value === '' && $fields[$key]['type'] !== 'boolean') {
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
