<?php

declare(strict_types=1);

namespace AlpacaBot\Toolkit;

use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Contract\ToolkitInterface;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Tool\Parameter\EnumParameter;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Tool\Parameter\StringParameter;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Tool\Tool;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Tool\ToolResult;

/**
 * `draft_post(title, content, post_type?)`: a draft post or page for the user to review in the
 * editor. It never publishes, and there is no way to ask it to: the status is not a parameter,
 * a `post_status` the model sends anyway is not read, and the insert says `draft` outright. A
 * draft is the one status where a wrong or unwanted piece of writing costs nothing: nobody
 * sees it, and deleting it is one click. Publishing from a chat turn would put text the user
 * has not read on the site under their name.
 *
 * The acting user comes from a closure, not an int: Plugin::register() builds the toolkits on
 * plugins_loaded, before the current user is resolved, so an id taken at construction would be
 * 0 for every turn and the draft would be authored by nobody. The closure is
 * get_current_user_id(...) in the plugin's own wiring, and the capability is asked about the
 * id it answers, user_can($userId, …), never about whoever is logged in: the shortcode and
 * Abilities surfaces later in this phase can run a toolkit for a user who is not the current
 * one, and the check and the authorship have to be one id so they cannot disagree (the same
 * reason Context\CurrentScreenSource asks user_can()). The capability is the post type's own
 * (`edit_posts` for a post, `edit_pages` for a page), which is what the editor itself asks
 * before it shows a New button; wp_insert_post() checks nothing on its own.
 *
 * The title goes through sanitize_text_field() and the body through wp_kses_post(): what the
 * model wrote is treated exactly as what a user pasted into the editor would be, no more
 * trusted for having come from a model. Markdown is stored as it is; the editor shows it as
 * text, which is what a draft for review should show. Sanitising happens on the real value and
 * wp_slash() goes around the whole array afterwards, at the write: wp_insert_post() unslashes
 * what it is handed, and a draft is often exactly the thing that is full of backslashes — a
 * regular expression, a Windows path, a LaTeX fragment.
 *
 * The description and guidelines are English on purpose (see WebFetchToolkit); the errors are
 * translated.
 *
 * @since 0.5.0
 */
final class DraftPostToolkit implements ToolkitInterface
{
    /** The post types the tool may create, and the capability each needs. Anything else is refused by the parameter's enum before the callback runs. Public so Abilities\Register asks the same map, and the two cannot drift. */
    public const TYPES = ['post' => 'edit_posts', 'page' => 'edit_pages'];

    /** @param \Closure(): int $userId the acting user's id, resolved when the tool runs (see the class docblock) */
    public function __construct(private \Closure $userId) {}

    public function tools(): array
    {
        return [new Tool(
            'draft_post',
            'Create a DRAFT post or page on this WordPress site for the user to review and publish themselves. It never publishes. Returns the draft\'s id and its edit link.',
            [
                new StringParameter('title', 'The post title.'),
                new StringParameter('content', 'The post body, as HTML or Markdown.'),
                new EnumParameter('post_type', 'What to create: a blog post (the default) or a page.', ['post', 'page'], false),
            ],
            fn(array $args): ToolResult => $this->draft((string) $args['title'], (string) $args['content'], (string) ($args['post_type'] ?? 'post')),
        )];
    }

    public function guidelines(): string
    {
        return 'When the user asks you to write, create, or draft content for the site, use draft_post so they can review it in the editor before it goes live. Tell them it is a draft and give them the edit link. Never claim something was published.';
    }

    private function draft(string $title, string $content, string $type): ToolResult
    {
        $userId = ($this->userId)();
        if ($userId < 1) {
            return ToolResult::error(__('Nobody is logged in, so there is no one to own the draft.', 'alpaca-bot'));
        }
        $capability = self::TYPES[$type] ?? null;
        if ($capability === null || !user_can($userId, $capability)) {
            return ToolResult::error(__('You cannot create this kind of content on this site.', 'alpaca-bot'));
        }
        $id = wp_insert_post(wp_slash([
            'post_type' => $type,
            'post_status' => 'draft',
            'post_author' => $userId,
            'post_title' => sanitize_text_field($title),
            'post_content' => wp_kses_post($content),
        ]), true);
        if (is_wp_error($id)) {
            /* translators: %s: WordPress's reason the post could not be created */
            return ToolResult::error(sprintf(__('The draft could not be created: %s', 'alpaca-bot'), $id->get_error_message()));
        }
        return ToolResult::json([
            'id' => $id,
            'status' => 'draft',
            'post_type' => $type,
            'edit_url' => (string) get_edit_post_link($id, 'raw'),
        ]);
    }
}
