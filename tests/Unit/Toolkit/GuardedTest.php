<?php

declare(strict_types=1);

use AlpacaBot\Toolkit\Guarded;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Enum\ToolResultStatus;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Tool\Parameter\StringParameter;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Tool\Tool;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Tool\ToolResult;
use Brain\Monkey\Actions;
use Brain\Monkey\Filters;

/** A tool named `echo_tool` that answers `echo:<text>` and counts its runs in `$ran`. */
function guardedEcho(int &$ran): Tool
{
    return new Tool('echo_tool', 'Echoes.', [new StringParameter('text', 'What to echo.')], static function (array $a) use (&$ran): ToolResult {
        ++$ran;
        return ToolResult::success('echo:' . $a['text']);
    });
}

it('is the tool it wraps to the model: same name, description, parameters and schema', function (): void {
    $ran = 0;
    $inner = guardedEcho($ran);
    $tool = new Guarded($inner, 'echo', 3);
    expect($tool->name())->toBe('echo_tool')
        ->and($tool->description())->toBe('Echoes.')
        ->and($tool->parameters())->toBe($inner->parameters())
        ->and($tool->toFunctionSchema())->toBe($inner->toFunctionSchema());
});

it('asks alpaca_bot/tool/before_call before the tool runs and tells alpaca_bot/tool/called after, with the toolkit, the tool, the arguments and the user', function (): void {
    $ran = 0;
    Filters\expectApplied('alpaca_bot/tool/before_call')->once()->with(null, 'echo', 'echo_tool', ['text' => 'ping'], 3)->andReturnFirstArg();
    Actions\expectDone('alpaca_bot/tool/called')->once()->with('echo', 'echo_tool', ['text' => 'ping'], 3, Mockery::on(
        static fn(mixed $r): bool => $r instanceof ToolResult && $r->content === 'echo:ping',
    ), null);
    $result = (new Guarded(guardedEcho($ran), 'echo', 3))->execute(['text' => 'ping']);
    expect($result->status)->toBe(ToolResultStatus::Success)
        ->and($result->content)->toBe('echo:ping')
        ->and($ran)->toBe(1);
});

it('refuses the call when before_call answers a WP_Error: the tool never runs, the model reads the message, and called hears the refusal', function (): void {
    $ran = 0;
    $refusal = new WP_Error('nope', 'Ask an editor first.');
    Filters\expectApplied('alpaca_bot/tool/before_call')->once()->andReturn($refusal);
    Actions\expectDone('alpaca_bot/tool/called')->once()->with('echo', 'echo_tool', ['text' => 'ping'], 3, Mockery::on(
        static fn(mixed $r): bool => $r instanceof ToolResult && $r->status === ToolResultStatus::Error && $r->content === 'Ask an editor first.',
    ), $refusal);
    $result = (new Guarded(guardedEcho($ran), 'echo', 3))->execute(['text' => 'ping']);
    expect($ran)->toBe(0)
        ->and($result->status)->toBe(ToolResultStatus::Error)
        ->and($result->content)->toBe('Ask an editor first.');
});

it('refuses on false as well, and words the refusal itself when there is no message, so the model is never handed an empty error', function (mixed $answer): void {
    $ran = 0;
    Filters\expectApplied('alpaca_bot/tool/before_call')->once()->andReturn($answer);
    Actions\expectDone('alpaca_bot/tool/called')->once()->with('echo', 'echo_tool', ['text' => 'ping'], 3, Mockery::type(ToolResult::class), Mockery::type(WP_Error::class));
    $result = (new Guarded(guardedEcho($ran), 'echo', 3))->execute(['text' => 'ping']);
    expect($ran)->toBe(0)
        ->and($result->status)->toBe(ToolResultStatus::Error)
        ->and($result->content)->toBe('The site refused this call to echo_tool.');
})->with([
    'false' => [false],
    'a WP_Error with no message' => [new WP_Error('nope')],
]);

it('runs the tool for any other answer: null from a listener that forgot to return, true, or a string', function (mixed $answer): void {
    $ran = 0;
    Filters\expectApplied('alpaca_bot/tool/before_call')->once()->andReturn($answer);
    $result = (new Guarded(guardedEcho($ran), 'echo', 3))->execute(['text' => 'ping']);
    expect($ran)->toBe(1)->and($result->content)->toBe('echo:ping');
})->with([
    'null' => [null],
    'true' => [true],
    'a string' => ['deny'],
]);
