<?php

declare(strict_types=1);

namespace AlpacaBot\Context;

/**
 * Supplies the post the user is editing, so "tighten this intro" means the intro on screen.
 *
 * The client names the post (`post_id`); the server decides whether to show it. Content is
 * included only when the id is a positive integer and `user_can($userId, 'edit_post', $id)`,
 * which is the same gate the editor itself sits behind — asked about the user the turn is
 * for, not whoever is logged in, so an elevated caller collecting on someone else's behalf
 * (WP-CLI with --user, a cron summariser, an admin "view as") cannot hand them a post they
 * could not open. Anything else in the request — a missing id, 0, a negative, a string with
 * junk in it, an array, a bool, a float — is treated as "no post" without touching WordPress
 * at all. A post whose status is `auto-draft` (UNSTARTED) is no post either, which is known
 * only once it is loaded. post-new.php stores such a post before its form is shown, titled
 * "Auto Draft" for a type with titles, and blanks the title only on the copy core keeps in
 * memory, so the stored row would tell the model it is editing a post called "Auto Draft". Its
 * first save or autosave gives it another status (wp_autosave() makes it a `draft`), and from
 * then on it is a post like any other.
 *
 * The title goes into the label, and the label is a `## ` heading in the system block, so
 * line breaks in it are collapsed to a space: a title cannot start a second heading.
 *
 * It also supplies the screen the chat was opened on, when the client names one: `screen` as
 * `{id, title}` becomes "On: {title}", a context with no text. That is the whole of what a screen
 * contributes: no list-table rows, no queries, no option values (Kanboard #4369), because an
 * options page holds secrets and every word of this goes to a provider. It is not gated. That
 * is not because the title is harmless: it can carry what the screen shows (edit-comments.php
 * titles one post's comments with the post's title, and a plugin may title its own page with a
 * record's name). It is because the id and the title are the caller's own words, as the message
 * is. The one caller in the plugin that passes a `screen` is POST /chat, which takes `context`
 * from the request of the user the turn is for, so whatever the title says, that user sent it,
 * and there is nothing of anyone else's in it for a capability to keep from them. The title is
 * cleaned by the rule the post title is, and for the same reason (screenFrom() says how far that
 * goes).
 *
 * Content is tag-stripped first and only then cut at MAX_CHARS, so markup can never crowd
 * the words out of the window, and the model sees prose rather than block comments.
 */
final class CurrentScreenSource implements ContextSourceInterface
{
    private const int MAX_CHARS = 4000;

    /** The status core gives the post it makes for an Add New screen, until that post is first saved or autosaved: no post yet (the class docblock). */
    public const string UNSTARTED = 'auto-draft';

    /** The most characters of a screen title that reach the label, the ellipsis of a cut one included. */
    public const int TITLE_CHARS = 120;

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
        $out = [];
        $post = $this->post($userId, $request['post_id'] ?? null);
        if ($post !== null) {
            $out[] = $post;
        }
        $screen = self::screenFrom($request['screen'] ?? null);
        if ($screen !== null) {
            // No text: the screen is a place, not a document, and the label is the whole of it.
            $out[] = new Context("screen:{$screen['id']}", "On: {$screen['title']}", '', ['screen' => $screen['id']]);
        }
        return $out;
    }

    /**
     * The screen the chat was opened on, as `{id, title}` cleaned, or null when the value is not
     * one: anything but an array whose `id` and `title` are strings (a bare string, which is what
     * `screen` was in 0.5, a list, a missing or non-string member), or one that cleans to an
     * empty id or an empty title.
     *
     * The title is untrusted text: a page title any plugin can register, arriving through the REST
     * request because the turn is not made on the screen. It goes through line(), the rule the post
     * title goes through, and is then cut to TITLE_CHARS characters with the ellipsis inside the
     * cap and no space before it. A title that is not valid UTF-8 cleans to empty, and so is no screen. The id is reduced
     * to lowercase letters, digits, `_` and `-`, at most 64 of them, so it can be written into a
     * Context id and an attribute as it stands.
     *
     * Public because `GET /view/panel` cleans the two strings with it before rendering the chip
     * whose fields the turn sends back. A cleaned screen cleans to itself, so the two ends agree.
     *
     * @return array{id: string, title: string}|null
     */
    public static function screenFrom(mixed $value): ?array
    {
        if (!is_array($value) || !is_string($value['id'] ?? null) || !is_string($value['title'] ?? null)) {
            return null;
        }
        $id = substr((string) preg_replace('/[^a-z0-9_-]/', '', strtolower($value['id'])), 0, 64);
        $title = self::line($value['title']);
        if ($id === '' || $title === '') {
            return null;
        }
        if (mb_strlen($title) > self::TITLE_CHARS) {
            // Trimmed first, so a cut that lands after a space does not end "… …".
            $title = rtrim(mb_substr($title, 0, self::TITLE_CHARS - 1)) . '…';
        }
        return ['id' => $id, 'title' => $title];
    }

    /** The post being edited, as a context, when the class docblock's gate lets it through; else null. */
    private function post(int $userId, mixed $value): ?Context
    {
        $postId = self::postId($value);
        if ($postId === 0 || !user_can($userId, 'edit_post', $postId)) {
            return null;
        }
        $post = get_post($postId);
        if ($post === null || $post->post_status === self::UNSTARTED) {
            return null;
        }
        $text = trim(wp_strip_all_tags($post->post_content));
        if (mb_strlen($text) > self::MAX_CHARS) {
            $text = mb_substr($text, 0, self::MAX_CHARS) . '…';
        }
        return new Context(
            "current-screen:{$post->post_type}:{$postId}",
            sprintf('Editing: %s (%s, %s)', self::line($post->post_title), $post->post_type, $post->post_status),
            $text,
            ['post_id' => $postId],
        );
    }

    /**
     * A title as one line of text for a label: tags stripped, every run of whitespace made one
     * space, and the ends trimmed. The `u` flag is what makes `\s` match Unicode's line breaks
     * (NEL, the line separator, the paragraph separator) as well as ASCII's; without it they pass
     * through. It also makes the pattern refuse a string that is not valid UTF-8 whole, which
     * comes out as ''.
     */
    private static function line(string $title): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', wp_strip_all_tags($title)));
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
