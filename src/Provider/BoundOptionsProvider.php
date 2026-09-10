<?php

declare(strict_types=1);

namespace AlpacaBot\Provider;

use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Contract\ProviderInterface;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Provider\Response;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Provider\Usage;

/**
 * A provider with the site's generation options already bound to it: every chat(), stream()
 * and structured() call goes to the wrapped provider with those options filled in. It also
 * keeps a running tally of the usage those calls report (usage()).
 *
 * The pipeline passes `temperature`, `num_ctx` and `keep_alive` (Store::modelOverrides()) as
 * the options argument of the one stream() call a plain turn makes. A tool turn is run by the
 * vendored AbstractAgent, whose loop calls `stream($messages, $tools)` with no options argument
 * at all, so a provider handed to it bare would run every tool turn on the provider's defaults
 * while the plain turn ran on the site's settings: a temperature an administrator set would
 * apply to one kind of turn and not the other, silently. Binding the options at the provider
 * closes that for every call the agent makes, however many iterations it takes, and leaves the
 * vendored library untouched; the alternative, a subclass of the agent overriding run(), would
 * mean copying a three-hundred-line loop to change one argument.
 *
 * A caller's own options win over the bound ones (`$options + $bound`): the bound set is the
 * site's default for calls that say nothing, not an override of a caller that knows better.
 * withModel() rebinds the same options over the provider's new model, so the contract holds
 * across the clone the vendored providers return; the clone starts its own tally.
 *
 * The tally is for the run that does not finish. The agent reports usage once, summed over
 * every call, in the Output it returns at the end of run(); a consumer that walks away
 * mid-run (a tab closed while the reply streams) never lets the run get there, and the
 * pipeline would then bill nothing for calls the provider had already answered, which is the
 * monthly cap's only input. This is the one object every call of the run goes through, so the
 * tally is kept here as the chunks pass: within one stream() the max of each field, since some
 * providers report prompt and completion tokens on separate chunks (the vendored loop merges
 * the same way); across calls the figures add up, as the agent's own total does. The pipeline
 * reads it before every delta it yields and bills the last reading when the run is abandoned;
 * when the run finishes, the agent's Output is the authority and the tally is not read.
 *
 * @since 0.5.0
 */
final class BoundOptionsProvider implements ProviderInterface
{
    private int $promptTokens = 0;

    private int $completionTokens = 0;

    /** @param array<string, mixed> $options the generation options every call carries unless it names them itself */
    public function __construct(private ProviderInterface $inner, private array $options) {}

    public function chat(array $messages, array $tools = [], array $options = []): Response
    {
        $response = $this->inner->chat($messages, $tools, $options + $this->options);
        if ($response->usage !== null) {
            $this->promptTokens += $response->usage->promptTokens;
            $this->completionTokens += $response->usage->completionTokens;
        }
        return $response;
    }

    /**
     * A generator over the inner stream, so each chunk's usage is tallied as it passes; a
     * caller that stops iterating keeps what had been tallied up to its last chunk.
     *
     * @return \Generator<int, Response>
     */
    public function stream(array $messages, array $tools = [], array $options = []): \Generator
    {
        $promptBefore = $this->promptTokens;
        $completionBefore = $this->completionTokens;
        $prompt = 0;
        $completion = 0;
        foreach ($this->inner->stream($messages, $tools, $options + $this->options) as $chunk) {
            if ($chunk->usage !== null) {
                $prompt = max($prompt, $chunk->usage->promptTokens);
                $completion = max($completion, $chunk->usage->completionTokens);
                $this->promptTokens = $promptBefore + $prompt;
                $this->completionTokens = $completionBefore + $completion;
            }
            yield $chunk;
        }
    }

    public function structured(array $messages, string $schema, array $options = []): mixed
    {
        return $this->inner->structured($messages, $schema, $options + $this->options);
    }

    public function models(): array
    {
        return $this->inner->models();
    }

    public function isAvailable(): bool
    {
        return $this->inner->isAvailable();
    }

    public function getModel(): string
    {
        return $this->inner->getModel();
    }

    public function withModel(string $model): static
    {
        return new self($this->inner->withModel($model), $this->options);
    }

    /**
     * The usage every chat() and stream() call through this provider has reported so far,
     * summed; structured() reports none. Zero until the first call reports.
     */
    public function usage(): Usage
    {
        return new Usage($this->promptTokens, $this->completionTokens, $this->promptTokens + $this->completionTokens);
    }
}
