<?php

declare(strict_types=1);

namespace Capell\AgentBridge\Actions;

use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use UnexpectedValueException;

final class HashAgentBridgeIpAddressAction
{
    use AsFake;
    use AsObject;

    public function handle(mixed $ipAddress): ?string
    {
        if (! is_string($ipAddress) || trim($ipAddress) === '') {
            return null;
        }

        $secret = config('capell-agent-bridge.audit_hash_secret') ?: config('app.key');

        if (! is_string($secret) || $secret === '') {
            throw new UnexpectedValueException('Agent Bridge audit hashing requires a non-empty string secret.');
        }

        return hash_hmac('sha256', trim($ipAddress), $secret);
    }
}
