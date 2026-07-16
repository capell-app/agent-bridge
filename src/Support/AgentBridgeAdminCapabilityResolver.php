<?php

declare(strict_types=1);

namespace Capell\AgentBridge\Support;

use Capell\AgentBridge\Data\CapabilityData;
use Capell\AgentBridge\Enums\CapabilityServerEnum;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;

final class AgentBridgeAdminCapabilityResolver
{
    public function resolve(string $capabilityKey, Authenticatable $user): CapabilityData
    {
        $registry = resolve(CapellAgentBridgeCapabilityRegistry::class);
        $capability = $registry->get($capabilityKey);

        throw_unless(
            $registry->visibleFor(CapabilityServerEnum::Site)
                ->contains(static fn (CapabilityData $visible): bool => $visible->key === $capability->key),
            AuthorizationException::class,
            __('capell-agent-bridge::admin.command_capability_unavailable'),
        );

        if ($capability->policyAbility !== null) {
            resolve(AgentBridgePolicyAuthorizer::class)->authorize($user, $capability->policyAbility);
        }

        return $capability;
    }
}
