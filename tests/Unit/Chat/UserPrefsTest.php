<?php

declare(strict_types=1);

use AlpacaBot\Chat\UserPrefs;
use AlpacaBot\Provider\Factory;
use AlpacaBot\Provider\ModelCatalog;
use AlpacaBot\Settings\Store;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

/**
 * The per-user default model (Kanboard #565): one user-meta row, and the rule for when it is
 * the model a turn runs on.
 *
 * @param list<string> $listed model ids the cached catalog holds; empty leaves the catalog empty (an unreachable provider)
 */
function userPrefsCatalog(array $listed, array $settings = []): array
{
    Functions\when('get_transient')->justReturn($listed === [] ? false : array_map(static fn(string $id): array => ['id' => $id, 'label' => $id], $listed));
    Functions\when('set_transient')->justReturn(true);
    Filters\expectApplied('alpaca_bot/models')->zeroOrMoreTimes()->andReturnFirstArg();
    if ($listed === []) {
        // Discovery asks the provider; a provider that lists nothing is the outage case.
        Filters\expectApplied('alpaca_bot/provider')->zeroOrMoreTimes()->andReturnUsing(static fn(object $built): object => $built);
    }
    $store = new Store($settings + ['models.default' => 'llama3.2']);
    return [new ModelCatalog(new Factory($store)), $store];
}

it('reads and writes the default model as user meta', function (): void {
    Functions\expect('get_user_meta')->once()->with(3, UserPrefs::META_DEFAULT_MODEL, true)->andReturn('llava:7b');
    Functions\expect('get_user_meta')->once()->with(4, UserPrefs::META_DEFAULT_MODEL, true)->andReturn('');
    Functions\expect('update_user_meta')->once()->with(3, UserPrefs::META_DEFAULT_MODEL, 'qwen3:8b');
    $prefs = new UserPrefs();
    expect($prefs->defaultModel(3))->toBe('llava:7b')
        ->and($prefs->defaultModel(4))->toBe('')
        ->and(UserPrefs::META_DEFAULT_MODEL)->toBe('alpaca_bot_default_model');
    $prefs->setDefaultModel(3, 'qwen3:8b');
});

it('resolves the model a user\'s turn runs on: their default while the site allows it and the catalog lists it, else the site default', function (): void {
    $prefs = new UserPrefs();
    Functions\when('get_user_meta')->alias(static fn(int $id): string => $id === 3 ? 'llava:7b' : '');

    [$catalog, $store] = userPrefsCatalog(['llama3.2', 'llava:7b']);
    expect($prefs->modelFor(3, $catalog, $store))->toBe('llava:7b')
        // No preference stored: the site default.
        ->and($prefs->modelFor(4, $catalog, $store))->toBe('llama3.2');

    // The site does not let users change the model: a preference stored earlier does not apply.
    [$catalog, $store] = userPrefsCatalog(['llama3.2', 'llava:7b'], ['chat.user_can_change_model' => false]);
    expect($prefs->modelFor(3, $catalog, $store))->toBe('llama3.2');

    // The preferred model has since gone from the provider: the site default, not a 400 on every turn.
    [$catalog, $store] = userPrefsCatalog(['llama3.2', 'qwen3:8b']);
    expect($prefs->modelFor(3, $catalog, $store))->toBe('llama3.2');
});

it('lets the preference through when the catalog is empty, as the pipeline does for a requested model', function (): void {
    // ModelCatalog reads an unreachable provider as an empty list; refusing here would report an
    // outage as a stale preference. The provider call fails loudly instead, either way.
    Functions\when('get_user_meta')->justReturn('llava:7b');
    [$catalog, $store] = userPrefsCatalog([]);
    expect((new UserPrefs())->modelFor(3, $catalog, $store))->toBe('llava:7b');
});
