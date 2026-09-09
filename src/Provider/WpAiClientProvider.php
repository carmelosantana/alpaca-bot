<?php

declare(strict_types=1);

namespace AlpacaBot\Provider;

use AlpacaBot\Provider\WpAi\Client;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Config\ModelDefinition;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Contract\MessageInterface;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Contract\ProviderInterface;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Contract\ToolInterface;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Enum\ModelCapability;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Enum\ProviderFinishReason;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Enum\Role;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Exception\ProviderException;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Message\ToolResultMessage;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Provider\Response;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Provider\Usage;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Tool\ToolCall;

/**
 * The php-agents provider over WordPress's own AI client (WordPress 7.0+): the bot's turn goes
 * to whichever provider plugin the site has registered with core, instead of to Ollama directly.
 * Factory::make() builds one when `provider.kind` is `wp-ai` and core has the client.
 *
 * Core's client does not stream. WordPress 7.1 vendors php-ai-client 1.3.1, which has no
 * stream method on the builder, the model contracts or the result; the WordPress wrapper adds
 * none. So stream() is chat() as a one-chunk generator: Chat\Pipeline and the vendored
 * AbstractAgent both iterate chunks reading `content`, `reasoning`, `toolCalls` and `usage`,
 * and the whole Response is one such chunk. The reply appears at once rather than as it is
 * produced, which is the cost of this kind; the settings copy says so.
 *
 * Tool calls are supported, mapped one-to-one: a php-agents tool's function schema becomes a
 * core function declaration, a function call part in the reply becomes a ToolCall, and the
 * history the agent loop sends back (an assistant turn with calls, a tool result) becomes a
 * model message with function-call parts and a user message with one function-response part,
 * which is the only shape core accepts for a tool result. The alternative, dropping `$tools`
 * and reporting no tool capability, would not have kept the agent off the tools path: the
 * catalogue's tool flag is a name heuristic that discovery may raise but never lower
 * (Provider\Model::fromDefinition()), so the agent would have offered tools the adapter then
 * swallowed, and a "Tools" setting that did nothing. Whether a given core provider honours the
 * declarations is that provider's business; the model list reports what its metadata claims.
 *
 * Of the site's generation options only `temperature` is sent. `num_ctx` and `keep_alive` are
 * Ollama's, and a core provider that is not Ollama would either reject them or ignore them;
 * the core provider plugin is where an Ollama host's own options belong.
 *
 * The adapter talks to core through WpAi\Client (arrays in core's `fromArray()` shapes), which
 * is where the unit tests fake it; WpAi\Client says why the seam is there and not at core's
 * prompt builder.
 *
 * @phpstan-import-type WpAiRequest from Client
 * @phpstan-import-type WpAiReply from Client
 *
 * @since 0.5.0
 */
final class WpAiClientProvider implements ProviderInterface
{
    public function __construct(private string $model, private Client $client) {}

    /**
     * @throws ProviderException when core's client is absent, no provider offers the model, or core refuses the turn
     */
    public function chat(array $messages, array $tools = [], array $options = []): Response
    {
        return $this->send($messages, $tools, $options, null);
    }

    /**
     * chat() as a single chunk, sent when the generator is first advanced (as every provider's
     * stream is), not when stream() is called.
     *
     * @return \Generator<int, Response>
     */
    public function stream(array $messages, array $tools = [], array $options = []): \Generator
    {
        yield $this->chat($messages, $tools, $options);
    }

    /**
     * A JSON reply constrained to `$schema`, or plain JSON when `$schema` does not parse. Returns
     * the Response, as the vendored OpenAI-compatible providers do, so a caller that switches
     * `provider.kind` gets the same type either way.
     */
    public function structured(array $messages, string $schema, array $options = []): mixed
    {
        $decoded = json_decode($schema, true);
        return $this->send($messages, [], $options, is_array($decoded) ? $decoded : true);
    }

