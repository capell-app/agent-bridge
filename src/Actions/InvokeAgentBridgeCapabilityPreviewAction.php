<?php

declare(strict_types=1);

namespace Capell\AgentBridge\Actions;

use Capell\AgentBridge\Data\AuthenticatedAgentBridgeClientData;
use Capell\AgentBridge\Data\CapabilityInvocationData;
use Capell\AgentBridge\Data\CapabilityResultData;
use Capell\AgentBridge\Models\CapellAgentBridgeConfirmation;
use Capell\AgentBridge\Models\CapellAgentBridgeToken;
use Capell\AgentBridge\Support\AgentBridgeAuditSanitizer;
use Capell\AgentBridge\Support\AgentBridgeCapabilityPolicy;
use Capell\AgentBridge\Support\AgentBridgeContentTrust;
use Capell\AgentBridge\Support\AgentBridgeHumanApproval;
use Capell\AgentBridge\Support\AgentBridgeTokenFreshness;
use Capell\AgentBridge\Support\AgentBridgeTokenSiteRestriction;
use Capell\AgentBridge\Support\AgentBridgeValue;
use Capell\AgentBridge\Support\CapellAgentBridgeCapabilityRegistry;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * @method static array<string, mixed> run(string $capabilityKey, array<string, mixed> $payload, ?AuthenticatedAgentBridgeClientData $client = null, ?CapellAgentBridgeToken $token = null, ?Authenticatable $user = null)
 */
