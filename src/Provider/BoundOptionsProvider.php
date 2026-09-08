<?php

declare(strict_types=1);

namespace AlpacaBot\Provider;

use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Contract\ProviderInterface;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Provider\Response;

/**
 * A provider with the site's generation options already bound to it: every chat(), stream()
 * and structured() call goes to the wrapped provider with those options filled in.
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
 * across the clone the vendored providers return.
 *
 * @since 0.5.0
 */
final class BoundOptionsProvider implements ProviderInterface
{
    /** @param array<string, mixed> $options the generation options every call carries unless it names them itself */
    public function __construct(private ProviderInterface $inner, private array $options) {}

    public function chat(array $messages, array $tools = [], array $options = []): Response
    {
        return $this->inner->chat($messages, $tools, $options + $this->options);
    }

    public function stream(array $messages, array $tools = [], array $options = []): iterable
    {
        return $this->inner->stream($messages, $tools, $options + $this->options);
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
}
