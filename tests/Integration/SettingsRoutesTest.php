<?php

declare(strict_types=1);

namespace AlpacaBot\Tests\Integration;

use AlpacaBot\Chat\UsageMeter;
use AlpacaBot\Plugin;
use AlpacaBot\Provider\ModelCatalog;
use AlpacaBot\Settings\Schema;

/**
 * The /settings, /settings/schema, /usage and /models routes over real core: real permission
 * callbacks, the real `alpaca_bot_settings` option, real chat_log rows, core's own schema
 * validation of the query switches. Only the model provider is faked (TestCase::fakeProvider()).
 *
 * @group rest
 */
final class SettingsRoutesTest extends TestCase
{
    public function test_settings_need_manage_options(): void
    {
        $this->assertSame(401, $this->rest('GET', '/settings')->get_status());
        $editor = self::factory()->user->create(['role' => 'editor']);
        wp_set_current_user($editor);
        foreach ([['GET', '/settings'], ['PUT', '/settings'], ['GET', '/settings/schema']] as [$method, $path]) {
            $res = $this->rest($method, $path, ['models.temperature' => 1.5]);
            $this->assertSame(403, $res->get_status(), "$method $path");
            $this->assertSame('rest_forbidden', $res->get_data()['code']);
        }
        $this->assertSame(Schema::defaults()['models.temperature'], get_option('alpaca_bot_settings')['models.temperature']);

        $this->asAdmin();
        $this->assertSame(200, $this->rest('GET', '/settings')->get_status());
        $res = $this->rest('PUT', '/settings', ['models.temperature' => 1.5]);
        $this->assertSame(200, $res->get_status(), print_r($res->get_data(), true));
        $this->assertSame(1.5, $res->get_data()['models.temperature']);
        $this->assertSame(1.5, get_option('alpaca_bot_settings')['models.temperature']);
        // A PUT is partial: everything else is the default it was, and the reply is the whole array.
        $this->assertSame(Schema::defaults()['models.num_ctx'], $res->get_data()['models.num_ctx']);
        $this->assertSame(array_keys(Schema::fields()), array_keys($res->get_data()));
        $this->assertSame(array_keys(Schema::fields()), array_keys(get_option('alpaca_bot_settings')));
    }

    /**
     * The catalog bust on a site's *first* settings save. update_option() creates the row rather
     * than updating it when the stored value is still the registered default, so that save fires
     * `add_option_alpaca_bot_settings` and never `update_option_alpaca_bot_settings` (core
     * option.php:928-930). Hooked only on the update, an operator who chose their provider in
     * that first save kept the previous provider's model list for the transient's five minutes,
     * and every turn in the window would be refused by name on `wp-ai`.
     *
     * The option row is deleted first because TestCase::set_up() writes one; that is the state a
     * fresh install is in, and the only state in which this can happen.
     */
    public function test_the_first_settings_save_a_site_makes_busts_the_model_catalog(): void
    {
        $this->asAdmin();
        delete_option(Plugin::OPTION);
        set_transient(ModelCatalog::TRANSIENT, [['id' => 'previous-providers-model']], 300);
        $this->assertFalse(get_option(Plugin::OPTION));

        // The row is created, by the branch that fires add_option_{$option}, not update_option_{$option}.
        $this->assertTrue(update_option(Plugin::OPTION, ['provider.kind' => 'wp-ai'] + Schema::defaults()));

        $this->assertFalse(get_transient(ModelCatalog::TRANSIENT));
    }

