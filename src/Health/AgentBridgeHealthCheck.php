<?php

declare(strict_types=1);

namespace Capell\AgentBridge\Health;

use Capell\AgentBridge\Data\CapabilityData;
use Capell\AgentBridge\Enums\CapabilityRiskEnum;
use Capell\AgentBridge\Enums\CapabilityServerEnum;
use Capell\AgentBridge\Settings\AgentBridgeSettings;
use Capell\AgentBridge\Support\CapellAgentBridgeCapabilityRegistry;
use Capell\Core\Contracts\Extensions\ChecksExtensionHealth;
use Capell\Core\Data\Diagnostics\DoctorCheckResultData;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Laravel\Mcp\Facades\Mcp;
use Throwable;

final class AgentBridgeHealthCheck implements ChecksExtensionHealth
{
    public static function compatibleCapellApiVersion(): string
    {
        return '^1.0';
    }

    /**
     * @return Collection<int, DoctorCheckResultData>
     */
    public static function runDiagnostics(): Collection
    {
        return collect([
            self::checkMcpPackage(),
            self::checkTables(),
            self::checkCapabilityRegistry(),
            self::checkServerPolicyCoverage(),
            self::checkSettings(),
            self::checkRoutes(),
        ]);
    }

    public static function passed(): bool
    {
        return self::runDiagnostics()->every(
            fn (DoctorCheckResultData $check): bool => $check->passed,
        );
    }

    private static function checkMcpPackage(): DoctorCheckResultData
    {
        $available = class_exists(Mcp::class);

        return new DoctorCheckResultData(
            label: 'Agent Bridge: Laravel MCP installed',
            passed: $available,
            message: $available
                ? 'The laravel/mcp package is installed and available.'
                : 'The laravel/mcp package is not installed.',
            remediation: $available ? null : 'Run: composer require laravel/mcp',
            id: 'legacy.laravel.mcp.package',
        );
    }

    private static function checkTables(): DoctorCheckResultData
    {
        $requiredTables = [
            'capell_agent_bridge_tokens',
            'capell_agent_bridge_confirmations',
            'capell_agent_bridge_audit_entries',
            'capell_agent_bridge_saved_prompts',
        ];

        try {
            $missingTables = collect($requiredTables)
                ->reject(fn (string $table): bool => Schema::hasTable($table));
        } catch (Throwable $throwable) {
            return new DoctorCheckResultData(
                label: 'Agent Bridge: database tables present',
                passed: false,
                message: 'Unable to check database tables.',
                remediation: $throwable->getMessage(),
                id: 'legacy.agent.bridge.database.tables',
            );
        }

        if ($missingTables->isNotEmpty()) {
            return new DoctorCheckResultData(
                label: 'Agent Bridge: database tables present',
                passed: false,
                message: 'Missing tables: ' . $missingTables->implode(', ') . '.',
                remediation: 'Install or migrate the Agent Bridge package so its token, confirmation, audit, and saved prompt tables exist.',
                id: 'legacy.agent.bridge.database.tables',
            );
        }

        return new DoctorCheckResultData(
            label: 'Agent Bridge: database tables present',
            passed: true,
            message: 'All required Agent Bridge tables are present.',
            id: 'legacy.agent.bridge.database.tables',
        );
    }

    private static function checkCapabilityRegistry(): DoctorCheckResultData
    {
        try {
            $registry = resolve(CapellAgentBridgeCapabilityRegistry::class);
            $capabilityCount = $registry->all()->count();
        } catch (Throwable $throwable) {
            return new DoctorCheckResultData(
                label: 'Agent Bridge: agent capabilities registered',
                passed: false,
                message: 'Unable to resolve the capability registry.',
                remediation: $throwable->getMessage(),
                id: 'legacy.agent.bridge.capability.registry',
            );
        }

        if ($capabilityCount === 0) {
            return new DoctorCheckResultData(
                label: 'Agent Bridge: agent capabilities registered',
                passed: false,
                message: 'The capability registry is resolvable but contains no capabilities.',
                remediation: 'Ensure AgentBridgeServiceProvider is booted and registers built-in capabilities.',
                id: 'legacy.agent.bridge.capability.registry',
            );
        }

        return new DoctorCheckResultData(
            label: 'Agent Bridge: agent capabilities registered',
            passed: true,
            message: sprintf('Capability registry is resolvable with %d capability(ies) registered.', $capabilityCount),
            id: 'legacy.agent.bridge.capability.registry',
        );
    }

