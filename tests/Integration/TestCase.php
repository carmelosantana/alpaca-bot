<?php

declare(strict_types=1);

namespace AlpacaBot\Tests\Integration;

use AlpacaBot\Plugin;
use AlpacaBot\Settings\Store;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Config\ModelDefinition;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Contract\ProviderInterface;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Enum\ProviderFinishReason;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Provider\Response;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Provider\Usage;

/**
 * Base for the integration suite: real WordPress (wp-phpunit) with the plugin loaded, every test
 * inside a transaction core rolls back. The three helpers are the whole REST test vocabulary:
 * become an administrator, fake the model provider, dispatch a request at the plugin's namespace.
 *
 * Every test gets its own REST server. WP_UnitTestCase never resets $GLOBALS['wp_rest_server']
 * (only core's WP_Test_REST_Controller_Testcase does), so rest_get_server() would build the server
 * and fire rest_api_init once per process, in whichever test touched it first; the plugin's
 * controllers are collected on that action, so a filter such as `alpaca_bot/rest/controllers`
 * added inside a later test would be silently inert and its routes absent. Dropping the global
 * before and after each test makes the next rest_get_server() rebuild the server and re-fire
 * rest_api_init with the current test's filters in place, so route-time filters work in any test,
 * in any order.
 *
 * Every test also starts from default settings. The plugin's Store is memoised on the Plugin
 * singleton for the whole process, so a setting one test wrote (through PUT /settings, say)
 * would still be in the memo for the next test after core rolled the option row back; writing
 * the defaults through the Store on set_up refreshes the memo and the row together, inside the
 * transaction, so both read as defaults and neither outlives the test.
 */
abstract class TestCase extends \WP_UnitTestCase
{
    public function set_up(): void
    {
        parent::set_up();
        $GLOBALS['wp_rest_server'] = null;
        Plugin::instance()->get(Store::class)->replace([]);
    }

    public function tear_down(): void
    {
        $GLOBALS['wp_rest_server'] = null;
        Plugin::instance()->get(Store::class)->replace([]);
        parent::tear_down();
    }

    /**
     * Swaps the configured provider for one that answers "fake reply" (or throws) and lists a
     * single model, `fake-model`. The site's own `models.default` is left alone: ModelCatalog
     * falls back to the first listed model when the configured one is not in the catalog, and
     * the plugin's Store and catalog are memoised on the Plugin singleton across tests, so a
     * setting written here would outlive this test while an option write would not be seen.
     *
     * Applied at request time (Factory::make() runs inside the route), not on rest_api_init, so
     * it may be added after the server has been built.
     */
    protected function fakeProvider(?\Throwable $failure = null): void
    {
        add_filter('alpaca_bot/provider', static fn(): ProviderInterface => new class ($failure) implements ProviderInterface {
            public function __construct(private ?\Throwable $failure) {}

            public function chat(array $messages, array $tools = [], array $options = []): Response
            {
                return new Response('fake reply', ProviderFinishReason::Stop, usage: new Usage(3, 2, 5));
            }

            public function stream(array $messages, array $tools = [], array $options = []): iterable
            {
                yield new Response('fake ', ProviderFinishReason::Stop);
                if ($this->failure !== null) {
                    throw $this->failure;
                }
                yield new Response('reply', ProviderFinishReason::Stop);
                yield new Response('', ProviderFinishReason::Stop, usage: new Usage(3, 2, 5));
            }

            public function structured(array $messages, string $schema, array $options = []): mixed
            {
                return [];
            }

            public function models(): array
            {
                return [new ModelDefinition('fake-model', 'Fake model', 'fake')];
            }

            public function isAvailable(): bool
            {
                return true;
            }

            public function getModel(): string
            {
                return 'fake-model';
            }

            public function withModel(string $model): static
            {
                return $this;
            }
        });
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
     * params need no Content-Type to be seen. A GET's or HEAD's go on the query string, the only
     * place core reads their parameters from.
     *
     * @param array<string, mixed> $body
     */
    protected function rest(string $method, string $path, array $body = []): \WP_REST_Response
    {
        $request = new \WP_REST_Request($method, '/alpaca-bot/v1' . $path);
        if ($body !== [] && in_array($method, ['GET', 'HEAD'], true)) {
            $request->set_query_params($body);
        } elseif ($body !== []) {
            $request->set_body_params($body);
        }
        return rest_get_server()->dispatch($request);
    }
}
