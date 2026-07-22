<?php

declare(strict_types=1);

namespace Capell\AgentBridge\Capabilities;

use Capell\Admin\Filament\Pages\ExtensionsPage;
use Capell\AgentBridge\Actions\Cache\ClearCapellCacheCapabilityAction;
use Capell\AgentBridge\Actions\Media\UploadMediaCapabilityAction;
use Capell\AgentBridge\Actions\Packages\InspectPackageCapabilityAction;
use Capell\AgentBridge\Actions\Packages\InstallPackageCapabilityAction;
use Capell\AgentBridge\Actions\Packages\SearchPackagesCapabilityAction;
use Capell\AgentBridge\Actions\Pages\ClonePageCapabilityAction;
use Capell\AgentBridge\Actions\Pages\CreateDraftPageCapabilityAction;
use Capell\AgentBridge\Actions\Pages\DisablePageCapabilityAction;
use Capell\AgentBridge\Actions\Pages\InspectPageCapabilityAction;
use Capell\AgentBridge\Actions\Pages\InspectPagePublishingReadinessCapabilityAction;
use Capell\AgentBridge\Actions\Pages\ListPageAuthoringOptionsCapabilityAction;
use Capell\AgentBridge\Actions\Pages\OpenPageEditorCapabilityAction;
use Capell\AgentBridge\Actions\Pages\SearchPagesCapabilityAction;
use Capell\AgentBridge\Actions\Pages\TransitionPagePublicationCapabilityAction;
use Capell\AgentBridge\Actions\Pages\UpdateDraftPageCapabilityAction;
use Capell\AgentBridge\Actions\Sites\InspectSiteCapabilityAction;
use Capell\AgentBridge\Actions\Sites\OpenSiteEditorCapabilityAction;
use Capell\AgentBridge\Actions\Sites\SearchSitesCapabilityAction;
use Capell\AgentBridge\Contracts\CapellAgentBridgeCapabilityProvider;
use Capell\AgentBridge\Data\Capabilities\ClearCacheCapabilityInputData;
use Capell\AgentBridge\Data\Capabilities\ClonePageCapabilityInputData;
use Capell\AgentBridge\Data\Capabilities\CreateDraftPageCapabilityInputData;
use Capell\AgentBridge\Data\Capabilities\InstallPackageCapabilityInputData;
use Capell\AgentBridge\Data\Capabilities\PackageReferenceCapabilityInputData;
use Capell\AgentBridge\Data\Capabilities\PageAuthoringOptionsCapabilityInputData;
use Capell\AgentBridge\Data\Capabilities\PageReferenceCapabilityInputData;
use Capell\AgentBridge\Data\Capabilities\SearchPackagesCapabilityInputData;
use Capell\AgentBridge\Data\Capabilities\SearchPagesCapabilityInputData;
use Capell\AgentBridge\Data\Capabilities\SearchSitesCapabilityInputData;
use Capell\AgentBridge\Data\Capabilities\SiteReferenceCapabilityInputData;
use Capell\AgentBridge\Data\Capabilities\TransitionPagePublicationCapabilityInputData;
use Capell\AgentBridge\Data\Capabilities\UpdateDraftPageCapabilityInputData;
use Capell\AgentBridge\Data\Capabilities\UploadMediaCapabilityInputData;
use Capell\AgentBridge\Data\CapabilityData;
use Capell\AgentBridge\Data\CapabilityResultData;
use Capell\AgentBridge\Enums\CapabilityRiskEnum;
use Capell\AgentBridge\Enums\CapabilityServerEnum;
use Capell\AgentBridge\Support\AgentBridgePolicyAuthorizer;
use Capell\AgentBridge\Support\CapabilitySchemas;
use Capell\AgentBridge\Support\CapellAgentBridgeCapabilityRegistry;

