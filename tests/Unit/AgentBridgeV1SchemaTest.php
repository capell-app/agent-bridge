<?php

declare(strict_types=1);

use Capell\AgentBridge\Actions\CreateAgentBridgeTokenAction;
use Capell\AgentBridge\Capabilities\BuiltInAgentBridgeCapabilityProvider;
use Capell\AgentBridge\Servers\CapellLocalSiteServer;
use Capell\AgentBridge\Support\AgentBridgeMcpV1Policy;
use Capell\AgentBridge\Support\CapellAgentBridgeCapabilityRegistry;
use Capell\AgentBridge\Tests\Fixtures\User;
use Capell\Tests\Support\InMemoryMcpTransport;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\File;
use Laravel\Mcp\Facades\Mcp;

it('matches the frozen external client v1 catalogue and schemas', function (): void {
    $registry = new CapellAgentBridgeCapabilityRegistry;
    (new BuiltInAgentBridgeCapabilityProvider)->registerCapabilities($registry);
    $policy = new AgentBridgeMcpV1Policy;

    $catalogue = $policy->allowedCapabilities($registry->all())
        ->map(fn ($capability): array => $policy->capabilityPayload($capability))
        ->values()
        ->all();

    // Frozen schema digests protect the versioned wire contract consumed by external clients.
    $snapshot = array_map(static fn (array $capability): array => [
        'key' => $capability['key'],
        'scope' => $capability['scope'],
        'risk' => $capability['risk'],
        'requiresConfirmation' => $capability['requiresConfirmation'],
        'inputSchemaSha256' => hash('sha256', json_encode($capability['inputSchema'], JSON_THROW_ON_ERROR)),
        'outputSchemaSha256' => hash('sha256', json_encode($capability['outputSchema'], JSON_THROW_ON_ERROR)),
    ], $catalogue);
    $fixture = agentBridgeV1Fixture(json_decode(
        File::get(dirname(__DIR__) . '/Fixtures/mcp-v1-external-client.json'),
        true,
        512,
        JSON_THROW_ON_ERROR,
    ));
    $fixtureCapabilities = agentBridgeV1ObjectList($fixture['capabilities'] ?? null, 'Fixture capabilities');
    $fixtureTools = agentBridgeV1Object($fixture['tools'] ?? null, 'Fixture tools');
    $fixturePublicResultFields = agentBridgeV1StringList($fixture['publicResultFields'] ?? null, 'Fixture public result fields');
    $fixtureRefusalContract = agentBridgeV1String($fixture['refusalContract'] ?? null, 'Fixture refusal contract');
    $fixtureMutationContract = agentBridgeV1Object($fixture['mutationContract'] ?? null, 'Fixture mutation contract');
    $fixtureAudit = agentBridgeV1String($fixtureMutationContract['audit'] ?? null, 'Fixture audit contract');
    $fixtureServerVersion = agentBridgeV1String($fixture['serverVersion'] ?? null, 'Fixture server version');
    $fixtureTransports = agentBridgeV1Object($fixture['transports'] ?? null, 'Fixture transports');
    $fixtureHttpTransport = agentBridgeV1Object($fixtureTransports['http'] ?? null, 'Fixture HTTP transport');
    $fixtureStdioTransport = agentBridgeV1Object($fixtureTransports['stdio'] ?? null, 'Fixture stdio transport');
    $fixtureHttpRoute = agentBridgeV1String($fixtureHttpTransport['route'] ?? null, 'Fixture HTTP route');
    $fixtureStdioHandle = agentBridgeV1String($fixtureStdioTransport['handle'] ?? null, 'Fixture stdio handle');
    expect($snapshot)->toBe($fixtureCapabilities)
        ->and(array_keys($fixtureTools))->toBe([
            'capell-site-list-capabilities',
            'capell-site-run-capability',
            'capell-site-confirm-capability',
        ])
        ->and($fixturePublicResultFields)->toBe(['ok', 'message', 'data', 'warnings'])
        ->and($fixtureRefusalContract)->toBe('all-capabilities-outside-closed-allowlist')
        ->and($fixtureAudit)->toBe('redacted-retention-bounded')
        ->and($fixtureServerVersion)->toBe('1.0.0')
        ->and($fixtureHttpRoute)->toBe(config('capell-agent-bridge.routes.site'))
        ->and($fixtureStdioHandle)->toBe(config('capell-agent-bridge.local.handle'))
        ->and(array_column($snapshot, 'key'))->not->toContain(
            'capell.pages.publish_now',
            'capell.packages.install',
            'capell.cache.clear',
        );
});

