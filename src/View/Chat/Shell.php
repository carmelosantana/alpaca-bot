<?php

declare(strict_types=1);

namespace AlpacaBot\View\Chat;

use AlpacaBot\Admin\Menu;
use AlpacaBot\Chat\Conversation;
use AlpacaBot\Context\CurrentScreenSource;
use AlpacaBot\Provider\ModelCatalog;
use AlpacaBot\Settings\Store;
use AlpacaBot\View\Component;
use AlpacaBot\View\Markdown;

/**
 * The whole chat inside one wrapper, whose class `$home` and `$drawer` choose: the icon sprite
 * (inlined once, so every Icon::svg() reference on the page resolves), the header, #ab-chat
 * holding the status region and the transcript, and the composer. The markup carries one figure for chat.ts: the open
 * conversation's id, on #ab-chat and #ab-messages, which it keeps current as turns start and
 * history swaps land. The REST root and the nonce reach it as `alpacaBot` (Admin\Assets,
 * wp_localize_script), not as attributes. There is no stream URL here: it is per turn, and
 * arrives with the ticket in the POST /chat response (chat.ts reads it from there, never from
 * the markup).
 *
 * The sprite is a build output (pnpm build) and gitignored, so a checkout without it must
 * still render: the icons are missing then, and nothing else is.
 *
 * `$home` and `$drawer` say where the shell is. `$home` is the "New chat" link's href (Header):
 * null is the wp-admin chat screen, `admin.php?page=alpaca-bot`, and a URL is a front-end page
 * (Shortcodes\Chat passes the page's own permalink), so a visitor is not sent out of the site into
 * wp-admin. `$drawer` marks the admin-wide drawer's shell (View\Chat\Drawer), which the block
 * editor's sidebar mounts too, and which leaves `$home` null: its link points at the chat screen,
 * but neither host follows it, and each starts the new chat in place (resources/ts/drawer-start.ts,
 * resources/ts/editor-start.ts). The wrapper's class follows from the two, `$drawer` first: the
 * drawer's shell is `ab-wrap--drawer`; with `$home` null the shell is the chat screen's and keeps
 * core's `.wrap`, whose margins the screen is laid out inside; with a URL it is `ab-wrap--front`.
 * Neither modifier comes with `.wrap`. On a front-end page it is an admin class the front end does
 * not style, and a class name themes use for their own layout; in the drawer, core's `.wrap` rule
 * sets the admin screen's margins, and the drawer's panel is not that screen. The stylesheet's
 * front-end and drawer blocks are what the two modifiers select, and each replaces the height
 * .ab-wrap computes from wp-admin's chrome.
 */
final class Shell extends Component
{
    /**
     * @param list<array{id: int, title: string, created: int}> $history
     * @param int $postId the post being edited when the screen was opened from one, else 0
     * @param string|null $sprite path to the icon sprite; null means the plugin's own assets/img/icons.svg
     * @param string|null $model the model the select and the composer start on (the user's effective model, UserPrefs::modelFor()); null means the catalog's default
     * @param string|null $home the "New chat" link's href: null is the chat screen, a URL is a front-end page, and marks this shell as that page's (the class docblock)
     * @param bool $drawer whether this is the admin-wide drawer's shell (View\Chat\Drawer); it chooses the wrapper's class ahead of `$home` (the class docblock)
     * @param array{id: string, title: string}|null $screen the screen the drawer is on, as Context\CurrentScreenSource::screenFrom() cleaned it, for the composer's screen chip; null for none
     */
    public function __construct(private Store $store, private ModelCatalog $catalog, private ?Conversation $conversation, private array $history, private int $postId = 0, private ?string $sprite = null, private ?string $model = null, private ?string $home = null, private bool $drawer = false, private ?array $screen = null) {}

    public function render(): string
    {
        $id = $this->conversation === null ? 0 : $this->conversation->id;
        $title = $this->conversation === null ? '' : $this->conversation->title;
        $messages = $this->conversation === null ? [] : $this->conversation->messages;
        $model = $this->model ?? $this->catalog->defaultId($this->store);
        $who = Participants::current($this->store);

        $header = new Header(
            __('Alpaca Bot', 'alpaca-bot'),
            new ModelSelect($this->catalog->all(), $model, (bool) $this->store->get('chat.user_can_change_model')),
            new HistorySelect($this->history, $id, $title),
            $this->home ?? admin_url('admin.php?page=' . Menu::SLUG),
        );
        $list = new MessageList($messages, new Markdown(), $this->store, $who->userName, $who->userAvatar, $who->assistantAvatar, $id);
        $chat = $this->tag('div', ['id' => 'ab-chat', 'data-conversation' => (string) $id],
            $this->tag('div', ['id' => 'ab-status', 'class' => 'ab-status', 'role' => 'status', 'aria-live' => 'polite'], '') . $list->render());
        // The post chip names a post only for a user who may edit it; anyone else gets no chip,
        // and so no id on the turn. It names it by the stored title, which is what
        // Context\CurrentScreenSource tells the model, not by get_the_title(), whose filters
        // texturize it and which, outside wp-admin (GET /view/panel is a REST request), prefixes
        // a private post's with "Private: ". An auto-draft gets no chip either, as the source
        // sends none for it (CurrentScreenSource::UNSTARTED says why).
        $post = $this->postId > 0 && current_user_can('edit_post', $this->postId) ? get_post($this->postId) : null;
        if ($post !== null && $post->post_status === CurrentScreenSource::UNSTARTED) {
            $post = null;
        }
        $composer = new Composer($this->store, $id, $model, $post === null ? 0 : $this->postId, $post === null ? '' : wp_strip_all_tags($post->post_title), $this->screen);

        $class = match (true) {
            $this->drawer => 'ab-wrap ab-wrap--drawer',
            $this->home === null => 'wrap ab-wrap',
            default => 'ab-wrap ab-wrap--front',
        };
        return $this->tag('div', ['class' => $class], $this->sprite() . $header->render() . $chat . $composer->render());
    }

    private function sprite(): string
    {
        $path = $this->sprite ?? dirname(ALPACA_BOT_FILE) . '/assets/img/icons.svg';
        if (!is_readable($path)) {
            return '';
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads the plugin's own bundled sprite off local disk behind is_readable(), never a URL; wp_remote_get() is for remote fetches and WP_Filesystem is for user-writable paths, which this is not.
        $svg = file_get_contents($path);
        return $svg === false ? '' : $svg;
    }
}
