<?php

declare(strict_types=1);

use AlpacaBot\Provider\WpAi\Client;
use AlpacaBot\Provider\WpAiClientProvider;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Config\ModelDefinition;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Contract\ProviderInterface;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Enum\ProviderFinishReason;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Enum\ToolResultStatus;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Exception\ProviderException;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Message\AssistantMessage;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Message\SystemMessage;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Message\ToolResultMessage;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Message\UserMessage;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Provider\Response;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Tool\Parameter\StringParameter;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Tool\Tool;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Tool\ToolCall;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Tool\ToolResult;

/**
 * The seam under test is Provider\WpAi\Client, not core's prompt builder: core's builder takes
 * its own DTOs (Message, MessagePart, File, FunctionDeclaration, a ModelInterface from the
 * registry), none of which exist in this process, so a fake builder alone could not isolate the
 * adapter from core. The adapter speaks arrays in core's own `fromArray()` shapes to the client;
 * the real client (CoreClient) turns them into DTOs and is covered by the integration suite over
 * the real core.
 *
 * @param list<array{content?: string, reasoning?: string, tool_calls?: list<array{id: string, name: string, arguments: array<string, mixed>}>, finish_reason?: string, prompt_tokens?: int, completion_tokens?: int, total_tokens?: int}> $replies what generate() answers, in order
 * @param list<array{id: string, name: string, provider: string, provider_name: string, tools: bool, vision: bool}> $models
 */
function fakeWpAiClient(bool $available = true, array $replies = [], array $models = [], ?\Throwable $failure = null): Client
{
    return new class ($available, $replies, $models, $failure) implements Client {
        /** @var list<array<string, mixed>> every request generate() received */
        public array $requests = [];

        public function __construct(private bool $available, private array $replies, private array $models, private ?\Throwable $failure) {}

        public function available(): bool
        {
            return $this->available;
        }

        public function models(): array
        {
            return $this->models;
        }

        public function generate(array $request): array
        {
            $this->requests[] = $request;
            if ($this->failure !== null) {
                throw $this->failure;
            }
            $reply = array_shift($this->replies) ?? [];
            return $reply + ['content' => '', 'reasoning' => '', 'tool_calls' => [], 'finish_reason' => 'stop', 'prompt_tokens' => 0, 'completion_tokens' => 0, 'total_tokens' => 0];
        }
    };
}

it('implements the php-agents provider contract', function (): void {
    expect(new WpAiClientProvider('m', fakeWpAiClient()))->toBeInstanceOf(ProviderInterface::class);
});

it('maps the conversation into core\'s message shapes: system text as the instruction, user and assistant turns as user and model messages, a tool result as a function response, tools as function declarations, and only the temperature of the site\'s options', function (): void {
    $client = fakeWpAiClient(replies: [['content' => 'ok']]);
    $provider = new WpAiClientProvider('qwen3:8b', $client);
    $tool = new Tool('lookup', 'Look something up.', [new StringParameter('q', 'The query.')], static fn(array $in): ToolResult => ToolResult::success('x'));
    $call = new ToolCall('call-1', 'lookup', ['q' => 'alpacas']);

    $response = $provider->chat([
        new SystemMessage('Be brief.'),
        new UserMessage('Hi'),
        new AssistantMessage('', [$call]),
        new ToolResultMessage((new ToolResult(ToolResultStatus::Success, '{"answer":42}', 'call-1', mimeType: 'application/json'))),
        new AssistantMessage('42.'),
        new UserMessage('Thanks'),
    ], [$tool], ['temperature' => 0.3, 'num_ctx' => 4096, 'keep_alive' => '5m']);

    expect($response->content)->toBe('ok')
        ->and($client->requests)->toHaveCount(1);
    $request = $client->requests[0];
    expect($request['model'])->toBe('qwen3:8b')
        ->and($request['system'])->toBe('Be brief.')
        ->and($request['temperature'])->toBe(0.3)
        ->and($request)->not->toHaveKeys(['num_ctx', 'keep_alive'])
        ->and($request['messages'])->toBe([
            ['role' => 'user', 'parts' => [['type' => 'text', 'text' => 'Hi']]],
            ['role' => 'model', 'parts' => [['type' => 'function_call', 'functionCall' => ['id' => 'call-1', 'name' => 'lookup', 'args' => ['q' => 'alpacas']]]]],
            ['role' => 'user', 'parts' => [['type' => 'function_response', 'functionResponse' => ['id' => 'call-1', 'name' => 'lookup', 'response' => ['answer' => 42]]]]],
            ['role' => 'model', 'parts' => [['type' => 'text', 'text' => '42.']]],
            ['role' => 'user', 'parts' => [['type' => 'text', 'text' => 'Thanks']]],
        ])
        ->and($request['tools'])->toBe([
            ['name' => 'lookup', 'description' => 'Look something up.', 'parameters' => $tool->toFunctionSchema()['function']['parameters']],
        ]);
});

