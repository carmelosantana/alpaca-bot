<?php

declare(strict_types=1);

namespace AlpacaBot\View\Chat;

use AlpacaBot\Chat\Conversation;
use AlpacaBot\Provider\ModelCatalog;
use AlpacaBot\Settings\Store;
use AlpacaBot\View\Component;
use AlpacaBot\View\Markdown;

/**
 * The whole chat screen inside core's `.wrap`: the icon sprite (inlined once, so every
 * Icon::svg() reference on the page resolves), the header, #ab-chat holding the status region
 * and the transcript, and the composer. The markup carries one figure for chat.ts: the open
 * conversation's id, on #ab-chat and #ab-messages, which it keeps current as turns start and
 * history swaps land. The REST root and the nonce reach it as `alpacaBot` (Admin\Assets,
 * wp_localize_script), not as attributes. There is no stream URL here: it is per turn, and
 * arrives with the ticket in the POST /chat response (chat.ts reads it from there, never from
 * the markup).
 *
 * The sprite is a build output (pnpm build) and gitignored, so a checkout without it must
 * still render: the icons are missing then, and nothing else is.
 */
final class Shell extends Component
{
    /**
     * @param list<array{id: int, title: string, created: int}> $history
     * @param int $postId the post being edited when the screen was opened from one, else 0
     * @param string|null $sprite path to the icon sprite; null means the plugin's own assets/img/icons.svg
     * @param string|null $model the model the select and the composer start on (the user's effective model, UserPrefs::modelFor()); null means the catalog's default
     */
    public function __construct(private Store $store, private ModelCatalog $catalog, private ?Conversation $conversation, private array $history, private int $postId = 0, private ?string $sprite = null, private ?string $model = null) {}

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
        );
        $list = new MessageList($messages, new Markdown(), $this->store, $who->userName, $who->userAvatar, $who->assistantAvatar, $id);
        $chat = $this->tag('div', ['id' => 'ab-chat', 'data-conversation' => (string) $id],
            $this->tag('div', ['id' => 'ab-status', 'class' => 'ab-status', 'role' => 'status', 'aria-live' => 'polite'], '') . $list->render());
        $composer = new Composer($this->store, $id, $model, $this->postId);

        return $this->tag('div', ['class' => 'wrap ab-wrap'], $this->sprite() . $header->render() . $chat . $composer->render());
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
