<?php

declare(strict_types=1);

namespace Capell\AgentBridge\Data\Capabilities;

use Capell\AgentBridge\Support\AgentBridgeValue;
use Spatie\LaravelData\Data;

final class TransitionPagePublicationCapabilityInputData extends Data
{
    public function __construct(
        public readonly int|string|null $page = null,
        public readonly ?int $page_id = null,
        public readonly int|string|null $site = null,
        public readonly ?int $site_id = null,
        public readonly ?string $publish_at = null,
        public readonly ?string $unpublish_at = null,
    ) {}

    /** @param array<string, mixed> $payload */
    public static function fromPayload(array $payload): self
    {
        return new self(
            page: AgentBridgeValue::reference($payload['page'] ?? null, 'Page reference'),
            page_id: AgentBridgeValue::nullableInteger($payload['page_id'] ?? null, 'Page ID'),
            site: AgentBridgeValue::reference($payload['site'] ?? null, 'Site reference'),
            site_id: AgentBridgeValue::nullableInteger($payload['site_id'] ?? null, 'Site ID'),
            publish_at: AgentBridgeValue::nullableString($payload['publish_at'] ?? null, 'Publish time'),
            unpublish_at: AgentBridgeValue::nullableString($payload['unpublish_at'] ?? null, 'Unpublish time'),
        );
    }
}
