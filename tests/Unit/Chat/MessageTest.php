<?php

declare(strict_types=1);

use AlpacaBot\Chat\Message;

it('serialises an empty meta as a JSON object, the same type as a populated one', function (): void {
    expect(json_encode((new Message('user', 'q'))->toArray()))->toContain('"meta":{}')
        ->and(json_encode((new Message('assistant', 'a', 'm', null, 0, [], ['reasoning' => 'r']))->toArray()))->toContain('"meta":{"reasoning":"r"}');
});

it('round-trips meta through toArray and fromArray, the stored shape included', function (): void {
    // toArray() is also what ConversationStore writes to post meta, so what it emits for meta
    // must read back through fromArray(): a populated one intact, an empty one still empty.
    $full = new Message('assistant', 'a', 'm', ['prompt_tokens' => 1, 'completion_tokens' => 2], 5, [], ['finish' => 'stop', 'partial' => true]);
    expect(Message::fromArray($full->toArray()))->toEqual($full)
        ->and(Message::fromArray($full->toArray())->meta)->toBe(['finish' => 'stop', 'partial' => true]);
    $empty = new Message('user', 'q');
    expect(Message::fromArray($empty->toArray())->meta)->toBe([])
        ->and(Message::fromArray($empty->toArray()))->toEqual($empty);
    // An array meta (rows written before the cast) still reads.
    expect(Message::fromArray(['role' => 'user', 'content' => 'q', 'meta' => ['k' => 'v']])->meta)->toBe(['k' => 'v']);
});
