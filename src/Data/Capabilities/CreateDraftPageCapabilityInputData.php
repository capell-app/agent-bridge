<?php

declare(strict_types=1);

namespace Capell\AgentBridge\Data\Capabilities;

use Capell\AgentBridge\Support\AgentBridgeValue;
use Spatie\LaravelData\Data;

final class CreateDraftPageCapabilityInputData extends Data
{
    /**
     * @param  array<string, mixed>|null  $meta
     * @param  array<string, mixed>|null  $translation_meta
     */
    public function __construct(
        public readonly string $name,
        public readonly int|string|null $site = null,
        public readonly ?int $site_id = null,
        public readonly int|string|null $page_type = null,
        public readonly ?int $blueprint_id = null,
        public readonly int|string|null $layout = null,
        public readonly ?int $layout_id = null,
        public readonly int|string|null $parent = null,
        public readonly ?int $parent_id = null,
        public readonly int|string|null $language = null,
        public readonly ?int $language_id = null,
        public readonly ?string $title = null,
        public readonly ?string $slug = null,
        public readonly mixed $content = null,
        public readonly ?array $meta = null,
        public readonly ?array $translation_meta = null,
    ) {}

    /** @param array<string, mixed> $payload */
    public static function fromPayload(array $payload): self
    {
        return new self(
            name: AgentBridgeValue::string($payload['name'] ?? null, 'Page name'),
            site: AgentBridgeValue::reference($payload['site'] ?? null, 'Site reference'),
            site_id: AgentBridgeValue::nullableInteger($payload['site_id'] ?? null, 'Site ID'),
            page_type: AgentBridgeValue::reference($payload['page_type'] ?? null, 'Page type reference'),
            blueprint_id: AgentBridgeValue::nullableInteger($payload['blueprint_id'] ?? null, 'Blueprint ID'),
            layout: AgentBridgeValue::reference($payload['layout'] ?? null, 'Layout reference'),
            layout_id: AgentBridgeValue::nullableInteger($payload['layout_id'] ?? null, 'Layout ID'),
            parent: AgentBridgeValue::reference($payload['parent'] ?? null, 'Parent page reference'),
            parent_id: AgentBridgeValue::nullableInteger($payload['parent_id'] ?? null, 'Parent page ID'),
            language: AgentBridgeValue::reference($payload['language'] ?? null, 'Language reference'),
            language_id: AgentBridgeValue::nullableInteger($payload['language_id'] ?? null, 'Language ID'),
            title: AgentBridgeValue::nullableString($payload['title'] ?? null, 'Page title'),
            slug: AgentBridgeValue::nullableString($payload['slug'] ?? null, 'Page slug'),
            content: $payload['content'] ?? null,
            meta: array_key_exists('meta', $payload) && $payload['meta'] !== null
                ? AgentBridgeValue::associativeArray($payload['meta'], 'Page metadata')
                : null,
            translation_meta: array_key_exists('translation_meta', $payload) && $payload['translation_meta'] !== null
                ? AgentBridgeValue::associativeArray($payload['translation_meta'], 'Translation metadata')
                : null,
        );
    }
}
