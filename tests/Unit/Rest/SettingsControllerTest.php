<?php

declare(strict_types=1);

use AlpacaBot\Access;
use AlpacaBot\Mcp;
use AlpacaBot\Rest\SettingsController;
use AlpacaBot\Settings\Schema;
use AlpacaBot\Settings\Store;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

// restRequest() lives in tests/Pest.php. Store is final, so the controller runs over the real
// one with get_option()/update_option() stubbed: what is pinned here is the masking round trip
// (a masked key read back keeps the stored one, an empty one clears it), that a PUT is a partial
// update of top-level keys through Schema::sanitize(), and what the schema route lets out.
// SettingsRoutesTest (integration) runs the same routes over real core dispatch.

beforeEach(function (): void {
    Functions\when('is_user_logged_in')->justReturn(true);
    // An administrator unless a test says otherwise: the settings rows are `manage_options` by
    // default, and `reveal` asks for it a second time, on its own account rather than the gate's.
    Functions\when('current_user_can')->justReturn(true);
    $this->stored = ['provider.api_key' => 'secret', 'models.temperature' => 0.5, 'models.overrides' => ['a' => ['temperature' => 0.1], 'b' => ['num_ctx' => 1024]]];
    Functions\when('get_option')->alias(fn(): array => $this->stored);
    $this->written = null;
    Functions\when('update_option')->alias(function (string $k, array $v): bool {
        $this->written = $v;
        return true;
    });
    $this->controller = new SettingsController(new Store());

    // The permission callbacks as core gets them, by "METHOD /path": what a test about the gate
    // has to drive, since one path carries two verbs with two different rows.
    //
    // useAccess() is not optional here. Plugin::controllers() hands every controller the
    // container's Access, so that is the only shape production ever runs in; a controller built
    // without one resolves its row from Access::defaults() and fires no row filter at all, so a
    // test written that way would assert nothing about the chain and would not notice a short
    // argument list that production fatals on.
    $permissions = [];
    $this->gate = function (string $key) use (&$permissions): Closure {
        $permissions = [];
        Functions\when('register_rest_route')->alias(static function (string $ns, string $path, array $opts) use (&$permissions): void {
            $permissions[$opts['methods'] . ' ' . $path] = $opts['permission_callback'];
        });
        $controller = new SettingsController(new Store());
        $controller->useAccess(new Access(new Store()));
        $controller->register();
        return $permissions[$key];
    };
    $this->asked = [];
    $this->capabilities = function (): void {
        Functions\when('current_user_can')->alias(function (string $cap): bool {
            $this->asked[] = $cap;
            return true;
        });
    };
});

it('declares the read row for both GETs and the write row for the PUT, none rate limited', function (): void {
    $routes = $this->controller->routes();
    expect(array_map(static fn(array $r): array => [$r['path'], $r['methods']], $routes))->toBe([
        ['/settings', 'GET'],
        ['/settings', 'PUT'],
        ['/settings/schema', 'GET'],
    ])
        ->and(array_column($routes, 'capability'))->toBe(['settings.read', 'settings.write', 'settings.read'])
        ->and(array_filter($routes, static fn(array $r): bool => !empty($r['rate_limit'])))->toBe([])
        ->and($routes[0]['args'])->toBe(['reveal' => ['type' => 'boolean', 'default' => false]]);
});

it('asks the settings.read row for GET /settings, through the 0.5 settings key and then settings/read', function (): void {
    $this->stored['access.settings.read'] = 'edit_others_posts';
    $gate = ($this->gate)('GET /settings');
    ($this->capabilities)();
    Filters\expectApplied('alpaca_bot/capability/settings')->once()->with('edit_others_posts', Mockery::type('WP_REST_Request'))->andReturn('publish_posts');
    Filters\expectApplied('alpaca_bot/capability/settings/read')->once()->with('publish_posts', Mockery::type('WP_REST_Request'))->andReturnFirstArg();
    expect($gate(restRequest('GET', '/alpaca-bot/v1/settings')))->toBeTrue()
        ->and($this->asked)->toBe(['publish_posts']);
});

