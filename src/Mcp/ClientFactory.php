<?php

declare(strict_types=1);

namespace AlpacaBot\Mcp;

/**
 * Where a client for one server comes from. With no closure, for() builds php-agents' MCP client
 * through PhpAgentsClient::over(), over the Egress the factory was handed (a new one by default)
 * and with TransientSessions as its session store. With a closure, for() returns whatever the
 * closure returns for the server it was asked about, which is how a test hands in its own double.
 *
 * The default build is where the server's address is checked: Egress::client() refuses a URL or
 * an address its check does not pass with AddressRefused, and PhpAgentsClient::over() refuses a
 * header name it would not send with McpUnavailable, so for() can throw either. The build sends
 * nothing; the client does, when it is asked to list or call. The factory itself reads nothing
 * from the ServerConfig and records nothing.
 *
 * for()'s parameter is a #[\SensitiveParameter], so a trace taken with zend.exception_ignore_args
 * off holds a SensitiveParameterValue in that frame rather than the server. The closure's own
 * frame is the closure's: the one a caller passes gets the ServerConfig as it is, and the default
 * one marks its parameter sensitive, as PhpAgentsClient::over() does. print_r() or var_dump() of a
 * trace that holds the server masks the header value through ServerConfig::__debugInfo();
 * json_encode() or var_export() of it writes the value.
 *
 * @since 0.6.0
 */
final class ClientFactory
{
    /** @var \Closure(ServerConfig): ClientInterface */
    private \Closure $build;

    /**
     * @param (\Closure(ServerConfig): ClientInterface)|null $build  null builds PhpAgentsClient::over() the server
     * @param Egress|null                                   $egress the default build's HTTP client source; a new Egress by default
     */
    public function __construct(?\Closure $build = null, ?Egress $egress = null)
    {
        $egress ??= new Egress();
        $this->build = $build ?? static fn(#[\SensitiveParameter] ServerConfig $server): ClientInterface => PhpAgentsClient::over($server, $egress, new TransientSessions());
    }

    public function for(#[\SensitiveParameter] ServerConfig $server): ClientInterface
    {
        return ($this->build)($server);
    }
}