final class BuiltInAgentBridgeCapabilityProvider implements CapellAgentBridgeCapabilityProvider
{
    public function registerCapabilities(CapellAgentBridgeCapabilityRegistry $registry): void
    {
        $this->registerCacheCapability($registry);
        $this->registerReadPageCapabilities($registry);
        $this->registerWritePageCapabilities($registry);
        $this->registerPagePublicationCapabilities($registry);
        $this->registerPackageCapabilities($registry);
        $this->registerSiteCapabilities($registry);
        $this->registerMediaCapabilities($registry);
    }

    private function registerCacheCapability(CapellAgentBridgeCapabilityRegistry $registry): void
    {
        $registry->register(new CapabilityData(
            key: 'capell.cache.clear',
            name: $this->translation('capability_cache_clear_name'),
            description: $this->translation('capability_cache_clear_description'),
            scope: 'capell.cache.run',
            server: CapabilityServerEnum::Site,
            risk: CapabilityRiskEnum::Medium,
            actionClass: ClearCapellCacheCapabilityAction::class,
            requiredPackage: 'capell-app/core',
            policyAbility: AgentBridgePolicyAuthorizer::CLEAR_CACHE_ABILITY,
            inputDataClass: ClearCacheCapabilityInputData::class,
            outputDataClass: CapabilityResultData::class,
            inputSchema: CapabilitySchemas::emptyObject(),
            outputSchema: CapabilitySchemas::capabilityResultOutput(),
            auditEvent: 'capell_agent-bridge.cache.clear',
            category: 'system',
            intents: $this->translationList('capability_intents.cache_clear'),
            examples: $this->translationList('capability_examples.cache_clear'),
            discoveryPriority: 20,
        ));
    }

    private function registerReadPageCapabilities(CapellAgentBridgeCapabilityRegistry $registry): void
    {
        $registry->register(new CapabilityData(
            key: 'capell.pages.search',
            name: $this->translation('capability_pages_search_name'),
            description: $this->translation('capability_pages_search_description'),
            scope: 'capell.pages.read',
            server: CapabilityServerEnum::Site,
            risk: CapabilityRiskEnum::Read,
            actionClass: SearchPagesCapabilityAction::class,
            requiredPackage: 'capell-app/core',
            inputDataClass: SearchPagesCapabilityInputData::class,
            outputDataClass: CapabilityResultData::class,
            inputSchema: CapabilitySchemas::searchPagesInput(),
            outputSchema: CapabilitySchemas::capabilityResultOutput(),
            requiresConfirmation: false,
            auditEvent: 'capell_agent-bridge.pages.search',
            category: 'pages',
            intents: $this->translationList('capability_intents.pages_search'),
            examples: $this->translationList('capability_examples.pages_search'),
            discoveryPriority: 100,
        ));

        $registry->register(new CapabilityData(
            key: 'capell.pages.inspect',
            name: $this->translation('capability_pages_inspect_name'),
            description: $this->translation('capability_pages_inspect_description'),
            scope: 'capell.pages.read',
            server: CapabilityServerEnum::Site,
            risk: CapabilityRiskEnum::Read,
            actionClass: InspectPageCapabilityAction::class,
            requiredPackage: 'capell-app/core',
            inputDataClass: PageReferenceCapabilityInputData::class,
            outputDataClass: CapabilityResultData::class,
            inputSchema: CapabilitySchemas::pageReferenceInput(includeContent: true),
            outputSchema: CapabilitySchemas::capabilityResultOutput(),
            requiresConfirmation: false,
            auditEvent: 'capell_agent-bridge.pages.inspect',
            category: 'pages',
            intents: $this->translationList('capability_intents.pages_inspect'),
            examples: $this->translationList('capability_examples.pages_inspect'),
            discoveryPriority: 90,
        ));

        $registry->register(new CapabilityData(
            key: 'capell.pages.open_editor',
            name: $this->translation('capability_pages_open_editor_name'),
            description: $this->translation('capability_pages_open_editor_description'),
            scope: 'capell.pages.read',
            server: CapabilityServerEnum::Site,
            risk: CapabilityRiskEnum::Read,
            actionClass: OpenPageEditorCapabilityAction::class,
            requiredPackage: 'capell-app/core',
            inputDataClass: PageReferenceCapabilityInputData::class,
            outputDataClass: CapabilityResultData::class,
            inputSchema: CapabilitySchemas::pageReferenceInput(),
            outputSchema: CapabilitySchemas::capabilityResultOutput(),
            requiresConfirmation: false,
            auditEvent: 'capell_agent-bridge.pages.open_editor',
            category: 'pages',
            intents: $this->translationList('capability_intents.pages_open_editor'),
            examples: $this->translationList('capability_examples.pages_open_editor'),
            discoveryPriority: 95,
        ));

        $registry->register(new CapabilityData(
            key: 'capell.pages.authoring_options',
            name: $this->translation('capability_pages_authoring_options_name'),
            description: $this->translation('capability_pages_authoring_options_description'),
            scope: 'capell.pages.read',
            server: CapabilityServerEnum::Site,
            risk: CapabilityRiskEnum::Read,
            actionClass: ListPageAuthoringOptionsCapabilityAction::class,
            requiredPackage: 'capell-app/core',
            inputDataClass: PageAuthoringOptionsCapabilityInputData::class,
            outputDataClass: CapabilityResultData::class,
            inputSchema: CapabilitySchemas::pageAuthoringOptionsInput(),
            outputSchema: CapabilitySchemas::capabilityResultOutput(),
            requiresConfirmation: false,
            auditEvent: 'capell_agent-bridge.pages.authoring_options',
            category: 'pages',
            intents: $this->translationList('capability_intents.pages_authoring_options'),
            examples: $this->translationList('capability_examples.pages_authoring_options'),
            discoveryPriority: 75,
        ));

        $registry->register(new CapabilityData(
            key: 'capell.pages.inspect_readiness',
            name: $this->translation('capability_pages_inspect_readiness_name'),
            description: $this->translation('capability_pages_inspect_readiness_description'),
            scope: 'capell.pages.read',
            server: CapabilityServerEnum::Site,
            risk: CapabilityRiskEnum::Read,
            actionClass: InspectPagePublishingReadinessCapabilityAction::class,
            requiredPackage: 'capell-app/core',
            inputDataClass: PageReferenceCapabilityInputData::class,
            outputDataClass: CapabilityResultData::class,
            inputSchema: CapabilitySchemas::pageReferenceInput(),
            outputSchema: CapabilitySchemas::capabilityResultOutput(),
            requiresConfirmation: false,
            auditEvent: 'capell_agent-bridge.pages.inspect_readiness',
            category: 'pages',
            intents: $this->translationList('capability_intents.pages_inspect_readiness'),
            examples: $this->translationList('capability_examples.pages_inspect_readiness'),
            discoveryPriority: 65,
        ));
    }

