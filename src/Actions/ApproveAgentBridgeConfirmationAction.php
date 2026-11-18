<?php

declare(strict_types=1);

namespace Capell\AgentBridge\Actions;

use Capell\AgentBridge\Models\CapellAgentBridgeConfirmation;
use Capell\AgentBridge\Models\CapellAgentBridgeToken;
use Capell\AgentBridge\Support\AgentBridgeCapabilityPolicy;
use Capell\AgentBridge\Support\AgentBridgeHumanApproval;
use Capell\AgentBridge\Support\CapellAgentBridgeCapabilityRegistry;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * Records that the person a token acts as has approved one previewed high-risk change.
 *
 * Only that person can approve, from their admin session: the approval is bound to them,
 * to the capability and to the previewed payload hash already stored on the confirmation,
 * and the agent still has to confirm with the same payload afterwards.
 *
 * @method static CapellAgentBridgeConfirmation run(CapellAgentBridgeConfirmation $confirmation, Authenticatable $approver)
 */
final class ApproveAgentBridgeConfirmationAction
{
    use AsFake;
    use AsObject;

    /**
     * @phpstan-assert Model&Authenticatable $approver
     */
    public static function assertCanApprove(CapellAgentBridgeConfirmation $confirmation, Authenticatable $approver): void
    {
        throw_if(
            AgentBridgeHumanApproval::insideBridgeRequest(),
            AuthorizationException::class,
            __('capell-agent-bridge::admin.confirmation_approval_outside_admin'),
        );

        throw_unless(
            $approver instanceof Model && $confirmation->belongsToUser($approver),
            AuthorizationException::class,
            __('capell-agent-bridge::admin.confirmation_approval_owner_only'),
        );

        throw_unless($confirmation->isAwaitingApproval(), AuthorizationException::class, __('capell-agent-bridge::admin.confirmation_not_awaiting_approval'));

        $token = $confirmation->agentBridgeToken()->first();

        throw_unless(
            $token instanceof CapellAgentBridgeToken
                && $token->isUsable()
                && $token->canUseScope($confirmation->scope)
                && $token->user_type === $approver->getMorphClass()
                && CapellAgentBridgeConfirmation::sameKey($token->user_id, $approver->getKey()),
            AuthorizationException::class,
            __('capell-agent-bridge::admin.confirmation_token_unusable'),
        );

        try {
            $capability = resolve(CapellAgentBridgeCapabilityRegistry::class)->get($confirmation->capability_key);
        } catch (InvalidArgumentException) {
            throw new AuthorizationException(__('capell-agent-bridge::admin.confirmation_capability_missing'));
        }

        if ($capability->policyAbility !== null) {
            AgentBridgeCapabilityPolicy::authorize($capability, $approver, $confirmation->payload);
        }
    }

    public function handle(CapellAgentBridgeConfirmation $confirmation, Authenticatable $approver): CapellAgentBridgeConfirmation
    {
        self::assertCanApprove($confirmation, $approver);

        $approvedAt = now();
        $approved = CapellAgentBridgeConfirmation::query()
            ->whereKey($confirmation->getKey())
            ->where('requires_approval', true)
            ->whereNull('approved_at')
            ->whereNull('declined_at')
            ->whereNull('used_at')
            ->where('expires_at', '>', $approvedAt)
            ->update([
                'approved_by_type' => $approver->getMorphClass(),
                'approved_by_id' => $approver->getKey(),
                'approved_at' => $approvedAt,
            ]);

        throw_if($approved !== 1, AuthorizationException::class, __('capell-agent-bridge::admin.confirmation_not_awaiting_approval'));

        $confirmation->refresh();

        AuditAgentBridgeCapabilityAction::run(
            event: 'capell_agent-bridge.capability.approved',
            capabilityKey: $confirmation->capability_key,
            scope: $confirmation->scope,
            payload: $this->storedPayload($confirmation),
            token: $confirmation->agentBridgeToken()->first(),
            user: $approver,
        );

        return $confirmation;
    }

    /** @return array<string, mixed> */
    private function storedPayload(CapellAgentBridgeConfirmation $confirmation): array
    {
        $payload = [];

        foreach ($confirmation->payload as $key => $value) {
            $payload[(string) $key] = $value;
        }

        return $payload;
    }
}
