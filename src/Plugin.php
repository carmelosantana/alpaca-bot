<?php

declare(strict_types=1);

namespace AlpacaBot;

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
        // Services are attached here by later tasks, e.g. $this->set(Settings\Store::class, new Settings\Store());
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
