<?php

declare(strict_types=1);

namespace Capell\AgentBridge\Actions\Pages;

use Capell\AgentBridge\Contracts\CapellAgentBridgeCapabilityAction;
use Capell\AgentBridge\Data\Capabilities\PageReferenceCapabilityInputData;
use Capell\AgentBridge\Data\CapabilityInvocationData;
use Capell\AgentBridge\Data\CapabilityResultData;
use Capell\AgentBridge\Support\AgentBridgeRecordResolver;
use Capell\AgentBridge\Support\AgentBridgeValue;
use Illuminate\Support\Collection;

final class InspectPagePublishingReadinessCapabilityAction implements CapellAgentBridgeCapabilityAction
{
    public function preview(CapabilityInvocationData $invocation): CapabilityResultData
    {
        return $this->execute($invocation);
    }

    public function execute(CapabilityInvocationData $invocation): CapabilityResultData
    {
        $payload = $this->validatedPayload($invocation->payload);
        $page = resolve(AgentBridgeRecordResolver::class)->page(
            user: $invocation->user,
            reference: $payload->page_id ?? $payload->page ?? '',
            siteReference: $payload->site_id ?? $payload->site,
            with: ['site', 'type', 'layout', 'pageUrls'],
        );
        $pageUrls = $page->getAttribute('pageUrls');

        $checks = [
            'has_site' => $page->getAttribute('site') !== null,
            'has_type' => $page->getAttribute('type') !== null,
            'has_layout' => $page->getAttribute('layout') !== null,
            'has_page_urls' => $pageUrls instanceof Collection && $pageUrls->isNotEmpty(),
            'is_visible_now' => method_exists($page, 'isVisible') ? (bool) $page->isVisible() : null,
        ];

        return new CapabilityResultData(
            ok: ! in_array(false, $checks, true),
            message: (string) __('capell-agent-bridge::admin.result_page_readiness_inspected'),
            data: [
                'page_id' => AgentBridgeValue::integer($page->getKey(), 'Page key'),
                'name' => $page->getAttribute('name'),
                'checks' => $checks,
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
}