    public function test_settings_accept_a_json_body_and_apply_the_schema(): void
    {
        $this->asAdmin();
        $request = new \WP_REST_Request('PUT', '/alpaca-bot/v1/settings');
        $request->set_header('Content-Type', 'application/json');
        $request->set_body((string) wp_json_encode(['models.temperature' => 9, 'chat.spellcheck' => false, 'bogus' => 1, 'models.overrides' => ['a' => ['temperature' => 0.1, 'nope' => 1]]]));
        $res = rest_get_server()->dispatch($request);
        $this->assertSame(200, $res->get_status(), print_r($res->get_data(), true));
        $stored = get_option('alpaca_bot_settings');
        // Out of range is clamped by Schema::sanitize(), not refused.
        $this->assertSame(2.0, $stored['models.temperature']);
        $this->assertFalse($stored['chat.spellcheck']);
        $this->assertArrayNotHasKey('bogus', $stored);
        $this->assertSame(['a' => ['temperature' => 0.1]], $stored['models.overrides']);

        // A later PUT of the map replaces it; a model left out is gone, not kept.
        $this->rest('PUT', '/settings', ['models.overrides' => ['b' => ['num_ctx' => 1024]]]);
        $this->assertSame(['b' => ['num_ctx' => 1024]], get_option('alpaca_bot_settings')['models.overrides']);

        // Nothing recognised (here: what a form or query string makes of dotted keys) is a 400.
        $res = $this->rest('PUT', '/settings', ['models_temperature' => 1]);
        $this->assertSame(400, $res->get_status());
        $this->assertSame('alpaca_bot_bad_request', $res->get_data()['code']);
        $this->assertSame(2.0, get_option('alpaca_bot_settings')['models.temperature']);
    }

    public function test_the_api_key_is_masked_on_read_kept_on_a_masked_write_and_cleared_by_an_empty_one(): void
    {
        $this->asAdmin();
        $res = $this->rest('PUT', '/settings', ['provider.api_key' => 'sk-live-1234']);
        $this->assertSame(Schema::MASK, $res->get_data()['provider.api_key']);
        $this->assertSame('sk-live-1234', get_option('alpaca_bot_settings')['provider.api_key']);
        $this->assertSame(Schema::MASK, $this->rest('GET', '/settings')->get_data()['provider.api_key']);
        $this->assertStringNotContainsString('sk-live', (string) wp_json_encode($this->rest('GET', '/settings')->get_data()));

        $revealed = $this->rest('GET', '/settings', ['reveal' => '1']);
        $this->assertSame('sk-live-1234', $revealed->get_data()['provider.api_key']);
        $this->assertSame('no-store', $revealed->get_headers()['Cache-Control']);

        $this->rest('PUT', '/settings', ['provider.api_key' => Schema::MASK, 'models.num_ctx' => 2048]);
        $this->assertSame('sk-live-1234', get_option('alpaca_bot_settings')['provider.api_key']);
        $this->assertSame(2048, get_option('alpaca_bot_settings')['models.num_ctx']);

        // Not a string at all (a typed client's null, an untouched form control serialised as
        // null, a stray array) is neither "clear" nor a new key: the stored one stays.
        $request = new \WP_REST_Request('PUT', '/alpaca-bot/v1/settings');
        $request->set_header('Content-Type', 'application/json');
        $request->set_body('{"provider.api_key": null, "models.num_ctx": 4096}');
        $res = rest_get_server()->dispatch($request);
        $this->assertSame(200, $res->get_status(), print_r($res->get_data(), true));
        $this->assertSame(Schema::MASK, $res->get_data()['provider.api_key']);
        $this->assertSame('sk-live-1234', get_option('alpaca_bot_settings')['provider.api_key']);
        $this->assertSame(4096, get_option('alpaca_bot_settings')['models.num_ctx']);
        $this->rest('PUT', '/settings', ['provider.api_key' => ['sk-live-9999']]);
        $this->assertSame('sk-live-1234', get_option('alpaca_bot_settings')['provider.api_key']);

        $res = $this->rest('PUT', '/settings', ['provider.api_key' => '']);
        $this->assertSame('', $res->get_data()['provider.api_key']);
        $this->assertSame('', get_option('alpaca_bot_settings')['provider.api_key']);
        $this->assertSame('', $this->rest('GET', '/settings')->get_data()['provider.api_key']);
    }

    public function test_the_schema_route_is_the_field_list_without_the_callables(): void
    {
        $this->asAdmin();
        $res = $this->rest('GET', '/settings/schema');
        $this->assertSame(200, $res->get_status());
        $data = $res->get_data();
        $this->assertSame(Schema::sections(), $data['sections']);
        $this->assertSame(Schema::MASK, $data['mask']);
        $this->assertSame(array_keys(Schema::fields()), array_keys($data['fields']));
        $this->assertTrue($data['fields']['provider.api_key']['secret']);
        foreach ($data['fields'] as $key => $field) {
            $this->assertArrayNotHasKey('sanitize', $field, $key);
        }
        // What core would send: encodable, and no trace of the sanitize callables' names in it.
        $json = wp_json_encode($data);
        $this->assertIsString($json);
        $this->assertStringNotContainsString('sanitize', $json);
    }

