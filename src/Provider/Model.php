<?php

declare(strict_types=1);

namespace AlpacaBot\Provider;

use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Config\ModelDefinition;

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
     */
    public static function fromId(string $id): self
    {
        $n = strtolower($id);
        $vision = (bool) preg_match('/llava|vision|moondream|minicpm-v|gemma3|qwen.*vl|bakllava/', $n);
        $tools = !(bool) preg_match('/llava|moondream|bakllava/', $n);
        $thinking = (bool) preg_match('/qwen3|deepseek-r1|gpt-oss|magistral|think/', $n);
        return new self($id, $id, $tools, $vision, $thinking);
    }

    /** What the provider discovered, raised over the name heuristic (discovery never lowers a flag). */
    public static function fromDefinition(ModelDefinition $definition): self
    {
        $model = self::fromId($definition->id);
        $model->tools = $model->tools || $definition->supportsToolCalls();
        $model->vision = $model->vision || $definition->supportsVision();
        $model->thinking = $model->thinking || $definition->thinking;
        return $model;
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