it('asks the same read row for the schema route, and no longer applies 0.5\'s settings/schema key', function (): void {
    $gate = ($this->gate)('GET /settings/schema');
    ($this->capabilities)();
    Filters\expectApplied('alpaca_bot/capability/settings/schema')->never();
    Filters\expectApplied('alpaca_bot/capability/settings')->once()->with('manage_options', Mockery::type('WP_REST_Request'))->andReturnFirstArg();
    Filters\expectApplied('alpaca_bot/capability/settings/read')->once()->with('manage_options', Mockery::type('WP_REST_Request'))->andReturn('edit_others_posts');
    expect($gate(restRequest('GET', '/alpaca-bot/v1/settings/schema')))->toBeTrue()
        ->and($this->asked)->toBe(['edit_others_posts']);
});

it('asks the settings.write row for the PUT, never the read key, so opening the read leaves the write shut', function (): void {
    // provider.base_url is a settable field: a role admitted to the read could otherwise point
    // every turn the site takes at a server of its choosing.
    $this->stored['access.settings.read'] = 'edit_others_posts';
    $gate = ($this->gate)('PUT /settings');
    ($this->capabilities)();
    Filters\expectApplied('alpaca_bot/capability/settings/read')->never();
    Filters\expectApplied('alpaca_bot/capability/settings')->once()->with('manage_options', Mockery::type('WP_REST_Request'))->andReturnFirstArg();
    Filters\expectApplied('alpaca_bot/capability/settings/write')->once()->with('manage_options', Mockery::type('WP_REST_Request'))->andReturnFirstArg();
    expect($gate(restRequest('PUT', '/alpaca-bot/v1/settings')))->toBeTrue()
        ->and($this->asked)->toBe(['manage_options']);
});

it('ignores a settings/write filter that answers anything but a capability name', function (): void {
    $gate = ($this->gate)('PUT /settings');
    ($this->capabilities)();
    Filters\expectApplied('alpaca_bot/capability/settings')->once()->andReturnFirstArg();
    Filters\expectApplied('alpaca_bot/capability/settings/write')->once()->andReturn(true);
    expect($gate(restRequest('PUT', '/alpaca-bot/v1/settings')))->toBeTrue()
        ->and($this->asked)->toBe(['manage_options']);
});

it('hands a declared capability that is not an access row back to the base, rather than resolving it as a row', function (): void {
    // Every route in this controller's table declares a row today, so this is the guard for the
    // next one added. Resolved as a row, a plain capability would fail closed to the unlisted
    // default — authorising the route at `manage_options` instead of the `edit_posts` it declared
    // — and the only filter it would offer a site is one named after the declared capability,
    // since Access::hook() builds the hook from the token it is handed. That is the hook asserted
    // never to fire: it is what the unguarded path would apply, so this line fails if the guard
    // goes away.
    $controller = new SettingsController(new Store());
    $controller->useAccess(new Access(new Store()));
    ($this->capabilities)();
    Filters\expectApplied('alpaca_bot/capability/edit_posts')->never();
    Filters\expectApplied('alpaca_bot/capability/settings/export')->once()->with('edit_posts', Mockery::type('WP_REST_Request'))->andReturnFirstArg();
    $gate = $controller->permission('settings/export', 'edit_posts');
    expect($gate(restRequest('GET', '/alpaca-bot/v1/settings/export')))->toBeTrue()
        ->and($this->asked)->toBe(['edit_posts']);
});

it('never applies alpaca_bot/capability/settings/{route} for these three routes, so the row is the only chain', function (): void {
    // The override answers outright rather than handing its answer back to the base as a
    // *declared* capability: Controller::capability() compares `$declared` against
    // Controller::CHAT, which is the literal 'chat', and `alpaca_bot/capability/settings/read`
    // may return any string a site likes — 'chat' included — so feeding a resolved capability
    // back through that comparison would let a settings filter flip these routes onto the Chat
    // row. It cannot, because the base never sees the resolved value.
    $this->stored['access.settings.read'] = 'edit_others_posts';
    $gate = ($this->gate)('GET /settings');
    ($this->capabilities)();
    Filters\expectApplied('alpaca_bot/capability/settings')->once()->andReturnFirstArg();
    Filters\expectApplied('alpaca_bot/capability/settings/read')->once()->andReturn(AlpacaBot\Rest\Controller::CHAT);
    Filters\expectApplied('alpaca_bot/capability/chat')->never();
    expect($gate(restRequest('GET', '/alpaca-bot/v1/settings')))->toBeTrue()
        // Whatever the filter said is checked as the capability name it is, not read as the sentinel.
        ->and($this->asked)->toBe(['chat']);
});

