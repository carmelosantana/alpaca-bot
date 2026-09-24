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

/*
 * `??` catches an absent key, not a blank field: a settings row saves '' for a number nobody
 * typed, and `(float) ''` is 0.0. Symfony reads max_duration 0 as no limit at all and a negative
 * one leaves it uncapped (ServerConfig says where), so the row that looks like it asks for
 * nothing is the row that removes the cap. A zero byte cap fails the other way and refuses every
 * response. Neither number was asked for, so both fall back to the shipped default.
 */
it('falls back to the shipped defaults for a timeout or byte cap that is not a positive number', function (string $value): void {
    $row = ServerConfig::fromSettings(['id' => 'x', 'url' => 'https://x.test/', 'timeout' => $value, 'max_bytes' => $value]);
    expect($row->timeout)->toBe(30.0)
        ->and($row->maxBytes)->toBe(1048576);
})->with(['blank' => '', 'not a number' => 'soon', 'zero' => '0', 'zero as a float' => '0.0', 'negative' => '-5', 'negative float' => '-0.5']);

it('keeps a positive number the administrator did type, including a fractional timeout', function (): void {
    $row = ServerConfig::fromSettings(['id' => 'x', 'url' => 'https://x.test/', 'timeout' => '0.5', 'max_bytes' => '1']);
    expect($row->timeout)->toBe(0.5)
        ->and($row->maxBytes)->toBe(1);
});

// A ServerConfig that reaches print_r() or var_dump(), on its own or inside a trace's arguments,
// shows every field but the header value.
it('masks the header value when it is dumped, and shows the rest', function (): void {
    $server = new ServerConfig('docs', 'https://mcp.example.test/mcp', 'Authorization', 'Bearer secret-t', 'docs');
    $printed = print_r($server, true);
    ob_start();
    var_dump($server);
    $dumped = (string) ob_get_clean();
    expect($printed)->not->toContain('secret-t')->toContain('[redacted]')->toContain('https://mcp.example.test/mcp')->toContain('Authorization')
        ->and($dumped)->not->toContain('secret-t')->toContain('[redacted]')->toContain('https://mcp.example.test/mcp');
});
