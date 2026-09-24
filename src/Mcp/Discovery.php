<?php

declare(strict_types=1);

namespace AlpacaBot\Mcp;

use AlpacaBot\Settings\Store;

/**
 * What an administrator is shown before approving a server's tools: each tool the server lists,
 * the fingerprint of the definition it lists it with, and whether that tool is `approved` (its
 * pinned fingerprint matches), `changed` (it is pinned to another one) or `new` (it is not
 * pinned), with whether its box starts ticked.
 *
 * The fingerprint an administrator ticks is the fingerprint of the definition they were shown
 * (View\Settings\McpTools posts it as the box's value), so approving is approving that
 * definition and nothing else. A changed tool starts unticked with its new fingerprint in the
 * box, so approving it again is a deliberate act, and it re-pins the tool. A new tool starts
 * ticked unless its `destructiveHint` annotation is exactly `true` (ToolDefinition::destructive()):
 * the annotation is the server's own claim, which the MCP specification says to treat as
 * untrusted, and here it only decides which way a box starts. A new tool without the annotation,
 * or with any other value in it, starts ticked.
 *
 * It opens no connection itself. tools() asks the ClientFactory it was handed for a client and
 * asks that client to list the server; what the client does is the factory's. The factory the
 * plugin constructs builds UnavailableClient, which answers McpUnavailable::NOT_YET without
 * contacting anything.
 *
 * @since 0.6.0
 */
final class Discovery
{
    public function __construct(private Store $store, private ClientFactory $clients) {}

    /**
     * The stored server whose id is `$id`, with its header value put back from Secrets (the row
     * carries the mask), or null when the settings list no server of that id.
     */
    public function server(string $id): ?ServerConfig
    {
        $rows = $this->store->get('toolkits.mcp_servers', []);
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (is_array($row) && ($row['id'] ?? null) === $id) {
                $row['header_value'] = Secrets::resolve($row);
                return ServerConfig::fromSettings($row);
            }
        }
        return null;
    }

    /**
     * One row per tool the server lists, in the order it lists them, and the names of the changed
     * ones recorded through Drift::set(), which clears the marker when there are none.
     *
     * @return list<array{definition: ToolDefinition, fingerprint: string, state: 'approved'|'changed'|'new', ticked: bool}>
     * @throws McpUnavailable when the server cannot be listed; the drift marker is left as it was
     */
    public function tools(#[\SensitiveParameter] ServerConfig $server): array
    {
        $rows = [];
        $changed = [];
        foreach ($this->clients->for($server)->listTools() as $definition) {
            $fingerprint = $definition->fingerprint();
            $pinned = $server->approved[$definition->name] ?? null;
            $state = match ($pinned) {
                null => 'new',
                $fingerprint => 'approved',
                default => 'changed',
            };
            if ($state === 'changed') {
                $changed[] = $definition->name;
            }
            $rows[] = [
                'definition' => $definition,
                'fingerprint' => $fingerprint,
                'state' => $state,
                'ticked' => $state === 'approved' || ($state === 'new' && !$definition->destructive()),
            ];
        }
        Drift::set($server->id, $changed);
        return $rows;
    }
}
