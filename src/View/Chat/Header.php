<?php

declare(strict_types=1);

namespace AlpacaBot\View\Chat;

use AlpacaBot\Admin\Menu;
use AlpacaBot\View\Component;

/**
 * The screen's title row in core's own chrome, laid out as 0.4 had it: the heading, a
 * "New chat" page-title-action (a plain link back to the bare screen), then the model and
 * history selects. The `wp-header-end` rule is where core moves admin notices to.
 */
final class Header extends Component
{
    public function __construct(private string $title, private ModelSelect $models, private HistorySelect $history) {}

    public function render(): string
    {
        return $this->tag('div', ['class' => 'ab-header'],
            $this->tag('h1', ['class' => 'wp-heading-inline'], $this->e($this->title))
            . $this->tag('a', ['href' => $this->u(admin_url('admin.php?page=' . Menu::SLUG)), 'class' => 'page-title-action'], $this->e($this->t('New chat')))
            . $this->tag('div', ['class' => 'ab-header__controls'], $this->models->render() . $this->history->render())
            . $this->tag('hr', ['class' => 'wp-header-end']));
    }
}