it('sends a plain-text tool result as its text, names the tool from the call it answers, and leaves out an assistant turn with nothing in it', function (): void {
    $client = fakeWpAiClient();
    (new WpAiClientProvider('m', $client))->chat([
        new UserMessage('Hi'),
        new AssistantMessage('', [new ToolCall('c9', 'web_fetch', [])]),
        new ToolResultMessage(ToolResult::success('page text')->withCallId('c9')),
        new AssistantMessage(''),
        new UserMessage('Go on'),
    ]);
    $messages = $client->requests[0]['messages'];
    expect($messages)->toHaveCount(4)
        ->and($messages[1]['parts'][0]['functionCall'])->toBe(['id' => 'c9', 'name' => 'web_fetch', 'args' => null])
        ->and($messages[2]['parts'][0]['functionResponse'])->toBe(['id' => 'c9', 'name' => 'web_fetch', 'response' => 'page text'])
        ->and($messages[3])->toBe(['role' => 'user', 'parts' => [['type' => 'text', 'text' => 'Go on']]]);
});

it('joins several system messages into one instruction and sends no instruction key when there is none', function (): void {
    $client = fakeWpAiClient();
    $provider = new WpAiClientProvider('m', $client);
    $provider->chat([new SystemMessage('One.'), new SystemMessage('Two.'), new UserMessage('Hi')]);
    $provider->chat([new UserMessage('Hi')]);
    expect($client->requests[0]['system'])->toBe("One.\n\nTwo.")
        ->and($client->requests[1])->not->toHaveKey('system');
});

it('turns a user turn\'s image parts into core file parts: a data URL becomes an inline file with its mime split out, an http(s) URL a remote file', function (): void {
    $client = fakeWpAiClient();
    (new WpAiClientProvider('m', $client))->chat([new UserMessage([
        ['type' => 'text', 'text' => 'What is this?'],
        ['type' => 'image_url', 'image_url' => ['url' => 'data:image/png;base64,iVBORw0KGgo=']],
        ['type' => 'image_url', 'image_url' => ['url' => 'https://example.com/a.jpg']],
    ])]);
    expect($client->requests[0]['messages'][0]['parts'])->toBe([
        ['type' => 'text', 'text' => 'What is this?'],
        ['type' => 'file', 'file' => ['fileType' => 'inline', 'mimeType' => 'image/png', 'base64Data' => 'iVBORw0KGgo=']],
        ['type' => 'file', 'file' => ['fileType' => 'remote', 'url' => 'https://example.com/a.jpg']],
    ]);
});