    private function registerWritePageCapabilities(CapellAgentBridgeCapabilityRegistry $registry): void
    {
        $registry->register(new CapabilityData(
            key: 'capell.pages.create_draft',
            name: $this->translation('capability_pages_create_draft_name'),
            description: $this->translation('capability_pages_create_draft_description'),
            scope: 'capell.pages.write',
            server: CapabilityServerEnum::Site,
            risk: CapabilityRiskEnum::High,
            actionClass: CreateDraftPageCapabilityAction::class,
            requiredPackage: 'capell-app/core',
            inputDataClass: CreateDraftPageCapabilityInputData::class,
            outputDataClass: CapabilityResultData::class,
            inputSchema: CapabilitySchemas::createDraftPageInput(),
            outputSchema: CapabilitySchemas::capabilityResultOutput(),
            auditEvent: 'capell_agent-bridge.pages.create_draft',
            category: 'pages',
            intents: $this->translationList('capability_intents.pages_create_draft'),
            examples: $this->translationList('capability_examples.pages_create_draft'),
            discoveryPriority: 100,
        ));

        $registry->register(new CapabilityData(
            key: 'capell.pages.clone',
            name: $this->translation('capability_pages_clone_name'),
            description: $this->translation('capability_pages_clone_description'),
            scope: 'capell.pages.write',
            server: CapabilityServerEnum::Site,
            risk: CapabilityRiskEnum::High,
            actionClass: ClonePageCapabilityAction::class,
            requiredPackage: 'capell-app/core',
            inputDataClass: ClonePageCapabilityInputData::class,
            outputDataClass: CapabilityResultData::class,
            inputSchema: CapabilitySchemas::clonePageInput(),
            outputSchema: CapabilitySchemas::capabilityResultOutput(),
            auditEvent: 'capell_agent-bridge.pages.clone',
            category: 'pages',
            intents: $this->translationList('capability_intents.pages_clone'),
            examples: $this->translationList('capability_examples.pages_clone'),
            discoveryPriority: 90,
        ));

        $registry->register(new CapabilityData(
            key: 'capell.pages.update_draft',
            name: $this->translation('capability_pages_update_draft_name'),
            description: $this->translation('capability_pages_update_draft_description'),
            scope: 'capell.pages.write',
            server: CapabilityServerEnum::Site,
            risk: CapabilityRiskEnum::High,
            actionClass: UpdateDraftPageCapabilityAction::class,
            requiredPackage: 'capell-app/core',
            inputDataClass: UpdateDraftPageCapabilityInputData::class,
            outputDataClass: CapabilityResultData::class,
            inputSchema: CapabilitySchemas::updateDraftPageInput(),
            outputSchema: CapabilitySchemas::capabilityResultOutput(),
            auditEvent: 'capell_agent-bridge.pages.update_draft',
            category: 'pages',
            intents: $this->translationList('capability_intents.pages_update'),
            examples: $this->translationList('capability_examples.pages_update'),
            discoveryPriority: 85,
        ));

        $registry->register(new CapabilityData(
            key: 'capell.pages.disable',
            name: $this->translation('capability_pages_disable_name'),
            description: $this->translation('capability_pages_disable_description'),
            scope: 'capell.pages.write',
            server: CapabilityServerEnum::Site,
            risk: CapabilityRiskEnum::High,
            actionClass: DisablePageCapabilityAction::class,
            requiredPackage: 'capell-app/core',
            inputDataClass: PageReferenceCapabilityInputData::class,
            outputDataClass: CapabilityResultData::class,
            inputSchema: CapabilitySchemas::pageReferenceInput(),
            outputSchema: CapabilitySchemas::capabilityResultOutput(),
            auditEvent: 'capell_agent-bridge.pages.disable',
            category: 'pages',
            intents: $this->translationList('capability_intents.pages_disable'),
            examples: $this->translationList('capability_examples.pages_disable'),
            discoveryPriority: 40,
        ));
    }

