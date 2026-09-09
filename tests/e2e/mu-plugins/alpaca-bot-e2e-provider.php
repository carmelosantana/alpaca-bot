<?php

/**
 * Plugin Name: Alpaca Bot e2e fake provider
 * Description: Answers every turn from memory so the Playwright smoke test needs no model server.
 *
 * The directory this file sits in is mapped over `wp-content/mu-plugins` in the wp-env
 * *development* environment only (.wp-env.json `env.development.mappings`), which is the site the
 * e2e drives on http://localhost:8888. The tests environment, where the PHPUnit integration suite
 * runs, never sees this file: that suite hands in its own provider through the same filter
 * (tests/Integration/TestCase::fakeProvider()) and asserts on how the real one is built, so a
 * site-wide fake would change what it is testing.
 *
 * The mapping is of this *directory*, not of this file. wp-env turns a mapping into a Docker bind
 * mount, and a single-file bind mount binds the inode: rewrite the file with anything that
 * replaces it (`sed -i`, most editors' atomic save) and the container keeps serving the old
 * content until the containers are recreated. A directory mount has no such trap, and leaves room
 * for a second e2e mu-plugin without touching .wp-env.json again.
 *
 * Belt and braces on top of that scoping: nothing here runs unless ALPACA_BOT_E2E is true, a
 * constant only wp-env's development wp-config.php defines. A copy of this file that reached a
 * real site would be inert.
 *
 * The reply is fixed and its text is the assertion: tests/e2e/chat.spec.ts looks for exactly
 * ALPACA_BOT_E2E_REPLY in the assistant bubble, so a turn that never arrived, or one answered by
 * anything else, fails rather than passing on "some text appeared". It is streamed in two frames
 * with the usage on a third, the shape OllamaProvider::stream() ends a turn with, so the receipt
 * under the bubble has a token count to render.
 *
 * @package AlpacaBot\Tests\E2E
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('ALPACA_BOT_E2E') || !constant('ALPACA_BOT_E2E')) {
    return;
}

define('ALPACA_BOT_E2E_REPLY', 'Hello from the Alpaca Bot end-to-end fake provider.');
define('ALPACA_BOT_E2E_MODEL', 'e2e-fake-model');

/**
 * Swaps in the fake at the moment a turn asks for a provider. The class is declared inside the
 * callback because it implements a strauss-prefixed interface out of vendor-prefixed/, which the
 * plugin's own autoloader registers on plugins_loaded -- long after this mu-plugin file is read.
 */
add_filter('alpaca_bot/provider', static function (): object {
    return new class implements \AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Contract\ProviderInterface {
        public function chat(array $messages, array $tools = [], array $options = []): \AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Provider\Response
        {
            return new \AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Provider\Response(
                ALPACA_BOT_E2E_REPLY,
                \AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Enum\ProviderFinishReason::Stop,
                usage: new \AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Provider\Usage(7, 11, 18),
            );
        }

        public function stream(array $messages, array $tools = [], array $options = []): iterable
        {
            $split = (int) (strlen(ALPACA_BOT_E2E_REPLY) / 2);
            yield new \AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Provider\Response(substr(ALPACA_BOT_E2E_REPLY, 0, $split), \AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Enum\ProviderFinishReason::Stop);
            yield new \AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Provider\Response(substr(ALPACA_BOT_E2E_REPLY, $split), \AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Enum\ProviderFinishReason::Stop);
            yield new \AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Provider\Response('', \AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Enum\ProviderFinishReason::Stop, usage: new \AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Provider\Usage(7, 11, 18));
        }

        public function structured(array $messages, string $schema, array $options = []): mixed
        {
            return [];
        }

        public function models(): array
        {
            return [new \AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Config\ModelDefinition(ALPACA_BOT_E2E_MODEL, 'Alpaca Bot e2e fake model', 'e2e')];
        }

        public function isAvailable(): bool
        {
            return true;
        }

        public function getModel(): string
        {
            return ALPACA_BOT_E2E_MODEL;
        }

        public function withModel(string $model): static
        {
            return $this;
        }
    };
});
