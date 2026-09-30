<?php

declare(strict_types=1);

namespace AlpacaBot\Rest;

use AlpacaBot\Access;
use AlpacaBot\Errors;
use AlpacaBot\Mcp\Secrets;
use AlpacaBot\Mcp\ServerSettings;
use AlpacaBot\Settings\ProviderKey;
use AlpacaBot\Settings\Schema;
use AlpacaBot\Settings\Store;

/**
 * The `alpaca_bot_settings` option over REST, for administrators by default: `GET /settings` is
 * the full array (every Schema key, defaults filled in), `PUT /settings` a partial update of it,
 * and `GET /settings/schema` the field list a client renders a form from.
 *
 * Secrets (Schema::SECRETS, today the provider API key) read back as Schema::MASK when set and
 * as '' when not, so a client can show "there is a key" without holding it. The mask is also
 * what a client sends back when it has not touched the field: a PUT whose secret is MASK keeps
 * the stored value, one whose secret is '' clears it, and any other string is the new value. The
 * three cases are distinct on the wire, so "clear the key" is always reachable and an untouched
 * form does not wipe it. A secret that is not a string at all (`null`, an array) keeps the stored
 * value too, and the reply shows the mask so the client can see it did: the rule and its reasons
 * are Schema::sanitize()'s, shared with every other writer of the option, and this route only
 * hands the body through. "Keeps" has one exception, the same as an MCP header value's below: a
 * PUT that moves `provider.base_url` to another scheme, host or port clears the stored key unless
 * it sends a new one, whether it sent the mask, a non-string or no key at all
 * (Schema::providerKeyClearedByMove()). The write is not refused for it; the key reads back '',
 * and the reply carries `X-Alpaca-Bot-Cleared: provider.api_key`, because '' alone cannot tell a
 * key just dropped from one never set. An MCP server's header value reads back the same way, MASK or '', in
 * each row of `toolkits.mcp_servers` (masked()), and takes the same three values on the way in;
 * Mcp\ServerSettings leaves only MASK or '' there on each update_option() of the option once the
 * plugin has registered its filter, so masked() is for a row that reached the option any other
 * way. `?reveal=1` on the GET answers the raw secrets
 * instead of the mask: the provider key from its own option (Settings\ProviderKey::resolve(), which
 * also reads a plaintext key a row not yet migrated still carries), and each header value from
 * Mcp\Secrets. Only the flag is on the URL, and the secrets are in the body. A PUT never
 * reveals, whatever its body says.
 *
 * `reveal` asks `manage_options` in show(), a second check the route's own gate has already
 * passed. The gate is the `settings.read` row of Settings › Access and its filters (capability()
 * below), and a site may lower that row or filter it for a custom role, so without this check it
 * would be handing that role the provider credential and every MCP server's header value in
 * cleartext. A caller the gate admitted without `manage_options` is not refused the route, only
 * the secrets: the reply is the masked read, the same one they get without the flag. That read
 * has no floor of its own, and it masks only the provider key and each header value: every other
 * field is answered as stored. Two of those can hold a credential a site wrote into them. An MCP
 * server's URL keeps its query string (`?api_key=…`); a user name or password in it is refused
 * at save (Schema::sanitizeMcpServers()), so the header is where a server's credential goes.
 * `provider.base_url` is answered as stored, a user name, password and query string included:
 * nothing masks it. A site that lowers the `settings.read` row answers those to
 * the role it admits. The write, which 0.5 gated with the read, now has a row and a key of its
 * own, so admitting a role to the read no longer admits it to the PUT.
 *
 * The reveal response sets `Cache-Control: no-store` itself even though core normally supplies
 * it. WP_REST_Server::serve_request() sends a response's own headers first and then, when
 * `rest_send_nocache_headers` holds (by default, for any logged-in user, which every caller of
 * this route is), replaces Cache-Control with its own string — which already contains
 * `no-store`, so on an ordinary site this header is the one that loses. It is set anyway for the
 * site that filters that off: the block does not run, nothing replaces the header, and the
 * reveal response is the one response here that must not be cached whatever the site has decided
 * about the rest. Verified both ways on the harness: with core's headers on, `/settings` and
 * `/settings?reveal=1` answer the identical core string; with `rest_send_nocache_headers`
 * filtered false, `/settings` answers no Cache-Control at all and `/settings?reveal=1` answers
 * `no-store`.
 *
 * A PUT is partial at the top level only: each key sent replaces the stored value outright,
 * keys not sent are untouched (Schema::sanitize() keeps the stored value for a key the input
 * leaves out, so Store::replace() is handed the body as it came). That includes
 * `models.overrides`, a map of model id to its options: a PUT of the map is the whole map, and
 * a model left out is gone. Merging per model would leave a client no way to remove one short
 * of a second, different verb; a client that wants to change one model reads the map, edits it
 * and sends it back. Whatever arrives goes through Schema::sanitize() on its way to the option
 * (unknown keys dropped, ranges clamped, types coerced), and the reply is the array as stored,
 * masked, so the client sees what the schema made of its input rather than what it sent. "As
 * stored" is exact: Store's memo after a write is what the option's filters wrote, so a header
 * value taken out of its row, or an `access.mcp` entry dropped with its server, is gone from the
 * reply too.
 *
 * A PUT carrying `toolkits.mcp_servers` is refused whole, 400 and nothing written, the other keys
 * of the same PUT included, in two cases, both checked before anything is stored by
 * Mcp\ServerSettings::check(), which `wp alpaca-bot settings` runs too:
 * - `alpaca_bot_mcp_row`: a row the schema would drop (Schema::droppedMcpRows()) that names a
 *   stored server's id, or that is new and has a URL. A client that sends a row for a server means
 *   to keep it, so a 200 that had quietly deleted it, its header value and its Access entry with
 *   it, would say the write went as asked. The error's data carries `rows`, each
 *   `{index, id, url, reason}`. Leaving a server out of the list, or sending its
 *   row with `remove`, still deletes it, and a row with neither a stored id nor a URL is still
 *   ignored.
 * - `alpaca_bot_mcp_address`: the address of a server whose URL is new or changed does not pass
 *   the check (Mcp\ServerSettings::refusals()), each row checked under the id the write will give
 *   it. The message names each refused URL and why.
 * Neither error carries a header value.
 *
 * A stored server whose URL the PUT moves to another host or port loses its header value when the
 * PUT sends the mask for it (Mcp\ServerSettings says why). The write is not refused for that: its
 * row reads back '' like any server with no value, and the reply carries
 * `X-Alpaca-Bot-Mcp-Cleared`, the ids of those servers comma-separated, naming only servers that
 * had a value (Mcp\ServerSettings::clearedByMove()), because '' alone cannot tell a value just
 * dropped from one never set. A header rather than an error or a body key keeps
 * the body the settings array and nothing else, as `X-Alpaca-Bot-Default-Model` does for
 * `GET /models`.
 *
 * The keys are dotted (`models.temperature`) and read from get_params(), core's merge of every
 * source; in practice only a JSON body can carry them. PHP rewrites a dot in a top-level
 * form-field or query-string name to an underscore before core sees it, so a form-encoded PUT
 * of `models.temperature` arrives as `models_temperature` and matches nothing. Rather than
 * answer 200 with nothing changed, a PUT that names no known key is a 400 that says to send
 * JSON.
 *
 * Capability: the two GETs ask the `settings.read` row and the PUT the `settings.write` row,
 * both `manage_options` by default, through Access::effective() — 0.5's
 * `alpaca_bot/capability/settings` over the stored row, then
 * `alpaca_bot/capability/settings/read` or `…/settings/write` over what it returned, so a site
 * that filtered the old key keeps what it set and the newer key wins where both are set.
 *
 * These routes do not apply `alpaca_bot/capability/{route}`, so 0.5's
 * `alpaca_bot/capability/settings/schema` is retired: it is no longer applied, and the schema
 * route asks the `settings.read` row with the rest of the read. A site that filtered that key
 * loses the filter, whichever way it pointed it. 0.5 applied it over the route's declared
 * `manage_options` on its own, independently of `alpaca_bot/capability/settings`, so opening the
 * schema *alone* was a thing a site could do — and a reasonable one, since the schema route
 * answers field descriptions and no stored value, which is what a custom settings form for a
 * lower role needs. That site's role is now refused, so this narrows as well as widens.
 * `alpaca_bot/capability/settings/read` is where such a filter moves to.
 *
 * Splitting the verbs is what makes a read-only settings role possible: `provider.base_url` is a
 * settable field, so a role admitted to the write can point every turn the site takes at a
 * server of its choosing.
 */
