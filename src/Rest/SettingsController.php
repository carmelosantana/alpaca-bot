<?php

declare(strict_types=1);

namespace AlpacaBot\Rest;

use AlpacaBot\Settings\Schema;
use AlpacaBot\Settings\Store;

/**
 * The `alpaca_bot_settings` option over REST, for administrators: `GET /settings` is the full
 * array (every Schema key, defaults filled in), `PUT /settings` a partial update of it, and
 * `GET /settings/schema` the field list a client renders a form from.
 *
 * Secrets (Schema::SECRETS, today the provider API key) read back as Schema::MASK when set and
 * as '' when not, so a client can show "there is a key" without holding it. The mask is also
 * what a client sends back when it has not touched the field: a PUT whose secret is MASK keeps
 * the stored value, one whose secret is '' clears it, and any other string is the new value. The
 * three cases are distinct on the wire, so "clear the key" is always reachable and an untouched
 * form never wipes it. A secret that is not a string at all (`null`, an array) keeps the stored
 * value too, and the reply shows the mask so the client can see it did: the rule and its reasons
 * are Schema::sanitize()'s, shared with every other writer of the option, and this route only
 * hands the body through. `?reveal=1` on the GET answers the raw secret instead of the mask:
 * only the flag is on the URL, and the secret is in the body. A PUT never reveals, whatever its
 * body says.
 *
 * `reveal` asks `manage_options` in show(), a second check the route's own gate has already
 * passed. The gate is filtered — `alpaca_bot/capability/settings` (below) — and the filter
 * decides the route, so without this check a site that loosened the filter for a custom role
 * would be handing that role the provider credential in cleartext. A caller the filter admitted
 * without `manage_options` is not refused the route, only the secret: the reply is the masked
 * read, the same one they get without the flag. Nothing else on the route has a floor, because
 * nothing else on it is a credential; the write verb is the other half of that argument and is
 * a 0.6 ticket (splitting the filter into read and write keys), not a check that can be added
 * here without deciding what a "read-only settings" role means.
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
 * masked, so the client sees what the schema made of its input rather than what it sent.
 *
 * The keys are dotted (`models.temperature`) and read from get_params(), core's merge of every
 * source; in practice only a JSON body can carry them. PHP rewrites a dot in a top-level
 * form-field or query-string name to an underscore before core sees it, so a form-encoded PUT
 * of `models.temperature` arrives as `models_temperature` and matches nothing. Rather than
 * answer 200 with nothing changed, a PUT that names no known key is a 400 that says to send
 * JSON.
 *
 * Capability filters follow Controller::routeKey(): `alpaca_bot/capability/settings` covers
 * both verbs on `/settings`, and `/settings/schema` has its own, `alpaca_bot/capability/settings/schema`.
 * A site that loosens the first for a custom role has not loosened the second; a client of that
 * role reads the settings and gets a 403 on the schema until the site names it too.
 *
 * One key over both verbs is worth saying plainly: loosening `alpaca_bot/capability/settings`
 * to admit a role to the GET admits it to the PUT as well, and `provider.base_url` is a
 * settable field — so that role can point every turn the site takes at a server of its
 * choosing. Tighten per request off the WP_REST_Request the filter is handed
 * (`$request->get_method()`) until 0.6 separates the keys.
 */
final class SettingsController extends Controller
{
    public function __construct(private Store $store) {}

    public function routes(): array
    {
        return [
            ['path' => '/settings', 'methods' => 'GET', 'callback' => [$this, 'show'], 'capability' => 'manage_options', 'args' => ['reveal' => ['type' => 'boolean', 'default' => false]]],
            ['path' => '/settings', 'methods' => 'PUT', 'callback' => [$this, 'update'], 'capability' => 'manage_options'],
            ['path' => '/settings/schema', 'methods' => 'GET', 'callback' => [$this, 'schema'], 'capability' => 'manage_options'],
        ];
    }

    public function show(\WP_REST_Request $request): \WP_REST_Response
    {
        if ((bool) $request->get_param('reveal') && current_user_can('manage_options')) {
            $response = new \WP_REST_Response($this->store->all());
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
        $this->store->replace($input);
        return new \WP_REST_Response($this->masked($this->store->all()));
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
        return $settings;
    }
}
