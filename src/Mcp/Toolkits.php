<?php

declare(strict_types=1);

namespace AlpacaBot\Mcp;

use AlpacaBot\Access;
use AlpacaBot\Settings\Schema;
use AlpacaBot\Settings\Store;

/**
 * The MCP servers of `toolkits.mcp_servers` as toolkits for one user's turn, keyed `mcp.<id>`:
 * the Settings › Access row that gates the server is also the key `alpaca_bot/toolkits` sees it
 * under, so what the filter receives names the server the way the Access tab and its filter
 * (`alpaca_bot/capability/mcp/<id>`) do.
 *
 * A row is skipped, before any client is built or any credential read, when:
 * - its id is not one Schema::isMcpId() admits. Store hands back what is stored, not what the
 *   schema would make of it, and Access::hook() keeps two rows' filters apart only for ids that
 *   rule admits;
 * - it approves nothing: there would be nothing to offer, and no reason to hold its header value;
 * - the user fails its `mcp.<id>` row (Access::allows(), asked of `$userId` with user_can()).
 *   That row defaults to `manage_options`. It is not in Access::defaults(), because there is one
 *   per server; a row that list does not name reads as `manage_options`.
 * For every other row the header value is put back from Secrets (the row carries the mask), and
 * the client comes from ClientFactory::for(); this class opens no connection and asks nothing of
 * the network itself. The factory the plugin constructs is given no closure in this release, so
 * the client it hands out is UnavailableClient, and a toolkit the plugin's instance of this class
 * builds lists no tools.
 *
 * The toolkit is handed `$userId`, the id its row was asked for, as the user its calls are
 * announced as, so the check and the record cannot disagree about who is asking. Its drift goes
 * to Drift::set(), the marker Discovery also writes.
 *
 * Nothing is kept between calls: for() reads the servers when it is asked, through the Store the
 * rest of the request shares.
 *
 * @since 0.6.0
 */
final class Toolkits
{
    public function __construct(private Store $store, private Access $access, private ClientFactory $clients) {}

    /** @return array<string, McpToolkit> by `mcp.<id>`, in the order the servers are stored */
    public function for(int $userId): array
    {
        $rows = $this->store->get('toolkits.mcp_servers', []);
        $out = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (!is_array($row) || !Schema::isMcpId($row['id'] ?? null)) {
                continue;
            }
            /** @var string $id isMcpId() passed it */
            $id = $row['id'];
            if (ServerConfig::fromSettings($row)->approved === [] || !$this->access->allows($userId, Access::MCP_PREFIX . $id, $userId)) {
                continue;
            }
            $row['header_value'] = Secrets::resolve($row);
            $server = ServerConfig::fromSettings($row);
            $out[Access::MCP_PREFIX . $id] = new McpToolkit($this->clients->for($server), $server, $userId, Drift::set(...));
        }
        return $out;
    }
}
