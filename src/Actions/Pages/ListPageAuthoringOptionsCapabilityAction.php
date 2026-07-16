<?php

declare(strict_types=1);

namespace Capell\AgentBridge\Actions\Pages;

use Capell\AgentBridge\Contracts\CapellAgentBridgeCapabilityAction;
use Capell\AgentBridge\Data\Capabilities\PageAuthoringOptionsCapabilityInputData;
use Capell\AgentBridge\Data\CapabilityInvocationData;
use Capell\AgentBridge\Data\CapabilityResultData;
use Capell\AgentBridge\Support\AgentBridgePolicyAuthorizer;
use Capell\AgentBridge\Support\AgentBridgeRecordResolver;
use Capell\AgentBridge\Support\AgentBridgeValue;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Language;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Page;
use Illuminate\Database\Eloquent\Builder;

final class ListPageAuthoringOptionsCapabilityAction implements CapellAgentBridgeCapabilityAction
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
        $payload = PageAuthoringOptionsCapabilityInputData::fromPayload($invocation->payload);
        $resolver = resolve(AgentBridgeRecordResolver::class);
        $site = $resolver->site($invocation->user, $payload->site_id ?? $payload->site);
        resolve(AgentBridgePolicyAuthorizer::class)->authorize($invocation->user, 'create', Page::class);
        $site->loadMissing(['language', 'siteDomains.language']);

        $languages = collect([$site->language])
            ->merge($site->siteDomains->pluck('language'))
            ->filter(fn (mixed $language): bool => $language instanceof Language)
            ->unique(fn (Language $language): int => AgentBridgeValue::integer($language->getKey(), 'Language key'))
            ->values();
        $pageTypes = Blueprint::query()
            ->pageType()
            ->enabled()
            ->orderByDesc('default')
            ->orderBy('order')
            ->get();
        $layouts = Layout::query()
            ->enabled()
            ->where(fn (Builder $query): Builder => $query->whereNull('site_id')->orWhere('site_id', $site->getKey()))
            ->orderByDesc('default')
            ->orderBy('order')
            ->get();

        return new CapabilityResultData(
            ok: true,
            message: (string) __('capell-agent-bridge::admin.result_page_authoring_options'),
            data: [
                'site' => [
                    'id' => AgentBridgeValue::integer($site->getKey(), 'Site key'),
                    'name' => AgentBridgeValue::string($site->name, 'Site name'),
                ],
                'languages' => $languages->map(fn (Language $language): array => [
                    'id' => AgentBridgeValue::integer($language->getKey(), 'Language key'),
                    'name' => AgentBridgeValue::string($language->name, 'Language name'),
                    'code' => AgentBridgeValue::string($language->code, 'Language code'),
                ])->all(),
                'page_types' => $pageTypes->map(fn (Blueprint $pageType): array => [
                    'id' => AgentBridgeValue::integer($pageType->getKey(), 'Page type key'),
                    'name' => AgentBridgeValue::string($pageType->name, 'Page type name'),
                    'key' => AgentBridgeValue::string($pageType->key, 'Page type machine key'),
                    'default' => (bool) $pageType->default,
                ])->all(),
                'layouts' => $layouts->map(fn (Layout $layout): array => [
                    'id' => AgentBridgeValue::integer($layout->getKey(), 'Layout key'),
                    'name' => AgentBridgeValue::string($layout->name, 'Layout name'),
                    'key' => AgentBridgeValue::string($layout->key, 'Layout machine key'),
                    'default' => (bool) $layout->default,
                ])->all(),
            ],
        );
    }
}
