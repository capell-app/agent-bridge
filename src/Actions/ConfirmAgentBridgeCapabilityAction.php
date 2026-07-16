<?php

declare(strict_types=1);

namespace Capell\AgentBridge\Actions;

use Capell\AgentBridge\Data\AuthenticatedAgentBridgeClientData;
use Capell\AgentBridge\Data\CapabilityInvocationData;
use Capell\AgentBridge\Models\CapellAgentBridgeConfirmation;
use Capell\AgentBridge\Models\CapellAgentBridgeToken;
use Capell\AgentBridge\Support\CapellAgentBridgeCapabilityRegistry;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;
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

        if ($capability->policyAbility !== null) {
            Gate::forUser($user)->authorize($capability->policyAbility);
        }

        $usedAt = now();
        $claimed = CapellAgentBridgeConfirmation::query()
            ->whereKey($confirmation->getKey())
            ->whereNull('used_at')
            ->where('expires_at', '>', $usedAt)
            ->update(['used_at' => $usedAt]);

        throw_if($claimed !== 1, AuthorizationException::class, 'The Agent Bridge confirmation token is invalid or expired.');

        $action = resolve($capability->actionClass);
        $result = $action->execute(new CapabilityInvocationData($capability, $payload, $client, $user));

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
        ];
    }
}
