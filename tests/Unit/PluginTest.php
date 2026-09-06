<?php

declare(strict_types=1);

use AlpacaBot\Admin\Menu;
use AlpacaBot\Admin\SettingsPage;
use AlpacaBot\Chat\CapPolicy;
use AlpacaBot\Chat\ConversationStore;
use AlpacaBot\Chat\Pipeline;
use AlpacaBot\Chat\UsageMeter;
use AlpacaBot\Cli\ChatCommand;
use AlpacaBot\Context\Collector;
use AlpacaBot\Plugin;
use AlpacaBot\Provider\Factory;
use AlpacaBot\Provider\ModelCatalog;
use AlpacaBot\Settings\Migrate04;
use AlpacaBot\Settings\Store;
use Brain\Monkey\Actions;
use Brain\Monkey\Functions;

it('exposes a version and boots once', function (): void {
    // Mockery only honours a matcher at the top level of an argument, so the
    // [instance, 'register'] callback is pinned with on() rather than type().
    $registerCallback = Mockery::on(
        static fn (mixed $cb): bool => is_array($cb)
            && ($cb[0] ?? null) instanceof Plugin
            && ($cb[1] ?? null) === 'register'
    );
    Actions\expectAdded('plugins_loaded')->once()->with($registerCallback, 9);
    $a = Plugin::boot();
    $b = Plugin::boot();
    expect($a)->toBe($b)
        ->and($a->version())->toBe(Plugin::VERSION)
        ->and(Plugin::VERSION)->toMatch('/^1\.0\.0/');
});

it('registers the settings store, provider factory, model catalog, conversation store, usage meter, cap policy, context collector and chat pipeline, hooks both post types on init, and runs the 0.4 migration on init after them, never on admin_init', function (): void {
    Actions\expectAdded('init')->once()->with(Mockery::on(
        static fn (mixed $cb): bool => is_array($cb)
            && ($cb[0] ?? null) instanceof ConversationStore
            && ($cb[1] ?? null) === 'registerPostType'
    ));
    Actions\expectAdded('init')->once()->with(Mockery::on(
        static fn (mixed $cb): bool => is_array($cb)
            && ($cb[0] ?? null) instanceof UsageMeter
            && ($cb[1] ?? null) === 'registerPostType'
    ));
    // On init, not admin_init: WP-CLI (the whole P1 user surface) never fires admin_init, and
    // the post types register on init at 10, so the migration's chat_history query runs after.
    $onInit = null;
    Actions\expectAdded('init')->once()->with(Mockery::on(
        static function (mixed $cb) use (&$onInit): bool {
            $onInit = $cb;
            return $cb instanceof Closure;
        }
    ), 20);
    // The daily usage cleanup is (re)scheduled on init, where an already-installed site reaches
    // it without a reactivation, and its hook is wired here so cron has a listener.
    Actions\expectAdded('init')->once()->with(Mockery::on(
        static fn (mixed $cb): bool => is_array($cb)
            && ($cb[0] ?? null) instanceof UsageMeter
            && ($cb[1] ?? null) === 'scheduleCleanup'
    ));
    // A closure: cleanup() returns the deleted count for callers that want it, and an action
    // callback must return nothing.
    Actions\expectAdded(UsageMeter::CLEANUP_HOOK)->once()->with(Mockery::type(Closure::class));
    // The only admin_init listener is the Settings API registration; the migration is not there.
    Actions\expectAdded('admin_init')->once()->with(Mockery::on(
        static fn (mixed $cb): bool => is_array($cb)
            && ($cb[0] ?? null) instanceof SettingsPage
            && ($cb[1] ?? null) === 'register'
    ));
    Actions\expectAdded('admin_menu')->once()->with(Mockery::on(
        static fn (mixed $cb): bool => is_array($cb)
            && ($cb[0] ?? null) instanceof Menu
            && ($cb[1] ?? null) === 'register'
    ));
    $plugin = Plugin::boot();
    $plugin->register();
    expect($plugin->get(SettingsPage::class))->toBeInstanceOf(SettingsPage::class);
    expect($plugin->get(Store::class))->toBeInstanceOf(Store::class)
        ->and($plugin->get(Factory::class))->toBeInstanceOf(Factory::class)
        ->and($plugin->get(ModelCatalog::class))->toBeInstanceOf(ModelCatalog::class)
        ->and($plugin->get(ConversationStore::class))->toBeInstanceOf(ConversationStore::class)
        ->and($plugin->get(UsageMeter::class))->toBeInstanceOf(UsageMeter::class)
        ->and($plugin->get(CapPolicy::class))->toBeInstanceOf(CapPolicy::class)
        ->and($plugin->get(Collector::class))->toBeInstanceOf(Collector::class)
        ->and($plugin->get(Pipeline::class))->toBeInstanceOf(Pipeline::class);

    // A 0.4 site: one legacy option present, no flag -> the hook migrates and flags.
    $legacy = ['alpaca_bot_api_url' => 'http://localhost:11434'];
    Functions\when('get_option')->alias(fn(string $k, mixed $d = false) => $legacy[$k] ?? ($k === Plugin::OPTION ? [] : $d));
    Functions\expect('update_option')->once()->with(Plugin::OPTION, Mockery::type('array'))->andReturn(true);
    Functions\expect('update_option')->once()->with(Migrate04::FLAG, '1', false)->andReturn(true);
    Functions\expect('update_option')->once()->with(Migrate04::FLAG_CONVERSATIONS, '1', false)->andReturn(true);
    Functions\when('get_posts')->justReturn([]);
    $onInit();
    expect($plugin->get(Store::class)->get('provider.base_url'))->toBe('http://localhost:11434/v1');
});