final class InvokeAgentBridgeCapabilityPreviewAction
{
    use AsFake;
    use AsObject;

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function payloadHash(array $payload): string
    {
        return hash('sha256', json_encode(self::canonicalPayload($payload), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function handle(
        string $capabilityKey,
        array $payload,
        ?AuthenticatedAgentBridgeClientData $client = null,
        ?CapellAgentBridgeToken $token = null,
        ?Authenticatable $user = null,
    ): array {
        $registry = resolve(CapellAgentBridgeCapabilityRegistry::class);
        $capability = $registry->get($capabilityKey);

        $client ??= app()->bound(AuthenticatedAgentBridgeClientData::class) ? resolve(AuthenticatedAgentBridgeClientData::class) : null;
        $user ??= request()->user();

        throw_unless($client instanceof AuthenticatedAgentBridgeClientData, AuthorizationException::class, 'Agent Bridge client context is required.');

        if (! $client->can($capability->scope)) {
            throw new AuthorizationException(sprintf('Agent Bridge scope [%s] is required.', $capability->scope));
        }

        throw_unless(
            $client->canUseCapability($capability->key),
            AuthorizationException::class,
            sprintf('This Agent Bridge token is not allowed to use capability [%s].', $capability->key),
        );

        if ($capability->policyAbility !== null) {
            throw_if(
                ! $user instanceof Authenticatable,
                AuthorizationException::class,
                sprintf('Agent Bridge policy ability [%s] requires an authenticated user.', $capability->policyAbility),
            );
        }

        // A client authenticated by a token must still hold that token, and the
        // token's current owner is the user every later check runs as. Internal
        // clients (token id 0) carry no token, so a token bound for the request
        // they run inside is never borrowed for them, and a token handed to one
        // explicitly is checked like any other, which refuses it.
        if ($client->tokenId > 0 || $token instanceof CapellAgentBridgeToken) {
            $token ??= app()->bound(CapellAgentBridgeToken::class) ? resolve(CapellAgentBridgeToken::class) : null;

            throw_unless($token instanceof CapellAgentBridgeToken, AuthorizationException::class, 'Agent Bridge token context is required.');

            $user = AgentBridgeTokenFreshness::assertStillAllowed($token, $client, $capability->scope, $user, $capability->key);
        }

        if ($capability->policyAbility !== null && $user instanceof Authenticatable) {
            AgentBridgeCapabilityPolicy::authorize($capability, $user, $payload);
        }

        // A confirmation is redeemed by the token that asked for it, so a client
        // without one cannot be offered a capability that needs confirming.
        throw_if(
            $capability->needsConfirmation() && ! $token instanceof CapellAgentBridgeToken,
            AuthorizationException::class,
            sprintf('Agent Bridge capability [%s] needs confirmation, which requires a token client.', $capability->key),
        );

        $action = resolve($capability->actionClass);
        $preview = AgentBridgeTokenSiteRestriction::within(
            $token,
            static fn (): CapabilityResultData => $action->preview(new CapabilityInvocationData($capability, $payload, $client, $user)),
        );

        if (! $capability->needsConfirmation()) {
            $result = AgentBridgeTokenSiteRestriction::within(
                $token,
                static fn (): CapabilityResultData => $action->execute(new CapabilityInvocationData($capability, $payload, $client, $user)),
            );

            AuditAgentBridgeCapabilityAction::run(
                event: $capability->auditEvent ?? 'capell_agent-bridge.capability.executed',
                capabilityKey: $capability->key,
                scope: $capability->scope,
                payload: $payload,
                result: $result,
                token: $token,
                user: $user,
            );

            return [
                'mode' => 'executed',
                'capability' => $capability->key,
                'result' => $result->toPayload(),
                'contentTrust' => AgentBridgeContentTrust::forCapabilityResult('result'),
            ];
        }

        $requiresApproval = AgentBridgeHumanApproval::requiredFor($capability);
        $plainTextConfirmationToken = Str::random(64);
        // Confirming re-sends the payload and checks it against payload_hash, so the stored
        // copies are only a record. They get the same secret redaction as the audit trail.
        $sanitizer = resolve(AgentBridgeAuditSanitizer::class);
        $confirmation = new CapellAgentBridgeConfirmation([
            'token' => CapellAgentBridgeConfirmation::hashPlainTextToken($plainTextConfirmationToken),
            'agent_bridge_token_id' => $token?->getKey(),
            'capability_key' => $capability->key,
            'scope' => $capability->scope,
            'payload_hash' => self::payloadHash($payload),
            'payload' => $sanitizer->sanitize($payload),
            'preview' => $sanitizer->sanitize($preview->toPayload()),
            'requires_approval' => $requiresApproval,
            'expires_at' => now()->addMinutes($requiresApproval
                ? AgentBridgeHumanApproval::approvalTtlMinutes()
                : AgentBridgeValue::integer(
                    config('capell-agent-bridge.confirmation_ttl_minutes', 10),
                    'Agent Bridge confirmation TTL',
                )),
        ]);

        if ($user instanceof Model) {
            $confirmation->user()->associate($user);
        }

        $confirmation->save();

        AuditAgentBridgeCapabilityAction::run(
            event: 'capell_agent-bridge.capability.previewed',
            capabilityKey: $capability->key,
            scope: $capability->scope,
            payload: $payload,
            result: $preview,
            token: $token,
            user: $user,
        );

        return [
            'mode' => 'preview',
            'capability' => $capability->key,
            'confirmationToken' => $plainTextConfirmationToken,
            'expiresAt' => $confirmation->expires_at->toIso8601String(),
            'approvalRequired' => $requiresApproval,
            'nextStep' => AgentBridgeValue::string(
                $requiresApproval
                    ? __('capell-agent-bridge::messages.confirmation.next_step_approval')
                    : __('capell-agent-bridge::messages.confirmation.next_step_confirm'),
                'Agent Bridge confirmation next step',
            ),
            'preview' => $preview->toPayload(),
            'contentTrust' => AgentBridgeContentTrust::forCapabilityResult('preview'),
        ];
    }

    /**
     * @param  array<array-key, mixed>  $payload
     * @return array<array-key, mixed>
     */
    private static function canonicalPayload(array $payload): array
    {
        if (! array_is_list($payload)) {
            ksort($payload);
        }

        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $payload[$key] = self::canonicalPayload($value);
            }
        }

        return $payload;
    }
}