it('serves the frozen catalogue and required schemas over the authenticated local transport', function (): void {
    $fixture = agentBridgeV1Fixture(json_decode(
        File::get(dirname(__DIR__) . '/Fixtures/mcp-v1-external-client.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    ));
    $user = User::query()->create(['name' => 'Local client', 'email' => 'local-client@example.test', 'password' => 'secret']);
    $token = CreateAgentBridgeTokenAction::run($user, 'Local client', ['*']);
    config()->set('capell-agent-bridge.local.token', $token['plainTextToken']);
    expect(is_callable(Mcp::getLocalServer(agentBridgeV1String(config('capell-agent-bridge.local.handle'), 'Local transport handle'))))->toBeTrue();
    $transport = new InMemoryMcpTransport;
    (new CapellLocalSiteServer($transport))->start();
    $initialized = $transport->request('initialize', [
        'protocolVersion' => '2025-11-25', 'capabilities' => (object) [],
        'clientInfo' => ['name' => 'Contract client', 'version' => '1.0.0'],
    ]);
    expect(data_get($initialized, 'result.serverInfo.version'))->toBe($fixture['serverVersion']);
    $catalogue = $transport->request('tools/list');
    $tools = agentBridgeV1ObjectList(data_get($catalogue, 'result.tools'), 'Published tools');
    $required = [];
    foreach ($tools as $tool) {
        $name = agentBridgeV1String($tool['name'] ?? null, 'Tool name');
        $required[$name] = ['required' => data_get($tool, 'inputSchema.required', [])];
    }
    expect($required)->toEqualCanonicalizing($fixture['tools']);
});

it('rejects local transport startup without a usable user token', function (): void {
    config()->set('capell-agent-bridge.local.token', '');
    expect(fn () => (new CapellLocalSiteServer(new InMemoryMcpTransport))->start())
        ->toThrow(AuthorizationException::class);
});

it('rejects object-shaped values at frozen mcp v1 list boundaries', function (): void {
    expect(fn (): array => agentBridgeV1ObjectList(['capability' => []], 'Capabilities'))
        ->toThrow(RuntimeException::class, 'Capabilities must be a list.')
        ->and(fn (): array => agentBridgeV1StringList(['field' => 'ok'], 'Public result fields'))
        ->toThrow(RuntimeException::class, 'Public result fields must be a list.');
});

/** @return array<string, mixed> */
function agentBridgeV1Fixture(mixed $fixture): array
{
    return agentBridgeV1Object($fixture, 'MCP v1 fixture');
}

/** @return array<string, mixed> */
function agentBridgeV1Object(mixed $value, string $context): array
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
function agentBridgeV1ObjectList(mixed $value, string $context): array
{
    if (! is_array($value) || ! array_is_list($value)) {
        throw new RuntimeException("{$context} must be a list.");
    }

    $objects = [];

    foreach ($value as $item) {
        $objects[] = agentBridgeV1Object($item, $context);
    }

    return $objects;
}

/** @return list<string> */
function agentBridgeV1StringList(mixed $value, string $context): array
{
    if (! is_array($value) || ! array_is_list($value)) {
        throw new RuntimeException("{$context} must be a list.");
    }

    $strings = [];

    foreach ($value as $item) {
        $strings[] = agentBridgeV1String($item, $context);
    }

    return $strings;
}

function agentBridgeV1String(mixed $value, string $context): string
{
    if (! is_string($value)) {
        throw new RuntimeException("{$context} must be a string.");
    }

    return $value;
}
