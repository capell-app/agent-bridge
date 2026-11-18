<?php

declare(strict_types=1);

namespace Capell\AgentBridge\Actions;

use Capell\AgentBridge\Models\CapellAgentBridgeConfirmation;
use Capell\AgentBridge\Support\AgentBridgeHumanApproval;
use Capell\AgentBridge\Support\AgentBridgeTokenManagementAccess;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * Closes an open confirmation so the agent can never confirm it. The person the token
 * acts as, or someone who may manage their tokens, can decline it; declining only takes
 * power away, so it does not need the capability's own permission.
 *
 * @method static CapellAgentBridgeConfirmation run(CapellAgentBridgeConfirmation $confirmation, Authenticatable $actor)
 */
final class DeclineAgentBridgeConfirmationAction
{
    use AsFake;
    use AsObject;

    public static function allows(CapellAgentBridgeConfirmation $confirmation, ?Authenticatable $actor): bool
    {
        if (AgentBridgeHumanApproval::insideBridgeRequest() || ! $confirmation->isUsable()) {
            return false;
        }

        $owner = $confirmation->user()->first();

        return $owner instanceof Model && AgentBridgeTokenManagementAccess::allows($actor, $owner);
    }

    public function handle(CapellAgentBridgeConfirmation $confirmation, Authenticatable $actor): CapellAgentBridgeConfirmation
    {
        throw_unless(self::allows($confirmation, $actor), AuthorizationException::class, __('capell-agent-bridge::admin.confirmation_decline_forbidden'));

        $declinedAt = now();
        $declined = CapellAgentBridgeConfirmation::query()
            ->whereKey($confirmation->getKey())
            ->whereNull('declined_at')
            ->whereNull('used_at')
            ->update(['declined_at' => $declinedAt]);

        throw_if($declined !== 1, AuthorizationException::class, __('capell-agent-bridge::admin.confirmation_not_open'));

        $confirmation->refresh();

        AuditAgentBridgeCapabilityAction::run(
            event: 'capell_agent-bridge.capability.declined',
            capabilityKey: $confirmation->capability_key,
            scope: $confirmation->scope,
            token: $confirmation->agentBridgeToken()->first(),
            user: $actor,
        );

        return $confirmation;
    }
}
