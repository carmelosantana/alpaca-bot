<?php

declare(strict_types=1);

namespace AlpacaBot\Tests\Integration;

use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Contract\ProviderInterface;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Provider\Response;

/**
 * The model provider an integration test gets unless it asks for TestCase::fakeProvider()
 * (Kanboard #4322). bootstrap.php swaps it in for the configured Ollama provider, which talks
 * to the network through Symfony's HttpClient, where no `pre_http_request` stub can reach it.
 * chat(), stream(), structured() and models() throw, so a test that meant to fake the provider and
 * forgot fails where it calls one, and one that only touches the catalog on the way (ModelCatalog
 * forgives a provider that throws, as it forgives one that is down) gets the empty catalog an
 * unreachable host would give, without a connection attempt behind it. isAvailable() would have
 * gone out too -- OllamaProvider's asks the host for /api/tags -- and answers false rather than
 * throwing, which is what that probe returns when nothing is listening. getModel() and withModel()
 * reach nothing in any provider.
 */
final class OfflineProvider implements ProviderInterface
{
    public const MESSAGE = 'The integration suite has no model provider: call TestCase::fakeProvider() in this test.';

    public function chat(array $messages, array $tools = [], array $options = []): Response
    {
        throw new \RuntimeException(self::MESSAGE);
    }

    public function stream(array $messages, array $tools = [], array $options = []): iterable
    {
        throw new \RuntimeException(self::MESSAGE);
    }

    public function structured(array $messages, string $schema, array $options = []): mixed
    {
        throw new \RuntimeException(self::MESSAGE);
    }

    public function models(): array
    {
        throw new \RuntimeException(self::MESSAGE);
    }

    public function isAvailable(): bool
    {
        return false;
    }

    public function getModel(): string
    {
        return '';
    }

    public function withModel(string $model): static
    {
        return $this;
    }
}
