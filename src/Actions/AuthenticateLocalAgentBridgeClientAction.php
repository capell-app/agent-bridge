<?php

declare(strict_types=1);

namespace Capell\AgentBridge\Actions;

use Capell\AgentBridge\Data\AuthenticatedAgentBridgeClientData;
use Capell\AgentBridge\Models\CapellAgentBridgeToken;
use Capell\AgentBridge\Support\AgentBridgeValue;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class AuthenticateLocalAgentBridgeClientAction
{
    use AsFake;
    use AsObject;

    public function handle(): void
    {
        $plainTextToken = AgentBridgeValue::string(
            config('capell-agent-bridge.local.token', ''),
            'Local Agent Bridge token',
        );
        $token = $plainTextToken === '' ? null : CapellAgentBridgeToken::findForPlainTextToken($plainTextToken);
        $user = $token?->user;

        throw_unless(
            $token instanceof CapellAgentBridgeToken && $token->isUsable() && $user !== null,
            AuthorizationException::class,
            AgentBridgeValue::string(
                __('capell-agent-bridge::messages.mcp_v1.denied'),
                'MCP v1 denial message',
            ),
        );

        $guard = AgentBridgeValue::string(config('capell-agent-bridge.site_auth_guard', 'web'), 'Site auth guard');
        Auth::guard($guard)->setUser($user);
        Auth::shouldUse($guard);
        app()->instance(CapellAgentBridgeToken::class, $token);
        app()->instance(AuthenticatedAgentBridgeClientData::class, new AuthenticatedAgentBridgeClientData(
            tokenId: AgentBridgeValue::integer($token->getKey(), 'Agent Bridge token key'),
            name: $token->name,
            scopes: $token->scopes,
        ));
    }
}
