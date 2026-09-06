<?php

declare(strict_types=1);

namespace AlpacaBot;

use AlpacaBot\Settings\Migrate04;
use AlpacaBot\Settings\Store;

final class Plugin
{
    public const VERSION = '1.0.0-dev';
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
        $factory = new Provider\Factory($store);
        $this->set(Provider\Factory::class, $factory);
        $this->set(Provider\ModelCatalog::class, new Provider\ModelCatalog($factory));
        $conversations = new Chat\ConversationStore($store);
        $this->set(Chat\ConversationStore::class, $conversations);
        add_action('init', [$conversations, 'registerPostType']);
        $meter = new Chat\UsageMeter($store);
        $this->set(Chat\UsageMeter::class, $meter);
        add_action('init', [$meter, 'registerPostType']);
        $caps = new Chat\CapPolicy($store, $meter);
        $this->set(Chat\CapPolicy::class, $caps);
        $collector = new Context\Collector([new Context\CurrentScreenSource()]);
        $this->set(Context\Collector::class, $collector);
        $this->set(Chat\Pipeline::class, new Chat\Pipeline($store, $factory, $this->get(Provider\ModelCatalog::class), $conversations, $meter, $caps, $collector));
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
        // WP-CLI is not a dependency: the command is only registered when WP-CLI is the
        // process running us, and the class itself never references WP_CLI until then.
        if (defined('WP_CLI') && constant('WP_CLI')) {
            \WP_CLI::add_command('alpaca-bot', new Cli\ChatCommand($this->get(Chat\Pipeline::class), $this->get(Provider\ModelCatalog::class), $meter, $store));
        }
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
        $controllers = apply_filters('alpaca_bot/rest/controllers', [
            new Rest\ChatController($this->get(Chat\Pipeline::class)),
            new Rest\StreamController($this->get(Chat\Pipeline::class)),
            new Rest\ConversationsController($this->get(Chat\ConversationStore::class), $this->get(Store::class)),
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