it('masks a stored api key on read and leaves an empty one empty', function (): void {
    $data = $this->controller->show(restRequest('GET', '/alpaca-bot/v1/settings'))->get_data();
    expect($data['provider.api_key'])->toBe(Schema::MASK)
        ->and($data['models.temperature'])->toBe(0.5)
        ->and(array_keys($data))->toBe(array_keys(Schema::fields()));

    $this->stored['provider.api_key'] = '';
    $data = (new SettingsController(new Store()))->show(restRequest('GET', '/alpaca-bot/v1/settings'))->get_data();
    expect($data['provider.api_key'])->toBe('');
});

it('reveals the raw key on request, and marks that response no-store', function (): void {
    $plain = $this->controller->show(restRequest('GET', '/alpaca-bot/v1/settings'));
    expect($plain->get_headers())->toBe([]);
    $revealed = $this->controller->show(restRequest('GET', '/alpaca-bot/v1/settings', ['reveal' => true]));
    expect($revealed->get_data()['provider.api_key'])->toBe('secret')
        ->and($revealed->get_headers())->toBe(['Cache-Control' => 'no-store']);
});

it('answers the masked settings, not the raw key, to a caller the capability filter admitted without manage_options', function (): void {
    // The `settings.read` row, and the two filters over it, can name any capability, so a site
    // that lowers the read for a custom role would otherwise hand that role the provider
    // credential in cleartext. The route's gate has already passed by the time show() runs;
    // reveal asks manage_options itself so neither the row nor its filters can answer for it.
    Functions\when('current_user_can')->alias(static fn(string $cap): bool => $cap !== 'manage_options');
    $res = $this->controller->show(restRequest('GET', '/alpaca-bot/v1/settings', ['reveal' => true]));
    expect($res->get_data()['provider.api_key'])->toBe(Schema::MASK)
        // Refused the secret, not the route: the rest of the settings are what the gate admitted them to.
        ->and($res->get_data()['models.temperature'])->toBe(0.5)
        ->and(array_keys($res->get_data()))->toBe(array_keys(Schema::fields()))
        // no-store is the revealed response's header; this one was never revealed.
        ->and($res->get_headers())->toBe([]);

    Functions\when('current_user_can')->alias(static fn(string $cap): bool => $cap === 'manage_options');
    $res = $this->controller->show(restRequest('GET', '/alpaca-bot/v1/settings', ['reveal' => true]));
    expect($res->get_data()['provider.api_key'])->toBe('secret')
        ->and($res->get_headers())->toBe(['Cache-Control' => 'no-store']);
});

it('keeps the stored key when a write echoes the mask back, and merges the other keys', function (): void {
    $res = $this->controller->update(restRequest('PUT', '/alpaca-bot/v1/settings', ['provider.api_key' => Schema::MASK, 'models.num_ctx' => 2048]));
    expect($this->written['provider.api_key'])->toBe('secret')
        ->and($this->written['models.num_ctx'])->toBe(2048)
        ->and($this->written['models.temperature'])->toBe(0.5)
        ->and(array_keys($this->written))->toBe(array_keys(Schema::fields()))
        // The reply is the masked read, never the raw key, whatever the body carried.
        ->and($res->get_data()['provider.api_key'])->toBe(Schema::MASK)
        ->and($res->get_data()['models.num_ctx'])->toBe(2048);
});

it('keeps the stored key when the write sends a non-string for it, and still applies the rest', function (): void {
    foreach ([null, [], ['sk-x'], false, 0] as $raw) {
        $this->written = null;
        $res = $this->controller->update(restRequest('PUT', '/alpaca-bot/v1/settings', ['provider.api_key' => $raw, 'models.num_ctx' => 2048]));
        expect($res)->toBeInstanceOf(WP_REST_Response::class)
            ->and($this->written['provider.api_key'])->toBe('secret', var_export($raw, true))
            ->and($this->written['models.num_ctx'])->toBe(2048)
            ->and($res->get_data()['provider.api_key'])->toBe(Schema::MASK);
    }
});

it('clears the key when the write sends an empty string', function (): void {
    $res = $this->controller->update(restRequest('PUT', '/alpaca-bot/v1/settings', ['provider.api_key' => '']));
    expect($this->written['provider.api_key'])->toBe('')
        ->and($res->get_data()['provider.api_key'])->toBe('');
});

