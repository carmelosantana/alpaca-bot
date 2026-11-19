<?php

declare(strict_types=1);

use AlpacaBot\Chat\Result;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Enum\ProviderFinishReason;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Provider\Response;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Provider\Usage;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Tool\ToolCall;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Tool\ToolResult;
use Brain\Monkey\Actions;

// php-agents 0.17 (PR #50) ends a run on a refusal, a content filter, a context-window overflow,
// a stream that broke off and a max_tokens stop with no text, and runs no tool after any of them.
// The tool path gets those as AgentFinishReason cases; the plain path reads the provider's last
// chunk itself. pipelineWith(), agentProvider(), echoToolkit() and registryWith() are in
// tests/Pest.php.

const DECLINED = 'The model declined to answer.';
const FILTERED = "The provider's content filter stopped this reply.";
const TOO_LONG = 'This conversation is too long for the model. Start a new conversation.';

/** A tool turn whose only provider call streams `$chunks`; `$ran` is set when the tool runs. */
function finishToolTurn(array $chunks, ?bool &$ran = null): object
{
    $ran = false;
    $toolkit = echoToolkit('echo_tool', 'Use it.', static function (array $a) use (&$ran): ToolResult {
        $ran = true;
        return ToolResult::success('echo');
    });
    return pipelineWith(agentProvider([$chunks]), [], [], [['id' => 'llama3.2', 'tools' => true]], null, registryWith(['echo' => $toolkit]));
}

it('keeps a refusal as the finished reply on a tool turn, and runs no tool the refusing reply asked for', function (): void {
    $h = finishToolTurn([
        new Response("I can't help with that.", ProviderFinishReason::Stop),
        new Response('', ProviderFinishReason::Refusal, [new ToolCall('c1', 'echo_tool', ['text' => 'x'])], usage: new Usage(4, 3, 7)),
    ], $ran);
    Actions\expectDone('alpaca_bot/chat/completed')->once()->with(Mockery::type(Result::class));
    Actions\expectDone('alpaca_bot/chat/failed')->never();

    $r = $h->pipeline->complete(3, 'do the bad thing');

    expect($ran)->toBeFalse()
        ->and($r->reply->content)->toBe("I can't help with that.")
        ->and($r->receipt['total_tokens'])->toBe(7);
});

it('says the model declined when a tool turn\'s refusal came with no words', function (): void {
    $h = finishToolTurn([new Response('', ProviderFinishReason::Refusal)]);

    expect($h->pipeline->complete(3, 'x')->reply->content)->toBe(DECLINED);
});

it('keeps what streamed before a content filter on a tool turn and says the filter stopped it', function (): void {
    $h = finishToolTurn([
        new Response('Here is the start', ProviderFinishReason::Stop),
        new Response('', ProviderFinishReason::ContentFilter, [new ToolCall('c1', 'echo_tool', ['text' => 'x'])]),
    ], $ran);
    Actions\expectDone('alpaca_bot/chat/failed')->never();

    $gen = $h->pipeline->send(3, 'x');
    $deltas = [];
    foreach ($gen as $d) {
        $deltas[] = $d->text;
    }

    expect($ran)->toBeFalse()
        ->and(implode('', $deltas))->toBe("Here is the start\n\n" . FILTERED)
        ->and($gen->getReturn()->reply->content)->toBe("Here is the start\n\n" . FILTERED);
});

it('fails a tool turn whose conversation outgrew the context window, in words that say what to do', function (): void {
    $h = finishToolTurn([new Response('', ProviderFinishReason::ContextWindowExceeded)]);
    Actions\expectDone('alpaca_bot/chat/failed')->once();
    Actions\expectDone('alpaca_bot/chat/completed')->never();

    expect(fn() => $h->pipeline->complete(3, 'x'))->toThrow(\RuntimeException::class, TOO_LONG);
});