    private static function checkServerPolicyCoverage(): DoctorCheckResultData
    {
        try {
            $capabilities = resolve(CapellAgentBridgeCapabilityRegistry::class)->all();
        } catch (Throwable $throwable) {
            return new DoctorCheckResultData(
                label: 'Agent Bridge: server access policies configured',
                passed: false,
                message: 'Unable to inspect registered capability policies.',
                remediation: $throwable->getMessage(),
                id: 'legacy.agent.bridge.server.policy.coverage',
            );
        }

        $siteCapabilities = $capabilities
            ->filter(fn (CapabilityData $capability): bool => $capability->server->isVisibleOn(CapabilityServerEnum::Site));

        $missingScopes = $siteCapabilities
            ->filter(fn (CapabilityData $capability): bool => trim($capability->scope) === '')
            ->pluck('key');

        $mutatingWithoutConfirmation = $siteCapabilities
            ->filter(fn (CapabilityData $capability): bool => $capability->risk !== CapabilityRiskEnum::Read && ! $capability->needsConfirmation())
            ->pluck('key');

        if ($missingScopes->isNotEmpty() || $mutatingWithoutConfirmation->isNotEmpty()) {
            return new DoctorCheckResultData(
                label: 'Agent Bridge: server access policies configured',
                passed: false,
                message: sprintf(
                    'Capability policy coverage is incomplete. Missing scopes: %s. Mutating without confirmation: %s.',
                    $missingScopes->isEmpty() ? 'none' : $missingScopes->implode(', '),
                    $mutatingWithoutConfirmation->isEmpty() ? 'none' : $mutatingWithoutConfirmation->implode(', '),
                ),
                remediation: 'Ensure every site capability has a token scope and every mutating capability requires confirmation or a risk level that forces confirmation.',
                id: 'legacy.agent.bridge.server.policy.coverage',
            );
        }

        $withPolicyAbility = $siteCapabilities
            ->filter(fn (CapabilityData $capability): bool => $capability->policyAbility !== null && $capability->policyAbility !== '')
            ->count();

        return new DoctorCheckResultData(
            label: 'Agent Bridge: server access policies configured',
            passed: true,
            message: sprintf(
                'Site server policy coverage is valid for %d capability(ies): scopes and confirmation gates are present; %d declare host policy abilities.',
                $siteCapabilities->count(),
                $withPolicyAbility,
            ),
            id: 'legacy.agent.bridge.server.policy.coverage',
        );
    }

    private static function checkSettings(): DoctorCheckResultData
    {
        try {
            $settings = resolve(AgentBridgeSettings::class);
            $bridgeEnabled = $settings->enable_user_resource_bridge;
        } catch (Throwable $throwable) {
            return new DoctorCheckResultData(
                label: 'Agent Bridge: settings valid',
                passed: false,
                message: 'Unable to resolve Agent Bridge settings.',
                remediation: $throwable->getMessage(),
                id: 'legacy.agent.bridge.settings',
            );
        }

        return new DoctorCheckResultData(
            label: 'Agent Bridge: settings valid',
            passed: true,
            message: sprintf(
                'Settings loaded. User resource bridge: %s.',
                $bridgeEnabled ? 'enabled' : 'disabled',
            ),
            id: 'legacy.agent.bridge.settings',
        );
    }

    private static function checkRoutes(): DoctorCheckResultData
    {
        $siteRoute = config('capell-agent-bridge.routes.site');
        $knowledgeRoute = config('capell-agent-bridge.routes.knowledge');

        $enabledServers = [];
        $disabledServers = [];

        if (is_string($siteRoute) && $siteRoute !== '') {
            $enabledServers[] = 'site';
        } else {
            $disabledServers[] = 'site';
        }

        if (is_string($knowledgeRoute) && $knowledgeRoute !== '') {
            $enabledServers[] = 'knowledge';
        } else {
            $disabledServers[] = 'knowledge';
        }

        if ($enabledServers === []) {
            return new DoctorCheckResultData(
                label: 'Agent Bridge: agent routes registered',
                passed: false,
                message: 'No MCP server routes are enabled. Both site and knowledge routes are disabled.',
                remediation: 'Enable at least one route in config/capell-agent-bridge.php under routes.',
                id: 'legacy.agent.bridge.mcp.routes',
            );
        }

        $message = 'Enabled MCP servers: ' . implode(', ', $enabledServers) . '.';

        if ($disabledServers !== []) {
            $message .= ' Disabled: ' . implode(', ', $disabledServers) . '.';
        }

        return new DoctorCheckResultData(
            label: 'Agent Bridge: agent routes registered',
            passed: true,
            message: $message,
            id: 'legacy.agent.bridge.mcp.routes',
        );
    }
}
