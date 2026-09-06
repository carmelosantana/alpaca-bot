<?php

declare(strict_types=1);

namespace AlpacaBot\Settings;

/**
 * The single source of truth for what lives in the `alpaca_bot_settings` option.
 *
 * Keys are dotted `section.name` strings. The label/description metadata is
 * consumed by the settings screen that arrives in a later phase.
 *
 * @phpstan-type Field array{type:'string'|'integer'|'number'|'boolean'|'array'|'select', default:mixed, section:string, label:string, description?:string, options?:array<string,string>, min?:int|float, max?:int|float, sanitize?:callable(mixed, array<string, mixed>): mixed}
 */
final class Schema
{
    /** @return array<string, array{label:string, description:string}> */
    public static function sections(): array
    {
        return [
            'provider' => ['label' => __('Provider', 'alpaca-bot'), 'description' => __('Where models run. Ollama by default; WordPress AI providers when WordPress 7.0+ has them registered.', 'alpaca-bot')],
            'models' => ['label' => __('Models', 'alpaca-bot'), 'description' => __('Default model and generation options, with per-model overrides.', 'alpaca-bot')],
            'chat' => ['label' => __('Chat', 'alpaca-bot'), 'description' => __('What users see and can change in the chat screen.', 'alpaca-bot')],
            'privacy' => ['label' => __('Privacy', 'alpaca-bot'), 'description' => __('What is stored in your database.', 'alpaca-bot')],
            'governance' => ['label' => __('Limits', 'alpaca-bot'), 'description' => __('Server-enforced monthly token caps. 0 means unlimited.', 'alpaca-bot')],
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
            'provider.timeout' => ['type' => 'integer', 'default' => 60, 'section' => 'provider', 'label' => __('Timeout (seconds)', 'alpaca-bot'), 'min' => 5, 'max' => 600],
            'models.default' => ['type' => 'string', 'default' => '', 'section' => 'models', 'label' => __('Default model', 'alpaca-bot')],
            'models.temperature' => ['type' => 'number', 'default' => 0.7, 'section' => 'models', 'label' => __('Temperature', 'alpaca-bot'), 'min' => 0, 'max' => 2],
            'models.num_ctx' => ['type' => 'integer', 'default' => 8192, 'section' => 'models', 'label' => __('Context window (tokens)', 'alpaca-bot'), 'min' => 512, 'max' => 1048576],
            'models.keep_alive' => ['type' => 'string', 'default' => '5m', 'section' => 'models', 'label' => __('Keep alive', 'alpaca-bot'), 'description' => __('How long Ollama keeps the model loaded, e.g. 5m, 1h, -1.', 'alpaca-bot')],
            'models.overrides' => ['type' => 'array', 'default' => [], 'section' => 'models', 'label' => __('Per-model overrides', 'alpaca-bot'), 'sanitize' => [self::class, 'sanitizeOverrides']],
            'chat.system_prompt' => ['type' => 'string', 'default' => '', 'section' => 'chat', 'label' => __('System prompt', 'alpaca-bot')],
            'chat.welcome' => ['type' => 'string', 'default' => __('How can I help?', 'alpaca-bot'), 'section' => 'chat', 'label' => __('Welcome message', 'alpaca-bot')],
            'chat.placeholder' => ['type' => 'string', 'default' => __('Message Alpaca Bot', 'alpaca-bot'), 'section' => 'chat', 'label' => __('Input placeholder', 'alpaca-bot')],
            'chat.user_can_change_model' => ['type' => 'boolean', 'default' => true, 'section' => 'chat', 'label' => __('Users can change model', 'alpaca-bot')],
            'chat.history_limit' => ['type' => 'integer', 'default' => 20, 'section' => 'chat', 'label' => __('Conversations shown in history', 'alpaca-bot'), 'min' => 1, 'max' => 200],
            'chat.spellcheck' => ['type' => 'boolean', 'default' => true, 'section' => 'chat', 'label' => __('Spellcheck the input', 'alpaca-bot')],
            'chat.assistant_avatar' => ['type' => 'string', 'default' => '', 'section' => 'chat', 'label' => __('Assistant avatar URL', 'alpaca-bot'), 'sanitize' => [self::class, 'sanitizeUrl']],
            'privacy.save_history' => ['type' => 'boolean', 'default' => true, 'section' => 'privacy', 'label' => __('Save conversations', 'alpaca-bot')],
            'privacy.usage_log' => ['type' => 'boolean', 'default' => true, 'section' => 'privacy', 'label' => __('Keep a usage log (tokens, model, duration)', 'alpaca-bot')],
            'governance.site_monthly_tokens' => ['type' => 'integer', 'default' => 0, 'section' => 'governance', 'label' => __('Site-wide monthly token cap', 'alpaca-bot'), 'min' => 0, 'max' => PHP_INT_MAX],
            'governance.user_monthly_tokens' => ['type' => 'integer', 'default' => 0, 'section' => 'governance', 'label' => __('Per-user monthly token cap', 'alpaca-bot'), 'min' => 0, 'max' => PHP_INT_MAX],
            'toolkits.user_agent' => ['type' => 'string', 'default' => 'AlpacaBot/1.0 (+https://github.com/carmelosantana/alpaca-bot)', 'section' => 'toolkits', 'label' => __('User agent for fetch tools', 'alpaca-bot')],
        ];
    }

    /** @return array<string, mixed> key => default */
    public static function defaults(): array
    {
        return array_map(static fn (array $f): mixed => $f['default'], self::fields());
    }

    /**
     * Full, validated settings array. Missing keys take their default; unknown keys are dropped.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public static function sanitize(array $input): array
    {
        $out = [];
        foreach (self::fields() as $key => $f) {
            $raw = array_key_exists($key, $input) ? $input[$key] : $f['default'];
            $out[$key] = isset($f['sanitize']) ? ($f['sanitize'])($raw, $f) : self::coerce($raw, $f);
        }
        return $out;
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
                return is_scalar($raw) ? trim((string) $raw) : $f['default'];
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
                $clean[$k] = $v;
            }
            if ($clean !== []) {
                $out[$model] = $clean;
            }
        }
        return $out;
    }
}
