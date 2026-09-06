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
        $this->set(Chat\CapPolicy::class, new Chat\CapPolicy($store, $meter));
        add_action('admin_init', function () use ($store): void {
            $migration = new Migrate04($store);
            if ($migration->needed()) {
                $migration->run();
            }
        });
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
