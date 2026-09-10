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
use Brain\Monkey\Functions;

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

// A tool result is text the model reads and may repeat, and on a front-end [alpacabot] turn the
// model's output is on its way to a visitor. Tool::execute()'s own catch reports the raw
// message, and what a provider throws quotes its endpoint -- the text Rest\Errors::provider()
// withholds from anyone but an administrator. The toolkit catches first and applies the same
// policy the REST routes and the shortcodes do.
it('answers a provider failure with the fixed message, never the provider\'s own text or its endpoint', function (): void {
    $h = pipelineWith(pipelineProvider([new \RuntimeException('cURL error 7: Failed to connect for "http://ollama.internal:11434/v1/chat/completions"')]));
    Functions\when('current_user_can')->justReturn(false);
    $asked = 0;
    $res = summarizeTool($h, $asked)->execute(['text' => 'x y z']);
    expect($res->status)->toBe(ToolResultStatus::Error)
        ->and($res->content)->toBe('The model provider could not complete the request.')
        ->and($res->content)->not->toContain('ollama.internal')
        ->and($res->content)->not->toContain('cURL')
        ->and($h->writes)->toBe([]);
});

// The other two arms of the same policy: those messages are the pipeline's own, written for a
// person and naming nothing the caller may not see, so they reach the model as they are.
it('keeps the pipeline\'s own words for the caller\'s mistake and for a spent cap', function (): void {
    $h = pipelineWith(null);
    $asked = 0;
    expect(summarizeTool($h, $asked)->execute(['text' => '  '])->content)->toBe('The message is empty.');

    $h = pipelineWith(null, ['governance.user_monthly_tokens' => 10]);
    $h->transients['alpaca_bot_usage_3_2024-08'] = ['tokens' => 12, 'requests' => 1];
    $asked = 0;
    $res = summarizeTool($h, $asked)->execute(['text' => 'x y z']);
    expect($res->status)->toBe(ToolResultStatus::Error)
        ->and($res->content)->toContain('monthly token cap');
});

it('carries guidelines', function (): void {
    $h = pipelineWith(null);
    $kit = new SummarizeToolkit($h->pipeline, static fn(): int => 3);
    expect($kit->tools())->toHaveCount(1)
        ->and($kit->guidelines())->toContain('summarize');
});
