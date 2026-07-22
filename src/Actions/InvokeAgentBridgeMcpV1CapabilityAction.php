<?php

declare(strict_types=1);

namespace Capell\AgentBridge\Actions;

use Capell\AgentBridge\Data\AuthenticatedAgentBridgeClientData;
use Capell\AgentBridge\Models\CapellAgentBridgeToken;
use Capell\AgentBridge\Support\AgentBridgeMcpV1Policy;
use Capell\AgentBridge\Support\CapellAgentBridgeCapabilityRegistry;
use Illuminate\Contracts\Auth\Authenticatable;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class InvokeAgentBridgeMcpV1CapabilityAction
{
    use AsFake;
    use AsObject;

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function handle(
        string $capabilityKey,
        array $payload,
        AuthenticatedAgentBridgeClientData $client,
        CapellAgentBridgeToken $token,
        ?Authenticatable $user,
    ): array {
        $policy = resolve(AgentBridgeMcpV1Policy::class);
        $capability = $policy->resolveCapability(
            resolve(CapellAgentBridgeCapabilityRegistry::class),
            $capabilityKey,
        );
        $policy->authorizeExecution($capability, $payload, $client, $token, $user);

        return $policy->projectOutput($capability, InvokeAgentBridgeCapabilityPreviewAction::run(
            capabilityKey: $capabilityKey,
            payload: $payload,
            client: $client,
            token: $token,
            user: $user,
        ));
    }
}