final class SettingsController extends Controller
{
    private ServerSettings $servers;

    /** @param ServerSettings|null $servers the address check a PUT of `toolkits.mcp_servers` runs; one over AddressCheck by default */
    public function __construct(private Store $store, ?ServerSettings $servers = null)
    {
        $this->servers = $servers ?? new ServerSettings();
    }

    public function routes(): array
    {
        return [
            ['path' => '/settings', 'methods' => 'GET', 'callback' => [$this, 'show'], 'capability' => 'settings.read', 'args' => ['reveal' => ['type' => 'boolean', 'default' => false]]],
            ['path' => '/settings', 'methods' => 'PUT', 'callback' => [$this, 'update'], 'capability' => 'settings.write'],
            ['path' => '/settings/schema', 'methods' => 'GET', 'callback' => [$this, 'schema'], 'capability' => 'settings.read'],
        ];
    }

    /**
     * These three routes resolve their Settings › Access row rather than the base's
     * `alpaca_bot/capability/{route}`: accessRow() hands the row to Access::effective(), which
     * applies 0.5's `alpaca_bot/capability/settings` over the stored row and then the row's own
     * key, and that chain is the whole of the split.
     *
     * `$declared` is what the route table carries — a row name for all three of today's routes —
     * so the verb is read off the table rather than off the request: the GET and the PUT share a
     * path, and `routeKey()` would hand both the one key again.
     *
     * Only a row Access declares is resolved as one. A route added here that names a plain
     * capability falls through to the base and is authorised like every other route in the
     * plugin, `alpaca_bot/capability/{route}` and all. Without that test it would be handed to
     * Access::effective() as if it were a row: an undeclared row fails closed to
     * `manage_options`, so the route would quietly be authorised at that rather than at what it
     * declared, and the only filter it offered a site would be one named after the *declared
     * capability* — Access::hook() builds the name from the token it is handed, so a route
     * declaring `edit_posts` would fire `alpaca_bot/capability/edit_posts`, a hook nobody
     * documents, since bin/hooks-doc.php reads string literals and this name is built at runtime.
     * The check is against Access::defaults() rather than a list kept here so that a row the access
     * model declares a default for is usable from this table the day it exists. That is every row
     * but `mcp.<server id>`, which exists only once a server does and is deliberately not in
     * defaults(); such a row would fall through to the base, and no route here wants one.
     *
     * What Access resolves is returned as it comes, and is never handed back to
     * parent::capability() as its `$declared` — the fallback above passes the route's *declared*
     * value, never a resolved one. That is deliberate rather than a shortcut. The base reads `$declared`
     * for Controller::CHAT, which is the literal string 'chat', while a site's
     * `alpaca_bot/capability/settings/read` filter may return any capability name it likes —
     * 'chat' among them, since Capability::filtered() honours every non-empty non-numeric string.
     * Routing a resolved capability back through that comparison would let such a filter flip a
     * settings route onto the Chat row; returning here means a capability name can never be read
     * as the sentinel. It also means these routes apply no `{route}` filter at all, which is what
     * retires `alpaca_bot/capability/settings/schema` and keeps `alpaca_bot/capability/settings`
     * to the one application Access makes of it.
     *
     * A controller nobody handed an Access — a test, a site building one by hand — resolves the
     * row to Access::defaults() instead, `manage_options` for both; accessRow() answers that case
     * once, in the base, so nothing here builds a second Access over the same Store.
     *
     * @since 0.6.0
     */
    protected function capability(string $route, string $declared, \WP_REST_Request $request): string
    {
        return isset(Access::defaults()[$declared])
            ? $this->accessRow($declared, $request)
            : parent::capability($route, $declared, $request);
    }

