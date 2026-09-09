<?php

declare(strict_types=1);

namespace AlpacaBot\Tests\Integration;

use AlpacaBot\Plugin;
use AlpacaBot\Provider\Factory;
use AlpacaBot\Provider\ModelCatalog;
use AlpacaBot\Provider\WpAi\CoreClient;
use AlpacaBot\Provider\WpAiClientProvider;
use AlpacaBot\Settings\Schema;
use AlpacaBot\Settings\Store;
use WordPress\AiClient\AiClient;
use WordPress\AiClient\Messages\DTO\Message;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\DTO\ModelMessage;
use WordPress\AiClient\Messages\Enums\MessagePartChannelEnum;
use WordPress\AiClient\Messages\Enums\ModalityEnum;
use WordPress\AiClient\Providers\AbstractProvider;
use WordPress\AiClient\Providers\Contracts\ModelMetadataDirectoryInterface;
use WordPress\AiClient\Providers\Contracts\ProviderAvailabilityInterface;
use WordPress\AiClient\Providers\DTO\ProviderMetadata;
use WordPress\AiClient\Providers\Enums\ProviderTypeEnum;
use WordPress\AiClient\Providers\Models\Contracts\ModelInterface;
use WordPress\AiClient\Providers\Models\DTO\ModelConfig;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Providers\Models\DTO\SupportedOption;
use WordPress\AiClient\Providers\Models\Enums\CapabilityEnum;
use WordPress\AiClient\Providers\Models\Enums\OptionEnum;
use WordPress\AiClient\Providers\Models\TextGeneration\Contracts\TextGenerationModelInterface;
use WordPress\AiClient\Results\DTO\Candidate;
use WordPress\AiClient\Results\DTO\GenerativeAiResult;
use WordPress\AiClient\Results\DTO\TokenUsage;
use WordPress\AiClient\Results\Enums\FinishReasonEnum;
use WordPress\AiClient\Tools\DTO\FunctionCall;

/**
 * The real glue over the real core AI client (WordPress 7.0+ ships it; the harness runs 7.1):
 * CoreClient turning the adapter's arrays into core's DTOs, core's prompt builder routing them
 * to a provider in its registry, and the result coming back as arrays. The provider is an
 * in-test one registered with core's own registry, so no network is involved and every DTO
 * core builds or reads on the way is the real class. The unit suite pins the adapter's mapping
 * against a fake client; this pins that the real client and core agree on the shapes.
 *
 * Core's registry is a process singleton: the provider is registered once and stays for every
 * later test, which is harmless (nothing else asks it for a model).
 */
final class WpAiClientTest extends TestCase
{
    public function set_up(): void
    {
        parent::set_up();
        if (!function_exists('wp_ai_client_prompt')) {
            $this->markTestSkipped('The AI client is not in this WordPress.');
        }
        $registry = AiClient::defaultRegistry();
        if (!$registry->hasProvider(FakeCoreProvider::class)) {
            $registry->registerProvider(FakeCoreProvider::class);
        }
        FakeCoreTextModel::$prompts = [];
        FakeCoreTextModel::$configs = [];
        FakeCoreTextModel::$reply = static fn(): array => [new MessagePart('fake core reply')];
    }

    public function test_models_lists_the_text_models_configured_providers_offer_with_their_flags(): void
    {
        $models = (new CoreClient())->models();
        $ours = array_values(array_filter($models, static fn(array $m): bool => $m['provider'] === 'alpaca-fake'));
        $this->assertSame([
            ['id' => 'fake-text', 'name' => 'Fake text', 'provider' => 'alpaca-fake', 'provider_name' => 'Alpaca fake', 'tools' => true, 'vision' => true],
            ['id' => 'fake-plain', 'name' => 'Fake plain', 'provider' => 'alpaca-fake', 'provider_name' => 'Alpaca fake', 'tools' => false, 'vision' => false],
        ], $ours);
        // The image-only model is not a chat model and is not listed.
        $this->assertNotContains('fake-image', array_column($models, 'id'));
        $this->assertTrue((new CoreClient())->available());
    }

