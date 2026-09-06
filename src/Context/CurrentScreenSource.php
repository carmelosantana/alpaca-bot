<?php

declare(strict_types=1);

namespace AlpacaBot\Context;

/**
 * Supplies the post the user is editing, so "tighten this intro" means the intro on screen.
 *
 * The client names the post (`post_id`); the server decides whether to show it. Content is
 * included only when the id is a positive integer and `current_user_can('edit_post', $id)`,
 * which is the same gate the editor itself sits behind. Anything else in the request —
 * a missing id, 0, a negative, a string with junk in it, an array, a bool, a float — is
 * treated as "no post" without touching WordPress at all.
 *
 * Content is tag-stripped first and only then cut at MAX_CHARS, so markup can never crowd
 * the words out of the window, and the model sees prose rather than block comments.
 */
final class CurrentScreenSource implements ContextSourceInterface
{
    private const int MAX_CHARS = 4000;

    public function id(): string
    {
        return 'current-screen';
    }

    /**
     * @param array<string, mixed> $request
     * @return Context[]
     */
    public function collect(int $userId, array $request): array
    {
        $postId = self::postId($request['post_id'] ?? null);
        if ($postId === 0 || !current_user_can('edit_post', $postId)) {
            return [];
        }
        $post = get_post($postId);
        if ($post === null) {
            return [];
        }
        $text = trim(wp_strip_all_tags($post->post_content));
        if (mb_strlen($text) > self::MAX_CHARS) {
            $text = mb_substr($text, 0, self::MAX_CHARS) . '…';
        }
        return [new Context(
            "current-screen:{$post->post_type}:{$postId}",
            sprintf('Editing: %s (%s, %s)', $post->post_title, $post->post_type, $post->post_status),
            $text,
            ['post_id' => $postId],
        )];
    }

    /**
     * The post id as the client sent it, reduced to a positive int or 0.
     *
     * Only an int or a string of digits counts. A plain `(int)` cast would turn '12abc' into
     * 12, an array into 1 and true into 1 — each a post the user never named.
     */
    private static function postId(mixed $value): int
    {
        if (is_string($value) && preg_match('/^[1-9][0-9]{0,17}$/', $value) === 1) {
            $value = (int) $value;
        }
        return is_int($value) && $value > 0 ? $value : 0;
    }
}
