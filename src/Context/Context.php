<?php

declare(strict_types=1);

namespace AlpacaBot\Context;

/**
 * One piece of site context a source hands the chat pipeline: a stable id, a label the
 * model sees as a heading, and the text itself. `meta` is for the client and for filters
 * (a post id, say); it is never sent to the model.
 */
final class Context
{
    /** @param array<string, mixed> $meta */
    public function __construct(public string $id, public string $label, public string $text, public array $meta = []) {}

    /** @return array{id:string, label:string, text:string, meta:array<string, mixed>} */
    public function toArray(): array
    {
        return ['id' => $this->id, 'label' => $this->label, 'text' => $this->text, 'meta' => $this->meta];
    }
}
