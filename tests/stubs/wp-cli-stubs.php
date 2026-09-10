<?php

/**
 * PHPStan-only declarations for the WP_CLI methods the plugin calls. WP-CLI is not a composer
 * dependency, and php-stubs/wp-cli-stubs cannot be installed alongside wordpress-stubs ^7.1.
 * Listed under `scanFiles` in phpstan.neon.dist; never loaded by the plugin or the test suite.
 *
 * Signatures follow wp-cli/wp-cli 2.x (php/class-wp-cli.php).
 */

declare(strict_types=1);

class WP_CLI
{
    /**
     * @param callable|object|string $callable
     * @param array<string, mixed> $args
     */
    public static function add_command(string $name, $callable, array $args = []): bool
    {
        return true;
    }

    /** @param string|\WP_Error $message */
    public static function error($message, bool|int $exit = true): void
    {
    }
}
