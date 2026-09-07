<?php

declare(strict_types=1);

namespace AlpacaBot\Rest;

use AlpacaBot\Chat\Conversation;
use AlpacaBot\Chat\Pipeline;

/**
 * `GET /chat/{conversation}/stream?token=…`: runs the turn a stream ticket describes and writes
 * it to the client as server-sent events. The ticket is what `POST /chat` with `stream: true`
 * stored (ChatController::ticket()); this route is the other half of that exchange.
 *
 * Events, in order: `start` `{conversation_id, model}` once the pipeline has the conversation
 * (created on the spot for a ticket that named 0, which is why the id is sent here rather than
 * only at the end: a client that shows a link to the conversation needs it before the text);
 * `delta` `{text, reasoning}` per fragment; then either `done`, whose data is exactly the body
 * a direct `POST /chat` answers with, or `error`, whose data is exactly the body a JSON route's
 * WP_Error would render as (`{code, message, data}`), so a client has one error shape for both
 * paths. After `done` or `error` the connection closes.
 *
 * The turn does not run inside the route callback. Core renders a callback's return value as
 * JSON once the callback is over, so handle() only redeems the ticket, keeps it here against
 * the request it was redeemed for, and returns an empty 200; serve(), on
 * `rest_pre_serve_request`, recognises that request, sends the SSE headers in place of core's,
 * and streams. Splitting it this way keeps the redemption (the part with an outcome core can
 * render, a 403) inside the permission and schema flow and leaves stream() pure: a ticket in,
 * frames out through a writer, which is what the tests run.
 */
final class StreamController extends Controller
{
    /** @var array{request: \WP_REST_Request, ticket: array<string, mixed>}|null the ticket handle() redeemed and the request it redeemed it for, until serve() streams it */
    private ?array $redeemed = null;

    /** @var \Closure(): void what serve() runs between sending its headers and writing the first frame */
    private \Closure $prepareOutput;

    /**
     * `$prepareOutput` defaults to Sse::prepareOutput(), which ends every output buffer in the
     * process; a test runner that owns those buffers hands in something gentler.
     *
     * @param (callable(): void)|null $prepareOutput
     */
    public function __construct(private Pipeline $pipeline, ?callable $prepareOutput = null)
    {
        $this->prepareOutput = $prepareOutput === null ? Sse::prepareOutput(...) : $prepareOutput(...);
    }

    /**
     * Not rate limited: the turn was counted on the POST that issued the ticket, and a streamed
     * turn must not cost two hits. The ticket is what stops this route being replayed instead:
     * bound to its user, and redeemable exactly once even when the same token arrives many
     * times at once (handle() says how), so one POST, one hit, one turn.
     */
    public function routes(): array
    {
        return [[
            'path' => '/chat/(?P<id>\d+)/stream',
            'methods' => 'GET',
            'callback' => [$this, 'handle'],
            'capability' => 'edit_posts',
            'args' => ['token' => ['type' => 'string', 'required' => true]],
        ]];
    }

    /**
     * The route and the hook that serves it are registered together, on rest_api_init, so a
     * site that drops this controller through `alpaca_bot/rest/controllers` loses both. Last
     * (PHP_INT_MAX): anything hooked earlier may still send a header or serve the request
     * itself, and once the first frame is out a header() call can only warn.
     */
    public function register(): void
    {
        parent::register();
        add_filter('rest_pre_serve_request', [$this, 'serve'], PHP_INT_MAX, 4);
    }

    /**
     * Redeems the ticket. GET only: core sends a HEAD to the GET handler of a route with no
     * HEAD handler and drops the body only after rest_pre_serve_request, so a HEAD taken here
     * would run a billed turn for a probe; it is refused (405, Allow: GET) before the ticket is
     * read, and survives for the GET that follows.
     *
     * The token must name a stored ticket, the ticket must be the current user's (the id core
     * authenticated, never anything in the request) and for the conversation in the URL, and
     * then the deletion is the claim: delete_transient() reports whether this call removed the
     * stored ticket, and only a call it says yes to goes on. Without a persistent object cache
     * a transient is an options row and that report is the affected-row count of
     * `DELETE … WHERE option_name = %s`, which the unique key on option_name makes exactly one
     * of any number of concurrent deletes; with one it is the cache's own delete, which the
     * mainstream drop-ins (Redis DEL, Memcached delete, APCu) likewise report as false for a key
     * already gone. So of N requests holding one token, N-1 read the ticket, pass the checks and
     * are still refused, and the token runs one turn. Reading before deleting is what keeps a
     * token that is not the caller's untouched: a refused request deletes nothing.
     *
     * Every refusal is the same 403: which check failed is nothing a caller needs, and saying
     * so would let one probe tokens. A token is alphanumeric (wp_generate_password without
     * specials, as ChatController makes it); one with anything else in it is refused before it
     * becomes a transient key rather than stripped, so that no other string can be made to
     * name a ticket.
     */
    public function handle(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        if ($request->get_method() !== 'GET') {
            $response = rest_convert_error_to_response(Errors::methodNotAllowed());
            $response->header('Allow', 'GET');
            return $response;
        }
        $token = (string) $request->get_param('token');
        $key = ChatController::STREAM_TRANSIENT . $token;
        $ticket = preg_match('/^[A-Za-z0-9]+$/', $token) === 1 ? get_transient($key) : false;
        if (
            !is_array($ticket)
            || (int) ($ticket['user_id'] ?? -1) !== $this->userId()
            || (int) ($ticket['conversation_id'] ?? -1) !== (int) $request->get_param('id')
            || !delete_transient($key)
        ) {
            return Errors::forbidden(__('Invalid or expired stream token.', 'alpaca-bot'));
        }
        $this->redeemed = ['request' => $request, 'ticket' => $ticket];
        return new \WP_REST_Response(null, 200);
    }

