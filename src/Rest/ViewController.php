<?php

declare(strict_types=1);

namespace AlpacaBot\Rest;

use AlpacaBot\Chat\ConversationStore;
use AlpacaBot\Chat\Message;
use AlpacaBot\Chat\UserPrefs;
use AlpacaBot\Context\CurrentScreenSource;
use AlpacaBot\Errors;
use AlpacaBot\Mcp\Discovery;
use AlpacaBot\Mcp\McpUnavailable;
use AlpacaBot\Mcp\ServerConfig;
use AlpacaBot\Provider\ModelCatalog;
use AlpacaBot\Settings\Schema;
use AlpacaBot\Settings\Store;
use AlpacaBot\View\Chat\Drawer;
use AlpacaBot\View\Chat\HistorySelect;
use AlpacaBot\View\Chat\MessageBubble;
use AlpacaBot\View\Chat\MessageList;
use AlpacaBot\View\Chat\ModelSelect;
use AlpacaBot\View\Chat\Notice;
use AlpacaBot\View\Chat\Participants;
use AlpacaBot\View\Markdown;
use AlpacaBot\View\Settings\McpTools;

/**
 * The `/view/*` routes: the chat screen's fragments, rendered server-side by the same components
 * the screen is built from, for htmx (the selects) and chat.ts (the bubbles) to swap in, the
 * whole chat for the admin-wide drawer and the block editor's sidebar (`/view/panel`), and one
 * MCP server's approval list for the settings page (`/view/mcp-tools`). Each answers
 * `text/html`, not JSON, and is for those: a client that wants data reads the JSON routes. Every
 * route but `/view/mcp-tools` takes the Chat row of Settings › Access (`edit_posts` by default),
 * as the screen and the chat routes do; `/view/mcp-tools` is `manage_options`, and asks for it
 * again itself (mcpTools()). Each is under its own `alpaca_bot/capability/view/{name}` filter.
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
 * - `GET /view/panel?conversation_id=&post_id=&screen_id=&screen_title=`: the whole chat as one
 *   fragment (View\Chat\Drawer) for the admin-wide drawer and the block editor's sidebar, on one
 *   of the user's conversations or a new chat, as the chat screen answers `?conversation=`, with
 *   the post and the screen as the composer's context chips.
 * - `POST /view/drawer {open, conversation_id}`: stores what the admin-wide drawer shows
 *   (Admin\Drawer) and answers an empty fragment.
 * - `GET /view/mcp-tools/{id}?index=`: a stored MCP server's tools as the settings form's
 *   approval list (View\Settings\McpTools), declared only when the controller was handed a
 *   Mcp\Discovery, which Plugin::controllers() does.
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

    /** The longest reason a server could not be listed that the fragment prints, in characters. */
    private const REASON_CHARS = 500;

    /** `$discovery` is optional, so a controller built without one (a test, a site's own) keeps every other route. */
    public function __construct(private ConversationStore $conversations, private Store $store, private ModelCatalog $catalog, private Markdown $markdown, private UserPrefs $prefs, private ?Discovery $discovery = null) {}

    /**
     * `/view/models` shares the chat rate limit for the reason `/models` does: `refresh=1` is a
     * synchronous provider call. `/view/mcp-tools` shares it for the same reason: it asks a remote
     * server synchronously. The rest are not limited. Each works on the site's own database or
     * builds a bubble from the request, and `/view/panel` also renders the model select through
     * the catalog, which asks the provider when its cache is empty, as the chat screen's own
     * render does; that screen is not rate limited either.
     */
    public function routes(): array
    {
        $role = ['type' => 'string', 'enum' => self::ROLES];
        return [
            ['path' => '/view/messages/(?P<id>\d+)', 'methods' => 'GET', 'callback' => [$this, 'messages'], 'capability' => self::CHAT],
            ['path' => '/view/history', 'methods' => 'GET', 'callback' => [$this, 'history'], 'capability' => self::CHAT, 'args' => ['conversation_id' => ['type' => 'integer', 'default' => 0, 'minimum' => 0]]],
            ['path' => '/view/models', 'methods' => 'GET', 'callback' => [$this, 'models'], 'capability' => self::CHAT, 'rate_limit' => true, 'args' => ['refresh' => ['type' => 'boolean', 'default' => false]]],
            ['path' => '/view/default-model', 'methods' => 'POST', 'callback' => [$this, 'defaultModel'], 'capability' => self::CHAT, 'args' => ['model' => ['type' => 'string', 'required' => true]]],
            ['path' => '/view/bubble', 'methods' => 'GET', 'callback' => [$this, 'streamingBubble'], 'capability' => self::CHAT, 'args' => ['role' => $role + ['default' => 'assistant'], 'streaming' => ['type' => 'boolean', 'default' => false]]],
            ['path' => '/view/bubble', 'methods' => 'POST', 'callback' => [$this, 'bubble'], 'capability' => self::CHAT, 'args' => [
                'role' => $role + ['required' => true],
                'content' => ['type' => 'string', 'default' => ''],
                'model' => ['type' => 'string', 'default' => ''],
                'usage' => ['type' => ['object', 'null'], 'default' => null, 'properties' => ['prompt_tokens' => ['type' => 'integer'], 'completion_tokens' => ['type' => 'integer']]],
                'duration_ms' => ['type' => 'integer', 'default' => 0, 'minimum' => 0],
                'images' => ['type' => 'array', 'items' => ['type' => 'string'], 'default' => []],
                'tool_calls' => ['type' => 'array', 'items' => ['type' => 'object'], 'default' => []],
            ]],
            ['path' => '/view/panel', 'methods' => 'GET', 'callback' => [$this, 'panel'], 'capability' => self::CHAT, 'args' => [
                'conversation_id' => ['type' => 'integer', 'default' => 0, 'minimum' => 0],
                'post_id' => ['type' => 'integer', 'default' => 0, 'minimum' => 0],
                'screen_id' => ['type' => 'string', 'default' => ''],
                'screen_title' => ['type' => 'string', 'default' => ''],
            ]],
            ['path' => '/view/drawer', 'methods' => 'POST', 'callback' => [$this, 'drawer'], 'capability' => self::CHAT, 'args' => [
                'open' => ['type' => 'boolean'],
                'conversation_id' => ['type' => 'integer', 'minimum' => 0],
            ]],
            ...($this->discovery === null ? [] : [
                ['path' => '/view/mcp-tools/(?P<id>[a-z0-9_]{1,24})', 'methods' => 'GET', 'callback' => [$this, 'mcpTools'], 'capability' => 'manage_options', 'rate_limit' => true, 'args' => ['index' => ['type' => 'integer', 'default' => 0, 'minimum' => 0]]],
            ]),
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
     * The whole chat as one fragment, built as Admin\ChatScreen builds the chat screen:
     * `conversation_id` opens one of the user's own, and anyone else's, or a missing one, is a
     * new chat rather than an error, as `?conversation=` is on the screen; the history is the
     * user's, `chat.history_limit` deep; the model is the user's effective one (UserPrefs).
     * `post_id` becomes the composer's post chip when the user may edit the post (Shell asks);
     * `screen_id` and `screen_title` become its screen chip, cleaned by
     * Context\CurrentScreenSource::screenFrom(), the function that cleans them again on the turn,
     * so the chip holds what the turn will make of it. Either chip is only what the turn sends:
     * the source decides per turn, for the turn's user, what reaches the model.
     */
    public function panel(\WP_REST_Request $request): \WP_REST_Response
    {
        $userId = $this->userId();
        $wanted = max(0, (int) $request->get_param('conversation_id'));
        $conversation = $wanted > 0 ? $this->conversations->load($wanted, $userId) : null;
        $history = $this->conversations->listFor($userId, max(1, (int) $this->store->get('chat.history_limit')));
        $drawer = new Drawer(
            $this->store,
            $this->catalog,
            $conversation,
            $history,
            max(0, (int) $request->get_param('post_id')),
            $this->prefs->modelFor($userId, $this->catalog, $this->store),
            null,
            CurrentScreenSource::screenFrom(['id' => (string) $request->get_param('screen_id'), 'title' => (string) $request->get_param('screen_title')]),
        );
        return self::html($drawer->render());
    }

    /**
     * Stores what the drawer shows (Admin\Drawer): whether it is open, the conversation in it, or
     * both. A parameter the request leaves out is left alone, and neither argument has a default,
     * so an absent one reads as null: the two are written by different events (a launcher press,
     * a turn starting), and a write of one must not reset the other.
     *
     * The conversation id is stored as sent. Whether it is the user's is decided where it is read:
     * `GET /view/panel` loads it through ConversationStore::load(), which answers null for anyone
     * else's, so an id that is not theirs opens as a new chat. There is nothing to swap in, so the
     * answer is an empty fragment.
     */
    public function drawer(\WP_REST_Request $request): \WP_REST_Response
    {
        $userId = $this->userId();
        if ($request->get_param('open') !== null) {
            $this->prefs->setDrawerOpen($userId, (bool) $request->get_param('open'));
        }
        if ($request->get_param('conversation_id') !== null) {
            $this->prefs->setDrawerConversation($userId, (int) $request->get_param('conversation_id'));
        }
        return self::html('');
    }

    /**
     * `GET /view/mcp-tools/{id}?index=`: the server's tools as the approval list of the settings
     * form, swapped in by htmx under that server's row (`index` is the row's index, which is what
     * the checkboxes have to post under).
     *
     * `manage_options` is asked twice, and the second time is the point. The route's own gate goes
     * through `alpaca_bot/capability/view/mcp-tools` like every other route here, and a filter
     * decides it; this callback then asks the capability again on its own account, the way
     * SettingsController::show() does for `?reveal=1`. Listing a server hands its ServerConfig,
     * the stored header value in it, to the client the ClientFactory builds, which is how the
     * credential reaches a remote host once a real client exists, so a site that loosened the
     * filter for a custom role must not have handed that role this. It shares the chat bucket's
     * rate limit (routes()).
     *
     * A server that cannot be listed is the fragment's own notice with a 200 rather than an error
     * status: the administrator asked a question, and "this server did not answer, and here is
     * what it said" is the answer. reason() says what the notice carries.
     */
    public function mcpTools(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        if ($this->discovery === null || !current_user_can('manage_options')) {
            return Errors::forbidden();
        }
        $server = $this->discovery->server((string) $request->get_param('id'));
        if ($server === null) {
            return Errors::notFound(__('MCP server', 'alpaca-bot'));
        }
        try {
            $tools = $this->discovery->tools($server);
            $error = '';
        } catch (McpUnavailable $e) {
            $tools = [];
            $error = self::reason($e, $server);
        }
        return self::html((new McpTools(max(0, (int) $request->get_param('index')), $tools, $error, $server->approved))->render());
    }

    /**
     * What the notice says for a server that could not be listed. McpUnavailable::NOT_YET, a
     * constant the library's absence is spelled with, becomes a translated sentence of the same
     * meaning. Any other message is untrusted text: a client may put a remote server's own words
     * in it. McpTools prints it escaped. Before that, every piece of the server's header value 8
     * characters or longer that is either the whole value or the part after its first run of
     * whitespace (the credential of `Bearer …`) is replaced with Schema::MASK wherever it appears,
     * and then the message is cut to REASON_CHARS characters, so a cut cannot leave the start of a
     * value behind. A shorter piece is not looked for, since replacing it would blank out
     * ordinary words; nor is a value that reaches the message changed (encoded, split, cut).
     */
    private static function reason(McpUnavailable $e, #[\SensitiveParameter] ServerConfig $server): string
    {
        $message = $e->getMessage();
        if ($message === McpUnavailable::NOT_YET) {
            return __('This server\'s tools cannot be listed yet: the MCP client arrives with php-agents 0.16.', 'alpaca-bot');
        }
        $value = $server->headerValue;
        $parts = preg_split('/\s+/', $value, 2);
        $secrets = array_filter([$value, is_array($parts) ? ($parts[1] ?? '') : ''], static fn(string $s): bool => mb_strlen($s) >= 8);
        $message = str_replace($secrets, Schema::MASK, $message);
        return mb_strlen($message) > self::REASON_CHARS ? mb_substr($message, 0, self::REASON_CHARS) . '…' : $message;
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
            echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Component output, escaped where it is built (Component::tag(), Markdown's wp_kses); escaping it again here would double-encode the markup.
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
