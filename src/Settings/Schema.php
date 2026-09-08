<?php

declare(strict_types=1);

namespace AlpacaBot\Settings;

/**
 * The single source of truth for what lives in the `alpaca_bot_settings` option.
 *
 * Keys are dotted `section.name` strings. The label/description metadata is
 * consumed by the settings screen that arrives in a later phase.
 *
 * SECRETS are the fields whose stored value must never be shown back or lost by accident
 * (today the provider API key). They read out as MASK wherever they are shown, and a write that
 * carries MASK back means "keep what is stored"; sanitize() owns that rule so every writer of the
 * option (the REST route, the Settings API's sanitize callback, Store) resolves it the same way
 * and none can store the literal mask as the key.
 *
 * @phpstan-type Field array{type:'string'|'integer'|'number'|'boolean'|'array'|'select', default:mixed, section:string, label:string, description?:string, options?:array<string,string>, min?:int|float, max?:int|float, sanitize?:callable(mixed, array<string, mixed>): mixed}
 */
final class Schema
{
    /** What a secret reads back as when one is stored, and what a writer sends to leave it alone. */
    public const MASK = '••••';

    /** @var list<string> the fields sanitize() applies the mask rule to */
    public const SECRETS = ['provider.api_key'];

    /** @return array<string, array{label:string, description:string}> */
    public static function sections(): array
    {
        return [
            'provider' => ['label' => __('Provider', 'alpaca-bot'), 'description' => __('Where models run. Ollama by default; WordPress AI providers when WordPress 7.0+ has them registered.', 'alpaca-bot')],
            'models' => ['label' => __('Models', 'alpaca-bot'), 'description' => __('Default model and generation options, with per-model overrides.', 'alpaca-bot')],
            'chat' => ['label' => __('Chat', 'alpaca-bot'), 'description' => __('What users see and can change in the chat screen.', 'alpaca-bot')],
            'privacy' => ['label' => __('Privacy', 'alpaca-bot'), 'description' => __('What is stored in your database. Message content lives only in saved conversations; a usage receipt never contains it.', 'alpaca-bot')],
            'governance' => ['label' => __('Limits', 'alpaca-bot'), 'description' => __('Server-enforced monthly token caps, counted from the usage receipts. 0 means unlimited. Completion tokens include the reasoning a thinking model produces before its answer (it comes back as message.meta.reasoning), so a thinking model spends a cap faster than its visible reply suggests: a short answer can cost several hundred reasoning tokens first.', 'alpaca-bot')],
            'toolkits' => ['label' => __('Tools', 'alpaca-bot'), 'description' => __('Settings for built-in tools.', 'alpaca-bot')],
        ];
    }

