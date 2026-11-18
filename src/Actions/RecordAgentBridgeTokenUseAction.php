<?php

declare(strict_types=1);

namespace Capell\AgentBridge\Actions;

use Capell\AgentBridge\Models\CapellAgentBridgeToken;
use Capell\AgentBridge\Support\AgentBridgeValue;
use Carbon\CarbonImmutable;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * Stamps last use and upgrades a legacy hash after a token authenticates, so the
 * HTTP and local transports leave the same usage trail.
 *
 * @method static void run(CapellAgentBridgeToken $token, string $plainTextToken)
 */
final class RecordAgentBridgeTokenUseAction
{
    use AsFake;
    use AsObject;

    public function handle(CapellAgentBridgeToken $token, string $plainTextToken): void
    {
        $tokenUpdates = [];

        if ($this->shouldUpdateLastUsedAt($token)) {
            $tokenUpdates['last_used_at'] = now();
        }

        if ($token->hasLegacyHashForPlainTextToken($plainTextToken)) {
            $tokenUpdates['token_hash'] = CapellAgentBridgeToken::hashPlainTextToken($plainTextToken);
        }

        if ($tokenUpdates !== []) {
            $token->forceFill($tokenUpdates)->save();
        }
    }

    private function shouldUpdateLastUsedAt(CapellAgentBridgeToken $token): bool
    {
        $throttleMinutes = AgentBridgeValue::integer(
            config('capell-agent-bridge.last_used_throttle_minutes', 5),
            'Last-used throttle minutes',
        );

        if ($throttleMinutes <= 0) {
            return true;
        }

        $lastUsed = $token->last_used_at;

        if (! $lastUsed instanceof CarbonImmutable) {
            return true;
        }

        return $lastUsed->diffInMinutes(now()) >= $throttleMinutes;
    }
}
