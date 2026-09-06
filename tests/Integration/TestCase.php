<?php

declare(strict_types=1);

namespace AlpacaBot\Tests\Integration;

/**
 * Base for the integration suite: real WordPress (wp-phpunit) with the plugin loaded, every test
 * inside a transaction core rolls back. The two helpers are the whole REST test vocabulary:
 * become an administrator, dispatch a request at the plugin's namespace.
 */
abstract class TestCase extends \WP_UnitTestCase
{
    protected function asAdmin(): int
    {
        $id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($id);
        return $id;
    }

    /**
     * Dispatches through the server core registered the routes on, so the permission callback,
     * capability filter and rate limit all run as they would over HTTP. Body params rather than a
     * JSON body: the routes read get_param(), which resolves either, and body params need no
     * Content-Type to be seen.
     *
     * @param array<string, mixed> $body
     */
    protected function rest(string $method, string $path, array $body = []): \WP_REST_Response
    {
        $request = new \WP_REST_Request($method, '/alpaca-bot/v1' . $path);
        if ($body !== []) {
            $request->set_body_params($body);
        }
        return rest_get_server()->dispatch($request);
    }
}
