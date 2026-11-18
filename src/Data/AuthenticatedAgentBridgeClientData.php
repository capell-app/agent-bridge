<?php

declare(strict_types=1);

namespace Capell\AgentBridge\Data;

use Capell\AgentBridge\Models\CapellAgentBridgeToken;
use Spatie\LaravelData\Data;

final class AuthenticatedAgentBridgeClientData extends Data
{
    /**
     * @param  array<int, string>  $scopes
     * @param  list<string>|null  $capabilityKeys  null allows every capability the scopes allow
     */
    public function __construct(
        public readonly int $tokenId,
        public readonly string $name,
        public readonly array $scopes,
        public readonly ?array $capabilityKeys = null,
    ) {}

    public static function fromToken(CapellAgentBridgeToken $token): self
    {
        return new self(
            tokenId: $token->id,
            name: $token->name,
            scopes: $token->scopes,
            capabilityKeys: $token->capability_keys,
        );
    }

    public function can(string $scope): bool
    {
        return in_array($scope, $this->scopes, true) || in_array('*', $this->scopes, true);
    }

    public function canUseCapability(string $capabilityKey): bool
    {
        return $this->capabilityKeys === null || in_array($capabilityKey, $this->capabilityKeys, true);
    }
}