it('maps the reply: content, reasoning, tool calls (which make the finish reason ToolUse), usage and the model', function (): void {
    $client = fakeWpAiClient(replies: [[
        'content' => 'Let me check.',
        'reasoning' => 'The user wants a lookup.',
        'tool_calls' => [['id' => 'call-7', 'name' => 'lookup', 'arguments' => ['q' => 'x']]],
        'finish_reason' => 'stop',
        'prompt_tokens' => 11,
        'completion_tokens' => 5,
        'total_tokens' => 16,
    ]]);
    $response = (new WpAiClientProvider('qwen3:8b', $client))->chat([new UserMessage('x')]);
    expect($response)->toBeInstanceOf(Response::class)
        ->and($response->content)->toBe('Let me check.')
        ->and($response->reasoning)->toBe('The user wants a lookup.')
        ->and($response->finishReason)->toBe(ProviderFinishReason::ToolUse)
        ->and($response->toolCalls)->toHaveCount(1)
        ->and($response->toolCalls[0])->toBeInstanceOf(ToolCall::class)
        ->and($response->toolCalls[0]->id)->toBe('call-7')
        ->and($response->toolCalls[0]->name)->toBe('lookup')
        ->and($response->toolCalls[0]->arguments)->toBe(['q' => 'x'])
        ->and($response->model)->toBe('qwen3:8b')
        ->and($response->usage?->promptTokens)->toBe(11)
        ->and($response->usage?->completionTokens)->toBe(5)
        ->and($response->usage?->totalTokens)->toBe(16);
});

it('maps core\'s finish reasons onto php-agents\': length is MaxTokens, error is Error, anything else Stop', function (string $core, ProviderFinishReason $expected): void {
    $response = (new WpAiClientProvider('m', fakeWpAiClient(replies: [['content' => 'x', 'finish_reason' => $core]])))->chat([new UserMessage('x')]);
    expect($response->finishReason)->toBe($expected);
})->with([
    'stop' => ['stop', ProviderFinishReason::Stop],
    'length' => ['length', ProviderFinishReason::MaxTokens],
    'error' => ['error', ProviderFinishReason::Error],
    'content_filter' => ['content_filter', ProviderFinishReason::Stop],
]);

// Core's client has no streaming (WordPress 7.1 vendors php-ai-client 1.3.1, which has no
// stream method anywhere): stream() is chat() as a one-chunk generator. Chat\Pipeline and the
// vendored AbstractAgent both read content, reasoning, toolCalls and usage off every chunk, so
// the one chunk is the whole Response, and it is not sent until the generator is advanced.
it('stream() yields chat()\'s Response as a single chunk, lazily', function (): void {
    $client = fakeWpAiClient(replies: [['content' => 'whole reply', 'reasoning' => 'thought', 'prompt_tokens' => 2, 'completion_tokens' => 3, 'total_tokens' => 5]]);
    $stream = (new WpAiClientProvider('m', $client))->stream([new UserMessage('x')], [], ['temperature' => 0.1]);
    expect($stream)->toBeInstanceOf(Generator::class)
        ->and($client->requests)->toBe([]);
    $chunks = iterator_to_array($stream, false);
    expect($chunks)->toHaveCount(1)
        ->and($chunks[0])->toBeInstanceOf(Response::class)
        ->and($chunks[0]->content)->toBe('whole reply')
        ->and($chunks[0]->reasoning)->toBe('thought')
        ->and($chunks[0]->toolCalls)->toBe([])
        ->and($chunks[0]->usage?->totalTokens)->toBe(5)
        ->and($client->requests[0]['temperature'])->toBe(0.1);
});

it('withModel() returns a new provider on the same client and leaves the original\'s model alone', function (): void {
    $client = fakeWpAiClient();
    $a = new WpAiClientProvider('a', $client);
    $b = $a->withModel('b');
    expect($b)->toBeInstanceOf(WpAiClientProvider::class)
        ->and($b)->not->toBe($a)
        ->and($a->getModel())->toBe('a')
        ->and($b->getModel())->toBe('b');
    $b->chat([new UserMessage('x')]);
    expect($client->requests[0]['model'])->toBe('b');
});

it('chat() throws php-agents\' ProviderException without calling core when the client is unavailable', function (): void {
    $client = fakeWpAiClient(available: false, replies: [['content' => 'must not be seen']]);
    $provider = new WpAiClientProvider('m', $client);
    expect(fn() => $provider->chat([new UserMessage('x')]))->toThrow(ProviderException::class, 'WordPress AI client');
    expect($client->requests)->toBe([]);
    // The guard is the client's word, not a constant: the same call goes through once it is available.
    expect((new WpAiClientProvider('m', fakeWpAiClient(replies: [['content' => 'seen']])))->chat([new UserMessage('x')])->content)->toBe('seen');
});

