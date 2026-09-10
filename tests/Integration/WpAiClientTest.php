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
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Enum\ProviderFinishReason;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Message\AssistantMessage;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Message\SystemMessage;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Message\ToolResultMessage;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Message\UserMessage;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Tool\Parameter\StringParameter;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Tool\Tool;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Tool\ToolCall;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Tool\ToolResult;
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
            ['id' => 'fake-text', 'name' => 'Fake text', 'provider' => 'alpaca-fake', 'tools' => true, 'vision' => true],
            ['id' => 'fake-plain', 'name' => 'Fake plain', 'provider' => 'alpaca-fake', 'tools' => false, 'vision' => false],
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

    /**
     * The adapter's own output, not a hand-written copy of it, through the real client into
     * core's own constructors: every shape the agent loop sends back (a model turn carrying a
     * function call, a user turn carrying the function response, an inline and a remote image on
     * a user turn) is built by WpAiClientProvider::request() and refused or accepted by core's
     * `Message::fromArray()`, so a mapping literal the adapter and its unit test both get wrong
     * fails here against core rather than against a mirror of itself. What core reads and so what
     * this test can catch: the role (an enum), which of `text|file|functionCall|functionResponse`
     * a part sets, `fileType` being present, `url` or `base64Data`, and `response` on a function
     * response. Core does not read a part's `type` value or a file's `fileType` value, so those
     * two literals are pinned by the unit suite alone and cannot fail here.
     */
    public function test_the_adapter_s_own_transcript_is_what_core_builds_its_messages_from(): void
    {
        FakeCoreTextModel::$reply = static fn(): array => [
            new MessagePart('Looking that up too.'),
            new MessagePart(new FunctionCall('call-2', 'lookup', ['q' => 'llamas'])),
        ];
        $tool = new Tool('lookup', 'Look something up.', [new StringParameter('q', 'The query.')], static fn(array $in): ToolResult => ToolResult::success('x'));
        $provider = new WpAiClientProvider('fake-text', new CoreClient());

        $response = $provider->chat([
            new SystemMessage('Be brief.'),
            new UserMessage([
                ['type' => 'text', 'text' => 'What is this?'],
                ['type' => 'image_url', 'image_url' => ['url' => 'data:image/png;base64,iVBORw0KGgo=']],
                ['type' => 'image_url', 'image_url' => ['url' => 'https://example.com/a.jpg']],
            ]),
            new AssistantMessage('', [new ToolCall('call-1', 'lookup', ['q' => 'alpacas'])]),
            new ToolResultMessage(ToolResult::json(['answer' => 42])->withCallId('call-1')),
            new AssistantMessage('42.'),
            new UserMessage('And llamas?'),
        ], [$tool], ['temperature' => 0.3]);

        // What core built from the adapter's arrays, read back through core's own getters.
        $this->assertCount(1, FakeCoreTextModel::$prompts);
        $prompt = FakeCoreTextModel::$prompts[0];
        $this->assertContainsOnlyInstancesOf(Message::class, $prompt);
        $this->assertSame(['user', 'model', 'user', 'model', 'user'], array_map(static fn(Message $m): string => $m->getRole()->value, $prompt));

        [$text, $inline, $remote] = $prompt[0]->getParts();
        $this->assertSame('What is this?', $text->getText());
        $this->assertSame('file', $inline->getType()->value);
        $this->assertSame('inline', (string) $inline->getFile()?->getFileType());
        $this->assertSame('image/png', $inline->getFile()?->getMimeType());
        $this->assertSame('iVBORw0KGgo=', $inline->getFile()?->getBase64Data());
        $this->assertSame('remote', (string) $remote->getFile()?->getFileType());
        $this->assertSame('https://example.com/a.jpg', $remote->getFile()?->getUrl());

        $call = $prompt[1]->getParts()[0];
        $this->assertSame('function_call', $call->getType()->value);
        $this->assertSame('call-1', $call->getFunctionCall()?->getId());
        $this->assertSame('lookup', $call->getFunctionCall()?->getName());
        $this->assertSame(['q' => 'alpacas'], $call->getFunctionCall()?->getArgs());

        $result = $prompt[2]->getParts()[0];
        $this->assertSame('function_response', $result->getType()->value);
        $this->assertSame('call-1', $result->getFunctionResponse()?->getId());
        $this->assertSame('lookup', $result->getFunctionResponse()?->getName());
        $this->assertSame(['answer' => 42], $result->getFunctionResponse()?->getResponse());

        $this->assertSame('42.', $prompt[3]->getParts()[0]->getText());
        $this->assertSame('And llamas?', $prompt[4]->getParts()[0]->getText());

        $config = FakeCoreTextModel::$configs[0];
        $this->assertSame('Be brief.', $config->getSystemInstruction());
        $this->assertSame(0.3, $config->getTemperature());
        $this->assertSame(['lookup'], array_map(static fn($d): string => $d->getName(), $config->getFunctionDeclarations()));

        // And core's reply, through the client, back as php-agents' Response.
        $this->assertSame('Looking that up too.', $response->content);
        $this->assertSame(ProviderFinishReason::ToolUse, $response->finishReason);
        $this->assertCount(1, $response->toolCalls);
        $this->assertSame('call-2', $response->toolCalls[0]->id);
        $this->assertSame('lookup', $response->toolCalls[0]->name);
        $this->assertSame(['q' => 'llamas'], $response->toolCalls[0]->arguments);
        $this->assertSame('fake-text', $response->model);
        $this->assertSame(10, $response->usage?->totalTokens);
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

    /**
     * The guard on `getCandidates()[0]`. Unreachable on this core — GenerativeAiResult's
     * constructor refuses an empty candidate list and fromArray() goes through it — so the
     * result here is a subclass that returns one anyway. Without the guard the index is a PHP
     * warning and an \Error, which is not an \Exception: it would pass generate()'s own catch
     * and break the \RuntimeException that Client::generate() documents and that every caller
     * mapping a failed turn by class relies on.
     */
    public function test_generate_refuses_a_result_with_no_candidate_as_a_runtime_exception_not_an_error(): void
    {
        FakeCoreTextModel::$noCandidates = true;
        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('no reply to read');
            (new CoreClient())->generate(['model' => 'fake-text', 'messages' => [['role' => 'user', 'parts' => [['type' => 'text', 'text' => 'x']]]]]);
        } finally {
            FakeCoreTextModel::$noCandidates = false;
        }
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
        $this->assertSame('fake-text', $data['message']['model']);
        $this->assertSame('fake-text', $data['receipt']['model']);
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

    /**
     * Whether the next generateTextResult() answers with an empty candidate list. Core's own
     * GenerativeAiResult refuses to be built with one, so this is a subclass that overrides
     * getCandidates(): what CoreClient::reply() must do if that invariant ever relaxes, which
     * is the only way an empty list can reach it.
     */
    public static bool $noCandidates = false;

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
        $candidates = [new Candidate(new ModelMessage((self::$reply)()), FinishReasonEnum::stop())];
        $usage = new TokenUsage(7, 3, 10, 1);
        if (self::$noCandidates) {
            return new class ('fake-result', $candidates, $usage, $this->providerMetadata, $this->metadata) extends GenerativeAiResult {
                /** @return list<Candidate> */
                public function getCandidates(): array
                {
                    return [];
                }
            };
        }
        return new GenerativeAiResult('fake-result', $candidates, $usage, $this->providerMetadata, $this->metadata);
    }
}
