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
 * Secrets (SECRETS, today the provider API key) read back as MASK when set and as '' when not,
 * so a client can show "there is a key" without holding it. The mask is also what a client
 * sends back when it has not touched the field: a PUT whose secret is MASK keeps the stored
 * value, one whose secret is '' clears it, and anything else is the new value. The three cases
 * are distinct on the wire, so "clear the key" is always reachable and an untouched form never
 * wipes it. `?reveal=1` on the GET answers the raw secret instead of the mask: only the flag is
 * on the URL, the secret is in the body, and the body is marked `Cache-Control: no-store` so
 * neither a browser nor a proxy keeps a copy. A PUT never reveals, whatever its body says.
 *
 * A PUT is partial at the top level only: each key sent replaces the stored value outright,
 * keys not sent are untouched. That includes `models.overrides`, a map of model id to its
 * options: a PUT of the map is the whole map, and a model left out is gone. Merging per model
 * would leave a client no way to remove one short of a second, different verb; a client that
 * wants to change one model reads the map, edits it and sends it back. Whatever arrives goes
 * through Schema::sanitize() on its way to the option (unknown keys dropped, ranges clamped,
 * types coerced), and the reply is the array as stored, masked, so the client sees what the
 * schema made of its input rather than what it sent.
 *
 * The keys are dotted (`models.temperature`) and read from get_params(), core's merge of every
 * source; in practice only a JSON body can carry them. PHP rewrites a dot in a top-level
 * form-field or query-string name to an underscore before core sees it, so a form-encoded PUT
 * of `models.temperature` arrives as `models_temperature` and matches nothing. Rather than
 * answer 200 with nothing changed, a PUT that names no known key is a 400 that says to send
 * JSON.
 */
final class SettingsController extends Controller
{
    public const MASK = '••••';

    /** @var list<string> */
    private const SECRETS = ['provider.api_key'];

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
        if ((bool) $request->get_param('reveal')) {
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
        $current = $this->store->all();
        foreach (self::SECRETS as $key) {
            if (($input[$key] ?? null) === self::MASK) {
                $input[$key] = $current[$key] ?? '';
            }
        }
        $this->store->replace(array_merge($current, $input));
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
            if (in_array($key, self::SECRETS, true)) {
                $field['secret'] = true;
            }
            $fields[$key] = $field;
        }
        return new \WP_REST_Response(['sections' => Schema::sections(), 'fields' => $fields, 'mask' => self::MASK]);
    }

    /**
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    private function masked(array $settings): array
    {
        foreach (self::SECRETS as $key) {
            if (($settings[$key] ?? '') !== '') {
                $settings[$key] = self::MASK;
            }
        }
        return $settings;
    }
}
