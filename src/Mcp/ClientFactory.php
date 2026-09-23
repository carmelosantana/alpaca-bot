<?php

declare(strict_types=1);

namespace AlpacaBot\Mcp;

/**
 * Where a client for one server comes from. With no closure, for() returns a new
 * UnavailableClient. With one, for() returns whatever the closure returns for the server it was
 * asked about, which is how a test hands in a FakeClient.
 *
 * It reads nothing from the ServerConfig itself and records nothing: the header value reaches the
 * closure a caller passed, if there is one, and goes nowhere else from here.
 *
 * @since 0.6.0
 */
final class ClientFactory
{
    /** @var \Closure(ServerConfig): ClientInterface */
    private \Closure $build;

    /** @param (\Closure(ServerConfig): ClientInterface)|null $build null builds an UnavailableClient */
    public function __construct(?\Closure $build = null)
    {
        $this->build = $build ?? static fn(ServerConfig $server): ClientInterface => new UnavailableClient();
    }

    public function for(ServerConfig $server): ClientInterface
    {
        return ($this->build)($server);
    }
}