    /** @return array<string, Field> */
    public static function fields(): array
    {
        return [
            'provider.kind' => ['type' => 'select', 'default' => 'ollama', 'section' => 'provider', 'label' => __('Provider', 'alpaca-bot'), 'options' => ['ollama' => 'Ollama', 'wp-ai' => __('WordPress AI provider', 'alpaca-bot')]],
            'provider.base_url' => ['type' => 'string', 'default' => 'http://localhost:11434/v1', 'section' => 'provider', 'label' => __('Base URL', 'alpaca-bot'), 'description' => __('OpenAI-compatible endpoint. For Ollama this ends in /v1.', 'alpaca-bot'), 'sanitize' => [self::class, 'sanitizeUrl']],
            'provider.api_key' => ['type' => 'string', 'default' => '', 'section' => 'provider', 'label' => __('API key', 'alpaca-bot'), 'description' => __('Optional. Sent as a Bearer token.', 'alpaca-bot')],
            'provider.timeout' => ['type' => 'integer', 'default' => 60, 'section' => 'provider', 'label' => __('Timeout (seconds)', 'alpaca-bot'), 'description' => __('How long one request may wait for the provider before it fails. The first request after a restart loads the model from cold, and a large model can take longer than the 60 seconds default to load; raise this if that first request times out and the next one works. Leave it low otherwise, so a provider that has stopped answering fails quickly instead of holding every chat open.', 'alpaca-bot'), 'min' => 5, 'max' => 600],
            'models.default' => ['type' => 'string', 'default' => '', 'section' => 'models', 'label' => __('Default model', 'alpaca-bot'), 'description' => __('Used when a request names no model. A thinking model (qwen3, deepseek-r1, gpt-oss) reasons before it answers; the reasoning is returned as message.meta.reasoning and its tokens count as completion tokens under the monthly caps.', 'alpaca-bot')],
            'models.temperature' => ['type' => 'number', 'default' => 0.7, 'section' => 'models', 'label' => __('Temperature', 'alpaca-bot'), 'min' => 0, 'max' => 2],
            'models.num_ctx' => ['type' => 'integer', 'default' => 8192, 'section' => 'models', 'label' => __('Context window (tokens)', 'alpaca-bot'), 'min' => 512, 'max' => 1048576],
            'models.keep_alive' => ['type' => 'string', 'default' => '5m', 'section' => 'models', 'label' => __('Keep alive', 'alpaca-bot'), 'description' => __('How long Ollama keeps the model loaded, e.g. 5m, 1h, -1.', 'alpaca-bot')],
            'models.overrides' => ['type' => 'array', 'default' => [], 'section' => 'models', 'label' => __('Per-model overrides', 'alpaca-bot'), 'sanitize' => [self::class, 'sanitizeOverrides']],
            'chat.system_prompt' => ['type' => 'string', 'default' => '', 'section' => 'chat', 'label' => __('System prompt', 'alpaca-bot')],
            'chat.welcome' => ['type' => 'string', 'default' => __('How can I help?', 'alpaca-bot'), 'section' => 'chat', 'label' => __('Welcome message', 'alpaca-bot')],
            'chat.placeholder' => ['type' => 'string', 'default' => __('Message Alpaca Bot', 'alpaca-bot'), 'section' => 'chat', 'label' => __('Input placeholder', 'alpaca-bot')],
            'chat.user_can_change_model' => ['type' => 'boolean', 'default' => true, 'section' => 'chat', 'label' => __('Users can change model', 'alpaca-bot')],
            'chat.context_messages' => ['type' => 'integer', 'default' => 20, 'section' => 'chat', 'label' => __('Messages sent to the model', 'alpaca-bot'), 'description' => __('The most recent messages of the conversation sent with each request, the new one included. 0 sends the whole conversation. Fewer messages cost fewer tokens per turn but lose older context.', 'alpaca-bot'), 'min' => 0, 'max' => 1000],
            'chat.history_limit' => ['type' => 'integer', 'default' => 20, 'section' => 'chat', 'label' => __('Conversations shown in history', 'alpaca-bot'), 'min' => 1, 'max' => 200],
            'chat.spellcheck' => ['type' => 'boolean', 'default' => true, 'section' => 'chat', 'label' => __('Spellcheck the input', 'alpaca-bot')],
            'chat.assistant_avatar' => ['type' => 'string', 'default' => '', 'section' => 'chat', 'label' => __('Assistant avatar URL', 'alpaca-bot'), 'sanitize' => [self::class, 'sanitizeUrl']],
            'privacy.save_history' => ['type' => 'boolean', 'default' => true, 'section' => 'privacy', 'label' => __('Save conversations', 'alpaca-bot')],
            'privacy.usage_log' => ['type' => 'boolean', 'default' => true, 'section' => 'privacy', 'label' => __('Record the model and conversation on usage receipts', 'alpaca-bot'), 'description' => __('A usage receipt is always written for every reply, on or off, so the monthly caps keep working: it always holds the token counts, the duration and the user who asked, and never the messages themselves. On, the receipt also records the model name and a link to the conversation. Off, it holds those numbers only.', 'alpaca-bot')],
            'privacy.usage_retention_days' => ['type' => 'integer', 'default' => 90, 'section' => 'privacy', 'label' => __('Keep usage receipts for (days)', 'alpaca-bot'), 'description' => __('A daily cleanup deletes receipts older than this. 0 keeps them forever; 3650 (ten years) is the most, and a larger number is stored as 3650. Conversations are never touched. A receipt is counted toward the caps until its month ends, so keep this at 31 or more while a cap is set.', 'alpaca-bot'), 'min' => 0, 'max' => 3650],
            'governance.site_monthly_tokens' => ['type' => 'integer', 'default' => 0, 'section' => 'governance', 'label' => __('Site-wide monthly token cap', 'alpaca-bot'), 'min' => 0, 'max' => PHP_INT_MAX],
            'governance.user_monthly_tokens' => ['type' => 'integer', 'default' => 0, 'section' => 'governance', 'label' => __('Per-user monthly token cap', 'alpaca-bot'), 'min' => 0, 'max' => PHP_INT_MAX],
            'toolkits.user_agent' => ['type' => 'string', 'default' => 'AlpacaBot/0.5 (+https://github.com/carmelosantana/alpaca-bot)', 'section' => 'toolkits', 'label' => __('User agent for fetch tools', 'alpaca-bot')],
        ];
    }

    /** @return array<string, mixed> key => default */
    public static function defaults(): array
    {
        return array_map(static fn (array $f): mixed => $f['default'], self::fields());
    }