    /**
     * The models every configured core provider offers, under that provider's id. Empty, never
     * an exception, when core is absent or its registry fails: ModelCatalog reads an empty list
     * as "provider unreachable" and leaves it uncached, which is the right reading of both.
     */
    public function models(): array
    {
        try {
            if (!$this->client->available()) {
                return [];
            }
            $models = [];
            foreach ($this->client->models() as $m) {
                $capabilities = [ModelCapability::Text];
                if ($m['vision']) {
                    $capabilities[] = ModelCapability::Image;
                }
                if ($m['tools']) {
                    $capabilities[] = ModelCapability::Tools;
                }
                $models[] = new ModelDefinition(
                    id: $m['id'],
                    name: sprintf('%s (%s)', $m['name'], $m['provider_name']),
                    provider: $m['provider'],
                    capabilities: $capabilities,
                    toolCalls: $m['tools'],
                    vision: $m['vision'],
                );
            }
            return $models;
        } catch (\Throwable) {
            return [];
        }
    }

    /** Core is present and at least one configured provider lists a chat model. */
    public function isAvailable(): bool
    {
        return $this->client->available() && $this->models() !== [];
    }

    public function getModel(): string
    {
        return $this->model;
    }

    public function withModel(string $model): static
    {
        return new self($model, $this->client);
    }

    /**
     * @param MessageInterface[] $messages
     * @param ToolInterface[] $tools
     * @param array<string, mixed> $options
     * @param array<string, mixed>|true|null $json a schema, `true` for unconstrained JSON, null for text
     */
    private function send(array $messages, array $tools, array $options, array|true|null $json): Response
    {
        if (!$this->client->available()) {
            throw ProviderException::chatFailed(__('the WordPress AI client is not available on this site; it needs WordPress 7.0 or later', 'alpaca-bot'));
        }
        try {
            $reply = $this->client->generate($this->request($messages, $tools, $options, $json));
        } catch (\Exception $e) {
            throw ProviderException::chatFailed($e->getMessage(), $e);
        }
        return $this->response($reply);
    }

    /**
     * @param MessageInterface[] $messages
     * @param ToolInterface[] $tools
     * @param array<string, mixed> $options
     * @param array<string, mixed>|true|null $json
     * @return WpAiRequest
     */
    private function request(array $messages, array $tools, array $options, array|true|null $json): array
    {
        $request = ['model' => $this->model, 'messages' => []];
        $system = [];
        // Tool call id => name, from the assistant turns seen so far, so the function response
        // for a result can carry the name core's DTO wants alongside the id.
        $names = [];
        foreach ($messages as $message) {
            switch ($message->role()) {
                case Role::System:
                    $system[] = (string) $message->content();
                    break;
                case Role::User:
                    $parts = self::userParts($message->content());
                    if ($parts !== []) {
                        $request['messages'][] = ['role' => 'user', 'parts' => $parts];
                    }
                    break;
                case Role::Assistant:
                    $parts = [];
                    $content = $message->content();
                    if (is_string($content) && $content !== '') {
                        $parts[] = ['type' => 'text', 'text' => $content];
                    }
                    foreach ($message->toolCalls() as $call) {
                        $names[$call->id] = $call->name;
                        // Core's FunctionCall keeps `null` for "no arguments" and its OpenAI
                        // mapping sends that as `{}`; an empty PHP array would encode as `[]`.
                        $parts[] = ['type' => 'function_call', 'functionCall' => ['id' => $call->id, 'name' => $call->name, 'args' => $call->arguments === [] ? null : $call->arguments]];
                    }
                    if ($parts !== []) {
                        $request['messages'][] = ['role' => 'model', 'parts' => $parts];
                    }
                    break;
                case Role::Tool:
                    $id = $message->toolCallId() ?? '';
                    $response = ['id' => $id];
                    if (isset($names[$id])) {
                        $response['name'] = $names[$id];
                    }
                    $response['response'] = self::toolResponse($message);
                    $request['messages'][] = ['role' => 'user', 'parts' => [['type' => 'function_response', 'functionResponse' => $response]]];
                    break;
            }
        }
        if ($system !== []) {
            $request['system'] = implode("\n\n", $system);
        }
        if (isset($options['temperature']) && is_numeric($options['temperature'])) {
            $request['temperature'] = (float) $options['temperature'];
        }
        if (isset($options['max_tokens']) && is_int($options['max_tokens'])) {
            $request['max_tokens'] = $options['max_tokens'];
        }
        if ($tools !== []) {
            $request['tools'] = array_values(array_map(static function (ToolInterface $tool): array {
                $schema = $tool->toFunctionSchema()['function'];
                return ['name' => $schema['name'], 'description' => $schema['description'], 'parameters' => $schema['parameters']];
            }, $tools));
        }
        if ($json !== null) {
            $request['json'] = $json;
        }
        return $request;
    }