    private function registerPagePublicationCapabilities(CapellAgentBridgeCapabilityRegistry $registry): void
    {
        /** @var list<array{key:string, suffix:string, time_field:string|null, priority:int}> $definitions */
        $definitions = [
            ['key' => 'capell.pages.publish_now', 'suffix' => 'pages_publish_now', 'time_field' => null, 'priority' => 80],
            ['key' => 'capell.pages.schedule_publish', 'suffix' => 'pages_schedule_publish', 'time_field' => 'publish_at', 'priority' => 75],
            ['key' => 'capell.pages.schedule_unpublish', 'suffix' => 'pages_schedule_unpublish', 'time_field' => 'unpublish_at', 'priority' => 60],
            ['key' => 'capell.pages.unpublish', 'suffix' => 'pages_unpublish', 'time_field' => null, 'priority' => 70],
            ['key' => 'capell.pages.revert_to_draft', 'suffix' => 'pages_revert_to_draft', 'time_field' => null, 'priority' => 65],
        ];

        foreach ($definitions as $definition) {
            $registry->register(new CapabilityData(
                key: $definition['key'],
                name: $this->translation('capability_' . $definition['suffix'] . '_name'),
                description: $this->translation('capability_' . $definition['suffix'] . '_description'),
                scope: 'capell.pages.publish',
                server: CapabilityServerEnum::Site,
                risk: CapabilityRiskEnum::High,
                actionClass: TransitionPagePublicationCapabilityAction::class,
                requiredPackage: 'capell-app/core',
                inputDataClass: TransitionPagePublicationCapabilityInputData::class,
                outputDataClass: CapabilityResultData::class,
                inputSchema: CapabilitySchemas::pagePublicationInput($definition['time_field']),
                outputSchema: CapabilitySchemas::capabilityResultOutput(),
                auditEvent: 'capell_agent-bridge.' . str_replace('capell.', '', $definition['key']),
                category: 'publishing',
                intents: $this->translationList('capability_intents.' . $definition['suffix']),
                examples: $this->translationList('capability_examples.' . $definition['suffix']),
                discoveryPriority: $definition['priority'],
            ));
        }
    }

