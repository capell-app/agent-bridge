<?php

declare(strict_types=1);

namespace Capell\AgentBridge\Actions\Sites;

use Capell\AgentBridge\Contracts\CapellAgentBridgeCapabilityAction;
use Capell\AgentBridge\Data\CapabilityInvocationData;
use Capell\AgentBridge\Data\CapabilityResultData;
use Capell\AgentBridge\Support\AgentBridgeRecordResolver;
use Capell\AgentBridge\Support\AgentBridgeSitePresenter;

final class InspectSiteCapabilityAction implements CapellAgentBridgeCapabilityAction
{
    public function preview(CapabilityInvocationData $invocation): CapabilityResultData
    {
        return $this->execute($invocation);
    }

    public function execute(CapabilityInvocationData $invocation): CapabilityResultData
    {
        $payload = validator($invocation->payload, [
            'site' => ['sometimes', 'nullable'],
            'site_id' => ['sometimes', 'nullable', 'integer'],
        ])->validate();
        $site = resolve(AgentBridgeRecordResolver::class)->site(
            $invocation->user,
            $payload['site_id'] ?? $payload['site'] ?? null,
        );

        return new CapabilityResultData(
            ok: true,
            message: (string) __('capell-agent-bridge::admin.result_site_inspected'),
            data: ['site' => resolve(AgentBridgeSitePresenter::class)->present($site)],
        );
    }
}
