<?php

declare(strict_types=1);

namespace Capell\AgentBridge\Http\Middleware;

use Capell\AgentBridge\Models\CapellAgentBridgeToken;
use Capell\AgentBridge\Support\AgentBridgeValue;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

final class ThrottleAgentBridgeRequests
{
    private const int DEFAULT_MAX_ATTEMPTS_PER_MINUTE = 60;

    /**
     * The token's own quota and the site-wide limit both apply, so the stricter wins. A
     * token quota still applies when the site-wide limit is switched off. Null means the
     * token is not limited.
     */
    public static function maxAttemptsFor(CapellAgentBridgeToken $token): ?int
    {
        $limits = [];

        if (AgentBridgeValue::boolean(config('capell-agent-bridge.rate_limit_enabled', true), 'Rate limit enabled')) {
            $siteLimit = AgentBridgeValue::integer(
                config('capell-agent-bridge.rate_limit_per_minute', self::DEFAULT_MAX_ATTEMPTS_PER_MINUTE),
                'Rate limit per minute',
            );

            if ($siteLimit > 0) {
                $limits[] = $siteLimit;
            }
        }

        if ($token->requests_per_minute !== null && $token->requests_per_minute > 0) {
            $limits[] = $token->requests_per_minute;
        }

        return $limits === [] ? null : min($limits);
    }

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $token = app()->bound(CapellAgentBridgeToken::class)
            ? resolve(CapellAgentBridgeToken::class)
            : null;

        if (! $token instanceof CapellAgentBridgeToken) {
            return $next($request);
        }

        $maxAttempts = self::maxAttemptsFor($token);

        if ($maxAttempts === null) {
            return $next($request);
        }

        $rateLimitKey = 'capell-agent-bridge:token:' . AgentBridgeValue::identifier($token->getKey(), 'Agent Bridge token key');

        // Count the request first and decide on the count the increment returns. Checking
        // and then counting lets concurrent requests all pass the check before any of
        // them is counted, so a burst could exceed the quota.
        if (RateLimiter::hit($rateLimitKey, 60) > $maxAttempts) {
            $retryAfter = RateLimiter::availableIn($rateLimitKey);

            return response(
                __('capell-agent-bridge::messages.rate_limited', ['seconds' => $retryAfter]),
                429,
            )->header('Retry-After', (string) $retryAfter);
        }

        return $next($request);
    }
}
