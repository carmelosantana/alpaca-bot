<?php

declare(strict_types=1);

namespace AlpacaBot\Rest;

use AlpacaBot\Chat\ConversationStore;
use AlpacaBot\Chat\Message;
use AlpacaBot\Chat\UserPrefs;
use AlpacaBot\Provider\ModelCatalog;
use AlpacaBot\Settings\Store;
use AlpacaBot\View\Chat\HistorySelect;
use AlpacaBot\View\Chat\MessageBubble;
use AlpacaBot\View\Chat\MessageList;
use AlpacaBot\View\Chat\ModelSelect;
use AlpacaBot\View\Chat\Notice;
use AlpacaBot\View\Chat\Participants;
use AlpacaBot\View\Markdown;

/**
 * The `/view/*` routes: the chat screen's fragments, rendered server-side by the same components
 * the screen is built from, for htmx (the selects) and chat.ts (the bubbles) to swap in. Each
 * answers `text/html`, not JSON, and is for the screen: a client that wants data reads the JSON
 * routes. Every route is `edit_posts`, as the screen and the chat routes are, under its own
 * `alpaca_bot/capability/view/{name}` filter.
 *
 * - `GET /view/messages/{id}`: the transcript (#ab-messages) of one of the user's conversations;
 *   404 for anyone else's, as `/conversations/{id}` answers.
 * - `GET /view/history?conversation_id=`: the history select (#ab-history) with the open
 *   conversation selected, listed with its own title when `chat.history_limit` cut it.
 * - `GET /view/models?refresh=`: the model select on the user's effective model (UserPrefs).
 * - `POST /view/default-model {model}`: stores the user's default model (Kanboard #565) and
 *   answers a Notice for #ab-status. Refused (403) while `chat.user_can_change_model` is off:
 *   the select is disabled then, but a disabled select is markup, and the route is the guard.
 * - `GET /view/bubble?role=&streaming=`: an empty bubble for chat.ts to stream deltas into;
 *   `POST /view/bubble {role, content, model, usage, duration_ms, images, tool_calls}`: a
 *   finished bubble, the assistant's content rendered as markdown, which chat.ts swaps in for
 *   the streamed text, its receipt counting the `tool_calls` the done frame carried; a user
 *   turn with its `images` (data URLs) is the optimistic bubble chat.ts shows while the turn
 *   runs.
 *
 * Core renders a callback's return as JSON, so a callback answers a WP_REST_Response whose data
 * is the HTML string and whose `X-Alpaca-Bot-View: 1` header marks it; serve(), on
 * `rest_pre_serve_request`, recognises the header, replaces the content type and writes the
 * string as the body. It is the mechanism StreamController uses for its frames, with the mark on
 * the response rather than the request: nothing here is spent by the time serve() runs, so a
 * response core has rebuilt (`?_envelope=1`) simply loses the mark and renders as JSON.
 */
final class ViewController extends Controller
{
    public const HEADER = 'X-Alpaca-Bot-View';

    private const ROLES = ['user', 'assistant'];

    public function __construct(private ConversationStore $conversations, private Store $store, private ModelCatalog $catalog, private Markdown $markdown, private UserPrefs $prefs) {}