    public function test_generate_hands_core_the_messages_instruction_options_and_tools_and_maps_the_result_back(): void
    {
        FakeCoreTextModel::$reply = static fn(): array => [
            new MessagePart('Thinking about it.', MessagePartChannelEnum::thought()),
            new MessagePart('Here you go.'),
            new MessagePart(new FunctionCall('call-1', 'lookup', ['q' => 'alpacas'])),
        ];
        $reply = (new CoreClient())->generate([
            'model' => 'fake-text',
            'system' => 'Be brief.',
            'temperature' => 0.2,
            'max_tokens' => 100,
            'tools' => [['name' => 'lookup', 'description' => 'Look up.', 'parameters' => ['type' => 'object', 'properties' => ['q' => ['type' => 'string']], 'required' => ['q']]]],
            'messages' => [
                ['role' => 'user', 'parts' => [['type' => 'text', 'text' => 'Hi'], ['type' => 'file', 'file' => ['fileType' => 'inline', 'mimeType' => 'image/png', 'base64Data' => 'iVBORw0KGgo=']]]],
                ['role' => 'model', 'parts' => [['type' => 'function_call', 'functionCall' => ['id' => 'c0', 'name' => 'lookup', 'args' => ['q' => 'x']]]]],
                ['role' => 'user', 'parts' => [['type' => 'function_response', 'functionResponse' => ['id' => 'c0', 'name' => 'lookup', 'response' => ['answer' => 42]]]]],
                ['role' => 'model', 'parts' => [['type' => 'text', 'text' => '42.']]],
                ['role' => 'user', 'parts' => [['type' => 'text', 'text' => 'Thanks']]],
            ],
        ]);

        $this->assertSame([
            'content' => 'Here you go.',
            'reasoning' => 'Thinking about it.',
            'tool_calls' => [['id' => 'call-1', 'name' => 'lookup', 'arguments' => ['q' => 'alpacas']]],
            'finish_reason' => 'stop',
            'prompt_tokens' => 7,
            'completion_tokens' => 3,
            'total_tokens' => 10,
        ], $reply);

        $this->assertCount(1, FakeCoreTextModel::$prompts);
        $prompt = FakeCoreTextModel::$prompts[0];
        $this->assertCount(5, $prompt);
        $this->assertContainsOnlyInstancesOf(Message::class, $prompt);
        $this->assertSame(['user', 'model', 'user', 'model', 'user'], array_map(static fn(Message $m): string => $m->getRole()->value, $prompt));
        $this->assertSame('Hi', $prompt[0]->getParts()[0]->getText());
        $this->assertSame('image/png', $prompt[0]->getParts()[1]->getFile()?->getMimeType());
        $this->assertSame('iVBORw0KGgo=', $prompt[0]->getParts()[1]->getFile()?->getBase64Data());
        $this->assertSame('lookup', $prompt[1]->getParts()[0]->getFunctionCall()?->getName());
        $this->assertSame(['answer' => 42], $prompt[2]->getParts()[0]->getFunctionResponse()?->getResponse());
        $config = FakeCoreTextModel::$configs[0];
        $this->assertSame('Be brief.', $config->getSystemInstruction());
        $this->assertSame(0.2, $config->getTemperature());
        $this->assertSame(100, $config->getMaxTokens());
        $this->assertSame('lookup', $config->getFunctionDeclarations()[0]->getName());
        $this->assertSame(['q'], $config->getFunctionDeclarations()[0]->getParameters()['required']);
    }

    public function test_generate_asks_for_json_when_told_to(): void
    {
        FakeCoreTextModel::$reply = static fn(): array => [new MessagePart('{"a":1}')];
        $client = new CoreClient();
        $client->generate(['model' => 'fake-text', 'json' => ['type' => 'object'], 'messages' => [['role' => 'user', 'parts' => [['type' => 'text', 'text' => 'x']]]]]);
        $client->generate(['model' => 'fake-text', 'json' => true, 'messages' => [['role' => 'user', 'parts' => [['type' => 'text', 'text' => 'x']]]]]);
        $this->assertSame('application/json', FakeCoreTextModel::$configs[0]->getOutputMimeType());
        $this->assertSame(['type' => 'object'], FakeCoreTextModel::$configs[0]->getOutputSchema());
        $this->assertSame('application/json', FakeCoreTextModel::$configs[1]->getOutputMimeType());
        $this->assertNull(FakeCoreTextModel::$configs[1]->getOutputSchema());
    }

    public function test_generate_refuses_a_model_no_configured_provider_offers_rather_than_picking_another(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('nope:latest');
        (new CoreClient())->generate(['model' => 'nope:latest', 'messages' => [['role' => 'user', 'parts' => [['type' => 'text', 'text' => 'x']]]]]);
    }

    public function test_generate_reports_core_s_refusal_as_an_exception_not_a_wp_error(): void
    {
        // The first message must be a user turn: core's builder refuses, as a WP_Error from the
        // WordPress wrapper, and the client turns that into an exception the adapter can wrap.
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('user role');
        (new CoreClient())->generate(['model' => 'fake-text', 'messages' => [['role' => 'model', 'parts' => [['type' => 'text', 'text' => 'x']]], ['role' => 'user', 'parts' => [['type' => 'text', 'text' => 'y']]]]]);
    }

