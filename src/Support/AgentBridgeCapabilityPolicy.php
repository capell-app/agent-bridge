<?php

declare(strict_types=1);

namespace Capell\AgentBridge\Support;

use Capell\AgentBridge\Contracts\ResolvesCapabilityPolicySite;
use Capell\AgentBridge\Data\CapabilityData;
use Capell\Core\Support\Permissions\PermissionTeamContext;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

/**
 * Checks a capability's policy ability for a user, in the permission team of the site
 * the capability's action says the payload reaches (see ResolvesCapabilityPolicySite).
 * Capabilities that name no site are checked in the current team, as before.
 */
final class AgentBridgeCapabilityPolicy
{
    /**
     * @param  array<array-key, mixed>  $payload
     */
    public static function authorize(CapabilityData $capability, Authenticatable $user, array $payload): void
    {
        if ($capability->policyAbility === null) {
            return;
        }

        $ability = $capability->policyAbility;

        self::withinSiteTeam($capability, $user, $payload, static function () use ($user, $ability): void {
            Gate::forUser($user)->authorize($ability);
        });
    }

    /**
     * @template TResult
     *
     * @param  array<array-key, mixed>  $payload
     * @param  callable(): TResult  $operation
     * @return TResult
     */
    private static function withinSiteTeam(CapabilityData $capability, Authenticatable $user, array $payload, callable $operation): mixed
    {
        $action = $capability->actionClass;

        if (! is_a($action, ResolvesCapabilityPolicySite::class, true)) {
            return $operation();
        }

        return PermissionTeamContext::run(
            $action::policySiteId($payload),
            $operation(...),
            $user instanceof Model ? $user : null,
        );
    }
}
