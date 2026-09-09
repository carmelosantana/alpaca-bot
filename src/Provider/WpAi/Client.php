<?php

declare(strict_types=1);

namespace AlpacaBot\Provider\WpAi;

/**
 * What Provider\WpAiClientProvider needs from WordPress's AI client, as arrays.
 *
 * The seam is here rather than at core's prompt builder because the builder is not the whole
 * surface. A multi-turn prompt is a list of core `Message` DTOs; an image is a core `File`; a
 * tool is a core `FunctionDeclaration`; the model is a `ModelInterface` fetched from core's
 * registry; the result is a `GenerativeAiResult` walked part by part. A fake builder in a unit
 * test would still leave the adapter constructing six core classes that do not exist in that
 * process. So the adapter speaks arrays, in the shapes core's own `fromArray()` methods accept
 * (a message is `{role, parts}`, a part `{type: text|file|function_call|function_response, ...}`),
 * and the one implementation that touches core (CoreClient) is covered where core is present,
 * by the integration suite. The cost is the array shapes below, typed once and imported by
 * both sides.
 *
 * @phpstan-type WpAiModel array{id: string, name: string, provider: string, provider_name: string, tools: bool, vision: bool}
 * @phpstan-type WpAiMessage array{role: string, parts: list<array<string, mixed>>}
 * @phpstan-type WpAiTool array{name: string, description: string, parameters?: array<string, mixed>}
 * @phpstan-type WpAiRequest array{model: string, messages: list<WpAiMessage>, system?: string, temperature?: float, max_tokens?: int, tools?: list<WpAiTool>, json?: array<string, mixed>|true}
 * @phpstan-type WpAiToolCall array{id: string, name: string, arguments: array<string, mixed>}
 * @phpstan-type WpAiReply array{content: string, reasoning: string, tool_calls: list<WpAiToolCall>, finish_reason: string, prompt_tokens: int, completion_tokens: int, total_tokens: int}
 *
 * @since 0.5.0
 */
interface Client
{
    /** Whether core's AI client is loaded at all (WordPress 7.0+). Cheap, side-effect free, safe on plugins_loaded. */
    public function available(): bool;

    /**
     * The chat-capable models every configured core provider offers, in registry order.
     *
     * @return list<WpAiModel> empty when unavailable, when AI is switched off for the site, or when no provider is configured
     */
    public function models(): array;

    /**
     * One non-streamed generation.
     *
     * @param WpAiRequest $request
     * @return WpAiReply
     * @throws \RuntimeException when the client is unavailable, no configured provider offers the model, or core answers with an error
     */
    public function generate(array $request): array;
}