    public function test_a_chat_turn_over_rest_is_answered_through_core_when_wp_ai_is_selected(): void
    {
        $store = Plugin::instance()->get(Store::class);
        $store->replace(['provider.kind' => 'wp-ai', 'models.default' => 'fake-text'] + Schema::defaults());
        // The catalog memoises per process; an earlier test's fake php-agents provider may be in it.
        Plugin::instance()->get(ModelCatalog::class)->all(true);
        $this->assertInstanceOf(WpAiClientProvider::class, Plugin::instance()->get(Factory::class)->make());
        $this->assertNull(Plugin::instance()->get(Factory::class)->fallbackNotice());
        $this->asAdmin();

        $res = $this->rest('POST', '/chat', ['message' => 'hello core']);
        $this->assertSame(200, $res->get_status(), print_r($res->get_data(), true));
        $data = $res->get_data();
        $this->assertSame('fake core reply', $data['message']['content']);
        $this->assertSame('fake-text', $data['message']['model'] ?? $data['receipt']['model'] ?? 'fake-text');
        $this->assertSame(10, $data['receipt']['total_tokens']);
        // The turn reached the fake model through core, carrying the user's text and, since the
        // catalog lists the model as able to call tools, the enabled toolkits as declarations.
        $this->assertNotEmpty(FakeCoreTextModel::$prompts);
        $last = end(FakeCoreTextModel::$prompts);
        $this->assertSame('hello core', end($last)->getParts()[0]->getText());
        $this->assertNotEmpty(FakeCoreTextModel::$configs[0]->getFunctionDeclarations());
        $this->assertContains('web_fetch', array_map(static fn($d): string => $d->getName(), FakeCoreTextModel::$configs[0]->getFunctionDeclarations()));
    }
}

/** A core provider with two chat models and one image model, answered in-process. */
final class FakeCoreProvider extends AbstractProvider
{
    protected static function createModel(ModelMetadata $modelMetadata, ProviderMetadata $providerMetadata): ModelInterface
    {
        return new FakeCoreTextModel($modelMetadata, $providerMetadata);
    }

    protected static function createProviderMetadata(): ProviderMetadata
    {
        return new ProviderMetadata('alpaca-fake', 'Alpaca fake', ProviderTypeEnum::server());
    }

    protected static function createProviderAvailability(): ProviderAvailabilityInterface
    {
        return new class implements ProviderAvailabilityInterface {
            public function isConfigured(): bool
            {
                return true;
            }
        };
    }

    protected static function createModelMetadataDirectory(): ModelMetadataDirectoryInterface
    {
        return new class implements ModelMetadataDirectoryInterface {
            /** @return array<string, ModelMetadata> */
            private function map(): array
            {
                $text = [
                    new SupportedOption(OptionEnum::systemInstruction()),
                    new SupportedOption(OptionEnum::temperature()),
                    new SupportedOption(OptionEnum::maxTokens()),
                    new SupportedOption(OptionEnum::outputMimeType(), ['text/plain', 'application/json']),
                    new SupportedOption(OptionEnum::outputSchema()),
                ];
                return [
                    'fake-text' => new ModelMetadata('fake-text', 'Fake text', [CapabilityEnum::textGeneration(), CapabilityEnum::chatHistory()], array_merge($text, [
                        new SupportedOption(OptionEnum::functionDeclarations()),
                        new SupportedOption(OptionEnum::inputModalities(), [[ModalityEnum::text()], [ModalityEnum::text(), ModalityEnum::image()]]),
                    ])),
                    'fake-plain' => new ModelMetadata('fake-plain', 'Fake plain', [CapabilityEnum::textGeneration(), CapabilityEnum::chatHistory()], array_merge($text, [
                        new SupportedOption(OptionEnum::inputModalities(), [[ModalityEnum::text()]]),
                    ])),
                    'fake-image' => new ModelMetadata('fake-image', 'Fake image', [CapabilityEnum::imageGeneration()], [
                        new SupportedOption(OptionEnum::inputModalities(), [[ModalityEnum::text()]]),
                    ]),
                ];
            }

            public function listModelMetadata(): array
            {
                return array_values($this->map());
            }

            public function hasModelMetadata(string $modelId): bool
            {
                return isset($this->map()[$modelId]);
            }

            public function getModelMetadata(string $modelId): ModelMetadata
            {
                return $this->map()[$modelId] ?? throw new \InvalidArgumentException("no model {$modelId}");
            }
        };
    }
}

final class FakeCoreTextModel implements ModelInterface, TextGenerationModelInterface
{
    /** @var list<list<Message>> every prompt generateTextResult() received */
    public static array $prompts = [];

    /** @var list<ModelConfig> the config in force for each of those calls */
    public static array $configs = [];

    /** @var \Closure(): list<MessagePart> the parts of the model message to answer with */
    public static \Closure $reply;

    private ModelConfig $config;

    public function __construct(private ModelMetadata $metadata, private ProviderMetadata $providerMetadata)
    {
        $this->config = new ModelConfig();
    }

    public function metadata(): ModelMetadata
    {
        return $this->metadata;
    }

    public function providerMetadata(): ProviderMetadata
    {
        return $this->providerMetadata;
    }

    public function setConfig(ModelConfig $config): void
    {
        $this->config = $config;
    }

    public function getConfig(): ModelConfig
    {
        return $this->config;
    }

    public function generateTextResult(array $prompt): GenerativeAiResult
    {
        self::$prompts[] = $prompt;
        self::$configs[] = clone $this->config;
        return new GenerativeAiResult(
            'fake-result',
            [new Candidate(new ModelMessage((self::$reply)()), FinishReasonEnum::stop())],
            new TokenUsage(7, 3, 10, 1),
            $this->providerMetadata,
            $this->metadata,
        );
    }
}
