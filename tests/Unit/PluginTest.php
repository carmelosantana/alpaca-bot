<?php

declare(strict_types=1);

use AlpacaBot\Abilities;
use AlpacaBot\Access;
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
use AlpacaBot\Settings\ProviderKey;
use AlpacaBot\Settings\Schema;
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
        ->and(Plugin::VERSION)->toMatch('/^0\.6\.1/');
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
    // The built-in toolkits, registered under the ids the schema's `toolkits.enabled` options
    // name, in that order. A stubbed get_option() answers nothing here, so the schema's default
    // list is what enables them, and it leaves abilities off; the filter runs with the user id it
    // was given.
    Functions\when('get_option')->justReturn([]);
    // enabled() holds each toolkit to its Access row for that user. This test is about the wiring
    // -- which toolkits are registered, under which ids, into which pipeline -- so the user
    // passes every row; RegistryTest is where the floor itself is pinned, and
    // tests/Integration/ToolkitsTest.php where real roles answer it.
    Functions\when('user_can')->justReturn(true);
    Filters\expectApplied('alpaca_bot/toolkits')->once()->with(Mockery::type('array'), 3)->andReturnFirstArg();
    $registry = $plugin->get(Registry::class);
    expect($registry)->toBeInstanceOf(Registry::class)
        ->and($registry->ids())->toBe(['web_fetch', 'summarize', 'draft_post', 'abilities']);
    $enabled = $registry->enabled(3);
    expect(array_keys($enabled))->toBe(['web_fetch', 'summarize', 'draft_post'])
        ->and($enabled['web_fetch'])->toBeInstanceOf(WebFetchToolkit::class)
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
    Functions\expect('update_option')->once()->with(Migrate04::FLAG, '1', true)->andReturn(true);
    Functions\expect('update_option')->once()->with(Migrate04::FLAG_CONVERSATIONS, '1', true)->andReturn(true);
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
    // The menu's capability is the Chat row now, so building it reads the settings option.
    Functions\when('get_option')->justReturn([]);
    Filters\expectApplied('alpaca_bot/admin/menu_capability')->once()->andReturn('edit_posts');
    Actions\expectAdded('admin_enqueue_scripts')->once()->with(Mockery::on(
        static fn (mixed $cb): bool => is_array($cb) && ($cb[0] ?? null) instanceof Assets && ($cb[1] ?? null) === 'enqueue'
    ));
    // The nonce refresh answers on admin-ajax, where admin_enqueue_scripts never fires, so the
    // heartbeat filter is hooked at register() time, not from enqueue().
    Filters\expectAdded('heartbeat_received')->once()->with(Mockery::on(
        static fn (mixed $cb): bool => is_array($cb) && ($cb[0] ?? null) instanceof Assets && ($cb[1] ?? null) === 'heartbeat'
    ), 10, 2);
    // The help tabs listen on current_screen and gate on the screen id themselves (HelpTabs::screens()).
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

it('registers the abilities on the two hooks core fires when its registries are first used, over the container\'s own pipeline and registry', function (): void {
    Functions\when('add_shortcode')->justReturn();
    // The category first: core refuses an ability whose category is not registered, and its
    // categories registry is built (and this hook fired) before wp_abilities_api_init.
    Actions\expectAdded('wp_abilities_api_categories_init')->once()->with(Mockery::on(
        static fn (mixed $cb): bool => is_array($cb) && ($cb[0] ?? null) instanceof Abilities\Register && ($cb[1] ?? null) === 'registerCategory'
    ));
    Actions\expectAdded('wp_abilities_api_init')->once()->with(Mockery::on(
        static fn (mixed $cb): bool => is_array($cb) && ($cb[0] ?? null) instanceof Abilities\Register && ($cb[1] ?? null) === 'register'
    ));
    // No init fallback: wp_register_ability() refuses any call outside its own action, and the
    // plugin's floor (6.9) has the API in core, so a fallback could only fire a notice. These
    // two are every init listener there is; one more is a call Mockery has no handler for.
    Actions\expectAdded('init')->times(3)->with(Mockery::type('array'));
    Actions\expectAdded('init')->once()->with(Mockery::type(Closure::class), 20);
    $plugin = Plugin::boot();
    $plugin->register();
    $register = $plugin->get(Abilities\Register::class);
    expect($register)->toBeInstanceOf(Abilities\Register::class)
        ->and((new ReflectionProperty(Abilities\Register::class, 'pipeline'))->getValue($register))->toBe($plugin->get(Pipeline::class))
        ->and((new ReflectionProperty(Abilities\Register::class, 'registry'))->getValue($register))->toBe($plugin->get(Registry::class));
});