    private function registerPackageCapabilities(CapellAgentBridgeCapabilityRegistry $registry): void
    {
        $registry->register(new CapabilityData(
            key: 'capell.packages.search',
            name: $this->translation('capability_packages_search_name'),
            description: $this->translation('capability_packages_search_description'),
            scope: 'capell.packages.read',
            server: CapabilityServerEnum::Site,
            risk: CapabilityRiskEnum::Read,
            actionClass: SearchPackagesCapabilityAction::class,
            requiredPackage: 'capell-app/core',
            policyAbility: ExtensionsPage::MANAGE_PERMISSION,
            inputDataClass: SearchPackagesCapabilityInputData::class,
            outputDataClass: CapabilityResultData::class,
            inputSchema: CapabilitySchemas::searchPackagesInput(),
            outputSchema: CapabilitySchemas::capabilityResultOutput(),
            requiresConfirmation: false,
            auditEvent: 'capell_agent-bridge.packages.search',
            category: 'packages',
            intents: $this->translationList('capability_intents.packages_search'),
            examples: $this->translationList('capability_examples.packages_search'),
            discoveryPriority: 70,
        ));

        $registry->register(new CapabilityData(
            key: 'capell.packages.inspect',
            name: $this->translation('capability_packages_inspect_name'),
            description: $this->translation('capability_packages_inspect_description'),
            scope: 'capell.packages.read',
            server: CapabilityServerEnum::Site,
            risk: CapabilityRiskEnum::Read,
            actionClass: InspectPackageCapabilityAction::class,
            requiredPackage: 'capell-app/core',
            policyAbility: ExtensionsPage::MANAGE_PERMISSION,
            inputDataClass: PackageReferenceCapabilityInputData::class,
            outputDataClass: CapabilityResultData::class,
            inputSchema: CapabilitySchemas::packageReferenceInput(),
            outputSchema: CapabilitySchemas::capabilityResultOutput(),
            requiresConfirmation: false,
            auditEvent: 'capell_agent-bridge.packages.inspect',
            category: 'packages',
            intents: $this->translationList('capability_intents.packages_inspect'),
            examples: $this->translationList('capability_examples.packages_inspect'),
            discoveryPriority: 65,
        ));

        $registry->register(new CapabilityData(
            key: 'capell.packages.install',
            name: $this->translation('capability_packages_install_name'),
            description: $this->translation('capability_packages_install_description'),
            scope: 'capell.packages.install',
            server: CapabilityServerEnum::Site,
            risk: CapabilityRiskEnum::High,
            actionClass: InstallPackageCapabilityAction::class,
            requiredPackage: 'capell-app/core',
            policyAbility: ExtensionsPage::MANAGE_PERMISSION,
            inputDataClass: InstallPackageCapabilityInputData::class,
            outputDataClass: CapabilityResultData::class,
            inputSchema: CapabilitySchemas::installPackageInput(),
            outputSchema: CapabilitySchemas::capabilityResultOutput(),
            auditEvent: 'capell_agent-bridge.packages.install',
            category: 'packages',
            intents: $this->translationList('capability_intents.packages_install'),
            examples: $this->translationList('capability_examples.packages_install'),
            discoveryPriority: 80,
        ));
    }

