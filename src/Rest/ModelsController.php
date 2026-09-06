<?php

declare(strict_types=1);

namespace AlpacaBot\Rest;

use AlpacaBot\Provider\Model;
use AlpacaBot\Provider\ModelCatalog;
use AlpacaBot\Settings\Store;

/**
 * `GET /models`: the chat models the configured provider offers, as ModelCatalog lists them
 * (`alpaca_bot/models` applied, embedding models out), each `{id, label, tools, vision,
 * thinking}`. The site's default model rides in the `X-Alpaca-Bot-Default-Model` header rather
 * than the body, so the body stays a plain list a client can bind to a picker as is.
 *
 * `refresh=1` bypasses the catalog's five-minute transient and asks the provider again: what a
 * settings screen wants after the operator pulled a new model. It is open to anyone who may
 * chat, unlimited, on purpose: a listing costs the provider far less than one of the thirty
 * chat turns a minute the same person is already allowed.
 *
 * An unreachable provider is not an error here: the catalog reads it as an empty list, and an
 * empty list is what the client needs to render ("no models") rather than a 5xx it would have to
 * explain. The one thing that does fail is a provider that cannot be built at all (an
 * `alpaca_bot/provider` filter returning something else), which the catalog lets out and this
 * route reports as the 502 the chat route would give for the same site.
 */
final class ModelsController extends Controller
{
    public function __construct(private ModelCatalog $catalog, private Store $store) {}

    public function routes(): array
    {
        return [[
            'path' => '/models',
            'methods' => 'GET',
            'callback' => [$this, 'index'],
            'capability' => 'edit_posts',
            'args' => ['refresh' => ['type' => 'boolean', 'default' => false]],
        ]];
    }

    public function index(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        try {
            $models = $this->catalog->all((bool) $request->get_param('refresh'));
        } catch (\Throwable $e) {
            return Errors::provider($e);
        }
        $response = new \WP_REST_Response(array_map(static fn(Model $m): array => $m->toArray(), $models));
        $response->header('X-Alpaca-Bot-Default-Model', $this->catalog->defaultId($this->store));
        return $response;
    }
}
