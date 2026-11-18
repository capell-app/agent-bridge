<?php

declare(strict_types=1);

namespace Capell\AgentBridge\Actions;

use Capell\AgentBridge\Data\CapabilityInvocationData;
use Capell\AgentBridge\Support\AgentBridgeAdminCapabilityResolver;
use Capell\AgentBridge\Support\AgentBridgeAdminConfirmationStore;
use Illuminate\Contracts\Auth\Authenticatable;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * @method static array<string, mixed> run(string $capabilityKey, array<string, mixed> $payload, Authenticatable $user)
 */
final class PreviewAgentBridgeAdminCapabilityAction
{
    use AsFake;
    use AsObject;

    /** @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function handle(string $capabilityKey, array $payload, Authenticatable $user): array
    {
        $capability = resolve(AgentBridgeAdminCapabilityResolver::class)->resolve($capabilityKey, $user, $payload);

        $action = resolve($capability->actionClass);
        $invocation = new CapabilityInvocationData($capability, $payload, user: $user);
        $preview = $action->preview($invocation);

        if (! $capability->needsConfirmation()) {
            $result = $action->execute($invocation);

            AuditAgentBridgeCapabilityAction::run(
                event: $capability->auditEvent ?? 'capell_agent-bridge.admin.executed',
                capabilityKey: $capability->key,
                scope: $capability->scope,
                payload: $payload,
                result: $result,
                user: $user,
            );

            return [
                'mode' => 'executed',
                'capability' => $capability->key,
                'result' => $result->toPayload(),
            ];
        }

        if (! $preview->ok) {
            return [
                'mode' => 'rejected',
                'capability' => $capability->key,
                'preview' => $preview->toPayload(),
            ];
        }

        $confirmationToken = resolve(AgentBridgeAdminConfirmationStore::class)->put(
            user: $user,
            capabilityKey: $capability->key,
            payload: $payload,
            preview: $preview->toPayload(),
        );

        AuditAgentBridgeCapabilityAction::run(
            event: 'capell_agent-bridge.admin.previewed',
            capabilityKey: $capability->key,
            scope: $capability->scope,
            payload: $payload,
            result: $preview,
            user: $user,
        );

        return [
            'mode' => 'preview',
            'capability' => $capability->key,
            'confirmationToken' => $confirmationToken,
            'preview' => $preview->toPayload(),
        ];
    }
}