    private function registerSiteCapabilities(CapellAgentBridgeCapabilityRegistry $registry): void
    {
        $registry->register(new CapabilityData(
            key: 'capell.sites.search',
            name: $this->translation('capability_sites_search_name'),
            description: $this->translation('capability_sites_search_description'),
            scope: 'capell.sites.read',
            server: CapabilityServerEnum::Site,
            risk: CapabilityRiskEnum::Read,
            actionClass: SearchSitesCapabilityAction::class,
            requiredPackage: 'capell-app/core',
            inputDataClass: SearchSitesCapabilityInputData::class,
            outputDataClass: CapabilityResultData::class,
            inputSchema: CapabilitySchemas::searchSitesInput(),
            outputSchema: CapabilitySchemas::capabilityResultOutput(),
            requiresConfirmation: false,
            auditEvent: 'capell_agent-bridge.sites.search',
            category: 'sites',
            intents: $this->translationList('capability_intents.sites_search'),
            examples: $this->translationList('capability_examples.sites_search'),
            discoveryPriority: 75,
        ));

        $registry->register(new CapabilityData(
            key: 'capell.sites.inspect',
            name: $this->translation('capability_sites_inspect_name'),
            description: $this->translation('capability_sites_inspect_description'),
            scope: 'capell.sites.read',
            server: CapabilityServerEnum::Site,
            risk: CapabilityRiskEnum::Read,
            actionClass: InspectSiteCapabilityAction::class,
            requiredPackage: 'capell-app/core',
            inputDataClass: SiteReferenceCapabilityInputData::class,
            outputDataClass: CapabilityResultData::class,
            inputSchema: CapabilitySchemas::siteReferenceInput(),
            outputSchema: CapabilitySchemas::capabilityResultOutput(),
            requiresConfirmation: false,
            auditEvent: 'capell_agent-bridge.sites.inspect',
            category: 'sites',
            intents: $this->translationList('capability_intents.sites_inspect'),
            examples: $this->translationList('capability_examples.sites_inspect'),
            discoveryPriority: 70,
        ));

        $registry->register(new CapabilityData(
            key: 'capell.sites.open_editor',
            name: $this->translation('capability_sites_open_editor_name'),
            description: $this->translation('capability_sites_open_editor_description'),
            scope: 'capell.sites.read',
            server: CapabilityServerEnum::Site,
            risk: CapabilityRiskEnum::Read,
            actionClass: OpenSiteEditorCapabilityAction::class,
            requiredPackage: 'capell-app/core',
            inputDataClass: SiteReferenceCapabilityInputData::class,
            outputDataClass: CapabilityResultData::class,
            inputSchema: CapabilitySchemas::siteReferenceInput(),
            outputSchema: CapabilitySchemas::capabilityResultOutput(),
            requiresConfirmation: false,
            auditEvent: 'capell_agent-bridge.sites.open_editor',
            category: 'sites',
            intents: $this->translationList('capability_intents.sites_open_editor'),
            examples: $this->translationList('capability_examples.sites_open_editor'),
            discoveryPriority: 85,
        ));
    }

    private function registerMediaCapabilities(CapellAgentBridgeCapabilityRegistry $registry): void
    {
        $registry->register(new CapabilityData(
            key: 'capell.media.upload',
            name: $this->translation('capability_media_upload_name'),
            description: $this->translation('capability_media_upload_description'),
            scope: 'capell.media.write',
            server: CapabilityServerEnum::Site,
            risk: CapabilityRiskEnum::High,
            actionClass: UploadMediaCapabilityAction::class,
            requiredPackage: 'capell-app/core',
            inputDataClass: UploadMediaCapabilityInputData::class,
            outputDataClass: CapabilityResultData::class,
            inputSchema: CapabilitySchemas::uploadMediaInput(),
            outputSchema: CapabilitySchemas::capabilityResultOutput(),
            auditEvent: 'capell_agent-bridge.media.upload',
            category: 'media',
            discoveryPriority: 80,
        ));
    }

    private function translation(string $key): string
    {
        $value = __('capell-agent-bridge::admin.' . $key);

        return is_string($value) ? $value : $key;
    }

    /** @return list<string> */
    private function translationList(string $key): array
    {
        $value = __('capell-agent-bridge::admin.' . $key);

        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, static fn (mixed $item): bool => is_string($item) && $item !== ''));
    }
}
