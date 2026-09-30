<?php

declare(strict_types=1);

use AlpacaBot\Chat\FakedToolCall;
use AlpacaBot\Chat\FakedToolCallStream;

// fakedStream() lives in tests/Pest.php: the chunks through one stream, flushed, pieces joined.
// Every case asserts the concatenation as well as the flags, because the one thing this may
// never do is change the text: what goes in comes out, in order, byte for byte.

it('holds a faked call from its opening marker through its closing one, and nothing else', function (): void {
    $text = "Let me check.\n<tool_call>\n{\"name\": \"web_fetch\", \"arguments\": {\"url\": \"https://example.test/\"}}\n</tool_call>\n\nThe page says hello.";
    expect(fakedStream([$text]))->toBe([
        ["Let me check.\n", false],
        ["<tool_call>\n{\"name\": \"web_fetch\", \"arguments\": {\"url\": \"https://example.test/\"}}\n</tool_call>", true],
        ["\n\nThe page says hello.", false],
    ]);
});

it('finds a marker split across two deltas, and one split a byte at a time', function (): void {
    $pieces = fakedStream(['Checking. <tool_', 'call>{"name": "done"', '}</tool', "_call>\n\nHere it is."]);
    expect($pieces)->toBe([
        ['Checking. ', false],
        ['<tool_call>{"name": "done"}</tool_call>', true],
        ["\n\nHere it is.", false],
    ]);
    // The same text one byte per delta, which is the worst chunking a provider can hand us.
    $text = "a\n<tool_call>{\"name\": \"done\"}</tool_call>\nb";
    expect(fakedStream(str_split($text)))->toBe([
        ["a\n", false],
        ['<tool_call>{"name": "done"}</tool_call>', true],
        ["\nb", false],
    ]);
});

it('lets a tail that turns out not to be a marker straight through', function (): void {
    expect(fakedStream(['Use <tool_', 'tip> for this']))->toBe([['Use <tool_tip> for this', false]])
        ->and(fakedStream(['1 < 2 and 3 <']))->toBe([['1 < 2 and 3 <', false]]);
});

it('flushes an unclosed block, and the bytes it was still deciding about, verbatim and still held', function (): void {
    $text = "Sure.\n<tool_call>{\"name\": \"done\", \"arguments\": {\"response\": \"hi\"}}</tool";
    expect(fakedStream([$text]))->toBe([
        ["Sure.\n", false],
        ['<tool_call>{"name": "done", "arguments": {"response": "hi"}}</tool', true],
    ]);
    $stream = new FakedToolCallStream();
    $stream->feed($text);
    expect($stream->open())->toBeTrue()
        // flush() hands the kept tail over once, and there is nothing left after it.
        ->and($stream->flush())->toBe([['</tool', true]])
        ->and($stream->flush())->toBe([]);
});

it('holds nothing in the recorded leak, which has no opening marker at all', function (): void {
    $leak = (string) file_get_contents(dirname(__DIR__, 2) . '/fixtures/faked-tool-call-qwen3-vl-2b.txt');
    expect(str_contains($leak, FakedToolCall::OPEN))->toBeFalse()
        ->and(fakedStream(str_split($leak, 7)))->toBe([[$leak, false]]);
});