// The fallback notice is the factory's verdict (Factory::fallbackNotice()) rendered where admin
// notices render; the hook is here so the verdict has one source and the factory prints nothing.
// The catalog bust is the other half of the provider picker: the model list is cached site-wide
// for five minutes, and a site that switched kinds would otherwise see the previous provider's
// models (and route a turn at one) until it expired.
it('prints the wp-ai fallback notice to administrators on admin_notices, and busts the model catalog when a provider setting changes', function (): void {
    Functions\when('add_shortcode')->justReturn();
    $onNotices = null;
    Actions\expectAdded('admin_notices')->once()->with(Mockery::on(static function (mixed $cb) use (&$onNotices): bool {
        $onNotices = $cb;
        return $cb instanceof Closure;
    }));
    $onUpdate = null;
    // Mcp\ServerSettings adds a listener of its own on this action (forgetPassed()); the one
    // under test is Plugin's closure.
    Actions\expectAdded('update_option_' . Plugin::OPTION)->once()->with(Mockery::on(static function (mixed $cb) use (&$onUpdate): bool {
        if (!$cb instanceof Closure) {
            return false;
        }
        $onUpdate = $cb;
        return true;
    }), 10, 2);
    $plugin = Plugin::boot();
    $plugin->register();

    // This process has no core AI client, so wp-ai selected is the fallback case.
    Functions\when('get_option')->justReturn(['provider.kind' => 'wp-ai', 'provider.base_url' => 'http://ollama:11434/v1']);
    Functions\when('current_user_can')->justReturn(true);
    ob_start();
    $onNotices();
    $html = (string) ob_get_clean();
    expect($html)->toContain('notice-warning')
        ->and($html)->toContain('http://ollama:11434/v1');

    // Not for a user who cannot change the setting.
    Functions\when('current_user_can')->justReturn(false);
    ob_start();
    $onNotices();
    expect((string) ob_get_clean())->toBe('');

    // Nothing when Ollama is selected: the factory has no fallback to report.
    (new ReflectionProperty(Store::class, 'cache'))->setValue($plugin->get(Store::class), null);
    Functions\when('get_option')->justReturn(['provider.kind' => 'ollama']);
    Functions\when('current_user_can')->justReturn(true);
    ob_start();
    $onNotices();
    expect((string) ob_get_clean())->toBe('');

    Functions\expect('delete_transient')->twice()->with(ModelCatalog::TRANSIENT)->andReturn(true);
    $onUpdate(['provider.kind' => 'ollama', 'chat.welcome' => 'a'], ['provider.kind' => 'wp-ai', 'chat.welcome' => 'a']);
    $onUpdate(['provider.kind' => 'ollama', 'chat.welcome' => 'a'], ['provider.kind' => 'ollama', 'chat.welcome' => 'b']);
    $onUpdate('not an array', ['provider.kind' => 'ollama']);
});

it('registers one Access over the container\'s Store, so every surface resolves a row the same way', function (): void {
    Functions\when('add_shortcode')->justReturn();
    Functions\when('get_option')->justReturn(['access.chat' => 'publish_posts']);
    $plugin = Plugin::boot();
    $plugin->register();
    expect($plugin->get(Access::class))->toBeInstanceOf(Access::class)
        ->and($plugin->get(Access::class)->stored('chat'))->toBe('publish_posts');
});

it('puts the drawer on the other admin screens: its loader on admin_enqueue_scripts and its launcher on admin_footer, and the editor sidebar on enqueue_block_editor_assets, over the container\'s Access and preferences', function (): void {
    Functions\when('add_shortcode')->justReturn();
    Actions\expectAdded('admin_enqueue_scripts')->once()->with(Mockery::on(
        static fn (mixed $cb): bool => is_array($cb) && ($cb[0] ?? null) instanceof AlpacaBot\Admin\Assets && ($cb[1] ?? null) === 'enqueue'
    ));
    Actions\expectAdded('admin_enqueue_scripts')->once()->with(Mockery::on(
        static fn (mixed $cb): bool => is_array($cb) && ($cb[0] ?? null) instanceof AlpacaBot\Admin\Drawer && ($cb[1] ?? null) === 'enqueue'
    ));
    Actions\expectAdded('admin_footer')->once()->with(Mockery::on(
        static fn (mixed $cb): bool => is_array($cb) && ($cb[0] ?? null) instanceof AlpacaBot\Admin\Drawer && ($cb[1] ?? null) === 'footer'
    ));
    // And, in its place on a block editor screen, the editor's own sidebar.
    Actions\expectAdded('enqueue_block_editor_assets')->once()->with(Mockery::on(
        static fn (mixed $cb): bool => is_array($cb) && ($cb[0] ?? null) instanceof AlpacaBot\Admin\Drawer && ($cb[1] ?? null) === 'enqueueEditor'
    ));
    $plugin = Plugin::boot();
    $plugin->register();
    $drawer = $plugin->get(AlpacaBot\Admin\Drawer::class);
    expect($drawer)->toBeInstanceOf(AlpacaBot\Admin\Drawer::class)
        ->and((new ReflectionProperty(AlpacaBot\Admin\Drawer::class, 'access'))->getValue($drawer))->toBe($plugin->get(AlpacaBot\Access::class))
        ->and((new ReflectionProperty(AlpacaBot\Admin\Drawer::class, 'prefs'))->getValue($drawer))->toBe($plugin->get(UserPrefs::class));
});

