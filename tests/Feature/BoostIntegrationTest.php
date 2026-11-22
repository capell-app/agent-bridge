<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

/** @param array<string, mixed> $params */
function requestBoostMcp(string $method, array $params = [], string $agentBridgeState = 'enabled'): stdClass
{
    $process = new Process(
        [PHP_BINARY, __DIR__ . '/../Fixtures/boost-artisan.php', 'boost:mcp'],
        env: [
            'AGENT_BRIDGE_FIXTURE_STATE' => $agentBridgeState,
            'CACHE_STORE' => 'array',
            'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => ':memory:',
            'DB_URL' => '',
        ],
        input: json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => (object) $params], JSON_THROW_ON_ERROR) . "\n",
        timeout: 30,
    );

    $process->mustRun();

    $response = json_decode(trim($process->getOutput()), flags: JSON_THROW_ON_ERROR);
    throw_unless($response instanceof stdClass, RuntimeException::class, 'Expected a Boost MCP response object.');
    expect(data_get($response, 'jsonrpc'))->toBe('2.0')
        ->and(data_get($response, 'id'))->toBe(1);

    return $response;
}

/** @return list<mixed> */
function discoverBoostTools(string $agentBridgeState): array
{
    $response = requestBoostMcp('tools/list', agentBridgeState: $agentBridgeState);
    $toolNames = array_column((array) data_get($response, 'result.tools'), 'name');
    expect($toolNames)->toContain('get-absolute-url');

    return $toolNames;
}

function boostToolPayload(stdClass $response): mixed
{
    $text = data_get($response, 'result.content.0.text');
    throw_unless(is_string($text), RuntimeException::class, 'Expected a text result from the Boost tool.');
    expect(data_get($response, 'result.isError'))->toBeFalse($text);

    return json_decode($text, true, flags: JSON_THROW_ON_ERROR);
}

it('lists the Capell tools through the Boost MCP command when Agent Bridge is enabled', function (): void {
    expect(discoverBoostTools('enabled'))
        ->toContain('capell-list-capabilities', 'capell-preview-capability');
});

it('omits the Capell tools from Boost when Agent Bridge is disabled', function (): void {
    expect(discoverBoostTools('disabled'))
        ->not->toContain('capell-list-capabilities')
        ->not->toContain('capell-preview-capability');
});

it('returns the visible capability catalogue through Boost tools/call', function (): void {
    $payload = boostToolPayload(requestBoostMcp('tools/call', ['name' => 'capell-list-capabilities']));

    expect(array_column((array) data_get($payload, 'capabilities'), 'key'))->toContain('capell.fixture.read')
        ->and(data_get($payload, 'confirmation'))->toContain('authenticated Capell Site Agent Bridge');
});

it('refuses to call the catalogue tool through Boost when Agent Bridge is disabled', function (): void {
    $response = requestBoostMcp('tools/call', ['name' => 'capell-list-capabilities'], agentBridgeState: 'disabled');

    expect(data_get($response, 'error.code'))->toBe(-32602)
        ->and(data_get($response, 'error.message'))->toBe('Tool [capell-list-capabilities] not found.');
});

it('returns a capability preview through Boost tools/call', function (): void {
    $payload = boostToolPayload(requestBoostMcp('tools/call', [
        'name' => 'capell-preview-capability',
        'arguments' => ['capability' => 'capell.fixture.read', 'payload' => ['name' => 'Boost example']],
    ]));

    expect(data_get($payload, 'mode'))->toBe('preview')
        ->and(data_get($payload, 'capability'))->toBe('capell.fixture.read')
        ->and(data_get($payload, 'preview.ok'))->toBeTrue()
        ->and(data_get($payload, 'preview.message'))->toBe('Previewed fake capability.')
        ->and(data_get($payload, 'preview.data.payload'))->toBe(['name' => 'Boost example']);
});

it('returns an MCP tool error for a preview requiring an authenticated user', function (): void {
    $response = requestBoostMcp('tools/call', [
        'name' => 'capell-preview-capability',
        'arguments' => ['capability' => 'capell.packages.search', 'payload' => ['query' => 'agent-bridge']],
    ]);

    expect(data_get($response, 'result.isError'))->toBeTrue()
        ->and(data_get($response, 'result.content.0.text'))->toBe('Capability [capell.packages.search] needs an authenticated user.');
});
