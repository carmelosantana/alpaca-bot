<?php

declare(strict_types=1);

namespace AlpacaBot\Rest;

use AlpacaBot\Chat\CapExceeded;
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
 * JSON once the callback is over, so handle() only redeems the ticket and returns it as a
 * response marked with a header; serve(), on `rest_pre_serve_request`, sees the marker, sends
 * the SSE headers in place of core's, and streams. Splitting it this way keeps the redemption
 * (the part with an outcome core can render, a 403) inside the permission and schema flow and
 * leaves stream() pure: a ticket in, frames out through a writer, which is what the tests run.
 */
final class StreamController extends Controller
{
    public const MARKER = 'X-Alpaca-Bot-Stream';

    public function __construct(private Pipeline $pipeline) {}

    /**
     * Not rate limited: the turn was counted on the POST that issued the ticket, and a streamed
     * turn must not cost two hits. The ticket itself, one-time and bound to its user, is what
     * stops this route being replayed.
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
     * Redeems the ticket: the token must name a stored ticket, the ticket must be the current
     * user's (the id core authenticated, never anything in the request) and for the
     * conversation in the URL, and it is deleted before anything runs, so a token is good
     * exactly once. Every refusal is the same 403: which of the three failed is nothing a
     * caller needs, and saying so would let one probe tokens.
     *
     * A token is alphanumeric (wp_generate_password without specials, as ChatController makes
     * it); one with anything else in it is refused before it becomes a transient key rather
     * than stripped, so that no other string can be made to name a ticket.
     */
    public function handle(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $token = (string) $request->get_param('token');
        $ticket = preg_match('/^[A-Za-z0-9]+$/', $token) === 1 ? get_transient(ChatController::STREAM_TRANSIENT . $token) : false;
        if (
            !is_array($ticket)
            || (int) ($ticket['user_id'] ?? -1) !== $this->userId()
            || (int) ($ticket['conversation_id'] ?? -1) !== (int) $request->get_param('id')
        ) {
            return Errors::forbidden(__('Invalid or expired stream token.', 'alpaca-bot'));
        }
        delete_transient(ChatController::STREAM_TRANSIENT . $token);
        $response = new \WP_REST_Response($ticket, 200);
        $response->header(self::MARKER, '1');
        return $response;
    }

    /**
     * `rest_pre_serve_request`: for a response handle() marked, and only then, takes over the
     * output. Core has already sent its headers (Content-Type: application/json, the no-cache
     * set) and the response's own, the marker included; the SSE headers replace what they
     * share a name with and the marker is withdrawn, then the frames are written straight to
     * the client. Returning true tells core the response has been sent.
     */
    public function serve(bool $served, \WP_HTTP_Response $result, \WP_REST_Request $request, \WP_REST_Server $server): bool
    {
        if ($served || empty($result->get_headers()[self::MARKER])) {
            return $served;
        }
        $server->remove_header(self::MARKER);
        foreach (Sse::headers() as $name => $value) {
            $server->send_header($name, $value);
        }
        Sse::prepareOutput();
        $this->stream((array) $result->get_data(), static function (string $frame): void {
            echo $frame;
            flush();
        });
        return true;
    }

    /**
     * Runs the ticket's turn and hands each frame to `$write`. The `start` frame rides on
     * `alpaca_bot/chat/started`, the pipeline's own signal that the conversation exists, with a
     * listener that lives exactly as long as this turn. A client that stops reading is noticed
     * after the frame that failed to reach it; the generator is then left undrained, which the
     * pipeline treats as an abandoned turn (the partial reply is stored, `chat/failed` fires).
     *
     * What the pipeline throws is mapped as ChatController maps it, through Errors, so the
     * policy is one: CapExceeded is the 402's data, an InvalidArgumentException the 400's, and
     * anything else the 502's, whose message is fixed and whose raw text reaches administrators
     * only. A provider that fails mid-reply has already had its deltas written; the error frame
     * follows them.
     *
     * @param array<string, mixed> $ticket as ChatController stored it: user_id, conversation_id, message, options
     * @param callable(string): void $write
     */
    public function stream(array $ticket, callable $write): void
    {
        // Not static: Brain Monkey names a hooked closure by binding it, which a static one refuses.
        $started = function (Conversation $conversation, string $model) use ($write): void {
            $write(Sse::frame('start', ['conversation_id' => $conversation->id, 'model' => $model]));
        };
        add_action('alpaca_bot/chat/started', $started, 10, 2);
        try {
            $turn = $this->pipeline->send((int) ($ticket['user_id'] ?? 0), (string) ($ticket['message'] ?? ''), (array) ($ticket['options'] ?? []));
            foreach ($turn as $delta) {
                $write(Sse::frame('delta', ['text' => $delta->text, 'reasoning' => $delta->reasoning]));
                if (connection_aborted()) {
                    return;
                }
            }
            $write(Sse::frame('done', ChatController::body($turn->getReturn())));
        } catch (CapExceeded $e) {
            $write(self::error(Errors::capExceeded($e)));
        } catch (\InvalidArgumentException $e) {
            $write(self::error(Errors::badRequest($e->getMessage())));
        } catch (\Throwable $e) {
            $write(self::error(Errors::provider($e)));
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
