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

// update_option() is a stand-in here, so no filter takes the value out of the row and Store's memo
// still holds it; the reply is the mask because the route masks what it reads back (masked()).
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
    // `none` holds no value, so moving it drops nothing and it is not named (N-7).
    $this->stored['toolkits.mcp_servers'][] = ['id' => 'none', 'url' => 'https://none.example.com/mcp', 'header_name' => '', 'header_value' => '', 'prefix' => 'none', 'approved' => []];
    settingsWithSecrets($this, ['trk' => 'Bearer t', 'gh' => 'Bearer g']);
    $controller = new SettingsController(new Store(), new Mcp\ServerSettings(static fn(string $host, string $url): array => ['93.184.216.34']));
    $res = $controller->update(restRequest('PUT', '/settings', ['toolkits.mcp_servers' => [
        ['id' => 'trk', 'url' => 'https://steal.example.net/mcp', 'prefix' => 'trk', 'header_value' => Schema::MASK],
        ['id' => 'gh', 'url' => 'https://gh.example.com/v2', 'prefix' => 'gh', 'header_value' => Schema::MASK],
        ['id' => 'none', 'url' => 'https://elsewhere.example.net/mcp', 'prefix' => 'none', 'header_value' => Schema::MASK],
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
    $servers = new Mcp\ServerSettings(static function (string $host, string $url) use (&$asked): array {
        $asked[] = $host;
        return ['93.184.216.34'];
    });
    // No id, same URL and prefix: the stored server, so its unchanged URL is not looked up.
    (new SettingsController(new Store(), $servers))->update(restRequest('PUT', '/settings', ['toolkits.mcp_servers' => [['url' => 'https://mcp.example.com/mcp', 'prefix' => 'trk']]]));
    expect($asked)->toBe([])->and($this->written['toolkits.mcp_servers'][0]['id'])->toBe('trk');

    // No id, the stored URL under another prefix: a new server, which Store will write as trk_2
    // since trk is taken, so its URL is looked up. Checked as `trk` it would read as unchanged.
    $this->stored['toolkits.mcp_servers'][0]['prefix'] = 'old';
    (new SettingsController(new Store(), $servers))->update(restRequest('PUT', '/settings', ['toolkits.mcp_servers' => [['url' => 'https://mcp.example.com/mcp', 'prefix' => 'trk']]]));
    expect($asked)->toBe(['mcp.example.com'])->and($this->written['toolkits.mcp_servers'][0]['id'])->toBe('trk_2');
});

// R78: a PUT that would drop a row naming a stored server, or a new row with a URL, is refused
// whole, as a refused address is: the row is named, and nothing is written. Leaving a server out,
// or sending `remove`, is how a client deletes one.
it('refuses a PUT that would drop a row, names the row and why, and writes nothing', function (array $rows, int $index, ?string $id, string $url, string $reason): void {
    $this->stored['toolkits.mcp_servers'] = [
        ['id' => 'aa', 'url' => 'https://aa.example.com/mcp', 'header_name' => 'Authorization', 'header_value' => Schema::MASK, 'prefix' => 'aa', 'approved' => []],
        ['id' => 'bb', 'url' => 'https://bb.example.com/mcp', 'header_name' => 'Authorization', 'header_value' => Schema::MASK, 'prefix' => 'bb', 'approved' => []],
    ];
    $controller = new SettingsController(new Store(), new Mcp\ServerSettings(static fn(string $host, string $url): array => throw new RuntimeException('no lookup was expected')));
    $response = $controller->update(restRequest('PUT', '/settings', ['models.num_ctx' => 2048, 'toolkits.mcp_servers' => $rows]));
    expect($response)->toBeInstanceOf(WP_Error::class)
        ->and($response->get_error_code())->toBe('alpaca_bot_mcp_row')
        ->and($response->get_error_data()['status'])->toBe(400)
        ->and($response->get_error_data()['rows'])->toHaveCount(1)
        ->and($response->get_error_data()['rows'][0])->toMatchArray(['index' => $index, 'id' => $id, 'url' => $url])
        ->and($response->get_error_data()['rows'][0]['reason'])->toContain($reason)
        ->and($response->get_error_message())->toContain($url)
        ->and(json_encode([$response->get_error_message(), $response->get_error_data()]))->not->toContain('Bearer typed')
        ->and($this->written)->toBeNull();
})->with([
    'a stored server takes a prefix another row holds' => [[['id' => 'aa', 'url' => 'https://aa.example.com/mcp', 'prefix' => 'bb', 'header_value' => 'Bearer typed'], ['id' => 'bb', 'url' => 'https://bb.example.com/mcp', 'prefix' => 'bb']], 1, 'bb', 'https://bb.example.com/mcp', 'prefix bb'],
    'a stored server given an http URL' => [[['id' => 'aa', 'url' => 'http://aa.example.com/mcp', 'prefix' => 'aa', 'header_value' => 'Bearer typed'], ['id' => 'bb', 'url' => 'https://bb.example.com/mcp', 'prefix' => 'bb']], 0, 'aa', 'http://aa.example.com/mcp', 'https'],
    'a stored server given a prefix the rule refuses' => [[['id' => 'aa', 'url' => 'https://aa.example.com/mcp', 'prefix' => 'Aa'], ['id' => 'bb', 'url' => 'https://bb.example.com/mcp', 'prefix' => 'bb']], 0, 'aa', 'https://aa.example.com/mcp', 'prefix'],
    'a stored server sent with no URL' => [[['id' => 'aa', 'url' => '', 'prefix' => 'aa'], ['id' => 'bb', 'url' => 'https://bb.example.com/mcp', 'prefix' => 'bb']], 0, 'aa', '', 'https'],
    'a new row whose prefix a stored server holds' => [[['id' => 'aa', 'url' => 'https://aa.example.com/mcp', 'prefix' => 'aa'], ['id' => 'bb', 'url' => 'https://bb.example.com/mcp', 'prefix' => 'bb'], ['url' => 'https://new.example.com/mcp', 'prefix' => 'aa', 'header_value' => 'Bearer typed']], 2, null, 'https://new.example.com/mcp', 'prefix aa'],
    'a stored server given a prefix that ends in an underscore' => [[['id' => 'aa', 'url' => 'https://aa.example.com/mcp', 'prefix' => 'aa_'], ['id' => 'bb', 'url' => 'https://bb.example.com/mcp', 'prefix' => 'bb']], 0, 'aa', 'https://aa.example.com/mcp', 'single underscores'],
    'a new row under the abilities\' prefix' => [[['id' => 'aa', 'url' => 'https://aa.example.com/mcp', 'prefix' => 'aa'], ['id' => 'bb', 'url' => 'https://bb.example.com/mcp', 'prefix' => 'bb'], ['url' => 'https://new.example.com/mcp', 'prefix' => 'ability', 'header_value' => 'Bearer typed']], 2, null, 'https://new.example.com/mcp', 'not "ability"'],
    'a new row with no prefix' => [[['id' => 'aa', 'url' => 'https://aa.example.com/mcp', 'prefix' => 'aa'], ['id' => 'bb', 'url' => 'https://bb.example.com/mcp', 'prefix' => 'bb'], ['url' => 'https://new.example.com/mcp', 'prefix' => '', 'header_value' => 'Bearer typed']], 2, null, 'https://new.example.com/mcp', 'prefix'],
]);

// M4 (R101): a URL with a user name or password is refused as any row the schema cannot keep is,
// with a reason of its own that says where a credential goes. The refusal names the row by its
// URL with the userinfo taken out, so the credential is not answered back.
it('refuses a PUT whose MCP URL carries a credential, says to use the header, and does not echo it', function (array $row, int $index, ?string $id, string $named): void {
    $this->stored['toolkits.mcp_servers'] = [
        ['id' => 'aa', 'url' => 'https://aa.example.com/mcp', 'header_name' => 'Authorization', 'header_value' => Schema::MASK, 'prefix' => 'aa', 'approved' => []],
    ];
    $controller = new SettingsController(new Store(), new Mcp\ServerSettings(static fn(string $host, string $url): array => throw new RuntimeException('no lookup was expected')));
    $rows = $index === 0 ? [$row] : [['id' => 'aa', 'url' => 'https://aa.example.com/mcp', 'prefix' => 'aa'], $row];
    $response = $controller->update(restRequest('PUT', '/settings', ['models.num_ctx' => 2048, 'toolkits.mcp_servers' => $rows]));
    expect($response)->toBeInstanceOf(WP_Error::class)
        ->and($response->get_error_code())->toBe('alpaca_bot_mcp_row')
        ->and($response->get_error_data()['status'])->toBe(400)
        ->and($response->get_error_data()['rows'])->toHaveCount(1)
        ->and($response->get_error_data()['rows'][0])->toMatchArray(['index' => $index, 'id' => $id, 'url' => $named])
        ->and($response->get_error_data()['rows'][0]['reason'])->toContain('user name or password')->toContain('header')
        ->and($response->get_error_message())->toContain($named)
        ->and(json_encode([$response->get_error_message(), $response->get_error_data()]))->not->toContain('s3cret')->not->toContain('tok3n')
        ->and($this->written)->toBeNull();
})->with([
    'a stored server given a user and password' => [['id' => 'aa', 'url' => 'https://user:s3cret@aa.example.com/mcp', 'prefix' => 'aa'], 0, 'aa', 'https://aa.example.com/mcp'],
    'a new row with a token for a user name' => [['url' => 'https://tok3n@new.example.com/mcp?x=1', 'prefix' => 'nn'], 1, null, 'https://new.example.com/mcp?x=1'],
]);

// A header name with no letter in it is refused as any row the schema cannot keep is, with a
// reason of its own; the header value is not repeated.
it('refuses a PUT whose MCP header name has no letter in it, and says a letter is needed', function (string $name): void {
    $controller = new SettingsController(new Store(), new Mcp\ServerSettings(static fn(string $host, string $url): array => throw new RuntimeException('no lookup was expected')));
    $response = $controller->update(restRequest('PUT', '/settings', ['toolkits.mcp_servers' => [['url' => 'https://num.example.com/mcp', 'prefix' => 'nn', 'header_name' => $name, 'header_value' => 'Bearer typed']]]));
    expect($response)->toBeInstanceOf(WP_Error::class)
        ->and($response->get_error_code())->toBe('alpaca_bot_mcp_row')
        ->and($response->get_error_data()['status'])->toBe(400)
        ->and($response->get_error_data()['rows'][0])->toMatchArray(['index' => 0, 'id' => null, 'url' => 'https://num.example.com/mcp'])
        ->and($response->get_error_data()['rows'][0]['reason'])->toContain('header name')->toContain('letter')
        ->and(json_encode([$response->get_error_message(), $response->get_error_data()]))->not->toContain('Bearer typed')
        ->and($this->written)->toBeNull();
})->with(['123', '-1', '0', '-0', '99999999999999999999']);

// A row refused for another reason is named without its userinfo too: the reason is the URL's.
it('names a refused http URL without the password it carried', function (): void {
    $controller = new SettingsController(new Store(), new Mcp\ServerSettings(static fn(string $host, string $url): array => throw new RuntimeException('no lookup was expected')));
    $response = $controller->update(restRequest('PUT', '/settings', ['toolkits.mcp_servers' => [['url' => 'http://user:s3cret@plain.example.com/mcp', 'prefix' => 'pp']]]));
    expect($response->get_error_code())->toBe('alpaca_bot_mcp_row')
        ->and($response->get_error_data()['rows'][0]['url'])->toBe('http://plain.example.com/mcp')
        ->and($response->get_error_data()['rows'][0]['reason'])->toContain('https with a host')
        ->and(json_encode([$response->get_error_message(), $response->get_error_data()]))->not->toContain('s3cret');
});

// A password with an unencoded `#`, `?` or `/` in it is no userinfo to wp_parse_url(): the URL is
// refused as malformed, and its refusal still must not answer the credential back.
it('names a refused URL without a password that wp_parse_url() could not find', function (): void {
    $controller = new SettingsController(new Store(), new Mcp\ServerSettings(static fn(string $host, string $url): array => throw new RuntimeException('no lookup was expected')));
    foreach (['https://alice:p#ss@evil.example.com/mcp', 'https://alice:p/ss@evil.example.com/mcp', 'https://alice:p?ss@evil.example.com/mcp'] as $url) {
        $response = $controller->update(restRequest('PUT', '/settings', ['toolkits.mcp_servers' => [['url' => $url, 'prefix' => 'pp']]]));
        expect($response->get_error_code())->toBe('alpaca_bot_mcp_row')
            ->and($response->get_error_data()['rows'][0]['url'])->toBe('https://evil.example.com/mcp')
            ->and(json_encode([$response->get_error_message(), $response->get_error_data()]))->not->toContain('alice')->not->toContain('ss@');
    }
});

it('lets a blank row, a removed row and a left-out server go without a refusal', function (): void {
    $this->stored['toolkits.mcp_servers'] = [
        ['id' => 'aa', 'url' => 'https://aa.example.com/mcp', 'header_name' => '', 'header_value' => '', 'prefix' => 'aa', 'approved' => []],
        ['id' => 'bb', 'url' => 'https://bb.example.com/mcp', 'header_name' => '', 'header_value' => '', 'prefix' => 'bb', 'approved' => []],
        ['id' => 'cc', 'url' => 'https://cc.example.com/mcp', 'header_name' => '', 'header_value' => '', 'prefix' => 'cc', 'approved' => []],
    ];
    $controller = new SettingsController(new Store(), new Mcp\ServerSettings(static fn(string $host, string $url): array => throw new RuntimeException('no lookup was expected')));
    $res = $controller->update(restRequest('PUT', '/settings', ['toolkits.mcp_servers' => [
        ['id' => 'aa', 'url' => 'https://aa.example.com/mcp', 'prefix' => 'aa'],
        ['id' => 'bb', 'url' => 'http://bb.example.com/mcp', 'prefix' => 'Nope', 'remove' => '1'],
        ['url' => '', 'prefix' => ''],
        ['url' => '  ', 'prefix' => 'x'],
    ]]));
    expect($res)->toBeInstanceOf(WP_REST_Response::class)
        ->and(array_column($this->written['toolkits.mcp_servers'], 'id'))->toBe(['aa']);
});
