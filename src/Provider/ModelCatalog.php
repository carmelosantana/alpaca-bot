<?php

declare(strict_types=1);

namespace AlpacaBot\Provider;

use AlpacaBot\Settings\Store;

/**
 * The chat models the configured provider offers, cached in a transient for five minutes.
 *
 * What is cached (and memoised for the request) is the list as discovered from the
 * provider. The `alpaca_bot/models` filter runs on every all() call, over that list,
 * so a filter whose output depends on request context (per-user or per-capability
 * lists) is never baked into the site-wide transient.
 */
final class ModelCatalog
{
    public const TRANSIENT = 'alpaca_bot_models';
    private const TTL = 300;

    /** @var Model[]|null the discovered (pre-filter) list, once per request */
    private ?array $discovered = null;

    public function __construct(private Factory $factory) {}

    /**
     * @param bool $refresh skip the memo and the transient and ask the provider again
     * @return Model[] empty (and left uncached) when the provider is unreachable or lists nothing
     * @throws \UnexpectedValueException from Factory::make() when an `alpaca_bot/provider` filter misbehaves
     */
    public function all(bool $refresh = false): array
    {
        if ($refresh || $this->discovered === null) {
            $this->discovered = $this->discover($refresh);
        }

        /**
         * Filters the models the picker, the REST models route and the CLI list, on every read of
         * the catalog, over the list as discovered from the provider (that list, not the filtered
         * one, is what the transient caches, so a per-user or per-capability filter is never baked
         * into the site-wide cache). Drop a Model to hide it, or add one the provider did not list;
         * anything that is not a Model is dropped. The catalog is also the allow-list: a turn may
         * only use a model that survives this filter.
         *
         * @since 0.5.0
         * @param Model[] $models the models the provider listed, cached for five minutes
         * @var mixed $filtered what the filter returned, checked before it is trusted
         */
        $filtered = apply_filters('alpaca_bot/models', $this->discovered);

        return array_values(array_filter(is_array($filtered) ? $filtered : [], static fn(mixed $m): bool => $m instanceof Model));
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

    /** @return Model[] */
    private function discover(bool $refresh): array
    {
        $cached = $refresh ? false : get_transient(self::TRANSIENT);
        if (is_array($cached)) {
            return self::fromTransient($cached);
        }

        // make() stays outside the try: a broken alpaca_bot/provider filter must be as loud
        // here as it is from the pipeline. The provider's network call and its models()
        // contract are forgiven: a downed provider, or a filter-supplied one returning
        // something other than ModelDefinition[], reads as an empty (uncached) catalog.
        $provider = $this->factory->make();
        try {
            $models = array_map(Model::fromDefinition(...), $provider->models());
        } catch (\Throwable) {
            $models = [];
        }

        // Embedding models cannot chat; this is the one place they are excluded.
        $models = array_values(array_filter(
            $models,
            static fn(Model $m): bool => !str_contains(strtolower($m->id), 'embed'),
        ));

        if ($models !== []) {
            set_transient(self::TRANSIENT, array_map(static fn(Model $m): array => $m->toArray(), $models), self::TTL);
        }
        return $models;
    }

    /**
     * @param array<mixed> $cached the transient payload; entries that are not arrays or carry no id are dropped
     * @return Model[]
     */
    private static function fromTransient(array $cached): array
    {
        $models = [];
        foreach ($cached as $entry) {
            if (is_array($entry) && (string) ($entry['id'] ?? '') !== '') {
                $models[] = Model::fromArray($entry);
            }
        }
        return $models;
    }
}
