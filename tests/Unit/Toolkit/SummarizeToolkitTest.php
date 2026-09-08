<?php

declare(strict_types=1);

use AlpacaBot\Toolkit\SummarizeToolkit;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Enum\ProviderFinishReason;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Enum\ToolResultStatus;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Message\SystemMessage;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Message\UserMessage;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Provider\Response;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Provider\Usage;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Tool\Tool;

// Pipeline is final, so the toolkit runs over pipelineWith() (tests/Pest.php): the real pipeline
// with WordPress stubbed and the provider handed in. The harness records every persisted write,
// which is how "no conversation was kept" is asserted. The acting user is a counting closure,
// as in DraftPostToolkitTest, for the same reason.

function summarizeTool(object $h, int &$asked, int $userId = 3): Tool
{
    $tool = (new SummarizeToolkit($h->pipeline, static function () use ($userId, &$asked): int {
        $asked++;
        return $userId;
    }))->tools()[0];
    expect($tool)->toBeInstanceOf(Tool::class);
    return $tool;
}

it('summarizes through an ephemeral turn as the acting user, the length in the system prompt, and returns the reply text only', function (): void {
    $provider = pipelineProvider([
        new Response('A short summary.', ProviderFinishReason::Stop),
        new Response('', ProviderFinishReason::Stop, usage: new Usage(5, 2, 7)),
    ], $call);
    $h = pipelineWith($provider, ['chat.system_prompt' => 'You are the site assistant.']);
    $asked = 0;
    $tool = summarizeTool($h, $asked);
    expect($tool->name())->toBe('summarize')->and($asked)->toBe(0);
    $res = $tool->execute(['text' => 'Long text here.', 'length' => 'short']);
    expect($res->status)->toBe(ToolResultStatus::Success)
        ->and($res->content)->toBe('A short summary.')
        ->and($asked)->toBe(1)
        ->and($call['messages'])->toHaveCount(2)
        ->and($call['messages'][0])->toBeInstanceOf(SystemMessage::class)
        // The tool's own prompt, not the site's: the site prompt is for a chat, and it would tell the model to converse.
        ->and($call['messages'][0]->content())->toContain('one or two sentences')->not->toContain('site assistant')
        ->and($call['messages'][1])->toBeInstanceOf(UserMessage::class)
        ->and($call['messages'][1]->content())->toBe('Long text here.')
        // No conversation kept: the receipt is the only write, and it is the acting user's.
        ->and(array_map(static fn(array $w): array => [$w[0], $w[1]], $h->writes))->toBe([['wp_insert_post', 'chat_log']])
        ->and($h->writes[0][2]['post_author'])->toBe(3)
        ->and($h->writes[0][2]['meta_input']['conversation_id'])->toBe(0);
});

it('asks for one paragraph by default and several for long', function (): void {
    $h = pipelineWith(pipelineProvider([new Response('Sum', ProviderFinishReason::Stop)], $call));
    $asked = 0;
    expect(summarizeTool($h, $asked)->execute(['text' => 'x y z'])->content)->toBe('Sum')
        ->and($call['messages'][0]->content())->toContain('one paragraph');
    $h = pipelineWith(pipelineProvider([new Response('Sum', ProviderFinishReason::Stop)], $call));
    expect(summarizeTool($h, $asked)->execute(['text' => 'x y z', 'length' => 'long'])->content)->toBe('Sum')
        ->and($call['messages'][0]->content())->toContain('paragraphs');
});

it('is refused for a length outside short, medium, long, or an empty text, before any provider is built', function (): void {
    $h = pipelineWith(null);
    $asked = 0;
    $tool = summarizeTool($h, $asked);
    expect($tool->execute(['text' => 'x', 'length' => 'huge'])->status)->toBe(ToolResultStatus::Error)
        ->and($tool->execute(['text' => '   '])->status)->toBe(ToolResultStatus::Error)
        ->and($tool->execute([])->status)->toBe(ToolResultStatus::Error)
        ->and($h->writes)->toBe([]);
});

it('passes a pipeline failure back as an error rather than an exception, with nothing left behind', function (): void {
    $h = pipelineWith(pipelineProvider([new \RuntimeException('connection refused')]));
    $asked = 0;
    $res = summarizeTool($h, $asked)->execute(['text' => 'x y z']);
    expect($res->status)->toBe(ToolResultStatus::Error)
        ->and($res->content)->toContain('connection refused')
        ->and($h->writes)->toBe([]);
});

it('carries guidelines', function (): void {
    $h = pipelineWith(null);
    $kit = new SummarizeToolkit($h->pipeline, static fn(): int => 3);
    expect($kit->tools())->toHaveCount(1)
        ->and($kit->guidelines())->toContain('summarize');
});
