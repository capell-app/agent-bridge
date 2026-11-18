<?php

declare(strict_types=1);

namespace Capell\AgentBridge\Http\Middleware;

use Capell\AgentBridge\Data\AuthenticatedAgentBridgeClientData;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuses an authenticated token that does not hold the named scope, so a server that
 * serves no capability of its own, such as the knowledge server, is still gated by a
 * scope an owner granted on purpose.
 */
final class RequireAgentBridgeScope
{
    public const string KNOWLEDGE_READ = 'capell.knowledge.read';

    public static function using(string $scope): string
    {
        return self::class . ':' . $scope;
    }

    public function handle(Request $request, Closure $next, string $scope): Response
    {
        $client = app()->bound(AuthenticatedAgentBridgeClientData::class)
            ? resolve(AuthenticatedAgentBridgeClientData::class)
            : null;

        if (! $client instanceof AuthenticatedAgentBridgeClientData || ! $client->can($scope)) {
            return response(__('capell-agent-bridge::messages.auth.missing_scope'), 403);
        }

        return $next($request);
    }
}
