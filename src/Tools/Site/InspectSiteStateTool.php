<?php

declare(strict_types=1);

namespace Capell\AgentBridge\Tools\Site;

use Capell\AgentBridge\Bridges\Navigation\NavigationSiteStateBridge;
use Capell\AgentBridge\Models\CapellAgentBridgeToken;
use Capell\AgentBridge\Support\AgentBridgePageAccess;
use Capell\AgentBridge\Support\AgentBridgeTokenSiteRestriction;
use Capell\Core\Enums\UrlTypeEnum;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Language;
use Capell\Core\Models\Page;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\Site;
use Capell\Core\Support\Extensions\ExtensionPresence;
use Composer\InstalledVersions;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Throwable;

#[Name('capell-site-inspect-state')]
#[Title('Inspect Site State')]
#[Description('Inspect high-level installed Capell app state without exposing private content bodies.')]
#[IsReadOnly]
final class InspectSiteStateTool extends Tool
{
    public function handle(): ResponseFactory
    {
        $app = [
            'name' => config('app.name'),
        ];

        if ((bool) config('capell-agent-bridge.inspect_app_runtime', false)) {
            $app['environment'] = app()->environment();
            $app['debug'] = (bool) config('app.debug');
        }

        return Response::structured([
            'app' => $app,
            'packages' => $this->installedCapellPackages(),
            'counts' => $this->modelCounts($this->reachableSiteIds()),
        ]);
    }

    /**
     * Null when the caller may see the whole installation: a client without a token, or
     * a token whose owner reaches every site and which is not limited to some. Otherwise
     * the sites the counts are kept to, which is empty when the token can no longer be
     * used or its owner reaches no site.
     *
     * @return list<int>|null
     */
    private function reachableSiteIds(): ?array
    {
        $token = app()->bound(CapellAgentBridgeToken::class) ? resolve(CapellAgentBridgeToken::class) : null;

        if (! $token instanceof CapellAgentBridgeToken) {
            return null;
        }

        $stored = $token->fresh(['user']);
        $owner = $stored?->user;

        if (! $stored instanceof CapellAgentBridgeToken || ! $stored->isUsable() || ! $owner instanceof Authenticatable) {
            return [];
        }

        try {
            return AgentBridgeTokenSiteRestriction::within($stored, static fn (): ?array => AgentBridgePageAccess::authorizedSiteIds($owner));
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Capell packages on disk, without extensions the site has disabled or uninstalled:
     * Composer still lists their files, but their features are off. Libraries the
     * extension registry does not track (such as Core itself) are reported as installed.
     *
     * @return array<string, string|null>
     */
    private function installedCapellPackages(): array
    {
        $packages = [];

        foreach (InstalledVersions::getInstalledPackages() as $packageName) {
            if (! str_starts_with($packageName, 'capell-app/')) {
                continue;
            }

            if (CapellCore::hasPackage($packageName) && ! ExtensionPresence::isActive($packageName)) {
                continue;
            }

            $packages[$packageName] = InstalledVersions::getPrettyVersion($packageName);
        }

        ksort($packages);

        return $packages;
    }

    /**
     * Counts for the whole installation, or, for a caller kept to some sites, counts of
     * those sites' records only. Records that belong to no site are left out then.
     *
     * @param  list<int>|null  $siteIds
     * @return array<string, int|null>
     */
    private function modelCounts(?array $siteIds): array
    {
        if ($siteIds !== null) {
            return [
                'sites' => $this->count(static fn (): int => Site::query()->whereKey($siteIds)->count()),
                'languages' => null,
                'pages' => $this->countSiteRecords(Page::class, $siteIds),
                'pageUrls' => $this->countSiteRecords(PageUrl::class, $siteIds),
                'blueprints' => null,
                'redirects' => $this->count(static fn (): int => PageUrl::query()->whereIn('site_id', $siteIds)->where('type', UrlTypeEnum::Redirect)->count()),
                'navigations' => $this->navigationCount($siteIds),
            ];
        }

        return [
            'sites' => $this->countOptionalModel(Site::class),
            'languages' => $this->countOptionalModel(Language::class),
            'pages' => $this->countOptionalModel(Page::class),
            'pageUrls' => $this->countOptionalModel(PageUrl::class),
            'blueprints' => $this->countOptionalModel(Blueprint::class),
            'redirects' => $this->count(static fn (): int => PageUrl::query()->where('type', UrlTypeEnum::Redirect)->count()),
            'navigations' => $this->navigationCount(null),
        ];
    }

    /** @param  list<int>|null  $siteIds */
    private function navigationCount(?array $siteIds): ?int
    {
        $modelClass = NavigationSiteStateBridge::modelClass();

        if ($modelClass === null) {
            return null;
        }

        return $siteIds === null
            ? $this->countOptionalModel($modelClass)
            : $this->countSiteRecords($modelClass, $siteIds);
    }

    /** @param  list<int>  $siteIds */
    private function countSiteRecords(string $modelClass, array $siteIds): ?int
    {
        if (! is_subclass_of($modelClass, Model::class)) {
            return null;
        }

        return $this->count(static fn (): int => $modelClass::query()->whereIn('site_id', $siteIds)->count());
    }

    private function countOptionalModel(string $modelClass): ?int
    {
        if (! is_subclass_of($modelClass, Model::class)) {
            return null;
        }

        return $this->count(static fn (): int => $modelClass::query()->count());
    }

    /** @param  callable(): int  $count */
    private function count(callable $count): ?int
    {
        try {
            return $count();
        } catch (Throwable) {
            return null;
        }
    }
}
