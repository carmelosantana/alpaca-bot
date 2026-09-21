<?php

declare(strict_types=1);

use AlpacaBot\Chat\FakedToolCall;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Tool\ToolCall;

/*
 * Chat\FakedToolCall on its own: the recovery Pipeline runs on a finished tool turn, driven
 * directly. Every case PipelineToolsTest drives through a whole agent run (:632-973) is here as
 * one input and one expected reply, so a change to the recovery costs a string, not a harness.
 * PipelineToolsTest keeps the cases that are about the run: that a leaked call is never
 * executed, never recorded in meta['tool_calls'], and that what is stored is what came back.
 */

function fakedDone(string $response = 'An alpaca is a camelid.'): string
{
    return '{"name": "done", "arguments": {"response": ' . json_encode($response) . '}}';
}

dataset('faked tool calls', function (): array {
    $done = fakedDone();
    $fetch = '{"name": "web_fetch", "arguments": {"url": "https://example.com"}}';
    $code = "Here is the fix:\n\n    if (\$x) {\n\n        run();\n    }\n\nThat is all.";
    $block = "    if (\$x) {\n        run();\n    }";
    $indent = '{"name": "done", "arguments": {"response": "Indent it by four spaces."}}';
    $fence = "```json\n{$done}\n```";

    return [
        'no marker: untouched' => ["Plain answer.\n\n    code\n", "Plain answer.\n\n    code\n"],
        'a well-formed done, markers and all' => ["Let me answer that.\n\n<tool_call>\n{$done}\n</tool_call>", "Let me answer that.\n\nAn alpaca is a camelid."],
        'a call that is not done is dropped' => ["I will draft that for you.\n\n<tool_call>{\"name\": \"draft_post\", \"arguments\": {\"text\": \"Hello\"}}</tool_call>\n\nDone.", "I will draft that for you.\n\nDone."],
        'prose that mentions the marker' => [
            "A model without a tools template writes <tool_call> markers into its answer.\n\nThey look like this: <tool_call>...</tool_call>, and they are not calls at all.",
            "A model without a tools template writes <tool_call> markers into its answer.\n\nThey look like this: <tool_call>...</tool_call>, and they are not calls at all.",
        ],
        'a blank done: the recovery is off' => ['<tool_call>{"name": "done", "arguments": {"response": ""}}</tool_call>', '<tool_call>{"name": "done", "arguments": {"response": ""}}</tool_call>'],
        'a done with no response: the recovery is off' => ['<tool_call>{"name": "done", "arguments": {}}</tool_call>', '<tool_call>{"name": "done", "arguments": {}}</tool_call>'],
        'a recovery that leaves nothing gives the raw JSON back' => ['<tool_call>{"name": "draft_post", "arguments": {"text": "Hello"}}</tool_call>', '<tool_call>{"name": "draft_post", "arguments": {"text": "Hello"}}</tool_call>'],
        'a blank done after prose keeps prose and markup' => ["Hello there.\n\n<tool_call>{\"name\": \"done\", \"arguments\": {\"response\": \"\"}}</tool_call>", "Hello there.\n\n<tool_call>{\"name\": \"done\", \"arguments\": {\"response\": \"\"}}</tool_call>"],
        'an indented code block before a marked done' => ["{$code}\n\n<tool_call>{$indent}</tool_call>", "{$code}\n\nIndent it by four spaces."],
        'a marker-less call beside an indented code block' => ["<tool_call>{$fetch}</tool_call>\n\n{$code}\n\n{$indent}", "{$code}\n\nIndent it by four spaces."],
        'a stray closing marker on the next line' => ["Let me check.\n\n{$done}\n</tool_call>", "Let me check.\n\nAn alpaca is a camelid."],
        'a stray closing marker on the call\'s line' => ["Let me check.\n\n{$done}</tool_call>", "Let me check.\n\nAn alpaca is a camelid."],
        'both marker-less calls of the recorded shape' => ["Let me check.\n\n{$fetch}\n\n{$done}\n</tool_call>", "Let me check.\n\nAn alpaca is a camelid."],
        'a blank line with spaces on it' => ["Let me check.\n   \n{$done}\n</tool_call>", "Let me check.\n   \nAn alpaca is a camelid."],
        'an opening marker with prose before the call' => ["<tool_call>\n\nSome prose.\n\n{$done}", "<tool_call>\n\nSome prose.\n\nAn alpaca is a camelid."],
        'a closing marker with prose after the call' => ["{$done}\n\nSome prose.\n\n</tool_call>", "An alpaca is a camelid.\n\nSome prose.\n\n</tool_call>"],
        'no orphan marker where a recovered call sat' => ["<tool_call>\n\n{$done}\n\nHere is more prose.", "An alpaca is a camelid.\n\nHere is more prose."],
        'an answer about tool calls, fenced example and all' => [
            "Tool calls look like <tool_call>.\n\nExample:\n\n```json\n{$fetch}\n```\n\nThat's it.",
            "Tool calls look like <tool_call>.\n\nExample:\n\n```json\n{$fetch}\n```\n\nThat's it.",
        ],
        'a fenced example that is the whole head' => ["{$fence}\n\n<tool_call>", "{$fence}\n\n<tool_call>"],
        'a fenced example that is the whole tail' => ["</tool_call>\n\n{$fence}", "</tool_call>\n\n{$fence}"],
        'a fenced call between the markers' => ["<tool_call>\n{$fence}\n</tool_call>", 'An alpaca is a camelid.'],
        'a call written inside a line of prose' => ['Write <tool_call>' . fakedDone('x') . '</tool_call> to fake a call.', 'Write <tool_call>' . fakedDone('x') . '</tool_call> to fake a call.'],
        'a done answer that is itself indented' => ["Here:\n\n<tool_call>{\"name\": \"done\", \"arguments\": {\"response\": \"    if (\$x) {\\n        run();\\n    }  \"}}</tool_call>", "Here:\n\n{$block}"],
        'the first line\'s indentation after a removal at the head' => ["<tool_call>{$fetch}</tool_call>\n\n{$block}\n\nThat is all.", "{$block}\n\nThat is all."],
        'the first line\'s indentation after a removal in the middle' => ["Here:\n\n<tool_call>{$fetch}</tool_call>\n\n{$block}\n\n{$indent}", "Here:\n\n{$block}\n\nIndent it by four spaces."],
        'a payload that names no call' => ["Hello.\n\n<tool_call>{\"tool_calls\": []}</tool_call>", "Hello.\n\n<tool_call>{\"tool_calls\": []}</tool_call>"],
        'no blank line left where the markup was the whole reply' => ["\n\n<tool_call>{$done}</tool_call>\n\n", 'An alpaca is a camelid.'],
    ];
});