it('never reveals in a write reply, even when the body asks', function (): void {
    $res = $this->controller->update(restRequest('PUT', '/alpaca-bot/v1/settings', ['reveal' => true, 'models.num_ctx' => 2048]));
    expect($res->get_data()['provider.api_key'])->toBe(Schema::MASK)
        ->and($res->get_headers())->toBe([]);
});

it('drops unknown keys, clamps ranges and coerces types through the schema', function (): void {
    $res = $this->controller->update(restRequest('PUT', '/alpaca-bot/v1/settings', ['models.temperature' => 5, 'chat.spellcheck' => 'false', 'provider.timeout' => '90', 'bogus' => 1]));
    expect($this->written['models.temperature'])->toBe(2.0)
        ->and($this->written['chat.spellcheck'])->toBeFalse()
        ->and($this->written['provider.timeout'])->toBe(90)
        ->and($this->written)->not->toHaveKey('bogus')
        ->and($res->get_data())->not->toHaveKey('bogus');
});

it('replaces models.overrides wholesale rather than merging models in', function (): void {
    $this->controller->update(restRequest('PUT', '/alpaca-bot/v1/settings', ['models.overrides' => ['c' => ['keep_alive' => '1h']]]));
    expect($this->written['models.overrides'])->toBe(['c' => ['keep_alive' => '1h']]);
});

it('reads the keys from a JSON body under real dispatch semantics', function (): void {
    $request = new WP_REST_Request('PUT', '/alpaca-bot/v1/settings');
    $request->set_header('Content-Type', 'application/json');
    $request->set_body((string) json_encode(['models.num_ctx' => 4096]));
    // What core carries on the query string of a plain-permalink request rides along and is ignored.
    $request->set_param('rest_route', '/alpaca-bot/v1/settings');
    $this->controller->update($request);
    expect($this->written['models.num_ctx'])->toBe(4096);
});

it('refuses a write that names no setting at all with a 400', function (): void {
    Functions\when('__')->returnArg();
    $res = $this->controller->update(restRequest('PUT', '/alpaca-bot/v1/settings', ['rest_route' => '/alpaca-bot/v1/settings', 'models_num_ctx' => 4096]));
    expect($res)->toBeInstanceOf(WP_Error::class)
        ->and($res->get_error_code())->toBe('alpaca_bot_bad_request')
        ->and($res->get_error_data()['status'])->toBe(400)
        ->and($this->written)->toBeNull();
});

it('exposes the schema without the sanitize callables, flagging the secret and naming the mask', function (): void {
    $data = $this->controller->schema(restRequest('GET', '/alpaca-bot/v1/settings/schema'))->get_data();
    expect($data['sections'])->toBe(Schema::sections())
        ->and($data['mask'])->toBe(Schema::MASK)
        ->and(array_keys($data['fields']))->toBe(array_keys(Schema::fields()))
        ->and($data['fields']['provider.api_key']['secret'])->toBeTrue()
        ->and($data['fields']['provider.base_url'])->not->toHaveKey('sanitize')
        ->and($data['fields']['provider.base_url'])->not->toHaveKey('secret')
        ->and($data['fields']['models.temperature'])->toMatchArray(['type' => 'number', 'default' => 0.7, 'min' => 0, 'max' => 2]);
    // Nothing left is an object (a Closure included): the payload is plain data all the way down.
    array_walk_recursive($data, static function (mixed $v): void {
        expect($v)->not->toBeObject();
    });
    expect(json_encode($data))->toBeString();
});

// ---------------------------------------------------------------- MCP servers

/** get_option() as the options table would answer it: the secrets option by name, the settings for everything else. */
function settingsWithSecrets(object $test, array $secrets): void
{
    Functions\when('get_option')->alias(fn(string $name, mixed $default = false): mixed => $name === Mcp\Secrets::OPTION ? $secrets : $test->stored);
}

