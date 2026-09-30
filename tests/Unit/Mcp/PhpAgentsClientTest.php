<?php

declare(strict_types=1);

use AlpacaBot\Mcp\Egress;
use AlpacaBot\Mcp\McpUnavailable;
use AlpacaBot\Mcp\PhpAgentsClient;
use AlpacaBot\Mcp\ServerConfig;
use AlpacaBot\Mcp\ToolDefinition;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Enum\ToolResultStatus;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Mcp\McpAuthException;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Mcp\McpClient;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Mcp\McpClientInterface;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Mcp\McpProtocolException;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Mcp\McpRedirectException;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Mcp\McpRpcException;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Mcp\McpServer;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Mcp\McpToolDefinition;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Mcp\McpTransportException;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Mcp\McpUnsupportedVersionException;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Tool\ToolResult;
use AlpacaBot\Vendor\Symfony\Component\HttpClient\MockHttpClient;
use AlpacaBot\Vendor\Symfony\Component\HttpClient\Response\MockResponse;

// The adapter is driven over php-agents' real McpClient, with Symfony's MockHttpClient as the
// server and the plugin's own Egress (a resolver that answers a public address, so nothing is
// looked up) in between, so what is tested is the library as the plugin builds it rather than a
// double of it. The first request of a client with nothing remembered is a 2026-07-28 one; a 200
// to it settles that version, so one answer per request is the whole exchange.

const MCP_TEST_URL = 'https://mcp.example.com/mcp';

/** A JSON-RPC answer to request `$id`. */
function mcpReply(int $id, array $result, int $status = 200): MockResponse
{
    return new MockResponse((string) json_encode(['jsonrpc' => '2.0', 'id' => $id, 'result' => $result]), ['http_code' => $status, 'response_headers' => ['content-type' => 'application/json']]);
}

