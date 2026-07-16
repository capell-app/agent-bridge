<?php

declare(strict_types=1);

namespace Capell\AgentBridge\Data\Capabilities;

use Capell\AgentBridge\Support\AgentBridgeValue;
use Spatie\LaravelData\Data;

final class ClonePageCapabilityInputData extends Data
{
    public function __construct(
        public readonly int|string|null $page = null,
        public readonly ?int $page_id = null,
        public readonly int|string|null $site = null,
        public readonly ?int $site_id = null,
        public readonly ?string $name = null,
        public readonly int|string|null $language = null,
        public readonly ?int $language_id = null,
        public readonly ?string $title = null,
        public readonly ?string $slug = null,
    ) {}

    /** @param array<string, mixed> $payload */
    public static function fromPayload(array $payload): self
    {
        return new self(
            page: AgentBridgeValue::reference($payload['page'] ?? null, 'Page reference'),
            page_id: AgentBridgeValue::nullableInteger($payload['page_id'] ?? null, 'Page ID'),
            site: AgentBridgeValue::reference($payload['site'] ?? null, 'Site reference'),
            site_id: AgentBridgeValue::nullableInteger($payload['site_id'] ?? null, 'Site ID'),
            name: AgentBridgeValue::nullableString($payload['name'] ?? null, 'Page name'),
            language: AgentBridgeValue::reference($payload['language'] ?? null, 'Language reference'),
            language_id: AgentBridgeValue::nullableInteger($payload['language_id'] ?? null, 'Language ID'),
            title: AgentBridgeValue::nullableString($payload['title'] ?? null, 'Page title'),
            slug: AgentBridgeValue::nullableString($payload['slug'] ?? null, 'Page slug'),
        );
    }
}
