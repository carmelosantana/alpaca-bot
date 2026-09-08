<?php

declare(strict_types=1);

use AlpacaBot\Chat\Assistant;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Contract\ProviderInterface;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Enum\ProviderFinishReason;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Message\Conversation;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Message\SystemMessage;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Message\UserMessage;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Provider\Response;

// echoToolkit() lives in tests/Pest.php.

it('is named, carries the system text as its instructions, and runs six iterations at most by default', function (): void {
    $agent = new Assistant(Mockery::mock(ProviderInterface::class), 'You are terse.');
    expect($agent->name())->toBe('Alpaca Bot')
        ->and($agent->instructions())->toBe('You are terse.')
        ->and($agent->maxIterations())->toBe(6)
        ->and((new Assistant(Mockery::mock(ProviderInterface::class), '', 3))->maxIterations())->toBe(3);
});

it('sends the model one system message holding the site text and every toolkit\'s guidelines, the toolkit tools, and the prior turns without their system message', function (): void {
    $provider = Mockery::mock(ProviderInterface::class);
    $seen = null;
    $provider->shouldReceive('stream')->once()->andReturnUsing(static function (array $messages, array $tools, array $options = []) use (&$seen): \Generator {
        $seen = ['messages' => $messages, 'tools' => $tools];
        yield new Response('fine', ProviderFinishReason::Stop);
    });
    $agent = new Assistant($provider, 'Site prompt here.');
    $agent->addToolkit(echoToolkit('echo_a', 'Use echo_a for A.'));
    $agent->addToolkit(echoToolkit('echo_b', 'Use echo_b for B.'));
    $history = new Conversation();
    $history->add(new SystemMessage('a stale system message'));
    $history->add(new UserMessage('earlier'));

    $output = $agent->run(new UserMessage('now'), $history);

    expect($output->content)->toBe('fine')
        ->and($seen['messages'][0])->toBeInstanceOf(SystemMessage::class)
        ->and($seen['messages'][0]->content())->toContain('Site prompt here.')->toContain('Use echo_a for A.')->toContain('Use echo_b for B.')
        ->not->toContain('a stale system message')
        ->and(count($seen['messages']))->toBe(3)
        ->and($seen['messages'][1]->content())->toBe('earlier')
        ->and($seen['messages'][2]->content())->toBe('now')
        ->and(array_map(static fn(object $t): string => $t->name(), $seen['tools']))->toBe(['echo_a', 'echo_b', 'done']);
});

// Ollama routes some thinking models' whole completion into reasoning and leaves the content
// empty (the vendored loop names qwen and gemma). The library's default is to nudge twice and
// then give up with an EmptyResponse finish; a chat should show the answer the model wrote,
// even if it wrote it as a thought, rather than a line saying it gave none.
it('nudges a model that answers only in reasoning twice, then takes the reasoning as the answer', function (): void {
    $provider = Mockery::mock(ProviderInterface::class);
    $nudges = [];
    $provider->shouldReceive('stream')->times(3)->andReturnUsing(static function (array $messages) use (&$nudges): \Generator {
        $last = $messages[array_key_last($messages)];
        $nudges[] = $last instanceof UserMessage ? $last->content() : '';
        yield new Response('', ProviderFinishReason::Stop, reasoning: 'the thought');
    });
    $output = (new Assistant($provider, 'Site'))->run(new UserMessage('now'));
    expect($output->content)->toBe('the thought')
        ->and($output->finishReason)->toBe(\AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Enum\AgentFinishReason::Stop)
        ->and($nudges[0])->toBe('now')
        ->and($nudges[1])->toContain('Reply again')
        ->and($nudges[2])->toContain('Reply again');
});
