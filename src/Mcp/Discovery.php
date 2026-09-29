<?php

declare(strict_types=1);

namespace AlpacaBot\Mcp;

use AlpacaBot\Settings\Schema;
use AlpacaBot\Settings\Store;
use AlpacaBot\Toolkit\AddressRefused;

/**
 * What an administrator is shown before approving a server's tools: each tool the server lists,
 * the fingerprint of the definition it lists it with, and whether that tool is `approved` (its
 * pinned fingerprint matches), `changed` (it is pinned to another one) or `new` (it is not
 * pinned), with whether its box starts ticked.
 *
 * The fingerprint an administrator ticks is the fingerprint of the definition the list was built
 * from (View\Settings\McpTools posts it as the box's value), so approving is approving that
 * definition and nothing else. The list shows the tool's name, title, description and input
 * schema, the schema cut at McpTools::SCHEMA_CHARS characters, though the fingerprint covers all
 * of it. A changed tool starts unticked with its new fingerprint in the box, so approving it
 * again is a deliberate act, and it re-pins the tool. A new tool starts ticked unless its `destructiveHint` annotation is exactly `true` (ToolDefinition::destructive()):
 * the annotation is the server's own claim, which the MCP specification says to treat as
 * untrusted, and here it only decides which way a box starts. A new tool without the annotation,
 * or with any other value in it, starts ticked.
 *
 * A name the listing carries more than once starts unticked on every copy, whatever its state:
 * MCP makes a tool's name unique on its server, and McpTools offers no box for such a name,
 * since boxes under one name post as one and keep whichever tick came last. Each copy keeps the
 * state its own fingerprint gives it, so one copy may be `approved` beside another `changed`, and
 * the name is recorded in the drift marker once when any copy is `changed`.
 *
 * It opens no connection itself. tools() asks the ClientFactory it was handed for a client and
 * asks that client to list the server; what the client does is the factory's. The factory the
 * plugin constructs builds PhpAgentsClient, which lists the server over the network through
 * Mcp\Egress, and throws McpUnavailable when it cannot. Building the client can fail too, and
 * outside the listing: Egress refuses an address with AddressRefused, which is not an
 * McpUnavailable. tools() hands its caller McpUnavailable for either, and for anything else
 * either throws (tools() says which message each carries).
 *
 * @since 0.6.0
 */
final class Discovery
{
    public function __construct(private Store $store, private ClientFactory $clients) {}

    /**
     * The stored server whose id is `$id`, with its header value put back from Secrets (the row
     * carries the mask), or null when the settings list no server of that id.
     *
     * Also null for a row only a write round the schema can store (a hand edit, `wp option
     * update`), as Mcp\Toolkits skips one: an id Schema::isMcpId() does not admit, which the
     * route's own pattern turns away too except for an uppercase letter, since core matches a
     * route case-insensitively; and a row ServerConfig::fromSettings() cannot read (an object
     * where a string belongs), which would otherwise be an Error out of the route.
     */
    public function server(string $id): ?ServerConfig
    {
        if (!Schema::isMcpId($id)) {
            return null;
        }
        $rows = $this->store->get('toolkits.mcp_servers', []);
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (is_array($row) && ($row['id'] ?? null) === $id) {
                $row['header_value'] = Secrets::resolve($row);
                try {
                    return ServerConfig::fromSettings($row);
                } catch (\Throwable) {
                    return null;
                }
            }
        }
        return null;
    }

    /**
     * One row per tool the server lists, in the order it lists them, and the names of the changed
     * ones recorded through Drift::set(), each once, which clears the marker when there are none.
     *
     * A failure to build the client or to list the server is McpUnavailable, whatever was thrown,
     * so a caller that shows it, as the Discover route does, has one class to catch and never
     * answers an error of its own. The catch is \Throwable because both halves can throw beyond
     * RuntimeException: the builder is whatever closure the factory was handed, and the default
     * one reaches McpServer's constructor and PinnedHttpClient's, each of which can throw
     * \InvalidArgumentException; PhpAgentsClient turns only RuntimeException into McpUnavailable,
     * and php-agents' McpException docblock names a TypeError and an Error its client can raise
     * besides. What the message says:
     *
     * - An McpUnavailable is handed on as it is.
     * - An AddressRefused's message is kept. It is the plugin's own sentence, written for the
     *   administrator who typed the URL (AddressRefused's docblock): Egress's or AddressPin's,
     *   naming at most the host and an address it refuses.
     * - Anything else gets the plugin's sentence and none of its own text, which is not the
     *   plugin's and could quote the URL, its query string included.
     *
     * The two it builds keep what was thrown as `previous`.
     *
     * @return list<array{definition: ToolDefinition, fingerprint: string, state: 'approved'|'changed'|'new', ticked: bool}>
     * @throws McpUnavailable when the client cannot be built or the server cannot be listed; the drift marker is left as it was
     */
    public function tools(#[\SensitiveParameter] ServerConfig $server): array
    {
        $rows = [];
        $changed = [];
        try {
            $listed = $this->clients->for($server)->listTools();
        } catch (McpUnavailable $e) {
            throw $e;
        } catch (AddressRefused $e) {
            throw new McpUnavailable($e->getMessage(), 0, $e);
        } catch (\Throwable $e) {
            throw new McpUnavailable(__('The MCP client failed in a way this plugin does not recognise, so the server was not listed.', 'alpaca-bot'), 0, $e);
        }
        $counts = array_count_values(array_map(static fn(ToolDefinition $d): string => $d->name, $listed));
        foreach ($listed as $definition) {
            $fingerprint = $definition->fingerprint();
            $pinned = $server->approved[$definition->name] ?? null;
            $state = match ($pinned) {
                null => 'new',
                $fingerprint => 'approved',
                default => 'changed',
            };
            $repeated = $counts[$definition->name] > 1;
            if ($state === 'changed' && !in_array($definition->name, $changed, true)) {
                $changed[] = $definition->name;
            }
            $rows[] = [
                'definition' => $definition,
                'fingerprint' => $fingerprint,
                'state' => $state,
                'ticked' => !$repeated && ($state === 'approved' || ($state === 'new' && !$definition->destructive())),
            ];
        }
        Drift::set($server->id, $changed);
        return $rows;
    }
}
