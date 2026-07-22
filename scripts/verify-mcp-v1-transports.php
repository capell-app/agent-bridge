<?php

declare(strict_types=1);

/**
 * External Agent Bridge MCP v1 transport verifier.
 *
 * Required environment:
 * - CAPELL_AGENT_BRIDGE_VERIFY_TOKEN
 * - CAPELL_AGENT_BRIDGE_VERIFY_HTTP_URL
 * - CAPELL_AGENT_BRIDGE_VERIFY_ARTISAN (path to the host app artisan file)
 */
$packagePath = dirname(__DIR__);
$fixture = json_decode(
    (string) file_get_contents($packagePath . '/tests/Fixtures/mcp-v1-external-client.json'),
    true,
    512,
    JSON_THROW_ON_ERROR,
);
$token = requiredEnvironment('CAPELL_AGENT_BRIDGE_VERIFY_TOKEN');
$httpUrl = requiredEnvironment('CAPELL_AGENT_BRIDGE_VERIFY_HTTP_URL');
$artisan = requiredEnvironment('CAPELL_AGENT_BRIDGE_VERIFY_ARTISAN');
$expectedTools = array_keys($fixture['tools']);

$http = httpRequest($httpUrl, $token, rpc(1, 'initialize', initializeParams()));
assertSame($fixture['serverVersion'], $http['result']['serverInfo']['version'] ?? null, 'HTTP server version');
$httpTools = httpRequest($httpUrl, $token, rpc(2, 'tools/list'));
assertToolNames($expectedTools, $httpTools, 'HTTP');
$httpCapabilities = httpRequest($httpUrl, $token, toolCall(3, 'capell-site-list-capabilities'));
assertCapabilities($fixture, $httpCapabilities, 'HTTP');
$httpRefusal = httpRequest($httpUrl, $token, toolCall(4, 'capell-site-run-capability', [
    'capability' => 'capell.pages.publish_now',
    'payload' => ['page_id' => 1],
]));
assertRefused($httpRefusal, 'HTTP publication');

$command = [PHP_BINARY, $artisan, 'mcp:start', $fixture['transports']['stdio']['handle']];
$stdio = openStdio($command, $token);

try {
    $stdioInitialize = stdioRequest($stdio, rpc(1, 'initialize', initializeParams()));
    assertSame($fixture['serverVersion'], $stdioInitialize['result']['serverInfo']['version'] ?? null, 'stdio server version');
    fwrite($stdio[0], json_encode(['jsonrpc' => '2.0', 'method' => 'notifications/initialized'], JSON_THROW_ON_ERROR) . "\n");
    $stdioTools = stdioRequest($stdio, rpc(2, 'tools/list'));
    assertToolNames($expectedTools, $stdioTools, 'stdio');
    $stdioCapabilities = stdioRequest($stdio, toolCall(3, 'capell-site-list-capabilities'));
    assertCapabilities($fixture, $stdioCapabilities, 'stdio');
    $stdioRefusal = stdioRequest($stdio, toolCall(4, 'capell-site-run-capability', [
        'capability' => 'capell.pages.publish_now',
        'payload' => ['page_id' => 1],
    ]));
    assertRefused($stdioRefusal, 'stdio publication');
} finally {
    fclose($stdio[0]);
    fclose($stdio[1]);
    fclose($stdio[2]);
    proc_terminate($stdio[3]);
    proc_close($stdio[3]);
}

fwrite(STDOUT, "Agent Bridge MCP v1 HTTP/stdio catalogue parity verified.\n");

function requiredEnvironment(string $key): string
{
    $value = getenv($key);

    if (! is_string($value) || trim($value) === '') {
        throw new RuntimeException("Missing required environment variable {$key}.");
    }

    return $value;
}

/** @return array<string, mixed> */
function rpc(int $id, string $method, array $params = []): array
{
    return ['jsonrpc' => '2.0', 'id' => $id, 'method' => $method, 'params' => $params];
}

/** @return array<string, mixed> */
function initializeParams(): array
{
    return [
        'protocolVersion' => '2025-06-18',
        'capabilities' => (object) [],
        'clientInfo' => ['name' => 'capell-agent-bridge-verifier', 'version' => '1.0.0'],
    ];
}

/** @return array<string, mixed> */
function toolCall(int $id, string $name, array $arguments = []): array
{
    return rpc($id, 'tools/call', ['name' => $name, 'arguments' => $arguments]);
}

/** @return array<string, mixed> */
function httpRequest(string $url, string $token, array $request): array
{
    $context = stream_context_create(['http' => [
        'method' => 'POST',
        'header' => "Authorization: Bearer {$token}\r\nAccept: application/json, text/event-stream\r\nContent-Type: application/json\r\n",
        'content' => json_encode($request, JSON_THROW_ON_ERROR),
        'ignore_errors' => true,
        'timeout' => 15,
    ]]);
    $response = file_get_contents($url, false, $context);

    if (! is_string($response)) {
        throw new RuntimeException('HTTP MCP request failed.');
    }

    return json_decode($response, true, 512, JSON_THROW_ON_ERROR);
}

/** @return array{0: resource, 1: resource, 2: resource, 3: resource} */
function openStdio(array $command, string $token): array
{
    $pipes = [];
    $environment = array_merge($_ENV, ['CAPELL_AGENT_BRIDGE_LOCAL_TOKEN' => $token]);
    $process = proc_open($command, [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, null, $environment);

    if (! is_resource($process) || count($pipes) !== 3) {
        throw new RuntimeException('Could not start the local MCP stdio process.');
    }

    return [$pipes[0], $pipes[1], $pipes[2], $process];
}

/** @param array{0: resource, 1: resource, 2: resource, 3: resource} $stdio
 * @return array<string, mixed>
 */
function stdioRequest(array $stdio, array $request): array
{
    fwrite($stdio[0], json_encode($request, JSON_THROW_ON_ERROR) . "\n");
    fflush($stdio[0]);
    $response = fgets($stdio[1]);

    if (! is_string($response)) {
        $error = stream_get_contents($stdio[2]);
        throw new RuntimeException('stdio MCP request failed: ' . $error);
    }

    return json_decode($response, true, 512, JSON_THROW_ON_ERROR);
}

function assertToolNames(array $expected, array $response, string $transport): void
{
    $actual = array_column($response['result']['tools'] ?? [], 'name');
    sort($expected);
    sort($actual);
    assertSame($expected, $actual, "{$transport} tool catalogue");
}

function assertCapabilities(array $fixture, array $response, string $transport): void
{
    $expected = array_column($fixture['capabilities'], 'key');
    $actual = array_column($response['result']['structuredContent']['capabilities'] ?? [], 'key');
    assertSame($expected, $actual, "{$transport} capability catalogue");
}

function assertRefused(array $response, string $label): void
{
    assertSame('forbidden', $response['result']['structuredContent']['error']['code'] ?? null, "{$label} refusal");
}

function assertSame(mixed $expected, mixed $actual, string $label): void
{
    if ($expected !== $actual) {
        throw new RuntimeException("{$label} mismatch.");
    }
}
