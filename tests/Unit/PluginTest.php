<?php

declare(strict_types=1);

use AlpacaBot\Admin\Assets;
use AlpacaBot\Admin\ChatScreen;
use AlpacaBot\Admin\HelpTabs;
use AlpacaBot\Admin\Menu;
use AlpacaBot\Admin\SettingsPage;
use AlpacaBot\Chat\CapPolicy;
use AlpacaBot\Chat\ConversationStore;
use AlpacaBot\Chat\Pipeline;
use AlpacaBot\Chat\UsageMeter;
use AlpacaBot\Chat\UserPrefs;
use AlpacaBot\Cli\ChatCommand;
use AlpacaBot\Context\Collector;
use AlpacaBot\Plugin;
use AlpacaBot\Provider\Factory;
use AlpacaBot\Provider\ModelCatalog;
use AlpacaBot\Rest\StreamController;
use AlpacaBot\Rest\ViewController;
use AlpacaBot\Settings\Migrate04;
use AlpacaBot\Settings\Store;
use AlpacaBot\Shortcodes;
use AlpacaBot\Toolkit\DraftPostToolkit;
use AlpacaBot\Toolkit\Registry;
use AlpacaBot\Toolkit\SummarizeToolkit;
use AlpacaBot\Toolkit\WebFetchToolkit;
use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
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
        ->and(Plugin::VERSION)->toMatch('/^0\.5\.0/');
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
    // plugins_loaded runs before the current user is resolved, so a toolkit that read the id at
    // construction would get 0 for every turn: the id must not be asked for here at all.
    Functions\expect('get_current_user_id')->never();
    // Both shortcodes, registered here rather than on init: `$shortcode_tags` exists from
    // shortcodes.php's load, and a shortcode registered on plugins_loaded is there for
    // whatever renders content first, WP-CLI included.
    Functions\expect('add_shortcode')->once()->with('alpacabot', Mockery::on(
        static fn (mixed $cb): bool => is_array($cb) && ($cb[0] ?? null) instanceof Shortcodes\Chat && ($cb[1] ?? null) === 'render'
    ));
    Functions\expect('add_shortcode')->once()->with('alpacabot_agent', Mockery::on(
        static fn (mixed $cb): bool => is_array($cb) && ($cb[0] ?? null) instanceof Shortcodes\AgentShim && ($cb[1] ?? null) === 'render'
    ));
    $plugin = Plugin::boot();
    $plugin->register();
    expect($plugin->get(Shortcodes\Chat::class))->toBeInstanceOf(Shortcodes\Chat::class)
        ->and($plugin->get(Shortcodes\AgentShim::class))->toBeInstanceOf(Shortcodes\AgentShim::class);
    expect($plugin->get(SettingsPage::class))->toBeInstanceOf(SettingsPage::class);
    expect($plugin->get(Store::class))->toBeInstanceOf(Store::class)
        ->and($plugin->get(Factory::class))->toBeInstanceOf(Factory::class)
        ->and($plugin->get(ModelCatalog::class))->toBeInstanceOf(ModelCatalog::class)
        ->and($plugin->get(ConversationStore::class))->toBeInstanceOf(ConversationStore::class)
        ->and($plugin->get(UsageMeter::class))->toBeInstanceOf(UsageMeter::class)
        ->and($plugin->get(CapPolicy::class))->toBeInstanceOf(CapPolicy::class)
        ->and($plugin->get(Collector::class))->toBeInstanceOf(Collector::class)
        ->and($plugin->get(Pipeline::class))->toBeInstanceOf(Pipeline::class);
    // The three built-in toolkits, registered under the ids the schema's default names, in that
    // order. The setting enables all three by default, and a stubbed get_option() answers
    // nothing here, so all three are enabled; the filter runs with the user id it was given.
    Functions\when('get_option')->justReturn([]);
    Filters\expectApplied('alpaca_bot/toolkits')->once()->with(Mockery::type('array'), 3)->andReturnFirstArg();
    $registry = $plugin->get(Registry::class);
    expect($registry)->toBeInstanceOf(Registry::class)
        ->and($registry->ids())->toBe(['web_fetch', 'summarize', 'draft_post']);
    $enabled = $registry->enabled(3);
    expect($enabled['web_fetch'])->toBeInstanceOf(WebFetchToolkit::class)
        ->and($enabled['summarize'])->toBeInstanceOf(SummarizeToolkit::class)
        ->and($enabled['draft_post'])->toBeInstanceOf(DraftPostToolkit::class);
    // The pipeline holds that same registry, so a chat turn can run the toolkits: the two are
    // built in a cycle (summarize runs through the pipeline; the pipeline asks the registry),
    // which register() resolves by building the registry empty first and filling it after.
    expect((new ReflectionProperty(Pipeline::class, 'toolkits'))->getValue($plugin->get(Pipeline::class)))->toBe($registry);

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
    Functions\when('add_shortcode')->justReturn();
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

