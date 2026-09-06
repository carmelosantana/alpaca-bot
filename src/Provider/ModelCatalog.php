<?php

declare(strict_types=1);

namespace AlpacaBot\Provider;

use AlpacaBot\Settings\Store;

/**
 * The chat models the configured provider offers, cached in a transient for five minutes.
 */
final class ModelCatalog
{
    public const TRANSIENT = 'alpaca_bot_models';
    private const TTL = 300;

    public function __construct(private Factory $factory) {}

    /**
     * @param bool $refresh skip the transient and ask the provider again
     * @return Model[] empty (and left uncached) when the provider is unreachable or lists nothing
     */
    public function all(bool $refresh = false): array
    {
        $cached = $refresh ? false : get_transient(self::TRANSIENT);
        if (is_array($cached)) {
            return array_values(array_map(Model::fromArray(...), array_filter($cached, 'is_array')));
        }

        try {
            $models = array_map(Model::fromDefinition(...), $this->factory->make()->models());
        } catch (\Throwable) {
            $models = [];
        }
        $models = array_values(array_filter($models, static fn(Model $m): bool => !str_contains(strtolower($m->id), 'embed')));

        $filtered = apply_filters('alpaca_bot/models', $models);
        /** @var Model[] $models */
        $models = array_values(array_filter(is_array($filtered) ? $filtered : [], static fn(mixed $m): bool => $m instanceof Model));

        if ($models !== []) {
            set_transient(self::TRANSIENT, array_map(static fn(Model $m): array => $m->toArray(), $models), self::TTL);
        }
        return $models;
    }

    public function find(string $id): ?Model
    {
        foreach ($this->all() as $m) {
            if ($m->id === $id) {
                return $m;
            }
        }
        return null;
    }

    /** The configured `models.default` when the catalog has it, else the first listed model, else the setting as is. */
    public function defaultId(Store $store): string
    {
        $wanted = (string) $store->get('models.default');
        $all = $this->all();
        foreach ($all as $m) {
            if ($wanted !== '' && $m->id === $wanted) {
                return $wanted;
            }
        }
        return $all[0]->id ?? $wanted;
    }
}
