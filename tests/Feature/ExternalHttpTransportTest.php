<?php

declare(strict_types=1);

use Capell\AgentBridge\Actions\CreateAgentBridgeTokenAction;
use Capell\AgentBridge\Tests\Fixtures\User;

/**
 * @param  array<string, mixed>  $params
 * @return array<string, mixed>
 */
function externalHttpMcpRequest(int $id, string $method, array $params = []): array
{
    return [
        'jsonrpc' => '2.0',
        'id' => $id,
        'method' => $method,
        'params' => $params,
    ];
}

it('serves the frozen site tool catalogue through authenticated HTTP JSON-RPC', function (): void {
    $user = User::query()->create([
        'name' => 'External MCP Client',
        'email' => 'external-mcp@example.com',
        'password' => 'secret',
    ]);
    $createdToken = CreateAgentBridgeTokenAction::run($user, 'External HTTP client', ['*']);
    $plainTextToken = $createdToken['plainTextToken'];
    $headers = [
        'Authorization' => 'Bearer ' . $plainTextToken,
        'Accept' => 'application/json, text/event-stream',
    ];

    // Newer protocols use discovery instead; this client exercises the initialise handshake.
    $protocolVersion = '2025-11-25';
    $initializeResponse = $this->withHeaders($headers)->postJson(
        '/agent-bridge/capell',
        externalHttpMcpRequest(1, 'initialize', [
            'protocolVersion' => $protocolVersion,
            'capabilities' => (object) [],
            'clientInfo' => [
                'name' => 'agent-bridge-external-client-contract',
                'version' => '1.0.0',
            ],
        ]),
    );

    $initializeResponse
        ->assertOk()
        ->assertHeader('Content-Type', 'application/json')
        ->assertJsonPath('jsonrpc', '2.0')
        ->assertJsonPath('id', 1)
        ->assertJsonPath('result.protocolVersion', $protocolVersion)
        ->assertJsonPath('result.serverInfo.name', 'Capell Site Agent Bridge')
        ->assertJsonPath('result.serverInfo.version', '1.0.0');

    $toolsResponse = $this->withHeaders($headers)->postJson(
        '/agent-bridge/capell',
        externalHttpMcpRequest(2, 'tools/list'),
    );

    $toolsResponse
        ->assertOk()
        ->assertJsonPath('jsonrpc', '2.0')
        ->assertJsonPath('id', 2);

    $tools = $toolsResponse->json('result.tools');

    if (! is_array($tools)) {
        throw new RuntimeException('Expected the MCP tool catalogue to be an array.');
    }

    $toolNames = collect($tools)
        ->pluck('name')
        ->sort()
        ->values()
        ->all();

    expect($toolNames)->toBe([
        'capell-site-confirm-capability',
        'capell-site-list-capabilities',
        'capell-site-run-capability',
    ]);
});

it('rejects an unauthenticated external HTTP JSON-RPC client before dispatch', function (): void {
    $this->withHeader('Accept', 'application/json, text/event-stream')
        ->postJson('/agent-bridge/capell', externalHttpMcpRequest(1, 'initialize'))
        ->assertUnauthorized()
        ->assertHeader('WWW-Authenticate', 'Bearer realm="mcp", error="invalid_token"');
});
