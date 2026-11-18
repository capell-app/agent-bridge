<?php

declare(strict_types=1);

namespace Capell\AgentBridge\Support;

use Capell\AgentBridge\Data\AuthenticatedAgentBridgeClientData;
use Capell\AgentBridge\Models\CapellAgentBridgeToken;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * The client context is built once when a transport authenticates, and the local
 * stdio server keeps it for the life of the process. Before a capability runs or is
 * confirmed, re-read the token so a revoked, disabled, expired, de-scoped or
 * reassigned token stops working at once, and re-read its owner so a deleted
 * owner, or one whose roles changed, is judged as they are now rather than as
 * the process first loaded them.
 */
final class AgentBridgeTokenFreshness
{
    /**
     * @return Model&Authenticatable the token owner as currently stored, for every later permission check
     */
    public static function assertStillAllowed(
        CapellAgentBridgeToken $token,
        AuthenticatedAgentBridgeClientData $client,
        string $scope,
        ?Authenticatable $user,
        ?string $capabilityKey = null,
    ): Model&Authenticatable {
        $currentToken = CapellAgentBridgeToken::query()->find($token->getKey());
        $userIdentifier = $user?->getAuthIdentifier();

        throw_unless(
            $currentToken instanceof CapellAgentBridgeToken
                && $currentToken->isUsable()
                && $currentToken->id === $client->tokenId
                && $currentToken->canUseScope($scope)
                && ($capabilityKey === null || $currentToken->canUseCapability($capabilityKey))
                && $user instanceof Model
                && $currentToken->user_type === $user->getMorphClass()
                && (is_int($userIdentifier) || is_string($userIdentifier))
                && (string) $currentToken->user_id === (string) $userIdentifier,
            AuthorizationException::class,
            'The Agent Bridge token is no longer allowed to use this capability.',
        );

        $owner = self::currentOwner($currentToken);

        if (! $owner instanceof Authenticatable) {
            throw new AuthorizationException('The Agent Bridge token owner no longer exists.');
        }

        return $owner;
    }

    private static function currentOwner(CapellAgentBridgeToken $token): ?Model
    {
        $ownerClass = Relation::getMorphedModel($token->user_type) ?? $token->user_type;

        if (! is_a($ownerClass, Model::class, true)) {
            return null;
        }

        return $ownerClass::query()->whereKey($token->user_id)->first();
    }
}