    /**
     * Full, validated settings array: every schema key, in schema order, nothing else. A key the
     * input names is taken from the input; one it leaves out keeps what `$current` holds; only
     * when `$current` has nothing for it either does the default apply. Kept or new, every value
     * goes through its field's coercion, so a stored value outside the schema is corrected on the
     * way back rather than carried.
     *
     * Absent-keeps-stored is what makes a write partial at the top level (Store::replace(), the
     * REST PUT), and it is the guarantee under the settings page: PHP's max_input_vars drops the
     * tail of a large form post with no notice to userland, and a post can only lose what it
     * failed to carry, never a field it did not mention. Clearing is always explicit ('' for a
     * string, the secret included; 0 for a checkbox, which its hidden input posts).
     *
     * `$current` is also where a SECRETS field keeps its value from: a secret sent as MASK, or as
     * anything that is not a string, resolves to `$current`'s value (secret()). There is no
     * default for it on purpose. `[]` would turn an echoed mask into a cleared key, and reading
     * the option here would hide a database read inside a pure function; every caller knows what
     * it is writing over (Store has its memo, a `register_setting()` sanitize callback has
     * get_option()), so it says so. Core calls that callback with the option name as the second
     * argument, so it must be a closure that passes the stored array, not
     * `[Schema::class, 'sanitize']` itself: that fails with a TypeError rather than storing a mask.
     *
     * @param array<string, mixed> $input
     * @param array<string, mixed> $current the stored settings this write replaces
     * @return array<string, mixed>
     */
    public static function sanitize(array $input, array $current): array
    {
        $out = [];
        foreach (self::fields() as $key => $f) {
            $raw = array_key_exists($key, $input) ? $input[$key] : ($current[$key] ?? $f['default']);
            if (in_array($key, self::SECRETS, true)) {
                $raw = self::secret($raw, $current[$key] ?? '');
            }
            $out[$key] = isset($f['sanitize']) ? ($f['sanitize'])($raw, $f) : self::coerce($raw, $f);
        }
        return $out;
    }

    /**
     * The three-valued rule for a secret on the way in: '' clears it, MASK keeps what is stored,
     * any other string is the new value. A value that is not a string is read as "keep" too. It
     * cannot be the new key, and it is not the one spelling of "clear", so the only safe reading
     * is the one that loses nothing: a typed client's `null`, an untouched password control a
     * form serialised as `null`, a stray array, all leave the stored key as it was. The reply to
     * a write shows the mask when a key is stored, so a client that meant "clear" sees it did not.
     */
    private static function secret(mixed $raw, mixed $stored): string
    {
        if (is_string($raw) && $raw !== self::MASK) {
            return $raw;
        }
        return is_string($stored) ? $stored : '';
    }

    /** @param Field $f */
    private static function coerce(mixed $raw, array $f): mixed
    {
        switch ($f['type']) {
            case 'boolean':
                return in_array($raw, [true, 1, '1', 'true', 'on', 'yes'], true);
            case 'integer':
                $v = is_numeric($raw) ? (int) $raw : (int) $f['default'];
                return max((int) ($f['min'] ?? PHP_INT_MIN), min((int) ($f['max'] ?? PHP_INT_MAX), $v));
            case 'number':
                $v = is_numeric($raw) ? (float) $raw : (float) $f['default'];
                return max((float) ($f['min'] ?? -INF), min((float) ($f['max'] ?? INF), $v));
            case 'select':
                return is_scalar($raw) && array_key_exists((string) $raw, $f['options'] ?? []) ? (string) $raw : $f['default'];
            case 'array':
                return is_array($raw) ? $raw : $f['default'];
            case 'string':
            default:
                // One line ending. A textarea posts CRLF, a JSON client LF, and a hidden
                // carry-over of either is normalised again by the browser on its way back;
                // stored as LF, a multi-line value reads the same whichever path wrote it, and
                // saving an unrelated tab cannot rewrite it.
                return is_scalar($raw) ? trim(str_replace(["\r\n", "\r"], "\n", (string) $raw)) : $f['default'];
        }
    }

    /**
     * esc_url_raw() drops disallowed schemes (javascript:, data:, ...) so nothing
     * unsafe reaches the chat UI (assistant_avatar) or server-side HTTP (base_url).
     *
     * @param array<string, mixed> $f
     */
    public static function sanitizeUrl(mixed $raw, array $f): string
    {
        $v = is_scalar($raw) ? trim((string) $raw) : '';
        $v = $v === '' ? '' : rtrim(esc_url_raw($v), '/');
        return $v === '' ? (string) $f['default'] : $v;
    }

    /**
     * model => {temperature?, num_ctx?, keep_alive?, system?}; anything else is dropped.
     *
     * Each override is coerced with the schema field it overrides, so the type and
     * min/max bounds have one source of truth: fields().
     *
     * @return array<string, array<string, float|int|string>>
     */
    public static function sanitizeOverrides(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $fields = self::fields();
        $allowed = ['temperature' => 'models.temperature', 'num_ctx' => 'models.num_ctx', 'keep_alive' => 'models.keep_alive', 'system' => 'chat.system_prompt'];
        $out = [];
        foreach ($raw as $model => $opts) {
            if (!is_string($model) || $model === '' || !is_array($opts)) {
                continue;
            }
            $clean = [];
            foreach ($allowed as $k => $field) {
                if (!array_key_exists($k, $opts) || !is_scalar($opts[$k])) {
                    continue;
                }
                // An unparseable number is dropped rather than coerced to the schema
                // default, which would silently shadow the admin's global value.
                if (in_array($fields[$field]['type'], ['number', 'integer'], true) && !is_numeric($opts[$k])) {
                    continue;
                }
                /** @var float|int|string $v */
                $v = self::coerce($opts[$k], $fields[$field]);
                // A blank cell in the settings table is "no override", not an empty value that
                // would shadow the global keep_alive or system prompt with nothing.
                if ($v === '') {
                    continue;
                }
                $clean[$k] = $v;
            }
            if ($clean !== []) {
                $out[$model] = $clean;
            }
        }
        return $out;
    }
}
