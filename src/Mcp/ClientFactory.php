<?php

declare(strict_types=1);

namespace AlpacaBot\Mcp;

/**
 * Where a client for one server comes from. With no closure, for() returns a new
 * UnavailableClient. With one, for() returns whatever the closure returns for the server it was
 * asked about, which is how a test hands in its own double.
 *
 * It reads nothing from the ServerConfig itself and records nothing. for()'s parameter is a
 * #[\SensitiveParameter], so a trace taken with zend.exception_ignore_args off holds a
 * SensitiveParameterValue in that frame rather than the server. The closure's own frame is the
 * closure's: the one a caller passes gets the ServerConfig as it is. print_r() or var_dump() of
 * that trace masks the header value through ServerConfig::__debugInfo(); json_encode() or
 * var_export() of it writes the value.
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

    public function for(#[\SensitiveParameter] ServerConfig $server): ClientInterface
    {
        return ($this->build)($server);
    }
}
