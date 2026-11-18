<?php

declare(strict_types=1);

namespace Capell\AgentBridge\Actions;

use Capell\AgentBridge\Data\AuthenticatedAgentBridgeClientData;
use Capell\AgentBridge\Exceptions\AgentBridgeConfirmationAwaitingApprovalException;
use Capell\AgentBridge\Exceptions\AgentBridgeMcpV1ConfirmationException;
use Capell\AgentBridge\Models\CapellAgentBridgeConfirmation;
use Capell\AgentBridge\Models\CapellAgentBridgeToken;
use Capell\AgentBridge\Support\AgentBridgeMcpV1Policy;
use Capell\AgentBridge\Support\AgentBridgeValue;
use Capell\AgentBridge\Support\CapellAgentBridgeCapabilityRegistry;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class ConfirmAgentBridgeMcpV1CapabilityAction
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
        AuthenticatedAgentBridgeClientData $client,
        CapellAgentBridgeToken $token,
        ?Authenticatable $user,
    ): array {
        $confirmation = CapellAgentBridgeConfirmation::query()
            ->where('token', CapellAgentBridgeConfirmation::hashPlainTextToken($confirmationToken))
            ->first();

        throw_unless(
            $confirmation instanceof CapellAgentBridgeConfirmation,
            AgentBridgeMcpV1ConfirmationException::class,
            AgentBridgeValue::string(
                __('capell-agent-bridge::messages.mcp_v1.denied'),
                'MCP v1 denial message',
            ),
        );

        $policy = resolve(AgentBridgeMcpV1Policy::class);
        $capability = $policy->resolveCapability(
            resolve(CapellAgentBridgeCapabilityRegistry::class),
            $confirmation->capability_key,
        );
        $policy->authorizeExecution($capability, $payload, $client, $token, $user);

        try {
            $result = ConfirmAgentBridgeCapabilityAction::run(
                confirmationToken: $confirmationToken,
                payload: $payload,
                client: $client,
                token: $token,
                user: $user,
            );
        } catch (AgentBridgeConfirmationAwaitingApprovalException $exception) {
            throw $exception;
        } catch (AuthorizationException $exception) {
            throw new AgentBridgeMcpV1ConfirmationException(message: $exception->getMessage(), code: $exception->getCode(), previous: $exception);
        }

        return $policy->projectOutput($capability, $result);
    }
}
