<?php

declare(strict_types=1);

namespace AlpacaBot\Tests\Integration;

/**
 * Base for the integration suite: real WordPress (wp-phpunit) with the plugin loaded, every test
 * inside a transaction core rolls back. The two helpers are the whole REST test vocabulary:
 * become an administrator, dispatch a request at the plugin's namespace.
 *
 * Every test gets its own REST server. WP_UnitTestCase never resets $GLOBALS['wp_rest_server']
 * (only core's WP_Test_REST_Controller_Testcase does), so rest_get_server() would build the server
 * and fire rest_api_init once per process, in whichever test touched it first; the plugin's
 * controllers are collected on that action, so a filter such as `alpaca_bot/rest/controllers`
 * added inside a later test would be silently inert and its routes absent. Dropping the global
 * before and after each test makes the next rest_get_server() rebuild the server and re-fire
 * rest_api_init with the current test's filters in place, so route-time filters work in any test,
 * in any order.
 */
abstract class TestCase extends \WP_UnitTestCase
{
    public function set_up(): void
    {
        parent::set_up();
        $GLOBALS['wp_rest_server'] = null;
    }

    public function tear_down(): void
    {
        $GLOBALS['wp_rest_server'] = null;
        parent::tear_down();
    }

    protected function asAdmin(): int
    {
        $id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($id);
        return $id;
    }

    /**
     * Dispatches through the server core registered the routes on, so the permission callback,
     * capability filter, schema validation and rate limit all run as they would over HTTP. Body
     * params rather than a JSON body: the routes read get_param(), which resolves either, and body
     * params need no Content-Type to be seen. A GET's go on the query string, the only place core
     * reads a GET's parameters from.
     *
     * @param array<string, mixed> $body
     */
    protected function rest(string $method, string $path, array $body = []): \WP_REST_Response
    {
        $request = new \WP_REST_Request($method, '/alpaca-bot/v1' . $path);
        if ($body !== [] && $method === 'GET') {
            $request->set_query_params($body);
        } elseif ($body !== []) {
            $request->set_body_params($body);
        }
        return rest_get_server()->dispatch($request);
    }
}
