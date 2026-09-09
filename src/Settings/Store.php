<?php

declare(strict_types=1);

namespace AlpacaBot\Settings;

use AlpacaBot\Plugin;

/**
 * Read/write access to the single `alpaca_bot_settings` option.
 *
 * The option is read at most once per request and memoized; every write goes through
 * Schema::sanitize() over what is held now, so the stored array is always complete and valid,
 * a key a write leaves out keeps its value, and a secret written as Schema::MASK keeps its
 * stored value rather than becoming the mask.
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

    /** @param array<string, mixed> $settings the keys to write; a key left out keeps what is held, so a reset says every key (Schema::defaults()) */
    public function replace(array $settings): void
    {
        $clean = Schema::sanitize($settings, $this->all());
        update_option(Plugin::OPTION, $clean);
        $this->cache = $clean;
    }

    /**
     * Generation options for one model: its `models.overrides` entry merged over the global values.
     *
     * The row is merged whole, so a key that is not a generation option rides along with it
     * (`system`, which Pipeline reads from here for the model-level prompt, and `tools`, which
     * has its own reader below). Callers take the keys they came for.
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
        return array_merge($global, $this->override($model));
    }

    /**
     * The operator's answer for one model: true forces tools on, false forces them off, null is
     * inherit and means the caller must ask the catalogue instead.
     *
     * Three states, so the answer is `?bool` and not a bool with a default: a model nobody has
     * touched must reach the catalogue's word, and any bool here would shadow it. Only the two
     * literals sanitizeOverrides() stores are read; anything else (a row written before this
     * key existed, a hand-edited option, a blank cell that was never stored) is inherit.
     *
     * @since 0.5.0
     */
    public function toolsOverride(string $model): ?bool
    {
        $stored = $this->override($model)['tools'] ?? null;
        return match (is_scalar($stored) ? (string) $stored : '') {
            Schema::TOOLS_ON => true,
            Schema::TOOLS_OFF => false,
            default => null,
        };
    }

    /**
     * One model's stored `models.overrides` row, or [] when there is none.
     *
     * @return array<string, mixed>
     */
    private function override(string $model): array
    {
        $overrides = $this->get('models.overrides', []);
        return is_array($overrides) && is_array($overrides[$model] ?? null) ? $overrides[$model] : [];
    }
}