    /**
     * A user turn's parts in core's shape. The OpenAI content-parts form is what the pipeline
     * sends when images are attached (`image_url` parts carrying a data URL, which is all
     * Chat\ImageData admits) and what `UserMessage::withImages()` builds; a data URL is split
     * into core's inline file (mime plus base64), anything else is passed as a remote file.
     *
     * @param string|array<array{type: string, text?: string, image_url?: array<string, mixed>}> $content
     * @return list<array<string, mixed>>
     */
    private static function userParts(string|array $content): array
    {
        if (is_string($content)) {
            return $content === '' ? [] : [['type' => 'text', 'text' => $content]];
        }
        $parts = [];
        foreach ($content as $part) {
            if ($part['type'] === 'text' && isset($part['text']) && $part['text'] !== '') {
                $parts[] = ['type' => 'text', 'text' => $part['text']];
                continue;
            }
            $url = $part['image_url']['url'] ?? null;
            if ($part['type'] !== 'image_url' || !is_string($url) || $url === '') {
                continue;
            }
            if (preg_match('#^data:([^;,]+);base64,(.*)$#s', $url, $m) === 1) {
                $parts[] = ['type' => 'file', 'file' => ['fileType' => 'inline', 'mimeType' => $m[1], 'base64Data' => $m[2]]];
            } else {
                $parts[] = ['type' => 'file', 'file' => ['fileType' => 'remote', 'url' => $url]];
            }
        }
        return $parts;
    }

    /**
     * What a tool answered, as core will encode it onto the wire: a JSON result (ToolResult::json())
     * decoded, so it travels as the object it is rather than as a string holding JSON; any other
     * result as its text.
     */
    private static function toolResponse(MessageInterface $message): mixed
    {
        $content = (string) $message->content();
        if ($message instanceof ToolResultMessage && $message->result()->mimeType === 'application/json') {
            $decoded = json_decode($content, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        return $content;
    }

    /**
     * Core's finish reasons onto php-agents': `length` is the token limit, `error` an error, a
     * reply that carries calls is ToolUse whatever core said (the agent loop reads the calls
     * themselves, but a consumer reading the reason should agree with them), and the rest
     * (`stop`, `content_filter`, which still carries what the provider allowed) is Stop.
     *
     * @param WpAiReply $reply
     */
    private function response(array $reply): Response
    {
        $calls = array_map(static fn(array $c): ToolCall => new ToolCall($c['id'], $c['name'], $c['arguments']), $reply['tool_calls']);
        $finish = $calls !== [] ? ProviderFinishReason::ToolUse : match ($reply['finish_reason']) {
            'length' => ProviderFinishReason::MaxTokens,
            'error' => ProviderFinishReason::Error,
            default => ProviderFinishReason::Stop,
        };
        return new Response(
            content: $reply['content'],
            finishReason: $finish,
            toolCalls: $calls,
            model: $this->model,
            usage: new Usage($reply['prompt_tokens'], $reply['completion_tokens'], $reply['total_tokens']),
            reasoning: $reply['reasoning'],
        );
    }
}
