<?php

declare(strict_types=1);

namespace AlpacaBot\View\Chat;

use AlpacaBot\Provider\Model;
use AlpacaBot\View\Component;
use AlpacaBot\View\Hx;

/**
 * The model dropdown in the header. Changing it posts the choice to /view/default-model, which
 * stores it as the user's default (Kanboard #565) and answers with a Notice for #ab-status.
 * When the site does not let users change the model the select is disabled, and a disabled
 * select fires no change event, so the request attributes it still carries are inert; the
 * route enforces the setting on its own. The select is named `model`, so htmx sends its value
 * with the post.
 */
final class ModelSelect extends Component
{
    /** @param Model[] $models */
    public function __construct(private array $models, private string $selected, private bool $canChange) {}

    public function render(): string
    {
        $opts = '';
        foreach ($this->models as $m) {
            $opts .= sprintf('<option value="%s"%s>%s%s</option>', $this->a($m->id), selected($this->selected, $m->id, false), $this->e($m->label), $m->vision ? ' 👁' : '');
        }
        $hx = Hx::attrs(['post' => '/default-model', 'trigger' => 'change', 'target' => '#ab-status', 'swap' => 'innerHTML', 'headers' => Hx::formHeaders()]);
        return sprintf('<label class="screen-reader-text" for="ab-model">%s</label><select id="ab-model" name="model" class="ab-select"%s%s>%s</select>', $this->e($this->t('Model')), $this->canChange ? '' : ' disabled', $hx, $opts);
    }
}
