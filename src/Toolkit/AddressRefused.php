<?php

declare(strict_types=1);

namespace AlpacaBot\Toolkit;

/**
 * An address a request may not go to, with a message fit to show the person who asked: it names
 * the host and, when a lookup was made, what the host answered with. Thrown by AddressPin, and
 * by Mcp\Egress for a server URL it will not build a client for at all.
 *
 * web_fetch catches it and answers with a sentence of its own instead, because the URL was the
 * model's choice and telling the user which private address a name resolved to reports on the
 * site's network rather than on the fetch. Mcp\Egress raises it for an MCP server's URL and
 * puts nothing in its place: that URL was typed by an administrator, who has the opposite need
 * and is the reader this message is written for.
 *
 * @since 0.6.0
 */
final class AddressRefused extends \RuntimeException {}
