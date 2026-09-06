<?php

declare(strict_types=1);

namespace AlpacaBot\Settings;

use AlpacaBot\Plugin;

/**
 * Read/write access to the single `alpaca_bot_settings` option.
 *
 * The option is read at most once per request and memoized; every write goes
 * through Schema::sanitize() so the stored array is always complete and valid.
 */
final class Store
{
    /** @var array<string, mixed>|null */
    private ?array $cache;

    /**
     * @param array<string, mixed>|null $cache pre-seeded settings (merged over the defaults,
     *                                         like a stored option); skips the option read entirely
     */
    public function __construct(?array $cache = null)
    {
        $this->cache = $cache === null ? null : array_merge(Schema::defaults(), $cache);
    }

    /** @return array<string, mixed> defaults merged with whatever is stored */
    public function all(): array
    {
        if ($this->cache === null) {
            $stored = get_option(Plugin::OPTION, []);
            /** @var array<string, mixed> $stored */
            $stored = is_array($stored) ? $stored : [];
            $this->cache = array_merge(Schema::defaults(), $stored);
        }
        return $this->cache;
    }

    /** @param string $key dotted key, e.g. 'provider.base_url' */
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->all()[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $next = $this->all();
        $next[$key] = $value;
        $this->replace($next);
    }

    /** @param array<string, mixed> $settings */
    public function replace(array $settings): void
    {
        $clean = Schema::sanitize($settings);
        update_option(Plugin::OPTION, $clean);
        $this->cache = $clean;
    }

    /**
     * Generation options for one model: its `models.overrides` entry merged over the global values.
     *
     * @return array<string, mixed> always carries temperature, num_ctx and keep_alive
     */
    public function modelOverrides(string $model): array
    {
        $global = [
            'temperature' => (float) $this->get('models.temperature'),
            'num_ctx' => (int) $this->get('models.num_ctx'),
            'keep_alive' => (string) $this->get('models.keep_alive'),
        ];
        $overrides = $this->get('models.overrides', []);
        $own = is_array($overrides) && is_array($overrides[$model] ?? null) ? $overrides[$model] : [];
        return array_merge($global, $own);
    }
}
