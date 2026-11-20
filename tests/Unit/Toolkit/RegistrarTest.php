<?php

declare(strict_types=1);

use AlpacaBot\Access;
use AlpacaBot\Settings\Schema;
use AlpacaBot\Settings\Store;
use AlpacaBot\Toolkit\Registrar;
use AlpacaBot\Toolkit\Registry;
use Brain\Monkey\Functions;

/** A registry holding the four built-ins, as Plugin::register() leaves it before companions are asked. */
function registrarRegistry(array $settings = []): Registry
{
    $registry = new Registry(new Store($settings));
    foreach (['web_fetch', 'summarize', 'draft_post', 'abilities'] as $id) {
        $registry->register($id, echoToolkit($id));
    }
    return $registry;
}

it('registers a companion toolkit with the registry, gated by its own tool row, and gives it a box under Enabled tools and a row under Access', function (): void {
    $registry = registrarRegistry();
    $kit = echoToolkit('lookup_order');
    $added = (new Registrar($registry))->add('orders', $kit, 'Look up an order', 'Reads one WooCommerce order by number.', 'edit_others_posts');

    $fields = Schema::fields();
    expect($added)->toBeTrue()
        ->and($registry->ids())->toBe(['web_fetch', 'summarize', 'draft_post', 'abilities', 'orders'])
        ->and($fields['toolkits.enabled']['options'])->toHaveKey('orders')
        ->and($fields['toolkits.enabled']['options']['orders'])->toBe('Look up an order')
        // A companion is off until an administrator ticks it.
        ->and($fields['toolkits.enabled']['default'])->toBe(['web_fetch', 'summarize', 'draft_post'])
        ->and($fields['access.tool.orders'])->toMatchArray(['type' => 'select', 'default' => 'edit_others_posts', 'section' => 'access', 'label' => 'Tool: Look up an order', 'description' => 'Reads one WooCommerce order by number.'])
        ->and(array_key_last(array_filter($fields, static fn(string $k): bool => str_starts_with($k, 'access.tool.'), ARRAY_FILTER_USE_KEY)))->toBe('access.tool.orders')
        ->and(Access::defaults()['tool.orders'])->toBe('edit_others_posts')
        ->and(Access::expectedArgs('tool.orders'))->toBe(1);
});

it('offers the companion to a turn only once it is ticked and only to users its row admits, like a built-in', function (): void {
    $kit = echoToolkit('lookup_order');
    $capabilities = [];
    Functions\when('user_can')->alias(static function (int $user, string $cap) use (&$capabilities): bool {
        $capabilities[] = $cap;
        return $cap === 'edit_others_posts';
    });
    $off = registrarRegistry(['toolkits.enabled' => []]);
    (new Registrar($off))->add('orders', $kit, 'Orders', '', 'edit_others_posts');
    $on = registrarRegistry(['toolkits.enabled' => ['orders']]);
    expect($off->enabled(3))->toBe([])
        ->and((new Registrar($on))->add('orders', $kit, 'Orders', '', 'edit_others_posts'))->toBeTrue()
        ->and($on->enabled(3))->toBe(['orders' => $kit])
        ->and($capabilities)->toBe(['edit_others_posts']);
});

it('stores a capability it does not offer as administrators only', function (): void {
    (new Registrar(registrarRegistry()))->add('orders', echoToolkit('x'), 'Orders', '', 'delete_everything');
    expect(Access::defaults()['tool.orders'])->toBe('manage_options')
        ->and(Schema::fields()['access.tool.orders']['default'])->toBe('manage_options');
});

it('refuses an id that is not a short lower-case word, one already registered, and the mcp prefix, saying why and registering nothing', function (string $id): void {
    $registry = registrarRegistry();
    $registrar = new Registrar($registry);
    $registrar->add('orders', echoToolkit('x'), 'Orders');
    Functions\expect('_doing_it_wrong')->once()->with(Registrar::class . '::add', Mockery::type('string'), '0.8.0');
    expect($registrar->add($id, echoToolkit('y'), 'Again'))->toBeFalse()
        ->and($registry->ids())->toBe(['web_fetch', 'summarize', 'draft_post', 'abilities', 'orders'])
        ->and(array_keys(Registrar::all()))->toBe(['orders']);
})->with([
    'a built-in' => ['web_fetch'],
    'one a companion took' => ['orders'],
    'an MCP-looking id' => ['mcp.trk'],
    'upper case' => ['Orders'],
    'a dash' => ['my-tool'],
    'empty' => [''],
    'too long' => [str_repeat('a', 33)],
    'leading digit' => ['1tool'],
]);
