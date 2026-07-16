<?php

declare(strict_types=1);

namespace Capell\AgentBridge\Support;

use Capell\AgentBridge\Actions\InvokeAgentBridgeCapabilityPreviewAction;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Throwable;

final class AgentBridgeAdminConfirmationStore
{
    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $preview
     */
    public function put(
        Authenticatable $user,
        string $capabilityKey,
        array $payload,
        array $preview,
    ): string {
        $token = Str::random(64);
        $ttlMinutes = max(1, AgentBridgeValue::integer(
            config('capell-agent-bridge.confirmation_ttl_minutes', 10),
            'Agent Bridge confirmation TTL',
        ));
        $record = [
            'user_type' => method_exists($user, 'getMorphClass') ? $user->getMorphClass() : $user::class,
            'user_id' => AgentBridgeValue::identifier($user->getAuthIdentifier(), 'User auth identifier'),
            'capability_key' => $capabilityKey,
            'payload_hash' => InvokeAgentBridgeCapabilityPreviewAction::payloadHash($payload),
            'payload' => $payload,
            'preview' => $preview,
            'expires_at' => now()->addMinutes($ttlMinutes)->toIso8601String(),
        ];

        Cache::put(
            $this->key($token, $user),
            Crypt::encryptString(json_encode($record, JSON_THROW_ON_ERROR)),
            now()->addMinutes($ttlMinutes),
        );

        return $token;
    }

    /** @return array{capability_key:string, payload:array<string, mixed>, preview:array<string, mixed>, expires_at:string} */
    public function pull(Authenticatable $user, string $token): array
    {
        $encrypted = Cache::pull($this->key($token, $user));

        try {
            $record = is_string($encrypted)
                ? json_decode(Crypt::decryptString($encrypted), true, flags: JSON_THROW_ON_ERROR)
                : null;
        } catch (Throwable) {
            $record = null;
        }

        $expectedUserType = method_exists($user, 'getMorphClass') ? $user->getMorphClass() : $user::class;

        if (! is_array($record)) {
            $this->rejectInvalidConfirmation();
        }

        $capabilityKey = $record['capability_key'] ?? null;
        $expiresAt = $record['expires_at'] ?? null;
        $payloadHash = $record['payload_hash'] ?? null;

        if (($record['user_type'] ?? null) !== $expectedUserType
            || ($record['user_id'] ?? null) !== AgentBridgeValue::identifier($user->getAuthIdentifier(), 'User auth identifier')
            || ! is_string($capabilityKey)
            || ! is_string($expiresAt)
            || ! is_string($payloadHash)) {
            $this->rejectInvalidConfirmation();
        }

        try {
            $payload = AgentBridgeValue::associativeArray($record['payload'] ?? null, 'Confirmation payload');
            $preview = AgentBridgeValue::associativeArray($record['preview'] ?? null, 'Confirmation preview');
            $expiration = CarbonImmutable::parse($expiresAt);
        } catch (Throwable) {
            $this->rejectInvalidConfirmation();
        }

        if (! hash_equals($payloadHash, InvokeAgentBridgeCapabilityPreviewAction::payloadHash($payload))
            || now()->greaterThanOrEqualTo($expiration)) {
            $this->rejectInvalidConfirmation();
        }

        return [
            'capability_key' => $capabilityKey,
            'payload' => $payload,
            'preview' => $preview,
            'expires_at' => $expiresAt,
        ];
    }

    public function forget(Authenticatable $user, string $token): void
    {
        Cache::forget($this->key($token, $user));
    }

    private function key(string $token, Authenticatable $user): string
    {
        return 'capell-agent-bridge:admin-confirmation:' . hash('sha256', implode('|', [
            $token,
            $user::class,
            AgentBridgeValue::identifier($user->getAuthIdentifier(), 'User auth identifier'),
        ]));
    }

    private function rejectInvalidConfirmation(): never
    {
        throw new AuthorizationException((string) __('capell-agent-bridge::admin.command_confirmation_invalid'));
    }
}
