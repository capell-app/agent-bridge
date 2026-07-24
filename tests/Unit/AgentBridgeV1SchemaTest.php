<?php

declare(strict_types=1);

use Capell\AgentBridge\Capabilities\BuiltInAgentBridgeCapabilityProvider;
use Capell\AgentBridge\Support\AgentBridgeMcpV1Policy;
use Capell\AgentBridge\Support\CapellAgentBridgeCapabilityRegistry;
use Capell\AgentBridge\Tools\Site\ConfirmSiteCapabilityTool;
use Capell\AgentBridge\Tools\Site\ListSiteCapabilitiesTool;
use Capell\AgentBridge\Tools\Site\RunSiteCapabilityTool;
use Illuminate\Support\Facades\File;

it('matches the frozen external client v1 catalogue and schemas', function (): void {
    $registry = new CapellAgentBridgeCapabilityRegistry;
    (new BuiltInAgentBridgeCapabilityProvider)->registerCapabilities($registry);
    $policy = new AgentBridgeMcpV1Policy;

    $catalogue = $policy->allowedCapabilities($registry->all())
        ->map(fn ($capability): array => $policy->capabilityPayload($capability))
        ->values()
        ->all();

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
    $packagePath = dirname(__DIR__, 2);
    $server = File::get($packagePath . '/src/Servers/CapellSiteServer.php');
    $localServer = File::get($packagePath . '/src/Servers/CapellLocalSiteServer.php');
    $routes = File::get($packagePath . '/routes/agent-bridge.php');
    $runTool = File::get($packagePath . '/src/Tools/Site/RunSiteCapabilityTool.php');
    $confirmTool = File::get($packagePath . '/src/Tools/Site/ConfirmSiteCapabilityTool.php');
    $externalVerifier = File::get($packagePath . '/scripts/verify-mcp-v1-transports.php');

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
        ->and($server)->toContain("#[Version('{$fixtureServerVersion}')]", ListSiteCapabilitiesTool::class, RunSiteCapabilityTool::class, ConfirmSiteCapabilityTool::class)
        ->and($localServer)->toContain('extends CapellSiteServer', 'AuthenticateLocalAgentBridgeClientAction::run()')
        ->and($routes)->toContain('Mcp::local($localHandle, CapellLocalSiteServer::class)', 'Mcp::web($siteRoute, CapellSiteServer::class)', 'AuthenticateCapellAgentBridgeToken::class')
        ->and($runTool)->toContain("'capability' => ['required'", "'payload' => ['required'")
        ->and($confirmTool)->toContain("'confirmationToken' => ['required'", "'payload' => ['required'")
        ->and($externalVerifier)->toContain(
            'capabilityContract($capability)',
            'hash(\'sha256\', json_encode($inputSchema, JSON_THROW_ON_ERROR))',
            'hash(\'sha256\', json_encode($outputSchema, JSON_THROW_ON_ERROR))',
        )
        ->and(array_column($snapshot, 'key'))->not->toContain(
            'capell.pages.publish_now',
            'capell.packages.install',
            'capell.cache.clear',
        );
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