it('masks every server\'s header value, and reveals it from its own option under reveal', function (): void {
    $this->stored['toolkits.mcp_servers'] = [
        ['id' => 'trk', 'url' => 'https://mcp.example.com/mcp', 'header_name' => 'Authorization', 'header_value' => Schema::MASK, 'prefix' => 'trk', 'approved' => []],
        ['id' => 'gh', 'url' => 'https://gh.example.com/mcp', 'header_name' => '', 'header_value' => '', 'prefix' => 'gh', 'approved' => []],
        // A row that reached the option with the value itself in it is masked all the same.
        ['id' => 'raw', 'url' => 'https://raw.example.com/mcp', 'header_name' => 'X-Key', 'header_value' => 'Bearer in-the-row', 'prefix' => 'raw', 'approved' => []],
    ];
    settingsWithSecrets($this, ['trk' => 'Bearer t']);
    $masked = $this->controller->show(restRequest('GET', '/settings'))->get_data();
    $revealed = $this->controller->show(restRequest('GET', '/settings', ['reveal' => true]))->get_data();
    expect(array_column($masked['toolkits.mcp_servers'], 'header_value'))->toBe([Schema::MASK, '', Schema::MASK])
        ->and(json_encode($masked))->not->toContain('Bearer')
        ->and(array_column($revealed['toolkits.mcp_servers'], 'header_value'))->toBe(['Bearer t', '', 'Bearer in-the-row'])
        ->and(array_keys($revealed['toolkits.mcp_servers'][0]))->toBe(array_keys($this->stored['toolkits.mcp_servers'][0]));
});

// R-reveal: the settings.read row can be lowered for a role that is not an administrator, and
// discovery or a turn sends this value to a remote host on the site's behalf. The floor is the
// provider key's floor, asked of the same check.
it('reveals no header value to a caller the read row admitted without manage_options', function (): void {
    $this->stored['toolkits.mcp_servers'] = [['id' => 'trk', 'url' => 'https://mcp.example.com/mcp', 'header_name' => 'Authorization', 'header_value' => Schema::MASK, 'prefix' => 'trk', 'approved' => []]];
    settingsWithSecrets($this, ['trk' => 'Bearer t']);
    Functions\when('current_user_can')->alias(static fn(string $cap): bool => $cap !== 'manage_options');
    $res = $this->controller->show(restRequest('GET', '/alpaca-bot/v1/settings', ['reveal' => true]));
    expect($res->get_data()['toolkits.mcp_servers'][0]['header_value'])->toBe(Schema::MASK)
        ->and(json_encode($res->get_data()))->not->toContain('Bearer t');
});

// Store's memo holds the row as it was posted until the request ends (the filter that takes the
// value out runs on the option, not on the memo), and the reply is read from the memo.
it('answers a PUT that set a header value with the mask, never the value', function (): void {
    $controller = new SettingsController(new Store(), new Mcp\ServerSettings(static fn(string $host, string $url): array => ['93.184.216.34']));
    $res = $controller->update(restRequest('PUT', '/settings', ['toolkits.mcp_servers' => [['url' => 'https://mcp.example.com/mcp', 'prefix' => 'trk', 'header_value' => 'Bearer new-secret']]]));
    expect($res->get_data()['toolkits.mcp_servers'][0]['header_value'])->toBe(Schema::MASK)
        ->and(json_encode($res->get_data()))->not->toContain('new-secret');
});

it('refuses a PUT whose server address does not pass the check, says why, and writes nothing', function (): void {
    $asked = [];
    $controller = new SettingsController(new Store(), new Mcp\ServerSettings(static function (string $host, string $url) use (&$asked): array {
        $asked[] = $host;
        throw new AlpacaBot\Toolkit\AddressRefused("{$host} resolves to 10.0.0.7, a private, local or other special-purpose address.");
    }));
    $response = $controller->update(restRequest('PUT', '/settings', [
        'models.num_ctx' => 2048,
        'toolkits.mcp_servers' => [['url' => 'https://internal.example.com/mcp', 'prefix' => 'x', 'header_value' => 'Bearer do-not-echo']],
    ]));
    expect($response)->toBeInstanceOf(WP_Error::class)
        ->and($response->get_error_code())->toBe('alpaca_bot_mcp_address')
        ->and($response->get_error_data()['status'])->toBe(400)
        ->and($response->get_error_message())->toContain('internal.example.com resolves to 10.0.0.7')
        ->and($response->get_error_message())->toContain('https://internal.example.com/mcp')
        ->and(json_encode([$response->get_error_message(), $response->get_error_data()]))->not->toContain('do-not-echo')
        ->and($asked)->toBe(['internal.example.com'])
        ->and($this->written)->toBeNull();
});

