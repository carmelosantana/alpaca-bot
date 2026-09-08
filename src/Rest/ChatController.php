<?php

declare(strict_types=1);

namespace AlpacaBot\Rest;

use AlpacaBot\Chat\Pipeline;
use AlpacaBot\Chat\Result;
use AlpacaBot\Context\Context;

/**
 * `POST /chat`: one turn through the Pipeline as the current user.
 *
 * Body `{message?, conversation_id?, model?, images?, context?, stream?}`; `message` may be left
 * out for an images-only turn (the schema cannot say "one of the two", so the controller checks
 * that at least one is there and answers 400 in its own words). With `stream` off the
 * turn runs here and the response is `{conversation_id, message, receipt, contexts}` (200). With
 * `stream` on nothing runs: the response is a ticket, `{conversation_id, token, stream_url}`
 * (202), and the client opens `stream_url` (Task 4's SSE route) to have the turn run there.
 *
 * The ticket exists because a browser's EventSource can only GET, with no body and no custom
 * headers, so the message and options have to be handed over some other way: they are stored
 * under the token (transient `alpaca_bot_stream_{token}`, STREAM_TTL seconds) together with
 * the user id that asked, and the stream route runs the turn as that user, for that user only.
 * This POST is where the rate limit is counted for a streamed turn as well as a direct one.
 *
 * Every refusal is a WP_Error with Errors' shapes; what the pipeline throws is mapped, not
 * leaked, by Errors::fromPipeline(): CapExceeded is 402, an InvalidArgumentException (the
 * caller's mistake, in the pipeline's own words) is 400, anything else is a 502 provider error.
 * The stream route maps its turn through the same function, so the two cannot drift.
 */
final class ChatController extends Controller
{
    public const STREAM_TTL = 120;
    public const STREAM_TRANSIENT = 'alpaca_bot_stream_';

    public function __construct(private Pipeline $pipeline) {}

    public function routes(): array
    {
        return [[
            'path' => '/chat',
            'methods' => 'POST',
            'callback' => [$this, 'create'],
            'capability' => 'edit_posts',
            'rate_limit' => true,
            'args' => [
                'message' => ['type' => 'string', 'required' => false, 'default' => ''],
                'conversation_id' => ['type' => 'integer', 'default' => 0],
                'model' => ['type' => 'string', 'default' => ''],
                'images' => ['type' => 'array', 'items' => ['type' => 'string'], 'default' => []],
                'context' => ['type' => 'object', 'default' => []],
                'stream' => ['type' => 'boolean', 'default' => false],
            ],
        ]];
    }

    public function create(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $message = trim((string) $request->get_param('message'));
        // Only strings go through; the pipeline refuses anything that is not a base64 image data URL, and a set past the site's allowance.
        $images = array_values(array_filter((array) $request->get_param('images'), 'is_string'));
        // An images-only turn is a turn, as it is for the pipeline.
        if ($message === '' && $images === []) {
            return Errors::badRequest(__('The message is empty.', 'alpaca-bot'));
        }
        $options = [
            'conversation_id' => max(0, (int) $request->get_param('conversation_id')),
            'model' => sanitize_text_field((string) $request->get_param('model')),
            'images' => $images,
            'context' => (array) $request->get_param('context'),
        ];
        if ((bool) $request->get_param('stream')) {
            return $this->ticket($message, $options);
        }
        try {
            $result = $this->pipeline->complete($this->userId(), $message, $options);
        } catch (\Throwable $e) {
            return Errors::fromPipeline($e);
        }
        return new \WP_REST_Response(self::body($result), 200);
    }

    /**
     * The body of a finished turn: the 200 here, and the `done` frame's data on the stream
     * route, which is the same turn told a different way and must read the same.
     *
     * @return array{conversation_id: int, message: array<string, mixed>, receipt: array<string, int|string>, contexts: list<array<string, mixed>>}
     */
    public static function body(Result $result): array
    {
        return [
            'conversation_id' => $result->conversation->id,
            'message' => $result->reply->toArray(),
            'receipt' => $result->receipt,
            'contexts' => array_map(static fn(Context $c): array => $c->toArray(), $result->contexts),
        ];
    }

    /**
     * The stream ticket for a turn: stored under a fresh token, answered as 202 with the URL to
     * open. `conversation_id` is 0 for a new conversation, which the stream route then creates
     * (the pipeline does that on the first advance), so the URL names conversation 0 and the
     * real id arrives over the stream.
     *
     * Nothing is validated against the pipeline here (ownership of the conversation, the model,
     * the cap): the stream route runs the turn and reports what the pipeline refuses as its
     * first event. Checking twice would only move some of those answers to this response.
     *
     * @param array{conversation_id: int, model: string, images: list<string>, context: array<string, mixed>} $options
     */
    private function ticket(string $message, array $options): \WP_REST_Response
    {
        $token = wp_generate_password(32, false);
        $cid = $options['conversation_id'];
        set_transient(self::STREAM_TRANSIENT . $token, [
            'user_id' => $this->userId(),
            'conversation_id' => $cid,
            'message' => $message,
            'options' => $options,
        ], self::STREAM_TTL);
        // add_query_arg rather than "?token=": with plain permalinks rest_url() already carries
        // a query string (?rest_route=...), and the token has to join it, not start another.
        $url = add_query_arg('token', $token, rest_url(self::NAMESPACE . "/chat/{$cid}/stream"));
        return new \WP_REST_Response(['conversation_id' => $cid, 'token' => $token, 'stream_url' => $url], 202);
    }
}
