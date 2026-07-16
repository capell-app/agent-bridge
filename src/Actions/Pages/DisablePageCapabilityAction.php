<?php

declare(strict_types=1);

namespace Capell\AgentBridge\Actions\Pages;

use Capell\AgentBridge\Contracts\CapellAgentBridgeCapabilityAction;
use Capell\AgentBridge\Data\Capabilities\PageReferenceCapabilityInputData;
use Capell\AgentBridge\Data\CapabilityInvocationData;
use Capell\AgentBridge\Data\CapabilityResultData;
use Capell\AgentBridge\Support\AgentBridgePolicyAuthorizer;
use Capell\AgentBridge\Support\AgentBridgeRecordResolver;
use Capell\AgentBridge\Support\AgentBridgeValue;
use Capell\Core\Models\Page;
use Carbon\CarbonInterface;

final class DisablePageCapabilityAction implements CapellAgentBridgeCapabilityAction
{
    public function preview(CapabilityInvocationData $invocation): CapabilityResultData
    {
        $payload = $this->validatedPayload($invocation->payload);
        $page = $this->page($invocation, $payload);

        return new CapabilityResultData(
            ok: true,
            message: (string) __('capell-agent-bridge::admin.result_page_disable_preview'),
            data: [
                'page_id' => AgentBridgeValue::integer($page->getKey(), 'Page key'),
                'name' => AgentBridgeValue::string($page->name, 'Page name'),
                'visible_until' => now()->toIso8601String(),
            ],
        );
    }

    public function execute(CapabilityInvocationData $invocation): CapabilityResultData
    {
        $payload = $this->validatedPayload($invocation->payload);
        $page = $this->page($invocation, $payload);
        $page->forceFill(['visible_until' => now()])->save();
        $visibleUntil = $page->getAttribute('visible_until');

        return new CapabilityResultData(
            ok: true,
            message: (string) __('capell-agent-bridge::admin.result_page_disabled'),
            data: [
                'page_id' => AgentBridgeValue::integer($page->getKey(), 'Page key'),
                'visible_until' => $visibleUntil instanceof CarbonInterface ? $visibleUntil->toIso8601String() : $visibleUntil,
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function validatedPayload(array $payload): PageReferenceCapabilityInputData
    {
        validator($payload, [
            'page' => ['required_without:page_id'],
            'page_id' => ['required_without:page', 'integer'],
            'site' => ['sometimes', 'nullable'],
            'site_id' => ['sometimes', 'nullable', 'integer'],
        ])->validate();

        return PageReferenceCapabilityInputData::fromPayload($payload);
    }

    private function page(CapabilityInvocationData $invocation, PageReferenceCapabilityInputData $payload): Page
    {
        $page = resolve(AgentBridgeRecordResolver::class)->page(
            user: $invocation->user,
            reference: $payload->page_id ?? $payload->page ?? '',
            siteReference: $payload->site_id ?? $payload->site,
        );

        resolve(AgentBridgePolicyAuthorizer::class)->authorize($invocation->user, 'update', $page);

        return $page;
    }
}