    /**
     * `/view/models` shares the chat rate limit for the reason `/models` does: `refresh=1` is a
     * synchronous provider call. The rest are reads of the site's own database, or a bubble
     * built from the request, and are not limited.
     */
    public function routes(): array
    {
        $role = ['type' => 'string', 'enum' => self::ROLES];
        return [
            ['path' => '/view/messages/(?P<id>\d+)', 'methods' => 'GET', 'callback' => [$this, 'messages'], 'capability' => 'edit_posts'],
            ['path' => '/view/history', 'methods' => 'GET', 'callback' => [$this, 'history'], 'capability' => 'edit_posts', 'args' => ['conversation_id' => ['type' => 'integer', 'default' => 0, 'minimum' => 0]]],
            ['path' => '/view/models', 'methods' => 'GET', 'callback' => [$this, 'models'], 'capability' => 'edit_posts', 'rate_limit' => true, 'args' => ['refresh' => ['type' => 'boolean', 'default' => false]]],
            ['path' => '/view/default-model', 'methods' => 'POST', 'callback' => [$this, 'defaultModel'], 'capability' => 'edit_posts', 'args' => ['model' => ['type' => 'string', 'required' => true]]],
            ['path' => '/view/bubble', 'methods' => 'GET', 'callback' => [$this, 'streamingBubble'], 'capability' => 'edit_posts', 'args' => ['role' => $role + ['default' => 'assistant'], 'streaming' => ['type' => 'boolean', 'default' => false]]],
            ['path' => '/view/bubble', 'methods' => 'POST', 'callback' => [$this, 'bubble'], 'capability' => 'edit_posts', 'args' => [
                'role' => $role + ['required' => true],
                'content' => ['type' => 'string', 'default' => ''],
                'model' => ['type' => 'string', 'default' => ''],
                'usage' => ['type' => ['object', 'null'], 'default' => null, 'properties' => ['prompt_tokens' => ['type' => 'integer'], 'completion_tokens' => ['type' => 'integer']]],
                'duration_ms' => ['type' => 'integer', 'default' => 0, 'minimum' => 0],
                'images' => ['type' => 'array', 'items' => ['type' => 'string'], 'default' => []],
                'tool_calls' => ['type' => 'array', 'items' => ['type' => 'object'], 'default' => []],
            ]],
        ];
    }

    /**
     * The routes and the hook that serves them register together, so a site that drops this
     * controller through `alpaca_bot/rest/controllers` loses both. Last, as StreamController's
     * is: anything hooked earlier may serve the request itself, and is left to.
     */
    public function register(): void
    {
        parent::register();
        add_filter('rest_pre_serve_request', [$this, 'serve'], PHP_INT_MAX, 4);
    }

    public function messages(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $c = $this->conversations->load((int) $request->get_param('id'), $this->userId());
        if ($c === null) {
            return Errors::notFound(__('Conversation', 'alpaca-bot'));
        }
        $who = Participants::current($this->store);
        return self::html((new MessageList($c->messages, $this->markdown, $this->store, $who->userName, $who->userAvatar, $who->assistantAvatar, $c->id))->render());
    }

    /**
     * `conversation_id` is the open conversation, which the wrapper sends from the composer's
     * hidden field (HistorySelect says how). One the list does not carry is loaded for its
     * title; one that is not the user's renders as a new chat rather than as an error, because
     * the select is the header's and has to render whatever the id says.
     */
    public function history(\WP_REST_Request $request): \WP_REST_Response
    {
        $userId = $this->userId();
        $current = max(0, (int) $request->get_param('conversation_id'));
        $rows = $this->conversations->listFor($userId, max(1, (int) $this->store->get('chat.history_limit')));
        $title = '';
        if ($current > 0 && !in_array($current, array_column($rows, 'id'), true)) {
            $c = $this->conversations->load($current, $userId);
            $current = $c === null ? 0 : $current;
            $title = $c === null ? '' : $c->title;
        }
        return self::html((new HistorySelect($rows, $current, $title))->render());
    }

    /** A provider that cannot be built is the 502 `/models` answers; an unreachable one is an empty select. */
    public function models(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        try {
            $models = $this->catalog->all((bool) $request->get_param('refresh'));
        } catch (\Throwable $e) {
            return Errors::provider($e);
        }
        $selected = $this->prefs->modelFor($this->userId(), $this->catalog, $this->store);
        return self::html((new ModelSelect($models, $selected, (bool) $this->store->get('chat.user_can_change_model')))->render());
    }

