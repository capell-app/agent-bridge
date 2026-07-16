<?php

declare(strict_types=1);

namespace Capell\AgentBridge\Actions\Packages;

use Capell\Admin\Filament\Pages\ExtensionsPage;
use Capell\AgentBridge\Contracts\CapellAgentBridgeCapabilityAction;
use Capell\AgentBridge\Data\CapabilityInvocationData;
use Capell\AgentBridge\Data\CapabilityResultData;
use Capell\AgentBridge\Support\AgentBridgePackagePresenter;
use Capell\AgentBridge\Support\AgentBridgePackageResolver;
use Capell\AgentBridge\Support\AgentBridgePolicyAuthorizer;
use Capell\Core\Data\PackageData;

final class SearchPackagesCapabilityAction implements CapellAgentBridgeCapabilityAction
{
    public function preview(CapabilityInvocationData $invocation): CapabilityResultData
    {
        return $this->execute($invocation);
    }

    public function execute(CapabilityInvocationData $invocation): CapabilityResultData
    {
        resolve(AgentBridgePolicyAuthorizer::class)->authorize(
            $invocation->user,
            ExtensionsPage::MANAGE_PERMISSION,
        );
        $payload = validator($invocation->payload, [
            'query' => ['sometimes', 'nullable', 'string', 'max:255'],
            'status' => ['sometimes', 'in:all,installed,available'],
            'include_core' => ['sometimes', 'boolean'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ])->validate();
        $packages = resolve(AgentBridgePackageResolver::class)->search(
            query: $payload['query'] ?? null,
            status: (string) ($payload['status'] ?? 'all'),
            includeCore: (bool) ($payload['include_core'] ?? false),
            limit: (int) ($payload['limit'] ?? 20),
        );
        $presenter = resolve(AgentBridgePackagePresenter::class);

        return new CapabilityResultData(
            ok: true,
            message: (string) __('capell-agent-bridge::admin.result_packages_found', ['count' => $packages->count()]),
            data: [
                'count' => $packages->count(),
                'packages' => $packages
                    ->map(fn (PackageData $package): array => $presenter->present($package))
                    ->all(),
            ],
        );
    }
}
