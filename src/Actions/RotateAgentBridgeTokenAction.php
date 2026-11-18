<?php

declare(strict_types=1);

namespace Capell\AgentBridge\Actions;

use Capell\AgentBridge\Models\CapellAgentBridgeToken;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * Issues a new secret for a token that is still usable. A revoked, disabled
 * or expired token stays dead: rotation never restores access, and every
 * rotation is recorded in the audit trail.
 *
 * @method static array{token: CapellAgentBridgeToken, plainTextToken: string} run(CapellAgentBridgeToken $token, ?Authenticatable $actor = null)
 */
final class RotateAgentBridgeTokenAction
{
    use AsFake;
    use AsObject;

    /**
     * @return array{token: CapellAgentBridgeToken, plainTextToken: string}
     */
    public function handle(CapellAgentBridgeToken $token, ?Authenticatable $actor = null): array
    {
        $plainTextToken = CapellAgentBridgeToken::generatePlainTextToken();

        $rotated = DB::transaction(function () use ($token, $plainTextToken): CapellAgentBridgeToken {
            $current = CapellAgentBridgeToken::query()
                ->whereKey($token->getKey())
                ->lockForUpdate()
                ->first();

            throw_unless(
                $current instanceof CapellAgentBridgeToken && $current->isUsable(),
                AuthorizationException::class,
                'A revoked, disabled or expired Agent Bridge token cannot be rotated. Create a new token instead.',
            );

            $current->forceFill([
                'token_hash' => CapellAgentBridgeToken::hashPlainTextToken($plainTextToken),
                'rotated_at' => now(),
            ])->save();

            return $current;
        });

        $token->setRawAttributes($rotated->getAttributes(), true);

        AuditAgentBridgeCapabilityAction::run(
            event: 'capell_agent-bridge.token.rotated',
            token: $token,
            user: $actor ?? auth()->user(),
        );

        return [
            'token' => $token,
            'plainTextToken' => $plainTextToken,
        ];
    }
}
