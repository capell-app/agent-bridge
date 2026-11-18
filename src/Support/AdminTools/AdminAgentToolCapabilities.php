<?php

declare(strict_types=1);

namespace Capell\AgentBridge\Support\AdminTools;

use Capell\Admin\Support\Agent\AgentAdminToolRegistry;
use Capell\AgentBridge\Actions\AdminTools\InvokeAdminAgentToolCapabilityAction;
use Capell\AgentBridge\Data\CapabilityData;
use Capell\AgentBridge\Data\CapabilityResultData;
use Capell\AgentBridge\Enums\CapabilityRiskEnum;
use Capell\AgentBridge\Enums\CapabilityServerEnum;
use Capell\AgentBridge\Support\CapabilitySchemas;
use Capell\AgentBridge\Support\CapellAgentBridgeCapabilityRegistry;
use Capell\Core\Enums\Agent\AgentToolEffect;

/**
 * Exposes Core Admin's permissioned agent tools as Agent Bridge capabilities.
 *
 * The free Agent Bridge registers the read tools and the scratch-draft save, which
 * never changes what visitors see. Agent Bridge Pro registers the tools that change
 * live content, publish, or change site configuration. Every capability runs the
 * Admin tool's own availability, permission and preview checks as the token owner.
 */
final class AdminAgentToolCapabilities
{
    /**
     * Capability key => [Admin tool name, scope, risk].
     *
     * @var array<string, array{0: string, 1: string, 2: CapabilityRiskEnum}>
     */
    private const array FREE = [
        'capell.admin.page_properties.read' => ['admin.page.properties.read', 'capell.pages.read', CapabilityRiskEnum::Read],
        'capell.admin.term_properties.read' => ['admin.term.properties.read', 'capell.pages.read', CapabilityRiskEnum::Read],
        'capell.admin.page_agent_readiness.read' => ['admin.page.agent_readiness.read', 'capell.pages.read', CapabilityRiskEnum::Read],
        'capell.admin.site_agent_readiness.read' => ['admin.site.agent_readiness.read', 'capell.sites.read', CapabilityRiskEnum::Read],
        'capell.admin.blueprints.read' => ['admin.blueprint.read', 'capell.pages.read', CapabilityRiskEnum::Read],
        'capell.admin.page_draft.save' => ['admin.page.draft.save', 'capell.pages.write', CapabilityRiskEnum::Low],
    ];

    /** @var array<string, array{0: string, 1: string, 2: CapabilityRiskEnum}> */
    private const array PRO = [
        'capell.admin.page_properties.write' => ['admin.page.properties.write', 'capell.pages.write', CapabilityRiskEnum::Medium],
        'capell.admin.page_terms.write' => ['admin.page.terms.write', 'capell.pages.write', CapabilityRiskEnum::Medium],
        'capell.admin.page.publish' => ['admin.page.publish', 'capell.pages.publish', CapabilityRiskEnum::High],
        'capell.admin.page.schedule_publish' => ['admin.page.publish.schedule', 'capell.pages.publish', CapabilityRiskEnum::High],
        'capell.admin.settings.write' => ['admin.settings.write', 'capell.sites.configure', CapabilityRiskEnum::High],
        'capell.admin.blueprints.write' => ['admin.blueprint.write', 'capell.sites.configure', CapabilityRiskEnum::High],
        'capell.admin.structure.write' => ['admin.structure.write', 'capell.sites.configure', CapabilityRiskEnum::High],
    ];

    /**
     * Tools that act on the whole installation rather than one site, so a token
     * limited to particular sites can never run them.
     *
     * @var list<string>
     */
    private const array INSTALLATION_WIDE_TOOLS = [
        'admin.settings.write',
        'admin.blueprint.write',
        'admin.structure.write',
    ];

    /** @return list<string> */
    public static function freeKeys(): array
    {
        return array_keys(self::FREE);
    }

    /** @return list<string> */
    public static function proKeys(): array
    {
        return array_keys(self::PRO);
    }

    public static function registerFree(CapellAgentBridgeCapabilityRegistry $registry): void
    {
        self::registerEach($registry, self::FREE);
    }

    public static function registerPro(CapellAgentBridgeCapabilityRegistry $registry): void
    {
        self::registerEach($registry, self::PRO);
    }

    public static function toolFor(string $capabilityKey): ?string
    {
        return (self::FREE[$capabilityKey] ?? self::PRO[$capabilityKey] ?? null)[0] ?? null;
    }

    public static function isInstallationWide(string $tool): bool
    {
        return in_array($tool, self::INSTALLATION_WIDE_TOOLS, true);
    }

    /** @param  array<string, array{0: string, 1: string, 2: CapabilityRiskEnum}>  $map */
    private static function registerEach(CapellAgentBridgeCapabilityRegistry $registry, array $map): void
    {
        $tools = resolve(AgentAdminToolRegistry::class);

        foreach ($map as $key => [$tool, $scope, $risk]) {
            // An Admin release without this tool leaves the capability unregistered.
            if (! $tools->has($tool)) {
                continue;
            }

            $definition = $tools->tool($tool)->definition();
            $read = $definition->effect === AgentToolEffect::Read;
            $slug = str_replace(['capell.admin.', '.'], ['', '_'], $key);

            $registry->register(new CapabilityData(
                key: $key,
                name: self::translation('capability_admin_' . $slug . '_name'),
                description: $definition->description,
                scope: $scope,
                server: CapabilityServerEnum::Site,
                risk: $read ? CapabilityRiskEnum::Read : $risk,
                actionClass: InvokeAdminAgentToolCapabilityAction::class,
                requiredPackage: 'capell-app/admin',
                outputDataClass: CapabilityResultData::class,
                inputSchema: self::inputSchema($definition->inputSchema),
                outputSchema: CapabilitySchemas::capabilityResultOutput(),
                requiresConfirmation: ! $read,
                auditEvent: 'capell_agent-bridge.admin.' . $slug,
                category: 'admin',
                discoveryPriority: $read ? 40 : 30,
            ));
        }
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    private static function inputSchema(array $schema): array
    {
        $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
        $properties['site'] = [
            'type' => ['integer', 'string'],
            'description' => self::translation('capability_admin_site_input'),
        ];

        return ['type' => 'object', ...$schema, 'properties' => $properties];
    }

    private static function translation(string $key): string
    {
        $value = __('capell-agent-bridge::admin.' . $key);

        return is_string($value) ? $value : $key;
    }
}
