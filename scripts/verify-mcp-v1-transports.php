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
$fixture = decodeJsonObject((string) file_get_contents($packagePath . '/tests/Fixtures/mcp-v1-external-client.json'));
$fixtureTools = objectValue($fixture['tools'] ?? null, 'Fixture tools');
$fixtureServerVersion = mcpV1StringValue($fixture['serverVersion'] ?? null, 'Fixture server version');
$fixtureTransports = objectValue($fixture['transports'] ?? null, 'Fixture transports');
$fixtureStdioTransport = objectValue($fixtureTransports['stdio'] ?? null, 'Fixture stdio transport');
$stdioHandle = mcpV1StringValue($fixtureStdioTransport['handle'] ?? null, 'Fixture stdio handle');
$token = requiredEnvironment('CAPELL_AGENT_BRIDGE_VERIFY_TOKEN');
$httpUrl = requiredEnvironment('CAPELL_AGENT_BRIDGE_VERIFY_HTTP_URL');
$artisan = requiredEnvironment('CAPELL_AGENT_BRIDGE_VERIFY_ARTISAN');
$expectedTools = array_map(
    static fn (int|string $toolName): string => (string) $toolName,
    array_keys($fixtureTools),
);

$http = httpRequest($httpUrl, $token, rpc(1, 'initialize', initializeParams()));
assertSame($fixtureServerVersion, nestedValue($http, ['result', 'serverInfo', 'version']), 'HTTP server version');
$httpTools = httpRequest($httpUrl, $token, rpc(2, 'tools/list'));
assertToolNames($expectedTools, $httpTools, 'HTTP');
$httpCapabilities = httpRequest($httpUrl, $token, toolCall(3, 'capell-site-list-capabilities'));
assertCapabilities($fixture, $httpCapabilities, 'HTTP');
$httpRefusal = httpRequest($httpUrl, $token, toolCall(4, 'capell-site-run-capability', [
    'capability' => 'capell.pages.publish_now',
    'payload' => ['page_id' => 1],
]));
assertRefused($httpRefusal, 'HTTP publication');

$command = [PHP_BINARY, $artisan, 'mcp:start', $stdioHandle];
$stdio = openStdio($command, $token);

try {
    $stdioInitialize = stdioRequest($stdio, rpc(1, 'initialize', initializeParams()));
    assertSame($fixtureServerVersion, nestedValue($stdioInitialize, ['result', 'serverInfo', 'version']), 'stdio server version');
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

/**
 * @param  array<string, mixed>  $params
 * @return array<string, mixed>
 */
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

/**
 * @param  array<string, mixed>  $arguments
 * @return array<string, mixed>
 */
function toolCall(int $id, string $name, array $arguments = []): array
{
    return rpc($id, 'tools/call', ['name' => $name, 'arguments' => $arguments]);
}

/**
 * @param  array<string, mixed>  $request
 * @return array<string, mixed>
 */
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

    return decodeJsonObject($response);
}

/**
 * @param  list<string>  $command
 * @return array{0: resource, 1: resource, 2: resource, 3: resource}
 */
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

/**
 * @param  array{0: resource, 1: resource, 2: resource, 3: resource}  $stdio
 * @param  array<string, mixed>  $request
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

    return decodeJsonObject($response);
}

/**
 * @param  list<string>  $expected
 * @param  array<string, mixed>  $response
 */
function assertToolNames(array $expected, array $response, string $transport): void
{
    $tools = arrayObjectList(nestedValue($response, ['result', 'tools']), 'MCP tool catalogue');
    $actual = array_column($tools, 'name');
    sort($expected);
    sort($actual);
    assertSame($expected, $actual, "{$transport} tool catalogue");
}

/**
 * @param  array<string, mixed>  $fixture
 * @param  array<string, mixed>  $response
 */
function assertCapabilities(array $fixture, array $response, string $transport): void
{
    $expected = arrayObjectList($fixture['capabilities'] ?? null, 'Fixture capabilities');
    $capabilities = arrayObjectList(nestedValue($response, ['result', 'structuredContent', 'capabilities']), 'MCP capabilities');
    $actual = array_map(
        static fn (array $capability): array => capabilityContract($capability),
        $capabilities,
    );

    assertSame($expected, $actual, "{$transport} capability contract");
}

/**
 * @param  array<string, mixed>  $capability
 * @return array<string, mixed>
 */
function capabilityContract(array $capability): array
{
    $inputSchema = is_array($capability['inputSchema'] ?? null) ? $capability['inputSchema'] : [];
    $outputSchema = is_array($capability['outputSchema'] ?? null) ? $capability['outputSchema'] : [];

    return [
        'key' => $capability['key'] ?? null,
        'scope' => $capability['scope'] ?? null,
        'risk' => $capability['risk'] ?? null,
        'requiresConfirmation' => $capability['requiresConfirmation'] ?? null,
        'inputSchemaSha256' => hash('sha256', json_encode($inputSchema, JSON_THROW_ON_ERROR)),
        'outputSchemaSha256' => hash('sha256', json_encode($outputSchema, JSON_THROW_ON_ERROR)),
    ];
}

/** @param array<string, mixed> $response */
function assertRefused(array $response, string $label): void
{
    assertSame('forbidden', nestedValue($response, ['result', 'structuredContent', 'error', 'code']), "{$label} refusal");
}

/** @return array<string, mixed> */
function decodeJsonObject(string $json): array
{
    return objectValue(json_decode($json, true, 512, JSON_THROW_ON_ERROR), 'JSON response');
}

/** @return array<string, mixed> */
function objectValue(mixed $value, string $context): array
{
    if (! is_array($value)) {
        throw new RuntimeException("{$context} must be an object.");
    }

    $object = [];

    foreach ($value as $key => $item) {
        if (! is_string($key)) {
            throw new RuntimeException("{$context} must use string keys.");
        }

        $object[$key] = $item;
    }

    return $object;
}

/** @return list<array<string, mixed>> */
function arrayObjectList(mixed $value, string $context): array
{
    if (! is_array($value) || ! array_is_list($value)) {
        throw new RuntimeException("{$context} must be a list.");
    }

    $objects = [];

    foreach ($value as $item) {
        $objects[] = objectValue($item, $context);
    }

    return $objects;
}

function mcpV1StringValue(mixed $value, string $context): string
{
    if (! is_string($value)) {
        throw new RuntimeException("{$context} must be a string.");
    }

    return $value;
}

/**
 * @param  array<string, mixed>  $payload
 * @param  list<string>  $keys
 */
function nestedValue(array $payload, array $keys): mixed
{
    $value = $payload;

    foreach ($keys as $key) {
        if (! is_array($value) || ! array_key_exists($key, $value)) {
            return null;
        }

        $value = $value[$key];
    }

    return $value;
}

function assertSame(mixed $expected, mixed $actual, string $label): void
{
    if ($expected !== $actual) {
        throw new RuntimeException("{$label} mismatch.");
    }
}