    public function test_usage_route_reports_the_month(): void
    {
        $user = $this->asAdmin();
        $data = $this->rest('GET', '/usage')->get_data();
        $this->assertSame(['tokens' => 0, 'requests' => 0, 'month' => gmdate('Y-m'), 'caps' => ['user' => 0, 'site' => 0]], $data);

        $meter = Plugin::instance()->get(UsageMeter::class);
        $this->assertGreaterThan(0, $meter->record($user, 'm', 10, 20, 100));
        $other = self::factory()->user->create(['role' => 'editor']);
        $this->assertGreaterThan(0, $meter->record($other, 'm', 1, 2, 100));
        $this->rest('PUT', '/settings', ['governance.user_monthly_tokens' => 5000]);

        $mine = $this->rest('GET', '/usage', ['user' => 'me'])->get_data();
        $this->assertSame(['tokens' => 30, 'requests' => 1, 'month' => gmdate('Y-m'), 'caps' => ['user' => 5000, 'site' => 0]], $mine);
        $all = $this->rest('GET', '/usage', ['user' => 'all'])->get_data();
        $this->assertSame(['tokens' => 33, 'requests' => 2, 'month' => gmdate('Y-m'), 'caps' => ['user' => 5000, 'site' => 0]], $all);
        $this->rest('PUT', '/settings', ['governance.site_monthly_tokens' => 90000]);
        $this->assertSame(90000, $this->rest('GET', '/usage')->get_data()['caps']['site']);

        // Anything but me|all is core's schema refusal.
        $refused = $this->rest('GET', '/usage', ['user' => '7']);
        $this->assertSame(400, $refused->get_status());
        $this->assertSame('rest_invalid_param', $refused->get_data()['code']);

        // An editor sees their own figures and the per-user cap only: the site-wide cap is the
        // operator's number, as the site-wide total is.
        wp_set_current_user($other);
        $theirs = $this->rest('GET', '/usage')->get_data();
        $this->assertSame(['tokens' => 3, 'requests' => 1, 'month' => gmdate('Y-m'), 'caps' => ['user' => 5000]], $theirs);
        $forbidden = $this->rest('GET', '/usage', ['user' => 'all']);
        $this->assertSame(403, $forbidden->get_status());
        $this->assertSame('rest_forbidden', $forbidden->get_data()['code']);
    }

    public function test_models_route_lists_the_catalog_with_the_default_in_a_header(): void
    {
        $this->assertSame(401, $this->rest('GET', '/models')->get_status());
        $this->fakeProvider();
        wp_set_current_user(self::factory()->user->create(['role' => 'editor']));
        // refresh=1 first: the catalog memoises what it discovered for the process, and an earlier
        // test may have had it discover something else.
        $res = $this->rest('GET', '/models', ['refresh' => '1']);
        $this->assertSame(200, $res->get_status(), print_r($res->get_data(), true));
        $this->assertSame([['id' => 'fake-model', 'label' => 'fake-model', 'tools' => true, 'vision' => false, 'thinking' => false]], $res->get_data());
        $this->assertSame('fake-model', $res->get_headers()['X-Alpaca-Bot-Default-Model']);
        $this->assertSame($res->get_data(), $this->rest('GET', '/models')->get_data());
        $this->assertSame(400, $this->rest('GET', '/models', ['refresh' => 'maybe'])->get_status());
    }

    public function test_models_route_shares_the_chat_rate_limit(): void
    {
        $this->fakeProvider();
        wp_set_current_user(self::factory()->user->create(['role' => 'editor']));
        add_filter('alpaca_bot/rate_limit', static fn(): int => 1);
        $this->assertSame(200, $this->rest('GET', '/models')->get_status());
        $blocked = $this->rest('GET', '/models', ['refresh' => '1']);
        $this->assertSame(429, $blocked->get_status());
        $this->assertSame('alpaca_bot_rate_limited', $blocked->get_data()['code']);
        $this->assertArrayHasKey('Retry-After', $blocked->get_headers());
    }
}
