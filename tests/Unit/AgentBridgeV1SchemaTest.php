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
    $fixture = json_decode(
        File::get(dirname(__DIR__) . '/Fixtures/mcp-v1-external-client.json'),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );
    $packagePath = dirname(__DIR__, 2);
    $server = File::get($packagePath . '/src/Servers/CapellSiteServer.php');
    $localServer = File::get($packagePath . '/src/Servers/CapellLocalSiteServer.php');
    $routes = File::get($packagePath . '/routes/agent-bridge.php');
    $runTool = File::get($packagePath . '/src/Tools/Site/RunSiteCapabilityTool.php');
    $confirmTool = File::get($packagePath . '/src/Tools/Site/ConfirmSiteCapabilityTool.php');

    expect($snapshot)->toBe($fixture['capabilities'])
        ->and(array_keys($fixture['tools']))->toBe([
            'capell-site-list-capabilities',
            'capell-site-run-capability',
            'capell-site-confirm-capability',
        ])
        ->and($fixture['publicResultFields'])->toBe(['ok', 'message', 'data', 'warnings'])
        ->and($fixture['refusalContract'])->toBe('all-capabilities-outside-closed-allowlist')
        ->and($fixture['mutationContract']['audit'])->toBe('redacted-retention-bounded')
        ->and($fixture['serverVersion'])->toBe('1.0.0')
        ->and($fixture['transports']['http']['route'])->toBe(config('capell-agent-bridge.routes.site'))
        ->and($fixture['transports']['stdio']['handle'])->toBe(config('capell-agent-bridge.local.handle'))
        ->and($server)->toContain("#[Version('{$fixture['serverVersion']}')]", ListSiteCapabilitiesTool::class, RunSiteCapabilityTool::class, ConfirmSiteCapabilityTool::class)
        ->and($localServer)->toContain('extends CapellSiteServer', 'AuthenticateLocalAgentBridgeClientAction::run()')
        ->and($routes)->toContain('Mcp::local($localHandle, CapellLocalSiteServer::class)', 'Mcp::web($siteRoute, CapellSiteServer::class)', 'AuthenticateCapellAgentBridgeToken::class')
        ->and($runTool)->toContain("'capability' => ['required'", "'payload' => ['required'")
        ->and($confirmTool)->toContain("'confirmationToken' => ['required'", "'payload' => ['required'")
        ->and(array_column($snapshot, 'key'))->not->toContain(
            'capell.pages.publish_now',
            'capell.packages.install',
            'capell.cache.clear',
        );
});