it('registers the wp alpaca-bot command when WP-CLI is the running process, and the 0.4 migration reaches that process through init', function (): void {
    // WP_CLI (the constant and the class) is process-wide once defined, and Pest runs every test
    // in one process, so this test is the one place that defines it. The stand-in records what
    // add_command() was given; every later register() in the run goes through it harmlessly.
    if (!class_exists('WP_CLI', false)) {
        class_alias(get_class(new class {
            /** @var list<array{0: string, 1: mixed}> */
            public static array $commands = [];

            public static function add_command(string $name, mixed $callable): bool
            {
                self::$commands[] = [$name, $callable];
                return true;
            }
        }), 'WP_CLI');
    }
    if (!defined('WP_CLI')) {
        define('WP_CLI', true);
    }
    // wp-cli loads WordPress fully and fires init, never admin_init: an upgraded 0.4 site's
    // first `wp alpaca-bot chat` must see the admin's configured api_url, not the schema default.
    $migration = null;
    Actions\expectAdded('init')->once()->with(Mockery::on(static function (mixed $cb) use (&$migration): bool {
        $migration = $cb instanceof Closure ? $cb : $migration;
        return $cb instanceof Closure;
    }), 20);
    Actions\expectAdded('init')->times(3)->with(Mockery::type('array'));
    Actions\expectAdded('admin_init')->once()->with(Mockery::type('array'));
    $plugin = Plugin::boot();
    $plugin->register();
    expect(\WP_CLI::$commands)->toHaveCount(1)
        ->and(\WP_CLI::$commands[0][0])->toBe('alpaca-bot')
        ->and(\WP_CLI::$commands[0][1])->toBeInstanceOf(ChatCommand::class)
        ->and($migration)->toBeInstanceOf(Closure::class);

    $legacy = ['alpaca_bot_api_url' => 'http://ollama.internal:11434', 'alpaca_bot_default_model' => 'qwen3:8b'];
    Functions\when('get_option')->alias(fn(string $k, mixed $d = false) => $legacy[$k] ?? ($k === Plugin::OPTION ? [] : $d));
    Functions\when('update_option')->justReturn(true);
    Functions\when('get_posts')->justReturn([]);
    $migration();
    expect($plugin->get(Store::class)->get('provider.base_url'))->toBe('http://ollama.internal:11434/v1')
        ->and($plugin->get(Store::class)->get('models.default'))->toBe('qwen3:8b');
});

it('deactivate() clears the daily usage cleanup so a deactivated plugin leaves no cron event behind', function (): void {
    Functions\expect('wp_clear_scheduled_hook')->once()->with(UsageMeter::CLEANUP_HOOK)->andReturn(1);
    Plugin::deactivate();
});