it('renders the chat screen, enqueues its assets, hands the pipeline the user preferences, and registers the view routes with their HTML serving hook', function (): void {
    // The P3 wiring: the menu's chat renderer is Admin\ChatScreen (no placeholder), Admin\Assets
    // listens on admin_enqueue_scripts, and the REST controllers gathered on rest_api_init
    // include the ViewController, whose register() hooks rest_pre_serve_request.
    $menuRenderer = null;
    Functions\when('add_menu_page')->alias(function (mixed ...$args) use (&$menuRenderer): void {
        $menuRenderer = $args[4];
    });
    Functions\when('add_submenu_page')->justReturn(false);
    Functions\when('add_shortcode')->justReturn();
    Filters\expectApplied('alpaca_bot/admin/menu_capability')->once()->andReturn('edit_posts');
    Actions\expectAdded('admin_enqueue_scripts')->once()->with(Mockery::on(
        static fn (mixed $cb): bool => is_array($cb) && ($cb[0] ?? null) instanceof Assets && ($cb[1] ?? null) === 'enqueue'
    ));
    // The nonce refresh answers on admin-ajax, where admin_enqueue_scripts never fires, so the
    // heartbeat filter is hooked at register() time, not from enqueue().
    Filters\expectAdded('heartbeat_received')->once()->with(Mockery::on(
        static fn (mixed $cb): bool => is_array($cb) && ($cb[0] ?? null) instanceof Assets && ($cb[1] ?? null) === 'heartbeat'
    ), 10, 2);
    // The help tabs listen on current_screen and gate on the screen id themselves (HelpTabs::SCREENS).
    Actions\expectAdded('current_screen')->once()->with(Mockery::on(
        static fn (mixed $cb): bool => is_array($cb) && ($cb[0] ?? null) instanceof HelpTabs && ($cb[1] ?? null) === 'add'
    ));
    $onRestInit = null;
    Actions\expectAdded('rest_api_init')->once()->with(Mockery::on(static function (mixed $cb) use (&$onRestInit): bool {
        $onRestInit = $cb;
        return $cb instanceof Closure;
    }));
    $onAdminMenu = null;
    Actions\expectAdded('admin_menu')->once()->with(Mockery::on(static function (mixed $cb) use (&$onAdminMenu): bool {
        $onAdminMenu = $cb;
        return is_array($cb) && $cb[0] instanceof Menu && $cb[1] === 'register';
    }));
    $plugin = Plugin::boot();
    $plugin->register();
    expect($plugin->get(UserPrefs::class))->toBeInstanceOf(UserPrefs::class)
        ->and($plugin->get(ChatScreen::class))->toBeInstanceOf(ChatScreen::class);

    // The menu is built on admin_menu; run it as core would and read the renderer it was given.
    $onAdminMenu();
    expect($menuRenderer)->toBe([$plugin->get(ChatScreen::class), 'render']);

    $registered = [];
    Functions\when('register_rest_route')->alias(static function (string $ns, string $path) use (&$registered): void {
        $registered[] = $path;
    });
    $servers = [];
    Filters\expectAdded('rest_pre_serve_request')->times(2)->with(Mockery::on(static function (mixed $cb) use (&$servers): bool {
        if (!is_array($cb) || ($cb[1] ?? null) !== 'serve') {
            return false;
        }
        $servers[] = $cb[0]::class;
        return true;
    }), PHP_INT_MAX, 4);
    Filters\expectApplied('alpaca_bot/rest/controllers')->once()->andReturnFirstArg();
    $onRestInit();
    expect($registered)->toContain('/view/history')->toContain('/view/messages/(?P<id>\d+)')->toContain('/chat')
        ->and($servers)->toBe([StreamController::class, ViewController::class]);
});
