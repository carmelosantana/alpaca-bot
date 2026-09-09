<?php

declare(strict_types=1);

namespace AlpacaBot\Provider\WpAi;

use WordPress\AiClient\AiClient;
use WordPress\AiClient\Messages\DTO\Message;
use WordPress\AiClient\Providers\DTO\ProviderMetadata;
use WordPress\AiClient\Providers\Models\DTO\ModelConfig;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Providers\Models\DTO\ModelRequirements;
use WordPress\AiClient\Providers\Models\Enums\CapabilityEnum;
use WordPress\AiClient\Results\DTO\GenerativeAiResult;
use WordPress\AiClient\Tools\DTO\FunctionDeclaration;

/**
 * The Client over the real thing: `wp_ai_client_prompt()` and core's default provider registry.
 *
 * Every method starts from available(), and available() is two injected probes (class_exists()
 * and function_exists() by default): Brain Monkey cannot stub either, and the "core has no AI
 * client" branch is the one that must never fatal, so it has to be reachable from a test. Both
 * names are probed, not one: the builder class and the function that makes one are loaded
 * together by wp-includes/ai-client.php, and a site where only one exists is not a site this
 * code should treat as WordPress 7.0.
 *
 * The WordPress wrapper (`WP_AI_Client_Prompt_Builder`) is used rather than the SDK's
 * `AiClient::prompt()` underneath it, so a site's own controls apply: `wp_supports_ai()` (the
 * WP_AI_SUPPORT constant and its filter), the `wp_ai_client_prevent_prompt` filter, and the
 * `wp_ai_client_default_request_timeout` filter. The wrapper answers a WP_Error instead of
 * throwing; generate() turns that back into an exception, since the adapter's contract with
 * php-agents is exceptions.
 *
 * The model is named explicitly (`using_model()` with the registry's model instance), never
 * left to `using_model_preference()`. A preference that matches no candidate makes the builder
 * fall back to the first model of any configured provider, silently: a site whose stored default
 * is an Ollama name, switched to a core OpenAI provider, would chat against whatever that
 * provider listed first and never be told. Naming the model also skips the builder's
 * requirements check, under which an option the model's metadata does not declare (a provider
 * plugin that forgot to list `temperature`) rejects the model with the same silent fallback.
 *
 * Finding which provider offers the model costs one model listing per configured provider per
 * call, which is what core's own `wp_ai_client_prompt()->generate_text()` pays on every call
 * too (its candidate map is the same listing). Core caches the listing through its
 * WP_AI_Client_Cache adapter, `wp_cache_*` with a 24-hour TTL, so a site with a persistent
 * object cache pays it once a day and a site without one pays it per request; ModelCatalog's
 * five-minute transient keeps the plugin's own listing off that path.
 *
 * @phpstan-import-type WpAiModel from Client
 * @phpstan-import-type WpAiRequest from Client
 * @phpstan-import-type WpAiReply from Client
 *
 * @since 0.5.0
 */
final class CoreClient implements Client
{
    private const BUILDER_CLASS = 'WP_AI_Client_Prompt_Builder';
    private const BUILDER_FUNCTION = 'wp_ai_client_prompt';

    /** @var \Closure(string): bool */
    private \Closure $classExists;

    /** @var \Closure(string): bool */
    private \Closure $functionExists;

    /**
     * @param (callable(string): bool)|null $classExists class_exists() or a stand-in for it
     * @param (callable(string): bool)|null $functionExists function_exists() or a stand-in for it
     */
    public function __construct(?callable $classExists = null, ?callable $functionExists = null)
    {
        $this->classExists = $classExists === null ? class_exists(...) : \Closure::fromCallable($classExists);
        $this->functionExists = $functionExists === null ? function_exists(...) : \Closure::fromCallable($functionExists);
    }

    public function available(): bool
    {
        return ($this->classExists)(self::BUILDER_CLASS) && ($this->functionExists)(self::BUILDER_FUNCTION);
    }

    public function models(): array
    {
        if (!$this->available() || !wp_supports_ai()) {
            return [];
        }
        $models = [];
        foreach ($this->textModels() as [$provider, $model]) {
            $models[] = [
                'id' => $model->getId(),
                'name' => $model->getName(),
                'provider' => $provider->getId(),
                'provider_name' => $provider->getName(),
                'tools' => self::supportsOption($model, ModelConfig::KEY_FUNCTION_DECLARATIONS),
                'vision' => self::acceptsImages($model),
            ];
        }
        return $models;
    }

