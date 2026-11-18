<?php

declare(strict_types=1);

namespace Capell\AgentBridge\Support;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

/**
 * Decides who may create, rotate or revoke Agent Bridge tokens for a user. A token
 * acts as its owner, so minting one for somebody else is acting as them: only the
 * owner, or an actor who holds the token-management permission and may update the
 * owner's account, is allowed.
 */
final class AgentBridgeTokenManagementAccess
{
    public const string MANAGE_PERMISSION = 'agent-bridge.manage-tokens';

    public static function allows(?Authenticatable $actor, Model $owner): bool
    {
        if (! $actor instanceof Authenticatable) {
            return false;
        }

        if (self::isOwner($actor, $owner)) {
            return true;
        }

        return $actor instanceof Authorizable
            && $actor->can(self::MANAGE_PERMISSION)
            && Gate::forUser($actor)->allows('update', $owner);
    }

    public static function authorize(?Authenticatable $actor, Model $owner): void
    {
        throw_unless(
            self::allows($actor, $owner),
            AuthorizationException::class,
            __('capell-agent-bridge::admin.token_management_forbidden'),
        );
    }

    private static function isOwner(Authenticatable $actor, Model $owner): bool
    {
        $actorIdentifier = $actor->getAuthIdentifier();
        $ownerIdentifier = $owner->getKey();

        return $actor instanceof Model
            && $actor->getMorphClass() === $owner->getMorphClass()
            && (is_int($actorIdentifier) || is_string($actorIdentifier))
            && (is_int($ownerIdentifier) || is_string($ownerIdentifier))
            && (string) $actorIdentifier === (string) $ownerIdentifier;
    }
}
