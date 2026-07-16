<?php

declare(strict_types=1);

namespace Capell\AgentBridge\Actions\Pages;

use Capell\AgentBridge\Contracts\CapellAgentBridgeCapabilityAction;
use Capell\AgentBridge\Data\CapabilityInvocationData;
use Capell\AgentBridge\Data\CapabilityResultData;
use Capell\AgentBridge\Support\AgentBridgePagePresenter;
use Capell\AgentBridge\Support\AgentBridgeRecordResolver;

final class InspectPageCapabilityAction implements CapellAgentBridgeCapabilityAction
{
    public function preview(CapabilityInvocationData $invocation): CapabilityResultData
    {
        return $this->execute($invocation);
    }

    public function execute(CapabilityInvocationData $invocation): CapabilityResultData
    {
        $payload = validator($invocation->payload, [
            'page' => ['required_without:page_id'],
            'page_id' => ['required_without:page', 'integer'],
            'site' => ['sometimes', 'nullable'],
            'site_id' => ['sometimes', 'nullable', 'integer'],
            'include_content' => ['sometimes', 'boolean'],
        ])->validate();

        $page = resolve(AgentBridgeRecordResolver::class)->page(
            user: $invocation->user,
            reference: $payload['page_id'] ?? $payload['page'],
            siteReference: $payload['site_id'] ?? $payload['site'] ?? null,
        );

        return new CapabilityResultData(
            ok: true,
            message: (string) __('capell-agent-bridge::admin.result_page_inspected'),
            data: [
                'page' => resolve(AgentBridgePagePresenter::class)->present(
                    page: $page,
                    includeContent: (bool) ($payload['include_content'] ?? false),
                ),
            ],
        );
    }
}