    public function generate(array $request): array
    {
        if (!$this->available()) {
            throw new \RuntimeException(__('The WordPress AI client is not available on this site; it needs WordPress 7.0 or later.', 'alpaca-bot'));
        }
        $providerId = null;
        foreach ($this->textModels() as [$provider, $model]) {
            if ($model->getId() === $request['model']) {
                $providerId = $provider->getId();
                break;
            }
        }
        if ($providerId === null) {
            throw new \RuntimeException(sprintf(
                /* translators: %s: a model id */
                __('No WordPress AI provider offers the model "%s". Install and configure an AI provider plugin, or choose a model that provider lists.', 'alpaca-bot'),
                $request['model'],
            ));
        }

        try {
            $builder = wp_ai_client_prompt(array_map(Message::fromArray(...), $request['messages']))
                ->using_model(AiClient::defaultRegistry()->getProviderModel($providerId, $request['model']));
            if (isset($request['system'])) {
                $builder = $builder->using_system_instruction($request['system']);
            }
            if (isset($request['temperature'])) {
                $builder = $builder->using_temperature($request['temperature']);
            }
            if (isset($request['max_tokens'])) {
                $builder = $builder->using_max_tokens($request['max_tokens']);
            }
            if (isset($request['tools']) && $request['tools'] !== []) {
                $builder = $builder->using_function_declarations(...array_map(FunctionDeclaration::fromArray(...), $request['tools']));
            }
            if (isset($request['json'])) {
                $builder = $builder->as_json_response($request['json'] === true ? null : $request['json']);
            }
            $result = $builder->generate_text_result();
        } catch (\Exception $e) {
            // Core's DTOs refuse a malformed shape with their own InvalidArgumentException, and
            // the registry refuses an unknown model the same way; both are a failed turn, not a
            // crash, so they reach the adapter as the one exception type it wraps.
            throw new \RuntimeException($e->getMessage(), 0, $e);
        }
        if ($result instanceof \WP_Error) {
            throw new \RuntimeException($result->get_error_message());
        }
        return self::reply($result);
    }

    /**
     * Every model of every configured provider that can generate text, with its provider.
     *
     * The registry's own support query rather than a walk over the providers: it skips a
     * provider whose availability check fails (an Ollama host that is down, a key that is not
     * set), and it applies the capability filter, so an embedding or image model is never
     * offered as a chat model.
     *
     * @return list<array{ProviderMetadata, ModelMetadata}>
     */
    private function textModels(): array
    {
        $requirements = new ModelRequirements([CapabilityEnum::textGeneration()], []);
        $out = [];
        foreach (AiClient::defaultRegistry()->findModelsMetadataForSupport($requirements) as $providerModels) {
            foreach ($providerModels->getModels() as $model) {
                $out[] = [$providerModels->getProvider(), $model];
            }
        }
        return $out;
    }

    /**
     * `$option` is a ModelConfig::KEY_* constant: OptionEnum's values are built from those keys
     * at runtime (its own INPUT_MODALITIES constant included, which ModelConfig's camelCase
     * spelling overrides), so the key is the one spelling that is right by construction.
     */
    private static function supportsOption(ModelMetadata $model, string $option): bool
    {
        foreach ($model->getSupportedOptions() as $supported) {
            if ($supported->getName()->value === $option) {
                return true;
            }
        }
        return false;
    }

    /**
     * Whether any input-modality combination the model declares includes an image. Core's
     * `input_modalities` option lists combinations (`[[text], [text, image]]`), and a model with
     * no declared values for it accepts anything.
     */
    private static function acceptsImages(ModelMetadata $model): bool
    {
        foreach ($model->getSupportedOptions() as $supported) {
            if ($supported->getName()->value !== ModelConfig::KEY_INPUT_MODALITIES) {
                continue;
            }
            $combinations = $supported->getSupportedValues();
            if ($combinations === null) {
                return true;
            }
            foreach ($combinations as $combination) {
                foreach (is_array($combination) ? $combination : [$combination] as $modality) {
                    if (is_scalar($modality) || $modality instanceof \Stringable) {
                        if ((string) $modality === 'image') {
                            return true;
                        }
                    }
                }
            }
        }
        return false;
    }

    /**
     * The first candidate, part by part: text on the content channel is the reply, text on the
     * thought channel the reasoning (what a thinking model produced before answering, which the
     * pipeline stores and the caps count), function calls the tool calls.
     *
     * @return WpAiReply
     */
    private static function reply(GenerativeAiResult $result): array
    {
        $candidate = $result->getCandidates()[0];
        $content = '';
        $reasoning = '';
        $calls = [];
        foreach ($candidate->getMessage()->getParts() as $part) {
            $text = $part->getText();
            if ($text !== null) {
                if ((string) $part->getChannel() === 'thought') {
                    $reasoning .= $text;
                } else {
                    $content .= $text;
                }
                continue;
            }
            $call = $part->getFunctionCall();
            if ($call !== null) {
                $args = $call->getArgs();
                $calls[] = [
                    'id' => $call->getId() ?? '',
                    'name' => $call->getName() ?? '',
                    'arguments' => is_array($args) ? $args : (is_object($args) ? (array) $args : []),
                ];
            }
        }
        $usage = $result->getTokenUsage();
        return [
            'content' => $content,
            'reasoning' => $reasoning,
            'tool_calls' => $calls,
            'finish_reason' => (string) $candidate->getFinishReason(),
            'prompt_tokens' => $usage->getPromptTokens(),
            'completion_tokens' => $usage->getCompletionTokens(),
            'total_tokens' => $usage->getTotalTokens(),
        ];
    }
}
