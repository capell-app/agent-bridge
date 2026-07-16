<?php

declare(strict_types=1);

namespace Capell\AgentBridge\Data;

use Spatie\LaravelData\Data;

final class AgentBridgeCommandPlanData extends Data
{
    /**
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $missingFields
     * @param  list<array<string, mixed>>  $suggestions
     */
    public function __construct(
        public readonly string $command,
        public readonly bool $matched,
        public readonly ?string $capabilityKey,
        public readonly array $payload,
        public readonly string $summary,
        public readonly float $confidence,
        public readonly array $missingFields = [],
        public readonly array $suggestions = [],
    ) {}
}