    public function show(\WP_REST_Request $request): \WP_REST_Response
    {
        if ((bool) $request->get_param('reveal') && current_user_can('manage_options')) {
            $settings = $this->store->all();
            $settings['provider.api_key'] = ProviderKey::resolve($settings['provider.api_key'] ?? '');
            if (is_array($settings['toolkits.mcp_servers'] ?? null)) {
                foreach ($settings['toolkits.mcp_servers'] as $i => $row) {
                    if (is_array($row)) {
                        $settings['toolkits.mcp_servers'][$i]['header_value'] = Secrets::resolve($row);
                    }
                }
            }
            $response = new \WP_REST_Response($settings);
            $response->header('Cache-Control', 'no-store');
            return $response;
        }
        return new \WP_REST_Response($this->masked($this->store->all()));
    }

    public function update(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $input = array_intersect_key($request->get_params(), Schema::fields());
        if ($input === []) {
            return Errors::badRequest(__('No settings were sent. Send a JSON body of dotted keys, e.g. {"models.temperature": 0.7}.', 'alpaca-bot'));
        }
        $cleared = [];
        if (array_key_exists('toolkits.mcp_servers', $input)) {
            $check = $this->servers->check($input['toolkits.mcp_servers'], $this->store->get('toolkits.mcp_servers'));
            if ($check->isRefused()) {
                return Errors::badRequest($check->message, (string) $check->code, $check->data);
            }
            $cleared = $check->cleared;
        }
        $keyCleared = Schema::providerKeyClearedByMove($input, $this->store->all());
        $this->store->replace($input);
        $response = new \WP_REST_Response($this->masked($this->store->all()));
        if ($cleared !== []) {
            $response->header('X-Alpaca-Bot-Mcp-Cleared', implode(',', $cleared));
        }
        if ($keyCleared) {
            $response->header('X-Alpaca-Bot-Cleared', 'provider.api_key');
        }
        return $response;
    }

