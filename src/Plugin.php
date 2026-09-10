<?php

declare(strict_types=1);

namespace AlpacaBot;

use AlpacaBot\Settings\Migrate04;
use AlpacaBot\Settings\Store;

final class Plugin
{
    public const VERSION = '0.5.0-dev';
    public const OPTION = 'alpaca_bot_settings';
    public const TEXT_DOMAIN = 'alpaca-bot';

    private static ?self $instance = null;

    /** @var array<string, object> */
    private array $services = [];

    private function __construct() {}

    public static function boot(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
            add_action('plugins_loaded', [self::$instance, 'register'], 9);
        }
        return self::$instance;
    }

    public static function instance(): self
    {
        return self::$instance ?? self::boot();
    }

    public function version(): string
    {
        return self::VERSION;
    }

    public function register(): void
    {
        $store = new Store();
        $this->set(Store::class, $store);
        $factory = new Provider\Factory($store, new Provider\WpAi\CoreClient());
        $this->set(Provider\Factory::class, $factory);
        $this->set(Provider\ModelCatalog::class, new Provider\ModelCatalog($factory));
        // The provider picker's two loose ends. The notice: `provider.kind` may say `wp-ai` on a
        // WordPress without the AI client (the setting outlived a downgrade, or was written
        // over REST), and the factory then builds Ollama; it is the factory's verdict
        // (fallbackNotice()), printed here because the factory also runs under REST and WP-CLI,
        // and only to someone who can change the setting. The catalog bust: the model list is
        // one site-wide transient for five minutes, and a save that changes the provider (its
        // kind, where it is, or the key that reaches it) would otherwise leave the previous
        // provider's models on offer, and a turn routed at one of them, until it expired.
        // There is no per-kind default model (`models.default` is the one default for both
        // kinds), and that is safe only because of this bust: Chat\Pipeline resolves the model
        // as UserPrefs::modelFor() ?? ModelCatalog::defaultId(), and both validate the stored
        // id against the catalogue's list, so after a switch a stale id is replaced by a model
        // the new provider lists. Against the previous provider's cached list they would pass a
        // model the new provider does not have, and on `wp-ai` CoreClient refuses it by name
        // on every turn for the transient's lifetime. Removing this bust needs a per-kind
        // default (or an eager re-list) to go in with it.
        // On `update_option_*` rather than in Store: every writer (the settings page, the REST
        // route, WP-CLI, a filter) goes through the option, and only one of them through Store.
        // Neither closure is `static`, for the reason the receipt-retention hook below gives.
        add_action('admin_notices', function () use ($factory): void {
            $notice = $factory->fallbackNotice();
            if ($notice === null || !current_user_can('manage_options')) {
                return;
            }
            printf('<div class="notice notice-warning"><p>%s</p></div>', esc_html($notice));
        });
        add_action('update_option_' . self::OPTION, function (mixed $old, mixed $new): void {
            if (is_array($old) && is_array($new)) {
                $changed = false;
                foreach (['provider.kind', 'provider.base_url', 'provider.api_key'] as $key) {
                    $changed = $changed || ($old[$key] ?? null) !== ($new[$key] ?? null);
                }
                if (!$changed) {
                    return;
                }
            }
            delete_transient(Provider\ModelCatalog::TRANSIENT);
        }, 10, 2);
        // And on the add, which is what a site's *first* save fires. update_option() creates the
        // row rather than updating it when the current value is the registered default — with no
        // row, get_option() returns that default, the `default_option_alpaca_bot_settings ===
        // $old_value` branch is taken, and add_option() runs instead (core option.php:927-929,
        // firing `add_option_{$option}` at :1176). So the settings screen's first Save fired
        // neither this hook nor the one above, and an operator who picked their provider in that
        // save kept the previous one's models for the transient's five minutes — which, by the
        // argument above, means every turn in that window refused by name on `wp-ai`.
        // Unconditional: an add has no old value to compare the three keys against, and the one
        // deleted transient it can cost is on the first settings save a site ever makes.
        add_action('add_option_' . self::OPTION, function (): void {
            delete_transient(Provider\ModelCatalog::TRANSIENT);
        });
        $conversations = new Chat\ConversationStore($store);
        $this->set(Chat\ConversationStore::class, $conversations);
        add_action('init', [$conversations, 'registerPostType']);
        $meter = new Chat\UsageMeter($store);
        $this->set(Chat\UsageMeter::class, $meter);
        add_action('init', [$meter, 'registerPostType']);
        // The receipt retention cron: (re)scheduled on init so an already-installed site gets it
        // without a reactivation (UsageMeter::scheduleCleanup() says why), cleared by deactivate().
        add_action('init', [$meter, 'scheduleCleanup']);
        // Not `static`: Brain Monkey (PHP 8.4) warns when it inspects a static closure hooked
        // through add_action(), as the migration closure below found first.
        add_action(Chat\UsageMeter::CLEANUP_HOOK, function () use ($meter): void {
            $meter->cleanup();
        });
        $caps = new Chat\CapPolicy($store, $meter);
        $this->set(Chat\CapPolicy::class, $caps);
        $collector = new Context\Collector([new Context\CurrentScreenSource()]);
        $this->set(Context\Collector::class, $collector);
        $prefs = new Chat\UserPrefs();
        $this->set(Chat\UserPrefs::class, $prefs);
        // The registry is built empty before the pipeline and filled after it: the pipeline asks
        // the registry what a turn may run, and the summarize toolkit runs its inner turn through
        // the pipeline, so one of the two has to exist before the other is complete. Nothing is
        // read from the registry until a turn runs, well after plugins_loaded.
        $registry = new Toolkit\Registry($store);
        $this->set(Chat\Pipeline::class, new Chat\Pipeline($store, $factory, $this->get(Provider\ModelCatalog::class), $conversations, $meter, $caps, $collector, $prefs, $registry));
        // The built-in toolkits, under the ids Schema's `toolkits.enabled` options name. The two
        // that act as a user take get_current_user_id as a closure and ask it when a tool runs,
        // never here. Not because the id is unreadable here -- this runs on plugins_loaded:9,
        // and core loaded pluggable.php at wp-settings.php:560 and registered all three
        // `determine_current_user` filters in default-filters.php:505-507, both before
        // `do_action('plugins_loaded')` at :578, so a cookie request would resolve. It is that
        // an id read here is the id for the whole request, and the acting user moves after this
        // point: WP-CLI leaves it at 0 until `wp alpaca-bot chat` calls wp_set_current_user()
        // (Cli\ChatCommand says why it does), and any wp_set_current_user() elsewhere moves it
        // again. A boot-time read would freeze the wrong one, and would also resolve and cache
        // $current_user from inside a plugin's boot, ahead of anything that wanted to hook
        // `determine_current_user` on plugins_loaded itself. The registry decides what is enabled
        // when a turn asks (Toolkit\Registry), so nothing about the setting is read here either.
        $webFetch = new Toolkit\WebFetchToolkit($store);
        $registry->register('web_fetch', $webFetch);
        $summarize = new Toolkit\SummarizeToolkit($this->get(Chat\Pipeline::class), get_current_user_id(...));
        $registry->register('summarize', $summarize);
        $draftPost = new Toolkit\DraftPostToolkit(get_current_user_id(...));
        $registry->register('draft_post', $draftPost);
        $this->set(Toolkit\Registry::class, $registry);
        // The abilities, on core's two hooks and no other (Abilities\Register says why there is
        // no init fallback), the category's hook first because core fires it first and refuses
        // an ability whose category it does not know. The toolkits come from the registry when
        // a callback runs, and the same user closure, so an ability and a chat turn act as one
        // user over the same toolkits.
        $abilities = new Abilities\Register($this->get(Chat\Pipeline::class), $registry, get_current_user_id(...));
        $this->set(Abilities\Register::class, $abilities);
        add_action('wp_abilities_api_categories_init', [$abilities, 'registerCategory']);
        add_action('wp_abilities_api_init', [$abilities, 'register']);
        // On init, after the post types (priority 10), not on admin_init: WP-CLI loads WordPress
        // and fires init but never admin_init, and in P1 the CLI is the whole user surface, so an
        // upgraded 0.4 site's first `wp alpaca-bot chat` must already see its configured
        // settings. Store::all() runs Schema::defaults() through __(), which is safe during init
        // (the just-in-time textdomain loader only objects before it).
        add_action('init', function () use ($store): void {
            $migration = new Migrate04($store);
            if ($migration->needed()) {
                $migration->run();
            }
        }, 20);
        add_action('rest_api_init', function (): void {
            foreach ($this->controllers() as $controller) {
                $controller->register();
            }
        });
        // The admin screens hook admin_init and admin_menu without an is_admin() gate: neither
        // action fires outside wp-admin, so the gate would only decide whether two small objects
        // are built, and it would decide wrong where is_admin() is false at plugins_loaded but a
        // test (wp-phpunit sets no screen until a test does) later fires the actions itself.
        // options.php, which every save posts to, is wp-admin and fires admin_init as any screen.
        $settingsPage = new Admin\SettingsPage($store, $this->get(Provider\ModelCatalog::class));
        $this->set(Admin\SettingsPage::class, $settingsPage);
        add_action('admin_init', [$settingsPage, 'register']);
        $chatScreen = new Admin\ChatScreen($store, $this->get(Provider\ModelCatalog::class), $conversations, $prefs);
        $this->set(Admin\ChatScreen::class, $chatScreen);
        $menu = new Admin\Menu($settingsPage, [$chatScreen, 'render']);
        add_action('admin_menu', [$menu, 'register']);
        // Assets::enqueue() gates on the hook suffix itself, so this listens on every admin
        // screen and enqueues on one. The heartbeat answer runs on admin-ajax, where no
        // enqueue hook fires, so it is hooked here.
        $assets = new Admin\Assets();
        add_action('admin_enqueue_scripts', [$assets, 'enqueue']);
        add_filter('heartbeat_received', [$assets, 'heartbeat'], 10, 2);
        // The help tabs of the chat screen and the settings page; HelpTabs gates on the screen id.
        add_action('current_screen', [new Admin\HelpTabs(), 'add']);
        // The two shortcodes, registered now rather than on init: `$shortcode_tags` exists from
        // shortcodes.php's load, and a shortcode registered on plugins_loaded is there for
        // whatever renders content first. The shim runs the same web_fetch instance the
        // registry holds, so the two fetch under one guard and one user agent.
        $shortcode = new Shortcodes\Chat($store, $this->get(Provider\ModelCatalog::class), $conversations, $prefs, $this->get(Chat\Pipeline::class), new View\Markdown(), $assets);
        $this->set(Shortcodes\Chat::class, $shortcode);
        $shortcode->register();
        $shim = new Shortcodes\AgentShim($shortcode, $this->get(Chat\Pipeline::class), $registry);
        $this->set(Shortcodes\AgentShim::class, $shim);
        $shim->register();
        // WP-CLI is not a dependency: the command is only registered when WP-CLI is the
        // process running us, and the class itself never references WP_CLI until then.
        if (defined('WP_CLI') && constant('WP_CLI')) {
            \WP_CLI::add_command('alpaca-bot', new Cli\ChatCommand($this->get(Chat\Pipeline::class), $this->get(Provider\ModelCatalog::class), $meter, $store));
        }
    }

    /**
     * The deactivation hook (alpaca-bot.php registers it): the one thing the plugin leaves in
     * the database that would keep running without it is the retention cron event. Options,
     * conversations and receipts stay; deactivation is not uninstall.
     */
    public static function deactivate(): void
    {
        Chat\UsageMeter::unscheduleCleanup();
    }

    /**
     * The REST controllers to register, through filter `alpaca_bot/rest/controllers`. The plugin's
     * own controllers are the array handed to the filter (later tasks add theirs here); a third
     * party appends its own Rest\Controller subclass to get the same namespace, capability
     * filters and rate limit, or drops one of ours to unregister its routes. Resolved on
     * rest_api_init, not at register(), so a filter added on plugins_loaded or init is seen, and
     * the controllers are built then too: they hold the container's services, which all exist by
     * plugins_loaded, but building them only for a REST request keeps every other request free
     * of them. Anything that is not a Controller is dropped rather than left to fatal inside
     * register().
     *
     * @return list<Rest\Controller>
     */
    private function controllers(): array
    {
        /**
         * Filters the REST controllers registered under `alpaca-bot/v1`, on `rest_api_init`. Append
         * a Rest\Controller subclass to get the namespace, the `alpaca_bot/capability/{route}`
         * permission filters and the rate limit without writing them, or drop one of the plugin's
         * to unregister its routes (each controller's docblock says what a site loses with it).
         * Anything that is not a Controller is dropped rather than left to fatal inside register().
         *
         * @since 0.5.0
         * @param list<Rest\Controller> $controllers the plugin's controllers
         * @var mixed $controllers what the filter returned, checked before it is trusted
         */
        $controllers = apply_filters('alpaca_bot/rest/controllers', [
            new Rest\ChatController($this->get(Chat\Pipeline::class)),
            new Rest\StreamController($this->get(Chat\Pipeline::class), $this->get(Store::class)),
            new Rest\ConversationsController($this->get(Chat\ConversationStore::class), $this->get(Store::class)),
            new Rest\ModelsController($this->get(Provider\ModelCatalog::class), $this->get(Store::class)),
            new Rest\SettingsController($this->get(Store::class)),
            new Rest\UsageController($this->get(Chat\UsageMeter::class), $this->get(Store::class)),
            new Rest\ViewController($this->get(Chat\ConversationStore::class), $this->get(Store::class), $this->get(Provider\ModelCatalog::class), new View\Markdown(), $this->get(Chat\UserPrefs::class)),
        ]);
        return array_values(array_filter(
            is_array($controllers) ? $controllers : [],
            static fn(mixed $controller): bool => $controller instanceof Rest\Controller,
        ));
    }

    public function set(string $id, object $service): void
    {
        $this->services[$id] = $service;
    }

    /**
     * @template T of object
     * @param class-string<T> $id
     * @return T
     */
    public function get(string $id): object
    {
        if (!isset($this->services[$id])) {
            throw new \RuntimeException("Alpaca Bot service not registered: {$id}");
        }
        /** @var T */
        return $this->services[$id];
    }
}
