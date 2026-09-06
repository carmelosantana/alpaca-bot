<?php

declare(strict_types=1);

namespace AlpacaBot\Rest;

use AlpacaBot\Chat\ConversationStore;
use AlpacaBot\Chat\Message;
use AlpacaBot\Settings\Store;

/**
 * The current user's conversations: `GET|DELETE /conversations` for the collection,
 * `GET|DELETE /conversations/{id}` for one. Every call goes to ConversationStore as the current
 * user, and the store answers only for the owner, so another user's conversation reads as
 * missing (404) rather than forbidden: whether it exists is not theirs to learn.
 *
 * Shapes: the list is `[{id, title, created}]`, newest first; one conversation is
 * `{id, title, created, mode, messages: Message[]}`; a delete is `{deleted: true}` for one and
 * `{deleted: n}` for all.
 */
final class ConversationsController extends Controller
{
    /** The whole history when deleting it all; listFor() needs a bound, and nobody has this many. */
    private const ALL = 10_000;

    public function __construct(private ConversationStore $conversations, private Store $store) {}

    public function routes(): array
    {
        return [
            ['path' => '/conversations', 'methods' => 'GET', 'callback' => [$this, 'index'], 'capability' => 'edit_posts', 'args' => ['limit' => ['type' => 'integer', 'default' => 0]]],
            ['path' => '/conversations', 'methods' => 'DELETE', 'callback' => [$this, 'destroyAll'], 'capability' => 'edit_posts'],
            ['path' => '/conversations/(?P<id>\d+)', 'methods' => 'GET', 'callback' => [$this, 'show'], 'capability' => 'edit_posts'],
            ['path' => '/conversations/(?P<id>\d+)', 'methods' => 'DELETE', 'callback' => [$this, 'destroy'], 'capability' => 'edit_posts'],
        ];
    }

    /**
     * `limit` is the request's when it is one or more, else the site's `chat.history_limit`
     * (what the history screen shows); either way capped at the setting's own ceiling of 200.
     */
    public function index(\WP_REST_Request $request): \WP_REST_Response
    {
        $limit = (int) $request->get_param('limit');
        if ($limit < 1) {
            $limit = (int) $this->store->get('chat.history_limit');
        }
        return new \WP_REST_Response($this->conversations->listFor($this->userId(), max(1, min(200, $limit))));
    }

    public function show(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $c = $this->conversations->load((int) $request->get_param('id'), $this->userId());
        if ($c === null) {
            return Errors::notFound(__('Conversation', 'alpaca-bot'));
        }
        return new \WP_REST_Response([
            'id' => $c->id,
            'title' => $c->title,
            'created' => $c->created,
            'mode' => $c->mode,
            'messages' => array_map(static fn(Message $m): array => $m->toArray(), $c->messages),
        ]);
    }

    public function destroy(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        return $this->conversations->delete((int) $request->get_param('id'), $this->userId())
            ? new \WP_REST_Response(['deleted' => true])
            : Errors::notFound(__('Conversation', 'alpaca-bot'));
    }

    public function destroyAll(\WP_REST_Request $request): \WP_REST_Response
    {
        $userId = $this->userId();
        $deleted = 0;
        foreach ($this->conversations->listFor($userId, self::ALL) as $row) {
            $deleted += $this->conversations->delete($row['id'], $userId) ? 1 : 0;
        }
        return new \WP_REST_Response(['deleted' => $deleted]);
    }
}
