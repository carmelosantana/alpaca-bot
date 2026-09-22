<?php

declare(strict_types=1);

use AlpacaBot\Mcp\ServerConfig;

it('reads a toolkits.mcp_servers row into typed fields, with the defaults for a row that leaves them out', function (): void {
    $full = ServerConfig::fromSettings(['id' => 'docs', 'url' => 'https://mcp.example.test/mcp', 'header_name' => 'Authorization', 'header_value' => 'Bearer t', 'prefix' => 'docs', 'timeout' => '12.5', 'max_bytes' => '2048', 'approved' => ['search' => 'abc', 'bad' => ['x']]]);
    expect([$full->id, $full->url, $full->headerName, $full->headerValue, $full->prefix, $full->timeout, $full->maxBytes, $full->approved])
        ->toBe(['docs', 'https://mcp.example.test/mcp', 'Authorization', 'Bearer t', 'docs', 12.5, 2048, ['search' => 'abc']]);
    $bare = ServerConfig::fromSettings(['id' => 'x', 'url' => 'https://x.test/']);
    expect([$bare->headerName, $bare->timeout, $bare->maxBytes, $bare->approved])->toBe(['', 30.0, 1048576, []]);
});
