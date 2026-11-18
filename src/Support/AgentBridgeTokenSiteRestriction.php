<?php

declare(strict_types=1);

namespace Capell\AgentBridge\Support;

use Capell\AgentBridge\Models\CapellAgentBridgeToken;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Carries a token's site limit into the capability it runs, so every site and page lookup
 * made through {@see AgentBridgePageAccess} is narrowed to those sites as well as to the
 * owner's own access. The token is read again from storage, so a limit tightened since the
 * client authenticated applies at once, and a token deleted in the meantime is refused
 * rather than run without a limit. The limit applies only while the capability runs and
 * is restored afterwards, even when the capability throws.
 */
final class AgentBridgeTokenSiteRestriction
{
    /** @var list<int>|null */
    private static ?array $siteIds = null;

    /**
     * @template TResult
     *
     * @param  CapellAgentBridgeToken|null  $token  null only for internal clients, which carry no token
     * @param  Closure(): TResult  $callback
     * @return TResult
     */
    public static function within(?CapellAgentBridgeToken $token, Closure $callback): mixed
    {
        $stored = $token?->fresh();

        throw_if($token instanceof CapellAgentBridgeToken && ! $stored instanceof CapellAgentBridgeToken, AuthorizationException::class, 'The Agent Bridge token is no longer valid.');

        $previous = self::$siteIds;
        self::$siteIds = $stored?->restrictedSiteIds();

        try {
            return $callback();
        } finally {
            self::$siteIds = $previous;
        }
    }

    /** @return list<int>|null */
    public static function current(): ?array
    {
        return self::$siteIds;
    }

    /**
     * @param  list<int>|null  $ownerSiteIds  null when the owner may reach every site
     * @return list<int>|null
     */
    public static function narrow(?array $ownerSiteIds): ?array
    {
        if (self::$siteIds === null) {
            return $ownerSiteIds;
        }

        if ($ownerSiteIds === null) {
            return self::$siteIds;
        }

        return array_values(array_intersect($ownerSiteIds, self::$siteIds));
    }
}
