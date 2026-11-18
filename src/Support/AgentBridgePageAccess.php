<?php

declare(strict_types=1);

namespace Capell\AgentBridge\Support;

use Capell\Admin\Support\SiteScope;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

final class AgentBridgePageAccess
{
    public static function authorizeSite(?Authenticatable $user, int $siteId, string $field = 'site_id'): void
    {
        $assignedSiteIds = self::authorizedSiteIds($user, $field);

        if ($assignedSiteIds === null) {
            return;
        }

        foreach ($assignedSiteIds as $assignedSiteId) {
            if ((int) $assignedSiteId === $siteId) {
                return;
            }
        }

        throw ValidationException::withMessages([
            $field => __('capell-agent-bridge::admin.capability_site_forbidden'),
        ]);
    }

    /**
     * @param  list<string>  $with
     */
    public static function authorizedPage(?Authenticatable $user, int $pageId, array $with = []): Page
    {
        $query = Page::query()->whereKey($pageId);

        if ($with !== []) {
            $query->with($with);
        }

        /** @var Page $page */
        $page = $query->firstOrFail();
        $siteId = $page->getAttribute('site_id');

        if ($siteId === null) {
            self::authorizeGlobalPage($user);

            return $page;
        }

        if (! is_int($siteId)) {
            throw ValidationException::withMessages([
                'page_id' => __('capell-agent-bridge::admin.capability_site_forbidden'),
            ]);
        }

        self::authorizeSite($user, $siteId, 'page_id');

        return $page;
    }

    /**
     * Return null when every site is reachable, otherwise the exact site IDs the
     * authenticated actor may address through Agent Bridge, narrowed to the sites the
     * running token is limited to.
     *
     * @return list<int>|null
     */
    public static function authorizedSiteIds(?Authenticatable $user, string $field = 'site'): ?array
    {
        return AgentBridgeTokenSiteRestriction::narrow(self::ownerSiteIds(self::requireUser($user, $field), $field));
    }

    /** @param Builder<Page> $query */
    public static function constrainPageQuery(Builder $query, ?Authenticatable $user): void
    {
        $siteIds = self::authorizedSiteIds($user, 'page');

        if ($siteIds !== null) {
            $query->whereIn($query->getModel()->qualifyColumn('site_id'), $siteIds);
        }
    }

    /** @param Builder<Site> $query */
    public static function constrainSiteQuery(Builder $query, ?Authenticatable $user): void
    {
        $siteIds = self::authorizedSiteIds($user);

        if ($siteIds !== null) {
            $query->whereKey($siteIds);
        }
    }

    public static function isGlobalAdmin(Authenticatable $user): bool
    {
        if (method_exists($user, 'isGlobalAdmin') && $user->isGlobalAdmin() === true) {
            return true;
        }

        $superAdminRole = config('capell.roles.super_admin', 'super_admin');

        return method_exists($user, 'hasRole')
            && is_string($superAdminRole)
            && SiteScope::isGlobalActor($user);
    }

    /** @return list<int>|null */
    private static function ownerSiteIds(Authenticatable $user, string $field): ?array
    {
        if (self::isGlobalAdmin($user)) {
            return null;
        }

        // is_callable() is true for every Eloquent model because of __call, so it would
        // turn a user without site assignments into a crash instead of a refusal.
        if (! method_exists($user, 'getAssignedSiteIds')) {
            throw ValidationException::withMessages([
                $field => __('capell-agent-bridge::admin.capability_site_forbidden'),
            ]);
        }

        $assignedSiteIds = $user->getAssignedSiteIds();

        $siteIds = [];

        foreach ($assignedSiteIds as $siteId) {
            if (is_int($siteId) || is_string($siteId) && ctype_digit($siteId)) {
                $siteIds[] = (int) $siteId;
            }
        }

        return array_values(array_unique($siteIds));
    }

    private static function authorizeGlobalPage(?Authenticatable $user): void
    {
        // A page outside every site is reachable only by a global administrator, and never
        // by a token limited to particular sites.
        if ($user instanceof Authenticatable && self::isGlobalAdmin($user) && AgentBridgeTokenSiteRestriction::current() === null) {
            return;
        }

        throw ValidationException::withMessages([
            'page_id' => __('capell-agent-bridge::admin.capability_site_forbidden'),
        ]);
    }

    private static function requireUser(?Authenticatable $user, string $field): Authenticatable
    {
        if ($user instanceof Authenticatable) {
            return $user;
        }

        throw ValidationException::withMessages([
            $field => __('capell-agent-bridge::admin.capability_site_requires_user'),
        ]);
    }
}