    /**
     * The model is stored as sent (sanitized, not checked against the catalog): the catalog is
     * consulted when the preference is used (UserPrefs::modelFor()), so a model that is listed
     * later, or unlisted while the provider is down, is neither refused here nor run blindly.
     * Sanitizing strips markup and caps nothing, so the length is capped here: a model id is a
     * short token, and the preference is a usermeta row any editor writes to at will.
     */
    public function defaultModel(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        if (!(bool) $this->store->get('chat.user_can_change_model')) {
            return Errors::forbidden(__('This site does not let users change the model.', 'alpaca-bot'));
        }
        $model = mb_substr(trim(sanitize_text_field((string) $request->get_param('model'))), 0, 200);
        if ($model === '') {
            return Errors::badRequest(__('Choose a model.', 'alpaca-bot'));
        }
        $this->prefs->setDefaultModel($this->userId(), $model);
        return self::html((new Notice('success', __('Default model saved.', 'alpaca-bot')))->render());
    }

    public function streamingBubble(\WP_REST_Request $request): \WP_REST_Response
    {
        $role = (string) $request->get_param('role');
        if (!in_array($role, self::ROLES, true)) {
            $role = 'assistant';
        }
        return $this->renderBubble(new Message($role, ''), (bool) $request->get_param('streaming'));
    }

    /**
     * The message as the `done` frame (or the composer) has it. The role is held to a turn's
     * (the schema refuses anything else first; this is for a caller that did not come through
     * core's validation), the content is the bubble's to escape or render, and the receipt
     * reads usage and duration_ms exactly as it does off a stored reply. `images` are passed as
     * strings; MessageBubble decides which are data URLs it will render. `tool_calls` are the
     * records the reply's meta carries (Pipeline: `{name, arguments, result_excerpt, ok}`),
     * kept as posted, records only: the receipt counts them, and nothing here reads inside one.
     */
    public function bubble(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $role = (string) $request->get_param('role');
        if (!in_array($role, self::ROLES, true)) {
            return Errors::badRequest(__('A bubble is a user or an assistant turn.', 'alpaca-bot'));
        }
        $usage = $request->get_param('usage');
        $message = new Message(
            $role,
            (string) $request->get_param('content'),
            sanitize_text_field((string) $request->get_param('model')),
            is_array($usage) ? ['prompt_tokens' => (int) ($usage['prompt_tokens'] ?? 0), 'completion_tokens' => (int) ($usage['completion_tokens'] ?? 0)] : null,
            0,
            array_values(array_filter((array) $request->get_param('images'), 'is_string')),
            ['duration_ms' => max(0, (int) $request->get_param('duration_ms')), 'tool_calls' => array_values(array_filter((array) $request->get_param('tool_calls'), 'is_array'))],
        );
        return $this->renderBubble($message, false);
    }

    /**
     * `rest_pre_serve_request`: for a response carrying the view mark, and only that, sends the
     * HTML content type over core's JSON one (core has already sent its headers, this one
     * included) and writes the string as the body. A HEAD (core sends it to the GET handler)
     * gets the type and no body. Every other response, and one something hooked earlier has
     * served, is left alone.
     */
    public function serve(bool $served, \WP_HTTP_Response $result, \WP_REST_Request $request, \WP_REST_Server $server): bool
    {
        $html = $result->get_data();
        if ($served || ($result->get_headers()[self::HEADER] ?? null) !== '1' || !is_string($html)) {
            return $served;
        }
        $server->send_header('Content-Type', 'text/html; charset=utf-8');
        if ($request->get_method() !== 'HEAD') {
            // Component output: escaped where it was built (Component::tag(), Markdown's kses).
            echo $html;
        }
        return true;
    }

    private function renderBubble(Message $message, bool $streaming): \WP_REST_Response
    {
        $who = Participants::current($this->store);
        return self::html((new MessageBubble($message, $this->markdown, $who->userName, $who->userAvatar, $who->assistantAvatar, $streaming))->render());
    }

    /** The response shape every route answers: the HTML as the data, marked for serve(). */
    private static function html(string $html): \WP_REST_Response
    {
        $response = new \WP_REST_Response($html);
        $response->header(self::HEADER, '1');
        return $response;
    }
}
