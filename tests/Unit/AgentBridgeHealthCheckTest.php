<?php

declare(strict_types=1);

use Capell\AgentBridge\Health\AgentBridgeHealthCheck;
use Capell\Core\Data\Diagnostics\DoctorCheckResultData;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    $settingsMigration = require dirname(__DIR__, 2) . '/database/settings/2026_05_10_190841_01_add_agent_bridge_settings.php';
    $settingsMigration->up();
});

it('returns a compatible capell api version', function (): void {
    expect(AgentBridgeHealthCheck::compatibleCapellApiVersion())->toBe('^1.0');
});

it('runs diagnostics and returns a collection of check results', function (): void {
    $results = AgentBridgeHealthCheck::runDiagnostics();

    expect($results)->toHaveCount(7)
        ->and($results->every(fn (mixed $check): bool => $check instanceof DoctorCheckResultData))->toBeTrue();
});

it('checks that the laravel mcp package is available', function (): void {
    $results = AgentBridgeHealthCheck::runDiagnostics();

    $mcpCheck = $results->first(fn (DoctorCheckResultData $check): bool => $check->id === 'legacy.laravel.mcp.package');

    throw_unless($mcpCheck instanceof DoctorCheckResultData, RuntimeException::class, 'Laravel MCP package health check was not returned.');

    expect($mcpCheck)->not->toBeNull()
        ->and($mcpCheck->passed)->toBeTrue()
        ->and($mcpCheck->message)->toContain('installed and available');
});

it('checks that agent bridge database tables exist', function (): void {
    $results = AgentBridgeHealthCheck::runDiagnostics();

    $tableCheck = $results->first(fn (DoctorCheckResultData $check): bool => $check->id === 'legacy.agent.bridge.database.tables');

    throw_unless($tableCheck instanceof DoctorCheckResultData, RuntimeException::class, 'Agent Bridge database tables health check was not returned.');

    expect($tableCheck)->not->toBeNull()
        ->and($tableCheck->passed)->toBeTrue()
        ->and($tableCheck->message)->toContain('All required Agent Bridge tables are present');
});

it('uses package-safe remediation when database tables are missing', function (): void {
    Schema::dropIfExists('capell_agent_bridge_saved_prompts');

    $results = AgentBridgeHealthCheck::runDiagnostics();

    $tableCheck = $results->first(fn (DoctorCheckResultData $check): bool => $check->id === 'legacy.agent.bridge.database.tables');

    throw_unless($tableCheck instanceof DoctorCheckResultData, RuntimeException::class, 'Agent Bridge database tables health check was not returned.');

    expect($tableCheck->passed)->toBeFalse()
        ->and($tableCheck->remediation)->toContain('Install or migrate the Agent Bridge package')
        ->and($tableCheck->remediation)->not->toContain('php artisan');
});

it('checks that the capability registry is resolvable with capabilities', function (): void {
    $results = AgentBridgeHealthCheck::runDiagnostics();

    $registryCheck = $results->first(fn (DoctorCheckResultData $check): bool => $check->id === 'legacy.agent.bridge.capability.registry');

    throw_unless($registryCheck instanceof DoctorCheckResultData, RuntimeException::class, 'Agent Bridge capability registry health check was not returned.');

    expect($registryCheck)->not->toBeNull()
        ->and($registryCheck->passed)->toBeTrue()
        ->and($registryCheck->message)->toContain('capability(ies) registered');
});

it('checks route configuration status', function (): void {
    $results = AgentBridgeHealthCheck::runDiagnostics();

    $routeCheck = $results->first(fn (DoctorCheckResultData $check): bool => $check->id === 'legacy.agent.bridge.mcp.routes');

    throw_unless($routeCheck instanceof DoctorCheckResultData, RuntimeException::class, 'Agent Bridge MCP routes health check was not returned.');

    expect($routeCheck)->not->toBeNull()
        ->and($routeCheck->passed)->toBeTrue()
        ->and($routeCheck->message)->toContain('site');
});

