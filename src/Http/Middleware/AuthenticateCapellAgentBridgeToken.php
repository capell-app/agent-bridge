<?php

declare(strict_types=1);

namespace Capell\AgentBridge\Http\Middleware;

use Capell\AgentBridge\Data\AuthenticatedAgentBridgeClientData;
use Capell\AgentBridge\Models\CapellAgentBridgeToken;
use Capell\AgentBridge\Support\AgentBridgeValue;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Stringable;
use Symfony\Component\HttpFoundation\Response;

final class AuthenticateCapellAgentBridgeToken
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $this->forgetRequestContext();

        $plainTextToken = (string) $request->bearerToken();

        if ($plainTextToken === '') {
            return $this->unauthorized(__('capell-agent-bridge::messages.auth.missing_bearer_token'));
        }

        $token = CapellAgentBridgeToken::findForPlainTextToken($plainTextToken);

        if (! $token instanceof CapellAgentBridgeToken || ! $token->isUsable()) {
            return $this->unauthorized(__('capell-agent-bridge::messages.auth.invalid_bearer_token'));
        }

        $user = $token->user;

        if ($user === null) {
            return $this->unauthorized(__('capell-agent-bridge::messages.auth.unlinked_user'));
        }

        $guard = AgentBridgeValue::string(config('capell-agent-bridge.site_auth_guard', 'web'), 'Site auth guard');
        $authGuard = Auth::guard($guard);
        $authGuard->setUser($user);
        Auth::shouldUse($guard);

        $tokenUpdates = [];

        if ($this->shouldUpdateLastUsedAt($token)) {
            $tokenUpdates['last_used_at'] = now();
        }

        if ($token->hasLegacyHashForPlainTextToken($plainTextToken)) {
            $tokenUpdates['token_hash'] = CapellAgentBridgeToken::hashPlainTextToken($plainTextToken);
        }

        if ($tokenUpdates !== []) {
            $token->forceFill($tokenUpdates)->save();
        }

        app()->instance(CapellAgentBridgeToken::class, $token);
        app()->instance(AuthenticatedAgentBridgeClientData::class, new AuthenticatedAgentBridgeClientData(
            tokenId: AgentBridgeValue::integer($token->getKey(), 'Agent Bridge token key'),
            name: $token->name,
            scopes: $token->scopes,
        ));

        try {
            return $next($request);
        } finally {
            $this->forgetRequestContext();
        }
    }

    private function shouldUpdateLastUsedAt(CapellAgentBridgeToken $token): bool
    {
        $throttleMinutes = AgentBridgeValue::integer(
            config('capell-agent-bridge.last_used_throttle_minutes', 5),
            'Last-used throttle minutes',
        );

        if ($throttleMinutes <= 0) {
            return true;
        }

        $lastUsed = $token->last_used_at;

        if (! $lastUsed instanceof CarbonImmutable) {
            return true;
        }

        return $lastUsed->diffInMinutes(now()) >= $throttleMinutes;
    }

    private function unauthorized(string|Stringable $message): Response
    {
        return response((string) $message, 401)
            ->header('WWW-Authenticate', 'Bearer realm="capell-agent-bridge", error="invalid_token"');
    }

    private function forgetRequestContext(): void
    {
        app()->forgetInstance(CapellAgentBridgeToken::class);
        app()->forgetInstance(AuthenticatedAgentBridgeClientData::class);
    }
}
