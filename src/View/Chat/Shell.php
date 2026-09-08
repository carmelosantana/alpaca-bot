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
 * and the transcript, and the composer. #ab-chat's data attributes are what chat.ts reads at
 * boot: the open conversation, the REST root, and how many conversations the history lists.
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
     */
    public function __construct(private Store $store, private ModelCatalog $catalog, private ?Conversation $conversation, private array $history, private string $nonce, private int $postId = 0, private ?string $sprite = null) {}

    public function render(): string
    {
        $id = $this->conversation === null ? 0 : $this->conversation->id;
        $messages = $this->conversation === null ? [] : $this->conversation->messages;
        $model = $this->catalog->defaultId($this->store);
        $user = wp_get_current_user();
        $userAvatar = get_avatar_url($user->ID);
        $assistantAvatar = (string) $this->store->get('chat.assistant_avatar');
        if ($assistantAvatar === '') {
            $assistantAvatar = plugins_url('assets/img/icon-80.png', ALPACA_BOT_FILE);
        }

        $header = new Header(
            $this->t('Alpaca Bot'),
            new ModelSelect($this->catalog->all(), $model, (bool) $this->store->get('chat.user_can_change_model')),
            new HistorySelect($this->history, $id),
        );
        $list = new MessageList($messages, new Markdown(), $this->store, $user->display_name, is_string($userAvatar) ? $userAvatar : '', $assistantAvatar, $id);
        $chat = $this->tag('div', ['id' => 'ab-chat', 'data-conversation' => (string) $id, 'data-rest' => rest_url('alpaca-bot/v1'), 'data-history-limit' => (string) (int) $this->store->get('chat.history_limit')],
            $this->tag('div', ['id' => 'ab-status', 'class' => 'ab-status', 'role' => 'status', 'aria-live' => 'polite'], '') . $list->render());
        $composer = new Composer($this->store, $this->nonce, $id, $model, $this->postId);

        return $this->tag('div', ['class' => 'wrap ab-wrap'], $this->sprite() . $header->render() . $chat . $composer->render());
    }

    private function sprite(): string
    {
        $path = $this->sprite ?? dirname(ALPACA_BOT_FILE) . '/assets/img/icons.svg';
        if (!is_readable($path)) {
            return '';
        }
        $svg = file_get_contents($path);
        return $svg === false ? '' : $svg;
    }
}
