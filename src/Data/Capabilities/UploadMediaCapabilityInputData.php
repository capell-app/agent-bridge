<?php

declare(strict_types=1);

namespace Capell\AgentBridge\Data\Capabilities;

use Capell\AgentBridge\Support\AgentBridgeValue;
use Spatie\LaravelData\Data;

final class UploadMediaCapabilityInputData extends Data
{
    public function __construct(
        public readonly int $site_id,
        public readonly string $idempotency_key,
        public readonly string $file_name,
        public readonly string $content_base64,
        public readonly ?string $alt = null,
    ) {}

    /** @param array<string, mixed> $payload */
    public static function fromPayload(array $payload): self
    {
        return new self(
            site_id: AgentBridgeValue::integer($payload['site_id'] ?? null, 'Site ID'),
            idempotency_key: AgentBridgeValue::string($payload['idempotency_key'] ?? null, 'Idempotency key'),
            file_name: AgentBridgeValue::string($payload['file_name'] ?? null, 'File name'),
            content_base64: AgentBridgeValue::string($payload['content_base64'] ?? null, 'Base64 media content'),
            alt: AgentBridgeValue::nullableString($payload['alt'] ?? null, 'Alternative text'),
        );
    }
}
