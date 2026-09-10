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
 * `tools` is the per-model routing decision, not the catalogue alone: an operator's
 * `models.overrides[<model>][tools]` outranks the catalogue at that decision
 * (Chat\Pipeline::toolkitsFor()), so it is laid over the flag here too, and a model somebody
 * forced tools off on does not list as tool-capable. It is not a promise about a turn:
 * toolkitsFor() returns [] before it ever reads the override when the user whose turn it is has
 * no toolkit enabled, and enablement is resolved per user (Toolkit\Registry::enabled(), through
 * `alpaca_bot/toolkits`). This route is not short of a user — it requires `edit_posts`, so there
 * always is one — but the controller is constructed with a ModelCatalog and a Store and never
 * asks the toolkit registry at all, so the row it prints cannot reflect enablement for anybody:
 * `tools: true` here can describe a turn that runs plain. The overlay is on the response only —
 * the Model objects, the request memo and the five-minute transient behind them all keep the
 * provider's own answer, which is what an inherit row still has to be able to read. `vision` and
 * `thinking` have no override and are the catalogue's word alone.
 *
 * `refresh=1` bypasses the catalog's five-minute transient and asks the provider again: what a
 * settings screen wants after the operator pulled a new model.
 *
 * The route is open to anyone who may chat and shares the chat routes' rate limit. A listing is
 * cheaper than a chat turn, but the limiter is about rate, not cost per request: the catalog
 * does not cache an empty list, so while the provider is down every request here, not only a
 * refresh, is a synchronous upstream call that holds a PHP worker for up to the provider
 * timeout, and an unlimited route lets one editor's script (or one leaked Application Password)
 * pin every worker the site has. Under the shared 'chat' bucket a client that lists models as
 * often as it chats never notices; a loop does.
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
            'rate_limit' => true,
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
        $response = new \WP_REST_Response(array_map(function (Model $m): array {
            $row = $m->toArray();
            $row['tools'] = $this->store->toolsOverride($m->id) ?? $row['tools'];
            return $row;
        }, $models));
        $response->header('X-Alpaca-Bot-Default-Model', $this->catalog->defaultId($this->store));
        return $response;
    }
}
