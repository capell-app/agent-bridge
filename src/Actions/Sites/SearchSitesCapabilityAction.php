<?php

declare(strict_types=1);

namespace Capell\AgentBridge\Actions\Sites;

use Capell\AgentBridge\Contracts\CapellAgentBridgeCapabilityAction;
use Capell\AgentBridge\Data\CapabilityInvocationData;
use Capell\AgentBridge\Data\CapabilityResultData;
use Capell\AgentBridge\Support\AgentBridgeRecordResolver;
use Capell\AgentBridge\Support\AgentBridgeSitePresenter;
use Capell\Core\Models\Site;
use Capell\Core\Models\SiteDomain;

final class SearchSitesCapabilityAction implements CapellAgentBridgeCapabilityAction
{
    public function preview(CapabilityInvocationData $invocation): CapabilityResultData
    {
        return $this->execute($invocation);
    }

    public function execute(CapabilityInvocationData $invocation): CapabilityResultData
    {
        $payload = validator($invocation->payload, [
            'query' => ['sometimes', 'nullable', 'string', 'max:255'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ])->validate();
        $query = mb_strtolower(trim((string) ($payload['query'] ?? '')));
        $sites = resolve(AgentBridgeRecordResolver::class)
            ->authorizedSites($invocation->user)
            ->filter(function (Site $site) use ($query): bool {
                if ($query === '') {
                    return true;
                }

                $haystack = mb_strtolower(implode(' ', [
                    (string) $site->name,
                    ...$site->siteDomains->flatMap(static fn (SiteDomain $domain): array => [
                        (string) $domain->domain,
                        (string) $domain->full_url,
                    ])->all(),
                ]));

                return str_contains($haystack, $query);
            })
            ->take((int) ($payload['limit'] ?? 20))
            ->values();
        $presenter = resolve(AgentBridgeSitePresenter::class);

        return new CapabilityResultData(
            ok: true,
            message: (string) __('capell-agent-bridge::admin.result_sites_found', ['count' => $sites->count()]),
            data: [
                'count' => $sites->count(),
                'sites' => $sites->map(fn (Site $site): array => $presenter->present($site))->all(),
            ],
        );
    }
}
