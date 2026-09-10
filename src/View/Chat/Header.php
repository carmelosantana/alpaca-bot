<?php

declare(strict_types=1);

namespace AlpacaBot\View\Chat;

use AlpacaBot\View\Component;

/**
 * The screen's title row in core's own chrome, laid out as 0.4 had it: the heading, a
 * "New chat" page-title-action (a plain link back to the bare screen), then the model and
 * history selects. The `wp-header-end` rule is where core moves admin notices to.
 *
 * `$newChat` is where "New chat" goes, and the caller's to decide, because the shell is not
 * only the wp-admin screen any more: `[alpacabot]` renders it on a front-end page (Shell says
 * how it resolves the URL), and an admin_url() written in here sent a visitor of that page out
 * of the site into wp-admin. chat.ts reads this link's href for the history select's own
 * "New chat" option, so the two follow one value.
 */
final class Header extends Component
{
    public function __construct(private string $title, private ModelSelect $models, private HistorySelect $history, private string $newChat) {}

    public function render(): string
    {
        return $this->tag('div', ['class' => 'ab-header'],
            $this->tag('h1', ['class' => 'wp-heading-inline'], $this->e($this->title))
            . $this->tag('a', ['href' => $this->u($this->newChat), 'class' => 'page-title-action'], $this->e(__('New chat', 'alpaca-bot')))
            . $this->tag('div', ['class' => 'ab-header__controls'], $this->models->render() . $this->history->render())
            . $this->tag('hr', ['class' => 'wp-header-end']));
    }
}
