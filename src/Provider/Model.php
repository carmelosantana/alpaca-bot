<?php

declare(strict_types=1);

namespace AlpacaBot\Provider;

use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Config\ModelDefinition;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Enum\ModelCapability;

/**
 * One chat model as the plugin sees it: an id plus the capability flags the UI and pipeline care about.
 */
final class Model
{
    public function __construct(
        public string $id,
        public string $label,
        public bool $tools = false,
        public bool $vision = false,
        public bool $thinking = false,
    ) {}

    /**
     * Capability flags from the model name alone. Ollama only reports capabilities when
     * `/api/show` answers, and OpenAI-compatible `/v1/models` never does, so the name is
     * the floor every model gets. Embedding models are not flagged here: ModelCatalog
     * drops them from the list outright before any flag is read.
     *
     * `tools` is not guessed from the name: a name says nothing about whether the deployed
     * template can parse a tool call, and the three-name denylist this replaces was wrong in
     * both directions. It is a floor, not a guess — true, because it is only reached where the
     * provider described nothing (fromDefinition()), and reading silence as "no tools" would
     * switch tools off for every model on such a provider. `vision` and `thinking` are still
     * guessed by name, and deliberately so: neither routes a turn, and a wrong guess costs a
     * badge in the picker rather than a failed reply.
     */
    public static function fromId(string $id): self
    {
        $n = strtolower($id);
        $vision = (bool) preg_match('/llava|vision|moondream|minicpm-v|gemma3|qwen.*vl|bakllava/', $n);
        $thinking = (bool) preg_match('/qwen3|deepseek-r1|gpt-oss|magistral|think/', $n);
        return new self($id, $id, true, $vision, $thinking);
    }

    /**
     * What the provider discovered. `vision` and `thinking` are raised over the name heuristic
     * and never lowered by it; `tools` is the provider's to set, and discovery lowers it.
     *
     * The rule for `tools` changed in 0.5.0. It used to be raised only, over a denylist of three
     * model names, so a model the provider described as unable to call tools was offered them
     * anyway, wrote the call out as prose, and the answer had to be recovered afterwards. The
     * reason the old rule gave — that discovery is unreliable enough that a `false` from it
     * should never cost a working model its tools — held only while nothing could tell the two
     * apart. Now the two spellings the library accepts (`toolCalls`, the `Tools` capability) are
     * taken as the answer wherever the definition is a description at all.
     *
     * "At all" is the whole of the judgement here, and it is a judgement about an ambiguity the
     * library cannot express: ModelDefinition has no "unknown". A provider that reports no
     * capabilities (an OpenAI-compatible `/v1/models`, an Ollama too old for `capabilities`, or
     * one whose `/api/show` timed out for this model) builds exactly the same object as a
     * provider describing a model with none. declaresCapabilities() reads a definition carrying
     * no capability signal whatever as the first of those, and the name floor stands. The cost
     * is that a described-but-featureless model — text only, no tools, no vision, no thinking —
     * still gets tools offered; the gain is that a provider that says nothing does not lose them
     * everywhere. `models.overrides[<model>][tools]` is the operator's answer to the cost, read
     * per turn in Chat\Pipeline::toolkitsFor().
     */
    public static function fromDefinition(ModelDefinition $definition): self
    {
        $model = self::fromId($definition->id);
        if (self::declaresCapabilities($definition)) {
            $model->tools = $definition->supportsToolCalls();
        }
        $model->vision = $model->vision || $definition->supportsVision();
        $model->thinking = $model->thinking || $definition->thinking;
        return $model;
    }

    /**
     * True when the definition carries any capability signal at all, in any of the spellings the
     * library fills in: the flags, or a capability list holding more than plain text. False is
     * "the provider told us nothing", which is as close as ModelDefinition comes to saying so.
     */
    private static function declaresCapabilities(ModelDefinition $definition): bool
    {
        if ($definition->supportsToolCalls() || $definition->supportsVision() || $definition->thinking || $definition->reasoning) {
            return true;
        }
        return array_filter($definition->capabilities, static fn(ModelCapability $c): bool => $c !== ModelCapability::Text) !== [];
    }

    /** @param array<string, mixed> $a the shape toArray() produces (the transient payload) */
    public static function fromArray(array $a): self
    {
        $id = (string) ($a['id'] ?? '');
        return new self(
            $id,
            (string) ($a['label'] ?? $id),
            (bool) ($a['tools'] ?? false),
            (bool) ($a['vision'] ?? false),
            (bool) ($a['thinking'] ?? false),
        );
    }

    /** @return array{id: string, label: string, tools: bool, vision: bool, thinking: bool} */
    public function toArray(): array
    {
        return ['id' => $this->id, 'label' => $this->label, 'tools' => $this->tools, 'vision' => $this->vision, 'thinking' => $this->thinking];
    }
}