it('looks up no address for a PUT that leaves the servers out, or sends back a URL already stored', function (): void {
    $this->stored['toolkits.mcp_servers'] = [['id' => 'trk', 'url' => 'https://mcp.example.com/mcp', 'header_name' => '', 'header_value' => '', 'prefix' => 'trk', 'approved' => []]];
    $controller = new SettingsController(new Store(), new Mcp\ServerSettings(static fn(string $host, string $url): array => throw new RuntimeException('no lookup was expected')));
    expect($controller->update(restRequest('PUT', '/settings', ['models.num_ctx' => 2048])))->toBeInstanceOf(WP_REST_Response::class)
        ->and($controller->update(restRequest('PUT', '/settings', ['toolkits.mcp_servers' => [['id' => 'trk', 'url' => 'https://mcp.example.com/mcp', 'prefix' => 'renamed']]])))->toBeInstanceOf(WP_REST_Response::class)
        ->and($this->written['toolkits.mcp_servers'][0]['prefix'])->toBe('renamed');
});

it('flags the header value of an MCP server row as the one secret inside it', function (): void {
    $data = $this->controller->schema(restRequest('GET', '/alpaca-bot/v1/settings/schema'))->get_data();
    expect($data['fields']['toolkits.mcp_servers']['secret_fields'])->toBe(['header_value'])
        ->and($data['fields']['toolkits.mcp_servers'])->not->toHaveKey('secret')
        ->and($data['fields']['provider.api_key'])->not->toHaveKey('secret_fields');
});

// R76 over REST: the PUT succeeds, the reply's row shows '' (nothing is kept), and the
// X-Alpaca-Bot-Mcp-Cleared header names each server whose stored value a posted mask could not
// keep because its URL moved to another origin. '' alone cannot say that: it is also what a server
// that never had a value shows.
it('names in a header each server whose value was dropped because its URL moved to another origin', function (): void {
    $this->stored['toolkits.mcp_servers'] = [
        ['id' => 'trk', 'url' => 'https://mcp.example.com/mcp', 'header_name' => 'Authorization', 'header_value' => Schema::MASK, 'prefix' => 'trk', 'approved' => []],
        ['id' => 'gh', 'url' => 'https://gh.example.com/mcp', 'header_name' => 'Authorization', 'header_value' => Schema::MASK, 'prefix' => 'gh', 'approved' => []],
    ];
    $controller = new SettingsController(new Store(), new Mcp\ServerSettings(static fn(string $host, string $url): array => ['93.184.216.34']));
    $res = $controller->update(restRequest('PUT', '/settings', ['toolkits.mcp_servers' => [
        ['id' => 'trk', 'url' => 'https://steal.example.net/mcp', 'prefix' => 'trk', 'header_value' => Schema::MASK],
        ['id' => 'gh', 'url' => 'https://gh.example.com/v2', 'prefix' => 'gh', 'header_value' => Schema::MASK],
    ]]));
    expect($res->get_status())->toBe(200)
        ->and($res->get_headers())->toBe(['X-Alpaca-Bot-Mcp-Cleared' => 'trk']);
    $plain = $controller->update(restRequest('PUT', '/settings', ['models.num_ctx' => 2048]));
    expect($plain->get_headers())->toBe([]);
});

// m-7: the ids the route checks are the ids Store will keep, so "unchanged" means the same server.
it('checks each row under the id the write will give it', function (): void {
    $this->stored['toolkits.mcp_servers'] = [['id' => 'trk', 'url' => 'https://mcp.example.com/mcp', 'header_name' => '', 'header_value' => '', 'prefix' => 'trk', 'approved' => []]];
    $asked = [];
    $controller = new SettingsController(new Store(), new Mcp\ServerSettings(static function (string $host, string $url) use (&$asked): array {
        $asked[] = $host;
        return ['93.184.216.34'];
    }));
    // No id, same URL and prefix: the stored server, so its unchanged URL is not looked up.
    $controller->update(restRequest('PUT', '/settings', ['toolkits.mcp_servers' => [['url' => 'https://mcp.example.com/mcp', 'prefix' => 'trk']]]));
    expect($asked)->toBe([])->and($this->written['toolkits.mcp_servers'][0]['id'])->toBe('trk');
});