/** A JSON-RPC error answer to request `$id`. */
function mcpRpcError(int $id, int $code, string $message, mixed $data = null, int $status = 200): MockResponse
{
    return new MockResponse((string) json_encode(['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message, 'data' => $data]]), ['http_code' => $status, 'response_headers' => ['content-type' => 'application/json']]);
}

/**
 * PhpAgentsClient::over() a server, over a MockHttpClient answering `$responses` in turn. `$sent`
 * collects each request's options as the transport was handed them.
 *
 * @param list<MockResponse> $responses
 * @param list<array<string, mixed>>|null $sent
 */
function mcpAdapter(array $responses, ?array &$sent = null, ?ServerConfig $server = null): PhpAgentsClient
{
    $sent = [];
    $mock = new MockHttpClient(static function (string $method, string $url, array $options) use (&$responses, &$sent): MockResponse {
        $sent[] = $options;
        return array_shift($responses) ?? new MockResponse('', ['http_code' => 500]);
    });
    return PhpAgentsClient::over(
        $server ?? new ServerConfig('trk', MCP_TEST_URL, 'Authorization', 'Bearer adapter-7d2c', 'trk', 12.5, 4096),
        new Egress(static fn(string $host, string $url): array => ['93.184.216.34'], $mock),
    );
}

/** What `$call` threw, or null. */
function mcpThrown(\Closure $call): ?\Throwable
{
    try {
        $call();
    } catch (\Throwable $e) {
        return $e;
    }
    return null;
}

/** The pinned vectors: the JSON a server lists, and the digest both classes give it. */
function mcpFingerprintVectors(): array
{
    return [
        'A' => ['{"name":"t","description":"a","inputSchema":{"type":"object","properties":{"n":{"type":"number","maximum":1e999}}},"annotations":{"readOnlyHint":true}}', '6660688c022f245f5c8a9768b11ec1a3c1a0dd3de1872d301879fad7c8a34dee'],
        'B' => ['{"name":"t","description":"b","inputSchema":{"type":"object","properties":{"n":{"type":"number","maximum":1e999}}},"annotations":{"readOnlyHint":true}}', '8176581eb88c5abdc60a54dabe456e93db0a2e0fd45179ba97db6083ad21cdbe'],
        'P' => ['{"name":"t","description":"a","inputSchema":{"type":"object","properties":{"n":{"type":"number","maximum":0.1}}},"annotations":{}}', 'da6c52e490a72e261c856c1c1c1ddd7359577aec30c49bd649189647023ebc36'],
    ];
}

it('lists the server\'s tools as the plugin\'s definitions, name, description, schema, annotations and title as sent', function (): void {
    $tools = [
        ['name' => 'search', 'title' => 'Search things', 'description' => 'Search the tracker.', 'inputSchema' => ['type' => 'object', 'properties' => ['q' => ['type' => 'string']]], 'annotations' => ['title' => 'Search', 'readOnlyHint' => true]],
        ['name' => 'plain', 'inputSchema' => ['type' => 'object']],
    ];
    $listed = mcpAdapter([mcpReply(1, ['tools' => $tools])])->listTools();
    expect($listed)->toHaveCount(2)
        ->and($listed[0])->toEqual(new ToolDefinition('search', 'Search the tracker.', ['type' => 'object', 'properties' => ['q' => ['type' => 'string']]], ['title' => 'Search', 'readOnlyHint' => true], 'Search things'))
        ->and($listed[1])->toEqual(new ToolDefinition('plain', '', ['type' => 'object'], [], null));
});

// The stored pins were computed by ToolDefinition; a definition that arrives through the client
// must hash as one assembled by hand from the same values, and the library's own fingerprint()
// must agree, at either serialize_precision.
it('hashes a listed definition to the digest the pins were computed with, and the library agrees', function (string $json, string $digest): void {
    $before = (string) ini_get('serialize_precision');
    try {
        foreach (['-1', '17'] as $precision) {
            ini_set('serialize_precision', $precision);
            $entry = json_decode($json, true);
            // Written into the body as it is: json_encode() cannot write the INF that 1e999 decodes to.
            $listed = mcpAdapter([new MockResponse('{"jsonrpc":"2.0","id":1,"result":{"tools":[' . $json . ']}}', ['response_headers' => ['content-type' => 'application/json']])])->listTools();
            $library = new McpToolDefinition($entry['name'], $entry['description'], $entry['inputSchema'], $entry['annotations']);
            $plugin = new ToolDefinition($entry['name'], $entry['description'], $entry['inputSchema'], $entry['annotations']);
            expect($listed[0]->fingerprint())->toBe($digest)
                ->and($plugin->fingerprint())->toBe($digest)
                ->and($library->fingerprint())->toBe($digest);
        }
    } finally {
        ini_set('serialize_precision', $before);
    }
})->with(mcpFingerprintVectors());

it('builds the library\'s server from the row: its URL, its one header, its timeout and its byte cap', function (): void {
    $adapter = mcpAdapter([]);
    $client = (new ReflectionProperty(PhpAgentsClient::class, 'client'))->getValue($adapter);
    $server = (new ReflectionProperty(McpClient::class, 'server'))->getValue($client);
    expect($server)->toEqual(new McpServer(url: MCP_TEST_URL, headers: ['Authorization' => 'Bearer adapter-7d2c'], timeout: 12.5, maxResponseBytes: 4096));
});

it('sends the one header the row names, and none when its name or its value is empty', function (string $name, string $value, array $expected): void {
    mcpAdapter([mcpReply(1, ['tools' => []])], $sent, new ServerConfig('trk', MCP_TEST_URL, $name, $value, 'trk'))->listTools();
    $headers = array_values(array_filter($sent[0]['headers'], static fn(string $line): bool => !preg_match('/^(MCP-Protocol-Version|Mcp-Method|Content-Type|Accept|Content-Length):/i', $line)));
    expect($headers)->toBe($expected);
})->with([
    'a name and a value' => ['X-Api-Key', 'key-4410', ['X-Api-Key: key-4410']],
    'no name' => ['', 'key-4410', []],
    'no value' => ['X-Api-Key', '', []],
    'digits PHP keeps as a string key' => ['0123', 'key-4410', ['0123: key-4410']],
    'a signed zero PHP keeps as a string key' => ['-0', 'key-4410', ['-0: key-4410']],
    'a plus sign PHP keeps as a string key' => ['+1', 'key-4410', ['+1: key-4410']],
    'a whole number past PHP_INT_MAX, which PHP keeps as a string key' => ['99999999999999999999', 'key-4410', ['99999999999999999999: key-4410']],
]);

// What php-agents is handed is a string name and a string value, whatever the row stored: fromSettings()
// types the row, and over() hands on only those two typed fields.
it('hands the library a string header value even for a row that stored a number', function (): void {
    $adapter = mcpAdapter([], $sent, ServerConfig::fromSettings(['id' => 'trk', 'url' => MCP_TEST_URL, 'header_name' => 'X-Api-Key', 'header_value' => 12345, 'prefix' => 'trk']));
    $client = (new ReflectionProperty(PhpAgentsClient::class, 'client'))->getValue($adapter);
    $server = (new ReflectionProperty(McpClient::class, 'server'))->getValue($client);
    expect($server->headers)->toBe(['X-Api-Key' => '12345']);
});

// PHP turns an array key that is a whole number into an int, and Symfony reads an int-keyed
// header as a whole "Name: value" line, so the value would go out as the header's name. The
// adapter contacts nothing rather than send that, and says so without the value.
it('refuses a header name that is a whole number before anything is sent, without the value', function (string $name): void {
    $sent = null;
    $thrown = mcpThrown(static function () use (&$sent, $name): void {
        mcpAdapter([mcpReply(1, ['tools' => []])], $sent, new ServerConfig('trk', MCP_TEST_URL, $name, 'Bearer digits-5c0', 'trk'))->listTools();
    });
    expect($thrown)->toBeInstanceOf(McpUnavailable::class)
        ->and($thrown->getMessage())->toContain($name)
        ->and($thrown->getMessage())->not->toContain('digits-5c0')
        ->and($sent)->toBe([]);
})->with(['123', '-1', '0']);

it('returns the library\'s result unchanged, a tool error included', function (): void {
    $given = ToolResult::success('two hits');
    $fake = new class ($given) implements McpClientInterface {
        public function __construct(private ToolResult $result) {}

        public function listTools(): array
        {
            return [];
        }

        public function callTool(string $name, array $arguments): ToolResult
        {
            return $this->result;
        }
    };
    expect((new PhpAgentsClient($fake))->callTool('search', ['q' => 'x']))->toBe($given);

    $tool = ['name' => 'search', 'inputSchema' => ['type' => 'object']];
    $ok = mcpAdapter([mcpReply(1, ['tools' => [$tool]]), mcpReply(2, ['content' => [['type' => 'text', 'text' => 'two hits']]])])->callTool('search', ['q' => 'x']);
    $failed = mcpAdapter([mcpReply(1, ['tools' => [$tool]]), mcpReply(2, ['content' => [['type' => 'text', 'text' => 'no such issue']], 'isError' => true])])->callTool('search', ['q' => 'x']);
    expect($ok->status)->toBe(ToolResultStatus::Success)
        ->and($ok->content)->toBe('two hits')
        ->and($failed->status)->toBe(ToolResultStatus::Error)
        ->and($failed->content)->toBe('no such issue');
});

/*
 * R28-5: what reaches the Discover notice, the model or a log is the plugin's own sentence for the
 * failure's class, with at most an HTTP status or a JSON-RPC code, never the library's text, the
 * server's text, McpRpcException::$data, a previous exception's text, the URL or a header value.
 * The library's exception stays chained as `previous`.
 */
it('says why a listing failed in the plugin\'s own words, per class of failure', function (MockResponse $response, string $class, string $message): void {
    $thrown = mcpThrown(static fn() => mcpAdapter([$response])->listTools());
    expect($thrown)->toBeInstanceOf(McpUnavailable::class)
        ->and($thrown->getMessage())->toBe($message)
        ->and($thrown->getPrevious())->toBeInstanceOf($class);
})->with([
    '401' => [new MockResponse('', ['http_code' => 401]), McpAuthException::class, 'The MCP server refused the credentials it was sent (HTTP 401).'],
    '403' => [new MockResponse('', ['http_code' => 403]), McpAuthException::class, 'The MCP server refused the credentials it was sent (HTTP 403).'],
    'a redirect' => [new MockResponse('', ['http_code' => 302, 'response_headers' => ['location' => 'https://elsewhere.test/']]), McpRedirectException::class, 'The MCP server answered with a redirect (HTTP 302), which is not followed.'],
    'no shared protocol version' => [mcpRpcError(1, -32022, 'unsupported', ['supported' => ['1999-01-01']], 400), McpUnsupportedVersionException::class, 'The MCP server speaks no protocol version this client does.'],
    'a JSON-RPC error' => [mcpRpcError(1, -32601, 'no such method'), McpRpcException::class, 'The MCP server answered with JSON-RPC error -32601.'],
    'an answer that is not MCP' => [new MockResponse('<html>hi</html>', ['response_headers' => ['content-type' => 'text/html']]), McpProtocolException::class, 'The MCP server answered, but not in a way this client can use.'],
    'an HTTP error status' => [new MockResponse('', ['http_code' => 500]), McpTransportException::class, 'The MCP server could not be reached, or gave no usable HTTP answer: a network error, a timeout, an answer over the byte cap or an HTTP error status.'],
]);

it('keeps the URL\'s query and the header value out of a transport failure\'s message, though the transport quoted both', function (): void {
    $server = new ServerConfig('trk', MCP_TEST_URL . '?token=SECRETQUERY123', 'Authorization', 'Bearer SECRETHEADER123', 'trk');
    $quoting = new MockResponse('', ['error' => 'Could not resolve host for "' . MCP_TEST_URL . '?token=SECRETQUERY123" (Authorization: Bearer SECRETHEADER123).']);
    $thrown = mcpThrown(static fn() => mcpAdapter([$quoting], $sent, $server)->listTools());
    expect($thrown)->toBeInstanceOf(McpUnavailable::class)
        ->and($thrown->getMessage())->not->toContain('SECRETQUERY123')
        ->and($thrown->getMessage())->not->toContain('SECRETHEADER123')
        ->and($thrown->getPrevious())->toBeInstanceOf(McpTransportException::class)
        // The secrets were there to leak: the transport's own exception, two links down, quotes them.
        ->and($thrown->getPrevious()?->getPrevious()?->getMessage())->toContain('SECRETQUERY123');
});

it('keeps a JSON-RPC error\'s server text and data out of the message, on a listing and on a call', function (): void {
    $server = new ServerConfig('trk', MCP_TEST_URL . '?token=SECRETQUERY123', 'Authorization', 'Bearer SECRETHEADER123', 'trk');
    $listing = mcpThrown(static fn() => mcpAdapter([mcpRpcError(1, -32603, 'failed for token SECRETQUERY123', 'SECRETDATA123')], $sent, $server)->listTools());
    $tool = ['name' => 'search', 'inputSchema' => ['type' => 'object']];
    // The WordPress MCP Adapter answers an unknown tool with a 404 carrying -32003.
    $call = mcpThrown(static fn() => mcpAdapter([mcpReply(1, ['tools' => [$tool]]), mcpRpcError(2, -32003, 'no tool SECRETQUERY123', 'SECRETDATA123', 404)], $sent, $server)->callTool('gone', []));
    foreach ([$listing, $call] as $thrown) {
        expect($thrown)->toBeInstanceOf(McpUnavailable::class)
            ->and($thrown->getPrevious())->toBeInstanceOf(McpRpcException::class)
            ->and($thrown->getPrevious()->getMessage())->toContain('SECRETQUERY123')
            ->and($thrown->getPrevious()->data)->toBe('SECRETDATA123')
            ->and($thrown->getMessage())->not->toContain('SECRETQUERY123')
            ->and($thrown->getMessage())->not->toContain('SECRETDATA123');
    }
    expect($listing->getMessage())->toBe('The MCP server answered with JSON-RPC error -32603.')
        ->and($call->getMessage())->toBe('The MCP server answered with JSON-RPC error -32003.');
});

it('says the same of a call whose transport fails', function (): void {
    $tool = ['name' => 'search', 'inputSchema' => ['type' => 'object']];
    $thrown = mcpThrown(static fn() => mcpAdapter([mcpReply(1, ['tools' => [$tool]]), new MockResponse('', ['error' => 'Timeout for "' . MCP_TEST_URL . '?token=SECRETQUERY123"'])])->callTool('search', []));
    expect($thrown)->toBeInstanceOf(McpUnavailable::class)
        ->and($thrown->getMessage())->toBe('The MCP server could not be reached, or gave no usable HTTP answer: a network error, a timeout, an answer over the byte cap or an HTTP error status.')
        ->and($thrown->getPrevious())->toBeInstanceOf(McpTransportException::class);
});

it('answers any other runtime failure with a sentence of its own, not the failure\'s text', function (): void {
    $failing = new class implements McpClientInterface {
        public function listTools(): array
        {
            throw new RuntimeException('reached https://mcp.example.com/mcp?token=SECRETQUERY123');
        }

        public function callTool(string $name, array $arguments): ToolResult
        {
            throw new RuntimeException('reached https://mcp.example.com/mcp?token=SECRETQUERY123');
        }
    };
    foreach ([static fn() => (new PhpAgentsClient($failing))->listTools(), static fn() => (new PhpAgentsClient($failing))->callTool('search', [])] as $call) {
        $thrown = mcpThrown($call);
        expect($thrown)->toBeInstanceOf(McpUnavailable::class)
            ->and($thrown->getMessage())->toBe('The MCP request failed.')
            ->and($thrown->getPrevious())->toBeInstanceOf(RuntimeException::class);
    }
});
