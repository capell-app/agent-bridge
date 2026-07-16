<?php

declare(strict_types=1);

namespace Capell\AgentBridge\Actions\Pages;

use Capell\AgentBridge\Contracts\CapellAgentBridgeCapabilityAction;
use Capell\AgentBridge\Data\Capabilities\PageReferenceCapabilityInputData;
use Capell\AgentBridge\Data\CapabilityInvocationData;
use Capell\AgentBridge\Data\CapabilityResultData;
use Capell\AgentBridge\Support\AgentBridgePagePresenter;
use Capell\AgentBridge\Support\AgentBridgeRecordResolver;
use Capell\AgentBridge\Support\AgentBridgeValue;

final class OpenPageEditorCapabilityAction implements CapellAgentBridgeCapabilityAction
{
    public function preview(CapabilityInvocationData $invocation): CapabilityResultData
    {
        return $this->execute($invocation);
    }

    public function execute(CapabilityInvocationData $invocation): CapabilityResultData
    {
        validator($invocation->payload, [
            'page' => ['required_without:page_id'],
            'page_id' => ['required_without:page', 'integer'],
            'site' => ['sometimes', 'nullable'],
            'site_id' => ['sometimes', 'nullable', 'integer'],
        ])->validate();
        $payload = PageReferenceCapabilityInputData::fromPayload($invocation->payload);
        $page = resolve(AgentBridgeRecordResolver::class)->page(
            user: $invocation->user,
            reference: $payload->page_id ?? $payload->page ?? '',
            siteReference: $payload->site_id ?? $payload->site,
        );
        $url = resolve(AgentBridgePagePresenter::class)->editUrl($page);

        return new CapabilityResultData(
            ok: true,
            message: (string) __('capell-agent-bridge::admin.result_page_editor_ready'),
            data: [
                'action' => 'open_url',
                'url' => $url,
                'page_id' => AgentBridgeValue::integer($page->getKey(), 'Page key'),
                'name' => AgentBridgeValue::string($page->name, 'Page name'),
            ],
        );
    }
}