    /**
     * `rest_pre_serve_request`: for the request handle() redeemed a ticket for, and only that
     * one, takes over the output. The match is on the request object itself, which core carries
     * unchanged from dispatch to this filter, rather than on anything in the response: core
     * builds a new response for `?_envelope=1` (and any `rest_post_dispatch` filter may), and
     * a marker set on the one handle() returned would be gone with it while the ticket had
     * already been spent. Every other request (a refusal, which core renders itself; OPTIONS,
     * which never reaches the callback; another route's) is left to whatever was going to
     * serve it.
     *
     * Core has already sent its headers (Content-Type: application/json, the no-cache set); the
     * SSE headers replace what they share a name with, then the frames are written straight to
     * the client. Returning true tells core the response has been sent. A request something
     * hooked earlier has served (`$served`) is not written over, even though its ticket is
     * spent: two bodies on one response help nobody.
     */
    public function serve(bool $served, \WP_HTTP_Response $result, \WP_REST_Request $request, \WP_REST_Server $server): bool
    {
        if ($served || $this->redeemed === null || $this->redeemed['request'] !== $request) {
            return $served;
        }
        $ticket = $this->redeemed['ticket'];
        $this->redeemed = null;
        foreach (Sse::headers() as $name => $value) {
            $server->send_header($name, $value);
        }
        ($this->prepareOutput)();
        $this->stream($ticket, static function (string $frame): void {
            echo $frame;
            flush();
        });
        return true;
    }

    /**
     * Runs the ticket's turn and hands each frame to `$write`. The `start` frame rides on
     * `alpaca_bot/chat/started`, the pipeline's own signal that the conversation exists, with a
     * listener that lives exactly as long as this turn. `$aborted` says whether the client has
     * gone; it defaults to PHP's connection_aborted(), which learns that only from a write that
     * failed, so it is asked after each frame. A client that has gone is noticed after the
     * frame that did not reach it; the generator is then left undrained, which the pipeline
     * treats as an abandoned turn (the partial reply is stored, `chat/failed` fires).
     *
     * What the pipeline throws goes through Errors::fromPipeline(), the same call the buffered
     * route makes, so the policy is one function rather than one convention: CapExceeded is the
     * 402's data, an InvalidArgumentException the 400's, and anything else the 502's, whose
     * message is fixed and whose raw text reaches administrators only. A provider that fails
     * mid-reply has already had its deltas written; the error frame follows them.
     *
     * @param array<string, mixed> $ticket as ChatController stored it: user_id, conversation_id, message, options
     * @param callable(string): void $write
     * @param (callable(): bool)|null $aborted
     */
    public function stream(array $ticket, callable $write, ?callable $aborted = null): void
    {
        $aborted ??= static fn(): bool => connection_aborted() !== 0;
        // Not static: Brain Monkey names a hooked closure by binding it, which a static one refuses.
        $started = function (Conversation $conversation, string $model) use ($write): void {
            $write(Sse::frame('start', ['conversation_id' => $conversation->id, 'model' => $model]));
        };
        add_action('alpaca_bot/chat/started', $started, 10, 2);
        try {
            $turn = $this->pipeline->send((int) ($ticket['user_id'] ?? 0), (string) ($ticket['message'] ?? ''), (array) ($ticket['options'] ?? []));
            foreach ($turn as $delta) {
                $write(Sse::frame('delta', ['text' => $delta->text, 'reasoning' => $delta->reasoning]));
                if ($aborted()) {
                    return;
                }
            }
            $write(Sse::frame('done', ChatController::body($turn->getReturn())));
        } catch (\Throwable $e) {
            $write(self::error(Errors::fromPipeline($e)));
        } finally {
            remove_action('alpaca_bot/chat/started', $started, 10);
        }
    }

    /** An error frame carrying the WP_Error as core would render it in a JSON body. */
    private static function error(\WP_Error $error): string
    {
        return Sse::frame('error', [
            'code' => $error->get_error_code(),
            'message' => $error->get_error_message(),
            'data' => $error->get_error_data(),
        ]);
    }
}