// The split of an MCP server's header value into its own option has to run on every write of the
// settings option, whoever makes it, so it is hooked at boot rather than by the settings page;
// and the page and the REST route are handed the same instance, so their address check is the
// one the container holds.
it('hooks the MCP server settings onto the option\'s writes and hands the same instance to the settings page and the REST route', function (): void {
    Functions\when('add_shortcode')->justReturn();
    $hooked = null;
    // Settings\ProviderKey adds a filter of its own on this hook; the one under test is the MCP split.
    Filters\expectAdded('pre_update_option_' . Plugin::OPTION)->twice()->with(Mockery::on(static function (mixed $cb) use (&$hooked): bool {
        $hooked = is_array($cb) && $cb[0] instanceof AlpacaBot\Mcp\ServerSettings ? $cb : $hooked;
        return true;
    }), 10, 2);
    $plugin = Plugin::boot();
    $plugin->register();
    $servers = $plugin->get(AlpacaBot\Mcp\ServerSettings::class);
    expect($hooked)->toBe([$servers, 'beforeSave'])
        ->and((new ReflectionProperty(SettingsPage::class, 'servers'))->getValue($plugin->get(SettingsPage::class)))->toBe($servers);

    Functions\when('register_rest_route')->justReturn(true);
    $settings = null;
    foreach ((new ReflectionMethod(Plugin::class, 'controllers'))->invoke($plugin) as $controller) {
        $settings = $controller instanceof AlpacaBot\Rest\SettingsController ? $controller : $settings;
    }
    expect((new ReflectionProperty(AlpacaBot\Rest\SettingsController::class, 'servers'))->getValue($settings))->toBe($servers);
});

// The provider key is kept out of the autoloaded row (Settings\ProviderKey, Kanboard #4384): lifted
// out of every write of the option, moved out of a 0.6.0 row on init, and, since the row reads
// MASK before and after a change of key, the model list is busted from the key's own option.
it('lifts the provider key out of every write of the option, moves a 0.6.0 key on init, and busts the model catalog when the key option changes', function (): void {
    Functions\when('add_shortcode')->justReturn();
    $lift = null;
    Filters\expectAdded('pre_update_option_' . Plugin::OPTION)->twice()->with(Mockery::on(static function (mixed $cb) use (&$lift): bool {
        $lift = is_array($cb) && $cb[0] instanceof ProviderKey ? $cb : $lift;
        return true;
    }), 10, 2);
    $busts = [];
    foreach (['add_option_', 'update_option_', 'delete_option_'] as $hook) {
        Actions\expectAdded($hook . ProviderKey::OPTION)->once()->with(Mockery::on(static function (mixed $cb) use (&$busts): bool {
            $busts[] = $cb;
            return $cb instanceof Closure;
        }));
    }
    $onInit = null;
    Actions\expectAdded('init')->once()->with(Mockery::on(static function (mixed $cb) use (&$onInit): bool {
        $onInit = $cb instanceof Closure ? $cb : $onInit;
        return $cb instanceof Closure;
    }), 20);
    Actions\expectAdded('init')->times(3)->with(Mockery::type('array'));
    $plugin = Plugin::boot();
    $plugin->register();
    expect($lift)->toBeArray()
        ->and($lift[1])->toBe('beforeSave')
        ->and($busts)->toHaveCount(3);

    Functions\expect('delete_transient')->times(3)->with(ModelCatalog::TRANSIENT)->andReturn(true);
    foreach ($busts as $bust) {
        $bust();
    }

    // Every Migrate04 step flagged done, so the only move left is the key's.
    $stored = [Plugin::OPTION => ['provider.api_key' => 'sk-FAKE-plain']];
    foreach ([Migrate04::FLAG, Migrate04::FLAG_RETENTION, Migrate04::FLAG_CONVERSATIONS, Migrate04::FLAG_AUTOLOAD] as $flag) {
        $stored[$flag] = '1';
    }
    Functions\when('get_option')->alias(static function (string $name, mixed $default = false) use (&$stored): mixed {
        return $stored[$name] ?? $default;
    });
    Functions\when('update_option')->alias(static function (string $name, mixed $value, mixed $autoload = null) use (&$stored): bool {
        $stored[$name] = $value;
        $stored['__autoload__' . $name] = $autoload;
        return true;
    });
    $onInit();
    expect($stored[Plugin::OPTION]['provider.api_key'])->toBe(Schema::MASK)
        ->and($stored[ProviderKey::OPTION])->toBe('sk-FAKE-plain')
        ->and($stored['__autoload__' . ProviderKey::OPTION])->toBeFalse();
});

