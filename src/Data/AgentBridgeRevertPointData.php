<?php

declare(strict_types=1);

namespace Capell\AgentBridge\Data;

use Capell\AgentBridge\Enums\AgentBridgeRevertKindEnum;

/**
 * Where a change made through Agent Bridge can be undone from. It names the page and the
 * page versions either side of the change, never the content, so the audit log holds no
 * more than it did before. A revert is refused once the page has moved past
 * $versionAfter, because undoing it then would also undo someone else's later work.
 */
final readonly class AgentBridgeRevertPointData
{
    public function __construct(
        public AgentBridgeRevertKindEnum $kind,
        public int $pageId,
        public string $pageUuid,
        public int $versionAfter,
        public ?int $versionBefore = null,
    ) {}

    /** @param  array<array-key, mixed>|null  $stored */
    public static function fromStored(?array $stored): ?self
    {
        if ($stored === null) {
            return null;
        }

        $kind = AgentBridgeRevertKindEnum::tryFrom(is_string($stored['kind'] ?? null) ? $stored['kind'] : '');
        $pageId = $stored['page_id'] ?? null;
        $pageUuid = $stored['page_uuid'] ?? null;
        $versionAfter = $stored['version_after'] ?? null;
        $versionBefore = $stored['version_before'] ?? null;

        if (! $kind instanceof AgentBridgeRevertKindEnum
            || ! is_int($pageId)
            || ! is_string($pageUuid) || $pageUuid === ''
            || ! is_int($versionAfter)
            || ($versionBefore !== null && ! is_int($versionBefore))
            || ($kind === AgentBridgeRevertKindEnum::RollbackPage && ($versionBefore === null || $versionBefore < 1))) {
            return null;
        }

        return new self($kind, $pageId, $pageUuid, $versionAfter, $versionBefore);
    }

    /** @return array{kind: string, page_id: int, page_uuid: string, version_after: int, version_before: int|null} */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind->value,
            'page_id' => $this->pageId,
            'page_uuid' => $this->pageUuid,
            'version_after' => $this->versionAfter,
            'version_before' => $this->versionBefore,
        ];
    }
}
