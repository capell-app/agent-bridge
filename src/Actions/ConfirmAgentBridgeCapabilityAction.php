<?php

declare(strict_types=1);

namespace Capell\AgentBridge\Actions;

use Capell\AgentBridge\Data\AuthenticatedAgentBridgeClientData;
use Capell\AgentBridge\Data\CapabilityInvocationData;
use Capell\AgentBridge\Data\CapabilityResultData;
use Capell\AgentBridge\Exceptions\AgentBridgeConfirmationAwaitingApprovalException;
use Capell\AgentBridge\Models\CapellAgentBridgeConfirmation;
use Capell\AgentBridge\Models\CapellAgentBridgeToken;
use Capell\AgentBridge\Support\AgentBridgeCapabilityPolicy;
use Capell\AgentBridge\Support\AgentBridgeContentTrust;
use Capell\AgentBridge\Support\AgentBridgeHumanApproval;
use Capell\AgentBridge\Support\AgentBridgeTokenFreshness;
use Capell\AgentBridge\Support\AgentBridgeTokenSiteRestriction;
use Capell\AgentBridge\Support\AgentBridgeValue;
use Capell\AgentBridge\Support\CapellAgentBridgeCapabilityRegistry;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * @method static array<string, mixed> run(string $confirmationToken, array<string, mixed> $payload, ?AuthenticatedAgentBridgeClientData $client = null, ?CapellAgentBridgeToken $token = null, ?Authenticatable $user = null)
 */
final class ConfirmAgentBridgeCapabilityAction
{
    use AsFake;
    use AsObject;

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function handle(
        string $confirmationToken,
        array $payload,
        ?AuthenticatedAgentBridgeClientData $client = null,
        ?CapellAgentBridgeToken $token = null,
        ?Authenticatable $user = null,
    ): array {
        $client ??= app()->bound(AuthenticatedAgentBridgeClientData::class) ? resolve(AuthenticatedAgentBridgeClientData::class) : null;
        $token ??= app()->bound(CapellAgentBridgeToken::class) ? resolve(CapellAgentBridgeToken::class) : null;
        $user ??= request()->user();

        throw_unless($client instanceof AuthenticatedAgentBridgeClientData, AuthorizationException::class, 'Agent Bridge client context is required.');

        $confirmation = CapellAgentBridgeConfirmation::query()
            ->where('token', CapellAgentBridgeConfirmation::hashPlainTextToken($confirmationToken))
            ->first();

        throw_if(! $confirmation instanceof CapellAgentBridgeConfirmation || ! $confirmation->isUsable(), AuthorizationException::class, 'The Agent Bridge confirmation token is invalid or expired.');

        throw_if($token === null || $confirmation->agent_bridge_token_id !== $token->getKey(), AuthorizationException::class, 'The Agent Bridge confirmation token does not belong to this client.');

        $userType = $confirmation->user_type;
        $userId = $confirmation->user_id;
        $authIdentifier = $user?->getAuthIdentifier();

        throw_if(
            $user === null
                || ! is_string($userType)
                || $userType !== $user->getMorphClass()
                || (! is_int($userId) && ! is_string($userId))
                || (! is_int($authIdentifier) && ! is_string($authIdentifier))
                || (string) $userId !== (string) $authIdentifier,
            AuthorizationException::class,
            'The Agent Bridge confirmation token does not belong to this user.',
        );

        throw_if($confirmation->payload_hash !== InvokeAgentBridgeCapabilityPreviewAction::payloadHash($payload), AuthorizationException::class, 'The Agent Bridge confirmation payload has changed.');

        $registry = resolve(CapellAgentBridgeCapabilityRegistry::class);
        $capability = $registry->get($confirmation->capability_key);

        if (! $client->can($capability->scope)) {
            throw new AuthorizationException(sprintf('Agent Bridge scope [%s] is required.', $capability->scope));
        }

        throw_unless(
            $client->canUseCapability($capability->key),
            AuthorizationException::class,
            sprintf('This Agent Bridge token is not allowed to use capability [%s].', $capability->key),
        );

        $user = AgentBridgeTokenFreshness::assertStillAllowed($token, $client, $capability->scope, $user, $capability->key);

        if ($capability->policyAbility !== null) {
            AgentBridgeCapabilityPolicy::authorize($capability, $user, $payload);
        }

        // The token alone cannot both preview and confirm a high-risk change: the person
        // it acts as must have approved this exact confirmation in the admin. The stored
        // flag is honoured even if the capability's risk was lowered after the preview.
        $requiresApproval = $confirmation->requires_approval || AgentBridgeHumanApproval::requiredFor($capability);

        throw_if(
            $requiresApproval && ! $confirmation->isApprovedBy($user),
            AgentBridgeConfirmationAwaitingApprovalException::class,
            AgentBridgeValue::string(__('capell-agent-bridge::messages.confirmation.awaiting_approval'), 'Agent Bridge awaiting approval message'),
        );

        $usedAt = now();
        $claim = CapellAgentBridgeConfirmation::query()
            ->whereKey($confirmation->getKey())
            ->whereNull('used_at')
            ->whereNull('declined_at')
            ->where('expires_at', '>', $usedAt);

        if ($requiresApproval) {
            $claim->whereNotNull('approved_at');
        }

        $claimed = $claim->update(['used_at' => $usedAt]);

        throw_if($claimed !== 1, AuthorizationException::class, 'The Agent Bridge confirmation token is invalid or expired.');

        $action = resolve($capability->actionClass);
        $result = AgentBridgeTokenSiteRestriction::within(
            $token,
            static fn (): CapabilityResultData => $action->execute(new CapabilityInvocationData($capability, $payload, $client, $user)),
        );

        AuditAgentBridgeCapabilityAction::run(
            event: $capability->auditEvent ?? 'capell_agent-bridge.capability.confirmed',
            capabilityKey: $capability->key,
            scope: $capability->scope,
            payload: $payload,
            result: $result,
            token: $token,
            user: $user,
        );

        return [
            'mode' => 'confirmed',
            'capability' => $capability->key,
            'result' => $result->toPayload(),
            'contentTrust' => AgentBridgeContentTrust::forCapabilityResult('result'),
        ];
    }
}
