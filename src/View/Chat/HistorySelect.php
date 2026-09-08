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
 */
final class HistorySelect extends Component
{
    /** @param list<array{id: int, title: string, created: int}> $history newest first, as ConversationStore::listFor() returns them */
    public function __construct(private array $history, private int $current) {}

    public function render(): string
    {
        $opts = $this->option(0, $this->t('New chat'));
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
            Hx::attrs(['get' => '/history', 'trigger' => 'ab:refresh from:body', 'target' => 'this', 'swap' => 'outerHTML', 'headers' => Hx::formHeaders()]),
            $this->e($this->t('History')),
            $select,
        );
    }

    private function option(int $id, string $title): string
    {
        $title = $title !== '' ? $title : $this->t('Untitled');
        return sprintf('<option value="%1$d" data-id="%1$d"%2$s>%3$s</option>', $id, $id === $this->current ? ' selected' : '', $this->e($title));
    }
}