it('wraps a failure inside core as a ProviderException that keeps the reason and the cause', function (): void {
    $cause = new RuntimeException('No models found for provider "ollama" that support text_generation for this prompt.');
    $provider = new WpAiClientProvider('m', fakeWpAiClient(failure: $cause));
    try {
        $provider->chat([new UserMessage('x')]);
        $this->fail('no exception');
    } catch (ProviderException $e) {
        expect($e->getMessage())->toContain('No models found for provider "ollama"')
            ->and($e->getPrevious())->toBe($cause);
    }
});

it('structured() asks for a JSON reply against the schema and returns the Response, as the vendored providers do', function (): void {
    $client = fakeWpAiClient(replies: [['content' => '{"a":1}']]);
    $provider = new WpAiClientProvider('m', $client);
    $schema = '{"type":"object","properties":{"a":{"type":"integer"}}}';
    $result = $provider->structured([new UserMessage('x')], $schema, ['temperature' => 0]);
    expect($result)->toBeInstanceOf(Response::class)
        ->and($result->content)->toBe('{"a":1}')
        ->and($client->requests[0]['json'])->toBe(['type' => 'object', 'properties' => ['a' => ['type' => 'integer']]])
        ->and($client->requests[0]['temperature'])->toBe(0.0);
    // A schema that is not JSON still asks for JSON, unconstrained.
    $provider->structured([new UserMessage('x')], 'not json');
    expect($client->requests[1]['json'])->toBeTrue();
});

it('models() returns ModelDefinitions under the core provider\'s id with the tool and vision flags the client reports, and isAvailable() is the client plus at least one model', function (): void {
    $client = fakeWpAiClient(models: [
        ['id' => 'qwen3:8b', 'name' => 'qwen3:8b', 'provider' => 'ollama', 'provider_name' => 'Ollama', 'tools' => true, 'vision' => false],
        ['id' => 'llava:7b', 'name' => 'llava:7b', 'provider' => 'ollama', 'provider_name' => 'Ollama', 'tools' => false, 'vision' => true],
    ]);
    $models = (new WpAiClientProvider('m', $client))->models();
    expect($models)->toHaveCount(2)
        ->and($models[0])->toBeInstanceOf(ModelDefinition::class)
        ->and($models[0]->id)->toBe('qwen3:8b')
        ->and($models[0]->name)->toBe('qwen3:8b (Ollama)')
        ->and($models[0]->provider)->toBe('ollama')
        ->and($models[0]->supportsToolCalls())->toBeTrue()
        ->and($models[0]->supportsVision())->toBeFalse()
        ->and($models[1]->supportsToolCalls())->toBeFalse()
        ->and($models[1]->supportsVision())->toBeTrue();
    expect((new WpAiClientProvider('m', $client))->isAvailable())->toBeTrue()
        ->and((new WpAiClientProvider('m', fakeWpAiClient(models: [])))->isAvailable())->toBeFalse()
        ->and((new WpAiClientProvider('m', fakeWpAiClient(available: false, models: $client->models())))->isAvailable())->toBeFalse();
});

it('models() and isAvailable() read as empty and false when the client is unavailable or throws, never as a fatal', function (): void {
    $broken = fakeWpAiClient(models: [['id' => 'x', 'name' => 'x', 'provider' => 'p', 'provider_name' => 'P', 'tools' => false, 'vision' => false]]);
    $broken = new class ($broken) implements Client {
        public function __construct(private Client $inner) {}

        public function available(): bool
        {
            return true;
        }

        public function models(): array
        {
            throw new RuntimeException('registry exploded');
        }

        public function generate(array $request): array
        {
            return $this->inner->generate($request);
        }
    };
    expect((new WpAiClientProvider('m', $broken))->models())->toBe([])
        ->and((new WpAiClientProvider('m', $broken))->isAvailable())->toBeFalse()
        ->and((new WpAiClientProvider('m', fakeWpAiClient(available: false)))->models())->toBe([]);
});
