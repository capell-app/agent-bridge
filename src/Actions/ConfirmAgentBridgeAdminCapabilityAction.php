<?php

declare(strict_types=1);

namespace Capell\AgentBridge\Actions;

use Capell\AgentBridge\Data\CapabilityInvocationData;
use Capell\AgentBridge\Support\AgentBridgeAdminCapabilityResolver;
use Capell\AgentBridge\Support\AgentBridgeAdminConfirmationStore;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Validation\ValidationException;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/** @method static array<string, mixed> run(string $confirmationToken, Authenticatable $user) */
final class ConfirmAgentBridgeAdminCapabilityAction
{
    use AsFake;
    use AsObject;

    /** @return array<string, mixed> */
    public function handle(string $confirmationToken, Authenticatable $user): array
    {
        $record = resolve(AgentBridgeAdminConfirmationStore::class)->pull($user, $confirmationToken);
        $capability = resolve(AgentBridgeAdminCapabilityResolver::class)->resolve($record['capability_key'], $user);

        $action = resolve($capability->actionClass);
        $invocation = new CapabilityInvocationData($capability, $record['payload'], user: $user);
        $currentPreview = $action->preview($invocation);

        if (! $currentPreview->ok) {
            throw ValidationException::withMessages([
                'confirmation' => __('capell-agent-bridge::admin.command_confirmation_no_longer_valid'),
            ]);
        }

        $result = $action->execute($invocation);

        AuditAgentBridgeCapabilityAction::run(
            event: $capability->auditEvent ?? 'capell_agent-bridge.admin.confirmed',
            capabilityKey: $capability->key,
            scope: $capability->scope,
            payload: $record['payload'],
            result: $result,
            user: $user,
        );

        return [
            'mode' => 'confirmed',
            'capability' => $capability->key,
            'result' => $result->toPayload(),
        ];
    }
}
