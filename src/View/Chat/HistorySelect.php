<?php

declare(strict_types=1);

namespace AlpacaBot\View\Chat;

use AlpacaBot\View\Component;
use AlpacaBot\View\Hx;

/**
 * The conversation dropdown in the header, wrapped so the whole thing can be reloaded.
 *
 * Two requests live here. The wrapper (#ab-history) reloads itself from /view/history when
 * chat.ts fires `ab:refresh` on the body after a turn completes, so a new conversation shows
 * up in the list. The select loads the chosen transcript into #ab-messages on change.
 *
 * htmx cannot build a URL from a select's value, and no htmx attribute may be written from
 * TypeScript (Hx is the one producer), so the select asks for `/messages/0` and each option
 * carries the conversation id as `data-id`: chat.ts listens for `htmx:configRequest` on the
 * select and rewrites `evt.detail.path`, swapping the trailing 0 for the selected option's
 * `data-id`. Nothing else about the request changes.
 *
 * The wrapper's request needs to know the open conversation, which changes without a page load
 * (a new chat gets its id on the first turn), so it includes (Hx `include`) the composer's
 * hidden `conversation_id`, the field chat.ts keeps current for the next send. The select
 * inherits the include and sends the same field with `/messages/0`, where it is unread.
 *
 * `$history` is capped by chat.history_limit, so the open conversation may not be in it (an
 * older one opened by id). It gets its own option then, right after "New chat": with no option
 * selected the browser would show "New chat" while the transcript and the composer's hidden
 * conversation_id both say otherwise.
 */
final class HistorySelect extends Component
{
    /**
     * @param list<array{id: int, title: string, created: int}> $history newest first, as ConversationStore::listFor() returns them
     * @param string $currentTitle the open conversation's title, used only when $history does not list it
     */
    public function __construct(private array $history, private int $current, private string $currentTitle = '') {}

    public function render(): string
    {
        $opts = $this->option(0, __('New chat', 'alpaca-bot'));
        if ($this->current !== 0 && !in_array($this->current, array_map(static fn(array $row): int => (int) $row['id'], $this->history), true)) {
            $opts .= $this->option($this->current, $this->currentTitle);
        }
        foreach ($this->history as $row) {
            $opts .= $this->option((int) $row['id'], (string) $row['title']);
        }
        $select = sprintf(
            '<select id="ab-history-select" name="conversation" class="ab-select"%s>%s</select>',
            Hx::attrs(['get' => '/messages/0', 'trigger' => 'change', 'target' => '#ab-messages', 'swap' => 'outerHTML', 'headers' => Hx::formHeaders()]),
            $opts,
        );
        return sprintf(
            '<div id="ab-history" class="ab-history"%s><label class="screen-reader-text" for="ab-history-select">%s</label>%s</div>',
            Hx::attrs(['get' => '/history', 'trigger' => 'ab:refresh from:body', 'target' => 'this', 'swap' => 'outerHTML', 'include' => '#ab-form [name=conversation_id]', 'headers' => Hx::formHeaders()]),
            $this->e(__('History', 'alpaca-bot')),
            $select,
        );
    }

    private function option(int $id, string $title): string
    {
        $title = $title !== '' ? $title : __('Untitled', 'alpaca-bot');
        return sprintf('<option value="%1$d" data-id="%1$d"%2$s>%3$s</option>', $id, $id === $this->current ? ' selected' : '', $this->e($title));
    }
}
