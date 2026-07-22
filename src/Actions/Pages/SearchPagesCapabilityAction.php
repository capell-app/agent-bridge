<?php

declare(strict_types=1);

namespace Capell\AgentBridge\Actions\Pages;

use Capell\AgentBridge\Contracts\CapellAgentBridgeCapabilityAction;
use Capell\AgentBridge\Data\Capabilities\SearchPagesCapabilityInputData;
use Capell\AgentBridge\Data\CapabilityInvocationData;
use Capell\AgentBridge\Data\CapabilityResultData;
use Capell\AgentBridge\Support\AgentBridgePagePresenter;
use Capell\AgentBridge\Support\AgentBridgeRecordResolver;
use Capell\Core\Models\Page;

final class SearchPagesCapabilityAction implements CapellAgentBridgeCapabilityAction
{
    public function preview(CapabilityInvocationData $invocation): CapabilityResultData
    {
        return $this->execute($invocation);
    }

    public function execute(CapabilityInvocationData $invocation): CapabilityResultData
    {
        validator($invocation->payload, [
            'query' => ['required', 'string', 'min:1', 'max:255'],
            'site' => ['sometimes', 'nullable'],
            'site_id' => ['sometimes', 'nullable', 'integer'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'cursor' => ['sometimes', 'integer', 'min:0', 'max:10000'],
        ])->validate();
        $payload = SearchPagesCapabilityInputData::fromPayload($invocation->payload);

        $resolver = resolve(AgentBridgeRecordResolver::class);
        $presenter = resolve(AgentBridgePagePresenter::class);
        $matches = $resolver->searchPages(
            user: $invocation->user,
            search: $payload->query,
            siteReference: $payload->site_id ?? $payload->site,
            limit: $payload->limit + 1,
            offset: $payload->cursor,
        );
        $hasMore = $matches->count() > $payload->limit;
        $matches = $matches->take($payload->limit)->values();

        return new CapabilityResultData(
            ok: true,
            message: (string) __($matches->isEmpty()
                ? 'capell-agent-bridge::admin.result_pages_none'
                : 'capell-agent-bridge::admin.result_pages_found'),
            data: [
                'query' => $payload->query,
                'count' => $matches->count(),
                'has_more' => $hasMore,
                'next_cursor' => $hasMore ? $payload->cursor + $matches->count() : null,
                'matches' => $matches
                    ->map(fn (Page $page): array => $presenter->present($page))
                    ->values()
                    ->all(),
            ],
        );
    }
}
