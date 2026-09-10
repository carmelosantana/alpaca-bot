<?php

declare(strict_types=1);

use AlpacaBot\Provider\WpAi\Client;
use AlpacaBot\Provider\WpAi\CoreClient;

// Brain Monkey cannot stub class_exists() or function_exists(), so the two probes are injected,
// as Abilities\Register injects its function_exists(). Both names are core's: the builder class
// and the function that makes one ship together in wp-includes/ai-client.php (WordPress 7.0+).
// Nothing else of CoreClient can run without core; the integration suite covers the rest.

it('is a Client, and is available only when both the builder class and its function exist', function (): void {
    $seen = [];
    $client = new CoreClient(
        function (string $class) use (&$seen): bool {
            $seen[] = $class;
            return $class === 'WP_AI_Client_Prompt_Builder';
        },
        function (string $function) use (&$seen): bool {
            $seen[] = $function;
            return $function === 'wp_ai_client_prompt';
        },
    );
    expect($client)->toBeInstanceOf(Client::class)
        ->and($client->available())->toBeTrue()
        ->and($seen)->toBe(['WP_AI_Client_Prompt_Builder', 'wp_ai_client_prompt']);

    expect((new CoreClient(static fn(string $c): bool => false, static fn(string $f): bool => true))->available())->toBeFalse()
        ->and((new CoreClient(static fn(string $c): bool => true, static fn(string $f): bool => false))->available())->toBeFalse();
});

it('probes the real process by default: this test process has no core, so it is unavailable, lists nothing and refuses to generate', function (): void {
    $client = new CoreClient();
    expect($client->available())->toBeFalse()
        ->and($client->models())->toBe([]);
    expect(fn() => $client->generate(['model' => 'm', 'messages' => [['role' => 'user', 'parts' => [['type' => 'text', 'text' => 'x']]]]]))
        ->toThrow(RuntimeException::class);
});
