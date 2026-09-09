<?php

declare(strict_types=1);

use AlpacaBot\Provider\Model;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Config\ModelDefinition;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Enum\ModelCapability;

/** A ModelDefinition as a provider that reports nothing about capabilities hands one over. */
function bareDefinition(string $id): ModelDefinition
{
    return new ModelDefinition(id: $id, name: $id, provider: 'ollama');
}

it('takes the provider at its word when it credits a model with tools', function (): void {
    // Both spellings the library accepts: the flag, and the capability in the list.
    expect(Model::fromDefinition(new ModelDefinition(id: 'qwen3:8b', name: 'qwen3', provider: 'ollama', toolCalls: true))->tools)->toBeTrue()
        ->and(Model::fromDefinition(new ModelDefinition(id: 'qwen3:8b', name: 'qwen3', provider: 'ollama', capabilities: [ModelCapability::Text, ModelCapability::Tools]))->tools)->toBeTrue();
});

// The case the name denylist got wrong: a provider that describes the model and does not put
// tools in the description. Discovery lowers the flag here, which it never used to do.
it('lowers the flag when the provider describes a model and does not credit it with tools', function (): void {
    $vision = new ModelDefinition(id: 'gemma3:4b', name: 'gemma3', provider: 'ollama', vision: true);
    $thinking = new ModelDefinition(id: 'deepseek-r1:7b', name: 'deepseek-r1', provider: 'ollama', thinking: true);
    $listed = new ModelDefinition(id: 'phi4:14b', name: 'phi4', provider: 'ollama', capabilities: [ModelCapability::Text, ModelCapability::Image]);
    expect(Model::fromDefinition($vision)->tools)->toBeFalse()
        ->and(Model::fromDefinition($vision)->vision)->toBeTrue()
        ->and(Model::fromDefinition($thinking)->tools)->toBeFalse()
        ->and(Model::fromDefinition($listed)->tools)->toBeFalse();
});

// A deliberate choice, not an oversight: ModelDefinition cannot say "unknown", so a provider
// that reports no capability data at all is indistinguishable from one describing a model with
// none. Reading that as "no tools" would switch tools off for every model on such a provider,
// which is a wider break than the mislabelling this replaces. The floor stays optimistic and
// the per-model override is the operator's lever.
it('keeps the optimistic floor for a model the provider says nothing about', function (): void {
    expect(Model::fromDefinition(bareDefinition('mystery:1b'))->tools)->toBeTrue()
        ->and(Model::fromDefinition(bareDefinition('mystery:1b'))->vision)->toBeFalse()
        ->and(Model::fromDefinition(bareDefinition('mystery:1b'))->thinking)->toBeFalse()
        // An empty capability list is no description either, not a description of nothing.
        ->and(Model::fromDefinition(new ModelDefinition(id: 'mystery:1b', name: 'm', provider: 'ollama', capabilities: []))->tools)->toBeTrue();
});

// A provider that builds its own ModelDefinition can say "this `false` was reported" where the
// flags alone cannot: `fieldSources['toolCalls']` names where the tool flag came from. That is a
// description, so discovery lowers the flag on it even though no capability is set. The plugin's
// own WP AI adapter is the caller that needs it (Provider\WpAiClientProvider::models()).
it('reads a declared provenance for the tool flag as the provider describing the model', function (): void {
    $declared = new ModelDefinition(id: 'plain:1b', name: 'plain', provider: 'wp-ai', toolCalls: false, vision: false, fieldSources: ['toolCalls' => 'provider-api']);
    expect(Model::fromDefinition($declared)->tools)->toBeFalse()
        // The arm is that one key and not "any provenance at all": ModelDefinition::fromDiscovery()
        // fills contextWindow and maxTokens for every model it builds, including a bare
        // /v1/models row, and neither says a word about tools.
        ->and(Model::fromDefinition(new ModelDefinition(id: 'mystery:1b', name: 'm', provider: 'ollama', fieldSources: ['contextWindow' => 'provider-inspection', 'maxTokens' => 'heuristic']))->tools)->toBeTrue();
});

// llava, moondream and bakllava were the three names hard-coded as tool-less. The provider's
// word is all that decides now: the real /api/show for each of them reports vision, so they
// still come out tool-less, but by data rather than by name.
it('no longer special-cases llava, moondream and bakllava by name', function (): void {
    foreach (['llava:7b', 'moondream:latest', 'bakllava:latest'] as $id) {
        expect(Model::fromId($id)->tools)->toBeTrue($id)
            ->and(Model::fromDefinition(bareDefinition($id))->tools)->toBeTrue($id)
            ->and(Model::fromDefinition(new ModelDefinition(id: $id, name: $id, provider: 'ollama', vision: true))->tools)->toBeFalse($id)
            ->and(Model::fromDefinition(new ModelDefinition(id: $id, name: $id, provider: 'ollama', toolCalls: true, vision: true))->tools)->toBeTrue($id);
    }
});

// Out of scope for this change and pinned so it stays that way: vision and thinking are still
// guessed from the name, and discovery may still only raise them.
it('leaves the vision and thinking name heuristics alone, discovery raising them and never lowering', function (): void {
    expect(Model::fromId('llava:7b')->vision)->toBeTrue()
        ->and(Model::fromId('qwen3:8b')->thinking)->toBeTrue()
        ->and(Model::fromId('llama3.2:latest')->vision)->toBeFalse()
        ->and(Model::fromId('llama3.2:latest')->thinking)->toBeFalse()
        // A provider that describes llava without vision does not take the name's word away.
        ->and(Model::fromDefinition(new ModelDefinition(id: 'llava:7b', name: 'llava', provider: 'ollama', toolCalls: true))->vision)->toBeTrue()
        ->and(Model::fromDefinition(new ModelDefinition(id: 'qwen3:8b', name: 'qwen3', provider: 'ollama', toolCalls: true))->thinking)->toBeTrue()
        ->and(Model::fromDefinition(new ModelDefinition(id: 'mystery:1b', name: 'm', provider: 'ollama', vision: true, thinking: true))->vision)->toBeTrue()
        ->and(Model::fromDefinition(new ModelDefinition(id: 'mystery:1b', name: 'm', provider: 'ollama', vision: true, thinking: true))->thinking)->toBeTrue();
});
