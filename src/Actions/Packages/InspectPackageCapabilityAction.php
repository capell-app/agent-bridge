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

final class InspectPackageCapabilityAction implements CapellAgentBridgeCapabilityAction
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
            'package' => ['required', 'string', 'max:255'],
        ])->validate();
        $package = resolve(AgentBridgePackageResolver::class)->package((string) $payload['package']);

        return new CapabilityResultData(
            ok: true,
            message: (string) __('capell-agent-bridge::admin.result_package_inspected'),
            data: [
                'package' => resolve(AgentBridgePackagePresenter::class)->present($package, includeInstallImpact: true),
            ],
        );
    }
}