it('reports server policy coverage for scoped site capabilities', function (): void {
    $results = AgentBridgeHealthCheck::runDiagnostics();

    $policyCheck = $results->first(fn (DoctorCheckResultData $check): bool => $check->id === 'legacy.agent.bridge.server.policy.coverage');

    throw_unless($policyCheck instanceof DoctorCheckResultData, RuntimeException::class, 'Agent Bridge server policy coverage health check was not returned.');

    expect($policyCheck)->not->toBeNull()
        ->and($policyCheck->passed)->toBeTrue()
        ->and($policyCheck->message)->toContain('Site server policy coverage is valid')
        ->and($policyCheck->message)->toContain('declare host policy abilities');
});

it('reports failed routes check when all routes are disabled', function (): void {
    $siteRoute = config('capell-agent-bridge.routes.site');
    $knowledgeRoute = config('capell-agent-bridge.routes.knowledge');
    config()->set('capell-agent-bridge.routes.site');
    config()->set('capell-agent-bridge.routes.knowledge');

    try {
        $results = AgentBridgeHealthCheck::runDiagnostics();
    } finally {
        config()->set('capell-agent-bridge.routes.site', $siteRoute);
        config()->set('capell-agent-bridge.routes.knowledge', $knowledgeRoute);
    }

    $routeCheck = $results->first(fn (DoctorCheckResultData $check): bool => $check->id === 'legacy.agent.bridge.mcp.routes');

    throw_unless($routeCheck instanceof DoctorCheckResultData, RuntimeException::class, 'Agent Bridge MCP routes health check was not returned.');

    expect($routeCheck)->not->toBeNull()
        ->and($routeCheck->passed)->toBeFalse()
        ->and($routeCheck->message)->toContain('No MCP server routes are enabled');
});

it('returns overall passed status based on all checks', function (): void {
    $failedChecks = AgentBridgeHealthCheck::runDiagnostics()
        ->reject(fn (DoctorCheckResultData $check): bool => $check->passed)
        ->pluck('label')
        ->all();

    expect($failedChecks)->toBe([])
        ->and(AgentBridgeHealthCheck::passed())->toBeTrue();
});

it('passes the connectivity check when the site route, audit secret and rate limits are ready', function (): void {
    config()->set('capell-agent-bridge.audit_hash_secret', 'audit-secret');

    $check = AgentBridgeHealthCheck::checkConnectivity();

    expect($check->passed)->toBeTrue()
        ->and($check->id)->toBe('agent.bridge.connectivity')
        ->and($check->message)->toContain('/agent-bridge/capell')
        ->and($check->message)->toContain('0 active agent key(s)')
        ->and(AgentBridgeHealthCheck::runDiagnostics()->pluck('id'))->toContain('agent.bridge.connectivity');
});

it('fails the connectivity check for each missing connection requirement', function (string $key, mixed $value, string $problem): void {
    config()->set('capell-agent-bridge.audit_hash_secret', 'audit-secret');
    config()->set($key, $value);

    if ($key === 'app.key') {
        config()->set('capell-agent-bridge.audit_hash_secret', null);
    }

    $check = AgentBridgeHealthCheck::checkConnectivity();

    expect($check->passed)->toBeFalse()
        ->and($check->message)->toContain($problem)
        ->and($check->remediation)->toContain('capell:doctor');
})->with([
    'site route disabled' => ['capell-agent-bridge.routes.site', null, 'site MCP route is disabled'],
    'site route not registered' => ['capell-agent-bridge.routes.site', 'agent-bridge/elsewhere', 'site MCP route is not registered'],
    'no audit secret' => ['app.key', '', 'no audit hash secret'],
    'rate limits off' => ['capell-agent-bridge.rate_limit_enabled', false, 'rate limits are off'],
]);

it('fails the connectivity check when agent keys cannot be read', function (): void {
    config()->set('capell-agent-bridge.audit_hash_secret', 'audit-secret');
    Schema::drop('capell_agent_bridge_tokens');

    $check = AgentBridgeHealthCheck::checkConnectivity();

    expect($check->passed)->toBeFalse()
        ->and($check->message)->toContain('agent keys cannot be read');
});
