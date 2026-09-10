<?php

declare(strict_types=1);

use AlpacaBot\Provider\BoundOptionsProvider;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Config\ModelDefinition;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Contract\ProviderInterface;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Enum\ProviderFinishReason;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Message\UserMessage;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Provider\Response;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Provider\Usage;

// AbstractAgent::run() calls stream($messages, $tools) with no options argument, so a provider
// handed to the agent bare would run every tool turn on the provider's defaults while a plain
// turn ran on the site's temperature and num_ctx. The decorator closes that gap at the provider
// rather than in the agent: the vendored library is not changed, and the pipeline hands the
// agent a provider that already knows the site's options.

it('merges the bound options into chat(), stream() and structured(), with the caller\'s own options winning', function (): void {
    $inner = Mockery::mock(ProviderInterface::class);
    $messages = [new UserMessage('hi')];
    $seen = [];
    $inner->shouldReceive('chat')->once()->andReturnUsing(function (array $m, array $t, array $o) use (&$seen): Response {
        $seen['chat'] = [$m, $t, $o];
        return new Response('ok', ProviderFinishReason::Stop);
    });
    $inner->shouldReceive('stream')->once()->andReturnUsing(function (array $m, array $t, array $o) use (&$seen): \Generator {
        $seen['stream'] = [$m, $t, $o];
        yield new Response('a', ProviderFinishReason::Stop);
    });
    $inner->shouldReceive('structured')->once()->andReturnUsing(function (array $m, string $schema, array $o) use (&$seen): array {
        $seen['structured'] = [$m, $schema, $o];
        return ['x' => 1];
    });

    $bound = new BoundOptionsProvider($inner, ['temperature' => 0.2, 'num_ctx' => 4096, 'keep_alive' => '5m']);

    expect($bound->chat($messages)->content)->toBe('ok')
        ->and(iterator_to_array($bound->stream($messages, [], ['temperature' => 0.9]))[0]->content)->toBe('a')
        ->and($bound->structured($messages, '{}', ['format' => 'json']))->toBe(['x' => 1]);

    expect($seen['chat'])->toBe([$messages, [], ['temperature' => 0.2, 'num_ctx' => 4096, 'keep_alive' => '5m']])
        // The caller's temperature wins over the bound one; the rest is filled in.
        ->and($seen['stream'])->toBe([$messages, [], ['temperature' => 0.9, 'num_ctx' => 4096, 'keep_alive' => '5m']])
        ->and($seen['structured'])->toBe([$messages, '{}', ['format' => 'json', 'temperature' => 0.2, 'num_ctx' => 4096, 'keep_alive' => '5m']]);
});

// The agent loop reports usage only in the Output it returns at the end of the run; a consumer
// that leaves mid-run never sees that Output, and the pipeline would bill nothing for calls the
// provider had already answered. Every call the agent makes goes through this decorator, so the
// tally lives here: what has been reported so far, readable at any moment.
it('tallies the usage every call reports as it arrives: max per field within one stream, summed across calls, and readable mid-stream', function (): void {
    $inner = Mockery::mock(ProviderInterface::class);
    $inner->shouldReceive('stream')->twice()->andReturnUsing(function (): \Generator {
        yield new Response('a', ProviderFinishReason::Stop);
        // Split across two chunks, as a provider that reports input and output separately does.
        yield new Response('', ProviderFinishReason::Stop, usage: new Usage(900, 0, 900));
        yield new Response('', ProviderFinishReason::Stop, usage: new Usage(900, 40, 940));
    });
    $inner->shouldReceive('chat')->once()->andReturn(new Response('ok', ProviderFinishReason::Stop, usage: new Usage(10, 5, 15)));
    $bound = new BoundOptionsProvider($inner, []);

    expect($bound->usage()->totalTokens)->toBe(0);
    $stream = $bound->stream([]);
    $stream->current();
    expect($bound->usage()->promptTokens)->toBe(0);
    $stream->next();
    expect([$bound->usage()->promptTokens, $bound->usage()->completionTokens])->toBe([900, 0]);
    $stream->next();
    expect([$bound->usage()->promptTokens, $bound->usage()->completionTokens, $bound->usage()->totalTokens])->toBe([900, 40, 940]);
    $stream->next();

    // A second stream, abandoned after its first usage chunk, adds what it had reported to the
    // first stream's figures; so does chat().
    $second = $bound->stream([]);
    $second->current();
    $second->next();
    unset($second);
    $bound->chat([]);
    expect([$bound->usage()->promptTokens, $bound->usage()->completionTokens, $bound->usage()->totalTokens])->toBe([1810, 45, 1855]);
});

it('delegates models(), isAvailable() and getModel(), and withModel() rebinds the same options over the inner provider\'s new model', function (): void {
    $inner = Mockery::mock(ProviderInterface::class);
    $swapped = Mockery::mock(ProviderInterface::class);
    $definition = new ModelDefinition('llama3.2', 'llama3.2', 'ollama');
    $inner->shouldReceive('models')->once()->andReturn([$definition]);
    $inner->shouldReceive('isAvailable')->once()->andReturn(true);
    $inner->shouldReceive('getModel')->once()->andReturn('llama3.2');
    $inner->shouldReceive('withModel')->once()->with('qwen3:8b')->andReturn($swapped);
    $swapped->shouldReceive('getModel')->once()->andReturn('qwen3:8b');
    $swapped->shouldReceive('chat')->once()->withArgs(fn(array $m, array $t, array $o): bool => $o === ['temperature' => 0.2])->andReturn(new Response('ok', ProviderFinishReason::Stop));

    $bound = new BoundOptionsProvider($inner, ['temperature' => 0.2]);
    expect($bound->models())->toBe([$definition])
        ->and($bound->isAvailable())->toBeTrue()
        ->and($bound->getModel())->toBe('llama3.2');

    $rebound = $bound->withModel('qwen3:8b');
    expect($rebound)->toBeInstanceOf(BoundOptionsProvider::class)
        ->and($rebound)->not->toBe($bound)
        ->and($rebound->getModel())->toBe('qwen3:8b')
        ->and($rebound->chat([])->content)->toBe('ok');
});
