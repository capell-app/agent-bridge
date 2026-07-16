<?php

declare(strict_types=1);

namespace Capell\AgentBridge\Data\Capabilities;

use Capell\AgentBridge\Support\AgentBridgeValue;
use Spatie\LaravelData\Data;

final class SearchPagesCapabilityInputData extends Data
{
    public function __construct(
        public readonly string $query,
        public readonly int|string|null $site = null,
        public readonly ?int $site_id = null,
        public readonly int $limit = 10,
    ) {}

    /** @param array<string, mixed> $payload */
    public static function fromPayload(array $payload): self
    {
        return new self(
            query: AgentBridgeValue::string($payload['query'] ?? null, 'Search query'),
            site: AgentBridgeValue::reference($payload['site'] ?? null, 'Site reference'),
            site_id: AgentBridgeValue::nullableInteger($payload['site_id'] ?? null, 'Site ID'),
            limit: AgentBridgeValue::integer($payload['limit'] ?? 10, 'Search limit'),
        );
    }
}
