<?php

declare(strict_types=1);

namespace Capell\AgentBridge\Data\Capabilities;

use Capell\AgentBridge\Support\AgentBridgeValue;
use Spatie\LaravelData\Data;

final class PageReferenceCapabilityInputData extends Data
{
    public function __construct(
        public readonly int|string|null $page = null,
        public readonly ?int $page_id = null,
        public readonly int|string|null $site = null,
        public readonly ?int $site_id = null,
        public readonly bool $include_content = false,
    ) {}

    /** @param array<string, mixed> $payload */
    public static function fromPayload(array $payload): self
    {
        return new self(
            page: AgentBridgeValue::reference($payload['page'] ?? null, 'Page reference'),
            page_id: AgentBridgeValue::nullableInteger($payload['page_id'] ?? null, 'Page ID'),
            site: AgentBridgeValue::reference($payload['site'] ?? null, 'Site reference'),
            site_id: AgentBridgeValue::nullableInteger($payload['site_id'] ?? null, 'Site ID'),
            include_content: AgentBridgeValue::boolean($payload['include_content'] ?? false, 'Include content'),
        );
    }
}
