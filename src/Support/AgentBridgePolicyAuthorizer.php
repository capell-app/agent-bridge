<?php

declare(strict_types=1);

namespace Capell\AgentBridge\Support;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;

final class AgentBridgePolicyAuthorizer
{
    public const string CLEAR_CACHE_ABILITY = 'capell-agent-bridge.clear-cache';

    public function authorize(
        ?Authenticatable $actor,
        string $ability,
        mixed $arguments = [],
    ): Authenticatable {
        throw_unless(
            $actor instanceof Authenticatable,
            AuthorizationException::class,
            __('capell-agent-bridge::admin.capability_authenticated_actor_required'),
        );

        Gate::forUser($actor)->authorize($ability, $arguments);

        return $actor;
    }

    public function allows(
        ?Authenticatable $actor,
        string $ability,
        mixed $arguments = [],
    ): bool {
        return $actor instanceof Authenticatable
            && Gate::forUser($actor)->allows($ability, $arguments);
    }

    public function authorizeGlobalAdmin(?Authenticatable $actor): Authenticatable
    {
        throw_unless(
            $actor instanceof Authenticatable,
            AuthorizationException::class,
            __('capell-agent-bridge::admin.capability_authenticated_actor_required'),
        );

        throw_unless(
            $this->isGlobalAdmin($actor),
            AuthorizationException::class,
            __('capell-agent-bridge::admin.capability_global_admin_required'),
        );

        return $actor;
    }

    public function isGlobalAdmin(Authenticatable $actor): bool
    {
        $configuredRole = config('capell.roles.super_admin', config('filament-shield.super_admin.name', 'super_admin'));
        $superAdminRole = is_string($configuredRole) && $configuredRole !== '' ? $configuredRole : 'super_admin';

        if (method_exists($actor, 'hasRole') && $actor->hasRole($superAdminRole)) {
            return true;
        }

        return method_exists($actor, 'isGlobalAdmin') && $actor->isGlobalAdmin();
    }
}