it('fails a tool turn that stopped at max_tokens with no text as the provider error the library announces', function (): void {
    $h = finishToolTurn([new Response('', ProviderFinishReason::MaxTokens)]);
    Actions\expectDone('alpaca_bot/chat/failed')->once();

    expect(fn() => $h->pipeline->complete(3, 'x'))->toThrow(\RuntimeException::class, 'Provider error: Provider stopped at max_tokens');
});

it('says "Provider error:" once when the library\'s announcement already starts with it', function (): void {
    // AbstractAgent::failedStopOutput() announces 'Provider error: the response ended ...' for a
    // stream that broke off; raised() puts the same prefix on every announced failure.
    $h = finishToolTurn([new Response('Half', ProviderFinishReason::Stop), new Response('', ProviderFinishReason::Error)]);

    try {
        $h->pipeline->complete(3, 'x');
        $message = null;
    } catch (\RuntimeException $e) {
        $message = $e->getMessage();
    }

    expect($message)->toBe('Provider error: the response ended without a valid finish reason.');
});

// The plain path: no toolkit, so the provider's chunks come straight through and the last one
// carries the provider's finish reason.

it('fails a plain turn whose stream broke off, instead of storing half an answer as a finished one', function (): void {
    $h = pipelineWith(agentProvider([[new Response('Half an ans', ProviderFinishReason::Stop), new Response('', ProviderFinishReason::Error)]]));
    Actions\expectDone('alpaca_bot/chat/failed')->once();
    Actions\expectDone('alpaca_bot/chat/completed')->never();

    expect(fn() => $h->pipeline->complete(3, 'x'))->toThrow(\RuntimeException::class, 'Provider error: The reply stopped before it finished.');
});

it('fails a plain turn that stopped at max_tokens before writing anything', function (): void {
    $h = pipelineWith(agentProvider([[new Response('', ProviderFinishReason::MaxTokens, usage: new Usage(5, 0, 5))]]));
    Actions\expectDone('alpaca_bot/chat/failed')->once();

    expect(fn() => $h->pipeline->complete(3, 'x'))->toThrow(\RuntimeException::class, 'Provider error: The model reached its token limit before writing an answer.');
});

it('keeps a plain turn that hit max_tokens after writing, as it always has', function (): void {
    $h = pipelineWith(agentProvider([[new Response('A long', ProviderFinishReason::Stop), new Response('', ProviderFinishReason::MaxTokens)]]));

    expect($h->pipeline->complete(3, 'x')->reply->content)->toBe('A long');
});

it('fails a plain turn whose conversation outgrew the context window', function (): void {
    $h = pipelineWith(agentProvider([[new Response('', ProviderFinishReason::ContextWindowExceeded)]]));

    expect(fn() => $h->pipeline->complete(3, 'x'))->toThrow(\RuntimeException::class, TOO_LONG);
});

it('keeps a plain turn a content filter stopped, saying so after what streamed', function (): void {
    $h = pipelineWith(agentProvider([[new Response('Here is', ProviderFinishReason::Stop), new Response('', ProviderFinishReason::ContentFilter)]]));
    Actions\expectDone('alpaca_bot/chat/failed')->never();

    $gen = $h->pipeline->send(3, 'x');
    $texts = array_map(static fn($d): string => $d->text, iterator_to_array($gen, false));

    expect($texts)->toBe(['Here is', "\n\n" . FILTERED])
        ->and($gen->getReturn()->reply->content)->toBe("Here is\n\n" . FILTERED);
});

it('keeps a plain refusal as the reply, and says the model declined when it said nothing', function (): void {
    $worded = pipelineWith(agentProvider([[new Response('No.', ProviderFinishReason::Stop), new Response('', ProviderFinishReason::Refusal)]]));
    expect($worded->pipeline->complete(3, 'x')->reply->content)->toBe('No.');

    $silent = pipelineWith(agentProvider([[new Response('', ProviderFinishReason::Refusal)]]));
    expect($silent->pipeline->complete(3, 'x')->reply->content)->toBe(DECLINED);
});