it('recovers what a faked call buried and keeps every other byte', function (string $content, string $expected): void {
    expect(FakedToolCall::recovered($content))->toBe($expected);
})->with('faked tool calls');

it('recovers the answer from the recorded qwen3-vl:2b leak', function (): void {
    $leak = (string) file_get_contents(__DIR__ . '/../../fixtures/faked-tool-call-qwen3-vl-2b.txt');

    expect(FakedToolCall::recovered($leak))
        ->toBe('An alpaca (Lama pacos) is a domesticated species of South American camelid, traditionally bred for their valuable fiber in the textile industry.');
});

it('names a marked call and both its markers as three touching byte ranges', function (): void {
    $done = fakedDone();
    $content = FakedToolCall::OPEN . $done . FakedToolCall::CLOSE;
    $open = strlen(FakedToolCall::OPEN);
    $end = $open + strlen($done);

    $found = FakedToolCall::markup($content);

    expect(array_map(static fn(array $r): array => [$r[0], $r[1], $r[2] === null], $found))
        ->toBe([[0, $open, true], [$open, $end, false], [$end, $end + strlen(FakedToolCall::CLOSE), true]])
        ->and($found[1][2][0]->name)->toBe('done')
        ->and(FakedToolCall::excisions($content, $found))
        ->toBe([[0, $open, ''], [$open, $end, 'An alpaca is a camelid.'], [$end, $end + strlen(FakedToolCall::CLOSE), '']]);
});

it('refuses a run that does not stand on its own lines, and calls the whole recovery off for a blank done', function (): void {
    $inline = 'Write ' . FakedToolCall::OPEN . fakedDone('x') . FakedToolCall::CLOSE . ' to fake a call.';
    $blank = FakedToolCall::OPEN . fakedDone('') . FakedToolCall::CLOSE;

    expect(FakedToolCall::excisions($inline, FakedToolCall::markup($inline)))->toBe([])
        ->and(FakedToolCall::excisions($blank, FakedToolCall::markup($blank)))->toBeNull();
});

it('answers with every done in a block, trailing whitespace off and leading kept, and refuses a done with nothing to say', function (): void {
    $block = [new ToolCall('a', 'done', ['response' => "  First.  \n"]), new ToolCall('b', 'web_fetch', ['url' => 'x']), new ToolCall('c', 'done', ['response' => 'Second.'])];

    expect(FakedToolCall::answer($block))->toBe("  First.\n\nSecond.")
        ->and(FakedToolCall::answer([new ToolCall('b', 'web_fetch', ['url' => 'x'])]))->toBe('')
        ->and(FakedToolCall::answer([new ToolCall('a', 'done', ['response' => '   '])]))->toBeNull()
        ->and(FakedToolCall::answer([new ToolCall('a', 'done', [])]))->toBeNull()
        ->and(FakedToolCall::answer([new ToolCall('a', 'done', ['response' => 42])]))->toBeNull();
});

it('reads a call payload as its calls and anything else, a payload holding none included, as prose', function (): void {
    $calls = FakedToolCall::calls(fakedDone('x'));

    expect($calls)->toHaveCount(1)
        ->and($calls[0]->name)->toBe('done')
        ->and($calls[0]->arguments)->toBe(['response' => 'x'])
        ->and(FakedToolCall::calls(''))->toBeNull()
        ->and(FakedToolCall::calls('Just prose.'))->toBeNull()
        ->and(FakedToolCall::calls('{"tool_calls": []}'))->toBeNull();
});