    /**
     * Schema::sections() and Schema::fields() as data: the `sanitize` callables are dropped (they
     * are server-side and would only serialise as a class name), a secret field is flagged
     * `secret` so a form renders it as a password input and knows the mask applies, and the mask
     * itself is named so the client compares against the same string this controller does.
     */
    public function schema(\WP_REST_Request $request): \WP_REST_Response
    {
        $fields = [];
        foreach (Schema::fields() as $key => $field) {
            unset($field['sanitize']);
            if (in_array($key, Schema::SECRETS, true)) {
                $field['secret'] = true;
            }
            if ($key === 'toolkits.mcp_servers') {
                $field['secret_fields'] = ['header_value'];
            }
            $fields[$key] = $field;
        }
        return new \WP_REST_Response(['sections' => Schema::sections(), 'fields' => $fields, 'mask' => Schema::MASK]);
    }

    /**
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    private function masked(array $settings): array
    {
        foreach (Schema::SECRETS as $key) {
            if (($settings[$key] ?? '') !== '') {
                $settings[$key] = Schema::MASK;
            }
        }
        if (array_key_exists('toolkits.mcp_servers', $settings)) {
            $settings['toolkits.mcp_servers'] = Schema::maskedServers($settings['toolkits.mcp_servers']);
        }
        return $settings;
    }
}