// The view routes get the MCP approval fragment through a Discovery over the container's one
// ClientFactory, the seam a real client arrives through; and the drift marker
// forgets what a save of the settings answered, whoever makes the save.
it('hands the view routes a Discovery over the container\'s client factory, and hooks the drift marker onto the option\'s updates', function (): void {
    Functions\when('add_shortcode')->justReturn();
    $plugin = Plugin::boot();
    $plugin->register();
    $factory = $plugin->get(AlpacaBot\Mcp\ClientFactory::class);
    expect($factory)->toBeInstanceOf(AlpacaBot\Mcp\ClientFactory::class)
        // has_action() answers the priority it is hooked at.
        ->and(has_action('update_option_' . Plugin::OPTION, [AlpacaBot\Mcp\Drift::class, 'afterSave']))->toBe(10);

    $registered = [];
    Functions\when('register_rest_route')->alias(static function (string $ns, string $path) use (&$registered): void {
        $registered[] = $path;
    });
    $view = null;
    foreach ((new ReflectionMethod(Plugin::class, 'controllers'))->invoke($plugin) as $controller) {
        $view = $controller instanceof ViewController ? $controller : $view;
    }
    $discovery = (new ReflectionProperty(ViewController::class, 'discovery'))->getValue($view);
    expect($discovery)->toBeInstanceOf(AlpacaBot\Mcp\Discovery::class)
        ->and((new ReflectionProperty(AlpacaBot\Mcp\Discovery::class, 'clients'))->getValue($discovery))->toBe($factory)
        ->and((new ReflectionProperty(AlpacaBot\Mcp\Discovery::class, 'store'))->getValue($discovery))->toBe($plugin->get(Store::class));
    $view->register();
    expect($registered)->toContain('/view/mcp-tools/(?P<id>' . AlpacaBot\Settings\Schema::MCP_ID_PATTERN . ')');

    // The registry's MCP servers come through the same factory, over the container's one Store
    // and one Access, so a turn's toolkits and the approval list ask the same seam.
    $mcp = (new ReflectionProperty(Registry::class, 'mcp'))->getValue($plugin->get(Registry::class));
    expect($mcp)->toBeInstanceOf(AlpacaBot\Mcp\Toolkits::class)
        ->and((new ReflectionProperty(AlpacaBot\Mcp\Toolkits::class, 'clients'))->getValue($mcp))->toBe($factory)
        ->and((new ReflectionProperty(AlpacaBot\Mcp\Toolkits::class, 'store'))->getValue($mcp))->toBe($plugin->get(Store::class))
        ->and((new ReflectionProperty(AlpacaBot\Mcp\Toolkits::class, 'access'))->getValue($mcp))->toBe($plugin->get(AlpacaBot\Access::class));
});

// Kanboard #4537: the Chat row's note asks each route that follows the row, and the routes are the
// ones the site registers, so the settings page is handed Plugin::controllers() itself — the
// `alpaca_bot/rest/controllers` filter included — and runs it only when it is called.
it('hands the settings page the controllers the REST API registers, through the same filter, run only when asked', function (): void {
    Functions\when('add_shortcode')->justReturn();
    Functions\when('get_current_user_id')->justReturn(1);
    $plugin = Plugin::boot();
    $plugin->register();
    $controllers = (new ReflectionProperty(SettingsPage::class, 'controllers'))->getValue($plugin->get(SettingsPage::class));

    // Once: register() built the page without listing them, and this call is the one listing.
    Filters\expectApplied('alpaca_bot/rest/controllers')->once()->andReturnFirstArg();
    $listed = array_map(static fn(object $c): string => $c::class, [...$controllers()]);

    expect($listed)->toContain(AlpacaBot\Rest\ChatController::class)->toContain(StreamController::class)->toContain(ViewController::class);
});
