<?php

declare(strict_types=1);

namespace Capell\AgentBridge\Actions\Sites;

use Capell\AgentBridge\Contracts\CapellAgentBridgeCapabilityAction;
use Capell\AgentBridge\Data\Capabilities\SiteReferenceCapabilityInputData;
use Capell\AgentBridge\Data\CapabilityInvocationData;
use Capell\AgentBridge\Data\CapabilityResultData;
use Capell\AgentBridge\Support\AgentBridgeRecordResolver;
use Capell\AgentBridge\Support\AgentBridgeSitePresenter;
use Capell\AgentBridge\Support\AgentBridgeValue;

final class OpenSiteEditorCapabilityAction implements CapellAgentBridgeCapabilityAction
{
    public function preview(CapabilityInvocationData $invocation): CapabilityResultData
    {
        return $this->execute($invocation);
    }

    public function execute(CapabilityInvocationData $invocation): CapabilityResultData
    {
        validator($invocation->payload, [
            'site' => ['sometimes', 'nullable'],
            'site_id' => ['sometimes', 'nullable', 'integer'],
        ])->validate();
        $payload = SiteReferenceCapabilityInputData::fromPayload($invocation->payload);
        $site = resolve(AgentBridgeRecordResolver::class)->site(
            $invocation->user,
            $payload->site_id ?? $payload->site,
        );
        $url = resolve(AgentBridgeSitePresenter::class)->editUrl($site);

        return new CapabilityResultData(
            ok: true,
            message: (string) __('capell-agent-bridge::admin.result_site_editor_ready'),
            data: [
                'action' => 'open_url',
                'url' => $url,
                'site_id' => AgentBridgeValue::integer($site->getKey(), 'Site key'),
                'name' => AgentBridgeValue::string($site->name, 'Site name'),
            ],
        );
    }
}
