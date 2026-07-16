<?php

declare(strict_types=1);

namespace Capell\AgentBridge\Support;

use Capell\Admin\Filament\Resources\Sites\SiteResource;
use Capell\Core\Models\Site;
use Capell\Core\Models\SiteDomain;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Route;
use Throwable;

final class AgentBridgeSitePresenter
{
    /** @return array<string, mixed> */
    public function present(Site $site): array
    {
        $site->loadMissing(['language', 'siteDomains.language', 'theme']);
        $updatedAt = $site->getAttribute('updated_at');

        return [
            'id' => AgentBridgeValue::integer($site->getKey(), 'Site key'),
            'name' => AgentBridgeValue::string($site->name, 'Site name'),
            'default' => (bool) $site->default,
            'language' => $site->language === null ? null : [
                'id' => AgentBridgeValue::integer($site->language->getKey(), 'Language key'),
                'name' => AgentBridgeValue::string($site->language->name, 'Language name'),
                'code' => AgentBridgeValue::string($site->language->code, 'Language code'),
            ],
            'theme' => $site->theme === null ? null : [
                'id' => AgentBridgeValue::integer($site->theme->getKey(), 'Theme key'),
                'name' => AgentBridgeValue::string($site->theme->name, 'Theme name'),
                'key' => AgentBridgeValue::string($site->theme->key, 'Theme machine key'),
            ],
            'domains' => $site->siteDomains
                ->map(static fn (SiteDomain $domain): array => [
                    'domain' => AgentBridgeValue::string($domain->domain, 'Site domain'),
                    'url' => AgentBridgeValue::string($domain->full_url, 'Site URL'),
                    'language' => $domain->language?->code,
                ])
                ->values()
                ->all(),
            'edit_url' => $this->editUrl($site),
            'updated_at' => $updatedAt instanceof CarbonInterface ? $updatedAt->toIso8601String() : null,
        ];
    }

    public function editUrl(Site $site): string
    {
        $resourceUrl = $this->resourceEditUrl($site);

        if ($resourceUrl !== null) {
            return $resourceUrl;
        }

        $routeName = 'filament.admin.resources.sites.edit';

        if (Route::has($routeName)) {
            return route($routeName, ['record' => $site->getKey()]);
        }

        $adminPath = trim(AgentBridgeValue::string(config('capell-admin.path', 'admin'), 'Admin path'), '/');

        return url(sprintf(
            '/%s/sites/%s/edit',
            $adminPath,
            AgentBridgeValue::identifier($site->getRouteKey(), 'Site route key'),
        ));
    }

    private function resourceEditUrl(Site $site): ?string
    {
        try {
            $url = SiteResource::getUrl('edit', ['record' => $site]);

            if ($url !== '') {
                return $url;
            }
        } catch (Throwable) {
            return null;
        }

        return null;
    }
}
